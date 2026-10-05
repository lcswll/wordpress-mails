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

use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\Delivery\Feedback;
use Mailspur\Modules\Delivery\Module;
use Mailspur\Modules\Delivery\Problems;
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

	// An API mailer on pre_wp_mail (like many SMTP/API plugins) must not deliver on a staging copy:
	// staging holds first, well-behaved handlers pass the non-null result through.
	$api_sent = 0;
	$api      = static function ( $result ) use ( &$api_sent ) {
		if ( null !== $result ) {
			return $result;
		}
		++$api_sent;
		return true;
	};
	add_filter( 'pre_wp_mail', $api, 10 );
	wp_mail( 'customer@example.com', 'Delivery hold vs API mailer', 'Body' );
	remove_filter( 'pre_wp_mail', $api, 10 );
	delivery_check( 0 === $api_sent && (int) Repository::STATUS_HELD === (int) delivery_last_row()['status'], 'hold wins over an API mailer on pre_wp_mail', $api_sent );

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

	// ---------------------------------------------------------- emergency brake.
	delete_option( Brake::STATE_OPTION );
	delete_option( Brake::COUNTER_OPTION );
	delivery_settings(
		array(
			'staging_mode'    => 'off',
			'brake_mode'      => 'hold',
			'brake_threshold' => 3,
			'alert_email'     => 'ops@example.com',
			'alert_recovery'  => true,
		)
	);
	$held_status = (int) Repository::STATUS_HELD;
	$brake_held  = static function ( $row ) use ( $held_status ) {
		return $held_status === (int) $row['status'] && Brake::HELD === ( $row['meta']['delivery']['held'] ?? '' );
	};
	for ( $i = 1; $i <= 3; $i++ ) {
		wp_mail( "flood{$i}@example.com", 'Brake test ' . $i, 'Body' );
	}
	$row = delivery_last_row();
	delivery_check( $held_status !== (int) $row['status'] && ! Brake::active(), 'brake: mails up to the threshold are delivered', $row );

	wp_mail( 'flood4@example.com', 'Brake test 4', 'Body' );
	$first_held = delivery_last_row();
	delivery_check( Brake::active() && $brake_held( $first_held ), 'brake: the mail above the threshold starts an incident and is held', array( Brake::state(), $first_held ) );
	wp_mail( 'flood5@example.com', 'Brake test 5', 'Body' );
	delivery_check( $brake_held( delivery_last_row() ), 'brake: further mails are held' );

	apply_filters( 'retrieve_password_message', 'Reset', 'key', 'user', null );
	wp_mail( 'admin@example.com', 'Brake password reset', 'Reset' );
	delivery_check( Repository::STATUS_HELD !== (int) delivery_last_row()['status'], 'brake: password reset mails still go out' );

	$alert_log = get_option( 'mailspur_insights_alert_log' );
	do_action( Brake::HOOK );
	$alert = delivery_last_row();
	delivery_check( 'mailspur:alert' === $alert['source'] && $held_status !== (int) $alert['status'] && false !== strpos( $alert['subject'], 'Emergency brake' ), 'brake: alert mail sent from cron and not held', $alert );
	delivery_check( false === strpos( $alert['message'], 'flood' ), 'brake: alert names no recipients', $alert['message'] );
	do_action( Brake::HOOK );
	delivery_check( (int) delivery_last_row()['id'] === (int) $alert['id'], 'brake: only one alert per incident' );

	$_GET['page'] = 'mailspur-email-log';
	$notice       = (string) delivery_call( 'admin_notices', 'brake_notice' );
	delivery_check( false !== strpos( $notice, 'Emergency brake:' ) && false !== strpos( $notice, '2 emails are held by the emergency brake.' ) && false !== strpos( $notice, 'data-mailspur-brake="release"' ), 'brake: notice with count and release button', $notice );
	$_GET['page'] = 'other';
	delivery_check( '' === delivery_call( 'admin_notices', 'brake_notice' ), 'brake: no notice on other screens' );
	$bar = new WP_Admin_Bar();
	delivery_call( 'admin_bar_menu', 'admin_bar', $bar );
	delivery_check( null !== $bar->get_node( 'mailspur-brake' ), 'brake: admin bar warning while emails are held' );

	$status = delivery_rest( 'GET', '/delivery/brake' )->get_data();
	delivery_check( ! empty( $status['active'] ) && 2 === $status['held'] && 3 === $status['threshold'] && ! empty( $status['sources'] ), 'brake: status route', $status );

	$sent  = 0;
	$guard = 0;
	do {
		$res   = delivery_rest( 'POST', '/delivery/brake/release' )->get_data();
		$sent += (int) ( $res['sent'] ?? 0 ) + (int) ( $res['failed'] ?? 0 );
	} while ( ! empty( $res['remaining'] ) && ++$guard < 10 );
	delivery_check( 2 === $sent && 0 === (int) $res['remaining'] && ! Brake::active() && ! Brake::holding(), 'brake: release sends every held mail and ends the incident', array( $res, Brake::state() ) );
	$first = ( new Repository() )->find( (int) $first_held['id'] );
	delivery_check( false !== strpos( (string) $first['meta'], '"held":"brake_released"' ), 'brake: released entries are marked', $first );
	$released = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND meta LIKE %s', Repository::table(), (int) $first_held['id'], '%"released_by":"brake"%' ) );
	delivery_check( 2 === $released, 'brake: released copies logged as new entries', $released );
	wp_mail( 'flood6@example.com', 'Brake test 6', 'Body' );
	delivery_check( Repository::STATUS_HELD !== (int) delivery_last_row()['status'], 'brake: paused after the release' );

	// Discard: a new incident.
	delete_option( Brake::STATE_OPTION );
	delete_option( Brake::COUNTER_OPTION );
	for ( $i = 1; $i <= 5; $i++ ) {
		wp_mail( "flood{$i}@example.com", 'Brake discard ' . $i, 'Body' );
	}
	$last = delivery_last_row();
	$res  = delivery_rest( 'POST', '/delivery/brake/discard' )->get_data();
	$last = ( new Repository() )->find( (int) $last['id'] );
	delivery_check( 2 === (int) ( $res['discarded'] ?? 0 ) && false !== strpos( (string) $last['meta'], '"held":"brake_discarded"' ) && ! Brake::holding(), 'brake: discard keeps the entries but marks them', array( $res, $last ) );

	// Alert only: delivered, notice offers "Mark as resolved".
	delete_option( Brake::STATE_OPTION );
	delete_option( Brake::COUNTER_OPTION );
	delivery_settings( array( 'brake_mode' => 'alert' ) );
	for ( $i = 1; $i <= 5; $i++ ) {
		wp_mail( "flood{$i}@example.com", 'Brake alert ' . $i, 'Body' );
	}
	delivery_check( Brake::active() && Repository::STATUS_HELD !== (int) delivery_last_row()['status'], 'brake: alert-only mode delivers' );
	$_GET['page'] = 'mailspur-email-log';
	delivery_check( false !== strpos( (string) delivery_call( 'admin_notices', 'brake_notice' ), 'data-mailspur-brake="reset"' ), 'brake: alert-only notice offers "Mark as resolved"' );
	delivery_rest( 'POST', '/delivery/brake/reset' );
	delivery_check( ! Brake::active() && (int) ( Brake::state()['paused'] ?? 0 ) > time(), 'brake: reset ends and pauses' );
	unset( $_GET['page'] );
	delivery_settings(
		array(
			'brake_mode'      => 'alert',
			'brake_threshold' => 0,
		)
	);
	if ( false === $alert_log ) {
		delete_option( 'mailspur_insights_alert_log' );
	} else {
		update_option( 'mailspur_insights_alert_log', $alert_log, false );
	}

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
		delivery_check( 403 === delivery_rest( 'POST', '/delivery/brake/release' )->get_status() && 403 === delivery_rest( 'GET', '/delivery/brake' )->get_status(), 'emergency brake: administrators only' );
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

	// ------------------------------------------- provider status + problem recipients.
	delete_option( Brake::STATE_OPTION );
	delete_option( Brake::COUNTER_OPTION );
	delete_option( Problems::OPTION );
	delivery_settings(
		array(
			'staging_mode'      => 'off',
			'brake_mode'        => 'off',
			'feedback_provider' => 'postmark',
			'problem_hold'      => true,
		)
	);
	$feedback_entry = static function ( $ref ) {
		return ( new Repository() )->insert(
			array(
				'created_at'   => current_time( 'mysql', true ),
				'status'       => Repository::STATUS_SENT,
				'recipients'   => 'bounce-me@example.com',
				'subject'      => 'Feedback test',
				'message'      => 'Body',
				'headers'      => '',
				'attachments'  => '',
				'content_type' => 'text/plain',
				'sender'       => '',
				'source'       => 'core',
				'error'        => '',
				'meta'         => wp_json_encode( array( 'feedback' => array( 'ref' => $ref ) ) ),
				'notes'        => 0,
				'size'         => 4,
				'raw'          => '',
			)
		);
	};
	// Webhooks arrive without a logged-in user.
	$webhook = static function ( $key, $payload, $provider = 'postmark' ) {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/mailspur-email-log/v1/delivery/webhook/' . $provider . '/' . $key );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
		$response = rest_ensure_response( rest_do_request( $request ) );
		wp_set_current_user( 1 );
		return $response;
	};
	$url     = Feedback::url( 'postmark' );
	$key     = Feedback::secret();
	delivery_check( false !== strpos( $url, '/delivery/webhook/postmark/' . $key ), 'webhook URL contains provider and secret', $url );

	$first = $feedback_entry( 'aaaaaaaaaaaa0001' );
	$res   = $webhook(
		$key,
		array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'e2e-1',
			'Recipient'  => 'bounce-me@example.com',
			'Metadata'   => array( 'mailspur' => 'aaaaaaaaaaaa0001' ),
		)
	);
	$item  = delivery_rest( 'GET', '/mails/' . $first )->get_data();
	delivery_check( 200 === $res->get_status() && 1 === ( $res->get_data()['matched'] ?? 0 ), 'webhook: delivery accepted and matched', $res->get_data() );
	delivery_check( 'delivered' === ( $item['meta']['feedback']['event'] ?? '' ) && 'postmark' === ( $item['meta']['feedback']['via'] ?? '' ), 'webhook: status on the log entry', $item['meta'] ?? null );

	$bounce = array(
		'RecordType' => 'Bounce',
		'ID'         => 1,
		'Type'       => 'HardBounce',
		'MessageID'  => 'e2e-1',
		'Email'      => 'bounce-me@example.com',
		'Metadata'   => array( 'mailspur' => 'aaaaaaaaaaaa0001' ),
	);
	$res    = $webhook( str_repeat( 'x', 32 ), $bounce );
	delivery_check( in_array( $res->get_status(), array( 401, 403 ), true ), 'webhook: wrong secret rejected', $res->get_status() );
	$res = $webhook( $key, $bounce, 'mailgun' );
	delivery_check( in_array( $res->get_status(), array( 401, 403 ), true ), 'webhook: other provider rejected', $res->get_status() );

	$webhook( $key, $bounce );
	$item = delivery_rest( 'GET', '/mails/' . $first )->get_data();
	delivery_check( 'bounced' === ( $item['meta']['feedback']['event'] ?? '' ) && true === ( $item['meta']['feedback']['hard'] ?? null ), 'webhook: hard bounce wins over delivery', $item['meta'] ?? null );
	delivery_check( ! Problems::is_problem( 'bounce-me@example.com' ), 'problem recipients: one hard failure is not enough' );
	$res = $webhook( $key, $bounce );
	delivery_check( 0 === ( $res->get_data()['matched'] ?? -1 ), 'webhook: replayed event ignored', $res->get_data() );

	$second             = $feedback_entry( 'aaaaaaaaaaaa0002' );
	$bounce['ID']       = 2;
	$bounce['Metadata'] = array( 'mailspur' => 'aaaaaaaaaaaa0002' );
	$webhook( $key, $bounce );
	delivery_check( Problems::is_problem( 'bounce-me@example.com' ), 'problem recipients: listed after the second hard bounce', Problems::all() );
	$item = delivery_rest( 'GET', '/mails/' . $second )->get_data();
	delivery_check( array( 'bounce-me@example.com' ) === ( $item['problem_recipients'] ?? null ), 'problem recipients: marked in the dialog', $item['problem_recipients'] ?? null );

	wp_mail( 'bounce-me@example.com', 'Problem hold test', 'Body' );
	$row = delivery_last_row();
	delivery_check( Repository::STATUS_HELD === (int) $row['status'] && Problems::HELD === ( $row['meta']['delivery']['held'] ?? '' ), 'problem recipients: further mails held', $row );
	delivery_check( 1 === preg_match( '/^[a-f0-9]{16}$/', (string) ( $row['meta']['feedback']['ref'] ?? '' ) ), 'provider status: reference stored for new mails', $row['meta'] );
	apply_filters( 'retrieve_password_message', 'Reset', 'key', 'user', null );
	wp_mail( 'bounce-me@example.com', 'Problem password reset', 'Reset' );
	delivery_check( Repository::STATUS_HELD !== (int) delivery_last_row()['status'], 'problem recipients: password reset mails still go out' );

	$settings_html = delivery_call( 'mailspur_settings_sections', 'render_settings', Settings::all(), Settings::OPTION );
	delivery_check( false !== strpos( (string) $settings_html, 'data-mailspur-allow="bounce-me@example.com"' ) && false !== strpos( (string) $settings_html, $key ), 'settings: problem list and webhook URL' );

	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$export    = call_user_func( $exporters['mailspur-problem-recipients']['callback'], 'bounce-me@example.com', 1 );
	delivery_check( 1 === count( $export['data'] ), 'privacy: problem recipient exported', $export );

	$res = delivery_rest( 'DELETE', '/delivery/problems', array( 'email' => 'bounce-me@example.com' ) );
	delivery_check( true === ( $res->get_data()['allowed'] ?? null ) && ! Problems::is_problem( 'bounce-me@example.com' ), 'problem recipients: allow again', $res->get_data() );
	delivery_settings(
		array(
			'feedback_provider' => '',
			'problem_hold'      => false,
		)
	);
	delivery_check( in_array( $webhook( $key, $bounce )->get_status(), array( 401, 403 ), true ), 'webhook: closed when the feature is off' );

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
	delete_option( Brake::STATE_OPTION );
	delete_option( Brake::COUNTER_OPTION );
	delete_transient( Brake::BASELINE_TRANSIENT );
	delete_option( Problems::OPTION );
	delete_option( Feedback::SECRET_OPTION );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, '%' . Feedback::SEEN_PREFIX . '%' ) );
	wp_clear_scheduled_hook( Brake::HOOK );
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
