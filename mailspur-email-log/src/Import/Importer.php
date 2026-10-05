<?php
/**
 * Imports the log of another mail logging plugin into Mailspur, in resumable batches.
 *
 * - Read-only towards the other plugin: its table is never modified.
 * - Progress (last imported primary key per source) is stored, so running it again only picks up
 *   new rows – no duplicates – and a long import survives page reloads.
 * - Entries older than the retention period are skipped (the daily cleanup would delete them anyway).
 * - Mails already in the log from another source (Mailspur itself or another import) are skipped as
 *   duplicates, see Duplicates.
 * - Secrets (in links and in plain text) are redacted like for logged mails.
 *
 * @package Mailspur
 */

namespace Mailspur\Import;

use Mailspur\Redactor;
use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Importer {

	const STATE_OPTION = 'mailspur_import';
	const LOCK_PREFIX  = 'mailspur_import_lock_';
	const BATCH        = 250;

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Every supported source, keyed by id.
	 *
	 * @return array<string,Source>
	 */
	public static function sources(): array {
		$sources = array(
			new Sources\WpMailLogging(),
			new Sources\EmailLog(),
			new Sources\CheckEmail(),
			new Sources\FluentSmtp(),
			new Sources\PostSmtp(),
			new Sources\SureMails(),
			new Sources\WpMailCatcher(),
			new Sources\WpMailLog(),
		);
		$out     = array();
		foreach ( $sources as $source ) {
			$out[ $source->id() ] = $source;
		}
		return $out;
	}

	public static function source( string $id ): ?Source {
		return self::sources()[ $id ] ?? null;
	}

	/**
	 * Status of every source whose table exists on this site.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function overview(): array {
		$out = array();
		foreach ( self::sources() as $source ) {
			if ( ! $source->available() ) {
				continue;
			}
			$state = $this->state( $source );
			$out[] = array(
				'id'         => $source->id(),
				'label'      => $source->label(),
				'total'      => $source->remaining( 0 ),
				'remaining'  => $source->remaining( $state['last'] ),
				'imported'   => $state['imported'],
				'skipped'    => $state['skipped'],
				'duplicates' => $state['duplicates'],
			);
		}
		return $out;
	}

	/**
	 * Imports the next batch.
	 *
	 * @return array{imported:int,skipped:int,duplicates:int,remaining:int,done:bool}|null Null when another import of this source is running.
	 */
	public function run( Source $source, int $batch = self::BATCH ): ?array {
		if ( ! $this->lock( $source ) ) {
			return null;
		}

		try {
			$state  = $this->state( $source );
			$rows   = $source->fetch( $state['last'], $batch );
			$cutoff = self::retention_cutoff();
			$insert = array();
			$skip   = 0;
			$dupes  = 0;
			$check  = new Duplicates( $this->repository );

			foreach ( $rows as $raw ) {
				$state['last'] = max( $state['last'], $source->key_of( $raw ) );
				$row           = $source->map( $raw );
				if ( null === $row || ( $cutoff && $row['created_at'] < $cutoff ) ) {
					++$skip;
					continue;
				}
				if ( $check->exists( $row ) ) {
					++$dupes;
					continue;
				}
				$insert[] = self::finalize( $row, $source );
			}

			// Several smaller INSERTs keep each statement well below max_allowed_packet with large bodies.
			foreach ( array_chunk( $insert, 50 ) as $chunk ) {
				$this->repository->insert_many( $chunk );
			}

			$state['imported']   += count( $insert );
			$state['skipped']    += $skip;
			$state['duplicates'] += $dupes;
			$this->save_state( $source, $state );

			return array(
				'imported'   => count( $insert ),
				'skipped'    => $skip,
				'duplicates' => $dupes,
				'remaining'  => $source->remaining( $state['last'] ),
				'done'       => count( $rows ) < $batch,
			);
		} finally {
			delete_option( self::LOCK_PREFIX . $source->id() );
		}
	}

	/**
	 * Redacts the message and lets modules add their data to an imported row (same filters as for
	 * logged mails: mailspur_meta sees the message as sent, mailspur_finalize_row the redacted row).
	 *
	 * @param array<string,string|int> $row
	 * @return array<string,string|int>
	 */
	private static function finalize( array $row, Source $source ): array {
		$meta           = (array) apply_filters( 'mailspur_meta', array( 'import' => $source->id() ), 'import', $row );
		$row['message'] = Redactor::redact( (string) $row['message'] );
		$data           = $row;
		$data['meta']   = $meta;
		$data           = (array) apply_filters( 'mailspur_finalize_row', $data, $data );
		$data['meta']   = is_array( $data['meta'] ) ? \Mailspur\Logger::encode( $data['meta'] ) : (string) $data['meta'];
		return array_merge( $row, $data );
	}

	/** Removes everything imported from this source and resets its progress. */
	public function undo( Source $source ): int {
		$deleted = $this->repository->delete_by_source( 'import:' . $source->id() );
		$all     = (array) get_option( self::STATE_OPTION, array() );
		unset( $all[ $source->id() ] );
		update_option( self::STATE_OPTION, $all, false );
		return $deleted;
	}

	/** GMT datetime before which entries are not imported, or '' without retention. */
	public static function retention_cutoff(): string {
		$days = (int) Settings::get( 'retention_days' );
		return $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '';
	}

	/**
	 * @return array{last:int,imported:int,skipped:int,duplicates:int}
	 */
	private function state( Source $source ): array {
		$all   = (array) get_option( self::STATE_OPTION, array() );
		$state = isset( $all[ $source->id() ] ) && is_array( $all[ $source->id() ] ) ? $all[ $source->id() ] : array();
		return array(
			'last'       => (int) ( $state['last'] ?? 0 ),
			'imported'   => (int) ( $state['imported'] ?? 0 ),
			'skipped'    => (int) ( $state['skipped'] ?? 0 ),
			'duplicates' => (int) ( $state['duplicates'] ?? 0 ),
		);
	}

	/**
	 * @param array{last:int,imported:int,skipped:int,duplicates:int} $state
	 */
	private function save_state( Source $source, array $state ): void {
		$all                  = (array) get_option( self::STATE_OPTION, array() );
		$all[ $source->id() ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	/** Atomic per-source lock (add_option fails if it exists); stale locks expire after 5 minutes. */
	private function lock( Source $source ): bool {
		$name = self::LOCK_PREFIX . $source->id();
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( $name ) > 5 * MINUTE_IN_SECONDS ) {
			update_option( $name, time(), false );
			return true;
		}
		return false;
	}
}
