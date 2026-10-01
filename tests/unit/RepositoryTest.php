<?php
/**
 * Repository: query construction (everything prepared, ORDER BY whitelisted).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Mailspur\Repository;

final class RepositoryTest extends TestCase {

	/** @var Repository */
	private $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new Repository();
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array{items:array<int,array<string,string>>,total:int,counts:array<string,int>}
	 */
	private function query( array $args, int $sent = 3, int $failed = 1 ): array {
		$this->wpdb->results = array(
			array(
				array(
					'status' => '1',
					'n'      => (string) $sent,
				),
				array(
					'status' => '2',
					'n'      => (string) $failed,
				),
			),
			array( array( 'id' => '9' ) ),
		);
		return $this->repository->query( $args );
	}

	public function test_counts_come_from_one_grouped_query(): void {
		$result = $this->query( array() );

		$this->assertSame(
			array(
				'all'     => 4,
				'sent'    => 3,
				'failed'  => 1,
				'pending' => 0,
			),
			$result['counts']
		);
		$this->assertSame( 4, $result['total'] );
		$this->assertStringContainsString( 'GROUP BY status', $this->wpdb->prepared[0]['sql'] );
	}

	public function test_search_is_escaped_and_parameterised(): void {
		$this->query( array( 'search' => "50%_off' OR 1=1 --" ) );

		$prepared = $this->wpdb->prepared[1];
		$this->assertStringContainsString( '(recipients LIKE %s OR subject LIKE %s)', $prepared['sql'] );
		$this->assertStringNotContainsString( 'OR 1=1', $prepared['sql'] );
		$this->assertSame( '%50\%\_off\' OR 1=1 --%', $prepared['args'][1] );
	}

	public function test_body_search_is_opt_in(): void {
		$this->query( array( 'search' => 'x' ) );
		$this->assertStringNotContainsString( 'message LIKE', $this->wpdb->prepared[1]['sql'] );

		$this->query(
			array(
				'search'  => 'x',
				'in_body' => true,
			)
		);
		$this->assertStringContainsString( 'message LIKE %s', $this->wpdb->prepared[3]['sql'] );
	}

	public function test_unknown_order_column_falls_back_to_id(): void {
		$this->query(
			array(
				'orderby' => 'id; DROP TABLE wp_users',
				'order'   => 'asc',
			)
		);

		$prepared = $this->wpdb->prepared[1];
		$this->assertStringContainsString( 'ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d', $prepared['sql'] );
		$this->assertContains( 'id', $prepared['args'] );
		$this->assertNotContains( 'id; DROP TABLE wp_users', $prepared['args'] );
	}

	public function test_status_filter_uses_total_of_that_status_and_paginates(): void {
		$result = $this->query(
			array(
				'status'   => 'failed',
				'page'     => 3,
				'per_page' => 10,
			)
		);

		$this->assertSame( 1, $result['total'] );
		$args = $this->wpdb->prepared[1]['args'];
		$this->assertSame( array( 'wp_mailspur', 2, 'id', 10, 20 ), $args );
	}

	public function test_empty_result_skips_the_item_query(): void {
		$result = $this->query( array( 'status' => 'pending' ) );

		$this->assertSame( array(), $result['items'] );
		$this->assertCount( 1, $this->wpdb->prepared );
	}

	public function test_extract_emails_handles_display_names(): void {
		$this->assertSame(
			array( 'anna@example.com', 'bob@example.org' ),
			Repository::extract_emails( 'Anna@Example.com, "Bob B." <bob@example.org>' )
		);
	}

	public function test_find_by_recipient_ignores_partial_matches(): void {
		$this->wpdb->results = array(
			array(
				array(
					'id'         => '1',
					'recipients' => 'joanna@example.com',
				),
				array(
					'id'         => '2',
					'recipients' => 'Anna <anna@example.com>',
				),
			),
		);

		$scanned = 0;
		$rows    = $this->repository->find_by_recipient( 'anna@example.com', 100, 0, $scanned );

		$this->assertSame( 2, $scanned );
		$this->assertSame( array( '2' ), array_column( $rows, 'id' ) );
	}

	public function test_delete_casts_ids_to_integers(): void {
		$this->wpdb->results = array( 2 );
		$this->assertSame( 2, $this->repository->delete( array( '3', 'x', 0, '7abc' ) ) );

		$prepared = $this->wpdb->prepared[0];
		$this->assertSame( 'DELETE FROM %i WHERE id IN (%d,%d)', $prepared['sql'] );
		$this->assertSame( array( 'wp_mailspur', 3, 7 ), $prepared['args'] );
	}
}
