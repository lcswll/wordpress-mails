<?php
/**
 * Emergency brake for mail floods: when far more emails leave than the site normally sends (abused contact form,
 * hacked site), alert once through the monitoring alert channels and – optionally – hold further mails until an
 * administrator releases or discards them.
 *
 * Cost per mail (only while the brake is not "off"): one small option read + write (a sliding one-hour counter).
 * Only when that counter is above the threshold does an indexed COUNT confirm the incident (at most once a minute).
 * The baseline (busiest hour of the last 14 days) is one grouped query, cached for 12 hours.
 *
 * Order per mail (see Staging):
 *   pre_wp_mail  PHP_INT_MIN  Staging::hold() → hold() (registered after Staging, so staging always wins)
 *   pre_wp_mail  PHP_INT_MAX  Logger::short_circuit → mailspur_finalize_row → finalize() sets "held" (reason "brake")
 *
 * Never held: password reset mails, the plugin's own mails (alerts, resend, release), mails that are not logged,
 * and anything the filter mailspur_brake_exempt lets through. Alerts are sent from WP-Cron, never while a mail
 * is being delivered.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small index range counts on the own table, cached or rare.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Logger;
use Mailspur\Modules\Insights\Alerts;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Brake {

	const OFF   = 'off';
	const ALERT = 'alert';
	const HOLD  = 'hold';
	const MODES = array( self::OFF, self::ALERT, self::HOLD );

	/** Automatic threshold: at least this many emails per hour … */
	const FLOOR = 50;

	/** … and this many times the busiest hour of the baseline period. */
	const FACTOR = 3;

	const BASELINE_DAYS = 14;

	/** No new incident for this long after an administrator released the held mails or reset the brake. */
	const PAUSE = 3600;

	/** Mails sent per release request. */
	const BATCH = 20;

	const COUNTER_OPTION     = 'mailspur_brake_counter';
	const STATE_OPTION       = 'mailspur_brake_state';
	const BASELINE_TRANSIENT = 'mailspur_brake_baseline';
	const HOOK               = 'mailspur_brake_check';

	/** Values of meta.delivery.held. */
	const HELD      = 'brake';
	const RELEASED  = 'brake_released';
	const DISCARDED = 'brake_discarded';

	/** Remembered past incidents (excluded from the baseline). */
	const HISTORY = 5;

	/**
	 * Clock, replaceable in tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/**
	 * Sends an alert: Alerts::dispatch( $type, $kind, $message, $settings ).
	 *
	 * @var callable|null
	 */
	private $dispatch;

	/** @var bool The current mail is held by the brake (set in pre_wp_mail, consumed by finalize()). */
	private static $held = false;

	/** @var bool The next mail is a password reset (set by the retrieve_password_message filter). */
	private static $password_reset = false;

	/** @var bool The current mail is being logged (set in the logger's capture phase). */
	private static $logged = false;

	/** @var bool A bulk release of brake-held mails is running. */
	private static $releasing = false;

	/**
	 * @param (callable():int)|null $now
	 */
	public function __construct( ?callable $now = null, ?callable $dispatch = null ) {
		$this->now      = $now ?? 'time';
		$this->dispatch = $dispatch;
	}

	public function register(): void {
		// Same priority as Staging::hold(), registered later: staging mode always decides first.
		add_filter( 'pre_wp_mail', array( $this, 'hold' ), PHP_INT_MIN, 2 );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 11, 2 );
		add_filter( 'mailspur_finalize_row', array( $this, 'finalize' ) );
		add_filter( 'retrieve_password_message', array( $this, 'password_reset' ), PHP_INT_MAX );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	public static function mode(): string {
		$mode = (string) Settings::get( 'brake_mode' );
		return in_array( $mode, self::MODES, true ) ? $mode : self::ALERT;
	}

	/* ------------------------------------------------------------------ per mail */

	/**
	 * Marks the next mail as a password reset (it is never held).
	 *
	 * @param mixed $message Unchanged.
	 * @return mixed
	 */
	public function password_reset( $message ) {
		self::$password_reset = true;
		return $message;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public function meta( $meta, string $phase ) {
		$meta = (array) $meta;
		if ( 'capture' !== $phase ) {
			return $meta;
		}
		self::$logged = true;
		if ( self::$releasing && Staging::$release ) {
			$meta['delivery']                = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
			$meta['delivery']['released_by'] = self::HELD;
		}
		return $meta;
	}

	/**
	 * Counts the mail and holds it while an incident is active in "alert and hold" mode.
	 *
	 * @param null|bool $result Non-null: staging mode or another plugin already handled the mail.
	 * @param mixed     $atts   wp_mail() arguments.
	 * @return null|bool
	 */
	public function hold( $result, $atts = array() ) {
		$password_reset       = self::$password_reset;
		$logged               = self::$logged;
		self::$password_reset = false;
		self::$logged         = false;
		self::$held           = false;

		if ( null !== $result ) {
			return $result;
		}
		try {
			$mode = self::mode();
			// The plugin's own mails (alerts, resend, release) are neither counted nor held.
			if ( self::OFF === $mode || Staging::$release || 0 === strpos( Logger::$source_override, 'mailspur:' ) ) {
				return $result;
			}

			$now   = $this->now();
			$count = $this->count( $now );
			$state = self::state();
			if ( empty( $state['active'] ) ) {
				$state = $this->maybe_start( $count, $now, $state, $mode );
			}

			if ( self::HOLD !== $mode || empty( $state['active'] ) || ! $logged || $password_reset ) {
				return $result;
			}
			/**
			 * Lets a mail through although the emergency brake holds emails (e.g. login codes).
			 *
			 * @param bool                $exempt Default false.
			 * @param array<string,mixed> $atts   wp_mail() arguments.
			 */
			if ( apply_filters( 'mailspur_brake_exempt', false, is_array( $atts ) ? $atts : array() ) ) {
				return $result;
			}

			if ( empty( $state['holding'] ) ) {
				$state['holding'] = true;
				self::save( $state );
			}
			self::$held = true;
			return true;
		} catch ( \Throwable $e ) { // The brake must never break delivery.
			self::$held = false;
			return $result;
		}
	}

	/**
	 * @param array<string,mixed> $data Columns of the final UPDATE.
	 * @return array<string,mixed>
	 */
	public function finalize( $data ) {
		$data = (array) $data;
		if ( ! self::$held ) {
			return $data;
		}
		self::$held     = false;
		$data['status'] = Repository::STATUS_HELD;
		$meta           = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		$meta['delivery']         = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
		$meta['delivery']['held'] = self::HELD;

		$data['meta'] = $meta;
		return $data;
	}

	/**
	 * Sliding one-hour counter: this hour's count plus the previous hour's, weighted by how much of it is still
	 * inside the window. Lost increments under heavy concurrency only make it trigger a little later.
	 *
	 * @return int Estimated mails in the last 60 minutes, including this one.
	 */
	public function count( int $now, bool $increment = true ): int {
		$stored = get_option( self::COUNTER_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$bucket = intdiv( $now, HOUR_IN_SECONDS );
		$last   = (int) ( $stored['b'] ?? 0 );

		$current  = $last === $bucket ? (int) ( $stored['c'] ?? 0 ) : 0;
		$previous = $last === $bucket ? (int) ( $stored['p'] ?? 0 ) : ( $last === $bucket - 1 ? (int) ( $stored['c'] ?? 0 ) : 0 );
		if ( $increment ) {
			++$current;
			update_option(
				self::COUNTER_OPTION,
				array(
					'b' => $bucket,
					'c' => $current,
					'p' => $previous,
				),
				false
			);
		}
		$weight = 1 - ( $now % HOUR_IN_SECONDS ) / HOUR_IN_SECONDS;
		return $current + (int) floor( $previous * $weight );
	}

	/**
	 * Starts an incident when the counter is above the threshold and the exact count confirms it.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,mixed> New state.
	 */
	private function maybe_start( int $count, int $now, array $state, string $mode ): array {
		// Cheap exit without the baseline: no threshold is lower than the fixed one or the floor.
		$fixed = (int) Settings::get( 'brake_threshold' );
		if ( $count <= ( $fixed > 0 ? $fixed : self::FLOOR ) || (int) ( $state['paused'] ?? 0 ) > $now ) {
			return $state;
		}
		$threshold = $this->threshold();
		if ( $count <= $threshold || (int) ( $state['verified'] ?? 0 ) > $now - MINUTE_IN_SECONDS ) {
			return $state;
		}

		$exact = $this->recent( max( $now - HOUR_IN_SECONDS, (int) ( $state['reset'] ?? 0 ) ) );
		if ( $exact <= $threshold ) {
			$state['verified'] = $now;
			self::save( $state );
			return $state;
		}

		$state = array_merge(
			$state,
			array(
				'active'    => true,
				'start'     => $now,
				'mode'      => $mode,
				'count'     => $exact,
				'threshold' => $threshold,
				'alerted'   => false,
				'verified'  => 0,
			)
		);
		self::save( $state );
		// The alert goes out from cron right away – never while this mail is being delivered.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $now, self::HOOK );
		}
		return $state;
	}

	/* --------------------------------------------------------------- threshold */

	/** Mails per hour above which the brake triggers. */
	public function threshold(): int {
		$fixed = (int) Settings::get( 'brake_threshold' );
		return $fixed > 0 ? $fixed : self::automatic( $this->baseline() );
	}

	/** Automatic threshold for a baseline (busiest hour). */
	public static function automatic( int $peak ): int {
		return max( self::FLOOR, self::FACTOR * $peak );
	}

	/**
	 * Busiest hour of the last 14 days (complete hours only, without the plugin's own mails and past incidents).
	 * Cached for 12 hours.
	 */
	public function baseline(): int {
		$cached = get_transient( self::BASELINE_TRANSIENT );
		if ( false !== $cached && is_numeric( $cached ) ) {
			return (int) $cached;
		}

		global $wpdb;
		$now    = $this->now();
		$until  = $now - $now % HOUR_IN_SECONDS;
		$where  = 'created_at >= %s AND created_at < %s AND source NOT LIKE %s';
		$params = array( Repository::table(), gmdate( 'Y-m-d H:i:s', $until - self::BASELINE_DAYS * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $until ), $wpdb->esc_like( 'mailspur:' ) . '%' );
		foreach ( self::incidents() as $incident ) {
			$where   .= ' AND NOT ( created_at >= %s AND created_at <= %s )';
			$params[] = gmdate( 'Y-m-d H:i:s', $incident[0] - HOUR_IN_SECONDS );
			$params[] = gmdate( 'Y-m-d H:i:s', $incident[1] );
		}

		// SUBSTR( created_at, 1, 13 ) = "Y-m-d H": the UTC hour, on MySQL/MariaDB and SQLite alike.
		$sql  = 'SELECT SUBSTR( created_at, 1, 13 ) AS h, COUNT(*) AS n FROM %i WHERE ' . $where . ' GROUP BY h ORDER BY n DESC LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from literals only.
		$peak = is_array( $row ) ? (int) ( $row['n'] ?? 0 ) : 0;

		set_transient( self::BASELINE_TRANSIENT, $peak, 12 * HOUR_IN_SECONDS );
		return $peak;
	}

	/**
	 * Past incidents as [start, end] timestamps.
	 *
	 * @return array<int,array{0:int,1:int}>
	 */
	private static function incidents(): array {
		$out = array();
		foreach ( (array) ( self::state()['history'] ?? array() ) as $incident ) {
			if ( is_array( $incident ) && isset( $incident[0], $incident[1] ) ) {
				$out[] = array( (int) $incident[0], (int) $incident[1] );
			}
		}
		return $out;
	}

	/** Exact number of logged mails since a timestamp, without the plugin's own mails (indexed range count). */
	public function recent( int $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND source NOT LIKE %s',
				Repository::table(),
				gmdate( 'Y-m-d H:i:s', $since ),
				$wpdb->esc_like( 'mailspur:' ) . '%'
			)
		);
	}

	/* ------------------------------------------------------------------- state */

	/** @return array<string,mixed> */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/** @param array<string,mixed> $state */
	private static function save( array $state ): void {
		update_option( self::STATE_OPTION, $state, true );
	}

	public static function active(): bool {
		return ! empty( self::state()['active'] );
	}

	/** Mails may be held right now (incident in hold mode, or held mails not yet released/discarded). */
	public static function holding(): bool {
		$state = self::state();
		return ! empty( $state['holding'] ) || ( ! empty( $state['active'] ) && self::HOLD === self::mode() );
	}

	/** Cron callback. */
	public function run(): void {
		try {
			$this->tick();
		} catch ( \Throwable $e ) { // Never break the other cron events.
			return;
		}
	}

	/**
	 * Cron: sends the alert of a new incident, ends an incident in "alert only" mode once the volume is back to
	 * normal, and checks again every 15 minutes while an incident is active.
	 *
	 * @return string What happened: '', 'alert', 'recovery' or 'waiting' (for tests).
	 */
	public function tick(): string {
		$state = self::state();
		if ( empty( $state['active'] ) ) {
			return '';
		}
		$now  = $this->now();
		$mode = self::mode();
		$done = 'waiting';

		if ( empty( $state['alerted'] ) ) {
			$state['top']     = $this->top( 'source', (int) $state['start'] );
			$state['alerted'] = true;
			self::save( $state );
			$this->alert( 'alert', self::message( $state, $mode ) );
			$done = 'alert';
		} elseif ( self::HOLD !== $mode ) {
			// "Alert only" (or switched off): over once the last hour is below the threshold again.
			$count = $this->recent( max( $now - HOUR_IN_SECONDS, (int) ( $state['reset'] ?? 0 ) ) );
			if ( self::OFF === $mode || $count <= $this->threshold() ) {
				$this->end( false );
				return 'recovery';
			}
			$state['count'] = max( (int) ( $state['count'] ?? 0 ), $count );
			self::save( $state );
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $now + 15 * MINUTE_IN_SECONDS, self::HOOK );
		}
		return $done;
	}

	/**
	 * Ends the incident (recovery message when an alert went out and recovery messages are enabled).
	 *
	 * @param bool $pause Administrator decision "this is fine": no new incident for an hour, counter restarts.
	 */
	public function end( bool $pause ): void {
		$state = self::state();
		$now   = $this->now();
		if ( ! empty( $state['active'] ) ) {
			$history            = isset( $state['history'] ) && is_array( $state['history'] ) ? $state['history'] : array();
			$history[]          = array( (int) ( $state['start'] ?? $now ), $now );
			$state['history']   = array_slice( $history, -self::HISTORY );
			$state['active']    = false;
			$alerted            = ! empty( $state['alerted'] );
			$state['alerted']   = false;
			$state['ended']     = $now;
			$state['top']       = array();
			$state['verified']  = 0;
			$settings           = Settings::all();
			$state['recovered'] = $alerted && ! empty( $settings['alert_recovery'] );
			self::save( $state );
			delete_transient( self::BASELINE_TRANSIENT );
			if ( $state['recovered'] ) {
				$this->alert( 'recovery', __( 'Email volume is back to normal: the emergency brake is no longer active.', 'mailspur-email-log' ) );
			}
		}
		if ( $pause ) {
			$state['paused'] = $now + self::PAUSE;
			$state['reset']  = $now;
			self::save( $state );
			delete_option( self::COUNTER_OPTION );
		}
	}

	/** Sends through the monitoring alert channels (if any is configured). */
	private function alert( string $kind, string $message ): void {
		$settings = Settings::all();
		if ( ! Alerts::has_channel( $settings ) ) {
			return;
		}
		try {
			call_user_func( $this->dispatch ?? array( new Alerts( $this->now ), 'dispatch' ), 'brake', $kind, $message, $settings );
		} catch ( \Throwable $e ) { // An unreachable webhook must not break the cron run.
			return;
		}
	}

	/**
	 * Alert text: volume, threshold, mode and main source – never recipients or contents.
	 *
	 * @param array<string,mixed> $state
	 */
	public static function message( array $state, string $mode ): string {
		$text = sprintf(
			/* translators: 1: number of emails, 2: number of emails per hour */
			__( '%1$s emails were sent within one hour – far more than usual (threshold: %2$s per hour). A contact form may be abused or the site may be compromised.', 'mailspur-email-log' ),
			number_format_i18n( (int) ( $state['count'] ?? 0 ) ),
			number_format_i18n( (int) ( $state['threshold'] ?? 0 ) )
		);
		$top = isset( $state['top'][0] ) && is_array( $state['top'][0] ) ? $state['top'][0] : null;
		if ( $top ) {
			/* translators: 1: plugin or theme name, 2: number of emails */
			$text .= ' ' . sprintf( __( 'Main source: %1$s (%2$s emails).', 'mailspur-email-log' ), \Mailspur\Modules\Insights\Stats::source_label( (string) $top['value'] ), number_format_i18n( (int) $top['count'] ) );
		}
		$text .= ' ' . ( self::HOLD === $mode
			? __( 'Further emails are held until an administrator releases or discards them.', 'mailspur-email-log' )
			: __( 'Emails are still being sent.', 'mailspur-email-log' ) );
		return $text;
	}

	/**
	 * Most frequent values of a column (source or recipients) since an hour before the incident started.
	 *
	 * @return array<int,array{value:string,count:int}>
	 */
	public function top( string $column, int $start, int $limit = 3 ): array {
		global $wpdb;
		$column = 'recipients' === $column ? 'recipients' : 'source';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i AS v, COUNT(*) AS n FROM %i WHERE created_at >= %s AND source NOT LIKE %s GROUP BY v ORDER BY n DESC LIMIT %d',
				$column,
				Repository::table(),
				gmdate( 'Y-m-d H:i:s', $start - HOUR_IN_SECONDS ),
				$wpdb->esc_like( 'mailspur:' ) . '%',
				$limit
			),
			ARRAY_A
		);
		$out    = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'value' => (string) ( $row['v'] ?? '' ),
					'count' => (int) ( $row['n'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/* ----------------------------------------------------------- held mails */

	/** LIKE pattern of entries held by the brake and not yet released or discarded. */
	private static function held_like(): string {
		global $wpdb;
		return '%' . $wpdb->esc_like( '"held":"' . self::HELD . '"' ) . '%';
	}

	/** Number of mails held by the brake that wait for a decision. */
	public function held_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %d AND meta LIKE %s', Repository::table(), Repository::STATUS_HELD, self::held_like() )
		);
	}

	/**
	 * Sends the next batch of held mails to their original recipients (oldest first). Ends the incident and pauses
	 * the brake for an hour, so the release itself and the following regular mails are not held again.
	 *
	 * @return array{sent:int,failed:int,remaining:int}
	 */
	public function release_batch( int $limit = self::BATCH ): array {
		global $wpdb;
		$this->end( true );

		$ids    = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE status = %d AND meta LIKE %s ORDER BY id ASC LIMIT %d', Repository::table(), Repository::STATUS_HELD, self::held_like(), max( 1, $limit ) )
			)
		);
		$result = array(
			'sent'      => 0,
			'failed'    => 0,
			'remaining' => 0,
		);
		$repo   = new Repository();
		foreach ( $ids as $id ) {
			$row = $repo->find( $id );
			if ( ! $row ) {
				continue;
			}
			// Marked first: a parallel request (second tab, CLI) never sends the same mail twice.
			self::mark( $row, self::RELEASED );
			if ( self::send( $row ) ) {
				++$result['sent'];
			} else {
				++$result['failed'];
			}
		}
		$result['remaining'] = $this->held_count();
		if ( 0 === $result['remaining'] ) {
			$this->clear_holding();
		}
		return $result;
	}

	/** Marks every held mail as discarded (they stay in the log, status "held") and ends the incident. */
	public function discard(): int {
		global $wpdb;
		$this->end( false );
		$count = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET meta = REPLACE( meta, %s, %s ) WHERE status = %d AND meta LIKE %s',
				Repository::table(),
				'"held":"' . self::HELD . '"',
				'"held":"' . self::DISCARDED . '"',
				Repository::STATUS_HELD,
				self::held_like()
			)
		);
		$this->clear_holding();
		return $count;
	}

	/** Administrator: "this is fine" – ends the incident and pauses the brake; held mails stay held. */
	public function reset(): void {
		$this->end( true );
		if ( 0 === $this->held_count() ) {
			$this->clear_holding();
		}
	}

	private function clear_holding(): void {
		$state = self::state();
		if ( ! empty( $state['holding'] ) ) {
			unset( $state['holding'] );
			self::save( $state );
		}
	}

	/**
	 * A held mail released one by one ("Send now"): the bulk release must not send it again.
	 *
	 * @param array<string,mixed> $row Log row.
	 */
	public static function released( array $row ): void {
		$meta = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		if ( self::HELD === ( $meta['delivery']['held'] ?? '' ) ) {
			self::mark( $row, self::RELEASED );
		}
	}

	/**
	 * @param array<string,mixed> $row Log row.
	 */
	private static function mark( array $row, string $reason ): void {
		$meta                     = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		$meta['delivery']         = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
		$meta['delivery']['held'] = $reason;
		( new Repository() )->update( (int) $row['id'], array( 'meta' => Logger::encode( $meta ) ) );
	}

	/**
	 * Sends one held mail like "Send now" (staging mode and the brake are bypassed for it). Logged as a new entry.
	 *
	 * @param array<string,mixed> $row Log row.
	 */
	private static function send( array $row ): bool {
		$meta = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		if ( ! empty( $meta['anonymised'] ) ) {
			return false;
		}
		$files = array();
		$json  = json_decode( (string) ( $row['attachments'] ?? '' ), true );
		foreach ( is_array( $json ) ? $json : array() as $item ) {
			if ( is_array( $item ) && isset( $item['name'], $item['path'] ) && self::allowed_file( (string) $item['path'] ) ) {
				$files[ sanitize_file_name( (string) $item['name'] ) ] = (string) $item['path'];
			}
		}

		$previous                = Logger::$source_override;
		Staging::$release        = (int) $row['id'];
		self::$releasing         = true;
		Logger::$source_override = 'mailspur:resend';
		try {
			return (bool) wp_mail( (string) $row['recipients'], (string) $row['subject'], (string) $row['message'], array_values( array_filter( explode( "\n", (string) $row['headers'] ) ) ), $files );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			Staging::$release        = 0;
			self::$releasing         = false;
			Logger::$source_override = $previous;
		}
	}

	/** Same rule as the admin resend: only readable files inside the WordPress installation. */
	private static function allowed_file( string $path ): bool {
		$real = realpath( $path );
		if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
			$root = trailingslashit( wp_normalize_path( (string) realpath( $root ) ) );
			if ( '/' !== $root && 0 === strpos( $real, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Status for the admin notice, REST and WP-CLI.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$state  = self::state();
		$active = ! empty( $state['active'] );
		$held   = ( $active || ! empty( $state['holding'] ) ) ? $this->held_count() : 0;
		$start  = (int) ( $state['start'] ?? 0 );
		return array(
			'mode'       => self::mode(),
			'active'     => $active,
			'since'      => $active ? $start : 0,
			'count'      => $active ? max( (int) ( $state['count'] ?? 0 ), $this->recent( max( $start - HOUR_IN_SECONDS, $this->now() - HOUR_IN_SECONDS ) ) ) : 0,
			'threshold'  => $this->threshold(),
			'held'       => $held,
			'sources'    => $active || $held ? $this->top( 'source', $active ? $start : $this->now() - DAY_IN_SECONDS ) : array(),
			'recipients' => $active || $held ? $this->top( 'recipients', $active ? $start : $this->now() - DAY_IN_SECONDS ) : array(),
			'paused'     => max( 0, (int) ( $state['paused'] ?? 0 ) - $this->now() ),
		);
	}

	/** Test helper: forget per-request state. */
	public static function reset_request(): void {
		self::$held           = false;
		self::$password_reset = false;
		self::$logged         = false;
		self::$releasing      = false;
	}
}
