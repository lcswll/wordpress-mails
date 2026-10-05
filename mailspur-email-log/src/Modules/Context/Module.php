<?php
/**
 * Context: emails right where you need them. Links every mail to the WordPress object it belongs to
 * (WooCommerce order, user) and shows the mails on the order screen and the user profile, with back-links
 * from the log dialog.
 *
 * Sending: the relation is taken from objects the sender already holds (no queries), stored in the meta and
 * mirrored into a small lookup table after logging. Viewing: indexed lookups by order/user, plus recipient
 * matches for mails that were never linked.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Context;

use Mailspur\Cleanup;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/** @var Repository */
	private $repository;

	/** @var Capture|null */
	private $capture;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		$store         = new Store();
		$this->capture = new Capture( $store );
		$this->capture->register();

		add_filter( 'rest_request_before_callbacks', array( $this, 'before_resend' ), 10, 3 );
		add_filter( 'mailspur_rest_item', array( $this, 'rest_item' ), 10, 2 );
		add_action( 'mailspur_admin_enqueue', array( $this, 'assets' ), 10, 2 );
		add_action(
			Cleanup::HOOK,
			static function () use ( $store ): void {
				Store::maybe_install();
				$store->prune();
			},
			40
		);

		if ( is_admin() ) {
			add_action( 'admin_init', array( Store::class, 'maybe_install' ) );
			( new Screens( $store ) )->register();
		}
	}

	/**
	 * A resend from the log stays attached to the order (and to the user, unless sent to other addresses).
	 *
	 * @param mixed $response Response so far (null).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed Unchanged.
	 */
	public function before_resend( $response, $handler = null, $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		if ( null !== $response || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() || null === $this->capture ) {
			return $response;
		}
		if ( ! preg_match( '#^/' . preg_quote( Rest::NS, '#' ) . '/mails/(\d+)/resend$#', $request->get_route(), $m ) || ! Settings::current_user_can_view() ) {
			return $response;
		}
		$row     = $this->repository->find( (int) $m[1] );
		$context = $row ? ( Rest::decode_meta( (string) ( $row['meta'] ?? '' ) )['context'] ?? null ) : null;
		if ( is_array( $context ) ) {
			if ( null !== $request->get_param( 'to' ) ) {
				unset( $context['user'] );
			}
			$this->capture->announce( self::clean( $context ) );
		}
		return $response;
	}

	/**
	 * Dialog payload: back-links to the order and the user, for users allowed to edit them.
	 *
	 * @param mixed $item Detail payload.
	 * @param mixed $row  Raw database row.
	 * @return mixed
	 */
	public function rest_item( $item, $row ) {
		if ( ! is_array( $item ) || ! is_array( $row ) ) {
			return $item;
		}
		$context = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) )['context'] ?? array();
		$context = is_array( $context ) ? self::clean( $context ) : array();
		$links   = array();

		$order_id = (int) ( $context['order'] ?? 0 );
		if ( $order_id > 0 && Screens::can_view_orders() ) {
			$order = function_exists( 'wc_get_order' ) ? Screens::order( wc_get_order( $order_id ) ) : null;
			if ( null !== $order ) {
				$links[] = array(
					'type'  => 'order',
					/* translators: %s: order number */
					'label' => sprintf( __( 'Order #%s', 'mailspur-email-log' ), (string) Capture::call( $order, 'get_order_number' ) ),
					'url'   => (string) Capture::call( $order, 'get_edit_order_url' ),
				);
			}
		}

		$user = self::user( $context, (string) ( $row['recipients'] ?? '' ) );
		if ( null !== $user && current_user_can( 'edit_user', $user->ID ) ) {
			$links[] = array(
				'type'  => 'user',
				/* translators: %s: user display name */
				'label' => sprintf( __( 'User: %s', 'mailspur-email-log' ), $user->display_name ),
				'url'   => (string) get_edit_user_link( $user->ID ),
			);
		}

		$item['context'] = array(
			'links'    => $links,
			'wc_email' => (string) ( $context['wc_email'] ?? '' ),
		);
		return $item;
	}

	/**
	 * The linked user, or the user one of the (first three) recipients belongs to.
	 *
	 * @param array<string,int|string> $context
	 */
	private static function user( array $context, string $recipients ): ?\WP_User {
		$id = (int) ( $context['user'] ?? 0 );
		if ( $id > 0 ) {
			$user = get_userdata( $id );
			return $user instanceof \WP_User ? $user : null;
		}
		foreach ( array_slice( Repository::extract_emails( $recipients ), 0, 3 ) as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				return $user;
			}
		}
		return null;
	}

	/**
	 * Only the known keys with the expected types (meta is data from the database).
	 *
	 * @param array<mixed> $context
	 * @return array<string,int|string>
	 */
	public static function clean( array $context ): array {
		$out = array();
		foreach ( Store::TYPES as $type ) {
			if ( isset( $context[ $type ] ) && is_numeric( $context[ $type ] ) && (int) $context[ $type ] > 0 ) {
				$out[ $type ] = (int) $context[ $type ];
			}
		}
		if ( isset( $context['wc_email'] ) && is_string( $context['wc_email'] ) && '' !== $context['wc_email'] ) {
			$out['wc_email'] = substr( sanitize_key( $context['wc_email'] ), 0, 60 );
		}
		return $out;
	}

	public function assets( string $tab, string $base ): void {
		if ( 'log' !== $tab ) {
			return;
		}
		wp_enqueue_script(
			'mailspur-context',
			$base . 'context.js',
			array( 'mailspur-email-log-admin' ),
			\Mailspur\VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'i18n' => array(
				'linked'  => __( 'Belongs to', 'mailspur-email-log' ),
				'wcEmail' => __( 'WooCommerce email', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-context', 'window.mailspurContext = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
