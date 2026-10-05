<?php
/**
 * "Copy for support": one ready-to-paste sentence per logged email for answering a customer – in the site
 * language, with the site's date and time format, the full recipient address and the delivery result in plain
 * words. Never technical details (no SMTP host, error message or internals).
 *
 * Added to the detail payload (REST mails/{id}) as "support"; workflow.js copies it.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use Mailspur\Repository;
use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Support {

	/**
	 * Adds the support sentence to the detail view (REST mails/{id}).
	 *
	 * @param mixed $item REST item.
	 * @param mixed $row  Log row.
	 * @return mixed
	 */
	public static function rest_item( $item, $row ) {
		if ( ! is_array( $item ) || ! is_array( $row ) ) {
			return $item;
		}
		try {
			$meta            = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
			$item['support'] = self::sentence(
				(int) ( $row['status'] ?? Repository::STATUS_PENDING ),
				(string) ( $row['subject'] ?? '' ),
				(string) ( $row['recipients'] ?? '' ),
				(int) strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' ),
				(string) ( $meta['delivery']['held'] ?? '' )
			);
		} catch ( \Throwable $e ) {
			return $item; // The dialog must never break because of this extra line.
		}
		return $item;
	}

	/**
	 * Plain-language sentence for a customer.
	 *
	 * @param int    $status     Repository::STATUS_*.
	 * @param string $subject    Subject as logged.
	 * @param string $recipients Recipients as logged ("a@example.com, Name <b@example.com>").
	 * @param int    $time       Unix time of the email.
	 * @param string $held       Why a held email was held (meta delivery.held): staging, brake, brake_released …
	 */
	public static function sentence( int $status, string $subject, string $recipients, int $time, string $held = '' ): string {
		$subject = trim( (string) preg_replace( '/\s+/u', ' ', $subject ) );
		$subject = '' === $subject ? __( '(no subject)', 'mailspur-email-log' ) : $subject;
		$to      = self::addresses( $recipients );
		$date    = (string) wp_date( (string) get_option( 'date_format' ), $time );
		$clock   = (string) wp_date( (string) get_option( 'time_format' ), $time );

		switch ( $status ) {
			case Repository::STATUS_SENT:
				/* translators: 1: email subject, 2: recipient address(es), 3: date, 4: time */
				$text = __( 'The email “%1$s” was sent to %2$s on %3$s at %4$s and accepted by the mail server. Please also check your spam folder.', 'mailspur-email-log' );
				break;
			case Repository::STATUS_FAILED:
				/* translators: 1: email subject, 2: recipient address(es), 3: date, 4: time */
				$text = __( 'The email “%1$s” to %2$s on %3$s at %4$s could not be delivered. We will send it again.', 'mailspur-email-log' );
				break;
			case Repository::STATUS_HELD:
				if ( 'brake_released' === $held ) {
					/* translators: 1: email subject, 2: recipient address(es), 3: date, 4: time */
					$text = __( 'The email “%1$s” to %2$s from %3$s at %4$s was briefly held back by a safety check on our side and sent afterwards. Please also check your spam folder.', 'mailspur-email-log' );
				} elseif ( 0 === strpos( $held, 'brake' ) ) {
					/* translators: 1: email subject, 2: recipient address(es), 3: date, 4: time */
					$text = __( 'The email “%1$s” to %2$s from %3$s at %4$s was held back by a safety check on our side and has not been sent. We will send it again.', 'mailspur-email-log' );
				} else {
					/* translators: 1: email subject, 2: recipient address(es), 3: date, 4: time */
					$text = __( 'The email “%1$s” to %2$s from %3$s at %4$s was not sent because sending emails was paused on our side at that time. We will send it again.', 'mailspur-email-log' );
				}
				break;
			default:
				/* translators: 1: email subject, 2: recipient address(es), 3: date, 4: time */
				$text = __( 'The email “%1$s” to %2$s was created on %3$s at %4$s, but we cannot confirm whether it was delivered. We will check this and send it again if needed.', 'mailspur-email-log' );
		}
		return sprintf( $text, $subject, $to, $date, $clock );
	}

	/** Bare addresses ("Anna <anna@example.com>" → "anna@example.com"), comma-separated; the text as is otherwise. */
	public static function addresses( string $recipients ): string {
		if ( preg_match_all( '/[^\s<>,;"\']+@[^\s<>,;"\']+/u', $recipients, $matches ) ) {
			return implode( ', ', array_unique( $matches[0] ) );
		}
		return trim( $recipients );
	}
}
