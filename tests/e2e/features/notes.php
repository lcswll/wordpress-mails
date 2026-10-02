<?php
/**
 * Notes module inside real WordPress (Playground): rules run for logged and imported mails, settings
 * apply to new mails, REST payloads carry rendered notes, dynamic checks and error explanations.
 *
 * Writes /e2e-out/features/notes.json (evaluated by scripts/e2e.mjs). Never throws; cleans up after itself.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Repository;
use Mailspur\Settings;

$notes_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function notes_check( $ok, $name, $detail = null ) {
	global $notes_results;
	$notes_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return array<string,mixed>|null Latest log row with this subject. */
function notes_row( $subject ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subject = %s ORDER BY id DESC LIMIT 1', Repository::table(), $subject ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/** @return string[] Stored note codes of a row. */
function notes_codes( $row ) {
	$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
	return is_array( $meta ) && isset( $meta['notes'] ) ? array_column( $meta['notes'], 'code' ) : array();
}

/** @return mixed REST data. */
function notes_rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/mailspur-email-log/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) )->get_data();
}

function notes_settings( $changes ) {
	update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), $changes ) );
}

$notes_original = get_option( Settings::OPTION, array() );
$notes_prefix   = 'Notes e2e';

// No DNS in Playground (and tests must be deterministic): answer MX lookups here.
$notes_mx = static function ( $state, $domain ) {
	return 'nomx-domain.de' === $domain ? 'none' : 'ok';
};
add_filter( 'mailspur_notes_mx_lookup', $notes_mx, 10, 2 );

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
	notes_settings(
		array(
			'notes_enabled'  => true,
			'notes_ignore'   => '',
			'retention_days' => 0,
		)
	);

	notes_check( has_filter( 'mailspur_finalize_row' ), 'module hooks into mailspur_finalize_row' );

	// ------------------------------------------------- static rules, logged mails.
	add_filter( 'pre_wp_mail', '__return_true' ); // Delivered by "another mailer".

	$s1 = "$notes_prefix: hello {first_name}";
	wp_mail( 'anna@gmial.com', $s1, '<p>Hi!</p><p><a href="/my-account/">Your account</a></p>', array( 'Content-Type: text/html; charset=UTF-8', 'From: Shop <shop@example.com>' ) );
	$row1  = notes_row( $s1 );
	$codes = notes_codes( $row1 );
	sort( $codes );
	notes_check( array( 'placeholder', 'recipient_typo', 'relative_urls' ) === $codes, 'rules find placeholder, typo domain and relative link', $codes );
	notes_check( 3 === (int) $row1['notes'], 'notes column holds the count', $row1['notes'] ?? null );

	$s2 = "$notes_prefix: clean";
	wp_mail( 'carl@gmail.com', $s2, '<p>Hello Carl, your order has shipped.</p>', array( 'Content-Type: text/html; charset=UTF-8', 'From: Shop <shop@example.com>' ) );
	$row2 = notes_row( $s2 );
	notes_check( 0 === (int) $row2['notes'] && array() === notes_codes( $row2 ), 'a clean mail has no notes', $row2 );

	// Two identical mails within minutes → repeated-sending check in the detail view.
	$s3 = "$notes_prefix: twice";
	wp_mail( 'bob@nomx-domain.de', $s3, 'Body', array( 'From: Shop <shop@example.com>' ) );
	wp_mail( 'bob@nomx-domain.de', $s3, 'Body', array( 'From: Shop <shop@example.com>' ) );
	remove_filter( 'pre_wp_mail', '__return_true' );

	// The real PHPMailer route (fails in Playground: no MTA) records text/plain → HTML in a plain mail.
	$s4 = "$notes_prefix: plain html";
	wp_mail( 'dora@example.org', $s4, '<p>Hello <b>Dora</b></p>' );
	$row4 = notes_row( $s4 );
	notes_check( in_array( 'html_in_plain', notes_codes( $row4 ), true ), 'HTML sent as text/plain is noticed', array( $row4['content_type'] ?? null, notes_codes( $row4 ) ) );

	// -------------------------------------------------------------- REST: detail.
	$item = notes_rest( 'GET', '/mails/' . (int) $row1['id'] );
	notes_check( 3 === count( $item['notes_list'] ?? array() ) && 'error' === $item['notes_list'][0]['severity'], 'detail lists rendered notes, worst first', $item['notes_list'] ?? $item );
	$titles = wp_list_pluck( $item['notes_list'] ?? array(), 'title' );
	notes_check( in_array( 'Typo in recipient domain? gmial.com → gmail.com', $titles, true ), 'note texts are filled in', $titles );
	notes_check( null === $item['error_help'], 'no error explanation for a sent mail', $item['error_help'] ?? 'missing' );

	$twice = notes_row( $s3 );
	$item  = notes_rest( 'GET', '/mails/' . (int) $twice['id'] );
	$dyn   = array();
	foreach ( $item['notes_list'] ?? array() as $note ) {
		if ( $note['dynamic'] ) {
			$dyn[ $note['code'] ] = $note['title'];
		}
	}
	notes_check( isset( $dyn['no_mx'] ), 'recipient domain without MX is reported when opened', $item['notes_list'] ?? $item );
	notes_check( isset( $dyn['duplicate'] ) && false !== strpos( $dyn['duplicate'], '1 more' ), 'repeated sending is reported when opened', $dyn );
	notes_check( 0 === (int) $twice['notes'], 'dynamic notes are not stored', $twice['notes'] );

	$item = notes_rest( 'GET', '/mails/' . (int) $row4['id'] );
	notes_check( is_array( $item['error_help'] ) && 'mail_function' === $item['error_help']['key'] && count( $item['error_help']['steps'] ) > 0, 'failed mail gets an explanation with steps', $item['error_help'] ?? $item );

	// ---------------------------------------------------------------- REST: list.
	$list = notes_rest( 'GET', '/mails', array( 'search' => $notes_prefix ) );
	$info = array();
	foreach ( $list['items'] as $entry ) {
		$info[ $entry['subject'] ] = array( array_key_exists( 'notes_info', $entry ) ? $entry['notes_info'] : 'missing', $entry['error_hint'] ?? '' );
	}
	notes_check( isset( $info[ $s1 ][0]['level'] ) && 'error' === $info[ $s1 ][0]['level'] && 3 === $info[ $s1 ][0]['count'], 'list rows carry count and worst severity', $info[ $s1 ] ?? $info );
	notes_check( isset( $info[ $s2 ] ) && null === $info[ $s2 ][0], 'clean rows carry no notes info', $info[ $s2 ] ?? $info );
	notes_check( isset( $info[ $s4 ] ) && 'The server cannot send email with PHP mail()' === $info[ $s4 ][1], 'failed rows carry a short error hint', $info[ $s4 ] ?? $info );

	// ------------------------------------------------------------------- settings.
	$clean = Settings::sanitize(
		array(
			'notes_enabled' => '1',
			'notes_ignore'  => array( 'placeholder', 'bogus' ),
		)
	);
	notes_check( true === $clean['notes_enabled'] && 'placeholder' === $clean['notes_ignore'], 'settings are sanitized through the module', $clean );

	notes_settings( array( 'notes_ignore' => 'placeholder' ) );
	add_filter( 'pre_wp_mail', '__return_true' );
	$s5 = "$notes_prefix: ignored {first_name}";
	wp_mail( 'eve@example.org', $s5, 'Hi', array( 'From: Shop <shop@example.com>' ) );
	notes_check( 0 === (int) notes_row( $s5 )['notes'], 'ignored codes are not recorded for new mails' );
	$item = notes_rest( 'GET', '/mails/' . (int) $row1['id'] );
	notes_check( ! in_array( 'placeholder', wp_list_pluck( $item['notes_list'], 'code' ), true ) && 3 === (int) notes_row( $s1 )['notes'], 'ignored codes are hidden for existing entries (stored count unchanged)', $item['notes_list'] );

	notes_settings(
		array(
			'notes_enabled' => false,
			'notes_ignore'  => '',
		)
	);
	$s6 = "$notes_prefix: disabled";
	wp_mail( 'fay@gmial.com', $s6, 'Hi', array( 'From: Shop <shop@example.com>' ) );
	remove_filter( 'pre_wp_mail', '__return_true' );
	notes_check( 0 === (int) notes_row( $s6 )['notes'] && array() === notes_codes( notes_row( $s6 ) ), 'no checks while hints are disabled' );
	$item = notes_rest( 'GET', '/mails/' . (int) $row1['id'] );
	notes_check( ! isset( $item['notes_list'] ), 'detail has no notes while disabled', array_keys( $item ) );
	notes_settings( array( 'notes_enabled' => true ) );

	// --------------------------------------------------------------------- import.
	$p = $wpdb->prefix;
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}mail_catcher_logs (id INTEGER PRIMARY KEY AUTO_INCREMENT, time INT NOT NULL, email_to TEXT, subject TEXT, message TEXT, backtrace_segment TEXT NOT NULL, status TINYINT(1) NOT NULL DEFAULT 1, error TEXT, attachments TEXT, additional_headers TEXT, is_html TINYINT(1) DEFAULT 0)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- fixture.
	$s7 = "$notes_prefix: imported";
	$wpdb->insert(
		"{$p}mail_catcher_logs",
		array(
			'time'               => time() - 3600,
			'email_to'           => 'gus@hotmial.com',
			'subject'            => $s7,
			'message'            => 'Hello',
			'backtrace_segment'  => '{}',
			'status'             => 0,
			'error'              => 'SMTP Error: Could not authenticate.',
			'attachments'        => '[]',
			'additional_headers' => wp_json_encode( array( 'From: Shop <shop@example.com>' ) ),
			'is_html'            => 0,
		)
	);
	$source_id = (int) $wpdb->insert_id;
	notes_rest( 'DELETE', '/import/wp-mail-catcher' ); // Fresh progress.
	$calls = 0;
	do {
		$step = notes_rest( 'POST', '/import/wp-mail-catcher' );
		++$calls;
	} while ( is_array( $step ) && empty( $step['done'] ) && $calls < 20 );
	$row7 = notes_row( $s7 );
	notes_check( $row7 && in_array( 'recipient_typo', notes_codes( $row7 ), true ) && (int) $row7['notes'] >= 1, 'imported mails are checked too', $row7 ? array( $row7['notes'], notes_codes( $row7 ) ) : $step );
	if ( $row7 ) {
		$item = notes_rest( 'GET', '/mails/' . (int) $row7['id'] );
		notes_check( isset( $item['error_help']['key'] ) && 'smtp_auth' === $item['error_help']['key'], 'imported errors are explained', $item['error_help'] ?? $item );
	}
	notes_rest( 'DELETE', '/import/wp-mail-catcher' );
	$wpdb->delete( "{$p}mail_catcher_logs", array( 'id' => $source_id ) );
} catch ( Throwable $e ) {
	notes_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Leave the site as we found it.
remove_filter( 'mailspur_notes_mx_lookup', $notes_mx, 10 );
update_option( Settings::OPTION, $notes_original );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject LIKE %s', Repository::table(), $wpdb->esc_like( $notes_prefix ) . '%' ) );
wp_set_current_user( 0 );

$notes_failed = array_values(
	array_filter(
		$notes_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
if ( ! is_dir( '/e2e-out/features' ) ) {
	mkdir( '/e2e-out/features', 0777, true );
}
file_put_contents(
	'/e2e-out/features/notes.json',
	wp_json_encode(
		array(
			'passed'  => count( $notes_results ) - count( $notes_failed ),
			'failed'  => count( $notes_failed ),
			'results' => $notes_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
