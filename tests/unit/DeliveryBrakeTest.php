<?php
/**
 * Delivery module: emergency brake (threshold, sliding counter, modes, exceptions, incident start/end, one alert).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Logger;
use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\Delivery\Module;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Repository;

final class DeliveryBrakeTest extends TestCase {

	/** Half past an hour: the previous hour still counts half. */
	const NOW = 1790001000;

	/** @var array<string,mixed> In-memory wp_options. */
	private $options = array();

	/** @var array<string,mixed> In-memory transients. */
	private $transients = array();

	/** @var int */
	private $now = self::NOW;

	/** @var array<int,array<int,mixed>> */
	private $alerts = array();

	/** @var array<int,array<int,mixed>> */
	private $scheduled = array();

	/** @var Brake */
	private $brake;

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n ) {
					return number_format( (float) $n );
				},
				'wp_next_scheduled'  => function ( $hook ) {
					foreach ( $this->scheduled as $event ) {
						if ( $hook === $event[1] ) {
							return $event[0];
						}
					}
					return false;
				},
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) {
				$this->scheduled[] = array( $time, $hook );
				return true;
			}
		);

		Brake::reset_request();
		Staging::reset();
		Logger::$source_override            = '';
		$this->options['mailspur_settings'] = array(
			'brake_mode'     => 'alert',
			'alert_email'    => 'ops@example.com',
			'alert_recovery' => true,
		);
		// Busiest hour of the baseline: 10 mails → automatic threshold max( 50, 3 × 10 ) = 50.
		$this->transients[ Brake::BASELINE_TRANSIENT ] = 10;

		$this->brake = new Brake(
			function (): int {
				return $this->now;
			},
			function ( ...$args ): void {
				$this->alerts[] = $args;
			}
		);
	}

	protected function tearDown(): void {
		Brake::reset_request();
		Staging::reset();
		Logger::$source_override = '';
		parent::tearDown();
	}

	/** @param array<string,mixed> $values */
	private function settings( array $values ): void {
		$this->options['mailspur_settings'] = array_merge( $this->options['mailspur_settings'], $values );
	}

	/** Pretends $count mails were already counted in the current hour. */
	private function counted( int $count ): void {
		$this->options[ Brake::COUNTER_OPTION ] = array(
			'b' => intdiv( $this->now, 3600 ),
			'c' => $count,
			'p' => 0,
		);
	}

	/**
	 * One mail through the filters in the order WordPress and the logger call them.
	 *
	 * @param mixed $pre Value of pre_wp_mail before the brake.
	 * @return array{pre:mixed,data:array<string,mixed>}
	 */
	private function send( $pre = null, bool $logged = true ): array {
		$meta = $logged ? $this->brake->meta( array( 'other' => 1 ), 'capture' ) : array();
		$pre  = $this->brake->hold( $pre, array( 'to' => 'a@example.com' ) );
		$data = $this->brake->finalize(
			array(
				'status' => Repository::STATUS_SENT,
				'meta'   => $meta,
			)
		);
		return array(
			'pre'  => $pre,
			'data' => $data,
		);
	}

	/* ---------------------------------------------------------------- threshold */

	public function test_automatic_threshold_has_a_floor_and_scales_with_the_busiest_hour(): void {
		$this->assertSame( 50, Brake::automatic( 0 ) );
		$this->assertSame( 50, Brake::automatic( 16 ) );
		$this->assertSame( 120, Brake::automatic( 40 ) );
		$this->assertSame( 50, $this->brake->threshold() );

		$this->settings( array( 'brake_threshold' => 200 ) );
		$this->assertSame( 200, $this->brake->threshold(), 'a fixed number wins' );
	}

	public function test_baseline_is_queried_once_and_cached(): void {
		unset( $this->transients[ Brake::BASELINE_TRANSIENT ] );
		$this->wpdb->results[] = array(
			'h' => '2026-09-30 10',
			'n' => '40',
		);
		$this->assertSame( 120, $this->brake->threshold() );
		$this->assertSame( 40, $this->transients[ Brake::BASELINE_TRANSIENT ] );
		$this->assertStringContainsString( 'GROUP BY h ORDER BY n DESC LIMIT 1', $this->wpdb->prepared[0]['sql'] );
		// Complete hours only: the current hour (the possible flood) is not part of the baseline.
		$this->assertSame( gmdate( 'Y-m-d H:00:00', self::NOW ), $this->wpdb->prepared[0]['args'][2] );

		$this->assertSame( 120, $this->brake->threshold() );
		$this->assertCount( 1, $this->wpdb->prepared, 'second call served from the cache' );
	}

	public function test_baseline_excludes_past_incidents(): void {
		unset( $this->transients[ Brake::BASELINE_TRANSIENT ] );
		$this->options[ Brake::STATE_OPTION ] = array( 'history' => array( array( self::NOW - 7200, self::NOW - 3600 ) ) );
		$this->brake->baseline();
		$this->assertStringContainsString( 'AND NOT ( created_at >= %s AND created_at <= %s )', $this->wpdb->prepared[0]['sql'] );
	}

	/* ------------------------------------------------------------------ counter */

	public function test_sliding_counter_weights_the_previous_hour(): void {
		$this->options[ Brake::COUNTER_OPTION ] = array(
			'b' => intdiv( self::NOW, 3600 ) - 1,
			'c' => 40,
			'p' => 99,
		);
		// New hour: the old current bucket becomes the previous one, at half weight (30 of 60 minutes left).
		$this->assertSame( 21, $this->brake->count( self::NOW ) );
		$this->assertSame( 22, $this->brake->count( self::NOW ) );
		$this->assertSame(
			array(
				'b' => intdiv( self::NOW, 3600 ),
				'c' => 2,
				'p' => 40,
			),
			$this->options[ Brake::COUNTER_OPTION ]
		);
		// Two hours later everything is forgotten.
		$this->assertSame( 1, $this->brake->count( self::NOW + 7200 ) );
	}

	/* -------------------------------------------------------------------- modes */

	public function test_off_does_nothing(): void {
		$this->settings( array( 'brake_mode' => 'off' ) );
		$this->counted( 500 );
		$result = $this->send();
		$this->assertNull( $result['pre'] );
		$this->assertSame( 500, $this->options[ Brake::COUNTER_OPTION ]['c'], 'not even counted' );
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_below_the_threshold_only_counts(): void {
		$this->settings( array( 'brake_mode' => 'hold' ) );
		$this->counted( 10 );
		$result = $this->send();
		$this->assertNull( $result['pre'] );
		$this->assertSame( Repository::STATUS_SENT, $result['data']['status'] );
		$this->assertSame( 11, $this->options[ Brake::COUNTER_OPTION ]['c'] );
		$this->assertSame( array(), $this->wpdb->prepared, 'no query below the floor' );
		$this->assertFalse( Brake::active() );
	}

	public function test_alert_mode_starts_an_incident_but_delivers(): void {
		$this->counted( 60 );
		$this->wpdb->results[] = 75; // Exact count of the last hour confirms the counter.
		$result                = $this->send();

		$this->assertNull( $result['pre'] );
		$this->assertSame( Repository::STATUS_SENT, $result['data']['status'] );
		$state = Brake::state();
		$this->assertTrue( $state['active'] );
		$this->assertSame( 75, $state['count'] );
		$this->assertSame( 50, $state['threshold'] );
		$this->assertSame( array( array( self::NOW, Brake::HOOK ) ), $this->scheduled, 'alert is sent from cron, not now' );
		$this->assertSame( array(), $this->alerts );
		$this->assertFalse( Brake::holding() );
	}

	public function test_fixed_threshold_below_the_floor(): void {
		$this->settings( array( 'brake_threshold' => 5 ) );
		$this->counted( 5 );
		$this->wpdb->results[] = 6;
		$this->send();
		$this->assertTrue( Brake::active() );
		$this->assertSame( 5, Brake::state()['threshold'] );
	}

	public function test_counter_alone_does_not_trigger_without_confirmation(): void {
		$this->counted( 60 );
		$this->wpdb->results[] = 30;
		$this->send();
		$this->assertFalse( Brake::active() );
		$this->assertSame( self::NOW, Brake::state()['verified'] );

		// Within a minute the exact count is not repeated.
		$this->send();
		$this->assertCount( 1, $this->wpdb->prepared );
	}

	public function test_hold_mode_holds_and_flags_the_entry(): void {
		$this->settings( array( 'brake_mode' => 'hold' ) );
		$this->counted( 60 );
		$this->wpdb->results[] = 75;
		$result                = $this->send();

		$this->assertTrue( $result['pre'], 'short-circuits wp_mail()' );
		$this->assertSame( Repository::STATUS_HELD, $result['data']['status'] );
		$this->assertSame( Brake::HELD, $result['data']['meta']['delivery']['held'] );
		$this->assertSame( 1, $result['data']['meta']['other'] );
		$this->assertTrue( Brake::holding() );

		// The flag is consumed.
		$this->assertSame( array( 'status' => 1 ), $this->brake->finalize( array( 'status' => 1 ) ) );

		// Next mail: incident already active, held without a new query.
		$this->assertTrue( $this->send()['pre'] );
		$this->assertCount( 1, $this->wpdb->prepared );
	}

	/* --------------------------------------------------------------- exceptions */

	private function active_hold(): void {
		$this->settings( array( 'brake_mode' => 'hold' ) );
		$this->options[ Brake::STATE_OPTION ] = array(
			'active' => true,
			'start'  => self::NOW - 600,
		);
		$this->counted( 100 );
	}

	public function test_password_reset_is_never_held_but_counted(): void {
		$this->active_hold();
		$this->assertSame( 'msg', $this->brake->password_reset( 'msg' ) );
		$result = $this->send();
		$this->assertNull( $result['pre'] );
		$this->assertSame( Repository::STATUS_SENT, $result['data']['status'] );
		$this->assertSame( 101, $this->options[ Brake::COUNTER_OPTION ]['c'] );

		// Only that one mail.
		$this->assertTrue( $this->send()['pre'] );
	}

	public function test_own_alert_mails_go_out_and_are_not_counted(): void {
		$this->active_hold();
		Logger::$source_override = 'mailspur:alert';
		$result                  = $this->send();
		$this->assertNull( $result['pre'] );
		$this->assertSame( 100, $this->options[ Brake::COUNTER_OPTION ]['c'] );
	}

	public function test_released_mails_bypass_the_brake(): void {
		$this->active_hold();
		Staging::$release = 7;
		$this->assertNull( $this->send()['pre'] );
	}

	public function test_mails_that_are_not_logged_are_never_held(): void {
		$this->active_hold();
		$this->assertNull( $this->send( null, false )['pre'], 'a held mail must be releasable from the log' );
	}

	public function test_exempt_filter(): void {
		$this->active_hold();
		Filters\expectApplied( 'mailspur_brake_exempt' )->once()->andReturn( true );
		$this->assertNull( $this->send()['pre'] );
	}

	public function test_staging_or_another_plugin_decides_first(): void {
		$this->active_hold();
		$this->assertFalse( $this->send( false )['pre'] );
		$this->assertSame( Repository::STATUS_SENT, $this->send( true )['data']['status'] );
		$this->assertSame( 100, $this->options[ Brake::COUNTER_OPTION ]['c'] );
	}

	public function test_errors_never_block_delivery(): void {
		$this->settings( array( 'brake_mode' => 'hold' ) );
		Functions\when( 'update_option' )->alias(
			static function () {
				throw new \RuntimeException( 'database gone' );
			}
		);
		$this->assertNull( $this->send()['pre'] );
	}

	/* ------------------------------------------------------- incident lifecycle */

	public function test_one_alert_per_incident_then_back_to_normal(): void {
		$this->counted( 60 );
		$this->wpdb->results[] = 75;
		$this->send();

		// Stats::source_label() caches the plugin list for the whole run: keep it compatible with InsightsStatsTest.
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'woo/woo.php'                       => array( 'Name' => 'WooCommerce' ),
				'contact-form-x/contact-form-x.php' => array( 'Name' => 'Contact Form X' ),
			)
		);
		$this->wpdb->results[] = array(
			array(
				'v' => 'plugin:contact-form-x',
				'n' => '70',
			),
		);
		$this->assertSame( 'alert', $this->brake->tick() );
		$this->assertCount( 1, $this->alerts );
		$this->assertSame( array( 'brake', 'alert' ), array_slice( $this->alerts[0], 0, 2 ) );
		$this->assertStringContainsString( '75 emails were sent within one hour', $this->alerts[0][2] );
		$this->assertStringContainsString( 'Main source: Contact Form X (70 emails).', $this->alerts[0][2] );
		$this->assertStringNotContainsString( '@', $this->alerts[0][2], 'never recipients' );

		// Still a flood: no second alert, checked again later.
		$this->now            += 900;
		$this->scheduled       = array();
		$this->wpdb->results[] = 90;
		$this->assertSame( 'waiting', $this->brake->tick() );
		$this->assertCount( 1, $this->alerts );
		$this->assertSame( array( array( $this->now + 900, Brake::HOOK ) ), $this->scheduled );

		// More mails during the incident do not alert again either.
		$this->send();
		$this->assertCount( 1, $this->alerts );

		// Volume back below the threshold: one recovery message, incident over and remembered.
		$this->now            += 3600;
		$this->wpdb->results[] = 12;
		$this->assertSame( 'recovery', $this->brake->tick() );
		$this->assertCount( 2, $this->alerts );
		$this->assertSame( array( 'brake', 'recovery' ), array_slice( $this->alerts[1], 0, 2 ) );
		$this->assertFalse( Brake::active() );
		$this->assertSame( array( array( self::NOW, $this->now ) ), Brake::state()['history'] );
		$this->assertArrayNotHasKey( Brake::BASELINE_TRANSIENT, $this->transients, 'baseline recomputed without the incident' );
		$this->assertSame( '', $this->brake->tick() );
	}

	public function test_hold_mode_waits_for_the_administrator(): void {
		$this->settings( array( 'brake_mode' => 'hold' ) );
		$this->options[ Brake::STATE_OPTION ] = array(
			'active'  => true,
			'start'   => self::NOW,
			'alerted' => true,
		);
		$this->now                           += 3 * 3600;
		$this->assertSame( 'waiting', $this->brake->tick() );
		$this->assertTrue( Brake::active() );
		$this->assertSame( array(), $this->alerts );
	}

	public function test_no_alert_without_channel_but_the_incident_is_tracked(): void {
		$this->settings( array( 'alert_email' => '' ) );
		$this->options[ Brake::STATE_OPTION ] = array(
			'active' => true,
			'start'  => self::NOW,
		);
		$this->assertSame( 'alert', $this->brake->tick() );
		$this->assertSame( array(), $this->alerts );
		$this->assertTrue( Brake::state()['alerted'] );
	}

	public function test_reset_pauses_the_brake_for_an_hour(): void {
		$this->settings( array( 'brake_mode' => 'hold' ) );
		$this->options[ Brake::STATE_OPTION ] = array(
			'active'  => true,
			'start'   => self::NOW - 600,
			'alerted' => true,
		);
		$this->wpdb->results[]                = 0; // No held mails left.
		$this->brake->reset();

		$state = Brake::state();
		$this->assertFalse( $state['active'] );
		$this->assertSame( self::NOW + Brake::PAUSE, $state['paused'] );
		$this->assertArrayNotHasKey( Brake::COUNTER_OPTION, $this->options, 'counter restarts' );
		$this->assertSame( 'recovery', $this->alerts[0][1] );

		// Even a burst right after the reset is not held during the pause.
		$this->counted( 500 );
		$this->assertNull( $this->send()['pre'] );
		$this->assertFalse( Brake::active() );
	}

	/* ----------------------------------------------------------------- settings */

	public function test_settings_defaults_and_sanitizing(): void {
		Functions\when( 'is_email' )->justReturn( false );
		$module   = new Module( new Repository() );
		$defaults = $module->defaults( array() );
		$this->assertSame( 'alert', $defaults['brake_mode'] );
		$this->assertSame( 0, $defaults['brake_threshold'] );

		$clean = $module->sanitize(
			array(),
			array(
				'brake_mode'      => 'HOLD',
				'brake_threshold' => '250',
			)
		);
		$this->assertSame( 'hold', $clean['brake_mode'] );
		$this->assertSame( 250, $clean['brake_threshold'] );

		$clean = $module->sanitize(
			array(),
			array(
				'brake_mode'      => 'panic',
				'brake_threshold' => '-5',
			)
		);
		$this->assertSame( 'alert', $clean['brake_mode'] );
		$this->assertSame( 0, $clean['brake_threshold'] );
	}
}
