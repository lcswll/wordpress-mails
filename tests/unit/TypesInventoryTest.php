<?php
/**
 * Email inventory: data categories found in an email's text, recipient groups, retention text, CSV (formula
 * injection, BOM, columns) and the printable page (escaping, no contents).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Inventory;

final class TypesInventoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n ) {
					return (string) $n;
				},
				'determine_locale'   => 'de_DE',
				'admin_url'          => static function ( $path = '' ) {
					return 'https://site.example/wp-admin/' . $path;
				},
				'add_query_arg'      => static function ( array $args, string $url ) {
					return $url . '?' . http_build_query( $args );
				},
			)
		);
	}

	public function test_email_address_is_always_a_category(): void {
		$this->assertSame( array( 'email' ), Inventory::detect( "Welcome\nThanks for signing up. Have a nice day." ) );
	}

	public function test_detects_postal_address_phone_order_data_and_free_text(): void {
		$order = "Your order #1042 has been received\nOrder number: 1042\nSubtotal: 39,90 €\nTotal: €44.80\n"
			. "Billing address\nAnna Example\nHauptstraße 12\n10115 Berlin\nPhone: +49 30 1234567";
		$this->assertSame( array( 'email', 'postal', 'phone', 'order' ), Inventory::detect( $order ) );

		$form = "New message from your website\nName: Ben\nEmail: ben@example.org\nMessage: Do you ship to Austria?";
		$this->assertSame( array( 'email', 'freetext' ), Inventory::detect( $form ) );

		$this->assertSame( array( 'email', 'postal' ), Inventory::detect( "Ship to\n221B Baker Street\nLondon NW1 6XE" ) );
		$this->assertSame( array( 'email', 'phone' ), Inventory::detect( 'Call us: <a href="tel:+4930123">us</a>' ) );
		$this->assertSame( array( 'email', 'phone' ), Inventory::detect( 'Telefon: 030 / 123 456 78' ) );
		// Free-form types (contact forms) count as free text even without a label.
		$this->assertSame( array( 'email', 'freetext' ), Inventory::detect( 'Hello, I would like to know more.', true ) );
	}

	public function test_numbers_alone_are_no_personal_data(): void {
		$this->assertSame( array( 'email' ), Inventory::detect( "Your code is 482913.\nIt expires in 15 minutes. Version 6.8.3 was installed on 2026-10-01." ) );
	}

	public function test_category_and_group_labels(): void {
		$this->assertSame( 'Email address, Order data', Inventory::categories_text( array( 'order', 'email' ) ) );
		$this->assertStringContainsString( 'no longer in the log', Inventory::categories_text( null ) );

		$this->assertSame( 'Unknown', Inventory::groups_text( array() ) );
		$this->assertSame(
			'Other recipients (e.g. guests), Registered users, Administrators',
			Inventory::groups_text(
				array(
					'admin'    => 1,
					'user'     => 4,
					'external' => 9,
				)
			)
		);
		$this->assertSame( 'Administrators', Inventory::groups_text( array( 'admin' => 3 ) ) );
	}

	public function test_classify_recipients_without_keeping_addresses(): void {
		$lookup = static function ( string $address ): string {
			$known = array(
				'admin@shop.example' => 'admin',
				'anna@example.com'   => 'user',
			);
			return $known[ $address ] ?? 'external';
		};
		$this->assertSame( array( 'user', 'external' ), Inventory::classify( 'Anna <ANNA@example.com>, guest@example.net, other@example.net', $lookup ) );
		$this->assertSame( array( 'admin' ), Inventory::classify( 'admin@shop.example', $lookup ) );
		$this->assertSame( array(), Inventory::classify( 'undisclosed-recipients', $lookup ) );
	}

	public function test_retention_text(): void {
		$this->assertSame( 'Log entries deleted after 90 days', Inventory::retention( 90, 0 ) );
		$this->assertSame( 'Content anonymised after 30 days, deleted after 90 days', Inventory::retention( 90, 30 ) );
		$this->assertSame( 'Log entries deleted after 30 days', Inventory::retention( 30, 60 ) );
		$this->assertSame( 'Log entries kept until deleted manually', Inventory::retention( 0, 0 ) );
	}

	/**
	 * @return array<int,array<string,string|int>>
	 */
	private function rows(): array {
		return array(
			array(
				'type'           => '=HYPERLINK("https://evil.example","Click")',
				'sender'         => '+WooCommerce',
				'recipients'     => 'Registered users',
				'rhythm'         => 'Daily',
				'last_sent'      => '3 October 2026',
				'emails_30_days' => 42,
				'retention'      => 'Log entries deleted after 90 days',
				'data'           => 'Email address, "Order data"',
			),
		);
	}

	public function test_csv_is_formula_injection_safe(): void {
		$csv   = Inventory::csv( $this->rows() );
		$lines = explode( "\r\n", $csv );

		$this->assertStringStartsWith( "\xEF\xBB\xBF\"Email type\",\"Sent by\",\"Recipients\"", $csv );
		$this->assertCount( 3, $lines ); // Header, one row, trailing empty string.
		$this->assertSame(
			'"\'=HYPERLINK(""https://evil.example"",""Click"")","\'+WooCommerce","Registered users","Daily","3 October 2026",42,"Log entries deleted after 90 days","Email address, ""Order data"""',
			$lines[1]
		);
	}

	public function test_printable_page_escapes_everything(): void {
		$rows            = $this->rows();
		$rows[0]['type'] = '<img src=x onerror=alert(1)>';
		$html            = Inventory::html( $rows, 'Shop <b>', '5 October 2026', 'https://site.example/wp-admin/admin-post.php?action=mailspur_types_inventory&format=csv' );

		$this->assertStringContainsString( '<html lang="de-DE">', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( 'Shop &lt;b&gt; · as of 5 October 2026', $html );
		$this->assertStringContainsString( 'Download as CSV', $html );
		$this->assertStringContainsString( '<th scope="col">Personal data</th>', $html );
		$this->assertStringContainsString( 'No emails logged yet', Inventory::html( array(), 'Shop', 'today', '' ) );
	}
}
