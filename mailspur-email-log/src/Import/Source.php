<?php
/**
 * A mail log table of another plugin that can be imported (read-only).
 *
 * Subclasses describe the table and map one raw row to Mailspur's row format. Formats are taken
 * from the current code of each plugin (time zones, separators, serialization) – see the
 * per-source docblocks.
 *
 * Direct queries are intended: foreign plugin tables have no API.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Import;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

abstract class Source {

	/** Stable id, used for progress state and as the "Source" of imported rows. */
	abstract public function id(): string;

	/** Name of the other plugin as shown in the admin. */
	abstract public function label(): string;

	/** Table name without the site prefix. */
	abstract protected function table_suffix(): string;

	/** Primary key column (rows are read in ascending order of it). */
	abstract protected function primary_key(): string;

	/**
	 * Maps one raw row to a Mailspur row, or null to skip it.
	 *
	 * @param array<string,string|null> $row
	 * @return array<string,string|int>|null
	 */
	abstract public function map( array $row ): ?array;

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . $this->table_suffix();
	}

	public function available(): bool {
		global $wpdb;
		return $this->table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table() ) ) );
	}

	/** Rows with a primary key above $after. */
	public function remaining( int $after = 0 ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i > %d', $this->table(), $this->primary_key(), $after ) );
	}

	/**
	 * Next batch of raw rows after the given primary key.
	 *
	 * @return array<int,array<string,string|null>>
	 */
	public function fetch( int $after, int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d', $this->table(), $this->primary_key(), $after, $this->primary_key(), $limit ),
			ARRAY_A
		);
	}

	/** @param array<string,string|null> $row */
	public function key_of( array $row ): int {
		return (int) ( $row[ $this->primary_key() ] ?? 0 );
	}

	// --------------------------------------------------------------- helpers.

	/**
	 * Base row with every column Mailspur stores.
	 *
	 * @param array<string,string|int> $data
	 * @return array<string,string|int>
	 */
	protected function row( array $data ): array {
		$row = array_merge(
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'status'       => Repository::STATUS_PENDING,
				'recipients'   => '',
				'subject'      => '',
				'message'      => '',
				'headers'      => '',
				'attachments'  => '',
				'content_type' => '',
				'sender'       => '',
				'source'       => 'import:' . $this->id(),
				'error'        => '',
			),
			$data
		);

		$row['content_type'] = (string) substr( strtolower( (string) $row['content_type'] ), 0, 100 );
		$row['sender']       = (string) substr( (string) $row['sender'], 0, 255 );
		return $row;
	}

	/**
	 * Trimmed, non-empty strings of a list.
	 *
	 * @param array<int|string,mixed> $parts
	 * @return string[]
	 */
	protected static function clean_list( array $parts ): array {
		$out = array();
		foreach ( $parts as $part ) {
			$part = is_scalar( $part ) ? trim( (string) $part ) : '';
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/** Site-local "Y-m-d H:i:s" (current_time('mysql')) → GMT. */
	protected static function local_to_gmt( ?string $local ): string {
		if ( ! $local || 0 === strpos( $local, '0000-00-00' ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return get_gmt_from_date( $local );
	}

	/** Unix timestamp shifted by the site offset (current_time('timestamp')) → GMT, DST-aware. */
	protected static function local_epoch_to_gmt( int $local_epoch ): string {
		return self::local_to_gmt( gmdate( 'Y-m-d H:i:s', $local_epoch ) );
	}

	/**
	 * Unserializes data written by another plugin. Objects are never instantiated
	 * (allowed_classes = false), so a crafted row cannot trigger PHP object injection.
	 *
	 * @return mixed|null Null when the value is not (valid) serialized data.
	 */
	protected static function unserialize_safe( ?string $value ) {
		if ( null === $value || ! is_serialized( $value ) ) {
			return null;
		}
		// Truncated data (e.g. old FluentSMTP columns) is expected: swallow the notice instead of logging it.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped, restored below.
			static function (): bool {
				return true;
			}
		);
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- objects are disabled.
			$data = unserialize( trim( $value ), array( 'allowed_classes' => false ) );
		} finally {
			restore_error_handler();
		}
		return false === $data && 'b:0;' !== trim( $value ) ? null : $data;
	}

	/**
	 * Header lines from a string ("\r\n"/"\n" separated) or a list.
	 *
	 * @param mixed $headers
	 * @return string[]
	 */
	protected static function header_lines( $headers ): array {
		if ( is_array( $headers ) ) {
			$lines = array();
			foreach ( $headers as $name => $value ) {
				if ( is_string( $name ) && is_scalar( $value ) ) {
					$lines[] = $name . ': ' . $value; // Associative form, e.g. array( 'Cc' => '…' ).
				} elseif ( is_scalar( $value ) ) {
					$lines[] = (string) $value;
				}
			}
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $headers );
		}
		return self::clean_list( (array) $lines );
	}

	/**
	 * Value of the first header with this name, e.g. "From".
	 *
	 * @param string[] $lines
	 */
	protected static function header_value( array $lines, string $name ): string {
		foreach ( $lines as $line ) {
			if ( 0 === stripos( $line, $name . ':' ) ) {
				return trim( substr( $line, strlen( $name ) + 1 ) );
			}
		}
		return '';
	}

	/**
	 * Content type (MIME type only) from header lines.
	 *
	 * @param string[] $lines
	 */
	protected static function content_type_of( array $lines ): string {
		$value = self::header_value( $lines, 'Content-Type' );
		return '' === $value ? '' : strtolower( trim( explode( ';', $value )[0] ) );
	}

	/**
	 * Attachment list in Mailspur's format: [{name, path}], JSON-encoded ('' when none).
	 *
	 * @param array<int,array{name:string,path:string}> $files
	 */
	protected static function attachments_json( array $files ): string {
		$files = array_values(
			array_filter(
				$files,
				static function ( array $file ): bool {
					return '' !== $file['name'];
				}
			)
		);
		return $files ? (string) wp_json_encode( $files ) : '';
	}

	/**
	 * Attachment entry for an absolute path.
	 *
	 * @return array{name:string,path:string}
	 */
	protected static function attachment( string $path, string $name = '' ): array {
		$path = trim( $path );
		return array(
			'name' => '' !== $name ? $name : wp_basename( $path ),
			'path' => $path,
		);
	}

	/**
	 * Attachment entry for a path stored relative to the uploads folder ("/2024/01/x.pdf").
	 *
	 * @return array{name:string,path:string}
	 */
	protected static function attachment_in_uploads( string $relative ): array {
		$relative = trim( $relative );
		if ( '' === $relative ) {
			return self::attachment( '' );
		}
		$uploads = wp_upload_dir( null, false );
		return self::attachment( rtrim( (string) $uploads['basedir'], '/' ) . '/' . ltrim( $relative, '/' ) );
	}
}
