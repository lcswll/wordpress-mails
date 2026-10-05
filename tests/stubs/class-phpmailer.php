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
}
