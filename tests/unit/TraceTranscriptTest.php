<?php
/**
 * Trace module: SMTP transcript masking.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Trace\Transcript;

final class TraceTranscriptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
	}

	/**
	 * @param string[] $chunks
	 */
	private function record( array $chunks ): string {
		$transcript = new Transcript();
		foreach ( $chunks as $chunk ) {
			$transcript->add( $chunk );
		}
		return $transcript->text();
	}

	public function test_auth_login_credentials_and_challenges_are_hidden(): void {
		$text = $this->record(
			array(
				"SERVER -> CLIENT: 220 mail.example.com ESMTP\r\n",
				"CLIENT -> SERVER: EHLO site.example\r\n",
				"SERVER -> CLIENT: 250-mail.example.com\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n",
				"CLIENT -> SERVER: AUTH LOGIN\r\n",
				"SERVER -> CLIENT: 334 VXNlcm5hbWU6\r\n",
				"CLIENT -> SERVER: am9obkBleGFtcGxlLmNvbQ==\r\n",
				"SERVER -> CLIENT: 334 UGFzc3dvcmQ6\r\n",
				"CLIENT -> SERVER: UzNjcmV0LVBhc3N3MHJk\r\n",
				"SERVER -> CLIENT: 235 2.7.0 Authentication successful\r\n",
				"CLIENT -> SERVER: MAIL FROM:<shop@example.com>\r\n",
			)
		);

		$this->assertStringNotContainsString( 'am9obkBleGFtcGxlLmNvbQ', $text );
		$this->assertStringNotContainsString( 'UzNjcmV0LVBhc3N3MHJk', $text );
		$this->assertStringNotContainsString( 'VXNlcm5hbWU6', $text );
		$this->assertStringContainsString( "CLIENT -> SERVER: AUTH LOGIN\nSERVER -> CLIENT: 334 [hidden]\nCLIENT -> SERVER: [hidden]", $text );
		$this->assertStringContainsString( 'SERVER -> CLIENT: 235 2.7.0 Authentication successful', $text );
		$this->assertStringContainsString( 'CLIENT -> SERVER: MAIL FROM:<shop@example.com>', $text, 'Masking ends with the final reply.' );
		$this->assertStringContainsString( "250-AUTH LOGIN PLAIN\n250 8BITMIME", $text, 'Multi-line replies are kept.' );
	}

	public function test_auth_plain_and_xoauth2_inline_credentials_are_hidden(): void {
		$text = $this->record(
			array(
				"CLIENT -> SERVER: AUTH PLAIN AGpvaG4AUzNjcmV0\r\n",
				"SERVER -> CLIENT: 535 5.7.8 Authentication failed\r\n",
				"CLIENT -> SERVER: AUTH XOAUTH2 dXNlcj1qb2huAWF1dGg9QmVhcmVyIHlhMjk=\r\n",
				"SERVER -> CLIENT: 334 eyJzdGF0dXMiOiI0MDEifQ==\r\n",
				"CLIENT -> SERVER: \r\n",
				"SERVER -> CLIENT: 535 5.7.8 Username and Password not accepted\r\n",
				"CLIENT -> SERVER: QUIT\r\n",
			)
		);

		$this->assertStringNotContainsString( 'AGpvaG4AUzNjcmV0', $text );
		$this->assertStringNotContainsString( 'dXNlcj1qb2hu', $text );
		$this->assertStringNotContainsString( 'eyJzdGF0dXMi', $text );
		$this->assertStringContainsString( 'CLIENT -> SERVER: AUTH PLAIN [hidden]', $text );
		$this->assertStringContainsString( 'CLIENT -> SERVER: AUTH XOAUTH2 [hidden]', $text );
		$this->assertStringContainsString( 'SERVER -> CLIENT: 535 5.7.8 Authentication failed', $text );
		$this->assertStringContainsString( 'CLIENT -> SERVER: QUIT', $text );
	}

	public function test_message_data_is_collapsed(): void {
		$text = $this->record(
			array(
				"CLIENT -> SERVER: DATA\r\n",
				"SERVER -> CLIENT: 354 End data with <CR><LF>.<CR><LF>\r\n",
				"CLIENT -> SERVER: Subject: Password reset\r\n",
				"CLIENT -> SERVER: \r\n",
				"CLIENT -> SERVER: https://site.example/wp-login.php?key=SECRET\r\n",
				"CLIENT -> SERVER: .\r\n",
				"SERVER -> CLIENT: 250 2.0.0 OK queued as 12345\r\n",
			)
		);

		$this->assertStringNotContainsString( 'SECRET', $text );
		$this->assertStringNotContainsString( 'Password reset', $text );
		$this->assertSame(
			"CLIENT -> SERVER: DATA\nSERVER -> CLIENT: 354 End data with <CR><LF>.<CR><LF>\n[message data omitted: 3 lines]\nCLIENT -> SERVER: .\nSERVER -> CLIENT: 250 2.0.0 OK queued as 12345",
			$text
		);
	}

	public function test_rejected_data_command_does_not_hide_following_lines(): void {
		$text = $this->record(
			array(
				"CLIENT -> SERVER: DATA\r\n",
				"SERVER -> CLIENT: 554 5.5.1 Error: no valid recipients\r\n",
				"CLIENT -> SERVER: QUIT\r\n",
			)
		);
		$this->assertStringContainsString( 'CLIENT -> SERVER: QUIT', $text );
	}

	public function test_connection_options_are_hidden(): void {
		$text = $this->record(
			array(
				"Connection: opening to ssl://smtp.example.com:465, timeout=300, options=array (\n  'ssl' => array ( 'passphrase' => 'topsecret' ),\n)",
				'Connection: opening to smtp.example.com:587, timeout=300, options=array()',
				'SMTP ERROR: Failed to connect to server: Connection refused (111)',
			)
		);
		$this->assertStringNotContainsString( 'topsecret', $text );
		$this->assertStringContainsString( 'Connection: opening to ssl://smtp.example.com:465, timeout=300, options=[hidden]', $text );
		$this->assertStringContainsString( 'options=array()', $text );
		$this->assertStringContainsString( 'Connection refused', $text );
	}

	public function test_size_is_capped_and_control_characters_are_removed(): void {
		$transcript = new Transcript();
		$transcript->add( "SERVER -> CLIENT: 220 hello\x07\x00 world" );
		for ( $i = 0; $i < 2000; $i++ ) {
			$transcript->add( 'CLIENT -> SERVER: NOOP ' . str_repeat( 'x', 40 ) );
		}
		$text = $transcript->text();

		$this->assertStringStartsWith( 'SERVER -> CLIENT: 220 hello world', $text );
		$this->assertLessThanOrEqual( Transcript::MAX_BYTES + 30, strlen( $text ) );
		$this->assertStringEndsWith( '[transcript truncated]', $text );
	}
}
