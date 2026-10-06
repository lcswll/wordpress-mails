<?php
/**
 * Webhook payloads of email providers → normalised delivery events. Pure functions (no I/O), so every provider
 * format is unit-tested with small fixtures.
 *
 * An event only says what happened to which recipient and how it can be matched to a log entry:
 *   event      delivered | bounced | complaint
 *   hard       permanent bounce (feeds the problem recipients)
 *   recipient  lower-cased address
 *   ref        Mailspur's reference header, echoed back by the provider ('' if missing)
 *   message_id Message-ID header of the email without angle brackets ('' if unknown)
 *   id         provider's id of the event (deduplication of retries)
 * Nothing else from the payload is used – no texts, no times (the time of receipt counts).
 *
 * Formats:
 *   Postmark  one JSON object per request, RecordType Delivery | Bounce | SpamComplaint, Metadata.mailspur
 *   Mailgun   JSON {signature:{timestamp,token,signature}, event-data:{event,severity,recipient,user-variables,message}};
 *             legacy webhooks post a form (event, recipient, Message-Id, timestamp, token, signature, custom
 *             variables as fields), converted to that shape by mailgun_legacy()
 *   Brevo     one JSON object (or a list when batched), event delivered | hard_bounce | soft_bounce | …, X-Mailin-custom
 *   SES       the Message of an SNS notification: notificationType/eventType Delivery | Bounce | Complaint, mail.tags
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

defined( 'ABSPATH' ) || exit;

final class Webhooks {

	const DELIVERED = 'delivered';
	const BOUNCED   = 'bounced';
	const COMPLAINT = 'complaint';

	/** Events handled per request at most. */
	const MAX_EVENTS = 50;

	/** Seconds a Mailgun signature stays valid (replays of older ones are rejected). */
	const MAILGUN_TOLERANCE = 900;

	/** Name of the reference in the provider's metadata (header values, see Feedback::headers()). */
	const KEY = 'mailspur';

	/**
	 * @param array<mixed> $payload Decoded JSON body.
	 * @return array<int,array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string}>
	 */
	public static function parse( string $provider, array $payload ): array {
		switch ( $provider ) {
			case 'postmark':
				$events = self::postmark( $payload );
				break;
			case 'mailgun':
				$events = self::mailgun( $payload );
				break;
			case 'brevo':
				$events = self::brevo( $payload );
				break;
			case 'ses':
				$events = self::ses( $payload );
				break;
			default:
				$events = array();
		}
		return array_slice( array_values( array_filter( $events ) ), 0, self::MAX_EVENTS );
	}

	/**
	 * @param array<mixed> $p
	 * @return array<int,array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string}|null>
	 */
	private static function postmark( array $p ): array {
		$type = self::str( $p, 'RecordType' );
		$ref  = self::str( isset( $p['Metadata'] ) && is_array( $p['Metadata'] ) ? $p['Metadata'] : array(), self::KEY );
		$id   = self::str( $p, 'MessageID' );
		if ( 'Delivery' === $type ) {
			return array( self::event( self::DELIVERED, false, self::str( $p, 'Recipient' ), $ref, '', 'pm-d-' . $id ) );
		}
		if ( 'SpamComplaint' === $type ) {
			return array( self::event( self::COMPLAINT, false, self::str( $p, 'Email' ), $ref, '', 'pm-c-' . $id ) );
		}
		if ( 'Bounce' === $type ) {
			$hard = in_array( self::str( $p, 'Type' ), array( 'HardBounce', 'BadEmailAddress' ), true );
			return array( self::event( self::BOUNCED, $hard, self::str( $p, 'Email' ), $ref, '', 'pm-b-' . self::str( $p, 'ID' ) . $id ) );
		}
		return array();
	}

	/**
	 * Valid signature: HMAC-SHA256( signing key, timestamp . token ), not older than MAILGUN_TOLERANCE.
	 *
	 * @param array<mixed> $p
	 */
	public static function verify_mailgun( array $p, string $key, int $now ): bool {
		$sig = isset( $p['signature'] ) && is_array( $p['signature'] ) ? $p['signature'] : array();
		$ts  = self::str( $sig, 'timestamp' );
		$tok = self::str( $sig, 'token' );
		$hex = strtolower( self::str( $sig, 'signature' ) );
		if ( '' === $key || '' === $tok || ! ctype_digit( $ts ) || ! preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
			return false;
		}
		if ( abs( $now - (int) $ts ) > self::MAILGUN_TOLERANCE ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $ts . $tok, $key ), $hex );
	}

	/**
	 * Mailgun's one-time token (remembered to reject replays within the tolerance).
	 *
	 * @param array<mixed> $p
	 */
	public static function mailgun_token( array $p ): string {
		return self::str( isset( $p['signature'] ) && is_array( $p['signature'] ) ? $p['signature'] : array(), 'token' );
	}

	/**
	 * @param array<mixed> $p
	 * @return array<int,array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string}|null>
	 */
	private static function mailgun( array $p ): array {
		$data = isset( $p['event-data'] ) && is_array( $p['event-data'] ) ? $p['event-data'] : array();
		$vars = isset( $data['user-variables'] ) && is_array( $data['user-variables'] ) ? $data['user-variables'] : array();
		$msg  = isset( $data['message']['headers'] ) && is_array( $data['message']['headers'] ) ? $data['message']['headers'] : array();
		$ref  = self::str( $vars, self::KEY );
		$mid  = self::str( $msg, 'message-id' );
		$id   = 'mg-' . self::str( $data, 'id' );
		$to   = self::str( $data, 'recipient' );
		switch ( self::str( $data, 'event' ) ) {
			case 'delivered':
				return array( self::event( self::DELIVERED, false, $to, $ref, $mid, $id ) );
			case 'failed':
				return array( self::event( self::BOUNCED, 'permanent' === self::str( $data, 'severity' ), $to, $ref, $mid, $id ) );
			case 'complained':
				return array( self::event( self::COMPLAINT, false, $to, $ref, $mid, $id ) );
		}
		return array();
	}

	/**
	 * Mailgun's legacy webhooks (form-encoded body: signature fields, event fields and custom variables at the top
	 * level) in the shape of the current JSON webhooks, or null when the body is no such form.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function mailgun_legacy( string $body ): ?array {
		parse_str( $body, $form );
		if ( '' === self::str( $form, 'signature' ) || '' === self::str( $form, 'token' ) ) {
			return null;
		}
		// Legacy event => current event and severity ("dropped" is permanent when the address bounced before).
		$events = array(
			'delivered'  => array( 'delivered', '' ),
			'bounced'    => array( 'failed', 'permanent' ),
			'dropped'    => array( 'failed', 'hardfail' === self::str( $form, 'reason' ) ? 'permanent' : 'temporary' ),
			'complained' => array( 'complained', '' ),
		);
		$name   = self::str( $form, 'event' );
		$event  = $events[ $name ] ?? array( $name, '' );
		$mid    = self::str( $form, 'Message-Id' );
		return array(
			'signature'  => array(
				'timestamp' => self::str( $form, 'timestamp' ),
				'token'     => self::str( $form, 'token' ),
				'signature' => self::str( $form, 'signature' ),
			),
			'event-data' => array(
				'event'          => $event[0],
				'severity'       => $event[1],
				'recipient'      => self::str( $form, 'recipient' ),
				'id'             => 'legacy-' . self::str( $form, 'token' ),
				'user-variables' => array( self::KEY => self::str( $form, self::KEY ) ), // Custom variables are form fields.
				'message'        => array( 'headers' => array( 'message-id' => '' !== $mid ? $mid : self::str( $form, 'message-id' ) ) ),
			),
		);
	}

	/**
	 * @param array<mixed> $p
	 * @return array<int,array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string}|null>
	 */
	private static function brevo( array $p ): array {
		if ( self::is_list( $p ) ) {
			$out = array();
			foreach ( array_slice( $p, 0, self::MAX_EVENTS ) as $item ) {
				if ( is_array( $item ) && ! self::is_list( $item ) ) {
					$out = array_merge( $out, self::brevo( $item ) );
				}
			}
			return $out;
		}
		$map  = array(
			'delivered'     => array( self::DELIVERED, false ),
			'hard_bounce'   => array( self::BOUNCED, true ),
			'invalid_email' => array( self::BOUNCED, true ),
			'soft_bounce'   => array( self::BOUNCED, false ),
			'blocked'       => array( self::BOUNCED, false ),
			'spam'          => array( self::COMPLAINT, false ),
			'complaint'     => array( self::COMPLAINT, false ),
		);
		$name = self::str( $p, 'event' );
		if ( ! isset( $map[ $name ] ) ) {
			return array();
		}
		$ref = self::str( $p, 'X-Mailin-custom' );
		$ref = 0 === strpos( $ref, self::KEY . ':' ) ? substr( $ref, strlen( self::KEY ) + 1 ) : '';
		$mid = self::str( $p, 'message-id' );
		return array( self::event( $map[ $name ][0], $map[ $name ][1], self::str( $p, 'email' ), $ref, $mid, 'br-' . $name . '-' . self::str( $p, 'id' ) . $mid ) );
	}

	/**
	 * @param array<mixed> $p Message of the SNS notification (decoded).
	 * @return array<int,array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string}|null>
	 */
	private static function ses( array $p ): array {
		$type = self::str( $p, 'notificationType' );
		$type = '' !== $type ? $type : self::str( $p, 'eventType' );
		$mail = isset( $p['mail'] ) && is_array( $p['mail'] ) ? $p['mail'] : array();
		$tags = isset( $mail['tags'][ self::KEY ] ) && is_array( $mail['tags'][ self::KEY ] ) ? $mail['tags'][ self::KEY ] : array();
		$ref  = isset( $tags[0] ) && is_string( $tags[0] ) ? $tags[0] : '';
		$mid  = self::str( isset( $mail['commonHeaders'] ) && is_array( $mail['commonHeaders'] ) ? $mail['commonHeaders'] : array(), 'messageId' );
		$id   = 'ses-' . strtolower( $type ) . '-' . self::str( $mail, 'messageId' );

		$out = array();
		switch ( $type ) {
			case 'Delivery':
				foreach ( self::list_of( $p['delivery']['recipients'] ?? array() ) as $to ) {
					$out[] = self::event( self::DELIVERED, false, is_string( $to ) ? $to : '', $ref, $mid, $id );
				}
				break;
			case 'Bounce':
				$hard = 'Permanent' === self::str( isset( $p['bounce'] ) && is_array( $p['bounce'] ) ? $p['bounce'] : array(), 'bounceType' );
				foreach ( self::list_of( $p['bounce']['bouncedRecipients'] ?? array() ) as $to ) {
					$out[] = self::event( self::BOUNCED, $hard, is_array( $to ) ? self::str( $to, 'emailAddress' ) : '', $ref, $mid, $id );
				}
				break;
			case 'Complaint':
				foreach ( self::list_of( $p['complaint']['complainedRecipients'] ?? array() ) as $to ) {
					$out[] = self::event( self::COMPLAINT, false, is_array( $to ) ? self::str( $to, 'emailAddress' ) : '', $ref, $mid, $id );
				}
				break;
		}
		return $out;
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @param mixed $value
	 * @return array<int,mixed>
	 */
	private static function list_of( $value ): array {
		return is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_EVENTS ) : array();
	}

	/**
	 * @return array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string}|null
	 */
	private static function event( string $event, bool $hard, string $recipient, string $ref, string $message_id, string $id ): ?array {
		$recipient = strtolower( trim( $recipient ) );
		// "Name <a@b>" in some payloads.
		if ( preg_match( '/<([^<>]+)>/', $recipient, $m ) ) {
			$recipient = $m[1];
		}
		if ( '' === $recipient || strlen( $recipient ) > 254 || ! is_email( $recipient ) ) {
			return null;
		}
		$message_id = trim( $message_id, " \t<>" );
		return array(
			'event'      => $event,
			'hard'       => self::BOUNCED === $event && $hard,
			'recipient'  => $recipient,
			'ref'        => preg_match( '/^[a-f0-9]{12,32}$/', $ref ) ? $ref : '',
			'message_id' => strlen( $message_id ) <= 250 && preg_match( '/^[^\s<>"]+@[^\s<>"]+$/', $message_id ) ? $message_id : '',
			'id'         => substr( $id, 0, 300 ),
		);
	}

	/**
	 * Scalar value of a key as string, '' otherwise.
	 *
	 * @param array<mixed> $data
	 */
	private static function str( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
	}
}
