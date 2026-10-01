<?php
/**
 * Runs the static (non-PHPCS) checks of the official Plugin Check plugin inside Playground
 * and writes the findings to /e2e-out/plugin-check.json.
 *
 * The PHPCS-based Plugin Check rules run outside Playground (scripts/plugin-check.mjs) because
 * php-wasm cannot take the file locks PHPCS uses for its temp reports.
 *
 * @package OutboxMailLog
 */

require '/wordpress/wp-load.php';

$outbox_mail_log_checks = array(
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

$outbox_mail_log_runner = new WordPress\Plugin_Check\Checker\AJAX_Runner();
$outbox_mail_log_runner->set_plugin( 'outbox-mail-log/outbox-mail-log.php' );
$outbox_mail_log_runner->set_check_slugs( $outbox_mail_log_checks );
$outbox_mail_log_runner->set_experimental_flag( true );
$outbox_mail_log_cleanup = $outbox_mail_log_runner->prepare();
$outbox_mail_log_result  = $outbox_mail_log_runner->run();
$outbox_mail_log_cleanup();

$outbox_mail_log_findings = array();
foreach ( array(
	'ERROR'   => $outbox_mail_log_result->get_errors(),
	'WARNING' => $outbox_mail_log_result->get_warnings(),
) as $outbox_mail_log_type => $outbox_mail_log_files ) {
	foreach ( $outbox_mail_log_files as $outbox_mail_log_file => $outbox_mail_log_lines ) {
		foreach ( $outbox_mail_log_lines as $outbox_mail_log_line => $outbox_mail_log_columns ) {
			foreach ( $outbox_mail_log_columns as $outbox_mail_log_messages ) {
				foreach ( $outbox_mail_log_messages as $outbox_mail_log_message ) {
					$outbox_mail_log_findings[] = array(
						'type'     => $outbox_mail_log_type,
						'file'     => $outbox_mail_log_file,
						'line'     => $outbox_mail_log_line,
						'code'     => $outbox_mail_log_message['code'],
						'message'  => wp_strip_all_tags( $outbox_mail_log_message['message'] ),
						'severity' => $outbox_mail_log_message['severity'] ?? 5,
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
			'checks'   => $outbox_mail_log_checks,
			'findings' => $outbox_mail_log_findings,
		),
		JSON_PRETTY_PRINT
	)
);
