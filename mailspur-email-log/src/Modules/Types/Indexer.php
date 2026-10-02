<?php
/**
 * Sorts logged emails into email types – incrementally and never while an email is being sent.
 *
 * Reads the log after a cursor (log id) in small keyset batches: only id, date, status, source, subject and
 * the notes count. Runs hourly via WP-Cron and briefly when the "Email types" tab is opened, so sending an
 * email costs nothing extra. Imported emails are picked up the same way.
 *
 * Direct queries: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Modules\Insights\Stats;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Indexer {

	const CURSOR = 'mailspur_types_cursor';
	const LOCK   = 'mailspur_types_lock';
	const BATCH  = 500;

	/** New types per sender; beyond that, further subjects of that sender share one catch-all type. */
	const MAX_PER_SOURCE = 150;

	/** An email still marked "unknown" this soon after logging may be in flight – wait for its result. */
	const SETTLE = 600;

	/** @var Store */
	private $store;

	/** @var callable():int */
	private $now;

	/** @var array<int,array<string,mixed>> Types by id. */
	private $types = array();

	/** @var bool */
	private $loaded = false;

	/** @var array<string,int[]> Type ids by source. */
	private $by_source = array();

	public function __construct( Store $store, ?callable $now = null ) {
		$this->store = $store;
		$this->now   = $now ?? 'time';
	}

	/** Cron callback. */
	public function cron(): void {
		$this->run( 20000 );
	}

	/**
	 * Indexes up to $limit new log entries.
	 *
	 * @return int Number of entries read.
	 */
	public function run( int $limit ): int {
		$now = (int) call_user_func( $this->now );
		if ( ! add_option( self::LOCK, $now + 300, '', false ) ) {
			if ( (int) get_option( self::LOCK ) > $now ) {
				return 0;
			}
			update_option( self::LOCK, $now + 300, false );
		}

		try {
			return $this->index( $limit, $now );
		} finally {
			delete_option( self::LOCK );
		}
	}

	private function index( int $limit, int $now ): int {
		global $wpdb;
		$table  = Repository::table();
		$cursor = (int) get_option( self::CURSOR, 0 );

		// The log was emptied (or rebuilt with new ids): start over.
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );
		if ( $max < $cursor ) {
			$this->store->clear();
			$cursor       = 0;
			$this->loaded = false;
			update_option( self::CURSOR, 0, false );
		}
		if ( $max === $cursor ) {
			return 0;
		}

		$this->load();
		$settle = gmdate( 'Y-m-d H:i:s', $now - self::SETTLE );
		$read   = 0;

		while ( $read < $limit ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, created_at, status, source, subject, notes FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
					$table,
					$cursor,
					min( self::BATCH, $limit - $read )
				),
				ARRAY_A
			);
			if ( ! $rows ) {
				break;
			}

			$counts  = array();
			$touched = array();
			$stop    = false;
			foreach ( $rows as $row ) {
				$status = (int) $row['status'];
				if ( Repository::STATUS_PENDING === $status && (string) $row['created_at'] > $settle ) {
					$stop = true;
					break;
				}
				$cursor = (int) $row['id'];
				++$read;

				$source = (string) $row['source'];
				if ( self::ignored( $source ) ) {
					continue;
				}
				$id = $this->classify( $source, (string) $row['subject'], (string) $row['created_at'] );
				if ( ! $id ) {
					continue;
				}
				$time = (int) strtotime( $row['created_at'] . ' UTC' );
				$day  = (string) wp_date( 'Y-m-d', $time );
				if ( ! isset( $counts[ $id ][ $day ] ) ) {
					$counts[ $id ][ $day ] = array(
						'total'  => 0,
						'failed' => 0,
						'held'   => 0,
					);
				}
				++$counts[ $id ][ $day ]['total'];
				if ( Repository::STATUS_FAILED === $status ) {
					++$counts[ $id ][ $day ]['failed'];
				} elseif ( Repository::STATUS_HELD === $status ) {
					++$counts[ $id ][ $day ]['held'];
				}

				$type = &$this->types[ $id ];
				if ( (string) $row['created_at'] >= $type['last_seen'] ) {
					$type['last_seen']   = (string) $row['created_at'];
					$type['last_status'] = $status;
					$type['last_notes']  = (int) $row['notes'];
				}
				if ( (string) $row['created_at'] < $type['first_seen'] ) {
					$type['first_seen'] = (string) $row['created_at'];
				}
				unset( $type );
				$touched[ $id ] = true;
			}

			$this->store->add_days( $counts );
			foreach ( array_keys( $touched ) as $id ) {
				$this->store->save( (array) $this->types[ $id ] );
			}
			update_option( self::CURSOR, $cursor, false );

			if ( $stop || count( $rows ) < self::BATCH ) {
				break;
			}
		}
		return $read;
	}

	/** Resends and Mailspur's own alert emails are copies or meta mails, not types of the site. */
	public static function ignored( string $source ): bool {
		return 0 === strpos( $source, 'mailspur:' ) || ( class_exists( Stats::class ) && Stats::ALERT_SOURCE === $source );
	}

	/** Type id for a subject – an existing (possibly widened) type of the sender or a new one. */
	public function classify( string $source, string $subject, string $seen ): int {
		$this->load();
		$words = Fingerprint::tokens( $subject );
		$ids   = $this->by_source[ $source ] ?? array();

		// Exact matches first, so a subject never widens a type when another one fits as it is.
		foreach ( array( false, true ) as $widen ) {
			foreach ( $ids as $id ) {
				$pattern = $this->types[ $id ]['pattern'];
				if ( array( Fingerprint::OTHER ) === $pattern ) {
					continue;
				}
				$merged = Fingerprint::merge( $pattern, $words );
				if ( null === $merged || ( ! $widen && $merged !== $pattern ) ) {
					continue;
				}
				$this->types[ $id ]['pattern'] = $merged;
				return $id;
			}
		}

		if ( count( $ids ) >= self::MAX_PER_SOURCE ) {
			foreach ( $ids as $id ) {
				if ( array( Fingerprint::OTHER ) === $this->types[ $id ]['pattern'] ) {
					return $id;
				}
			}
			$words = array( Fingerprint::OTHER );
		}

		$id = $this->store->create( $source, $words, $seen );
		if ( $id ) {
			$this->types[ $id ]           = array(
				'id'          => $id,
				'source'      => $source,
				'pattern'     => $words,
				'first_seen'  => $seen,
				'last_seen'   => $seen,
				'last_status' => 0,
				'last_notes'  => 0,
				'muted'       => false,
			);
			$this->by_source[ $source ][] = $id;
		}
		return $id;
	}

	/**
	 * Type of a single (logged) email without changing anything, for the detail view.
	 *
	 * @param array<int,array<string,mixed>> $types Types of the email's sender.
	 */
	public static function find( array $types, string $subject ): ?int {
		$words = Fingerprint::tokens( $subject );
		foreach ( $types as $type ) {
			$pattern = (array) $type['pattern'];
			if ( array( Fingerprint::OTHER ) !== $pattern && Fingerprint::merge( $pattern, $words ) === $pattern ) {
				return (int) $type['id'];
			}
		}
		return null;
	}

	/** Starts over: clears the types and re-reads the whole log on the next runs. */
	public function rebuild(): void {
		$this->store->clear();
		update_option( self::CURSOR, 0, false );
		$this->loaded = false;
	}

	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded    = true;
		$this->types     = $this->store->types();
		$this->by_source = array();
		foreach ( $this->types as $id => $type ) {
			$this->by_source[ $type['source'] ][] = $id;
		}
	}
}
