<?php
/**
 * Answers integration test inside real WordPress (Playground, SQLite): the "Overview" tab comes first and the log
 * stays the default, POST /answers/arrived for addresses and order numbers (delivered, failed, held, nothing
 * found, invalid, permissions), the health sentence incl. staging mode and its cache, stopped and due email types
 * with a cron event later today, the most common failure reason, the overview markup and the dashboard line.
 *
 * Uses its own rows (source "e2e:answers") and email types, and removes everything it created.
 * Writes /e2e-out/features/answers.json.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Modules\Answers\Facts;
use Mailspur\Modules\Answers\Page;
use Mailspur\Modules\Answers\Sentences;
use Mailspur\Modules\Insights\Dashboard;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Modules\Types\Store as TypesStore;
use Mailspur\Repository;

$answers_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function answers_check( $ok, $name, $detail = null ) {
	global $answers_results;
	$answers_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return WP_REST_Response */
function answers_ask( $query ) {
	$request = new WP_REST_Request( 'POST', '/mailspur-email-log/v1/answers/arrived' );
	$request->set_param( 'q', $query );
	return rest_ensure_response( rest_do_request( $request ) );
}

/** All sentences of an answer as one string. */
function answers_text( $answer ) {
	$out = array();
	foreach ( (array) ( $answer['parts'] ?? array() ) as $part ) {
		$out[] = $part['text'];
		foreach ( $part['items'] as $item ) {
			$out[] = $item['text'];
		}
	}
	return implode( ' ', $out );
}

/** Inserts a log row $ago seconds ago. */
function answers_row( $ago, $status, $to, $subject, $error = '', $meta = array() ) {
	global $wpdb;
	$wpdb->insert(
		Repository::table(),
		array(
			'created_at'  => gmdate( 'Y-m-d H:i:s', time() - $ago ),
			'status'      => $status,
			'recipients'  => $to,
			'subject'     => $subject,
			'message'     => 'x',
			'headers'     => '',
			'attachments' => '',
			'source'      => 'e2e:answers',
			'error'       => $error,
			'meta'        => $meta ? wp_json_encode( $meta ) : '',
			'raw'         => '',
		)
	);
	return (int) $wpdb->insert_id;
}

$answers_settings = get_option( 'mailspur_settings', array() );
$answers_users    = array();
$answers_types    = array();
$answers_hook     = 'mailspur_e2e_answers_digest';

