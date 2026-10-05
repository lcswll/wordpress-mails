<?php
/**
 * WP-CLI: wp mailspur probe – triggers core emails for an administrator's own account (see Probe), for deploy
 * scripts: exits with 1 when an email fails or none is sent.
 *
 * Registered only when WP_CLI is defined (Module::register()).
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use WP_CLI;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class ProbeCli {

	/** @var Probe */
	private $probe;

	public function __construct( Probe $probe ) {
		$this->probe = $probe;
	}

	/**
	 * Triggers WordPress core emails for an administrator's own account and checks that they are sent.
	 *
	 * Only the administrator receives the emails. The password reset creates a new reset link for that account
	 * (earlier reset links stop working); the password stays the same. No user is created.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Which emails to trigger.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - password-reset
	 *   - new-user
	 * ---
	 *
	 * [--to=<email>]
	 * : Email address of the administrator account (default: the site's administration email address, if it
	 * belongs to an administrator, otherwise the first administrator).
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
	 * ## EXAMPLES
	 *
	 *     # After a deploy: fails (exit code 1) when an email cannot be sent
	 *     $ wp mailspur probe --type=password-reset
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$type = (string) ( $assoc_args['type'] ?? 'all' );
		$user = self::user( (string) ( $assoc_args['to'] ?? '' ) );
		if ( null === $user ) {
			WP_CLI::error( 'No administrator account with this email address.' );
		}

		$rows   = array();
		$failed = 0;
		$held   = 0;
		foreach ( 'all' === $type ? Probe::KINDS : array( $type ) as $kind ) {
			$result = $this->probe->run( $kind, $user );
			if ( is_wp_error( $result ) ) {
				++$failed;
				$rows[] = array(
					'type'     => $kind,
					'subject'  => '',
					'status'   => 'failed',
					'duration' => '',
					'notes'    => '',
					'id'       => '',
					'error'    => $result->get_error_message(),
				);
				continue;
			}
			foreach ( $result as $mail ) {
				$failed += in_array( $mail['status'], array( 'failed', 'pending' ), true ) ? 1 : 0;
				$held   += 'held' === $mail['status'] ? 1 : 0;
				$rows[]  = array(
					'type'     => $kind,
					'subject'  => $mail['subject'],
					'status'   => $mail['status'],
					'duration' => $mail['duration'] . ' ms',
					'notes'    => $mail['notes'],
					'id'       => $mail['id'],
					'error'    => $mail['error'],
				);
			}
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $rows ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'subject', 'status', 'duration', 'notes', 'id', 'error' ) );
		}
		if ( $failed ) {
			WP_CLI::error( sprintf( '%d of %d probe emails failed (sent to %s).', $failed, count( $rows ), $user->user_email ) );
		}
		if ( $held ) {
			WP_CLI::warning( sprintf( '%d probe emails were held (staging mode) instead of being delivered.', $held ) );
		}
		WP_CLI::success( sprintf( '%d probe emails sent to %s.', count( $rows ) - $held, $user->user_email ) );
	}

	/** The administrator to probe for: by --to, else the administration email address, else the first administrator. */
	public static function user( string $email ): ?WP_User {
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			return $user instanceof WP_User && user_can( $user, 'manage_options' ) ? $user : null;
		}
		$user = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
			return $user;
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
			)
		);
		return isset( $admins[0] ) && $admins[0] instanceof WP_User ? $admins[0] : null;
	}
}
