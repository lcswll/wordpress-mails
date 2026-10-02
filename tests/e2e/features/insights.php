<?php
/**
 * Insights integration test inside real WordPress (Playground, SQLite):
 * statistics over a DST switch (Europe/Berlin), top lists, cache invalidation, permissions, dashboard widget,
 * alert evaluation incl. the alert mail itself, webhook payload, silence baseline and the cron schedule.
 *
 * Works on a past date range (2024) with its own rows, so the rest of the log does not interfere,
 * and removes everything it created. Writes /e2e-out/features/insights.json.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Modules\Insights\Alerts;
use Mailspur\Modules\Insights\Dashboard;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Repository;

$insights_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function insights_check( $ok, $name, $detail = null ) {
	global $insights_results;
	$insights_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return WP_REST_Response */
function insights_rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/mailspur-email-log/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) );
}

/** Inserts a log row at a UTC time. */
function insights_row( $utc, $status, $source, $to, $subject ) {
	global $wpdb;
	$wpdb->insert(
		Repository::table(),
		array(
			'created_at'  => $utc,
			'status'      => $status,
			'recipients'  => $to,
			'subject'     => $subject,
			'message'     => 'x',
			'headers'     => '',
			'attachments' => '',
			'source'      => $source,
			'error'       => '',
			'meta'        => '',
			'raw'         => '',
		)
	);
	return (int) $wpdb->insert_id;
}

$insights_settings = get_option( 'mailspur_settings', array() );
$insights_tz       = get_option( 'timezone_string' );
$insights_captured = array();

