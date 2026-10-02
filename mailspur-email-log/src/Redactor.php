<?php
/**
 * Masks one-time secrets in links (password reset / activation keys, order keys) so the log
 * cannot be used to take over accounts. Used for logged and for imported mails.
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	public static function redact( string $text ): string {
		if ( '' === $text || ! Settings::get( 'redact_secrets' ) ) {
			return $text;
		}
		$params = (array) apply_filters( 'mailspur_redact_params', array( 'key', 'token', 'reset_key', 'activation_key', 'login_token', 'password', 'pass', 'pwd' ) );
		$params = implode(
			'|',
			array_map(
				static function ( $param ): string {
					return preg_quote( (string) $param, '/' );
				},
				$params
			)
		);
		$result = preg_replace( '/([?&](?:amp;)?(?:' . $params . ')=)[^&\s"\'<>]+/i', '$1[redacted]', $text );
		return null === $result ? $text : $result;
	}
}
