<?php
/**
 * Sender check: SPF, DKIM, DMARC and MX of the domains the site sends from.
 *
 * Uses PHP's own resolver (dns_get_record()) only – no external service. Only runs on demand (REST, admin),
 * never while a mail is being sent. The evaluate_* methods are pure, so they are unit-tested with fixtures.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class SenderCheck {

	const TRANSIENT = 'mailspur_delivery_check_';

	/** Common DKIM selectors of mail providers and hosting panels. */
	const SELECTORS = array( 'default', 'google', 'selector1', 'selector2', 'k1', 'k2', 'k3', 's1', 's2', 'mail', 'dkim', 'smtp', 'mx', 'mandrill', 'mxvault', 'zoho', 'sendgrid', 'amazonses', 'mailjet', 'sig1', 'fm1', 'protonmail' );

	/** RFC 7208 limit of DNS-querying SPF terms. */
	const SPF_LOOKUP_LIMIT = 10;

	/** Seconds the whole check may spend on DNS lookups. */
	const TIME_BUDGET = 20.0;

	/** Upper bound of lookups per run (selectors × domains + SPF includes). */
	const MAX_LOOKUPS = 200;

	/** @var callable(string,string):(array<int,array<string,mixed>>|null) */
	private $resolver;

	/** @var float */
	private $deadline = 0.0;

	/** @var int */
	private $lookups = 0;

	/** @var bool */
	private $timed_out = false;

	/**
	 * @param callable(string,string):(array<int,array<string,mixed>>|null)|null $resolver ( host, 'TXT'|'MX' ) → records, null on failure.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ? $resolver : array( self::class, 'dns' );
	}

	public static function available(): bool {
		return function_exists( 'dns_get_record' );
	}

	/**
	 * Resolver backed by dns_get_record(). Warnings (timeouts, SERVFAIL) are swallowed and reported as null.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function dns( string $host, string $type ) {
		if ( ! self::available() ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- turns resolver warnings into a "lookup failed" result.
		set_error_handler( '__return_true' );
		try {
			$records = dns_get_record( $host, 'MX' === $type ? DNS_MX : DNS_TXT );
		} catch ( \Throwable $e ) {
			$records = false;
		} finally {
			restore_error_handler();
		}
		return is_array( $records ) ? $records : null;
	}

	/**
	 * Sender domains: the default From address (wp_mail_from) plus the most frequent From domains of the
	 * last 200 log entries.
	 *
	 * @return array<string,array{default:bool,count:int}> domain => where it was seen.
	 */
	public function domains( int $top = 5 ): array {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		// Same default and filter as wp_mail(), so plugins that change the sender are respected.
		$from = (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$out     = array();
		$default = self::domain_of( $from );
		if ( '' !== $default ) {
			$out[ $default ] = array(
				'default' => true,
				'count'   => 0,
			);
		}

		$counts = array();
		foreach ( $this->recent_senders() as $sender ) {
			$domain = self::domain_of( $sender );
			if ( '' !== $domain ) {
				$counts[ $domain ] = ( $counts[ $domain ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		foreach ( array_slice( $counts, 0, $top, true ) as $domain => $count ) {
			$out[ $domain ] = array(
				'default' => isset( $out[ $domain ] ),
				'count'   => $count,
			);
		}
		return $out;
	}

	/**
	 * @return array<int,string>
	 */
	private function recent_senders(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table, read on demand.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT sender FROM %i ORDER BY id DESC LIMIT %d', Repository::table(), 200 ), ARRAY_A );
		return array_map( 'strval', array_column( (array) $rows, 'sender' ) );
	}

	/** Lower-case domain of "Name <user@example.com>" / "user@example.com", '' if none. */
	public static function domain_of( string $address ): string {
		if ( preg_match( '/<([^>]*)>/', $address, $m ) ) {
			$address = $m[1];
		}
		$at = strrpos( $address, '@' );
		if ( false === $at ) {
			return '';
		}
		$domain = strtolower( rtrim( trim( substr( $address, $at + 1 ) ), '.' ) );
		return self::is_domain( $domain ) ? $domain : '';
	}

	/** A public DNS name (at least one dot, valid labels, alphabetic TLD) – excludes IPs and "localhost". */
	public static function is_domain( string $domain ): bool {
		return strlen( $domain ) <= 253 && (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $domain );
	}

	/**
	 * Checks all domains (or returns the cached result of the last hour).
	 *
	 * @param array<string,array{default:bool,count:int}> $domains From domains().
	 * @return array<string,mixed>
	 */
	public function run( array $domains, string $selector = '', bool $force = false ): array {
		$key = self::cache_key( $domains, $selector );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$result = array(
			'available'  => self::available(),
			'checked_at' => time(),
			'cached'     => false,
			'timed_out'  => false,
			'selector'   => $selector,
			'domains'    => array(),
		);
		if ( $result['available'] ) {
			$this->deadline = microtime( true ) + self::TIME_BUDGET;
			foreach ( $domains as $domain => $seen ) {
				$result['domains'][] = array_merge(
					array(
						'domain'  => (string) $domain,
						'default' => (bool) $seen['default'],
						'count'   => (int) $seen['count'],
					),
					array( 'checks' => $this->check_domain( (string) $domain, $selector ) )
				);
			}
			$result['timed_out'] = $this->timed_out;
		}

		set_transient( $key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Cached result for these domains, if any.
	 *
	 * @param array<string,array{default:bool,count:int}> $domains
	 * @return array<string,mixed>|null
	 */
	public static function cached( array $domains, string $selector = '' ): ?array {
		$cached = get_transient( self::cache_key( $domains, $selector ) );
		if ( ! is_array( $cached ) ) {
			return null;
		}
		$cached['cached'] = true;
		return $cached;
	}

	/**
	 * @param array<string,array{default:bool,count:int}> $domains
	 */
	private static function cache_key( array $domains, string $selector ): string {
		return self::TRANSIENT . md5( implode( ',', array_keys( $domains ) ) . '|' . strtolower( $selector ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function check_domain( string $domain, string $selector = '' ): array {
		$spf_txt = $this->lookup( $domain, 'TXT' );
		$spf     = self::spf_records( $spf_txt );
		$count   = 1 === count( $spf ) ? $this->spf_lookups( $spf[0], array( $domain ) ) : 0;

		$selectors = self::SELECTORS;
		if ( '' !== $selector ) {
			array_unshift( $selectors, $selector );
		}
		$dkim = array();
		foreach ( array_unique( $selectors ) as $name ) {
			$records = $this->lookup( $name . '._domainkey.' . $domain, 'TXT' );
			if ( null === $records ) {
				continue;
			}
			foreach ( self::txt_values( $records ) as $txt ) {
				if ( preg_match( '/(^|;)\s*p\s*=/i', $txt ) ) {
					$dkim[ $name ] = $txt;
					break;
				}
			}
		}

		return array(
			self::evaluate_spf( null === $spf_txt ? null : $spf, $count, $domain ),
			self::evaluate_dmarc( self::nullable_txt( $this->lookup( '_dmarc.' . $domain, 'TXT' ) ), $domain ),
			self::evaluate_dkim( $dkim, $this->timed_out, $selector ),
			self::evaluate_mx( $this->lookup( $domain, 'MX' ) ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>|null
	 */
	private function lookup( string $host, string $type ): ?array {
		if ( $this->lookups >= self::MAX_LOOKUPS || ( $this->deadline > 0 && microtime( true ) > $this->deadline ) ) {
			$this->timed_out = true;
			return null;
		}
		++$this->lookups;
		$records = call_user_func( $this->resolver, $host, $type );
		return is_array( $records ) ? $records : null;
	}

	/**
	 * @param array<int,array<string,mixed>>|null $records
	 * @return array<int,string>|null
	 */
	private static function nullable_txt( ?array $records ): ?array {
		return null === $records ? null : self::txt_values( $records );
	}

	/**
	 * TXT strings of dns_get_record() results; long records arrive split into 255-byte chunks ("entries").
	 *
	 * @param array<int,array<string,mixed>> $records
	 * @return array<int,string>
	 */
	public static function txt_values( array $records ): array {
		$out = array();
		foreach ( $records as $record ) {
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$out[] = implode( '', array_map( 'strval', $record['entries'] ) );
			} elseif ( isset( $record['txt'] ) ) {
				$out[] = (string) $record['txt'];
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>>|null $records
	 * @return array<int,string>
	 */
	public static function spf_records( ?array $records ): array {
		return array_values(
			array_filter(
				self::txt_values( (array) $records ),
				static function ( string $txt ): bool {
					return (bool) preg_match( '/^v=spf1(\s|$)/i', trim( $txt ) );
				}
			)
		);
	}

	/**
	 * Estimates the DNS lookups a receiver needs to evaluate an SPF record (include, a, mx, ptr, exists,
	 * redirect – recursively through includes). Stops counting deeper once the limit is clearly exceeded.
	 *
	 * @param array<int,string> $seen Domains already expanded (loop protection).
	 */
	public function spf_lookups( string $record, array $seen, int $depth = 0 ): int {
		$count = 0;
		$terms = preg_split( '/\s+/', trim( $record ) );
		foreach ( $terms ? $terms : array() as $term ) {
			$term = strtolower( ltrim( $term, '+-~?' ) );
			$next = '';
			if ( 0 === strpos( $term, 'include:' ) ) {
				$next = (string) substr( $term, 8 );
			} elseif ( 0 === strpos( $term, 'redirect=' ) ) {
				$next = (string) substr( $term, 9 );
			} elseif ( preg_match( '/^(a|mx|ptr|exists)([:\/]|$)/', $term ) ) {
				++$count;
				continue;
			} else {
				continue;
			}
			++$count;
			// Macros (%{i}) are expanded per message and cannot be followed here.
			if ( '' === $next || false !== strpos( $next, '%' ) || in_array( $next, $seen, true ) || $depth >= 5 || $count > self::SPF_LOOKUP_LIMIT * 2 ) {
				continue;
			}
			$seen[]  = $next;
			$records = self::spf_records( $this->lookup( $next, 'TXT' ) );
			if ( $records ) {
				$count += $this->spf_lookups( $records[0], $seen, $depth + 1 );
			}
		}
		return $count;
	}

	/**
	 * @param array<int,string>|null $records SPF records of the domain, null when the lookup failed.
	 * @param int                    $lookups Estimated DNS lookups (spf_lookups()).
	 * @return array<string,mixed>
	 */
	public static function evaluate_spf( ?array $records, int $lookups, string $domain ): array {
		$check = self::check( 'spf', 'SPF' );
		if ( null === $records ) {
			return self::failed( $check );
		}

		$check['suggestion_host'] = $domain;
		if ( ! $records ) {
			$check['status']     = 'bad';
			$check['summary']    = __( 'No SPF record found. Receivers cannot tell which servers may send for this domain.', 'mailspur-email-log' );
			$check['suggestion'] = 'v=spf1 a mx ~all';
			$check['notes'][]    = __( 'Add the include: of your mail provider (e.g. include:_spf.google.com) if you send through one.', 'mailspur-email-log' );
			return $check;
		}

		$check['record'] = implode( "\n", $records );
		if ( count( $records ) > 1 ) {
			$check['status']  = 'bad';
			$check['summary'] = __( 'Multiple SPF records found. Receivers treat this as an error (PermError) – merge them into one record.', 'mailspur-email-log' );
			return $check;
		}

		$record = trim( $records[0] );
		$all    = preg_match( '/(?:^|\s)([+\-~?]?)all(?:\s|$)/i', $record, $m ) ? ( '' === $m[1] ? '+' : $m[1] ) : '';

		switch ( $all ) {
			case '-':
				$check['status']  = 'ok';
				$check['summary'] = __( 'SPF record found. Other servers are rejected (-all).', 'mailspur-email-log' );
				break;
			case '~':
				$check['status']  = 'ok';
				$check['summary'] = __( 'SPF record found. Other servers are marked as suspicious (~all, softfail).', 'mailspur-email-log' );
				$check['notes'][] = __( '-all is stricter; ~all is common and fine together with DMARC.', 'mailspur-email-log' );
				break;
			case '?':
				$check['status']     = 'warn';
				$check['summary']    = __( 'SPF record ends with ?all (neutral): it does not protect the domain against spoofing.', 'mailspur-email-log' );
				$check['suggestion'] = (string) preg_replace( '/(^|\s)\?all(\s|$)/i', '$1~all$2', $record );
				break;
			case '+':
				$check['status']     = 'bad';
				$check['summary']    = __( 'SPF record allows every server in the world to send for this domain (+all).', 'mailspur-email-log' );
				$check['suggestion'] = (string) preg_replace( '/(^|\s)\+?all(\s|$)/i', '$1~all$2', $record );
				break;
			default:
				$check['status']  = false !== stripos( $record, 'redirect=' ) ? 'ok' : 'warn';
				$check['summary'] = 'ok' === $check['status']
					? __( 'SPF record found (redirect= to another policy).', 'mailspur-email-log' )
					: __( 'SPF record has no "all" term, so mail from other servers is treated as neutral.', 'mailspur-email-log' );
				if ( 'warn' === $check['status'] ) {
					$check['suggestion'] = $record . ' ~all';
				}
		}

		if ( $lookups > self::SPF_LOOKUP_LIMIT ) {
			$check['status']  = 'bad';
			$check['notes'][] = sprintf(
				/* translators: 1: estimated number of DNS lookups, 2: allowed maximum (10) */
				__( 'About %1$s DNS lookups are needed to evaluate this record – more than the allowed %2$s, so receivers may ignore it (PermError). Remove unused include: entries.', 'mailspur-email-log' ),
				$lookups,
				self::SPF_LOOKUP_LIMIT
			);
		} else {
			$check['notes'][] = sprintf(
				/* translators: 1: estimated number of DNS lookups, 2: allowed maximum (10) */
				__( 'DNS lookups needed: about %1$s of %2$s allowed.', 'mailspur-email-log' ),
				$lookups,
				self::SPF_LOOKUP_LIMIT
			);
		}
		return $check;
	}

	/**
	 * @param array<int,string>|null $txt TXT records of _dmarc.<domain>, null when the lookup failed.
	 * @return array<string,mixed>
	 */
	public static function evaluate_dmarc( ?array $txt, string $domain ): array {
		$check = self::check( 'dmarc', 'DMARC' );
		if ( null === $txt ) {
			return self::failed( $check );
		}
		$check['suggestion_host'] = '_dmarc.' . $domain;

		$records = array_values(
			array_filter(
				$txt,
				static function ( string $value ): bool {
					return (bool) preg_match( '/^v\s*=\s*DMARC1\s*(;|$)/i', trim( $value ) );
				}
			)
		);
		if ( ! $records ) {
			$check['status']     = 'bad';
			$check['summary']    = __( 'No DMARC record found. Large mailbox providers expect one, and without it anyone can spoof this domain unnoticed.', 'mailspur-email-log' );
			$check['suggestion'] = 'v=DMARC1; p=none; rua=mailto:' . self::report_address( $domain );
			$check['notes'][]    = __( 'Start with p=none to receive reports, then tighten to quarantine or reject once SPF and DKIM pass.', 'mailspur-email-log' );
			return $check;
		}

		$check['record'] = implode( "\n", $records );
		if ( count( $records ) > 1 ) {
			$check['status']  = 'bad';
			$check['summary'] = __( 'Multiple DMARC records found. Receivers ignore DMARC in this case – keep only one.', 'mailspur-email-log' );
			return $check;
		}

		$tags   = self::tags( $records[0] );
		$policy = strtolower( $tags['p'] ?? '' );
		$pct    = isset( $tags['pct'] ) && is_numeric( $tags['pct'] ) ? (int) $tags['pct'] : 100;

		if ( 'reject' === $policy || 'quarantine' === $policy ) {
			$check['status']  = 'ok';
			$check['summary'] = sprintf(
				/* translators: %s: DMARC policy, "quarantine" or "reject" */
				__( 'DMARC record found with policy p=%s.', 'mailspur-email-log' ),
				$policy
			);
		} elseif ( 'none' === $policy ) {
			$check['status']     = 'warn';
			$check['summary']    = __( 'DMARC record found with p=none: reports only, spoofed mail is still delivered.', 'mailspur-email-log' );
			$check['suggestion'] = (string) preg_replace( '/(^|;)\s*p\s*=\s*none/i', '$1 p=quarantine', $records[0] );
			$check['notes'][]    = __( 'Once your reports show that legitimate mail passes, switch to p=quarantine (or p=reject).', 'mailspur-email-log' );
		} else {
			$check['status']     = 'bad';
			$check['summary']    = __( 'DMARC record has no valid policy (p=none, quarantine or reject).', 'mailspur-email-log' );
			$check['suggestion'] = 'v=DMARC1; p=none; rua=mailto:' . self::report_address( $domain );
		}

		if ( $pct < 100 && 'none' !== $policy ) {
			$check['status']  = 'warn';
			$check['notes'][] = sprintf(
				/* translators: %s: percentage */
				__( 'The policy only applies to %s%% of the mail (pct).', 'mailspur-email-log' ),
				$pct
			);
		}
		$check['notes'][] = isset( $tags['rua'] ) && '' !== $tags['rua']
			/* translators: %s: report address(es), e.g. mailto:dmarc@example.com */
			? sprintf( __( 'Aggregate reports go to %s.', 'mailspur-email-log' ), $tags['rua'] )
			: __( 'No report address (rua) – you will not see who sends in your name.', 'mailspur-email-log' );
		return $check;
	}

	/**
	 * @param array<string,string> $found    selector => DKIM key record.
	 * @param bool                 $partial  Lookups were cut short (time budget).
	 * @param string               $selector Selector the user entered.
	 * @return array<string,mixed>
	 */
	public static function evaluate_dkim( array $found, bool $partial, string $selector = '' ): array {
		$check = self::check( 'dkim', 'DKIM' );

		$active  = array();
		$revoked = array();
		foreach ( $found as $name => $record ) {
			$tags = self::tags( $record );
			if ( '' === ( $tags['p'] ?? '' ) ) {
				$revoked[] = (string) $name;
			} else {
				$active[] = (string) $name;
			}
		}

		if ( $active ) {
			$check['status']  = 'ok';
			$check['summary'] = sprintf(
				/* translators: %s: comma-separated DKIM selectors */
				__( 'DKIM key found (selector: %s).', 'mailspur-email-log' ),
				implode( ', ', $active )
			);
			$check['record']  = implode(
				"\n",
				array_map(
					static function ( string $name ) use ( $found ): string {
						return $name . ': ' . ( strlen( $found[ $name ] ) > 120 ? substr( $found[ $name ], 0, 117 ) . '…' : $found[ $name ] );
					},
					$active
				)
			);
			$check['notes'][] = __( 'A published key does not prove that your mails are signed with it – check the DKIM result in the headers of a received mail.', 'mailspur-email-log' );
		} else {
			$check['status']  = 'warn';
			$check['summary'] = __( 'No DKIM key found under the common selectors.', 'mailspur-email-log' );
			$check['notes'][] = __( 'Enable DKIM signing at your mail provider or host and publish the key it gives you. If you already sign, enter your selector (the s= value of the DKIM-Signature header) and check again.', 'mailspur-email-log' );
			if ( '' !== $selector ) {
				$check['notes'][] = sprintf(
					/* translators: %s: DKIM selector */
					__( 'Selector "%s" has no key either.', 'mailspur-email-log' ),
					$selector
				);
			}
		}
		if ( $revoked ) {
			$check['notes'][] = sprintf(
				/* translators: %s: comma-separated DKIM selectors */
				__( 'Revoked keys (empty p=): %s.', 'mailspur-email-log' ),
				implode( ', ', $revoked )
			);
		}
		if ( $partial && ! $active ) {
			$check['status']  = 'unknown';
			$check['notes'][] = __( 'Not all selectors could be checked in time.', 'mailspur-email-log' );
		}
		return $check;
	}

	/**
	 * @param array<int,array<string,mixed>>|null $records MX records, null when the lookup failed.
	 * @return array<string,mixed>
	 */
	public static function evaluate_mx( ?array $records ): array {
		$check = self::check( 'mx', 'MX' );
		if ( null === $records ) {
			return self::failed( $check );
		}
		$hosts = array();
		foreach ( $records as $record ) {
			if ( isset( $record['target'] ) && '' !== (string) $record['target'] ) {
				$hosts[ (string) $record['target'] ] = (int) ( $record['pri'] ?? 0 );
			}
		}
		if ( ! $hosts ) {
			$check['status']  = 'warn';
			$check['summary'] = __( 'No MX record: this domain cannot receive replies or bounces, and some receivers reject such senders.', 'mailspur-email-log' );
			return $check;
		}
		asort( $hosts );
		$check['status']  = 'ok';
		$check['summary'] = __( 'Mail servers for replies and bounces are set up.', 'mailspur-email-log' );
		$check['record']  = implode(
			"\n",
			array_map(
				static function ( string $host, int $priority ): string {
					return $priority . ' ' . $host;
				},
				array_keys( $hosts ),
				array_values( $hosts )
			)
		);
		return $check;
	}

	/**
	 * "k=v; k2=v2" → array( 'k' => 'v', … ) with lower-case keys.
	 *
	 * @return array<string,string>
	 */
	public static function tags( string $record ): array {
		$tags = array();
		foreach ( explode( ';', $record ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 === count( $pair ) ) {
				$tags[ strtolower( trim( $pair[0] ) ) ] = trim( $pair[1] );
			}
		}
		return $tags;
	}

	private static function report_address( string $domain ): string {
		$admin = (string) get_option( 'admin_email' );
		return self::domain_of( $admin ) === $domain ? $admin : 'dmarc-reports@' . $domain;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check( string $id, string $label ): array {
		return array(
			'id'              => $id,
			'label'           => $label,
			'status'          => 'unknown',
			'summary'         => '',
			'notes'           => array(),
			'record'          => '',
			'suggestion'      => '',
			'suggestion_host' => '',
		);
	}

	/**
	 * @param array<string,mixed> $check
	 * @return array<string,mixed>
	 */
	private static function failed( array $check ): array {
		$check['status']  = 'unknown';
		$check['summary'] = __( 'DNS lookup failed or timed out. Try again later.', 'mailspur-email-log' );
		return $check;
	}
}
