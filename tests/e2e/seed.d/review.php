<?php
/**
 * Seed hook for tests/e2e/review.spec.js: wp-admin/?mailspur-e2e-seed=review backdates the start of use by 30 days,
 * so the review request is due with the core seed (61 delivered emails, 1 failure). Only on demand.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Mailspur
 */

wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/mailspur-e2e-review.php', "<?php\nrequire '/e2e/review-seed.php';\n" );
