<?php
/**
 * Health of every email type: volume of the last 30 days, failures, rhythm, silence and what changed on the
 * site since the type was last sent. Pure (input: stored types and day counters), used by the tab, REST and
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

	/**
	 * @param array<int,array<string,mixed>>                                   $types   Store::types().
	 * @param array<int,array<string,array{total:int,failed:int,held:int}>>    $days    Store::days_since().
	 * @param string                                                           $today   Site-local "Y-m-d".
	 * @param int                                                              $now     Unix time.
	 * @param array<int,array{time:int,label:string,slug:string}>              $updates Updates::all().
	 * @return array<int,array<string,mixed>> Items, most emails in 30 days first.
	 */
	public static function build( array $types, array $days, string $today, int $now, array $updates = array() ): array {
		$midnight = (int) strtotime( $today . ' 00:00:00 UTC' );
		$range    = array();
		for ( $i = self::DAYS - 1; $i >= 0; $i-- ) {
			$range[] = gmdate( 'Y-m-d', $midnight - $i * DAY_IN_SECONDS );
		}
		$week = gmdate( 'Y-m-d', $midnight - 6 * DAY_IN_SECONDS );

		$items = array();
		foreach ( $types as $id => $type ) {
			$counts = $days[ $id ] ?? array();
			$series = array();
			$total  = 0;
			$failed = 0;
			$held   = 0;
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
				'state'      => $state,
				'series'     => $series,
				'total'      => $total,
				'failed'     => $failed,
				'held'       => $held,
				'rhythm'     => $rhythm,
				'updates'    => $since,
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
		return self::build( $types ?? $store->types(), $store->days_since( $since, $one ), $today, $now, Updates::all() );
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
		);
		foreach ( $items as $item ) {
			if ( isset( $out[ $item['state'] ] ) ) {
				++$out[ $item['state'] ];
			}
		}
		return $out;
	}
}
