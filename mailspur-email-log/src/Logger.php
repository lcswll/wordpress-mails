<?php
/**
 * Captures every wp_mail() call.
 *
 * Flow per mail (2 queries):
 *   wp_mail filter      → INSERT row as "pending" (survives fatals mid-send)
 *   phpmailer_init      → remember final body / content type / sender in memory
 *   succeeded / failed  → single UPDATE with status + enrichment
 * A pre_wp_mail short-circuit (e.g. API-based mailers) resolves the row as well.
 *
 * Extension points for modules (see docs/MODULES.md):
 *   filter mailspur_meta( array $meta, string $phase, mixed $context )
 *          phase 'capture' (context: wp_mail args), 'phpmailer' (PHPMailer), 'result' (status/error)
 *   filter mailspur_finalize_row( array $data, array $row )  – last chance to set columns (notes, meta …)
 *   action mailspur_logged( int $id, array $row )            – after the final UPDATE
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Logger {

	/**
	 * Set while the plugin itself resends a mail, so the source is recorded correctly.
	 *
	 * @var string
	 */
	public static $source_override = '';

	/** @var Repository */
	private $repository;

	/**
	 * Mails in flight (wp_mail can be nested, e.g. a failure handler sending a mail).
	 *
	 * @var array<int,array{id:int,hash:string,row:array<string,mixed>,data:array<string,mixed>,meta:array<string,mixed>}>
	 */
	private $stack = array();

	/** @var string|null */
	private $self_dir;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_filter( 'wp_mail', array( $this, 'capture' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'short_circuit' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'enrich' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( $this, 'succeeded' ) );
		add_action( 'wp_mail_failed', array( $this, 'failed' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * @param mixed $atts wp_mail() arguments after all other filters ran.
	 * @return mixed Unchanged.
	 */
	public function capture( $atts ) {
		if ( ! is_array( $atts ) ) {
			return $atts;
		}

		$message = (string) ( $atts['message'] ?? '' );
		$id      = 0;
		$row     = array();
		$meta    = array();

		// A skipped mail still gets a stack slot (id 0) so result hooks stay aligned.
		if ( apply_filters( 'mailspur_should_log', true, $atts ) ) {
			try {
				$row         = $this->normalize( $atts, $message );
				$meta        = (array) apply_filters( 'mailspur_meta', array(), 'capture', $atts );
				$row['meta'] = self::encode( $meta );
				$id          = $this->repository->insert( $row );
			} catch ( \Throwable $e ) { // Logging must never break mail delivery.
				$id = 0;
			}
		}

		$this->stack[] = array(
			'id'   => $id,
			'hash' => md5( $message ),
			'row'  => $row,
			'data' => array(),
			'meta' => $meta,
		);

		return $atts;
	}

	/**
	 * @param null|bool $result Non-null means another plugin handled delivery.
	 * @return null|bool Unchanged.
	 */
	public function short_circuit( $result ) {
		if ( null !== $result ) {
			$this->resolve( false === $result ? Repository::STATUS_FAILED : Repository::STATUS_SENT, false === $result ? 'pre_wp_mail returned false' : '' );
		}
		return $result;
	}

	/**
	 * Records what PHPMailer will actually send (other plugins may rewrite the
	 * body in phpmailer_init, e.g. HTML template wrappers).
	 *
	 * @param mixed $mailer PHPMailer instance passed by phpmailer_init.
	 */
	public function enrich( $mailer ): void {
		$key = array_key_last( $this->stack );
		if ( null === $key || ! $this->stack[ $key ]['id'] || ! $mailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			return;
		}

		$data = array(
			'content_type' => substr( (string) $mailer->ContentType, 0, 100 ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			'sender'       => substr( trim( $mailer->FromName ? sprintf( '%s <%s>', $mailer->FromName, $mailer->From ) : (string) $mailer->From ), 0, 255 ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		);

		$body = (string) $mailer->Body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( md5( $body ) !== $this->stack[ $key ]['hash'] ) {
			$data['message'] = Redactor::redact( $body );
		}

		$this->stack[ $key ]['data'] = $data;
		$this->stack[ $key ]['meta'] = (array) apply_filters( 'mailspur_meta', $this->stack[ $key ]['meta'], 'phpmailer', $mailer );
	}

	public function succeeded(): void {
		$this->resolve( Repository::STATUS_SENT );
	}

	/**
	 * @param mixed $error WP_Error from wp_mail().
	 */
	public function failed( $error ): void {
		$this->resolve( Repository::STATUS_FAILED, is_wp_error( $error ) ? $error->get_error_message() : '' );
	}

	/** Persists enrichment for mails sent by a pluggable wp_mail() that fires no result hooks. */
	public function flush(): void {
		while ( $this->stack ) {
			$this->finish( (array) array_pop( $this->stack ), null );
		}
	}

	private function resolve( int $status, string $error = '' ): void {
		$entry = array_pop( $this->stack );
		if ( $entry ) {
			$this->finish( $entry, $status, $error );
		}
	}

	/**
	 * Single UPDATE per mail: status, PHPMailer enrichment and everything modules collected.
	 *
	 * @param array<string,mixed> $entry  Stack entry.
	 * @param int|null            $status Null when the result is unknown (no result hook fired).
	 */
	private function finish( array $entry, ?int $status, string $error = '' ): void {
		if ( empty( $entry['id'] ) ) {
			return;
		}
		$data = (array) $entry['data'];
		if ( null !== $status ) {
			$data['status'] = $status;
		}
		if ( '' !== $error ) {
			$data['error'] = $error;
		}

		try {
			$data['meta'] = (array) apply_filters(
				'mailspur_meta',
				(array) $entry['meta'],
				'result',
				array(
					'status' => $status,
					'error'  => $error,
				)
			);
			$row          = array_merge( (array) $entry['row'], $data );
			$data['size'] = strlen( (string) ( $row['message'] ?? '' ) ) + strlen( (string) ( $row['headers'] ?? '' ) );
			$data         = (array) apply_filters( 'mailspur_finalize_row', $data, array_merge( $row, $data ) );
		} catch ( \Throwable $e ) { // A faulty module must not lose the status update.
			unset( $data['meta'] );
		}
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = self::encode( $data['meta'] );
		}

		$this->repository->update( (int) $entry['id'], $data );
		do_action( 'mailspur_logged', (int) $entry['id'], array_merge( (array) $entry['row'], $data ) );
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	public static function encode( array $meta ): string {
		return $meta ? (string) wp_json_encode( $meta ) : '';
	}

	/**
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @return array<string,string|int> Row for Repository::insert().
	 */
	private function normalize( array $atts, string $message ): array {
		$to          = self::to_list( $atts['to'] ?? array(), ',' );
		$headers     = self::to_list( $atts['headers'] ?? array(), "\n" );
		$attachments = array();

		foreach ( self::to_list( $atts['attachments'] ?? array(), "\n", true ) as $name => $path ) {
			$attachments[] = array(
				'name' => is_string( $name ) ? $name : wp_basename( (string) $path ),
				'path' => (string) $path,
			);
		}

		// Best guess from the headers; phpmailer_init replaces both with the final values.
		$content_type = '';
		$sender       = '';
		foreach ( $headers as $header ) {
			if ( preg_match( '/^content-type:\s*([^;\s]+)/i', $header, $m ) ) {
				$content_type = strtolower( $m[1] );
			} elseif ( preg_match( '/^from:\s*(.+)$/i', $header, $m ) ) {
				$sender = substr( trim( $m[1] ), 0, 255 );
			}
		}

		return array(
			'created_at'   => current_time( 'mysql', true ),
			'status'       => Repository::STATUS_PENDING,
			'recipients'   => implode( ', ', $to ),
			'subject'      => (string) ( $atts['subject'] ?? '' ),
			'message'      => Redactor::redact( $message ),
			'headers'      => implode( "\n", $headers ),
			'attachments'  => $attachments ? (string) wp_json_encode( $attachments ) : '',
			'content_type' => $content_type,
			'sender'       => $sender,
			'source'       => self::$source_override ? self::$source_override : $this->source(),
			'error'        => '',
			'meta'         => '',
			'notes'        => 0,
			'size'         => strlen( $message ),
			'raw'          => '',
		);
	}

	/**
	 * Same splitting rules wp_mail() applies to string arguments.
	 *
	 * @param mixed            $value
	 * @param non-empty-string $separator
	 * @return array<int|string,string>
	 */
	private static function to_list( $value, string $separator, bool $keep_keys = false ): array {
		if ( ! is_array( $value ) ) {
			$value = explode( $separator, str_replace( "\r\n", "\n", (string) $value ) );
		}
		$value = array_filter(
			array_map( 'trim', array_map( 'strval', $value ) ),
			static function ( string $item ): bool {
				return '' !== $item;
			}
		);
		return $keep_keys ? $value : array_values( $value );
	}

	/** Which plugin / theme triggered the mail, e.g. "plugin:woocommerce". */
	private function source(): string {
		if ( null === $this->self_dir ) {
			$this->self_dir = wp_normalize_path( dirname( FILE ) ) . '/';
		}
		$roots = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);

		/*
		 * Not debug code: the backtrace is the only way to tell which plugin or theme called wp_mail()
		 * (shown as "Source" in the log). Arguments are not collected and the depth is capped,
		 * so it is cheap and never exposes data; only the file paths of the frames are inspected.
		 */
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- feature, see above.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $this->self_dir ) ) {
				continue;
			}
			foreach ( $roots as $type => $root ) {
				if ( 0 === strpos( $file, $root ) ) {
					$slug = strtok( substr( $file, strlen( $root ) ), '/' );
					return substr( $type . ':' . preg_replace( '/\.php$/', '', (string) $slug ), 0, 100 );
				}
			}
		}
		return 'core';
	}
}
