<?php
/**
 * Must-use plugin (installed by seed.d/types.php) for the browser tests of the Email types module.
 *
 * - wp-admin/?mailspur-e2e-seed=types: a weekly digest whose "Read online" link disappeared after an update
 *   (content change) and a daily reminder sent by a cron event that is no longer scheduled (stopped type).
 *   Subjects contain "[Types test]"; emails with that marker are "delivered" without a mail server.
 * - wp-admin/?mailspur-e2e-seed=types-cleanup: removes everything again (incl. resends).
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification.Recommended, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Updates;
use Mailspur\Repository;

add_filter(
	'pre_wp_mail',
	static function ( $result, $atts ) {
		return is_array( $atts ) && false !== strpos( (string) ( $atts['subject'] ?? '' ), '[Types test]' ) ? true : $result;
	},
	10,
	2
);

add_action(
	'admin_init',
	static function () {
		$action = isset( $_GET['mailspur-e2e-seed'] ) ? (string) $_GET['mailspur-e2e-seed'] : '';
		if ( ! in_array( $action, array( 'types', 'types-cleanup' ), true ) || ! current_user_can( 'manage_options' ) || ! class_exists( Repository::class ) ) {
			return;
		}
		global $wpdb;
		$label = 'E2E Digest 2.0';

		if ( 'types-cleanup' === $action ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject LIKE %s', Repository::table(), '%' . $wpdb->esc_like( '[Types test]' ) . '%' ) );
			$updates = array_values(
				array_filter(
					Updates::all(),
					static function ( $update ) use ( $label ) {
						return $label !== $update['label'];
					}
				)
			);
			update_option( Updates::OPTION, $updates, false );
			delete_option( Report::SEEN );
			delete_option( 'mailspur_e2e_types_seeded' );
			delete_transient( 'mailspur_sources' );
			wp_safe_redirect( admin_url( 'admin.php?page=mailspur-email-log&tab=types' ) );
			exit;
		}

		if ( ! get_option( 'mailspur_e2e_types_seeded' ) ) {
			$repository = new Repository();
			$now        = time();
			$base       = array(
				'status'       => Repository::STATUS_SENT,
				'recipients'   => 'reader@example.com',
				'headers'      => 'Content-Type: text/html; charset=UTF-8',
				'attachments'  => '',
				'content_type' => 'text/html',
				'sender'       => 'News <news@example.com>',
				'source'       => 'plugin:e2e-types-news',
				'error'        => '',
				'meta'         => '',
				'notes'        => 0,
				'size'         => 300,
				'raw'          => '',
			);
			for ( $d = 20; $d >= 11; $d-- ) {
				$n = 100 - $d;
				$repository->insert(
					array_merge(
						$base,
						array(
							'created_at' => gmdate( 'Y-m-d 08:00:00', $now - $d * DAY_IN_SECONDS ),
							'subject'    => '[Types test] Weekly digest #' . $n,
							'message'    => '<h1>Your digest</h1><p>Hi Anna,</p><p>Here is digest number ' . $n . ' with ' . ( $n * 3 ) . ' new posts.</p>'
								. ( $d > 12 ? '<p><a href="https://news.example/read/' . $n . '/?token=e2e">Read online</a></p>' : '' )
								. '<p>Unsubscribe any time.</p>',
						)
					)
				);
			}
			$updates   = Updates::all();
			$updates[] = array(
				'time'  => (int) strtotime( gmdate( 'Y-m-d 20:00:00', $now - 13 * DAY_IN_SECONDS ) . ' UTC' ),
				'label' => $label,
				'slug'  => 'plugin:e2e-types-news',
			);
			update_option( Updates::OPTION, $updates, false );

			$meta = (string) wp_json_encode(
				array(
					'trace' => array(
						'request' => array(
							'type' => 'cron',
							'hook' => 'mailspur_e2e_ui_reminder',
						),
					),
				)
			);
			for ( $d = 33; $d >= 4; $d-- ) {
				$repository->insert(
					array_merge(
						$base,
						array(
							'created_at'   => gmdate( 'Y-m-d 06:00:00', $now - $d * DAY_IN_SECONDS ),
							'subject'      => '[Types test] Daily reminder',
							'message'      => 'Your reminder for today.',
							'headers'      => '',
							'content_type' => 'text/plain',
							'source'       => 'plugin:e2e-types-cron',
							'meta'         => $meta,
						)
					)
				);
			}
			update_option( 'mailspur_e2e_types_seeded', 1, false );
			delete_transient( 'mailspur_sources' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mailspur-email-log&tab=types' ) );
		exit;
	}
);
