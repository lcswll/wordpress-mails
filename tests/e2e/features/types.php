<?php
/**
 * Email types integration test inside real WordPress (Playground, SQLite): tables, incremental indexing with
 * placeholder and name merging, held/failed counters, the in-flight guard, rhythm-based silence with plugin
 * updates, the REST route and permissions, the type line in the log detail, the "type stopped" alert through
 * the real alert channel, retention pruning and rebuild, probe emails ("Trigger to me") and the core hint.
 *
 * Uses its own sender ("plugin:e2e-types-shop") and removes everything it created.
 * Writes /e2e-out/features/types.json.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Modules\Insights\Alerts;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Modules\Types\Indexer;
use Mailspur\Modules\Types\Module;
use Mailspur\Modules\Types\Monitor;
use Mailspur\Modules\Types\Noise;
use Mailspur\Modules\Types\Page;
use Mailspur\Modules\Types\Quiet;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Senders;
use Mailspur\Modules\Types\Store;
use Mailspur\Modules\Types\Updates;
use Mailspur\Repository;

$types_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function types_check( $ok, $name, $detail = null ) {
	global $types_results;
	$types_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** Inserts a log row at a UTC time. */
function types_row( $utc, $status, $subject, $source = 'plugin:e2e-types-shop', $message = 'x', $meta = '' ) {
	global $wpdb;
	$wpdb->insert(
		Repository::table(),
		array(
			'created_at'  => $utc,
			'status'      => $status,
			'recipients'  => 'customer@example.com',
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => '',
			'attachments' => '',
			'source'      => $source,
			'error'       => '',
			'meta'        => $meta,
			'raw'         => '',
		)
	);
	return (int) $wpdb->insert_id;
}

/** @return array<string,array<string,mixed>> Report items of the test sender by pattern text. */
function types_items( $store ) {
	$out = array();
	foreach ( Report::current( $store, time() ) as $item ) {
		if ( 'plugin:e2e-types-shop' === $item['source'] ) {
			$out[ Report::text( $item['pattern'] ) ] = $item;
		}
	}
	return $out;
}

$types_settings = get_option( 'mailspur_settings', array() );
$types_captured = array();

