<?php
/**
 * Site Health (Tools › Site Health): Mailspur's tests (Checks) and a short section on the Info tab.
 *
 * - Tests are only registered for users who may view the log (and for WP-Cron's weekly Site Health check).
 *   Direct tests read stored state; the sender test does DNS lookups and therefore runs asynchronously via the
 *   REST route GET mailspur-email-log/v1/site-health/sender.
 * - The Info section lists settings and sizes only – never email addresses.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\SiteHealth;

use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\Delivery\Feedback;
use Mailspur\Modules\Delivery\Module as Delivery;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Modules\Types\Store;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	const ROUTE = '/site-health/sender';

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debug' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/** Users who may view the log; WP-Cron for the weekly Site Health check. */
	public static function allowed(): bool {
		return wp_doing_cron() || Settings::current_user_can_view();
	}

	/**
	 * Filter site_status_tests.
	 *
	 * @param mixed $tests
	 * @return mixed
	 */
	public function tests( $tests ) {
		if ( ! is_array( $tests ) || ! self::allowed() ) {
			return $tests;
		}
		$checks = new Checks();
		$direct = array(
			'mailspur_failures' => array( __( 'Email failures', 'mailspur-email-log' ), 'failures' ),
			'mailspur_types'    => array( __( 'Stopped email types', 'mailspur-email-log' ), 'types' ),
			'mailspur_brake'    => array( __( 'Emergency brake', 'mailspur-email-log' ), 'brake' ),
			'mailspur_staging'  => array( __( 'Staging mode', 'mailspur-email-log' ), 'staging' ),
		);
		foreach ( $direct as $id => $test ) {
			$tests['direct'][ $id ] = array(
				'label' => $test[0],
				'test'  => array( $checks, $test[1] ),
			);
		}
		$tests['async']['mailspur_sender'] = array(
			'label'             => __( 'Sender domain (SPF and DMARC)', 'mailspur-email-log' ),
			'test'              => rest_url( Rest::NS . self::ROUTE ),
			'has_rest'          => true,
			'async_direct_test' => array( $checks, 'sender' ),
		);
		return $tests;
	}

	public function routes(): void {
		register_rest_route(
			Rest::NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return rest_ensure_response( ( new Checks() )->sender() );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'view_site_health_checks' ) && Settings::current_user_can_view();
				},
				'args'                => array(),
			)
		);
	}

	/**
	 * Filter debug_information: sizes and settings, no addresses.
	 *
	 * @param mixed $info
	 * @return mixed
	 */
	public function debug( $info ) {
		if ( ! is_array( $info ) || ! Settings::current_user_can_view() ) {
			return $info;
		}
		$stats    = $this->repository->stats();
		$days     = (int) Settings::get( 'retention_days' );
		$max      = (int) Settings::get( 'max_entries' );
		$staging  = Staging::mode();
		$brake    = Brake::mode();
		$provider = Feedback::provider();
		$labels   = Delivery::provider_labels();

		$staging_labels = array(
			Staging::OFF      => __( 'Off', 'mailspur-email-log' ),
			Staging::HOLD     => __( 'Log only – do not send any email', 'mailspur-email-log' ),
			Staging::REDIRECT => __( 'Redirect to test addresses', 'mailspur-email-log' ),
		);
		$brake_labels   = array(
			Brake::OFF   => __( 'Off', 'mailspur-email-log' ),
			Brake::ALERT => __( 'Alert only', 'mailspur-email-log' ),
			Brake::HOLD  => __( 'Alert and hold further emails', 'mailspur-email-log' ),
		);
		$holding        = Brake::holding();

		$fields = array(
			'entries'   => array(
				'label' => __( 'Logged emails', 'mailspur-email-log' ),
				'value' => number_format_i18n( $stats['rows'] ),
				'debug' => $stats['rows'],
			),
			'size'      => array(
				'label' => __( 'Log size', 'mailspur-email-log' ),
				'value' => size_format( $stats['bytes'] ),
				'debug' => $stats['bytes'],
			),
			'retention' => array(
				'label' => __( 'Delete entries after', 'mailspur-email-log' ),
				/* translators: %s: number of days */
				'value' => $days > 0 ? sprintf( __( '%s days', 'mailspur-email-log' ), number_format_i18n( $days ) ) : __( 'Never', 'mailspur-email-log' ),
				'debug' => $days,
			),
			'max'       => array(
				'label' => __( 'Keep at most', 'mailspur-email-log' ),
				/* translators: %s: number of log entries */
				'value' => $max > 0 ? sprintf( __( '%s entries', 'mailspur-email-log' ), number_format_i18n( $max ) ) : __( 'No limit', 'mailspur-email-log' ),
				'debug' => $max,
			),
			'staging'   => array(
				'label' => __( 'Staging mode', 'mailspur-email-log' ),
				'value' => $staging_labels[ $staging ] ?? $staging,
				'debug' => $staging,
			),
			'brake'     => array(
				'label' => __( 'Emergency brake', 'mailspur-email-log' ),
				'value' => ( $brake_labels[ $brake ] ?? $brake ) . ( $holding ? ' – ' . __( 'holding emails right now', 'mailspur-email-log' ) : '' ),
				'debug' => $brake . ( $holding ? ', holding' : '' ),
			),
			'feedback'  => array(
				'label' => __( 'Delivery status from your email provider', 'mailspur-email-log' ),
				'value' => '' !== $provider ? ( $labels[ $provider ] ?? $provider ) : __( 'Not set up', 'mailspur-email-log' ),
				'debug' => '' !== $provider ? $provider : 'none',
			),
		);
		if ( class_exists( Store::class ) && Store::DB_VERSION === (int) get_option( Store::DB_OPTION ) ) {
			global $wpdb;
			$types           = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Store::types_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, Info tab only.
			$fields['types'] = array(
				'label' => __( 'Email types', 'mailspur-email-log' ),
				'value' => number_format_i18n( $types ),
				'debug' => $types,
			);
		}

		$info['mailspur-email-log'] = array(
			'label'  => 'Mailspur',
			'fields' => $fields,
		);
		return $info;
	}
}
