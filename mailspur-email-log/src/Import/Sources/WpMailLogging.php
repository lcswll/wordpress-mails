<?php
/**
 * WP Mail Logging – table {prefix}wpml_mails.
 *
 * - timestamp: current_time('mysql') → site-local.
 * - receiver / headers / attachments: lists joined with the LITERAL three characters ",\n"
 *   (comma, backslash, n), not a newline.
 * - attachments: paths relative to the uploads folder ("/2024/01/x.pdf").
 * - No status column: error '' means no failure was reported (the plugin shows these as sent).
 *   error may be a serialized string list.
 * - Adds "Content-Type: text/html" when the mail had none, so the stored type can be wrong.
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

use Mailspur\Import\Source;
use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class WpMailLogging extends Source {

	const SEPARATOR = ',\n'; // Literal backslash-n on purpose (single quotes).

	public function id(): string {
		return 'wp-mail-logging';
	}

	public function label(): string {
		return 'WP Mail Logging';
	}

	protected function table_suffix(): string {
		return 'wpml_mails';
	}

	protected function primary_key(): string {
		return 'mail_id';
	}

	public function map( array $row ): array {
		$headers = self::split( (string) $row['headers'] );
		$error   = (string) $row['error'];
		$list    = self::unserialize_safe( $error );
		if ( is_array( $list ) ) {
			$error = implode( '; ', array_filter( array_map( 'strval', $list ) ) );
		}

		$files = array();
		foreach ( self::split( (string) $row['attachments'] ) as $path ) {
			if ( '0' !== $path ) {
				$files[] = self::attachment_in_uploads( $path );
			}
		}

		return $this->row(
			array(
				'created_at'   => self::local_to_gmt( $row['timestamp'] ),
				'status'       => '' === trim( $error ) ? Repository::STATUS_SENT : Repository::STATUS_FAILED,
				'recipients'   => implode( ', ', self::split( (string) $row['receiver'] ) ),
				'subject'      => (string) $row['subject'],
				'message'      => (string) $row['message'],
				'headers'      => implode( "\n", $headers ),
				'attachments'  => self::attachments_json( $files ),
				'content_type' => self::content_type_of( $headers ),
				'sender'       => self::header_value( $headers, 'From' ),
				'error'        => trim( $error ),
			)
		);
	}

	/**
	 * @return string[]
	 */
	private static function split( string $value ): array {
		return self::clean_list( explode( self::SEPARATOR, $value ) );
	}
}
