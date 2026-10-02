<?php
/**
 * Trace module: the mailspur_meta phases, transcript lifecycle, raw source and settings.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Modules\Trace\Collector;
use Mailspur\Modules\Trace\Eml;
use Mailspur\Modules\Trace\Module;
use Mailspur\Repository;

final class TraceCollectorTest extends TestCase {

	/** @var Collector */
	private $collector;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'wp_doing_cron'              => false,
				'wp_is_serving_rest_request' => false,
				'wp_doing_ajax'              => false,
				'is_admin'                   => false,
				'wp_check_invalid_utf8'      => static function ( $value ) {
					return $value;
				},
			)
		);
		$this->collector = new Collector();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function capture(): array {
		$GLOBALS['wp_current_filter'] = array( 'woocommerce_order_status_completed', 'wp_mail', 'mailspur_meta' );
		$meta                         = $this->collector->meta( array(), 'capture', array() );
		unset( $GLOBALS['wp_current_filter'] );
		return $meta;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	private function result( array $meta, ?int $status, string $error = '' ): array {
		return $this->collector->meta(
			$meta,
			'result',
			array(
				'status' => $status,
				'error'  => $error,
			)
		);
	}

	public function test_full_smtp_lifecycle_with_transcript_for_failed_mail(): void {
		$mailer = new TraceMailer();
		$meta   = $this->capture();

		$this->assertSame( array( 'woocommerce_order_status_completed' ), $meta['trace']['hooks'] );
		$this->assertArrayHasKey( '_t0', $meta['trace'] );

		$meta = $this->collector->meta( $meta, 'phpmailer', $mailer );
		$this->assertSame( 3, $mailer->SMTPDebug, 'Transcript switched on.' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertInstanceOf( \Closure::class, $mailer->Debugoutput ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$mailer->debug( "CLIENT -> SERVER: AUTH LOGIN\r\n" );
		$mailer->debug( "SERVER -> CLIENT: 334 VXNlcm5hbWU6\r\n" );
		$mailer->debug( "CLIENT -> SERVER: am9obkBleGFtcGxlLmNvbQ==\r\n" );
		$mailer->debug( "SERVER -> CLIENT: 535 5.7.8 Authentication failed\r\n" );

		$meta  = $this->result( $meta, Repository::STATUS_FAILED, 'SMTP Error: Could not authenticate.' );
		$trace = $meta['trace'];

		$this->assertSame( 0, $mailer->SMTPDebug, 'PHPMailer is handed back unchanged for the next mail.' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'echo', $mailer->Debugoutput ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$this->assertSame( 'phpmailer', $trace['via'] );
		$this->assertSame( 'smtp', $trace['transport']['mailer'] );
		$this->assertSame( 'j***@example.com', $trace['transport']['user'] );
		$this->assertSame( 'stored', $trace['transcript_status'] );
		$this->assertStringContainsString( 'SERVER -> CLIENT: 535 5.7.8 Authentication failed', $trace['transcript'] );
		$this->assertStringNotContainsString( 'am9obkBleGFtcGxlLmNvbQ', $trace['transcript'] );
		$this->assertSame( array( 'capture', 'phpmailer', 'result' ), array_keys( $trace['timeline'] ) );
		$this->assertGreaterThanOrEqual( $trace['timeline']['phpmailer'], $trace['total_ms'] );
		$this->assertArrayNotHasKey( '_t0', $trace, 'Internal keys are removed.' );
		$this->assertArrayNotHasKey( '_k', $trace );

		$json = (string) json_encode( $meta );
		$this->assertStringNotContainsString( 'S3cret', $json, 'The password is never stored.' );
		$this->assertLessThan( 4096, strlen( $json ), 'Compact.' );

		// Late debug output (e.g. a kept-alive connection) no longer lands anywhere.
		$mailer->debug( 'CLIENT -> SERVER: QUIT' );
	}

	public function test_transcript_of_successful_mail_is_dropped_in_failed_only_mode(): void {
		$mailer = new TraceMailer();
		$meta   = $this->collector->meta( $this->capture(), 'phpmailer', $mailer );
		$mailer->debug( 'SERVER -> CLIENT: 250 OK' );
		$trace = $this->result( $meta, Repository::STATUS_SENT )['trace'];

		$this->assertArrayNotHasKey( 'transcript', $trace );
		$this->assertSame( 'not_failed', $trace['transcript_status'] );
		$this->assertSame( 0, $mailer->SMTPDebug ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_always_mode_stores_successful_transcripts(): void {
		$this->settings = array( 'trace_transcript' => 'always' );
		$mailer         = new TraceMailer();
		$meta           = $this->collector->meta( $this->capture(), 'phpmailer', $mailer );
		$mailer->debug( 'SERVER -> CLIENT: 250 OK' );
		$trace = $this->result( $meta, Repository::STATUS_SENT )['trace'];

		$this->assertSame( 'SERVER -> CLIENT: 250 OK', $trace['transcript'] );
	}

	public function test_transcript_is_not_started_when_off_not_smtp_or_debugging_elsewhere(): void {
		$this->settings = array( 'trace_transcript' => 'off' );
		$mailer         = new TraceMailer();
		$trace          = $this->collector->meta( $this->capture(), 'phpmailer', $mailer )['trace'];
		$this->assertSame( 'off', $trace['transcript_status'] );
		$this->assertSame( 0, $mailer->SMTPDebug ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$this->settings = array();
		$mailer->Mailer = 'mail'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$trace          = $this->collector->meta( $this->capture(), 'phpmailer', $mailer )['trace'];
		$this->assertSame( 'not_smtp', $trace['transcript_status'] );

		$other = static function (): void {};
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Mailer      = 'smtp';
		$mailer->SMTPDebug   = 2;
		$mailer->Debugoutput = $other;
		$meta                = $this->collector->meta( $this->capture(), 'phpmailer', $mailer );
		$this->assertSame( 'debug_in_use', $meta['trace']['transcript_status'] );
		$this->result( $meta, Repository::STATUS_FAILED );
		$this->assertSame( 2, $mailer->SMTPDebug, "Other plugins' debugging is left alone." );
		$this->assertSame( $other, $mailer->Debugoutput );
		// phpcs:enable
	}

	public function test_pre_wp_mail_short_circuit_is_recorded_as_api_delivery(): void {
		$meta = $this->capture();
		$self = $this;

		Filters\expectApplied( 'pre_wp_mail' )->once()->andReturnUsing(
			static function () use ( &$meta, $self ) {
				$meta = $self->result( $meta, Repository::STATUS_SENT );
				return true;
			}
		);
		apply_filters( 'pre_wp_mail', null, array() );

		$trace = $meta['trace'];
		$this->assertSame( 'pre_wp_mail', $trace['via'] );
		$this->assertSame( 'api', $trace['transport']['mailer'] );
		$this->assertSame( array( 'capture', 'result' ), array_keys( $trace['timeline'] ) );
		$this->assertArrayNotHasKey( 'transcript_status', $trace );
	}

	public function test_unknown_result_without_hooks(): void {
		$trace = $this->result( $this->capture(), null )['trace'];
		$this->assertSame( 'unknown', $trace['via'] );
		$this->assertArrayNotHasKey( 'transport', $trace );
	}

	public function test_raw_source_is_stored_only_when_enabled_and_built_for_this_mail(): void {
		$mailer       = new TraceMailer();
		$mailer->mime = 'previous message';

		// Raw source switched off (default): nothing is handed over.
		$meta         = $this->collector->meta( $this->capture(), 'phpmailer', $mailer );
		$mailer->mime = "Subject: Hi\r\n\r\nBody";
		$this->result( $meta, Repository::STATUS_SENT );
		$this->assertSame( array( 'status' => 1 ), $this->collector->finalize( array( 'status' => 1 ) ) );

		// On: the new source goes into the raw column.
		$this->settings = array( 'trace_raw' => true );
		$meta           = $this->collector->meta( $this->capture(), 'phpmailer', $mailer );
		$mailer->mime   = "Subject: Second\r\n\r\nBody";
		$trace          = $this->result( $meta, Repository::STATUS_SENT )['trace'];
		$data           = $this->collector->finalize( array( 'status' => 1 ) );
		$this->assertSame( 'stored', $trace['raw'] );
		$this->assertSame( "Subject: Second\r\n\r\nBody", Eml::unpack( $data['raw'] ) );
		$this->assertSame( array( 'status' => 1 ), $this->collector->finalize( array( 'status' => 1 ) ), 'Handed over once.' );

		// Failed before PHPMailer built the message: the global instance still holds the old one.
		$meta  = $this->collector->meta( $this->capture(), 'phpmailer', $mailer );
		$trace = $this->result( $meta, Repository::STATUS_FAILED )['trace'];
		$this->assertArrayNotHasKey( 'raw', $trace );
		$this->assertArrayNotHasKey( 'raw', $this->collector->finalize( array() ) );
	}

	public function test_nested_mails_keep_their_own_state(): void {
		$outer_mailer = new TraceMailer();
		$outer        = $this->collector->meta( $this->capture(), 'phpmailer', $outer_mailer );

		$inner_mailer         = new TraceMailer();
		$inner_mailer->Mailer = 'mail'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$inner                = $this->collector->meta( $this->capture(), 'phpmailer', $inner_mailer );
		$this->assertSame( 'phpmailer', $this->result( $inner, Repository::STATUS_SENT )['trace']['via'] );

		$outer_mailer->debug( 'SERVER -> CLIENT: 421 Too busy' );
		$trace = $this->result( $outer, Repository::STATUS_FAILED )['trace'];
		$this->assertSame( 'SERVER -> CLIENT: 421 Too busy', $trace['transcript'] );
	}

	public function test_other_phases_and_garbage_pass_through(): void {
		$this->assertSame( 'x', $this->collector->meta( 'x', 'capture' ) );
		$this->assertSame( array( 'import' => 'wpml' ), $this->collector->meta( array( 'import' => 'wpml' ), 'import', array() ) );
		$this->assertSame( array( 'a' => 1 ), $this->collector->meta( array( 'a' => 1 ), 'result', array() ), 'No trace without capture.' );
	}

	public function test_settings_defaults_and_sanitizing(): void {
		$module = new Module( new Repository() );

		$defaults = $module->defaults( array() );
		$this->assertSame( 'failed', $defaults['trace_transcript'] );
		$this->assertFalse( $defaults['trace_raw'] );

		$clean = $module->sanitize(
			array(),
			array(
				'trace_transcript' => 'ALWAYS',
				'trace_raw'        => '1',
			)
		);
		$this->assertSame( 'always', $clean['trace_transcript'] );
		$this->assertFalse( $clean['trace_raw'], 'Raw source needs the acknowledgement.' );

		$clean = $module->sanitize(
			array(),
			array(
				'trace_transcript' => 'bogus',
				'trace_raw'        => '1',
				'trace_raw_ack'    => '1',
			)
		);
		$this->assertSame( 'failed', $clean['trace_transcript'] );
		$this->assertTrue( $clean['trace_raw'] );
	}

	public function test_rest_item_flags_exact_raw_source(): void {
		$module = new Module( new Repository() );
		$this->assertTrue( $module->rest_item( array(), array( 'raw' => 'gz:abc' ) )['trace_raw'] );
		$this->assertFalse( $module->rest_item( array(), array( 'raw' => '' ) )['trace_raw'] );
	}

	public function test_pack_roundtrip(): void {
		$mime = "From: a@example.com\r\nSubject: Ümlaut\r\n\r\n" . str_repeat( 'Body ', 1000 );
		$raw  = Eml::pack( $mime );
		$this->assertStringStartsWith( 'gz:', $raw );
		$this->assertLessThan( strlen( $mime ), strlen( $raw ) );
		$this->assertSame( $mime, Eml::unpack( $raw ) );
		$this->assertSame( 'x', Eml::unpack( 'b64:eA==' ) );
		$this->assertSame( '', Eml::unpack( '' ) );
		$this->assertSame( '', Eml::unpack( 'something else' ) );
		$this->assertSame( '', Eml::unpack( 'gz:!!!' ) );
	}
}
