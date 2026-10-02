<?php
/**
 * Turns subjects into subject patterns ("Order #{#} from {email}") and decides whether a subject belongs to
 * an existing pattern of the same sender. Pure functions, no WordPress calls.
 *
 * Step 1 – placeholders: quoted text, URLs, addresses, random ids, dates, times and numbers become
 * {text} {url} {email} {id} {date} {time} {#}, so "Order #1001" and "Order #1002" are the same type at once.
 *
 * Step 2 – merging: what is left are names ("Welcome, Anna" / "Welcome, Ben"). Two subjects of the same
 * sender and the same number of words merge into "Welcome, {…}" when the differing words look like
 * variables (capitalised in a lowercase sentence, or containing digits/placeholders) and most words stay
 * literal. Lowercase words never merge, so "Your order is complete" and "Your order is cancelled" stay
 * two types.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Fingerprint {

	const WILDCARD = '{…}';

	/** Pattern of the catch-all type of a sender with very many different subjects. */
	const OTHER = '{…}*';

	/** Placeholders, in the order they are applied. */
	const RULES = array(
		'/(["“„«‹])[^"“”„«»‹›]{1,200}?(["”“»›])/u'   => '{text}',
		'/\bhttps?:\/\/\S+/iu'                       => '{url}',
		'/[^\s<>()"\']+@[^\s<>()"\']+\.[a-z]{2,}/iu' => '{email}',
		'/\b(?=[a-z_-]*\d)(?=[\d_-]*[a-z])[a-z0-9_-]{10,}\b/iu' => '{id}',
		'/\b\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}\b/u'   => '{date}',
		'/\b\d{1,2}:\d{2}(?::\d{2})?(?:\s?[ap]\.?m\.?)?/iu' => '{time}',
		'/\d+(?:[.,]\d+)*/u'                         => '{#}',
	);

	/**
	 * Words of a subject with placeholders applied.
	 *
	 * @return string[]
	 */
	public static function tokens( string $subject ): array {
		$subject = trim( (string) preg_replace( '/\s+/u', ' ', $subject ) );
		if ( '' === $subject ) {
			return array();
		}
		foreach ( self::RULES as $pattern => $placeholder ) {
			$subject = (string) preg_replace( $pattern, $placeholder, $subject );
		}
		$words = preg_split( '/\s+/u', $subject, -1, PREG_SPLIT_NO_EMPTY );
		return array_slice( is_array( $words ) ? $words : array(), 0, 40 );
	}

	/**
	 * Merges a subject into a pattern of the same sender.
	 *
	 * @param string[] $pattern Words of the existing pattern.
	 * @param string[] $words   Words of the new subject (see tokens()).
	 * @return string[]|null The (possibly widened) pattern, or null when the subject is a different type.
	 */
	public static function merge( array $pattern, array $words ): ?array {
		$count = count( $words );
		if ( count( $pattern ) !== $count ) {
			return null;
		}
		if ( 0 === $count ) {
			return $pattern;
		}

		$differs = array();
		$wild    = 0;
		foreach ( $pattern as $i => $word ) {
			if ( false !== strpos( $word, self::WILDCARD ) ) {
				++$wild;
			} elseif ( self::lower( $word ) !== self::lower( $words[ $i ] ) ) {
				$differs[] = $i;
			}
		}
		if ( ! $differs ) {
			return $pattern;
		}

		// Never let names swallow the sentence: at most a third of the words (min. one) may be variable.
		$after = $wild + count( $differs );
		if ( $count < 2 || $after > max( 1, intdiv( $count, 3 ) ) || $count - $after < 1 ) {
			return null;
		}
		$title_case = self::title_case( $pattern ) || self::title_case( $words );
		foreach ( $differs as $i ) {
			if ( ! self::variable( $pattern[ $i ], 0 === $i, $title_case ) || ! self::variable( $words[ $i ], 0 === $i, $title_case ) ) {
				return null;
			}
		}

		foreach ( $differs as $i ) {
			$pattern[ $i ] = self::wildcard( $pattern[ $i ], $words[ $i ] );
		}
		return $pattern;
	}

	/** "{…}" keeping punctuation both words share: "Anna," + "Ben," → "{…},". */
	private static function wildcard( string $a, string $b ): string {
		$affix = static function ( string $regex, string $word ): string {
			return preg_match( $regex, $word, $m ) ? $m[0] : '';
		};
		$lead  = $affix( '/^[^\p{L}\p{N}]+/u', $a );
		$trail = $affix( '/[^\p{L}\p{N}]+$/u', $a );
		return ( $lead === $affix( '/^[^\p{L}\p{N}]+/u', $b ) ? $lead : '' )
			. self::WILDCARD
			. ( $trail === $affix( '/[^\p{L}\p{N}]+$/u', $b ) ? $trail : '' );
	}

	/** Whether a differing word looks like a value (a name, a code) rather than wording. */
	private static function variable( string $word, bool $first, bool $title_case ): bool {
		if ( false !== strpos( $word, '{' ) || preg_match( '/\d/u', $word ) ) {
			return true;
		}
		$letters = (string) preg_replace( '/[^\p{L}]/u', '', $word );
		if ( '' === $letters ) {
			return false;
		}
		// Capitalised words only signal a name inside a normally written sentence, never as its first word.
		return ! $first && ! $title_case && self::upper_first( $letters );
	}

	/**
	 * "New Order Received": most words capitalised, so capitals say nothing.
	 *
	 * @param string[] $words
	 */
	private static function title_case( array $words ): bool {
		$letters = 0;
		$upper   = 0;
		foreach ( array_slice( $words, 1 ) as $word ) { // The first word is capitalised anyway.
			$word = (string) preg_replace( '/[^\p{L}]/u', '', $word );
			if ( '' === $word || ( function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word ) ) < 4 ) {
				continue; // Short words (a, of, to) are lowercase in title case too.
			}
			++$letters;
			if ( self::upper_first( $word ) ) {
				++$upper;
			}
		}
		return $letters >= 3 && $upper / $letters >= 0.6;
	}

	private static function upper_first( string $letters ): bool {
		return 1 === preg_match( '/^\p{Lu}/u', $letters );
	}

	private static function lower( string $word ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
	}

	/**
	 * Longest run of literal words, for a log search that finds the emails of this type.
	 *
	 * @param string[] $pattern
	 */
	public static function search_term( array $pattern ): string {
		$best = '';
		$run  = array();
		foreach ( array_merge( $pattern, array( '{' ) ) as $word ) {
			if ( false !== strpos( $word, '{' ) ) {
				$candidate = implode( ' ', $run );
				if ( strlen( $candidate ) > strlen( $best ) ) {
					$best = $candidate;
				}
				$run = array();
				continue;
			}
			$run[] = $word;
		}
		return $best;
	}
}
