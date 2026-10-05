<?php
/**
 * Sorts logged emails into email types – incrementally and never while an email is being sent.
 *
 * Reads the log after a cursor (log id) in small keyset batches: id, date, status, source, subject and the notes
 * count, then – in smaller chunks – the start of each body (content fingerprint, see Content), the recipients
 * (only compared with the administrators' addresses, see Noise) and the meta (was the email sent by WP-Cron,
 * from which hook, how long it took – see Speed). New senders are remembered (Senders). Runs hourly via WP-Cron and briefly when the "Email types" tab is
 * opened, so sending an email costs nothing extra. Imported emails are picked up the same way.
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

	/** Rows whose body and meta are read in one query. */
	const BODY_BATCH = 100;

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

	/** @var string[]|null Administrators' addresses (lower-cased), looked up once per run. */
	private $admins;

	/**
	 * @param (callable():int)|null $now    Clock.
	 * @param string[]|null         $admins Administrators' addresses (default: Noise::addresses()).
	 */
	public function __construct( Store $store, ?callable $now = null, ?array $admins = null ) {
		$this->store  = $store;
		$this->now    = $now ?? 'time';
		$this->admins = $admins;
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
		$settle  = gmdate( 'Y-m-d H:i:s', $now - self::SETTLE );
		$read    = 0;
		$senders = Senders::state();
		$before  = $senders;
		if ( $senders['since'] <= 0 ) {
			// First run: senders already in the types (e.g. before an update) are known, not new.
			foreach ( $this->types as $type ) {
				$senders = Senders::observe( $senders, (string) $type['source'], (int) strtotime( $type['first_seen'] . ' UTC' ), $now );
			}
		}

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
			$mails   = array();
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
				$touched[ $id ]            = true;
				$mails[ (int) $row['id'] ] = array( $id, $time, $day );
				$senders                   = Senders::observe( $senders, $source, $time, $now );
			}

			$this->contents( $mails );
			$this->store->add_days( $counts );
			foreach ( array_keys( $touched ) as $id ) {
				$this->store->save( (array) $this->types[ $id ] );
			}
			update_option( self::CURSOR, $cursor, false );

			if ( $stop || count( $rows ) < self::BATCH ) {
				break;
			}
		}
		if ( $senders !== $before ) {
			Senders::save( $senders );
		}
		return $read;
	}

	/**
	 * Content fingerprints, cron origin, emails to administrators, waiting times and latest log id per type, in
	 * log order.
	 *
	 * @param array<int,array{0:int,1:int,2:string}> $mails Type id, unix time and site-local day by log id.
	 */
	private function contents( array $mails ): void {
		global $wpdb;
		foreach ( array_chunk( array_keys( $mails ), self::BODY_BATCH ) as $ids ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, content_type, recipients, SUBSTR(message, 1, %d) AS body, meta FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC',
					array_merge( array( Content::MAX_BODY, Repository::table() ), $ids )
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$log_id = (int) $row['id'];
				if ( ! isset( $mails[ $log_id ] ) ) {
					continue;
				}
				list( $id, $time, $day ) = $mails[ $log_id ];
				$extra                   = (array) ( $this->types[ $id ]['extra'] ?? array() );
				$body                    = (string) $row['body'];

				$extra['lid']     = max( (int) ( $extra['lid'] ?? 0 ), $log_id );
				$content          = (array) ( $extra['content'] ?? array() );
				$extra['content'] = Content::observe( $content, Content::hash( $body, Content::is_html( (string) $row['content_type'], $body ) ), $log_id, $time );

				if ( Noise::to_admin( (string) $row['recipients'], $this->admins() ) ) {
					$extra = Noise::observe( $extra, $day );
				}

				$trace = self::trace( (string) $row['meta'] );
				if ( null !== $trace ) {
					$extra  = Speed::observe( $extra, $trace );
					$origin = isset( $trace['origin']['function'] ) && is_string( $trace['origin']['function'] ) ? $trace['origin']['function'] : '';
					if ( '' !== $origin ) {
						$extra['fn'] = substr( $origin, 0, 100 ); // Where the type is sent from (switch-off links).
					}
				}

				$cron = null === $trace ? null : self::cron_of_trace( $trace );
				if ( null !== $cron ) {
					// Majority of the emails with a trace: "mostly sent by WP-Cron" plus the latest cron hook.
					$extra['traced'] = (int) ( $extra['traced'] ?? 0 ) + 1;
					if ( false !== $cron ) {
						$extra['cron_n'] = (int) ( $extra['cron_n'] ?? 0 ) + 1;
						if ( '' !== $cron || ! isset( $extra['cron_hook'] ) ) {
							$extra['cron_hook'] = $cron;
						}
					}
				}
				$this->types[ $id ]['extra'] = $extra;
			}
		}
	}

	/**
	 * Cron origin from a log row's meta (trace): the hook (or '' when unknown), false for other requests, null
	 * when the email has no trace (imported, anonymised, trace module off).
	 *
	 * @return string|false|null
	 */
	public static function cron_hook( string $meta ) {
		$trace = self::trace( $meta );
		return null === $trace ? null : self::cron_of_trace( $trace );
	}

	/**
	 * The trace of a log row's meta, when it has a request context.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function trace( string $meta ): ?array {
		if ( '' === $meta || false === strpos( $meta, '"request"' ) ) {
			return null;
		}
		$data  = json_decode( $meta, true );
		$trace = is_array( $data ) && isset( $data['trace'] ) && is_array( $data['trace'] ) ? $data['trace'] : array();
		return isset( $trace['request'] ) && is_array( $trace['request'] ) ? $trace : null;
	}

	/**
	 * Cron origin of a trace with a request context.
	 *
	 * @param array<string,mixed> $trace
	 * @return string|false
	 */
	private static function cron_of_trace( array $trace ) {
		$request = (array) $trace['request'];
		if ( 'cron' !== ( $request['type'] ?? '' ) ) {
			return false;
		}
		if ( isset( $request['hook'] ) && is_string( $request['hook'] ) ) {
			return substr( $request['hook'], 0, 100 );
		}
		// Older traces: the outermost hook on the stack is the cron event.
		$hooks = isset( $trace['hooks'] ) && is_array( $trace['hooks'] ) ? array_values( $trace['hooks'] ) : array();
		return isset( $hooks[0] ) && is_string( $hooks[0] ) ? substr( $hooks[0], 0, 100 ) : '';
	}

	/**
	 * Whether a type's emails come from WP-Cron (most of the traced ones), and from which hook.
	 *
	 * @param array<string,mixed> $extra Type state.
	 * @return string|null Cron hook ('' = unknown hook), null when the type is not sent by cron.
	 */
	public static function cron_of( array $extra ): ?string {
		$traced = (int) ( $extra['traced'] ?? 0 );
		$cron   = (int) ( $extra['cron_n'] ?? 0 );
		if ( $traced < 1 || $cron * 2 <= $traced ) {
			return null;
		}
		return (string) ( $extra['cron_hook'] ?? '' );
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
				'extra'       => array(),
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

	/** @return string[] */
	private function admins(): array {
		if ( null === $this->admins ) {
			$this->admins = Noise::addresses();
		}
		return $this->admins;
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
