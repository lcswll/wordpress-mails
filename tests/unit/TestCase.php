<?php
/**
 * Base test case: Brain Monkey + a fresh recording $wpdb + common WordPress function stubs.
 *
 * @package OutboxMailLog
 */

namespace OutboxMailLog\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Fake_WPDB;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	/** @var Fake_WPDB */
	protected $wpdb;

	/** @var array<string,mixed> Value returned by get_option( 'outbox_mail_log_settings' ). */
	protected $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\stubs(
			array(
				'wp_json_encode'    => static function ( $data ) {
					return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				},
				'wp_basename'       => static function ( $path ) {
					return basename( $path );
				},
				'wp_normalize_path' => static function ( $path ) {
					return str_replace( '\\', '/', $path );
				},
				'get_theme_root'    => WP_CONTENT_DIR . '/themes',
				'current_time'      => '2026-10-01 12:00:00',
				'is_wp_error'       => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'is_email'          => static function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
				},
				'absint'            => static function ( $value ) {
					return abs( (int) $value );
				},
				'sanitize_key'      => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'get_gmt_from_date' => static function ( $date ) {
					return $date;
				},
				'get_option'        => function ( $name, $fallback = false ) {
					return 'outbox_mail_log_settings' === $name ? $this->settings : $fallback;
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Writes of one kind recorded by the fake $wpdb.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	protected function writes( string $kind ): array {
		return array_values(
			array_filter(
				$this->wpdb->writes,
				static function ( array $write ) use ( $kind ): bool {
					return $kind === $write[0];
				}
			)
		);
	}
}
