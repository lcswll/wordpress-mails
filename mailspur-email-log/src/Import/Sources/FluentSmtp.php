<?php
/**
 * FluentSMTP – table {prefix}fsmpt_email_logs.
 *
 * - created_at: current_time('mysql') → site-local.
 * - to: serialized list of {email, name}; old installs truncated it at 255 chars (then unreadable).
 * - headers: serialized {reply-to: [...], cc: [...], bcc: [...], content-type: 'text/html'}.
 * - attachments: serialized PHPMailer attachment arrays [0 => path, 1 => filename, 2 => name, …].
 * - status: 'sent' | 'failed' | 'pending'; the error is in the serialized response['message'].
 * - from: plain "Name <email>".
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class FluentSmtp extends Source {

	public function id(): string {
		return 'fluent-smtp';
	}

	public function label(): string {
		return 'FluentSMTP';
	}

	protected function table_suffix(): string {
		return 'fsmpt_email_logs';
	}

	protected function primary_key(): string {
		return 'id';
	}

	public function map( array $row ): array {
		$to = self::unserialize_safe( $row['to'] );
		if ( is_array( $to ) ) {
			$recipients = self::addresses( $to );
		} else {
			// Truncated serialized data: keep whatever addresses are readable.
			preg_match_all( '/[^\s"\';:{}]+@[^\s"\';:{}]+/', (string) $row['to'], $m );
			$recipients = implode( ', ', array_unique( $m[0] ) );
		}

		$headers = array();
		$meta    = self::unserialize_safe( $row['headers'] );
		if ( is_array( $meta ) ) {
			foreach ( array(
				'reply-to' => 'Reply-To',
				'cc'       => 'Cc',
				'bcc'      => 'Bcc',
			) as $key => $name ) {
				if ( ! empty( $meta[ $key ] ) && is_array( $meta[ $key ] ) ) {
					$headers[] = $name . ': ' . self::addresses( $meta[ $key ] );
				}
			}
			if ( ! empty( $meta['content-type'] ) && is_string( $meta['content-type'] ) ) {
				$headers[] = 'Content-Type: ' . $meta['content-type'];
			}
		}
		if ( ! empty( $row['from'] ) ) {
			array_unshift( $headers, 'From: ' . $row['from'] );
		}

		$files = array();
		foreach ( (array) self::unserialize_safe( $row['attachments'] ) as $file ) {
			if ( is_array( $file ) && isset( $file[0] ) && is_string( $file[0] ) ) {
				$files[] = self::attachment( $file[0], isset( $file[2] ) && is_string( $file[2] ) ? $file[2] : '' );
			}
		}

		$status   = (string) $row['status'];
		$response = self::unserialize_safe( $row['response'] );
		$error    = 'failed' === $status && is_array( $response ) && isset( $response['message'] ) && is_scalar( $response['message'] ) ? (string) $response['message'] : '';

		return $this->row(
			array(
				'created_at'   => self::local_to_gmt( $row['created_at'] ),
				'status'       => 'sent' === $status ? Repository::STATUS_SENT : ( 'failed' === $status ? Repository::STATUS_FAILED : Repository::STATUS_PENDING ),
				'recipients'   => $recipients,
				'subject'      => (string) $row['subject'],
				'message'      => (string) $row['body'],
				'headers'      => implode( "\n", $headers ),
				'attachments'  => self::attachments_json( $files ),
				'content_type' => self::content_type_of( $headers ),
				'sender'       => (string) $row['from'],
				'error'        => $error,
			)
		);
	}

	/**
	 * [{email, name}] → "Name <email>, email".
	 *
	 * @param array<int|string,mixed> $entries
	 */
	private static function addresses( array $entries ): string {
		$out = array();
		foreach ( $entries as $item ) {
			if ( is_array( $item ) && ! empty( $item['email'] ) && is_string( $item['email'] ) ) {
				$out[] = ! empty( $item['name'] ) && is_string( $item['name'] ) ? sprintf( '%s <%s>', $item['name'], $item['email'] ) : $item['email'];
			} elseif ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}
		return implode( ', ', $out );
	}
}
