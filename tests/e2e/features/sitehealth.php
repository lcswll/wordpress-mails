<?php
/**
 * Site Health module inside real WordPress (Playground): the tests are registered in WP_Site_Health for users who
 * may view the log, return the expected statuses for failures, staging mode and the emergency brake, the async
 * sender test answers via REST (DNS-free: a cached result is prepared), and the Info section carries no addresses.
 *
 * Writes /e2e-out/features/sitehealth.json (evaluated by scripts/e2e.mjs). Never throws; cleans up after itself.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\SiteHealth\Checks;
use Mailspur\Repository;
use Mailspur\Settings;

$health_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function health_check( $ok, $name, $detail = null ) {
	global $health_results;
	$health_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** Runs a registered direct test the way WP_Site_Health does. */
function health_run( $id ) {
	$tests = WP_Site_Health::get_tests();
	if ( ! isset( $tests['direct'][ $id ]['test'] ) || ! is_callable( $tests['direct'][ $id ]['test'] ) ) {
		return array( 'status' => 'missing' );
	}
	return call_user_func( $tests['direct'][ $id ]['test'] );
}

/** Logs a test email directly (no sending). */
function health_insert( $status, $source ) {
	( new Repository() )->insert(
		array(
			'created_at'  => current_time( 'mysql', true ),
			'status'      => $status,
			'recipients'  => 'anna@example.com',
			'subject'     => 'Site Health e2e',
			'message'     => 'Body',
			'headers'     => '',
			'attachments' => '',
			'source'      => $source,
			'error'       => Repository::STATUS_FAILED === $status ? 'SMTP Error: Could not authenticate.' : '',
			'meta'        => '',
			'raw'         => '',
		)
	);
}

function health_settings( array $values ) {
	update_option( Settings::OPTION, array_merge( Settings::all(), $values ) );
}

global $wpdb;
$health_before = get_option( Settings::OPTION );
$health_start  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Repository::table() ) );
$health_from   = static function () {
	return 'noreply@sitehealth-e2e.example';
};

