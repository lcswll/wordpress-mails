<?php
/**
 * Finds secrets that some plugins put into emails in plain text – passwords, API keys / tokens with a
 * well-known prefix, private keys and payment card numbers – and masks them.
 *
 * Pure string functions (regular expressions on a capped input, no I/O), so they are safe to run while
 * a mail is logged. Used by Redactor (masking, setting "redact_secrets") and by the Notes module
 * (the note that tells the site owner about it). A finding never contains the secret itself, only its
 * kind and at most a masked hint such as "sk_live_…4f2a".
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Secrets {

	const MASK = '[redacted]';

	/** Bytes looked at; a mail body larger than this is only checked at its start. */
	const SCAN_BYTES = 524288;

	const PASSWORD    = 'password';
	const API_KEY     = 'api_key';
	const PRIVATE_KEY = 'private_key';
	const CARD        = 'card';

	/** Password labels (lower case, byte mode – "ñ" is matched as its UTF-8 bytes). */
	const LABEL = '(?<![\w\-.\/?&=])(?:password|passwort|kennwort|passwd|mot\s+de\s+passe|contrase(?:ñ|Ñ|&ntilde;|n)a|wachtwoord)(?!\w)';

	/** Inline tags and spaces between a label and its colon (e.g. "<b>Password</b>:"). */
	const GAP_INLINE = '(?:[ \t]|&nbsp;|\xC2\xA0|<\/?(?:b|strong|em|i|u|span|font|code|small|label)\b[^<>]{0,200}>){0,6}';

	/** Whitespace and any tags between the colon and the value (e.g. "</td><td><code>"). */
	const GAP_ANY = '(?:\s|&nbsp;|\xC2\xA0|<[^<>]{0,300}>){0,10}';

	/** Status words that follow a password label but are no password. */
	const NOT_A_PASSWORD = array( 'changed', 'unchanged', 'updated', 'hidden', 'required', 'optional', 'protected', 'encrypted', 'unknown', 'removed', 'geändert', 'aktualisiert', 'versteckt', 'verborgen', 'erforderlich', 'geschützt', 'verschlüsselt', 'unbekannt', 'modifié', 'masqué', 'cambiada', 'oculta', 'gewijzigd' );

	/**
	 * API keys and tokens with a well-known prefix: regex => length of the prefix shown in the hint.
	 */
	const API_KEYS = array(
		'/(?<![\w-])(?:sk|rk)_live_[0-9A-Za-z]{16,247}(?![\w-])/' => 8,
		'/(?<![\w-])gh[pousr]_[A-Za-z0-9]{36,251}(?![\w-])/' => 4,
		'/(?<![\w-])github_pat_[A-Za-z0-9_]{50,244}(?![\w-])/' => 11,
		'/(?<![\w-])xox[baprs]-[A-Za-z0-9-]{10,250}(?![\w-])/' => 5,
		'/(?<![\w-])AKIA[0-9A-Z]{16}(?![\w-])/'      => 4,
		'/(?<![\w-])AIza[0-9A-Za-z_-]{35}(?![\w-])/' => 4,
		'/(?<![\w-])SG\.[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}(?![\w-])/' => 3,
	);

	/**
	 * Kinds and masked hints of the secrets in a text (at most one finding per distinct secret).
	 *
	 * @return array<int,array{kind:string,hint:string}>
	 */
	public static function find( string $text ): array {
		if ( strlen( $text ) > self::SCAN_BYTES ) {
			$text = substr( $text, 0, self::SCAN_BYTES );
		}
		$out = array();
		foreach ( self::scan( $text ) as $hit ) {
			$out[ $hit['kind'] . "\0" . $hit['hint'] ] = array(
				'kind' => $hit['kind'],
				'hint' => $hit['hint'],
			);
		}
		return array_values( $out );
	}

	/** The text with every secret value replaced by "[redacted]". */
	public static function mask( string $text ): string {
		$hits = self::scan( $text );
		if ( ! $hits ) {
			return $text;
		}
		// Back to front, so earlier offsets stay valid; overlapping hits are skipped.
		usort(
			$hits,
			static function ( array $a, array $b ): int {
				return $b['start'] <=> $a['start'];
			}
		);
		$limit = PHP_INT_MAX;
		foreach ( $hits as $hit ) {
			if ( $hit['start'] + $hit['length'] > $limit ) {
				continue;
			}
			$text  = substr_replace( $text, self::MASK, $hit['start'], $hit['length'] );
			$limit = $hit['start'];
		}
		return $text;
	}

	/**
	 * Every secret with its byte range.
	 *
	 * @return array<int,array{kind:string,hint:string,start:int,length:int}>
	 */
	private static function scan( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		return array_merge( self::private_keys( $text ), self::api_keys( $text ), self::passwords( $text ), self::cards( $text ) );
	}

	/**
	 * @return array<int,array{kind:string,hint:string,start:int,length:int}>
	 */
	private static function private_keys( string $text ): array {
		if ( false === strpos( $text, 'PRIVATE KEY-----' ) ) {
			return array();
		}
		$hits = array();
		// The body up to the END line, or – without one – the run of base64 characters after the header.
		$re = '/-----BEGIN ((?:[A-Z0-9]+ )*)PRIVATE KEY-----(?:([\s\S]{1,8192}?)(?=-----END (?:[A-Z0-9]+ )*PRIVATE KEY-----)|([A-Za-z0-9+\/=\s]{1,8192}))/';
		if ( preg_match_all( $re, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				$body = isset( $match[3] ) && $match[3][1] >= 0 ? $match[3] : ( $match[2] ?? array( '', -1 ) );
				// Only a real key body counts, not a mention like "paste your -----BEGIN PRIVATE KEY----- here".
				if ( $body[1] < 0 || strlen( (string) preg_replace( '/<br\s*\/?>|[^A-Za-z0-9+\/]/', '', $body[0] ) ) < 64 ) {
					continue;
				}
				$hits[] = array(
					'kind'   => self::PRIVATE_KEY,
					'hint'   => '-----BEGIN ' . $match[1][0] . 'PRIVATE KEY-----',
					'start'  => $body[1],
					'length' => strlen( $body[0] ),
				);
			}
		}
		return $hits;
	}

	/**
	 * @return array<int,array{kind:string,hint:string,start:int,length:int}>
	 */
	private static function api_keys( string $text ): array {
		$hits = array();
		foreach ( self::API_KEYS as $re => $prefix ) {
			if ( ! preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $match ) {
				$hits[] = array(
					'kind'   => self::API_KEY,
					'hint'   => substr( $match[0], 0, $prefix ) . '…' . substr( $match[0], -4 ),
					'start'  => $match[1],
					'length' => strlen( $match[0] ),
				);
			}
		}
		return $hits;
	}

	/**
	 * "Password: Xy7!kq", "<b>Passwort:</b> …", "<td>Password</td><td>…</td>", "Your password is: …".
	 *
	 * @return array<int,array{kind:string,hint:string,start:int,length:int}>
	 */
	private static function passwords( string $text ): array {
		if ( ! preg_match( '/password|passwort|kennwort|passwd|mot\s+de\s+passe|contrase|wachtwoord/i', $text ) ) {
			return array();
		}
		$value = '([^\s<>"\']{6,128}?)[.,;]?';
		$res   = array(
			// Label, a few words and a colon ("Your password has been generated:") or "password is", then
			// the value alone up to the end of its line, cell or paragraph.
			'/' . self::LABEL . self::GAP_INLINE . '(?:(?:[ \t]+[A-Za-z\x80-\xFF]{1,20}){0,5}[ \t]*(?::|\xEF\xBC\x9A)|[ \t]+(?:is|ist|est|es)[ \t]+)' . self::GAP_ANY . $value . '(?=[ \t]*(?:<|\r?\n|&nbsp;|\z))/i',
			// Two table cells: label, then the value in the next cell.
			'/<t[dh]\b[^<>]*>\s*(?:<[^<>]{0,300}>\s*){0,5}(?:[^<>\n]{0,30}\s)?' . self::LABEL . '\s*:?\s*(?:<[^<>]{0,300}>\s*){0,5}<\/t[dh]>\s*<t[dh]\b[^<>]*>\s*(?:<[^<>]{0,300}>\s*){0,5}' . $value . '\s*(?=<)/i',
		);
		$hits  = array();
		foreach ( $res as $i => $re ) {
			if ( ! preg_match_all( $re, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m as $match ) {
				$candidate = $match[1][0];
				if ( ! self::looks_like_password( $candidate, 1 === $i ) ) {
					continue;
				}
				$hits[] = array(
					'kind'   => self::PASSWORD,
					'hint'   => '',
					'start'  => $match[1][1],
					'length' => strlen( $candidate ),
				);
			}
		}
		return $hits;
	}

	/**
	 * Rejects what follows a password label but is no password: links, masked or placeholder values,
	 * email addresses, status words.
	 *
	 * @param bool $in_table Value of a "Password" table cell (structure alone is a strong signal).
	 */
	public static function looks_like_password( string $value, bool $in_table = false ): bool {
		$lower = strtolower( $value );
		if ( strlen( $value ) < 6
			|| false !== strpos( $lower, 'redacted' )
			|| false !== strpos( $lower, '://' )
			|| 0 === strpos( $lower, 'www.' )
			|| preg_match( '/^(?:\*|•|·|x|\.|#|-|_|…)+$/i', $value )
			|| preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $value )
			|| preg_match( '/^[\[{(%*|<].*[\]})%*|>]$/', $value )
			|| preg_match( '/^(\S)\1+$/', $value )
			|| preg_match( '/^\d{1,4}[\-.\/]\d{1,2}[\-.\/]\d{1,4}$|^\d{1,2}:\d{2}/', $value ) // Dates and times.
			|| in_array( $lower, self::NOT_A_PASSWORD, true )
		) {
			return false;
		}
		// Digits or symbols, mixed case inside the word, or a value in a "Password" table cell.
		return 1 === preg_match( '/\d|[^A-Za-z0-9\x80-\xFF.\-_()]/', $value )
			|| ( 1 === preg_match( '/^.+[A-Z]/', $value ) && 1 === preg_match( '/[a-z]/', $value ) )
			|| $in_table;
	}

	/**
	 * Payment card numbers: 13–19 digits (groups of 4 or Amex/Diners grouping), card-like IIN and
	 * length, Luhn check. Ungrouped numbers only count with a card word right before them; IBANs never.
	 *
	 * @return array<int,array{kind:string,hint:string,start:int,length:int}>
	 */
	private static function cards( string $text ): array {
		if ( ! preg_match_all( '/(?<![\w.,\/+\-])(?<!\d[ \-])[3-6]\d{3}(?:[ \-]?\d){9,15}(?!\w|[.,\/ \-]\d)/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$hits = array();
		foreach ( $m[0] as $match ) {
			list( $raw, $start ) = $match;
			if ( ! self::is_card( $raw ) ) {
				continue;
			}
			$before = substr( $text, max( 0, $start - 80 ), min( 80, $start ) );
			// An IBAN like "DE89 3704 0044 …" starts with country code and check digits.
			if ( preg_match( '/[A-Z]{2}\d{2}[ \-]?$/', $before ) ) {
				continue;
			}
			if ( self::digits_only( $raw ) && ! preg_match( '/(?:card|karte|kart|visa|mastercard|master card|amex|american express|carte|tarjeta|kaart|\bcc\b|\bpan\b)[^\d]{0,40}$/i', $before ) ) {
				continue;
			}
			$digits = (string) preg_replace( '/\D/', '', $raw );
			$hits[] = array(
				'kind'   => self::CARD,
				'hint'   => '…' . substr( $digits, -4 ),
				'start'  => $start,
				'length' => strlen( $raw ),
			);
		}
		return $hits;
	}

	/** Grouping, IIN + length and Luhn of a candidate like "4111 1111 1111 1111". */
	public static function is_card( string $raw ): bool {
		$digits = (string) preg_replace( '/\D/', '', $raw );
		$length = strlen( $digits );
		if ( $length < 13 || $length > 19 || preg_match( '/^(\d)\1+$/', $digits ) ) {
			return false;
		}
		if ( ! self::digits_only( $raw ) ) {
			$groups = (array) preg_split( '/[ \-]/', $raw );
			$sizes  = implode( ',', array_map( 'strlen', array_map( 'strval', $groups ) ) );
			if ( ( false !== strpos( $raw, ' ' ) && false !== strpos( $raw, '-' ) )
				|| ! preg_match( '/^(?:4,4,4,[1-4]|4,4,4,4,[1-3]|4,6,5|4,6,4)$/', $sizes ) ) {
				return false;
			}
		}
		$two   = (int) substr( $digits, 0, 2 );
		$three = (int) substr( $digits, 0, 3 );
		$four  = (int) substr( $digits, 0, 4 );
		if ( '4' === $digits[0] ) {
			$ok = in_array( $length, array( 13, 16, 19 ), true ); // Visa.
		} elseif ( $two >= 51 && $two <= 55 ) {
			$ok = 16 === $length; // Mastercard.
		} elseif ( 34 === $two || 37 === $two ) {
			$ok = 15 === $length; // American Express.
		} elseif ( 36 === $two || 38 === $two || ( $three >= 300 && $three <= 305 ) ) {
			$ok = $length >= 14 && $length <= 16; // Diners Club.
		} elseif ( $four >= 3528 && $four <= 3589 ) {
			$ok = $length >= 16; // JCB.
		} elseif ( 6011 === $four || 65 === $two || ( $three >= 644 && $three <= 649 ) || 62 === $two ) {
			$ok = $length >= 16; // Discover, UnionPay.
		} else {
			$ok = false;
		}
		return $ok && self::luhn( $digits );
	}

	private static function digits_only( string $text ): bool {
		return 1 === preg_match( '/^\d+$/', $text );
	}

	public static function luhn( string $digits ): bool {
		$sum    = 0;
		$double = false;
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];
			if ( $double ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum   += $digit;
			$double = ! $double;
		}
		return 0 === $sum % 10;
	}
}
