<?php
/**
 * The two tables behind the email types: one row per type and one counter row per type and day.
 *
 * Only counters, subject patterns and a small state per type (log ids, body fingerprints, cron hook – see
 * Indexer) are stored – no recipients, no contents. Day rows follow the log
 * retention (at most a year), and a type disappears with its last day row.
 *
 * Direct queries: the module's own tables.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Store {

	const DB_VERSION = 2;
	const DB_OPTION  = 'mailspur_types_db';

	public static function types_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mailspur_types';
	}

	public static function days_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mailspur_type_days';
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$types   = self::types_table();
		$days    = self::days_table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$types} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source varchar(100) NOT NULL DEFAULT '',
pattern varchar(500) NOT NULL DEFAULT '',
first_seen datetime NOT NULL,
last_seen datetime NOT NULL,
last_status tinyint(1) unsigned NOT NULL DEFAULT 0,
last_notes smallint(5) unsigned NOT NULL DEFAULT 0,
muted tinyint(1) unsigned NOT NULL DEFAULT 0,
extra text NULL,
PRIMARY KEY  (id),
KEY source (source)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$days} (
type_id bigint(20) unsigned NOT NULL,
day date NOT NULL,
total int(10) unsigned NOT NULL DEFAULT 0,
failed int(10) unsigned NOT NULL DEFAULT 0,
held int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (type_id,day),
KEY day (day)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * All types.
	 *
	 * @return array<int,array{id:int,source:string,pattern:string[],first_seen:string,last_seen:string,last_status:int,last_notes:int,muted:bool,extra:array<string,mixed>}> By id.
	 */
	public function types(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', self::types_table() ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$id         = (int) $row['id'];
			$out[ $id ] = array(
				'id'          => $id,
				'source'      => (string) $row['source'],
				'pattern'     => self::decode( (string) $row['pattern'] ),
				'first_seen'  => (string) $row['first_seen'],
				'last_seen'   => (string) $row['last_seen'],
				'last_status' => (int) $row['last_status'],
				'last_notes'  => (int) $row['last_notes'],
				'muted'       => (bool) $row['muted'],
				'extra'       => self::decode_extra( (string) ( $row['extra'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * @param string[] $pattern
	 */
	public function create( string $source, array $pattern, string $seen ): int {
		global $wpdb;
		$wpdb->insert(
			self::types_table(),
			array(
				'source'     => substr( $source, 0, 100 ),
				'pattern'    => self::encode( $pattern ),
				'first_seen' => $seen,
				'last_seen'  => $seen,
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $type Type as returned by types() (pattern as word list).
	 */
	public function save( array $type ): void {
		global $wpdb;
		$wpdb->update(
			self::types_table(),
			array(
				'pattern'     => self::encode( (array) $type['pattern'] ),
				'first_seen'  => (string) $type['first_seen'],
				'last_seen'   => (string) $type['last_seen'],
				'last_status' => (int) $type['last_status'],
				'last_notes'  => min( 65535, (int) $type['last_notes'] ),
				'extra'       => (string) wp_json_encode( (array) ( $type['extra'] ?? array() ) ),
			),
			array( 'id' => (int) $type['id'] ),
			array( '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	public function mute( int $id, bool $muted ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::types_table(), array( 'muted' => $muted ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Adds counters. Portable (MySQL, MariaDB, SQLite): look up existing rows, then update or insert.
	 *
	 * @param array<int,array<string,array{total:int,failed:int,held:int}>> $counts By type id and day.
	 */
	public function add_days( array $counts ): void {
		global $wpdb;
		$ids      = array_map( 'intval', array_keys( $counts ) );
		$all_days = array();
		foreach ( $counts as $days ) {
			$all_days = array_merge( $all_days, array_map( 'strval', array_keys( $days ) ) );
		}
		if ( ! $ids || ! $all_days ) {
			return;
		}
		$existing = array();
		$rows     = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type_id, day FROM %i WHERE type_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND day BETWEEN %s AND %s',
				array_merge( array( self::days_table() ), $ids, array( min( $all_days ), max( $all_days ) ) )
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$existing[ (int) $row['type_id'] . '|' . $row['day'] ] = true;
		}

		foreach ( $counts as $id => $days ) {
			foreach ( $days as $day => $count ) {
				if ( isset( $existing[ $id . '|' . $day ] ) ) {
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET total = total + %d, failed = failed + %d, held = held + %d WHERE type_id = %d AND day = %s',
							self::days_table(),
							$count['total'],
							$count['failed'],
							$count['held'],
							$id,
							$day
						)
					);
				} else {
					$wpdb->insert(
						self::days_table(),
						array(
							'type_id' => $id,
							'day'     => $day,
							'total'   => $count['total'],
							'failed'  => $count['failed'],
							'held'    => $count['held'],
						),
						array( '%d', '%s', '%d', '%d', '%d' )
					);
				}
			}
		}
	}

	/**
	 * Day counters since a date, of all types or one.
	 *
	 * @return array<int,array<string,array{total:int,failed:int,held:int}>> By type id and day.
	 */
	public function days_since( string $day, int $type = 0 ): array {
		global $wpdb;
		if ( $type ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT type_id, day, total, failed, held FROM %i WHERE type_id = %d AND day >= %s', self::days_table(), $type, $day ),
				ARRAY_A
			);
		} else {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT type_id, day, total, failed, held FROM %i WHERE day >= %s', self::days_table(), $day ),
				ARRAY_A
			);
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['type_id'] ][ substr( (string) $row['day'], 0, 10 ) ] = array(
				'total'  => (int) $row['total'],
				'failed' => (int) $row['failed'],
				'held'   => (int) $row['held'],
			);
		}
		return $out;
	}

	/** Drops day rows before a date and every type without day rows left. */
	public function prune( string $day ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::days_table(), $day ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id NOT IN ( SELECT DISTINCT type_id FROM %i )', self::types_table(), self::days_table() ) );
	}

	public function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::days_table() ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::types_table() ) );
	}

	/**
	 * @param string[] $pattern
	 */
	public static function encode( array $pattern ): string {
		$text = implode( ' ', $pattern );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500, 'UTF-8' ) : substr( $text, 0, 500 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function decode_extra( string $json ): array {
		$extra = '' === $json ? array() : json_decode( $json, true );
		return is_array( $extra ) ? $extra : array();
	}

	/**
	 * @return string[]
	 */
	public static function decode( string $pattern ): array {
		$words = preg_split( '/ /', $pattern, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? $words : array();
	}
}
