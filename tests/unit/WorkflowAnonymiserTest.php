<?php
/**
 * Workflow module: anonymisation of old entries.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Workflow\Anonymiser;
use Mailspur\Repository;

final class WorkflowAnonymiserTest extends TestCase {

	/** @var Anonymiser */
	private $anonymiser;

	protected function setUp(): void {
		parent::setUp();
		$this->anonymiser = new Anonymiser( new Repository() );
		Functions\stubs(
			array(
				'sanitize_file_name' => static function ( $name ) {
					return $name;
				},
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function row( string $created_at = '2026-01-01 10:00:00' ): array {
		return array(
			'id'           => '7',
			'created_at'   => $created_at,
			'status'       => '2',
			'recipients'   => 'Anna Example <anna@example.com>, bob@example.org',
			'subject'      => 'Your order #123, Anna',
			'message'      => 'Hello Anna',
			'headers'      => 'From: Shop <shop@example.com>',
			'attachments'  => '[{"name":"a.pdf","path":"/x/a.pdf"},{"name":"b.pdf","path":"/x/b.pdf"}]',
			'content_type' => 'text/plain',
			'sender'       => 'Shop <shop@example.com>',
			'source'       => 'plugin:woocommerce',
			'error'        => 'SMTP Error: The following recipients failed: anna@example.com',
			'meta'         => '{"trace":{"file":"x.php"},"spam_score":3,"dkim":true,"note":"text"}',
			'notes'        => '2',
			'size'         => '999',
			'raw'          => 'MIME',
		);
	}

	public function test_masks_addresses(): void {
		$this->assertSame( 'a***@example.com', Anonymiser::mask_email( 'anna@example.com' ) );
		$this->assertSame( '***', Anonymiser::mask_email( '@example.com' ) );
		$this->assertSame( '***', Anonymiser::mask_email( 'no-address' ) );
		$this->assertSame( 'a***@example.com, b***@example.org', Anonymiser::mask_list( '"Anna" <Anna@Example.com>, bob@example.org' ) );
		$this->assertSame( '', Anonymiser::mask_list( ' ' ) );
		$this->assertSame( '***', Anonymiser::mask_list( 'undisclosed-recipients' ) );
		$this->assertSame( 'Could not reach a***@example.com (550)', Anonymiser::mask_text( 'Could not reach anna@example.com (550)' ) );
	}

	public function test_anonymise_removes_content_and_keeps_statistics(): void {
		$data = Anonymiser::anonymise( $this->row(), 30, false, 1700000000 );

		$this->assertSame( 'a***@example.com, b***@example.org', $data['recipients'] );
		$this->assertSame( 's***@example.com', $data['sender'] );
		foreach ( array( 'message', 'headers', 'attachments', 'raw' ) as $column ) {
			$this->assertSame( '', $data[ $column ], $column );
		}
		$this->assertSame( 'SMTP Error: The following recipients failed: a***@example.com', $data['error'] );
		$this->assertArrayNotHasKey( 'subject', $data, 'Subject kept unless configured.' );
		foreach ( array( 'created_at', 'status', 'source', 'notes', 'size', 'content_type' ) as $column ) {
			$this->assertArrayNotHasKey( $column, $data, $column . ' stays untouched' );
		}

		// Marker first (the list query reads the JSON prefix), then counts/codes only.
		$this->assertSame(
			array(
				'anonymised' => array(
					'at'          => 1700000000,
					'days'        => 30,
					'attachments' => 2,
				),
				'spam_score' => 3,
				'dkim'       => true,
			),
			$data['meta']
		);
		$this->assertStringStartsWith( Anonymiser::MARK, (string) json_encode( $data['meta'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_subject_is_cleared_on_request(): void {
		$data = Anonymiser::anonymise( $this->row(), 30, true, 1 );
		$this->assertSame( '', $data['subject'] );
	}

	public function test_detects_anonymised_rows(): void {
		$this->assertTrue( Anonymiser::is_anonymised( array( 'anonymised' => '1' ) ) );
		$this->assertFalse( Anonymiser::is_anonymised( array( 'anonymised' => '0' ) ) );
		$this->assertTrue( Anonymiser::is_anonymised( array( 'meta' => '{"anonymised":{"at":1,"days":3}}' ) ) );
		$this->assertFalse( Anonymiser::is_anonymised( array( 'meta' => '{"trace":{"anonymised":1}}' ) ) );

		$item = $this->anonymiser->summary( array( 'id' => 7 ), array( 'anonymised' => '1' ) );
		$this->assertTrue( $item['anonymised'] );
	}

	public function test_settings_are_sanitized_and_switching_on_restarts(): void {
		$this->settings = array( 'anonymise_days' => 0 );
		Functions\expect( 'delete_option' )->once()->with( Anonymiser::CURSOR_OPTION );

		$clean = $this->anonymiser->sanitize(
			array(),
			array(
				'anonymise_days'    => '99999',
				'anonymise_subject' => '1',
			)
		);
		$this->assertSame( 3650, $clean['anonymise_days'] );
		$this->assertTrue( $clean['anonymise_subject'] );

		$this->settings = array( 'anonymise_days' => 30 );
		$clean          = $this->anonymiser->sanitize( array(), array( 'anonymise_days' => '-3' ) );
		$this->assertSame( 3, $clean['anonymise_days'] );
		$this->assertFalse( $clean['anonymise_subject'] );

		$this->assertSame( 0, $this->anonymiser->defaults( array() )['anonymise_days'] );
	}

	public function test_off_by_default(): void {
		$this->assertSame( '', Anonymiser::cutoff() );
		$this->assertSame( 0, $this->anonymiser->run() );
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_imported_old_rows_are_anonymised_on_import_only(): void {
		$this->settings = array( 'anonymise_days' => 30 );

		$data = array( 'meta' => array( 'import' => 'wp-mail-logging' ) );
		$old  = $this->anonymiser->finalize_row( $data, $this->row( '2001-01-01 00:00:00' ) );
		$this->assertSame( '', $old['message'] );
		$this->assertArrayHasKey( 'anonymised', $old['meta'] );

		$fresh = $this->anonymiser->finalize_row( $data, $this->row( gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertSame( $data, $fresh );

		$this->settings = array( 'anonymise_days' => 0 );
		$this->assertSame( $data, $this->anonymiser->finalize_row( $data, $this->row( '2001-01-01 00:00:00' ) ) );
	}

	public function test_daily_run_walks_the_index_in_batches_and_saves_the_cursor(): void {
		$this->settings = array(
			'anonymise_days'    => 30,
			'anonymise_subject' => true,
		);
		$done           = $this->row( '2026-01-01 09:00:00' );
		$done['id']     = '6';
		$done['meta']   = '{"anonymised":{"at":1,"days":30}}';

		$this->wpdb->results = array( array( $done, $this->row() ) );
		Functions\expect( 'update_option' )->once()->with( Anonymiser::CURSOR_OPTION, array( '2026-01-01 10:00:00', 7 ), false );
		Functions\expect( 'delete_transient' )->once();

		$this->assertSame( 1, $this->anonymiser->run() );

		$query = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'WHERE created_at < %s AND ( created_at > %s OR ( created_at = %s AND id > %d ) ) ORDER BY created_at ASC, id ASC LIMIT %d', $query['sql'] );
		$this->assertSame( array( '1000-01-01 00:00:00', '1000-01-01 00:00:00', 0, Anonymiser::BATCH ), array_slice( $query['args'], 2 ) );

		$updates = $this->writes( 'update' );
		$this->assertCount( 1, $updates, 'Already anonymised rows are skipped.' );
		$this->assertSame( array( 'id' => 7 ), $updates[0][3] );
		$this->assertSame( '', $updates[0][2]['subject'] );
		$this->assertStringStartsWith( Anonymiser::MARK, $updates[0][2]['meta'] );
	}
}
