<?php
/**
 * Email types: subject placeholders, merging names into patterns, keeping distinct wording apart.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Mailspur\Modules\Types\Fingerprint;

final class TypesFingerprintTest extends TestCase {

	private function pattern( string $subject ): string {
		return implode( ' ', Fingerprint::tokens( $subject ) );
	}

	/**
	 * @param string ...$subjects
	 * @return string|null Pattern after merging all subjects, null when one does not fit.
	 */
	private function merged( string ...$subjects ): ?string {
		$pattern = Fingerprint::tokens( (string) array_shift( $subjects ) );
		foreach ( $subjects as $subject ) {
			$pattern = Fingerprint::merge( $pattern, Fingerprint::tokens( $subject ) );
			if ( null === $pattern ) {
				return null;
			}
		}
		return implode( ' ', $pattern );
	}

	public function test_placeholders(): void {
		$this->assertSame( '[Shop]: New order #{#}', $this->pattern( '[Shop]: New order #10452' ) );
		$this->assertSame( 'Invoice {#}-{#} – total {#} EUR', $this->pattern( 'Invoice 2026-17 – total 1.249,90 EUR' ) );
		$this->assertSame( 'Booking for {date} at {time}', $this->pattern( 'Booking for 12.10.2026 at 14:30' ) );
		$this->assertSame( 'New message from {email}', $this->pattern( 'New message from anna@example.com' ) );
		$this->assertSame( 'Shop {text}', $this->pattern( 'Shop "Question about my order"' ) );
		$this->assertSame( 'Shop {text}', $this->pattern( 'Shop „Frage zur Bestellung“' ) );
		$this->assertSame( 'Your code {id}', $this->pattern( 'Your code a8f3k29dk3x9' ) );
		$this->assertSame( 'See {url}', $this->pattern( 'See https://example.com/x?y=1' ) );
		$this->assertSame( array(), Fingerprint::tokens( '   ' ) );
	}

	public function test_numbers_alone_make_one_type(): void {
		$this->assertSame( $this->pattern( 'Order #1001 received' ), $this->pattern( 'Order #2002 received' ) );
	}

	public function test_names_merge(): void {
		$this->assertSame( 'Welcome to the shop, {…}!', $this->merged( 'Welcome to the shop, Anna!', 'Welcome to the shop, Ben!' ) );
		$this->assertSame( 'Hi {…}, your order #{#} has shipped', $this->merged( 'Hi Anna, your order #12 has shipped', 'Hi Ben, your order #13 has shipped', 'Hi Émile, your order #14 has shipped' ) );
	}

	public function test_wording_does_not_merge(): void {
		$this->assertNull( $this->merged( 'Your order is complete', 'Your order is cancelled' ) );
		$this->assertNull( $this->merged( 'Password changed', 'Email changed' ) );
		// Title case: capitals carry no meaning.
		$this->assertNull( $this->merged( 'New Order Received Today', 'New Order Cancelled Today' ) );
		// The first word is wording, not a name.
		$this->assertNull( $this->merged( 'Anna sent you a message', 'Ben sent you a message' ) );
		// Different length never merges.
		$this->assertNull( $this->merged( 'Hi Anna', 'Hi Anna Maria' ) );
	}

	public function test_names_never_swallow_the_sentence(): void {
		// Second name would make 2 of 4 words variable (> a third).
		$this->assertNull( $this->merged( 'Message from Anna Smith', 'Message from Ben Jones' ) );
		// Two-word subjects: one variable word is fine, one literal remains.
		$this->assertSame( 'Hello {…}', $this->merged( 'Hello Anna', 'Hello Ben' ) );
	}

	public function test_case_insensitive_exact_match_keeps_pattern(): void {
		$pattern = Fingerprint::tokens( 'Your Account' );
		$this->assertSame( $pattern, Fingerprint::merge( $pattern, Fingerprint::tokens( 'your account' ) ) );
	}

	public function test_search_term_is_longest_literal_run(): void {
		$this->assertSame( '[Shop]: New order', Fingerprint::search_term( Fingerprint::tokens( '[Shop]: New order #10452' ) ) );
		$this->assertSame( 'your order', Fingerprint::search_term( array( 'Hi', '{…},', 'your', 'order', '#{#}' ) ) );
		$this->assertSame( '', Fingerprint::search_term( array( '{#}' ) ) );
	}
}
