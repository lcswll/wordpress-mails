<?php
/**
 * Daily retention cleanup via WP-Cron.
 *
 * Modules can give single kinds of email their own period (filter mailspur_retention_rules, e.g. "Keep for 7 days"
 * per email type). Only the entries of senders with such a rule are read – in small batches – and sorted by
 * subject; everything else is deleted by date as before.
 *
 * Direct queries: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	const HOOK = 'mailspur_cleanup';

	/** Days of a rule that keeps entries until the maximum number of entries is reached (forever without one). */
	const UNTIL_LIMIT = -1;

	/** Entries read per query while sorting a sender's entries by rule. */
	const BATCH = 500;

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) ); // Self-heal if the event got lost.
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * @param int|null $now Unix time (tests); the cron passes nothing.
	 */
	public function run( $now = null ): void {
		$now   = is_int( $now ) ? $now : time();
		$days  = (int) Settings::get( 'retention_days' );
		$rules = self::rules();

		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
			$longer = array();
			foreach ( $rules as $rule ) {
				if ( self::UNTIL_LIMIT === $rule['days'] || $rule['days'] > $days ) {
					$longer[ $rule['source'] ] = true;
				}
			}
			$this->repository->delete_before( $cutoff, array_map( 'strval', array_keys( $longer ) ) );
			// Senders with a longer rule: their other entries still go after the general period.
			foreach ( array_keys( $longer ) as $source ) {
				$source = (string) $source;
				$this->sweep(
					$source,
					$cutoff,
					false,
					static function ( array $row ) use ( $rules, $source, $now ): bool {
						foreach ( $rules as $rule ) {
							if ( $rule['source'] === $source && self::keeps( $rule, (string) $row['created_at'], $now ) && call_user_func( $rule['match'], (string) $row['subject'] ) ) {
								return false;
							}
						}
						return true;
					}
				);
			}
		}

		// Shorter periods: only the entries of the rule.
		foreach ( $rules as $rule ) {
			if ( $rule['days'] > 0 && ( $days <= 0 || $rule['days'] < $days ) ) {
				$this->sweep(
					$rule['source'],
					gmdate( 'Y-m-d H:i:s', $now - $rule['days'] * DAY_IN_SECONDS ),
					true,
					static function ( array $row ) use ( $rule ): bool {
						return (bool) call_user_func( $rule['match'], (string) $row['subject'] );
					}
				);
			}
		}

		$max = (int) Settings::get( 'max_entries' );
		if ( $max > 0 ) {
			$this->repository->trim_to( $max );
		}
	}

	/**
	 * Valid retention rules of the modules.
	 *
	 * @return array<int,array{source:string,days:int,match:callable}>
	 */
	public static function rules(): array {
		/**
		 * Own retention periods for single kinds of email.
		 *
		 * @param mixed[] $rules Per rule an array: "source" – the sender ("plugin:woocommerce"), "days"
		 *                       (Cleanup::UNTIL_LIMIT: until the maximum number of entries) and "match" – a callback
		 *                       ( string $subject ): bool telling whether an entry of that sender belongs to the rule.
		 */
		$rules = (array) apply_filters( 'mailspur_retention_rules', array() );
		$out   = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['source'], $rule['days'], $rule['match'] ) || ! is_callable( $rule['match'] ) || '' === (string) $rule['source'] ) {
				continue;
			}
			$days = (int) $rule['days'];
			if ( $days > 0 || self::UNTIL_LIMIT === $days ) {
				$out[] = array(
					'source' => (string) $rule['source'],
					'days'   => $days,
					'match'  => $rule['match'],
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a rule still keeps an entry of a date.
	 *
	 * @param array{source:string,days:int,match:callable} $rule
	 */
	private static function keeps( array $rule, string $created_at, int $now ): bool {
		return self::UNTIL_LIMIT === $rule['days'] || $created_at >= gmdate( 'Y-m-d H:i:s', $now - $rule['days'] * DAY_IN_SECONDS );
	}

	/**
	 * Deletes the entries of one sender before a date that a callback picks, in batches along the primary key.
	 *
	 * @param bool                               $by_rule A rule's own (shorter) period: anonymised entries wait for
	 *                                                    the general period, and modules may keep entries
	 *                                                    (filter mailspur_retention_expire).
	 * @param callable(array<string,mixed>):bool $expired Row (id, created_at, subject) → delete it?
	 * @return int Entries deleted.
	 */
	private function sweep( string $source, string $before, bool $by_rule, callable $expired ): int {
		global $wpdb;
		$cursor  = 0;
		$deleted = 0;
		$sql     = 'SELECT id, created_at, subject FROM %i WHERE source = %s AND created_at < %s AND id > %d'
			. ( $by_rule ? ' AND meta NOT LIKE %s' : '' ) . ' ORDER BY id ASC LIMIT %d';
		do {
			$args = array( Repository::table(), $source, $before, $cursor );
			if ( $by_rule ) {
				$args[] = $wpdb->esc_like( '{"anonymised":' ) . '%';
			}
			$args[] = self::BATCH;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from literals and placeholders only.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			$read = count( $rows );
			$ids  = array();
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				if ( $expired( (array) $row ) ) {
					$ids[] = $cursor;
				}
			}
			if ( $ids && $by_rule ) {
				/**
				 * Entries whose own retention period ended, before they are deleted. Return the ids to delete; a
				 * module may keep some (the anonymisation anonymises them instead).
				 *
				 * @param int[] $ids Log entry ids.
				 */
				$ids = array_map( 'intval', (array) apply_filters( 'mailspur_retention_expire', $ids ) );
			}
			if ( $ids ) {
				$deleted += $this->repository->delete( $ids );
			}
		} while ( self::BATCH === $read );
		return $deleted;
	}
}
