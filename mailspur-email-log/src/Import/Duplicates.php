<?php
/**
 * Detects mails that are already in the log from another source.
 *
 * Typical cases: two log plugins were active at the same time, or Mailspur already logged a mail
 * that the old plugin logged too. A row counts as a duplicate when an entry from ANOTHER source
 * (Mailspur itself or a different import) has
 *   - the same recipient addresses (as a set: order and display names do not matter),
 *   - the same subject (normalized: entities, tags, whitespace, case; truncation tolerated),
 *   - a send time within ±window seconds (plugins write their timestamp at slightly different moments).
 *
 * Rows of the same source are never compared with each other: a plugin that logged two identical
 * mails a minute apart really saw two mails. The entry already in the log wins, so Mailspur's own
 * entry (the most accurate one) always beats an imported copy.
 *
 * @package Mailspur
 */

namespace Mailspur\Import;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Duplicates {

	const DEFAULT_WINDOW = 120;

	/** Subjects shorter than this must match exactly; longer ones may be truncated copies. */
	const MIN_PREFIX = 150;

	/** @var Repository */
	private $repository;

	/** @var int */
	private $window;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
		$this->window     = max( 0, (int) apply_filters( 'mailspur_import_duplicate_window', self::DEFAULT_WINDOW ) );
	}

	/**
	 * @param array<string,string|int> $row Mapped row about to be imported.
	 */
	public function exists( array $row ): bool {
		$emails = self::emails( (string) $row['recipients'] );
		if ( ! $emails || 0 === $this->window ) {
			return false;
		}
		$subject = self::subject( (string) $row['subject'] );

		$candidates = $this->repository->near( (string) $row['created_at'], $this->window, (string) $row['source'], $emails[0] );
		foreach ( $candidates as $candidate ) {
			if ( self::emails( $candidate['recipients'] ) === $emails && self::same_subject( $subject, self::subject( $candidate['subject'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sorted, unique, lower-cased addresses.
	 *
	 * @return string[]
	 */
	public static function emails( string $recipients ): array {
		$emails = array_values( array_unique( Repository::extract_emails( $recipients ) ) );
		sort( $emails );
		return $emails;
	}

	public static function subject( string $subject ): string {
		$subject = html_entity_decode( $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$subject = wp_strip_all_tags( $subject );
		$subject = (string) preg_replace( '/\s+/u', ' ', $subject );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $subject ) ) : strtolower( trim( $subject ) );
	}

	/**
	 * Equal, or one is a truncated copy of the other (e.g. WP Mail Logging: 195 chars + "...",
	 * VARCHAR(255) columns of other plugins).
	 */
	public static function same_subject( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		$short = strlen( $a ) <= strlen( $b ) ? $a : $b;
		$long  = $short === $a ? $b : $a;
		$short = rtrim( $short, ".… \t" );
		return strlen( $short ) >= self::MIN_PREFIX && 0 === strpos( $long, $short );
	}
}
