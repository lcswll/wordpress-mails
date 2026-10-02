<?php
/**
 * Remembers recent plugin, theme and WordPress updates, so a stopped email type can say what changed on the
 * site since it was last sent ("WooCommerce was updated 2 hours after the last order confirmation").
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

defined( 'ABSPATH' ) || exit;

final class Updates {

	const OPTION = 'mailspur_types_updates';
	const KEEP   = 50;

	/**
	 * upgrader_process_complete.
	 *
	 * @param mixed $upgrader Upgrader instance (unused).
	 * @param mixed $options  Hook extra: action, type, plugins/themes.
	 */
	public static function record( $upgrader, $options ): void {
		if ( ! is_array( $options ) || ! in_array( $options['action'] ?? '', array( 'update', 'install' ), true ) ) {
			return;
		}
		$entries = array();
		$type    = (string) ( $options['type'] ?? '' );

		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			wp_clean_plugins_cache( false );
			$all   = get_plugins();
			$files = isset( $options['plugins'] ) ? (array) $options['plugins'] : ( isset( $options['plugin'] ) ? array( $options['plugin'] ) : array() );
			foreach ( $files as $file ) {
				$file = (string) $file;
				$slug = false !== strpos( $file, '/' ) ? (string) strtok( $file, '/' ) : (string) preg_replace( '/\.php$/', '', $file );
				$data = $all[ $file ] ?? array();
				$name = (string) ( $data['Name'] ?? $slug );
				$ver  = (string) ( $data['Version'] ?? '' );
				if ( '' !== $slug ) {
					$entries[] = self::entry( 'plugin:' . $slug, trim( $name . ' ' . $ver ) );
				}
			}
		} elseif ( 'theme' === $type ) {
			$slugs = isset( $options['themes'] ) ? (array) $options['themes'] : ( isset( $options['theme'] ) ? array( $options['theme'] ) : array() );
			foreach ( $slugs as $slug ) {
				$theme     = wp_get_theme( (string) $slug );
				$label     = $theme->exists() ? trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ) : (string) $slug;
				$entries[] = self::entry( 'theme:' . $slug, $label );
			}
		} elseif ( 'core' === $type ) {
			$entries[] = self::entry( 'core', __( 'WordPress', 'mailspur-email-log' ) );
		} elseif ( 'translation' === $type ) {
			return;
		}

		if ( $entries ) {
			$list = array_merge( self::all(), $entries );
			update_option( self::OPTION, array_slice( $list, -self::KEEP ), false );
		}
	}

	/**
	 * @return array{time:int,label:string,slug:string}
	 */
	private static function entry( string $slug, string $label ): array {
		return array(
			'time'  => time(),
			'label' => substr( wp_strip_all_tags( $label ), 0, 120 ),
			'slug'  => substr( $slug, 0, 100 ),
		);
	}

	/**
	 * Oldest first.
	 *
	 * @return array<int,array{time:int,label:string,slug:string}>
	 */
	public static function all(): array {
		$list = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$out[] = array(
					'time'  => (int) ( $entry['time'] ?? 0 ),
					'label' => (string) ( $entry['label'] ?? '' ),
					'slug'  => (string) ( $entry['slug'] ?? '' ),
				);
			}
		}
		return $out;
	}
}
