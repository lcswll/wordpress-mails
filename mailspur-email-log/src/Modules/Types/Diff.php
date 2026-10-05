<?php
/**
 * Line diff of two emails of a type for the "Compare" view. Pure functions.
 *
 * Lines are matched by their normalised form (Content::normalise), so "Hi Anna," and "Hi Ben," or two order
 * numbers count as the same line and only real changes are marked. Shown is the text of the newer email for
 * unchanged lines, the older one's for removed and the newer one's for added lines.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Diff {

	/**
	 * @param string[] $before Lines of the older email (Content::lines()).
	 * @param string[] $after  Lines of the newer email.
	 * @return array<int,array{0:string,1:string}> Operations: [' ' | '-' | '+', line].
	 */
	public static function lines( array $before, array $after ): array {
		$before = array_values( $before );
		$after  = array_values( $after );
		$a      = array_map( array( Content::class, 'normalise' ), $before );
		$b      = array_map( array( Content::class, 'normalise' ), $after );
		$na     = count( $a );
		$nb     = count( $b );

		// Common head and tail first: keeps the table small for the usual "one paragraph changed".
		$head = 0;
		while ( $head < $na && $head < $nb && $a[ $head ] === $b[ $head ] ) {
			++$head;
		}
		$tail = 0;
		while ( $tail < $na - $head && $tail < $nb - $head && $a[ $na - 1 - $tail ] === $b[ $nb - 1 - $tail ] ) {
			++$tail;
		}

		$out = array();
		for ( $i = 0; $i < $head; $i++ ) {
			$out[] = array( ' ', $after[ $i ] );
		}

		$x = array_slice( $a, $head, $na - $head - $tail );
		$y = array_slice( $b, $head, $nb - $head - $tail );
		$n = count( $x );
		$m = count( $y );

		// Longest common subsequence (lengths from the end), then walk it.
		$lcs = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$lcs[ $i ][ $j ] = $x[ $i ] === $y[ $j ] ? $lcs[ $i + 1 ][ $j + 1 ] + 1 : max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
			}
		}
		$i = 0;
		$j = 0;
		while ( $i < $n || $j < $m ) {
			if ( $i < $n && $j < $m && $x[ $i ] === $y[ $j ] ) {
				$out[] = array( ' ', $after[ $head + $j ] );
				++$i;
				++$j;
			} elseif ( $i < $n && ( $j >= $m || $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) ) {
				$out[] = array( '-', $before[ $head + $i ] );
				++$i;
			} else {
				$out[] = array( '+', $after[ $head + $j ] );
				++$j;
			}
		}

		for ( $k = $nb - $tail; $k < $nb; $k++ ) {
			$out[] = array( ' ', $after[ $k ] );
		}
		return $out;
	}
}
