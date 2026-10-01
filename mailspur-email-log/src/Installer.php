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
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY status (status)
) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, DB_VERSION, true );
	}
}
