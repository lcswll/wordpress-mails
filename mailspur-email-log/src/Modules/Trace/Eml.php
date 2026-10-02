<?php
/**
 * Raw MIME source of a log entry: the exact source stored at send time (optional setting) or a
 * reconstruction from the logged – already redacted – fields with a fresh PHPMailer instance that
 * only builds the message and never sends it.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Trace;

use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

final class Eml {

	/** Marker header of reconstructed messages. */
	const MARKER = 'X-Mailspur-Reconstructed';

	/** Larger messages (attachments) are not stored as raw source. */
	const MAX_RAW_BYTES = 10485760;

	/**
	 * Compresses a MIME message for the raw column.
	 */
	public static function pack( string $mime ): string {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary gzip data in a text column, not obfuscation.
		if ( function_exists( 'gzencode' ) ) {
			$gz = gzencode( $mime, 6 );
			if ( false !== $gz ) {
				return 'gz:' . base64_encode( $gz );
			}
		}
		return 'b64:' . base64_encode( $mime );
		// phpcs:enable
	}

	/**
	 * @return string The MIME message, or '' when the column holds nothing usable.
	 */
	public static function unpack( string $raw ): string {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see pack().
		if ( 0 === strpos( $raw, 'gz:' ) && function_exists( 'gzdecode' ) ) {
			$gz   = base64_decode( substr( $raw, 3 ), true );
			$mime = false === $gz ? false : gzdecode( $gz );
			return false === $mime ? '' : $mime;
		}
		if ( 0 === strpos( $raw, 'b64:' ) ) {
			return (string) base64_decode( substr( $raw, 4 ), true );
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Builds an RFC 822 message from a log row (no attachments: only their names are logged).
	 *
	 * @param array<string,string> $row Database row.
	 */
	public static function reconstruct( array $row ): string {
		$note = 'yes; built from the log entry (redacted content, no attachments)';
		try {
			$mime = self::with_phpmailer( $row, $note );
		} catch ( \Throwable $e ) {
			$mime = '';
		}
		return '' !== $mime ? $mime : self::plain( $row, $note );
	}

	/**
	 * @param array<string,string> $row
	 */
	private static function with_phpmailer( array $row, string $note ): string {
		if ( ! class_exists( PHPMailer::class ) ) {
			require_once ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php';
			require_once ABSPATH . 'wp-includes/PHPMailer/Exception.php';
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		$mail          = new PHPMailer( true );
		$mail->CharSet = 'UTF-8';
		$mail->Mailer  = 'smtp'; // Only so that To and Subject are part of the header; nothing is sent.

		$html = false !== strpos( strtolower( (string) ( $row['content_type'] ?? '' ) ), 'html' );
		foreach ( explode( "\n", str_replace( "\r", '', (string) ( $row['headers'] ?? '' ) ) ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			try {
				switch ( strtolower( $name ) ) {
					case 'from':
						list( $address, $label ) = self::address( $value );
						$mail->setFrom( $address, $label, false );
						break;
					case 'cc':
					case 'bcc':
					case 'reply-to':
						foreach ( explode( ',', $value ) as $item ) {
							list( $address, $label ) = self::address( $item );
							if ( 'cc' === strtolower( $name ) ) {
								$mail->addCC( $address, $label );
							} elseif ( 'bcc' === strtolower( $name ) ) {
								$mail->addBCC( $address, $label );
							} else {
								$mail->addReplyTo( $address, $label );
							}
						}
						break;
					case 'content-type':
						$html = $html || false !== stripos( $value, 'html' );
						if ( preg_match( '/charset\s*=\s*"?([\w-]+)/i', $value, $m ) ) {
							$mail->CharSet = $m[1];
						}
						break;
					case 'to':
					case 'subject':
					case 'date':
					case 'mime-version':
					case 'content-transfer-encoding':
					case 'message-id':
						break; // Set by PHPMailer.
					default:
						$mail->addCustomHeader( $name, $value );
				}
			} catch ( \Throwable $e ) {
				continue; // Skip unusable headers, keep the rest.
			}
		}

		// The final sender PHPMailer used wins over the From header.
		if ( '' !== (string) ( $row['sender'] ?? '' ) ) {
			try {
				list( $address, $label ) = self::address( (string) $row['sender'] );
				$mail->setFrom( $address, $label, false );
			} catch ( \Throwable $e ) {
				unset( $e ); // Keep the From header (or PHPMailer's default).
			}
		}

		foreach ( explode( ',', (string) ( $row['recipients'] ?? '' ) ) as $item ) {
			list( $address, $label ) = self::address( $item );
			try {
				$mail->addAddress( $address, $label );
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		$mail->Subject = (string) ( $row['subject'] ?? '' );
		$mail->Body    = (string) ( $row['message'] ?? '' );
		$mail->isHTML( $html );
		$time = strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' );
		if ( false !== $time ) {
			$mail->MessageDate = gmdate( 'D, d M Y H:i:s', $time ) . ' +0000';
		}
		$mail->addCustomHeader( self::MARKER, $note );
		$names = self::attachment_names( (string) ( $row['attachments'] ?? '' ) );
		if ( $names ) {
			$mail->addCustomHeader( 'X-Mailspur-Attachments', self::header_value( implode( ', ', $names ) ) );
		}

		$mail->preSend();
		return $mail->getSentMIMEMessage();
		// phpcs:enable
	}

	/**
	 * Minimal fallback when PHPMailer rejects the logged data (e.g. no valid recipient).
	 *
	 * @param array<string,string> $row
	 */
	private static function plain( array $row, string $note ): string {
		$time    = strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' );
		$html    = false !== strpos( strtolower( (string) ( $row['content_type'] ?? '' ) ), 'html' );
		$headers = array(
			'Date: ' . gmdate( 'D, d M Y H:i:s', false === $time ? time() : $time ) . ' +0000',
			'From: ' . self::header_value( (string) ( $row['sender'] ?? '' ) ),
			'To: ' . self::header_value( (string) ( $row['recipients'] ?? '' ) ),
			'Subject: ' . self::encode_word( (string) ( $row['subject'] ?? '' ) ),
			'MIME-Version: 1.0',
			'Content-Type: ' . ( $html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8',
			'Content-Transfer-Encoding: 8bit',
			self::MARKER . ': ' . $note,
		);
		$body    = str_replace( array( "\r\n", "\r", "\n" ), "\r\n", (string) ( $row['message'] ?? '' ) );
		return implode( "\r\n", $headers ) . "\r\n\r\n" . $body;
	}

	/**
	 * "Name <a@b.c>" → array( 'a@b.c', 'Name' ).
	 *
	 * @return array{0:string,1:string}
	 */
	private static function address( string $value ): array {
		$value = trim( $value );
		if ( preg_match( '/^(.*?)<\s*([^>]+?)\s*>\s*$/', $value, $m ) ) {
			return array( $m[2], trim( $m[1], " \t\"'" ) );
		}
		return array( $value, '' );
	}

	/**
	 * @return string[]
	 */
	private static function attachment_names( string $json ): array {
		$list  = '' === $json ? array() : json_decode( $json, true );
		$names = array();
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) ) {
				$names[] = sanitize_file_name( $item['name'] );
			}
		}
		return $names;
	}

	private static function header_value( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $value ) );
	}

	private static function encode_word( string $value ): string {
		$value = self::header_value( $value );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 2047 encoded-word.
		return preg_match( '/[^\x20-\x7E]/', $value ) ? '=?UTF-8?B?' . base64_encode( $value ) . '?=' : $value;
	}
}
