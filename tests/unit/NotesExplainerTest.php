<?php
/**
 * Notes: error messages of failed mails are mapped to the right explanation.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Notes\Explainer;

final class NotesExplainerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Real-world messages (PHPMailer, SMTP servers, API mailers) => expected explanation.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function errors(): array {
		return array(
			'mail()'            => array( 'Could not instantiate mail function.', 'mail_function' ),
			'connect'           => array( 'SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting', 'smtp_connect' ),
			'connect host'      => array( 'SMTP Error: Could not connect to SMTP host. Connection refused', 'smtp_connect' ),
			'auth'              => array( 'SMTP Error: Could not authenticate.', 'smtp_auth' ),
			'535 generic'       => array( '535 5.7.0 Error: authentication failed: authentication failure', 'smtp_auth' ),
			'gmail'             => array( 'SMTP Error: Could not authenticate. 535-5.7.8 Username and Password not accepted. Learn more at https://support.google.com/mail/?p=BadCredentials', 'gmail_auth' ),
			'm365'              => array( '535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant.', 'm365_smtp_auth' ),
			'invalid address'   => array( 'Invalid address:  (From): wordpress@localhost', 'invalid_address' ),
			'data not accepted' => array( 'SMTP Error: data not accepted.', 'data_not_accepted' ),
			'recipients failed' => array( 'SMTP Error: The following recipients failed: anna@example.de: 550 5.1.1 <anna@example.de>: Recipient address rejected: User unknown', 'recipients_failed' ),
			'openssl'           => array( 'Extension missing: openssl', 'openssl' ),
			'timeout'           => array( 'SMTP Error: Could not connect to SMTP host. Connection timed out', 'timeout' ),
			'certificate'       => array( 'stream_socket_enable_crypto(): SSL operation failed with code 1. OpenSSL Error messages: error:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed', 'tls' ),
			'421'               => array( '421 4.7.0 Try again later, closing connection.', 'temporary' ),
			'421 service'       => array( '421 Service not available, closing transmission channel', 'service_unavailable' ),
			'450 greylisting'   => array( '450 4.2.0 <anna@example.de>: Recipient address rejected: Greylisted', 'temporary' ),
			'452'               => array( '452 4.5.3 Too many recipients', 'temporary' ),
			'530'               => array( '530 5.7.0 Must issue a STARTTLS command first', 'smtp_auth_required' ),
			'550 relay'         => array( '550 5.7.1 Relaying denied', 'relay_denied' ),
			'552'               => array( '552 5.3.4 Message size exceeds fixed maximum message size', 'too_large' ),
			'553'               => array( '553 5.7.1 <info@other.de>: Sender address rejected: not owned by user shop@example.de', 'sender_rejected' ),
			'554 spam'          => array( '554 5.7.1 Service unavailable; Client host [203.0.113.5] blocked using zen.spamhaus.org', 'blocked' ),
			'554 generic'       => array( '554 5.0.0 Transaction failed', 'data_not_accepted' ),
			'dmarc'             => array( '550 5.7.26 Unauthenticated email from gmail.com is not accepted due to domain\'s DMARC policy.', 'dmarc' ),
			'api key'           => array( 'The provided authorization grant is invalid, expired, or revoked (Unauthorized)', 'api_key' ),
			'api key sendgrid'  => array( 'The provided API key is invalid.', 'api_key' ),
			'api domain'        => array( 'The from address does not match a verified Sender Identity.', 'api_domain' ),
			'api quota'         => array( 'Maximum credits exceeded / Too Many Requests (429)', 'api_quota' ),
			'quota in data'     => array( 'SMTP Error: data not accepted. Message rejected: Sending quota exceeded.', 'api_quota' ),
			'pre_wp_mail'       => array( 'pre_wp_mail returned false', 'api_generic' ),
			'uppercase'         => array( 'COULD NOT INSTANTIATE MAIL FUNCTION', 'mail_function' ),
			'unknown'           => array( 'Something odd happened', '' ),
			'empty'             => array( '   ', '' ),
		);
	}

	/**
	 * @dataProvider errors
	 */
	public function test_errors_are_matched( string $error, string $key ): void {
		$this->assertSame( $key, Explainer::match( $error ) );
	}

	public function test_every_pattern_has_texts_with_steps(): void {
		$texts = Explainer::texts();
		foreach ( array_unique( Explainer::PATTERNS ) as $key ) {
			$this->assertArrayHasKey( $key, $texts, $key );
			$this->assertNotSame( '', $texts[ $key ]['title'] );
			$this->assertNotSame( '', $texts[ $key ]['explanation'] );
			$this->assertNotEmpty( $texts[ $key ]['steps'], $key );
		}
	}

	public function test_explain_returns_texts_or_null(): void {
		$help = Explainer::explain( 'SMTP Error: Could not authenticate.' );
		$this->assertNotNull( $help );
		$this->assertSame( 'smtp_auth', $help['key'] );
		$this->assertSame( 'SMTP login failed', $help['title'] );
		$this->assertSame( 'SMTP login failed', Explainer::title( 'SMTP Error: Could not authenticate.' ) );

		$this->assertNull( Explainer::explain( 'Something odd happened' ) );
		$this->assertSame( '', Explainer::title( '' ) );
	}
}
