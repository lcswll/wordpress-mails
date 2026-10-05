<?php
/**
 * Logger: what gets stored and how delivery results are matched to rows.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mailspur\Logger;
use Mailspur\Repository;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

final class LoggerTest extends TestCase {

	/** @var Logger */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = array( 'redact_secrets' => true );
		$this->logger   = new Logger( new Repository() );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function atts( array $overrides = array() ): array {
		return array_merge(
			array(
				'to'          => 'anna@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			$overrides
		);
	}

	public function test_capture_returns_arguments_unchanged_and_inserts_pending_row(): void {
		$atts = $this->atts(
			array(
				'to'          => 'anna@example.com, Bob <bob@example.org>',
				'headers'     => "From: Shop <shop@example.com>\r\nContent-Type: text/html; charset=UTF-8",
				'attachments' => array(
					'/tmp/invoice-123.pdf',
					'Custom name.pdf' => '/tmp/x.pdf',
				),
			)
		);

		$this->assertSame( $atts, $this->logger->capture( $atts ) );

		$inserts = $this->writes( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( 'wp_mailspur', $inserts[0][1] );

		$row = $inserts[0][2];
		$this->assertSame( Repository::STATUS_PENDING, $row['status'] );
		$this->assertSame( 'anna@example.com, Bob <bob@example.org>', $row['recipients'] );
		$this->assertSame( "From: Shop <shop@example.com>\nContent-Type: text/html; charset=UTF-8", $row['headers'] );
		$this->assertSame( 'text/html', $row['content_type'] );
		$this->assertSame( 'Shop <shop@example.com>', $row['sender'], 'Sender from the From header (API mailers never reach PHPMailer).' );
		$this->assertSame( '2026-10-01 12:00:00', $row['created_at'] );
		$this->assertSame(
			array(
				array(
					'name' => 'invoice-123.pdf',
					'path' => '/tmp/invoice-123.pdf',
				),
				array(
					'name' => 'Custom name.pdf',
					'path' => '/tmp/x.pdf',
				),
			),
			json_decode( $row['attachments'], true )
		);
	}

	public function test_non_array_arguments_are_ignored(): void {
		$this->assertSame( 'nope', $this->logger->capture( 'nope' ) );
		$this->assertSame( array(), $this->wpdb->writes );
	}

	public function test_secrets_in_links_are_redacted(): void {
		$this->logger->capture(
			$this->atts(
				array(
					'message' => 'Reset: https://x.test/wp-login.php?action=rp&key=AbC123&login=anna <a href="https://x.test/?order=1&amp;key=wc_order_9">',
				)
			)
		);

		$message = $this->writes( 'insert' )[0][2]['message'];
		$this->assertStringNotContainsString( 'AbC123', $message );
		$this->assertStringNotContainsString( 'wc_order_9', $message );
		$this->assertStringContainsString( 'key=[redacted]&login=anna', $message );
		$this->assertStringContainsString( '&amp;key=[redacted]"', $message );
	}

	public function test_plain_text_secrets_are_masked_but_modules_see_the_mail_as_sent(): void {
		$seen = '';
		Filters\expectApplied( 'mailspur_meta' )->andReturnUsing(
			static function ( $meta, $phase, $context ) use ( &$seen ) {
				if ( 'capture' === $phase ) {
					$seen = $context['message'];
				}
				return $meta;
			}
		);
		$this->logger->capture( $this->atts( array( 'message' => "Username: anna\nPassword: Xy7!kq99\nCard: 4111 1111 1111 1111" ) ) );

		$this->assertSame( "Username: anna\nPassword: [redacted]\nCard: [redacted]", $this->writes( 'insert' )[0][2]['message'] );
		$this->assertStringContainsString( 'Xy7!kq99', $seen );
	}

	public function test_redaction_can_be_disabled(): void {
		$this->settings = array( 'redact_secrets' => false );
		$this->logger->capture( $this->atts( array( 'message' => '?key=AbC123' ) ) );

		$this->assertSame( '?key=AbC123', $this->writes( 'insert' )[0][2]['message'] );
	}

	public function test_success_updates_status_with_final_phpmailer_data_in_one_query(): void {
		$this->logger->capture( $this->atts() );

		$mailer              = new PHPMailer();
		$mailer->ContentType = 'text/html'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->From        = 'shop@example.com'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->FromName    = 'Shop'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Body        = '<p>Wrapped by a template plugin</p>'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->logger->enrich( $mailer );
		$this->logger->succeeded();

		$updates = $this->writes( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame(
			array(
				'content_type' => 'text/html',
				'sender'       => 'Shop <shop@example.com>',
				'message'      => '<p>Wrapped by a template plugin</p>',
				'status'       => Repository::STATUS_SENT,
				'meta'         => '{"plain_text":false}',
				'size'         => 35,
			),
			$updates[0][2]
		);
		$this->assertSame( array( 'id' => 1 ), $updates[0][3] );
	}

	public function test_records_whether_an_html_mail_has_a_plain_text_alternative_without_storing_it(): void {
		$this->logger->capture( $this->atts() );
		$mailer              = new PHPMailer();
		$mailer->ContentType = 'text/html'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Body        = 'Body'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->AltBody     = 'Secret plain text'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->logger->enrich( $mailer );
		$this->logger->succeeded();

		$update = $this->writes( 'update' )[0][2];
		$this->assertSame( '{"plain_text":true}', $update['meta'] );
		$this->assertStringNotContainsString( 'Secret plain text', (string) wp_json_encode( $update ) );

		// Plain-text mails do not get the flag.
		$this->logger->capture( $this->atts() );
		$this->logger->enrich( new PHPMailer() );
		$this->logger->succeeded();
		$this->assertSame( '', $this->writes( 'update' )[1][2]['meta'] );
	}

	public function test_modules_collect_meta_in_three_phases_and_set_columns(): void {
		$phases = array();
		Filters\expectApplied( 'mailspur_meta' )->times( 3 )->andReturnUsing(
			static function ( $meta, $phase ) use ( &$phases ) {
				$phases[]       = $phase;
				$meta[ $phase ] = true;
				return $meta;
			}
		);
		Filters\expectApplied( 'mailspur_finalize_row' )->once()->andReturnUsing(
			static function ( $data, $row ) {
				$data['notes'] = 'Hello' === $row['subject'] ? 2 : 0;
				return $data;
			}
		);
		Actions\expectDone( 'mailspur_logged' )->once();

		$this->logger->capture( $this->atts() );
		$this->logger->enrich( new PHPMailer() );
		$this->logger->succeeded();

		$this->assertSame( array( 'capture', 'phpmailer', 'result' ), $phases );
		$this->assertSame( '{"capture":true}', $this->writes( 'insert' )[0][2]['meta'] );
		$update = $this->writes( 'update' )[0][2];
		$this->assertSame( '{"capture":true,"phpmailer":true,"result":true}', $update['meta'] );
		$this->assertSame( 2, $update['notes'] );
	}

	public function test_a_failing_module_does_not_lose_the_status(): void {
		Filters\expectApplied( 'mailspur_finalize_row' )->andReturnUsing(
			static function () {
				throw new \RuntimeException( 'broken module' );
			}
		);

		$this->logger->capture( $this->atts() );
		$this->logger->failed( new WP_Error( 'x', 'SMTP down' ) );

		$update = $this->writes( 'update' )[0][2];
		$this->assertSame( Repository::STATUS_FAILED, $update['status'] );
		$this->assertSame( 'SMTP down', $update['error'] );
	}

	public function test_unchanged_body_is_not_written_again(): void {
		$this->logger->capture( $this->atts() );
		$mailer       = new PHPMailer();
		$mailer->Body = 'Body'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->logger->enrich( $mailer );
		$this->logger->succeeded();

		$this->assertArrayNotHasKey( 'message', $this->writes( 'update' )[0][2] );
	}

	public function test_failure_stores_error_message(): void {
		$this->logger->capture( $this->atts() );
		$this->logger->failed( new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );

		$update = $this->writes( 'update' )[0][2];
		$this->assertSame( Repository::STATUS_FAILED, $update['status'] );
		$this->assertSame( 'SMTP connect() failed.', $update['error'] );
	}

	public function test_pre_wp_mail_short_circuit_resolves_the_row(): void {
		$this->logger->capture( $this->atts() );
		$this->assertTrue( $this->logger->short_circuit( true ) );
		$this->assertSame( Repository::STATUS_SENT, $this->writes( 'update' )[0][2]['status'] );

		$this->logger->capture( $this->atts() );
		$this->assertFalse( $this->logger->short_circuit( false ) );
		$this->assertSame( Repository::STATUS_FAILED, $this->writes( 'update' )[1][2]['status'] );
	}

	public function test_null_from_pre_wp_mail_means_normal_delivery_continues(): void {
		$this->logger->capture( $this->atts() );
		$this->assertNull( $this->logger->short_circuit( null ) );
		$this->assertSame( array(), $this->writes( 'update' ) );
	}

	public function test_nested_mails_resolve_their_own_rows(): void {
		$this->logger->capture( $this->atts( array( 'subject' => 'outer' ) ) ); // Row 1.
		$this->logger->capture( $this->atts( array( 'subject' => 'inner' ) ) ); // Row 2, sent while 1 is in flight.
		$this->logger->failed( new WP_Error( 'x', 'inner failed' ) );
		$this->logger->succeeded();

		$updates = $this->writes( 'update' );
		$this->assertSame( array( 'id' => 2 ), $updates[0][3] );
		$this->assertSame( Repository::STATUS_FAILED, $updates[0][2]['status'] );
		$this->assertSame( array( 'id' => 1 ), $updates[1][3] );
		$this->assertSame( Repository::STATUS_SENT, $updates[1][2]['status'] );
	}

	public function test_skipped_mail_keeps_result_hooks_aligned(): void {
		Filters\expectApplied( 'mailspur_should_log' )->twice()->andReturn( true, false );

		$this->logger->capture( $this->atts() ); // Logged as row 1.
		$this->logger->capture( $this->atts() ); // Skipped, but occupies a stack slot.
		$this->logger->succeeded();              // Result of the skipped mail: no write.
		$this->logger->succeeded();              // Result of row 1.

		$updates = $this->writes( 'update' );
		$this->assertCount( 1, $this->writes( 'insert' ) );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'id' => 1 ), $updates[0][3] );
	}

	public function test_source_identifies_the_calling_plugin(): void {
		$this->assertSame( 'core', $this->writes_source() );

		Logger::$source_override = 'mailspur:resend';
		$this->assertSame( 'mailspur:resend', $this->writes_source() );
		Logger::$source_override = '';
	}

	private function writes_source(): string {
		$this->wpdb->writes = array();
		$this->logger->capture( $this->atts() );
		return $this->writes( 'insert' )[0][2]['source'];
	}
}
