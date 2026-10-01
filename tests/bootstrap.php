<?php
/**
 * PHPUnit bootstrap: plugin classes without WordPress (Brain Monkey stubs the functions).
 *
 * @package Mailspur
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// Plugin classes and tests are autoloaded via composer.json "autoload-dev" (same PSR-4 layout as the plugin).
require __DIR__ . '/stubs/constants.php';
require __DIR__ . '/stubs/class-wp-error.php';
require __DIR__ . '/stubs/class-fake-wpdb.php';
require __DIR__ . '/stubs/class-phpmailer.php';
