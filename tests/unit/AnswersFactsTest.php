<?php
/**
 * Answers facts: which email types are stopped, expected today or scheduled later today (conservative weekday
 * rule), the next cron run and the most common failure reason.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Answers\Facts;

final class AnswersFactsTest extends TestCase {

	/** @var int Tuesday 2026-10-06 12:00 UTC */
	private $now;

	/** @var int */
	private $end;

	protected function setUp(): void {
		parent::setUp();
		$this->now = (int) strtotime( '2026-10-06 12:00:00 UTC' );
		$this->end = (int) strtotime( '2026-10-06 23:59:59 UTC' );
		Functions\stubTranslationFunctions();
	}

	/**
	 * A report item with a 30-day series (index 29 = today).
	 *
	 * @param int[]               $sent  Days back (1 = yesterday) with emails.
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function item( array $sent, array $extra = array() ): array {
		$series = array();
		for ( $back = 29; $back >= 0; $back-- ) {
			$series[] = array( gmdate( 'Y-m-d', $this->now - $back * 86400 ), in_array( $back, $sent, true ) ? 2 : 0, 0, 0 );
		}
		return array_merge(
			array(
				'name'      => 'Subscription reminder',
				'label'     => 'WooCommerce',
				'muted'     => false,
				'other'     => false,
				'state'     => 'ok',
				'last_seen' => $this->now - 86400,
				'rhythm'    => array(
					'regular'  => true,
					'expected' => 2,
				),
				'series'    => $series,
				'cron'      => null,
				'cause'     => null,
			),
			$extra
		);
	}

	public function test_daily_type_not_sent_today_is_due(): void {
		$out = Facts::types( array( $this->item( range( 1, 29 ) ) ), $this->now, $this->end, array(), 'Tuesday' );
		$this->assertSame( 1, $out['regular'] );
		$this->assertCount( 1, $out['due'] );
		$this->assertTrue( $out['due'][0]['daily'] );
		$this->assertSame( 'WooCommerce', $out['due'][0]['source'] );
		$this->assertSame( 0, $out['due'][0]['next'] );
	}

	public function test_weekday_type_is_due_only_on_its_weekday_with_four_weeks_of_history(): void {
		$out = Facts::types( array( $this->item( array( 7, 14, 21, 28 ) ) ), $this->now, $this->end, array(), 'Tuesday' );
		$this->assertCount( 1, $out['due'] );
		$this->assertFalse( $out['due'][0]['daily'] );
		$this->assertSame( 'Tuesday', $out['due'][0]['weekday'] );

		// One missing week: not stable enough – never alarm.
		$out = Facts::types( array( $this->item( array( 7, 14, 28 ) ) ), $this->now, $this->end, array(), 'Tuesday' );
		$this->assertSame( array(), $out['due'] );
		// Another weekday.
		$out = Facts::types( array( $this->item( array( 6, 13, 20, 27 ) ) ), $this->now, $this->end, array(), 'Tuesday' );
		$this->assertSame( array(), $out['due'] );
	}

	public function test_sent_today_irregular_muted_or_catch_all_types_are_not_due(): void {
		$items = array(
			$this->item( range( 0, 29 ) ),
			$this->item( range( 1, 29 ), array( 'rhythm' => array( 'regular' => false ) ) ),
			$this->item( range( 1, 29 ), array( 'muted' => true ) ),
			$this->item( range( 1, 29 ), array( 'other' => true ) ),
		);
		$out   = Facts::types( $items, $this->now, $this->end, array(), 'Tuesday' );
		$this->assertSame( array(), $out['due'] );
		$this->assertSame( 1, $out['regular'], 'Irregular, muted and catch-all types do not count.' );
	}

	public function test_stopped_types_carry_rhythm_and_cause(): void {
		$item = $this->item(
			array( 10, 11, 12 ),
			array(
				'state'     => 'silent',
				'last_seen' => $this->now - 10 * 86400,
				'cause'     => array(
					'code' => 'unscheduled',
					'text' => 'No longer scheduled.',
				),
			)
		);
		$out  = Facts::types( array( $item ), $this->now, $this->end, array(), 'Tuesday' );
		$this->assertSame(
			array(
				array(
					'name'      => 'Subscription reminder',
					'source'    => 'WooCommerce',
					'last_seen' => $this->now - 10 * 86400,
					'expected'  => 2,
					'cause'     => 'No longer scheduled.',
				),
			),
			$out['stopped']
		);
		$this->assertSame( array(), $out['due'] );
	}

	public function test_cron_events_later_today(): void {
		$crons = array(
			$this->now - 600      => array( 'wcs_reminder' => array( 'x' => array() ) ), // Overdue: not "later today".
			$this->now + 3600     => array( 'wcs_reminder' => array( 'x' => array() ) ),
			$this->now + 2 * 3600 => array( 'my_digest' => array( 'x' => array() ) ),
			$this->end + 60       => array( 'tomorrow_hook' => array( 'x' => array() ) ),
			'version'             => 2,
		);
		$items = array(
			$this->item( range( 1, 29 ), array( 'cron' => 'wcs_reminder' ) ),
			$this->item(
				array( 3, 17 ),
				array(
					'name'   => 'Digest',
					'cron'   => 'my_digest',
					'rhythm' => array( 'regular' => false ),
				)
			),
			$this->item( array( 2 ), array( 'cron' => 'tomorrow_hook' ) ),
			$this->item( array( 0, 2 ), array( 'cron' => 'my_digest' ) ), // Already sent today.
		);
		$out   = Facts::types( $items, $this->now, $this->end, $crons, 'Tuesday' );
		$this->assertSame( $this->now + 3600, $out['due'][0]['next'] );
		$this->assertCount( 1, $out['scheduled'] );
		$this->assertSame( 'Digest', $out['scheduled'][0]['name'] );
		$this->assertSame( $this->now + 2 * 3600, $out['scheduled'][0]['next'] );

		$this->assertSame( 0, Facts::next_run( 'unknown', $crons, $this->now, $this->end ) );
		$this->assertSame( 0, Facts::next_run( 'tomorrow_hook', $crons, $this->now, $this->end ) );
	}

	public function test_top_failure_groups_messages_with_the_same_explanation(): void {
		$top = Facts::top_failure(
			array(
				array(
					'error' => 'Mystery',
					'n'     => 2,
				),
				array(
					'error' => 'SMTP Error: Could not authenticate. (smtp.a.example)',
					'n'     => 2,
				),
				array(
					'error' => 'SMTP Error: Could not authenticate. (smtp.b.example)',
					'n'     => 1,
				),
			),
			4
		);
		$this->assertSame( 5, $top['total'], 'Never fewer than the grouped errors.' );
		$this->assertSame( 3, $top['count'] );
		$this->assertSame( 'SMTP Error: Could not authenticate. (smtp.a.example)', $top['error'] );

		$this->assertSame(
			array(
				'total' => 0,
				'count' => 0,
				'error' => '',
			),
			Facts::top_failure( array(), 0 )
		);
	}
}
