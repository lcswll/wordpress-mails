<?php
/**
 * Read-only helpers that describe where a mail came from and how it left: call site, hook stack,
 * request context, transport settings and the code behind a pre_wp_mail short-circuit.
 *
 * Nothing here queries the database or the network.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Trace;

use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

final class Inspector {

	/** Hooks that are on the stack only because Mailspur is collecting data (innermost first). */
	const OWN_HOOKS = array( 'mailspur_meta', 'wp_mail' );

	/** @var string|null */
	private static $self_dir;

	/**
	 * Call site of wp_mail(): file (relative to ABSPATH), line, calling function and component.
	 *
	 * @return array{file:string,line:int,function:string,component:string}|null
	 */
	public static function origin(): ?array {
		/*
		 * Not debug code: the backtrace is the only way to tell which code called wp_mail(). Arguments are
		 * not collected and the depth is capped, so it is cheap and never exposes data.
		 */
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- feature, see above.
		return self::origin_from( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $frames Backtrace (innermost first).
	 * @return array{file:string,line:int,function:string,component:string}|null
	 */
	public static function origin_from( array $frames ): ?array {
		foreach ( $frames as $i => $frame ) {
			if ( 'wp_mail' !== ( $frame['function'] ?? '' ) || isset( $frame['class'] ) ) {
				continue;
			}
			if ( empty( $frame['file'] ) ) {
				return null;
			}
			$caller   = $frames[ $i + 1 ] ?? array();
			$function = (string) ( $caller['function'] ?? '' );
			if ( in_array( $function, array( 'require', 'require_once', 'include', 'include_once' ), true ) ) {
				$function = '';
			} elseif ( '' !== $function && isset( $caller['class'] ) ) {
				$function = $caller['class'] . ( $caller['type'] ?? '::' ) . $function;
			}
			$file = (string) $frame['file'];
			return array(
				'file'      => self::relative_path( $file ),
				'line'      => (int) ( $frame['line'] ?? 0 ),
				'function'  => substr( $function, 0, 200 ),
				'component' => self::component( $file ),
			);
		}
		return null;
	}

	/**
	 * Hooks running when wp_mail() was called, outermost first (without Mailspur's own).
	 *
	 * @param array<int,mixed> $stack Usually $GLOBALS['wp_current_filter'].
	 * @return string[]
	 */
	public static function hooks( array $stack ): array {
		$stack = array_values( array_map( 'strval', $stack ) );
		foreach ( self::OWN_HOOKS as $own ) {
			if ( $stack && end( $stack ) === $own ) {
				array_pop( $stack );
			}
		}
		$stack = array_slice( $stack, -15 );
		return array_map(
			static function ( string $hook ): string {
				return substr( $hook, 0, 100 );
			},
			$stack
		);
	}

	/**
	 * Kind of request that sent the mail. No IP addresses, no query strings.
	 *
	 * @return array<string,string|int>
	 */
	public static function request(): array {
		if ( wp_doing_cron() ) {
			$type = 'cron';
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			$type = 'cli';
		} elseif ( wp_is_serving_rest_request() ) {
			$type = 'rest';
		} elseif ( wp_doing_ajax() ) {
			$type = 'ajax';
		} elseif ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$type = 'xmlrpc';
		} elseif ( is_admin() ) {
			$type = 'admin';
		} elseif ( 'cli' === PHP_SAPI && empty( $_SERVER['REQUEST_URI'] ) ) {
			$type = 'cli';
		} else {
			$type = 'frontend';
		}

		$out = array( 'type' => $type );

		// The cron event that is running: the outermost hook (wp-cron.php fires each event at the top level).
		if ( 'cron' === $type && ! empty( $GLOBALS['wp_current_filter'] ) && is_array( $GLOBALS['wp_current_filter'] ) ) {
			$out['hook'] = substr( (string) reset( $GLOBALS['wp_current_filter'] ), 0, 100 );
		}

		if ( 'cli' !== $type && isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$method = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) );
			if ( '' !== $method ) {
				$out['method'] = substr( $method, 0, 10 );
			}
		}

		if ( 'cli' !== $type && isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = self::path_only( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			if ( '' !== $path ) {
				$out['path'] = $path;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the route name is read (sites without pretty permalinks).
		if ( 'rest' === $type && isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			$route = self::path_only( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) );
			if ( '' !== $route ) {
				$out['route'] = $route;
			}
		}

		// Only when WordPress already knows the user: resolving it here could cost queries.
		if ( did_action( 'set_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user->exists() ) {
				$out['user_id']    = (int) $user->ID;
				$out['user_login'] = substr( (string) $user->user_login, 0, 60 );
			}
		}

		return $out;
	}

	/** URL path without query string or fragment (privacy: tokens often live in the query). */
	public static function path_only( string $uri ): string {
		$path = substr( $uri, 0, strcspn( $uri, '?#' ) );
		return substr( $path, 0, 200 );
	}

	/**
	 * Transport settings from PHPMailer after phpmailer_init. Never the password.
	 *
	 * @return array<string,string|int|bool>
	 */
	public static function transport( PHPMailer $mailer ): array {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		$out = array( 'mailer' => substr( strtolower( (string) $mailer->Mailer ), 0, 30 ) );
		if ( 'smtp' === $out['mailer'] ) {
			$out['host']     = substr( (string) $mailer->Host, 0, 200 );
			$out['port']     = (int) $mailer->Port;
			$out['secure']   = substr( (string) $mailer->SMTPSecure, 0, 10 );
			$out['auth']     = (bool) $mailer->SMTPAuth;
			$out['auto_tls'] = (bool) $mailer->SMTPAutoTLS;
			if ( $out['auth'] && '' !== (string) $mailer->Username ) {
				$out['user'] = self::mask_user( (string) $mailer->Username );
			}
		}
		// phpcs:enable
		return $out;
	}

	/** "john@example.com" → "j***@example.com", "apikey" → "a***". */
	public static function mask_user( string $user ): string {
		$at    = strrpos( $user, '@' );
		$first = mb_substr( $user, 0, 1 );
		if ( false !== $at && $at > 0 ) {
			return $first . '***' . substr( substr( $user, $at ), 0, 120 );
		}
		return mb_strlen( $user ) > 2 ? $first . '***' : '***';
	}

	/**
	 * Code hooked into pre_wp_mail (candidates for an API delivery), Mailspur's own callbacks excluded.
	 *
	 * @param array<int|string,mixed> $callbacks WP_Hook::$callbacks (priority => id => {function}).
	 * @return array<int,array{component:string,callback:string,file:string}>
	 */
	public static function api_handlers( array $callbacks ): array {
		ksort( $callbacks );
		$out = array();
		foreach ( $callbacks as $list ) {
			foreach ( is_array( $list ) ? $list : array() as $callback ) {
				if ( ! is_array( $callback ) || ! isset( $callback['function'] ) ) {
					continue;
				}
				list( $file, $name ) = self::callback_location( $callback['function'] );
				if ( '' !== $file && 0 === strpos( wp_normalize_path( $file ), self::self_dir() ) ) {
					continue;
				}
				$out[] = array(
					'component' => '' === $file ? 'unknown' : self::component( $file ),
					'callback'  => substr( $name, 0, 200 ),
					'file'      => '' === $file ? '' : self::relative_path( $file ),
				);
				if ( count( $out ) >= 3 ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * @param mixed $callback
	 * @return array{0:string,1:string} File (empty if unknown) and readable callback name.
	 */
	public static function callback_location( $callback ): array {
		try {
			if ( $callback instanceof \Closure ) {
				$ref = new \ReflectionFunction( $callback );
				return array( (string) $ref->getFileName(), '{closure}' );
			}
			if ( is_string( $callback ) && false === strpos( $callback, '::' ) && function_exists( $callback ) ) {
				$ref = new \ReflectionFunction( $callback );
				return array( (string) $ref->getFileName(), $callback );
			}
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$callback               = array( $class, $method );
			}
			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$callback = array( $callback, '__invoke' );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) ) {
				if ( ! is_object( $callback[0] ) && ! class_exists( $callback[0] ) ) {
					return array( '', '' );
				}
				$ref = new \ReflectionMethod( $callback[0], $callback[1] );
				return array( (string) $ref->getFileName(), $ref->getDeclaringClass()->getName() . '::' . $ref->getName() );
			}
		} catch ( \ReflectionException $e ) {
			return array( '', '' );
		}
		return array( '', '' );
	}

	/** "plugin:woocommerce", "mu-plugin:x", "theme:astra", "core" or "unknown". */
	public static function component( string $file ): string {
		$file  = wp_normalize_path( $file );
		$roots = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);
		foreach ( $roots as $type => $root ) {
			if ( 0 === strpos( $file, $root ) ) {
				$slug = strtok( substr( $file, strlen( $root ) ), '/' );
				return substr( $type . ':' . preg_replace( '/\.php$/', '', (string) $slug ), 0, 100 );
			}
		}
		$abspath = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, $abspath . 'wp-includes/' ) || 0 === strpos( $file, $abspath . 'wp-admin/' ) ) {
			return 'core';
		}
		return 'unknown';
	}

	/** Path relative to the WordPress root (no absolute server paths in the log). */
	public static function relative_path( string $file ): string {
		$file = wp_normalize_path( $file );
		$abs  = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, $abs ) ) {
			return substr( $file, strlen( $abs ) );
		}
		$content = wp_normalize_path( WP_CONTENT_DIR ) . '/';
		if ( 0 === strpos( $file, $content ) ) {
			return 'wp-content/' . substr( $file, strlen( $content ) );
		}
		return '…/' . wp_basename( $file );
	}

	private static function self_dir(): string {
		if ( null === self::$self_dir ) {
			self::$self_dir = wp_normalize_path( dirname( \Mailspur\FILE ) ) . '/';
		}
		return self::$self_dir;
	}
}
