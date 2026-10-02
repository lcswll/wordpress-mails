<?php
/**
 * Workflow module: logic of the WP-CLI commands (without WP_CLI).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use Mailspur\Logger;
use Mailspur\Modules\Workflow\Commands;
use Mailspur\Repository;
use RuntimeException;

final class WorkflowCommandsTest extends TestCase {

	/** @var Commands */
	private $commands;

	protected function setUp(): void {
		parent::setUp();
		$this->commands = new Commands( new Repository() );
		Functions\stubs(
			array(
				'wp_date'            => static function ( $format, $timestamp = null ) {
					return gmdate( $format, $timestamp ?? time() );
				},
				'sanitize_file_name' => static function ( $name ) {
					return $name;
				},
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function row(): array {
		return array(
			'id'          => '5',
			'created_at'  => '2026-09-01 08:00:00',
			'status'      => '2',
			'recipients'  => 'anna@example.com',
			'subject'     => 'Hi',
			'message'     => 'Body',
			'headers'     => "From: a@b.c\nX-Test: 1",
			'attachments' => '[{"name":"gone.pdf","path":"/does/not/exist.pdf"}]',
			'source'      => 'core',
			'error'       => 'boom',
			'meta'        => '',
			'notes'       => '0',
			'size'        => '4',
		);
	}

	public function test_cli_options_become_list_filters(): void {
		$filters = Commands::filters(
			array(
				'status'      => 'failed',
				'search'      => 'anna',
				'since'       => '2026-01-01',
				'until'       => '2026-01-31',
				'source'      => 'plugin:woocommerce',
				'content'     => 'text',
				'attachments' => true,
			)
		);
		$this->assertSame( 'failed', $filters['status'] );
		$this->assertSame( '2026-01-01', $filters['after'] );
		$this->assertSame( '2026-01-31', $filters['before'] );
		$this->assertSame( 'plugin:woocommerce', $filters['source'] );
		$this->assertSame( 'text', $filters['format'] );
		$this->assertTrue( $filters['attachments'] );
		$this->assertFalse( $filters['notes'] );

		$this->assertSame( gmdate( 'Y-m-d', strtotime( '-3 days' ) ), Commands::date( '3 days ago' ) );
	}

	public function test_invalid_options_are_rejected(): void {
		foreach ( array( array( 'status' => 'gone' ), array( 'content' => 'pdf' ), array( 'since' => 'not a date at all' ) ) as $assoc ) {
			try {
				Commands::filters( $assoc );
				$this->fail( 'Expected an exception for ' . json_encode( $assoc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_purge_before_is_exclusive_and_deletes_in_batches(): void {
		$filters = Commands::purge_filters(
			array(
				'before' => '2026-03-01',
				'status' => 'sent',
			)
		);
		$this->assertSame( '2026-02-28', $filters['before'] );
		$this->assertSame( 'sent', $filters['status'] );

		Functions\expect( 'delete_transient' )->once();
		$this->wpdb->results = array( Commands::PURGE_BATCH, 3 );

		$this->assertSame( Commands::PURGE_BATCH + 3, $this->commands->purge( $filters ) );
		$this->assertCount( 2, $this->wpdb->prepared );
		$this->assertSame( 'DELETE FROM %i WHERE 1=1 AND created_at <= %s AND status = %d LIMIT %d', $this->wpdb->prepared[0]['sql'] );
		$this->assertSame( array( 'wp_mailspur', '2026-02-28 23:59:59', 1, Commands::PURGE_BATCH ), $this->wpdb->prepared[0]['args'] );
	}

	public function test_count_and_list_use_the_filter(): void {
		$this->wpdb->results = array( '12' );
		$this->assertSame( 12, $this->commands->count( Commands::filters( array( 'status' => 'held' ) ) ) );
		$this->assertSame( 'SELECT COUNT(*) FROM %i WHERE 1=1 AND status = %d', $this->wpdb->prepared[0]['sql'] );

		$this->wpdb->results = array( array( $this->row() ) );
		$items               = $this->commands->items( Commands::filters( array() ), 10 );
		$this->assertSame(
			array(
				array(
					'id'      => 5,
					'date'    => '2026-09-01 08:00:00',
					'status'  => 'failed',
					'to'      => 'anna@example.com',
					'subject' => 'Hi',
					'source'  => 'core',
				),
			),
			$items
		);
	}

	public function test_show(): void {
		$this->wpdb->results = array( $this->row() );
		$entry               = $this->commands->show( 5, false );
		$this->assertSame( 'gone.pdf', $entry['attachments'] );
		$this->assertSame( 'no', $entry['anonymised'] );
		$this->assertArrayNotHasKey( 'message', $entry );

		$this->wpdb->results = array( $this->row() );
		$this->assertSame( 'Body', $this->commands->show( 5, true )['message'] );

		$this->expectException( RuntimeException::class );
		$this->commands->show( 99, false );
	}

	public function test_resend_to_other_recipients(): void {
		$this->wpdb->results = array( $this->row() );
		Functions\expect( 'wp_mail' )->once()->andReturnUsing(
			function ( $to, $subject, $message, $headers, $files ) {
				$this->assertSame( 'me@example.com, you@example.com', $to );
				$this->assertSame( array( 'From: a@b.c', 'X-Test: 1' ), $headers );
				$this->assertSame( array(), $files );
				$this->assertSame( 'mailspur:resend', Logger::$source_override );
				return true;
			}
		);

		$result = $this->commands->resend( 5, 'me@example.com, you@example.com' );

		$this->assertTrue( $result['sent'] );
		$this->assertSame( array( 'gone.pdf' ), $result['missing'] );
		$this->assertSame( '', Logger::$source_override );
	}

	public function test_resend_rejects_bad_addresses_and_anonymised_entries(): void {
		$this->wpdb->results = array( $this->row() );
		try {
			$this->commands->resend( 5, 'me@example.com, nope' );
			$this->fail( 'Invalid address accepted.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'nope', $e->getMessage() );
		}

		$row                 = $this->row();
		$row['meta']         = '{"anonymised":{"at":1,"days":30}}';
		$this->wpdb->results = array( $row );
		$this->expectException( RuntimeException::class );
		$this->commands->resend( 5 );
	}

	public function test_stats(): void {
		$this->wpdb->results = array(
			array(
				array(
					'status' => '1',
					'n'      => '9',
					'bytes'  => '900',
				),
				array(
					'status' => '2',
					'n'      => '1',
					'bytes'  => '100',
				),
			),
			array(
				array(
					'source' => 'core',
					'n'      => '10',
					'failed' => '1',
				),
			),
		);
		$stats               = $this->commands->stats( 7 );

		$this->assertSame( 10, $stats['total'] );
		$this->assertSame( 1000, $stats['bytes'] );
		$this->assertSame( 9, $stats['statuses']['sent'] );
		$this->assertSame( 0, $stats['statuses']['held'] );
		$this->assertSame( 10.0, $stats['failure_rate'] );
		$this->assertSame(
			array(
				array(
					'source' => 'core',
					'count'  => 10,
					'failed' => 1,
				),
			),
			$stats['sources']
		);

		$this->expectException( InvalidArgumentException::class );
		$this->commands->stats( 0 );
	}

	public function test_import_rejects_unknown_sources(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->commands->import( 'nope' );
	}
}
