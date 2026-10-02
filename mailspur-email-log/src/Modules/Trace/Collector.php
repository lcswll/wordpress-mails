<?php
/**
 * Collects the trace of every logged mail through the mailspur_meta phases and stores it under
 * $meta['trace'] (a few KB at most). No database queries, no network calls.
 *
 *   capture   → start time, call site, hook stack, request context
 *   phpmailer → transport settings; optionally starts the SMTP transcript (SMTPDebug 3)
 *   result    → timeline, API handler (pre_wp_mail), transcript and raw source according to the settings
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Trace;

use Mailspur\Repository;
use Mailspur\Settings;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

final class Collector {

	const TRANSCRIPT_OFF    = 'off';
	const TRANSCRIPT_FAILED = 'failed';
	const TRANSCRIPT_ALWAYS = 'always';

	/** @var int */
	private $sequence = 0;

	/**
	 * Mails in flight, keyed by the sequence number kept in $meta['trace']['_k'].
	 *
	 * @var array<int,array{mailer:PHPMailer|null,transcript:Transcript|null,closure:\Closure|null,debug_output:mixed,mime_before:string}>
	 */
	private $mails = array();

	/** @var string|null Raw MIME source waiting for mailspur_finalize_row. */
	private $raw;

	/**
	 * Filter mailspur_meta.
	 *
	 * @param mixed  $meta
	 * @param mixed  $phase
	 * @param mixed  $context
	 * @return mixed
	 */
	public function meta( $meta, $phase = '', $context = null ) {
		if ( ! is_array( $meta ) ) {
			return $meta;
		}
		try {
			switch ( $phase ) {
				case 'capture':
					$meta['trace'] = $this->capture();
					break;
				case 'phpmailer':
					if ( isset( $meta['trace'] ) && is_array( $meta['trace'] ) && $context instanceof PHPMailer ) {
						$meta['trace'] = $this->phpmailer( $meta['trace'], $context );
					}
					break;
				case 'result':
					if ( isset( $meta['trace'] ) && is_array( $meta['trace'] ) ) {
						$meta['trace'] = $this->result( $meta['trace'], is_array( $context ) ? $context : array() );
					}
					break;
			}
		} catch ( \Throwable $e ) { // The trace is a nice-to-have; never lose the rest of the meta.
			unset( $e );
		}
		return $meta;
	}

	/**
	 * Filter mailspur_finalize_row: hands the raw source of the mail that was just resolved to the row.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public function finalize( $data ) {
		if ( null !== $this->raw && is_array( $data ) ) {
			$data['raw'] = Eml::pack( $this->raw );
		}
		$this->raw = null;
		return $data;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function capture(): array {
		$key                 = ++$this->sequence;
		$this->mails[ $key ] = array(
			'mailer'       => null,
			'transcript'   => null,
			'closure'      => null,
			'debug_output' => null,
			'mime_before'  => '',
		);

		$trace = array(
			'_k'      => $key,
			'_t0'     => microtime( true ),
			'origin'  => Inspector::origin(),
			'hooks'   => Inspector::hooks( isset( $GLOBALS['wp_current_filter'] ) && is_array( $GLOBALS['wp_current_filter'] ) ? $GLOBALS['wp_current_filter'] : array() ),
			'request' => Inspector::request(),
		);
		return array_filter(
			$trace,
			static function ( $value ): bool {
				return null !== $value && array() !== $value;
			}
		);
	}

	/**
	 * @param array<string,mixed> $trace
	 * @return array<string,mixed>
	 */
	private function phpmailer( array $trace, PHPMailer $mailer ): array {
		$trace['_t1']       = microtime( true );
		$trace['transport'] = Inspector::transport( $mailer );

		$key = (int) ( $trace['_k'] ?? 0 );
		if ( ! isset( $this->mails[ $key ] ) ) {
			return $trace;
		}
		$this->mails[ $key ]['mailer'] = $mailer;

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		if ( self::TRANSCRIPT_OFF === self::transcript_mode() ) {
			$trace['transcript_status'] = 'off';
		} elseif ( 'smtp' !== $trace['transport']['mailer'] ) {
			$trace['transcript_status'] = 'not_smtp';
		} elseif ( 0 !== (int) $mailer->SMTPDebug ) {
			$trace['transcript_status'] = 'debug_in_use'; // Another plugin is debugging; leave it alone.
		} else {
			$transcript = new Transcript();
			$closure    = static function ( $line ) use ( $transcript ): void {
				$transcript->add( (string) $line );
			};

			$this->mails[ $key ]['transcript']   = $transcript;
			$this->mails[ $key ]['closure']      = $closure;
			$this->mails[ $key ]['debug_output'] = $mailer->Debugoutput;
			$mailer->SMTPDebug                   = 3; // SMTP::DEBUG_CONNECTION.
			$mailer->Debugoutput                 = $closure;
		}

		if ( Settings::get( 'trace_raw' ) ) {
			// The global PHPMailer still holds the previous message; only a changed source belongs to this mail.
			$this->mails[ $key ]['mime_before'] = md5( $mailer->getSentMIMEMessage() );
		}
		// phpcs:enable

		return $trace;
	}

	/**
	 * @param array<string,mixed> $trace
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function result( array $trace, array $result ): array {
		$this->raw = null;
		$now       = microtime( true );
		$key       = (int) ( $trace['_k'] ?? 0 );
		$state     = $this->mails[ $key ] ?? null;
		unset( $this->mails[ $key ] );

		$status = isset( $result['status'] ) ? (int) $result['status'] : null;
		$start  = (float) ( $trace['_t0'] ?? $now );

		$timeline = array( 'capture' => 0.0 );
		if ( isset( $trace['_t1'] ) ) {
			$timeline['phpmailer'] = self::ms( (float) $trace['_t1'] - $start );
			$trace['via']          = 'phpmailer';
		} elseif ( null !== $status && doing_filter( 'pre_wp_mail' ) ) {
			$trace['via']       = 'pre_wp_mail';
			$hook               = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
			$trace['transport'] = array(
				'mailer'   => 'api',
				'handlers' => Inspector::api_handlers( $hook instanceof \WP_Hook ? $hook->callbacks : array() ),
			);
		} else {
			$trace['via'] = 'unknown'; // E.g. a replaced wp_mail() that fires no result hook.
		}
		$timeline['result'] = self::ms( $now - $start );
		$trace['timeline']  = $timeline;
		$trace['total_ms']  = $timeline['result'];

		if ( $state && $state['transcript'] instanceof Transcript && $state['mailer'] instanceof PHPMailer ) {
			$this->stop_transcript( $state['mailer'], $state['closure'], $state['debug_output'] );
			$text = $state['transcript']->text();
			$mode = self::transcript_mode();
			if ( self::TRANSCRIPT_ALWAYS === $mode || ( self::TRANSCRIPT_FAILED === $mode && Repository::STATUS_FAILED === $status ) ) {
				$trace['transcript']        = $text;
				$trace['transcript_status'] = '' === $text ? 'empty' : 'stored';
			} else {
				$trace['transcript_status'] = 'not_failed';
			}
		}

		if ( $state && $state['mailer'] instanceof PHPMailer && Settings::get( 'trace_raw' ) ) {
			$mime = $state['mailer']->getSentMIMEMessage();
			if ( strlen( $mime ) > Eml::MAX_RAW_BYTES ) {
				$trace['raw'] = 'too_large';
			} elseif ( '' !== $state['mime_before'] && md5( $mime ) !== $state['mime_before'] ) {
				$this->raw    = $mime;
				$trace['raw'] = 'stored';
			}
		}

		unset( $trace['_k'], $trace['_t0'], $trace['_t1'] );
		return $trace;
	}

	/**
	 * Hands PHPMailer back as it was: the instance is reused for the next mail.
	 *
	 * @param \Closure|null $closure  Our debug output collector.
	 * @param mixed         $previous Debugoutput before the transcript started.
	 */
	private function stop_transcript( PHPMailer $mailer, ?\Closure $closure, $previous ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		if ( null !== $closure && $mailer->Debugoutput === $closure ) {
			$mailer->SMTPDebug   = 0;
			$mailer->Debugoutput = $previous;
		}
		// phpcs:enable
	}

	public static function transcript_mode(): string {
		$mode = (string) Settings::get( 'trace_transcript' );
		return in_array( $mode, array( self::TRANSCRIPT_OFF, self::TRANSCRIPT_FAILED, self::TRANSCRIPT_ALWAYS ), true ) ? $mode : self::TRANSCRIPT_FAILED;
	}

	private static function ms( float $seconds ): float {
		return round( max( 0.0, $seconds ) * 1000, 1 );
	}
}
