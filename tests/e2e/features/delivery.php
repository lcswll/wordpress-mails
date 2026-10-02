<?php
/**
 * Delivery module integration test inside real WordPress (Playground): staging hold / redirect / release with
 * real wp_mail() calls, resend to other addresses, admin bar + notices, and the sender check (DNS tolerant:
 * Playground may have no resolver, so only the structure of the result is checked).
 *
 * Writes /e2e-out/features/delivery.json (evaluated by scripts/e2e.mjs). Never throws.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.Security.NonceVerification
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Modules\Delivery\Module;
use Mailspur\Modules\Delivery\SenderCheck;
use Mailspur\Repository;
use Mailspur\Settings;

$delivery_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function delivery_check( $ok, $name, $detail = null ) {
	global $delivery_results;
	$delivery_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return WP_REST_Response */
function delivery_rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/mailspur-email-log/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) );
}

/** @return array<string,mixed>|null */
function delivery_last_row() {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Repository::table() ), ARRAY_A );
	if ( is_array( $row ) ) {
		$row['meta'] = json_decode( (string) $row['meta'], true );
	}
	return $row;
}

function delivery_settings( array $values ) {
	update_option( Settings::OPTION, array_merge( Settings::all(), $values ) );
}

/** Calls a hooked method of the delivery module instance and returns its output. */
function delivery_call( $hook, $method, ...$args ) {
	global $wp_filter;
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Module && $method === $callback['function'][1] ) {
				ob_start();
				$callback['function'][0]->$method( ...$args );
				return (string) ob_get_clean();
			}
		}
	}
	return null;
}

global $wpdb;
$delivery_before = get_option( Settings::OPTION );
$delivery_start  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Repository::table() ) );

