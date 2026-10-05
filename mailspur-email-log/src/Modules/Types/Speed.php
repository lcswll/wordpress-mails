<?php
/**
 * Slow emails: how long a type keeps someone waiting. The trace measures the time wp_mail() took; for emails
 * sent while a person waits for the page (front end, Ajax, REST, XML-RPC, admin screens – not WP-Cron or
 * WP-CLI), the latest durations are kept per type as rounded milliseconds. Pure functions.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Speed {

	/** Request types in which someone waits for wp_mail() to return. */
	const WAITING = array( 'frontend', 'ajax', 'rest', 'xmlrpc', 'admin' );

	/** Durations kept per type (the latest ones). */
	const SAMPLES = 20;

	/** Fewer measurements say nothing. */
	const MIN_SAMPLES = 5;

	/** Median from which a type counts as slow. */
	const SLOW_MS = 1500;

	/**
	 * Adds the duration of one traced email.
	 *
	 * @param array<string,mixed> $extra Type state.
	 * @param array<string,mixed> $trace The email's trace (meta "trace").
	 * @return array<string,mixed>
	 */
	public static function observe( array $extra, array $trace ): array {
		$request = isset( $trace['request'] ) && is_array( $trace['request'] ) ? $trace['request'] : array();
		if ( ! in_array( $request['type'] ?? '', self::WAITING, true ) || ! isset( $trace['total_ms'] ) || ! is_numeric( $trace['total_ms'] ) ) {
			return $extra;
		}
		$samples      = isset( $extra['dur'] ) && is_array( $extra['dur'] ) ? array_values( $extra['dur'] ) : array();
		$samples[]    = max( 0, (int) round( (float) $trace['total_ms'] ) );
		$extra['dur'] = array_slice( $samples, -self::SAMPLES );
		$mailer       = isset( $trace['transport'] ) && is_array( $trace['transport'] ) ? (string) ( $trace['transport']['mailer'] ?? '' ) : '';
		if ( '' !== $mailer ) {
			$extra['tx'] = substr( $mailer, 0, 20 );
		}
		return $extra;
	}

	/**
	 * The type's waiting time when it is slow.
	 *
	 * @param array<string,mixed> $extra Type state.
	 * @return array{median:int,average:int,n:int,mailer:string}|null
	 */
	public static function of( array $extra ): ?array {
		$samples = isset( $extra['dur'] ) && is_array( $extra['dur'] ) ? array_map( 'intval', $extra['dur'] ) : array();
		if ( count( $samples ) < self::MIN_SAMPLES ) {
			return null;
		}
		$median = self::median( $samples );
		if ( $median < self::SLOW_MS ) {
			return null;
		}
		return array(
			'median'  => $median,
			'average' => (int) round( array_sum( $samples ) / count( $samples ) ),
			'n'       => count( $samples ),
			'mailer'  => (string) ( $extra['tx'] ?? '' ),
		);
	}

	/**
	 * @param int[] $values Non-empty.
	 */
	public static function median( array $values ): int {
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );
		return $n % 2 ? $values[ $mid ] : (int) round( ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 );
	}

	/**
	 * What usually helps, depending on how the site sends.
	 *
	 * @param string $mailer Transport of the latest traced email: smtp, mail, sendmail, qmail or api.
	 * @return string[]
	 */
	public static function tips( string $mailer ): array {
		$tips = array();
		if ( 'smtp' === $mailer ) {
			$tips[] = __( 'Check the SMTP host and port: a wrong port or a blocked connection often ends in a timeout before a fallback works.', 'mailspur-email-log' );
			$tips[] = __( 'An email provider with an HTTP API instead of SMTP needs fewer round trips per email.', 'mailspur-email-log' );
		} elseif ( 'api' === $mailer ) {
			$tips[] = __( 'The email API answers slowly: check the region or endpoint configured in your mailer plugin.', 'mailspur-email-log' );
		} else {
			$tips[] = __( 'The server’s local mail program answers slowly: an SMTP or API mailer plugin hands emails to a provider instead.', 'mailspur-email-log' );
		}
		$tips[] = __( 'Sending in the background (a mailer plugin with a queue) lets the page finish without waiting.', 'mailspur-email-log' );
		return $tips;
	}
}
