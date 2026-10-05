<?php
/**
 * Seed hook for tests/e2e/types.spec.js: wp-admin/?mailspur-e2e-seed=types inserts an email type whose content
 * changed after a plugin update and a stopped cron type; ?mailspur-e2e-seed=types-cleanup removes them again, so
 * the fixed counts of the other browser specs stay untouched. Only on demand.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Mailspur
 */

wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/mailspur-e2e-types.php', "<?php\nrequire '/e2e/types-seed.php';\n" );
