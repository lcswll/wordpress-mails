<?php
/**
 * Post SMTP – table {prefix}post_smtp_logs (since Post SMTP 2.5; older sites used a custom post type,
 * which Post SMTP migrates into this table itself).
 *
 * - time: current_time('timestamp') → unix epoch shifted by the site offset ("local epoch").
 * - to_header / cc_header / bcc_header: bare addresses joined with ", " (display names stripped).
 * - original_headers: raw string or serialized array.
 * - success: '1' / 'Sent ( ** Fallback ** )' = sent; 'Warning: An empty subject…' = sent with a warning;
 *   'In Queue' = pending; '0' or any other text = failed, the text being the error.
 * - Attachments are not stored by the free plugin.
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class PostSmtp extends Source {

	public function id(): string {
		return 'post-smtp';
	}

	public function label(): string {
		return 'Post SMTP';
	}

	protected function table_suffix(): string {
		return 'post_smtp_logs';
	}

	protected function primary_key(): string {
		return 'id';
	}

	public function map( array $row ): array {
		$raw     = self::unserialize_safe( $row['original_headers'] );
		$headers = self::header_lines( null !== $raw ? $raw : (string) $row['original_headers'] );
		foreach ( array(
			'From' => 'from_header',
			'Cc'   => 'cc_header',
			'Bcc'  => 'bcc_header',
		) as $name => $column ) {
			if ( ! empty( $row[ $column ] ) && '' === self::header_value( $headers, $name ) ) {
				$headers[] = $name . ': ' . $row[ $column ];
			}
		}

		$success = trim( (string) $row['success'] );
		$error   = '';
		if ( '1' === $success || 0 === stripos( $success, 'Sent' ) || 0 === stripos( $success, 'Warning' ) ) {
			$status = Repository::STATUS_SENT;
		} elseif ( 'In Queue' === $success ) {
			$status = Repository::STATUS_PENDING;
		} else {
			$status = Repository::STATUS_FAILED;
			$error  = '0' === $success ? '' : $success;
		}

		return $this->row(
			array(
				'created_at'   => self::local_epoch_to_gmt( (int) $row['time'] ),
				'status'       => $status,
				'recipients'   => (string) $row['to_header'],
				'subject'      => (string) $row['original_subject'],
				'message'      => (string) $row['original_message'],
				'headers'      => implode( "\n", $headers ),
				'content_type' => self::content_type_of( $headers ),
				'sender'       => (string) $row['from_header'],
				'error'        => $error,
			)
		);
	}
}
