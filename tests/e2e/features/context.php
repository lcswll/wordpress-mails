<?php
/**
 * Context integration test inside real WordPress (Playground, SQLite).
 *
 * User part for real: the lookup table, core notifications (password reset, email change) linked to the user,
 * "Emails to this user" incl. mails to a former address, exact recipient matching, capability checks and the
 * "User:" back-link in the REST detail.
 *
 * WooCommerce part with a stand-in: WooCommerce is not installed in the e2e Playground (installing and
 * activating it would add a large download and its own mails/tables to every run of every module), so this
 * test declares minimal WC_Order / WC_Email classes and wc_get_order() – unless WooCommerce is active, in
 * which case the order part is skipped with a note. The module only talks to WooCommerce through the
 * woocommerce_mail_callback_params / woocommerce_email_sent hooks and order getters, which is what the
 * stand-in provides. Covered: capture via the real hook, the order box (linked + "same recipient" mails),
 * the "Order #" back-link, resend keeping the order, pruning of deleted mails.
 *
 * Mails are short-circuited on pre_wp_mail (not delivered). Removes everything it created.
 * Writes /e2e-out/features/context.json.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName, Universal.Files.SeparateFunctionsFromOO, Squiz.Commenting
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Mailspur\Modules\Context\Capture;
use Mailspur\Modules\Context\Module;
use Mailspur\Modules\Context\Screens;
use Mailspur\Modules\Context\Store;
use Mailspur\Modules\Context\View;
use Mailspur\Repository;

$context_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function context_check( $ok, $name, $detail = null ) {
	global $context_results;
	$context_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** Newest log row to an address (or with a subject). */
function context_last( $like ) {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM %i WHERE recipients LIKE %s OR subject LIKE %s ORDER BY id DESC LIMIT 1', Repository::table(), '%' . $wpdb->esc_like( $like ) . '%', '%' . $wpdb->esc_like( $like ) . '%' ),
		ARRAY_A
	);
}

function context_meta( $row ) {
	$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
	return is_array( $meta ) ? $meta : array();
}

function context_rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

function context_render( $callback ) {
	ob_start();
	$callback();
	return (string) ob_get_clean();
}

$context_deliver = static function () {
	return true; // Short-circuit: logged as sent, nothing delivered.
};
add_filter( 'pre_wp_mail', $context_deliver, 10 );

$context_users = array();
$context_ids   = array();
$context_grant = static function ( $allcaps ) {
	$allcaps['edit_shop_orders'] = true; // WooCommerce capability (the stand-in shop has no roles).
	return $allcaps;
};

