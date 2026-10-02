<?php
/**
 * Installs the must-use plugin behind scripts/wporg-assets.mjs: wp-admin/?mailspur-e2e-seed=history adds
 * 30 days of realistic history for the statistics screenshot. Only on demand, so the fixed counts of the
 * browser specs stay untouched.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Mailspur
 */

wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/mailspur-e2e-screenshots.php', "<?php\nrequire '/e2e/screenshot-seed.php';\n" );
