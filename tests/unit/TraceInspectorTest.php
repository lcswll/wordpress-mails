<?php
/**
 * Trace module: call site, hooks, request context, transport and API handler detection.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Trace\Inspector;

final class TraceInspectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'wp_doing_cron'              => false,
				'wp_is_serving_rest_request' => false,
				'wp_doing_ajax'              => false,
				'is_admin'                   => false,
				'wp_unslash'                 => static function ( $value ) {
					return $value;
				},
				'sanitize_text_field'        => static function ( $value ) {
					return trim( (string) $value );
				},
				'esc_url_raw'                => static function ( $value ) {
					return (string) $value;
				},
			)
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_GET['rest_route'] );
		parent::tearDown();
	}

	public function test_origin_is_the_frame_that_called_wp_mail(): void {
		$plugin = WP_PLUGIN_DIR . '/woocommerce/includes/emails/class-wc-email.php';
		$frames = array(
			array(
				'file'     => WP_PLUGIN_DIR . '/mailspur-email-log/src/Logger.php',
				'line'     => 77,
				'function' => 'apply_filters',
			),
			array(
				'file'     => ABSPATH . 'wp-includes/pluggable.php',
				'line'     => 200,
				'function' => 'apply_filters',
			),
			array(
				'file'     => $plugin,
				'line'     => 812,
				'function' => 'wp_mail',
			),
			array(
				'file'     => $plugin,
				'line'     => 700,
				'function' => 'send',
				'class'    => 'WC_Email',
				'type'     => '->',
			),
		);

		$this->assertSame(
			array(
				'file'      => 'wp-content/plugins/woocommerce/includes/emails/class-wc-email.php',
				'line'      => 812,
				'function'  => 'WC_Email->send',
				'component' => 'plugin:woocommerce',
			),
			Inspector::origin_from( $frames )
		);
	}

	public function test_origin_from_top_level_code_and_unknown_locations(): void {
		$origin = Inspector::origin_from(
			array(
				array(
					'file'     => '/srv/elsewhere/cron-job.php',
					'line'     => 5,
					'function' => 'wp_mail',
				),
				array(
					'file'     => '/srv/elsewhere/runner.php',
					'line'     => 1,
					'function' => 'require_once',
				),
			)
		);
		$this->assertNotNull( $origin );
		$this->assertSame( '…/cron-job.php', $origin['file'], 'No absolute server paths outside WordPress.' );
		$this->assertSame( '', $origin['function'] );
		$this->assertSame( 'unknown', $origin['component'] );

		$this->assertNull( Inspector::origin_from( array( array( 'function' => 'send' ) ) ) );
		$this->assertNull(
			Inspector::origin_from(
				array(
					array(
						'function' => 'wp_mail',
						'class'    => 'Some_Mailer',
					),
				)
			),
			'A method named wp_mail is not the core function.'
		);
	}

	public function test_hooks_drop_mailspur_frames_and_are_capped(): void {
		$this->assertSame(
			array( 'init', 'woocommerce_order_status_completed' ),
			Inspector::hooks( array( 'init', 'woocommerce_order_status_completed', 'wp_mail', 'mailspur_meta' ) )
		);
		$this->assertCount( 15, Inspector::hooks( array_fill( 0, 40, 'do_something' ) ) );
	}

	public function test_request_context_without_query_string_and_ip(): void {
		$_SERVER['REQUEST_METHOD'] = 'post';
		$_SERVER['REQUEST_URI']    = '/checkout/order-received/123/?key=wc_order_SECRET#top';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.9';

		$request = Inspector::request();

		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( '/checkout/order-received/123/', $request['path'] );
		$this->assertNotContains( '203.0.113.9', $request );
		$this->assertArrayNotHasKey( 'user_id', $request, 'Current user is not resolved before WordPress did it.' );
		$this->assertContains( $request['type'], array( 'frontend', 'cli' ) );
	}

	public function test_request_type_priorities(): void {
		Functions\when( 'wp_is_serving_rest_request' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		$_GET['rest_route'] = '/wc/v3/orders?x=1';

		$request = Inspector::request();
		$this->assertSame( 'rest', $request['type'] );
		$this->assertSame( '/wc/v3/orders', $request['route'] );

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->assertSame( 'cron', Inspector::request()['type'] );
	}

	public function test_transport_masks_user_and_never_contains_the_password(): void {
		$mailer = new TraceMailer();
		$t      = Inspector::transport( $mailer );

		$this->assertSame(
			array(
				'mailer'   => 'smtp',
				'host'     => 'smtp.example.com',
				'port'     => 587,
				'secure'   => 'tls',
				'auth'     => true,
				'auto_tls' => true,
				'user'     => 'j***@example.com',
			),
			$t
		);
		$this->assertStringNotContainsString( 'S3cret', (string) json_encode( $t ) );

		$mailer->Mailer = 'mail'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( array( 'mailer' => 'mail' ), Inspector::transport( $mailer ) );
	}

	public function test_mask_user(): void {
		$this->assertSame( 'j***@example.com', Inspector::mask_user( 'john@example.com' ) );
		$this->assertSame( 'a***', Inspector::mask_user( 'apikey' ) );
		$this->assertSame( '***', Inspector::mask_user( 'ab' ) );
		$this->assertSame( 'ü***@example.com', Inspector::mask_user( 'ümit@example.com' ), 'Multibyte-safe (valid JSON).' );
	}

	public function test_component_and_relative_path(): void {
		$this->assertSame( 'plugin:hello', Inspector::component( WP_PLUGIN_DIR . '/hello.php' ) );
		$this->assertSame( 'mu-plugin:loader', Inspector::component( WPMU_PLUGIN_DIR . '/loader.php' ) );
		$this->assertSame( 'theme:astra', Inspector::component( WP_CONTENT_DIR . '/themes/astra/functions.php' ) );
		$this->assertSame( 'core', Inspector::component( ABSPATH . 'wp-includes/user.php' ) );
		$this->assertSame( 'unknown', Inspector::component( '/tmp/x.php' ) );
		$this->assertSame( 'wp-includes/user.php', Inspector::relative_path( ABSPATH . 'wp-includes/user.php' ) );
	}

	public function test_api_handlers_skip_mailspur_and_resolve_files(): void {
		$own = new \Mailspur\Logger( new \Mailspur\Repository() );

		$handlers = Inspector::api_handlers(
			array(
				PHP_INT_MAX => array(
					'own' => array( 'function' => array( $own, 'short_circuit' ) ),
				),
				10          => array(
					'closure' => array(
						'function' => static function ( $result ) {
							return $result;
						},
					),
					'native'  => array( 'function' => 'strlen' ),
					'method'  => array( 'function' => __CLASS__ . '::fake_api_send' ),
					'gone'    => array( 'function' => array( 'No_Such_Class', 'send' ) ),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'component' => 'unknown',
					'callback'  => '{closure}',
					'file'      => '…/' . basename( __FILE__ ),
				),
				array(
					'component' => 'unknown',
					'callback'  => 'strlen',
					'file'      => '',
				),
				array(
					'component' => 'unknown',
					'callback'  => __CLASS__ . '::fake_api_send',
					'file'      => '…/' . basename( __FILE__ ),
				),
			),
			$handlers,
			'Ordered by priority, Mailspur itself excluded, at most three.'
		);
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function fake_api_send( $result ) {
		return $result;
	}

	public function test_path_only(): void {
		$this->assertSame( '/wp-json/x', Inspector::path_only( '/wp-json/x?token=1' ) );
		$this->assertSame( '/', Inspector::path_only( '/#frag' ) );
	}
}
