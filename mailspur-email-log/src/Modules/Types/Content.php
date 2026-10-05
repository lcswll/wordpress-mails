<?php
/**
 * Content structure of an email type ("before/after"): a cheap fingerprint of the body and the decision whether
 * a type's content changed. Pure functions, no WordPress calls – runs during indexing, never while sending.
 *
 * Fingerprint: the body as text lines (HTML tags stripped, link targets kept without their query string),
 * every line normalised like a subject pattern – numbers, dates, times, addresses, codes and capitalised
 * names become placeholders, short salutations ("Hi Anna,", "Hello Ben,") one token – lowercased, hashed.
 * Two order confirmations for different customers and amounts share the fingerprint; a missing link, a new
 * paragraph or an empty placeholder do not.
 *
 * Change detection per type (state kept in the type row, see observe()):
 * - the first LEARN emails only collect the structures a type has (e.g. one variant per payment method);
 * - afterwards an unseen structure is a candidate and confirmed by the next email of the type that does not
 *   have one of the known structures either – a one-off variant that is followed by a known one is learnt
 *   silently;
 * - types whose content is free-form (contact forms: almost every email differs) are marked "variable" and
 *   never report changes.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Content {

	/** Emails per type that only teach the known structures. */
	const LEARN = 5;

	/** Known structures kept per type. */
	const KNOWN = 8;

	/** Characters of a body that are read (bodies are cut at this length before normalising). */
	const MAX_BODY = 60000;

	/** Lines of a body that count. */
	const MAX_LINES = 300;

	/**
	 * Readable text lines of a body: tags removed, block elements as line breaks, link targets in square brackets.
	 *
	 * @return string[]
	 */
	public static function lines( string $message, bool $html ): array {
		$text = substr( $message, 0, self::MAX_BODY );
		if ( $html ) {
			$text = (string) preg_replace( '#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', ' ', $text );
			$text = (string) preg_replace( '/<!--.*?-->/s', ' ', $text );
			// Links keep their target (without query string – tokens live there), so a missing link is a change.
			$text = (string) preg_replace_callback(
				'#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
				static function ( array $m ): string {
					$url = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					return $m[3] . ( preg_match( '#^(https?:)?//#i', $url ) ? ' [' . self::strip_query( $url ) . ']' : '' );
				},
				$text
			);
			$text = (string) preg_replace( '#<br\b[^>]*>|</?(?:p|div|tr|li|ul|ol|h[1-6]|table|tbody|thead|tfoot|blockquote|section|header|footer|article|center|hr|pre)\b[^>]*>#i', "\n", $text );
			$text = (string) preg_replace( '#</t[dh]\s*>#i', ' ', $text );
			$text = (string) preg_replace( '/<[^>]*>/', '', $text ); // Same as strip_tags(), but also for broken markup.
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$out   = array();
		$split = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( is_array( $split ) ? $split : array() as $line ) {
			$line = trim( (string) preg_replace( '/[\s\x{00a0}\x{200b}]+/u', ' ', (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			$out[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 500, 'UTF-8' ) : substr( $line, 0, 500 );
			if ( count( $out ) >= self::MAX_LINES ) {
				break;
			}
		}
		return $out;
	}

	/** "https://shop.example/view-order/12/?key=x#top" → "https://shop.example/view-order/12/". */
	public static function strip_query( string $url ): string {
		return substr( $url, 0, strcspn( $url, '?#' ) );
	}

	/**
	 * One line reduced to its structure.
	 */
	public static function normalise( string $line ): string {
		// Salutation: "Hi Anna," / "Hello Ben," / "Dear customer," – the wording of a greeting is no structure.
		// Not when the name is missing ("Hi ,") or a merge tag was left unreplaced ("Hi {first_name},").
		if ( preg_match( '/^[^\s{}\[\]%]+(?:\s[^\s{}\[\]%]+)?\s[^\s{}\[\]%]*\p{L},$/u', trim( $line ) ) ) {
			return '{greeting},';
		}

		$line = (string) preg_replace_callback(
			'#\bhttps?://[^\s<>\[\]"\']+#iu',
			static function ( array $m ): string {
				return self::strip_query( $m[0] );
			},
			$line
		);
		foreach ( Fingerprint::RULES as $pattern => $placeholder ) {
			if ( '{url}' !== $placeholder ) {
				$line = (string) preg_replace( $pattern, $placeholder, $line );
			}
		}
		$words = preg_split( '/\s+/u', trim( $line ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();

		$out      = array();
		$previous = '';
		foreach ( $words as $i => $word ) {
			$sentence = 0 === $i || preg_match( '/[.!?:]$/u', $previous );
			$previous = $word;
			// Capitalised words inside a sentence are names (customer, product, city) – not the structure.
			if ( ! $sentence && preg_match( '/^[^\p{L}\p{N}{]*\p{Lu}/u', $word ) ) {
				$affix = preg_match( '/[^\p{L}\p{N}}]+$/u', $word, $m ) ? $m[0] : '';
				$word  = Fingerprint::WILDCARD . $affix;
				// "Anna Smith" is one name.
				if ( $out && 0 === strpos( (string) end( $out ), Fingerprint::WILDCARD ) ) {
					array_pop( $out );
				}
			}
			$out[] = $word;
		}
		$line = implode( ' ', $out );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $line, 'UTF-8' ) : strtolower( $line );
	}

	/**
	 * Fingerprint of a body; '' when there is nothing to compare (empty or anonymised).
	 */
	public static function hash( string $message, bool $html ): string {
		$lines = self::lines( $message, $html );
		if ( ! $lines ) {
			return '';
		}
		return substr( md5( implode( "\n", array_map( array( self::class, 'normalise' ), $lines ) ) ), 0, 12 );
	}

	/** Whether a logged body is HTML (content type, or sniffed like the log's detail view). */
	public static function is_html( string $content_type, string $message ): bool {
		$type = strtolower( $content_type );
		if ( '' === $type ) {
			return 1 === preg_match( '/<(?:html|body|table|div|p|br|a)\b[^>]*>/i', $message );
		}
		return false !== strpos( $type, 'html' );
	}

	/**
	 * Feeds the next email of a type (in log order) into the type's content state.
	 *
	 * State keys: h (known structures), n (emails seen), nv (unseen structures after learning), var (free-form
	 * type), last (latest email: [id, time, hash]), p (candidate change), c (latest confirmed change:
	 * before/before_at/after/after_at).
	 *
	 * @param array<string,mixed> $state Content state of the type (empty for a new type).
	 * @param string              $hash  Fingerprint of the email ('' = no body: ignored).
	 * @param int                 $id    Log id.
	 * @param int                 $time  Unix time of the email.
	 * @return array<string,mixed> New state.
	 */
	public static function observe( array $state, string $hash, int $id, int $time ): array {
		if ( '' === $hash ) {
			return $state;
		}
		$known = array_values( array_map( 'strval', (array) ( $state['h'] ?? array() ) ) );
		$n     = (int) ( $state['n'] ?? 0 ) + 1;
		$last  = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : null;

		$state['n'] = $n;
		if ( $n <= self::LEARN ) {
			$known = self::remember( $known, $hash );
			if ( self::LEARN === $n && count( $known ) > 3 ) {
				$state['var'] = true; // Five emails, four or more structures: free-form content.
			}
		} elseif ( empty( $state['var'] ) ) {
			$pending = isset( $state['p'] ) && is_array( $state['p'] ) ? $state['p'] : null;
			if ( $pending ) {
				if ( $hash === $pending['hash'] || ! in_array( $hash, $known, true ) ) {
					$state['c'] = array(
						'before'    => (int) $pending['before'],
						'before_at' => (int) $pending['before_at'],
						'after'     => (int) $pending['after'],
						'after_at'  => (int) $pending['after_at'],
					);
				}
				$known = self::remember( self::remember( $known, (string) $pending['hash'] ), $hash );
				unset( $state['p'] );
			} elseif ( ! in_array( $hash, $known, true ) ) {
				$state['nv'] = (int) ( $state['nv'] ?? 0 ) + 1;
				if ( $state['nv'] > 5 && $state['nv'] * 3 > $n ) {
					$state['var'] = true;
					unset( $state['c'] );
				} elseif ( $last ) {
					$state['p'] = array(
						'hash'      => $hash,
						'before'    => (int) $last[0],
						'before_at' => (int) $last[1],
						'after'     => $id,
						'after_at'  => $time,
					);
				}
			} else {
				$known = self::remember( $known, $hash );
			}
		}

		$state['h']    = $known;
		$state['last'] = array( $id, $time, $hash );
		return $state;
	}

	/**
	 * Known structures, most recently seen last, capped.
	 *
	 * @param string[] $known
	 * @return string[]
	 */
	private static function remember( array $known, string $hash ): array {
		$known   = array_values( array_diff( $known, array( $hash ) ) );
		$known[] = $hash;
		return array_slice( $known, -self::KNOWN );
	}
}
