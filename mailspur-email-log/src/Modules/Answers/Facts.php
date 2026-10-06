<?php
/**
 * What Mailspur knows, gathered for the answers (Sentences): small grouped counts of the last 7 days, the email
 * types (stopped, due today, scheduled by WP-Cron), the cached sender check, staging mode and the emergency
 * brake – and, on request, the latest emails to one address or order.
 *
 * Cheap on purpose: three range queries on the created_at index, the types report the "Email types" tab uses
 * (no indexing here) and the sender check only from its cache (never a DNS lookup). The counts are cached for
 * five minutes; staging mode and the brake are read live, so switching them shows at once.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- range counts on the own table, cached in a transient.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Answers;

use DateTimeImmutable;
use Mailspur\Admin;
use Mailspur\Modules\Context\Screens;
use Mailspur\Modules\Context\Store as ContextStore;
use Mailspur\Modules\Delivery\Brake;
use Mailspur\Modules\Delivery\Module as Delivery;
use Mailspur\Modules\Delivery\Problems;
use Mailspur\Modules\Delivery\SenderCheck;
use Mailspur\Modules\Delivery\Staging;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Modules\Notes\Explainer;
use Mailspur\Modules\Types\Report;
use Mailspur\Modules\Types\Store as TypesStore;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Facts {

	const CACHE      = 'mailspur_answers_';
	const GENERATION = 'mailspur_answers_generation';
	const TTL        = 300; // Five minutes.

	/** Emails shown per lookup. */
	const ROWS = 3;

	/** A type counts as daily when it was sent on at least this many of the previous 29 days. */
	const DAILY_DAYS = 27;

	/** @var Repository */
	private $repository;

	/**
	 * Clock, replaceable in tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/** @param (callable():int)|null $now */
	public function __construct( Repository $repository, ?callable $now = null ) {
		$this->repository = $repository;
		$this->now        = $now ?? 'time';
	}

	public function now(): int {
		return (int) call_user_func( $this->now );
	}

	/** Forgets the cached counts (tests, and after the log was changed through the REST API). */
	public static function flush(): void {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, false );
	}

	/**
	 * Links the answers point to.
	 *
	 * @return array<string,string>
	 */
	public function links(): array {
		$since    = (string) wp_date( 'Y-m-d', $this->now() - 6 * DAY_IN_SECONDS );
		$settings = Admin::url( array( 'tab' => 'settings' ) );
		return array(
			'log'     => Admin::url(),
			'failed'  => Admin::url(
				array(
					'status' => 'failed',
					'after'  => $since,
				)
			),
			'held'    => Admin::url(
				array(
					'status' => 'held',
					'after'  => $since,
				)
			),
			'types'   => Admin::url( array( 'tab' => 'types' ) ),
			'missing' => Admin::url( array( 'tab' => Page::TAB ) ) . '#msa-missing',
			'staging' => $settings . '#mailspur-staging',
			'brake'   => $settings . '#mailspur-brake',
			'sender'  => $settings . '#mailspur-sender-check',
		);
	}

	/* ---------------------------------------------------------------- health */

	/**
	 * Everything the overview needs (see Sentences::health(), missing(), failure()).
	 *
	 * @return array<string,mixed>
	 */
	public function health(): array {
		return array_merge(
			$this->cached(),
			array(
				'staging' => class_exists( Staging::class ) ? Staging::effective_mode() : 'off',
				'brake'   => $this->brake(),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cached(): array {
		$generation = (int) get_option( self::GENERATION, 0 ) . '.' . ( class_exists( Stats::class ) ? (int) get_option( Stats::GENERATION_OPTION, 0 ) : 0 );
		$key        = self::CACHE . md5( $generation . '|' . determine_locale() . '|' . wp_timezone_string() );
		$cached     = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = $this->compute();
		set_transient( $key, $data, self::TTL );
		return $data;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function compute(): array {
		$now              = $this->now();
		$data             = array(
			'week'       => $this->counts( $now - 7 * DAY_IN_SECONDS ),
			'day_failed' => $this->counts( $now - DAY_IN_SECONDS )['failed'],
			'failures'   => array(),
			'types'      => false,
			'stopped'    => array(),
			'due'        => array(),
			'scheduled'  => array(),
			'regular'    => 0,
			'sender'     => $this->sender(),
		);
		$data['failures'] = self::top_failure( $this->errors( $now - 7 * DAY_IN_SECONDS ), (int) $data['week']['failed'] );

		if ( class_exists( Report::class ) ) {
			try {
				TypesStore::maybe_install();
				$items = array();
				foreach ( Report::current( new TypesStore(), $now ) as $item ) {
					$item['name']  = Report::text( (array) $item['pattern'] );
					$item['label'] = Report::source_label( (string) $item['source'] );
					$items[]       = $item;
				}
				$end   = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 23, 59, 59 )->getTimestamp();
				$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
				$data  = array_merge( $data, self::types( $items, $now, $end, $crons, (string) wp_date( 'l', $now ) ), array( 'types' => true ) );
			} catch ( \Throwable $e ) {
				$data['types'] = false; // The overview goes without the types part.
			}
		}
		return $data;
	}

	/**
	 * Emails per status since a time, without Mailspur's own emails (alerts, reports).
	 *
	 * @return array{total:int,sent:int,failed:int,held:int,pending:int}
	 */
	private function counts( int $since ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s AND source NOT LIKE %s GROUP BY status',
				$this->repository::table(),
				gmdate( 'Y-m-d H:i:s', $since ),
				$wpdb->esc_like( 'mailspur:alert' ) . '%'
			),
			ARRAY_A
		);
		$by   = array();
		foreach ( $rows as $row ) {
			$slug        = Repository::status_slug( (int) $row['status'] );
			$by[ $slug ] = ( $by[ $slug ] ?? 0 ) + (int) $row['n'];
		}
		return array(
			'total'   => (int) array_sum( $by ),
			'sent'    => $by['sent'] ?? 0,
			'failed'  => $by['failed'] ?? 0,
			'held'    => $by['held'] ?? 0,
			'pending' => $by['pending'] ?? 0,
		);
	}

	/**
	 * Error messages of failed emails since a time, most frequent first.
	 *
	 * @return array<int,array{error:string,n:int}>
	 */
	private function errors( int $since ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT error, COUNT(*) AS n FROM %i WHERE status = %d AND created_at >= %s AND source NOT LIKE %s GROUP BY error ORDER BY n DESC LIMIT 50',
				$this->repository::table(),
				Repository::STATUS_FAILED,
				gmdate( 'Y-m-d H:i:s', $since ),
				$wpdb->esc_like( 'mailspur:alert' ) . '%'
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'error' => (string) $row['error'],
				'n'     => (int) $row['n'],
			);
		}
		return $out;
	}

	/**
	 * The most common failure reason: messages with the same explanation (Notes) count together, so
	 * "SMTP Error: Could not authenticate." with different server names is one reason.
	 *
	 * @param array<int,array{error:string,n:int}> $errors
	 * @return array{total:int,count:int,error:string}
	 */
	public static function top_failure( array $errors, int $total ): array {
		$groups = array();
		foreach ( $errors as $row ) {
			$key = class_exists( Explainer::class ) ? Explainer::match( $row['error'] ) : '';
			$key = '' !== $key ? $key : 'raw:' . trim( $row['error'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'count' => 0,
					'error' => $row['error'],
				);
			}
			$groups[ $key ]['count'] += $row['n'];
		}
		$top = array(
			'count' => 0,
			'error' => '',
		);
		foreach ( $groups as $group ) {
			if ( $group['count'] > $top['count'] ) {
				$top = $group;
			}
		}
		return array(
			'total' => max( $total, (int) array_sum( array_column( $groups, 'count' ) ) ),
			'count' => $top['count'],
			'error' => $top['error'],
		);
	}

	/**
	 * @return array{active:bool,held:int}
	 */
	private function brake(): array {
		if ( ! class_exists( Brake::class ) || ! Brake::active() ) {
			return array(
				'active' => false,
				'held'   => 0,
			);
		}
		return array(
			'active' => true,
			'held'   => ( new Brake() )->held_count(),
		);
	}

	/**
	 * Sender domains with a failed check – only from the cache of the last check (no DNS lookups here).
	 *
	 * @return string[]
	 */
	private function sender(): array {
		if ( ! class_exists( SenderCheck::class ) ) {
			return array();
		}
		$result = SenderCheck::cached( ( new SenderCheck() )->domains() );
		$bad    = array();
		foreach ( (array) ( $result['domains'] ?? array() ) as $domain ) {
			foreach ( (array) ( $domain['checks'] ?? array() ) as $check ) {
				if ( 'bad' === ( $check['status'] ?? '' ) ) {
					$bad[] = (string) $domain['domain'];
					break;
				}
			}
		}
		return $bad;
	}

	/**
	 * Stopped types, types expected today that have not been sent yet, and WP-Cron events of types that run
	 * later today. Conservative: "expected today" needs an email on each of the last four same weekdays.
	 *
	 * @param array<int,array<string,mixed>> $items   Report items plus "name" and "label" (sender).
	 * @param int                            $now     Unix time.
	 * @param int                            $end     End of today (site time).
	 * @param array<int|string,mixed>        $crons   _get_cron_array().
	 * @param string                         $weekday Today's weekday name, e.g. "Monday".
	 * @return array{stopped:array<int,array<string,mixed>>,due:array<int,array<string,mixed>>,scheduled:array<int,array<string,mixed>>,regular:int}
	 */
	public static function types( array $items, int $now, int $end, array $crons, string $weekday ): array {
		$out = array(
			'stopped'   => array(),
			'due'       => array(),
			'scheduled' => array(),
			'regular'   => 0,
		);
		foreach ( $items as $item ) {
			if ( ! empty( $item['muted'] ) || ! empty( $item['other'] ) ) {
				continue;
			}
			$rhythm = (array) ( $item['rhythm'] ?? array() );
			if ( ! empty( $rhythm['regular'] ) ) {
				++$out['regular'];
			}
			$base = array(
				'name'      => (string) $item['name'],
				'source'    => (string) $item['label'],
				'last_seen' => (int) $item['last_seen'],
			);
			if ( 'silent' === ( $item['state'] ?? '' ) ) {
				$cause            = (array) ( $item['cause'] ?? array() );
				$out['stopped'][] = $base + array(
					'expected' => (int) ( $rhythm['expected'] ?? 0 ),
					'cause'    => (string) ( $cause['text'] ?? '' ),
				);
				continue;
			}

			$series = array_values( (array) ( $item['series'] ?? array() ) );
			$last   = count( $series ) - 1;
			if ( $last < 28 || (int) $series[ $last ][1] > 0 ) {
				continue; // Too little history, or already sent today.
			}
			$hook = is_string( $item['cron'] ?? null ) ? (string) $item['cron'] : '';
			$next = '' !== $hook ? self::next_run( $hook, $crons, $now, $end ) : 0;

			$weekly = true;
			foreach ( array( 7, 14, 21, 28 ) as $back ) {
				$weekly = $weekly && (int) $series[ $last - $back ][1] > 0;
			}
			if ( ! empty( $rhythm['regular'] ) && $weekly ) {
				$days = 0;
				for ( $i = 1; $i <= $last; $i++ ) {
					$days += (int) $series[ $last - $i ][1] > 0 ? 1 : 0;
				}
				$out['due'][] = $base + array(
					'daily'   => $days >= self::DAILY_DAYS,
					'weekday' => $weekday,
					'next'    => $next,
				);
			} elseif ( $next > 0 ) {
				$out['scheduled'][] = $base + array( 'next' => $next );
			}
		}
		usort(
			$out['scheduled'],
			static function ( array $a, array $b ): int {
				return $a['next'] <=> $b['next'];
			}
		);
		return $out;
	}

	/**
	 * Next run of a cron hook between now and the end of today, 0 if none.
	 *
	 * @param array<int|string,mixed> $crons _get_cron_array().
	 */
	public static function next_run( string $hook, array $crons, int $now, int $end ): int {
		$next = 0;
		foreach ( $crons as $time => $hooks ) {
			if ( is_numeric( $time ) && is_array( $hooks ) && isset( $hooks[ $hook ] ) && (int) $time >= $now && (int) $time <= $end ) {
				$next = 0 === $next ? (int) $time : min( $next, (int) $time );
			}
		}
		return $next;
	}

	/* ---------------------------------------------------------------- lookup */

	/**
	 * The latest emails to an address, or of an order (WooCommerce: linked emails and emails to the billing
	 * address around the order date; without a shop: the number in the subject).
	 *
	 * @return array<string,mixed> See Sentences::arrived().
	 */
	public function lookup( string $query ): array {
		$query = trim( $query );
		$out   = array(
			'kind'        => 'invalid',
			'email'       => '',
			'order'       => '',
			'shop'        => false,
			'order_found' => false,
			'rows'        => array(),
			'problem'     => false,
			'retention'   => (int) Settings::get( 'retention_days' ),
			'url'         => '',
		);

		if ( is_email( $query ) ) {
			$email        = strtolower( $query );
			$out['kind']  = 'email';
			$out['email'] = $email;
			$out['rows']  = $this->to_address( $email );
			$out['url']   = $out['rows'] ? Admin::url( array( 's' => rawurlencode( $email ) ) ) : '';
		} elseif ( preg_match( '/^#?(\d{1,12})$/', $query, $m ) ) {
			$out['kind']  = 'order';
			$out['order'] = $m[1];
			$out          = $this->order( $out );
		}
		if ( '' !== $out['email'] && class_exists( Problems::class ) ) {
			$out['problem'] = Problems::is_problem( $out['email'] );
		}
		$out['rows'] = array_map(
			function ( array $row ) use ( $out ): array {
				return $this->row( $row, (string) $out['email'] );
			},
			array_slice( $out['rows'], 0, self::ROWS )
		);
		return $out;
	}

	/**
	 * @param array<string,mixed> $out
	 * @return array<string,mixed>
	 */
	private function order( array $out ): array {
		$number = (string) $out['order'];
		if ( function_exists( 'wc_get_order' ) && class_exists( Screens::class ) && Screens::can_view_orders() ) {
			$out['shop'] = true;
			// Sequential order number plugins map the visible number to the order id through this WooCommerce filter.
			$id    = (int) apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
			$order = Screens::order( wc_get_order( $id ) );
			if ( null === $order ) {
				return $out;
			}
			ContextStore::maybe_install();
			$mails              = ( new Screens( new ContextStore() ) )->order_mails( $order );
			$out['order_found'] = true;
			$out['email']       = strtolower( (string) $mails['email'] );
			$out['rows']        = $this->by_ids( array_map( 'intval', array_column( $mails['rows'], 'id' ) ) );
			$out['url']         = '' !== $out['email'] ? Admin::url( array( 's' => rawurlencode( $out['email'] ) ) ) : '';
			return $out;
		}
		$out['rows'] = $this->by_subject( $number );
		$out['url']  = $out['rows'] ? Admin::url( array( 's' => rawurlencode( $number ) ) ) : '';
		return $out;
	}

	/**
	 * Newest emails to an address (exact recipient match; the LIKE is only a pre-filter).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function to_address( string $email ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, status, recipients, subject, error, meta FROM %i WHERE recipients LIKE %s ORDER BY created_at DESC, id DESC LIMIT %d',
				$this->repository::table(),
				'%' . $wpdb->esc_like( $email ) . '%',
				self::ROWS * 3
			),
			ARRAY_A
		);
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $email ): bool {
					return is_array( $row ) && in_array( $email, Repository::extract_emails( (string) $row['recipients'] ), true );
				}
			)
		);
	}

	/**
	 * Newest emails with the number in their subject (not part of a longer number).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function by_subject( string $number ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, status, recipients, subject, error, meta FROM %i WHERE subject LIKE %s ORDER BY created_at DESC, id DESC LIMIT %d',
				$this->repository::table(),
				'%' . $wpdb->esc_like( $number ) . '%',
				self::ROWS * 5
			),
			ARRAY_A
		);
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $number ): bool {
					return is_array( $row ) && 1 === preg_match( '/(?<!\d)' . preg_quote( $number, '/' ) . '(?!\d)/', (string) $row['subject'] );
				}
			)
		);
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>> Newest first.
	 */
	private function by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_slice( array_filter( $ids ), 0, self::ROWS );
		if ( ! $ids ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, status, recipients, subject, error, meta FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY created_at DESC, id DESC',
				array_merge( array( $this->repository::table() ), $ids )
			),
			ARRAY_A
		);
		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * One email as the answer needs it – no body, no headers.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function row( array $row, string $email ): array {
		$meta       = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		$recipients = Repository::extract_emails( (string) $row['recipients'] );
		$recipient  = '' !== $email && in_array( $email, $recipients, true ) ? $email : (string) ( $recipients[0] ?? '' );
		$feedback   = isset( $meta['feedback'] ) && is_array( $meta['feedback'] ) ? $meta['feedback'] : array();
		$delivery   = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
		$providers  = class_exists( Delivery::class ) ? Delivery::provider_labels() : array();
		$via        = (string) ( $feedback['via'] ?? '' );
		$time       = (int) strtotime( (string) $row['created_at'] . ' UTC' );
		$day        = (string) wp_date( 'Y-m-d', $time );

		return array(
			'id'        => (int) $row['id'],
			'time'      => $time,
			'status'    => Repository::status_slug( (int) $row['status'] ),
			'subject'   => (string) $row['subject'],
			'recipient' => $recipient,
			'error'     => (string) $row['error'],
			'held'      => (string) ( $delivery['held'] ?? '' ),
			'feedback'  => $feedback ? array(
				'event' => (string) ( $feedback['event'] ?? '' ),
				'hard'  => ! empty( $feedback['hard'] ),
				'via'   => $providers[ $via ] ?? '',
			) : array(),
			// Opens the email in the log (filtered to the recipient and the day, so it is on the first page).
			'url'       => Admin::url(
				array(
					'mail'   => (string) (int) $row['id'],
					's'      => rawurlencode( $recipient ),
					'after'  => $day,
					'before' => $day,
				)
			),
		);
	}
}
