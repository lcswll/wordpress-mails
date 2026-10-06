<?php
/**
 * "Bundle into one daily email" for noisy email types to administrators: the single emails are logged and held,
 * once a day one digest per administrator lists them (Digest). Opt-in per type.
 *
 * Matching at send time stays cheap: the bundled types (sender + subject pattern) are mirrored in one autoloaded
 * option, refreshed when a type is switched and after every indexing run. A mail is only looked at further when
 * its sender has a bundled type; the subject must then fit the pattern exactly (Fingerprint::merge without
 * widening) – the same rule the detail view uses to find an email's type.
 *
 * Never bundled: password resets, emails with a recipient who is not an administrator (or the admin email), with
 * Cc/Bcc recipients or attachments, the plugin's own emails (alerts, digests, resends), emails that are not
 * logged and anything the filter mailspur_brake_exempt lets through.
 *
 * Order per mail (see Staging, Brake, Problems):
 *   pre_wp_mail  PHP_INT_MIN  Staging::hold() → Brake::hold() → Problems::hold() → hold() (Types loads later)
 *   pre_wp_mail  PHP_INT_MAX  Logger::short_circuit → mailspur_finalize_row → finalize() sets "held" (reason "bundled")
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Logger;
use Mailspur\Repository;
use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Bundle {

	/** Bundled types: type id => array( sender, pattern words ). Autoloaded – read for every logged mail. */
	const OPTION = 'mailspur_types_bundle';

	/** Values of meta.delivery.held. */
	const HELD     = 'bundled';
	const RELEASED = 'bundle_released';

	/** @var int Type of the current mail when it is held (set in pre_wp_mail, consumed by finalize()). */
	private static $held = 0;

	/** @var bool The next mail is a password reset (set by the retrieve_password_message filter). */
	private static $password_reset = false;

	/** @var bool The current mail is being logged (set in the logger's capture phase). */
	private static $logged = false;

	/** @var string[]|null Administrators' addresses, looked up once per request and only when needed. */
	private static $admins;

	public function register(): void {
		// Same priority as the holds of the Delivery module, registered later: those always decide first.
		add_filter( 'pre_wp_mail', array( $this, 'hold' ), PHP_INT_MIN, 2 );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 11, 2 );
		add_filter( 'mailspur_finalize_row', array( $this, 'finalize' ) );
		add_filter( 'retrieve_password_message', array( $this, 'password_reset' ), PHP_INT_MAX );
		add_action( 'mailspur_held_released', array( self::class, 'released' ) );
	}

	/**
	 * Bundled types as mirrored in the option.
	 *
	 * @return array<int,array{0:string,1:string[]}>
	 */
	public static function map(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $entry ) {
			if ( is_array( $entry ) && isset( $entry[0], $entry[1] ) && is_array( $entry[1] ) ) {
				$out[ (int) $id ] = array( (string) $entry[0], array_map( 'strval', $entry[1] ) );
			}
		}
		return $out;
	}

	/**
	 * Mirrors the bundled types of the store into the option (only written when it changed).
	 *
	 * @param array<int,array<string,mixed>>|null $types Store::types(), default: read.
	 */
	public static function sync( Store $store, ?array $types = null ): void {
		$map = array();
		foreach ( $types ?? $store->types() as $id => $type ) {
			if ( ! empty( $type['bundle'] ) && array( Fingerprint::OTHER ) !== $type['pattern'] ) {
				$map[ (int) $id ] = array( (string) $type['source'], array_values( array_map( 'strval', (array) $type['pattern'] ) ) );
			}
		}
		if ( self::map() !== $map ) {
			update_option( self::OPTION, $map, true );
		}
	}

	/**
	 * Bundled type of a mail by sender and subject.
	 *
	 * @param array<int,array{0:string,1:string[]}> $map map().
	 */
	public static function match( array $map, string $source, string $subject ): int {
		$words = null;
		foreach ( $map as $id => $entry ) {
			if ( $source !== $entry[0] ) {
				continue;
			}
			$words = $words ?? Fingerprint::tokens( $subject );
			if ( Fingerprint::merge( $entry[1], $words ) === $entry[1] ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Whether a mail goes to administrators only: every To address is one of theirs, no Cc/Bcc, no attachments.
	 *
	 * @param array<string,mixed> $atts   wp_mail() arguments.
	 * @param string[]            $admins Lower-cased addresses (Noise::addresses()).
	 */
	public static function admins_only( array $atts, array $admins ): bool {
		if ( ! $admins || ! empty( $atts['attachments'] ) ) {
			return false;
		}
		$headers = $atts['headers'] ?? array();
		$headers = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;
		if ( preg_match( '/^\s*b?cc\s*:/im', $headers ) ) {
			return false;
		}
		$to = $atts['to'] ?? array();
		$to = Repository::extract_emails( is_array( $to ) ? implode( ',', array_map( 'strval', $to ) ) : (string) $to );
		return array() !== $to && array() === array_diff( $to, $admins );
	}

	/**
	 * Marks the next mail as a password reset (it is never bundled).
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
	 * Holds the mail when it belongs to a bundled type and goes to administrators only.
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
		self::$held           = 0;

		if ( null !== $result || ! $logged || $password_reset ) {
			return $result;
		}
		try {
			$map = self::map();
			// The plugin's own mails (alerts, digests, resend, release) are never bundled.
			if ( ! $map || 0 === strpos( Logger::$source_override, 'mailspur:' ) ) {
				return $result;
			}
			$atts = is_array( $atts ) ? $atts : array();
			$type = self::match( $map, Logger::$current_source, (string) ( $atts['subject'] ?? '' ) );
			if ( ! $type ) {
				return $result;
			}
			if ( null === self::$admins ) {
				self::$admins = Noise::addresses();
			}
			if ( ! self::admins_only( $atts, self::$admins ) ) {
				return $result;
			}
			/** This filter is documented in src/Modules/Delivery/Brake.php */
			if ( apply_filters( 'mailspur_brake_exempt', false, $atts ) ) {
				return $result;
			}
			self::$held = $type;
			return true;
		} catch ( \Throwable $e ) { // Must never break delivery.
			self::$held = 0;
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
		$data['status'] = Repository::STATUS_HELD;
		$meta           = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		$meta['delivery']           = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
		$meta['delivery']['held']   = self::HELD;
		$meta['delivery']['bundle'] = self::$held;

		$data['meta'] = $meta;
		self::$held   = 0;
		return $data;
	}

	/**
	 * A bundled mail sent on its own ("Send now"): the digest must not list it again.
	 *
	 * @param mixed $row Log row.
	 */
	public static function released( $row ): void {
		if ( ! is_array( $row ) ) {
			return;
		}
		$meta = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		if ( self::HELD !== ( $meta['delivery']['held'] ?? '' ) ) {
			return;
		}
		$meta['delivery']['held'] = self::RELEASED;
		( new Repository() )->update( (int) $row['id'], array( 'meta' => Logger::encode( $meta ) ) );
	}

	/** Test helper: forget per-request state. */
	public static function reset_request(): void {
		self::$held           = 0;
		self::$password_reset = false;
		self::$logged         = false;
		self::$admins         = null;
	}
}
