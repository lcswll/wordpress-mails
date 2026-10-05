<?php
/**
 * Dead links to the site itself: a page was renamed or deleted, and the welcome email still points to
 * the old address. Runs only when an entry is opened (Dynamic), never while a mail is sent.
 *
 * Only links to this site's own host are checked, and only page-like ones (no admin/login/action/key
 * URLs, no files, no unknown query parameters). A link is reported only when the site itself answers
 * "404 Not Found" or "410 Gone":
 *   1. url_to_postid() finds a published post or page → alive, no request at all.
 *   2. Otherwise one anonymous HEAD request to the own site (loopback, like Site Health does), without
 *      following redirects to other hosts. 404/410 = dead; 2xx or a redirect elsewhere = alive;
 *      anything else (401, 403, 5xx, timeout, loopback blocked) = unknown, so no note.
 * Results are cached per URL for a day; a failing loopback pauses all checks for an hour.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

defined( 'ABSPATH' ) || exit;

final class Links {

	/** Distinct links checked per mail. */
	const MAX_URLS = 10;

	/** Dead-link notes per mail. */
	const MAX_NOTES = 3;

	/** Seconds per request and for all requests of one opened entry. */
	const TIMEOUT = 3;
	const BUDGET  = 6;

	/** Redirects followed on the own host. */
	const MAX_HOPS = 3;

	const TRANSIENT_PREFIX = 'mailspur_notes_link_';
	const LOOPBACK_DOWN    = 'mailspur_notes_loopback_down';

	/** Query parameters that only track and never change the page. */
	const TRACKING = '/^(?:utm_[a-z_]+|mc_cid|mc_eid|fbclid|gclid|msclkid|_ga|_gl|mtm_[a-z_]+|pk_[a-z_]+)$/';

	/** Query parameters of WordPress' plain permalinks (?p=12, ?page_id=7 …). */
	const PERMALINK_QUERY = array( 'p', 'page_id', 'cat', 'tag' );

	/**
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function notes( Mail $mail ): array {
		$urls = self::candidates( $mail );
		if ( ! $urls ) {
			return array();
		}
		$notes    = array();
		$deadline = microtime( true ) + self::BUDGET;
		foreach ( $urls as $url => $label ) {
			if ( 'dead' === self::state( $url, $deadline ) ) {
				$notes[] = Rules::note( 'dead_link', Rules::WARNING, array( $label ) );
				if ( count( $notes ) >= self::MAX_NOTES ) {
					break;
				}
			}
		}
		return $notes;
	}

	/**
	 * Own-site links worth checking: normalized URL (on the canonical host) => path shown in the note.
	 *
	 * @return array<string,string>
	 */
	public static function candidates( Mail $mail ): array {
		$text = $mail->text();
		if ( false === stripos( $text, 'http' ) ) {
			return array();
		}
		if ( $mail->is_html ) {
			preg_match_all( '#<a\b[^>]*?\shref\s*=\s*(["\']?)\s*(https?://[^"\'\s<>]+)#i', $text, $m );
			$found = array_map(
				static function ( string $url ): string {
					return html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
				},
				$m[2]
			);
		} else {
			preg_match_all( '#https?://[^\s<>"\']+#i', $text, $m );
			$found = array_map(
				static function ( string $url ): string {
					return rtrim( $url, '.,;:!?)]}' );
				},
				$m[0]
			);
		}

		$origins = null;
		$out     = array();
		foreach ( array_slice( $found, 0, 300 ) as $url ) {
			if ( null === $origins ) {
				$origins = self::origins();
				if ( ! $origins ) {
					return array();
				}
			}
			$checked = self::checkable( $url, $origins );
			if ( null !== $checked ) {
				$out[ $checked[0] ] = $checked[1];
				if ( count( $out ) >= self::MAX_URLS ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * This site's own hosts: bare host (no www., no port) => array( origin to request, port or 0 ).
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function origins(): array {
		$origins = array();
		foreach ( array( site_url(), home_url() ) as $base ) {
			$parts = wp_parse_url( (string) $base );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$port   = (int) ( $parts['port'] ?? 0 );
			$scheme = 'http' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 'http' : 'https';
			$host   = strtolower( (string) $parts['host'] );

			$origins[ Rules::bare_host( $host ) ] = array( $scheme . '://' . $host . ( $port ? ':' . $port : '' ), $port );
		}
		return $origins;
	}

	/**
	 * The URL to request and the label for the note, or null when the link is not checked.
	 *
	 * @param array<string,array{0:string,1:int}> $origins
	 * @return array{0:string,1:string}|null
	 */
	public static function checkable( string $url, array $origins ) {
		if ( strlen( $url ) > 2000 || false !== stripos( $url, 'redacted' ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$bare = Rules::bare_host( (string) $parts['host'] );
		$port = (int) ( $parts['port'] ?? 0 );
		if ( ! isset( $origins[ $bare ] ) ) {
			return null;
		}
		$own_port = $origins[ $bare ][1];
		if ( $port !== $own_port && ! ( 0 === $own_port && in_array( $port, array( 80, 443 ), true ) ) ) {
			return null; // Another service on the same host name.
		}

		$path  = (string) ( $parts['path'] ?? '' );
		$path  = '' === $path ? '/' : $path;
		$lower = strtolower( rawurldecode( $path ) );
		if ( preg_match( '#/wp-(?:admin|content|includes|json)(?:/|$)|/wp-[a-z-]+\.php|/xmlrpc\.php|/feed/?$|/(?:cart|checkout|logout|unsubscribe|abmelden)(?:/|$)#', $lower ) ) {
			return null; // Admin, login, files, API, feeds and action pages.
		}
		if ( preg_match( '#\.([a-z0-9]{1,5})$#', $lower, $ext ) && ! in_array( $ext[1], array( 'html', 'htm' ), true ) ) {
			return null; // Downloads and other files.
		}

		$query = array();
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			parse_str( (string) $parts['query'], $params );
			foreach ( $params as $key => $value ) {
				$key = (string) $key;
				if ( preg_match( self::TRACKING, strtolower( $key ) ) ) {
					continue;
				}
				// Keys, nonces, actions, tokens, add-to-cart, downloads …: never request those.
				if ( ! in_array( $key, self::PERMALINK_QUERY, true ) || ! is_scalar( $value ) || ! preg_match( '/^[a-z0-9_-]{1,100}$/i', (string) $value ) ) {
					return null;
				}
				$query[ $key ] = (string) $value;
			}
		}

		if ( '/' === $path && ! $query ) {
			return null; // The front page exists.
		}
		$suffix = $query ? '?' . http_build_query( $query ) : '';
		$label  = $path . $suffix;
		if ( strlen( $label ) > 120 ) {
			$label = substr( $label, 0, 120 ) . '…';
		}
		return array( $origins[ $bare ][0] . $path . $suffix, $label );
	}

	/**
	 * 'ok', 'dead' or 'unknown'.
	 *
	 * @param float $deadline microtime() after which no more requests are made.
	 */
	public static function state( string $url, float $deadline = 0.0 ): string {
		$key    = self::TRANSIENT_PREFIX . md5( $url );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && in_array( $cached, array( 'ok', 'dead', 'unknown' ), true ) ) {
			return $cached;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
			set_transient( $key, 'ok', DAY_IN_SECONDS );
			return 'ok';
		}

		if ( get_transient( self::LOOPBACK_DOWN ) || ( $deadline > 0 && microtime( true ) > $deadline ) ) {
			return 'unknown';
		}
		$state = self::request( $url );
		if ( null === $state ) {
			return 'unknown';
		}
		set_transient( $key, $state, 'unknown' === $state ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
		return $state;
	}

	/**
	 * HEAD request to the own site, following redirects only while they stay on the own host.
	 *
	 * @return string|null 'ok', 'dead', 'unknown', or null when the loopback itself failed.
	 */
	private static function request( string $url ): ?string {
		$origins = self::origins();
		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			// Never contact another host: the URL must be on the own site and pass WordPress' URL check.
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' === $host || ! isset( $origins[ Rules::bare_host( $host ) ] ) || ! wp_http_validate_url( $url ) ) {
				return 'unknown';
			}
			$response = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					// Same as WordPress' own loopback requests (Site Health, cron): local certificates may be self-signed.
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
					'user-agent'  => 'Mailspur link check; ' . home_url( '/' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				set_transient( self::LOOPBACK_DOWN, 1, HOUR_IN_SECONDS );
				return null;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $code || 410 === $code ) {
				return 'dead';
			}
			if ( $code >= 200 && $code < 300 ) {
				return 'ok';
			}
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return 'unknown';
			}
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = trim( is_array( $location ) ? (string) reset( $location ) : $location );
			if ( '' === $location ) {
				return 'unknown';
			}
			if ( 0 === strpos( $location, '/' ) && 0 !== strpos( $location, '//' ) ) {
				$location = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $url ) . $location;
			}
			$next = (string) wp_parse_url( $location, PHP_URL_HOST );
			if ( '' === $next || ! isset( $origins[ Rules::bare_host( $next ) ] ) ) {
				return 'ok'; // Redirects to another site: the link leads somewhere.
			}
			$url = $location;
		}
		return 'unknown';
	}
}
