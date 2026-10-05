<?php
/**
 * Weekly email report: a short HTML email to the alert recipients with the last 7 days – volume and failure rate
 * compared with the week before, new, stopped and changed email types, emails with notes and emergency-brake
 * incidents. Opt-in (setting "weekly_report"), sent by WP-Cron on Monday morning (site time) and only when
 * there is something to report.
 *
 * Never contains email contents or recipient addresses: counts, subject patterns and plugin names only.
 * The report itself is logged with the source of the alerts ("mailspur:alert"), so it is not counted.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- two grouped range counts on the own table, once a week.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use DateTimeImmutable;
use Mailspur\Admin;
use Mailspur\Logger;
use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Store;
use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Weekly {

	const HOOK        = 'mailspur_insights_weekly';
	const SENT_OPTION = 'mailspur_insights_weekly_sent';

	/** Entries per list (new / stopped / changed types). */
	const LIST_MAX = 10;

	/**
	 * Clock, replaceable in tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/** @param (callable():int)|null $now */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	/** @param array<string,mixed> $settings */
	public static function enabled( array $settings ): bool {
		return ! empty( $settings['weekly_report'] ) && '' !== (string) ( $settings['alert_email'] ?? '' );
	}

	/** Keeps the weekly cron event in sync with the settings (admin and after saving). */
	public static function sync_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( self::enabled( Settings::all() ) ) {
			if ( ! $next ) {
				wp_schedule_event( self::first_run( time() ), 'weekly', self::HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/** Next Monday, 8:00 site time. */
	public static function first_run( int $now ): int {
		return ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->modify( 'next monday 08:00' )->getTimestamp();
	}

	/** Cron callback: at most one report in 6 days (WP-Cron may fire late or twice). */
	public function run(): void {
		$last = (int) get_option( self::SENT_OPTION, 0 );
		if ( ! self::enabled( Settings::all() ) || ( $last > 0 && $this->now() - $last < 6 * DAY_IN_SECONDS ) ) {
			return;
		}
		try {
			$this->send( false );
		} catch ( \Throwable $e ) { // Never break the other cron events.
			return;
		}
	}

	/**
	 * Collects, composes and sends the report.
	 *
	 * @param bool $force Send even when there is nothing to report ("Send report now").
	 * @return bool|null Null when nothing was sent (no recipient, nothing to report).
	 */
	public function send( bool $force ): ?bool {
		$settings = Settings::all();
		$to       = (string) ( $settings['alert_email'] ?? '' );
		if ( '' === $to ) {
			return null;
		}
		$data = $this->collect();
		$mail = self::compose( $data, wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), $force );
		if ( null === $mail ) {
			return null;
		}

		$previous                = Logger::$source_override;
		Logger::$source_override = Stats::ALERT_SOURCE;
		try {
			$sent = (bool) wp_mail( $to, $mail['subject'], $mail['body'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		} catch ( \Throwable $e ) {
			$sent = false;
		} finally {
			Logger::$source_override = $previous;
		}
		if ( $sent && ! $force ) {
			update_option( self::SENT_OPTION, $this->now(), false );
		}
		return $sent;
	}

	/**
	 * Numbers of the last 7 full days (site time) and the 7 days before.
	 *
	 * @return array<string,mixed> See compose().
	 */
	public function collect(): array {
		$today = ( new DateTimeImmutable( '@' . $this->now() ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		$start = $today->modify( '-7 days' );
		$prev  = $today->modify( '-14 days' );
		$from  = $start->getTimestamp();
		$to    = $today->getTimestamp();

		$data = array(
			'from'     => $from,
			'to'       => $to - 1,
			'week'     => $this->counts( $from, $to ),
			'previous' => $this->counts( $prev->getTimestamp(), $from ),
			'notes'    => $this->notes( $from, $to ),
			'new'      => array(),
			'stopped'  => array(),
			'changed'  => array(),
			'brake'    => 0,
			'links'    => array(
				'log'    => Admin::url(),
				'failed' => Admin::url( array( 'status' => 'failed' ) ),
				'notes'  => Admin::url( array( 'notes' => '1' ) ),
				'types'  => Admin::url( array( 'tab' => 'types' ) ),
			),
		);

		if ( class_exists( Report::class ) ) {
			try {
				Store::maybe_install();
				$data = array_merge( $data, self::types( Report::current( new Store(), $this->now() ), $from ) );
			} catch ( \Throwable $e ) {
				$data['new'] = array(); // The report goes out without the types part.
			}
		}
		if ( class_exists( Brake::class ) ) {
			$data['brake'] = self::incidents( Brake::state(), $from, $to );
		}
		return $data;
	}

	/**
	 * Emails per status in a time range, without Mailspur's own emails.
	 *
	 * @return array{total:int,sent:int,failed:int,held:int,pending:int}
	 */
	private function counts( int $from, int $to ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s AND created_at < %s AND source NOT LIKE %s GROUP BY status',
				Repository::table(),
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to ),
				$wpdb->esc_like( 'mailspur:' ) . '%'
			),
			ARRAY_A
		);
		$by   = array();
		foreach ( $rows as $row ) {
			$slug        = Repository::status_slug( (int) $row['status'] );
			$by[ $slug ] = ( $by[ $slug ] ?? 0 ) + (int) $row['n'];
		}
		return array(
			'total'   => (int) array_sum( $by ),
			'sent'    => $by['sent'] ?? 0,
			'failed'  => $by['failed'] ?? 0,
			'held'    => $by['held'] ?? 0,
			'pending' => $by['pending'] ?? 0,
		);
	}

	/** Emails with notes in a time range. */
	private function notes( int $from, int $to ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at < %s AND notes > 0 AND source NOT LIKE %s',
				Repository::table(),
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to ),
				$wpdb->esc_like( 'mailspur:' ) . '%'
			)
		);
	}

	/**
	 * New (first seen this week), stopped (overdue now) and changed (content changed this week) email types.
	 *
	 * @param array<int,array<string,mixed>> $items Types report items.
	 * @return array{new:string[],stopped:string[],changed:string[]} "Pattern (sender)" each.
	 */
	public static function types( array $items, int $from ): array {
		$out = array(
			'new'     => array(),
			'stopped' => array(),
			'changed' => array(),
		);
		foreach ( $items as $item ) {
			if ( ! empty( $item['muted'] ) || ! empty( $item['other'] ) ) {
				continue;
			}
			$label = sprintf( '“%s” (%s)', Report::text( (array) $item['pattern'] ), Report::source_label( (string) $item['source'] ) );
			if ( (int) $item['first_seen'] >= $from ) {
				$out['new'][] = $label;
			}
			if ( 'silent' === $item['state'] ) {
				$out['stopped'][] = $label;
			}
			if ( is_array( $item['change'] ?? null ) && (int) $item['change']['after_at'] >= $from ) {
				$out['changed'][] = $label;
			}
		}
		return $out;
	}

	/**
	 * Emergency-brake incidents that started in a time range (past ones and a running one).
	 *
	 * @param array<string,mixed> $state Brake::state().
	 */
	public static function incidents( array $state, int $from, int $to ): int {
		$starts = array();
		foreach ( (array) ( $state['history'] ?? array() ) as $incident ) {
			if ( is_array( $incident ) && isset( $incident[0] ) ) {
				$starts[] = (int) $incident[0];
			}
		}
		if ( ! empty( $state['active'] ) ) {
			$starts[] = (int) ( $state['start'] ?? 0 );
		}
		$count = 0;
		foreach ( array_unique( $starts ) as $start ) {
			if ( $start >= $from && $start <= $to ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether the data contains anything worth an email.
	 *
	 * @param array<string,mixed> $data See compose().
	 */
	public static function noteworthy( array $data ): bool {
		return (int) $data['week']['total'] > 0 || (int) $data['previous']['total'] > 0
			|| $data['new'] || $data['stopped'] || $data['changed'] || (int) $data['brake'] > 0;
	}

	/**
	 * Subject and HTML body. Pure apart from translation, formatting and escaping.
	 *
	 * @param array<string,mixed> $data  collect(): from, to, week, previous (status counts), notes, new, stopped,
	 *                                   changed (labels), brake (incidents), links (log, failed, notes, types).
	 * @param string              $site  Site name (plain text).
	 * @param bool                $force Compose even without anything to report.
	 * @return array{subject:string,body:string}|null Null when there is nothing to report.
	 */
	public static function compose( array $data, string $site, bool $force = false ): ?array {
		if ( ! $force && ! self::noteworthy( $data ) ) {
			return null;
		}
		$week   = $data['week'];
		$prev   = $data['previous'];
		$format = (string) get_option( 'date_format' );
		$range  = wp_date( $format, (int) $data['from'] ) . ' – ' . wp_date( $format, (int) $data['to'] );
		$links  = (array) $data['links'];

		$rate      = Stats::rate( (int) $week['failed'], (int) $week['total'] );
		$prev_rate = Stats::rate( (int) $prev['failed'], (int) $prev['total'] );

		$rows = array(
			array(
				__( 'Emails', 'mailspur-email-log' ),
				/* translators: 1: number of emails, 2: number of emails in the previous week */
				sprintf( __( '%1$s (previous week: %2$s)', 'mailspur-email-log' ), number_format_i18n( (int) $week['total'] ), number_format_i18n( (int) $prev['total'] ) ),
				$links['log'] ?? '',
			),
			array(
				__( 'Failed', 'mailspur-email-log' ),
				/* translators: 1: number of failed emails, 2: failure rate in percent, 3: failure rate of the previous week in percent */
				sprintf( __( '%1$s – failure rate %2$s %% (previous week: %3$s %%)', 'mailspur-email-log' ), number_format_i18n( (int) $week['failed'] ), number_format_i18n( $rate, 1 ), number_format_i18n( $prev_rate, 1 ) ),
				(int) $week['failed'] > 0 ? ( $links['failed'] ?? '' ) : '',
			),
		);
		if ( (int) $week['held'] > 0 ) {
			$rows[] = array( __( 'Held', 'mailspur-email-log' ), number_format_i18n( (int) $week['held'] ), '' );
		}
		if ( (int) $data['notes'] > 0 ) {
			$rows[] = array( __( 'Emails with notes', 'mailspur-email-log' ), number_format_i18n( (int) $data['notes'] ), $links['notes'] ?? '' );
		}
		if ( (int) $data['brake'] > 0 ) {
			$rows[] = array(
				__( 'Emergency brake', 'mailspur-email-log' ),
				/* translators: %s: number of incidents */
				sprintf( __( 'triggered %s times', 'mailspur-email-log' ), number_format_i18n( (int) $data['brake'] ) ),
				'',
			);
		}

		$cell = 'padding:6px 12px 6px 0;border-bottom:1px solid #dcdcde;vertical-align:top;';
		$body = '<div style="font:14px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327;max-width:600px">'
			. '<h1 style="font-size:18px;margin:0 0 4px">' . esc_html__( 'Weekly email report', 'mailspur-email-log' ) . '</h1>'
			. '<p style="margin:0 0 16px;color:#50575e">' . esc_html( $site . ' · ' . $range ) . '</p>'
			. '<table role="presentation" style="border-collapse:collapse;margin:0 0 16px">';
		foreach ( $rows as $row ) {
			$value = '' !== $row[2] ? '<a href="' . esc_url( $row[2] ) . '">' . esc_html( $row[1] ) . '</a>' : esc_html( $row[1] );
			$body .= '<tr><th scope="row" style="' . $cell . 'text-align:left;font-weight:600">' . esc_html( $row[0] ) . '</th><td style="' . $cell . '">' . $value . '</td></tr>';
		}
		$body .= '</table>';

		$lists = array(
			'stopped' => __( 'Stopped email types', 'mailspur-email-log' ),
			'new'     => __( 'New email types', 'mailspur-email-log' ),
			'changed' => __( 'Content changed', 'mailspur-email-log' ),
		);
		$types = false;
		foreach ( $lists as $key => $title ) {
			$labels = array_values( (array) ( $data[ $key ] ?? array() ) );
			if ( ! $labels ) {
				continue;
			}
			$types = true;
			$body .= '<h2 style="font-size:15px;margin:16px 0 4px">' . esc_html( $title ) . '</h2><ul style="margin:0;padding-left:20px">';
			foreach ( array_slice( $labels, 0, self::LIST_MAX ) as $label ) {
				$body .= '<li>' . esc_html( (string) $label ) . '</li>';
			}
			if ( count( $labels ) > self::LIST_MAX ) {
				/* translators: %s: number of further entries */
				$body .= '<li>' . esc_html( sprintf( __( 'and %s more', 'mailspur-email-log' ), number_format_i18n( count( $labels ) - self::LIST_MAX ) ) ) . '</li>';
			}
			$body .= '</ul>';
		}
		if ( $types && ! empty( $links['types'] ) ) {
			$body .= '<p style="margin:8px 0 0"><a href="' . esc_url( (string) $links['types'] ) . '">' . esc_html__( 'Open the email types', 'mailspur-email-log' ) . '</a></p>';
		}
		if ( ! self::noteworthy( $data ) ) {
			$body .= '<p>' . esc_html__( 'No emails were logged in the last two weeks.', 'mailspur-email-log' ) . '</p>';
		}

		$body .= '<p style="margin:16px 0"><a href="' . esc_url( (string) ( $links['log'] ?? '' ) ) . '">' . esc_html__( 'Open the mail log', 'mailspur-email-log' ) . '</a></p>'
			. '<p style="margin:16px 0 0;color:#646970;font-size:12px">' . esc_html__( 'You receive this email because the weekly report is enabled in the Mailspur settings of this site. It contains counts and subject patterns only – no email contents or recipients.', 'mailspur-email-log' ) . '</p>'
			. '</div>';

		return array(
			/* translators: 1: site name, 2: date range */
			'subject' => sprintf( __( '[%1$s] Weekly email report %2$s', 'mailspur-email-log' ), $site, $range ),
			'body'    => $body,
		);
	}
}
