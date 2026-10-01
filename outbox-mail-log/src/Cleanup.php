<?php
/**
 * Daily retention cleanup via WP-Cron.
 *
 * @package OutboxMailLog
 */

namespace OutboxMailLog;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	const HOOK = 'outbox_mail_log_cleanup';

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) ); // Self-heal if the event got lost.
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function run(): void {
		$days = (int) Settings::get( 'retention_days' );
		if ( $days > 0 ) {
			$this->repository->delete_before( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		}

		$max = (int) Settings::get( 'max_entries' );
		if ( $max > 0 ) {
			$this->repository->trim_to( $max );
		}
	}
}
