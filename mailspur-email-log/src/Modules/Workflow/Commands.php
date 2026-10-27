<?php
/**
 * Logic behind the WP-CLI commands (Cli), free of WP_CLI I/O so it can be tested directly.
 * Invalid input throws \InvalidArgumentException, failures \RuntimeException; values in their messages are escaped.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- statistics and batched purges on the plugin's own table, run on demand from WP-CLI.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use InvalidArgumentException;
use Mailspur\Import\Importer;
use Mailspur\Logger;
use Mailspur\Repository;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Commands {

	const LIST_FIELDS = array( 'id', 'date', 'status', 'to', 'subject', 'source' );
	const PURGE_BATCH = 5000;

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function repository(): Repository {
		return $this->repository;
	}

	/**
	 * List filters from CLI options: --status --search --since --until --source --content --attachments --notes.
	 *
	 * @param array<string,mixed> $assoc
	 * @return array<string,string|bool>
	 */
	public static function filters( array $assoc ): array {
		$status = (string) ( $assoc['status'] ?? 'all' );
		if ( 'all' !== $status && ! in_array( $status, Repository::STATUSES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown status "%s". Use one of: all, %s.', esc_html( $status ), esc_html( implode( ', ', Repository::STATUSES ) ) ) );
		}
		$content = (string) ( $assoc['content'] ?? '' );
		if ( '' !== $content && ! in_array( $content, Repository::FORMATS, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown content type "%s". Use html or text.', esc_html( $content ) ) );
		}
		return Filters::sanitize(
			array(
				'status'      => $status,
				'search'      => (string) ( $assoc['search'] ?? '' ),
				'in_body'     => ! empty( $assoc['in-body'] ),
				'after'       => isset( $assoc['since'] ) ? self::date( (string) $assoc['since'] ) : '',
				'before'      => isset( $assoc['until'] ) ? self::date( (string) $assoc['until'] ) : '',
				'source'      => (string) ( $assoc['source'] ?? '' ),
				'format'      => $content,
				'attachments' => ! empty( $assoc['attachments'] ),
				'notes'       => ! empty( $assoc['notes'] ),
			)
		);
	}

	/**
	 * "2026-01-31", "yesterday", "30 days ago" … → Y-m-d (site time).
	 */
	public static function date( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		$time = strtotime( $value );
		if ( false === $time || '' === $value ) {
			throw new InvalidArgumentException( sprintf( 'Invalid date "%s". Use YYYY-MM-DD or e.g. "30 days ago".', esc_html( $value ) ) );
		}
		return (string) wp_date( 'Y-m-d', $time );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,string|int>> Newest first.
	 */
	public function items( array $filters, int $limit ): array {
		$items    = array();
		$exporter = new Exporter( $this->repository );
		foreach ( $exporter->rows( $filters, false, max( 1, $limit ) ) as $row ) {
			$items[] = array(
				'id'      => (int) $row['id'],
				'date'    => Exporter::local_date( $row['created_at'] ),
				'status'  => Repository::status_slug( (int) $row['status'] ),
				'to'      => $row['recipients'],
				'subject' => $row['subject'],
				'source'  => $row['source'],
			);
		}
		return $items;
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	public function count( array $filters ): int {
		$status = (string) ( $filters['status'] ?? 'all' );
		return $this->repository->counts( $filters )[ in_array( $status, Repository::STATUSES, true ) ? $status : 'all' ];
	}

	/**
	 * @return array<string,string|int> One entry (with $body incl. headers and message).
	 */
	public function show( int $id, bool $body ): array {
		$row = $this->repository->find( $id );
		if ( ! $row ) {
			throw new RuntimeException( sprintf( 'Log entry %d not found.', (int) $id ) );
		}
		$entry = array();
		foreach ( Exporter::entry( $row, $body ) as $key => $value ) {
			$entry[ $key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}
		$entry['anonymised'] = Anonymiser::is_anonymised( $row ) ? 'yes' : 'no';
		return $entry;
	}

	/**
	 * Sends a logged mail again, to its original recipients or to $to.
	 *
	 * @param string $to Comma-separated addresses; '' = original recipients.
	 * @return array{sent:bool,to:string,missing:string[]}
	 */
	public function resend( int $id, string $to = '' ): array {
		$row = $this->repository->find( $id );
		if ( ! $row ) {
			throw new RuntimeException( sprintf( 'Log entry %d not found.', (int) $id ) );
		}
		if ( Anonymiser::is_anonymised( $row ) ) {
			throw new RuntimeException( sprintf( 'Log entry %d is anonymised; its content is gone.', (int) $id ) );
		}

		$recipients = $row['recipients'];
		if ( '' !== trim( $to ) ) {
			$list = array_map( 'trim', explode( ',', $to ) );
			foreach ( $list as $email ) {
				if ( ! is_email( $email ) ) {
					throw new InvalidArgumentException( sprintf( 'Invalid email address "%s".', esc_html( $email ) ) );
				}
			}
			$recipients = implode( ', ', $list );
		}

		$files       = array();
		$missing     = array();
		$attachments = json_decode( (string) $row['attachments'], true );
		// Invalid JSON = no attachments.
		foreach ( is_array( $attachments ) ? $attachments : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'], $item['path'] ) ) {
				continue;
			}
			$name = sanitize_file_name( (string) $item['name'] );
			if ( self::is_allowed_file( (string) $item['path'] ) ) {
				$files[ $name ] = (string) $item['path'];
			} else {
				$missing[] = $name;
			}
		}

		Logger::$source_override = 'mailspur:resend';
		try {
			$sent = wp_mail( $recipients, $row['subject'], $row['message'], array_filter( explode( "\n", (string) $row['headers'] ) ), $files );
		} finally {
			Logger::$source_override = '';
		}

		return array(
			'sent'    => (bool) $sent,
			'to'      => $recipients,
			'missing' => $missing,
		);
	}

	/** Same rule as the admin resend: only files inside the WordPress installation. */
	private static function is_allowed_file( string $path ): bool {
		$real = realpath( $path );
		if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
			$root = trailingslashit( wp_normalize_path( (string) realpath( $root ) ) );
			if ( '/' !== $root && 0 === strpos( $real, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Numbers for the last $days days.
	 *
	 * @return array{since:string,total:int,bytes:int,statuses:array<string,int>,failure_rate:float,sources:array<int,array{source:string,count:int,failed:int}>}
	 */
	public function stats( int $days ): array {
		global $wpdb;
		if ( $days < 1 ) {
			throw new InvalidArgumentException( '--days must be at least 1.' );
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table = Repository::table();

		$statuses = array_fill_keys( Repository::STATUSES, 0 );
		$total    = 0;
		$bytes    = 0;
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, SUM(size) AS bytes FROM %i WHERE created_at >= %s GROUP BY status', $table, $since ), ARRAY_A );
		foreach ( $rows as $row ) {
			$statuses[ Repository::status_slug( (int) $row['status'] ) ] += (int) $row['n'];
			$total += (int) $row['n'];
			$bytes += (int) $row['bytes'];
		}

		$sources = array();
		$rows    = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, COUNT(*) AS n, SUM(CASE WHEN status = %d THEN 1 ELSE 0 END) AS failed FROM %i WHERE created_at >= %s GROUP BY source ORDER BY n DESC LIMIT 10',
				Repository::STATUS_FAILED,
				$table,
				$since
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$sources[] = array(
				'source' => (string) $row['source'],
				'count'  => (int) $row['n'],
				'failed' => (int) $row['failed'],
			);
		}

		return array(
			'since'        => $since,
			'total'        => $total,
			'bytes'        => $bytes,
			'statuses'     => $statuses,
			'failure_rate' => $total ? round( 100 * $statuses['failed'] / $total, 1 ) : 0.0,
			'sources'      => $sources,
		);
	}

	/**
	 * Purge filters: --before=<date> (exclusive) and --status=<status>.
	 *
	 * @param array<string,mixed> $assoc
	 * @return array<string,string|bool>
	 */
	public static function purge_filters( array $assoc ): array {
		$filters = self::filters( array( 'status' => $assoc['status'] ?? 'all' ) );
		if ( isset( $assoc['before'] ) ) {
			// Inclusive "before" of the list filter = the day before the given date.
			$day               = self::date( (string) $assoc['before'] );
			$filters['before'] = gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
		}
		return $filters;
	}

	/**
	 * Deletes every matching entry in LIMITed batches.
	 *
	 * @param array<string,mixed> $filters
	 */
	public function purge( array $filters ): int {
		global $wpdb;
		$f       = $this->repository->filter( $filters );
		$deleted = 0;
		do {
			// The list filter condition documented at Repository::filter().
			$n        = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE ( 0 = %d OR recipients LIKE %s OR subject LIKE %s OR ( 1 = %d AND message LIKE %s ) ) AND ( 0 = %d OR created_at >= %s ) AND ( 0 = %d OR created_at <= %s ) AND ( 0 = %d OR source = %s ) AND ( 0 = %d OR content_type LIKE %s ) AND ( 0 = %d OR content_type NOT LIKE %s ) AND ( 0 = %d OR attachments <> '' ) AND ( 0 = %d OR notes > 0 ) AND ( 0 = %d OR delivery = %d ) AND ( 0 = %d OR status = %d ) LIMIT %d",
					Repository::table(),
					$f['search_on'],
					$f['search'],
					$f['search'],
					$f['in_body'],
					$f['search'],
					$f['after_on'],
					$f['after'],
					$f['before_on'],
					$f['before'],
					$f['source_on'],
					$f['source'],
					$f['html'],
					'%html%',
					$f['text'],
					'%html%',
					$f['attachments'],
					$f['notes'],
					$f['delivery_on'],
					$f['delivery'],
					$f['status_on'],
					$f['status'],
					self::PURGE_BATCH
				)
			);
			$deleted += $n;
		} while ( self::PURGE_BATCH === $n );
		delete_transient( Sources::TRANSIENT );
		return $deleted;
	}

	/**
	 * Imports one source (or every available one with 'all') completely.
	 *
	 * @param callable(string,array<string,int|bool>):void|null $progress Called after every batch.
	 * @return array<string,array{imported:int,skipped:int,duplicates:int}> Per source id.
	 */
	public function import( string $id, ?callable $progress = null ): array {
		$importer = new Importer( $this->repository );
		if ( 'all' === $id ) {
			$sources = array_filter(
				Importer::sources(),
				static function ( $source ): bool {
					return $source->available();
				}
			);
		} else {
			$source = Importer::source( $id );
			if ( ! $source ) {
				throw new InvalidArgumentException( sprintf( 'Unknown source "%s". Supported: %s.', esc_html( $id ), esc_html( implode( ', ', array_keys( Importer::sources() ) ) ) ) );
			}
			if ( ! $source->available() ) {
				throw new RuntimeException( sprintf( 'No log of %s was found on this site.', esc_html( $source->label() ) ) );
			}
			$sources = array( $id => $source );
		}

		$results = array();
		foreach ( $sources as $key => $source ) {
			$total = array(
				'imported'   => 0,
				'skipped'    => 0,
				'duplicates' => 0,
			);
			do {
				$batch = $importer->run( $source );
				if ( null === $batch ) {
					throw new RuntimeException( sprintf( 'An import of %s is already running.', esc_html( $source->label() ) ) );
				}
				$total['imported']   += $batch['imported'];
				$total['skipped']    += $batch['skipped'];
				$total['duplicates'] += $batch['duplicates'];
				if ( $progress ) {
					$progress( (string) $key, $batch );
				}
			} while ( ! $batch['done'] );
			$results[ (string) $key ] = $total;
		}
		delete_transient( Sources::TRANSIENT );
		return $results;
	}
}
