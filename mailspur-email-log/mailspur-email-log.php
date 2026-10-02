<?php
/**
 * Plugin Name:       Mailspur – Email Log
 * Plugin URI:        https://github.com/lcswll/wordpress-mails
 * Description:       Logs every outgoing email, maps every type of email your site sends and alerts you when one stops. Safe preview, delivery diagnostics, statistics.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Lucas Wille
 * Author URI:        https://lucaswille.de/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailspur-email-log
 * Domain Path:       /languages
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

const VERSION    = '1.0.0';
const DB_VERSION = 3; // 3: meta (module data, JSON), notes (count of hints), size, raw (optional MIME source).
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
