<?php
/**
 * Delivery status from the email provider (opt-in, setting feedback_provider): the provider reports deliveries,
 * bounces and spam complaints to a webhook URL of this site, and the logged email shows the result. Hard
 * bounces count for the problem recipients.
 *
 * Endpoint (public, see Controller): POST /delivery/webhook/{provider}/{key}
 *   key       random secret of this site (constant-time comparison), only the configured provider is accepted;
 *             a new one can be created in the settings (the old URL stops working at once)
 *   Mailgun   additionally the HMAC signature with the signing key (replays rejected), SES the SNS signature;
 *             Mailgun's legacy webhooks (form-encoded, signature fields in the body) are accepted as well
 * The payload is only trusted for the status: an event changes nothing but meta.feedback and the indexed
 * "delivery" column of the log entry it matches (action mailspur_delivery_status), and a hard bounce counts only
 * for a recipient of that entry (To, Cc or Bcc).
 *
 * Matching an event to a log entry:
 *   1. ref: a random reference stored in meta.feedback.ref and sent along in a header the provider echoes back
 *      (Postmark metadata, Mailgun variables, Brevo X-Mailin-custom, SES message tag) – SMTP and API mailers
 *      that pass custom headers on;
 *   2. the Message-ID header (meta.message_id, recorded for every mail sent through PHPMailer);
 *   3. without a reference: the latest email to the recipient shortly before (DELIVERED_WINDOW / BOUNCE_WINDOW).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lookups on the own table, created_at range first.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Logger;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Feedback {

	const PROVIDERS = array( 'postmark', 'mailgun', 'brevo', 'ses' );

	const SECRET_OPTION = 'mailspur_feedback_secret';
	const SEEN_PREFIX   = 'mailspur_feedback_seen_';

	/** Largest accepted request body (bytes). */
	const MAX_BODY = 262144;

	/** Log entries searched by reference or Message-ID: the last … days. */
	const LOOKBACK_DAYS = 45;

	/** Without a reference: a delivery is matched to an email sent at most this long before. */
	const DELIVERED_WINDOW = 6 * 3600;

	/** … a bounce or complaint to one sent at most this long before. */
	const BOUNCE_WINDOW = 7 * 86400;

	/** Which status wins when several events arrive for one email. */
	const RANK = array(
		'soft_bounce' => 1,
		'delivered'   => 2,
		'bounced'     => 3,
		'complaint'   => 4,
	);

	/**
	 * Clock, replaceable in tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/** @var Problems */
	private $problems;

	/** @var Sns */
	private $sns;

	/** @var \PHPMailer\PHPMailer\PHPMailer|null Mailer of the mail being sent (for its Message-ID after sending). */
	private static $mailer = null;

	/**
	 * @param (callable():int)|null $now
	 */
	public function __construct( ?callable $now = null, ?Problems $problems = null, ?Sns $sns = null ) {
		$this->now      = $now ?? 'time';
		$this->problems = $problems ?? new Problems( $this->now );
		$this->sns      = $sns ?? new Sns();
	}

	public function register(): void {
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 10, 3 );
	}

	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	/** Configured provider, '' = off. */
	public static function provider(): string {
		$provider = (string) Settings::get( 'feedback_provider' );
		return in_array( $provider, self::PROVIDERS, true ) ? $provider : '';
	}

	/** Secret of the webhook URL ('' when none was created yet and $create is false). */
	public static function secret( bool $create = false ): string {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( is_string( $secret ) && preg_match( '/^[A-Za-z0-9]{32}$/', $secret ) ) {
			return $secret;
		}
		if ( ! $create ) {
			return '';
		}
		$secret = wp_generate_password( 32, false, false );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	/** Replaces the secret: every webhook URL changes, the old ones are rejected from now on. */
	public static function regenerate(): string {
		delete_option( self::SECRET_OPTION );
		return self::secret( true );
	}

	/**
	 * Webhook URLs of all providers (settings UI).
	 *
	 * @return array<string,string>
	 */
	public static function urls(): array {
		$urls = array();
		foreach ( self::PROVIDERS as $provider ) {
			$urls[ $provider ] = self::url( $provider );
		}
		return $urls;
	}

	/** Webhook URL to paste into the provider's settings. */
	public static function url( string $provider ): string {
		return rest_url( Rest::NS . '/delivery/webhook/' . $provider . '/' . self::secret( true ) );
	}

	/**
	 * Header the provider echoes back in its webhooks (name => value), or null for an unknown provider.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function header( string $provider, string $ref ): ?array {
		switch ( $provider ) {
			case 'postmark':
				return array( 'X-PM-Metadata-' . Webhooks::KEY, $ref );
			case 'mailgun':
				return array( 'X-Mailgun-Variables', (string) wp_json_encode( array( Webhooks::KEY => $ref ) ) );
			case 'brevo':
				return array( 'X-Mailin-custom', Webhooks::KEY . ':' . $ref );
			case 'ses':
				return array( 'X-SES-MESSAGE-TAGS', Webhooks::KEY . '=' . $ref );
		}
		return null;
	}

	/* -------------------------------------------------------------- per mail */

	/**
	 * capture: new reference (when a provider is configured); phpmailer: reference header; result: Message-ID.
	 *
	 * @param array<string,mixed> $meta
	 * @param mixed               $context
	 * @return array<string,mixed>
	 */
	public function meta( $meta, string $phase, $context = null ) {
		$meta = (array) $meta;
		try {
			if ( 'capture' === $phase ) {
				self::$mailer = null;
				if ( '' !== self::provider() ) {
					$meta['feedback'] = array( 'ref' => bin2hex( random_bytes( 8 ) ) );
				}
			} elseif ( 'phpmailer' === $phase && $context instanceof \PHPMailer\PHPMailer\PHPMailer ) {
				self::$mailer = $context;
				$ref          = (string) ( $meta['feedback']['ref'] ?? '' );
				$header       = '' !== $ref ? self::header( self::provider(), $ref ) : null;
				if ( $header && ! self::has_header( $context, $header[0] ) ) {
					$context->addCustomHeader( $header[0], $header[1] );
				}
			} elseif ( 'result' === $phase ) {
				$mailer       = self::$mailer;
				self::$mailer = null;
				$status       = is_array( $context ) ? ( $context['status'] ?? null ) : null;
				// Only after a successful send: then PHPMailer built the headers of exactly this mail.
				if ( $mailer && Repository::STATUS_SENT === $status ) {
					$id = trim( (string) $mailer->getLastMessageID() );
					if ( '' !== $id && strlen( $id ) <= 250 ) {
						$meta['message_id'] = $id;
					}
				}
			}
		} catch ( \Throwable $e ) { // Must never break delivery.
			return $meta;
		}
		return $meta;
	}

	private static function has_header( \PHPMailer\PHPMailer\PHPMailer $mailer, string $name ): bool {
		foreach ( (array) $mailer->getCustomHeaders() as $header ) {
			if ( is_array( $header ) && 0 === strcasecmp( (string) ( $header[0] ?? '' ), $name ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------- webhook */

	/** permission_callback: feature on, the configured provider and the secret of this site. */
	public function authorize( WP_REST_Request $request ): bool {
		$provider = self::provider();
		$secret   = self::secret();
		// URL parameters only: JSON body fields would take precedence over them in $request['…'].
		$url = (array) $request->get_url_params();
		return '' !== $provider && '' !== $secret
			&& hash_equals( $provider, (string) ( $url['provider'] ?? '' ) )
			&& hash_equals( $secret, (string) ( $url['key'] ?? '' ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( WP_REST_Request $request ) {
		$provider = self::provider();
		$body     = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'mailspur_too_large', 'Payload too large.', array( 'status' => 413 ) );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) && 'mailgun' === $provider ) {
			$payload = Webhooks::mailgun_legacy( $body );
		}
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mailspur_invalid', 'Invalid JSON.', array( 'status' => 400 ) );
		}

		$now = $this->now();
		if ( 'mailgun' === $provider ) {
			$token = Webhooks::mailgun_token( $payload );
			if ( ! Webhooks::verify_mailgun( $payload, (string) Settings::get( 'feedback_signing_key' ), $now ) || ! $this->first_time( 'mg-token-' . $token, Webhooks::MAILGUN_TOLERANCE * 2 ) ) {
				return new WP_Error( 'mailspur_signature', 'Invalid signature.', array( 'status' => 401 ) );
			}
		} elseif ( 'ses' === $provider ) {
			$result = $this->sns->handle( $payload, $now );
			if ( 'invalid' === $result['status'] ) {
				return new WP_Error( 'mailspur_signature', 'Invalid signature.', array( 'status' => 401 ) );
			}
			if ( 'notification' !== $result['status'] || ! $this->first_time( 'sns-' . (string) $payload['MessageId'], DAY_IN_SECONDS ) ) {
				return self::response( array( 'status' => $result['status'] ) );
			}
			$payload = (array) $result['message'];
		}

		$events  = Webhooks::parse( $provider, $payload );
		$matched = 0;
		foreach ( $events as $event ) {
			$key = implode( '|', array( $provider, $event['id'], $event['event'], $event['recipient'], $event['ref'], $event['message_id'] ) );
			if ( $this->first_time( $key, 2 * DAY_IN_SECONDS ) && $this->apply( $event, $provider ) ) {
				++$matched;
			}
		}
		return self::response(
			array(
				'received' => count( $events ),
				'matched'  => $matched,
			)
		);
	}

	/** @param array<string,mixed> $data */
	private static function response( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Remembers a key for $ttl seconds; false when it was seen before (retries, replays). */
	private function first_time( string $key, int $ttl ): bool {
		$name = self::SEEN_PREFIX . md5( $key );
		if ( false !== get_transient( $name ) ) {
			return false;
		}
		set_transient( $name, 1, $ttl );
		return true;
	}

	/**
	 * Stores an event on its log entry; a hard bounce counts for the problem recipients.
	 *
	 * @param array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string} $event
	 * @return bool Whether a log entry matched.
	 */
	public function apply( array $event, string $provider ): bool {
		$row = $this->find( $event );
		if ( ! $row ) {
			return false;
		}
		$meta     = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		$current  = isset( $meta['feedback'] ) && is_array( $meta['feedback'] ) ? $meta['feedback'] : array();
		$new_rank = self::rank( $event['event'], $event['hard'] );
		$old_rank = isset( $current['event'] ) ? self::rank( (string) $current['event'], ! empty( $current['hard'] ) ) : 0;
		if ( $new_rank >= $old_rank ) {
			$meta['feedback'] = array_merge(
				$current,
				array(
					'event' => $event['event'],
					'hard'  => $event['hard'],
					'at'    => $this->now(),
					'via'   => $provider,
				)
			);
			$code             = Repository::delivery_code( $event['event'], $event['hard'] );
			$before           = isset( $current['event'] ) ? Repository::delivery_code( (string) $current['event'], ! empty( $current['hard'] ) ) : 0;
			( new Repository() )->update(
				(int) $row['id'],
				array(
					'meta'     => Logger::encode( $meta ),
					'delivery' => $code,
				)
			);
			if ( $code !== $before ) {
				/**
				 * The provider status of a log entry changed (per-type counters of the Types module).
				 *
				 * @param int                 $id     Log entry id.
				 * @param string              $status New status (slug of Repository::DELIVERY).
				 * @param string              $before Previous status, '' for none.
				 * @param array<string,mixed> $row    id, created_at, source, subject.
				 */
				do_action(
					'mailspur_delivery_status',
					(int) $row['id'],
					Repository::delivery_slug( $code ),
					Repository::delivery_slug( $before ),
					array_intersect_key( $row, array_flip( array( 'id', 'created_at', 'source', 'subject' ) ) )
				);
			}
		}
		// Only a recipient of the entry itself (To, Cc, Bcc) – never an address that exists in the payload alone.
		if ( $event['hard'] && in_array( $event['recipient'], self::recipients( $row ), true ) ) {
			$this->problems->record( array( $event['recipient'] ), Problems::BOUNCE, (string) (int) $row['id'] );
		}
		return true;
	}

	/**
	 * Every address a log entry was sent to: To plus the Cc and Bcc headers.
	 *
	 * @param array<string,mixed> $row
	 * @return string[] Lower-cased.
	 */
	public static function recipients( array $row ): array {
		$list = (string) ( $row['recipients'] ?? '' );
		foreach ( explode( "\n", str_replace( "\r\n", "\n", (string) ( $row['headers'] ?? '' ) ) ) as $line ) {
			if ( preg_match( '/^\s*b?cc\s*:(.*)$/i', $line, $m ) ) {
				$list .= ',' . $m[1];
			}
		}
		return array_values( array_unique( Repository::extract_emails( $list ) ) );
	}

	private static function rank( string $event, bool $hard ): int {
		if ( Webhooks::BOUNCED === $event && ! $hard ) {
			return self::RANK['soft_bounce'];
		}
		return self::RANK[ $event ] ?? 0;
	}

	/**
	 * Log entry an event belongs to: by reference, by Message-ID, otherwise the latest email to the recipient
	 * (whose To list must then contain the recipient; Cc/Bcc recipients are only matched by reference).
	 *
	 * @param array{event:string,hard:bool,recipient:string,ref:string,message_id:string,id:string} $event
	 * @return array<string,mixed>|null id, created_at, source, subject, recipients, headers, meta.
	 */
	public function find( array $event ): ?array {
		global $wpdb;
		$now   = $this->now();
		$since = gmdate( 'Y-m-d H:i:s', $now - self::LOOKBACK_DAYS * DAY_IN_SECONDS );

		$needles = array();
		if ( '' !== $event['ref'] ) {
			$needles[] = '"ref":"' . $event['ref'] . '"';
		}
		if ( '' !== $event['message_id'] ) {
			$needles[] = '"message_id":' . wp_json_encode( '<' . $event['message_id'] . '>' );
		}
		foreach ( $needles as $needle ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, created_at, source, subject, recipients, headers, meta FROM %i WHERE created_at >= %s AND meta LIKE %s ORDER BY id DESC LIMIT 1',
					Repository::table(),
					$since,
					'%' . $wpdb->esc_like( $needle ) . '%'
				),
				ARRAY_A
			);
			if ( isset( $rows[0] ) && is_array( $rows[0] ) ) {
				return $rows[0];
			}
		}
		// A reference that matches nothing belongs to an entry that is gone (deleted, other site) – no guessing.
		if ( '' !== $event['ref'] ) {
			return null;
		}

		$window = Webhooks::DELIVERED === $event['event'] ? self::DELIVERED_WINDOW : self::BOUNCE_WINDOW;
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, source, subject, recipients, headers, meta FROM %i WHERE created_at BETWEEN %s AND %s AND status IN ( %d, %d ) AND recipients LIKE %s ORDER BY created_at DESC, id DESC LIMIT 20',
				Repository::table(),
				gmdate( 'Y-m-d H:i:s', $now - $window ),
				gmdate( 'Y-m-d H:i:s', $now + 300 ),
				Repository::STATUS_SENT,
				Repository::STATUS_PENDING,
				'%' . $wpdb->esc_like( $event['recipient'] ) . '%'
			),
			ARRAY_A
		);
		return self::first_with_recipient( $rows, $event['recipient'] );
	}

	/**
	 * @param array<int,mixed> $rows
	 * @return array<string,mixed>|null
	 */
	private static function first_with_recipient( array $rows, string $recipient ): ?array {
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && in_array( $recipient, Repository::extract_emails( (string) ( $row['recipients'] ?? '' ) ), true ) ) {
				return $row;
			}
		}
		return null;
	}
}
