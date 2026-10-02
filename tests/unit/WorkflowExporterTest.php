<?php
/**
 * Workflow module: CSV/JSON export (formula injection, BOM, streaming batches, no server paths).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Workflow\Exporter;
use Mailspur\Repository;

final class WorkflowExporterTest extends TestCase {

	/** @var Exporter */
	private $exporter;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter = new Exporter( new Repository() );
		Functions\stubs(
			array(
				'wp_date'            => static function ( $format, $timestamp = null ) {
					return gmdate( $format, $timestamp ?? time() );
				},
				'sanitize_file_name' => static function ( $name ) {
					return preg_replace( '/[^A-Za-z0-9._-]/', '', $name );
				},
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function row( int $id, string $extra = '' ): array {
		return array(
			'id'           => (string) $id,
			'created_at'   => '2026-09-30 10:00:0' . ( $id % 10 ),
			'status'       => '1',
			'recipients'   => 'anna@example.com',
			'subject'      => 'Order ' . $id . $extra,
			'attachments'  => '[{"name":"invoice.pdf","path":"/srv/www/wp-content/uploads/secret/invoice.pdf"}]',
			'content_type' => 'text/html',
			'sender'       => 'Shop <shop@example.com>',
			'source'       => 'plugin:woocommerce',
			'error'        => '',
			'notes'        => '2',
			'size'         => '1234',
			'headers'      => 'From: Shop <shop@example.com>',
			'message'      => '<p>Hi</p>',
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function export( string $format, bool $bodies = false, array $filters = array() ): string {
		$out = '';
		$this->exporter->stream(
			$filters,
			$format,
			$bodies,
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			}
		);
		return $out;
	}

	public function test_csv_cells_are_defused_against_formula_injection(): void {
		$this->assertSame( "\"'=HYPERLINK(\"\"http://x\"\")\"", Exporter::csv_cell( '=HYPERLINK("http://x")' ) );
		$this->assertSame( "\"'+1\"", Exporter::csv_cell( '+1' ) );
		$this->assertSame( "\"'-1\"", Exporter::csv_cell( '-1' ) );
		$this->assertSame( "\"'@SUM(A1)\"", Exporter::csv_cell( '@SUM(A1)' ) );
		$this->assertSame( "\"'\tcmd\"", Exporter::csv_cell( "\tcmd" ) );
		$this->assertSame( "\"'\rcmd\"", Exporter::csv_cell( "\rcmd" ) );
		$this->assertSame( '"a=b"', Exporter::csv_cell( 'a=b' ) );
		$this->assertSame( '""', Exporter::csv_cell( '' ) );
		$this->assertSame( '42', Exporter::csv_cell( 42 ) );
		$this->assertSame( "1,\"x\",\"\"\"q\"\"\"\r\n", Exporter::csv_line( array( 1, 'x', '"q"' ) ) );
	}

	public function test_csv_has_bom_header_and_no_server_paths(): void {
		$this->wpdb->results = array( array( $this->row( 1, ', "quoted"' ) ) );

		$csv = $this->export( 'csv' );

		$this->assertStringStartsWith( "\xEF\xBB\xBF\"id\",\"date\",\"date_utc\",\"status\",\"from\",\"to\",\"subject\"", $csv );
		$this->assertStringContainsString( '1,"2026-09-30 10:00:01","2026-09-30T10:00:01Z","sent","Shop <shop@example.com>","anna@example.com","Order 1, ""quoted""","plugin:woocommerce","text/html","invoice.pdf","",2,1234' . "\r\n", $csv );
		$this->assertStringNotContainsString( '/srv/www', $csv );
		$this->assertStringNotContainsString( '<p>Hi</p>', $csv, 'Bodies only on request.' );
	}

	public function test_bodies_are_opt_in(): void {
		$this->wpdb->results = array( array( $this->row( 1 ) ) );
		$csv                 = $this->export( 'csv', true );

		$this->assertStringContainsString( '"headers","message"', $csv );
		$this->assertStringContainsString( '"<p>Hi</p>"', $csv );
		$this->assertStringContainsString( 'headers, message FROM', $this->wpdb->prepared[0]['sql'] );
	}

	public function test_json_is_valid_and_streams_rows(): void {
		$this->wpdb->results = array( array( $this->row( 1 ), $this->row( 2 ) ) );

		$data = json_decode( $this->export( 'json' ), true );

		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
		$this->assertSame( array( 'invoice.pdf' ), $data[0]['attachments'] );
		$this->assertSame( 2, $data[1]['id'] );
		$this->assertSame( array( 'id', 'date', 'date_utc', 'status', 'from', 'to', 'subject', 'source', 'content_type', 'attachments', 'error', 'notes', 'size' ), array_keys( $data[0] ) );

		$this->wpdb->results = array( array() );
		$this->assertSame( array(), json_decode( $this->export( 'json' ), true ) );
	}

	public function test_rows_are_read_in_keyset_batches_with_the_list_filter(): void {
		$first = array();
		for ( $i = Exporter::BATCH; $i > 0; $i-- ) {
			$first[] = $this->row( $i + 1 );
		}
		$this->wpdb->results = array( $first, array( $this->row( 1 ) ) );

		$count = 0;
		foreach ( $this->exporter->rows( array( 'status' => 'failed' ), false ) as $row ) {
			++$count;
		}

		$this->assertSame( Exporter::BATCH + 1, $count );
		$this->assertCount( 2, $this->wpdb->prepared );
		$this->assertStringContainsString( 'WHERE 1=1 AND status = %d ORDER BY created_at DESC, id DESC LIMIT %d', $this->wpdb->prepared[0]['sql'] );
		$this->assertStringNotContainsString( 'OFFSET', $this->wpdb->prepared[0]['sql'] );

		$second = $this->wpdb->prepared[1];
		$this->assertStringContainsString( 'AND ( created_at < %s OR ( created_at = %s AND id < %d ) )', $second['sql'] );
		// Cursor = last row of the first batch (id 2).
		$this->assertSame( array( 'wp_mailspur', 2, '2026-09-30 10:00:02', '2026-09-30 10:00:02', 2, Exporter::BATCH ), $second['args'] );
	}

	public function test_limit_stops_early(): void {
		$this->wpdb->results = array( array( $this->row( 3 ), $this->row( 2 ) ) );
		$rows                = iterator_to_array( $this->exporter->rows( array(), false, 2 ), false );

		$this->assertCount( 2, $rows );
		$this->assertCount( 1, $this->wpdb->prepared );
		$this->assertSame( 2, end( $this->wpdb->prepared[0]['args'] ) );
	}

	public function test_filename_contains_the_date(): void {
		$this->assertMatchesRegularExpression( '/^mail-log-\d{4}-\d{2}-\d{2}\.json$/', Exporter::filename( 'json' ) );
		$this->assertStringEndsWith( '.csv', Exporter::filename( 'anything' ) );
	}
}
