<?php
/**
 * Delivery module: staging mode (hold / redirect / release) and its settings.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Modules\Delivery\Module;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Repository;

final class DeliveryStagingTest extends TestCase {

	/** @var Staging */
	private $staging;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Staging::reset();
		$this->staging = new Staging();
	}

	protected function tearDown(): void {
		Staging::reset();
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function atts(): array {
		return array(
			'to'          => 'anna@example.com, Bob <bob@example.org>',
			'subject'     => 'Your order',
			'message'     => 'Hello',
			'headers'     => "From: Shop <shop@example.com>\nCc: boss@example.com, team@example.com\nBCC: audit@example.com\nContent-Type: text/html",
			'attachments' => array(),
		);
	}

	/**
	 * Runs one mail through the staging filters in the order WordPress and the logger call them.
	 *
	 * @param array<string,mixed> $atts
	 * @param mixed               $pre  Value of pre_wp_mail before the staging filter.
	 * @return array{atts:array<string,mixed>,pre:mixed,data:array<string,mixed>}
	 */
	private function send( array $atts, $pre = null ): array {
		$atts = $this->staging->redirect( $atts );
		$meta = $this->staging->meta( array( 'other' => 1 ), 'capture' );
		$pre  = $this->staging->hold( $pre );
		$data = $this->staging->finalize(
			array(
				'status' => Repository::STATUS_SENT,
				'meta'   => $this->staging->meta( $meta, 'result' ),
			)
		);
		return array(
			'atts' => $atts,
			'pre'  => $pre,
			'data' => $data,
		);
	}

	public function test_off_changes_nothing(): void {
		$result = $this->send( self::atts() );
		$this->assertSame( self::atts(), $result['atts'] );
		$this->assertNull( $result['pre'] );
		$this->assertSame(
			array(
				'status' => Repository::STATUS_SENT,
				'meta'   => array( 'other' => 1 ),
			),
			$result['data']
		);
		$this->assertFalse( Staging::active() );
	}

	public function test_hold_short_circuits_and_marks_the_entry_held(): void {
		$this->settings = array( 'staging_mode' => 'hold' );
		$result         = $this->send( self::atts() );

		$this->assertSame( self::atts(), $result['atts'] );
		$this->assertTrue( $result['pre'] );
		$this->assertSame( Repository::STATUS_HELD, $result['data']['status'] );
		$this->assertSame( 'staging', $result['data']['meta']['delivery']['held'] );
		$this->assertSame( 1, $result['data']['meta']['other'] );

		// The flag is consumed: an unrelated entry finalized later is not touched.
		$this->assertSame( array( 'status' => 1 ), $this->staging->finalize( array( 'status' => 1 ) ) );
	}

	public function test_hold_respects_mails_already_handled_by_another_plugin(): void {
		$this->settings = array( 'staging_mode' => 'hold' );
		$result         = $this->send( self::atts(), false );
		$this->assertFalse( $result['pre'] );
		$this->assertSame( Repository::STATUS_SENT, $result['data']['status'] );
	}

	public function test_redirect_rewrites_recipients_and_remembers_the_originals(): void {
		$this->settings = array(
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'dev@example.net, qa@example.net',
		);
		$result         = $this->send( self::atts() );

		$this->assertSame( array( 'dev@example.net', 'qa@example.net' ), $result['atts']['to'] );
		$this->assertSame( '[Staging → anna@example.com, Bob <bob@example.org>] Your order', $result['atts']['subject'] );
		$this->assertSame( array( 'From: Shop <shop@example.com>', 'Content-Type: text/html' ), $result['atts']['headers'] );
		$this->assertNull( $result['pre'], 'redirected mails are delivered' );

		$delivery = $result['data']['meta']['delivery'];
		$this->assertSame( array( 'anna@example.com', 'Bob <bob@example.org>' ), $delivery['original_to'] );
		$this->assertSame( array( 'boss@example.com', 'team@example.com' ), $delivery['original_cc'] );
		$this->assertSame( array( 'audit@example.com' ), $delivery['original_bcc'] );
		$this->assertTrue( $delivery['redirected'] );
		$this->assertSame( Repository::STATUS_SENT, $result['data']['status'] );

		// The next mail does not inherit the originals.
		$this->assertSame( array(), $this->staging->meta( array(), 'capture' ) );
	}

	public function test_redirect_subject_is_filterable(): void {
		$this->settings = array(
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'dev@example.net',
		);
		Filters\expectApplied( 'mailspur_staging_subject' )->once()->with( '[Staging → a@example.com] Hi', 'Hi', array( 'a@example.com' ) )->andReturn( 'Custom' );
		$atts = $this->staging->redirect(
			array(
				'to'      => array( 'a@example.com' ),
				'subject' => 'Hi',
			)
		);
		$this->assertSame( 'Custom', $atts['subject'] );
		$this->assertSame( array(), $atts['headers'] );
	}

	public function test_redirect_without_valid_address_holds_instead(): void {
		$this->settings = array(
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'not-an-address',
		);
		$result         = $this->send( self::atts() );
		$this->assertSame( self::atts(), $result['atts'] );
		$this->assertTrue( $result['pre'] );
		$this->assertSame( Repository::STATUS_HELD, $result['data']['status'] );
		$this->assertSame( 'no_redirect_address', $result['data']['meta']['delivery']['held'] );
	}

	public function test_release_bypasses_staging_once(): void {
		$this->settings   = array(
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'dev@example.net',
		);
		Staging::$release = 42;
		$result           = $this->send( self::atts() );

		$this->assertSame( self::atts(), $result['atts'] );
		$this->assertNull( $result['pre'] );
		$this->assertSame( Repository::STATUS_SENT, $result['data']['status'] );
		$this->assertSame( array( 'released_from' => 42 ), $result['data']['meta']['delivery'] );

		Staging::$release = 0;
		$this->assertSame( array( 'dev@example.net' ), $this->send( self::atts() )['atts']['to'] );
	}

	public function test_non_array_atts_pass_through(): void {
		$this->settings = array( 'staging_mode' => 'redirect' );
		$this->assertSame( 'x', $this->staging->redirect( 'x' ) );
	}

	public function test_parse_addresses(): void {
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), Staging::parse_addresses( " a@example.com;b@example.com,\nA@EXAMPLE.COM junk" ) );
		$many = implode( ',', array_map( static fn( int $i ): string => "u{$i}@example.com", range( 1, 15 ) ) );
		$this->assertCount( 10, Staging::parse_addresses( $many ) );
		$this->assertSame( array(), Staging::parse_addresses( '' ) );
	}

	public function test_settings_defaults_and_sanitizing(): void {
		$module   = new Module( new Repository() );
		$defaults = $module->defaults( array( 'x' => 1 ) );
		$this->assertSame( 'off', $defaults['staging_mode'] );
		$this->assertSame( '', $defaults['staging_redirect_to'] );

		$clean = $module->sanitize(
			array( 'x' => 1 ),
			array(
				'staging_mode'        => 'REDIRECT',
				'staging_redirect_to' => 'dev@example.net, <script>, qa@example.net',
			)
		);
		$this->assertSame( 'redirect', $clean['staging_mode'] );
		$this->assertSame( 'dev@example.net, qa@example.net', $clean['staging_redirect_to'] );
		$this->assertSame( 1, $clean['x'] );

		$this->assertSame( 'off', $module->sanitize( array(), array( 'staging_mode' => 'evil' ) )['staging_mode'] );
		$this->assertSame( 'off', $module->sanitize( array(), array() )['staging_mode'] );
		$this->assertSame( '', $module->sanitize( array(), array( 'staging_redirect_to' => array( 'a@example.com' ) ) )['staging_redirect_to'] );
	}

	public function test_staging_suggestion(): void {
		$options = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( &$options ) {
				return 'mailspur_settings' === $name ? $this->settings : ( $options[ $name ] ?? $fallback );
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		$env = 'staging';
		Functions\when( 'wp_get_environment_type' )->alias(
			static function () use ( &$env ) {
				return $env;
			}
		);

		$this->assertTrue( Module::suggest_staging() );

		$env = 'production';
		$this->assertFalse( Module::suggest_staging() );

		$env            = 'development';
		$this->settings = array( 'staging_mode' => 'hold' );
		$this->assertFalse( Module::suggest_staging(), 'already on' );

		$this->settings                              = array();
		$options['mailspur_delivery_hint_dismissed'] = 1;
		$this->assertFalse( Module::suggest_staging(), 'dismissed' );
	}
}
