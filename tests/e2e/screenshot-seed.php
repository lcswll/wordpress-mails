<?php
/**
 * Must-use plugin for scripts/wporg-assets.mjs: wp-admin/?mailspur-e2e-seed=history inserts 60 days of
 * plausible shop traffic (orders following a weekday pattern, contact form mails, password resets, a weekly
 * newsletter, a few failures) so the statistics screenshot shows real-looking charts. Deterministic (seeded).
 *
 * @package Mailspur
 */

add_action(
	'admin_init',
	static function () {
		if ( isset( $_GET['mailspur-e2e-seed'] ) && 'history' === $_GET['mailspur-e2e-seed'] && current_user_can( 'manage_options' ) && ! get_option( 'mailspur_e2e_history_seeded' ) ) {
			mailspur_e2e_seed_history();
		}
	}
);

/**
 * Inserts the history. Also called directly by the wordpress.org Live Preview (scripts/wporg-blueprint.mjs).
 */
function mailspur_e2e_seed_history(): void {
	mt_srand( 42 );
	$names  = array( 'jan', 'lena', 'mia', 'noah', 'emma', 'paul', 'lea', 'ben', 'hannah', 'finn', 'sofia', 'elias' );
	$hosts  = array( 'gmail.com', 'gmx.de', 'web.de', 'outlook.com', 't-online.de', 'icloud.com', 'yahoo.com' );
	$rows   = array();
	$base   = array(
		'status'       => \Mailspur\Repository::STATUS_SENT,
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

	for ( $day = 60; $day >= 1; $day-- ) { // 60 days: the statistics compare 30 days with the 30 before.
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
					'status'     => $failed ? \Mailspur\Repository::STATUS_FAILED : \Mailspur\Repository::STATUS_SENT,
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
		if ( $day <= 2 || 0 === mt_rand( 0, 2 ) ) { // Recent resets, so only the renewal counts as stopped.
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
		// Email types screenshot: a daily renewal that stopped three days ago (after a plugin update) …
		if ( $day >= 3 ) {
			$rows[] = array_merge(
				$base,
				array(
					'created_at' => $at( $day, 4 ),
					'recipients' => $person(),
					'subject'    => sprintf( '[Example Shop] Your subscription #%d has been renewed', 8000 + $day ),
					'source'     => 'plugin:woocommerce-subscriptions',
				)
			);
		}
		// … and welcome emails whose names merge into one type.
		for ( $i = 0, $n = mt_rand( 0, 2 ); $i < $n; $i++ ) {
			$rows[] = array_merge(
				$base,
				array(
					'created_at' => $at( $day, mt_rand( 8, 22 ) ),
					'recipients' => $person(),
					'subject'    => sprintf( 'Welcome to Example Shop, %s!', ucfirst( $names[ mt_rand( 0, count( $names ) - 1 ) ] ) ),
					'source'     => 'plugin:woocommerce',
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

	$repository = new \Mailspur\Repository();
	foreach ( array_chunk( $rows, 100 ) as $chunk ) {
		$repository->insert_many( $chunk );
	}
	update_option( 'mailspur_e2e_history_seeded', count( $rows ), false );

	// Readable sender names: plugin headers only (never activated), and the update that broke the renewals.
	$plugins = array(
		'woocommerce'               => 'WooCommerce',
		'woocommerce-subscriptions' => 'WooCommerce Subscriptions',
		'contact-form-7'            => 'Contact Form 7',
		'mailpoet'                  => 'MailPoet',
	);
	foreach ( $plugins as $slug => $name ) {
		wp_mkdir_p( WP_PLUGIN_DIR . '/' . $slug );
		file_put_contents( WP_PLUGIN_DIR . "/{$slug}/{$slug}.php", "<?php\n/*\nPlugin Name: {$name}\nVersion: 9.4.1\n*/\n" );
	}
	update_option(
		'mailspur_types_updates',
		array(
			array(
				'time'  => time() - 2 * DAY_IN_SECONDS - 5 * HOUR_IN_SECONDS,
				'label' => 'WooCommerce Subscriptions 7.2.0',
				'slug'  => 'plugin:woocommerce-subscriptions',
			),
		),
		false
	);

	// seed.php sent its 62 emails within a second: spread them over the last 10 hours as a real shop would (no
	// emergency brake burst), with the orders under WooCommerce like the history, and index the email types now.
	global $wpdb;
	$table = \Mailspur\Repository::table();
	$fresh = $wpdb->get_results( $wpdb->prepare( 'SELECT id, subject FROM %i WHERE created_at >= %s ORDER BY id DESC', $table, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), ARRAY_A );
	foreach ( $fresh as $i => $mail ) {
		$wpdb->update(
			$table,
			array_merge(
				array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 - $i * 9 * MINUTE_IN_SECONDS - mt_rand( 0, 300 ) ) ),
				false !== strpos( $mail['subject'], 'Your order' ) ? array( 'source' => 'plugin:woocommerce' ) : array()
			),
			array( 'id' => (int) $mail['id'] )
		);
	}
	delete_option( \Mailspur\Modules\Delivery\Brake::STATE_OPTION );
	delete_option( \Mailspur\Modules\Delivery\Brake::COUNTER_OPTION );
	do_action( 'mailspur_types_index_now' );
	\Mailspur\Modules\Answers\Facts::flush();
	\Mailspur\Modules\Insights\Stats::flush();
}
