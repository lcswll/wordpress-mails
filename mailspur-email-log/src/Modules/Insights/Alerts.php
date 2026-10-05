<?php
/**
 * Monitoring alerts: failure spikes and unusual silence, sent by email and/or webhook. Opt-in, off by default.
 *
 * - Evaluated by WP-Cron every 15 minutes (hook mailspur_insights_check, schedule mailspur_15min) while an
 *   alert type is enabled. A failed mail only appends its timestamp to a small option (no query); when the
 *   threshold is reached an immediate one-off check is scheduled – the actual sending never happens while a
 *   mail is being delivered.
 * - Alert mails are logged with the source "mailspur:alert" and are never counted themselves.
 * - Per alert type: cooldown (no repeated alert within N minutes) and an optional recovery message.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small index range counts on the own table, run from cron only.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use Mailspur\Admin;
use Mailspur\Logger;
use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Alerts {

	const HOOK          = 'mailspur_insights_check';
	const HOOK_NOW      = 'mailspur_insights_check_now';
	const SCHEDULE      = 'mailspur_15min';
	const STATE_OPTION  = 'mailspur_insights_alert_state';
	const LOG_OPTION    = 'mailspur_insights_alert_log';
	const FAILS_OPTION  = 'mailspur_insights_recent_failures';
	const HISTORY       = 50;
	const BASELINE_DAYS = 14;

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

	/**
	 * Defaults of the alert settings (all alerts off).
	 *
	 * @return array<string,string|int|bool>
	 */
	public static function defaults(): array {
		return array(
			'alert_failures'         => false,
			'alert_failures_count'   => 5,
			'alert_failures_minutes' => 15,
			'alert_silence'          => false,
			'alert_silence_hours'    => 6,
			'alert_email'            => '',
			'alert_webhook'          => '',
			'alert_cooldown'         => 60,
			'alert_recovery'         => true,
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,string|int|bool>
	 */
	public static function sanitize( array $input ): array {
		$int = static function ( string $key, int $min, int $max ) use ( $input ): int {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (int) $input[ $key ] : (int) self::defaults()[ $key ];
			return max( $min, min( $max, $value ) );
		};

		return array(
			'alert_failures'         => ! empty( $input['alert_failures'] ),
			'alert_failures_count'   => $int( 'alert_failures_count', 1, 1000 ),
			'alert_failures_minutes' => $int( 'alert_failures_minutes', 1, 1440 ),
			'alert_silence'          => ! empty( $input['alert_silence'] ),
			'alert_silence_hours'    => $int( 'alert_silence_hours', 1, 168 ),
			'alert_email'            => self::sanitize_emails( isset( $input['alert_email'] ) && is_string( $input['alert_email'] ) ? $input['alert_email'] : '' ),
			'alert_webhook'          => self::sanitize_webhook( isset( $input['alert_webhook'] ) && is_string( $input['alert_webhook'] ) ? $input['alert_webhook'] : '' ),
			'alert_cooldown'         => $int( 'alert_cooldown', 5, 1440 ),
			'alert_recovery'         => ! empty( $input['alert_recovery'] ),
		);
	}

	/** Comma-separated list of up to 5 valid addresses. */
	public static function sanitize_emails( string $value ): string {
		$out = array();
		foreach ( (array) preg_split( '/[\s,;]+/', $value ) as $email ) {
			$email = (string) $email;
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $out, true ) ) {
				$out[] = $email;
			}
		}
		return implode( ', ', array_slice( $out, 0, 5 ) );
	}

	/** Only https URLs with a host; anything else is dropped. */
	public static function sanitize_webhook( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$url   = esc_url_raw( $value, array( 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return '';
		}
		return substr( $url, 0, 500 );
	}

	/** @param array<string,mixed> $settings */
	public static function enabled( array $settings ): bool {
		return ( ! empty( $settings['alert_failures'] ) || ! empty( $settings['alert_silence'] ) ) && self::has_channel( $settings );
	}

	/** @param array<string,mixed> $settings */
	public static function has_channel( array $settings ): bool {
		return '' !== (string) ( $settings['alert_email'] ?? '' ) || '' !== (string) ( $settings['alert_webhook'] ?? '' );
	}

	/**
	 * @param mixed $schedules Registered schedules.
	 * @return array<string,array<int|string,int|string>>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Mailspur alerts)', 'mailspur-email-log' ),
		);
		return $schedules;
	}

	/** Keeps the cron event in sync with the settings (runs in the admin and after saving the settings). */
	public static function sync_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( self::enabled( Settings::all() ) ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Cheap counter on every logged mail: only failed mails write (one small option), never a query or request.
	 *
	 * @param int   $id  Log entry id.
	 * @param mixed $row Logged row.
	 */
	public function on_logged( $id, $row ): void {
		if ( ! is_array( $row ) || Repository::STATUS_FAILED !== (int) ( $row['status'] ?? -1 ) || Stats::ALERT_SOURCE === ( $row['source'] ?? '' ) ) {
			return;
		}
		$settings = Settings::all();
		if ( empty( $settings['alert_failures'] ) || ! self::has_channel( $settings ) ) {
			return;
		}

		$now    = $this->now();
		$window = (int) $settings['alert_failures_minutes'] * MINUTE_IN_SECONDS;
		$needed = (int) $settings['alert_failures_count'];
		$times  = array();
		foreach ( (array) get_option( self::FAILS_OPTION, array() ) as $time ) {
			if ( (int) $time > $now - $window ) {
				$times[] = (int) $time;
			}
		}
		$times[] = $now;
		$times   = array_slice( $times, -$needed );
		update_option( self::FAILS_OPTION, $times, true );

		if ( count( $times ) >= $needed && ! $this->cooling_down( 'failures', $settings ) && ! wp_next_scheduled( self::HOOK_NOW ) ) {
			wp_schedule_single_event( $now, self::HOOK_NOW );
		}
	}

	/** Cron callback. */
	public function run(): void {
		$this->check();
	}

	/**
	 * Evaluates all enabled alerts (cron).
	 *
	 * @return array<string,bool> Condition per evaluated type (for tests).
	 */
	public function check(): array {
		$settings = Settings::all();
		$result   = array();
		if ( ! self::has_channel( $settings ) ) {
			return $result;
		}

		if ( ! empty( $settings['alert_failures'] ) ) {
			$minutes = (int) $settings['alert_failures_minutes'];
			$count   = $this->failures_since( $this->now() - $minutes * MINUTE_IN_SECONDS );
			$active  = $count >= (int) $settings['alert_failures_count'];

			$result['failures'] = $active;
			$this->handle(
				'failures',
				$active,
				/* translators: 1: number of failed emails, 2: number of minutes */
				sprintf( __( '%1$s emails failed within the last %2$s minutes.', 'mailspur-email-log' ), number_format_i18n( $count ), number_format_i18n( $minutes ) ),
				__( 'Sending works again: no more failure spike.', 'mailspur-email-log' ),
				$settings
			);
		}

		if ( ! empty( $settings['alert_silence'] ) ) {
			$hours   = (int) $settings['alert_silence_hours'];
			$silence = $this->silence( $hours );

			$result['silence'] = null !== $silence;
			$this->handle(
				'silence',
				null !== $silence,
				null === $silence ? '' : sprintf(
					/* translators: 1: number of hours, 2: number of days with emails, 3: number of days looked at, 4: average number of emails */
					__( 'No email was logged in the last %1$s hours, although this site sent emails in this time window on %2$s of the last %3$s days (on average %4$s).', 'mailspur-email-log' ),
					number_format_i18n( $hours ),
					number_format_i18n( $silence['days'] ),
					number_format_i18n( self::BASELINE_DAYS ),
					number_format_i18n( $silence['average'], 1 )
				),
				__( 'Emails are being logged again.', 'mailspur-email-log' ),
				$settings
			);
		}

		if ( empty( $result['failures'] ) ) {
			delete_option( self::FAILS_OPTION );
		}
		return $result;
	}

	/** Failed mails since a timestamp, without the plugin's own alert mails. */
	public function failures_since( int $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %d AND created_at >= %s AND source <> %s',
				Repository::table(),
				Repository::STATUS_FAILED,
				gmdate( 'Y-m-d H:i:s', $since ),
				Stats::ALERT_SOURCE
			)
		);
	}

	/**
	 * Silence check. Mails are "expected" in the current window when, during the last 14 days, the same time
	 * window had mails on at least 10 days AND on both days with the same weekday (7 and 14 days ago) – so a
	 * site that only sends on weekdays does not alert on weekends.
	 *
	 * @return array{days:int,average:float}|null Baseline when the silence is unusual, null otherwise.
	 */
	public function silence( int $hours ): ?array {
		global $wpdb;
		$now    = $this->now();
		$window = $hours * HOUR_IN_SECONDS;
		$table  = Repository::table();

		$recent = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at <= %s AND source <> %s', $table, gmdate( 'Y-m-d H:i:s', $now - $window ), gmdate( 'Y-m-d H:i:s', $now ), Stats::ALERT_SOURCE )
		);
		if ( $recent > 0 ) {
			return null;
		}

		$counts = array();
		for ( $d = 1; $d <= self::BASELINE_DAYS; $d++ ) {
			$end          = $now - $d * DAY_IN_SECONDS;
			$counts[ $d ] = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at < %s', $table, gmdate( 'Y-m-d H:i:s', $end - $window ), gmdate( 'Y-m-d H:i:s', $end ) )
			);
		}
		return self::expected( $counts );
	}

	/**
	 * @param array<int,int> $counts Mails in the window, keyed by "days ago" (1 … 14).
	 * @return array{days:int,average:float}|null
	 */
	public static function expected( array $counts ): ?array {
		$days = count(
			array_filter(
				$counts,
				static function ( int $n ): bool {
					return $n > 0;
				}
			)
		);
		if ( $days < 10 || empty( $counts[7] ) || empty( $counts[14] ) ) {
			return null;
		}
		return array(
			'days'    => $days,
			'average' => round( array_sum( $counts ) / max( 1, count( $counts ) ), 1 ),
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function cooling_down( string $type, array $settings ): bool {
		$state = $this->state();
		$sent  = (int) ( $state[ $type ]['sent'] ?? 0 );
		return $sent > 0 && $this->now() - $sent < (int) $settings['alert_cooldown'] * MINUTE_IN_SECONDS;
	}

	/**
	 * State machine per alert type: alert (respecting the cooldown), then recovery once the condition is gone.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function handle( string $type, bool $condition, string $message, string $recovery, array $settings ): void {
		$state   = $this->state();
		$current = $state[ $type ] ?? array(
			'active' => false,
			'sent'   => 0,
		);

		if ( $condition ) {
			if ( $this->cooling_down( $type, $settings ) ) {
				return;
			}
			$this->dispatch( $type, 'alert', $message, $settings );
			$current = array(
				'active' => true,
				'sent'   => $this->now(),
			);
		} elseif ( ! empty( $current['active'] ) ) {
			if ( ! empty( $settings['alert_recovery'] ) ) {
				$this->dispatch( $type, 'recovery', $recovery, $settings );
			}
			$current['active'] = false;
		} else {
			return;
		}

		$state[ $type ] = $current;
		update_option( self::STATE_OPTION, $state, false );
	}

	/** @return array<string,array{active:bool,sent:int}> */
	private function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Sends a test alert through the configured channels.
	 *
	 * @return array{email:bool|null,webhook:int|string|null}
	 */
	public function test(): array {
		return $this->dispatch( 'test', 'test', __( 'This is a test alert. Your alert channels work.', 'mailspur-email-log' ), Settings::all() );
	}

	/**
	 * Sends one message to every configured channel and records it in the history.
	 *
	 * @param array<string,mixed> $settings
	 * @return array{email:bool|null,webhook:int|string|null} Per channel: null = not configured.
	 */
	public function dispatch( string $type, string $kind, string $message, array $settings ): array {
		$results = array(
			'email'   => null,
			'webhook' => null,
		);
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title   = self::title( $type, $kind );
		$link    = Admin::url( 'failures' === $type ? array( 'status' => 'failed' ) : ( 'type' === $type ? array( 'tab' => 'types' ) : array() ) );

		$to = (string) ( $settings['alert_email'] ?? '' );
		if ( '' !== $to ) {
			$body = $message . "\n\n" . $link . "\n\n"
				. __( 'You receive this email because monitoring alerts are enabled in the Mailspur settings of this site.', 'mailspur-email-log' );

			$previous                = Logger::$source_override;
			Logger::$source_override = Stats::ALERT_SOURCE;
			try {
				$results['email'] = (bool) wp_mail( $to, sprintf( '[%s] %s', $site, $title ), $body );
			} catch ( \Throwable $e ) {
				$results['email'] = false;
			} finally {
				Logger::$source_override = $previous;
			}
		}

		$url = (string) ( $settings['alert_webhook'] ?? '' );
		if ( '' !== $url ) {
			$response           = wp_safe_remote_post(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'        => (string) wp_json_encode( self::payload( $url, $type, $kind, $site, $title, $message, $link ) ),
				)
			);
			$results['webhook'] = is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );
		}

		$this->record( $type, $kind, $message, $results );
		return $results;
	}

	public static function title( string $type, string $kind ): string {
		if ( 'test' === $kind ) {
			return __( 'Test alert', 'mailspur-email-log' );
		}
		if ( 'type' === $type ) {
			return 'recovery' === $kind ? __( 'Email type is sent again', 'mailspur-email-log' ) : __( 'Email type stopped', 'mailspur-email-log' );
		}
		if ( 'brake' === $type ) {
			return 'recovery' === $kind ? __( 'Email volume back to normal', 'mailspur-email-log' ) : __( 'Emergency brake: unusual email flood', 'mailspur-email-log' );
		}
		if ( 'recovery' === $kind ) {
			return 'failures' === $type ? __( 'Email failures resolved', 'mailspur-email-log' ) : __( 'Emails are flowing again', 'mailspur-email-log' );
		}
		return 'failures' === $type ? __( 'Email failure spike', 'mailspur-email-log' ) : __( 'No emails sent', 'mailspur-email-log' );
	}

	/**
	 * Request body for Slack, Discord or any other endpoint (generic JSON).
	 *
	 * @return array<string,mixed>
	 */
	public static function payload( string $url, string $type, string $kind, string $site, string $title, string $message, string $link ): array {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$text = sprintf( '%s – %s: %s', $site, $title, $message );

		if ( 'hooks.slack.com' === $host ) {
			return array( 'text' => $text . "\n<" . $link . '|' . __( 'Open the mail log', 'mailspur-email-log' ) . '>' );
		}
		if ( in_array( $host, array( 'discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com' ), true ) && 0 === strpos( $path, '/api/webhooks/' ) ) {
			return array( 'content' => $text . "\n" . $link );
		}
		return array(
			'event'     => 'mailspur.' . $kind,
			'type'      => $type,
			'kind'      => $kind,
			'title'     => $title,
			'message'   => $message,
			'site'      => $site,
			'site_url'  => home_url( '/' ),
			'log_url'   => $link,
			'timestamp' => gmdate( 'c' ),
		);
	}

	/**
	 * @param array{email:bool|null,webhook:int|string|null} $results
	 */
	private function record( string $type, string $kind, string $message, array $results ): void {
		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'time'    => $this->now(),
			'type'    => $type,
			'kind'    => $kind,
			'message' => $message,
			'email'   => $results['email'],
			'webhook' => $results['webhook'],
		);
		update_option( self::LOG_OPTION, array_slice( $log, -self::HISTORY ), false );
	}

	/**
	 * Alert history, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? array_reverse( array_values( array_filter( $log, 'is_array' ) ) ) : array();
	}
}
