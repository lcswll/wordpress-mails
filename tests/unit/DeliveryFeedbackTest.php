<?php
/**
 * Delivery module: delivery status from the email provider – reference header and Message-ID per mail, the
 * webhook secret, matching events to log entries (reference, Message-ID, recipient + time), which status wins,
 * replays and the problem recipients fed by hard bounces.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Delivery\Feedback;
use Mailspur\Modules\Delivery\Problems;
use Mailspur\Repository;
use PHPMailer\PHPMailer\PHPMailer;
use WP_REST_Request;

final class DeliveryFeedbackTest extends TestCase {

	const NOW = 1790001000;
	const REF = 'abcdef0123456789';

	/** Fake webhook secret (32 alphanumeric characters), split so secret scanners do not flag it. */
	const SECRET = 'TestSecret' . 'AbcdefghijKlmnopqrstUv';

	/** @var array<string,mixed> */
	private $options = array();

	/** @var array<string,mixed> */
	private $transients = array();

	/** @var Feedback */
	private $feedback;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( self::SECRET );
		Functions\when( 'rest_url' )->alias(
			static function ( $path ) {
				return 'https://site.test/wp-json/' . $path;
			}
		);

		$this->options['mailspur_settings'] = array( 'feedback_provider' => 'postmark' );
		$now                                = static function (): int {
			return self::NOW;
		};
		$this->feedback                     = new Feedback( $now, new Problems( $now ) );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $url
	 */
	private function request( array $payload, array $url = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mailspur-email-log/v1/delivery/webhook' );
		$request->set_body( (string) json_encode( $payload ) );
		$request->set_url_params(
			$url + array(
				'provider' => 'postmark',
				'key'      => self::SECRET,
			)
		);
		return $request;
	}

	/** @return array<string,mixed> */
	private function bounce( string $id = '1', string $ref = self::REF ): array {
		return array(
			'RecordType' => 'Bounce',
			'ID'         => $id,
			'Type'       => 'HardBounce',
			'MessageID'  => 'pm-' . $id,
			'Email'      => 'anna@example.com',
			'Metadata'   => array( 'mailspur' => $ref ),
		);
	}

	/** @return array<string,mixed> */
	private function row( int $id, string $meta = '' ): array {
		return array(
			'id'         => $id,
			'recipients' => 'Anna <anna@example.com>',
			'meta'       => $meta,
		);
	}

	public function test_reference_header_and_message_id(): void {
		$meta = $this->feedback->meta( array(), 'capture', array() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $meta['feedback']['ref'] );

		$mailer = new PHPMailer();
		$meta   = $this->feedback->meta( $meta, 'phpmailer', $mailer );
		$this->assertSame( array( array( 'X-PM-Metadata-mailspur', $meta['feedback']['ref'] ) ), $mailer->getCustomHeaders() );

		$mailer->last_message_id = '<m1@site.test>';
		$meta                    = $this->feedback->meta( $meta, 'result', array( 'status' => Repository::STATUS_SENT ) );
		$this->assertSame( '<m1@site.test>', $meta['message_id'] );
	}

	public function test_no_reference_when_off_and_no_message_id_after_failure(): void {
		$this->options['mailspur_settings'] = array();
		$meta                               = $this->feedback->meta( array(), 'capture', array() );
		$this->assertArrayNotHasKey( 'feedback', $meta );

		$mailer                  = new PHPMailer();
		$mailer->last_message_id = '<stale@site.test>';
		$meta                    = $this->feedback->meta( $meta, 'phpmailer', $mailer );
		$this->assertSame( array(), $mailer->getCustomHeaders() );
		$meta = $this->feedback->meta( $meta, 'result', array( 'status' => Repository::STATUS_FAILED ) );
		$this->assertArrayNotHasKey( 'message_id', $meta, 'a stale id of the previous mail' );
	}

	public function test_provider_headers(): void {
		$this->assertSame( array( 'X-Mailgun-Variables', '{"mailspur":"' . self::REF . '"}' ), Feedback::header( 'mailgun', self::REF ) );
		$this->assertSame( array( 'X-Mailin-custom', 'mailspur:' . self::REF ), Feedback::header( 'brevo', self::REF ) );
		$this->assertSame( array( 'X-SES-MESSAGE-TAGS', 'mailspur=' . self::REF ), Feedback::header( 'ses', self::REF ) );
		$this->assertNull( Feedback::header( '', self::REF ) );
	}

	public function test_authorize_needs_secret_and_provider(): void {
		$this->assertFalse( $this->feedback->authorize( $this->request( array() ) ), 'no secret created yet' );
		$this->assertStringEndsWith( '/delivery/webhook/postmark/' . self::SECRET, Feedback::url( 'postmark' ) );

		$this->assertTrue( $this->feedback->authorize( $this->request( array() ) ) );
		$this->assertFalse( $this->feedback->authorize( $this->request( array(), array( 'key' => str_repeat( 'a', 32 ) ) ) ), 'wrong secret' );
		$this->assertFalse( $this->feedback->authorize( $this->request( array(), array( 'provider' => 'brevo' ) ) ), 'not the configured provider' );

		$this->options['mailspur_settings'] = array( 'feedback_provider' => '' );
		$this->assertFalse( $this->feedback->authorize( $this->request( array() ) ), 'feature off' );
	}

	public function test_hard_bounce_matched_by_reference(): void {
		$this->wpdb->results = array( array( $this->row( 12, '{"feedback":{"ref":"' . self::REF . '"}}' ) ) );
		$response            = $this->feedback->receive( $this->request( $this->bounce() ) );
		$this->assertSame(
			array(
				'received' => 1,
				'matched'  => 1,
			),
			$response->get_data()
		);
		$this->assertStringContainsString( '"ref":"' . self::REF . '"', $this->wpdb->prepared[0]['args'][2] );

		$update = $this->writes( 'update' )[0];
		$this->assertSame( array( 'id' => 12 ), $update[3] );
		$meta = json_decode( $update[2]['meta'], true );
		$this->assertSame(
			array(
				'ref'   => self::REF,
				'event' => 'bounced',
				'hard'  => true,
				'at'    => self::NOW,
				'via'   => 'postmark',
			),
			$meta['feedback']
		);
		$this->assertSame( array( '12' ), Problems::all()['anna@example.com']['ids'] );
	}

	public function test_replayed_event_is_ignored(): void {
		$this->wpdb->results = array( array( $this->row( 12 ) ), array( $this->row( 12 ) ) );
		$this->feedback->receive( $this->request( $this->bounce() ) );
		$second = $this->feedback->receive( $this->request( $this->bounce() ) );
		$this->assertSame( 0, $second->get_data()['matched'] );
		$this->assertCount( 1, $this->writes( 'update' ) );
	}

	public function test_unknown_reference_is_not_guessed(): void {
		$this->wpdb->results = array( array() );
		$response            = $this->feedback->receive( $this->request( $this->bounce() ) );
		$this->assertSame( 0, $response->get_data()['matched'] );
		$this->assertCount( 1, $this->wpdb->prepared, 'no fallback query by recipient' );
		$this->assertSame( array(), Problems::all() );
	}

	public function test_fallback_by_recipient_and_time(): void {
		// Latest email to the recipient: the first candidate does not contain the exact address.
		$this->wpdb->results = array(
			array(
				array(
					'id'         => 30,
					'recipients' => 'joanna@example.com',
					'meta'       => '',
				),
				$this->row( 29 ),
			),
		);
		$event               = array(
			'event'      => 'delivered',
			'hard'       => false,
			'recipient'  => 'anna@example.com',
			'ref'        => '',
			'message_id' => '',
			'id'         => 'x',
		);
		$this->assertTrue( $this->feedback->apply( $event, 'postmark' ) );
		$this->assertSame( array( 'id' => 29 ), $this->writes( 'update' )[0][3] );
		$args = $this->wpdb->prepared[0]['args'];
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - Feedback::DELIVERED_WINDOW ), $args[1], 'deliveries: emails of the last hours only' );
	}

	public function test_match_by_message_id(): void {
		$this->wpdb->results = array( array( $this->row( 40 ) ) );
		$event               = array(
			'event'      => 'delivered',
			'hard'       => false,
			'recipient'  => 'anna@example.com',
			'ref'        => '',
			'message_id' => 'm1@site.test',
			'id'         => 'x',
		);
		$this->assertTrue( $this->feedback->apply( $event, 'mailgun' ) );
		$this->assertSame( '%"message\_id":"<m1@site.test>"%', $this->wpdb->prepared[0]['args'][2] );
	}

	public function test_stronger_status_wins(): void {
		$event = array(
			'event'      => 'delivered',
			'hard'       => false,
			'recipient'  => 'anna@example.com',
			'ref'        => self::REF,
			'message_id' => '',
			'id'         => 'x',
		);
		// A delivery after a complaint does not hide the complaint …
		$this->wpdb->results = array( array( $this->row( 12, '{"feedback":{"ref":"' . self::REF . '","event":"complaint"}}' ) ) );
		$this->assertTrue( $this->feedback->apply( $event, 'postmark' ) );
		$this->assertSame( array(), $this->writes( 'update' ) );

		// … but replaces a temporary bounce.
		$this->wpdb->results = array( array( $this->row( 12, '{"feedback":{"ref":"' . self::REF . '","event":"bounced","hard":false}}' ) ) );
		$this->assertTrue( $this->feedback->apply( $event, 'postmark' ) );
		$this->assertSame( 'delivered', json_decode( $this->writes( 'update' )[0][2]['meta'], true )['feedback']['event'] );
	}

	public function test_bounce_of_an_address_outside_the_entry_is_not_recorded(): void {
		$this->wpdb->results = array( array( $this->row( 12 ) ) );
		$bounce              = $this->bounce();
		$bounce['Email']     = 'cc-recipient@example.com';
		$this->feedback->receive( $this->request( $bounce ) );
		$this->assertCount( 1, $this->writes( 'update' ), 'status of the entry' );
		$this->assertSame( array(), Problems::all(), 'but no problem recipient from the payload alone' );
	}

	public function test_invalid_bodies(): void {
		$request = $this->request( array() );
		$request->set_body( 'not json' );
		$this->assertSame( 400, $this->feedback->receive( $request )->get_error_data()['status'] );
		$request->set_body( str_repeat( ' ', Feedback::MAX_BODY + 1 ) );
		$this->assertSame( 413, $this->feedback->receive( $request )->get_error_data()['status'] );
	}

	public function test_mailgun_signature_required(): void {
		$this->options['mailspur_settings'] = array(
			'feedback_provider'    => 'mailgun',
			'feedback_signing_key' => 'test-' . 'signing-' . 'key',
		);
		$payload                            = array(
			'signature'  => array(
				'timestamp' => (string) self::NOW,
				'token'     => 'tok',
				'signature' => str_repeat( '0', 64 ),
			),
			'event-data' => array(
				'event'     => 'delivered',
				'recipient' => 'anna@example.com',
			),
		);
		$this->assertSame( 401, $this->feedback->receive( $this->request( $payload ) )->get_error_data()['status'] );

		$payload['signature']['signature'] = hash_hmac( 'sha256', self::NOW . 'tok', 'test-' . 'signing-' . 'key' );
		$this->wpdb->results               = array( array(), array() );
		$this->assertSame( 200, $this->feedback->receive( $this->request( $payload ) )->get_status() );
		$this->assertSame( 401, $this->feedback->receive( $this->request( $payload ) )->get_error_data()['status'], 'token replayed' );
	}
}
