<?php
/**
 * Trace module inside real WordPress (Playground): timeline, origin, hooks, request context, SMTP transport
 * with masked transcript, API delivery detection via a real plugin file, raw source and the .eml download.
 *
 * No network: the SMTP mail fails before connecting (empty host list), the SMTP conversation is fed into the
 * debug output PHPMailer was given.
 *
 * Writes /e2e-out/features/trace.json (evaluated by scripts/e2e.mjs).
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Modules\Trace\Eml;
use Mailspur\Repository;

$trace_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function trace_check( $ok, $name, $detail = null ) {
	global $trace_results;
	$trace_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return array<string,string>|null */
function trace_last_row() {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Repository::table() ), ARRAY_A );
}

/** @return array<string,mixed> */
function trace_of( $row ) {
	$meta = json_decode( (string) $row['meta'], true );
	return is_array( $meta ) && isset( $meta['trace'] ) && is_array( $meta['trace'] ) ? $meta['trace'] : array();
}

/** @return WP_REST_Response */
function trace_rest( $route ) {
	return rest_ensure_response( rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1' . $route ) ) );
}

/** Sends a mail from a known line (origin check). */
function trace_feature_send( $subject, $message = 'Hello', $headers = array() ) {
	$GLOBALS['trace_line'] = __LINE__ + 1;
	return wp_mail( 'jane@example.com', $subject, $message, $headers );
}

$trace_plugin_dir = WP_PLUGIN_DIR . '/trace-fake-api';
$trace_settings   = get_option( 'mailspur_settings', array() );
$trace_ids        = array();

try {
	wp_set_current_user( 1 );

	// ------------------------------------------------------------------ API delivery (pre_wp_mail).
	wp_mkdir_p( $trace_plugin_dir );
	file_put_contents( $trace_plugin_dir . '/trace-fake-api.php', "<?php\nfunction trace_fake_api_send( \$result ) {\n\treturn true;\n}\n" );
	require_once $trace_plugin_dir . '/trace-fake-api.php';
	add_filter( 'pre_wp_mail', 'trace_fake_api_send' );

	add_action(
		'trace_feature_hook',
		static function () {
			trace_feature_send( 'Trace API mail', '<p>Hi</p>', array( 'From: Shop <shop@example.com>', 'Cc: boss@example.com', 'X-Custom: 1', 'Content-Type: text/html; charset=UTF-8' ) );
		}
	);
	do_action( 'trace_feature_hook' );
	remove_filter( 'pre_wp_mail', 'trace_fake_api_send' );

	$row         = trace_last_row();
	$trace_ids[] = (int) $row['id'];
	$trace       = trace_of( $row );
	trace_check( 'Trace API mail' === $row['subject'] && (int) Repository::STATUS_SENT === (int) $row['status'], 'API mail logged as sent', $row );
	trace_check( 'pre_wp_mail' === ( $trace['via'] ?? '' ), 'delivery via pre_wp_mail detected', $trace );
	trace_check(
		isset( $trace['transport']['handlers'][0] ) && 'plugin:trace-fake-api' === $trace['transport']['handlers'][0]['component'] && 'trace_fake_api_send' === $trace['transport']['handlers'][0]['callback'],
		'API handler mapped to its plugin (Mailspur itself ignored)',
		$trace['transport'] ?? null
	);
	trace_check( isset( $trace['timeline']['capture'], $trace['timeline']['result'] ) && ! isset( $trace['timeline']['phpmailer'] ) && $trace['total_ms'] >= 0, 'timeline without PHPMailer phase', $trace['timeline'] ?? null );
	trace_check(
		isset( $trace['origin'] ) && 'trace_feature_send' === $trace['origin']['function'] && $GLOBALS['trace_line'] === $trace['origin']['line'] && '…/trace.php' === $trace['origin']['file'],
		'origin: file, line and function of the wp_mail() call',
		$trace['origin'] ?? null
	);
	trace_check( isset( $trace['hooks'] ) && in_array( 'trace_feature_hook', $trace['hooks'], true ) && ! in_array( 'mailspur_meta', $trace['hooks'], true ), 'hook stack recorded without Mailspur frames', $trace['hooks'] ?? null );
	trace_check(
		isset( $trace['request']['type'] ) && in_array( $trace['request']['type'], array( 'cron', 'rest', 'ajax', 'cli', 'admin', 'frontend', 'xmlrpc' ), true ) && false === strpos( (string) ( $trace['request']['path'] ?? '' ), '?' ),
		'request context without query string',
		$trace['request'] ?? null
	);
	trace_check( 1 === ( $trace['request']['user_id'] ?? 0 ) && 'admin' === ( $trace['request']['user_login'] ?? '' ), 'logged-in user recorded', $trace['request'] ?? null );
	foreach ( array( '_k', '_t0', '_t1' ) as $internal ) {
		trace_check( ! isset( $trace[ $internal ] ), "internal key {$internal} removed" );
	}

	// ------------------------------------------------------------------ SMTP with transcript (fails without network).
	$trace_smtp = static function ( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host       = ''; // No valid host: PHPMailer gives up before opening a socket.
		$mailer->Port       = 2525;
		$mailer->SMTPSecure = 'tls';
		$mailer->SMTPAuth   = true;
		$mailer->Username   = 'jane.doe@example.com';
		$mailer->Password   = 'Secr3t-Pa55';
	};
	// Runs after Mailspur's phpmailer_init handler (same priority, added later): the conversation of a real server.
	$trace_talk = static function ( $mailer ) {
		foreach ( array( "CLIENT -> SERVER: AUTH LOGIN\r\n", "SERVER -> CLIENT: 334 VXNlcm5hbWU6\r\n", "CLIENT -> SERVER: amFuZS5kb2VAZXhhbXBsZS5jb20=\r\n", "SERVER -> CLIENT: 334 UGFzc3dvcmQ6\r\n", "CLIENT -> SERVER: U2VjcjN0LVBhNTU=\r\n", "SERVER -> CLIENT: 535 5.7.8 Authentication failed\r\n" ) as $line ) {
			if ( is_callable( $mailer->Debugoutput ) ) {
				call_user_func( $mailer->Debugoutput, $line, 2 );
			}
		}
	};
	add_action( 'phpmailer_init', $trace_smtp );
	add_action( 'phpmailer_init', $trace_talk, PHP_INT_MAX );

	update_option( 'mailspur_settings', array_merge( $trace_settings, array( 'trace_raw' => true ) ) );
	$sent = trace_feature_send( 'Trace SMTP mail', "Reset: https://site.example/wp-login.php?action=rp&key=RawSecret42&login=jane\n", array( 'From: Shop <shop@example.com>' ) );

	$row         = trace_last_row();
	$trace_ids[] = (int) $row['id'];
	$trace       = trace_of( $row );
	$t           = $trace['transport'] ?? array();
	trace_check( false === $sent && (int) Repository::STATUS_FAILED === (int) $row['status'], 'SMTP mail failed without network', $row['error'] );
	trace_check( 'phpmailer' === ( $trace['via'] ?? '' ) && isset( $trace['timeline']['phpmailer'] ) && $trace['timeline']['result'] >= $trace['timeline']['phpmailer'], 'timeline with PHPMailer phase', $trace['timeline'] ?? null );
	trace_check(
		'smtp' === ( $t['mailer'] ?? '' ) && 2525 === ( $t['port'] ?? 0 ) && 'tls' === ( $t['secure'] ?? '' ) && true === ( $t['auth'] ?? null ) && 'j***@example.com' === ( $t['user'] ?? '' ) && array_key_exists( 'auto_tls', $t ),
		'transport settings with masked username',
		$t
	);
	trace_check( false === strpos( (string) $row['meta'], 'Secr3t' ) && false === strpos( (string) $row['meta'], 'jane.doe' ), 'password and full username never stored', $row['meta'] );
	$transcript = (string) ( $trace['transcript'] ?? '' );
	trace_check( 'stored' === ( $trace['transcript_status'] ?? '' ) && false !== strpos( $transcript, 'SERVER -> CLIENT: 535 5.7.8 Authentication failed' ), 'transcript stored for the failed mail', $trace );
	trace_check( false !== strpos( $transcript, 'AUTH LOGIN' ) && false === strpos( $transcript, 'amFuZS5kb2VAZXhhbXBsZS5jb20' ) && false === strpos( $transcript, 'U2VjcjN0' ) && false === strpos( $transcript, 'VXNlcm5hbWU6' ), 'transcript credentials masked', $transcript );
	global $phpmailer;
	trace_check( 0 === (int) $phpmailer->SMTPDebug && ! ( $phpmailer->Debugoutput instanceof Closure ), 'PHPMailer debug settings restored', array( $phpmailer->SMTPDebug ) );

	// Raw source: exact, NOT redacted (the message column is).
	$raw = Eml::unpack( (string) $row['raw'] );
	trace_check( 'stored' === ( $trace['raw'] ?? '' ) && 0 === strpos( (string) $row['raw'], 'gz:' ), 'raw source stored compressed', substr( (string) $row['raw'], 0, 20 ) );
	trace_check( false !== strpos( $raw, 'Subject: Trace SMTP mail' ) && false !== strpos( $raw, 'RawSecret42' ) && false === strpos( (string) $row['message'], 'RawSecret42' ), 'raw source is the exact message, the log column stays redacted', $raw );

	remove_action( 'phpmailer_init', $trace_smtp );
	remove_action( 'phpmailer_init', $trace_talk, PHP_INT_MAX );
	update_option( 'mailspur_settings', $trace_settings );

	// ------------------------------------------------------------------ REST: detail payload and .eml download.
	$detail = trace_rest( '/mails/' . $trace_ids[1] )->get_data();
	trace_check( true === ( $detail['trace_raw'] ?? null ) && isset( $detail['meta']['trace']['transport'] ), 'detail payload has trace and raw flag', $detail );

	$res = trace_rest( '/mails/' . $trace_ids[1] . '/eml' );
	$h   = $res->get_headers();
	trace_check( 200 === $res->get_status() && 'message/rfc822' === ( $h['Content-Type'] ?? '' ) && 'exact' === ( $h['X-Mailspur-Source'] ?? '' ), 'download serves the exact raw source', $h );
	trace_check( $raw === $res->get_data() && false !== strpos( (string) ( $h['Content-Disposition'] ?? '' ), 'filename="mailspur-' . $trace_ids[1] . '.eml"' ), 'download body and safe file name', $h );

	$res  = trace_rest( '/mails/' . $trace_ids[0] . '/eml' );
	$mime = (string) $res->get_data();
	trace_check( 'reconstructed' === ( $res->get_headers()['X-Mailspur-Source'] ?? '' ) && false !== strpos( $mime, 'X-Mailspur-Reconstructed: yes' ), 'entries without raw source are reconstructed and marked', $mime );
	foreach ( array( 'Subject: Trace API mail', 'To: jane@example.com', 'From: Shop <shop@example.com>', 'Cc: boss@example.com', 'X-Custom: 1', 'Content-Type: text/html', '<p>Hi</p>' ) as $needle ) {
		trace_check( false !== strpos( $mime, $needle ), "reconstruction contains {$needle}", $mime );
	}

	// Streaming through the REST server: the body is sent unencoded.
	$request = new WP_REST_Request( 'GET', '/mailspur-email-log/v1/mails/' . $trace_ids[0] . '/eml' );
	ob_start();
	$served = apply_filters( 'rest_pre_serve_request', false, $res, $request, rest_get_server() );
	$output = ob_get_clean();
	trace_check( true === $served && $mime === $output, 'rest_pre_serve_request streams the .eml unencoded', array( $served, substr( (string) $output, 0, 200 ) ) );
	ob_start();
	$served = apply_filters( 'rest_pre_serve_request', false, trace_rest( '/mails/' . $trace_ids[0] ), new WP_REST_Request( 'GET', '/mailspur-email-log/v1/mails/' . $trace_ids[0] ), rest_get_server() );
	ob_end_clean();
	trace_check( false === $served, 'other routes stay JSON' );

	trace_check( 404 === trace_rest( '/mails/999999/eml' )->get_status(), 'download of a missing entry is a 404' );
	wp_set_current_user( 0 );
	trace_check( 401 === trace_rest( '/mails/' . $trace_ids[0] . '/eml' )->get_status(), 'download requires log access' );
	wp_set_current_user( 1 );

	// ------------------------------------------------------------------ settings.
	$clean = \Mailspur\Settings::sanitize(
		array(
			'trace_transcript' => 'always',
			'trace_raw'        => '1',
		)
	);
	trace_check( 'always' === $clean['trace_transcript'] && false === $clean['trace_raw'], 'raw source is only enabled with the acknowledgement', $clean );
	trace_check( 'failed' === \Mailspur\Settings::get( 'trace_transcript' ) && false === \Mailspur\Settings::get( 'trace_raw' ), 'defaults: transcript for failed mails, no raw source' );
} catch ( Throwable $e ) {
	trace_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Clean up.
update_option( 'mailspur_settings', $trace_settings );
( new Repository() )->delete( $trace_ids );
if ( is_file( $trace_plugin_dir . '/trace-fake-api.php' ) ) {
	unlink( $trace_plugin_dir . '/trace-fake-api.php' );
	rmdir( $trace_plugin_dir );
}
wp_set_current_user( 0 );

$trace_failed = array_values(
	array_filter(
		$trace_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
wp_mkdir_p( '/e2e-out/features' );
file_put_contents(
	'/e2e-out/features/trace.json',
	wp_json_encode(
		array(
			'passed'  => count( $trace_results ) - count( $trace_failed ),
			'failed'  => count( $trace_failed ),
			'results' => $trace_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
// No exception on failure: later blueprint steps must still run; scripts/e2e.mjs evaluates the report.
