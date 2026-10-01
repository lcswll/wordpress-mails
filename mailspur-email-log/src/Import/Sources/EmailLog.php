<?php
/**
 * Email Log (by Sudar) – table {prefix}email_log.
 *
 * - sent_date: current_time('mysql') → site-local.
 * - to_email: string as passed, arrays joined with ",".
 * - headers: string as passed, arrays joined with "\n".
 * - result: 1 = sent (written before sending), 0 = failed (error_message), NULL = legacy/unknown.
 * - Attachment paths are not stored by the free plugin; attachment_name may be filled by add-ons.
 *
 * Check & Log Email is a fork with the same table layout; it extends this class.
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

class EmailLog extends Source {

	public function id(): string {
		return 'email-log';
	}

	public function label(): string {
		return 'Email Log';
	}

	protected function table_suffix(): string {
		return 'email_log';
	}

	protected function primary_key(): string {
		return 'id';
	}

	public function map( array $row ): array {
		$headers = self::header_lines( self::unify_list( (string) $row['headers'], "\n" ) );

		$files = array();
		foreach ( explode( ',', self::unify_list( (string) ( $row['attachment_name'] ?? '' ), ',' ) ) as $path ) {
			if ( '' !== trim( $path ) ) {
				$files[] = self::attachment( $path );
			}
		}

		if ( null === $row['result'] || '' === $row['result'] ) {
			$status = Repository::STATUS_PENDING;
		} else {
			$status = '0' === (string) $row['result'] ? Repository::STATUS_FAILED : Repository::STATUS_SENT;
		}

		return $this->row(
			array(
				'created_at'   => self::local_to_gmt( $row['sent_date'] ),
				'status'       => $status,
				'recipients'   => self::recipients( (string) $row['to_email'] ),
				'subject'      => $this->subject( (string) $row['subject'] ),
				'message'      => (string) $row['message'],
				'headers'      => implode( "\n", $headers ),
				'attachments'  => self::attachments_json( $files ),
				'content_type' => self::content_type_of( $headers ),
				'sender'       => self::header_value( $headers, 'From' ),
				'error'        => (string) ( $row['error_message'] ?? '' ),
			)
		);
	}

	protected function subject( string $subject ): string {
		return $subject;
	}

	/** "a@x.de,b@y.de" → "a@x.de, b@y.de". */
	protected static function recipients( string $value ): string {
		return implode( ', ', self::clean_list( explode( ',', self::unify_list( $value, ',' ) ) ) );
	}

	/**
	 * Rows that were themselves imported from WP Mail Logging (Check & Log Email has such an importer)
	 * keep WPML's literal ",\n" separator – turn it into the expected one.
	 */
	protected static function unify_list( string $value, string $separator ): string {
		return str_replace( ',\n', $separator, $value );
	}
}
