<?php
/**
 * Staging mode: hold every mail (log only) or redirect all mails to test addresses.
 *
 * Order per mail (see Logger):
 *   wp_mail      PHP_INT_MAX - 1  redirect()   → recipients/subject rewritten, originals remembered
 *   wp_mail      PHP_INT_MAX      Logger::capture → mailspur_meta 'capture' → meta() stores the originals
 *   pre_wp_mail  PHP_INT_MIN      hold()       → returns true FIRST, so API mailers hooked on pre_wp_mail
 *                                               see the mail as handled and do not deliver it either
 *   pre_wp_mail  PHP_INT_MAX      Logger::short_circuit → mailspur_finalize_row → finalize() sets "held"
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Staging {

	const OFF      = 'off';
	const HOLD     = 'hold';
	const REDIRECT = 'redirect';
	const MODES    = array( self::OFF, self::HOLD, self::REDIRECT );

	/** Maximum number of redirect addresses. */
	const MAX_ADDRESSES = 10;

	/**
	 * Set while a held mail is released ("Send now"): the next mails bypass staging mode.
	 *
	 * @var int Id of the released log entry, 0 = no release in progress.
	 */
	public static $release = 0;

	/**
	 * Original recipients of the mail being redirected, until the logger's capture phase picks them up.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $redirected = null;

	/**
	 * Why the current mail was held ('' = not held). Set in pre_wp_mail, consumed by finalize().
	 *
	 * @var string
	 */
	private static $held = '';

	public function register(): void {
		add_filter( 'wp_mail', array( $this, 'redirect' ), PHP_INT_MAX - 1 );
		// Earliest possible: on a staging copy no other pre_wp_mail handler (API mailers) may send for real.
		add_filter( 'pre_wp_mail', array( $this, 'hold' ), PHP_INT_MIN );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 10, 2 );
		add_filter( 'mailspur_finalize_row', array( $this, 'finalize' ) );
	}

	public static function mode(): string {
		$mode = (string) Settings::get( 'staging_mode' );
		return in_array( $mode, self::MODES, true ) ? $mode : self::OFF;
	}

	public static function active(): bool {
		return self::OFF !== self::mode();
	}

	/**
	 * Valid redirect addresses from the settings.
	 *
	 * @return array<int,string>
	 */
	public static function addresses(): array {
		return self::parse_addresses( (string) Settings::get( 'staging_redirect_to' ) );
	}

	/**
	 * Valid, unique addresses of a comma/semicolon/whitespace-separated list (max. MAX_ADDRESSES).
	 *
	 * @return array<int,string>
	 */
	public static function parse_addresses( string $addresses ): array {
		$out = array();
		foreach ( (array) preg_split( '/[\s,;]+/', $addresses ) as $address ) {
			$address = trim( (string) $address );
			if ( '' !== $address && is_email( $address ) && ! in_array( strtolower( $address ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $address;
			}
		}
		return array_slice( $out, 0, self::MAX_ADDRESSES );
	}

	/**
	 * Effective behaviour: redirect mode without a valid address holds the mail instead (fail safe).
	 */
	public static function effective_mode(): string {
		$mode = self::mode();
		return self::REDIRECT === $mode && ! self::addresses() ? self::HOLD : $mode;
	}

	/**
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed
	 */
	public function redirect( $atts ) {
		self::$redirected = null;
		if ( ! is_array( $atts ) || self::$release || self::REDIRECT !== self::effective_mode() ) {
			return $atts;
		}

		$targets = self::addresses();
		$to      = self::to_list( $atts['to'] ?? array(), ',' );
		$headers = self::to_list( $atts['headers'] ?? array(), "\n" );
		$cc      = array();
		$bcc     = array();
		$keep    = array();
		foreach ( $headers as $header ) {
			if ( preg_match( '/^(cc|bcc)\s*:\s*(.*)$/i', $header, $m ) ) {
				if ( 'cc' === strtolower( $m[1] ) ) {
					$cc = array_merge( $cc, self::to_list( $m[2], ',' ) );
				} else {
					$bcc = array_merge( $bcc, self::to_list( $m[2], ',' ) );
				}
				continue;
			}
			$keep[] = $header;
		}

		$original = $to ? implode( ', ', $to ) : '-';
		$subject  = (string) ( $atts['subject'] ?? '' );
		/**
		 * Subject of a mail redirected by staging mode.
		 *
		 * @param string            $prefixed Subject with the "[Staging → original recipients]" prefix.
		 * @param string            $subject  Original subject.
		 * @param array<int,string> $to       Original recipients.
		 */
		$atts['subject'] = (string) apply_filters( 'mailspur_staging_subject', sprintf( '[Staging → %s] %s', $original, $subject ), $subject, $to );
		$atts['to']      = $targets;
		$atts['headers'] = $keep;

		self::$redirected = array_filter(
			array(
				'original_to'  => $to,
				'original_cc'  => $cc,
				'original_bcc' => $bcc,
				'redirected'   => true,
			)
		);
		return $atts;
	}

	/**
	 * @param null|bool $result Non-null: another plugin already handled the mail.
	 * @return null|bool
	 */
	public function hold( $result ) {
		self::$held = '';
		if ( null !== $result || self::$release || self::HOLD !== self::effective_mode() ) {
			return $result;
		}
		self::$held = self::REDIRECT === self::mode() ? 'no_redirect_address' : 'staging';
		return true;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public function meta( $meta, string $phase ) {
		$meta = (array) $meta;
		if ( 'capture' !== $phase ) {
			return $meta;
		}
		self::$held = '';
		$delivery   = array();
		if ( self::$redirected ) {
			$delivery         = self::$redirected;
			self::$redirected = null;
		}
		if ( self::$release ) {
			$delivery['released_from'] = self::$release;
		}
		if ( $delivery ) {
			$meta['delivery'] = $delivery;
		}
		return $meta;
	}

	/**
	 * @param array<string,mixed> $data Columns of the final UPDATE.
	 * @return array<string,mixed>
	 */
	public function finalize( $data ) {
		$data = (array) $data;
		if ( '' === self::$held ) {
			return $data;
		}
		$data['status'] = Repository::STATUS_HELD;
		$meta           = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		$meta['delivery']         = isset( $meta['delivery'] ) && is_array( $meta['delivery'] ) ? $meta['delivery'] : array();
		$meta['delivery']['held'] = self::$held;

		$data['meta'] = $meta;
		self::$held   = '';
		return $data;
	}

	/** Test helper: forget per-request state. */
	public static function reset(): void {
		self::$release    = 0;
		self::$redirected = null;
		self::$held       = '';
	}

	/**
	 * Same splitting rules as wp_mail() for string arguments.
	 *
	 * @param mixed            $value
	 * @param non-empty-string $separator
	 * @return array<int,string>
	 */
	private static function to_list( $value, string $separator ): array {
		if ( ! is_array( $value ) ) {
			$value = explode( $separator, str_replace( "\r\n", "\n", (string) $value ) );
		}
		return array_values(
			array_filter(
				array_map( 'trim', array_map( 'strval', $value ) ),
				static function ( string $item ): bool {
					return '' !== $item;
				}
			)
		);
	}
}
