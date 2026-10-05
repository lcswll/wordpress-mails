<?php
/**
 * Seed hook for tests/e2e/views.spec.js. The rows are inserted lazily – only when the spec opens
 * wp-admin/?mailspur-e2e-seed=views – so the fixed counts of the other browser specs stay untouched.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Mailspur
 */

wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/mailspur-e2e-views.php', "<?php\nrequire '/e2e/views-seed.php';\n" );
