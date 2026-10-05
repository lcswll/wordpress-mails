<?php
/**
 * Minimal WooCommerce / WordPress object stand-ins for the context module tests (loaded on demand by
 * tests/unit/Context*Test.php). WooCommerce is optional for the plugin, so only the getters it calls exist.
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName -- stand-ins, also declared by tests/e2e/features/context.php.
 *
 * @package Mailspur
 */

if ( ! class_exists( 'WP_User' ) ) {
	/** User with ID, address and name (also the login). */
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $user_email = '';
		/** @var string */
		public $display_name = '';
		/** @var string */
		public $user_login = '';

		public function __construct( int $id = 0, string $email = '', string $name = '' ) {
			$this->ID           = $id;
			$this->user_email   = $email;
			$this->display_name = $name;
			$this->user_login   = $name;
		}

		public function exists(): bool {
			return $this->ID > 0;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/** Post with an ID. */
	class WP_Post {
		/** @var int */
		public $ID = 0;

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

if ( ! class_exists( 'WC_Abstract_Order' ) ) {
	/** Order base class. */
	abstract class WC_Abstract_Order {
		/** @var int */
		protected $id;

		public function __construct( int $id ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}
	}

	/** Order. */
	class WC_Order extends WC_Abstract_Order {
		/** @var int */
		public $customer = 0;
		/** @var string */
		public $billing = '';
		/** @var DateTimeImmutable|null */
		public $created;

		public function get_customer_id(): int {
			return $this->customer;
		}

		public function get_billing_email(): string {
			return $this->billing;
		}

		/** @return DateTimeImmutable|null */
		public function get_date_created() {
			return $this->created;
		}

		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_edit_order_url(): string {
			return 'https://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
		}
	}

	/** Refund of an order. */
	class WC_Order_Refund extends WC_Abstract_Order {
		/** @var int */
		public $parent = 0;

		public function get_parent_id(): int {
			return $this->parent;
		}
	}

	/** WooCommerce email (WC_Email): id, object and whether it goes to the customer. */
	class WC_Email {
		/** @var string */
		public $id = '';
		/** @var object|null */
		public $object;
		/** @var bool */
		public $customer = true;

		public function is_customer_email(): bool {
			return $this->customer;
		}
	}
}
