<?php
/**
 * Must-use plugin (installed by seed.d/review.php): wp-admin/?mailspur-e2e-seed=review makes the review request due.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification.Recommended
 *
 * @package Mailspur
 */

add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_GET['mailspur-e2e-seed'] ) || 'review' !== $_GET['mailspur-e2e-seed'] || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option(
			'mailspur_review',
			array(
				'since' => time() - 30 * DAY_IN_SECONDS,
				'state' => '',
				'until' => 0,
			),
			false
		);
		delete_transient( 'mailspur_review_health' );
	},
	5
);
