<?php
/**
 * Read-only view of one logged mail for the note rules: normalized fields, parsed once.
 *
 * Rules receive this object (see Rules and the mailspur_note_rules filter). The body is capped, so a
 * rule's regular expressions never run over megabytes of HTML.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

defined( 'ABSPATH' ) || exit;

final class Mail {

	/** Bytes of the body the rules look at (the full size is still known via $size). */
	const SCAN_BYTES = 524288;

	/** @var string Body, capped at SCAN_BYTES. */
	public $body;

	/** @var int Full body size in bytes. */
	public $size;

	/** @var string */
	public $subject;

	/** @var bool */
	public $is_html;

	/** @var string Lower-cased content type as recorded ('' when unknown). */
	public $content_type;

	/** @var string Lower-cased From address ('' when none was recorded). */
	public $from;

	/** @var string[] Lower-cased To addresses. */
	public $to;

	/** @var string[] Lower-cased Cc and Bcc addresses. */
	public $copies;

	/** @var string[] Lower-cased Cc addresses (visible to every recipient, unlike Bcc). */
	public $cc;

	/** @var int Number of Bcc addresses. */
	public $bcc;

	/** @var array<string,string> Lower-cased header names => last value. */
	public $headers;

	/** @var string Source, e.g. "plugin:woocommerce" or "import:wp-mail-logging". */
	public $source;

	/** @var array{home:string,environment:string,multisite:bool,admins?:string[]} Site context (no I/O to compute; admins see admins()). */
	public $site;

	/**
	 * Secrets found in the mail as it was sent, before the log masked them (see Module::meta()).
	 *
	 * @var array<int,array{kind:string,hint:string}>
	 */
	public $secrets = array();

	/** @var bool A Reply-To was set: as a header, or by a plugin on PHPMailer (see Module::meta()). */
	public $reply_to;

	/**
	 * @param array<string,mixed>                                     $row  Log row (message, subject, recipients, headers, content_type, sender, source).
	 * @param array{home:string,environment:string,multisite:bool,admins?:string[]} $site Lower-cased home host, wp_get_environment_type(), is_multisite(), optionally the administrators' addresses.
	 */
	public function __construct( array $row, array $site ) {
		$message    = (string) ( $row['message'] ?? '' );
		$this->size = strlen( $message );
		$this->body = $this->size > self::SCAN_BYTES ? substr( $message, 0, self::SCAN_BYTES ) : $message;

		$this->subject = (string) ( $row['subject'] ?? '' );
		$this->source  = (string) ( $row['source'] ?? '' );
		$this->site    = $site;

		$this->headers = array();
		$copies        = '';
		$cc            = '';
		$bcc           = '';
		foreach ( explode( "\n", str_replace( "\r\n", "\n", (string) ( $row['headers'] ?? '' ) ) ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$name                   = strtolower( trim( substr( $line, 0, $pos ) ) );
			$value                  = trim( substr( $line, $pos + 1 ) );
			$this->headers[ $name ] = $value;
			if ( 'cc' === $name ) {
				$copies .= ',' . $value;
				$cc     .= ',' . $value;
			} elseif ( 'bcc' === $name ) {
				$copies .= ',' . $value;
				$bcc    .= ',' . $value;
			}
		}

		$type = strtolower( (string) ( $row['content_type'] ?? '' ) );
		if ( '' === $type && isset( $this->headers['content-type'] ) ) {
			$type = strtolower( trim( (string) strtok( $this->headers['content-type'], ';' ) ) );
		}
		$this->content_type = $type;
		$this->is_html      = '' === $type
			? 1 === preg_match( '/<(?:html|body|table|div|p|br|a)\b[^>]*>/i', $this->body )
			: false !== strpos( $type, 'html' );

		$sender = (string) ( $row['sender'] ?? '' );
		if ( '' === $sender && isset( $this->headers['from'] ) ) {
			$sender = $this->headers['from'];
		}
		$from       = self::emails( $sender );
		$this->from = $from ? $from[0] : '';

		$this->reply_to = '' !== trim( $this->headers['reply-to'] ?? '' );

		$meta = $row['meta'] ?? null;
		if ( is_array( $meta ) && ! empty( $meta[ Module::REPLY_TO_META ] ) ) {
			$this->reply_to = true;
		}
		if ( is_array( $meta ) && isset( $meta[ Module::SECRETS_META ] ) && is_array( $meta[ Module::SECRETS_META ] ) ) {
			foreach ( $meta[ Module::SECRETS_META ] as $secret ) {
				if ( is_array( $secret ) && isset( $secret['kind'] ) && is_string( $secret['kind'] ) ) {
					$this->secrets[] = array(
						'kind' => $secret['kind'],
						'hint' => is_string( $secret['hint'] ?? null ) ? $secret['hint'] : '',
					);
				}
			}
		}

		$this->to     = self::emails( (string) ( $row['recipients'] ?? '' ) );
		$this->copies = self::emails( $copies );
		$this->cc     = self::emails( $cc );
		$this->bcc    = count( self::emails( $bcc ) );
	}

	/**
	 * Domain part of an address ('' when there is none).
	 */
	public static function domain( string $email ): string {
		$at = strrpos( $email, '@' );
		return false === $at ? '' : rtrim( strtolower( substr( $email, $at + 1 ) ), '.' );
	}

	/**
	 * Lower-cased addresses of the site's administrators: from the site context, otherwise looked up once.
	 *
	 * @return string[]
	 */
	public function admins(): array {
		if ( ! isset( $this->site['admins'] ) ) {
			$this->site['admins'] = Engine::admins();
		}
		return $this->site['admins'];
	}

	/**
	 * Distinct recipient domains (To, Cc, Bcc).
	 *
	 * @return string[]
	 */
	public function recipient_domains(): array {
		$domains = array();
		foreach ( array_merge( $this->to, $this->copies ) as $email ) {
			$domain = self::domain( $email );
			if ( '' !== $domain ) {
				$domains[ $domain ] = true;
			}
		}
		return array_keys( $domains );
	}

	/** Body without <style>/<script>/<head> blocks and HTML comments (for text-based checks). */
	public function text(): string {
		if ( ! $this->is_html ) {
			return $this->body;
		}
		return (string) preg_replace( '#<(style|script|head)\b[^>]*>.*?</\1\s*>|<!--.*?-->#is', ' ', $this->body );
	}

	/**
	 * Lower-cased addresses from a "Name <a@b>, c@d" list.
	 *
	 * @return string[]
	 */
	public static function emails( string $addresses ): array {
		preg_match_all( '/[^\s<>,;"\'()]+@[^\s<>,;"\'()]+/', $addresses, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
	}
}
