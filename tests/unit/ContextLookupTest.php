<?php
/**
 * Context module: lookup queries, merging, the dialog back-links, deep links and resend relations.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Context\Capture;
use Mailspur\Modules\Context\Module;
use Mailspur\Modules\Context\Screens;
use Mailspur\Modules\Context\Store;
use Mailspur\Modules\Context\View;
use Mailspur\Repository;

require_once dirname( __DIR__ ) . '/stubs/class-context-fakes.php';

final class ContextLookupTest extends TestCase {

	/** @var array<string,bool> */
	private $caps = array();

	protected function setUp(): void {
		parent::setUp();
		$this->caps = array(
			'manage_options'   => true,
			'edit_shop_orders' => true,
			'edit_user'        => true,
		);
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'current_user_can'   => function ( $cap ) {
					return ! empty( $this->caps[ $cap ] );
				},
				'add_query_arg'      => static function ( array $args, string $url ) {
					return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
				},
				'admin_url'          => static function ( $path ) {
					return 'https://example.com/wp-admin/' . $path;
				},
				'wp_date'            => static function ( $format, $time ) {
					return gmdate( $format, $time );
				},
				'get_edit_user_link' => static function ( $id ) {
					return 'https://example.com/wp-admin/user-edit.php?user_id=' . $id;
				},
				'wc_get_order'       => function ( $id ) {
					return 123 === $id ? new \WC_Order( 123 ) : false;
				},
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function row( int $id, string $date, string $to ): array {
		return array(
			'id'         => (string) $id,
			'created_at' => $date,
			'status'     => '1',
			'recipients' => $to,
			'subject'    => 'S' . $id,
			'notes'      => '0',
		);
	}

	public function test_insert_skips_empty_relations(): void {
		$store = new Store();
		$this->assertSame( 0, $store->insert( 5, '2026-10-01 12:00:00', array( 'wc_email' => 'x' ) ) );
		$this->assertSame( 0, $store->insert( 0, '2026-10-01 12:00:00', array( 'order' => 3 ) ) );
		$this->assertSame( 0, $store->insert( 5, '2026-10-01 12:00:00', array( 'order' => 'abc' ) ) );
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_for_object_uses_the_index_and_rejects_unknown_types(): void {
		$store = new Store();
		$this->assertSame( array(), $store->for_object( 'post', 3, 10 ) );
		$this->assertSame( array(), $store->for_object( 'order', 0, 10 ) );
		$this->assertSame( array(), $this->wpdb->prepared );

		$this->wpdb->results[] = array( self::row( 1, '2026-10-01 10:00:00', 'a@example.com' ) );
		$this->assertCount( 1, $store->for_object( 'order', 3, 10 ) );
		$query = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'ON l.id = c.mail_id AND l.created_at = c.created_at', $query['sql'] );
		$this->assertSame( array( 'wp_mailspur_context', 'wp_mailspur', 'order', 3, '{"anonymised":%', 10 ), $query['args'] );
	}

	public function test_recipient_matches_are_exact(): void {
		$store                 = new Store();
		$this->wpdb->results[] = array(
			self::row( 3, '2026-10-01 10:00:00', 'Joanna <joanna@example.com>' ),
			self::row( 2, '2026-10-01 09:00:00', 'Anna <ANNA@example.com>, b@example.com' ),
		);
		$rows                  = $store->to_recipient( 'anna@example.com', 10 );
		$this->assertSame( array( '2' ), array_column( $rows, 'id' ) );
		$this->assertSame( 30, end( $this->wpdb->prepared[0]['args'] ), 'over-fetches for the exact filter' );
		$this->assertSame( array(), $store->to_recipient( 'not-an-address', 10 ) );
	}

	public function test_unlinked_near_is_limited_to_the_window_and_unlinked_mails(): void {
		$store                 = new Store();
		$this->wpdb->results[] = array( self::row( 4, '2026-10-02 10:00:00', 'anna@example.com' ) );
		$rows                  = $store->unlinked_near( 'anna@example.com', '2026-09-30 00:00:00', '2026-11-30 00:00:00', 10 );
		$this->assertSame( array( '4' ), array_column( $rows, 'id' ) );
		$query = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'l.created_at BETWEEN %s AND %s', $query['sql'] );
		$this->assertStringContainsString( 'c.mail_id IS NULL', $query['sql'] );
		$this->assertSame( array( 'wp_mailspur', 'wp_mailspur_context', 'order', '2026-09-30 00:00:00', '2026-11-30 00:00:00', '%anna@example.com%' ), array_slice( $query['args'], 0, 6 ) );
	}

	public function test_merge_dedupes_and_sorts_newest_first(): void {
		$merged = Store::merge(
			2,
			array( self::row( 1, '2026-10-01 10:00:00', 'a@x.de' ), self::row( 3, '2026-10-03 10:00:00', 'a@x.de' ) ),
			array( self::row( 3, '2026-10-03 10:00:00', 'a@x.de' ), self::row( 2, '2026-10-02 10:00:00', 'a@x.de' ) )
		);
		$this->assertSame( array( '3', '2' ), array_column( $merged, 'id' ) );
	}

	public function test_order_box_marks_unlinked_mails(): void {
		$order          = new \WC_Order( 123 );
		$order->billing = 'anna@example.com';
		$order->created = new \DateTimeImmutable( '2026-10-01 12:00:00 UTC' );

		$this->wpdb->results[] = array( self::row( 10, '2026-10-01 12:01:00', 'anna@example.com' ) );
		$this->wpdb->results[] = array( self::row( 8, '2026-10-01 11:59:00', 'anna@example.com' ) );
		$data                  = ( new Screens( new Store() ) )->order_mails( $order );

		$this->assertSame( array( '10', '8' ), array_column( $data['rows'], 'id' ) );
		$this->assertSame( array( 8 => true ), $data['loose'] );
		$this->assertSame( 'anna@example.com', $data['email'] );
		$this->assertSame( array( '2026-09-30 12:00:00', '2026-11-30 12:00:00' ), array_slice( $this->wpdb->prepared[1]['args'], 3, 2 ) );
	}

	public function test_dialog_links_order_and_user(): void {
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( 45, 'anna@example.com', 'Anna' ) );
		$item = ( new Module( new Repository() ) )->rest_item(
			array( 'id' => 1 ),
			array(
				'meta'       => '{"context":{"order":123,"user":45,"wc_email":"customer_invoice"}}',
				'recipients' => 'anna@example.com',
			)
		);
		$this->assertSame(
			array(
				'links'    => array(
					array(
						'type'  => 'order',
						'label' => 'Order #123',
						'url'   => 'https://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123',
					),
					array(
						'type'  => 'user',
						'label' => 'User: Anna',
						'url'   => 'https://example.com/wp-admin/user-edit.php?user_id=45',
					),
				),
				'wc_email' => 'customer_invoice',
			),
			$item['context']
		);
	}

	public function test_dialog_links_need_the_capabilities(): void {
		$this->caps['edit_shop_orders'] = false;
		$this->caps['edit_user']        = false;
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( 45, 'anna@example.com', 'Anna' ) );
		$item = ( new Module( new Repository() ) )->rest_item( array(), array( 'meta' => '{"context":{"order":123,"user":45}}' ) );
		$this->assertSame( array(), $item['context']['links'] );
	}

	public function test_dialog_finds_the_user_by_recipient(): void {
		Functions\expect( 'get_user_by' )->twice()->andReturnUsing(
			static function ( $field, $email ) {
				return 'b@example.com' === $email ? new \WP_User( 7, $email, 'Bea' ) : false;
			}
		);
		$item = ( new Module( new Repository() ) )->rest_item( array(), array( 'recipients' => 'a@example.com, Bea <b@example.com>' ) );
		$this->assertSame( 'User: Bea', $item['context']['links'][0]['label'] );
	}

	public function test_clean_keeps_known_keys_only(): void {
		$this->assertSame(
			array(
				'order'    => 5,
				'wc_email' => 'customer_note',
			),
			Module::clean(
				array(
					'order'    => '5',
					'user'     => -1,
					'wc_email' => 'Customer_Note!',
					'x'        => 1,
				)
			)
		);
	}

	public function test_deep_link_filters_to_recipient_and_day(): void {
		$url = View::log_url( self::row( 42, '2026-10-01 23:30:00', 'Anna <anna+shop@example.com>' ) );
		$this->assertStringContainsString( 'page=mailspur-email-log', $url );
		$this->assertStringContainsString( 'mail=42', $url );
		$this->assertStringContainsString( 'after=2026-10-01', $url );
		$this->assertStringContainsString( 'before=2026-10-01', $url );
		$this->assertStringContainsString( 's=anna%252Bshop%2540example.com', $url, 'value is url-encoded, so "+" survives' );
	}

	public function test_resend_keeps_the_relation(): void {
		$this->wpdb->results[] = array( 'meta' => '{"context":{"order":123,"user":45}}' );
		$this->wpdb->results[] = array( 'meta' => '{"context":{"order":123,"user":45}}' );

		$module = new Module( new Repository() );
		Functions\stubs( array( 'is_admin' => false ) );
		$module->register();
		$capture = $this->capture_of( $module );

		$module->before_resend( null, array(), new \WP_REST_Request( 'POST', '/mailspur-email-log/v1/mails/5/resend' ) );
		$capture->promote( array() );
		$this->assertSame(
			array(
				'order' => 123,
				'user'  => 45,
			),
			$capture->meta( array(), 'capture' )['context']
		);

		// To other addresses: not "to this user" any more.
		$module->before_resend( null, array(), new \WP_REST_Request( 'POST', '/mailspur-email-log/v1/mails/5/resend', array( 'to' => array( 'x@example.com' ) ) ) );
		$capture->promote( array() );
		$this->assertSame( array( 'order' => 123 ), $capture->meta( array(), 'capture' )['context'] );

		// Other routes are ignored.
		$module->before_resend( null, array(), new \WP_REST_Request( 'POST', '/mailspur-email-log/v1/mails/5' ) );
		$capture->promote( array() );
		$this->assertSame( array(), $capture->meta( array(), 'capture' ) );
	}

	private function capture_of( Module $module ): Capture {
		$property = new \ReflectionProperty( Module::class, 'capture' );
		$property->setAccessible( true );
		$capture = $property->getValue( $module );
		$this->assertInstanceOf( Capture::class, $capture );
		return $capture;
	}
}
