<?php
/**
 * SMTP conversation recorder for PHPMailer's debug output (SMTPDebug 3).
 *
 * Credentials never reach the buffer: after "AUTH <mechanism>" every client line and every 334
 * challenge is replaced until the server answers with a final reply (235, 5xx …). The message
 * itself (DATA) is collapsed into a single line, so the transcript holds no unredacted content.
 * Connection options (TLS certificates, passphrases) are omitted as well.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Trace;

defined( 'ABSPATH' ) || exit;

final class Transcript {

	/** Maximum stored size in bytes. */
	const MAX_BYTES = 20480;

	const HIDDEN = '[hidden]';

	/** @var string */
	private $text = '';

	/** @var bool Inside an AUTH exchange. */
	private $auth = false;

	/** @var bool DATA was sent, waiting for the 354 go-ahead. */
	private $data_requested = false;

	/** @var bool Message content is being sent. */
	private $data = false;

	/** @var int */
	private $data_lines = 0;

	/** @var bool */
	private $truncated = false;

	/**
	 * One chunk of PHPMailer debug output (may span several lines, e.g. a multi-line server reply).
	 */
	public function add( string $chunk ): void {
		$chunk = rtrim( $chunk, "\r\n" );
		if ( '' === $chunk ) {
			return;
		}

		if ( preg_match( '/^CLIENT -> SERVER: ?(.*)$/s', $chunk, $m ) ) {
			$this->client( $m[1] );
			return;
		}

		if ( preg_match( '/^SERVER -> CLIENT: ?(\d{3})?/', $chunk, $m ) ) {
			$this->server( $chunk, $m[1] ?? '' );
			return;
		}

		if ( $this->data ) {
			$this->end_data();
		}
		// Stream context options may contain certificate paths or passphrases.
		$chunk = (string) preg_replace( '/^(Connection: opening to .*?, options=)(?!array\(\)\s*$).*$/s', '$1' . self::HIDDEN, $chunk );
		$this->write( $chunk );
	}

	private function client( string $command ): void {
		if ( $this->data ) {
			if ( '.' === trim( $command ) ) {
				$this->end_data();
				$this->write( 'CLIENT -> SERVER: .' );
			} else {
				$this->data_lines += substr_count( rtrim( $command, "\r\n" ), "\n" ) + 1;
			}
			return;
		}

		if ( $this->auth ) {
			$this->write( 'CLIENT -> SERVER: ' . self::HIDDEN );
			return;
		}

		if ( preg_match( '/^AUTH\s+([A-Za-z0-9_-]+)(\s+\S)?/i', $command, $m ) ) {
			$this->auth = true;
			$this->write( 'CLIENT -> SERVER: AUTH ' . strtoupper( $m[1] ) . ( empty( $m[2] ) ? '' : ' ' . self::HIDDEN ) );
			return;
		}

		$this->data_requested = (bool) preg_match( '/^DATA\s*$/i', $command );
		$this->write( 'CLIENT -> SERVER: ' . $command );
	}

	private function server( string $chunk, string $code ): void {
		if ( $this->data ) {
			$this->end_data();
		}

		if ( $this->auth ) {
			if ( '334' === $code ) {
				$this->write( 'SERVER -> CLIENT: 334 ' . self::HIDDEN );
				return;
			}
			$this->auth = false;
		}

		if ( $this->data_requested ) {
			$this->data_requested = false;
			$this->data           = '354' === $code;
		}

		$this->write( $chunk );
	}

	private function end_data(): void {
		if ( $this->data_lines > 0 ) {
			$this->write( sprintf( '[message data omitted: %d lines]', $this->data_lines ) );
		}
		$this->data       = false;
		$this->data_lines = 0;
	}

	private function write( string $line ): void {
		if ( $this->truncated ) {
			return;
		}
		// Control characters (except tab and line breaks) have no place in a transcript.
		$line = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace( "\r\n", "\n", $line ) );
		if ( strlen( $this->text ) + strlen( $line ) + 1 > self::MAX_BYTES ) {
			$this->text     .= "[transcript truncated]\n";
			$this->truncated = true;
			return;
		}
		$this->text .= $line . "\n";
	}

	/** The masked transcript, valid UTF-8. */
	public function text(): string {
		if ( $this->data ) {
			$this->end_data();
		}
		return wp_check_invalid_utf8( rtrim( $this->text, "\n" ), true );
	}
}
