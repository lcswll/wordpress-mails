<?php
/**
 * PHPMailer stand-in with the public properties the logger reads in phpmailer_init.
 *
 * @package Mailspur
 */

namespace PHPMailer\PHPMailer;

/** Public properties the logger reads in phpmailer_init. */
class PHPMailer {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	/** @var string */
	public $ContentType = 'text/plain';
	/** @var string */
	public $From = '';
	/** @var string */
	public $FromName = '';
	/** @var string */
	public $Body = '';
	/** @var string */
	public $AltBody = '';
	// phpcs:enable

	/** @var string Message-ID of the last mail sent (PHPMailer sets it while building the headers). */
	public $last_message_id = '';

	/** @var array<int,array{0:string,1:string}> */
	private $custom_headers = array();

	public function addCustomHeader( string $name, ?string $value = null ): bool {
		$this->custom_headers[] = array( $name, (string) $value );
		return true;
	}

	/** @return array<int,array{0:string,1:string}> */
	public function getCustomHeaders(): array {
		return $this->custom_headers;
	}

	public function getLastMessageID(): string {
		return $this->last_message_id;
	}
}
