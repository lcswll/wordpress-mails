<?php
/**
 * Context module: relation capture while sending (WooCommerce emails, core user notifications), the
 * one-mail lifetime of a relation and the mirror into the lookup table.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Context\Capture;
use Mailspur\Modules\Context\Store;

require_once dirname( __DIR__ ) . '/stubs/class-context-fakes.php';

final class ContextCaptureTest extends TestCase {

	/** @var Capture */
	private $capture;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				return Store::DB_OPTION === $name ? Store::DB_VERSION : $fallback;
			}
		);
		$this->capture = new Capture( new Store() );
	}

	private function wc_email( string $id, ?object $item, bool $customer = true ): \WC_Email {
		$email           = new \WC_Email();
		$email->id       = $id;
		$email->object   = $item;
		$email->customer = $customer;
		return $email;
	}

	private function order( int $id, int $customer = 0 ): \WC_Order {
		$order           = new \WC_Order( $id );
		$order->customer = $customer;
		return $order;
	}

	/**
	 * Runs one mail through the hooks: wp_mail filter, then the "capture" meta phase.
	 *
	 * @return array<string,mixed>
	 */
	private function send(): array {
		$this->capture->promote( array( 'to' => 'x@example.com' ) );
		return (array) $this->capture->meta( array( 'other' => 1 ), 'capture' );
	}

	public function test_woocommerce_customer_email_links_order_customer_and_email_id(): void {
		$params = array( 'anna@example.com', 'Your order', 'body', '', array() );
		$this->assertSame( $params, $this->capture->woocommerce( $params, $this->wc_email( 'customer_completed_order', $this->order( 123, 45 ) ) ) );

		$meta = $this->send();
		$this->assertSame(
			array(
				'order'    => 123,
				'user'     => 45,
				'wc_email' => 'customer_completed_order',
			),
			$meta['context']
		);
		$this->assertSame( 1, $meta['other'] );
	}

	public function test_relation_is_used_for_one_mail_only(): void {
		$this->capture->woocommerce( array(), $this->wc_email( 'customer_invoice', $this->order( 7 ) ) );
		$this->assertArrayHasKey( 'context', $this->send() );
		$this->assertArrayNotHasKey( 'context', $this->send() );
	}

	public function test_admin_email_links_the_order_but_not_the_customer(): void {
		$this->capture->woocommerce( array(), $this->wc_email( 'new_order', $this->order( 9, 45 ), false ) );
		$this->assertSame(
			array(
				'order'    => 9,
				'wc_email' => 'new_order',
			),
			$this->send()['context']
		);
	}

	public function test_refund_links_its_order_and_new_account_links_the_user(): void {
		$refund         = new \WC_Order_Refund( 99 );
		$refund->parent = 12;
		$this->assertSame(
			array(
				'order'    => 12,
				'wc_email' => 'customer_refunded_order',
			),
			Capture::from_wc_email( $this->wc_email( 'customer_refunded_order', $refund ) )
		);
		$this->assertSame(
			array(
				'user'     => 5,
				'wc_email' => 'customer_new_account',
			),
			Capture::from_wc_email( $this->wc_email( 'customer_new_account', new \WP_User( 5 ) ) )
		);
		$this->assertSame( array( 'wc_email' => 'low_stock' ), Capture::from_wc_email( $this->wc_email( 'low_stock', null ) ) );
		$this->assertSame( array(), Capture::from_wc_email( 'not an email' ) );
	}

	public function test_relation_not_taken_by_an_unrelated_later_mail(): void {
		// WooCommerce's mail callback was replaced, so wp_mail() never ran for its email.
		$this->capture->woocommerce( array(), $this->wc_email( 'customer_note', $this->order( 3 ) ) );
		$this->capture->clear();
		$this->assertArrayNotHasKey( 'context', $this->send() );
	}

	public function test_only_the_capture_phase_is_touched(): void {
		$this->capture->woocommerce( array(), $this->wc_email( 'customer_note', $this->order( 3 ) ) );
		$this->capture->promote( array() );
		$this->assertSame( array(), $this->capture->meta( array(), 'phpmailer' ) );
		$this->assertSame( array(), $this->capture->meta( array(), 'result' ) );
		$this->assertSame( 'x', $this->capture->meta( 'x', 'capture' ) );
	}

	public function test_core_user_notifications(): void {
		$email = array( 'to' => 'u@example.com' );
		$this->assertSame( $email, $this->capture->user_object( $email, new \WP_User( 8 ) ) );
		$this->assertSame( array( 'user' => 8 ), $this->send()['context'] );

		$this->capture->reset_password( $email, 'key', 'login', new \WP_User( 9 ) );
		$this->assertSame( array( 'user' => 9 ), $this->send()['context'] );

		$this->capture->user_array( $email, array( 'ID' => '10' ) );
		$this->assertSame( array( 'user' => 10 ), $this->send()['context'] );

		$this->capture->user_array( $email, array( 'ID' => 0 ) );
		$this->capture->user_object( $email, 'nobody' );
		$this->assertArrayNotHasKey( 'context', $this->send() );
	}

	public function test_logged_mirrors_the_relation_with_one_insert(): void {
		$this->capture->logged(
			17,
			array(
				'created_at' => '2026-10-01 12:00:00',
				'meta'       => '{"context":{"order":123,"user":45,"wc_email":"customer_invoice"}}',
			)
		);
		$this->assertCount( 1, $this->wpdb->prepared );
		$query = $this->wpdb->prepared[0];
		$this->assertStringStartsWith( 'INSERT INTO %i (mail_id,created_at,object_type,object_id) VALUES (%d,%s,%s,%d),(%d,%s,%s,%d)', $query['sql'] );
		$this->assertSame( array( 'wp_mailspur_context', 17, '2026-10-01 12:00:00', 'order', 123, 17, '2026-10-01 12:00:00', 'user', 45 ), $query['args'] );
	}

	public function test_logged_without_relation_costs_nothing(): void {
		$this->capture->logged( 17, array( 'meta' => '{"trace":{"a":1}}' ) );
		$this->capture->logged( 17, array( 'meta' => '{"context":{"wc_email":"low_stock"}}' ) );
		$this->capture->logged( 17, 'broken' );
		$this->assertSame( array(), $this->wpdb->prepared );
	}
}
