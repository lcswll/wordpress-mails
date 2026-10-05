<?php
/**
 * Admin noise: email types that frequently go to the site's administrators ("New comment awaiting moderation",
 * "Some plugins were automatically updated", "New order" on a busy shop) and where they can be switched off.
 *
 * While indexing, every email whose recipients include the admin email or an administrator's address adds one
 * to a per-type day counter – the addresses themselves are only compared in memory, never stored.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Repository;

defined( 'ABSPATH' ) || exit;

final class Noise {

	/** Emails to administrators within 30 days from which a type counts as noise (more than one a day). */
	const THRESHOLD = 30;

	/** Day counters kept in the type state (the report looks at 30 days). */
	const KEEP_DAYS = 31;

	/** Administrators looked up for the comparison. */
	const MAX_ADMINS = 50;

	/**
	 * Lower-cased addresses that reach the site's administrators: the admin email and every administrator.
	 *
	 * @return string[]
	 */
	public static function addresses(): array {
		$out   = array( strtolower( (string) get_option( 'admin_email' ) ) );
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => self::MAX_ADMINS,
				'fields' => 'user_email',
			)
		);
		foreach ( $users as $email ) {
			$out[] = is_string( $email ) ? strtolower( $email ) : '';
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Whether one of the recipients is an administrator.
	 *
	 * @param string[] $admins Lower-cased addresses (addresses()).
	 */
	public static function to_admin( string $recipients, array $admins ): bool {
		return array() !== $admins && '' !== $recipients && array() !== array_intersect( Repository::extract_emails( $recipients ), $admins );
	}

	/**
	 * Counts one email to an administrator on a (site-local) day; drops counters older than KEEP_DAYS.
	 *
	 * @param array<string,mixed> $extra Type state.
	 * @return array<string,mixed>
	 */
	public static function observe( array $extra, string $day ): array {
		$days         = isset( $extra['adm'] ) && is_array( $extra['adm'] ) ? $extra['adm'] : array();
		$days[ $day ] = (int) ( $days[ $day ] ?? 0 ) + 1;
		$cutoff       = gmdate( 'Y-m-d', (int) strtotime( max( array_keys( $days ) ) . ' 00:00:00 UTC' ) - self::KEEP_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $days ) as $key ) {
			if ( (string) $key < $cutoff ) {
				unset( $days[ $key ] );
			}
		}
		ksort( $days );
		$extra['adm'] = $days;
		return $extra;
	}

	/**
	 * Emails to administrators since a day (inclusive).
	 *
	 * @param array<string,mixed> $extra Type state.
	 */
	public static function count( array $extra, string $from ): int {
		$sum = 0;
		foreach ( isset( $extra['adm'] ) && is_array( $extra['adm'] ) ? $extra['adm'] : array() as $day => $n ) {
			if ( (string) $day >= $from ) {
				$sum += (int) $n;
			}
		}
		return $sum;
	}

	/**
	 * Where a noisy type can be switched off: a settings screen, a Mailspur switch (core emails without a
	 * setting) or null when Mailspur does not know the email.
	 *
	 * @param string $origin Function that called wp_mail() (trace), e.g. "wp_notify_moderator".
	 * @return array{label:string,url:string,hint:string}|array{quiet:string}|null
	 */
	public static function fix( string $origin, string $source ): ?array {
		switch ( $origin ) {
			case 'wp_notify_moderator':
				return array(
					'label' => __( 'Settings › Discussion', 'mailspur-email-log' ),
					'url'   => admin_url( 'options-discussion.php' ),
					'hint'  => __( 'Email me whenever a comment is held for moderation', 'mailspur-email-log' ),
				);
			case 'wp_notify_postauthor':
				return array(
					'label' => __( 'Settings › Discussion', 'mailspur-email-log' ),
					'url'   => admin_url( 'options-discussion.php' ),
					'hint'  => __( 'Email me whenever anyone posts a comment', 'mailspur-email-log' ),
				);
			case 'WP_Automatic_Updater::send_plugin_theme_email':
			case 'WP_Automatic_Updater::send_email':
				return array( 'quiet' => Quiet::UPDATES );
			case 'wp_new_user_notification':
				return array( 'quiet' => Quiet::NEW_USER );
		}
		if ( 'plugin:woocommerce' === $source ) {
			return array(
				'label' => __( 'WooCommerce › Settings › Emails', 'mailspur-email-log' ),
				'url'   => admin_url( 'admin.php?page=wc-settings&tab=email' ),
				'hint'  => __( 'Disable the email or change its recipient', 'mailspur-email-log' ),
			);
		}
		return null;
	}
}
