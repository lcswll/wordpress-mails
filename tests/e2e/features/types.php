<?php
/**
 * Email types integration test inside real WordPress (Playground, SQLite): tables, incremental indexing with
 * placeholder and name merging, held/failed counters, the in-flight guard, rhythm-based silence with plugin
 * updates, the REST route and permissions, the type line in the log detail, the "type stopped" alert through
 * the real alert channel, retention pruning and rebuild.
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
use Mailspur\Modules\Types\Report;
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
function types_row( $utc, $status, $subject, $source = 'plugin:e2e-types-shop' ) {
	global $wpdb;
	$wpdb->insert(
		Repository::table(),
		array(
			'created_at'  => $utc,
			'status'      => $status,
			'recipients'  => 'customer@example.com',
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

try {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s OR ( source = %s AND subject = %s )', Repository::table(), 'plugin:e2e-types-shop', 'mailspur:resend', 'Order #1 confirmed' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND recipients = %s', Repository::table(), Stats::ALERT_SOURCE, 'ops@example.com' ) );
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
