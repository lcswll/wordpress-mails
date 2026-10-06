<?php
/**
 * Email types: "Bundle into one daily email" and "Keep for …" inside real WordPress (Playground) with real
 * wp_mail() calls – bundled emails to the admin are held, the exclusions are delivered, one digest per run lists
 * every held email and marks them as delivered (never twice), overdue digests, "Send now", switching bundling off,
 * the type counters, the menu entries, and the retention per type (shorter, until the log limit, anonymised
 * instead of deleted, the inventory column).
 *
 * Delivery is captured by a fake API mailer on pre_wp_mail. Removes everything it created.
 * Writes /e2e-out/features/types-digest.json.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Cleanup;
use Mailspur\Logger;
use Mailspur\Modules\Types\Bundle;
use Mailspur\Modules\Types\Digest;
use Mailspur\Modules\Types\Fingerprint;
use Mailspur\Modules\Types\Indexer;
use Mailspur\Modules\Types\Inventory;
use Mailspur\Modules\Types\Page;
use Mailspur\Modules\Types\Store;
use Mailspur\Repository;
use Mailspur\Settings;

$digest_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function digest_check( $ok, $name, $detail = null ) {
	global $digest_results;
	$digest_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return array<string,mixed> */
function digest_last_row() {
	global $wpdb;
	$row         = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Repository::table() ), ARRAY_A );
	$row['meta'] = json_decode( (string) ( $row['meta'] ?? '' ), true );
	return $row;
}

/** @return array<string,mixed> */
function digest_row( $id ) {
	$row         = (array) ( new Repository() )->find( (int) $id );
	$row['meta'] = json_decode( (string) ( $row['meta'] ?? '' ), true );
	return $row;
}

