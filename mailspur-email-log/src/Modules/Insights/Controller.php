<?php
/**
 * REST routes of the Insights module: GET /stats and POST /alerts/test.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use DateInterval;
use DateTimeImmutable;
use Mailspur\Rest;
use Mailspur\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/** @var Stats */
	private $stats;

	/** @var Alerts */
	private $alerts;

	/** @var Weekly */
	private $weekly;

	public function __construct( Stats $stats, Alerts $alerts, ?Weekly $weekly = null ) {
		$this->stats  = $stats;
		$this->alerts = $alerts;
		$this->weekly = $weekly ?? new Weekly();
	}

	public function register_routes(): void {
		$date = array(
			'type'    => 'string',
			'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			'default' => '',
		);

		register_rest_route(
			Rest::NS,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( Settings::class, 'current_user_can_view' ),
				'args'                => array(
					'from' => $date,
					'to'   => $date,
				),
			)
		);

		register_rest_route(
			Rest::NS,
			'/alerts/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_alert' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			Rest::NS,
			'/alerts/report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_report' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function stats( WP_REST_Request $request ) {
		$range = self::resolve_range( (string) $request['from'], (string) $request['to'], ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ) );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$response = new WP_REST_Response( $this->stats->get( $range[0], $range[1] ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Validated [from, to]; empty values default to the last 30 days.
	 *
	 * @return array{0:string,1:string}|WP_Error
	 */
	public static function resolve_range( string $from, string $to, string $today ) {
		$to   = '' === $to ? $today : $to;
		$from = '' === $from ? ( new DateTimeImmutable( $to ) )->sub( new DateInterval( 'P29D' ) )->format( 'Y-m-d' ) : $from;

		foreach ( array( $from, $to ) as $date ) {
			$parts = array_map( 'intval', explode( '-', $date ) );
			if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
				return new WP_Error( 'mailspur_invalid_range', __( 'Invalid date.', 'mailspur-email-log' ), array( 'status' => 400 ) );
			}
		}
		if ( $from > $to ) {
			return new WP_Error( 'mailspur_invalid_range', __( 'The start date must not be after the end date.', 'mailspur-email-log' ), array( 'status' => 400 ) );
		}
		if ( Stats::days_between( $from, $to ) > Stats::MAX_DAYS ) {
			/* translators: %s: maximum number of days */
			return new WP_Error( 'mailspur_invalid_range', sprintf( __( 'Choose a range of at most %s days.', 'mailspur-email-log' ), number_format_i18n( Stats::MAX_DAYS ) ), array( 'status' => 400 ) );
		}
		return array( $from, $to );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_alert() {
		if ( ! Alerts::has_channel( Settings::all() ) ) {
			return new WP_Error( 'mailspur_no_channel', __( 'Enter an email address or a webhook URL and save the settings first.', 'mailspur-email-log' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $this->alerts->test() );
	}

	/**
	 * "Send report now": the weekly report of the last 7 days, also when there is nothing to report.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_report() {
		if ( '' === (string) Settings::get( 'alert_email' ) ) {
			return new WP_Error( 'mailspur_no_channel', __( 'Enter an email address under “Send alerts by email to” and save the settings first.', 'mailspur-email-log' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'sent' => (bool) $this->weekly->send( true ) ) );
	}
}
