<?php
/**
 * Health of every email type: volume of the last 30 days, failures, rhythm, silence, what changed on the
 * site since the type was last sent, emails to administrators (Noise) and waiting time (Speed). Pure (input: stored types and day counters), used by the tab, REST and
 * the alert check.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Modules\Workflow\Sources;

defined( 'ABSPATH' ) || exit;

final class Report {

	const DAYS = 30;

	/** Days a type counts as new. */
	const NEW_DAYS = 7;

	/** Content changes marked as seen: type id => log id of the first email after the change. */
	const SEEN = 'mailspur_types_seen';

	/**
	 * @param array<int,array<string,mixed>>                                   $types   Store::types().
	 * @param array<int,array<string,array{total:int,failed:int,held:int,reported?:int,bounced?:int,complaint?:int}>> $days Store::days_since().
	 * @param string                                                           $today   Site-local "Y-m-d".
	 * @param int                                                              $now     Unix time.
	 * @param array<int,array{time:int,label:string,slug:string}>              $updates Updates::all().
	 * @param array<int|string,int>                                            $seen    Dismissed content changes (option SEEN).
	 * @return array<int,array<string,mixed>> Items, most emails in 30 days first.
	 */
	public static function build( array $types, array $days, string $today, int $now, array $updates = array(), array $seen = array() ): array {
		$midnight = (int) strtotime( $today . ' 00:00:00 UTC' );
		$range    = array();
		for ( $i = self::DAYS - 1; $i >= 0; $i-- ) {
			$range[] = gmdate( 'Y-m-d', $midnight - $i * DAY_IN_SECONDS );
		}
		$week = gmdate( 'Y-m-d', $midnight - 6 * DAY_IN_SECONDS );

		$items = array();
		foreach ( $types as $id => $type ) {
			$counts   = $days[ $id ] ?? array();
			$series   = array();
			$total    = 0;
			$failed   = 0;
			$held     = 0;
			$delivery = array(
				'reported'  => 0,
				'bounced'   => 0,
				'complaint' => 0,
			);
			foreach ( $range as $day ) {
				$c        = $counts[ $day ] ?? array(
					'total'  => 0,
					'failed' => 0,
					'held'   => 0,
				);
				$series[] = array( $day, $c['total'], $c['failed'], $c['held'] );
				$total   += $c['total'];
				$failed  += $c['failed'];
				$held    += $c['held'];
				foreach ( $delivery as $key => $sum ) {
					$delivery[ $key ] = $sum + (int) ( $c[ $key ] ?? 0 );
				}
			}
			$week_total  = 0;
			$week_failed = 0;
			$totals      = array();
			foreach ( $counts as $day => $c ) {
				$totals[ $day ] = $c['total']; // Held emails were still produced: no silence.
				if ( $day >= $week ) {
					$week_total  += $c['total'];
					$week_failed += $c['failed'];
				}
			}

			$last_seen  = (int) strtotime( $type['last_seen'] . ' UTC' );
			$first_seen = (int) strtotime( $type['first_seen'] . ' UTC' );
			$rhythm     = Rhythm::analyse( $totals, $today, $last_seen, $now );
			$failing    = ( $week_failed >= 3 && $week_failed / max( 1, $week_total ) >= 0.2 ) || 2 === (int) $type['last_status'];

			if ( $type['muted'] ) {
				$state = 'muted';
			} elseif ( $rhythm['silent'] ) {
				$state = 'silent';
			} elseif ( $failing ) {
				$state = 'failing';
			} elseif ( $now - $first_seen < self::NEW_DAYS * DAY_IN_SECONDS ) {
				$state = 'new';
			} else {
				$state = 'ok';
			}

			$since = array();
			if ( 'silent' === $state ) {
				foreach ( $updates as $update ) {
					if ( (int) $update['time'] > $last_seen ) {
						$since[] = $update;
					}
				}
			}

			$extra   = (array) ( $type['extra'] ?? array() );
			$admin   = Noise::count( $extra, $range[0] );
			$items[] = array(
				'id'         => (int) $id,
				'source'     => (string) $type['source'],
				'pattern'    => (array) $type['pattern'],
				'other'      => array( Fingerprint::OTHER ) === $type['pattern'],
				'search'     => Fingerprint::search_term( (array) $type['pattern'] ),
				'first_seen' => $first_seen,
				'last_seen'  => $last_seen,
				'last_ok'    => 1 === (int) $type['last_status'],
				'notes'      => (int) $type['last_notes'],
				'muted'      => (bool) $type['muted'],
				'bundle'     => ! empty( $type['bundle'] ),
				'keep'       => (int) ( $type['keep'] ?? 0 ),
				'state'      => $state,
				'series'     => $series,
				'total'      => $total,
				'failed'     => $failed,
				'held'       => $held,
				'delivery'   => $delivery, // Provider statuses in 30 days: emails with a status, permanent bounces, complaints.
				'rhythm'     => $rhythm,
				'updates'    => $since,
				'last_id'    => (int) ( $extra['lid'] ?? 0 ),
				'change'     => self::change( $extra, $updates, (int) ( $seen[ $id ] ?? 0 ) ),
				'cron'       => Indexer::cron_of( $extra ),
				'cause'      => null,
				'admin'      => $admin,
				'noise'      => ! $type['muted'] && $admin >= Noise::THRESHOLD,
				'origin'     => (string) ( $extra['fn'] ?? '' ),
				'slow'       => $type['muted'] ? null : Speed::of( $extra ),
				'new_sender' => false,
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return array( $b['total'], $b['last_seen'] ) <=> array( $a['total'], $a['last_seen'] );
			}
		);
		return $items;
	}

	/**
	 * Report of the stored types (indexing is up to the caller).
	 *
	 * @param array<int,array<string,mixed>>|null $types Only these types (Store::types() format), default all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function current( Store $store, int $now, ?array $types = null ): array {
		$today = (string) wp_date( 'Y-m-d', $now );
		$since = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - Rhythm::WINDOW * DAY_IN_SECONDS );
		$one   = null !== $types && 1 === count( $types ) ? (int) key( $types ) : 0;
		$seen  = get_option( self::SEEN, array() );
		$items = self::build( $types ?? $store->types(), $store->days_since( $since, $one ), $today, $now, Updates::all(), is_array( $seen ) ? $seen : array() );
		$fresh = Senders::fresh( Senders::state(), $now );
		foreach ( $items as $i => $item ) {
			$items[ $i ]['new_sender'] = isset( $fresh[ $item['source'] ] );
			if ( 'silent' === $item['state'] && null !== $item['cron'] ) {
				$items[ $i ]['cause'] = Cron::current( (string) $item['cron'], $now );
			}
		}
		return $items;
	}

	/**
	 * Latest content change of a type that was not marked as seen, with the updates installed between the last
	 * email before and the first email after it.
	 *
	 * @param array<string,mixed>                                 $extra   Type state (Indexer).
	 * @param array<int,array{time:int,label:string,slug:string}> $updates Updates::all().
	 * @param int                                                 $seen    Log id of the change marked as seen.
	 * @return array{before:int,after:int,before_at:int,after_at:int,updates:string[]}|null
	 */
	public static function change( array $extra, array $updates, int $seen ): ?array {
		$content = isset( $extra['content'] ) && is_array( $extra['content'] ) ? $extra['content'] : array();
		$change  = isset( $content['c'] ) && is_array( $content['c'] ) ? $content['c'] : null;
		if ( null === $change || ! empty( $content['var'] ) || (int) $change['after'] === $seen ) {
			return null;
		}
		$between = array();
		foreach ( $updates as $update ) {
			if ( (int) $update['time'] > (int) $change['before_at'] && (int) $update['time'] <= (int) $change['after_at'] ) {
				$between[] = (string) $update['label'];
			}
		}
		return array(
			'before'    => (int) $change['before'],
			'after'     => (int) $change['after'],
			'before_at' => (int) $change['before_at'],
			'after_at'  => (int) $change['after_at'],
			'updates'   => array_values( array_unique( $between ) ),
		);
	}

	/**
	 * Marks the current content change of a type as seen (the marker disappears until the next change).
	 *
	 * @param array<int,mixed> $existing All types by id (entries of types that no longer exist are dropped).
	 */
	public static function mark_seen( int $id, int $after, array $existing ): void {
		$seen = get_option( self::SEEN, array() );
		$seen = is_array( $seen ) ? array_intersect_key( $seen, $existing ) : array();
		if ( $after > 0 ) {
			$seen[ $id ] = $after;
		}
		update_option( self::SEEN, $seen, false );
	}

	/**
	 * Readable sender name ("WooCommerce", "WordPress", "Imported from WP Mail Logging").
	 */
	public static function source_label( string $source ): string {
		static $names = null;
		if ( null === $names ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$names = array( Sources::names( get_plugins() ), Sources::names( get_mu_plugins() ) );
		}
		return Sources::label( $source, $names[0], $names[1] );
	}

	/**
	 * Pattern as text, placeholders shown as "…" ("New order #… from …").
	 *
	 * @param string[] $pattern
	 */
	public static function text( array $pattern ): string {
		if ( array( Fingerprint::OTHER ) === $pattern ) {
			return __( 'Other emails', 'mailspur-email-log' );
		}
		if ( ! $pattern ) {
			return __( '(no subject)', 'mailspur-email-log' );
		}
		return (string) preg_replace( '/\{[^}]*\}/u', '…', implode( ' ', $pattern ) );
	}

	/**
	 * Counts per state.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,int>
	 */
	public static function summary( array $items ): array {
		$out = array(
			'types'   => count( $items ),
			'senders' => count( array_unique( array_column( $items, 'source' ) ) ),
			'silent'  => 0,
			'failing' => 0,
			'new'     => 0,
			'noise'   => 0,
			'slow'    => 0,
			'fresh'   => 0,
		);
		foreach ( $items as $item ) {
			if ( isset( $out[ $item['state'] ] ) ) {
				++$out[ $item['state'] ];
			}
			$out['noise'] += empty( $item['noise'] ) ? 0 : 1;
			$out['slow']  += empty( $item['slow'] ) ? 0 : 1;
			$out['fresh'] += empty( $item['new_sender'] ) ? 0 : 1;
		}
		return $out;
	}
}
