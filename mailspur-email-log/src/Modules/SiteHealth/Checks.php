<?php
/**
 * The Site Health tests: email failures, stopped email types, emergency brake, staging mode and the sender domain.
 *
 * Site Health runs the direct tests on every visit of Tools › Site Health, so each one reads stored state or does
 * small index queries on the plugin's own tables. The sender test is the only one with DNS lookups (two TXT
 * records of the default sender domain, cached): it runs asynchronously via REST, never while the page loads.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small index range counts on the own table.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\SiteHealth;

use Mailspur\Admin;
use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\Delivery\SenderCheck;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Modules\Types\Monitor;
use Mailspur\Modules\Types\Page as TypesPage;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Store;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Checks {

	const GOOD        = 'good';
	const RECOMMENDED = 'recommended';
	const CRITICAL    = 'critical';

	/** Days the failure test looks back. */
	const DAYS = 7;

	/** The latest emails failing in a row from which sending counts as broken. */
	const STREAK = 3;

	/** Failed emails and failure rate from which failures are critical … */
	const CRITICAL_FAILED = 5;
	const CRITICAL_RATE   = 0.2;

	/** … and from which they are worth a look (single failures, e.g. mistyped addresses, are normal). */
	const RECOMMENDED_FAILED = 3;
	const RECOMMENDED_RATE   = 0.1;

	/** Stopped email types named in the description at most. */
	const MAX_TYPES = 3;

	/** Cached SPF/DMARC result of the sender test, per domain. */
	const SENDER_TRANSIENT = 'mailspur_health_sender_';
	const SENDER_TTL       = 43200;

	/** @var callable(string,string):(array<int,array<string,mixed>>|null) */
	private $resolver;

	/** @var callable():int */
	private $now;

	/**
	 * @param callable(string,string):(array<int,array<string,mixed>>|null)|null $resolver ( host, 'TXT' ) → records, null on failure.
	 * @param callable():int|null                                                 $now
	 */
	public function __construct( ?callable $resolver = null, ?callable $now = null ) {
		$this->resolver = $resolver ?? array( SenderCheck::class, 'dns' );
		$this->now      = $now ?? 'time';
	}

	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	/* ------------------------------------------------------------- failures */

	/**
	 * Status of the failure test.
	 *
	 * @param int $sent   Sent emails in the window.
	 * @param int $failed Failed emails in the window.
	 * @param int $streak The latest emails that failed in a row.
	 */
	public static function failure_level( int $sent, int $failed, int $streak ): string {
		$rate = $failed / max( 1, $sent + $failed );
		if ( $streak >= self::STREAK || ( $failed >= self::CRITICAL_FAILED && $rate >= self::CRITICAL_RATE ) ) {
			return self::CRITICAL;
		}
		if ( $failed >= self::RECOMMENDED_FAILED || ( $failed >= 2 && $rate >= self::RECOMMENDED_RATE ) ) {
			return self::RECOMMENDED;
		}
		return self::GOOD;
	}

	/**
	 * Failed emails of the last 7 days (without Mailspur's own alerts) and whether the latest ones all failed.
	 *
	 * @return array<string,mixed>
	 */
	public function failures(): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', $this->now() - self::DAYS * DAY_IN_SECONDS );
		$own   = class_exists( Stats::class ) ? Stats::ALERT_SOURCE : 'mailspur:alert';
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM %i WHERE status IN (%d, %d) AND created_at >= %s AND source <> %s GROUP BY status',
				Repository::table(),
				Repository::STATUS_SENT,
				Repository::STATUS_FAILED,
				$since,
				$own
			),
			ARRAY_A
		);
		$count = array(
			Repository::STATUS_SENT   => 0,
			Repository::STATUS_FAILED => 0,
		);
		foreach ( $rows as $row ) {
			$count[ (int) $row['status'] ] = (int) $row['n'];
		}
		$latest = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE status IN (%d, %d) AND created_at >= %s AND source <> %s ORDER BY id DESC LIMIT %d',
				Repository::table(),
				Repository::STATUS_SENT,
				Repository::STATUS_FAILED,
				$since,
				$own,
				self::STREAK
			),
			ARRAY_A
		);
		$streak = 0;
		foreach ( array_column( $latest, 'status' ) as $status ) {
			if ( Repository::STATUS_FAILED !== (int) $status ) {
				break;
			}
			++$streak;
		}

		$sent   = $count[ Repository::STATUS_SENT ];
		$failed = $count[ Repository::STATUS_FAILED ];
		$level  = self::failure_level( $sent, $failed, $streak );
		$ratio  = sprintf(
			/* translators: 1: number of failed emails, 2: number of all emails, 3: number of days */
			__( '%1$s of %2$s emails in the last %3$s days could not be sent.', 'mailspur-email-log' ),
			number_format_i18n( $failed ),
			number_format_i18n( $sent + $failed ),
			number_format_i18n( self::DAYS )
		);

		if ( self::CRITICAL === $level && $streak >= self::STREAK ) {
			$label       = __( 'The latest emails could not be sent', 'mailspur-email-log' );
			$description = sprintf(
				/* translators: %s: number of emails */
				__( 'The last %s emails all failed. Sending from this site may be broken, e.g. because of a wrong SMTP login or a mail service that refuses the site. Customers may not receive order confirmations or password resets.', 'mailspur-email-log' ),
				number_format_i18n( $streak )
			) . ' ' . $ratio;
		} elseif ( self::GOOD !== $level ) {
			$label       = self::CRITICAL === $level
				? __( 'Many emails could not be sent recently', 'mailspur-email-log' )
				: __( 'Some emails could not be sent recently', 'mailspur-email-log' );
			$description = $ratio . ' ' . __( 'The log shows the reason for each failed email.', 'mailspur-email-log' );
		} else {
			$label       = __( 'No unusual email failures', 'mailspur-email-log' );
			$description = $failed
				? $ratio . ' ' . __( 'Single failures, e.g. because of a mistyped address, are normal.', 'mailspur-email-log' )
				: __( 'Mailspur checks the log for emails that could not be sent.', 'mailspur-email-log' );
		}
		return self::result( 'mailspur_failures', $level, $label, $description, Admin::url( array( 'status' => Repository::status_slug( Repository::STATUS_FAILED ) ) ), __( 'Show failed emails', 'mailspur-email-log' ) );
	}

	/* ------------------------------------------------------------- types */

	/**
	 * Regularly sent email types that stopped, from the stored type data (indexed hourly by WP-Cron).
	 *
	 * @return array<string,mixed>
	 */
	public function types(): array {
		$now     = $this->now();
		$stopped = array();
		if ( class_exists( Store::class ) && Store::DB_VERSION === (int) get_option( Store::DB_OPTION ) ) {
			foreach ( Report::current( new Store(), $now ) as $item ) {
				if ( 'silent' === $item['state'] ) {
					$stopped[] = $item;
				}
			}
		}
		$link = Admin::url( array( 'tab' => TypesPage::TAB ) );
		if ( ! $stopped ) {
			return self::result(
				'mailspur_types',
				self::GOOD,
				__( 'No regularly sent email has stopped', 'mailspur-email-log' ),
				__( 'Mailspur learns how often each kind of email is sent, e.g. order confirmations, and reports here when one stops.', 'mailspur-email-log' ),
				$link,
				__( 'Show email types', 'mailspur-email-log' )
			);
		}

		$list = '';
		foreach ( array_slice( $stopped, 0, self::MAX_TYPES ) as $item ) {
			$list .= '<li>' . esc_html( Monitor::message( $item, $now ) ) . '</li>';
		}
		if ( count( $stopped ) > self::MAX_TYPES ) {
			/* translators: %s: number of further email types */
			$list .= '<li>' . esc_html( sprintf( __( '%s more on the Email types page.', 'mailspur-email-log' ), number_format_i18n( count( $stopped ) - self::MAX_TYPES ) ) ) . '</li>';
		}
		$result = self::result(
			'mailspur_types',
			self::RECOMMENDED,
			sprintf(
				/* translators: %s: number of email types */
				__( 'Emails that are sent regularly have stopped (%s)', 'mailspur-email-log' ),
				number_format_i18n( count( $stopped ) )
			),
			__( 'These kinds of email have not been sent for longer than usual. A plugin update, a changed setting or a stuck scheduled task may be the reason.', 'mailspur-email-log' ),
			$link,
			__( 'Show email types', 'mailspur-email-log' )
		);
		$result['description'] .= '<ul>' . $list . '</ul>';
		return $result;
	}

	/* ------------------------------------------------------------- brake */

	/**
	 * @return array<string,mixed>
	 */
	public function brake(): array {
		$link = Admin::url();
		if ( Brake::holding() ) {
			$held = ( new Brake() )->held_count();
			return self::result(
				'mailspur_brake',
				self::CRITICAL,
				__( 'The emergency brake is holding emails', 'mailspur-email-log' ),
				( $held
					/* translators: %s: number of emails */
					? sprintf( __( '%s emails are waiting and have not been delivered.', 'mailspur-email-log' ), number_format_i18n( $held ) ) . ' '
					: '' ) . __( 'Far more emails than usual were sent, so Mailspur holds further emails until you release or discard them.', 'mailspur-email-log' ),
				$link,
				__( 'Review held emails', 'mailspur-email-log' )
			);
		}
		if ( Brake::active() ) {
			return self::result(
				'mailspur_brake',
				self::RECOMMENDED,
				__( 'Far more emails than usual were sent', 'mailspur-email-log' ),
				__( 'The emergency brake noticed an unusual number of emails within one hour. A contact form may be abused. Emails are still being sent.', 'mailspur-email-log' ),
				$link,
				__( 'Open the log', 'mailspur-email-log' )
			);
		}
		return self::result(
			'mailspur_brake',
			self::GOOD,
			__( 'The emergency brake is not holding any emails', 'mailspur-email-log' ),
			__( 'When far more emails than usual are sent, the emergency brake warns you and can hold further emails.', 'mailspur-email-log' ),
			Admin::url( array( 'tab' => 'settings' ) ) . '#mailspur-brake',
			__( 'Emergency brake settings', 'mailspur-email-log' )
		);
	}

	/* ------------------------------------------------------------- staging */

	/**
	 * Staging mode on a live site is easy to forget after copying a staging site to production.
	 *
	 * @return array<string,mixed>
	 */
	public function staging(): array {
		$environment = self::environment();
		$link        = Admin::url( array( 'tab' => 'settings' ) ) . '#mailspur-staging';
		if ( ! Staging::active() ) {
			return self::result(
				'mailspur_staging',
				self::GOOD,
				__( 'Staging mode is off', 'mailspur-email-log' ),
				__( 'Emails are delivered to their recipients.', 'mailspur-email-log' ),
				$link,
				__( 'Staging mode settings', 'mailspur-email-log' )
			);
		}
		if ( 'production' !== $environment ) {
			return self::result(
				'mailspur_staging',
				self::GOOD,
				__( 'Staging mode is on for this staging site', 'mailspur-email-log' ),
				sprintf(
					/* translators: %s: environment type, e.g. "staging" */
					__( 'This site runs as a %s environment, and staging mode keeps its emails from reaching real people.', 'mailspur-email-log' ),
					$environment
				),
				$link,
				__( 'Staging mode settings', 'mailspur-email-log' )
			);
		}
		return self::result(
			'mailspur_staging',
			self::RECOMMENDED,
			__( 'Staging mode is on although this is a live site', 'mailspur-email-log' ),
			( Staging::REDIRECT === Staging::effective_mode()
				? __( 'All emails go to test addresses instead of the real recipients.', 'mailspur-email-log' )
				: __( 'Emails are logged but not delivered.', 'mailspur-email-log' ) )
			. ' ' . __( 'That is right for a test copy, but on a live site customers receive no order confirmations, password resets or answers to their forms. Turn staging mode off if this is the live site.', 'mailspur-email-log' ),
			$link,
			__( 'Change staging mode', 'mailspur-email-log' )
		);
	}

	/** Same environment type the staging mode suggestion uses. */
	private static function environment(): string {
		/** This filter is documented in src/Modules/Delivery/Module.php */
		return (string) apply_filters( 'mailspur_environment_type', wp_get_environment_type() );
	}

	/* ------------------------------------------------------------- sender */

	/**
	 * Status of the sender test.
	 *
	 * @param bool|null $spf   SPF record found (null: lookup failed).
	 * @param bool|null $dmarc DMARC record found (null: lookup failed).
	 */
	public static function sender_level( ?bool $spf, ?bool $dmarc ): string {
		return false === $spf || false === $dmarc ? self::RECOMMENDED : self::GOOD;
	}

	/**
	 * SPF and DMARC of the default sender domain: two TXT lookups, cached for 12 hours. Async test (REST) only.
	 *
	 * @return array<string,mixed>
	 */
	public function sender(): array {
		$link   = Admin::url( array( 'tab' => 'settings' ) ) . '#mailspur-sender-check';
		$action = __( 'Run the full sender check', 'mailspur-email-log' );
		$domain = self::sender_domain();
		if ( '' === $domain || ! SenderCheck::available() ) {
			return self::result( 'mailspur_sender', self::GOOD, __( 'No public sender domain to check', 'mailspur-email-log' ), __( 'The default sender address has no public domain, or this server cannot look up DNS records.', 'mailspur-email-log' ), $link, $action );
		}

		$key    = self::SENDER_TRANSIENT . md5( $domain );
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			$spf    = call_user_func( $this->resolver, $domain, 'TXT' );
			$dmarc  = call_user_func( $this->resolver, '_dmarc.' . $domain, 'TXT' );
			$cached = array(
				'spf'   => is_array( $spf ) ? array() !== SenderCheck::spf_records( $spf ) : null,
				'dmarc' => is_array( $dmarc ) ? self::has_dmarc( $dmarc ) : null,
			);
			set_transient( $key, $cached, self::SENDER_TTL );
		}
		$spf   = isset( $cached['spf'] ) ? (bool) $cached['spf'] : null;
		$dmarc = isset( $cached['dmarc'] ) ? (bool) $cached['dmarc'] : null;

		if ( self::RECOMMENDED === self::sender_level( $spf, $dmarc ) ) {
			if ( false === $spf && false === $dmarc ) {
				/* translators: %s: domain */
				$label = __( 'No SPF and no DMARC record for the sender domain %s', 'mailspur-email-log' );
			} elseif ( false === $spf ) {
				/* translators: %s: domain */
				$label = __( 'No SPF record for the sender domain %s', 'mailspur-email-log' );
			} else {
				/* translators: %s: domain */
				$label = __( 'No DMARC record for the sender domain %s', 'mailspur-email-log' );
			}
			return self::result(
				'mailspur_sender',
				self::RECOMMENDED,
				sprintf( $label, $domain ),
				__( 'Without these DNS records, mailbox providers cannot verify that your site may send emails for this domain, so emails are more likely to land in spam. The sender check suggests records you can add at your domain provider.', 'mailspur-email-log' ),
				$link,
				$action
			);
		}
		if ( null === $spf || null === $dmarc ) {
			return self::result( 'mailspur_sender', self::GOOD, __( 'The sender domain could not be checked', 'mailspur-email-log' ), __( 'The DNS lookup failed or timed out. Try the sender check again later.', 'mailspur-email-log' ), $link, $action );
		}
		return self::result(
			'mailspur_sender',
			self::GOOD,
			/* translators: %s: domain */
			sprintf( __( 'The sender domain %s has SPF and DMARC records', 'mailspur-email-log' ), $domain ),
			__( 'Mailbox providers can verify that your site may send emails for this domain. The full sender check also looks at DKIM.', 'mailspur-email-log' ),
			$link,
			$action
		);
	}

	/**
	 * Domain of the default sender address: the same default and filter as wp_mail(), like SenderCheck::domains().
	 */
	public static function sender_domain(): string {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$from = (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		return SenderCheck::domain_of( $from );
	}

	/**
	 * @param array<int,array<string,mixed>> $records TXT records of _dmarc.<domain>.
	 */
	private static function has_dmarc( array $records ): bool {
		foreach ( SenderCheck::txt_values( $records ) as $txt ) {
			if ( preg_match( '/^v\s*=\s*DMARC1\s*(;|$)/i', trim( $txt ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------- result */

	/**
	 * A Site Health result in the format of WP_Site_Health (texts are escaped here).
	 *
	 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
	 */
	public static function result( string $test, string $status, string $label, string $description, string $url, string $action ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => 'Mailspur',
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( $url ), esc_html( $action ) ),
			'test'        => $test,
		);
	}
}
