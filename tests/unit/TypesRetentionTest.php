<?php
/**
 * Retention per email type: the cleanup rules (longer and shorter periods than the log's, "until the log limit"),
 * anonymising instead of deleting, the rules built from the stored types and the effective periods shown in the
 * email inventory.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Cleanup;
use Mailspur\Modules\Types\Retention;
use Mailspur\Modules\Types\Store;
use Mailspur\Modules\Workflow\Anonymiser;
use Mailspur\Repository;

final class TypesRetentionTest extends TestCase {

	const NOW = 1790000000;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n ) {
					return (string) $n;
				},
				'delete_transient'   => true,
			)
		);
		$this->settings = array(
			'retention_days' => 30,
			'max_entries'    => 0,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rules
	 */
	private function rules( array $rules ): void {
		Filters\expectApplied( 'mailspur_retention_rules' )->andReturn( $rules );
	}

	/** @return array<string,mixed>|null First prepared query containing a text. */
	private function prepared( string $text ): ?array {
		foreach ( $this->wpdb->prepared as $query ) {
			if ( false !== strpos( $query['sql'], $text ) ) {
				return $query;
			}
		}
		return null;
	}

	private static function invoices(): callable {
		return static function ( string $subject ): bool {
			return 0 === strpos( $subject, 'Invoice' );
		};
	}

	public function test_without_rules_everything_goes_by_date(): void {
		$this->rules( array() );
		$this->wpdb->results = array( 0 );
		( new Cleanup( new Repository() ) )->run( self::NOW );
		$delete = $this->prepared( 'DELETE FROM %i WHERE created_at' );
		$this->assertNotNull( $delete );
		$this->assertStringNotContainsString( 'NOT IN', $delete['sql'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 30 * DAY_IN_SECONDS ), $delete['args'][1] );
		$this->assertNull( $this->prepared( 'SELECT id, created_at, subject' ), 'no entry is read' );
	}

	public function test_longer_period_keeps_only_the_entries_of_that_type(): void {
		$this->rules(
			array(
				array(
					'source' => 'plugin:shop',
					'days'   => 365,
					'match'  => self::invoices(),
				),
			)
		);
		$old                 = gmdate( 'Y-m-d H:i:s', self::NOW - 40 * DAY_IN_SECONDS );
		$this->wpdb->results = array(
			0, // General deletion, without the shop.
			array(
				array(
					'id'         => '1',
					'created_at' => $old,
					'subject'    => 'Invoice 1001',
				),
				array(
					'id'         => '2',
					'created_at' => $old,
					'subject'    => 'Welcome, Anna',
				),
				array(
					'id'         => '3',
					'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 400 * DAY_IN_SECONDS ),
					'subject'    => 'Invoice 17',
				),
			),
			2,
		);
		( new Cleanup( new Repository() ) )->run( self::NOW );

		$general = $this->prepared( 'source NOT IN (%s)' );
		$this->assertNotNull( $general );
		$this->assertSame( 'plugin:shop', $general['args'][2] );

		$sweep = $this->prepared( 'SELECT id, created_at, subject' );
		$this->assertSame( array( 'plugin:shop', gmdate( 'Y-m-d H:i:s', self::NOW - 30 * DAY_IN_SECONDS ), 0 ), array_slice( $sweep['args'] ?? array(), 1, 3 ) );
		$this->assertStringNotContainsString( 'anonymised', (string) ( $sweep['sql'] ?? '' ), 'the general period also deletes anonymised entries' );

		$delete = $this->prepared( 'WHERE id IN' );
		$this->assertSame( array( 2, 3 ), array_slice( $delete['args'] ?? array(), 1 ), 'other emails of the sender and invoices older than a year' );
	}

	public function test_until_the_log_limit(): void {
		$this->settings['max_entries'] = 1000;
		$this->rules(
			array(
				array(
					'source' => 'plugin:shop',
					'days'   => Cleanup::UNTIL_LIMIT,
					'match'  => self::invoices(),
				),
			)
		);
		$this->wpdb->results = array(
			0,
			array(
				array(
					'id'         => '3',
					'created_at' => '2001-01-01 00:00:00',
					'subject'    => 'Invoice 17',
				),
			),
			null, // trim_to(): fewer entries than the limit.
		);
		( new Cleanup( new Repository() ) )->run( self::NOW );
		$this->assertNull( $this->prepared( 'WHERE id IN' ), 'kept however old' );
		$this->assertNotNull( $this->prepared( 'OFFSET %d' ), 'the limit still applies' );
	}

	public function test_shorter_period_deletes_only_that_type(): void {
		$this->rules(
			array(
				array(
					'source' => 'core',
					'days'   => 7,
					'match'  => static function ( string $subject ): bool {
						return false !== strpos( $subject, 'Password Reset' );
					},
				),
				array(
					'source' => 'core',
					'days'   => 0, // Invalid: ignored.
					'match'  => '__return_true',
				),
				array(
					'source' => 'core',
					'days'   => 7,
					'match'  => 'not a callable',
				),
			)
		);
		$this->wpdb->results = array(
			0,
			array(
				array(
					'id'         => '5',
					'created_at' => '2026-09-01 10:00:00',
					'subject'    => '[Site] Password Reset',
				),
				array(
					'id'         => '6',
					'created_at' => '2026-09-01 10:00:00',
					'subject'    => '[Site] New user registration',
				),
			),
			1,
		);
		( new Cleanup( new Repository() ) )->run( self::NOW );

		$sweep = $this->prepared( 'SELECT id, created_at, subject' );
		$this->assertStringContainsString( 'meta NOT LIKE %s', (string) ( $sweep['sql'] ?? '' ), 'anonymised entries wait for the general period' );
		$this->assertSame( array( 'core', gmdate( 'Y-m-d H:i:s', self::NOW - 7 * DAY_IN_SECONDS ) ), array_slice( $sweep['args'] ?? array(), 1, 2 ) );
		$delete = $this->prepared( 'WHERE id IN' );
		$this->assertSame( array( 5 ), array_slice( $delete['args'] ?? array(), 1 ) );
	}

	public function test_shorter_period_can_anonymise_instead(): void {
		$this->rules(
			array(
				array(
					'source' => 'core',
					'days'   => 7,
					'match'  => '__return_true',
				),
			)
		);
		Filters\expectApplied( 'mailspur_retention_expire' )->once()->with( array( 5 ) )->andReturn( array() );
		$this->wpdb->results = array(
			0,
			array(
				array(
					'id'         => '5',
					'created_at' => '2026-09-01 10:00:00',
					'subject'    => 'Hello',
				),
			),
		);
		( new Cleanup( new Repository() ) )->run( self::NOW );
		$this->assertNull( $this->prepared( 'WHERE id IN' ) );
	}

	public function test_anonymiser_keeps_expired_entries_anonymised(): void {
		$anonymiser = new Anonymiser( new Repository() );
		$this->assertSame( array( 5, 6 ), $anonymiser->expire( array( 5, '6' ) ), 'anonymisation off: delete' );

		$this->settings['anonymise_days'] = 30;
		$this->wpdb->results              = array(
			array(
				'id'          => '5',
				'created_at'  => '2026-09-01 10:00:00',
				'recipients'  => 'anna@example.com',
				'sender'      => '',
				'subject'     => 'Hello',
				'message'     => 'Secret text',
				'attachments' => '',
				'error'       => '',
				'meta'        => '{"delivery":{"held":"bundled"},"notes":2}',
			),
			array(
				'id'   => '6',
				'meta' => '{"anonymised":{"at":1,"days":30}}',
			),
		);
		$this->assertSame( array(), $anonymiser->expire( array( 5, 6 ) ) );
		$updates = $this->writes( 'update' );
		$this->assertCount( 1, $updates, 'already anonymised entries are left alone' );
		$this->assertSame( '', $updates[0][2]['message'] );
		$this->assertSame( 'a***@example.com', $updates[0][2]['recipients'] );
	}

	public function test_rules_of_the_stored_types(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return Store::DB_OPTION === $name ? Store::DB_VERSION : ( 'mailspur_settings' === $name ? $this->settings : $fallback );
			}
		);
		$type                = static function ( int $id, string $source, string $pattern, int $keep ): array {
			return array(
				'id'          => (string) $id,
				'source'      => $source,
				'pattern'     => $pattern,
				'first_seen'  => '2026-09-01 00:00:00',
				'last_seen'   => '2026-09-01 00:00:00',
				'last_status' => '1',
				'last_notes'  => '0',
				'muted'       => '0',
				'bundle'      => '0',
				'keep_days'   => (string) $keep,
				'extra'       => '',
			);
		};
		$this->wpdb->results = array(
			array(
				$type( 1, 'core', '[Site] Password Reset', 7 ),
				$type( 2, 'core', '[Site] Welcome, {…}', 0 ),
				$type( 3, 'core', '{…}*', 30 ),
				$type( 4, 'plugin:shop', 'Invoice #{#}', Cleanup::UNTIL_LIMIT ),
			),
		);
		$rules               = ( new Retention( new Store() ) )->rules( array( 'other module' ) );
		$this->assertCount( 3, $rules, 'one rule per type with an own period, never for the catch-all type' );
		$this->assertSame( 'other module', $rules[0] );
		$this->assertSame( array( 'core', 7 ), array( $rules[1]['source'], $rules[1]['days'] ) );
		$this->assertTrue( $rules[1]['match']( '[Site] Password Reset' ) );
		$this->assertFalse( $rules[1]['match']( '[Site] Welcome, Anna' ) );
		$this->assertSame( array( 'plugin:shop', Cleanup::UNTIL_LIMIT ), array( $rules[2]['source'], $rules[2]['days'] ) );
		$this->assertTrue( $rules[2]['match']( 'Invoice #1042' ) );
	}

	public function test_choices_labels_and_effective_periods(): void {
		$this->assertSame( 7, Retention::sanitize( 7 ) );
		$this->assertSame( Cleanup::UNTIL_LIMIT, Retention::sanitize( -1 ) );
		$this->assertSame( 0, Retention::sanitize( 8 ), 'only the offered choices' );

		$this->assertSame( 'Default (30 days)', Retention::label( 0 ) );
		$this->assertSame( '365 days', Retention::label( 365 ) );
		$this->assertSame( 'Until the log limit', Retention::label( Cleanup::UNTIL_LIMIT ) );
		$this->settings['retention_days'] = 0;
		$this->assertSame( 'Default (no time limit)', Retention::label( 0 ) );

		// Deleted after, anonymised after (0 = never / no time limit).
		$this->assertSame( array( 90, 0 ), Retention::effective( 0, 90, 0 ) );
		$this->assertSame( array( 7, 0 ), Retention::effective( 7, 90, 0 ) );
		$this->assertSame( array( 365, 30 ), Retention::effective( 365, 90, 30 ) );
		$this->assertSame( array( 0, 30 ), Retention::effective( Cleanup::UNTIL_LIMIT, 90, 30 ) );
		$this->assertSame( array( 90, 7 ), Retention::effective( 7, 90, 30 ), 'with anonymisation on, a shorter period anonymises' );
		$this->assertSame( array( 0, 7 ), Retention::effective( 7, 0, 30 ) );
	}
}
