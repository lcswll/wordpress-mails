<?php
/**
 * Daily digest of bundled emails (Bundle): once a day (8:00 site time) every administrator gets one email that
 * lists the held emails addressed to them – subject, time, the start of the text and the first link – with links
 * to the log. The listed entries are then marked as delivered in the digest: status "sent", meta.delivery.digest
 * (time), so statistics and alerts do not count them as held or failed, and the type counters drop them as held.
 *
 * Robust against a WP-Cron that does not run on time: the hourly types run sends an overdue digest (oldest
 * entry older than a day), at most MAX_ROWS entries per run (the rest follows with the next run) and at most
 * LIST_MAX entries listed per digest. Switching bundling off for a type sends what waits right away.
 *
 * The digest is built from the log entries, so secrets masked in the log stay masked. It is logged with the
 * source of the alerts, so it is never bundled, held by the emergency brake or counted itself.
 *
 * Direct queries: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use DateTimeImmutable;
use Mailspur\Admin;
use Mailspur\Logger;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Repository;
use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Digest {

	const HOOK = 'mailspur_types_digest';
	const LOCK = 'mailspur_types_digest_lock';

	/** Entries handled per run. */
	const MAX_ROWS = 300;

	/** Entries listed per digest; further ones are counted. */
	const LIST_MAX = 50;

	/** An entry waiting longer than this is overdue (the daily run did not happen). */
	const OVERDUE = DAY_IN_SECONDS + HOUR_IN_SECONDS;

	/** Characters of the text shown per entry. */
	const EXCERPT = 200;

	/** Logged as one of the plugin's own emails (Insights\Stats::ALERT_SOURCE). */
	const SOURCE = 'mailspur:alert';

	/**
	 * Clock, replaceable in tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/** @var Store */
	private $store;

	/** @param (callable():int)|null $now */
	public function __construct( Store $store, ?callable $now = null ) {
		$this->store = $store;
		$this->now   = $now ?? 'time';
	}

	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	/** Keeps the daily event in sync: scheduled while a type is bundled or emails wait. */
	public static function schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Bundle::map() ) {
			if ( ! $next ) {
				wp_schedule_event( self::first_run( time() ), 'daily', self::HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/** Next 8:00 site time. */
	public static function first_run( int $now ): int {
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		$today = $local->setTime( 8, 0 );
		return ( $today->getTimestamp() > $now ? $today : $today->modify( '+1 day' ) )->getTimestamp();
	}

	/** Cron callback. */
	public function cron(): void {
		try {
			$this->run();
		} catch ( \Throwable $e ) { // Never break the other cron events.
			return;
		}
	}

	/** Hourly: sends an overdue digest when the daily event did not run. */
	public function overdue(): void {
		global $wpdb;
		try {
			$oldest = (string) $wpdb->get_var(
				$wpdb->prepare( 'SELECT MIN(created_at) FROM %i WHERE status = %d AND meta LIKE %s', Repository::table(), Repository::STATUS_HELD, self::like() )
			);
			if ( '' !== $oldest && (int) strtotime( $oldest . ' UTC' ) < $this->now() - self::OVERDUE ) {
				$this->run();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** LIKE pattern of entries waiting for the digest. */
	private static function like(): string {
		global $wpdb;
		return '%' . $wpdb->esc_like( '"held":"' . Bundle::HELD . '"' ) . '%';
	}

	/**
	 * Sends the digests of the waiting entries (all types or one) and marks the entries.
	 *
	 * @return array{entries:int,sent:int,failed:int} Entries delivered, digests sent and failed.
	 */
	public function run( int $type = 0 ): array {
		global $wpdb;
		$done = array(
			'entries' => 0,
			'sent'    => 0,
			'failed'  => 0,
		);
		// Staging mode would hold the digest as well: the entries wait.
		if ( class_exists( Staging::class ) && Staging::HOLD === Staging::effective_mode() ) {
			return $done;
		}
		$now = $this->now();
		if ( ! add_option( self::LOCK, $now + 300, '', false ) ) {
			if ( (int) get_option( self::LOCK ) > $now ) {
				return $done;
			}
			update_option( self::LOCK, $now + 300, false );
		}

		try {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, created_at, recipients, subject, message, content_type, meta FROM %i WHERE status = %d AND meta LIKE %s ORDER BY id ASC LIMIT %d',
					Repository::table(),
					Repository::STATUS_HELD,
					self::like(),
					self::MAX_ROWS
				),
				ARRAY_A
			);

			$labels = array();
			foreach ( $this->store->types() as $id => $t ) {
				$labels[ $id ] = Report::text( (array) $t['pattern'] );
			}
			$by_to   = array();
			$entries = array();
			foreach ( $rows as $row ) {
				$meta = Rest::decode_meta( (string) $row['meta'] );
				$of   = (int) ( $meta['delivery']['bundle'] ?? 0 );
				if ( Bundle::HELD !== ( $meta['delivery']['held'] ?? '' ) || ( $type && $type !== $of ) ) {
					continue;
				}
				$id             = (int) $row['id'];
				$entries[ $id ] = array(
					'row'  => $row,
					'meta' => $meta,
					'type' => $of,
					'to'   => array_values( array_unique( Repository::extract_emails( (string) $row['recipients'] ) ) ),
				);
				foreach ( $entries[ $id ]['to'] as $address ) {
					$by_to[ $address ][] = $id;
				}
			}

			$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$ok   = array();
			foreach ( $by_to as $address => $ids ) {
				$list = array();
				foreach ( $ids as $id ) {
					$row    = $entries[ $id ]['row'];
					$html   = Content::is_html( (string) $row['content_type'], (string) $row['message'] );
					$list[] = array(
						'id'      => $id,
						'subject' => (string) $row['subject'],
						'time'    => (int) strtotime( $row['created_at'] . ' UTC' ),
						'excerpt' => self::excerpt( (string) $row['message'], $html ),
						'link'    => self::first_link( (string) $row['message'], $html ),
						'type'    => $labels[ $entries[ $id ]['type'] ] ?? '',
					);
				}
				$mail = self::compose( $list, $site, Admin::url(), Admin::url( array( 'tab' => Page::TAB ) ) );
				if ( $this->send( (string) $address, $mail ) ) {
					++$done['sent'];
					foreach ( $ids as $id ) {
						$ok[ $id ] = ( $ok[ $id ] ?? 0 ) + 1;
					}
				} else {
					++$done['failed'];
				}
			}

			// Delivered once every recipient got their digest; otherwise the entry waits for the next run.
			$unhold = array();
			$cursor = (int) get_option( Indexer::CURSOR, 0 );
			foreach ( $entries as $id => $entry ) {
				if ( ! $entry['to'] || ( $ok[ $id ] ?? 0 ) < count( $entry['to'] ) ) {
					continue;
				}
				$meta                       = $entry['meta'];
				$meta['delivery']['digest'] = $now;
				unset( $meta['delivery']['held'] );
				( new Repository() )->update(
					$id,
					array(
						'status' => Repository::STATUS_SENT,
						'meta'   => Logger::encode( $meta ),
					)
				);
				++$done['entries'];
				if ( $entry['type'] && $id <= $cursor ) {
					$day                              = (string) wp_date( 'Y-m-d', (int) strtotime( $entry['row']['created_at'] . ' UTC' ) );
					$unhold[ $entry['type'] ][ $day ] = ( $unhold[ $entry['type'] ][ $day ] ?? 0 ) + 1;
				}
			}
			if ( $unhold ) {
				$this->store->unhold( $unhold );
			}
		} finally {
			delete_option( self::LOCK );
		}
		return $done;
	}

	/**
	 * @param array{subject:string,body:string} $mail
	 */
	private function send( string $to, array $mail ): bool {
		$previous                = Logger::$source_override;
		Logger::$source_override = self::SOURCE;
		try {
			return (bool) wp_mail( $to, $mail['subject'], $mail['body'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			Logger::$source_override = $previous;
		}
	}

	/** Start of an email's readable text. */
	public static function excerpt( string $message, bool $html ): string {
		$lines = Content::lines( substr( $message, 0, 20000 ), $html );
		$text  = trim( (string) preg_replace( '/ \[(?:https?:)?\/\/[^\]]*\]/i', '', implode( ' ', array_slice( $lines, 0, 12 ) ) ) );
		if ( mb_strlen( $text, 'UTF-8' ) > self::EXCERPT ) {
			$text = rtrim( mb_substr( $text, 0, self::EXCERPT - 1, 'UTF-8' ) ) . '…';
		}
		return $text;
	}

	/** First web link of an email (a link target in HTML), '' when there is none. */
	public static function first_link( string $message, bool $html ): string {
		$message = substr( $message, 0, 20000 );
		if ( $html && preg_match( '#<a\b[^>]*?\bhref\s*=\s*(["\'])\s*(https?://[^"\']+)\1#i', $message, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return preg_match( '#https?://[^\s<>"\']+#i', $message, $m ) ? rtrim( $m[0], '.,;:!?)' ) : '';
	}

	/**
	 * Subject and HTML body of one digest. Pure apart from translation, formatting and escaping.
	 *
	 * @param array<int,array{id:int,subject:string,time:int,excerpt:string,link:string,type:string}> $entries Oldest first.
	 * @param string                                                                                   $site    Site name (plain text).
	 * @param string                                                                                   $log     Link to the log.
	 * @param string                                                                                   $types   Link to the email types.
	 * @return array{subject:string,body:string}
	 */
	public static function compose( array $entries, string $site, string $log, string $types ): array {
		$count  = count( $entries );
		$counts = array();
		foreach ( $entries as $entry ) {
			$label            = trim( (string) preg_replace( '/^\[[^\]]*\]\s*/u', '', '' !== $entry['type'] ? $entry['type'] : $entry['subject'] ) );
			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}
		arsort( $counts );
		$main = (string) key( $counts );
		$main = ( '' !== $main ? $main : __( '(no subject)', 'mailspur-email-log' ) ) . ( count( $counts ) > 1 ? ' …' : '' );

		$subject = 1 === $count
			/* translators: 1: site name, 2: email type, e.g. "New comment awaiting moderation" */
			? sprintf( __( '[%1$s] 1 notification: %2$s', 'mailspur-email-log' ), $site, $main )
			/* translators: 1: site name, 2: number of emails, 3: email type, e.g. "New comment awaiting moderation …" */
			: sprintf( __( '[%1$s] %2$s notifications: %3$s', 'mailspur-email-log' ), $site, number_format_i18n( $count ), $main );

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$body   = '<div style="font:14px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327;max-width:600px">'
			. '<h1 style="font-size:18px;margin:0 0 4px">' . esc_html__( 'Daily digest', 'mailspur-email-log' ) . '</h1>'
			/* translators: 1: site name, 2: number of emails */
			. '<p style="margin:0 0 16px;color:#50575e">' . esc_html( sprintf( __( '%1$s · %2$s emails bundled since the last digest', 'mailspur-email-log' ), $site, number_format_i18n( $count ) ) ) . '</p>';
		foreach ( array_slice( $entries, 0, self::LIST_MAX ) as $entry ) {
			$entry_url = add_query_arg( 'mail', (string) (int) $entry['id'], $log );
			$body     .= '<div style="margin:0 0 14px;padding:0 0 12px;border-bottom:1px solid #dcdcde">'
				. '<p style="margin:0;font-weight:600"><a href="' . esc_url( $entry_url ) . '">' . esc_html( '' !== trim( $entry['subject'] ) ? $entry['subject'] : __( '(no subject)', 'mailspur-email-log' ) ) . '</a></p>'
				. '<p style="margin:0;color:#646970;font-size:12px">' . esc_html( (string) wp_date( $format, $entry['time'] ) ) . '</p>';
			if ( '' !== $entry['excerpt'] ) {
				$body .= '<p style="margin:4px 0 0">' . esc_html( $entry['excerpt'] ) . '</p>';
			}
			if ( '' !== $entry['link'] ) {
				$body .= '<p style="margin:4px 0 0;word-break:break-all"><a href="' . esc_url( $entry['link'] ) . '">' . esc_html( $entry['link'] ) . '</a></p>';
			}
			$body .= '</div>';
		}
		if ( $count > self::LIST_MAX ) {
			/* translators: %s: number of further emails */
			$body .= '<p>' . esc_html( sprintf( __( 'and %s more – see the log', 'mailspur-email-log' ), number_format_i18n( $count - self::LIST_MAX ) ) ) . '</p>';
		}
		$body .= '<p style="margin:16px 0"><a href="' . esc_url( $log ) . '">' . esc_html__( 'Open the mail log', 'mailspur-email-log' ) . '</a></p>'
			. '<p style="margin:16px 0 0;color:#646970;font-size:12px">' . esc_html__( 'You receive this digest because these email types are bundled into one daily email in Mailspur. Each email stays in the log.', 'mailspur-email-log' )
			. ' <a href="' . esc_url( $types ) . '">' . esc_html__( 'Change in the email types', 'mailspur-email-log' ) . '</a></p>'
			. '</div>';

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}
}
