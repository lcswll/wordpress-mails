<?php
/**
 * Email types, before/after: body normalisation, change detection per type and the line diff.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Mailspur\Modules\Types\Content;
use Mailspur\Modules\Types\Diff;
use Mailspur\Modules\Types\Report;

final class TypesContentTest extends TestCase {

	private function order( string $name, int $number, string $total = '€68.00', bool $link = true ): string {
		return '<html><head><style>p{color:red}</style><title>Order</title></head><body>'
			. '<h1>Thanks for your order</h1><p>Hi ' . $name . ',</p>'
			. '<p>we have received your order <b>#' . $number . '</b> on ' . ( 10 + $number % 18 ) . '.10.2026 and will ship it within 24 hours.</p>'
			. '<table><tr><td>Coaching session (60 min)</td><td>' . $total . '</td></tr><tr><td>Total</td><td>' . $total . '</td></tr></table>'
			. ( $link ? '<p><a href="https://shop.example/my-account/view-order/' . $number . '/?key=wc_order_' . md5( (string) $number ) . '">View order</a></p>' : '' )
			. '<p>Questions? Write to support@shop.example &amp; we answer.</p><!-- tracking ' . $number . ' -->'
			. '</body></html>';
	}

	public function test_lines_strip_markup_and_keep_link_targets_without_query(): void {
		$lines = Content::lines( $this->order( 'Anna', 1001 ), true );
		$this->assertSame( 'Thanks for your order', $lines[0] );
		$this->assertSame( 'Hi Anna,', $lines[1] );
		$this->assertContains( 'View order [https://shop.example/my-account/view-order/1001/]', $lines );
		$this->assertContains( 'Questions? Write to support@shop.example & we answer.', $lines );
		$this->assertNotContains( 'p{color:red}', $lines, 'Styles are no content.' );
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( 'wc_order_', $line, 'Query strings (tokens) are dropped.' );
		}
	}

	public function test_plain_text_bodies_keep_their_lines(): void {
		$this->assertSame( array( 'Hello,', 'line two' ), Content::lines( "Hello,\r\n\r\n  line   two  \n", false ) );
	}

	public function test_normalise_replaces_values_and_names(): void {
		$this->assertSame( 'we have received your order #{#} on {date} and will ship it within {#} hours.', Content::normalise( 'we have received your order #1001 on 12.10.2026 and will ship it within 24 hours.' ) );
		$this->assertSame( 'questions? write to {email} & we answer.', Content::normalise( 'Questions? Write to support@shop.example & we answer.' ) );
		$this->assertSame( 'view order [https://shop.example/my-account/view-order/{#}/]', Content::normalise( 'View order [https://shop.example/my-account/view-order/1001/]' ) );
		$this->assertSame( 'your booking with {…} at {time}', Content::normalise( 'Your booking with Anna Smith at 14:30' ) );
		$this->assertSame( 'done. see you {…}.', Content::normalise( 'Done. See you Monday.' ), 'Capitals after a full stop start a sentence.' );
		// A greeting's wording and name are no structure; a salutation with an empty placeholder is a different line.
		$this->assertSame( Content::normalise( 'Hi Anna,' ), Content::normalise( 'Hello Ben,' ) );
		$this->assertSame( Content::normalise( 'Hi Anna,' ), Content::normalise( 'Dear customer,' ) );
		$this->assertNotSame( Content::normalise( 'Thanks for your order' ), Content::normalise( 'Thanks for your booking' ) );
	}

	public function test_hash_is_stable_across_customers_but_not_across_template_changes(): void {
		$base = Content::hash( $this->order( 'Anna', 1001 ), true );
		$this->assertSame( 12, strlen( $base ) );
		$this->assertSame( $base, Content::hash( $this->order( 'Ben', 2002, '€1,249.90' ), true ), 'Names, numbers, amounts, dates and tokens do not count.' );
		$this->assertNotSame( $base, Content::hash( $this->order( 'Anna', 1001, '€68.00', false ), true ), 'A missing link is a change.' );
		$this->assertNotSame( $base, Content::hash( str_replace( 'Hi Anna,', 'Hi ,', $this->order( 'Anna', 1001 ) ), true ), 'An empty name in the greeting is a change.' );
		$this->assertNotSame( $base, Content::hash( str_replace( 'Hi Anna,', 'Hi {first_name},', $this->order( 'Anna', 1001 ) ), true ), 'An unreplaced merge tag is a change.' );
		$this->assertSame( '', Content::hash( '', true ), 'Empty (anonymised) bodies are not compared.' );
	}

	public function test_is_html(): void {
		$this->assertTrue( Content::is_html( 'text/html', 'plain' ) );
		$this->assertFalse( Content::is_html( 'text/plain', '<p>x</p>' ) );
		$this->assertTrue( Content::is_html( '', '<p>x</p>' ) );
		$this->assertFalse( Content::is_html( '', 'Hello' ) );
	}

	/**
	 * @param string[] $hashes
	 * @return array<string,mixed>
	 */
	private function feed( array $hashes, array $state = array(), int $first_id = 1 ): array {
		foreach ( $hashes as $i => $hash ) {
			$state = Content::observe( $state, $hash, $first_id + $i, 1000 + ( $first_id + $i ) * 100 );
		}
		return $state;
	}

	public function test_change_is_detected_and_points_at_both_emails(): void {
		$state = $this->feed( array_fill( 0, 8, 'aaa' ) );
		$this->assertArrayNotHasKey( 'c', $state );

		$state = $this->feed( array( 'bbb', 'bbb' ), $state, 9 );
		$this->assertSame(
			array(
				'before'    => 8,
				'before_at' => 1800,
				'after'     => 9,
				'after_at'  => 1900,
			),
			$state['c']
		);
		$this->assertArrayNotHasKey( 'p', $state );
		$this->assertContains( 'bbb', $state['h'] );
	}

	public function test_one_off_variant_is_learnt_silently(): void {
		$state = $this->feed( array( 'aaa', 'aaa', 'aaa', 'aaa', 'aaa', 'aaa', 'bbb', 'aaa', 'aaa', 'bbb' ) );
		$this->assertArrayNotHasKey( 'c', $state, 'A variant followed by the known structure is no change.' );
		$this->assertContains( 'bbb', $state['h'] );
	}

	public function test_variants_seen_while_learning_are_known(): void {
		// Every 10th email is a different variant (e.g. another payment method).
		$hashes = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$hashes[] = 0 === $i % 10 ? 'variant' : 'main';
		}
		$this->assertArrayNotHasKey( 'c', $this->feed( $hashes ) );
	}

	public function test_free_form_types_never_report_changes(): void {
		$state = $this->feed( array( 'a1', 'a2', 'a3', 'a4', 'a5' ) );
		$this->assertTrue( $state['var'] );
		$state = $this->feed( array( 'b1', 'b2' ), $state, 6 );
		$this->assertArrayNotHasKey( 'c', $state );

		// Becomes free-form later: many unseen structures.
		$state = $this->feed( array( 'x', 'x', 'x', 'x', 'x' ) );
		$state = $this->feed( array( 'y1', 'y2', 'y3', 'y4', 'y5', 'y6', 'y7', 'y8', 'y9', 'y10', 'y11', 'y12' ), $state, 6 );
		$this->assertTrue( $state['var'] );
		$this->assertArrayNotHasKey( 'c', $state );
	}

	public function test_empty_bodies_are_skipped_and_only_the_latest_change_is_kept(): void {
		$state = $this->feed( array( 'a', 'a', 'a', 'a', 'a', '', 'b', 'b', 'a', 'c', 'c' ) );
		$this->assertSame( 10, $state['n'], 'Empty bodies are not counted.' );
		$this->assertSame( 10, $state['c']['after'], 'The latest change wins.' );
		$this->assertSame( 9, $state['c']['before'] );
	}

	public function test_change_names_updates_in_between_and_respects_seen(): void {
		$extra   = array(
			'content' => array(
				'c' => array(
					'before'    => 8,
					'before_at' => 1000,
					'after'     => 9,
					'after_at'  => 5000,
				),
			),
		);
		$updates = array(
			array(
				'time'  => 900,
				'label' => 'Old 1.0',
				'slug'  => 'plugin:old',
			),
			array(
				'time'  => 3000,
				'label' => 'WooCommerce 9.4',
				'slug'  => 'plugin:woocommerce',
			),
			array(
				'time'  => 6000,
				'label' => 'Later 2.0',
				'slug'  => 'plugin:later',
			),
		);
		$change  = Report::change( $extra, $updates, 0 );
		$this->assertNotNull( $change );
		$this->assertSame( array( 'WooCommerce 9.4' ), $change['updates'] );
		$this->assertNull( Report::change( $extra, $updates, 9 ), 'Seen: marker hidden.' );
		$this->assertNotNull( Report::change( $extra, $updates, 7 ), 'Seen an older change: the new one shows.' );
		$extra['content']['var'] = true;
		$this->assertNull( Report::change( $extra, $updates, 0 ) );
	}

	public function test_diff_marks_only_real_changes(): void {
		$before = Content::lines( $this->order( 'Anna', 1001 ), true );
		$after  = Content::lines( $this->order( 'Ben', 2002, '€68.00', false ), true );
		$ops    = Diff::lines( $before, $after );

		$changed = array_values(
			array_filter(
				$ops,
				static function ( array $op ): bool {
					return ' ' !== $op[0];
				}
			)
		);
		$this->assertSame( array( array( '-', 'View order [https://shop.example/my-account/view-order/1001/]' ) ), $changed );
		$this->assertSame( array( ' ', 'Hi Ben,' ), $ops[1], 'Unchanged lines show the newer email.' );
		$this->assertCount( count( $before ), $ops );
	}

	public function test_diff_of_replaced_and_added_lines(): void {
		$ops = Diff::lines( array( 'A one', 'b two', 'c three' ), array( 'A one', 'b changed', 'c three', 'd four' ) );
		$this->assertSame(
			array(
				array( ' ', 'A one' ),
				array( '-', 'b two' ),
				array( '+', 'b changed' ),
				array( ' ', 'c three' ),
				array( '+', 'd four' ),
			),
			$ops
		);
		$this->assertSame( array(), Diff::lines( array(), array() ) );
		$this->assertSame( array( array( '+', 'x' ) ), Diff::lines( array(), array( 'x' ) ) );
	}
}
