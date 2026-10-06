<?php
/**
 * Delivery module: webhook payloads of Postmark, Mailgun, Brevo and Amazon SES → delivery events, and the Mailgun
 * signature (valid, wrong key, tampered, replayed with an old timestamp).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Mailspur\Modules\Delivery\Webhooks;

final class DeliveryWebhooksTest extends TestCase {

	const NOW = 1790001000;

	/** Fake signing key, split so secret scanners do not flag it. */
	private function key(): string {
		return 'test-' . 'signing-' . 'key';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function mailgun( string $event, string $severity = '', int $timestamp = self::NOW, string $token = 'tok1' ): array {
		return array(
			'signature'  => array(
				'timestamp' => (string) $timestamp,
				'token'     => $token,
				'signature' => hash_hmac( 'sha256', $timestamp . $token, $this->key() ),
			),
			'event-data' => array(
				'id'             => 'ev1',
				'event'          => $event,
				'severity'       => $severity,
				'recipient'      => 'Anna@Example.com',
				'user-variables' => array( 'mailspur' => 'abcdef0123456789' ),
				'message'        => array( 'headers' => array( 'message-id' => 'm1@site.test' ) ),
			),
		);
	}

	public function test_mailgun_signature_valid(): void {
		$this->assertTrue( Webhooks::verify_mailgun( $this->mailgun( 'delivered' ), $this->key(), self::NOW ) );
		$this->assertSame( 'tok1', Webhooks::mailgun_token( $this->mailgun( 'delivered' ) ) );
	}

	public function test_mailgun_signature_invalid(): void {
		$this->assertFalse( Webhooks::verify_mailgun( $this->mailgun( 'delivered' ), 'other-key', self::NOW ), 'wrong key' );
		$this->assertFalse( Webhooks::verify_mailgun( $this->mailgun( 'delivered' ), '', self::NOW ), 'no key configured' );

		$tampered                           = $this->mailgun( 'delivered' );
		$tampered['signature']['timestamp'] = (string) ( self::NOW + 1 );
		$this->assertFalse( Webhooks::verify_mailgun( $tampered, $this->key(), self::NOW + 1 ), 'tampered timestamp' );

		$missing = $this->mailgun( 'delivered' );
		unset( $missing['signature'] );
		$this->assertFalse( Webhooks::verify_mailgun( $missing, $this->key(), self::NOW ), 'no signature' );
	}

	public function test_mailgun_signature_replay_rejected(): void {
		$old = $this->mailgun( 'delivered', '', self::NOW - 3600 );
		$this->assertFalse( Webhooks::verify_mailgun( $old, $this->key(), self::NOW ), 'an hour old' );
		$this->assertTrue( Webhooks::verify_mailgun( $old, $this->key(), self::NOW - 3000 ), 'fresh at that time' );
	}

	public function test_mailgun_events(): void {
		$delivered = Webhooks::parse( 'mailgun', $this->mailgun( 'delivered' ) );
		$this->assertSame(
			array(
				array(
					'event'      => 'delivered',
					'hard'       => false,
					'recipient'  => 'anna@example.com',
					'ref'        => 'abcdef0123456789',
					'message_id' => 'm1@site.test',
					'id'         => 'mg-ev1',
				),
			),
			$delivered
		);

		$hard = Webhooks::parse( 'mailgun', $this->mailgun( 'failed', 'permanent' ) )[0];
		$this->assertSame( array( 'bounced', true ), array( $hard['event'], $hard['hard'] ) );
		$soft = Webhooks::parse( 'mailgun', $this->mailgun( 'failed', 'temporary' ) )[0];
		$this->assertSame( array( 'bounced', false ), array( $soft['event'], $soft['hard'] ) );
		$this->assertSame( 'complaint', Webhooks::parse( 'mailgun', $this->mailgun( 'complained' ) )[0]['event'] );
		$this->assertSame( array(), Webhooks::parse( 'mailgun', $this->mailgun( 'opened' ) ), 'opens and clicks are ignored' );
	}

	public function test_mailgun_legacy_form_webhooks(): void {
		$form = static function ( string $event, array $more = array() ): string {
			return http_build_query(
				$more + array(
					'event'      => $event,
					'recipient'  => 'anna@example.com',
					'Message-Id' => '<m1@site.test>',
					'mailspur'   => 'abcdef0123456789', // X-Mailgun-Variables arrive as form fields.
					'timestamp'  => (string) self::NOW,
					'token'      => 'tok9',
					'signature'  => hash_hmac( 'sha256', self::NOW . 'tok9', 'test-' . 'signing-' . 'key' ),
				)
			);
		};

		$payload = Webhooks::mailgun_legacy( $form( 'bounced', array( 'code' => '550' ) ) );
		$this->assertIsArray( $payload );
		$this->assertTrue( Webhooks::verify_mailgun( $payload, $this->key(), self::NOW ), 'signature fields taken from the form' );
		$this->assertSame( 'tok9', Webhooks::mailgun_token( $payload ) );
		$this->assertSame(
			array(
				array(
					'event'      => 'bounced',
					'hard'       => true,
					'recipient'  => 'anna@example.com',
					'ref'        => 'abcdef0123456789',
					'message_id' => 'm1@site.test',
					'id'         => 'mg-legacy-tok9',
				),
			),
			Webhooks::parse( 'mailgun', $payload )
		);

		$this->assertSame( 'delivered', Webhooks::parse( 'mailgun', (array) Webhooks::mailgun_legacy( $form( 'delivered' ) ) )[0]['event'] );
		$this->assertSame( 'complaint', Webhooks::parse( 'mailgun', (array) Webhooks::mailgun_legacy( $form( 'complained' ) ) )[0]['event'] );
		$this->assertTrue( Webhooks::parse( 'mailgun', (array) Webhooks::mailgun_legacy( $form( 'dropped', array( 'reason' => 'hardfail' ) ) ) )[0]['hard'], 'dropped: bounced before' );
		$this->assertFalse( Webhooks::parse( 'mailgun', (array) Webhooks::mailgun_legacy( $form( 'dropped', array( 'reason' => 'old' ) ) ) )[0]['hard'], 'dropped: retries given up' );
		$this->assertSame( array(), Webhooks::parse( 'mailgun', (array) Webhooks::mailgun_legacy( $form( 'opened' ) ) ) );

		$this->assertNull( Webhooks::mailgun_legacy( 'event=delivered&recipient=anna%40example.com' ), 'unsigned form' );
		$this->assertNull( Webhooks::mailgun_legacy( 'not a form' ) );
	}

	public function test_postmark_events(): void {
		$delivery = Webhooks::parse(
			'postmark',
			array(
				'RecordType' => 'Delivery',
				'MessageID'  => 'pm-1',
				'Recipient'  => 'anna@example.com',
				'Metadata'   => array( 'mailspur' => 'abcdef0123456789' ),
			)
		);
		$this->assertSame( 'delivered', $delivery[0]['event'] );
		$this->assertSame( 'abcdef0123456789', $delivery[0]['ref'] );
		$this->assertSame( '', $delivery[0]['message_id'], 'Postmark\'s MessageID is its own id, not the Message-ID header' );

		$bounce = array(
			'RecordType' => 'Bounce',
			'ID'         => 42,
			'Type'       => 'HardBounce',
			'MessageID'  => 'pm-1',
			'Email'      => 'anna@example.com',
		);
		$this->assertTrue( Webhooks::parse( 'postmark', $bounce )[0]['hard'] );
		$bounce['Type'] = 'SoftBounce';
		$this->assertFalse( Webhooks::parse( 'postmark', $bounce )[0]['hard'] );

		$spam = Webhooks::parse(
			'postmark',
			array(
				'RecordType' => 'SpamComplaint',
				'MessageID'  => 'pm-1',
				'Email'      => 'anna@example.com',
			)
		);
		$this->assertSame( 'complaint', $spam[0]['event'] );
		$this->assertSame( array(), Webhooks::parse( 'postmark', array( 'RecordType' => 'Open' ) ) );
	}

	public function test_brevo_events_single_and_batched(): void {
		$one = Webhooks::parse(
			'brevo',
			array(
				'event'           => 'hard_bounce',
				'email'           => 'anna@example.com',
				'id'              => 7,
				'message-id'      => '<m2@site.test>',
				'X-Mailin-custom' => 'mailspur:abcdef0123456789',
			)
		);
		$this->assertSame( array( 'bounced', true, 'abcdef0123456789', 'm2@site.test' ), array( $one[0]['event'], $one[0]['hard'], $one[0]['ref'], $one[0]['message_id'] ) );

		$batch = Webhooks::parse(
			'brevo',
			array(
				array(
					'event' => 'delivered',
					'email' => 'anna@example.com',
				),
				array(
					'event' => 'spam',
					'email' => 'ben@example.com',
				),
				array(
					'event' => 'opened',
					'email' => 'ben@example.com',
				),
			)
		);
		$this->assertSame( array( 'delivered', 'complaint' ), array_column( $batch, 'event' ) );

		$foreign = Webhooks::parse(
			'brevo',
			array(
				'event'           => 'delivered',
				'email'           => 'anna@example.com',
				'X-Mailin-custom' => 'something else',
			)
		);
		$this->assertSame( '', $foreign[0]['ref'], 'only our own reference' );
	}

	public function test_ses_events(): void {
		$mail   = array(
			'messageId'     => 'ses-1',
			'tags'          => array( 'mailspur' => array( 'abcdef0123456789' ) ),
			'commonHeaders' => array( 'messageId' => '<m3@site.test>' ),
		);
		$bounce = Webhooks::parse(
			'ses',
			array(
				'notificationType' => 'Bounce',
				'mail'             => $mail,
				'bounce'           => array(
					'bounceType'        => 'Permanent',
					'bouncedRecipients' => array( array( 'emailAddress' => 'anna@example.com' ), array( 'emailAddress' => 'ben@example.com' ) ),
				),
			)
		);
		$this->assertCount( 2, $bounce );
		$this->assertSame( array( true, 'abcdef0123456789', 'm3@site.test' ), array( $bounce[0]['hard'], $bounce[0]['ref'], $bounce[0]['message_id'] ) );

		$delivery = Webhooks::parse(
			'ses',
			array(
				'eventType' => 'Delivery',
				'mail'      => $mail,
				'delivery'  => array( 'recipients' => array( 'anna@example.com' ) ),
			)
		);
		$this->assertSame( 'delivered', $delivery[0]['event'] );

		$complaint = Webhooks::parse(
			'ses',
			array(
				'notificationType' => 'Complaint',
				'mail'             => $mail,
				'complaint'        => array( 'complainedRecipients' => array( array( 'emailAddress' => 'anna@example.com' ) ) ),
			)
		);
		$this->assertSame( 'complaint', $complaint[0]['event'] );
	}

	public function test_untrusted_values_are_dropped(): void {
		$events = Webhooks::parse(
			'postmark',
			array(
				'RecordType' => 'Delivery',
				'Recipient'  => 'not an address',
			)
		);
		$this->assertSame( array(), $events, 'no valid recipient, no event' );

		$events = Webhooks::parse(
			'postmark',
			array(
				'RecordType' => 'Delivery',
				'Recipient'  => 'anna@example.com',
				'Metadata'   => array( 'mailspur' => '%" OR 1=1' ),
			)
		);
		$this->assertSame( '', $events[0]['ref'], 'reference must be hex' );
		$this->assertSame( array(), Webhooks::parse( 'unknown', array( 'RecordType' => 'Delivery' ) ) );
	}
}
