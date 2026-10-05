<?php
/**
 * Email types: admin noise (counter, threshold, switch-off links), slow types (waiting contexts, median,
 * minimum sample), new senders (baseline, new window, external addresses) and the opt-in quiet switches.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Noise;
use Mailspur\Modules\Types\Quiet;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Senders;
use Mailspur\Modules\Types\Speed;

final class TypesInsightsTest extends TestCase {

	const TODAY = '2026-10-01';

	/** @var int 2026-10-01 12:00 UTC */
	private $now;

	protected function setUp(): void {
		parent::setUp();
		$this->now = (int) strtotime( self::TODAY . ' 12:00:00 UTC' );
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'admin_url' => static function ( $path = '' ) {
					return 'https://example.com/wp-admin/' . $path;
				},
			)
		);
	}

	private function day( int $ago ): string {
		return gmdate( 'Y-m-d', strtotime( self::TODAY . ' UTC' ) - $ago * 86400 );
	}

	public function test_admin_recipients_are_recognised_without_storing_them(): void {
		$admins = array( 'admin@example.com', 'owner@example.org' );
		$this->assertTrue( Noise::to_admin( 'Site Admin <Admin@Example.com>', $admins ) );
		$this->assertTrue( Noise::to_admin( 'customer@shop.test, owner@example.org', $admins ) );
		$this->assertFalse( Noise::to_admin( 'customer@shop.test', $admins ) );
		$this->assertFalse( Noise::to_admin( 'admin@example.com.evil.test', $admins ), 'Exact addresses only.' );
		$this->assertFalse( Noise::to_admin( 'admin@example.com', array() ) );

		$extra = Noise::observe( array(), $this->day( 0 ) );
		$this->assertSame( array( $this->day( 0 ) => 1 ), $extra['adm'] );
		$this->assertStringNotContainsString( '@', (string) json_encode( $extra ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_admin_counter_keeps_31_days_and_sums_a_range(): void {
		$extra = array();
		for ( $ago = 40; $ago >= 0; $ago-- ) {
			$extra = Noise::observe( $extra, $this->day( $ago ) );
			$extra = Noise::observe( $extra, $this->day( $ago ) );
		}
		$this->assertCount( 32, $extra['adm'], 'Today plus 31 days back.' );
		$this->assertSame( $this->day( 31 ), array_key_first( $extra['adm'] ) );
		$this->assertSame( 60, Noise::count( $extra, $this->day( 29 ) ) ); // 30 days × 2.

		// An older (imported) email after newer ones does not throw newer days away.
		$extra = Noise::observe( $extra, $this->day( 50 ) );
		$this->assertArrayNotHasKey( $this->day( 50 ), $extra['adm'] );
		$this->assertArrayHasKey( $this->day( 0 ), $extra['adm'] );
	}

	public function test_noise_threshold_in_the_report(): void {
		$types  = array();
		$days   = array();
		$counts = array(
			1 => 30, // Exactly the threshold.
			2 => 29,
			3 => 45, // Ignored.
		);
		foreach ( $counts as $id => $count ) {
			$extra = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$extra = Noise::observe( $extra, $this->day( 1 + $i % 20 ) );
			}
			$extra['fn']  = 2 === $id ? '' : 'wp_notify_moderator';
			$types[ $id ] = array(
				'id'          => $id,
				'source'      => 'core',
				'pattern'     => array( 'Please', 'moderate:', '{text}' ),
				'first_seen'  => '2026-07-01 08:00:00',
				'last_seen'   => gmdate( 'Y-m-d H:i:s', $this->now - 86400 ),
				'last_status' => 1,
				'last_notes'  => 0,
				'muted'       => 3 === $id,
				'extra'       => $extra,
			);
			$days[ $id ]  = array(
				$this->day( 1 ) => array(
					'total'  => $count,
					'failed' => 0,
					'held'   => 0,
				),
			);
		}
		$items = array_column( Report::build( $types, $days, self::TODAY, $this->now ), null, 'id' );
		$this->assertTrue( $items[1]['noise'], '30 in 30 days is noise.' );
		$this->assertSame( 30, $items[1]['admin'] );
		$this->assertFalse( $items[2]['noise'], '29 is not.' );
		$this->assertFalse( $items[3]['noise'], 'Ignored types are never noise.' );
		$this->assertSame( 'wp_notify_moderator', $items[1]['origin'] );
		$this->assertFalse( $items[1]['new_sender'] );
		$this->assertSame( 1, Report::summary( $items )['noise'] );
	}

	public function test_switch_off_targets(): void {
		$moderation = Noise::fix( 'wp_notify_moderator', 'core' );
		$this->assertSame( 'https://example.com/wp-admin/options-discussion.php', $moderation['url'] ?? null );
		$this->assertSame( 'https://example.com/wp-admin/options-discussion.php', Noise::fix( 'wp_notify_postauthor', 'core' )['url'] ?? null );
		$this->assertSame( array( 'quiet' => Quiet::UPDATES ), Noise::fix( 'WP_Automatic_Updater::send_plugin_theme_email', 'core' ) );
		$this->assertSame( array( 'quiet' => Quiet::UPDATES ), Noise::fix( 'WP_Automatic_Updater::send_email', 'core' ) );
		$this->assertSame( array( 'quiet' => Quiet::NEW_USER ), Noise::fix( 'wp_new_user_notification', 'core' ) );
		$this->assertStringContainsString( 'page=wc-settings&tab=email', (string) ( Noise::fix( 'WC_Email::send', 'plugin:woocommerce' )['url'] ?? '' ) );
		$this->assertNull( Noise::fix( 'my_plugin_notify', 'plugin:my-plugin' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function trace( string $type, float $ms, string $mailer = 'smtp' ): array {
		return array(
			'request'   => array( 'type' => $type ),
			'total_ms'  => $ms,
			'transport' => array( 'mailer' => $mailer ),
		);
	}

	public function test_only_waiting_requests_count_for_speed(): void {
		$extra = array();
		foreach ( array( 'cron', 'cli' ) as $type ) {
			$extra = Speed::observe( $extra, $this->trace( $type, 9000 ) );
		}
		$this->assertArrayNotHasKey( 'dur', $extra, 'Nobody waits for WP-Cron or WP-CLI.' );

		foreach ( array( 'frontend', 'ajax', 'rest', 'admin', 'xmlrpc' ) as $type ) {
			$extra = Speed::observe( $extra, $this->trace( $type, 2400.4 ) );
		}
		$extra = Speed::observe( $extra, array( 'request' => array( 'type' => 'frontend' ) ) ); // No duration (older trace).
		$this->assertSame( array( 2400, 2400, 2400, 2400, 2400 ), $extra['dur'] );
		$this->assertSame( 'smtp', $extra['tx'] );
	}

	public function test_slow_needs_enough_samples_and_a_slow_median(): void {
		$extra = array();
		foreach ( array( 3000, 3000, 3000, 3000 ) as $ms ) {
			$extra = Speed::observe( $extra, $this->trace( 'ajax', $ms ) );
		}
		$this->assertNull( Speed::of( $extra ), 'Four measurements say nothing.' );

		$extra = Speed::observe( $extra, $this->trace( 'ajax', 2000, 'api' ) );
		$slow  = Speed::of( $extra );
		$this->assertSame( 3000, $slow['median'] ?? null );
		$this->assertSame( 2800, $slow['average'] ?? null );
		$this->assertSame( 'api', $slow['mailer'] ?? null );

		// One outlier among fast emails is no slow type.
		$fast = array();
		foreach ( array( 300, 250, 400, 30000, 350, 280 ) as $ms ) {
			$fast = Speed::observe( $fast, $this->trace( 'frontend', $ms ) );
		}
		$this->assertNull( Speed::of( $fast ) );

		// Only the latest SAMPLES count: a fixed mail server makes the type fast again.
		for ( $i = 0; $i < Speed::SAMPLES; $i++ ) {
			$extra = Speed::observe( $extra, $this->trace( 'ajax', 200 ) );
		}
		$this->assertCount( Speed::SAMPLES, $extra['dur'] );
		$this->assertNull( Speed::of( $extra ) );

		$this->assertSame( 2, Speed::median( array( 3, 1, 2 ) ) );
		$this->assertSame( 3, Speed::median( array( 4, 1, 2, 5 ) ) );
		$this->assertCount( 3, Speed::tips( 'smtp' ) );
		$this->assertCount( 2, Speed::tips( 'api' ) );
	}

	public function test_no_sender_is_new_during_the_baseline(): void {
		$start = $this->now - 20 * 86400;
		$state = Senders::normalize( array() );

		// Install: the existing log (and an import of old emails) is the baseline.
		$state = Senders::observe( $state, 'core', $start - 90 * 86400, $start );
		$state = Senders::observe( $state, 'plugin:woocommerce', $start + 3600, $start );
		$this->assertSame( $start, $state['since'] );
		$this->assertSame( array(), Senders::fresh( $state, $start + 7200 ), 'Nothing is new right after the install.' );

		// A sender appearing 10 days after the install: still baseline.
		$state = Senders::observe( $state, 'plugin:forms', $start + 10 * 86400, $start + 10 * 86400 );
		$this->assertSame( array(), Senders::fresh( $state, $start + 10 * 86400 + 60 ) );

		// After the baseline: new for 7 days.
		$first = $this->now - 2 * 86400;
		$state = Senders::observe( $state, 'plugin:backdoor', $first, $this->now );
		$state = Senders::observe( $state, 'plugin:backdoor', $first + 600, $this->now );
		$this->assertSame( array( 'plugin:backdoor' => $first ), Senders::fresh( $state, $this->now ) );
		$this->assertSame( array(), Senders::fresh( $state, $first + 7 * 86400 ) );

		// A late import of old emails of a known sender moves its first email back.
		$state = Senders::observe( $state, 'plugin:backdoor', $start - 86400, $this->now );
		$this->assertSame( array(), Senders::fresh( $state, $this->now ) );
		$this->assertSame( array(), Senders::fresh( Senders::normalize( 'garbage' ), $this->now ) );
	}

	public function test_known_senders_are_capped(): void {
		$state = Senders::normalize( array() );
		for ( $i = 0; $i < Senders::MAX_KNOWN + 5; $i++ ) {
			$state = Senders::observe( $state, 'plugin:p' . $i, 1000 + $i, $this->now );
		}
		$this->assertCount( Senders::MAX_KNOWN, $state['known'] );
		$this->assertArrayHasKey( 'plugin:p' . ( Senders::MAX_KNOWN + 4 ), $state['known'] );
		$this->assertArrayNotHasKey( 'plugin:p0', $state['known'] );
	}

	public function test_external_addresses_skip_the_site_and_administrators(): void {
		$lists = array(
			'a@gmail.test, b@gmail.test',
			'A@gmail.test',
			'shop@example.com',
			'news@mail.example.com',
			'boss@other.test',
			'"Someone" <c@yahoo.test>',
		);
		$this->assertSame( 3, Senders::external( $lists, 'www.example.com', array( 'boss@other.test' ) ) );
		$this->assertSame( 0, Senders::external( array(), 'example.com', array() ) );
	}

	public function test_quiet_switches_keep_failure_notices(): void {
		$ok     = (object) array( 'result' => true );
		$failed = (object) array( 'result' => new \WP_Error( 'x', 'failed' ) );
		$this->assertFalse( Quiet::plugin_theme_email( true, array( $ok, $ok ) ) );
		$this->assertTrue( Quiet::plugin_theme_email( true, array( $ok, $failed ) ) );
		$this->assertTrue( Quiet::plugin_theme_email( true, array( $ok, (object) array( 'result' => null ) ) ) );
		$this->assertFalse( Quiet::core_email( true, 'success' ) );
		$this->assertTrue( Quiet::core_email( true, 'fail' ) );
		$this->assertTrue( Quiet::core_email( true, 'critical' ) );
	}
}
