<?php
/**
 * Insights: statistics tab with charts, REST /stats, dashboard widget and monitoring alerts.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use Mailspur\Cleanup;
use Mailspur\Repository;
use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		$stats  = new Stats( $this->repository );
		$alerts = new Alerts();
		$page   = new Page();

		add_filter( 'mailspur_admin_tabs', array( $page, 'tabs' ) );
		add_action( 'mailspur_render_tab_' . Page::TAB, array( $page, 'render' ) );
		add_action( 'mailspur_admin_enqueue', array( $page, 'enqueue' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $page, 'settings_section' ), 10, 2 );
		add_filter( 'mailspur_settings_defaults', array( self::class, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( self::class, 'sanitize' ), 10, 2 );

		add_action(
			'rest_api_init',
			static function () use ( $stats, $alerts ): void {
				( new Controller( $stats, $alerts ) )->register_routes();
			}
		);
		add_action( 'wp_dashboard_setup', array( new Dashboard( $stats ), 'setup' ) );

		// Alerts: cheap counter at send time, evaluation in cron.
		add_filter( 'cron_schedules', array( Alerts::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutes, see Alerts.
		add_action( Alerts::HOOK, array( $alerts, 'run' ) );
		add_action( Alerts::HOOK_NOW, array( $alerts, 'run' ) );
		add_action( 'mailspur_logged', array( $alerts, 'on_logged' ), 10, 2 );
		add_action( 'admin_init', array( Alerts::class, 'sync_schedule' ) );
		add_action( 'update_option_mailspur_settings', array( Alerts::class, 'sync_schedule' ) );
		add_action( 'add_option_mailspur_settings', array( Alerts::class, 'sync_schedule' ) );

		// Cached statistics become stale when entries are deleted or imported.
		add_action( Cleanup::HOOK, array( Stats::class, 'flush' ), 20 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'flush_after_change' ), 10, 3 );
	}

	/**
	 * @param mixed $defaults Defaults.
	 * @return array<string,string|int|bool>
	 */
	public static function defaults( $defaults ): array {
		return array_merge( is_array( $defaults ) ? $defaults : array(), Alerts::defaults() );
	}

	/**
	 * @param mixed $clean Sanitized settings.
	 * @param mixed $input Raw form input.
	 * @return array<string,string|int|bool>
	 */
	public static function sanitize( $clean, $input ): array {
		$input = is_array( $input ) ? $input : array();
		$alert = Alerts::sanitize( $input );

		if ( '' === $alert['alert_webhook'] && ! empty( $input['alert_webhook'] ) ) {
			self::notice( 'mailspur_alert_webhook', __( 'The webhook URL was removed: only valid https URLs are allowed.', 'mailspur-email-log' ), 'error' );
		}
		if ( ( $alert['alert_failures'] || $alert['alert_silence'] ) && ! Alerts::has_channel( $alert ) ) {
			self::notice( 'mailspur_alert_channel', __( 'Alerts are enabled but have no channel: enter an email address or a webhook URL.', 'mailspur-email-log' ), 'warning' );
		}
		return array_merge( is_array( $clean ) ? $clean : array(), $alert );
	}

	/** Settings notice, once per request (WordPress sanitizes a new option twice). */
	private static function notice( string $code, string $message, string $type ): void {
		static $shown = array();
		if ( isset( $shown[ $code ] ) || ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		$shown[ $code ] = true;
		add_settings_error( 'mailspur_settings', $code, $message, $type );
	}

	/**
	 * Bumps the statistics cache generation after deletions and imports through the REST API.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $handler  Route handler.
	 * @param mixed            $request  Request.
	 * @return mixed Unchanged response.
	 */
	public static function flush_after_change( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && 'GET' !== $request->get_method() ) {
			$route = $request->get_route();
			if ( 0 === strpos( $route, '/' . Rest::NS . '/mails' ) || 0 === strpos( $route, '/' . Rest::NS . '/import' ) ) {
				Stats::flush();
			}
		}
		return $response;
	}
}
