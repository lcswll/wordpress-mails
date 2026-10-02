<?php
/**
 * Runs the static (non-PHPCS) checks of the official Plugin Check plugin inside Playground
 * and writes the findings to /e2e-out/plugin-check.json.
 *
 * The PHPCS-based Plugin Check rules run outside Playground (scripts/plugin-check.mjs) because
 * php-wasm cannot take the file locks PHPCS uses for its temp reports.
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

$mailspur_checks = array(
	'code_obfuscation',
	'plugin_content',
	'file_type',
	'plugin_header_fields',
	'plugin_updater',
	'plugin_uninstall',
	'plugin_readme',
	'no_unfiltered_uploads',
	'trademarks',
	'direct_file_access',
	'external_admin_menu_links',
	'wp_functions_compatibility',
);

$mailspur_runner = new WordPress\Plugin_Check\Checker\AJAX_Runner();
$mailspur_runner->set_plugin( 'mailspur-email-log/mailspur-email-log.php' );
$mailspur_runner->set_check_slugs( $mailspur_checks );
$mailspur_runner->set_experimental_flag( true );
$mailspur_cleanup = $mailspur_runner->prepare();
$mailspur_result  = $mailspur_runner->run();
$mailspur_cleanup();

$mailspur_findings = array();
foreach ( array(
	'ERROR'   => $mailspur_result->get_errors(),
	'WARNING' => $mailspur_result->get_warnings(),
) as $mailspur_type => $mailspur_files ) {
	foreach ( $mailspur_files as $mailspur_file => $mailspur_lines ) {
		foreach ( $mailspur_lines as $mailspur_line => $mailspur_columns ) {
			foreach ( $mailspur_columns as $mailspur_messages ) {
				foreach ( $mailspur_messages as $mailspur_message ) {
					$mailspur_findings[] = array(
						'type'     => $mailspur_type,
						'file'     => $mailspur_file,
						'line'     => $mailspur_line,
						'code'     => $mailspur_message['code'],
						'message'  => wp_strip_all_tags( $mailspur_message['message'] ),
						'severity' => $mailspur_message['severity'] ?? 5,
					);
				}
			}
		}
	}
}

file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test harness output.
	'/e2e-out/plugin-check.json',
	wp_json_encode(
		array(
			'checks'   => $mailspur_checks,
			'findings' => $mailspur_findings,
		),
		JSON_PRETTY_PRINT
	)
);
