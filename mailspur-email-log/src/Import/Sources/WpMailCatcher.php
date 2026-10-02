<?php
/**
 * WP Mail Catcher – table {prefix}mail_catcher_logs.
 *
 * - time: time() → real UTC epoch.
 * - email_to: To list joined with ", " (passed through wp_kses, display names may be mangled).
 * - additional_headers: JSON (list of header lines, or a string).
 * - attachments: JSON [{id, url}] – no file paths (id -1 when not in the media library).
 * - status: 1 = not reported as failed, 0 = failed (error).
 * - is_html (since 2.0): 1 when sent as text/html.
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class WpMailCatcher extends Source {

	public function id(): string {
		return 'wp-mail-catcher';
	}

	public function label(): string {
		return 'WP Mail Catcher';
	}

	protected function table_suffix(): string {
		return 'mail_catcher_logs';
	}

	protected function primary_key(): string {
		return 'id';
	}

	public function map( array $row ): array {
		$decoded = json_decode( (string) $row['additional_headers'], true );
		$headers = self::header_lines( null !== $decoded ? $decoded : (string) $row['additional_headers'] );

		$files = array();
		foreach ( (array) json_decode( (string) $row['attachments'], true ) as $file ) {
			if ( is_array( $file ) && ! empty( $file['url'] ) && is_string( $file['url'] ) ) {
				// Only a URL is known; the name is shown, resending re-attaches nothing.
				$files[] = array(
					'name' => wp_basename( (string) wp_parse_url( $file['url'], PHP_URL_PATH ) ),
					'path' => '',
				);
			}
		}

		$type = self::content_type_of( $headers );
		if ( '' === $type && isset( $row['is_html'] ) ) {
			$type = '1' === (string) $row['is_html'] ? 'text/html' : 'text/plain';
		}

		return $this->row(
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s', (int) $row['time'] ),
				'status'       => '0' === (string) $row['status'] ? Repository::STATUS_FAILED : Repository::STATUS_SENT,
				'recipients'   => (string) $row['email_to'],
				'subject'      => (string) $row['subject'],
				'message'      => (string) $row['message'],
				'headers'      => implode( "\n", $headers ),
				'attachments'  => self::attachments_json( $files ),
				'content_type' => $type,
				'sender'       => self::header_value( $headers, 'From' ),
				'error'        => (string) $row['error'],
			)
		);
	}
}