try {
	wp_set_current_user( 1 );

	// --------------------------------------------------------------------- hold.
	delivery_settings( array( 'staging_mode' => 'hold' ) );
	$sent = wp_mail( 'customer@example.com', 'Delivery hold test', 'Body' );
	$held = delivery_last_row();
	delivery_check( true === $sent, 'held mail: wp_mail() reports success to the caller', $sent );
	delivery_check( (int) Repository::STATUS_HELD === (int) $held['status'], 'held mail: status held', $held );
	delivery_check( 'staging' === ( $held['meta']['delivery']['held'] ?? '' ), 'held mail: reason in meta', $held['meta'] );
	delivery_check( 'customer@example.com' === $held['recipients'], 'held mail: original recipient logged', $held['recipients'] );

	$list = delivery_rest( 'GET', '/mails', array( 'status' => 'held' ) )->get_data();
	delivery_check( in_array( (int) $held['id'], array_column( (array) ( $list['items'] ?? array() ), 'id' ), true ), 'held filter lists the mail', $list );

	// Admin bar and notice while active.
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	$bar = new WP_Admin_Bar();
	delivery_call( 'admin_bar_menu', 'admin_bar', $bar );
	$node = $bar->get_node( 'mailspur-staging' );
	delivery_check( $node && false !== strpos( (string) $node->title, 'staging mode' ), 'admin bar shows staging mode', $node );

	$_GET['page'] = 'mailspur-email-log';
	$notice       = delivery_call( 'admin_notices', 'notices' );
	delivery_check( false !== strpos( (string) $notice, 'Staging mode is active: emails are logged but not delivered.' ), 'notice on the Mail Log screen', $notice );
	$_GET['page'] = 'other';
	delivery_check( '' === delivery_call( 'admin_notices', 'notices' ), 'no notice on other screens' );

	// ------------------------------------------------------------------ release.
	$res = delivery_rest( 'POST', '/mails/' . $held['id'] . '/release' );
	$new = delivery_last_row();
	delivery_check( 200 === $res->get_status() && array_key_exists( 'sent', (array) $res->get_data() ), 'release: route answers', $res->get_data() );
	delivery_check( (int) $new['id'] > (int) $held['id'] && 'mailspur:resend' === $new['source'], 'release: logged as new entry', $new );
	delivery_check( (int) Repository::STATUS_HELD !== (int) $new['status'] && 'customer@example.com' === $new['recipients'], 'release: delivery attempted to the original recipient', $new );
	$released = (int) ( $new['meta']['delivery']['released_from'] ?? 0 );
	delivery_check( (int) $held['id'] === $released, 'release: meta links the held entry', $new['meta'] );
	delivery_check( 409 === delivery_rest( 'POST', '/mails/' . $new['id'] . '/release' )->get_status(), 'release: only held mails' );

	$sent = wp_mail( 'customer@example.com', 'Delivery hold test 2', 'Body' );
	delivery_check( (int) Repository::STATUS_HELD === (int) delivery_last_row()['status'], 'release bypass ends after one mail' );

	// ----------------------------------------------------------------- redirect.
	delivery_settings(
		array(
			'staging_mode'        => 'redirect',
			'staging_redirect_to' => 'dev@example.net, qa@example.net',
		)
	);
	$seen    = array();
	$watcher = static function ( $mailer ) use ( &$seen ) {
		$seen = array(
			'to'  => array_column( $mailer->getToAddresses(), 0 ),
			'cc'  => $mailer->getCcAddresses(),
			'bcc' => $mailer->getBccAddresses(),
		);
	};
	add_action( 'phpmailer_init', $watcher );
	wp_mail( array( 'anna@example.com', 'bob@example.org' ), 'Delivery redirect test', 'Body', array( 'Cc: cc@example.com', 'Bcc: bcc@example.com', 'X-Test: 1' ) );
	remove_action( 'phpmailer_init', $watcher );
	$row = delivery_last_row();
	delivery_check( array( 'dev@example.net', 'qa@example.net' ) === ( $seen['to'] ?? null ) && array() === $seen['cc'] && array() === $seen['bcc'], 'redirect: PHPMailer only gets the redirect targets', $seen );
	delivery_check( 'dev@example.net, qa@example.net' === $row['recipients'], 'redirect: log shows the redirect target', $row['recipients'] );
	delivery_check( '[Staging → anna@example.com, bob@example.org] Delivery redirect test' === $row['subject'], 'redirect: subject prefixed', $row['subject'] );
	delivery_check( false === stripos( $row['headers'], 'cc:' ) && false !== strpos( $row['headers'], 'X-Test: 1' ), 'redirect: Cc/Bcc stripped, other headers kept', $row['headers'] );
	$d = $row['meta']['delivery'] ?? array();
	delivery_check(
		array( 'anna@example.com', 'bob@example.org' ) === ( $d['original_to'] ?? null ) && array( 'cc@example.com' ) === ( $d['original_cc'] ?? null ) && array( 'bcc@example.com' ) === ( $d['original_bcc'] ?? null ),
		'redirect: originals in meta',
		$d
	);
	$item = delivery_rest( 'GET', '/mails/' . $row['id'] )->get_data();
	delivery_check( isset( $item['meta']['delivery']['original_to'] ), 'redirect: originals in the REST detail payload', $item['meta'] ?? null );

	$_GET['page'] = 'mailspur-email-log';
	delivery_check( false !== strpos( (string) delivery_call( 'admin_notices', 'notices' ), 'redirected to dev@example.net, qa@example.net' ), 'redirect notice names the targets' );

	delivery_settings( array( 'staging_redirect_to' => '' ) );
	$sent = wp_mail( 'anna@example.com', 'Delivery redirect without address', 'Body' );
	$row  = delivery_last_row();
	delivery_check( (int) Repository::STATUS_HELD === (int) $row['status'] && 'no_redirect_address' === ( $row['meta']['delivery']['held'] ?? '' ), 'redirect without address holds instead', $row );

	// ---------------------------------------------------------- resend to others.
	delivery_settings( array( 'staging_mode' => 'off' ) );
	$res = delivery_rest( 'POST', '/mails/' . $held['id'] . '/resend', array( 'to' => 'x@example.com, y@example.com' ) );
	$row = delivery_last_row();
	delivery_check( 200 === $res->get_status() && array( 'x@example.com', 'y@example.com' ) === ( $res->get_data()['to'] ?? null ), 'send to: route accepts a comma list', $res->get_data() );
	delivery_check( 'x@example.com, y@example.com' === $row['recipients'] && 'mailspur:resend' === $row['source'], 'send to: new entry with the new recipients', $row );
	delivery_check( 400 === delivery_rest( 'POST', '/mails/' . $held['id'] . '/resend', array( 'to' => 'x@example.com, nope' ) )->get_status(), 'send to: invalid address rejected' );
	$many = array();
	for ( $i = 1; $i <= 11; $i++ ) {
		$many[] = "u{$i}@example.com";
	}
	$many = implode( ',', $many );
	delivery_check( 400 === delivery_rest( 'POST', '/mails/' . $held['id'] . '/resend', array( 'to' => $many ) )->get_status(), 'send to: more than 10 addresses rejected' );
	delivery_rest( 'POST', '/mails/' . $held['id'] . '/resend' );
	delivery_check( 'customer@example.com' === delivery_last_row()['recipients'], 'resend without "to" keeps the original recipients' );

	// Editors with log access may resend, but not to other addresses.
	$editor = wp_insert_user(
		array(
			'user_login' => 'delivery_editor',
			'user_pass'  => wp_generate_password(),
			'role'       => 'editor',
		)
	);
	if ( is_wp_error( $editor ) ) {
		delivery_check( false, 'editor created', $editor->get_error_message() );
	} else {
		delivery_settings( array( 'capability' => 'edit_others_posts' ) );
		wp_set_current_user( $editor );
		delivery_check( 403 === delivery_rest( 'POST', '/mails/' . $held['id'] . '/resend', array( 'to' => 'x@example.com' ) )->get_status(), 'send to: administrators only' );
		delivery_check( 403 === delivery_rest( 'POST', '/mails/' . $held['id'] . '/release' )->get_status(), 'release: administrators only' );
		delivery_check( 403 === delivery_rest( 'POST', '/delivery/check' )->get_status(), 'sender check: administrators only' );
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor );
	}

	// --------------------------------------------------------- staging suggestion.
	delivery_settings( array( 'staging_mode' => 'off' ) );
	$env = static function () {
		return 'staging';
	};
	add_filter( 'mailspur_environment_type', $env );
	$_GET['page'] = 'mailspur-email-log';
	delivery_check( false !== strpos( (string) delivery_call( 'admin_notices', 'notices' ), 'data-mailspur-hint' ), 'staging environment: suggestion shown' );
	delivery_rest( 'POST', '/delivery/hint' );
	delivery_check( '' === delivery_call( 'admin_notices', 'notices' ), 'suggestion dismissed' );
	remove_filter( 'mailspur_environment_type', $env );
	delete_option( 'mailspur_delivery_hint_dismissed' );

	// --------------------------------------------------------------- sender check.
	$res  = delivery_rest( 'POST', '/delivery/check', array( 'force' => true ) );
	$data = $res->get_data();
	delivery_check( 200 === $res->get_status() && isset( $data['available'], $data['domains'] ), 'sender check: route answers', $data );
	$shape = true;
	foreach ( (array) ( $data['domains'] ?? array() ) as $domain ) {
		$shape = $shape && array( 'spf', 'dmarc', 'dkim', 'mx' ) === array_column( $domain['checks'], 'id' );
	}
	delivery_check( $shape, 'sender check: four checks per domain', $data );
	$cached = delivery_rest( 'GET', '/delivery/check' )->get_data();
	delivery_check( is_array( $cached ) && true === $cached['cached'], 'sender check: result cached', $cached );
	delivery_check( 400 === delivery_rest( 'POST', '/delivery/check', array( 'selector' => 'bad selector!' ) )->get_status(), 'sender check: selector validated' );
	if ( SenderCheck::available() ) {
		// Live DNS is optional: only record what happened (no assertion on the network).
		$statuses = array();
		foreach ( (array) $data['domains'] as $domain ) {
			$statuses[ $domain['domain'] ] = array_column( $domain['checks'], 'status', 'id' );
		}
		delivery_check( true, 'sender check: live DNS ' . wp_json_encode( $statuses ) );
	}
} catch ( Throwable $e ) {
	delivery_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Clean up: entries, settings, transients.
try {
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d', Repository::table(), $delivery_start ) ) );
	( new Repository() )->delete( $ids );
	if ( false === $delivery_before ) {
		delete_option( Settings::OPTION );
	} else {
		update_option( Settings::OPTION, $delivery_before );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, '%mailspur_delivery_check_%' ) );
	unset( $_GET['page'] );
} catch ( Throwable $e ) {
	delivery_check( false, 'cleanup ' . get_class( $e ), $e->getMessage() );
}

$delivery_failed = array_values(
	array_filter(
		$delivery_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
if ( ! is_dir( '/e2e-out/features' ) ) {
	mkdir( '/e2e-out/features', 0777, true );
}
file_put_contents(
	'/e2e-out/features/delivery.json',
	wp_json_encode(
		array(
			'passed'  => count( $delivery_results ) - count( $delivery_failed ),
			'failed'  => count( $delivery_failed ),
			'results' => $delivery_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
