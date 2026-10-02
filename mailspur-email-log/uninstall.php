<?php
/**
 * Removes all plugin data when the plugin is deleted (unless disabled in settings).
 *
 * Generic on purpose: everything the plugin and its modules store uses the "mailspur" prefix
 * (table {prefix}mailspur and {prefix}mailspur_*, options/transients/cron hooks mailspur_*),
 * so modules never need to touch this file.
 *
 * @package Mailspur
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mailspur_uninstall = static function (): void {
	global $wpdb;

	foreach ( (array) _get_cron_array() as $mailspur_events ) {
		foreach ( array_keys( (array) $mailspur_events ) as $mailspur_hook ) {
			if ( 0 === strpos( (string) $mailspur_hook, 'mailspur_' ) ) {
				wp_clear_scheduled_hook( (string) $mailspur_hook );
			}
		}
	}

	$settings = get_option( 'mailspur_settings' );
	if ( is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && ! $settings['delete_data_on_uninstall'] ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mailspur' ) . '%' ) );
	foreach ( $tables as $table ) {
		// Only {prefix}mailspur and {prefix}mailspur_* – never another plugin's table that merely starts alike.
		if ( $wpdb->prefix . 'mailspur' === $table || 0 === strpos( (string) $table, $wpdb->prefix . 'mailspur_' ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	$options = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'mailspur_' ) . '%',
			$wpdb->esc_like( '_transient_mailspur_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mailspur_' ) . '%'
		)
	);
	// phpcs:enable
	foreach ( $options as $option ) {
		delete_option( (string) $option );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $mailspur_site_id ) {
		switch_to_blog( $mailspur_site_id );
		$mailspur_uninstall();
		restore_current_blog();
	}
} else {
	$mailspur_uninstall();
}
