<?php
/**
 * List filters from untrusted input (export form, WP-CLI), validated against the list's REST schema
 * so the export always matches what the list shows.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Filters {

	/** Filter keys shared with the list (Rest::list_args()); paging is not part of an export. */
	const KEYS = array( 'search', 'in_body', 'status', 'after', 'before', 'source', 'format', 'attachments', 'notes', 'delivery', 'orderby', 'order' );

	/**
	 * @param array<string,mixed> $input Raw values (already unslashed).
	 * @return array<string,string|bool> Every key of KEYS, invalid values replaced by their default.
	 */
	public static function sanitize( array $input ): array {
		$schema = Rest::list_args();
		$out    = array();
		foreach ( self::KEYS as $key ) {
			$def   = $schema[ $key ];
			$value = $input[ $key ] ?? null;

			if ( 'boolean' === $def['type'] ) {
				$out[ $key ] = null !== $value && is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				continue;
			}

			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( isset( $def['enum'] ) && ! in_array( $value, (array) $def['enum'], true ) ) {
				$value = (string) $def['default'];
			}
			if ( isset( $def['pattern'] ) && ! preg_match( '/' . $def['pattern'] . '/', $value ) ) {
				$value = '';
			}
			if ( isset( $def['maxLength'] ) ) {
				$value = mb_substr( $value, 0, (int) $def['maxLength'] );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
