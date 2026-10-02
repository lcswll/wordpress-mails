<?php
/**
 * How regularly an email type is sent, and whether its silence is unusual. Pure functions.
 *
 * Learns from the type's own history – no thresholds to configure: over the last 56 full days, a type sent
 * on at least 6 days is "regular". Its expected gap is the larger of twice the median gap between sending
 * days and the longest gap seen plus one day. So a daily order confirmation is overdue after two days,
 * one that pauses every weekend only after four, a weekly report after two weeks.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Rhythm {

	const WINDOW     = 56;
	const MIN_ACTIVE = 6;

	/**
	 * @param array<string,int> $days      Emails per site-local day ("Y-m-d" => count), any range.
	 * @param string            $today     Site-local date "Y-m-d" (not part of the window, it is not over yet).
	 * @param int               $last_seen Unix time of the latest email of this type.
	 * @param int               $now       Unix time.
	 * @return array{active:int,regular:bool,median:int,expected:int,silent:bool,overdue:int}
	 *         active: sending days in the window; median/expected: gaps in days; overdue: seconds past the expected gap.
	 */
	public static function analyse( array $days, string $today, int $last_seen, int $now ): array {
		$end    = (int) strtotime( $today . ' 00:00:00 UTC' );
		$start  = $end - self::WINDOW * DAY_IN_SECONDS;
		$active = array();
		foreach ( $days as $day => $count ) {
			$time = strtotime( $day . ' 00:00:00 UTC' );
			if ( $count > 0 && false !== $time && $time >= $start && $time < $end ) {
				$active[] = intdiv( $time, DAY_IN_SECONDS );
			}
		}
		sort( $active );

		$result = array(
			'active'   => count( $active ),
			'regular'  => false,
			'median'   => 0,
			'expected' => 0,
			'silent'   => false,
			'overdue'  => 0,
		);
		if ( count( $active ) < self::MIN_ACTIVE ) {
			return $result;
		}

		$gaps = array();
		for ( $i = 1, $n = count( $active ); $i < $n; $i++ ) {
			$gaps[] = $active[ $i ] - $active[ $i - 1 ];
		}
		sort( $gaps );
		$median   = $gaps[ intdiv( count( $gaps ), 2 ) ];
		$expected = max( 2 * $median, max( $gaps ) + 1, 2 );
		$overdue  = $now - $last_seen - $expected * DAY_IN_SECONDS;

		$result['regular']  = true;
		$result['median']   = $median;
		$result['expected'] = $expected;
		$result['silent']   = $overdue > 0;
		$result['overdue']  = max( 0, $overdue );
		return $result;
	}
}
