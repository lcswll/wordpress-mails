<?php
/**
 * Import integration test inside real WordPress (Playground).
 *
 * - WP Mail Logging and Email Log are the real plugins (installed by the blueprint) logging real wp_mail() calls.
 * - The other sources get their tables with rows in exactly the format their plugins write (see src/Import/Sources).
 * - Site time zone Europe/Berlin, so local→UTC conversion is checked against a real WordPress.
 *
 * Writes /e2e-out/import.json (evaluated by scripts/e2e.mjs).
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixture DDL
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Mailspur\Repository;

$results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function import_check( $ok, $name, $detail = null ) {
	global $results;
	$results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return WP_REST_Response */
function import_rest( $method, $route ) {
	return rest_ensure_response( rest_do_request( new WP_REST_Request( $method, '/mailspur-email-log/v1' . $route ) ) );
}

/** Runs the import of one source to the end, like the admin screen does. */
function import_all( $source ) {
	$total = array(
		'imported' => 0,
		'skipped'  => 0,
		'calls'    => 0,
	);
	do {
		$res = import_rest( 'POST', '/import/' . $source );
		if ( 200 !== $res->get_status() ) {
			return array( 'error' => $res->get_data() );
		}
		$data               = $res->get_data();
		$total['imported'] += $data['imported'];
		$total['skipped']  += $data['skipped'];
		++$total['calls'];
	} while ( ! $data['done'] && $total['calls'] < 50 );
	return $total;
}

/** @return array<string,string>|null */
function imported_row( $source, $subject ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s AND subject = %s', Repository::table(), 'import:' . $source, $subject ), ARRAY_A );
}

