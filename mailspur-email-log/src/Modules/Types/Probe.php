<?php
/**
 * Probe emails ("Trigger to me"): WordPress generates a core email for real – for the administrator's own
 * account, sent only to that administrator – and the result is read back from the log.
 *
 * - password-reset: retrieve_password() for the administrator. WordPress creates a new reset key for that
 *   account (earlier reset links stop working); the password itself stays the same.
 * - new-user: wp_new_user_notification() for the administrator's own account, both the notice to the site
 *   administrator and the "Login Details" email (again with a new reset key). No user is created.
 *
 * While a probe runs, every email gets the administrator as its only recipient (Cc/Bcc removed), is marked
 * as probe in the log meta ($meta['probe']) and is never held by the emergency brake. Staging mode still applies.
 *
 * REST: POST /types/probe { kind } (manage_options). WP-CLI: wp mailspur probe (ProbeCli).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Admin;
use Mailspur\Repository;
use Mailspur\Rest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class Probe {

	const PASSWORD_RESET = 'password-reset';
	const NEW_USER       = 'new-user';
	const KINDS          = array( self::PASSWORD_RESET, self::NEW_USER );

	/** @var string Kind of the probe in progress ('' = none). */
	private $kind = '';

	/** @var string Only recipient while a probe runs. */
	private $to = '';

	/** @var float[] Start times of the emails in flight. */
	private $started = array();

	/**
	 * Emails of the probe in progress.
	 *
	 * @var array<int,array{id:int,subject:string,status:string,duration:int,notes:int,error:string}>
	 */
	private $mails = array();

	/** @var array<string,string>|null Endings of the core subjects in the languages of this site => kind. */
	private static $endings = null;

	/** @var string Current and site locale the endings were built for. */
	private static $endings_for = '';

	/**
	 * Probe kind of a template kind ("new-user-admin") or of a core subject pattern, or null. Core subjects are
	 * recognised in English and in the language WordPress sends them in on this site (older emails have no
	 * template kind yet).
	 */
	public static function kind( string $text ): ?string {
		if ( in_array( $text, self::KINDS, true ) ) {
			return $text;
		}
		if ( self::NEW_USER . '-admin' === $text ) {
			return self::NEW_USER;
		}
		if ( preg_match( '/\bPassword Reset$/i', $text ) ) {
			return self::PASSWORD_RESET;
		}
		if ( preg_match( '/\b(New User Registration|Login Details)$/i', $text ) ) {
			return self::NEW_USER;
		}
		$lower = self::lower( $text );
		foreach ( self::endings() as $ending => $kind ) {
			if ( strlen( $lower ) > strlen( $ending ) && substr( $lower, -strlen( $ending ) ) === $ending ) {
				return $kind;
			}
		}
		return null;
	}

	/**
	 * The text after the site name placeholder of each core subject ("] Passwort zurücksetzen"), translated into
	 * the current language and the site language – the password reset goes out in the user's language, the
	 * notice to the administrator in the site language.
	 *
	 * @return array<string,string> Lower-cased ending => kind.
	 */
	private static function endings(): array {
		$locale = determine_locale();
		$site   = get_locale();
		if ( null !== self::$endings && self::$endings_for === $locale . '|' . $site ) {
			return self::$endings;
		}
		$subjects = self::subjects();
		if ( $site !== $locale && switch_to_locale( $site ) ) {
			$subjects = array_merge( $subjects, self::subjects() );
			restore_previous_locale();
		}
		self::$endings     = array();
		self::$endings_for = $locale . '|' . $site;
		foreach ( $subjects as $subject ) {
			$parts  = (array) preg_split( '/%(?:\d+\$)?s/', $subject[0] );
			$ending = self::lower( trim( (string) end( $parts ) ) );
			if ( preg_match( '/\p{L}{3}/u', $ending ) ) { // Only a distinctive ending, not just "]".
				self::$endings[ $ending ] = $subject[1];
			}
		}
		return self::$endings;
	}

	/**
	 * Core subjects in the current language (WordPress' own strings, default text domain).
	 *
	 * @return array<int,array{0:string,1:string}> Subject format and kind.
	 */
	private static function subjects(): array {
		return array(
			// phpcs:disable WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.TextDomainMismatch -- WordPress' own subjects, only looked up (never output) to recognise core emails.
			array( translate( '[%s] Password Reset', 'default' ), self::PASSWORD_RESET ),
			array( translate( '[%s] New User Registration', 'default' ), self::NEW_USER ),
			array( translate( '[%s] Login Details', 'default' ), self::NEW_USER ),
			// phpcs:enable WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.TextDomainMismatch
		);
	}

	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	public function register_routes(): void {
		register_rest_route(
			Rest::NS,
			'/types/probe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'kind' => array(
						'type'     => 'string',
						'enum'     => self::KINDS,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest( WP_REST_Request $request ) {
		$result = $this->run( (string) $request['kind'], wp_get_current_user() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( $result as $i => $mail ) {
			$result[ $i ]['url'] = $mail['id'] ? Admin::url( array( 'mail' => (string) $mail['id'] ) ) : '';
		}
		$response = new WP_REST_Response( array( 'mails' => $result ) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Triggers the core email(s) of a kind for an administrator's own account.
	 *
	 * @return array<int,array{id:int,subject:string,status:string,duration:int,notes:int,error:string}>|WP_Error
	 *         The logged emails (duration in milliseconds).
	 */
	public function run( string $kind, WP_User $user ) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new WP_Error( 'mailspur_probe_kind', __( 'Unknown probe.', 'mailspur-email-log' ), array( 'status' => 400 ) );
		}
		if ( ! $user->exists() || ! user_can( $user, 'manage_options' ) || ! is_email( $user->user_email ) ) {
			return new WP_Error( 'mailspur_probe_user', __( 'Probe emails are only sent to an administrator.', 'mailspur-email-log' ), array( 'status' => 403 ) );
		}

		$this->kind    = $kind;
		$this->to      = (string) $user->user_email;
		$this->started = array();
		$this->mails   = array();
		add_filter( 'wp_mail', array( $this, 'start' ), PHP_INT_MIN );
		add_filter( 'wp_mail', array( $this, 'guard' ), PHP_INT_MAX - 1 ); // Before the Logger records the recipients.
		add_filter( 'mailspur_meta', array( $this, 'mark' ), 10, 2 );
		add_filter( 'mailspur_brake_exempt', array( $this, 'exempt' ) );
		add_action( 'mailspur_logged', array( $this, 'logged' ), 10, 2 );

		$error = null;
		try {
			if ( self::PASSWORD_RESET === $kind ) {
				$result = retrieve_password( $user->user_login );
				if ( is_wp_error( $result ) ) {
					$error = $result;
				}
			} else {
				wp_new_user_notification( $user->ID, null, 'both' );
			}
		} catch ( \Throwable $e ) {
			$error = new WP_Error( 'mailspur_probe_error', $e->getMessage() );
		} finally {
			remove_filter( 'wp_mail', array( $this, 'start' ), PHP_INT_MIN );
			remove_filter( 'wp_mail', array( $this, 'guard' ), PHP_INT_MAX - 1 );
			remove_filter( 'mailspur_meta', array( $this, 'mark' ), 10 );
			remove_filter( 'mailspur_brake_exempt', array( $this, 'exempt' ) );
			remove_action( 'mailspur_logged', array( $this, 'logged' ), 10 );
			$this->kind = '';
		}

		$mails = $this->collected();
		if ( ! $mails ) {
			return null !== $error ? new WP_Error( 'mailspur_probe_failed', $error->get_error_message(), array( 'status' => 500 ) )
				: new WP_Error( 'mailspur_probe_none', __( 'WordPress did not send an email – a plugin may have turned this email off.', 'mailspur-email-log' ), array( 'status' => 500 ) );
		}
		return $mails;
	}

	/**
	 * The logged emails plus those that started but were never resolved (e.g. a pluggable wp_mail() without
	 * result hooks).
	 *
	 * @return array<int,array{id:int,subject:string,status:string,duration:int,notes:int,error:string}>
	 */
	private function collected(): array {
		$mails   = $this->mails;
		$started = count( $this->started );
		for ( $i = count( $mails ); $i < $started; $i++ ) {
			$mails[] = array(
				'id'       => 0,
				'subject'  => '',
				'status'   => 'pending',
				'duration' => 0,
				'notes'    => 0,
				'error'    => '',
			);
		}
		return $mails;
	}

	/**
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed Unchanged.
	 */
	public function start( $atts ) {
		$this->started[] = microtime( true );
		return $atts;
	}

	/**
	 * Only the administrator receives probe emails: the recipient is replaced, Cc and Bcc are dropped.
	 *
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed
	 */
	public function guard( $atts ) {
		if ( ! is_array( $atts ) || '' === $this->kind ) {
			return $atts;
		}
		$atts['to'] = array( $this->to );
		$headers    = $atts['headers'] ?? array();
		$headers    = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );

		$atts['headers'] = array_values(
			array_filter(
				array_map( 'strval', $headers ),
				static function ( string $header ): bool {
					return '' !== trim( $header ) && ! preg_match( '/^\s*b?cc\s*:/i', $header );
				}
			)
		);
		return $atts;
	}

	/** Probe emails are never held by the emergency brake. */
	public function exempt(): bool {
		return true;
	}

	/**
	 * @param mixed $meta  Meta collected so far.
	 * @param mixed $phase Logger phase.
	 * @return mixed
	 */
	public function mark( $meta, $phase = '' ) {
		if ( 'capture' === $phase && is_array( $meta ) && '' !== $this->kind ) {
			$meta['probe'] = $this->kind;
		}
		return $meta;
	}

	/**
	 * @param mixed $id  Log entry id.
	 * @param mixed $row Logged row.
	 */
	public function logged( $id, $row = array() ): void {
		$row      = is_array( $row ) ? $row : array();
		$start    = $this->started[ count( $this->mails ) ] ?? microtime( true );
		$statuses = array(
			Repository::STATUS_SENT   => 'sent',
			Repository::STATUS_FAILED => 'failed',
			Repository::STATUS_HELD   => 'held',
		);

		$this->mails[] = array(
			'id'       => (int) $id,
			'subject'  => (string) ( $row['subject'] ?? '' ),
			'status'   => $statuses[ (int) ( $row['status'] ?? 0 ) ] ?? 'pending',
			'duration' => max( 0, (int) round( ( microtime( true ) - $start ) * 1000 ) ),
			'notes'    => (int) ( $row['notes'] ?? 0 ),
			'error'    => (string) ( $row['error'] ?? '' ),
		);
	}
}
