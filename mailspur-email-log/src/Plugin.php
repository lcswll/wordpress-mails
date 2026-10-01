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
		}

		add_action( 'init', array( self::class, 'load_textdomain' ) );
	}

	/**
	 * Bundled translations are only a fallback: language packs from
	 * translate.wordpress.org (wp-content/languages/plugins) take precedence
	 * and are loaded just in time by WordPress itself.
	 */
	public static function load_textdomain(): void {
		$locale = determine_locale();
		if ( file_exists( WP_LANG_DIR . "/plugins/mailspur-email-log-{$locale}.mo" ) || file_exists( WP_LANG_DIR . "/plugins/mailspur-email-log-{$locale}.l10n.php" ) ) {
			return;
		}
		// WordPress 6.5+ prefers the .l10n.php variant of this path automatically.
		load_textdomain( 'mailspur-email-log', dirname( FILE ) . "/languages/mailspur-email-log-{$locale}.mo", $locale );
	}
}
