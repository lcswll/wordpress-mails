<?php
/**
 * Notes: the static rules – real problems are found, ordinary mails stay quiet.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Modules\Notes\Catalog;
use Mailspur\Modules\Notes\Engine;
use Mailspur\Modules\Notes\Mail;
use Mailspur\Modules\Notes\Rules;

final class NotesRulesTest extends TestCase {

	const SITE = array(
		'home'        => 'shop.example.de',
		'environment' => 'production',
		'multisite'   => false,
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Catalog::reset();
	}

	/**
	 * A clean HTML order mail from the site's own domain.
	 *
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'recipients'   => 'Anna <anna@gmail.com>',
				'subject'      => 'Your order #1234 has been received',
				'message'      => '<html><body><h1>Thanks, Anna!</h1><p>We received your order <b>#1234</b>.</p>'
					. '<p><a href="https://shop.example.de/my-account/view-order/1234/">View order</a></p>'
					. '<img src="https://shop.example.de/logo.png" alt="Example Shop">'
					. '<img src="https://shop.example.de/pixel.gif" width="1" height="1">'
					. '<p>Example Shop · <a href="https://shop.example.de">shop.example.de</a></p></body></html>',
				'headers'      => "Content-Type: text/html; charset=UTF-8\nFrom: Example Shop <shop@example.de>",
				'content_type' => 'text/html',
				'sender'       => 'Example Shop <shop@example.de>',
				'source'       => 'plugin:woocommerce',
				'meta'         => array(),
			),
			$overrides
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $site
	 * @return string[] Codes found.
	 */
	private function codes( array $row, array $site = self::SITE ): array {
		return array_column( Engine::analyze( $row, $site + self::SITE ), 'code' );
	}

	public function test_clean_mail_has_no_notes(): void {
		$this->assertSame( array(), $this->codes( $this->row() ) );
	}

	public function test_default_wordpress_plain_text_mail_has_no_notes(): void {
		$row = $this->row(
			array(
				'subject'      => '[Example Shop] Password Reset',
				'message'      => "Someone has requested a password reset for the following account:\n\nSite Name: Example Shop\n\nUsername: anna\n\nTo reset your password, visit the following address:\n\nhttps://shop.example.de/wp-login.php?action=rp&key=[redacted]&login=anna\n\nThis password reset request originated from the IP address 203.0.113.5.\n",
				'headers'      => '',
				'content_type' => 'text/plain',
				'sender'       => 'Example Shop <wordpress@shop.example.de>',
				'source'       => 'core',
			)
		);
		$this->assertSame( array(), $this->codes( $row ) );
	}

	public function test_html_in_a_plain_text_mail(): void {
		$row = $this->row(
			array(
				'content_type' => 'text/plain',
				'headers'      => '',
			)
		);
		$this->assertContains( 'html_in_plain', $this->codes( $row ) );

		// Angle-bracketed links of WordPress' plain mails are not HTML.
		$row['message'] = "Visit <https://shop.example.de/wp-login.php> to log in.\n<3 Example Shop";
		$this->assertNotContains( 'html_in_plain', $this->codes( $row ) );
	}

	public function test_unknown_content_type_is_sniffed_and_never_flagged_as_plain(): void {
		$mail = new Mail(
			$this->row(
				array(
					'content_type' => '',
					'headers'      => '',
				)
			),
			self::SITE
		);
		$this->assertTrue( $mail->is_html );
		$this->assertSame( array(), Rules::html_in_plain( $mail ) );
	}

	public function test_gmail_clips_large_html(): void {
		$row   = $this->row( array( 'message' => '<p>' . str_repeat( 'x', 110 * 1024 ) . '</p>' ) );
		$notes = Engine::analyze( $row, self::SITE );
		$this->assertSame( 'gmail_clip', $notes[0]['code'] );
		$this->assertSame( array( '111' ), $notes[0]['params'] ); // 110 KB + "<p></p>", rounded up.

		$this->assertNotContains( 'gmail_clip', $this->codes( $this->row( array( 'message' => '<p>' . str_repeat( 'x', 90 * 1024 ) . '</p>' ) ) ) );
	}

	public function test_scanned_body_is_capped(): void {
		$mail = new Mail( $this->row( array( 'message' => str_repeat( 'a', Mail::SCAN_BYTES + 5000 ) ) ), self::SITE );
		$this->assertSame( Mail::SCAN_BYTES, strlen( $mail->body ) );
		$this->assertSame( Mail::SCAN_BYTES + 5000, $mail->size );
	}

	public function test_relative_links_and_images(): void {
		$row   = $this->row( array( 'message' => '<p><a href="/my-account/">Account</a> <img src="/wp-content/uploads/logo.png" alt="Logo"> <a href="//cdn.example.de/x">cdn</a> <a href="#top">top</a></p>' ) );
		$notes = Engine::analyze( $row, self::SITE );
		$this->assertSame( 'relative_urls', $notes[0]['code'] );
		$this->assertSame( array( '2', '/my-account/' ), $notes[0]['params'] );

		// Protocol-relative URLs, anchors and mailto: are fine.
		$this->assertNotContains( 'relative_urls', $this->codes( $this->row( array( 'message' => '<a href="//cdn.example.de/x">a</a><a href="#top">b</a><a href="mailto:a@b.de">c</a>' ) ) ) );
	}

	public function test_dev_urls_only_on_production_and_not_for_the_own_host(): void {
		$row = $this->row( array( 'message' => '<p><a href="http://localhost:8080/my-account/">Account</a></p>' ) );
		$this->assertContains( 'dev_url', $this->codes( $row ) );
		$this->assertNotContains( 'dev_url', $this->codes( $row, array( 'environment' => 'staging' ) ) );

		// A local site that sends links to itself is not a problem.
		$this->assertNotContains( 'dev_url', $this->codes( $row, array( 'home' => 'localhost' ) ) );
		$this->assertContains( 'dev_url', $this->codes( $this->row( array( 'message' => 'Shop: https://shop.test/cart' ) ) ) );
	}

	public function test_links_to_a_related_wordpress_host(): void {
		$staging = $this->row( array( 'message' => '<img src="https://shop.example.de/wp-content/uploads/logo.png" alt="Logo">' ) );
		$this->assertContains( 'foreign_wp_host', $this->codes( $staging, array( 'home' => 'staging.shop.example.de' ) ) );
		$this->assertContains( 'foreign_wp_host', $this->codes( $staging, array( 'home' => 'shop-example-staging.kinsta.cloud' ) ) );

		// Unrelated WordPress sites (a partner's logo) and subdomain multisites are fine.
		$partner = $this->row( array( 'message' => '<img src="https://partner.de/wp-content/uploads/logo.png" alt="Partner">' ) );
		$this->assertNotContains( 'foreign_wp_host', $this->codes( $partner, array( 'home' => 'staging.shop.example.de' ) ) );
		$this->assertNotContains(
			'foreign_wp_host',
			$this->codes(
				$staging,
				array(
					'home'      => 'blog.shop.example.de',
					'multisite' => true,
				)
			)
		);
		// Non-WordPress paths on the main domain are fine as well.
		$this->assertNotContains( 'foreign_wp_host', $this->codes( $this->row(), array( 'home' => 'staging.shop.example.de' ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function placeholder_cases(): array {
		return array(
			'mustache'           => array( 'Hello {{ first_name }},', true ),
			'single brace'       => array( 'Hello {first_name},', true ),
			'printf'             => array( 'Your order %s has shipped', true ),
			'positional printf'  => array( 'Order %1$s for %2$s', true ),
			'shortcode token'    => array( 'Order [order_number] received', true ),
			'shortcode attrs'    => array( '[contact-form-7 id="12" title="Contact"]', true ),
			'site name brackets' => array( '[Example Shop] New order', false ),
			'footnote'           => array( 'See note [1] and [a]', false ),
			'percent'            => array( 'Save 20% on 50%s and 100%d', false ),
			'url encoding'       => array( 'https://shop.example.de/?q=a%3Db%2Fc%5D', false ),
			'json-ish'           => array( 'Code: {"a":1}', false ),
		);
	}

	/**
	 * @dataProvider placeholder_cases
	 */
	public function test_placeholders( string $text, bool $expected ): void {
		$row = $this->row(
			array(
				'message'      => $text,
				'content_type' => 'text/plain',
				'headers'      => '',
			)
		);
		$this->assertSame( $expected, in_array( 'placeholder', $this->codes( $row ), true ) );
	}

	public function test_placeholders_ignore_css_and_list_tokens(): void {
		$row   = $this->row( array( 'message' => '<style>p{color:red} .x{margin:0}</style><p>Hi {first_name}, order %s</p>' ) );
		$notes = Engine::analyze( $row, self::SITE );
		$this->assertSame( 'placeholder', $notes[0]['code'] );
		$this->assertSame( array( '{first_name}, %s' ), $notes[0]['params'] );
	}

	public function test_mojibake(): void {
		$this->assertContains( 'mojibake', $this->codes( $this->row( array( 'subject' => 'GrÃ¼ÃŸe aus MÃ¼nchen' ) ) ) );
		$this->assertContains( 'mojibake', $this->codes( $this->row( array( 'message' => '<p>Don’t worry – it’s fine. Itâ€™s broken.</p>' ) ) ) );
		$this->assertNotContains( 'mojibake', $this->codes( $this->row( array( 'subject' => 'Grüße aus München – „Bestellung“' ) ) ) );
	}

	public function test_sender_problems(): void {
		$this->assertContains( 'from_localhost', $this->codes( $this->row( array( 'sender' => 'WordPress <wordpress@localhost>' ) ) ) );
		$this->assertContains( 'from_localhost', $this->codes( $this->row( array( 'sender' => 'wordpress@127.0.0.1' ) ) ) );
		$this->assertContains( 'from_free_mailer', $this->codes( $this->row( array( 'sender' => 'Shop <myshop@gmail.com>' ) ) ) );
		$this->assertContains( 'from_domain_mismatch', $this->codes( $this->row( array( 'sender' => 'news@other-brand.com' ) ) ) );
		// Subdomain / parent domain of the site is fine.
		$this->assertSame( array(), $this->codes( $this->row( array( 'sender' => 'noreply@example.de' ) ) ) );
		$this->assertSame( array(), $this->codes( $this->row( array( 'sender' => 'noreply@mail.shop.example.de' ) ) ) );
		// A test site domain gives no mismatch noise.
		$this->assertSame( array(), $this->codes( $this->row( array( 'sender' => 'news@other-brand.com' ) ), array( 'home' => '127.0.0.1' ) ) );

		$no_from = $this->row(
			array(
				'sender'  => '',
				'headers' => 'Content-Type: text/html',
			)
		);
		$this->assertSame( array( 'no_from' ), $this->codes( $no_from ) );
	}

	public function test_from_header_is_used_when_no_sender_was_recorded(): void {
		$row = $this->row(
			array(
				'sender'  => '',
				'headers' => "From: Shop <shop@gmx.de>\nContent-Type: text/html",
			)
		);
		$this->assertSame( array( 'from_free_mailer' ), $this->codes( $row ) );
	}

	public function test_bulk_without_list_unsubscribe(): void {
		$bcc = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$bcc[] = "user{$i}@example.org";
		}
		$row = $this->row( array( 'headers' => "Content-Type: text/html\nBcc: " . implode( ', ', $bcc ) ) );
		$this->assertContains( 'bulk_no_unsubscribe', $this->codes( $row ) );

		$row['headers'] .= "\nList-Unsubscribe: <https://shop.example.de/unsubscribe>";
		$this->assertNotContains( 'bulk_no_unsubscribe', $this->codes( $row ) );

		$this->assertContains( 'bulk_no_unsubscribe', $this->codes( $this->row( array( 'source' => 'plugin:mailpoet' ) ) ) );
		// A normal mail with a few Cc recipients is not bulk.
		$this->assertNotContains( 'bulk_no_unsubscribe', $this->codes( $this->row( array( 'headers' => 'Cc: a@example.org, b@example.org' ) ) ) );
	}

	public function test_recipient_typos(): void {
		$notes = Engine::analyze( $this->row( array( 'recipients' => 'anna@gmial.com, Bob <bob@web.dee>, carl@gmx.de' ) ), self::SITE );
		$this->assertSame( array( 'recipient_typo', 'recipient_typo' ), array_column( $notes, 'code' ) );
		$this->assertSame( array( 'gmial.com', 'gmail.com' ), $notes[0]['params'] );
		$this->assertSame( array( 'web.dee', 'web.de' ), $notes[1]['params'] );

		// Cc/Bcc recipients count too.
		$this->assertContains( 'recipient_typo', $this->codes( $this->row( array( 'headers' => 'Bcc: x@hotmial.com' ) ) ) );
	}

	public function test_link_text_with_a_different_domain(): void {
		$row   = $this->row( array( 'message' => '<p><a href="https://evil.example.net/login">https://www.paypal.com/signin</a></p>' ) );
		$notes = Engine::analyze( $row, self::SITE );
		$this->assertSame( 'link_mismatch', $notes[0]['code'] );
		$this->assertSame( array( 'paypal.com', 'evil.example.net' ), $notes[0]['params'] );

		// Same site (www/subdomain), descriptive text and "Node.js"-like words are fine.
		$ok = '<a href="https://shop.example.de/x">www.shop.example.de</a><a href="https://example.de">View order</a><a href="https://nodejs.org">Node.js</a>';
		$this->assertNotContains( 'link_mismatch', $this->codes( $this->row( array( 'message' => $ok ) ) ) );
	}

	public function test_subject_checks(): void {
		$this->assertSame( array( 'subject_empty' ), $this->codes( $this->row( array( 'subject' => '  ' ) ) ) );
		$this->assertSame( array( 'subject_long' ), $this->codes( $this->row( array( 'subject' => str_repeat( 'ä', 101 ) ) ) ) );
		$this->assertSame( array(), $this->codes( $this->row( array( 'subject' => str_repeat( 'ä', 100 ) ) ) ) );
	}

	public function test_images_without_alt_but_not_tracking_pixels(): void {
		$row   = $this->row( array( 'message' => '<img src="https://shop.example.de/a.png"><img src="https://shop.example.de/b.png" alt=""><img src="https://t.example/p.gif" width="1" height="1">' ) );
		$notes = Engine::analyze( $row, self::SITE );
		$this->assertSame( array( 'img_no_alt' ), array_column( $notes, 'code' ) );
		$this->assertSame( array( '1' ), $notes[0]['params'] );
		$this->assertSame( 'info', $notes[0]['severity'] );
	}

	public function test_notes_are_sorted_by_severity_and_ignored_codes_dropped(): void {
		$row   = $this->row(
			array(
				'subject'    => str_repeat( 'x', 120 ),
				'recipients' => 'a@gmial.com',
				'message'    => '<p>Hi {name}</p>',
			)
		);
		$notes = Engine::analyze( $row, self::SITE );
		$this->assertSame( array( 'error', 'warning', 'info' ), array_column( $notes, 'severity' ) );

		$this->assertSame( array( 'placeholder', 'subject_long' ), array_column( Engine::analyze( $row, self::SITE, array( 'recipient_typo' ) ), 'code' ) );
	}

	public function test_custom_rules_via_filter_and_failing_rules_are_skipped(): void {
		Filters\expectApplied( 'mailspur_note_rules' )->andReturnUsing(
			static function ( array $rules ): array {
				$rules['lorem']        = static function ( Mail $mail ): array {
					return false !== strpos( $mail->body, 'Lorem' ) ? array( Rules::note( 'my_lorem', 'warning', array( 'x' ) ) ) : array();
				};
				$rules['broken']       = static function (): array {
					throw new \RuntimeException( 'boom' );
				};
				$rules['garbage']      = static function (): array {
					return array(
						'nope',
						array(
							'code'     => 'Bad Code!',
							'severity' => 'error',
						),
						array(
							'code'     => 'x',
							'severity' => 'fatal',
						),
					);
				};
				$rules['not_callable'] = 'no_such_function_here';
				return $rules;
			}
		);
		$notes = Engine::analyze( $this->row( array( 'message' => '<p>Lorem ipsum</p>' ) ), self::SITE );
		$this->assertSame( array( 'my_lorem' ), array_column( $notes, 'code' ) );
	}

	public function test_prepare_validates_stored_notes(): void {
		$notes = Engine::prepare(
			array(
				array(
					'code'     => 'placeholder',
					'severity' => 'warning',
					'params'   => array( str_repeat( 'x', 300 ), array( 'nested' ), 3 ),
				),
				array(
					'code'     => 'placeholder',
					'severity' => 'warning',
					'params'   => array( str_repeat( 'x', 300 ), array( 'nested' ), 3 ),
				),
				'garbage',
			)
		);
		$this->assertCount( 1, $notes );
		$this->assertSame( 200, strlen( $notes[0]['params'][0] ) );
		$this->assertSame( '3', $notes[0]['params'][1] );
		$this->assertSame( array(), Engine::prepare( 'not an array' ) );
	}

	public function test_every_built_in_code_has_texts(): void {
		$texts = Catalog::texts();
		foreach ( array( 'html_in_plain', 'gmail_clip', 'relative_urls', 'dev_url', 'foreign_wp_host', 'placeholder', 'mojibake', 'no_from', 'from_localhost', 'from_free_mailer', 'from_domain_mismatch', 'bulk_no_unsubscribe', 'recipient_typo', 'link_mismatch', 'subject_empty', 'subject_long', 'img_no_alt', 'no_mx', 'null_mx', 'duplicate' ) as $code ) {
			$this->assertArrayHasKey( $code, $texts, $code );
			$this->assertNotSame( '', $texts[ $code ]['text'], $code );
			$this->assertNotSame( '', $texts[ $code ]['fix'], $code );
		}
	}

	public function test_render_fills_params_and_survives_broken_translations(): void {
		$note = Rules::note( 'recipient_typo', 'error', array( 'gmial.com', 'gmail.com' ) );
		$this->assertSame( 'Typo in recipient domain? gmial.com → gmail.com', Catalog::render( $note )['title'] );

		Catalog::reset();
		Filters\expectApplied( 'mailspur_note_texts' )->andReturnUsing(
			static function ( array $texts ): array {
				$texts['recipient_typo']['title'] = '100% %3$s %s %s %9$s';
				return $texts;
			}
		);
		$this->assertSame( '100%  gmial.com gmail.com ', Catalog::render( $note )['title'] );
		$this->assertSame( 'unknown_code', Catalog::render( Rules::note( 'unknown_code', 'info' ) )['title'] );
	}
}