try {
	global $wpdb;
	$store   = new Store();
	$screens = new Screens( $store );
	Store::install();
	$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Store::table() ) ) );
	context_check( 1 === count( $tables ), 'install creates the lookup table', $tables );

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) $admins[0] );

	/* ------------------------------------------------------------------ users */

	$user_id         = wp_insert_user(
		array(
			'user_login'   => 'e2e_ctx_anna',
			'user_email'   => 'anna.ctx@example.com',
			'user_pass'    => wp_generate_password(),
			'display_name' => 'Anna Context',
			'role'         => 'subscriber',
		)
	);
	$context_users[] = $user_id;
	context_check( is_int( $user_id ), 'test user created', $user_id );

	retrieve_password( 'e2e_ctx_anna' );
	$reset = context_last( 'anna.ctx@example.com' );
	context_check( array( 'user' => $user_id ) === ( context_meta( $reset )['context'] ?? null ), 'password reset mail linked to the user', context_meta( $reset ) );
	$context_ids[] = (int) $reset['id'];
	$mapped        = $store->for_object( 'user', (int) $user_id, 10 );
	context_check( array( (string) $reset['id'] ) === array_column( $mapped, 'id' ), 'relation mirrored into the lookup table', $mapped );

	// Plain mails: one to the user (no relation), one to a look-alike address.
	wp_mail( 'Anna <anna.ctx@example.com>', 'E2E context hello', 'x' );
	$hello         = context_last( 'E2E context hello' );
	$context_ids[] = (int) $hello['id'];
	wp_mail( 'joanna.ctx@example.com', 'E2E context look-alike', 'x' );
	$alike         = context_last( 'E2E context look-alike' );
	$context_ids[] = (int) $alike['id'];
	context_check( ! isset( context_meta( $hello )['context'] ), 'unrelated mail gets no relation', context_meta( $hello ) );

	// Email change: the notice goes to the former address and stays with the user.
	wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => 'anna.new.ctx@example.com',
		)
	);
	$change        = context_last( 'anna.ctx@example.com' );
	$context_ids[] = (int) $change['id'];
	context_check( (int) $change['id'] !== (int) $hello['id'] && array( 'user' => $user_id ) === ( context_meta( $change )['context'] ?? null ), 'email change notice linked to the user', array( $change['subject'] ?? null, context_meta( $change ) ) );

	$user  = get_userdata( $user_id );
	$mails = array_column( $screens->user_mails( $user ), 'id' );
	context_check( in_array( (string) $reset['id'], $mails, true ) && in_array( (string) $change['id'], $mails, true ), 'profile list keeps mails to the former address', $mails );
	context_check( ! in_array( (string) $alike['id'], $mails, true ), 'look-alike address not matched', $mails );

	$html = context_render(
		static function () use ( $screens, $user ) {
			$screens->profile( $user );
		}
	);
	context_check( false !== strpos( $html, 'mailspur-ctx-profile' ) && false !== strpos( $html, View::OPEN_ARG . '=' . $reset['id'] ) && false !== strpos( $html, 's=anna.new.ctx%40example.com' ), 'profile section with deep links and "show all"', substr( $html, 0, 600 ) );

	$subscriber      = wp_insert_user(
		array(
			'user_login' => 'e2e_ctx_sub',
			'user_email' => 'sub.ctx@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	$context_users[] = $subscriber;
	wp_set_current_user( $subscriber );
	$html = context_render(
		static function () use ( $screens, $user ) {
			$screens->profile( $user );
		}
	);
	context_check( '' === trim( $html ), 'profile section hidden without the log capability', $html );
	wp_set_current_user( (int) $admins[0] );

	$detail = context_rest( 'GET', '/mailspur-email-log/v1/mails/' . $reset['id'] )->get_data();
	$labels = array_column( $detail['context']['links'] ?? array(), 'label' );
	context_check( in_array( 'User: Anna Context', $labels, true ), 'dialog links the user', $detail['context'] ?? null );

	/* ------------------------------------------------------------- WooCommerce */

	if ( ! function_exists( 'wc_get_order' ) ) {
		// Without WooCommerce: an order relation is ignored quietly, no meta box.
		$item = ( new Module( new Repository() ) )->rest_item( array(), array( 'meta' => '{"context":{"order":5,"wc_email":"customer_invoice"}}' ) );
		context_check( array() === $item['context']['links'] && 'customer_invoice' === $item['context']['wc_email'], 'order relation without WooCommerce: no link, no error', $item );
		context_check( array() === Capture::from_wc_email( null ) && null === Screens::order( 5 ), 'WooCommerce helpers safe without WooCommerce objects' );
	}

	if ( class_exists( 'WooCommerce' ) ) {
		context_check( true, 'order part skipped: WooCommerce is active, the stand-in cannot be declared' );
	} else {
		if ( ! class_exists( 'WC_Abstract_Order' ) ) {
			abstract class WC_Abstract_Order {
				public $id;
				public function __construct( $id ) {
					$this->id = $id;
				}
				public function get_id() {
					return $this->id;
				}
			}
			class WC_Order extends WC_Abstract_Order {
				public $customer = 0;
				public $billing  = '';
				public $created;
				public function get_customer_id() {
					return $this->customer;
				}
				public function get_billing_email() {
					return $this->billing;
				}
				public function get_date_created() {
					return $this->created;
				}
				public function get_order_number() {
					return (string) $this->id;
				}
				public function get_edit_order_url() {
					return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $this->id );
				}
			}
			class WC_Email {
				public $id;
				public $object;
				public function is_customer_email() {
					return 0 === strpos( (string) $this->id, 'customer_' );
				}
			}
			function wc_get_order( $id ) {
				$orders = $GLOBALS['context_orders'] ?? array();
				return $orders[ (int) $id ] ?? false;
			}
		}

		add_filter( 'user_has_cap', $context_grant );

		$order                     = new WC_Order( 990001 );
		$order->customer           = $user_id;
		$order->billing            = 'buyer.ctx@example.com';
		$order->created            = new DateTimeImmutable( '-2 hours' );
		$other                     = new WC_Order( 990002 );
		$GLOBALS['context_orders'] = array(
			990001 => $order,
			990002 => $other,
		);
		$send                      = static function ( $id, $item, $subject ) {
			$email         = new WC_Email();
			$email->id     = $id;
			$email->object = $item;
			$params        = apply_filters( 'woocommerce_mail_callback_params', array( 'buyer.ctx@example.com', $subject, 'x', '', array() ), $email );
			$sent          = wp_mail( ...$params );
			do_action( 'woocommerce_email_sent', $sent, $id, $email );
			return context_last( $subject );
		};

		// An older, unlinked mail to the billing address (e.g. imported) and one belonging to another order.
		wp_mail( 'buyer.ctx@example.com', 'E2E context unlinked', 'x' );
		$unlinked      = context_last( 'E2E context unlinked' );
		$context_ids[] = (int) $unlinked['id'];
		$foreign       = $send( 'customer_processing_order', $other, 'E2E context other order' );
		$context_ids[] = (int) $foreign['id'];

		$invoice       = $send( 'customer_invoice', $order, 'E2E context invoice' );
		$context_ids[] = (int) $invoice['id'];
		context_check(
			array(
				'order'    => 990001,
				'user'     => $user_id,
				'wc_email' => 'customer_invoice',
			) === ( context_meta( $invoice )['context'] ?? null ),
			'WooCommerce email linked via woocommerce_mail_callback_params',
			context_meta( $invoice )
		);
		$admin_mail    = $send( 'new_order', $order, 'E2E context new order' );
		$context_ids[] = (int) $admin_mail['id'];
		context_check( ! isset( context_meta( $admin_mail )['context']['user'] ) && 990001 === ( context_meta( $admin_mail )['context']['order'] ?? 0 ), 'admin email linked to the order, not to the customer', context_meta( $admin_mail ) );

		wp_mail( 'buyer.ctx@example.com', 'E2E context after sent', 'x' );
		$after         = context_last( 'E2E context after sent' );
		$context_ids[] = (int) $after['id'];
		context_check( ! isset( context_meta( $after )['context'] ), 'relation does not leak into the next mail', context_meta( $after ) );

		$data = $screens->order_mails( $order );
		$ids  = array_column( $data['rows'], 'id' );
		context_check( in_array( (string) $invoice['id'], $ids, true ) && in_array( (string) $admin_mail['id'], $ids, true ), 'order box lists the linked mails', $ids );
		context_check( isset( $data['loose'][ (int) $unlinked['id'] ] ) && isset( $data['loose'][ (int) $after['id'] ] ), 'unlinked mails to the billing address marked "same recipient"', $data['loose'] );
		context_check( ! in_array( (string) $foreign['id'], $ids, true ), 'mail of another order not listed', $ids );

		$html = context_render(
			static function () use ( $screens, $order ) {
				$screens->render_order( $order );
			}
		);
		context_check( false !== strpos( $html, 'E2E context invoice' ) && false !== strpos( $html, 'mailspur-ctx-resend' ) && false !== strpos( $html, 'same recipient' ), 'order box renders with resend and "same recipient"', substr( $html, 0, 800 ) );

		$empty = new WC_Order( 990003 );
		$html  = context_render(
			static function () use ( $screens, $empty ) {
				$screens->render_order( $empty );
			}
		);
		context_check( false !== strpos( $html, 'No emails logged for this order yet.' ), 'empty order box message', $html );

		$detail = context_rest( 'GET', '/mailspur-email-log/v1/mails/' . $invoice['id'] )->get_data();
		$labels = array_column( $detail['context']['links'] ?? array(), 'label' );
		context_check( in_array( 'Order #990001', $labels, true ) && 'customer_invoice' === ( $detail['context']['wc_email'] ?? '' ), 'dialog links the order and names the WooCommerce email', $detail['context'] ?? null );

		$resent = context_rest( 'POST', '/mailspur-email-log/v1/mails/' . $invoice['id'] . '/resend' );
		$copy   = context_last( 'E2E context invoice' );
		if ( $copy ) {
			$context_ids[] = (int) $copy['id'];
		}
		context_check( 200 === $resent->get_status() && 'mailspur:resend' === ( $copy['source'] ?? '' ) && 990001 === ( context_meta( $copy )['context']['order'] ?? 0 ), 'resend from the log stays with the order', array( $resent->get_status(), $copy['source'] ?? null, context_meta( $copy ) ) );

		// Deleted mails disappear from the lookup table with the daily cleanup.
		( new Repository() )->delete( array( (int) $invoice['id'] ) );
		$store->prune();
		$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE mail_id = %d', Store::table(), (int) $invoice['id'] ) );
		context_check( 0 === $left, 'prune removes relations of deleted mails', $left );

		remove_filter( 'user_has_cap', $context_grant );
		wp_set_current_user( $subscriber );
		context_check( ! Screens::can_view_orders(), 'order mails need the log and the order capability' );
		wp_set_current_user( (int) $admins[0] );
	}
} catch ( Throwable $e ) {
	context_check( false, 'exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

try {
	global $wpdb;
	remove_filter( 'pre_wp_mail', $context_deliver, 10 );
	remove_filter( 'user_has_cap', $context_grant );
	if ( $context_ids ) {
		( new Repository() )->delete( $context_ids );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE recipients LIKE %s', Repository::table(), '%' . $wpdb->esc_like( '.ctx@example.com' ) . '%' ) );
	( new Store() )->prune();
	foreach ( $context_users as $id ) {
		if ( is_int( $id ) ) {
			wp_delete_user( $id );
		}
	}
} catch ( Throwable $e ) {
	context_check( false, 'cleanup failed', $e->getMessage() );
}

$context_failed = array_values(
	array_filter(
		$context_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
file_put_contents(
	'/e2e-out/features/context.json',
	wp_json_encode(
		array(
			'passed'  => count( $context_results ) - count( $context_failed ),
			'failed'  => count( $context_failed ),
			'results' => $context_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
