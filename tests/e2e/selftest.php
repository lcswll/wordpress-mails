<?php
/**
 * Integration self-test inside a real WordPress (Playground): activation, logging paths,
 * REST API incl. permissions, retention, privacy tools, deactivation and uninstall.
 *
 * Writes /e2e-out/selftest.json and fails the blueprint step (exit 255) on any failed assertion.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Mailspur\Repository;

$results = array();

/**
 * Records one assertion.
 *
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function check( $ok, $name, $detail = null ) {
	global $results;
	$results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return array<string,string>|null */
function last_row() {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Repository::table() ), ARRAY_A );
}

/** @return WP_REST_Response */
function rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/mailspur-email-log/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) );
}

try {
	global $wpdb;
	$table = Repository::table();

	// ---------------------------------------------------------------- activation.
	check( is_plugin_active( 'mailspur-email-log/mailspur-email-log.php' ), 'plugin is active' );
	check( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'log table exists', $wpdb->last_error );
	check( \Mailspur\DB_VERSION === (int) get_option( 'mailspur_db_version' ), 'schema version stored' );
	check( (bool) wp_next_scheduled( 'mailspur_cleanup' ), 'daily cleanup scheduled' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // Start from a known state.

	// ------------------------------------------------- delivery via pre_wp_mail.
	$short = static function ( $result, $atts ) {
		return false === strpos( $atts['subject'], 'FAIL' );
	};
	add_filter( 'pre_wp_mail', $short, 10, 2 );

	$sent = wp_mail(
		array( 'anna@example.com', 'Bob <bob@example.org>' ),
		'Bestellung #1 abgeschlossen 👍',
		'<p>Hallo</p><a href="https://shop.test/order/?key=wc_order_SECRET">Order</a>',
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
	$row  = last_row();
	check( true === $sent, 'wp_mail() result unchanged' );
	check( '1' === $row['status'], 'pre_wp_mail success → sent', $row['status'] );
	check( 'anna@example.com, Bob <bob@example.org>' === $row['recipients'], 'all recipients stored', $row['recipients'] );
	check( 'Bestellung #1 abgeschlossen 👍' === $row['subject'], 'utf8mb4 subject stored', $row['subject'] );
	check( 'text/html' === $row['content_type'], 'content type from headers', $row['content_type'] );
	check( false === strpos( $row['message'], 'SECRET' ) && false !== strpos( $row['message'], 'key=[redacted]' ), 'secret key redacted', $row['message'] );

	wp_mail( 'fail@example.com', 'This should FAIL', 'x' );
	$row = last_row();
	check( '2' === $row['status'] && '' !== $row['error'], 'pre_wp_mail false → failed with error', $row );
	remove_filter( 'pre_wp_mail', $short, 10 );

	// ------------------------------------- real PHPMailer path (no MTA → fails).
	$template = static function ( $mailer ) {
		$mailer->Body = '<div class="template">' . $mailer->Body . '</div>'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	};
	add_action( 'phpmailer_init', $template );
	$nested = static function () {
		static $once = false;
		if ( ! $once ) {
			$once = true;
			wp_mail( 'admin@example.com', 'Nested failure notice', 'A mail failed.' );
		}
	};
	add_action( 'wp_mail_failed', $nested, 5 );

	wp_mail( 'carl@example.com', 'Plain text mail', 'Body text', array( 'From: Shop <shop@example.com>' ) );
	remove_action( 'phpmailer_init', $template );
	remove_action( 'wp_mail_failed', $nested, 5 );

	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 2', $table ), ARRAY_A );
	$main  = 'Plain text mail' === $rows[1]['subject'] ? $rows[1] : $rows[0];
	$inner = 'Nested failure notice' === $rows[0]['subject'] ? $rows[0] : $rows[1];
	check( '2' === $main['status'] && '' !== $main['error'], 'PHPMailer failure → failed with error', $main );
	check( '<div class="template">Body text</div>' === $main['message'], 'body rewritten in phpmailer_init is stored', $main['message'] );
	check( 'Shop <shop@example.com>' === $main['sender'], 'final sender stored', $main['sender'] );
	check( 'text/plain' === $main['content_type'], 'final content type stored', $main['content_type'] );
	check( 'Nested failure notice' === $inner['subject'] && '2' === $inner['status'], 'nested mail resolved separately', $inner );

	add_filter( 'mailspur_should_log', '__return_false' );
	$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	wp_mail( 'skip@example.com', 'Skipped', 'x' );
	remove_filter( 'mailspur_should_log', '__return_false' );
	check( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) === $before, 'mailspur_should_log can skip mails' );

	// ------------------------------------------------------------ REST: access.
	wp_set_current_user( 0 );
	check( 401 === rest( 'GET', '/mails' )->get_status(), 'REST rejects anonymous users' );
	$subscriber = wp_insert_user(
		array(
			'user_login' => 'e2e_subscriber',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	wp_set_current_user( $subscriber );
	check( 403 === rest( 'GET', '/mails' )->get_status(), 'REST rejects subscribers' );
	check( 403 === rest( 'GET', '/mails/1' )->get_status(), 'REST detail rejects subscribers' );

	// ------------------------------------------------------- REST: as admin.
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	);
	wp_set_current_user( (int) $admins[0] );

	$list = rest( 'GET', '/mails' )->get_data();
	// anna (sent), fail, carl and the nested notice (failed); the skipped mail is not logged.
	check( 4 === $list['total'], 'list total', $list['total'] );
	check( 1 === $list['counts']['sent'] && 3 === $list['counts']['failed'], 'status counts', $list['counts'] );
	check( ! isset( $list['items'][0]['message'] ), 'list never ships message bodies' );

	$search = rest( 'GET', '/mails', array( 'search' => 'bob@example' ) )->get_data();
	check( 1 === $search['total'], 'search by recipient', $search['total'] );
	$body = rest(
		'GET',
		'/mails',
		array(
			'search'  => 'template',
			'in_body' => true,
		)
	)->get_data();
	// The nested notice is sent while the template filter is still active, so both bodies match.
	check( 2 === $body['total'], 'content search', $body['total'] );
	$failed = rest( 'GET', '/mails', array( 'status' => 'failed' ) )->get_data();
	check( 3 === $failed['total'], 'status filter', $failed['total'] );
	check( 400 === rest( 'GET', '/mails', array( 'orderby' => 'id;DROP TABLE x' ) )->get_status(), 'invalid orderby rejected' );
	check( 400 === rest( 'GET', '/mails', array( 'after' => "2026-01-01' OR 1=1" ) )->get_status(), 'invalid date rejected' );
	$sorted = rest(
		'GET',
		'/mails',
		array(
			'orderby' => 'subject',
			'order'   => 'asc',
		)
	)->get_data();
	check( 'Bestellung #1 abgeschlossen 👍' === $sorted['items'][0]['subject'], 'sort by subject', wp_list_pluck( $sorted['items'], 'subject' ) );

	$first  = $list['items'][ count( $list['items'] ) - 1 ];
	$detail = rest( 'GET', '/mails/' . $first['id'] )->get_data();
	check( true === $detail['is_html'] && false !== strpos( $detail['message'], '<p>Hallo</p>' ), 'detail returns html body', $detail );
	check( 404 === rest( 'GET', '/mails/999999' )->get_status(), 'unknown id → 404' );

	$resend = rest( 'POST', '/mails/' . $first['id'] . '/resend' );
	$row    = last_row();
	check( 200 === $resend->get_status() && 'mailspur:resend' === $row['source'], 'resend creates a new entry', $row );

	$delete = rest( 'DELETE', '/mails', array( 'ids' => array( (int) $row['id'] ) ) )->get_data();
	check( 1 === $delete['deleted'], 'bulk delete', $delete );

	// ------------------------------------------------------------- retention.
	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'retention_days' => 30 ) ) );
	$old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i', $table ) );
	$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
	do_action( 'mailspur_cleanup' );
	check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $old ) ), 'entries older than retention are deleted' );

	update_option( 'mailspur_settings', array_merge( get_option( 'mailspur_settings', array() ), array( 'max_entries' => 2 ) ) );
	do_action( 'mailspur_cleanup' );
	check( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'max entries enforced' );

	// --------------------------------------------------------------- privacy.
	wp_mail( 'privacy@example.com', 'Privacy test', 'x' );
	wp_mail( 'xprivacy@example.com', 'Similar address', 'x' );
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	check( isset( $exporters['mailspur-email-log'], $erasers['mailspur-email-log'] ), 'privacy exporter and eraser registered' );
	$export = call_user_func( $exporters['mailspur-email-log']['callback'], 'privacy@example.com', 1 );
	check( 1 === count( $export['data'] ) && $export['done'], 'export finds exact address only', $export );
	$erase = call_user_func( $erasers['mailspur-email-log']['callback'], 'privacy@example.com', 1 );
	check( $erase['items_removed'] && $erase['done'], 'eraser removes entries', $erase );
	check( 0 === count( call_user_func( $exporters['mailspur-email-log']['callback'], 'privacy@example.com', 1 )['data'] ), 'nothing left after erase' );
	check( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipients = %s', $table, 'xprivacy@example.com' ) ), 'similar address kept' );

	// ---------------------------------------------------------- purge (admin).
	check( 200 === rest( 'DELETE', '/mails', array( 'all' => true ) )->get_status(), 'empty log' );
	check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'log is empty after purge' );

	// ------------------------------------------------- deactivate + uninstall.
	deactivate_plugins( 'mailspur-email-log/mailspur-email-log.php' );
	check( ! wp_next_scheduled( 'mailspur_cleanup' ), 'deactivation removes cron event' );

	define( 'WP_UNINSTALL_PLUGIN', 'mailspur-email-log/mailspur-email-log.php' );
	include WP_PLUGIN_DIR . '/mailspur-email-log/uninstall.php';
	$wpdb->suppress_errors( true );
	check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'uninstall drops the table' );
	check( false === get_option( 'mailspur_settings' ) && false === get_option( 'mailspur_db_version' ), 'uninstall deletes options' );
} catch ( Throwable $e ) {
	check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
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
	'/e2e-out/selftest.json',
	wp_json_encode(
		array(
			'php'     => PHP_VERSION,
			'wp'      => get_bloginfo( 'version' ),
			'passed'  => count( $results ) - count( $failed ),
			'failed'  => count( $failed ),
			'results' => $results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);

if ( $failed ) {
	throw new RuntimeException( count( $failed ) . ' assertion(s) failed – see selftest.json' );
}
