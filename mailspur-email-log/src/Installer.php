<?php
/**
 * Schema installation and upgrades.
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const DB_VERSION_OPTION = 'mailspur_db_version';

	/**
	 * Installs the table for the current site. On network activation other
	 * sites are installed lazily by maybe_upgrade() on their first request.
	 */
	public static function activate(): void {
		self::install();
		Cleanup::schedule();
	}

	public static function deactivate(): void {
		Cleanup::unschedule();
		self::unschedule_all();
	}

	/** Removes every scheduled event of the plugin (core and modules use the "mailspur_" hook prefix). */
	public static function unschedule_all(): void {
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'mailspur_' ) ) {
					wp_clear_scheduled_hook( (string) $hook );
				}
			}
		}
	}

	/**
	 * Runs on every request but costs only one (autoloaded) option lookup.
	 * Also covers network activations and new multisite sites lazily.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION ) !== DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Repository::table();
		$charset = $wpdb->get_charset_collate();
		$from    = (int) get_option( self::DB_VERSION_OPTION );

		// dbDelta is picky: two spaces after PRIMARY KEY, one column per line.
		dbDelta(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
status tinyint(1) unsigned NOT NULL DEFAULT 0,
recipients text NOT NULL,
subject text NOT NULL,
message longtext NOT NULL,
headers text NOT NULL,
attachments text NOT NULL,
content_type varchar(100) NOT NULL DEFAULT '',
sender varchar(255) NOT NULL DEFAULT '',
source varchar(100) NOT NULL DEFAULT '',
error text NOT NULL,
meta longtext NOT NULL,
notes smallint(5) unsigned NOT NULL DEFAULT 0,
size int(10) unsigned NOT NULL DEFAULT 0,
raw longtext NOT NULL,
delivery tinyint(1) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY status_created (status,created_at),
KEY source (source),
KEY delivery_created (delivery,created_at)
) {$charset};"
		);

		// 4: provider statuses received before the column existed (meta.feedback).
		if ( $from > 0 && $from < 4 ) {
			( new Repository() )->backfill_delivery();
		}

		update_option( self::DB_VERSION_OPTION, DB_VERSION, true );
	}
}
