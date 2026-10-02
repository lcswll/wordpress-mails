<?php
/**
 * Distinct "source" values of the log (which plugin/theme sent the mails) with counts and readable
 * labels, for the source filter: GET mailspur-email-log/v1/sources.
 *
 * One GROUP BY over the indexed source column, cached for a few minutes in a transient.
 *
 * Direct query: the plugin's own table.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Workflow;

use Mailspur\Import\Importer;
use Mailspur\Repository;
use Mailspur\Rest;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Sources {

	const TRANSIENT = 'mailspur_sources';
	const TTL       = 300;
	const LIMIT     = 500;

	public function register_routes(): void {
		register_rest_route(
			Rest::NS,
			'/sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest' ),
				'permission_callback' => array( Module::class, 'can_view' ),
				'args'                => array(
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public function rest( \WP_REST_Request $request ): WP_REST_Response {
		if ( $request['refresh'] ) {
			delete_transient( self::TRANSIENT );
		}
		$response = new WP_REST_Response( array( 'sources' => $this->all() ) );
		$response->header( 'Cache-Control', 'private, max-age=60' );
		return $response;
	}

	/**
	 * @return array<int,array{value:string,label:string,count:int}> Most frequent first.
	 */
	public function all(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT source, COUNT(*) AS n FROM %i GROUP BY source ORDER BY n DESC LIMIT %d', Repository::table(), self::LIMIT ),
			ARRAY_A
		);

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = self::names( get_plugins() );
		$mu      = self::names( get_mu_plugins() );

		$list = array();
		foreach ( $rows as $row ) {
			$value  = (string) $row['source'];
			$list[] = array(
				'value' => $value,
				'label' => self::label( $value, $plugins, $mu ),
				'count' => (int) $row['n'],
			);
		}

		set_transient( self::TRANSIENT, $list, self::TTL );
		return $list;
	}

	/**
	 * Plugin names by slug (folder, or file name without .php for single-file plugins).
	 *
	 * @param array<string,array<string,mixed>> $plugins As returned by get_plugins().
	 * @return array<string,string>
	 */
	public static function names( array $plugins ): array {
		$out = array();
		foreach ( $plugins as $file => $data ) {
			$slug = false !== strpos( $file, '/' ) ? strtok( $file, '/' ) : preg_replace( '/\.php$/', '', $file );
			if ( is_string( $slug ) && '' !== $slug && ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = (string) ( $data['Name'] ?? $slug );
			}
		}
		return $out;
	}

	/**
	 * Readable label of a source value as written by the Logger ("plugin:woocommerce", "theme:foo", "core" …).
	 *
	 * @param array<string,string> $plugins    Plugin names by slug.
	 * @param array<string,string> $mu_plugins Must-use plugin names by slug.
	 */
	public static function label( string $source, array $plugins, array $mu_plugins ): string {
		if ( '' === $source || 'core' === $source ) {
			return __( 'WordPress', 'mailspur-email-log' );
		}
		if ( 'mailspur:resend' === $source ) {
			return __( 'Resent from log', 'mailspur-email-log' );
		}

		$pos  = strpos( $source, ':' );
		$type = false === $pos ? '' : substr( $source, 0, $pos );
		$slug = false === $pos ? $source : substr( $source, $pos + 1 );

		switch ( $type ) {
			case 'plugin':
				return $plugins[ $slug ] ?? $slug;
			case 'mu-plugin':
				/* translators: %s: plugin name */
				return sprintf( __( '%s (must-use plugin)', 'mailspur-email-log' ), $mu_plugins[ $slug ] ?? $slug );
			case 'theme':
				$theme = wp_get_theme( $slug );
				/* translators: %s: theme name */
				return sprintf( __( '%s (theme)', 'mailspur-email-log' ), $theme->exists() ? (string) $theme->get( 'Name' ) : $slug );
			case 'import':
				$importer = Importer::source( $slug );
				/* translators: %s: name of another plugin, e.g. "WP Mail Logging" */
				return sprintf( __( 'Imported from %s', 'mailspur-email-log' ), $importer ? $importer->label() : $slug );
		}
		return $slug;
	}
}
