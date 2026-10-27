<?php
/**
 * Base test case: Brain Monkey + a fresh recording $wpdb + common WordPress function stubs.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Fake_WPDB;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	/** The list filter condition every filtered query repeats literally (see Repository::filter()). */
	const WHERE = "WHERE ( 0 = %d OR recipients LIKE %s OR subject LIKE %s OR ( 1 = %d AND message LIKE %s ) ) AND ( 0 = %d OR created_at >= %s ) AND ( 0 = %d OR created_at <= %s ) AND ( 0 = %d OR source = %s ) AND ( 0 = %d OR content_type LIKE %s ) AND ( 0 = %d OR content_type NOT LIKE %s ) AND ( 0 = %d OR attachments <> '' ) AND ( 0 = %d OR notes > 0 ) AND ( 0 = %d OR delivery = %d ) AND ( 0 = %d OR status = %d )";

	/**
	 * The values for WHERE: every filter off, except the given ones.
	 *
	 * @param array<string,string|int> $on Keys of Repository::filter().
	 * @return array<int,string|int>
	 */
	protected static function where( array $on = array() ): array {
		$f = array_merge(
			array(
				'search_on'   => 0,
				'search'      => '',
				'in_body'     => 0,
				'after_on'    => 0,
				'after'       => '',
				'before_on'   => 0,
				'before'      => '',
				'source_on'   => 0,
				'source'      => '',
				'html'        => 0,
				'text'        => 0,
				'attachments' => 0,
				'notes'       => 0,
				'delivery_on' => 0,
				'delivery'    => 0,
				'status_on'   => 0,
				'status'      => 0,
			),
			$on
		);
		return array( $f['search_on'], $f['search'], $f['search'], $f['in_body'], $f['search'], $f['after_on'], $f['after'], $f['before_on'], $f['before'], $f['source_on'], $f['source'], $f['html'], '%html%', $f['text'], '%html%', $f['attachments'], $f['notes'], $f['delivery_on'], $f['delivery'], $f['status_on'], $f['status'] );
	}

	/** @var Fake_WPDB */
	protected $wpdb;

	/** @var array<string,mixed> Value returned by get_option( 'mailspur_settings' ). */
	protected $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\stubs(
			array(
				'wp_json_encode'              => static function ( $data ) {
					return json_encode( $data );
				},
				'wp_basename'                 => static function ( $path ) {
					return basename( $path );
				},
				'wp_normalize_path'           => static function ( $path ) {
					return str_replace( '\\', '/', $path );
				},
				'get_theme_root'              => WP_CONTENT_DIR . '/themes',
				'current_time'                => '2026-10-01 12:00:00',
				'is_wp_error'                 => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'is_email'                    => static function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
				},
				'absint'                      => static function ( $value ) {
					return abs( (int) $value );
				},
				'sanitize_key'                => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'get_gmt_from_date'           => static function ( $date ) {
					return $date;
				},
				'get_option'                  => function ( $name, $fallback = false ) {
					return 'mailspur_settings' === $name ? $this->settings : $fallback;
				},
				// WordPress' own (English) strings.
				'get_translations_for_domain' => static function () {
					return new class() {
						public function translate( string $text ): string {
							return $text;
						}
					};
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
