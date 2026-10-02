<?php
/**
 * All database access for the mail log table.
 *
 * Every value and identifier goes through $wpdb->prepare() (%i for the table
 * name and the whitelisted ORDER BY column, available since WordPress 6.2).
 *
 * Direct queries are intended: this is the plugin's own table, and object
 * caching would only serve stale data for a log that changes with every mail.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
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

	/**
	 * Paginated, filtered list plus per-status counts for the same filter.
	 *
	 * @param array{page?:int,per_page?:int,search?:string,in_body?:bool,status?:string,orderby?:string,order?:string,after?:string,before?:string} $args
	 * @return array{items:array<int,array<string,string>>,total:int,counts:array<string,int>}
	 */
	public function query( array $args ): array {
		global $wpdb;

		// WHERE fragments are string literals only; every value is a placeholder.
		$where  = array( '1=1' );
		$params = array( self::table() );

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$fields   = array( 'recipients LIKE %s', 'subject LIKE %s' );
			$params[] = $like;
			$params[] = $like;
			if ( ! empty( $args['in_body'] ) ) {
				$fields[] = 'message LIKE %s';
				$params[] = $like;
			}
			$where[] = '(' . implode( ' OR ', $fields ) . ')';
		}

		if ( ! empty( $args['after'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = get_gmt_from_date( $args['after'] . ' 00:00:00' );
		}
		if ( ! empty( $args['before'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = get_gmt_from_date( $args['before'] . ' 23:59:59' );
		}

		// One grouped query yields the tab counts and the total for the active tab.
		$sql  = 'SELECT status, COUNT(*) AS n FROM %i WHERE ' . implode( ' AND ', $where ) . ' GROUP BY status';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from literals above.

		$counts = array_fill_keys( array( 'all', 'sent', 'failed', 'pending', 'held' ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ self::status_slug( (int) $row['status'] ) ] += (int) $row['n'];
			$counts['all']                                       += (int) $row['n'];
		}

		$status = (string) ( $args['status'] ?? 'all' );
		$code   = array_search( $status, self::STATUSES, true );
		if ( false !== $code ) {
			$where[]  = 'status = %d';
			$params[] = $code;
		} else {
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

		$per_page = max( 1, (int) ( $args['per_page'] ?? 25 ) );
		$column   = self::ORDER_COLUMNS[ $args['orderby'] ?? 'date' ] ?? 'created_at';
		$order    = 'asc' === ( $args['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$params[] = $column;
		$params[] = $per_page;
		$params[] = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;

		// Secondary sort on id keeps pagination stable for equal recipients/subjects.
		$sql   = 'SELECT id, created_at, status, recipients, subject, attachments, source, error, notes, size FROM %i WHERE '
			. implode( ' AND ', $where ) . " ORDER BY %i {$order}, id {$order} LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from literals above, $order is ASC|DESC.

		return array(
			'items'  => (array) $items,
			'total'  => $total,
			'counts' => $counts,
		);
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
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %d per id.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", self::table(), ...$ids ) );
	}

	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
	}

	/**
	 * Deletes every entry created before the given GMT datetime.
	 *
	 * By date, not by id: imported entries are old but get new ids.
	 */
	public function delete_before( string $gmt_datetime ): int {
		global $wpdb;
		return $this->delete_in_batches(
			static function ( int $batch ) use ( $wpdb, $gmt_datetime ): int {
				return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT %d', self::table(), $gmt_datetime, $batch ) );
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
	 * Inserts many rows with one multi-row INSERT per call (used by the importer).
	 *
	 * @param array<int,array<string,string|int>> $rows Rows in the format of Logger::normalize().
	 */
	public function insert_many( array $rows ): int {
		global $wpdb;
		if ( ! $rows ) {
			return 0;
		}
		$columns = array(
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
		$tuple   = '(' . implode( ',', $columns ) . ')';
		$values  = array( self::table() );
		foreach ( $rows as $row ) {
			foreach ( array_keys( $columns ) as $column ) {
				$values[] = $row[ $column ] ?? '';
			}
		}
		$sql = 'INSERT INTO %i (' . implode( ',', array_keys( $columns ) ) . ') VALUES ' . implode( ',', array_fill( 0, count( $rows ), $tuple ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from literal column names and placeholders.
		return (int) $wpdb->query( $wpdb->prepare( $sql, $values ) );
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
