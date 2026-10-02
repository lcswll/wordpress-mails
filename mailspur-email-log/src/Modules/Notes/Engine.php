<?php
/**
 * Runs the note rules on a mail and normalizes their output.
 *
 * A note is array{ code: string, severity: 'error'|'warning'|'info', params: string[] }. Only these are
 * stored (meta "notes"); the texts come from Catalog at display time.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Engine {

	/** Severity => sort rank (worst first). */
	const SEVERITIES = array(
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
	);

	/** Notes stored per mail at most. */
	const MAX_NOTES = 20;

	/**
	 * Active rules, keyed by id. Each rule is callable( Mail $mail ): array of notes (see Rules::note()).
	 * Rules run while mails are sent: no network, no database queries, keep them fast.
	 *
	 * @return array<string,callable>
	 */
	public static function rules(): array {
		/**
		 * Add, replace or remove note rules.
		 *
		 * Example: $rules['my_rule'] = static function ( \Mailspur\Modules\Notes\Mail $mail ): array {
		 *     return false !== strpos( $mail->body, 'Lorem ipsum' ) ? array( \Mailspur\Modules\Notes\Rules::note( 'my_lorem', 'warning' ) ) : array();
		 * };
		 * Texts for your codes: filter mailspur_note_texts.
		 *
		 * @param array<string,callable> $rules
		 */
		$rules = (array) apply_filters( 'mailspur_note_rules', Rules::defaults() );
		return array_filter( $rules, 'is_callable' );
	}

	/**
	 * Runs every rule on the row. A failing rule is skipped, never breaks logging.
	 *
	 * @param array<string,mixed>                                    $row
	 * @param array{home:string,environment:string,multisite:bool}|null $site Defaults to the current site.
	 * @param string[]                                               $ignore Codes to drop.
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function analyze( array $row, ?array $site = null, array $ignore = array() ): array {
		$mail  = new Mail( $row, $site ?? self::site() );
		$notes = array();
		foreach ( self::rules() as $rule ) {
			try {
				$result = $rule( $mail );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( is_array( $result ) ) {
				$notes = array_merge( $notes, $result );
			}
		}
		return self::prepare( $notes, $ignore );
	}

	/**
	 * Validates (also data read back from the database), drops ignored codes and duplicates,
	 * sorts worst first and caps the list.
	 *
	 * @param mixed    $notes
	 * @param string[] $ignore
	 * @return array<int,array{code:string,severity:string,params:array<int,string>}>
	 */
	public static function prepare( $notes, array $ignore = array() ): array {
		$out = array();
		foreach ( is_array( $notes ) ? $notes : array() as $note ) {
			if ( ! is_array( $note ) || ! isset( $note['code'], $note['severity'] ) || ! is_string( $note['code'] ) ) {
				continue;
			}
			$code     = $note['code'];
			$severity = (string) $note['severity'];
			if ( ! preg_match( '/^[a-z0-9_.-]{1,64}$/', $code ) || ! isset( self::SEVERITIES[ $severity ] ) || in_array( $code, $ignore, true ) ) {
				continue;
			}
			$params = array();
			foreach ( is_array( $note['params'] ?? null ) ? $note['params'] : array() as $param ) {
				if ( is_scalar( $param ) ) {
					$params[] = function_exists( 'mb_substr' ) ? mb_substr( (string) $param, 0, 200, 'UTF-8' ) : substr( (string) $param, 0, 200 );
				}
			}
			$params = array_slice( $params, 0, 5 );
			$key    = $code . "\0" . implode( "\0", $params );

			$out[ $key ] = array(
				'code'     => $code,
				'severity' => $severity,
				'params'   => $params,
			);
		}
		return array_slice( self::sort( array_values( $out ) ), 0, self::MAX_NOTES );
	}

	/**
	 * Worst first, keeping the rules' order within a severity (usort() is not stable on PHP 7).
	 *
	 * @template T of array{severity:string}
	 * @param array<int,T> $notes
	 * @return array<int,T>
	 */
	public static function sort( array $notes ): array {
		$ranks = array();
		foreach ( $notes as $i => $note ) {
			$ranks[ $i ] = self::SEVERITIES[ $note['severity'] ] ?? count( self::SEVERITIES );
		}
		uksort(
			$notes,
			static function ( int $a, int $b ) use ( $ranks ): int {
				$by_rank = $ranks[ $a ] <=> $ranks[ $b ];
				return 0 !== $by_rank ? $by_rank : $a <=> $b;
			}
		);
		return array_values( $notes );
	}

	/**
	 * Worst severity of the notes, or ''.
	 *
	 * @param array<int,array{code:string,severity:string,params:array<int,string>}> $notes Sorted by prepare().
	 */
	public static function worst( array $notes ): string {
		return $notes ? $notes[0]['severity'] : '';
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'notes_enabled' );
	}

	/**
	 * Codes the admin chose to ignore.
	 *
	 * @return string[]
	 */
	public static function ignored(): array {
		return self::parse_codes( (string) Settings::get( 'notes_ignore' ) );
	}

	/**
	 * @return string[]
	 */
	public static function parse_codes( string $codes ): array {
		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', strtolower( $codes ) ) ),
				static function ( string $code ): bool {
					return '' !== $code;
				}
			)
		);
	}

	/**
	 * Site context for the rules (cached options only, no I/O; not memoized because of switch_to_blog()).
	 *
	 * @return array{home:string,environment:string,multisite:bool}
	 */
	public static function site(): array {
		return array(
			'home'        => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'environment' => wp_get_environment_type(),
			'multisite'   => is_multisite(),
		);
	}
}
