<?php
/**
 * Removes all plugin data when the plugin is deleted (unless disabled in settings).
 *
 * @package OutboxMailLog
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$outbox_mail_log_uninstall = static function (): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'outbox_mail_log_cleanup' );

	$settings = get_option( 'outbox_mail_log_settings' );
	if ( is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && ! $settings['delete_data_on_uninstall'] ) {
		return;
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}outbox_mail_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'outbox_mail_log_settings' );
	delete_option( 'outbox_mail_log_db_version' );
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $outbox_mail_log_site_id ) {
		switch_to_blog( $outbox_mail_log_site_id );
		$outbox_mail_log_uninstall();
		restore_current_blog();
	}
} else {
	$outbox_mail_log_uninstall();
}