try {
	global $wpdb;
	$p = $wpdb->prefix;
	update_option( 'timezone_string', 'Europe/Berlin' );
	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'retention_days' => 0 ) ) );

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	);
	wp_set_current_user( (int) $admins[0] );

	// ------------------------------------------------ real plugins log real mails.
	import_check( is_plugin_active( 'wp-mail-logging/wp-mail-logging.php' ), 'WP Mail Logging is active' );
	import_check( is_plugin_active( 'email-log/email-log.php' ), 'Email Log is active' );

	add_filter( 'pre_wp_mail', '__return_true' ); // Playground has no MTA; both plugins log in the wp_mail filter.
	wp_mail(
		array( 'anna@example.com', 'Bob <bob@example.org>' ),
		'Live order mail',
		'<p>Reset: https://shop.example/wp-login.php?action=rp&key=LIVESECRET&login=anna</p>',
		array( 'Content-Type: text/html; charset=UTF-8', 'From: Shop <shop@example.com>' )
	);
	remove_filter( 'pre_wp_mail', '__return_true' );

	// ------------------------------------------- tables of the other plugins.
	$ddl = array(
		"CREATE TABLE {$p}check_email_log (id INTEGER PRIMARY KEY AUTO_INCREMENT, to_email VARCHAR(500) NOT NULL, subject VARCHAR(500) NOT NULL, message TEXT NOT NULL, backtrace_segment TEXT, headers TEXT NOT NULL, attachments TEXT NOT NULL, sent_date DATETIME NOT NULL, attachment_name VARCHAR(1000), ip_address VARCHAR(15), result TINYINT(1), error_message VARCHAR(1000))",
		"CREATE TABLE {$p}fsmpt_email_logs (id INTEGER PRIMARY KEY AUTO_INCREMENT, site_id INT NULL, `to` TEXT NULL, `from` VARCHAR(255), subject VARCHAR(255), body LONGTEXT NULL, headers LONGTEXT NULL, attachments LONGTEXT NULL, status VARCHAR(20) DEFAULT 'pending', response TEXT NULL, extra TEXT NULL, retries INT NULL, resent_count INT NULL, source VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)",
		"CREATE TABLE {$p}post_smtp_logs (id INTEGER PRIMARY KEY AUTO_INCREMENT, solution LONGTEXT, success LONGTEXT, from_header LONGTEXT, to_header LONGTEXT, cc_header LONGTEXT, bcc_header LONGTEXT, reply_to_header LONGTEXT, transport_uri LONGTEXT, original_to LONGTEXT, original_subject LONGTEXT, original_message LONGTEXT, original_headers LONGTEXT, session_transcript LONGTEXT, time BIGINT)",
		"CREATE TABLE {$p}suremails_email_log (id INTEGER PRIMARY KEY AUTO_INCREMENT, email_from VARCHAR(100) NOT NULL, email_to LONGTEXT NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, attachments LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, response LONGTEXT NOT NULL, meta TEXT NULL, connection VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)",
		"CREATE TABLE {$p}mail_catcher_logs (id INTEGER PRIMARY KEY AUTO_INCREMENT, time INT NOT NULL, email_to TEXT, subject TEXT, message TEXT, backtrace_segment TEXT NOT NULL, status TINYINT(1) NOT NULL DEFAULT 1, error TEXT, attachments TEXT, additional_headers TEXT, is_html TINYINT(1) DEFAULT 0)",
		"CREATE TABLE {$p}wml_entries (id INTEGER PRIMARY KEY AUTO_INCREMENT, to_email VARCHAR(100) NOT NULL, subject VARCHAR(250) NOT NULL, message TEXT NOT NULL, headers TEXT NOT NULL, attachments VARCHAR(50) NOT NULL, sent_date VARCHAR(50) NOT NULL, captured_gmt VARCHAR(50) NOT NULL, attachments_file TEXT)",
	);
	foreach ( $ddl as $sql ) {
		$wpdb->query( $sql );
	}

	$wpdb->insert(
		"{$p}check_email_log",
		array(
			'to_email'          => 'a@example.com,b@example.com',
			'subject'           => 'Tom &amp; Jerry&#039;s offer',
			'message'           => '<p>Offer</p>',
			'backtrace_segment' => '',
			'headers'           => "From: Shop <shop@example.com>\nContent-Type: text/html",
			'attachments'       => 'false',
			'sent_date'         => '2026-07-30 14:00:00',
			'result'            => 0,
			'error_message'     => 'Could not instantiate mail function.',
		)
	);
	$wpdb->insert(
		"{$p}fsmpt_email_logs",
		array(
			'to'          => serialize(
				array(
					array(
						'email' => 'anna@example.com',
						'name'  => 'Anna',
					),
				)
			),
			'from'        => 'Shop <shop@example.com>',
			'subject'     => 'Fluent invoice',
			'body'        => '<p>Invoice</p>',
			'headers'     => serialize( array( 'content-type' => 'text/html' ) ),
			'attachments' => serialize( array() ),
			'status'      => 'sent',
			'response'    => serialize( array( 'response' => 'OK' ) ),
			'created_at'  => '2026-07-30 14:00:00',
		)
	);
	$wpdb->insert(
		"{$p}post_smtp_logs",
		array(
			'success'          => 'SMTP connect() failed.',
			'from_header'      => 'Shop <shop@example.com>',
			'to_header'        => 'anna@example.com',
			'original_subject' => 'Post SMTP mail',
			'original_message' => 'Body',
			'original_headers' => '',
			'time'             => ( new DateTime( '2026-07-30 14:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(), // Local epoch.
		)
	);
	$wpdb->insert(
		"{$p}suremails_email_log",
		array(
			'email_from'  => 'Shop <shop@example.com>',
			'email_to'    => 'Anna <anna@example.com>',
			'subject'     => 'SureMail mail',
			'body'        => '<b>x</b>',
			'headers'     => serialize( array( 'Content-Type: text/html; charset=UTF-8' ) ),
			'attachments' => serialize( array() ),
			'status'      => 'sent',
			'response'    => serialize( array() ),
			'connection'  => 'Default',
			'created_at'  => '2026-07-30 14:00:00',
			'updated_at'  => '2026-07-30 14:00:00',
		)
	);
	$wpdb->insert(
		"{$p}mail_catcher_logs",
		array(
			'time'               => 1785412800, // 2026-07-30 12:00:00 UTC.
			'email_to'           => 'anna@example.com',
			'subject'            => 'Catcher mail',
			'message'            => 'x',
			'backtrace_segment'  => '{}',
			'status'             => 1,
			'attachments'        => '[]',
			'additional_headers' => wp_json_encode( array( 'From: Shop <shop@example.com>' ) ),
			'is_html'            => 0,
		)
	);
	$wpdb->insert(
		"{$p}wml_entries",
		array(
			'to_email'     => 'anna@example.com',
			'subject'      => 'WML mail',
			'message'      => 'x',
			'headers'      => '',
			'attachments'  => 'false',
			'sent_date'    => '2026-07-30 14:00:00',
			'captured_gmt' => '2026-07-30 12:00:00',
		)
	);

	// --------------------------------------------------------------- overview.
	$overview = import_rest( 'GET', '/import' )->get_data();
	$found    = wp_list_pluck( $overview['sources'], 'total', 'id' );
	import_check( 8 === count( $found ), 'all 8 sources detected', array_keys( $found ) );
	import_check( 1 === ( $found['wp-mail-logging'] ?? 0 ) && 1 === ( $found['email-log'] ?? 0 ), 'live plugins logged the mail', $found );

	wp_set_current_user( 0 );
	import_check( 401 === import_rest( 'POST', '/import/email-log' )->get_status(), 'import requires login' );
	wp_set_current_user( (int) $admins[0] );
	import_check( 400 === import_rest( 'POST', '/import/not-a-plugin' )->get_status(), 'unknown source rejected' );

	// ------------------------------------------------------------------ import.
	$before = array();
	foreach ( array_keys( $found ) as $id ) {
		$before[ $id ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Mailspur\Import\Importer::source( $id )->table() ) );
		$run           = import_all( $id );
		import_check( 1 === ( $run['imported'] ?? 0 ), "import {$id}", $run );
	}

	// Live WP Mail Logging row: literal ",\n" lists, local time, redaction.
	$wpml = imported_row( 'wp-mail-logging', 'Live order mail' );
	import_check( $wpml && 'anna@example.com, Bob <bob@example.org>' === $wpml['recipients'], 'WPML recipients', $wpml['recipients'] ?? null );
	import_check( $wpml && 'text/html' === $wpml['content_type'] && 'Shop <shop@example.com>' === $wpml['sender'], 'WPML headers parsed', $wpml );
	import_check( $wpml && false === strpos( $wpml['message'], 'LIVESECRET' ) && false !== strpos( $wpml['message'], 'key=[redacted]' ), 'secrets redacted on import', $wpml['message'] ?? null );
	import_check( $wpml && abs( strtotime( $wpml['created_at'] . ' UTC' ) - time() ) < 300, 'WPML local time converted to UTC', $wpml['created_at'] ?? null );

	$elog = imported_row( 'email-log', 'Live order mail' );
	import_check( $elog && '1' === $elog['status'] && 'anna@example.com, Bob <bob@example.org>' === $elog['recipients'], 'Email Log row', $elog );
	import_check( $elog && abs( strtotime( $elog['created_at'] . ' UTC' ) - time() ) < 300, 'Email Log local time converted to UTC', $elog['created_at'] ?? null );

	$check = imported_row( 'check-email', "Tom & Jerry's offer" );
	import_check( $check && '2' === $check['status'] && '2026-07-30 12:00:00' === $check['created_at'], 'Check & Log Email: decoded subject, failed, UTC', $check );
	$fluent = imported_row( 'fluent-smtp', 'Fluent invoice' );
	import_check( $fluent && 'Anna <anna@example.com>' === $fluent['recipients'] && '2026-07-30 12:00:00' === $fluent['created_at'], 'FluentSMTP row', $fluent );
	$post = imported_row( 'post-smtp', 'Post SMTP mail' );
	import_check( $post && '2' === $post['status'] && 'SMTP connect() failed.' === $post['error'] && '2026-07-30 12:00:00' === $post['created_at'], 'Post SMTP row (local epoch)', $post );
	$sure = imported_row( 'suremails', 'SureMail mail' );
	import_check( $sure && 'text/html' === $sure['content_type'] && '2026-07-30 12:00:00' === $sure['created_at'], 'SureMail row', $sure );
	$catcher = imported_row( 'wp-mail-catcher', 'Catcher mail' );
	import_check( $catcher && '2026-07-30 12:00:00' === $catcher['created_at'] && 'text/plain' === $catcher['content_type'], 'WP Mail Catcher row (UTC epoch)', $catcher );
	$wml = imported_row( 'wp-mail-log', 'WML mail' );
	import_check( $wml && '0' === $wml['status'] && '2026-07-30 12:00:00' === $wml['created_at'], 'WP Mail Log row (unknown status)', $wml );

	// Read-only towards the other plugins.
	foreach ( $before as $id => $count ) {
		$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Mailspur\Import\Importer::source( $id )->table() ) );
		import_check( $after === $count, "{$id} table untouched" );
	}

	// Running again imports nothing twice; only new rows.
	$again = import_all( 'fluent-smtp' );
	import_check( 0 === $again['imported'], 'second run imports no duplicates', $again );
	$wpdb->insert(
		"{$p}fsmpt_email_logs",
		array(
			'to'         => serialize( array( array( 'email' => 'new@example.com' ) ) ),
			'from'       => '',
			'subject'    => 'Fluent new',
			'body'       => 'x',
			'headers'    => '',
			'status'     => 'sent',
			'created_at' => current_time( 'mysql' ),
		)
	);
	import_check( 1 === import_all( 'fluent-smtp' )['imported'], 'new rows are picked up later' );

	// Old imported mails are sorted by their send date, not inserted on top.
	$list   = rest_ensure_response( rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/mails' ) ) )->get_data();
	$dates  = wp_list_pluck( $list['items'], 'date_iso' );
	$sorted = $dates;
	rsort( $sorted );
	import_check( $dates === $sorted, 'list sorted by send date incl. imported mails', $dates );
	import_check( 'import:fluent-smtp' === $list['items'][0]['source'] || 'import:wp-mail-logging' === $list['items'][0]['source'] || 'import:email-log' === $list['items'][0]['source'], 'newest entries first', $list['items'][0] );

	// Retention: old entries are skipped instead of imported and deleted right away.
	import_rest( 'DELETE', '/import/wp-mail-catcher' );
	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'retention_days' => 30 ) ) );
	$retention = import_all( 'wp-mail-catcher' );
	import_check( 0 === $retention['imported'] && 1 === $retention['skipped'], 'entries older than retention are skipped', $retention );
	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'retention_days' => 0 ) ) );

	// Cleanup with a mixed table: retention must not delete newer native entries with lower ids.
	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'retention_days' => 30 ) ) );
	do_action( 'mailspur_cleanup' );
	import_check( null !== imported_row( 'email-log', 'Live order mail' ), 'cleanup keeps recent entries next to old imported ones' );
	import_check( null === imported_row( 'fluent-smtp', 'Fluent invoice' ), 'cleanup removes old imported entries' );
	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'retention_days' => 0 ) ) );

	// Undo.
	$undo = import_rest( 'DELETE', '/import/email-log' )->get_data();
	import_check( 1 === $undo['deleted'] && null === imported_row( 'email-log', 'Live order mail' ), 'undo removes imported entries', $undo );

	// Leave the site as the self-test expects it.
	foreach ( array_keys( $found ) as $id ) {
		import_rest( 'DELETE', '/import/' . $id );
	}
	deactivate_plugins( array( 'wp-mail-logging/wp-mail-logging.php', 'email-log/email-log.php' ) );
	update_option( 'timezone_string', '' );
} catch ( Throwable $e ) {
	import_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

$failed = array_values(
	array_filter(
		$results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
file_put_contents(
	'/e2e-out/import.json',
	wp_json_encode(
		array(
			'passed'  => count( $results ) - count( $failed ),
			'failed'  => count( $failed ),
			'results' => $results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
// No exception on failure: the self-test step must still run; scripts/e2e.mjs evaluates import.json.
