<?php
/**
 * Minimal WP-CLI declarations for static analysis (PHPStan scanFiles) – only what the plugin uses.
 * Not loaded at runtime or in unit tests.
 *
 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
 *
 * @package Mailspur
 */

/** WP-CLI facade. */
class WP_CLI {
	/**
	 * @param string|callable|object $command
	 * @param array<string,mixed>    $args
	 */
	public static function add_command( string $name, $command, array $args = array() ): bool {
		return true;
	}

	public static function line( string $message = '' ): void {}

	public static function log( string $message ): void {}

	public static function success( string $message ): void {}

	public static function warning( string $message ): void {}

	/**
	 * @param string|\WP_Error|\Exception $message
	 * @param bool|int                    $quit
	 * @return never
	 */
	public static function error( $message, $quit = true ) {
		exit( 1 );
	}

	/**
	 * @param array<string,mixed> $assoc_args
	 */
	public static function confirm( string $question, array $assoc_args = array() ): void {}
}