try {
	global $wpdb;
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	);
	wp_set_current_user( (int) $admins[0] );
	$facts = new Facts( new Repository() );
	Facts::flush();
	$baseline = (int) $facts->health()['week']['total']; // Emails of earlier steps (core self-test, import test).
	Facts::flush();

	// ------------------------------------------------------------------- tabs.
	$tabs = apply_filters( 'mailspur_admin_tabs', array( 'log' => array( 'Log', 'manage_options' ) ) );
	$keys = array_keys( $tabs );
	answers_check( 'overview' === $keys[0] && 'log' === $keys[1], 'Overview is the first tab, the log second', $keys );
	answers_check( 'Overview' === $tabs['overview'][0], 'tab label', $tabs['overview'] );

	// ---------------------------------------------------------------- rows.
	$anna = answers_row(
		2 * HOUR_IN_SECONDS,
		Repository::STATUS_SENT,
		'Anna <anna@answers.example>',
		'Your invoice #4711',
		'',
		array(
			'feedback' => array(
				'event' => 'delivered',
				'hard'  => false,
				'at'    => time(),
				'via'   => 'postmark',
			),
		)
	);
	answers_row( 3 * DAY_IN_SECONDS, Repository::STATUS_SENT, 'anna@answers.example', 'Welcome, Anna' );
	answers_row( HOUR_IN_SECONDS, Repository::STATUS_FAILED, 'ben@answers.example', 'Your invoice #4712', 'SMTP Error: The following recipients failed: ben@answers.example: 550 5.1.1 User unknown' );
	answers_row( HOUR_IN_SECONDS, Repository::STATUS_HELD, 'cara@answers.example', 'Your invoice #4713', '', array( 'delivery' => array( 'held' => 'staging' ) ) );
	answers_row( 2 * DAY_IN_SECONDS, Repository::STATUS_FAILED, 'dora@answers.example', 'Contact form', 'SMTP Error: Could not authenticate.' );
	answers_row( 3 * DAY_IN_SECONDS, Repository::STATUS_FAILED, 'eve@answers.example', 'Contact form', 'SMTP Error: Could not authenticate.' );
	answers_row( 9 * DAY_IN_SECONDS, Repository::STATUS_FAILED, 'old@answers.example', 'Old', 'Too old to count' );

	// --------------------------------------------------------- did it arrive?
	$res    = answers_ask( 'Anna@Answers.example' );
	$answer = $res->get_data();
	answers_check( 200 === $res->get_status() && 'ok' === $answer['tone'], 'delivered: 200, tone ok', $answer );
	answers_check( 0 === strpos( answers_text( $answer ), 'Yes. “Your invoice #4711” went to anna@answers.example today at ' ) && false !== strpos( answers_text( $answer ), 'Postmark confirmed delivery' ), 'delivered: plain sentence with the provider', answers_text( $answer ) );
	answers_check( 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ), 'answers are not cached', $res->get_headers() );
	answers_check( 2 === count( $answer['parts'][0]['items'] ) && false !== strpos( $answer['parts'][0]['items'][0]['url'], 'mail=' . $anna ), 'latest emails listed with a deep link', $answer['parts'][0]['items'] );
	answers_check( false !== strpos( (string) $answer['link']['url'], 's=anna%40answers.example' ), 'link to the filtered log', $answer['link'] );

	$answer = answers_ask( 'ben@answers.example' )->get_data();
	answers_check( 'bad' === $answer['tone'] && false !== strpos( answers_text( $answer ), 'Reason: Mailbox unknown or unavailable.' ), 'failed: reason in plain words', answers_text( $answer ) );

	$answer = answers_ask( 'cara@answers.example' )->get_data();
	answers_check( 0 === strpos( answers_text( $answer ), 'No, on purpose.' ) && false !== strpos( answers_text( $answer ), 'staging mode is on' ), 'held: on purpose, by staging mode', answers_text( $answer ) );

	$answer = answers_ask( '#4711' )->get_data();
	answers_check( 0 === strpos( answers_text( $answer ), 'Yes. “Your invoice #4711”' ), 'order number without a shop: found by subject', answers_text( $answer ) );
	$answer = answers_ask( '471' )->get_data();
	answers_check( false !== strpos( answers_text( $answer ), 'no email for order #471 ' ), 'order number: no partial number matches', answers_text( $answer ) );

	$answer = answers_ask( 'nobody@answers.example' )->get_data();
	answers_check( 'info' === $answer['tone'] && 0 === strpos( answers_text( $answer ), 'Mailspur has no email to nobody@answers.example in the log.' ), 'unknown address', answers_text( $answer ) );
	$answer = answers_ask( 'hello world' )->get_data();
	answers_check( 'Enter an email address or an order number.' === answers_text( $answer ), 'invalid input', answers_text( $answer ) );
	answers_check( 400 === answers_ask( str_repeat( 'x', 201 ) )->get_status(), 'overlong input is rejected by the schema' );

	$subscriber = wp_insert_user(
		array(
			'user_login' => 'answers_sub',
			'user_pass'  => wp_generate_password(),
			'user_email' => 'answers_sub@answers.example',
			'role'       => 'subscriber',
		)
	);
	if ( ! is_wp_error( $subscriber ) ) {
		$answers_users[] = $subscriber;
		wp_set_current_user( $subscriber );
		answers_check( 403 === answers_ask( 'anna@answers.example' )->get_status(), 'subscribers cannot ask' );
		ob_start();
		do_action( 'mailspur_dashboard_widget_top' );
		answers_check( '' === trim( (string) ob_get_clean() ), 'no dashboard line for users without log access' );
		wp_set_current_user( (int) $admins[0] );
	}

	// ----------------------------------------------------------------- health.
	$health = $facts->health();
	$links  = $facts->links();
	answers_check( $baseline + 6 === $health['week']['total'] && 3 === $health['week']['failed'] && 1 === $health['week']['held'], 'counts of the last 7 days', $health['week'] );
	answers_check( 1 === $health['day_failed'], 'failures of the last 24 hours', $health['day_failed'] );
	$text = answers_text( Sentences::health( $health, $links, true ) );
	answers_check( 0 === strpos( $text, '2 things need attention:' ) && false !== strpos( $text, '3 emails failed in the last 7 days. 1 of them in the last 24 hours.' ) && false !== strpos( $text, '1 email was held and not sent' ), 'health: what needs attention', $text );

	$failure = Sentences::failure( (array) $health['failures'], $links );
	answers_check( false !== strpos( answers_text( $failure ), 'Most common reason (2 of them): SMTP login failed.' ), 'most common failure reason', answers_text( $failure ) );

	update_option( 'mailspur_settings', array_merge( (array) $answers_settings, array( 'staging_mode' => 'hold' ) ) );
	$text = answers_text( Sentences::health( $facts->health(), $links, true ) );
	answers_check( false !== strpos( $text, 'Staging mode is on: emails are logged but not sent.' ) && false === strpos( $text, 'was held' ), 'staging mode shows at once (not cached)', $text );
	update_option( 'mailspur_settings', $answers_settings );

	// Cached counts: a new failure shows after the cache is refreshed (deleting through the REST API does it).
	$extra = answers_row( 60, Repository::STATUS_FAILED, 'fay@answers.example', 'Late failure', 'SMTP Error: Could not authenticate.' );
	answers_check( 3 === $facts->health()['week']['failed'], 'counts are cached briefly' );
	$delete = new WP_REST_Request( 'DELETE', '/mailspur-email-log/v1/mails/' . $extra );
	rest_do_request( $delete );
	answers_row( 60, Repository::STATUS_FAILED, 'gil@answers.example', 'Late failure', 'SMTP Error: Could not authenticate.' );
	answers_check( 4 === $facts->health()['week']['failed'], 'a deletion through the REST API refreshes the counts', $facts->health()['week'] );

	// ------------------------------------------------------------ email types.
	TypesStore::maybe_install();
	$store         = new TypesStore();
	$now           = time();
	$daily         = $store->create( 'e2e:answers', array( 'E2E', 'daily', 'digest' ), gmdate( 'Y-m-d H:i:s', $now - 28 * DAY_IN_SECONDS ) );
	$quiet         = $store->create( 'e2e:answers', array( 'E2E', 'stopped', 'reminder' ), gmdate( 'Y-m-d H:i:s', $now - 40 * DAY_IN_SECONDS ) );
	$answers_types = array( $daily, $quiet );
	$counts        = array();
	for ( $back = 1; $back <= 28; $back++ ) {
		$counts[ $daily ][ (string) wp_date( 'Y-m-d', $now - $back * DAY_IN_SECONDS ) ] = array(
			'total'  => 1,
			'failed' => 0,
			'held'   => 0,
		);
	}
	for ( $back = 20; $back <= 40; $back++ ) {
		$counts[ $quiet ][ (string) wp_date( 'Y-m-d', $now - $back * DAY_IN_SECONDS ) ] = array(
			'total'  => 1,
			'failed' => 0,
			'held'   => 0,
		);
	}
	$store->add_days( $counts );
	$types = $store->types();
	foreach ( array(
		$daily => 1,
		$quiet => 20,
	) as $id => $back ) {
		$type                = $types[ $id ];
		$type['last_seen']   = gmdate( 'Y-m-d H:i:s', $now - $back * DAY_IN_SECONDS );
		$type['last_status'] = 1;
		$type['extra']       = $daily === $id ? array(
			'traced'    => 3,
			'cron_n'    => 3,
			'cron_hook' => $answers_hook,
		) : array();
		$store->save( $type );
	}
	$end = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 23, 59, 59 )->getTimestamp();
	$run = min( $now + 60, $end );
	wp_schedule_single_event( $run, $answers_hook );

	Facts::flush();
	$health = $facts->health();
	$due    = array_values(
		array_filter(
			(array) $health['due'],
			static function ( $type ) {
				return 'E2E daily digest' === $type['name'];
			}
		)
	);
	answers_check( 1 === count( $due ) && $due[0]['daily'], 'a daily type not sent today is due', $health['due'] );
	answers_check( isset( $due[0] ) && $run === $due[0]['next'], 'its cron event later today is named', $due );
	$names = array_column( (array) $health['stopped'], 'name' );
	answers_check( in_array( 'E2E stopped reminder', $names, true ), 'a stopped type is listed', $names );
	$text = answers_text( Sentences::missing( $health, $links, $now ) );
	answers_check( 0 === strpos( $text, 'Probably yes: 1 email type has stopped.' ) && false !== strpos( $text, 'Expected today and not sent yet: “E2E daily digest”' ) && false !== strpos( $text, 'usually every day' ), 'missing and due today in plain words', $text );

	// --------------------------------------------------------------- markup.
	ob_start();
	do_action( 'mailspur_render_tab_' . Page::TAB );
	$html = (string) ob_get_clean();
	foreach ( array( 'msa-arrived', 'msa-health', 'msa-missing', 'msa-failure', 'msa-arrived-form' ) as $id ) {
		answers_check( false !== strpos( $html, 'id="' . $id . '"' ), "overview renders #{$id}" );
	}
	answers_check( false !== strpos( $html, 'Is everything running?' ) && false !== strpos( $html, 'things need attention' ), 'overview shows the health answer' );
	answers_check( false === strpos( $html, '<script' ), 'no inline script in the markup' );

	ob_start();
	( new Dashboard( new Stats( new Repository() ) ) )->render();
	$widget = (string) ob_get_clean();
	$line   = strpos( $widget, 'class="msa-dashboard is-warn"' );
	answers_check( false !== $line && $line < (int) strpos( $widget, 'msi-widget-kpis' ), 'dashboard widget: health line on top', substr( $widget, 0, 600 ) );
	answers_check( false !== strpos( $widget, 'things need attention' ) && false !== strpos( $widget, 'tab=overview' ), 'dashboard line links to the overview', substr( $widget, 0, 600 ) );
} catch ( Throwable $e ) {
	answers_check( false, 'answers test crashed', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// ----------------------------------------------------------------- cleanup.
try {
	global $wpdb;
	update_option( 'mailspur_settings', $answers_settings );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s', Repository::table(), 'e2e:answers' ) );
	foreach ( $answers_types as $id ) {
		$wpdb->delete( TypesStore::days_table(), array( 'type_id' => $id ) );
		$wpdb->delete( TypesStore::types_table(), array( 'id' => $id ) );
	}
	wp_clear_scheduled_hook( $answers_hook );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $answers_users as $id ) {
		wp_delete_user( $id );
	}
	Facts::flush();
	Stats::flush();
} catch ( Throwable $e ) {
	answers_check( false, 'cleanup failed', $e->getMessage() );
}

$answers_failed = array_values(
	array_filter(
		$answers_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
file_put_contents(
	'/e2e-out/features/answers.json',
	wp_json_encode(
		array(
			'passed'  => count( $answers_results ) - count( $answers_failed ),
			'failed'  => count( $answers_failed ),
			'results' => $answers_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
