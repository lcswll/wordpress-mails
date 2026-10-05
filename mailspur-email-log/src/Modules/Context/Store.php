<?php
/**
 * Lookup of log entries by the WordPress object they belong to.
 *
 * The relation itself lives in the mail's meta ("context"); this small table only mirrors it so an order or a
 * user can find its mails through an index instead of scanning the meta JSON of the whole log. One row per mail
 * and object type, written once after the mail was logged (only for mails that have a context).
 *
 * Rows are matched to the log by id AND send date, so ids reused after the log was emptied never point to a
 * different mail; orphans are removed with the daily cleanup.
 *
 * Direct queries: the module's own table and the plugin's log table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Context;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Store {

	const DB_VERSION = 1;
	const DB_OPTION  = 'mailspur_context_db';

	/** Object types stored in the table. */
	const TYPES = array( 'order', 'user' );

	/** JSON prefix of anonymised entries (see Modules\Workflow\Anonymiser): their relation was removed with the content. */
	const ANONYMISED = '{"anonymised":';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mailspur_context';
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
mail_id bigint(20) unsigned NOT NULL,
created_at datetime NOT NULL,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (mail_id,object_type),
KEY object (object_type,object_id,created_at)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Records the relations of one logged mail (one multi-row INSERT).
	 *
	 * @param array<string,mixed> $context Meta "context" of the mail, e.g. array( 'order' => 12, 'user' => 3 ).
	 */
	public function insert( int $mail_id, string $created_at, array $context ): int {
		global $wpdb;
		$values = array( self::table() );
		$tuples = array();
		foreach ( self::TYPES as $type ) {
			$object_id = isset( $context[ $type ] ) && is_numeric( $context[ $type ] ) ? (int) $context[ $type ] : 0;
			if ( $object_id > 0 ) {
				$tuples[] = '(%d,%s,%s,%d)';
				array_push( $values, $mail_id, $created_at, $type, $object_id );
			}
		}
		if ( $mail_id <= 0 || ! $tuples ) {
			return 0;
		}
		$sql = 'INSERT INTO %i (mail_id,created_at,object_type,object_id) VALUES ' . implode( ',', $tuples );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from literal placeholders.
		return (int) $wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Newest mails linked to one object (index on object_type, object_id, created_at).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function for_object( string $type, int $object_id, int $limit ): array {
		global $wpdb;
		if ( $object_id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
			return array();
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.created_at, l.status, l.recipients, l.subject, l.notes FROM %i c INNER JOIN %i l ON l.id = c.mail_id AND l.created_at = c.created_at'
				. ' WHERE c.object_type = %s AND c.object_id = %d AND l.meta NOT LIKE %s ORDER BY c.created_at DESC, c.mail_id DESC LIMIT %d',
				self::table(),
				Repository::table(),
				$type,
				$object_id,
				$wpdb->esc_like( self::ANONYMISED ) . '%',
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Mails to an address within a time window that are not linked to any order – e.g. logged before this
	 * module existed or imported from another plugin. Uses the created_at index; the LIKE runs on that slice only.
	 *
	 * @return array<int,array<string,string>> Exact recipient matches only.
	 */
	public function unlinked_near( string $email, string $from_gmt, string $to_gmt, int $limit ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.created_at, l.status, l.recipients, l.subject, l.notes FROM %i l LEFT JOIN %i c ON c.mail_id = l.id AND c.created_at = l.created_at AND c.object_type = %s'
				. ' WHERE l.created_at BETWEEN %s AND %s AND l.recipients LIKE %s AND c.mail_id IS NULL AND l.meta NOT LIKE %s'
				. ' ORDER BY l.created_at DESC, l.id DESC LIMIT %d',
				Repository::table(),
				self::table(),
				'order',
				$from_gmt,
				$to_gmt,
				'%' . $wpdb->esc_like( $email ) . '%',
				$wpdb->esc_like( self::ANONYMISED ) . '%',
				$limit * 3
			),
			ARRAY_A
		);
		return array_slice( self::exact( $rows, $email ), 0, $limit );
	}

	/**
	 * Newest mails to an address. Same cost as searching the address in the log: MySQL walks the created_at
	 * index backwards and stops as soon as enough rows matched.
	 *
	 * @return array<int,array<string,string>> Exact recipient matches only.
	 */
	public function to_recipient( string $email, int $limit ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.created_at, l.status, l.recipients, l.subject, l.notes FROM %i l WHERE l.recipients LIKE %s AND l.meta NOT LIKE %s ORDER BY l.created_at DESC, l.id DESC LIMIT %d',
				Repository::table(),
				'%' . $wpdb->esc_like( $email ) . '%',
				$wpdb->esc_like( self::ANONYMISED ) . '%',
				$limit * 3
			),
			ARRAY_A
		);
		return array_slice( self::exact( $rows, $email ), 0, $limit );
	}

	/** Removes relations whose mail no longer exists (deleted, retention, emptied log). */
	public function prune(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE NOT EXISTS ( SELECT 1 FROM %i l WHERE l.id = %i.mail_id AND l.created_at = %i.created_at )',
				self::table(),
				Repository::table(),
				self::table(),
				self::table()
			)
		);
	}

	/**
	 * Merges lists by id, newest first.
	 *
	 * @param array<int,array<string,string>> ...$lists
	 * @return array<int,array<string,string>>
	 */
	public static function merge( int $limit, array ...$lists ): array {
		$out = array();
		foreach ( $lists as $list ) {
			foreach ( $list as $row ) {
				$out[ (int) $row['id'] ] = $row;
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return array( $b['created_at'], (int) $b['id'] ) <=> array( $a['created_at'], (int) $a['id'] );
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/**
	 * LIKE is only a pre-filter ("anna@x.de" also matches "joanna@x.de").
	 *
	 * @param array<int,array<string,string>> $rows
	 * @return array<int,array<string,string>>
	 */
	private static function exact( array $rows, string $email ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $email ): bool {
					return in_array( $email, Repository::extract_emails( (string) ( $row['recipients'] ?? '' ) ), true );
				}
			)
		);
	}
}
