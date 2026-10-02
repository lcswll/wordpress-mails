<?php
/**
 * WP-CLI commands: wp mailspur list|show|resend|stats|purge|export|import.
 *
 * Only I/O lives here (arguments, output, confirmation, exit codes); the logic is in Commands.
 * Registered only when WP_CLI is defined (Module::register()).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Work with the Mailspur email log.
 *
 * ## EXAMPLES
 *
 *     # Failed mails of the last week
 *     $ wp mailspur list --status=failed --since="7 days ago"
 *
 *     # Delivery statistics of the last 30 days
 *     $ wp mailspur stats
 *
 *     # Delete everything older than a year
 *     $ wp mailspur purge --before="1 year ago" --yes
 */
final class Cli {

	/** @var Commands */
	private $commands;

	public function __construct( Commands $commands ) {
		$this->commands = $commands;
	}

	/**
	 * Lists log entries, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only entries with this status.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - sent
	 *   - failed
	 *   - pending
	 *   - held
	 * ---
	 *
	 * [--search=<text>]
	 * : Search recipients and subject.
	 *
	 * [--in-body]
	 * : Also search the message content.
	 *
	 * [--since=<date>]
	 * : Entries from this day on (YYYY-MM-DD or e.g. "30 days ago", site time zone).
	 *
	 * [--until=<date>]
	 * : Entries up to and including this day.
	 *
	 * [--source=<source>]
	 * : Exact source, e.g. "plugin:woocommerce", "theme:astra", "core".
	 *
	 * [--content=<type>]
	 * : Only HTML or only plain-text mails.
	 * ---
	 * options:
	 *   - html
	 *   - text
	 * ---
	 *
	 * [--attachments]
	 * : Only entries with attachments.
	 *
	 * [--notes]
	 * : Only entries with notes.
	 *
	 * [--limit=<number>]
	 * : Maximum number of entries.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur list --status=failed --since=2026-01-01
	 *     $ wp mailspur list --source=plugin:woocommerce --format=count
	 *
	 * @subcommand list
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function list_entries( array $args, array $assoc_args ): void {
		$filters = $this->guard(
			static function () use ( $assoc_args ): array {
				return Commands::filters( $assoc_args );
			}
		);
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $this->commands->count( $filters ) );
			return;
		}
		$items = $this->commands->items( $filters, max( 1, (int) ( $assoc_args['limit'] ?? 50 ) ) );
		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_column( $items, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, Commands::LIST_FIELDS );
	}

	/**
	 * Shows one log entry.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Log entry ID.
	 *
	 * [--body]
	 * : Include the message content.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur show 42 --body
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function show( array $args, array $assoc_args ): void {
		$commands = $this->commands;
		$entry    = $this->guard(
			static function () use ( $commands, $args, $assoc_args ): array {
				return $commands->show( (int) ( $args[0] ?? 0 ), ! empty( $assoc_args['body'] ) );
			}
		);
		$format   = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $format ) {
			$rows = array();
			foreach ( $entry as $field => $value ) {
				$rows[] = array(
					'Field' => $field,
					'Value' => (string) $value,
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, array( $entry ), array_keys( $entry ) );
	}

	/**
	 * Sends a logged email again (logged as a new entry with source "mailspur:resend").
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Log entry ID.
	 *
	 * [--to=<emails>]
	 * : Send to these comma-separated addresses instead of the original recipients.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur resend 42
	 *     $ wp mailspur resend 42 --to=me@example.com --yes
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function resend( array $args, array $assoc_args ): void {
		$id    = (int) ( $args[0] ?? 0 );
		$to    = (string) ( $assoc_args['to'] ?? '' );
		$entry = $this->guard(
			function () use ( $id ): array {
				return $this->commands->show( $id, false );
			}
		);
		WP_CLI::confirm( sprintf( 'Send "%s" again to %s?', $entry['subject'], '' !== $to ? $to : $entry['to'] ), $assoc_args );

		$result = $this->guard(
			function () use ( $id, $to ): array {
				return $this->commands->resend( $id, $to );
			}
		);
		if ( $result['missing'] ) {
			WP_CLI::warning( 'Attachments no longer available: ' . implode( ', ', $result['missing'] ) );
		}
		if ( ! $result['sent'] ) {
			WP_CLI::error( 'Sending failed – see the new log entry for details.' );
		}
		WP_CLI::success( sprintf( 'Sent again to %s.', $result['to'] ) );
	}

	/**
	 * Shows delivery statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Period in days.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur stats --days=7
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function stats( array $args, array $assoc_args ): void {
		$days  = (int) ( $assoc_args['days'] ?? 30 );
		$stats = $this->guard(
			function () use ( $days ): array {
				return $this->commands->stats( $days );
			}
		);
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array(
			array(
				'Metric' => 'Since (UTC)',
				'Value'  => $stats['since'],
			),
			array(
				'Metric' => 'Total',
				'Value'  => (string) $stats['total'],
			),
		);
		foreach ( $stats['statuses'] as $status => $n ) {
			$rows[] = array(
				'Metric' => ucfirst( $status ),
				'Value'  => (string) $n,
			);
		}
		$rows[] = array(
			'Metric' => 'Failure rate',
			'Value'  => $stats['failure_rate'] . ' %',
		);
		$rows[] = array(
			'Metric' => 'Logged size',
			'Value'  => (string) size_format( $stats['bytes'], 1 ),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Metric', 'Value' ) );

		if ( $stats['sources'] ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Top sources:' );
			\WP_CLI\Utils\format_items( 'table', $stats['sources'], array( 'source', 'count', 'failed' ) );
		}
	}

	/**
	 * Deletes log entries.
	 *
	 * Without options this empties the whole log.
	 *
	 * ## OPTIONS
	 *
	 * [--before=<date>]
	 * : Only entries sent before this day (YYYY-MM-DD or e.g. "90 days ago").
	 *
	 * [--status=<status>]
	 * : Only entries with this status.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - sent
	 *   - failed
	 *   - pending
	 *   - held
	 * ---
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur purge --before="90 days ago" --status=sent --yes
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function purge( array $args, array $assoc_args ): void {
		$filters = $this->guard(
			static function () use ( $assoc_args ): array {
				return Commands::purge_filters( $assoc_args );
			}
		);
		$count   = $this->commands->count( $filters );
		if ( 0 === $count ) {
			WP_CLI::success( 'Nothing to delete.' );
			return;
		}
		WP_CLI::confirm( sprintf( 'Delete %d log entries? This cannot be undone.', $count ), $assoc_args );
		WP_CLI::success( sprintf( 'Deleted %d log entries.', $this->commands->purge( $filters ) ) );
	}

	/**
	 * Exports log entries as CSV or JSON (newest first).
	 *
	 * Accepts the same filters as `wp mailspur list`.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * [--body]
	 * : Include headers and message content.
	 *
	 * [--status=<status>]
	 * : Only entries with this status (all, sent, failed, pending, held).
	 *
	 * [--search=<text>]
	 * : Search recipients and subject.
	 *
	 * [--since=<date>]
	 * : Entries from this day on.
	 *
	 * [--until=<date>]
	 * : Entries up to and including this day.
	 *
	 * [--source=<source>]
	 * : Exact source, e.g. "plugin:woocommerce".
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur export --format=json --file=mail-log.json
	 *     $ wp mailspur export --status=failed > failed.csv
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function export( array $args, array $assoc_args ): void {
		$filters = $this->guard(
			static function () use ( $assoc_args ): array {
				return Commands::filters( $assoc_args );
			}
		);
		$format  = 'json' === ( $assoc_args['format'] ?? 'csv' ) ? 'json' : 'csv';
		$file    = (string) ( $assoc_args['file'] ?? '' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming CLI output to a user-chosen file; WP_Filesystem cannot append.
		$handle = '' !== $file ? fopen( $file, 'wb' ) : null;
		if ( false === $handle ) {
			WP_CLI::error( sprintf( 'Cannot write to %s.', $file ) );
		}
		$write = static function ( string $chunk ) use ( $handle ): void {
			if ( $handle ) {
				fwrite( $handle, $chunk );
			} else {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV/JSON on standard output.
			}
		};
		$count = ( new Exporter( $this->commands->repository() ) )->stream( $filters, $format, ! empty( $assoc_args['body'] ), $write );
		if ( $handle ) {
			fclose( $handle );
			WP_CLI::success( sprintf( 'Exported %d log entries to %s.', $count, $file ) );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	/**
	 * Imports the log of another mail logging plugin.
	 *
	 * ## OPTIONS
	 *
	 * [<source>]
	 * : Source id: wp-mail-logging, email-log, check-email, fluent-smtp, post-smtp, suremails, wp-mail-catcher, wp-mail-log.
	 *
	 * [--all]
	 * : Import every source found on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mailspur import wp-mail-logging
	 *     $ wp mailspur import --all
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function import( array $args, array $assoc_args ): void {
		$id = ! empty( $assoc_args['all'] ) ? 'all' : (string) ( $args[0] ?? '' );
		if ( '' === $id ) {
			WP_CLI::error( 'Name a source or use --all.' );
		}
		$results = $this->guard(
			function () use ( $id ): array {
				return $this->commands->import(
					$id,
					static function ( string $source, array $batch ): void {
						WP_CLI::log( sprintf( '%s: %d imported, %d duplicates, %d too old …', $source, $batch['imported'], $batch['duplicates'], $batch['skipped'] ) );
					}
				);
			}
		);
		if ( ! $results ) {
			WP_CLI::warning( 'No log of another mail logging plugin was found on this site.' );
			return;
		}
		foreach ( $results as $source => $total ) {
			WP_CLI::success( sprintf( '%s: %d imported, %d duplicates, %d too old.', $source, $total['imported'], $total['duplicates'], $total['skipped'] ) );
		}
	}

	/**
	 * Runs $fn and turns exceptions into WP_CLI::error() (exit code 1).
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	private function guard( callable $callback ) {
		try {
			return $callback();
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}
