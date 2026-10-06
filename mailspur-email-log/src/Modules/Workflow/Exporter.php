<?php
/**
 * CSV / JSON export of the entries matching a list filter.
 *
 * - Streams: rows are read in keyset-paginated batches (no OFFSET, no full result in memory) and
 *   written out immediately, so 100k entries do not exhaust memory.
 * - CSV is safe to open in spreadsheets: cells starting with = + - @ tab or CR get a leading
 *   apostrophe (formula injection), UTF-8 BOM so Excel detects the encoding.
 * - Never exposes server paths: attachments are exported by file name only.
 *
 * Direct queries: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	const ACTION  = 'mailspur_export';
	const NONCE   = 'mailspur_export';
	const FORMATS = array( 'csv', 'json' );

	/** Rows per query: smaller with message bodies, which can be large. */
	const BATCH        = 500;
	const BATCH_BODIES = 50;

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/** admin-post.php?action=mailspur_export (POST, nonce-protected). */
	public function download(): void {
		if ( ! Module::can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export the mail log.', 'mailspur-email-log' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just above.
		$format = isset( $_POST['file'] ) && 'json' === sanitize_key( wp_unslash( $_POST['file'] ) ) ? 'json' : 'csv';
		$bodies = ! empty( $_POST['bodies'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$filters = Filters::from_post();

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $format ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$this->stream(
			$filters,
			$format,
			$bodies,
			static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV/JSON file download, not HTML; served as attachment with nosniff.
			}
		);
		exit;
	}

	/** E.g. "mail-log-2026-10-02.csv" (site date). */
	public static function filename( string $format ): string {
		return 'mail-log-' . wp_date( 'Y-m-d' ) . '.' . ( 'json' === $format ? 'json' : 'csv' );
	}

	/**
	 * Writes the export through $write, chunk by chunk.
	 *
	 * @param array<string,mixed>   $filters See Repository::filter().
	 * @param callable(string):void $write   Receives the output.
	 * @param int                   $limit   Maximum number of entries, 0 = all.
	 * @return int Number of exported entries.
	 */
	public function stream( array $filters, string $format, bool $bodies, callable $write, int $limit = 0 ): int {
		$json = 'json' === $format;
		$write( $json ? '[' : "\xEF\xBB\xBF" . self::csv_line( self::columns( $bodies ) ) );

		$count = 0;
		foreach ( $this->rows( $filters, $bodies, $limit ) as $row ) {
			$entry = self::entry( $row, $bodies );
			if ( $json ) {
				$write( ( $count ? ",\n" : "\n" ) . wp_json_encode( $entry ) );
			} else {
				$entry['attachments'] = implode( ', ', (array) $entry['attachments'] );
				$write( self::csv_line( $entry ) );
			}
			++$count;
		}

		if ( $json ) {
			$write( $count ? "\n]\n" : "]\n" );
		}
		return $count;
	}

	/**
	 * Matching raw rows, newest first, fetched in batches with keyset pagination on (created_at, id).
	 *
	 * @param array<string,mixed> $filters
	 * @return \Generator<int,array<string,string>>
	 */
	public function rows( array $filters, bool $bodies, int $limit = 0 ): \Generator {
		global $wpdb;

		list( $where, $params ) = $this->repository->filter( $filters );

		$columns = 'id, created_at, status, delivery, recipients, subject, attachments, content_type, sender, source, error, notes, size'
			. ( $bodies ? ', headers, message' : '' );
		$batch   = $bodies ? self::BATCH_BODIES : self::BATCH;
		$count   = 0;
		$last    = null;

		do {
			$size = $limit > 0 ? min( $batch, $limit - $count ) : $batch;
			$sql  = "SELECT {$columns} FROM %i WHERE {$where}";
			$args = $params;
			if ( null !== $last ) {
				$sql   .= ' AND ( created_at < %s OR ( created_at = %s AND id < %d ) )';
				$args[] = $last[0];
				$args[] = $last[0];
				$args[] = $last[1];
			}
			$sql   .= ' ORDER BY created_at DESC, id DESC LIMIT %d';
			$args[] = $size;

			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- columns are literals, $where comes from Repository::filter() (literals + placeholders).
			$got  = count( $rows );
			foreach ( $rows as $row ) {
				++$count;
				yield (array) $row;
			}
			$end  = end( $rows );
			$last = is_array( $end ) ? array( (string) $end['created_at'], (int) $end['id'] ) : null;
			unset( $rows );
		} while ( null !== $last && $got === $size && ( 0 === $limit || $count < $limit ) );
	}

	/**
	 * @return string[] Column names of an exported entry.
	 */
	public static function columns( bool $bodies ): array {
		$columns = array( 'id', 'date', 'date_utc', 'status', 'delivery', 'from', 'to', 'subject', 'source', 'content_type', 'attachments', 'error', 'notes', 'size' );
		return $bodies ? array_merge( $columns, array( 'headers', 'message' ) ) : $columns;
	}

	/**
	 * One exported entry from a raw row.
	 *
	 * @param array<string,string> $row
	 * @return array<string,string|int|string[]>
	 */
	public static function entry( array $row, bool $bodies ): array {
		$time  = (int) strtotime( $row['created_at'] . ' UTC' );
		$entry = array(
			'id'           => (int) $row['id'],
			'date'         => self::local_date( $row['created_at'] ),
			'date_utc'     => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
			'status'       => Repository::status_slug( (int) $row['status'] ),
			'delivery'     => Repository::delivery_slug( (int) ( $row['delivery'] ?? 0 ) ), // Reported by the email provider.
			'from'         => (string) ( $row['sender'] ?? '' ),
			'to'           => (string) $row['recipients'],
			'subject'      => (string) $row['subject'],
			'source'       => (string) $row['source'],
			'content_type' => (string) ( $row['content_type'] ?? '' ),
			'attachments'  => self::attachment_names( (string) $row['attachments'] ),
			'error'        => (string) $row['error'],
			'notes'        => (int) ( $row['notes'] ?? 0 ),
			'size'         => (int) ( $row['size'] ?? 0 ),
		);
		if ( $bodies ) {
			$entry['headers'] = (string) ( $row['headers'] ?? '' );
			$entry['message'] = (string) ( $row['message'] ?? '' );
		}
		return $entry;
	}

	/** GMT datetime from the database → "Y-m-d H:i:s" in the site's time zone. */
	public static function local_date( string $gmt ): string {
		return (string) wp_date( 'Y-m-d H:i:s', (int) strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * File names only – the stored server paths never leave the server.
	 *
	 * @return string[]
	 */
	public static function attachment_names( string $json ): array {
		$list  = '' === $json ? array() : json_decode( $json, true );
		$names = array();
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$names[] = sanitize_file_name( (string) $item['name'] );
			}
		}
		return $names;
	}

	/**
	 * One RFC 4180 line (CRLF), every text cell quoted and defused against formula injection.
	 *
	 * @param array<int|string,mixed> $cells
	 */
	public static function csv_line( array $cells ): string {
		return implode( ',', array_map( array( self::class, 'csv_cell' ), array_values( $cells ) ) ) . "\r\n";
	}

	/**
	 * @param mixed $value
	 */
	public static function csv_cell( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		// Spreadsheets evaluate cells starting with these as formulas (CSV injection).
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}
		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
