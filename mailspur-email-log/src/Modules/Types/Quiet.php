<?php
/**
 * Opt-in switches for WordPress emails to administrators that have no setting in WordPress itself: success
 * notices of automatic updates (failure notices still arrive) and the "New user registration" notice to the
 * admin. Offered next to a noisy type; off until an administrator turns one on.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Admin;

defined( 'ABSPATH' ) || exit;

final class Quiet {

	const OPTION   = 'mailspur_types_quiet';
	const NONCE    = 'mailspur_types_quiet';
	const UPDATES  = 'updates';
	const NEW_USER = 'new_user';

	/** Functions that send the emails of a switch (trace origin), to find their types. */
	const ORIGINS = array(
		self::UPDATES  => array( 'WP_Automatic_Updater::send_plugin_theme_email', 'WP_Automatic_Updater::send_email' ),
		self::NEW_USER => array( 'wp_new_user_notification' ),
	);

	/** @return string[] Switches turned on. */
	public static function active(): array {
		$keys = get_option( self::OPTION, array() );
		return is_array( $keys ) ? array_values( array_intersect( array_keys( self::ORIGINS ), $keys ) ) : array();
	}

	/** Applies the switches that are on (cheap: one autoloaded option). */
	public static function register(): void {
		$active = self::active();
		if ( in_array( self::UPDATES, $active, true ) ) {
			add_filter( 'auto_plugin_update_send_email', array( self::class, 'plugin_theme_email' ), 10, 2 );
			add_filter( 'auto_theme_update_send_email', array( self::class, 'plugin_theme_email' ), 10, 2 );
			add_filter( 'auto_core_update_send_email', array( self::class, 'core_email' ), 10, 2 );
		}
		if ( in_array( self::NEW_USER, $active, true ) ) {
			add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
		}
	}

	/**
	 * Keeps the email when one of the updates failed.
	 *
	 * @param mixed $send    Whether to send.
	 * @param mixed $results Update results (objects with a "result" property).
	 */
	public static function plugin_theme_email( $send, $results = array() ): bool {
		foreach ( is_array( $results ) ? $results : array() as $result ) {
			if ( ! is_object( $result ) || ! isset( $result->result ) || true !== $result->result ) {
				return (bool) $send;
			}
		}
		return false;
	}

	/**
	 * Only the success notice of a core update is dropped; failures and critical notices still arrive.
	 *
	 * @param mixed $send Whether to send.
	 * @param mixed $type "success", "fail", "critical" or "manual".
	 */
	public static function core_email( $send, $type = '' ): bool {
		return 'success' === $type ? false : (bool) $send;
	}

	/**
	 * Turns a switch on or off. Turning it on also ignores the matching email types, so they do not count as
	 * stopped; turning it off monitors them again.
	 */
	public static function set( Store $store, string $key, bool $on ): void {
		if ( ! isset( self::ORIGINS[ $key ] ) ) {
			return;
		}
		$active = array_diff( self::active(), array( $key ) );
		if ( $on ) {
			$active[] = $key;
		}
		update_option( self::OPTION, array_values( $active ), true );
		foreach ( $store->types() as $type ) {
			if ( in_array( (string) ( $type['extra']['fn'] ?? '' ), self::ORIGINS[ $key ], true ) ) {
				$store->mute( $type['id'], $on );
			}
		}
	}

	/** admin-post.php?action=mailspur_types_quiet */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mailspur-email-log' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$key = isset( $_POST['quiet'] ) ? sanitize_key( wp_unslash( $_POST['quiet'] ) ) : '';
		$on  = ! empty( $_POST['on'] );
		$id  = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		self::set( new Store(), $key, $on );
		wp_safe_redirect(
			Admin::url(
				array(
					'tab'        => Page::TAB,
					'types-done' => $on ? 'quiet' : 'loud',
				)
			) . '#mailspur-type-' . $id
		);
		exit;
	}
}
