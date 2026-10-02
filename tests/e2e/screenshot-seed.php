<?php
/**
 * Must-use plugin for scripts/wporg-assets.mjs: wp-admin/?mailspur-e2e-seed=history inserts 30 days of
 * plausible shop traffic (orders following a weekday pattern, contact form mails, password resets, a weekly
 * newsletter, a few failures) so the statistics screenshot shows real-looking charts. Deterministic (seeded).
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.rand_mt_rand, WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand
 *
 * @package Mailspur
 */

use Mailspur\Repository;

add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_GET['mailspur-e2e-seed'] ) || 'history' !== $_GET['mailspur-e2e-seed'] || ! current_user_can( 'manage_options' ) || get_option( 'mailspur_e2e_history_seeded' ) ) {
			return;
		}
		mt_srand( 42 );
		$names  = array( 'jan', 'lena', 'mia', 'noah', 'emma', 'paul', 'lea', 'ben', 'hannah', 'finn', 'sofia', 'elias' );
		$hosts  = array( 'gmail.com', 'gmx.de', 'web.de', 'outlook.com', 't-online.de', 'icloud.com', 'yahoo.com' );
		$rows   = array();
		$base   = array(
			'status'       => Repository::STATUS_SENT,
			'message'      => '<p>…</p>',
			'headers'      => 'Content-Type: text/html; charset=UTF-8',
			'attachments'  => '',
			'content_type' => 'text/html',
			'sender'       => 'Example Shop <shop@example.com>',
			'error'        => '',
			'meta'         => '',
			'notes'        => 0,
			'size'         => 4200,
			'raw'          => '',
		);
		$at     = static function ( int $days_ago, int $hour ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( gmdate( 'Y-m-d', time() - $days_ago * DAY_IN_SECONDS ) . ' 00:00:00 UTC' ) + $hour * HOUR_IN_SECONDS + mt_rand( 0, 3599 ) );
		};
		$person = static function () use ( $names, $hosts ) {
			return $names[ mt_rand( 0, count( $names ) - 1 ) ] . mt_rand( 1, 99 ) . '@' . $hosts[ mt_rand( 0, count( $hosts ) - 1 ) ];
		};

		for ( $day = 30; $day >= 1; $day-- ) {
			$weekday = (int) gmdate( 'N', time() - $day * DAY_IN_SECONDS );
			$orders  = ( $weekday >= 6 ? 9 : 16 ) + mt_rand( 0, 8 ) + ( $day < 10 ? 4 : 0 );
			for ( $i = 0; $i < $orders; $i++ ) {
				$hour   = array( 7, 9, 10, 11, 12, 13, 14, 17, 18, 19, 20, 21 )[ mt_rand( 0, 11 ) ];
				$failed = 0 === mt_rand( 0, 45 );
				$rows[] = array_merge(
					$base,
					array(
						'created_at' => $at( $day, $hour ),
						'recipients' => $person(),
						'subject'    => sprintf( '[Example Shop] Your order #%d has been received', 120000 + $day * 40 + $i ),
						'source'     => 'plugin:woocommerce',
						'status'     => $failed ? Repository::STATUS_FAILED : Repository::STATUS_SENT,
						'error'      => $failed ? 'SMTP Error: Could not authenticate.' : '',
					)
				);
			}
			for ( $i = 0, $n = mt_rand( 1, 4 ); $i < $n; $i++ ) {
				$rows[] = array_merge(
					$base,
					array(
						'created_at'   => $at( $day, mt_rand( 8, 22 ) ),
						'recipients'   => 'shop@example.com',
						'subject'      => 'New message from the contact form',
						'source'       => 'plugin:contact-form-7',
						'content_type' => 'text/plain',
					)
				);
			}
			if ( 0 === mt_rand( 0, 2 ) ) {
				$rows[] = array_merge(
					$base,
					array(
						'created_at' => $at( $day, mt_rand( 6, 23 ) ),
						'recipients' => $person(),
						'subject'    => '[Example Shop] Password Reset',
						'source'     => 'core',
					)
				);
			}
			if ( 2 === $weekday ) { // Tuesday newsletter.
				for ( $i = 0; $i < 40; $i++ ) {
					$rows[] = array_merge(
						$base,
						array(
							'created_at' => $at( $day, 10 ),
							'recipients' => $person(),
							'subject'    => 'Example Shop – new coaching slots this week',
							'source'     => 'plugin:mailpoet',
						)
					);
				}
			}
		}

		$repository = new Repository();
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$repository->insert_many( $chunk );
		}
		update_option( 'mailspur_e2e_history_seeded', count( $rows ), false );
	}
);
