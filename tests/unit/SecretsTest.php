<?php
/**
 * Secrets in plain text: what is found and masked – and the many look-alikes that must stay untouched.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Mailspur\Redactor;
use Mailspur\Secrets;

final class SecretsTest extends TestCase {

	/**
	 * @return string[] Kinds found in the text.
	 */
	private function kinds( string $text ): array {
		return array_column( Secrets::find( $text ), 'kind' );
	}

	/**
	 * @return array<string,array{0:string,1:string}> Text => masked text.
	 */
	public function passwords(): array {
		return array(
			'plain label'              => array( "Username: anna\nPassword: Xy7!kq\n", "Username: anna\nPassword: [redacted]\n" ),
			'end of text'              => array( 'Password: Xy7!kq', 'Password: [redacted]' ),
			'German Passwort'          => array( "Benutzername: anna\nPasswort: Ab3\$xyz9\nGruß", "Benutzername: anna\nPasswort: [redacted]\nGruß" ),
			'German Kennwort in HTML'  => array( '<p>Kennwort: <strong>Gh7-kk2lp</strong></p>', '<p>Kennwort: <strong>[redacted]</strong></p>' ),
			'French with space'        => array( "Mot de passe : Zq9!aaap\n", "Mot de passe : [redacted]\n" ),
			'Spanish'                  => array( "Contraseña: Pq8#rrrs\n", "Contraseña: [redacted]\n" ),
			'Spanish entity'           => array( '<p>Contrase&ntilde;a: Pq8#rrrs</p>', '<p>Contrase&ntilde;a: [redacted]</p>' ),
			'Dutch'                    => array( 'Wachtwoord: Kk8#rrrs', 'Wachtwoord: [redacted]' ),
			'bold label'               => array( '<b>Password</b>: hunter22<br>', '<b>Password</b>: [redacted]<br>' ),
			'label words before colon' => array( '<p>Your password has been automatically generated: <strong>p@ssW0rd77</strong></p>', '<p>Your password has been automatically generated: <strong>[redacted]</strong></p>' ),
			'password is'              => array( "Your new password is Xy7!kq2.\n", "Your new password is [redacted].\n" ),
			'table cells'              => array( '<table><tr><td>Password</td><td>secret</td></tr></table>', '<table><tr><td>Password</td><td>[redacted]</td></tr></table>' ),
			'table cells with colon'   => array( '<tr><td><b>Password:</b></td><td><code>hunter22</code></td></tr>', '<tr><td><b>Password:</b></td><td><code>[redacted]</code></td></tr>' ),
			'table header cell'        => array( '<tr><th>Your password</th><td>Sunny2026</td></tr>', '<tr><th>Your password</th><td>[redacted]</td></tr>' ),
			'value on the next line'   => array( "Password:\n  9fK2-pq81\n\nThanks", "Password:\n  [redacted]\n\nThanks" ),
			'mixed case letters'       => array( "Password: SunnyDayX\n", "Password: [redacted]\n" ),
			'full-width colon'         => array( "Password\u{FF1A}Xy7!kq\n", "Password\u{FF1A}[redacted]\n" ),
		);
	}

	/**
	 * @dataProvider passwords
	 */
	public function test_passwords_are_found_and_masked( string $text, string $masked ): void {
		$this->assertSame( array( Secrets::PASSWORD ), $this->kinds( $text ) );
		$this->assertSame( $masked, Secrets::mask( $text ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function not_passwords(): array {
		return array(
			'forgot link'           => array( 'Forgot your password? <a href="https://x.de/lost">Reset it</a>' ),
			'set password link'     => array( 'Set your password here: https://x.de/wp-login.php?action=rp&key=AbC123&login=anna' ),
			'set password link tag' => array( 'Password: <a href="https://x.de/wp-login.php?action=rp">Set your password</a>' ),
			'core new-user mail'    => array( "Username: anna\n\nTo set your password, visit the following address:\n\nhttps://x.de/wp-login.php?action=rp&key=[redacted]&login=anna\n" ),
			'password reset'        => array( 'Password reset for your account at Example Shop' ),
			'password changed'      => array( "Password: changed\n" ),
			'German geändert'       => array( "Passwort: geändert\n" ),
			'status sentence'       => array( "Your password was changed successfully.\n" ),
			'redacted'              => array( "Password: [redacted]\n" ),
			'asterisks'             => array( "Password: ********\n" ),
			'bullets'               => array( "Password: ••••••••\n" ),
			'url value'             => array( "Password: https://x.de/reset/abc123\n" ),
			'www value'             => array( "Password: www.x.de/reset1\n" ),
			'multiple words'        => array( "Password: The one you chose at signup\n" ),
			'placeholder'           => array( "Password: {password}\n" ),
			'percent placeholder'   => array( "Password: %password%\n" ),
			'email address'         => array( "Password: anna@example.com\n" ),
			'date'                  => array( "Password last changed: 2024-10-05\n" ),
			'time'                  => array( "Password changed at: 12:45\n" ),
			'too short'             => array( "Password: n/a\n" ),
			'is required'           => array( "A password is required.\n" ),
			'is weak'               => array( "Your password is too weak, please choose another.\n" ),
			'url parameter'         => array( "https://x.de/?password=Xy7!kq\n" ),
			'lostpassword path'     => array( "https://x.de/lostpassword: abc123\n" ),
			'plugin slug'           => array( "wp-password-bcrypt: active1\n" ),
			'password field css'    => array( '<style>input[type=password]{border:1px}</style>' ),
			'html attribute'        => array( '<input type="password" name="password" value="">' ),
			'strength'              => array( "Password strength: Strong\n" ),
			'table status cell'     => array( '<tr><td>Password</td><td>Changed</td></tr>' ),
			'table masked cell'     => array( '<tr><td>Password</td><td>******</td></tr>' ),
			'words after colon'     => array( "Password: Use the one from your welcome email\n" ),
			'passwords plural'      => array( "Passwords: Xy7!kq\n" ),
		);
	}

	/**
	 * @dataProvider not_passwords
	 */
	public function test_look_alikes_are_no_passwords( string $text ): void {
		$this->assertSame( array(), Secrets::find( $text ) );
		$this->assertSame( $text, Secrets::mask( $text ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function api_keys(): array {
		// Fake keys are split ("sk_" . "live_…") so secret scanners do not flag the test data.
		return array(
			'stripe secret'     => array( 'Key: sk_' . 'live_51HxYzAbCdEfGhIjKlMn4f2a', 'sk_' . 'live_…4f2a' ),
			'stripe restricted' => array( 'rk_' . 'live_51HxYzAbCdEfGhIjKlMnZZ99 in config', 'rk_' . 'live_…ZZ99' ),
			'github classic'    => array( 'token ghp' . '_abcdefghijklmnopqrstuvwxyz0123456789 ok', 'ghp_…6789' ),
			'github fine'       => array( 'github' . '_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyzABCDEFGHIJ0123456789xyz', 'github_pat_…9xyz' ),
			'slack bot'         => array( 'slack xox' . 'b-1234567890-abcdefghij', 'xoxb-…ghij' ),
			'aws access key'    => array( 'AWS_ACCESS_KEY_ID=AKIA' . 'IOSFODNN7EXAMPLE', 'AKIA…MPLE' ),
			'google api key'    => array( 'maps key AIza' . 'SyA-1234567890abcdefghijklmnopqrstu.', 'AIza…rstu' ),
			'sendgrid'          => array( 'SG' . '.abcdefghijklmnopqrstuv.abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ', 'SG.…NOPQ' ),
		);
	}

	/**
	 * @dataProvider api_keys
	 */
	public function test_api_keys_are_found_with_a_masked_hint_only( string $text, string $hint ): void {
		$found = Secrets::find( $text );
		$this->assertSame(
			array(
				array(
					'kind' => Secrets::API_KEY,
					'hint' => $hint,
				),
			),
			$found
		);
		$this->assertStringContainsString( '[redacted]', Secrets::mask( $text ) );
		$this->assertStringNotContainsString( substr( $hint, -4 ) . ' ', Secrets::mask( $text ) . ' ' );
	}

	public function test_api_key_look_alikes_are_ignored(): void {
		foreach ( array(
			'sk_' . 'test_51HxYzAbCdEfGhIjKlMn4f2a', // Test keys cannot charge anything.
			'pk_' . 'live_51HxYzAbCdEfGhIjKlMn4f2a', // Publishable keys are public by design.
			'ghp_short',
			'xoxb-short',
			'AKIA1234',
			'https://example.com/task_sk_' . 'live_link',
			'MY_AKIA' . 'IOSFODNN7EXAMPLEX',
		) as $text ) {
			$this->assertSame( array(), Secrets::find( $text ), $text );
		}
	}

	public function test_private_key_body_is_masked_and_the_header_kept(): void {
		$body = str_repeat( 'MIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeL', 3 );
		$text = "Here is the key:\n-----BEGIN RSA PRIVATE KEY-----\n{$body}\n-----END RSA PRIVATE KEY-----\nBye";

		$this->assertSame(
			array(
				array(
					'kind' => Secrets::PRIVATE_KEY,
					'hint' => '-----BEGIN RSA PRIVATE KEY-----',
				),
			),
			Secrets::find( $text )
		);
		$masked = Secrets::mask( $text );
		$this->assertStringNotContainsString( 'MIIEow', $masked );
		$this->assertSame( "Here is the key:\n-----BEGIN RSA PRIVATE KEY-----[redacted]-----END RSA PRIVATE KEY-----\nBye", $masked );
	}

	public function test_private_key_without_end_line_and_mere_mentions(): void {
		$text = "-----BEGIN PRIVATE KEY-----\n" . str_repeat( 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo', 3 ) . "\n";
		$this->assertSame( array( Secrets::PRIVATE_KEY ), $this->kinds( $text ) );
		$this->assertStringNotContainsString( 'QUJD', Secrets::mask( $text ) );

		$this->assertSame( array(), Secrets::find( 'Paste your -----BEGIN PRIVATE KEY----- block into the settings.' ) );
		$this->assertSame( array(), Secrets::find( "-----BEGIN PUBLIC KEY-----\n" . str_repeat( 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo', 3 ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function cards(): array {
		return array(
			'visa grouped'           => array( "Card: 4111 1111 1111 1111\n", '…1111', "Card: [redacted]\n" ),
			'visa dashes'            => array( 'Card number 4012-8888-8888-1881 exp 12/27', '…1881', 'Card number [redacted] exp 12/27' ),
			'mastercard grouped'     => array( '<td>5555 5555 5555 4444</td>', '…4444', '<td>[redacted]</td>' ),
			'amex grouped'           => array( 'Amex 3782 822463 10005', '…0005', 'Amex [redacted]' ),
			'discover ungrouped'     => array( 'Credit card: 6011111111111117', '…1117', 'Credit card: [redacted]' ),
			'German ungrouped'       => array( 'Kreditkartennummer: 4111111111111111', '…1111', 'Kreditkartennummer: [redacted]' ),
			'visa context in HTML'   => array( '<b>Visa</b> <span>4111111111111111</span>', '…1111', '<b>Visa</b> <span>[redacted]</span>' ),
			'diners grouped'         => array( 'Diners 3056 930902 5904', '…5904', 'Diners [redacted]' ),
			'jcb grouped'            => array( 'JCB 3530 1113 3330 0000', '…0000', 'JCB [redacted]' ),
			'visa 13 digits grouped' => array( 'Card 4222 2222 2222 2', '…2222', 'Card [redacted]' ),
		);
	}

	/**
	 * @dataProvider cards
	 */
	public function test_card_numbers_are_found_and_masked( string $text, string $hint, string $masked ): void {
		$this->assertSame(
			array(
				array(
					'kind' => Secrets::CARD,
					'hint' => $hint,
				),
			),
			Secrets::find( $text )
		);
		$this->assertSame( $masked, Secrets::mask( $text ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function not_cards(): array {
		return array(
			'luhn fails'               => array( 'Card: 4111 1111 1111 1112' ),
			'ungrouped order number'   => array( 'Order 4111111111111111 has shipped' ),
			'ungrouped tracking'       => array( 'Tracking: 5555555555554444 (DHL)' ),
			'German IBAN'              => array( 'IBAN: DE89 3704 0044 0532 0130 00' ),
			'IBAN no spaces'           => array( 'IBAN DE89370400440532013000' ),
			'Austrian IBAN'            => array( 'AT61 1904 3002 3457 3201' ),
			'Dutch IBAN with card IIN' => array( 'NL91 4111 1111 1111 1111 00' ),
			'phone number'             => array( 'Call +49 30 1234 5678 90' ),
			'wrong IIN'                => array( 'Card: 1234 5678 9012 3456' ),
			'masked card'              => array( 'Card: **** **** **** 1111' ),
			'last four only'           => array( 'Visa ending in 1111' ),
			'decimal amount'           => array( 'Total 4111111111111111.00 EUR' ),
			'mixed separators'         => array( 'Card 4111 1111-1111 1111' ),
			'odd grouping'             => array( 'Card 41 1111 1111 1111 11' ),
			'same digit'               => array( 'Card 4444 4444 4444 4444' ),
			'timestamp'                => array( 'Card updated 1696501234567' ),
			'inside a longer number'   => array( 'Card ref 9 4111 1111 1111 1111 9999' ),
			'part of a word'           => array( 'card-id: x4111111111111111' ),
			'visa wrong length'        => array( 'Card 4111 1111 1111 11' ),
			'amex wrong length'        => array( 'Card 3782 8224 6310 0050' ),
		);
	}

	/**
	 * @dataProvider not_cards
	 */
	public function test_card_look_alikes_are_ignored( string $text ): void {
		$this->assertSame( array(), Secrets::find( $text ) );
		$this->assertSame( $text, Secrets::mask( $text ) );
	}

	public function test_luhn(): void {
		$this->assertTrue( Secrets::luhn( '4111111111111111' ) );
		$this->assertTrue( Secrets::luhn( '378282246310005' ) );
		$this->assertFalse( Secrets::luhn( '4111111111111112' ) );
	}

	public function test_several_secrets_in_one_mail_are_all_masked(): void {
		$text = "Login: anna\nPassword: Xy7!kq99\nCard: 4111 1111 1111 1111\nKey: sk_" . "live_51HxYzAbCdEfGhIjKlMn4f2a\n";
		$this->assertSame( array( Secrets::API_KEY, Secrets::PASSWORD, Secrets::CARD ), $this->kinds( $text ) );
		$this->assertSame( "Login: anna\nPassword: [redacted]\nCard: [redacted]\nKey: [redacted]\n", Secrets::mask( $text ) );
	}

	public function test_same_secret_twice_is_one_finding_but_masked_twice(): void {
		$text = "Card: 4111 1111 1111 1111\nCard: 4111 1111 1111 1111\n";
		$this->assertCount( 1, Secrets::find( $text ) );
		$this->assertSame( "Card: [redacted]\nCard: [redacted]\n", Secrets::mask( $text ) );
	}

	public function test_ordinary_mails_stay_untouched(): void {
		$order = '<html><body><h1>Thanks for your order #4711</h1><table><tr><td>Total</td><td>129,90 €</td></tr>'
			. '<tr><td>IBAN</td><td>DE89 3704 0044 0532 0130 00</td></tr><tr><td>Reference</td><td>2026-4711</td></tr></table>'
			. '<p>Forgot your password? <a href="https://shop.example.de/my-account/lost-password/">Reset it</a>.</p>'
			. '<p>Phone: +49 30 123456789 · VAT ID DE123456789</p></body></html>';
		$this->assertSame( array(), Secrets::find( $order ) );
		$this->assertSame( $order, Secrets::mask( $order ) );
	}

	public function test_only_the_start_of_huge_bodies_is_scanned(): void {
		$text = str_repeat( 'x', Secrets::SCAN_BYTES ) . "\nPassword: Xy7!kq\n";
		$this->assertSame( array(), Secrets::find( $text ) );
		$this->assertSame( array( Secrets::PASSWORD ), $this->kinds( "Password: Xy7!kq\n" . str_repeat( 'x', Secrets::SCAN_BYTES ) ) );
	}

	public function test_invalid_utf8_does_not_break_anything(): void {
		$text = "Passwort: Ab3\$xyz9\n\xC3\x28 broken";
		$this->assertSame( array( Secrets::PASSWORD ), $this->kinds( $text ) );
		$this->assertSame( "Passwort: [redacted]\n\xC3\x28 broken", Secrets::mask( $text ) );
	}

	public function test_redactor_masks_links_and_plain_text_secrets_with_the_same_setting(): void {
		$text = "Password: Xy7!kq99\nhttps://x.de/wp-login.php?action=rp&key=AbC123&login=anna\nCard: 4111 1111 1111 1111";

		$this->settings = array( 'redact_secrets' => true );
		$this->assertSame( "Password: [redacted]\nhttps://x.de/wp-login.php?action=rp&key=[redacted]&login=anna\nCard: [redacted]", Redactor::redact( $text ) );

		$this->settings = array( 'redact_secrets' => false );
		$this->assertSame( $text, Redactor::redact( $text ) );
	}
}
