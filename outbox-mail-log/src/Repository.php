<?php
/**
 * All database access for the mail log table.
 *
 * Table name is built from $wpdb->prefix and never from user input; every
 * value goes through $wpdb->prepare(), every ORDER BY through a whitelist.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
 *
 * @package Outbox
 */

namespace Outbox;

defined( 'ABSPATH' ) || exit;

final class Repository {

	const STATUS_PENDING = 0;
	const STATUS_SENT    = 1;
	const STATUS_FAILED  = 2;

	const STATUSES = array(
		self::STATUS_PENDING => 'pending',
		self::STATUS_SENT    => 'sent',
		self::STATUS_FAILED  => 'failed',
	);

	/** Columns needed for the list view (never the heavy message body). */
	const LIST_COLUMNS = 'id, created_at, status, recipients, subject, attachments, source, error';

	const ORDER_COLUMNS = array(
		'date'    => 'id', // Auto-increment id follows insertion time and uses the primary key.
		'to'      => 'recipients',
		'subject' => 'subject',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'outbox_mails';
	}

	public static function status_slug( int $status ): string {
		return self::STATUSES[ $status ] ?? 'pending';
	}

	public function insert( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function update( int $id, array $data ): void {
		global $wpdb;
		if ( $id > 0 && $data ) {
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		}
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Paginated, filtered list plus per-status counts for the same filter.
	 *
	 * @param array{page:int,per_page:int,search:string,in_body:bool,status:string,orderby:string,order:string,after:string,before:string} $args
	 * @return array{items:array,total:int,counts:array<string,int>}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$table  = self::table();
		$where  = array();
		$params = array();

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
		$sql  = "SELECT status, COUNT(*) AS n FROM {$table}" . self::where( $where ) . ' GROUP BY status';
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );

		$counts = array_fill_keys( array( 'all', 'sent', 'failed', 'pending' ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ self::status_slug( (int) $row['status'] ) ] += (int) $row['n'];
			$counts['all']                                    += (int) $row['n'];
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

		$column = self::ORDER_COLUMNS[ $args['orderby'] ?? 'date' ] ?? 'id';
		$order  = 'asc' === ( $args['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$sort   = 'id' === $column ? "id {$order}" : "{$column} {$order}, id DESC";

		$per_page = max( 1, (int) $args['per_page'] );
		$params[] = $per_page;
		$params[] = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . self::LIST_COLUMNS . " FROM {$table}" . self::where( $where ) . " ORDER BY {$sort} LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return array(
			'items'  => (array) $items,
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * @param int[] $ids
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", $ids ) );
	}

	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
	}

	/** Deletes every entry created before the given GMT datetime. */
	public function delete_before( string $gmt_datetime ): int {
		global $wpdb;
		$max_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE created_at < %s ORDER BY id DESC LIMIT 1', $gmt_datetime )
		);
		return $max_id ? $this->delete_up_to( $max_id ) : 0;
	}

	/** Keeps only the newest $keep entries. */
	public function trim_to( int $keep ): int {
		global $wpdb;
		$max_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1 OFFSET %d', $keep )
		);
		return $max_id ? $this->delete_up_to( $max_id ) : 0;
	}

	/** Deletes in primary-key batches to keep locks and binlog events small. */
	private function delete_up_to( int $max_id, int $batch = 5000 ): int {
		global $wpdb;
		$deleted = 0;
		do {
			$n        = (int) $wpdb->query(
				$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE id <= %d ORDER BY id LIMIT %d', $max_id, $batch )
			);
			$deleted += $n;
		} while ( $n === $batch );
		return $deleted;
	}

	/**
	 * Entries whose To list contains exactly this address (for privacy tools).
	 *
	 * @param int|null $scanned Receives the number of raw candidate rows (for pagination).
	 * @return array<int,array>
	 */
	public function find_by_recipient( string $email, int $limit, int $offset = 0, ?int &$scanned = null ): array {
		global $wpdb;
		$scanned = 0;
		$email   = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . self::LIST_COLUMNS . ' FROM ' . self::table() . ' WHERE recipients LIKE %s ORDER BY id LIMIT %d OFFSET %d',
				'%' . $wpdb->esc_like( $email ) . '%',
				$limit,
				$offset
			),
			ARRAY_A
		);
		$scanned = count( (array) $rows );
		// LIKE is only a pre-filter ("anna@x.de" also matches "joanna@x.de").
		return array_values(
			array_filter(
				(array) $rows,
				static function ( array $row ) use ( $email ): bool {
					return in_array( $email, self::extract_emails( $row['recipients'] ), true );
				}
			)
		);
	}

	/**
	 * @return string[] Lower-cased addresses from a "Name <a@b>, c@d" list.
	 */
	public static function extract_emails( string $list ): array {
		preg_match_all( '/[^\s<>,;"\']+@[^\s<>,;"\']+/', $list, $m );
		return array_map( 'strtolower', $m[0] );
	}

	/**
	 * @return array{rows:int,bytes:int}
	 */
	public function stats(): array {
		global $wpdb;
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', self::table() ), ARRAY_A );
		return array(
			'rows'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ),
			'bytes' => $status ? (int) $status['Data_length'] + (int) $status['Index_length'] : 0,
		);
	}

	private static function where( array $conditions ): string {
		return $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
	}
}
