<?php
/**
 * Import sources: raw rows exactly as the other plugins write them → Mailspur rows.
 *
 * The site time zone is Europe/Berlin (UTC+2 in summer), so every local timestamp must come out 2 hours earlier.
 *
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.WP.AlternativeFunctions.json_encode_json_encode
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Import\Sources;
use Mailspur\Repository;

final class ImportSourcesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_gmt_from_date' )->alias(
			static function ( $local ) {
				$date = new \DateTime( $local, new \DateTimeZone( 'Europe/Berlin' ) );
				return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'is_serialized' )->alias(
			static function ( $data ) {
				return is_string( $data ) && (bool) preg_match( '/^(a|O|s|i|b|d|N):/', trim( $data ) );
			}
		);
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/srv/site/wp-content/uploads' ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * @param string $json
	 * @return array<int,array{name:string,path:string}>
	 */
	private static function files( $json ): array {
		return '' === $json ? array() : json_decode( $json, true );
	}

	public function test_wp_mail_logging_literal_separators_local_time_and_relative_attachments(): void {
		$row = ( new Sources\WpMailLogging() )->map(
			array(
				'mail_id'     => '7',
				'timestamp'   => '2026-07-30 14:05:00',
				'receiver'    => 'anna@example.com,\n Bob <bob@example.org>',
				'subject'     => 'Your order',
				'message'     => '<p>Hi</p>',
				'headers'     => 'From: Shop <shop@example.com>,\nContent-Type: text/html; charset=UTF-8',
				'attachments' => '/2026/07/invoice.pdf,\n/2026/07/terms.pdf',
				'error'       => '',
			)
		);

		$this->assertSame( '2026-07-30 12:05:00', $row['created_at'] );
		$this->assertSame( Repository::STATUS_SENT, $row['status'] );
		$this->assertSame( 'anna@example.com, Bob <bob@example.org>', $row['recipients'] );
		$this->assertSame( "From: Shop <shop@example.com>\nContent-Type: text/html; charset=UTF-8", $row['headers'] );
		$this->assertSame( 'text/html', $row['content_type'] );
		$this->assertSame( 'Shop <shop@example.com>', $row['sender'] );
		$this->assertSame( 'import:wp-mail-logging', $row['source'] );
		$this->assertSame(
			array(
				array(
					'name' => 'invoice.pdf',
					'path' => '/srv/site/wp-content/uploads/2026/07/invoice.pdf',
				),
				array(
					'name' => 'terms.pdf',
					'path' => '/srv/site/wp-content/uploads/2026/07/terms.pdf',
				),
			),
			self::files( $row['attachments'] )
		);
	}

	public function test_wp_mail_logging_serialized_error_means_failed(): void {
		$row = ( new Sources\WpMailLogging() )->map(
			array(
				'mail_id'     => '8',
				'timestamp'   => '2026-01-10 08:00:00', // Winter: UTC+1.
				'receiver'    => 'a@example.com',
				'subject'     => 'x',
				'message'     => 'x',
				'headers'     => '',
				'attachments' => '0',
				'error'       => serialize( array( 'SMTP Error: Could not connect to SMTP host.' ) ),
			)
		);

		$this->assertSame( '2026-01-10 07:00:00', $row['created_at'] );
		$this->assertSame( Repository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'SMTP Error: Could not connect to SMTP host.', $row['error'] );
		$this->assertSame( '', $row['attachments'] );
	}

	public function test_email_log_result_column(): void {
		$source = new Sources\EmailLog();
		$base   = array(
			'id'              => '1',
			'to_email'        => 'a@example.com,b@example.com',
			'subject'         => 'Hi',
			'message'         => 'Body',
			'headers'         => "From: WP <wp@example.com>\r\nCc: c@example.com",
			'attachments'     => 'false',
			'sent_date'       => '2026-07-30 10:00:00',
			'attachment_name' => '',
			'ip_address'      => '',
			'result'          => '1',
			'error_message'   => null,
		);

		$sent = $source->map( $base );
		$this->assertSame( Repository::STATUS_SENT, $sent['status'] );
		$this->assertSame( 'a@example.com, b@example.com', $sent['recipients'] );
		$this->assertSame( "From: WP <wp@example.com>\nCc: c@example.com", $sent['headers'] );
		$this->assertSame( '2026-07-30 08:00:00', $sent['created_at'] );

		$failed = $source->map(
			array_merge(
				$base,
				array(
					'result'        => '0',
					'error_message' => 'Could not instantiate mail function.',
				)
			)
		);
		$this->assertSame( Repository::STATUS_FAILED, $failed['status'] );
		$this->assertSame( 'Could not instantiate mail function.', $failed['error'] );

		$legacy = $source->map( array_merge( $base, array( 'result' => null ) ) );
		$this->assertSame( Repository::STATUS_PENDING, $legacy['status'] );
	}

	public function test_check_email_decodes_escaped_subject_and_keeps_attachment_paths(): void {
		$row = ( new Sources\CheckEmail() )->map(
			array(
				'id'              => '3',
				'to_email'        => 'a@example.com',
				'subject'         => 'Tom &amp; Jerry&#039;s &lt;newsletter&gt;',
				'message'         => '<p>x</p>',
				'headers'         => '',
				'attachments'     => 'true',
				'sent_date'       => '2026-07-30 10:00:00',
				'attachment_name' => '/srv/site/wp-content/uploads/a.pdf,/tmp/b.csv',
				'result'          => '1',
				'error_message'   => '',
			)
		);

		$this->assertSame( "Tom & Jerry's <newsletter>", $row['subject'] );
		$this->assertSame( array( 'a.pdf', 'b.csv' ), array_column( self::files( $row['attachments'] ), 'name' ) );
		$this->assertSame( '/tmp/b.csv', self::files( $row['attachments'] )[1]['path'] );
		$this->assertSame( 'import:check-email', $row['source'] );
	}

	public function test_fluent_smtp_serialized_columns(): void {
		$row = ( new Sources\FluentSmtp() )->map(
			array(
				'id'          => '11',
				'to'          => serialize(
					array(
						array(
							'email' => 'anna@example.com',
							'name'  => 'Anna',
						),
						array( 'email' => 'b@example.com' ),
					)
				),
				'from'        => 'Shop <shop@example.com>',
				'subject'     => 'Invoice',
				'body'        => '<p>Invoice</p>',
				'headers'     => serialize(
					array(
						'reply-to'     => array( array( 'email' => 'reply@example.com' ) ),
						'cc'           => array(),
						'bcc'          => array( array( 'email' => 'archive@example.com' ) ),
						'content-type' => 'text/html',
					)
				),
				'attachments' => serialize( array( array( '/srv/site/wp-content/uploads/inv-11.pdf', 'inv-11.pdf', 'Invoice.pdf', 'base64', 'application/pdf', false, 'attachment', '0' ) ) ),
				'status'      => 'failed',
				'response'    => serialize(
					array(
						'code'    => 400,
						'message' => 'Invalid API key',
					)
				),
				'created_at'  => '2026-07-30 10:00:00',
			)
		);

		$this->assertSame( 'Anna <anna@example.com>, b@example.com', $row['recipients'] );
		$this->assertSame( "From: Shop <shop@example.com>\nReply-To: reply@example.com\nBcc: archive@example.com\nContent-Type: text/html", $row['headers'] );
		$this->assertSame( 'text/html', $row['content_type'] );
		$this->assertSame( Repository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Invalid API key', $row['error'] );
		$this->assertSame(
			array(
				array(
					'name' => 'Invoice.pdf',
					'path' => '/srv/site/wp-content/uploads/inv-11.pdf',
				),
			),
			self::files( $row['attachments'] )
		);
	}

	public function test_fluent_smtp_truncated_recipients_and_no_object_injection(): void {
		$row = ( new Sources\FluentSmtp() )->map(
			array(
				'id'          => '12',
				// Old installs cut the serialized list at VARCHAR(255).
				'to'          => 'a:2:{i:0;a:2:{s:5:"email";s:16:"anna@example.com";s:4:"na',
				'from'        => '',
				'subject'     => 'x',
				'body'        => 'x',
				// A crafted object must never be instantiated.
				'headers'     => 'O:8:"stdClass":1:{s:2:"cc";a:0:{}}',
				'attachments' => 'O:11:"ArrayObject":0:{}',
				'status'      => 'sent',
				'response'    => '',
				'created_at'  => '2026-07-30 10:00:00',
			)
		);

		$this->assertSame( 'anna@example.com', $row['recipients'] );
		$this->assertSame( '', $row['headers'] );
		$this->assertSame( '', $row['attachments'] );
		$this->assertSame( Repository::STATUS_SENT, $row['status'] );
	}

	public function test_post_smtp_local_epoch_and_success_values(): void {
		$source = new Sources\PostSmtp();
		$local  = ( new \DateTime( '2026-07-30 14:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp(); // Local epoch, as written by Post SMTP.
		$base   = array(
			'id'                 => '5',
			'time'               => (string) $local,
			'success'            => '1',
			'from_header'        => 'Shop <shop@example.com>',
			'to_header'          => 'anna@example.com, bob@example.org',
			'cc_header'          => 'cc@example.com',
			'bcc_header'         => '',
			'original_subject'   => 'Hello',
			'original_message'   => 'Body',
			'original_headers'   => serialize( array( 'Content-Type: text/plain; charset=UTF-8' ) ),
			'session_transcript' => '',
		);

		$sent = $source->map( $base );
		$this->assertSame( '2026-07-30 12:00:00', $sent['created_at'] );
		$this->assertSame( Repository::STATUS_SENT, $sent['status'] );
		$this->assertSame( "Content-Type: text/plain; charset=UTF-8\nFrom: Shop <shop@example.com>\nCc: cc@example.com", $sent['headers'] );
		$this->assertSame( 'text/plain', $sent['content_type'] );

		$this->assertSame( Repository::STATUS_SENT, $source->map( array_merge( $base, array( 'success' => 'Sent ( ** Fallback ** )' ) ) )['status'] );
		$this->assertSame( Repository::STATUS_SENT, $source->map( array_merge( $base, array( 'success' => 'Warning: An empty subject line can result in delivery failure.' ) ) )['status'] );
		$this->assertSame( Repository::STATUS_PENDING, $source->map( array_merge( $base, array( 'success' => 'In Queue' ) ) )['status'] );

		$failed = $source->map( array_merge( $base, array( 'success' => 'SMTP connect() failed.' ) ) );
		$this->assertSame( Repository::STATUS_FAILED, $failed['status'] );
		$this->assertSame( 'SMTP connect() failed.', $failed['error'] );
	}

	public function test_suremails_status_and_last_attempt_error(): void {
		$source = new Sources\SureMails();
		$base   = array(
			'id'          => '9',
			'email_from'  => 'Shop <shop@example.com>',
			'email_to'    => 'Anna <anna@example.com>, b@example.com',
			'subject'     => 'Hi',
			'body'        => '<b>Hi</b>',
			'headers'     => serialize( array( 'From: Shop <shop@example.com>', 'Content-Type: text/html; charset=UTF-8; boundary="x"' ) ),
			'attachments' => serialize( array( '/srv/site/wp-content/uploads/suremails/attachments/abc-def-file.pdf' ) ),
			'status'      => 'failed',
			'response'    => serialize(
				array(
					array(
						'retry'   => 0,
						'Message' => 'Timeout',
					),
					array(
						'retry'   => 1,
						'Message' => 'Connection refused',
					),
				)
			),
			'created_at'  => '2026-07-30 10:00:00',
		);

		$row = $source->map( $base );
		$this->assertSame( Repository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Connection refused', $row['error'] );
		$this->assertSame( 'text/html', $row['content_type'] );
		$this->assertSame( array( 'abc-def-file.pdf' ), array_column( self::files( $row['attachments'] ), 'name' ) );

		$blocked = $source->map(
			array_merge(
				$base,
				array(
					'status'   => 'blocked',
					'response' => '',
				)
			)
		);
		$this->assertSame( Repository::STATUS_FAILED, $blocked['status'] );
		$this->assertSame( 'Blocked by SureMail Content Guard', $blocked['error'] );
	}

	public function test_wp_mail_catcher_utc_epoch_json_columns(): void {
		$row = ( new Sources\WpMailCatcher() )->map(
			array(
				'id'                 => '4',
				'time'               => '1785412800', // 2026-07-30 12:00:00 UTC.
				'email_to'           => 'anna@example.com, bob@example.org',
				'subject'            => 'x',
				'message'            => 'x',
				'status'             => '0',
				'error'              => 'Invalid address',
				'attachments'        => json_encode(
					array(
						array(
							'id'  => 5,
							'url' => 'https://shop.example/wp-content/uploads/2026/07/a.pdf',
						),
						array( 'id' => -1 ),
					)
				),
				'additional_headers' => json_encode( array( 'From: A <a@example.com>' ) ),
				'is_html'            => '1',
			)
		);

		$this->assertSame( '2026-07-30 12:00:00', $row['created_at'] );
		$this->assertSame( Repository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Invalid address', $row['error'] );
		$this->assertSame( 'text/html', $row['content_type'] );
		$this->assertSame( 'A <a@example.com>', $row['sender'] );
		$this->assertSame( array( 'a.pdf' ), array_column( self::files( $row['attachments'] ), 'name' ) );
	}

	public function test_wp_mail_log_prefers_gmt_column_and_has_no_status(): void {
		$row = ( new Sources\WpMailLog() )->map(
			array(
				'id'               => '2',
				'to_email'         => 'a@example.com, b@example.com',
				'subject'          => 'x',
				'message'          => 'x',
				'headers'          => 'Content-Type: text/html',
				'attachments'      => 'true',
				'sent_date'        => '2026-07-30 14:00:00',
				'captured_gmt'     => '2026-07-30 12:00:00',
				'attachments_file' => '/2026/07/a.pdf',
			)
		);

		$this->assertSame( '2026-07-30 12:00:00', $row['created_at'] );
		$this->assertSame( Repository::STATUS_PENDING, $row['status'] );
		$this->assertSame( '/srv/site/wp-content/uploads/2026/07/a.pdf', self::files( $row['attachments'] )[0]['path'] );
	}
}
