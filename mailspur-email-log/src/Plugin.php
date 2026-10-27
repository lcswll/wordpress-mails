<?php
/**
 * Bootstrap: wires all components to WordPress.
 *
 * @package Mailspur
 */

namespace Mailspur;

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

		// Feature modules first, so their settings defaults and filters exist before anything runs.
		foreach ( (array) require __DIR__ . '/Modules.php' as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, Module::class ) ) {
				( new $class( $repository ) )->register();
			}
		}

		( new Logger( $repository ) )->register();
		( new Cleanup( $repository ) )->register();
		( new Privacy( $repository ) )->register();

		add_action(
			'rest_api_init',
			static function () use ( $repository ): void {
				( new Rest( $repository ) )->register_routes();
				( new Import\Controller( new Import\Importer( $repository ) ) )->register_routes();
			}
		);

		if ( is_admin() ) {
			( new Admin( $repository ) )->register();
			( new Review() )->register();
		}
	}
}
