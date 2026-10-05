<?php
/**
 * WP-CLI: wp mailspur brake status|release|discard|reset.
 *
 * Registered only when WP_CLI is defined (Module::register()).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Emergency brake for mail floods.
 *
 * ## EXAMPLES
 *
 *     $ wp mailspur brake status
 *     $ wp mailspur brake release --yes
 */
final class BrakeCli {

	/** @var Brake */
	private $brake;

	public function __construct( Brake $brake ) {
		$this->brake = $brake;
	}

	/**
	 * Shows whether the emergency brake is active and how many emails it holds.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = $this->brake->status();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status ) );
			return;
		}
		$top = array();
		foreach ( (array) $status['sources'] as $item ) {
			$top[] = sprintf( '%s (%d)', $item['value'], $item['count'] );
		}
		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'mode'      => $status['mode'],
					'active'    => $status['active'] ? 'yes' : 'no',
					'count'     => $status['count'],
					'threshold' => $status['threshold'],
					'held'      => $status['held'],
					'sources'   => implode( ', ', $top ),
				),
			),
			array( 'mode', 'active', 'count', 'threshold', 'held', 'sources' )
		);
	}

	/**
	 * Sends all emails held by the emergency brake to their original recipients and ends the incident.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function release( array $args, array $assoc_args ): void {
		$held = $this->brake->held_count();
		if ( ! $held ) {
			$this->brake->reset();
			WP_CLI::success( 'No held emails. The emergency brake was reset.' );
			return;
		}
		WP_CLI::confirm( sprintf( 'Send %d held emails now?', $held ), $assoc_args );
		$sent   = 0;
		$failed = 0;
		do {
			$batch   = $this->brake->release_batch();
			$sent   += $batch['sent'];
			$failed += $batch['failed'];
			WP_CLI::log( sprintf( '%d sent, %d failed, %d still held.', $sent, $failed, $batch['remaining'] ) );
		} while ( $batch['remaining'] > 0 && ( $batch['sent'] + $batch['failed'] ) > 0 );
		WP_CLI::success( sprintf( 'Released: %d sent, %d failed.', $sent, $failed ) );
	}

	/**
	 * Discards all emails held by the emergency brake (they stay in the log) and ends the incident.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function discard( array $args, array $assoc_args ): void {
		WP_CLI::confirm( sprintf( 'Discard %d held emails?', $this->brake->held_count() ), $assoc_args );
		WP_CLI::success( sprintf( '%d held emails discarded.', $this->brake->discard() ) );
	}

	/**
	 * Ends the current incident and pauses the emergency brake for an hour. Held emails stay held.
	 */
	public function reset(): void {
		$this->brake->reset();
		WP_CLI::success( 'The emergency brake was reset.' );
	}
}
