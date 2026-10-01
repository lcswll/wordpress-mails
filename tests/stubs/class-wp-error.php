<?php
/**
 * Minimal WP_Error stand-in for unit tests.
 *
 * @package OutboxMailLog
 */

/** Just enough of WP_Error for the logger. */
class WP_Error {
	/** @var string */
	private $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}
