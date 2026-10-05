<?php
/**
 * The mails where support needs them: an "Emails" box on the WooCommerce order screen (HPOS and classic)
 * and an "Emails to this user" section on the user profile. Nothing is loaded on any other screen.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Context;

use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Screens {

	/** Mails per list. */
	const LIMIT = 10;

	/** Window for unlinked mails to the billing address: from shortly before the order … */
	const BEFORE_ORDER = DAY_IN_SECONDS;

	/** … until some weeks after it (completion, invoices, notes). */
	const AFTER_ORDER = 60 * DAY_IN_SECONDS;

	/** Classic order screen (post type) – the HPOS screen id comes from WooCommerce. */
	const LEGACY_SCREEN = 'shop_order';

	/** @var Store */
	private $store;

	public function __construct( Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public static function hpos_screen(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
	}

	/** May the current user see the mails of orders? */
	public static function can_view_orders(): bool {
		return Settings::current_user_can_view() && current_user_can( 'edit_shop_orders' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability (required by its order screens).
	}

	/**
	 * @param mixed $screen_id Post type (classic) or screen id (HPOS).
	 * @param mixed $item      WP_Post or WC_Order.
	 */
	public function meta_box( $screen_id, $item = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! function_exists( 'wc_get_order' ) || ! in_array( $screen_id, array( self::LEGACY_SCREEN, self::hpos_screen() ), true ) || ! self::can_view_orders() ) {
			return;
		}
		add_meta_box( 'mailspur-context', __( 'Emails', 'mailspur-email-log' ), array( $this, 'render_order' ), (string) $screen_id, 'normal', 'low' );
	}

	/**
	 * @param mixed $item WP_Post (classic) or WC_Order (HPOS).
	 */
	public function render_order( $item ): void {
		$order = self::order( $item );
		if ( null === $order || ! self::can_view_orders() ) {
			return;
		}
		Store::maybe_install();
		$data = $this->order_mails( $order );
		?>
		<div class="mailspur-ctx">
			<?php if ( ! $data['rows'] ) : ?>
				<p class="mailspur-ctx-empty"><?php esc_html_e( 'No emails logged for this order yet.', 'mailspur-email-log' ); ?></p>
			<?php else : ?>
				<?php View::table( $data['rows'], $data['loose'], current_user_can( 'manage_options' ) ); ?>
				<p class="mailspur-ctx-message" role="status" aria-live="polite"></p>
			<?php endif; ?>
			<?php if ( '' !== $data['email'] ) : ?>
				<p class="mailspur-ctx-more"><a href="<?php echo esc_url( View::recipient_url( $data['email'] ) ); ?>"><?php esc_html_e( 'Show all in Mail Log', 'mailspur-email-log' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Linked mails plus unlinked ones to the billing address around the order date.
	 *
	 * @param object $order WC_Order.
	 * @return array{rows:array<int,array<string,string>>,loose:array<int,bool>,email:string}
	 */
	public function order_mails( $order ): array {
		$linked = $this->store->for_object( 'order', (int) Capture::call( $order, 'get_id' ), self::LIMIT );
		$email  = (string) Capture::call( $order, 'get_billing_email' );
		$nearby = array();
		$loose  = array();

		$created = Capture::call( $order, 'get_date_created' );
		$time    = $created instanceof \DateTimeInterface ? $created->getTimestamp() : 0;
		if ( '' !== $email && $time > 0 ) {
			$nearby = $this->store->unlinked_near( $email, gmdate( 'Y-m-d H:i:s', $time - self::BEFORE_ORDER ), gmdate( 'Y-m-d H:i:s', $time + self::AFTER_ORDER ), self::LIMIT );
			foreach ( $nearby as $row ) {
				$loose[ (int) $row['id'] ] = true;
			}
		}
		$rows = Store::merge( self::LIMIT * 2, $linked, $nearby );

		return array(
			'rows'  => $rows,
			'loose' => $loose,
			'email' => is_email( $email ) ? $email : '',
		);
	}

	/**
	 * @param mixed $user WP_User whose profile is shown.
	 */
	public function profile( $user ): void {
		if ( ! $user instanceof \WP_User || ! Settings::current_user_can_view() || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		Store::maybe_install();
		$rows = $this->user_mails( $user );
		?>
		<div class="mailspur-ctx mailspur-ctx-profile">
			<h2><?php esc_html_e( 'Emails to this user', 'mailspur-email-log' ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p class="mailspur-ctx-empty"><?php esc_html_e( 'No emails to this user in the log.', 'mailspur-email-log' ); ?></p>
			<?php else : ?>
				<?php View::table( $rows, array(), false ); ?>
			<?php endif; ?>
			<?php if ( is_email( $user->user_email ) ) : ?>
				<p class="mailspur-ctx-more"><a href="<?php echo esc_url( View::recipient_url( $user->user_email ) ); ?>"><?php esc_html_e( 'Show all in Mail Log', 'mailspur-email-log' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Mails to the user's current address plus mails linked to the user (e.g. sent to a former address).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function user_mails( \WP_User $user ): array {
		return Store::merge(
			self::LIMIT,
			$this->store->to_recipient( (string) $user->user_email, self::LIMIT ),
			$this->store->for_object( 'user', (int) $user->ID, self::LIMIT )
		);
	}

	public function assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return;
		}
		$order_screen = in_array( $screen->id, array( self::LEGACY_SCREEN, self::hpos_screen() ), true ) && function_exists( 'wc_get_order' ) && self::can_view_orders();
		$profile      = in_array( $screen->id, array( 'profile', 'user-edit' ), true ) && Settings::current_user_can_view();
		if ( ! $order_screen && ! $profile ) {
			return;
		}

		$base = plugin_dir_url( \Mailspur\FILE ) . 'assets/';
		wp_enqueue_style( 'mailspur-context', $base . 'context.css', array(), \Mailspur\VERSION );

		if ( ! $order_screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script(
			'mailspur-context-box',
			$base . 'context-box.js',
			array(),
			\Mailspur\VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				/* translators: %s: recipient email address(es) */
				'confirmResend' => __( 'Send this email again to %s?', 'mailspur-email-log' ),
				'resendOk'      => __( 'Email sent again.', 'mailspur-email-log' ),
				'resendFail'    => __( 'Sending failed – see the new log entry for details.', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'requestFailed' => __( 'Request failed: %s', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-context-box', 'window.mailspurContextBox = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * @param mixed $item WP_Post or order object.
	 * @return object|null WC order (not a refund).
	 */
	public static function order( $item ) {
		if ( $item instanceof \WP_Post && function_exists( 'wc_get_order' ) ) {
			$item = wc_get_order( $item->ID );
		}
		return is_object( $item ) && is_a( $item, 'WC_Abstract_Order' ) && ! is_a( $item, 'WC_Order_Refund' ) ? $item : null;
	}
}