try {
	global $wpdb;
	$table = Repository::table();
	update_option( 'timezone_string', 'Europe/Berlin' );

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	);
	wp_set_current_user( (int) $admins[0] );

	// ------------------------------------------------------------ data set.
	// 60 days from 2024-02-01 (crosses the DST switch on 2024-03-31), 1–5 mails per day at 08:15 local time.
	$expected_days  = array();
	$expected_total = 0;
	$expected_fail  = 0;
	$expected_held  = 0;
	$berlin         = new DateTimeZone( 'Europe/Berlin' );
	for ( $d = 0; $d < 60; $d++ ) {
		$local = new DateTimeImmutable( '2024-02-01 08:15:00', $berlin );
		$local = $local->add( new DateInterval( 'P' . $d . 'D' ) );
		$n     = $d % 5 + 1;
		for ( $k = 0; $k < $n; $k++ ) {
			$i      = $expected_total;
			$status = 0 === $i % 7 ? Repository::STATUS_FAILED : ( 0 === $i % 11 ? Repository::STATUS_HELD : Repository::STATUS_SENT );
			$utc    = $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			insights_row( $utc, $status, 0 === $i % 3 ? 'e2e:insights-shop' : 'e2e:insights-forms', "user{$i}@" . ( 0 === $i % 4 ? 'big.example' : 'small.example' ), 'Order #' . ( 1000 + $i ) . ' shipped' );
			++$expected_total;
			$expected_fail += Repository::STATUS_FAILED === $status ? 1 : 0;
			$expected_held += Repository::STATUS_HELD === $status ? 1 : 0;
		}
		$expected_days[ $local->format( 'Y-m-d' ) ] = $n;
	}
	// Around the DST switch: 00:30 CET on 31 March, and 03:30 CEST on the same day (02:00–03:00 does not exist).
	insights_row( '2024-03-30 23:30:00', Repository::STATUS_SENT, 'e2e:insights-dst', 'dst@dst.example', 'DST A' );
	insights_row( '2024-03-31 01:30:00', Repository::STATUS_SENT, 'e2e:insights-dst', 'dst@dst.example', 'DST B' );
	$expected_total              += 2;
	$expected_days['2024-03-31'] += 2;

	// ------------------------------------------------------------- statistics.
	$res   = insights_rest(
		'GET',
		'/stats',
		array(
			'from' => '2024-02-01',
			'to'   => '2024-03-31',
		)
	);
	$stats = $res->get_data();
	insights_check( 200 === $res->get_status(), 'GET /stats answers', $stats );
	insights_check( $expected_total === $stats['totals']['all'], 'total count', array( $expected_total, $stats['totals'] ) );
	insights_check( $expected_fail === $stats['totals']['failed'] && $expected_held === $stats['totals']['held'], 'status counts', $stats['totals'] );
	insights_check( Stats::rate( $expected_fail, $expected_total ) === (float) $stats['totals']['rate'], 'failure rate', $stats['totals'] );
	insights_check( 60 === count( $stats['days'] ) && 60 === $stats['range']['days'], 'one entry per day', count( $stats['days'] ) );

	$per_day = array();
	foreach ( $stats['days'] as $day ) {
		$per_day[ $day['date'] ] = $day['sent'] + $day['failed'] + $day['held'] + $day['pending'];
	}
	insights_check( $per_day === $expected_days, 'mails land on their local day', array_diff_assoc( $per_day, $expected_days ) );

	// 08:15 local is hour 8 before and after the switch (07:15 UTC in winter, 06:15 UTC in summer).
	$hour8 = 0;
	foreach ( $stats['heatmap'] as $hours ) {
		$hour8 += $hours[8];
	}
	insights_check( $expected_total - 2 === $hour8, 'heatmap uses local hours across DST', $stats['heatmap'] );
	insights_check( 1 === $stats['heatmap'][0][0] && 1 === $stats['heatmap'][0][3], 'DST night: 00:30 CET and 03:30 CEST on Sunday', array( $stats['heatmap'][0] ) );
	insights_check( '2024-03-31' === $stats['busiest']['date'], 'busiest day', $stats['busiest'] );
	insights_check( 0 === $stats['previous']['all'] && '2023-12-03' === $stats['previous']['from'], 'previous period', $stats['previous'] );

	$sources = array_column( $stats['top']['sources'], 'count', 'key' );
	insights_check( isset( $sources['e2e:insights-shop'], $sources['e2e:insights-forms'] ) && $sources['e2e:insights-shop'] + $sources['e2e:insights-forms'] + $sources['e2e:insights-dst'] === $expected_total, 'top sources', $stats['top']['sources'] );
	insights_check( 'big.example' === $stats['top']['domains'][1]['label'] || 'big.example' === $stats['top']['domains'][0]['label'], 'top domains', $stats['top']['domains'] );
	insights_check( 'Order #… shipped' === $stats['top']['subjects'][0]['label'] && $expected_total - 2 === $stats['top']['subjects'][0]['count'], 'subjects grouped without numbers', $stats['top']['subjects'][0] );
	insights_check( false === $stats['sample']['limited'], 'no sampling for small ranges', $stats['sample'] );

	// The search term of a grouped subject finds the mails in the log.
	$list = insights_rest(
		'GET',
		'/mails',
		array(
			'search' => $stats['top']['subjects'][0]['search'],
			'after'  => '2024-02-01',
			'before' => '2024-03-31',
		)
	)->get_data();
	insights_check( $expected_total - 2 === $list['total'], 'log link of a subject finds its mails', array( $stats['top']['subjects'][0]['search'], $list['total'] ) );

	// ---------------------------------------------------------------- caching.
	$extra  = insights_row( '2024-02-10 10:00:00', Repository::STATUS_SENT, 'e2e:insights-shop', 'late@small.example', 'Late insert' );
	$cached = insights_rest(
		'GET',
		'/stats',
		array(
			'from' => '2024-02-01',
			'to'   => '2024-03-31',
		)
	)->get_data();
	insights_check( $expected_total === $cached['totals']['all'], 'past ranges are served from the cache', $cached['totals'] );

	$gen = (int) get_option( Stats::GENERATION_OPTION, 0 );
	insights_rest( 'DELETE', '/mails/' . $extra );
	insights_check( (int) get_option( Stats::GENERATION_OPTION, 0 ) === $gen + 1, 'deleting entries invalidates the cache' );
	insights_row( '2024-02-10 10:00:00', Repository::STATUS_SENT, 'e2e:insights-shop', 'late@small.example', 'Late insert' );
	Stats::flush();
	$fresh = insights_rest(
		'GET',
		'/stats',
		array(
			'from' => '2024-02-01',
			'to'   => '2024-03-31',
		)
	)->get_data();
	insights_check( $expected_total + 1 === $fresh['totals']['all'], 'fresh numbers after invalidation', $fresh['totals'] );

	// ----------------------------------------------------------- validation.
	$bad = insights_rest(
		'GET',
		'/stats',
		array(
			'from' => '2024-03-01',
			'to'   => '2024-02-01',
		)
	);
	insights_check( 400 === $bad->get_status(), 'reversed range is rejected', $bad->get_data() );
	$bad = insights_rest( 'GET', '/stats', array( 'from' => '2024-02-31' ) );
	insights_check( 400 === $bad->get_status(), 'invalid date is rejected', $bad->get_data() );
	$bad = insights_rest( 'GET', '/stats', array( 'from' => '<script>' ) );
	insights_check( 400 === $bad->get_status(), 'malformed date is rejected by the schema', $bad->get_status() );
	$default = insights_rest( 'GET', '/stats' )->get_data();
	insights_check( 30 === $default['range']['days'] && wp_date( 'Y-m-d' ) === $default['range']['to'], 'default range: last 30 days', $default['range'] );

	// ----------------------------------------------------------- permissions.
	$subscriber = wp_insert_user(
		array(
			'user_login' => 'insights_sub',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	$editor     = wp_insert_user(
		array(
			'user_login' => 'insights_editor',
			'user_pass'  => wp_generate_password(),
			'role'       => 'editor',
		)
	);
	wp_set_current_user( $subscriber );
	insights_check( 403 === insights_rest( 'GET', '/stats' )->get_status(), 'subscribers cannot read statistics' );
	update_option( 'mailspur_settings', array_merge( $insights_settings, array( 'capability' => 'edit_others_posts' ) ) );
	wp_set_current_user( $editor );
	insights_check( 200 === insights_rest( 'GET', '/stats' )->get_status(), 'log viewers (editors) can read statistics' );
	insights_check( 403 === insights_rest( 'POST', '/alerts/test' )->get_status(), 'only administrators can send test alerts' );

	// Dashboard widget for a viewer.
	require_once ABSPATH . 'wp-admin/includes/dashboard.php';
	ob_start();
	( new Dashboard( new Stats( new Repository() ) ) )->render();
	$widget = (string) ob_get_clean();
	insights_check( false !== strpos( $widget, '<svg class="msi-widget-chart"' ) && false !== strpos( $widget, '<table class="screen-reader-text">' ), 'dashboard widget renders chart and table', substr( $widget, 0, 500 ) );
	wp_set_current_user( (int) $admins[0] );
	update_option( 'mailspur_settings', $insights_settings );

	// ---------------------------------------------------------------- alerts.
	// No real network or mail: capture webhook requests, deliver alert mails, fail the test mails.
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) use ( &$insights_captured ) {
			$insights_captured[] = array(
				'url'  => $url,
				'body' => json_decode( $args['body'], true ),
			);
			return array(
				'headers'  => array(),
				'body'     => 'ok',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		},
		10,
		3
	);
	$deliver = static function ( $result, $atts ) {
		return false === strpos( (string) $atts['subject'], 'insights failing' );
	};
	add_filter( 'pre_wp_mail', $deliver, 10, 2 );

	delete_option( Alerts::LOG_OPTION );
	delete_option( Alerts::STATE_OPTION );
	delete_option( Alerts::FAILS_OPTION );
	update_option(
		'mailspur_settings',
		array_merge(
			$insights_settings,
			array(
				'alert_failures'       => true,
				'alert_failures_count' => 3,
				'alert_email'          => 'ops@example.com',
				'alert_webhook'        => 'https://hooks.slack.com/services/T000/B000/XXXX',
			)
		)
	);
	insights_check( false !== wp_next_scheduled( Alerts::HOOK ), 'enabling alerts schedules the 15 minute check' );
	insights_check( isset( wp_get_schedules()[ Alerts::SCHEDULE ] ) && 900 === wp_get_schedules()[ Alerts::SCHEDULE ]['interval'], 'custom cron schedule' );

	wp_clear_scheduled_hook( Alerts::HOOK_NOW );
	wp_mail( 'x1@example.com', 'insights failing 1', 'Body' );
	wp_mail( 'x2@example.com', 'insights failing 2', 'Body' );
	insights_check( false === wp_next_scheduled( Alerts::HOOK_NOW ), 'below the threshold nothing is scheduled' );
	wp_mail( 'x3@example.com', 'insights failing 3', 'Body' );
	insights_check( false !== wp_next_scheduled( Alerts::HOOK_NOW ), 'threshold reached: immediate check scheduled' );
	insights_check( empty( $insights_captured ), 'no network request while sending mails' );

	do_action( Alerts::HOOK_NOW );
	$alert_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s', $table, Stats::ALERT_SOURCE ), ARRAY_A );
	insights_check( 1 === count( $alert_rows ) && 'ops@example.com' === $alert_rows[0]['recipients'], 'alert email sent and logged with source mailspur:alert', $alert_rows );
	insights_check( 1 === count( $insights_captured ) && false !== strpos( (string) $insights_captured[0]['body']['text'], 'Email failure spike' ), 'Slack webhook payload', $insights_captured );
	$history = Alerts::history();
	insights_check( 1 === count( $history ) && 'alert' === $history[0]['kind'] && true === $history[0]['email'] && 200 === $history[0]['webhook'], 'alert history entry', $history );

	// Cooldown: still failing, but no second alert.
	do_action( Alerts::HOOK );
	insights_check( 1 === count( Alerts::history() ), 'cooldown prevents repeated alerts' );

	// A failing alert mail never triggers alerts itself.
	$before = get_option( Alerts::FAILS_OPTION );
	do_action(
		'mailspur_logged',
		1,
		array(
			'status' => Repository::STATUS_FAILED,
			'source' => Stats::ALERT_SOURCE,
		)
	);
	insights_check( get_option( Alerts::FAILS_OPTION ) === $before, 'failed alert mails are not counted' );

	// Recovery once the failures are gone.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject LIKE %s', $table, 'insights failing%' ) );
	$failed_now = ( new Alerts() )->failures_since( time() - 15 * MINUTE_IN_SECONDS );
	do_action( Alerts::HOOK );
	$history = Alerts::history();
	insights_check( $failed_now >= 3 || ( 2 === count( $history ) && 'recovery' === $history[0]['kind'] ), 'recovery message', array( $failed_now, $history ) );

	// Test alert through REST.
	$test = insights_rest( 'POST', '/alerts/test' );
	insights_check( 200 === $test->get_status() && true === $test->get_data()['email'] && 200 === $test->get_data()['webhook'], 'test alert', $test->get_data() );

	// Silence with a fixed clock on the 2024 data: baseline 14 days with a mail at 11:30 UTC, none on 2024-04-20.
	for ( $d = 1; $d <= 14; $d++ ) {
		insights_row( gmdate( 'Y-m-d H:i:s', strtotime( '2024-04-20 11:30:00 UTC' ) - $d * DAY_IN_SECONDS ), Repository::STATUS_SENT, 'e2e:insights-silence', 'cron@example.com', 'Daily digest' );
	}
	$clock    = static function () {
		return (int) strtotime( '2024-04-20 12:00:00 UTC' );
	};
	$baseline = ( new Alerts( $clock ) )->silence( 1 );
	insights_check( is_array( $baseline ) && 14 === $baseline['days'], 'silence detected against the 14 day baseline', $baseline );
	$late = static function () {
		return (int) strtotime( '2024-04-20 15:00:00 UTC' );
	};
	insights_check( null === ( new Alerts( $late ) )->silence( 1 ), 'no silence alert outside the usual time window' );

	update_option(
		'mailspur_settings',
		array_merge(
			$insights_settings,
			array(
				'alert_silence'       => true,
				'alert_silence_hours' => 1,
				'alert_email'         => 'ops@example.com',
			)
		)
	);
	$result = ( new Alerts( $clock ) )->check();
	insights_check( array( 'silence' => true ) === $result && 'silence' === Alerts::history()[0]['type'], 'silence alert sent', array( $result, Alerts::history()[0] ) );

	// Disabling alerts removes the cron event.
	update_option( 'mailspur_settings', $insights_settings );
	insights_check( false === wp_next_scheduled( Alerts::HOOK ), 'disabling alerts unschedules the check' );

	remove_filter( 'pre_wp_mail', $deliver, 10 );
} catch ( Throwable $e ) {
	insights_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// ------------------------------------------------------------------ cleanup.
try {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source LIKE %s OR source = %s OR subject LIKE %s', Repository::table(), 'e2e:insights%', Stats::ALERT_SOURCE, 'insights failing%' ) );
	foreach ( array( 'insights_sub', 'insights_editor' ) as $login ) {
		$user = get_user_by( 'login', $login );
		if ( $user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID );
		}
	}
	update_option( 'mailspur_settings', $insights_settings );
	update_option( 'timezone_string', $insights_tz );
	foreach ( array( Alerts::LOG_OPTION, Alerts::STATE_OPTION, Alerts::FAILS_OPTION ) as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( Alerts::HOOK );
	wp_clear_scheduled_hook( Alerts::HOOK_NOW );
	Stats::flush();
} catch ( Throwable $e ) {
	insights_check( false, 'cleanup failed', $e->getMessage() );
}

$insights_failed = array_values(
	array_filter(
		$insights_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
file_put_contents(
	'/e2e-out/features/insights.json',
	wp_json_encode(
		array(
			'passed'  => count( $insights_results ) - count( $insights_failed ),
			'failed'  => count( $insights_failed ),
			'results' => $insights_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
