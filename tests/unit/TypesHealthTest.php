<?php
/**
 * Email types: rhythm learning, silence detection, report states and the "type stopped" alert state machine.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Monitor;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Rhythm;
use Mailspur\Modules\Types\Store;

final class TypesHealthTest extends TestCase {

	const TODAY = '2026-10-01';

	/** @var int 2026-10-01 12:00 UTC */
	private $now;

	/** @var array<string,mixed> */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->now = (int) strtotime( self::TODAY . ' 12:00:00 UTC' );
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n ) {
					return (string) $n;
				},
				'human_time_diff'    => static function ( $from, $to ) {
					return round( ( $to - $from ) / 86400 ) . ' days';
				},
				'get_plugins'        => array( 'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ) ),
				'get_mu_plugins'     => array(),
				'get_option'         => function ( $name, $fallback = false ) {
					return $this->options[ $name ] ?? $fallback;
				},
				'update_option'      => function ( $name, $value ) {
					$this->options[ $name ] = $value;
					return true;
				},
			)
		);
	}

	/**
	 * Day counts for the days before today.
	 *
	 * @param int[] $offsets Days before today (1 = yesterday).
	 * @return array<string,int>
	 */
	private function days( array $offsets, int $count = 3 ): array {
		$out = array();
		foreach ( $offsets as $offset ) {
			$out[ gmdate( 'Y-m-d', strtotime( self::TODAY . ' UTC' ) - $offset * 86400 ) ] = $count;
		}
		return $out;
	}

	private function ago( int $days, int $hours = 0 ): int {
		return $this->now - $days * 86400 - $hours * 3600;
	}

	public function test_daily_type_is_overdue_after_two_days(): void {
		$days = $this->days( range( 3, 40 ) ); // Daily until 3 days ago.
		$r    = Rhythm::analyse( $days, self::TODAY, $this->ago( 3 ), $this->now );
		$this->assertTrue( $r['regular'] );
		$this->assertSame( 1, $r['median'] );
		$this->assertSame( 2, $r['expected'] );
		$this->assertTrue( $r['silent'] );

		$fresh = Rhythm::analyse( $this->days( range( 1, 40 ) ), self::TODAY, $this->ago( 1 ), $this->now );
		$this->assertFalse( $fresh['silent'] );
	}

	public function test_weekday_type_tolerates_weekends(): void {
		$offsets = array();
		for ( $i = 1; $i <= 56; $i++ ) {
			$weekday = (int) gmdate( 'N', strtotime( self::TODAY . ' UTC' ) - $i * 86400 );
			if ( $weekday <= 5 ) {
				$offsets[] = $i;
			}
		}
		$r = Rhythm::analyse( $this->days( $offsets ), self::TODAY, $this->ago( 3 ), $this->now );
		$this->assertSame( 4, $r['expected'] ); // Longest gap (Fri → Mon = 3) + 1.
		$this->assertFalse( $r['silent'] );
	}

	public function test_weekly_type_and_rare_type(): void {
		$weekly = Rhythm::analyse( $this->days( array( 2, 9, 16, 23, 30, 37, 44, 51 ) ), self::TODAY, $this->ago( 2 ), $this->now );
		$this->assertTrue( $weekly['regular'] );
		$this->assertSame( 7, $weekly['median'] );
		$this->assertSame( 14, $weekly['expected'] );
		$this->assertFalse( $weekly['silent'] );

		$rare = Rhythm::analyse( $this->days( array( 5, 20, 33 ) ), self::TODAY, $this->ago( 5 ), $this->now );
		$this->assertFalse( $rare['regular'] );
		$this->assertFalse( $rare['silent'] );
		$this->assertSame( 3, $rare['active'] );
	}

	public function test_today_and_old_days_are_outside_the_window(): void {
		$days                = $this->days( array( 60, 70, 80 ) );
		$days[ self::TODAY ] = 50;
		$r                   = Rhythm::analyse( $days, self::TODAY, $this->now, $this->now );
		$this->assertSame( 0, $r['active'] );
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function type( int $id, string $last_seen, string $first_seen = '2026-07-01 08:00:00', array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => $id,
				'source'      => 'plugin:woocommerce',
				'pattern'     => array( 'New', 'order', '#{#}' ),
				'first_seen'  => $first_seen,
				'last_seen'   => $last_seen,
				'last_status' => 1,
				'last_notes'  => 0,
				'muted'       => false,
			),
			$extra
		);
	}

	/**
	 * @param int[] $offsets
	 * @return array<string,array{total:int,failed:int,held:int}>
	 */
	private function counters( array $offsets, int $total = 3, int $failed = 0 ): array {
		$out = array();
		foreach ( $this->days( $offsets, $total ) as $day => $n ) {
			$out[ $day ] = array(
				'total'  => $n,
				'failed' => $failed,
				'held'   => 0,
			);
		}
		return $out;
	}

	public function test_report_states(): void {
		$types   = array(
			1 => $this->type( 1, gmdate( 'Y-m-d H:i:s', $this->ago( 4 ) ) ),                       // Daily, stopped.
			2 => $this->type( 2, gmdate( 'Y-m-d H:i:s', $this->ago( 1 ) ) ),                       // Daily, failing.
			3 => $this->type( 3, gmdate( 'Y-m-d H:i:s', $this->ago( 1 ) ), '2026-09-29 10:00:00' ), // New.
			4 => $this->type( 4, gmdate( 'Y-m-d H:i:s', $this->ago( 4 ) ), '2026-07-01 08:00:00', array( 'muted' => true ) ),
			5 => $this->type( 5, gmdate( 'Y-m-d H:i:s', $this->ago( 1 ) ) ),                       // Daily, fine.
		);
		$days    = array(
			1 => $this->counters( range( 4, 50 ) ),
			2 => $this->counters( range( 1, 50 ), 3, 1 ),
			3 => $this->counters( array( 1, 2 ) ),
			4 => $this->counters( range( 4, 50 ) ),
			5 => $this->counters( range( 1, 50 ), 10 ),
		);
		$updates = array(
			array(
				'time'  => $this->ago( 3, 20 ),
				'label' => 'WooCommerce 10.2',
				'slug'  => 'plugin:woocommerce',
			),
			array(
				'time'  => $this->ago( 10 ),
				'label' => 'Old update',
				'slug'  => 'plugin:other',
			),
		);

		$items = Report::build( $types, $days, self::TODAY, $this->now, $updates );
		$state = array_column( $items, 'state', 'id' );
		$this->assertSame( 'silent', $state[1] );
		$this->assertSame( 'failing', $state[2] );
		$this->assertSame( 'new', $state[3] );
		$this->assertSame( 'muted', $state[4] );
		$this->assertSame( 'ok', $state[5] );

		$this->assertSame( 5, $items[0]['id'], 'Most emails in 30 days first.' );
		$by_id = array_column( $items, null, 'id' );
		$this->assertCount( 30, $by_id[5]['series'] );
		$this->assertSame( 290, $by_id[5]['total'] ); // 29 full days in range × 10 (today has none).
		$this->assertSame( array( 'WooCommerce 10.2' ), array_column( $by_id[1]['updates'], 'label' ), 'Only updates after the last email.' );
		$this->assertSame( array(), $by_id[5]['updates'] );
		$this->assertSame( 'New order', $by_id[1]['search'] );

		$summary = Report::summary( $items );
		$this->assertSame( 5, $summary['types'] );
		$this->assertSame( 1, $summary['senders'] );
		$this->assertSame( 1, $summary['silent'] );
		$this->assertSame( 1, $summary['failing'] );
		$this->assertSame( 1, $summary['new'] );
	}

	public function test_report_text(): void {
		$this->assertSame( 'Hi …, your order #… has shipped', Report::text( array( 'Hi', '{…},', 'your', 'order', '#{#}', 'has', 'shipped' ) ) );
		$this->assertSame( 'Other emails', Report::text( array( '{…}*' ) ) );
		$this->assertSame( '(no subject)', Report::text( array() ) );
	}

	/**
	 * @return array<string,mixed> Report item.
	 */
	private function item( int $id, string $state, int $last_seen, bool $regular = true ): array {
		return array(
			'id'        => $id,
			'source'    => 'plugin:woocommerce',
			'pattern'   => array( 'New', 'order', '#{#}' ),
			'state'     => $state,
			'muted'     => 'muted' === $state,
			'last_seen' => $last_seen,
			'rhythm'    => array(
				'regular'  => $regular,
				'expected' => 2,
			),
			'updates'   => array(
				array(
					'time'  => $last_seen + 3600,
					'label' => 'WooCommerce 10.2',
					'slug'  => 'plugin:woocommerce',
				),
			),
		);
	}

	public function test_monitor_alerts_once_then_recovers(): void {
		$sent     = array();
		$dispatch = static function ( $type, $kind, $message ) use ( &$sent ): array {
			$sent[] = array( $type, $kind, $message );
			return array();
		};
		$monitor  = new Monitor(
			new Store(),
			$dispatch,
			function () {
				return $this->now;
			}
		);
		$settings = array( 'alert_recovery' => true );
		$stopped  = $this->ago( 4 );

		$done = $monitor->check( array( $this->item( 7, 'silent', $stopped ) ), $settings );
		$this->assertSame( array( 7 ), $done['alert'] );
		$this->assertSame( array( 'type', 'alert' ), array_slice( $sent[0], 0, 2 ) );
		$this->assertStringContainsString( '“New order #…” (WooCommerce) was last sent 4 days ago', $sent[0][2] );
		$this->assertStringContainsString( 'Updated since then: WooCommerce 10.2.', $sent[0][2] );

		// Still silent: no second alert.
		$done = $monitor->check( array( $this->item( 7, 'silent', $stopped ) ), $settings );
		$this->assertSame( array(), $done['alert'] );
		$this->assertCount( 1, $sent );

		// Sent again: recovery message, state cleared.
		$done = $monitor->check( array( $this->item( 7, 'ok', $this->ago( 0, 1 ) ) ), $settings );
		$this->assertSame( array( 7 ), $done['recovery'] );
		$this->assertSame( 'recovery', $sent[1][1] );
		$this->assertSame( '“New order #…” (WooCommerce) is being sent again.', $sent[1][2] );
		$this->assertSame( array(), $this->options[ Monitor::STATE ] );
	}

	public function test_monitor_forgets_muted_and_irregular_types_silently(): void {
		$sent     = array();
		$dispatch = static function ( $type, $kind ) use ( &$sent ): array {
			$sent[] = $kind;
			return array();
		};
		$monitor  = new Monitor(
			new Store(),
			$dispatch,
			function () {
				return $this->now;
			}
		);
		$settings = array( 'alert_recovery' => true );

		$monitor->check( array( $this->item( 1, 'silent', $this->ago( 5 ) ), $this->item( 2, 'silent', $this->ago( 5 ) ) ), $settings );
		$this->assertSame( array( 'alert', 'alert' ), $sent );

		$monitor->check( array( $this->item( 1, 'muted', $this->ago( 5 ) ), $this->item( 2, 'ok', $this->ago( 5 ), false ) ), $settings );
		$this->assertSame( array( 'alert', 'alert' ), $sent, 'No recovery for muted or no-longer-regular types.' );
		$this->assertSame( array(), $this->options[ Monitor::STATE ] );

		// Without recovery messages, a returning type clears its state quietly.
		$monitor->check( array( $this->item( 3, 'silent', $this->ago( 5 ) ) ), array() );
		$monitor->check( array( $this->item( 3, 'ok', $this->ago( 0, 1 ) ) ), array() );
		$this->assertSame( array( 'alert', 'alert', 'alert' ), $sent );
		$this->assertSame( array(), $this->options[ Monitor::STATE ] );
	}
}
