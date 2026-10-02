<?php
/**
 * Workflow module integration test inside real WordPress (Playground): list filters via REST, the sources
 * endpoint, CSV/JSON export, the WP-CLI command logic and the anonymisation cron.
 *
 * Writes /e2e-out/features/workflow.json (evaluated by scripts/e2e.mjs). Never throws.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

use Mailspur\Import\Importer;
use Mailspur\Modules\Workflow\Anonymiser;
use Mailspur\Modules\Workflow\Commands;
use Mailspur\Modules\Workflow\Exporter;
use Mailspur\Modules\Workflow\Filters;
use Mailspur\Modules\Workflow\Sources;
use Mailspur\Repository;
use Mailspur\Settings;

$wf_results = array();

/**
 * @param bool   $ok
 * @param string $name
 * @param mixed  $detail
 */
function wf_check( $ok, $name, $detail = null ) {
	global $wf_results;
	$wf_results[] = array(
		'ok'     => (bool) $ok,
		'name'   => $name,
		'detail' => $ok ? null : $detail,
	);
}

/** @return WP_REST_Response */
function wf_rest( $route, $params = array() ) {
	$request = new WP_REST_Request( 'GET', '/mailspur-email-log/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) );
}

/** Inserts a test row; returns its id. */
function wf_insert( array $row ) {
	return ( new Repository() )->insert(
		array_merge(
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'status'       => Repository::STATUS_SENT,
				'recipients'   => 'wf@example.com',
				'subject'      => '[wf-e2e] mail',
				'message'      => 'Hello',
				'headers'      => 'From: Shop <shop@example.com>',
				'attachments'  => '',
				'content_type' => 'text/plain',
				'sender'       => 'Shop <shop@example.com>',
				'source'       => 'core',
				'error'        => '',
				'meta'         => '',
				'notes'        => 0,
				'size'         => 5,
				'raw'          => '',
			),
			$row
		)
	);
}

/** @return array<string,string>|null */
function wf_row( $id ) {
	return ( new Repository() )->find( (int) $id );
}

$wf_settings = get_option( Settings::OPTION, array() );

