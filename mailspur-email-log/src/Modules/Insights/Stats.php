<?php
/**
 * Aggregated statistics of the mail log for a date range (site-local days).
 *
 * Query plan (MySQL/MariaDB, also fine on SQLite):
 *  1. Time series: `WHERE status IN (0,1,2,3) AND created_at >= a AND created_at < b GROUP BY hour, status`.
 *     The IN list on the leading column makes the composite index status_created(status, created_at) usable as
 *     four range scans, and because only status + created_at are read, the index is covering ("Using index",
 *     no row lookups). Grouping into UTC hours needs a small temporary table (≤ 24 × days × 4 groups).
 *     Hours are converted to site-local days/hours in PHP with wp_timezone(), so DST switches are correct.
 *  2. Previous period: the same covering range scan, grouped by status only.
 *  3. Top lists (sources, recipient domains, subjects) need the text columns, i.e. row lookups. To stay bounded on
 *     huge logs they read at most SAMPLE rows of the range (newest first, created_at index, LIMIT). The response
 *     says when the lists are based on a sample.
 * Results are cached in transients: 2 minutes for ranges that include today, 12 hours for past ranges. The cache
 * key contains a generation number that is bumped when entries are deleted or imported.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, cached in transients above.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Stats {

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/** Rows read at most for the top lists. */
	const SAMPLE = 20000;

	/** Longest range in days. */
	const MAX_DAYS = 731;

	/** Entries of each top list. */
	const TOP = 10;

	const GENERATION_OPTION = 'mailspur_insights_generation';

	/** Source written by the plugin's own alert mails. */
	const ALERT_SOURCE = 'mailspur:alert';

	/**
	 * Statistics for the site-local dates $from … $to (inclusive), cached.
	 *
	 * @return array<string,mixed>
	 */
	public function get( string $from, string $to ): array {
		$tz    = wp_timezone();
		$today = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$key   = 'mailspur_insights_' . md5( (string) wp_json_encode( array( $from, $to, $tz->getName(), (int) get_option( self::GENERATION_OPTION, 0 ), determine_locale() ) ) );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = $this->compute( $from, $to, $tz );
		set_transient( $key, $data, $to >= $today ? 2 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** Invalidates all cached statistics (one option write, old transients simply expire). */
	public static function flush(): void {
		update_option( self::GENERATION_OPTION, (int) get_option( self::GENERATION_OPTION, 0 ) + 1, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function compute( string $from, string $to, DateTimeZone $tz ): array {
		global $wpdb;

		list( $start, $end ) = self::utc_range( $from, $to, $tz );
		$days                = self::days_between( $from, $to );
		$prev_start          = gmdate( 'Y-m-d H:i:s', (int) strtotime( $start . ' UTC' ) - ( (int) strtotime( $end . ' UTC' ) - (int) strtotime( $start . ' UTC' ) ) );

		$buckets = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT SUBSTRING(created_at, 1, 13) AS h, status, COUNT(*) AS n FROM %i WHERE status IN (0,1,2,3) AND created_at >= %s AND created_at < %s GROUP BY h, status',
				$this->repository::table(),
				$start,
				$end
			),
			ARRAY_A
		);

		$previous = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM %i WHERE status IN (0,1,2,3) AND created_at >= %s AND created_at < %s GROUP BY status',
				$this->repository::table(),
				$prev_start,
				$start
			),
			ARRAY_A
		);

		$sample = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, status, SUBSTRING(recipients, 1, 500) AS recipients, SUBSTRING(subject, 1, 300) AS subject FROM %i WHERE created_at >= %s AND created_at < %s ORDER BY created_at DESC LIMIT %d',
				$this->repository::table(),
				$start,
				$end,
				self::SAMPLE
			),
			ARRAY_A
		);

		$data             = self::aggregate( $buckets, $from, $to, $tz );
		$data['range']    = array(
			'from'  => $from,
			'to'    => $to,
			'days'  => $days,
			'today' => ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' ),
		);
		$data['previous'] = self::previous( $previous, $from, $days );
		$data['top']      = self::top_lists( $sample );
		$data['sample']   = array(
			'rows'    => count( $sample ),
			'limited' => count( $sample ) >= self::SAMPLE && $data['totals']['all'] > count( $sample ),
		);
		return $data;
	}

	/**
	 * UTC bounds [start, end) of the site-local dates $from … $to.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function utc_range( string $from, string $to, DateTimeZone $tz ): array {
		$utc   = new DateTimeZone( 'UTC' );
		$start = new DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end   = ( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->add( new DateInterval( 'P1D' ) );
		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	public static function days_between( string $from, string $to ): int {
		$utc = new DateTimeZone( 'UTC' );
		return (int) ( new DateTimeImmutable( $from, $utc ) )->diff( new DateTimeImmutable( $to, $utc ) )->days + 1;
	}

	/**
	 * Daily series, weekday × hour heatmap and totals from hourly UTC buckets.
	 *
	 * @param array<int,array<string,mixed>> $buckets Rows {h: "Y-m-d H" (UTC), status, n}.
	 * @return array{totals:array<string,int|float>,days:array<int,array<string,int|string>>,heatmap:array<int,array<int,int>>,busiest:array{date:string,count:int}|null,average:float}
	 */
	public static function aggregate( array $buckets, string $from, string $to, DateTimeZone $tz ): array {
		$zero   = array(
			'sent'    => 0,
			'failed'  => 0,
			'held'    => 0,
			'pending' => 0,
		);
		$counts = array();
		$day    = new DateTimeImmutable( $from, new DateTimeZone( 'UTC' ) );
		$last   = self::days_between( $from, $to );
		for ( $i = 0; $i < $last; $i++ ) {
			$counts[ $day->format( 'Y-m-d' ) ] = $zero;
			$day                               = $day->add( new DateInterval( 'P1D' ) );
		}

		$heatmap = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		$totals  = array_merge( array( 'all' => 0 ), $zero );

		foreach ( $buckets as $bucket ) {
			$time = strtotime( (string) $bucket['h'] . ':00:00 UTC' );
			if ( false === $time ) {
				continue;
			}
			$local = ( new DateTimeImmutable( '@' . $time ) )->setTimezone( $tz );
			// Time zones with a 30/45 minute offset: the first UTC hour starts before local midnight.
			$date = max( $from, min( $to, $local->format( 'Y-m-d' ) ) );
			$n    = (int) $bucket['n'];
			$slug = Repository::status_slug( (int) $bucket['status'] );
			$hour = (int) $local->format( 'G' );
			$wday = (int) $local->format( 'w' );

			$counts[ $date ][ $slug ]  += $n;
			$totals[ $slug ]           += $n;
			$totals['all']             += $n;
			$heatmap[ $wday ][ $hour ] += $n;
		}

		$busiest = null;
		$days    = array();
		foreach ( $counts as $date => $row ) {
			$days[] = array_merge( array( 'date' => (string) $date ), $row );
			$count  = array_sum( $row );
			if ( $count > 0 && ( null === $busiest || $count > $busiest['count'] ) ) {
				$busiest = array(
					'date'  => (string) $date,
					'count' => $count,
				);
			}
		}

		$totals['rate'] = self::rate( $totals['failed'], $totals['all'] );

		return array(
			'totals'  => $totals,
			'days'    => $days,
			'heatmap' => $heatmap,
			'busiest' => $busiest,
			'average' => round( $totals['all'] / max( 1, $last ), 1 ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows {status, n}.
	 * @return array{from:string,to:string,all:int,failed:int,rate:float}
	 */
	public static function previous( array $rows, string $from, int $days ): array {
		$all    = 0;
		$failed = 0;
		foreach ( $rows as $row ) {
			$all += (int) $row['n'];
			if ( Repository::STATUS_FAILED === (int) $row['status'] ) {
				$failed += (int) $row['n'];
			}
		}
		$start = new DateTimeImmutable( $from, new DateTimeZone( 'UTC' ) );
		return array(
			'from'   => $start->sub( new DateInterval( 'P' . $days . 'D' ) )->format( 'Y-m-d' ),
			'to'     => $start->sub( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ),
			'all'    => $all,
			'failed' => $failed,
			'rate'   => self::rate( $failed, $all ),
		);
	}

	/** Failure rate in percent with one decimal. */
	public static function rate( int $failed, int $all ): float {
		return $all > 0 ? round( 100 * $failed / $all, 1 ) : 0.0;
	}

	/**
	 * Top sources, recipient domains and (normalized) subjects.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows {source, status, recipients, subject}.
	 * @return array{sources:array<int,array<string,mixed>>,domains:array<int,array<string,mixed>>,subjects:array<int,array<string,mixed>>}
	 */
	public static function top_lists( array $rows ): array {
		$sources  = array();
		$domains  = array();
		$subjects = array();
		$failed   = array();

		foreach ( $rows as $row ) {
			$source             = (string) $row['source'];
			$sources[ $source ] = ( $sources[ $source ] ?? 0 ) + 1;
			if ( Repository::STATUS_FAILED === (int) $row['status'] ) {
				$failed[ $source ] = ( $failed[ $source ] ?? 0 ) + 1;
			}

			$seen = array();
			foreach ( Repository::extract_emails( (string) $row['recipients'] ) as $email ) {
				$domain = substr( (string) strrchr( $email, '@' ), 1 );
				if ( '' !== $domain && ! isset( $seen[ $domain ] ) ) {
					$seen[ $domain ]    = true;
					$domains[ $domain ] = ( $domains[ $domain ] ?? 0 ) + 1;
				}
			}

			$subject              = self::normalize_subject( (string) $row['subject'] );
			$subjects[ $subject ] = ( $subjects[ $subject ] ?? 0 ) + 1;
		}

		$out = array(
			'sources'  => array(),
			'domains'  => array(),
			'subjects' => array(),
		);
		foreach ( self::top( $sources ) as $key => $count ) {
			$out['sources'][] = array(
				'key'    => (string) $key,
				'label'  => self::source_label( (string) $key ),
				'count'  => $count,
				'failed' => $failed[ $key ] ?? 0,
			);
		}
		foreach ( self::top( $domains ) as $key => $count ) {
			$out['domains'][] = array(
				'label'  => (string) $key,
				'search' => '@' . $key,
				'count'  => $count,
			);
		}
		foreach ( self::top( $subjects ) as $key => $count ) {
			$out['subjects'][] = array(
				'label'  => (string) $key,
				'search' => self::search_term( (string) $key ),
				'count'  => $count,
			);
		}
		return $out;
	}

	/**
	 * @param array<int|string,int> $counts
	 * @return array<int|string,int>
	 */
	private static function top( array $counts ): array {
		// Stable: equal counts keep alphabetical order.
		ksort( $counts, SORT_STRING );
		arsort( $counts );
		return array_slice( $counts, 0, self::TOP, true );
	}

	/**
	 * Groups subjects that only differ in numbers: "Your order #123" → "Your order #…".
	 */
	public static function normalize_subject( string $subject ): string {
		$subject = (string) preg_replace( '/\d+(?:[.,:\/-]\d+)*/u', '…', $subject );
		$subject = (string) preg_replace( '/…(?:\s*…)+/u', '…', $subject );
		$subject = trim( (string) preg_replace( '/\s+/u', ' ', $subject ) );
		if ( '' === $subject ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $subject, 0, 150 ) : substr( $subject, 0, 150 );
	}

	/** Longest literal part of a normalized subject – used as search term for the log. */
	public static function search_term( string $normalized ): string {
		$best = '';
		foreach ( explode( '…', $normalized ) as $part ) {
			$part = trim( $part );
			if ( strlen( $part ) > strlen( $best ) ) {
				$best = $part;
			}
		}
		return substr( $best, 0, 200 );
	}

	/** Human-readable name of a source like "plugin:woocommerce". */
	public static function source_label( string $source ): string {
		static $plugins = null;

		if ( '' === $source || 'core' === $source ) {
			return __( 'WordPress', 'mailspur-email-log' );
		}
		if ( 'mailspur:resend' === $source ) {
			return __( 'Resent from log', 'mailspur-email-log' );
		}
		if ( self::ALERT_SOURCE === $source ) {
			return __( 'Mailspur alerts', 'mailspur-email-log' );
		}

		$parts = explode( ':', $source, 2 );
		$type  = $parts[0];
		$slug  = $parts[1] ?? $parts[0];

		if ( 'plugin' === $type ) {
			if ( null === $plugins ) {
				$plugins = array();
				if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( function_exists( 'get_plugins' ) ) {
					foreach ( get_plugins() as $file => $plugin ) {
						$plugins[ strtok( (string) $file, '/' ) ] = (string) $plugin['Name'];
					}
				}
			}
			if ( isset( $plugins[ $slug ] ) && '' !== $plugins[ $slug ] ) {
				return $plugins[ $slug ];
			}
		} elseif ( 'theme' === $type && function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				return (string) $theme->get( 'Name' );
			}
		} elseif ( 'import' === $type ) {
			$importer = \Mailspur\Import\Importer::source( $slug );
			/* translators: %s: name of another plugin, e.g. "WP Mail Logging" */
			return sprintf( __( 'Imported from %s', 'mailspur-email-log' ), $importer ? $importer->label() : $slug );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
