<?php
/**
 * Remembers which WordPress object a mail belongs to, while it is being sent – without queries.
 *
 * The sender tells us right before it calls wp_mail(): WooCommerce passes the WC_Email instance to the
 * woocommerce_mail_callback_params filter (its "object" is the order – an object in memory, so HPOS and
 * legacy storage behave the same), core passes the WP_User to its notification filters. That relation is
 * "pending" until the next wp_mail() call takes it over and the mailspur_meta "capture" phase stores it as
 * $meta['context'] = array( 'order' => 123, 'user' => 45, 'wc_email' => 'customer_invoice' ).
 * A relation is used for exactly one mail; anything left over is dropped by the next wp_mail() call.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Context;

use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Capture {

	/** @var Store */
	private $store;

	/**
	 * Relation announced for the next wp_mail() call.
	 *
	 * @var array<string,int|string>
	 */
	private $pending = array();

	/**
	 * Relation of the wp_mail() call in progress.
	 *
	 * @var array<string,int|string>
	 */
	private $current = array();

	public function __construct( Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		// WooCommerce (only fires when it is active; the callbacks never touch WooCommerce classes directly).
		add_filter( 'woocommerce_mail_callback_params', array( $this, 'woocommerce' ), 10, 2 );
		add_action( 'woocommerce_email_sent', array( $this, 'clear' ) );

		// Core notifications to a user (the filters run right before their wp_mail() call).
		add_filter( 'wp_new_user_notification_email', array( $this, 'user_object' ), 10, 2 );
		add_filter( 'retrieve_password_notification_email', array( $this, 'reset_password' ), 10, 4 );
		add_filter( 'password_change_email', array( $this, 'user_array' ), 10, 2 );
		add_filter( 'email_change_email', array( $this, 'user_array' ), 10, 2 );

		add_filter( 'wp_mail', array( $this, 'promote' ), 1 );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 10, 2 );
		add_action( 'mailspur_logged', array( $this, 'logged' ), 10, 2 );
	}

	/**
	 * @param mixed $params Arguments for wp_mail().
	 * @param mixed $email  WC_Email instance.
	 * @return mixed Unchanged.
	 */
	public function woocommerce( $params, $email = null ) {
		$this->pending = self::from_wc_email( $email );
		return $params;
	}

	/** After WooCommerce sent (or did not send) its mail: nothing may be left for an unrelated mail. */
	public function clear(): void {
		$this->pending = array();
	}

	/**
	 * @param mixed $email Filtered email array.
	 * @param mixed $user  WP_User.
	 * @return mixed Unchanged.
	 */
	public function user_object( $email, $user = null ) {
		if ( $user instanceof \WP_User && $user->ID > 0 ) {
			$this->pending = array( 'user' => (int) $user->ID );
		}
		return $email;
	}

	/**
	 * @param mixed $email      Filtered email array.
	 * @param mixed $key        Reset key (unused).
	 * @param mixed $user_login Login (unused).
	 * @param mixed $user       WP_User.
	 * @return mixed Unchanged.
	 */
	public function reset_password( $email, $key = null, $user_login = null, $user = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		return $this->user_object( $email, $user );
	}

	/**
	 * @param mixed $email Filtered email array.
	 * @param mixed $user  Original user data as array (with "ID").
	 * @return mixed Unchanged.
	 */
	public function user_array( $email, $user = null ) {
		if ( is_array( $user ) && isset( $user['ID'] ) && (int) $user['ID'] > 0 ) {
			$this->pending = array( 'user' => (int) $user['ID'] );
		}
		return $email;
	}

	/**
	 * A wp_mail() call starts: it owns the pending relation (and nothing else does afterwards).
	 *
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed Unchanged.
	 */
	public function promote( $atts ) {
		$this->current = $this->pending;
		$this->pending = array();
		return $atts;
	}

	/**
	 * Explicit relation for the next mail, e.g. a resend from the log keeps the order of the original.
	 *
	 * @param array<string,int|string> $context
	 */
	public function announce( array $context ): void {
		$this->pending = $context;
	}

	/**
	 * @param mixed $meta  Meta collected so far.
	 * @param mixed $phase capture|phpmailer|result|import.
	 * @return mixed
	 */
	public function meta( $meta, $phase = '' ) {
		if ( 'capture' !== $phase || ! $this->current || ! is_array( $meta ) ) {
			return $meta;
		}
		$meta['context'] = $this->current;
		$this->current   = array();
		return $meta;
	}

	/**
	 * Mirrors the relation into the lookup table (one INSERT, only for mails with a context).
	 *
	 * @param mixed $id  Log entry id.
	 * @param mixed $row Logged row; meta as JSON string.
	 */
	public function logged( $id, $row = array() ): void {
		if ( ! is_array( $row ) || ! isset( $row['meta'] ) || ! is_string( $row['meta'] ) || false === strpos( $row['meta'], '"context"' ) ) {
			return;
		}
		$context = Rest::decode_meta( $row['meta'] )['context'] ?? null;
		if ( ! is_array( $context ) ) {
			return;
		}
		try {
			Store::maybe_install();
			$this->store->insert( (int) $id, (string) ( $row['created_at'] ?? '' ), $context );
		} catch ( \Throwable $e ) { // Never break mail delivery.
			return;
		}
	}

	/**
	 * Relation of a WooCommerce email: the order (refunds: their order), its customer (customer emails only)
	 * and the email id, e.g. "customer_completed_order", "customer_invoice", "customer_note".
	 *
	 * @param mixed $email WC_Email instance.
	 * @return array<string,int|string>
	 */
	public static function from_wc_email( $email ): array {
		if ( ! is_object( $email ) ) {
			return array();
		}
		$context = array();
		$object  = isset( $email->object ) ? $email->object : null;

		if ( is_a( $object, 'WC_Order_Refund' ) ) {
			$order = (int) self::call( $object, 'get_parent_id' );
			if ( $order > 0 ) {
				$context['order'] = $order;
			}
		} elseif ( is_a( $object, 'WC_Abstract_Order' ) ) {
			$order = (int) self::call( $object, 'get_id' );
			if ( $order > 0 ) {
				$context['order'] = $order;
			}
			// The customer only for mails to the customer – "new order" to the shop owner is not "to this user".
			$customer = true === self::call( $email, 'is_customer_email' ) ? (int) self::call( $object, 'get_customer_id' ) : 0;
			if ( $customer > 0 ) {
				$context['user'] = $customer;
			}
		} elseif ( $object instanceof \WP_User && $object->ID > 0 ) {
			$context['user'] = (int) $object->ID;
		}

		$id = isset( $email->id ) && is_string( $email->id ) ? substr( sanitize_key( $email->id ), 0, 60 ) : '';
		if ( '' !== $id ) {
			$context['wc_email'] = $id;
		}
		return $context;
	}

	/**
	 * Calls a getter of an object whose class may not exist (WooCommerce is optional).
	 *
	 * @param mixed $item
	 * @return mixed
	 */
	public static function call( $item, string $method ) {
		if ( ! is_object( $item ) || ! method_exists( $item, $method ) ) {
			return null;
		}
		$callback = array( $item, $method );
		return is_callable( $callback ) ? call_user_func( $callback ) : null;
	}
}