try {
	wp_set_current_user( 1 );

	// -------------------------------------------------------------- registration.
	$tests = WP_Site_Health::get_tests();
	foreach ( array( 'mailspur_failures', 'mailspur_types', 'mailspur_brake', 'mailspur_staging' ) as $id ) {
		health_check( isset( $tests['direct'][ $id ] ) && is_callable( $tests['direct'][ $id ]['test'] ), "direct test $id registered", array_keys( $tests['direct'] ) );
	}
	health_check( ! empty( $tests['async']['mailspur_sender']['has_rest'] ) && false !== strpos( (string) $tests['async']['mailspur_sender']['test'], 'site-health/sender' ), 'async sender test registered with its REST route', $tests['async']['mailspur_sender'] ?? array_keys( $tests['async'] ) );

	foreach ( array( 'mailspur_failures', 'mailspur_types', 'mailspur_brake', 'mailspur_staging' ) as $id ) {
		$result = health_run( $id );
		health_check( ( $result['test'] ?? '' ) === $id && 'Mailspur' === ( $result['badge']['label'] ?? '' ) && in_array( $result['status'], array( 'good', 'recommended', 'critical' ), true ), "$id returns a valid result", $result );
	}

	// ------------------------------------------------------------------ failures.
	foreach ( array( Repository::STATUS_SENT, Repository::STATUS_FAILED, Repository::STATUS_FAILED, Repository::STATUS_FAILED ) as $status ) {
		health_insert( $status, 'core' );
	}
	$result = health_run( 'mailspur_failures' );
	health_check( 'critical' === $result['status'] && false !== strpos( $result['description'], 'The last 3 emails all failed.' ), 'three failures in a row are critical', $result );
	health_check( false !== strpos( $result['actions'], 'status=failed' ), 'failure test links to the failed emails', $result['actions'] );

	health_insert( Repository::STATUS_FAILED, 'mailspur:alert' );
	health_insert( Repository::STATUS_SENT, 'core' );
	$result = health_run( 'mailspur_failures' );
	health_check( false === strpos( $result['description'], 'The last 3 emails all failed.' ), 'a sent email ends the streak, own alerts do not count', $result );

	// ------------------------------------------------------------------ staging.
	health_settings( array( 'staging_mode' => 'hold' ) );
	$result = health_run( 'mailspur_staging' );
	health_check( 'recommended' === $result['status'], 'staging mode on a live site is recommended to check', $result );
	$staging_env = static function () {
		return 'staging';
	};
	add_filter( 'mailspur_environment_type', $staging_env );
	$result = health_run( 'mailspur_staging' );
	remove_filter( 'mailspur_environment_type', $staging_env );
	health_check( 'good' === $result['status'], 'staging mode on a staging site is fine', $result );
	health_settings( array( 'staging_mode' => 'off' ) );
	health_check( 'good' === health_run( 'mailspur_staging' )['status'], 'staging mode off is fine' );

	// -------------------------------------------------------------------- brake.
	$brake_before = get_option( Brake::STATE_OPTION, null );
	health_check( 'good' === health_run( 'mailspur_brake' )['status'], 'no incident: brake is fine' );
	update_option( Brake::STATE_OPTION, array( 'holding' => true ) );
	$result = health_run( 'mailspur_brake' );
	health_check( 'critical' === $result['status'], 'held emails are critical', $result );
	if ( null === $brake_before ) {
		delete_option( Brake::STATE_OPTION );
	} else {
		update_option( Brake::STATE_OPTION, $brake_before );
	}

	// ------------------------------------------------------------ sender (REST).
	add_filter( 'wp_mail_from', $health_from );
	set_transient(
		Checks::SENDER_TRANSIENT . md5( 'sitehealth-e2e.example' ),
		array(
			'spf'   => false,
			'dmarc' => true,
		),
		HOUR_IN_SECONDS
	);
	$response = rest_ensure_response( rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/site-health/sender' ) ) );
	$data     = (array) $response->get_data();
	health_check( 200 === $response->get_status() && 'recommended' === ( $data['status'] ?? '' ) && 'mailspur_sender' === ( $data['test'] ?? '' ), 'sender route reports a missing SPF record', $data );
	health_check( false !== strpos( (string) ( $data['label'] ?? '' ), 'No SPF record for the sender domain sitehealth-e2e.example' ), 'sender result names the domain', $data );
	remove_filter( 'wp_mail_from', $health_from );

	// ---------------------------------------------------------------- Info tab.
	health_settings( array( 'staging_redirect_to' => 'qa@example.net' ) );
	$info = apply_filters( 'debug_information', array() );
	health_check( isset( $info['mailspur-email-log']['fields']['entries'], $info['mailspur-email-log']['fields']['staging'] ), 'Info tab has a Mailspur section', $info['mailspur-email-log'] ?? array_keys( $info ) );
	health_check( false === strpos( (string) wp_json_encode( $info['mailspur-email-log'] ?? array() ), '@' ), 'Info section contains no email addresses', $info['mailspur-email-log'] ?? null );

	// ------------------------------------------------------------ capabilities.
	wp_set_current_user( 0 );
	$tests = WP_Site_Health::get_tests();
	health_check( ! isset( $tests['direct']['mailspur_failures'] ) && ! isset( $tests['async']['mailspur_sender'] ), 'no tests without the capability', array_keys( $tests['direct'] ) );
	$response = rest_ensure_response( rest_do_request( new WP_REST_Request( 'GET', '/mailspur-email-log/v1/site-health/sender' ) ) );
	health_check( 401 === $response->get_status(), 'sender route needs a logged-in user', $response->get_status() );
	health_check( ! isset( apply_filters( 'debug_information', array() )['mailspur-email-log'] ), 'no Info section without the capability' );
} catch ( Throwable $e ) {
	health_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Leave the site as we found it.
try {
	remove_filter( 'wp_mail_from', $health_from );
	if ( false === $health_before ) {
		delete_option( Settings::OPTION );
	} else {
		update_option( Settings::OPTION, $health_before );
	}
	delete_transient( Checks::SENDER_TRANSIENT . md5( 'sitehealth-e2e.example' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Repository::table(), $health_start ) );
	wp_set_current_user( 0 );
} catch ( Throwable $e ) {
	health_check( false, 'cleanup ' . get_class( $e ), $e->getMessage() );
}

$health_failed = array_values(
	array_filter(
		$health_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
if ( ! is_dir( '/e2e-out/features' ) ) {
	mkdir( '/e2e-out/features', 0777, true );
}
file_put_contents(
	'/e2e-out/features/sitehealth.json',
	wp_json_encode(
		array(
			'passed'  => count( $health_results ) - count( $health_failed ),
			'failed'  => count( $health_failed ),
			'results' => $health_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
