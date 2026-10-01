<?php
/**
 * Duplicate detection across sources during import.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Import\Duplicates;
use Mailspur\Repository;

final class DuplicatesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPressVIPMinimum.Functions.StripTags -- stub of wp_strip_all_tags().
			}
		);
	}

	/**
	 * @param array<string,string|int> $overrides
	 * @return array<string,string|int>
	 */
	private static function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'created_at' => '2026-07-30 12:00:30',
				'recipients' => 'Bob <bob@example.org>, anna@example.com',
				'subject'    => 'Your order #12',
				'source'     => 'import:email-log',
			),
			$overrides
		);
	}

	public function test_recipients_compare_as_address_set(): void {
		$this->assertSame( Duplicates::emails( 'anna@example.com,bob@example.org' ), Duplicates::emails( 'Bob <BOB@example.org>, Anna <anna@example.com>' ) );
		$this->assertNotSame( Duplicates::emails( 'anna@example.com' ), Duplicates::emails( 'anna@example.com, bob@example.org' ) );
	}

	public function test_subjects_are_normalized(): void {
		$this->assertTrue( Duplicates::same_subject( Duplicates::subject( 'Tom &amp; Jerry&#039;s   Offer' ), Duplicates::subject( "Tom & Jerry's offer" ) ) );
		$this->assertFalse( Duplicates::same_subject( Duplicates::subject( 'Order #12' ), Duplicates::subject( 'Order #13' ) ) );
	}

	public function test_truncated_long_subjects_match_but_short_prefixes_do_not(): void {
		$long = str_repeat( 'Very long subject ', 15 ); // ~270 characters.
		$wpml = substr( Duplicates::subject( $long ), 0, 195 ) . '...';
		$this->assertTrue( Duplicates::same_subject( Duplicates::subject( $long ), Duplicates::subject( $wpml ) ) );
		// Short subjects must match exactly: "Order" is not a copy of "Order #12".
		$this->assertFalse( Duplicates::same_subject( Duplicates::subject( 'Order' ), Duplicates::subject( 'Order #12' ) ) );
	}

	public function test_match_from_another_source_within_the_window_is_a_duplicate(): void {
		$this->wpdb->results = array(
			array(
				array(
					'recipients' => 'anna@example.com, bob@example.org',
					'subject'    => 'Your order #12',
				),
			),
		);

		$this->assertTrue( ( new Duplicates( new Repository() ) )->exists( self::row() ) );

		$query = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'created_at BETWEEN %s AND %s AND source <> %s AND recipients LIKE %s', $query['sql'] );
		$this->assertSame( array( 'wp_mailspur', '2026-07-30 11:58:30', '2026-07-30 12:02:30', 'import:email-log', '%anna@example.com%' ), $query['args'] );
	}

	public function test_different_subject_or_recipients_is_no_duplicate(): void {
		$this->wpdb->results = array(
			array(
				array(
					'recipients' => 'anna@example.com',
					'subject'    => 'Your order #12',
				),
				array(
					'recipients' => 'anna@example.com, bob@example.org',
					'subject'    => 'Your order #13',
				),
			),
		);

		$this->assertFalse( ( new Duplicates( new Repository() ) )->exists( self::row() ) );
	}

	public function test_window_is_filterable_and_zero_disables_detection(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'mailspur_import_duplicate_window' === $hook ? 0 : $value;
			}
		);

		$this->assertFalse( ( new Duplicates( new Repository() ) )->exists( self::row() ) );
		$this->assertSame( array(), $this->wpdb->prepared );
	}
}
