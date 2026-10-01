<?php
/**
 * Removes all plugin data when the plugin is deleted (unless disabled in settings).
 *
 * @package Mailspur
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mailspur_uninstall = static function (): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'mailspur_cleanup' );

	$settings = get_option( 'mailspur_settings' );
	if ( is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && ! $settings['delete_data_on_uninstall'] ) {
		return;
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mailspur" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'mailspur_settings' );
	delete_option( 'mailspur_db_version' );
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
