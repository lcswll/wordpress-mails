<?php
/**
 * WP Mail Log (by WPVibes) – table {prefix}wml_entries.
 *
 * - captured_gmt: current_time('mysql', true) → UTC (preferred); sent_date: site-local fallback.
 * - to_email: sanitize_email()'d addresses joined with ", " (display names are lost).
 * - headers: string as passed (array headers were stored as '').
 * - attachments_file: paths relative to uploads, joined with ",".
 * - No status at all: every entry is "unknown".
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class WpMailLog extends Source {

	public function id(): string {
		return 'wp-mail-log';
	}

	public function label(): string {
		return 'WP Mail Log';
	}

	protected function table_suffix(): string {
		return 'wml_entries';
	}

	protected function primary_key(): string {
		return 'id';
	}

	public function map( array $row ): array {
		$headers = self::header_lines( (string) $row['headers'] );
		$gmt     = (string) ( $row['captured_gmt'] ?? '' );

		$files = array();
		foreach ( explode( ',', (string) ( $row['attachments_file'] ?? '' ) ) as $path ) {
			if ( '' !== trim( $path ) ) {
				$files[] = self::attachment_in_uploads( $path );
			}
		}

		return $this->row(
			array(
				'created_at'   => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $gmt ) ? $gmt : self::local_to_gmt( $row['sent_date'] ),
				'status'       => Repository::STATUS_PENDING,
				'recipients'   => (string) $row['to_email'],
				'subject'      => (string) $row['subject'],
				'message'      => (string) $row['message'],
				'headers'      => implode( "\n", $headers ),
				'attachments'  => self::attachments_json( $files ),
				'content_type' => self::content_type_of( $headers ),
				'sender'       => self::header_value( $headers, 'From' ),
			)
		);
	}
}
