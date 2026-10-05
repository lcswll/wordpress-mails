<?php
/**
 * Minimal WP_Error stand-in for unit tests.
 *
 * @package Mailspur
 */

/** Just enough of WP_Error for the logger and REST callbacks. */
class WP_Error {
	/** @var string */
	private $message;

	/** @var mixed */
	private $data;

	/** @param mixed $data */
	public function __construct( string $code = '', string $message = '', $data = '' ) {
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}
