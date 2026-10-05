<?php
/**
 * Delivery module: Amazon SNS messages (SES) – strict certificate and subscribe URLs, signature verification with a
 * key pair generated for the test, stale messages, subscription confirmation and the confirmed topic.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Delivery\Sns;

final class DeliverySnsTest extends TestCase {

	const NOW   = 1790001000;
	const TOPIC = 'arn:aws:sns:eu-central-1:123456789012:ses-events';
	const CERT  = 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-0123456789abcdef0123456789abcdef.pem';

	/** @var array<string,mixed> */
	private $options = array();

	/** @var array<string,mixed> */
	private $transients = array();

	/** @var array<int,string> */
	private $fetched = array();

	/** @var string */
	private $pem = '';

	/** @var resource|\OpenSSLAsymmetricKey|null */
	private $key = null;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
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
	}

	/** Self-signed key pair (needs a minimal openssl.cnf on Windows builds without one). */
	private function keys(): void {
		$config = sys_get_temp_dir() . '/mailspur-test-openssl.cnf';
		file_put_contents( $config, "[req]\ndistinguished_name=dn\n[dn]\n" );
		$options = array(
			'config'           => $config,
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'digest_alg'       => 'sha256',
		);
		$key     = openssl_pkey_new( $options );
		if ( false === $key ) {
			$this->markTestSkipped( 'OpenSSL cannot generate keys here.' );
		}
		$csr  = openssl_csr_new( array( 'commonName' => 'sns.test' ), $key, $options );
		$cert = openssl_csr_sign( $csr, null, $key, 1, $options );
		openssl_x509_export( $cert, $pem );
		$this->key = $key;
		$this->pem = (string) $pem;
	}

	private function sns(): Sns {
		return new Sns(
			function ( string $url ): string {
				$this->fetched[] = $url;
				return self::CERT === $url ? $this->pem : 'ok';
			}
		);
	}

	/**
	 * @param array<string,string> $fields
	 * @return array<string,string>
	 */
	private function signed( array $fields, string $version = '2' ): array {
		$message = array_merge(
			array(
				'MessageId'        => 'msg-1',
				'TopicArn'         => self::TOPIC,
				'Timestamp'        => gmdate( 'Y-m-d\TH:i:s.000\Z', self::NOW - 10 ),
				'SignatureVersion' => $version,
				'SigningCertURL'   => self::CERT,
			),
			$fields
		);
		openssl_sign( Sns::string_to_sign( $message ), $signature, $this->key, '1' === $version ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256 );
		$message['Signature'] = base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SNS signatures are base64.
		return $message;
	}

	/** @return array<string,string> */
	private function notification(): array {
		return $this->signed(
			array(
				'Type'    => 'Notification',
				'Message' => '{"notificationType":"Delivery"}',
			)
		);
	}

	public function test_certificate_url_is_checked_strictly(): void {
		$this->assertTrue( Sns::valid_cert_url( self::CERT, 'eu-central-1' ) );
		foreach ( array(
			'http://sns.eu-central-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem',
			'https://sns.eu-central-1.amazonaws.com.evil.test/SimpleNotificationService-0123456789abcdef.pem',
			'https://evil.test/sns.eu-central-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem',
			'https://sns.eu-central-1.amazonaws.com:8443/SimpleNotificationService-0123456789abcdef.pem',
			'https://user@sns.eu-central-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem',
			'https://sns.eu-central-1.amazonaws.com/other.pem',
			'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem?x=1',
			'https://s3.eu-central-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem',
		) as $url ) {
			$this->assertFalse( Sns::valid_cert_url( $url, 'eu-central-1' ), $url );
		}
		$this->assertFalse( Sns::valid_cert_url( self::CERT, 'us-east-1' ), 'region of the topic' );
	}

	public function test_subscribe_url_is_checked_strictly(): void {
		$url = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=' . rawurlencode( self::TOPIC ) . '&Token=abc';
		$this->assertTrue( Sns::valid_subscribe_url( $url, self::TOPIC ) );
		$this->assertFalse( Sns::valid_subscribe_url( str_replace( 'sns.eu-central-1.amazonaws.com', 'evil.test', $url ), self::TOPIC ) );
		$this->assertFalse( Sns::valid_subscribe_url( str_replace( 'ConfirmSubscription', 'Publish', $url ), self::TOPIC ) );
		$this->assertFalse( Sns::valid_subscribe_url( $url, 'arn:aws:sns:eu-central-1:123456789012:other' ), 'other topic' );
		$this->assertFalse( Sns::valid_subscribe_url( str_replace( 'https:', 'http:', $url ), self::TOPIC ) );
	}

	public function test_valid_signature_both_versions(): void {
		$this->keys();
		$this->assertTrue( $this->sns()->verify( $this->notification(), self::NOW ), 'SHA256' );
		$v1 = $this->signed(
			array(
				'Type'    => 'Notification',
				'Message' => 'x',
				'Subject' => 'Hello',
			),
			'1'
		);
		$this->assertTrue( $this->sns()->verify( $v1, self::NOW ), 'SHA1 with subject' );
		$this->assertSame( array( self::CERT ), array_unique( $this->fetched ) );
		$this->assertCount( 1, $this->fetched, 'certificate cached' );
	}

	public function test_invalid_signature_or_stale_message(): void {
		$this->keys();
		$tampered            = $this->notification();
		$tampered['Message'] = '{"notificationType":"Bounce"}';
		$this->assertFalse( $this->sns()->verify( $tampered, self::NOW ), 'tampered message' );

		$this->assertFalse( $this->sns()->verify( $this->notification(), self::NOW + 2 * 3600 ), 'replayed two hours later' );

		$foreign                   = $this->notification();
		$foreign['SigningCertURL'] = 'https://evil.test/SimpleNotificationService-0123456789abcdef.pem';
		$this->assertFalse( $this->sns()->verify( $foreign, self::NOW ), 'foreign certificate' );
		$this->assertNotContains( $foreign['SigningCertURL'], $this->fetched, 'never fetched' );
	}

	public function test_confirmation_and_topic(): void {
		$this->keys();
		$subscribe = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=' . rawurlencode( self::TOPIC ) . '&Token=abc';

		$this->assertSame( 'invalid', $this->sns()->handle( $this->notification(), self::NOW )['status'], 'no confirmed topic yet' );

		$confirm = $this->signed(
			array(
				'Type'         => 'SubscriptionConfirmation',
				'Message'      => 'Confirm',
				'Token'        => 'abc',
				'SubscribeURL' => $subscribe,
			)
		);
		$this->assertSame( 'confirmed', $this->sns()->handle( $confirm, self::NOW )['status'] );
		$this->assertContains( $subscribe, $this->fetched );
		$this->assertSame( self::TOPIC, $this->options[ Sns::TOPIC_OPTION ] );

		$result = $this->sns()->handle( $this->notification(), self::NOW );
		$this->assertSame( 'notification', $result['status'] );
		$this->assertSame( array( 'notificationType' => 'Delivery' ), $result['message'] );

		$this->options[ Sns::TOPIC_OPTION ] = 'arn:aws:sns:eu-central-1:123456789012:other';
		$this->assertSame( 'invalid', $this->sns()->handle( $this->notification(), self::NOW )['status'], 'other topic' );
	}
}
