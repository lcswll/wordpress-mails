<?php
/**
 * Plugin Name:       Outbox – Mail Log
 * Plugin URI:        https://github.com/lcswll02/outbox-mail-log
 * Description:       Logs every outgoing email and lets you search, filter and safely inspect it. A modern, lightweight replacement for classic mail logging plugins.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Lucas
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       outbox-mail-log
 * Domain Path:       /languages
 *
 * @package OutboxMailLog
 */

namespace OutboxMailLog;

defined( 'ABSPATH' ) || exit;

const VERSION    = '1.0.0';
const DB_VERSION = 1;
const FILE       = __FILE__;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

// Boot immediately (not on plugins_loaded) so mails sent by other plugins while loading are captured too.
Plugin::boot();