/** Inserts a log row at a UTC time. */
function digest_insert( $utc, $subject, $source ) {
	global $wpdb;
	$wpdb->insert(
		Repository::table(),
		array(
			'created_at'  => $utc,
			'status'      => 1,
			'recipients'  => 'customer@example.com',
			'subject'     => $subject,
			'message'     => 'Hello',
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

global $wpdb;
$digest_settings = get_option( Settings::OPTION );
$digest_start    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Repository::table() ) );
$digest_sent     = array();
$digest_types    = array();
$digest_file     = '';

// Fake API mailer: records what would be delivered (staging mode, the brake and bundling decide before it).
$digest_mailer = static function ( $result, $atts ) use ( &$digest_sent ) {
	if ( null !== $result ) {
		return $result;
	}
	$digest_sent[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $digest_mailer, 10, 2 );

try {
	wp_set_current_user( 1 );
	update_option( Settings::OPTION, array_merge( Settings::all(), array( 'staging_mode' => 'off' ) ) );
	Store::install();
	$store = new Store();
	$admin = (string) get_option( 'admin_email' );

	// A bundled core type (emails sent from this script count as "core").
	$type         = $store->create( 'core', Fingerprint::tokens( '[E2E Digest] Please moderate: "Post"' ), current_time( 'mysql', true ) );
	$other        = $store->create( 'core', Fingerprint::tokens( '[E2E Digest] Other notice' ), current_time( 'mysql', true ) );
	$digest_types = array( $type, $other );
	$store->bundle( $type, true );
	Bundle::sync( $store );
	Digest::schedule();
	digest_check( array( $type ) === array_keys( Bundle::map() ) && false !== wp_next_scheduled( Digest::HOOK ), 'bundling a type mirrors it for send time and schedules the daily digest', Bundle::map() );

	// --------------------------------------------------------------- held.
	$sent = wp_mail( $admin, '[E2E Digest] Please moderate: "First post"', "A new comment is waiting for your approval\nhttps://example.com/wp-admin/comment.php?action=approve&c=1" );
	$held = digest_last_row();
	digest_check( true === $sent && array() === $digest_sent, 'bundled email to the admin is not delivered (wp_mail() reports success)', $digest_sent );
	digest_check( Repository::STATUS_HELD === (int) $held['status'] && Bundle::HELD === ( $held['meta']['delivery']['held'] ?? '' ) && (int) ( $held['meta']['delivery']['bundle'] ?? 0 ) === $type, 'bundled email is logged as held with reason "bundled"', $held );
	wp_mail( 'Admin <' . strtoupper( $admin ) . '>', '[E2E Digest] Please moderate: "Second post"', '<p>Another comment on <a href="https://example.com/?p=2">Second post</a></p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
	$held2 = digest_last_row();
	digest_check( Repository::STATUS_HELD === (int) $held2['status'] && array() === $digest_sent, 'second bundled email is held too', $held2 );

	// ---------------------------------------------------------- exclusions.
	$excluded = array();
	wp_mail( 'customer@example.com', '[E2E Digest] Please moderate: "Customer"', 'Body' );
	$excluded['customer'] = digest_last_row();
	wp_mail( $admin, '[E2E Digest] Please moderate: "Cc"', 'Body', array( 'Cc: customer@example.com' ) );
	$excluded['cc'] = digest_last_row();
	$digest_file    = wp_upload_dir()['basedir'] . '/e2e-digest.txt';
	file_put_contents( $digest_file, 'x' );
	wp_mail( $admin, '[E2E Digest] Please moderate: "Attachment"', 'Body', array(), array( $digest_file ) );
	$excluded['attachment'] = digest_last_row();
	apply_filters( 'retrieve_password_message', 'Reset', 'key', 'user', null );
	wp_mail( $admin, '[E2E Digest] Please moderate: "Reset"', 'Body' );
	$excluded['password reset'] = digest_last_row();
	Logger::$source_override    = 'mailspur:alert';
	wp_mail( $admin, '[E2E Digest] Please moderate: "Alert"', 'Body' );
	Logger::$source_override = '';
	$excluded['own alert']   = digest_last_row();
	$exempt                  = static function () {
		return true;
	};
	add_filter( 'mailspur_brake_exempt', $exempt );
	wp_mail( $admin, '[E2E Digest] Please moderate: "Exempt"', 'Body' );
	remove_filter( 'mailspur_brake_exempt', $exempt );
	$excluded['brake exempt'] = digest_last_row();
	wp_mail( $admin, '[E2E Digest] Other notice', 'Body' );
	$excluded['other type'] = digest_last_row();
	foreach ( $excluded as $why => $row ) {
		digest_check( Repository::STATUS_HELD !== (int) $row['status'] && empty( $row['meta']['delivery']['held'] ), 'never bundled: ' . $why, $row );
	}
	digest_check( count( $excluded ) === count( $digest_sent ), 'every excluded email was delivered', count( $digest_sent ) );

	// "Send now" takes an email out of the digest.
	wp_mail( $admin, '[E2E Digest] Please moderate: "Released"', 'Body' );
	$released = digest_last_row();
	do_action( 'mailspur_held_released', ( new Repository() )->find( (int) $released['id'] ) );
	digest_check( Bundle::RELEASED === ( digest_row( $released['id'] )['meta']['delivery']['held'] ?? '' ), '"Send now" marks the email as released from the digest', digest_row( $released['id'] )['meta'] );

	// --------------------------------------------------------------- digest.
	$indexer = new Indexer( $store );
	$indexer->run( 100000 );
	$days       = $store->days_since( gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), $type );
	$held_count = 0;
	foreach ( $days[ $type ] ?? array() as $count ) {
		$held_count += $count['held'];
	}
	digest_check( 3 === $held_count, 'type counters see the held emails', $days );

	$digest_sent = array();
	$digest      = new Digest( $store );
	$result      = $digest->run();
	$mail        = $digest_sent[0] ?? array();
	digest_check( 1 === count( $digest_sent ) && 2 === $result['entries'] && 1 === $result['sent'], 'one digest for the two waiting emails', array( $result, count( $digest_sent ) ) );
	digest_check( ( is_array( $mail['to'] ?? null ) ? implode( ',', $mail['to'] ) : (string) ( $mail['to'] ?? '' ) ) === $admin, 'digest goes to the administrator', $mail['to'] ?? null );
	digest_check( false !== strpos( (string) ( $mail['subject'] ?? '' ), '2 notifications: Please moderate:' ), 'digest subject counts the emails and names the type', $mail['subject'] ?? null );
	$body = (string) ( $mail['message'] ?? '' );
	digest_check( false !== strpos( $body, 'First post' ) && false !== strpos( $body, 'Second post' ) && false === strpos( $body, 'Released' ) && false === strpos( $body, 'Customer' ), 'digest lists exactly the bundled emails', $body );
	digest_check( false !== strpos( $body, 'comment.php?action=approve&amp;c=1' ) && false !== strpos( $body, 'https://example.com/?p=2' ) && false !== strpos( $body, 'mail=' . (int) $held['id'] ), 'digest has the first link of each email and a link to the log entry', $body );
	$after = digest_row( $held['id'] );
	digest_check( Repository::STATUS_SENT === (int) $after['status'] && ! isset( $after['meta']['delivery']['held'] ) && ! empty( $after['meta']['delivery']['digest'] ), 'listed emails are marked as delivered in the digest', $after );
	$logged = digest_last_row();
	digest_check( 'mailspur:alert' === $logged['source'] && Repository::STATUS_HELD !== (int) $logged['status'], 'the digest itself is logged as Mailspur email and not bundled', $logged );
	$days       = $store->days_since( gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), $type );
	$held_count = 0;
	foreach ( $days[ $type ] ?? array() as $count ) {
		$held_count += $count['held'];
	}
	digest_check( 1 === $held_count, 'digested emails no longer count as held (the released one still does)', $days );

	$digest_sent = array();
	$again       = $digest->run();
	digest_check( array() === $digest_sent && 0 === $again['entries'], 'a digest is sent only once', $again );

	// ------------------------------------------------------------- overdue.
	wp_mail( $admin, '[E2E Digest] Please moderate: "Late"', 'Body' );
	$late = digest_last_row();
	$digest->overdue();
	digest_check( array() === $digest_sent, 'a fresh email is not overdue', count( $digest_sent ) );
	$wpdb->update( Repository::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'id' => (int) $late['id'] ) );
	$digest->overdue();
	digest_check( 1 === count( $digest_sent ) && Repository::STATUS_SENT === (int) digest_row( $late['id'] )['status'], 'an overdue digest goes out with the next hourly run', count( $digest_sent ) );

	// ----------------------------------------------------- switching it off.
	$digest_sent = array();
	wp_mail( $admin, '[E2E Digest] Please moderate: "Pending"', 'Body' );
	$pending = digest_last_row();
	ob_start();
	( new Page( $store, $indexer ) )->render();
	$html = (string) ob_get_clean();
	digest_check( false !== strpos( $html, 'Stop bundling (sends what waits now)' ) && false !== strpos( $html, 'Bundled into one daily email' ) && false !== strpos( $html, 'name="keep"' ), 'the menu offers the bundling switch and "Keep for"', null );
	$store->bundle( $type, false );
	Bundle::sync( $store );
	$digest->run( $type );
	digest_check( 1 === count( $digest_sent ) && Repository::STATUS_SENT === (int) digest_row( $pending['id'] )['status'], 'switching off sends what waits in one digest right away', count( $digest_sent ) );
	wp_mail( $admin, '[E2E Digest] Please moderate: "After"', 'Body' );
	digest_check( 2 === count( $digest_sent ) && Repository::STATUS_HELD !== (int) digest_last_row()['status'], 'afterwards the emails are delivered one by one again', count( $digest_sent ) );
	Digest::schedule();
	digest_check( false === wp_next_scheduled( Digest::HOOK ), 'no daily event without a bundled type', wp_next_scheduled( Digest::HOOK ) );
} catch ( Throwable $e ) {
	digest_check( false, 'exception (bundling)', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// ------------------------------------------------------------------ retention.
try {
	$store = new Store();
	update_option(
		Settings::OPTION,
		array_merge(
			Settings::all(),
			array(
				'retention_days' => 3650,
				'anonymise_days' => 0,
				'max_entries'    => 0,
			)
		)
	);
	$reset        = $store->create( 'plugin:e2e-keep', Fingerprint::tokens( 'Password reset for Anna' ), current_time( 'mysql', true ) );
	$welcome      = $store->create( 'plugin:e2e-keep', Fingerprint::tokens( 'Welcome to the shop' ), current_time( 'mysql', true ) );
	$invoice      = $store->create( 'plugin:e2e-keep', Fingerprint::tokens( 'Invoice #1001' ), current_time( 'mysql', true ) );
	$digest_types = array_merge( $digest_types, array( $reset, $welcome, $invoice ) );
	$store->keep( $reset, 7 );
	$store->keep( $invoice, Cleanup::UNTIL_LIMIT );

	$ten  = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
	$rows = array(
		'old reset'       => digest_insert( $ten, 'Password reset for Anna', 'plugin:e2e-keep' ),
		'new reset'       => digest_insert( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'Password reset for Anna', 'plugin:e2e-keep' ),
		'welcome'         => digest_insert( $ten, 'Welcome to the shop', 'plugin:e2e-keep' ),
		'other sender'    => digest_insert( $ten, 'Password reset for Anna', 'plugin:e2e-keep-other' ),
		'ancient invoice' => digest_insert( '2005-01-01 10:00:00', 'Invoice #17', 'plugin:e2e-keep' ),
		'ancient welcome' => digest_insert( '2005-01-01 10:00:00', 'Welcome to the shop', 'plugin:e2e-keep' ),
	);
	( new Cleanup( new Repository() ) )->run();
	$left = array();
	foreach ( $rows as $name => $id ) {
		$left[ $name ] = null !== ( new Repository() )->find( $id );
	}
	digest_check(
		array(
			'old reset'       => false,
			'new reset'       => true,
			'welcome'         => true,
			'other sender'    => true,
			'ancient invoice' => true,
			'ancient welcome' => false,
		) === $left,
		'retention per type deletes only that type after its own period; "until the log limit" keeps it longer',
		$left
	);

	// With anonymisation on, a shorter period anonymises instead of deleting.
	update_option( Settings::OPTION, array_merge( Settings::all(), array( 'anonymise_days' => 365 ) ) );
	$anon = digest_insert( $ten, 'Password reset for Anna', 'plugin:e2e-keep' );
	( new Cleanup( new Repository() ) )->run();
	$row = ( new Repository() )->find( $anon );
	digest_check( null !== $row && 0 === strpos( (string) $row['meta'], '{"anonymised":' ) && '' === $row['message'], 'with anonymisation on, the type is anonymised after its period', $row );

	$inventory = array();
	foreach ( ( new Inventory( $store ) )->rows( time() ) as $item ) {
		$inventory[ $item['type'] ] = $item['retention'];
	}
	digest_check(
		false !== strpos( (string) ( $inventory['Invoice #…'] ?? '' ), 'kept until deleted manually' ) && false !== strpos( (string) ( $inventory['Welcome to the shop'] ?? '' ), 'deleted after ' . number_format_i18n( 3650 ) . ' days' ) && false !== strpos( (string) ( $inventory['Password reset for Anna'] ?? '' ), 'anonymised after 7 days' ),
		'the email inventory shows the retention per type',
		$inventory
	);
	$extra = implode( ' ', array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT extra FROM %i', Store::types_table() ) ) ) ) . wp_json_encode( Bundle::map() );
	digest_check( false === stripos( $extra, (string) get_option( 'admin_email' ) ), 'bundling and retention store no addresses', null );
} catch ( Throwable $e ) {
	digest_check( false, 'exception (retention)', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

try {
	remove_filter( 'pre_wp_mail', $digest_mailer, 10 );
	Logger::$source_override = '';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Repository::table(), $digest_start ) );
	foreach ( $digest_types as $id ) {
		$wpdb->delete( Store::types_table(), array( 'id' => (int) $id ) );
		$wpdb->delete( Store::days_table(), array( 'type_id' => (int) $id ) );
	}
	delete_option( Bundle::OPTION );
	delete_option( Store::CHOICES );
	delete_option( Digest::LOCK );
	wp_clear_scheduled_hook( Digest::HOOK );
	update_option( Indexer::CURSOR, 0, false );
	update_option( Settings::OPTION, $digest_settings );
	if ( '' !== $digest_file && file_exists( $digest_file ) ) {
		unlink( $digest_file );
	}
} catch ( Throwable $e ) {
	digest_check( false, 'cleanup failed', $e->getMessage() );
}

$digest_failed = array_values(
	array_filter(
		$digest_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
file_put_contents(
	'/e2e-out/features/types-digest.json',
	wp_json_encode(
		array(
			'passed'  => count( $digest_results ) - count( $digest_failed ),
			'failed'  => count( $digest_failed ),
			'results' => $digest_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
