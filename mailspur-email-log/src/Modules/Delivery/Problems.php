<?php
/**
 * Problem recipients: addresses that failed hard more than once – rejected by the receiving server ("550 user
 * unknown", "mailbox unavailable"), a recipient domain without mail server (no MX / null MX) or a hard bounce
 * reported by the email provider (Feedback). From THRESHOLD hard failures on, the address is listed under
 * Settings, where it can be allowed again. Optionally (setting problem_hold, off by default) further emails whose
 * recipients are all problem recipients are held instead of sent.
 *
 * Storage: one option that is not autoloaded, address => failures. A failure is remembered by the id of its log
 * entry, so a retried webhook or a reopened entry never counts twice. At most MAX addresses; entries expire with
 * the retention period of the log and are part of the WordPress personal data export and erasure.
 *
 * Cost per mail: nothing for sent mails; for failed mails one regular expression and, only if a recipient was
 * rejected, one option write. The DNS state of recipient domains is only read from the Notes module's cache.
 *
 * Order per mail (see Staging, Brake):
 *   pre_wp_mail  PHP_INT_MIN  Staging::hold() → Brake::hold() → hold() (registered last)
 *   pre_wp_mail  PHP_INT_MAX  Logger::short_circuit → mailspur_finalize_row → finalize() sets "held"
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Logger;
use Mailspur\Modules\Notes\Dynamic;
use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Problems {

	const OPTION = 'mailspur_problem_recipients';

	/** Hard failures (different emails) from which an address is a problem recipient. */
	const THRESHOLD = 2;

	/** Addresses remembered at most (the least recently failed ones are dropped first). */
	const MAX = 500;

	/** Failures remembered per address. */
	const MAX_IDS = 10;

	/** Kept this long when the log itself is kept forever (retention 0). */
	const MAX_DAYS = 365;

	/** Value of meta.delivery.held. */
	const HELD = 'problem_recipient';

	/** Reasons. */
	const REJECTED = 'rejected';
	const NO_MX    = 'no_mx';
	const NULL_MX  = 'null_mx';
	const BOUNCE   = 'bounce';

	/** Not a permanent problem of the recipient: temporary errors and problems of the sender or the connection. */
	const NOT_RECIPIENT = '/\b4\.\d{1,3}\.\d{1,3}\b|\b4[25]\d[\s-]|try again|temporar|greylist|sender (?:address )?(?:rejected|not allowed|verify)|not owned by user|relay|\bspf\b|dmarc|dkim|authenticat|quota|mailbox (?:is )?full|rate limit|too many/i';

	const NULL_MX_PATTERN = '/\b556\b|5\.1\.10\b|null mx/i';

	const NO_MX_PATTERN = '/5\.1\.2\b|(?:host or )?domain (?:name )?not found|no mx\b|unrouteable address|domain does not (?:exist|accept mail)|nxdomain/i';

	const REJECTED_PATTERN = '/5\.1\.1\b|user unknown|unknown user|no such user|user not found|mailbox (?:unavailable|not found|does not exist)|recipient (?:address )?rejected|invalid recipient|recipient unknown|unknown recipient|does not exist|\b55[013]\b.{0,80}(?:recipient|mailbox|user)/i';

	/**
	 * Clock, replaceable in tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/** @var bool The current mail is held (set in pre_wp_mail, consumed by finalize()). */
	private static $held = false;

	/** @var bool The next mail is a password reset (set by the retrieve_password_message filter). */
	private static $password_reset = false;

	/** @var bool The current mail is being logged (set in the logger's capture phase). */
	private static $logged = false;

	/**
	 * @param (callable():int)|null $now
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	public function register(): void {
		// Same priority as Staging::hold() and Brake::hold(), registered later: those always decide first.
		add_filter( 'pre_wp_mail', array( $this, 'hold' ), PHP_INT_MIN, 2 );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 11, 2 );
		add_filter( 'mailspur_finalize_row', array( $this, 'finalize' ) );
		add_filter( 'retrieve_password_message', array( $this, 'password_reset' ), PHP_INT_MAX );
		add_action( 'mailspur_logged', array( $this, 'logged' ), 10, 2 );
		// After the Notes module (priority 10), which has just looked up the recipient domains.
		add_filter( 'mailspur_rest_item', array( $this, 'rest_item' ), 20, 2 );
		add_action( \Mailspur\Cleanup::HOOK, array( $this, 'cron' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	/* ------------------------------------------------------------------ store */

	/**
	 * Every remembered address (also those below the threshold).
	 *
	 * @return array<string,array{ids:array<int,string>,first:int,last:int,why:string}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $email => $entry ) {
			if ( is_string( $email ) && is_array( $entry ) && isset( $entry['ids'] ) && is_array( $entry['ids'] ) ) {
				$out[ $email ] = array(
					'ids'   => array_values( array_map( 'strval', $entry['ids'] ) ),
					'first' => (int) ( $entry['first'] ?? 0 ),
					'last'  => (int) ( $entry['last'] ?? 0 ),
					'why'   => (string) ( $entry['why'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string,array{ids:array<int,string>,first:int,last:int,why:string}> $entries
	 */
	private static function save( array $entries ): void {
		if ( ! $entries ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Remembers a hard failure of one or more addresses.
	 *
	 * @param array<int,string> $addresses
	 * @param string            $ref       What failed (log entry id) – the same reference never counts twice.
	 * @return int Addresses whose count changed.
	 */
	public function record( array $addresses, string $reason, string $ref ): int {
		$list    = self::all();
		$now     = $this->now();
		$changed = 0;
		foreach ( $addresses as $address ) {
			$email = strtolower( trim( (string) $address ) );
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}
			$entry = $list[ $email ] ?? array(
				'ids'   => array(),
				'first' => $now,
				'last'  => $now,
				'why'   => $reason,
			);
			if ( in_array( $ref, $entry['ids'], true ) ) {
				continue;
			}
			$entry['ids'][] = $ref;
			$entry['ids']   = array_slice( $entry['ids'], -self::MAX_IDS );
			$entry['last']  = $now;
			$entry['why']   = $reason;
			$list[ $email ] = $entry;
			++$changed;
		}
		if ( $changed ) {
			if ( count( $list ) > self::MAX ) {
				uasort(
					$list,
					static function ( array $a, array $b ): int {
						return $b['last'] <=> $a['last'];
					}
				);
				$list = array_slice( $list, 0, self::MAX, true );
			}
			self::save( $list );
		}
		return $changed;
	}

	/** Whether an address failed hard at least THRESHOLD times. */
	public static function is_problem( string $email ): bool {
		$list = self::all();
		$key  = strtolower( trim( $email ) );
		return isset( $list[ $key ] ) && count( $list[ $key ]['ids'] ) >= self::THRESHOLD;
	}

	/**
	 * Problem recipients, most recent failure first.
	 *
	 * @return array<int,array{email:string,count:int,first:int,last:int,why:string}>
	 */
	public static function problems(): array {
		$out = array();
		foreach ( self::all() as $email => $entry ) {
			if ( count( $entry['ids'] ) >= self::THRESHOLD ) {
				$out[] = array(
					'email' => $email,
					'count' => count( $entry['ids'] ),
					'first' => $entry['first'],
					'last'  => $entry['last'],
					'why'   => $entry['why'],
				);
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['last'] <=> $a['last'];
			}
		);
		return $out;
	}

	/** "Allow again": forgets the address. */
	public static function allow( string $email ): bool {
		$list = self::all();
		$key  = strtolower( trim( $email ) );
		if ( ! isset( $list[ $key ] ) ) {
			return false;
		}
		unset( $list[ $key ] );
		self::save( $list );
		return true;
	}

	/** Daily cleanup (cron). */
	public function cron(): void {
		$this->expire();
	}

	/**
	 * Forgets addresses whose last failure is older than the retention period of the log.
	 *
	 * @return int Addresses forgotten.
	 */
	public function expire(): int {
		$days   = (int) Settings::get( 'retention_days' );
		$cutoff = $this->now() - ( $days > 0 ? $days : self::MAX_DAYS ) * DAY_IN_SECONDS;
		$list   = self::all();
		$kept   = array_filter(
			$list,
			static function ( array $entry ) use ( $cutoff ): bool {
				return $entry['last'] >= $cutoff;
			}
		);
		if ( count( $kept ) !== count( $list ) ) {
			self::save( $kept );
		}
		return count( $list ) - count( $kept );
	}

	/** Translated label of a reason. */
	public static function reason_label( string $why ): string {
		switch ( $why ) {
			case self::NO_MX:
				return __( 'The recipient domain has no mail server', 'mailspur-email-log' );
			case self::NULL_MX:
				return __( 'The recipient domain accepts no email (null MX)', 'mailspur-email-log' );
			case self::BOUNCE:
				return __( 'Hard bounce reported by your email provider', 'mailspur-email-log' );
			default:
				return __( 'Mailbox unknown or unavailable', 'mailspur-email-log' );
		}
	}

	/* ------------------------------------------------------------- detection */

	/**
	 * Reason of a permanent failure of the recipient in an error message, or '' (temporary, sender problem, other).
	 */
	public static function classify( string $error ): string {
		$error = substr( trim( $error ), 0, 2000 );
		if ( '' === $error ) {
			return '';
		}
		if ( preg_match( self::NULL_MX_PATTERN, $error ) ) {
			return self::NULL_MX;
		}
		if ( preg_match( self::NOT_RECIPIENT, $error ) ) {
			return '';
		}
		if ( preg_match( self::NO_MX_PATTERN, $error ) ) {
			return self::NO_MX;
		}
		return preg_match( self::REJECTED_PATTERN, $error ) ? self::REJECTED : '';
	}

	/**
	 * Recipients a failure applies to: those named in the error message, or the only recipient.
	 *
	 * @return array<int,string>
	 */
	public static function failed_addresses( string $error, string $recipients ): array {
		$to = array_values( array_unique( Repository::extract_emails( $recipients ) ) );
		preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', substr( $error, 0, 2000 ), $m );
		$named = array_values( array_intersect( $to, array_map( 'strtolower', $m[0] ) ) );
		if ( $named ) {
			return $named;
		}
		return 1 === count( $to ) ? $to : array();
	}

	/**
	 * After every logged mail: a failed mail whose recipient was rejected permanently counts as a hard failure.
	 *
	 * @param int|string          $id  Log entry id.
	 * @param array<string,mixed> $row
	 */
	public function logged( $id, $row ): void {
		try {
			$row = (array) $row;
			if ( Repository::STATUS_FAILED !== (int) ( $row['status'] ?? 0 ) ) {
				return;
			}
			$error  = (string) ( $row['error'] ?? '' );
			$reason = self::classify( $error );
			if ( '' !== $reason ) {
				$this->record( self::failed_addresses( $error, (string) ( $row['recipients'] ?? '' ) ), $reason, (string) (int) $id );
				return;
			}
			// No DNS while a mail is sent: only what the Notes module already found out.
			$this->record_domains( (string) ( $row['recipients'] ?? '' ), (string) (int) $id );
		} catch ( \Throwable $e ) { // Must never break logging.
			return;
		}
	}

	/**
	 * Recipients whose domain has no mail server count as failed. Only the cached lookup of the Notes module is
	 * read – never a DNS query of its own.
	 */
	private function record_domains( string $recipients, string $ref ): void {
		$by_state = array();
		$looked   = 0;
		foreach ( array_unique( Repository::extract_emails( $recipients ) ) as $email ) {
			$domain = strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) );
			if ( '' === $domain || ++$looked > Dynamic::MAX_DOMAINS ) {
				break;
			}
			$state = get_transient( Dynamic::TRANSIENT_PREFIX . md5( $domain ) );
			if ( 'none' === $state || 'null' === $state ) {
				$by_state[ 'null' === $state ? self::NULL_MX : self::NO_MX ][] = $email;
			}
		}
		foreach ( $by_state as $reason => $emails ) {
			$this->record( $emails, $reason, $ref );
		}
	}

	/**
	 * Detail view: marks problem recipients of the mail. When the Notes module found that a recipient domain has
	 * no mail server, this mail counts as a hard failure of that recipient (cached lookup, no new DNS query).
	 *
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function rest_item( $item, $row ) {
		$item = (array) $item;
		$row  = (array) $row;
		try {
			$recipients = (string) ( $row['recipients'] ?? '' );
			if ( in_array( (int) ( $row['status'] ?? 0 ), array( Repository::STATUS_SENT, Repository::STATUS_FAILED ), true ) ) {
				$this->record_domains( $recipients, (string) (int) ( $row['id'] ?? 0 ) );
			}
			$problems = array();
			foreach ( array_unique( Repository::extract_emails( $recipients ) ) as $email ) {
				if ( self::is_problem( $email ) ) {
					$problems[] = $email;
				}
			}
			if ( $problems ) {
				$item['problem_recipients'] = $problems;
			}
		} catch ( \Throwable $e ) {
			return $item;
		}
		return $item;
	}

	/* ------------------------------------------------------------------ hold */

	/**
	 * Marks the next mail as a password reset (it is never held).
	 *
	 * @param mixed $message Unchanged.
	 * @return mixed
	 */
	public function password_reset( $message ) {
		self::$password_reset = true;
		return $message;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public function meta( $meta, string $phase ) {
		if ( 'capture' === $phase ) {
			self::$logged = true;
		}
		return (array) $meta;
	}

	/**
	 * Holds the mail when the setting is on and every To recipient is a problem recipient.
	 *
	 * @param null|bool $result Non-null: staging mode, the emergency brake or another plugin already handled the mail.
	 * @param mixed     $atts   wp_mail() arguments.
	 * @return null|bool
	 */
	public function hold( $result, $atts = array() ) {
		$password_reset       = self::$password_reset;
		$logged               = self::$logged;
		self::$password_reset = false;
		self::$logged         = false;
		self::$held           = false;

		if ( null !== $result || ! $logged || $password_reset || empty( Settings::get( 'problem_hold' ) ) ) {
			return $result;
		}
		try {
			// The plugin's own mails (alerts, resend, release) are never held.
			if ( Staging::$release || 0 === strpos( Logger::$source_override, 'mailspur:' ) ) {
				return $result;
			}
			$atts = is_array( $atts ) ? $atts : array();
			$to   = $atts['to'] ?? array();
			$to   = Repository::extract_emails( is_array( $to ) ? implode( ',', array_map( 'strval', $to ) ) : (string) $to );
			if ( ! $to ) {
				return $result;
			}
			foreach ( $to as $email ) {
				if ( ! self::is_problem( $email ) ) {
					return $result;
				}
			}
			/** This filter is documented in src/Modules/Delivery/Brake.php */
			if ( apply_filters( 'mailspur_brake_exempt', false, $atts ) ) {
				return $result;
			}
			self::$held = true;
			return true;
		} catch ( \Throwable $e ) { // Must never break delivery.
			self::$held = false;
			return $result;
		}
	}

	/**
	 * @param array<string,mixed> $data Columns of the final UPDATE.
	 * @return array<string,mixed>
	 */
	public function finalize( $data ) {
		$data = (array) $data;
		if ( ! self::$held ) {
			return $data;
		}
		self::$held     = false;
		$data['status'] = Repository::STATUS_HELD;
		$meta           = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		$meta['delivery']         = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
		$meta['delivery']['held'] = self::HELD;

		$data['meta'] = $meta;
		return $data;
	}

	/** Test helper: forget per-request state. */
	public static function reset_request(): void {
		self::$held           = false;
		self::$password_reset = false;
		self::$logged         = false;
	}

	/* --------------------------------------------------------------- privacy */

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public function add_exporter( $exporters ) {
		$exporters                                = (array) $exporters;
		$exporters['mailspur-problem-recipients'] = array(
			'exporter_friendly_name' => __( 'Email log: problem recipients', 'mailspur-email-log' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public function add_eraser( $erasers ) {
		$erasers                                = (array) $erasers;
		$erasers['mailspur-problem-recipients'] = array(
			'eraser_friendly_name' => __( 'Email log: problem recipients', 'mailspur-email-log' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$key   = strtolower( trim( $email ) );
		$entry = self::all()[ $key ] ?? null;
		$data  = array();
		if ( $entry && $page <= 1 ) {
			$data[] = array(
				'group_id'    => 'mailspur-problem-recipients',
				'group_label' => __( 'Email log: problem recipients', 'mailspur-email-log' ),
				'item_id'     => 'mailspur-problem-' . md5( $key ),
				'data'        => array(
					array(
						'name'  => __( 'Recipient', 'mailspur-email-log' ),
						'value' => $key,
					),
					array(
						'name'  => __( 'Hard failures', 'mailspur-email-log' ),
						'value' => (string) count( $entry['ids'] ),
					),
					array(
						'name'  => __( 'Last failure', 'mailspur-email-log' ),
						'value' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $entry['last'] ) ),
					),
					array(
						'name'  => __( 'Reason', 'mailspur-email-log' ),
						'value' => self::reason_label( $entry['why'] ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- eraser signature.
		return array(
			'items_removed'  => self::allow( $email ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
