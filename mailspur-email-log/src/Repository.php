<?php
/**
 * All database access for the mail log table.
 *
 * Every value and identifier goes through $wpdb->prepare() (%i for the table
 * name and the whitelisted ORDER BY column, available since WordPress 6.2).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table; object caching would only serve stale data for a log that changes with every mail.
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Repository {

	const STATUS_PENDING = 0;
	const STATUS_SENT    = 1;
	const STATUS_FAILED  = 2;
	const STATUS_HELD    = 3; // Intercepted on purpose, e.g. staging mode: logged but not delivered.

	const STATUSES = array(
		self::STATUS_PENDING => 'pending',
		self::STATUS_SENT    => 'sent',
		self::STATUS_FAILED  => 'failed',
		self::STATUS_HELD    => 'held',
	);

	/**
	 * Delivery status reported by the email provider (column "delivery", see Modules\Delivery\Feedback),
	 * 0 = no report. A higher code wins when several reports arrive for one email.
	 */
	const DELIVERY = array(
		1 => 'soft_bounce',
		2 => 'delivered',
		3 => 'bounced',
		4 => 'complaint',
	);

	const ORDER_COLUMNS = array(
		'date'    => 'created_at', // Not the id: imported entries are old but get new ids.
		'to'      => 'recipients',
		'subject' => 'subject',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mailspur';
	}

	public static function status_slug( int $status ): string {
		return self::STATUSES[ $status ] ?? 'pending';
	}

	/** Slug of a delivery code, '' for none. */
	public static function delivery_slug( int $code ): string {
		return self::DELIVERY[ $code ] ?? '';
	}

	/** Delivery code of a provider event ("bounced" + not permanent = soft bounce), 0 for none. */
	public static function delivery_code( string $event, bool $hard = true ): int {
		if ( 'bounced' === $event && ! $hard ) {
			$event = 'soft_bounce';
		}
		$code = array_search( $event, self::DELIVERY, true );
		return false === $code ? 0 : (int) $code;
	}

	/**
	 * @param array<string,string|int> $row
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array<string,string|int> $data
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		if ( $id > 0 && $data ) {
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		}
	}

	/**
	 * @return array<string,string>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Content formats for the "format" filter (matched against content_type). */
	const FORMATS = array( 'html', 'text' );

	/**
	 * Values of a list filter, shared by the list, the export and WP-CLI.
	 *
	 * Every filtered query spells out the same fixed condition as a string literal, so the complete SQL and every
	 * value reach $wpdb->prepare() as written (tests/unit/RepositoryTest.php checks that every copy is identical):
	 *
	 *     ( 0 = %d OR recipients LIKE %s OR subject LIKE %s OR ( 1 = %d AND message LIKE %s ) )
	 *     AND ( 0 = %d OR created_at >= %s ) AND ( 0 = %d OR created_at <= %s ) AND ( 0 = %d OR source = %s )
	 *     AND ( 0 = %d OR content_type LIKE %s ) AND ( 0 = %d OR content_type NOT LIKE %s )
	 *     AND ( 0 = %d OR attachments <> '' ) AND ( 0 = %d OR notes > 0 ) AND ( 0 = %d OR delivery = %d )
	 *     AND ( 0 = %d OR status = %d )
	 *
	 * with the values search_on, search, search, in_body, search, after_on, after, before_on, before, source_on,
	 * source, html, '%html%', text, '%html%', attachments, notes, delivery_on, delivery, status_on, status.
	 * Each "( 0 = %d OR … )" is one filter with its on/off flag. The flags become constants in the prepared SQL,
	 * which MySQL folds away before choosing an index (source, delivery, status, created_at stay indexed).
	 *
	 * @param array<string,mixed> $args       search, in_body, after, before (Y-m-d, site time), source,
	 *                                        format (html|text), attachments, notes (bool), delivery (slug of
	 *                                        DELIVERY), status (slug).
	 * @param bool                $with_status Whether to apply the status filter (the list needs counts across all statuses first).
	 * @return array<string,string|int>
	 */
	public function filter( array $args, bool $with_status = true ): array {
		global $wpdb;

		$search = trim( (string) ( $args['search'] ?? '' ) );
		$after  = ! empty( $args['after'] ) ? get_gmt_from_date( (string) $args['after'] . ' 00:00:00' ) : '';
		$before = ! empty( $args['before'] ) ? get_gmt_from_date( (string) $args['before'] . ' 23:59:59' ) : '';
		$source = (string) ( $args['source'] ?? '' );
		// Unknown content types (pre_wp_mail mailers) count as plain text here.
		$format   = (string) ( $args['format'] ?? '' );
		$delivery = array_search( (string) ( $args['delivery'] ?? '' ), self::DELIVERY, true );
		$status   = $with_status ? array_search( (string) ( $args['status'] ?? 'all' ), self::STATUSES, true ) : false;

		return array(
			'search_on'   => (int) ( '' !== $search ),
			'search'      => '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '',
			'in_body'     => (int) ! empty( $args['in_body'] ),
			'after_on'    => (int) ( '' !== $after ),
			'after'       => $after,
			'before_on'   => (int) ( '' !== $before ),
			'before'      => $before,
			'source_on'   => (int) ( '' !== $source ),
			'source'      => $source,
			'html'        => (int) ( 'html' === $format ),
			'text'        => (int) ( 'text' === $format ),
			'attachments' => (int) ! empty( $args['attachments'] ),
			'notes'       => (int) ! empty( $args['notes'] ),
			'delivery_on' => (int) ( false !== $delivery ),
			'delivery'    => (int) $delivery,
			'status_on'   => (int) ( false !== $status ),
			'status'      => (int) $status,
		);
	}

	/**
	 * Number of entries per status (plus "all") for a list filter; its status is ignored.
	 *
	 * @param array<string,mixed> $args See filter().
	 * @return array<string,int>
	 */
	public function counts( array $args ): array {
		global $wpdb;

		$f    = $this->filter( $args, false );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM %i WHERE ( 0 = %d OR recipients LIKE %s OR subject LIKE %s OR ( 1 = %d AND message LIKE %s ) ) AND ( 0 = %d OR created_at >= %s ) AND ( 0 = %d OR created_at <= %s ) AND ( 0 = %d OR source = %s ) AND ( 0 = %d OR content_type LIKE %s ) AND ( 0 = %d OR content_type NOT LIKE %s ) AND ( 0 = %d OR attachments <> '' ) AND ( 0 = %d OR notes > 0 ) AND ( 0 = %d OR delivery = %d ) AND ( 0 = %d OR status = %d ) GROUP BY status",
				self::table(),
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
				$f['status']
			),
			ARRAY_A
		);

		$counts = array_fill_keys( array( 'all', 'sent', 'failed', 'pending', 'held' ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ self::status_slug( (int) $row['status'] ) ] += (int) $row['n'];
			$counts['all']                                       += (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Paginated, filtered list plus per-status counts for the same filter.
	 *
	 * @param array{page?:int,per_page?:int,search?:string,in_body?:bool,status?:string,orderby?:string,order?:string,after?:string,before?:string,source?:string,format?:string,attachments?:bool,notes?:bool,delivery?:string} $args
	 * @return array{items:array<int,array<string,string>>,total:int,counts:array<string,int>}
	 */
	public function query( array $args ): array {
		global $wpdb;

		// One grouped query yields the tab counts and the total for the active tab.
		$counts = $this->counts( $args );
		$status = (string) ( $args['status'] ?? 'all' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'all';
		}

		$total = $counts[ $status ];
		if ( 0 === $total ) {
			return array(
				'items'  => array(),
				'total'  => 0,
				'counts' => $counts,
			);
		}

		$f        = $this->filter( $args );
		$column   = self::ORDER_COLUMNS[ $args['orderby'] ?? 'date' ] ?? 'created_at';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 25 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		// "anonymised": flag set by the anonymisation (Modules\Workflow), which always writes that key first.
		$anonymised = $wpdb->esc_like( '{"anonymised":' ) . '%';

		// Secondary sort on id keeps pagination stable for equal recipients/subjects.
		if ( 'asc' === ( $args['order'] ?? 'desc' ) ) {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at, status, recipients, subject, attachments, source, error, notes, size, ( meta LIKE %s ) AS anonymised FROM %i WHERE ( 0 = %d OR recipients LIKE %s OR subject LIKE %s OR ( 1 = %d AND message LIKE %s ) ) AND ( 0 = %d OR created_at >= %s ) AND ( 0 = %d OR created_at <= %s ) AND ( 0 = %d OR source = %s ) AND ( 0 = %d OR content_type LIKE %s ) AND ( 0 = %d OR content_type NOT LIKE %s ) AND ( 0 = %d OR attachments <> '' ) AND ( 0 = %d OR notes > 0 ) AND ( 0 = %d OR delivery = %d ) AND ( 0 = %d OR status = %d ) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d",
					$anonymised,
					self::table(),
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
					$column,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at, status, recipients, subject, attachments, source, error, notes, size, ( meta LIKE %s ) AS anonymised FROM %i WHERE ( 0 = %d OR recipients LIKE %s OR subject LIKE %s OR ( 1 = %d AND message LIKE %s ) ) AND ( 0 = %d OR created_at >= %s ) AND ( 0 = %d OR created_at <= %s ) AND ( 0 = %d OR source = %s ) AND ( 0 = %d OR content_type LIKE %s ) AND ( 0 = %d OR content_type NOT LIKE %s ) AND ( 0 = %d OR attachments <> '' ) AND ( 0 = %d OR notes > 0 ) AND ( 0 = %d OR delivery = %d ) AND ( 0 = %d OR status = %d ) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d",
					$anonymised,
					self::table(),
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
					$column,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'items'  => (array) $items,
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/** Whether any entry has a delivery status from the email provider (one index lookup). */
	public function has_delivery(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE delivery > %d LIMIT 1', self::table(), 0 ) );
	}

	/**
	 * Fills the delivery column from meta.feedback (entries that got a provider status before the column existed).
	 *
	 * @return int Number of updated entries.
	 */
	public function backfill_delivery(): int {
		global $wpdb;
		$last    = 0;
		$updated = 0;
		do {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, meta FROM %i WHERE id > %d AND delivery = %d AND meta LIKE %s ORDER BY id LIMIT %d',
					self::table(),
					$last,
					0,
					'%' . $wpdb->esc_like( '"feedback":{' ) . '%' . $wpdb->esc_like( '"event":' ) . '%',
					500
				),
				ARRAY_A
			);
			$got  = count( $rows );
			foreach ( $rows as $row ) {
				$last     = (int) $row['id'];
				$meta     = json_decode( (string) $row['meta'], true );
				$feedback = is_array( $meta ) && isset( $meta['feedback'] ) && is_array( $meta['feedback'] ) ? $meta['feedback'] : array();
				$code     = self::delivery_code( (string) ( $feedback['event'] ?? '' ), ! empty( $feedback['hard'] ) );
				if ( $code ) {
					$this->update( $last, array( 'delivery' => $code ) );
					++$updated;
				}
			}
		} while ( 500 === $got );
		return $updated;
	}

	/**
	 * @param array<int,int|string> $ids
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( self::table() ), $ids ) ) );
	}

	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
	}

	/**
	 * Deletes every entry created before the given GMT datetime.
	 *
	 * By date, not by id: imported entries are old but get new ids.
	 *
	 * @param string[] $except_sources Sources left alone (they keep entries longer, see Cleanup).
	 */
	public function delete_before( string $gmt_datetime, array $except_sources = array() ): int {
		global $wpdb;
		$except = array_values( array_unique( array_map( 'strval', $except_sources ) ) );
		// "NOT IN ()" is invalid SQL: with nothing to keep, the condition is switched off by its flag.
		$keep = $except ? $except : array( '' );
		return $this->delete_in_batches(
			static function ( int $batch ) use ( $wpdb, $gmt_datetime, $except, $keep ): int {
				return (int) $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE created_at < %s AND ( 0 = %d OR source NOT IN (' . implode( ',', array_fill( 0, count( $keep ), '%s' ) ) . ') ) LIMIT %d',
						array_merge( array( self::table(), $gmt_datetime, (int) ( array() !== $except ) ), $keep, array( $batch ) )
					)
				);
			}
		);
	}

	/** Keeps only the newest $keep entries (by send date). */
	public function trim_to( int $keep ): int {
		global $wpdb;
		$edge = $wpdb->get_row(
			$wpdb->prepare( 'SELECT created_at, id FROM %i ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET %d', self::table(), $keep ),
			ARRAY_A
		);
		if ( ! is_array( $edge ) ) {
			return 0;
		}
		return $this->delete_in_batches(
			static function ( int $batch ) use ( $wpdb, $edge ): int {
				return (int) $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE created_at < %s OR ( created_at = %s AND id <= %d ) LIMIT %d',
						self::table(),
						$edge['created_at'],
						$edge['created_at'],
						(int) $edge['id'],
						$batch
					)
				);
			}
		);
	}

	/**
	 * Duplicate candidates for the importer: entries of OTHER sources sent within ±$window seconds
	 * whose recipients contain $email. Uses the created_at index; the LIKE only runs on that slice.
	 *
	 * @return array<int,array{recipients:string,subject:string}>
	 */
	public function near( string $gmt_datetime, int $window, string $exclude_source, string $email ): array {
		global $wpdb;
		$time = strtotime( $gmt_datetime . ' UTC' );
		if ( false === $time ) {
			return array();
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT recipients, subject FROM %i WHERE created_at BETWEEN %s AND %s AND source <> %s AND recipients LIKE %s LIMIT 200',
				self::table(),
				gmdate( 'Y-m-d H:i:s', $time - $window ),
				gmdate( 'Y-m-d H:i:s', $time + $window ),
				$exclude_source,
				'%' . $wpdb->esc_like( $email ) . '%'
			),
			ARRAY_A
		);
	}

	/** Deletes all entries imported from one source plugin (undo an import). */
	public function delete_by_source( string $source ): int {
		global $wpdb;
		return $this->delete_in_batches(
			static function ( int $batch ) use ( $wpdb, $source ): int {
				return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s LIMIT %d', self::table(), $source, $batch ) );
			}
		);
	}

	/**
	 * Inserts imported rows (used by the importer, which already runs one duplicate lookup per row).
	 *
	 * @param array<int,array<string,string|int>> $rows Rows in the format of Logger::normalize().
	 * @return int Number of inserted rows.
	 */
	public function insert_many( array $rows ): int {
		global $wpdb;
		$formats  = array(
			'created_at'   => '%s',
			'status'       => '%d',
			'recipients'   => '%s',
			'subject'      => '%s',
			'message'      => '%s',
			'headers'      => '%s',
			'attachments'  => '%s',
			'content_type' => '%s',
			'sender'       => '%s',
			'source'       => '%s',
			'error'        => '%s',
			'meta'         => '%s',
			'notes'        => '%d',
			'size'         => '%d',
			'raw'          => '%s',
		);
		$inserted = 0;
		foreach ( $rows as $row ) {
			$data = array();
			foreach ( array_keys( $formats ) as $column ) {
				$data[ $column ] = $row[ $column ] ?? '';
			}
			$inserted += (int) $wpdb->insert( self::table(), $data, array_values( $formats ) );
		}
		return $inserted;
	}

	/**
	 * Runs a LIMITed DELETE until nothing is left, keeping locks and binlog events small.
	 *
	 * @param callable(int):int $delete Deletes at most $batch rows, returns the number deleted.
	 */
	private function delete_in_batches( callable $delete, int $batch = 5000 ): int {
		$deleted = 0;
		do {
			$n        = $delete( $batch );
			$deleted += $n;
		} while ( $n === $batch );
		return $deleted;
	}

	/**
	 * Entries whose To list contains exactly this address (for privacy tools).
	 *
	 * @param int $scanned Receives the number of raw candidate rows (for pagination).
	 * @return array<int,array<string,string>>
	 */
	public function find_by_recipient( string $email, int $limit, int $offset = 0, int &$scanned = 0 ): array {
		global $wpdb;
		$scanned = 0;
		$email   = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$rows    = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, status, recipients, subject, attachments, source, error FROM %i WHERE recipients LIKE %s ORDER BY id LIMIT %d OFFSET %d',
				self::table(),
				'%' . $wpdb->esc_like( $email ) . '%',
				$limit,
				$offset
			),
			ARRAY_A
		);
		$scanned = count( $rows );
		// LIKE is only a pre-filter ("anna@x.de" also matches "joanna@x.de").
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $email ): bool {
					return in_array( $email, self::extract_emails( $row['recipients'] ), true );
				}
			)
		);
	}

	/**
	 * @return string[] Lower-cased addresses from a "Name <a@b>, c@d" list.
	 */
	public static function extract_emails( string $recipients ): array {
		preg_match_all( '/[^\s<>,;"\']+@[^\s<>,;"\']+/', $recipients, $m );
		return array_map( 'strtolower', $m[0] );
	}

	/**
	 * @return array{rows:int,bytes:int}
	 */
	public function stats(): array {
		global $wpdb;
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( self::table() ) ), ARRAY_A );
		return array(
			'rows'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ),
			'bytes' => is_array( $status ) ? (int) $status['Data_length'] + (int) $status['Index_length'] : 0,
		);
	}
}
