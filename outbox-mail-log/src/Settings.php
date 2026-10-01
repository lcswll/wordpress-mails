<?php
/**
 * Plugin settings (single autoloaded option).
 *
 * @package Outbox
 */

namespace Outbox;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'outbox_settings';

	/** Capabilities that may be granted read access to the log. */
	const CAPABILITIES = array( 'manage_options', 'edit_others_posts', 'manage_woocommerce' );

	public static function defaults(): array {
		return array(
			'capability'               => 'manage_options',
			'menu_location'            => 'top',
			'retention_days'           => 90,
			'max_entries'              => 0,
			'remote_images'            => false,
			'redact_secrets'           => true,
			'delete_data_on_uninstall' => true,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Capability required to view and manage the log. */
	public static function capability(): string {
		$cap = (string) self::get( 'capability' );
		return in_array( $cap, self::CAPABILITIES, true ) ? $cap : 'manage_options';
	}

	public static function current_user_can_view(): bool {
		return current_user_can( self::capability() ) || current_user_can( 'manage_options' );
	}

	/**
	 * @param mixed $input Raw option value.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$cap = isset( $input['capability'] ) ? sanitize_key( $input['capability'] ) : $defaults['capability'];

		return array(
			'capability'               => in_array( $cap, self::CAPABILITIES, true ) ? $cap : 'manage_options',
			'menu_location'            => ( isset( $input['menu_location'] ) && 'tools' === $input['menu_location'] ) ? 'tools' : 'top',
			'retention_days'           => isset( $input['retention_days'] ) ? min( 3650, absint( $input['retention_days'] ) ) : $defaults['retention_days'],
			'max_entries'              => isset( $input['max_entries'] ) ? min( 10000000, absint( $input['max_entries'] ) ) : $defaults['max_entries'],
			'remote_images'            => ! empty( $input['remote_images'] ),
			'redact_secrets'           => ! empty( $input['redact_secrets'] ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}
}