try {
	global $wpdb;
	$table = Repository::table();
	$admin = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admin ? $admin[0]->ID : 1 );
	add_filter(
		'pre_wp_mail',
		static function () {
			return true; // No mail server in Playground.
		}
	);
	delete_transient( Sources::TRANSIENT );

	// ---------------------------------------------------------------- fixtures.
	$html   = wf_insert(
		array(
			'subject'      => '[wf-e2e] HTML with attachment',
			'content_type' => 'text/html',
			'attachments'  => wp_json_encode(
				array(
					array(
						'name' => 'invoice.pdf',
						'path' => '/tmp/secret-dir/invoice.pdf',
					),
				)
			),
			'source'       => 'plugin:mailspur-email-log',
		)
	);
	$noted  = wf_insert(
		array(
			'subject' => '=HYPERLINK("http://evil.example") [wf-e2e]',
			'notes'   => 2,
			'status'  => Repository::STATUS_FAILED,
			'source'  => 'plugin:mailspur-email-log',
		)
	);
	$held   = wf_insert(
		array(
			'subject' => '[wf-e2e] held',
			'status'  => Repository::STATUS_HELD,
			'source'  => 'theme:wf-missing-theme',
		)
	);
	$old    = wf_insert(
		array(
			'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			'subject'     => '[wf-e2e] old order for Anna',
			'recipients'  => 'Anna <anna.wf@example.com>, bob.wf@example.org',
			'message'     => '<p>Secret content</p>',
			'attachments' => '[{"name":"a.pdf","path":"/x/a.pdf"}]',
			'meta'        => '{"trace":{"file":"x.php"},"spam_score":4}',
			'error'       => 'Could not deliver to anna.wf@example.com',
			'notes'       => 1,
			'size'        => 321,
		)
	);
	$recent = wf_insert(
		array(
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ),
			'subject'    => '[wf-e2e] recent',
		)
	);
	wf_check( $html && $noted && $held && $old && $recent, 'fixtures inserted', $wpdb->last_error );
	$search = '[wf-e2e]';

	// ------------------------------------------------------------- list filters.
	$list = wf_rest(
		'/mails',
		array(
			'search' => $search,
			'source' => 'plugin:mailspur-email-log',
		)
	)->get_data();
	wf_check( 2 === $list['total'] && 1 === $list['counts']['failed'] && 1 === $list['counts']['sent'], 'source filter with status counts', $list['counts'] ?? $list );

	$list = wf_rest(
		'/mails',
		array(
			'search' => $search,
			'format' => 'html',
		)
	)->get_data();
	wf_check( 1 === $list['total'] && $html === $list['items'][0]['id'], 'format=html filter', $list );

	$list = wf_rest(
		'/mails',
		array(
			'search' => $search,
			'format' => 'text',
		)
	)->get_data();
	wf_check( 4 === $list['total'], 'format=text filter', $list['total'] ?? $list );

	$list = wf_rest(
		'/mails',
		array(
			'search'      => $search,
			'attachments' => true,
		)
	)->get_data();
	wf_check( 2 === $list['total'], 'attachments filter', $list['total'] ?? $list );
	wf_check( array( 'invoice.pdf' ) === $list['items'][1]['attachments'] || array( 'invoice.pdf' ) === $list['items'][0]['attachments'], 'attachment names only in the list', $list['items'] );

	$list = wf_rest(
		'/mails',
		array(
			'search'   => $search,
			'notes'    => true,
			'status'   => 'failed',
			'per_page' => 1,
		)
	)->get_data();
	wf_check( 1 === $list['total'] && $noted === $list['items'][0]['id'] && 2 === $list['counts']['all'], 'notes filter combined with status, counts and paging', $list );

	$list = wf_rest(
		'/mails',
		array(
			'search'   => $search,
			'per_page' => 2,
			'page'     => 2,
		)
	)->get_data();
	wf_check( 5 === $list['total'] && 3 === $list['pages'] && 2 === count( $list['items'] ), 'paging with filters', $list['pages'] ?? $list );

	wf_check( 400 === wf_rest( '/mails', array( 'format' => 'pdf' ) )->get_status(), 'invalid format rejected' );
	wf_check( 400 === wf_rest( '/mails', array( 'source' => str_repeat( 'x', 101 ) ) )->get_status(), 'overlong source rejected' );

	// ---------------------------------------------------------------- sources.
	$sources = wf_rest( '/sources' );
	$by      = array();
	foreach ( (array) ( $sources->get_data()['sources'] ?? array() ) as $source ) {
		$by[ $source['value'] ] = $source;
	}
	wf_check( 200 === $sources->get_status() && isset( $by['plugin:mailspur-email-log'] ), 'sources endpoint lists distinct sources', $sources->get_data() );
	wf_check( isset( $by['plugin:mailspur-email-log'] ) && 'Mailspur – Email Log' === $by['plugin:mailspur-email-log']['label'] && 2 === $by['plugin:mailspur-email-log']['count'], 'plugin source labelled via get_plugins()', $by['plugin:mailspur-email-log'] ?? null );
	wf_check( isset( $by['theme:wf-missing-theme'] ) && 'wf-missing-theme (theme)' === $by['theme:wf-missing-theme']['label'], 'unknown theme falls back to its slug', $by['theme:wf-missing-theme'] ?? null );
	wf_check( is_array( get_transient( Sources::TRANSIENT ) ), 'sources are cached' );

	$subscriber = wp_insert_user(
		array(
			'user_login' => 'wf_subscriber',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	$current    = get_current_user_id();
	wp_set_current_user( $subscriber );
	wf_check( 403 === wf_rest( '/sources' )->get_status(), 'sources endpoint rejects subscribers' );
	wp_set_current_user( $current );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $subscriber );

	// ----------------------------------------------------------------- export.
	$exporter = new Exporter( new Repository() );
	$csv      = '';
	$count    = $exporter->stream(
		Filters::sanitize( array( 'search' => $search ) ),
		'csv',
		false,
		static function ( $chunk ) use ( &$csv ) {
			$csv .= $chunk;
		}
	);
	$lines    = array_values( array_filter( explode( "\r\n", $csv ) ) );
	wf_check( 5 === $count && 6 === count( $lines ), 'CSV export streams every matching entry', array( $count, $lines ) );
	wf_check( 0 === strpos( $csv, "\xEF\xBB\xBF\"id\"," ), 'CSV starts with a UTF-8 BOM and the header' );
	wf_check( false !== strpos( $csv, "\"'=HYPERLINK(\"\"http://evil.example\"\") [wf-e2e]\"" ), 'CSV defuses formulas', $csv );
	wf_check( false === strpos( $csv, '/tmp/secret-dir' ) && false !== strpos( $csv, '"invoice.pdf"' ), 'CSV contains attachment names, never paths' );
	wf_check( false === strpos( $csv, 'Secret content' ), 'CSV without bodies by default' );

	$json = '';
	$exporter->stream(
		Filters::sanitize(
			array(
				'search' => $search,
				'status' => 'failed',
			)
		),
		'json',
		true,
		static function ( $chunk ) use ( &$json ) {
			$json .= $chunk;
		}
	);
	$data = json_decode( $json, true );
	wf_check( is_array( $data ) && 1 === count( $data ) && $noted === $data[0]['id'] && 'Hello' === $data[0]['message'], 'JSON export with bodies and status filter', $json );
	wf_check( 1 === preg_match( '/^mail-log-\d{4}-\d{2}-\d{2}\.csv$/', Exporter::filename( 'csv' ) ), 'export file name has the date' );
	wf_check( has_action( 'admin_post_mailspur_export' ) && ! has_action( 'admin_post_nopriv_mailspur_export' ), 'export only for logged-in users (admin-post)' );

	// ------------------------------------------------------------------- CLI.
	$cli = new Commands( new Repository() );
	wf_check( 5 === $cli->count( Commands::filters( array( 'search' => $search ) ) ), 'wp mailspur list --format=count' );
	$items = $cli->items(
		Commands::filters(
			array(
				'search' => $search,
				'since'  => gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ),
			)
		),
		2
	);
	wf_check( 2 === count( $items ) && $recent !== $items[0]['id'], 'wp mailspur list --since --limit (newest first)', $items );
	$show = $cli->show( $html, true );
	wf_check( 'invoice.pdf' === $show['attachments'] && 'Hello' === $show['message'] && 'sent' === $show['status'], 'wp mailspur show --body', $show );

	$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );
	$resent = $cli->resend( $html, 'copy.wf@example.com' );
	$copy   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id DESC LIMIT 1', $table, $before ), ARRAY_A );
	wf_check( $resent['sent'] && array( 'invoice.pdf' ) === $resent['missing'] && $copy && 'copy.wf@example.com' === $copy['recipients'] && 'mailspur:resend' === $copy['source'], 'wp mailspur resend --to', array( $resent, $copy ) );
	if ( $copy ) {
		( new Repository() )->delete( array( (int) $copy['id'] ) );
	}

	$stats = $cli->stats( 30 );
	wf_check( $stats['total'] >= 4 && $stats['statuses']['held'] >= 1 && $stats['failure_rate'] > 0, 'wp mailspur stats', $stats );

	try {
		$cli->import( 'not-a-source' );
		wf_check( false, 'wp mailspur import rejects unknown sources' );
	} catch ( InvalidArgumentException $e ) {
		wf_check( true, 'wp mailspur import rejects unknown sources' );
	}
	$wpml = Importer::source( 'wp-mail-logging' );
	if ( $wpml && $wpml->available() && $wpml->remaining( 0 ) > 0 ) {
		$imported = $cli->import( 'wp-mail-logging' );
		wf_check( isset( $imported['wp-mail-logging'] ) && array_sum( $imported['wp-mail-logging'] ) === $wpml->remaining( 0 ), 'wp mailspur import <source> runs to the end', $imported );
		( new Importer( new Repository() ) )->undo( $wpml );
	}

	// ---------------------------------------------------------- anonymisation.
	update_option(
		Settings::OPTION,
		Settings::sanitize(
			array_merge(
				Settings::all(),
				array(
					'retention_days'    => 0,
					'anonymise_days'    => '30',
					'anonymise_subject' => '',
				)
			)
		)
	);
	wf_check( 30 === Settings::get( 'anonymise_days' ) && false === Settings::get( 'anonymise_subject' ), 'anonymisation settings saved' );

	do_action( 'mailspur_cleanup' );
	$row = wf_row( $old );
	wf_check( $row && '' === $row['message'] && '' === $row['headers'] && '' === $row['attachments'], 'old entry: content removed by the daily cron', $row );
	wf_check( $row && 'a***@example.com, b***@example.org' === $row['recipients'] && 'Could not deliver to a***@example.com' === $row['error'], 'old entry: addresses masked', $row );
	wf_check( $row && '[wf-e2e] old order for Anna' === $row['subject'] && '1' === (string) $row['notes'] && '321' === (string) $row['size'], 'old entry: subject, notes and size kept', $row );
	$meta = $row ? json_decode( $row['meta'], true ) : null;
	wf_check( is_array( $meta ) && 0 === strpos( $row['meta'], Anonymiser::MARK ) && 30 === $meta['anonymised']['days'] && 1 === $meta['anonymised']['attachments'] && 4 === $meta['spam_score'] && ! isset( $meta['trace'] ), 'old entry: marked, counts kept, module data removed', $meta );
	wf_check( 'Hello' === wf_row( $recent )['message'], 'recent entry untouched' );

	$list = wf_rest(
		'/mails',
		array(
			'search' => 'old order for Anna',
		)
	)->get_data();
	wf_check( 1 === $list['total'] && true === $list['items'][0]['anonymised'], 'list flags anonymised entries', $list['items'] ?? $list );
	$detail = wf_rest( '/mails/' . $old )->get_data();
	wf_check( true === $detail['anonymised'] && 30 === $detail['meta']['anonymised']['days'], 'detail carries the anonymisation marker', $detail );
	$list = wf_rest( '/mails', array( 'search' => '[wf-e2e] recent' ) )->get_data();
	wf_check( false === $list['items'][0]['anonymised'], 'normal entries are not flagged' );

	wf_check( 0 === ( new Anonymiser( new Repository() ) )->run(), 'second run has nothing left to do' );
	try {
		$cli->resend( $old );
		wf_check( false, 'anonymised entries cannot be resent' );
	} catch ( RuntimeException $e ) {
		wf_check( true, 'anonymised entries cannot be resent' );
	}

	// Imported entries older than the limit are stored anonymised right away.
	$legacy   = array(
		'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
		'recipients' => 'legacy@example.com',
		'message'    => 'old',
		'meta'       => array( 'import' => 'x' ),
	);
	$imported = apply_filters( 'mailspur_finalize_row', $legacy, $legacy ); // Like Importer::finalize().
	wf_check( '' === $imported['message'] && 'l***@example.com' === $imported['recipients'], 'imported old entries are anonymised on import', $imported );

	// Purge (CLI) on the fixtures only.
	$deleted = $cli->purge( Commands::filters( array( 'search' => $search ) ) );
	wf_check( 5 === $deleted && 0 === $cli->count( Commands::filters( array( 'search' => $search ) ) ), 'wp mailspur purge deletes the matching entries', $deleted );
} catch ( Throwable $e ) {
	wf_check( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

// Leave the site as the self-test expects it.
try {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject LIKE %s', Repository::table(), '%' . $wpdb->esc_like( '[wf-e2e]' ) . '%' ) );
	update_option( Settings::OPTION, $wf_settings );
	delete_option( Anonymiser::CURSOR_OPTION );
	delete_transient( Sources::TRANSIENT );
} catch ( Throwable $e ) {
	wf_check( false, 'cleanup', $e->getMessage() );
}

$wf_failed = array_values(
	array_filter(
		$wf_results,
		static function ( $r ) {
			return ! $r['ok'];
		}
	)
);
if ( ! is_dir( '/e2e-out/features' ) ) {
	mkdir( '/e2e-out/features', 0777, true );
}
file_put_contents(
	'/e2e-out/features/workflow.json',
	wp_json_encode(
		array(
			'passed'  => count( $wf_results ) - count( $wf_failed ),
			'failed'  => count( $wf_failed ),
			'results' => $wf_results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	)
);
