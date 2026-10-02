<?php
/**
 * PHPMailer double for the Trace module: transport settings, debug output and the sent MIME source.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use PHPMailer\PHPMailer\PHPMailer;

/** Transport properties the trace reads in phpmailer_init. */
final class TraceMailer extends PHPMailer {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	/** @var string */
	public $Mailer = 'smtp';
	/** @var string */
	public $Host = 'smtp.example.com';
	/** @var int */
	public $Port = 587;
	/** @var string */
	public $SMTPSecure = 'tls';
	/** @var bool */
	public $SMTPAuth = true;
	/** @var bool */
	public $SMTPAutoTLS = true;
	/** @var string */
	public $Username = 'john@example.com';
	/** @var string */
	public $Password = 'S3cret-Passw0rd';
	/** @var int */
	public $SMTPDebug = 0;
	/** @var mixed */
	public $Debugoutput = 'echo';
	// phpcs:enable

	/** @var string What getSentMIMEMessage() returns. */
	public $mime = '';

	public function getSentMIMEMessage(): string {
		return $this->mime;
	}

	/** Simulates PHPMailer's debug output. */
	public function debug( string $line ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		if ( is_callable( $this->Debugoutput ) && 'echo' !== $this->Debugoutput ) {
			call_user_func( $this->Debugoutput, $line, 2 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}
}
