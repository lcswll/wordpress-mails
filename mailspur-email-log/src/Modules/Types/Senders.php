<?php
/**
 * New senders: a plugin, theme or other source that never sent an email before starts sending. Its types are
 * marked "New sender" for a week; with the type alerts enabled, a new sender that writes to many different
 * external addresses within a day triggers one alert (a hacked site often sends through a fresh backdoor –
 * the emergency brake watches the volume, this watches the origin).
 *
 * State (option, a few hundred bytes): when Mailspur started watching, the first email per sender and the
 * senders already alerted. The first two weeks are the baseline – on a fresh install, after an import or an
 * update every sender is already "known", so nothing is new. Recipients are only counted, never stored.
 *
 * Direct queries: the plugin's own table, from cron only.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Senders {

	const OPTION = 'mailspur_types_senders';

	/** Days after Mailspur started watching in which no sender counts as new. */
	const BASELINE_DAYS = 14;

	/** Days a sender counts as new after its first email. */
	const NEW_DAYS = 7;

	/** Different external addresses within WINDOW from which a new sender alerts. */
	const ALERT_MIN = 25;

	const WINDOW = DAY_IN_SECONDS;

	/** Log rows read per new sender for the check. */
	const MAX_ROWS = 5000;

	/** Senders remembered (oldest first out). */
	const MAX_KNOWN = 500;

	/** @var callable():int */
	private $now;

	/** @param (callable():int)|null $now */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	/**
	 * @return array{since:int,known:array<string,int>,alerted:array<string,int>}
	 */
	public static function state(): array {
		return self::normalize( get_option( self::OPTION, array() ) );
	}

	/**
	 * @param array{since:int,known:array<string,int>,alerted:array<string,int>} $state
	 */
	public static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * @param mixed $state Stored state.
	 * @return array{since:int,known:array<string,int>,alerted:array<string,int>}
	 */
	public static function normalize( $state ): array {
		$state = is_array( $state ) ? $state : array();
		return array(
			'since'   => (int) ( $state['since'] ?? 0 ),
			'known'   => isset( $state['known'] ) && is_array( $state['known'] ) ? array_map( 'intval', $state['known'] ) : array(),
			'alerted' => isset( $state['alerted'] ) && is_array( $state['alerted'] ) ? array_map( 'intval', $state['alerted'] ) : array(),
		);
	}

	/**
	 * Remembers a sender's first email. The very first call starts the baseline.
	 *
	 * @param array{since:int,known:array<string,int>,alerted:array<string,int>} $state
	 * @param int                                                                $time Unix time of the email.
	 * @param int                                                                $now  Unix time.
	 * @return array{since:int,known:array<string,int>,alerted:array<string,int>}
	 */
	public static function observe( array $state, string $source, int $time, int $now ): array {
		if ( $state['since'] <= 0 ) {
			$state['since'] = $now;
		}
		if ( ! isset( $state['known'][ $source ] ) || $time < $state['known'][ $source ] ) {
			$state['known'][ $source ] = $time;
			if ( count( $state['known'] ) > self::MAX_KNOWN ) {
				asort( $state['known'] );
				$state['known'] = array_slice( $state['known'], -self::MAX_KNOWN, null, true );
			}
		}
		return $state;
	}

	/**
	 * Senders whose first email came after the baseline and less than NEW_DAYS ago.
	 *
	 * @param array{since:int,known:array<string,int>,alerted:array<string,int>} $state
	 * @return array<string,int> First email (unix time) by source.
	 */
	public static function fresh( array $state, int $now ): array {
		if ( $state['since'] <= 0 ) {
			return array();
		}
		$out = array();
		foreach ( $state['known'] as $source => $first ) {
			if ( $first >= $state['since'] + self::BASELINE_DAYS * DAY_IN_SECONDS && $now - $first < self::NEW_DAYS * DAY_IN_SECONDS ) {
				$out[ (string) $source ] = $first;
			}
		}
		return $out;
	}

	/**
	 * Different external addresses in a set of recipient lists: not at the site's domain (or a subdomain of
	 * it) and not one of the administrators.
	 *
	 * @param string[] $lists  Recipient columns.
	 * @param string   $host   The site's host, e.g. "example.com".
	 * @param string[] $admins Lower-cased administrator addresses.
	 */
	public static function external( array $lists, string $host, array $admins ): int {
		$host = (string) preg_replace( '/^www\./', '', strtolower( $host ) );
		$seen = array();
		foreach ( $lists as $list ) {
			foreach ( Repository::extract_emails( (string) $list ) as $email ) {
				$domain = (string) substr( (string) strrchr( $email, '@' ), 1 );
				if ( '' === $domain || in_array( $email, $admins, true ) || ( '' !== $host && ( $domain === $host || substr( $domain, -strlen( $host ) - 1 ) === '.' . $host ) ) ) {
					continue;
				}
				$seen[ $email ] = true;
			}
		}
		return count( $seen );
	}

	/**
	 * Cron (after indexing, type alerts enabled): one alert per new sender that writes to many external addresses.
	 *
	 * @param callable            $dispatch Alerts::dispatch( $type, $kind, $message, $settings ).
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string[] Sources alerted (for tests).
	 */
	public function check( callable $dispatch, array $settings ): array {
		global $wpdb;
		$now   = (int) call_user_func( $this->now );
		$state = self::state();
		$fresh = self::fresh( $state, $now );
		$done  = array();

		$state['alerted'] = array_intersect_key( $state['alerted'], $fresh );
		$admins           = null;
		foreach ( $fresh as $source => $first ) {
			if ( isset( $state['alerted'][ $source ] ) ) {
				continue;
			}
			$lists = (array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT recipients FROM %i WHERE source = %s AND created_at >= %s LIMIT %d',
					Repository::table(),
					$source,
					gmdate( 'Y-m-d H:i:s', $now - self::WINDOW ),
					self::MAX_ROWS
				)
			);
			if ( count( $lists ) < self::ALERT_MIN ) {
				continue; // Fewer emails than the threshold cannot reach it.
			}
			$admins = $admins ?? Noise::addresses();
			$count  = self::external( $lists, (string) wp_parse_url( home_url(), PHP_URL_HOST ), $admins );
			if ( $count < self::ALERT_MIN ) {
				continue;
			}
			call_user_func( $dispatch, 'sender', 'alert', self::message( $source, $first, $count ), $settings );
			$state['alerted'][ $source ] = $now;
			$done[]                      = $source;
		}
		self::save( $state );
		return $done;
	}

	/** Alert text: the sender and the numbers, never recipients or contents. */
	public static function message( string $source, int $first, int $count ): string {
		return sprintf(
			/* translators: 1: plugin/theme name, 2: date, 3: number of addresses */
			__( '%1$s sent its first email on %2$s and has written to %3$s different external addresses within the last 24 hours. If you did not install or set up anything that sends such emails, check the site: a new sender can be a sign of a hacked site.', 'mailspur-email-log' ),
			Report::source_label( $source ),
			(string) wp_date( (string) get_option( 'date_format' ), $first ),
			number_format_i18n( $count )
		);
	}
}
