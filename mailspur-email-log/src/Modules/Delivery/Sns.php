<?php
/**
 * Amazon SNS messages (how Amazon SES reports deliveries, bounces and complaints to an HTTPS endpoint).
 *
 * Every message is signed by SNS. It is accepted only when
 *   - the signing certificate comes from https://sns.<region>.amazonaws.com/SimpleNotificationService-<hex>.pem
 *     (strict host and path check, same region as the topic) and the signature over the canonical fields verifies,
 *   - its timestamp is at most an hour old,
 *   - notifications come from the topic whose subscription this site confirmed.
 * A SubscriptionConfirmation is confirmed by requesting its SubscribeURL (same strict host check).
 *
 * Outgoing requests (only for SES, documented in readme.txt "External services"): the signing certificate
 * (cached for a day) and the SubscribeURL – both to sns.<region>.amazonaws.com.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

defined( 'ABSPATH' ) || exit;

final class Sns {

	const TOPIC_OPTION = 'mailspur_feedback_sns_topic';
	const CERT_PREFIX  = 'mailspur_sns_cert_';

	/** Maximum age of a message (seconds). */
	const TOLERANCE = 3600;

	const HOST = '/^sns\.([a-z]{2}(?:-[a-z]+)+-\d)\.amazonaws\.com$/';

	const TOPIC = '/^arn:aws:sns:([a-z]{2}(?:-[a-z]+)+-\d):\d{12}:[A-Za-z0-9_-]{1,256}$/';

	/**
	 * Fetches a URL: returns the body of a 200 response or ''.
	 *
	 * @var callable(string):string
	 */
	private $fetch;

	/**
	 * @param (callable(string):string)|null $fetch
	 */
	public function __construct( ?callable $fetch = null ) {
		$this->fetch = $fetch ?? array( self::class, 'get' );
	}

	/**
	 * Handles one SNS message.
	 *
	 * @param array<mixed> $m Decoded SNS message.
	 * @return array{status:string,message:array<mixed>|null} status: notification | confirmed | ignored | invalid
	 */
	public function handle( array $m, int $now ): array {
		$out = array(
			'status'  => 'invalid',
			'message' => null,
		);
		if ( ! $this->verify( $m, $now ) ) {
			return $out;
		}
		$type  = (string) $m['Type'];
		$topic = (string) $m['TopicArn'];
		if ( 'SubscriptionConfirmation' === $type ) {
			$url = (string) ( $m['SubscribeURL'] ?? '' );
			if ( ! self::valid_subscribe_url( $url, $topic ) || '' === call_user_func( $this->fetch, $url ) ) {
				return $out;
			}
			update_option( self::TOPIC_OPTION, $topic, false );
			$out['status'] = 'confirmed';
			return $out;
		}
		if ( 'Notification' !== $type ) {
			$out['status'] = 'ignored';
			return $out;
		}
		$confirmed = (string) get_option( self::TOPIC_OPTION, '' );
		if ( '' === $confirmed || ! hash_equals( $confirmed, $topic ) ) {
			return $out;
		}
		$message        = json_decode( (string) ( $m['Message'] ?? '' ), true );
		$out['status']  = 'notification';
		$out['message'] = is_array( $message ) ? $message : array();
		return $out;
	}

	/**
	 * @param array<mixed> $m
	 */
	public function verify( array $m, int $now ): bool {
		foreach ( array( 'Type', 'MessageId', 'TopicArn', 'Timestamp', 'Signature', 'SigningCertURL', 'SignatureVersion' ) as $key ) {
			if ( ! isset( $m[ $key ] ) || ! is_string( $m[ $key ] ) || '' === $m[ $key ] ) {
				return false;
			}
		}
		if ( ! function_exists( 'openssl_verify' ) || ! in_array( $m['SignatureVersion'], array( '1', '2' ), true ) ) {
			return false;
		}
		if ( ! preg_match( self::TOPIC, $m['TopicArn'], $topic ) || ! self::valid_cert_url( $m['SigningCertURL'], $topic[1] ) ) {
			return false;
		}
		$time = strtotime( $m['Timestamp'] );
		if ( false === $time || abs( $now - $time ) > self::TOLERANCE ) {
			return false;
		}
		$signature = base64_decode( $m['Signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SNS signatures are base64.
		$string    = self::string_to_sign( $m );
		$cert      = $this->certificate( $m['SigningCertURL'] );
		if ( false === $signature || '' === $string || '' === $cert ) {
			return false;
		}
		$key = openssl_pkey_get_public( $cert );
		if ( false === $key ) {
			return false;
		}
		return 1 === openssl_verify( $string, $signature, $key, '1' === $m['SignatureVersion'] ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256 );
	}

	/**
	 * Canonical string SNS signs: "Key\nValue\n" for the fields of the message type in this order.
	 *
	 * @param array<mixed> $m
	 */
	public static function string_to_sign( array $m ): string {
		$type = $m['Type'] ?? '';
		if ( 'Notification' === $type ) {
			$keys = array( 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' );
		} elseif ( 'SubscriptionConfirmation' === $type || 'UnsubscribeConfirmation' === $type ) {
			$keys = array( 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' );
		} else {
			return '';
		}
		$out = '';
		foreach ( $keys as $key ) {
			if ( isset( $m[ $key ] ) && is_string( $m[ $key ] ) ) {
				$out .= $key . "\n" . $m[ $key ] . "\n";
			} elseif ( 'Subject' !== $key ) {
				return '';
			}
		}
		return $out;
	}

	/** https://sns.<region>.amazonaws.com/SimpleNotificationService-<hex>.pem – nothing else. */
	public static function valid_cert_url( string $url, string $region ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		return preg_match( self::HOST, (string) ( $parts['host'] ?? '' ), $host ) && $host[1] === $region
			&& 1 === preg_match( '/^\/SimpleNotificationService-[0-9a-f]{16,64}\.pem$/', (string) ( $parts['path'] ?? '' ) );
	}

	/** https://sns.<region of the topic>.amazonaws.com/?Action=ConfirmSubscription&TopicArn=<topic>&… */
	public static function valid_subscribe_url( string $url, string $topic ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		if ( ! preg_match( self::TOPIC, $topic, $region ) || ! preg_match( self::HOST, (string) ( $parts['host'] ?? '' ), $host ) || $host[1] !== $region[1] ) {
			return false;
		}
		if ( ! in_array( (string) ( $parts['path'] ?? '/' ), array( '', '/' ), true ) ) {
			return false;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$arn = isset( $query['TopicArn'] ) && is_string( $query['TopicArn'] ) ? $query['TopicArn'] : '';
		return 'ConfirmSubscription' === ( $query['Action'] ?? '' ) && $arn === $topic;
	}

	/** PEM of the signing certificate ('' if it cannot be fetched), cached for a day. */
	private function certificate( string $url ): string {
		$key    = self::CERT_PREFIX . md5( $url );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$pem = (string) call_user_func( $this->fetch, $url );
		if ( strlen( $pem ) > 16384 || false === strpos( $pem, '-----BEGIN CERTIFICATE-----' ) ) {
			return '';
		}
		set_transient( $key, $pem, DAY_IN_SECONDS );
		return $pem;
	}

	/** Body of a 200 response or ''. No redirects (the URL was checked, its target would not be). */
	public static function get( string $url ): string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 65536,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}
}
