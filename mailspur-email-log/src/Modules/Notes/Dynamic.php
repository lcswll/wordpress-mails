<?php
/**
 * Dynamic notes: need DNS or the database, so they never run while a mail is sent. They are computed
 * when an entry is opened in the log (REST detail payload); DNS results are cached per domain for a day.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Dynamic {

	/** Window (seconds, either side) in which identical mails count as repeated. */
	const DUPLICATE_WINDOW = 600;

	/** Recipient domains looked up per opened entry (DNS can be slow). */
	const MAX_DOMAINS = 3;

	const TRANSIENT_PREFIX = 'mailspur_notes_mx_';

	/**
	 * @param array<string,mixed> $row Database row of the entry.
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function notes( array $row ): array {
		$notes = array();
		$mail  = new Mail( $row, Engine::site() );

		$looked_up = 0;
		foreach ( $mail->recipient_domains() as $domain ) {
			if ( Domains::is_reserved( $domain ) || ! self::valid_domain( $domain ) ) {
				continue;
			}
			if ( ++$looked_up > self::MAX_DOMAINS ) {
				break;
			}
			$state = self::mx_state( $domain );
			if ( 'none' === $state ) {
				$notes[] = Rules::note( 'no_mx', Rules::ERROR, array( $domain ) );
			} elseif ( 'null' === $state ) {
				$notes[] = Rules::note( 'null_mx', Rules::ERROR, array( $domain ) );
			}
		}

		$repeats = self::repeats( $row );
		if ( $repeats > 0 ) {
			$notes[] = Rules::note( 'duplicate', Rules::WARNING, array( $repeats, (int) ( self::DUPLICATE_WINDOW / 60 ) ) );
		}
		return $notes;
	}

	/**
	 * 'ok', 'none' (no MX and no A/AAAA), 'null' (null MX, RFC 7505) or 'unknown' (DNS unavailable).
	 */
	public static function mx_state( string $domain ): string {
		$domain = strtolower( $domain );

		/**
		 * Short-circuits the DNS lookup of a recipient domain (tests, hosts with their own resolver).
		 * Return 'ok', 'none', 'null' or 'unknown'; null runs the lookup.
		 *
		 * @param string|null $state
		 * @param string      $domain
		 */
		$pre = apply_filters( 'mailspur_notes_mx_lookup', null, $domain );
		if ( is_string( $pre ) ) {
			return in_array( $pre, array( 'ok', 'none', 'null' ), true ) ? $pre : 'unknown';
		}

		$key    = self::TRANSIENT_PREFIX . md5( $domain );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$state = self::lookup( $domain );
		if ( 'unknown' !== $state ) {
			set_transient( $key, $state, DAY_IN_SECONDS );
		}
		return $state;
	}

	private static function lookup( string $domain ): string {
		$host = $domain . '.'; // Fully qualified: no search-domain suffixes.

		if ( function_exists( 'dns_get_record' ) ) {
			$mx = self::quietly(
				static function () use ( $host ) {
					return dns_get_record( $host, DNS_MX );
				}
			);
			if ( ! is_array( $mx ) ) {
				return 'unknown'; // Resolver error: do not guess.
			}
			if ( $mx ) {
				$targets = array_unique(
					array_map(
						static function ( $record ): string {
							return is_array( $record ) ? rtrim( (string) ( $record['target'] ?? '' ), '.' ) : '';
						},
						$mx
					)
				);
				return array( '' ) === array_values( $targets ) ? 'null' : 'ok';
			}
			$a = self::quietly(
				static function () use ( $host ) {
					return dns_get_record( $host, DNS_A + DNS_AAAA );
				}
			);
			if ( ! is_array( $a ) ) {
				return 'unknown';
			}
			return $a ? 'ok' : 'none';
		}

		if ( function_exists( 'checkdnsrr' ) ) {
			$found = self::quietly(
				static function () use ( $host ): bool {
					return checkdnsrr( $host, 'MX' ) || checkdnsrr( $host, 'A' ) || checkdnsrr( $host, 'AAAA' );
				}
			);
			return true === $found ? 'ok' : 'none';
		}

		return 'unknown'; // DNS functions disabled on this host.
	}

	/**
	 * Number of OTHER entries with the same recipients and subject within ±DUPLICATE_WINDOW.
	 * One query on the created_at index; resends from the log are deliberate and not counted.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function repeats( array $row ): int {
		global $wpdb;
		$source = (string) ( $row['source'] ?? '' );
		$time   = strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' );
		if ( false === $time || 'mailspur:resend' === $source || '' === (string) ( $row['recipients'] ?? '' ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table, see Repository.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at BETWEEN %s AND %s AND id <> %d AND source <> %s AND recipients = %s AND subject = %s',
				Repository::table(),
				gmdate( 'Y-m-d H:i:s', $time - self::DUPLICATE_WINDOW ),
				gmdate( 'Y-m-d H:i:s', $time + self::DUPLICATE_WINDOW ),
				(int) ( $row['id'] ?? 0 ),
				'mailspur:resend',
				(string) $row['recipients'],
				(string) ( $row['subject'] ?? '' )
			)
		);
		return (int) $count;
	}

	private static function valid_domain( string $domain ): bool {
		return strlen( $domain ) <= 253 && 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain );
	}

	/**
	 * Runs a DNS call without letting its warnings ("A temporary server error occurred") reach the output.
	 *
	 * @param callable():mixed $lookup
	 * @return mixed
	 */
	private static function quietly( callable $lookup ) {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- swallow DNS warnings only for this call.
			static function (): bool {
				return true;
			}
		);
		try {
			return $lookup();
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			restore_error_handler();
		}
	}
}
