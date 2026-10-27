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
				'held'    => 0,
			),
			$result['counts']
		);
		$this->assertSame( 4, $result['total'] );
		$this->assertSame( 'SELECT status, COUNT(*) AS n FROM %i ' . self::WHERE . ' GROUP BY status', $this->wpdb->prepared[0]['sql'] );
		$this->assertSame( array_merge( array( 'wp_mailspur' ), self::where() ), $this->wpdb->prepared[0]['args'] );
	}

	public function test_every_filtered_query_uses_the_same_condition_and_values(): void {
		$this->query( array( 'search' => 'x' ) );
		foreach ( $this->wpdb->prepared as $prepared ) {
			$this->assertStringContainsString( ' FROM %i ' . self::WHERE . ' ', $prepared['sql'] );
			$this->assertSame( substr_count( $prepared['sql'], '%' ) - 2 * substr_count( $prepared['sql'], '%%' ), count( $prepared['args'] ), 'one value per placeholder' );
		}
	}

	public function test_search_is_escaped_and_parameterised(): void {
		$this->query( array( 'search' => "50%_off' OR 1=1 --" ) );

		$prepared = $this->wpdb->prepared[1];
		$this->assertStringNotContainsString( 'OR 1=1', $prepared['sql'] );
		$this->assertSame(
			array_merge(
				array( '{"anonymised":%', 'wp_mailspur' ),
				self::where(
					array(
						'search_on' => 1,
						'search'    => '%50\%\_off\' OR 1=1 --%',
					)
				),
				array( 'created_at', 25, 0 )
			),
			$prepared['args']
		);
	}

	public function test_body_search_is_opt_in(): void {
		$this->query( array( 'search' => 'x' ) );
		$this->assertSame( 0, $this->wpdb->prepared[1]['args'][5], 'message LIKE switched off' );

		$this->query(
			array(
				'search'  => 'x',
				'in_body' => true,
			)
		);
		$this->assertSame( 1, $this->wpdb->prepared[3]['args'][5] );
		$this->assertSame( '%x%', $this->wpdb->prepared[3]['args'][6] );
	}

	public function test_unknown_order_column_falls_back_to_send_date(): void {
		$this->query(
			array(
				'orderby' => 'id; DROP TABLE wp_users',
				'order'   => 'asc',
			)
		);

		$prepared = $this->wpdb->prepared[1];
		$this->assertStringEndsWith( 'ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d', $prepared['sql'] );
		$this->assertContains( 'created_at', $prepared['args'] );
		$this->assertNotContains( 'id; DROP TABLE wp_users', $prepared['args'] );

		$this->query( array( 'order' => 'desc; DROP TABLE x' ) );
		$this->assertStringEndsWith( 'ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d', $this->wpdb->prepared[3]['sql'] );
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
		$this->assertSame( self::where(), array_slice( $this->wpdb->prepared[0]['args'], 1 ), 'Counts cover every status.' );
		$this->assertSame(
			array_merge(
				array( '{"anonymised":%', 'wp_mailspur' ),
				self::where(
					array(
						'status_on' => 1,
						'status'    => 2,
					)
				),
				array( 'created_at', 10, 20 )
			),
			$this->wpdb->prepared[1]['args']
		);
	}

	public function test_empty_result_skips_the_item_query(): void {
		$result = $this->query( array( 'status' => 'pending' ) );

		$this->assertSame( array(), $result['items'] );
		$this->assertCount( 1, $this->wpdb->prepared );
	}

	public function test_source_format_attachment_and_notes_filters(): void {
		$this->query(
			array(
				'source'      => "plugin:x' OR 1=1",
				'format'      => 'html',
				'attachments' => true,
				'notes'       => true,
				'status'      => 'failed',
			)
		);

		$on = array(
			'source_on'   => 1,
			'source'      => "plugin:x' OR 1=1",
			'html'        => 1,
			'attachments' => 1,
			'notes'       => 1,
		);
		$this->assertSame( array_merge( array( 'wp_mailspur' ), self::where( $on ) ), $this->wpdb->prepared[0]['args'], 'Counts cover every status.' );
		$this->assertSame(
			array_merge(
				array( '{"anonymised":%', 'wp_mailspur' ),
				self::where(
					$on + array(
						'status_on' => 1,
						'status'    => 2,
					)
				),
				array( 'created_at', 25, 0 )
			),
			$this->wpdb->prepared[1]['args']
		);
	}

	public function test_plain_text_format_and_unknown_values_are_ignored_safely(): void {
		$this->query( array( 'format' => 'text' ) );
		$this->assertSame( array_merge( array( 'wp_mailspur' ), self::where( array( 'text' => 1 ) ) ), $this->wpdb->prepared[0]['args'] );

		$this->query(
			array(
				'format'      => 'pdf; DROP TABLE x',
				'source'      => '',
				'attachments' => false,
				'notes'       => false,
			)
		);
		$this->assertSame( array_merge( array( 'wp_mailspur' ), self::where() ), $this->wpdb->prepared[2]['args'] );
	}

	public function test_provider_status_filter_uses_the_indexed_column(): void {
		$this->query( array( 'delivery' => 'bounced' ) );
		$this->assertSame(
			array_merge(
				array( 'wp_mailspur' ),
				self::where(
					array(
						'delivery_on' => 1,
						'delivery'    => 3,
					)
				)
			),
			$this->wpdb->prepared[0]['args']
		);
		$this->assertStringNotContainsString( 'meta LIKE', $this->wpdb->prepared[0]['sql'], 'no scan of the JSON column' );

		$this->query( array( 'delivery' => "bounced' OR 1=1" ) );
		$this->assertSame( array_merge( array( 'wp_mailspur' ), self::where() ), $this->wpdb->prepared[2]['args'], 'unknown statuses are ignored' );

		$this->assertSame( 4, Repository::delivery_code( 'complaint' ) );
		$this->assertSame( 3, Repository::delivery_code( 'bounced', true ) );
		$this->assertSame( 1, Repository::delivery_code( 'bounced', false ) );
		$this->assertSame( 0, Repository::delivery_code( 'opened' ) );
		$this->assertSame( 'soft_bounce', Repository::delivery_slug( 1 ) );
		$this->assertSame( '', Repository::delivery_slug( 0 ) );
	}

	public function test_backfill_reads_the_status_from_the_meta(): void {
		$this->wpdb->results = array(
			array(
				array(
					'id'   => '4',
					'meta' => '{"feedback":{"ref":"abc","event":"bounced","hard":false}}',
				),
				array(
					'id'   => '7',
					'meta' => '{"feedback":{"event":"complaint"}}',
				),
				array(
					'id'   => '8',
					'meta' => '{"feedback":{"ref":"abc"},"x":{"event":"delivered"}}',
				),
			),
		);
		$this->assertSame( 2, $this->repository->backfill_delivery() );
		$this->assertSame(
			array( array( 'delivery' => 1 ), array( 'delivery' => 4 ) ),
			array_column( $this->writes( 'update' ), 2 )
		);
		$this->assertSame( '%"feedback":{%"event":%', $this->wpdb->prepared[0]['args'][3] );
	}

	public function test_list_rows_carry_the_anonymised_flag(): void {
		$this->query( array() );
		$this->assertStringStartsWith( 'SELECT id, created_at, status, recipients, subject, attachments, source, error, notes, size, ( meta LIKE %s ) AS anonymised FROM', $this->wpdb->prepared[1]['sql'] );
		$this->assertSame( '{"anonymised":%', $this->wpdb->prepared[1]['args'][0] );
	}

	public function test_filter_is_shared_with_export_and_cli(): void {
		$filter = $this->repository->filter(
			array(
				'status' => 'failed',
				'search' => 'anna',
				'after'  => '2026-01-01',
			)
		);
		$this->assertSame( 1, $filter['search_on'] );
		$this->assertSame( '%anna%', $filter['search'] );
		$this->assertSame( 1, $filter['after_on'] );
		$this->assertSame( '2026-01-01 00:00:00', $filter['after'] );
		$this->assertSame( 0, $filter['before_on'] );
		$this->assertSame( 1, $filter['status_on'] );
		$this->assertSame( 2, $filter['status'] );

		$this->assertSame( 0, $this->repository->filter( array( 'status' => 'failed' ), false )['status_on'] );
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
