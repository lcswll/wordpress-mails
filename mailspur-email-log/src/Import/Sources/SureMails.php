<?php
/**
 * SureMail – table {prefix}suremails_email_log.
 *
 * - created_at: current_time('mysql') → site-local.
 * - email_to: "Name <email>, email" string; email_from: "Name <email>".
 * - headers: serialized list of header lines.
 * - attachments: serialized list of absolute paths (copies under uploads/suremails/attachments/).
 * - status: sent | failed | pending | blocked (Content Guard); errors are in the serialized
 *   response list (one entry per attempt, key "Message").
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class SureMails extends Source {

	public function id(): string {
		return 'suremails';
	}

	public function label(): string {
		return 'SureMail';
	}

	protected function table_suffix(): string {
		return 'suremails_email_log';
	}

	protected function primary_key(): string {
		return 'id';
	}

	public function map( array $row ): array {
		$raw     = self::unserialize_safe( $row['headers'] );
		$headers = self::header_lines( null !== $raw ? $raw : (string) $row['headers'] );

		$files = array();
		foreach ( (array) self::unserialize_safe( $row['attachments'] ) as $path ) {
			if ( is_string( $path ) && '' !== $path ) {
				$files[] = self::attachment( $path );
			}
		}

		$status = (string) $row['status'];
		$error  = '';
		if ( 'failed' === $status || 'blocked' === $status ) {
			$attempts = self::unserialize_safe( $row['response'] );
			$last     = is_array( $attempts ) ? end( $attempts ) : null;
			$error    = is_array( $last ) && isset( $last['Message'] ) && is_scalar( $last['Message'] ) ? (string) $last['Message'] : '';
			if ( 'blocked' === $status && '' === $error ) {
				$error = 'Blocked by SureMail Content Guard';
			}
		}

		$map = array(
			'sent'    => Repository::STATUS_SENT,
			'failed'  => Repository::STATUS_FAILED,
			'blocked' => Repository::STATUS_FAILED,
		);

		return $this->row(
			array(
				'created_at'   => self::local_to_gmt( $row['created_at'] ),
				'status'       => $map[ $status ] ?? Repository::STATUS_PENDING,
				'recipients'   => (string) $row['email_to'],
				'subject'      => (string) $row['subject'],
				'message'      => (string) $row['body'],
				'headers'      => implode( "\n", $headers ),
				'attachments'  => self::attachments_json( $files ),
				'content_type' => self::content_type_of( $headers ),
				'sender'       => trim( (string) $row['email_from'] ),
				'error'        => $error,
			)
		);
	}
}
