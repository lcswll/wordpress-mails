<?php
/**
 * Check & Log Email – table {prefix}check_email_log (fork of Email Log, same layout).
 *
 * Differences to Email Log:
 * - subject is stored esc_html()'d → decoded here.
 * - message is stored wp_kses_post()'d (not byte-identical to what was sent).
 * - attachment_name holds the attachment paths joined with ",".
 *
 * @package Mailspur
 */

namespace Mailspur\Import\Sources;

defined( 'ABSPATH' ) || exit;

final class CheckEmail extends EmailLog {

	public function id(): string {
		return 'check-email';
	}

	public function label(): string {
		return 'Check & Log Email';
	}

	protected function table_suffix(): string {
		return 'check_email_log';
	}

	protected function subject( string $subject ): string {
		return html_entity_decode( $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
