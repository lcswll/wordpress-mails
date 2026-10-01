<?php
/**
 * Bootstrap: wires all components to WordPress.
 *
 * @package Outbox
 */

namespace Outbox;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var bool */
	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Installer::maybe_upgrade();

		$repository = new Repository();

		( new Logger( $repository ) )->register();
		( new Cleanup( $repository ) )->register();
		( new Privacy( $repository ) )->register();

		add_action(
			'rest_api_init',
			static function () use ( $repository ): void {
				( new Rest( $repository ) )->register_routes();
			}
		);

		if ( is_admin() ) {
			( new Admin( $repository ) )->register();
		}

		add_action( 'init', array( self::class, 'load_textdomain' ) );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'outbox-mail-log', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}
}
