<?php
/**
 * Review request: only when it is going well, never too early, snooze and "done" respected.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Review;

final class ReviewTest extends TestCase {

	const NOW = 1790000000;

	/**
	 * @return array{since:int,state:string,until:int}
	 */
	private function state( int $days_ago, string $state = '', int $until = 0 ): array {
		return array(
			'since' => self::NOW - $days_ago * DAY_IN_SECONDS,
			'state' => $state,
			'until' => $until,
		);
	}

	/**
	 * @return array{sent:int,week:int,failed:int}
	 */
	private function health( int $sent, int $week = 100, int $failed = 0 ): array {
		return array(
			'sent'   => $sent,
			'week'   => $week,
			'failed' => $failed,
		);
	}

	public function test_asks_after_two_weeks_when_it_goes_well(): void {
		$this->assertTrue( Review::due( $this->state( 15 ), $this->health( 200 ), self::NOW ) );
	}

	public function test_never_too_early_or_with_too_little_use(): void {
		$this->assertFalse( Review::due( $this->state( 13 ), $this->health( 200 ), self::NOW ), 'Less than 14 days.' );
		$this->assertFalse( Review::due( $this->state( 30 ), $this->health( 24 ), self::NOW ), 'Fewer than 25 delivered emails.' );
		$this->assertFalse( Review::due( $this->state( 0 ) + array( 'since' => 0 ), $this->health( 200 ), self::NOW ), 'Start unknown.' );
	}

	public function test_not_while_emails_fail(): void {
		$this->assertTrue( Review::due( $this->state( 30 ), $this->health( 500, 100, 5 ), self::NOW ), '5 % failures are tolerated.' );
		$this->assertFalse( Review::due( $this->state( 30 ), $this->health( 500, 100, 6 ), self::NOW ), 'More is not "going well".' );
		$this->assertFalse( Review::due( $this->state( 30 ), $this->health( 500, 10, 1 ), self::NOW ), 'One failure in a quiet week counts.' );
	}

	public function test_snooze_and_done(): void {
		$this->assertFalse( Review::due( $this->state( 40, 'later', self::NOW + DAY_IN_SECONDS ), $this->health( 200 ), self::NOW ) );
		$this->assertTrue( Review::due( $this->state( 40, 'later', self::NOW - 1 ), $this->health( 200 ), self::NOW ) );
		$this->assertFalse( Review::due( $this->state( 400, 'done' ), $this->health( 9999 ), self::NOW ) );
	}

	public function test_row_meta_only_for_this_plugin(): void {
		Functions\stubs(
			array(
				'plugin_basename' => 'mailspur-email-log/mailspur-email-log.php',
				'esc_url'         => static function ( $url ) {
					return $url;
				},
				'esc_html__'      => static function ( $text ) {
					return $text;
				},
			)
		);
		$meta = Review::row_meta( array( 'Version 1.0.0' ), 'mailspur-email-log/mailspur-email-log.php' );
		$this->assertCount( 2, $meta );
		$this->assertStringContainsString( 'reviews/#new-post', $meta[1] );
		$this->assertStringContainsString( '>Leave a review</a>', $meta[1] );
		$this->assertSame( array( 'x' ), Review::row_meta( array( 'x' ), 'other/other.php' ) );
	}
}