try {
	global $wpdb;
	$store   = new Store();
	$indexer = new Indexer( $store );
	Store::install();
	$indexer->rebuild();

	$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mailspur_type' ) . '%' ) );
	types_check( 2 === count( $tables ), 'install creates both tables', $tables );

	// 40 days of daily order confirmations that stopped 4 days ago, a weekly report, names to merge.
	$now = time();
	for ( $d = 4; $d <= 43; $d++ ) {
		types_row( gmdate( 'Y-m-d 10:00:00', $now - $d * DAY_IN_SECONDS ), 1, 'Order #' . ( 5000 + $d ) . ' confirmed' );
	}
	types_row( gmdate( 'Y-m-d 11:00:00', $now - 5 * DAY_IN_SECONDS ), 2, 'Order #4999 confirmed' );
	types_row( gmdate( 'Y-m-d 11:00:00', $now - 6 * DAY_IN_SECONDS ), 3, 'Order #4998 confirmed' );
	foreach ( array( 'Anna', 'Ben', 'Chloé' ) as $i => $name ) {
		types_row( gmdate( 'Y-m-d H:i:s', $now - ( $i + 1 ) * HOUR_IN_SECONDS ), 1, "Welcome to the shop, {$name}!" );
	}
	types_row( gmdate( 'Y-m-d H:i:s', $now - 2 * HOUR_IN_SECONDS ), 1, 'Your order is complete' );
	types_row( gmdate( 'Y-m-d H:i:s', $now - 2 * HOUR_IN_SECONDS ), 1, 'Your order is cancelled' );
	// In flight: still "unknown" and just logged – must wait.
	$in_flight = types_row( gmdate( 'Y-m-d H:i:s', $now - 30 ), 0, 'Order #9999 confirmed' );
	types_row( gmdate( 'Y-m-d H:i:s', $now - 20 ), 1, 'Order #9998 confirmed' );
	// Resends are copies, not types.
	types_row( gmdate( 'Y-m-d H:i:s', $now - 3 * HOUR_IN_SECONDS ), 1, 'Order #1 confirmed', 'mailspur:resend' );

	update_option(
		Updates::OPTION,
		array(
			array(
				'time'  => $now - 3 * DAY_IN_SECONDS,
				'label' => 'E2E Shop 2.0',
				'slug'  => 'plugin:e2e-types-shop',
			),
		),
		false
	);

	$read  = $indexer->run( 100000 );
	$items = types_items( $store );
	types_check( $read > 0 && (int) get_option( Indexer::CURSOR ) === $in_flight - 1, 'indexer stops before an email still in flight', array( $read, get_option( Indexer::CURSOR ), $in_flight ) );
	types_check( isset( $items['Order #… confirmed'] ), 'numbers become one type', array_keys( $items ) );
	types_check( isset( $items['Welcome to the shop, …!'] ), 'names merge into one type', array_keys( $items ) );
	types_check( isset( $items['Your order is complete'], $items['Your order is cancelled'] ), 'different wording stays apart', array_keys( $items ) );
	types_check( 4 === count( $items ), 'four types for the test sender', array_keys( $items ) );

	$order = $items['Order #… confirmed'] ?? array();
	types_check( 'silent' === ( $order['state'] ?? '' ), 'daily type that stopped is "silent"', $order['state'] ?? null );
	types_check( 2 === ( $order['rhythm']['expected'] ?? 0 ) && $order['rhythm']['regular'], 'learned daily rhythm', $order['rhythm'] ?? null );
	types_check( array( 'E2E Shop 2.0' ) === array_column( $order['updates'] ?? array(), 'label' ), 'update since the last email is named', $order['updates'] ?? null );
	types_check( 1 === ( $order['failed'] ?? 0 ) && 1 === ( $order['held'] ?? 0 ), 'failed and held counted', array( $order['failed'] ?? null, $order['held'] ?? null ) );
	types_check( 'new' === ( $items['Welcome to the shop, …!']['state'] ?? '' ), 'fresh type is "new"', $items['Welcome to the shop, …!']['state'] ?? null );

	// Settled after 10 minutes: the in-flight email is indexed on a later run.
	$wpdb->update( Repository::table(), array( 'status' => 1 ), array( 'id' => $in_flight ) );
	$indexer->run( 100000 );
	$items = types_items( $store );
	types_check( 'silent' !== ( $items['Order #… confirmed']['state'] ?? '' ), 'a new email ends the silence', $items['Order #… confirmed']['state'] ?? null );
	types_check( 0 === $indexer->run( 100000 ), 'nothing left to index', get_option( Indexer::CURSOR ) );

	// REST: permission and payload.
	wp_set_current_user( 0 );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/types' ) );
	types_check( in_array( $response->get_status(), array( 401, 403 ), true ), 'REST /types needs the log capability', $response->get_status() );

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins ? $admins[0]->ID : 1 );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/types' ) );
	$data     = $response->get_data();
	$labels   = array_column( (array) ( $data['types'] ?? array() ), 'label' );
	types_check( 200 === $response->get_status() && in_array( 'Order #… confirmed', $labels, true ), 'REST /types lists the types', array( $response->get_status(), $labels ) );
	$first = array();
	foreach ( (array) ( $data['types'] ?? array() ) as $type ) {
		if ( 'Order #… confirmed' === $type['label'] ) {
			$first = $type;
		}
	}
	types_check( 30 === count( $first['series'] ?? array() ) && false !== strpos( (string) ( $first['logUrl'] ?? '' ), '&s=confirmed' ), 'REST item has 30 days and a log link', $first );
	types_check( ! preg_match( '/customer@example\.com/', (string) wp_json_encode( $data ) ), 'REST payload has no recipients', null );

	// Detail view of a logged email names its type.
	$detail = rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/mails/' . $in_flight ) )->get_data();
	types_check( 'Order #… confirmed' === ( $detail['mailtype']['label'] ?? '' ) && 'Daily' === ( $detail['mailtype']['rhythm'] ?? '' ), 'log detail shows the email type', $detail['mailtype'] ?? null );

	// Alert through the real alert channel (captured email).
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND subject IN ( %s, %s )', Repository::table(), 'plugin:e2e-types-shop', 'Order #9999 confirmed', 'Order #9998 confirmed' ) );
	$indexer->rebuild();
	$indexer->run( 100000 );
	update_option(
		'mailspur_settings',
		array_merge(
			(array) $types_settings,
			array(
				'alert_types'    => true,
				'alert_email'    => 'ops@example.com',
				'alert_recovery' => true,
			)
		)
	);
	add_filter(
		'pre_wp_mail',
		static function ( $short, $atts ) use ( &$types_captured ) {
			$types_captured[] = $atts;
			return true;
		},
		PHP_INT_MIN,
		2
	);
	( new Monitor( $store, array( new Alerts(), 'dispatch' ) ) )->run();
	$alert = $types_captured[0] ?? array();
	types_check( 1 === count( $types_captured ) && false !== strpos( (string) ( $alert['subject'] ?? '' ), 'Email type stopped' ), 'stopped type sends one alert', $types_captured );
	types_check( false !== strpos( (string) ( $alert['message'] ?? '' ), '“Order #… confirmed”' ) && false !== strpos( (string) ( $alert['message'] ?? '' ), 'E2E Shop 2.0' ) && false !== strpos( (string) ( $alert['message'] ?? '' ), 'tab=types' ), 'alert names type, update and links the tab', $alert['message'] ?? null );
	( new Monitor( $store, array( new Alerts(), 'dispatch' ) ) )->run();
	types_check( 1 === count( $types_captured ), 'no repeated alert while still stopped', count( $types_captured ) );
	$history = Alerts::history();
	types_check( 'type' === ( $history[0]['type'] ?? '' ), 'alert appears in the alert history', $history[0] ?? null );

	types_row( gmdate( 'Y-m-d H:i:s', $now - 15 * MINUTE_IN_SECONDS ), 1, 'Order #10001 confirmed' );
	$indexer->run( 100000 );
	( new Monitor( $store, array( new Alerts(), 'dispatch' ) ) )->run();
	types_check( 2 === count( $types_captured ) && false !== strpos( (string) ( $types_captured[1]['subject'] ?? '' ), 'Email type is sent again' ), 'recovery message when the type is back', array_column( $types_captured, 'subject' ) );

	// Muted types neither alert nor count as attention.
	$order_id = (int) ( types_items( $store )['Order #… confirmed']['id'] ?? 0 );
	$store->mute( $order_id, true );
	types_check( 'muted' === ( types_items( $store )['Order #… confirmed']['state'] ?? '' ), 'mute works', null );

	// Retention: day rows before the cutoff go, types without days go with them.
	$store->prune( gmdate( 'Y-m-d', $now - 10 * DAY_IN_SECONDS ) );
	$days = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE day < %s', Store::days_table(), gmdate( 'Y-m-d', $now - 10 * DAY_IN_SECONDS ) ) );
	types_check( 0 === $days && isset( types_items( $store )['Order #… confirmed'] ), 'prune drops old days, keeps active types', $days );
	types_check( Module::cutoff( 30, $now ) === wp_date( 'Y-m-d', $now - 30 * DAY_IN_SECONDS ) && Module::cutoff( 0, $now ) === wp_date( 'Y-m-d', $now - 365 * DAY_IN_SECONDS ), 'cutoff follows retention, max one year', null );

	// Emptied log: the next run starts over.
	$indexer->rebuild();
	types_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Store::types_table() ) ) && 0 === (int) get_option( Indexer::CURSOR ), 'rebuild clears types', null );

	// Plugin updates are recorded.
	delete_option( Updates::OPTION );
	Updates::record(
		null,
		array(
			'action'  => 'update',
			'type'    => 'plugin',
			'plugins' => array( 'mailspur-email-log/mailspur-email-log.php' ),
		)
	);
	$recorded = Updates::all();
	types_check( 'plugin:mailspur-email-log' === ( $recorded[0]['slug'] ?? '' ) && false !== strpos( (string) ( $recorded[0]['label'] ?? '' ), 'Mailspur' ), 'plugin update recorded with name and version', $recorded );
} catch ( Throwable $e ) {
	types_check( false, 'exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Before/after content changes, the cron cause of a stopped type and the "send latest to me" shortcut.
try {
	global $wpdb;
	$store   = new Store();
	$indexer = new Indexer( $store );
	$indexer->rebuild();
	$now = time();
	wp_clear_scheduled_hook( 'mailspur_e2e_reminder' );

	$digest = static function ( $n, $link ) {
		return '<html><head><style>p{margin:0}</style></head><body><p>Hi Anna,</p><p>Here is your digest number ' . $n . ' with ' . ( $n * 3 ) . ' new posts.</p>'
			. ( $link ? '<p><a href="https://news.example/read/' . $n . '/?token=e2e-secret">Read online</a></p>' : '' )
			. '<p>Unsubscribe any time.</p></body></html>';
	};
	$news   = array();
	for ( $d = 20; $d >= 11; $d-- ) {
		$news[] = types_row( gmdate( 'Y-m-d 08:00:00', $now - $d * DAY_IN_SECONDS ), 1, 'Your digest #' . ( 100 - $d ), 'plugin:e2e-types-news', $digest( 100 - $d, $d > 12 ), '' );
	}
	update_option(
		Updates::OPTION,
		array(
			array(
				'time'  => (int) strtotime( gmdate( 'Y-m-d 20:00:00', $now - 13 * DAY_IN_SECONDS ) . ' UTC' ), // Between the two emails.
				'label' => 'E2E News 3.0',
				'slug'  => 'plugin:e2e-types-news',
			),
		),
		false
	);

	$cron_meta = (string) wp_json_encode(
		array(
			'trace' => array(
				'request' => array(
					'type' => 'cron',
					'hook' => 'mailspur_e2e_reminder',
				),
			),
		)
	);
	for ( $d = 33; $d >= 4; $d-- ) {
		types_row( gmdate( 'Y-m-d 06:00:00', $now - $d * DAY_IN_SECONDS ), 1, 'Daily reminder', 'plugin:e2e-types-cron', 'Your reminder for today.', $cron_meta );
	}

	$indexer->run( 100000 );
	$by_source = array();
	foreach ( Report::current( $store, time() ) as $item ) {
		$by_source[ $item['source'] ] = $item;
	}
	$digest_item = $by_source['plugin:e2e-types-news'] ?? array();
	$change      = $digest_item['change'] ?? null;
	types_check( is_array( $change ) && $news[8] === $change['after'] && $news[7] === $change['before'], 'content change points at the last email before and the first after it', $change );
	types_check( array( 'E2E News 3.0' ) === ( $change['updates'] ?? null ), 'content change names the update in between', $change );
	$latest = (int) ( $digest_item['last_id'] ?? 0 );
	types_check( $news[9] === $latest && false !== strpos( Page::log_url( $digest_item, true ), 'mail=' . $news[9] ), '"Open latest" links to the latest email', $latest );

	$compare = rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/types/' . (int) ( $digest_item['id'] ?? 0 ) . '/compare' ) );
	$cdata   = (array) $compare->get_data();
	$removed = array();
	foreach ( (array) ( $cdata['diff'] ?? array() ) as $op ) {
		if ( '-' === $op[0] ) {
			$removed[] = $op[1];
		}
	}
	types_check( 200 === $compare->get_status() && true === ( $cdata['available'] ?? null ) && array( 'Read online [https://news.example/read/87/]' ) === $removed, 'compare: line diff shows only the removed link', array( $compare->get_status(), $cdata['diff'] ?? $cdata ) );
	types_check( ! empty( $cdata['before']['isHtml'] ) && false === strpos( (string) wp_json_encode( $cdata['diff'] ?? array() ), 'e2e-secret' ), 'compare: previews are HTML, diff has no query strings', null );

	wp_set_current_user( 0 );
	$denied = rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/types/' . (int) ( $digest_item['id'] ?? 0 ) . '/compare' ) );
	types_check( in_array( $denied->get_status(), array( 401, 403 ), true ), 'compare needs the log capability', $denied->get_status() );
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins ? $admins[0]->ID : 1 );

	Report::mark_seen( (int) $digest_item['id'], (int) $change['after'], $store->types() );
	$seen = null;
	foreach ( Report::current( $store, time() ) as $item ) {
		if ( 'plugin:e2e-types-news' === $item['source'] ) {
			$seen = $item;
		}
	}
	types_check( is_array( $seen ) && array_key_exists( 'change', $seen ) && null === $seen['change'], '"Seen" clears the marker', $seen );

	$wpdb->update(
		Repository::table(),
		array(
			'message' => '',
			'meta'    => '{"anonymised":{"at":1}}',
		),
		array( 'id' => $news[7] )
	);
	$gone = (array) rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/types/' . (int) ( $digest_item['id'] ?? 0 ) . '/compare' ) )->get_data();
	types_check( false === ( $gone['available'] ?? null ) && ! isset( $gone['diff'] ) && '' !== (string) ( $gone['reason'] ?? '' ), 'compare is unavailable once an email was anonymised', $gone );

	// Cron diagnosis.
	$cron_item = $by_source['plugin:e2e-types-cron'] ?? array();
	types_check( 'silent' === ( $cron_item['state'] ?? '' ) && 'mailspur_e2e_reminder' === ( $cron_item['cron'] ?? null ), 'cron type learnt from the trace', array( $cron_item['state'] ?? null, $cron_item['cron'] ?? null ) );
	types_check( 'unscheduled' === ( $cron_item['cause']['code'] ?? '' ) && false !== strpos( Monitor::message( $cron_item, time() ), 'mailspur_e2e_reminder – this event is no longer scheduled' ), 'unscheduled cron event is the cause, also in the alert text', $cron_item['cause'] ?? null );
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mailspur_e2e_reminder' );
	$cause = null;
	foreach ( Report::current( $store, time() ) as $item ) {
		if ( 'plugin:e2e-types-cron' === $item['source'] ) {
			$cause = $item['cause'];
		}
	}
	types_check( in_array( $cause['code'] ?? '', array( 'running', 'stalled' ), true ) && false !== strpos( (string) ( $cause['text'] ?? '' ), 'mailspur_e2e_reminder' ), 'scheduled again: cause names the event and the cron state', $cause );
	wp_clear_scheduled_hook( 'mailspur_e2e_reminder' );

	// A real email from a cron request records its cron hook in the trace.
	add_filter( 'wp_doing_cron', '__return_true' );
	add_action(
		'mailspur_e2e_cron_send',
		static function () {
			wp_mail( 'cron@example.com', 'E2E cron trace', 'Sent from cron.' );
		}
	);
	do_action( 'mailspur_e2e_cron_send' );
	remove_filter( 'wp_doing_cron', '__return_true' );
	$traced = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta FROM %i WHERE subject = %s ORDER BY id DESC LIMIT 1', Repository::table(), 'E2E cron trace' ) );
	types_check( 'mailspur_e2e_cron_send' === Indexer::cron_hook( $traced ), 'trace stores the cron hook of the request', $traced );

	// "Send latest to me": the core resend route with the current user's address.
	$before_count = count( $types_captured );
	$resend       = new WP_REST_Request( 'POST', '/mailspur-email-log/v1/mails/' . $news[9] . '/resend' );
	$resend->set_param( 'to', array( 'me@example.com' ) );
	$sent = (array) rest_do_request( $resend )->get_data();
	$last = $types_captured[ count( $types_captured ) - 1 ] ?? array();
	types_check( ! empty( $sent['sent'] ) && count( $types_captured ) === $before_count + 1 && array( 'me@example.com' ) === (array) ( $last['to'] ?? array() ), 'latest email of a type is sent to one address', array( $sent, $last['to'] ?? null ) );
} catch ( Throwable $e ) {
	types_check( false, 'exception (before/after, cron)', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Probe emails ("Trigger to me"): real core emails for the administrator's own account, sent only to them.
$probe_ids = array();
try {
	global $wpdb;
	$probe_sent = array();
	$probe_trap = static function ( $short, $atts ) use ( &$probe_sent ) {
		$probe_sent[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $probe_trap, PHP_INT_MIN, 2 );
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	$admin  = $admins ? $admins[0] : wp_get_current_user();
	wp_set_current_user( $admin->ID );

	$request = new WP_REST_Request( 'POST', '/mailspur-email-log/v1/types/probe' );
	$request->set_param( 'kind', 'password-reset' );
	$response  = rest_do_request( $request );
	$mails     = (array) ( $response->get_data()['mails'] ?? array() );
	$probe_ids = array_map( 'intval', array_column( $mails, 'id' ) );
	$row       = $probe_ids ? (array) $wpdb->get_row( $wpdb->prepare( 'SELECT recipients, source, meta FROM %i WHERE id = %d', Repository::table(), $probe_ids[0] ), ARRAY_A ) : array();
	types_check( 200 === $response->get_status() && 1 === count( $mails ) && 'sent' === ( $mails[0]['status'] ?? '' ) && false !== strpos( (string) ( $mails[0]['url'] ?? '' ), 'mail=' . $probe_ids[0] ), 'probe: password reset is sent and links its log entry', $response->get_data() );
	types_check( ( $row['recipients'] ?? '' ) === $admin->user_email && 'core' === ( $row['source'] ?? '' ) && false !== strpos( (string) ( $row['meta'] ?? '' ), '"probe":"password-reset"' ), 'probe: logged as core email to the administrator, marked as probe', $row );

	$request->set_param( 'kind', 'new-user' );
	$mails     = (array) ( rest_do_request( $request )->get_data()['mails'] ?? array() );
	$probe_ids = array_merge( $probe_ids, array_map( 'intval', array_column( $mails, 'id' ) ) );
	$to        = array_unique( array_map( 'wp_json_encode', array_column( $probe_sent, 'to' ) ) );
	types_check( 2 === count( $mails ) && 3 === count( $probe_sent ) && array( wp_json_encode( array( $admin->user_email ) ) ) === array_values( $to ), 'probe: new user notifications reach only the administrator', array( $mails, $to ) );

	// The type of the probed email offers the probe again; core types explain that they have no editor.
	( new Indexer( new Store() ) )->run( 100000 );
	$types = (array) ( rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/types' ) )->get_data()['types'] ?? array() );
	$reset = array();
	foreach ( $types as $type ) {
		if ( 'core' === $type['source'] && 'password-reset' === $type['probe'] ) {
			$reset = $type;
		}
	}
	types_check( $reset && '' === $reset['template']['url'] && '' !== $reset['template']['hint'], 'REST /types: core type has a hint and its probe', $reset );

	wp_set_current_user( 0 );
	$denied = rest_do_request( $request );
	types_check( in_array( $denied->get_status(), array( 401, 403 ), true ), 'probe needs manage_options', $denied->get_status() );
	wp_set_current_user( $admin->ID );
	remove_filter( 'pre_wp_mail', $probe_trap, PHP_INT_MIN );
} catch ( Throwable $e ) {
	types_check( false, 'exception (probe)', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Admin noise, slow types, new senders and the quiet switch.
try {
	global $wpdb;
	$store   = new Store();
	$indexer = new Indexer( $store );
	$indexer->rebuild();
	$now = time();
	// Mailspur watches since 30 days: a sender first seen 20 days ago is still baseline, one from today is new.
	// Senders already in the log (seed, other features) are old acquaintances.
	update_option(
		Senders::OPTION,
		array(
			'since'   => $now - 30 * DAY_IN_SECONDS,
			'known'   => array_fill_keys( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source FROM %i', Repository::table() ) ) ), $now - 60 * DAY_IN_SECONDS ),
			'alerted' => array(),
		),
		false
	);
	$slow_meta   = static function ( $type, $origin ) {
		return (string) wp_json_encode(
			array(
				'trace' => array(
					'origin'    => array( 'function' => $origin ),
					'request'   => array( 'type' => $type ),
					'transport' => array( 'mailer' => 'smtp' ),
					'total_ms'  => 2400.5,
				),
			)
		);
	};
	$admin_email = (string) get_option( 'admin_email' );
	for ( $i = 0; $i < 34; $i++ ) {
		$id = types_row( gmdate( 'Y-m-d H:i:s', $now - ( 20 * DAY_IN_SECONDS ) + $i * 12 * HOUR_IN_SECONDS ), 1, 'Please moderate: "Post ' . $i . '"', 'plugin:e2e-types-noise', 'x', $i < 8 ? $slow_meta( 'frontend', 'wp_notify_moderator' ) : $slow_meta( 'cron', 'wp_notify_moderator' ) );
		$wpdb->update( Repository::table(), array( 'recipients' => 'Admin <' . strtoupper( $admin_email ) . '>' ), array( 'id' => $id ) );
	}
	for ( $i = 0; $i < 30; $i++ ) {
		$id = types_row( gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS + $i * 60 ), 1, 'Special offer ' . $i, 'plugin:e2e-types-fresh' );
		$wpdb->update( Repository::table(), array( 'recipients' => 'person' . $i . '@e2e-recipients.test' ), array( 'id' => $id ) );
	}
	types_row( gmdate( 'Y-m-d H:i:s', $now - 2 * HOUR_IN_SECONDS ), 1, '[Site] Some plugins were automatically updated', 'plugin:e2e-types-updates', 'x', $slow_meta( 'cron', 'WP_Automatic_Updater::send_plugin_theme_email' ) );
	$indexer->run( 100000 );

	$by_source = array();
	foreach ( Report::current( $store, time() ) as $item ) {
		$by_source[ $item['source'] ] = $item;
	}
	$noisy = $by_source['plugin:e2e-types-noise'] ?? array();
	types_check( 34 === ( $noisy['admin'] ?? null ) && true === ( $noisy['noise'] ?? null ), 'emails to the admin address are counted, type is noise', array( $noisy['admin'] ?? null, $noisy['noise'] ?? null ) );
	types_check( 'wp_notify_moderator' === ( $noisy['origin'] ?? '' ) && false !== strpos( (string) ( Noise::fix( $noisy['origin'], $noisy['source'] )['url'] ?? '' ), 'options-discussion.php' ), 'moderation emails link to Settings › Discussion', $noisy['origin'] ?? null );
	types_check( 2401 === ( $noisy['slow']['median'] ?? null ) && 8 === ( $noisy['slow']['n'] ?? null ), 'only emails someone waited for count as slow', $noisy['slow'] ?? null );
	types_check( false === ( $by_source['plugin:e2e-types-fresh']['noise'] ?? null ) && array_key_exists( 'slow', $by_source['plugin:e2e-types-fresh'] ?? array() ) && null === $by_source['plugin:e2e-types-fresh']['slow'], 'no noise or slowness without a reason', $by_source['plugin:e2e-types-fresh'] ?? null );
	types_check( true === ( $by_source['plugin:e2e-types-fresh']['new_sender'] ?? null ) && false === ( $noisy['new_sender'] ?? null ), 'new sender after the baseline, not during it', array( $by_source['plugin:e2e-types-fresh']['new_sender'] ?? null, $noisy['new_sender'] ?? null ) );
	$stored = implode( ' ', array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT extra FROM %i', Store::types_table() ) ) ) );
	types_check( '' !== $stored && false === stripos( $stored, $admin_email ) && false === strpos( $stored, 'e2e-recipients' ), 'type state stores no addresses', null );

	$sent    = array();
	$senders = new Senders();
	$alerted = $senders->check(
		static function ( $type, $kind, $message ) use ( &$sent ) {
			$sent[] = array( $type, $kind, $message );
			return array();
		},
		array()
	);
	types_check( array( 'plugin:e2e-types-fresh' ) === $alerted && 'sender' === ( $sent[0][0] ?? '' ) && false !== strpos( (string) ( $sent[0][2] ?? '' ), '30 different external addresses' ) && false === strpos( (string) ( $sent[0][2] ?? '' ), '@' ), 'new sender writing to many external addresses alerts, without addresses', $sent );
	types_check( array() === $senders->check( '__return_empty_array', array() ), 'the new-sender alert is sent once', null );
	types_check( 'New sender writes to many addresses' === Alerts::title( 'sender', 'alert' ), 'alert title for new senders', null );

	// Page: summary counts and row markers.
	ob_start();
	( new Page( $store, $indexer ) )->render();
	$html = (string) ob_get_clean();
	types_check( false !== strpos( $html, 'often to administrators' ) && false !== strpos( $html, '34 to administrators in 30 days' ) && false !== strpos( $html, 'Waits 2.4 s for the mail server' ) && false !== strpos( $html, 'mst-flag is-fresh' ), 'tab shows noise, slow and new-sender markers', null );

	// Quiet switch: stops the success notices and ignores the matching type.
	$updates_id = (int) ( $by_source['plugin:e2e-types-updates']['id'] ?? 0 );
	Quiet::set( $store, Quiet::UPDATES, true );
	$types = $store->types();
	types_check( array( Quiet::UPDATES ) === Quiet::active() && ! empty( $types[ $updates_id ]['muted'] ), 'quiet switch is stored and ignores the type', Quiet::active() );
	Quiet::register();
	$ok = (object) array( 'result' => true );
	types_check( false === apply_filters( 'auto_plugin_update_send_email', true, array( $ok ) ) && true === apply_filters( 'auto_plugin_update_send_email', true, array( (object) array( 'result' => false ) ) ) && false === apply_filters( 'auto_core_update_send_email', true, 'success' ), 'core filters drop only success notices', null );
	remove_filter( 'auto_plugin_update_send_email', array( Quiet::class, 'plugin_theme_email' ) );
	remove_filter( 'auto_theme_update_send_email', array( Quiet::class, 'plugin_theme_email' ) );
	remove_filter( 'auto_core_update_send_email', array( Quiet::class, 'core_email' ) );
	Quiet::set( $store, Quiet::UPDATES, false );
	$types = $store->types();
	types_check( array() === Quiet::active() && empty( $types[ $updates_id ]['muted'] ), 'quiet switch can be turned off again', Quiet::active() );
} catch ( Throwable $e ) {
	types_check( false, 'exception (noise, speed, senders)', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

try {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source IN ( %s, %s, %s )', Repository::table(), 'plugin:e2e-types-noise', 'plugin:e2e-types-fresh', 'plugin:e2e-types-updates' ) );
	delete_option( Senders::OPTION );
	delete_option( Quiet::OPTION );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source IN ( %s, %s ) OR subject = %s OR ( source = %s AND recipients = %s )', Repository::table(), 'plugin:e2e-types-news', 'plugin:e2e-types-cron', 'E2E cron trace', 'mailspur:resend', 'me@example.com' ) );
	wp_clear_scheduled_hook( 'mailspur_e2e_reminder' );
	delete_option( Report::SEEN );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s OR ( source = %s AND subject = %s )', Repository::table(), 'plugin:e2e-types-shop', 'mailspur:resend', 'Order #1 confirmed' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND recipients = %s', Repository::table(), Stats::ALERT_SOURCE, 'ops@example.com' ) );
	foreach ( $probe_ids as $probe_id ) {
		$wpdb->delete( Repository::table(), array( 'id' => $probe_id ) );
	}
	( new Indexer( new Store() ) )->rebuild();
	update_option( 'mailspur_settings', $types_settings );
	foreach ( array( Updates::OPTION, Monitor::STATE, Alerts::LOG_OPTION, Alerts::STATE_OPTION ) as $option ) {
		delete_option( $option );
	}
} catch ( Throwable $e ) {
	types_check( false, 'cleanup failed', $e->getMessage() );
}

$types_failed = array_values(
	array_filter(
		$types_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
file_put_contents(
	'/e2e-out/features/types.json',
	wp_json_encode(
		array(
			'passed'  => count( $types_results ) - count( $types_failed ),
			'failed'  => count( $types_failed ),
			'results' => $types_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
