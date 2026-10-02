<?php
/**
 * Explains error messages of failed mails: PHPMailer messages, SMTP reply codes and typical
 * API mailer errors → title, explanation and concrete steps. Pure string matching, translated at
 * render time.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

defined( 'ABSPATH' ) || exit;

final class Explainer {

	/**
	 * Patterns in priority order (specific provider texts before generic SMTP codes) => key.
	 */
	const PATTERNS = array(
		'/smtpclientauthentication is disabled|5\.7\.139|basic authentication is disabled/i' => 'm365_smtp_auth',
		'/username and password not accepted|application-specific password required|5\.7\.8 .*gsmtp|badcredentials/i' => 'gmail_auth',
		'/extension missing:\s*openssl/i'                  => 'openssl',
		'/could not instantiate mail function|mail\(\) .*(?:failed|disabled)|sendmail.*not found/i' => 'mail_function',
		'/invalid (?:api[ _-]?key|token)|api[ _-]?key (?:is )?(?:invalid|not valid|missing|revoked)|unauthori[sz]ed|\b401\b|forbidden|\b403\b/i' => 'api_key',
		'/domain (?:is )?not verified|not a verified|unverified (?:domain|sender)|sender identity|verify a single sender|does not match a verified|not authorized to send from|from address .*not (?:allowed|verified)/i' => 'api_domain',
		'/quota|rate limit|too many requests|\b429\b|sending limit|limit (?:reached|exceeded)|daily limit|throttl/i' => 'api_quota',
		'/could not authenticate|authentication (?:failed|unsuccessful|credentials invalid)|\b535\b|invalid login|incorrect authentication data/i' => 'smtp_auth',
		'/\b530\b|authentication required|must issue a starttls|starttls (?:required|first)/i' => 'smtp_auth_required',
		'/certificate|ssl routines|ssl3?_|tls handshake|stream_socket_enable_crypto|crypto enabling|peer name|self[- ]signed|unable to get local issuer/i' => 'tls',
		'/timed? ?out|timeout/i'                           => 'timeout',
		'/smtp connect\(\) failed|could not connect to smtp host|failed to connect to server|connection refused|network is unreachable|php_network_getaddresses|no route to host/i' => 'smtp_connect',
		'/invalid address/i'                               => 'invalid_address',
		'/relay(?:ing)? (?:access )?(?:denied|not permitted)|not permitted to relay|unable to relay/i' => 'relay_denied',
		'/sender address rejected|not owned by user|\b553\b|sender not allowed|sendasdenied|send as denied/i' => 'sender_rejected',
		'/spf|dmarc|dkim|5\.7\.(?:23|25|26|27)|unauthenticated email/i' => 'dmarc',
		'/blocked|blacklist|blocklist|spamhaus|listed at|poor reputation|5\.7\.1 .*spam|\b554\b.*spam|spam detected/i' => 'blocked',
		'/message size|too large|size limit|\b552\b/i'     => 'too_large',
		'/\b45[012]\b|greylist|try again later|temporar/i' => 'temporary',
		'/the following recipients failed|recipient address rejected|user unknown|no such user|mailbox (?:unavailable|not found|does not exist)|\b550\b|5\.1\.1/i' => 'recipients_failed',
		'/\b421\b|service not available|too many connections/i' => 'service_unavailable',
		'/data not accepted|\b554\b/i'                     => 'data_not_accepted',
		'/pre_wp_mail returned false/i'                    => 'api_generic',
	);

	/**
	 * Key of the matching explanation, or '' when the error is unknown.
	 */
	public static function match( string $error ): string {
		$error = substr( trim( $error ), 0, 2000 );
		if ( '' === $error ) {
			return '';
		}
		foreach ( self::PATTERNS as $pattern => $key ) {
			if ( preg_match( $pattern, $error ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Explanation in the current language, or null for unknown errors.
	 *
	 * @return array{key:string,title:string,explanation:string,steps:string[]}|null
	 */
	public static function explain( string $error ): ?array {
		$key = self::match( $error );
		if ( '' === $key ) {
			return null;
		}
		$all = self::texts();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		return array( 'key' => $key ) + $all[ $key ];
	}

	/** Short title for the list row, or ''. */
	public static function title( string $error ): string {
		$help = self::explain( $error );
		return $help ? $help['title'] : '';
	}

	/**
	 * @return array<string,array{title:string,explanation:string,steps:string[]}>
	 */
	public static function texts(): array {
		$smtp_settings = __( 'Check host, port, encryption, user name and password in your SMTP plugin and send a test email from there.', 'mailspur-email-log' );
		return array(
			'mail_function'       => array(
				'title'       => __( 'The server cannot send email with PHP mail()', 'mailspur-email-log' ),
				'explanation' => __( 'WordPress sends via the PHP mail() function by default. On this server it is disabled or no mail program (sendmail) is installed – common on local setups, Docker and many managed hosts.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Install an SMTP plugin and send through a real mailbox or a mail service of your domain.', 'mailspur-email-log' ),
					__( 'Alternatively ask your host to enable mail() / sendmail for this site.', 'mailspur-email-log' ),
					__( 'Resend the email from the log once delivery works.', 'mailspur-email-log' ),
				),
			),
			'smtp_connect'        => array(
				'title'       => __( 'No connection to the SMTP server', 'mailspur-email-log' ),
				'explanation' => __( 'The SMTP server could not be reached at all: wrong host name or port, the server is down, or the web host blocks outgoing mail ports.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Check the host name and port: 587 with STARTTLS (TLS) or 465 with SSL. Port 25 is blocked by most hosts.', 'mailspur-email-log' ),
					__( 'Make sure the encryption setting matches the port.', 'mailspur-email-log' ),
					__( 'Ask your host whether outgoing connections to that port are allowed (firewall).', 'mailspur-email-log' ),
				),
			),
			'smtp_auth'           => array(
				'title'       => __( 'SMTP login failed', 'mailspur-email-log' ),
				'explanation' => __( 'The SMTP server rejected the user name or password (reply code 535).', 'mailspur-email-log' ),
				'steps'       => array(
					$smtp_settings,
					__( 'The user name is usually the full email address. Watch for spaces copied along with the password.', 'mailspur-email-log' ),
					__( 'If the account uses two-factor authentication, create an app password and use it instead.', 'mailspur-email-log' ),
				),
			),
			'gmail_auth'          => array(
				'title'       => __( 'Google rejected the login', 'mailspur-email-log' ),
				'explanation' => __( 'Gmail and Google Workspace no longer accept the normal account password for SMTP.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Enable 2-step verification for the Google account and create an app password (Google account → Security → App passwords).', 'mailspur-email-log' ),
					__( 'Enter the app password in your SMTP plugin, or use the plugin\'s "Sign in with Google" (OAuth) connection.', 'mailspur-email-log' ),
				),
			),
			'm365_smtp_auth'      => array(
				'title'       => __( 'SMTP AUTH is disabled in Microsoft 365', 'mailspur-email-log' ),
				'explanation' => __( 'Microsoft 365 / Exchange Online blocks SMTP login (basic authentication) for this mailbox or the whole organization.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'In the Microsoft 365 admin center, open the user → Mail → Manage email apps and enable "Authenticated SMTP".', 'mailspur-email-log' ),
					__( 'If security defaults block it, use your SMTP plugin\'s Microsoft (OAuth / Graph API) connection instead.', 'mailspur-email-log' ),
				),
			),
			'smtp_auth_required'  => array(
				'title'       => __( 'The SMTP server requires a login or encryption', 'mailspur-email-log' ),
				'explanation' => __( 'The server only accepts email after authentication or after switching to an encrypted connection (reply code 530).', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Enable authentication in your SMTP plugin and enter user name and password.', 'mailspur-email-log' ),
					__( 'Use TLS on port 587 or SSL on port 465.', 'mailspur-email-log' ),
				),
			),
			'openssl'             => array(
				'title'       => __( 'PHP extension openssl is missing', 'mailspur-email-log' ),
				'explanation' => __( 'Encrypted SMTP connections (TLS/SSL) need the PHP extension openssl, which is not installed or not enabled on this server.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Ask your host to enable the openssl extension (or enable it in the PHP settings of your hosting panel).', 'mailspur-email-log' ),
				),
			),
			'timeout'             => array(
				'title'       => __( 'The mail server did not answer in time', 'mailspur-email-log' ),
				'explanation' => __( 'The connection or the transfer took too long. Usually a firewall silently drops the connection, or host and port are wrong.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Check host and port; try 587 (TLS) instead of 465 (SSL) or the other way round.', 'mailspur-email-log' ),
					__( 'Ask your host whether outgoing SMTP connections are blocked.', 'mailspur-email-log' ),
					__( 'For large attachments the timeout can also be hit during the transfer – send smaller files or links.', 'mailspur-email-log' ),
				),
			),
			'tls'                 => array(
				'title'       => __( 'Encryption (TLS/SSL) failed', 'mailspur-email-log' ),
				'explanation' => __( 'The secure connection to the SMTP server could not be established: the certificate does not match the host name, has expired, or the server does not support the chosen encryption.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Use exactly the host name the certificate was issued for (e.g. the provider\'s smtp.… host instead of an IP or your own domain).', 'mailspur-email-log' ),
					__( 'Check that the encryption matches the port: TLS for 587, SSL for 465.', 'mailspur-email-log' ),
					__( 'Ask your host to update the CA certificates and OpenSSL of the server.', 'mailspur-email-log' ),
				),
			),
			'invalid_address'     => array(
				'title'       => __( 'Invalid email address', 'mailspur-email-log' ),
				'explanation' => __( 'A recipient or the sender address is not a valid email address, so PHPMailer refused to send.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Check the recipient addresses of this entry for typos, spaces or missing parts.', 'mailspur-email-log' ),
					__( 'Check the sender address in your SMTP plugin and the wp_mail_from filter.', 'mailspur-email-log' ),
				),
			),
			'recipients_failed'   => array(
				'title'       => __( 'The recipient address was rejected', 'mailspur-email-log' ),
				'explanation' => __( 'The receiving server does not know this mailbox (reply code 550 / 5.1.1) – the address is misspelled or no longer exists.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Check the address with the recipient and correct it where it is stored.', 'mailspur-email-log' ),
					__( 'If several recipients failed, the server reply after the address names the reason.', 'mailspur-email-log' ),
				),
			),
			'relay_denied'        => array(
				'title'       => __( 'The SMTP server refuses to relay', 'mailspur-email-log' ),
				'explanation' => __( 'The server does not forward email for you, usually because you are not logged in or the sender address does not belong to the account.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Enable authentication in your SMTP plugin.', 'mailspur-email-log' ),
					__( 'Use the mailbox address of the SMTP account as sender (From).', 'mailspur-email-log' ),
				),
			),
			'sender_rejected'     => array(
				'title'       => __( 'The sender address is not allowed', 'mailspur-email-log' ),
				'explanation' => __( 'The SMTP account may only send with its own address (or verified aliases). WordPress or a plugin used a different From address.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Set the From address in your SMTP plugin to the mailbox you log in with and enable "force From address".', 'mailspur-email-log' ),
					__( 'Or add the address as an alias / "send as" permission at your mail provider.', 'mailspur-email-log' ),
				),
			),
			'dmarc'               => array(
				'title'       => __( 'Rejected by SPF, DKIM or DMARC', 'mailspur-email-log' ),
				'explanation' => __( 'The receiving server could not confirm that your server may send for the sender domain.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Add your mail server or mail service to the SPF record of the sender domain.', 'mailspur-email-log' ),
					__( 'Enable DKIM signing at your mail service and publish its DNS record.', 'mailspur-email-log' ),
					__( 'Do not send "from" free mail addresses (gmail.com, gmx.de …) through your own server.', 'mailspur-email-log' ),
				),
			),
			'blocked'             => array(
				'title'       => __( 'Blocked as spam or by a blocklist', 'mailspur-email-log' ),
				'explanation' => __( 'The receiving server rejected the email because of its content or because the sending server\'s IP address is on a blocklist.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Send through a reputable SMTP service instead of the web server, especially on shared hosting.', 'mailspur-email-log' ),
					__( 'Check the server IP on a blocklist checker and request delisting.', 'mailspur-email-log' ),
					__( 'Review the notes of this email (links, sender, placeholders).', 'mailspur-email-log' ),
				),
			),
			'too_large'           => array(
				'title'       => __( 'The email is too large', 'mailspur-email-log' ),
				'explanation' => __( 'The mail server rejected the size of the email (reply code 552). Attachments grow by about a third when encoded.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Send large files as a download link instead of an attachment.', 'mailspur-email-log' ),
					__( 'Most servers accept 10–25 MB in total.', 'mailspur-email-log' ),
				),
			),
			'temporary'           => array(
				'title'       => __( 'Temporary rejection – try again later', 'mailspur-email-log' ),
				'explanation' => __( 'The receiving server asked to retry later (reply code 450/451/452): greylisting, a full mailbox or too many emails in a short time.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Resend the email from the log in a few minutes.', 'mailspur-email-log' ),
					__( 'If it keeps happening, send fewer emails at once or through a mail service with a queue.', 'mailspur-email-log' ),
				),
			),
			'service_unavailable' => array(
				'title'       => __( 'The mail server is temporarily unavailable', 'mailspur-email-log' ),
				'explanation' => __( 'The server closed the connection (reply code 421), e.g. because of maintenance, overload or too many connections from your server.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Resend the email from the log later.', 'mailspur-email-log' ),
					__( 'If it happens often, check the sending limits of your mail provider.', 'mailspur-email-log' ),
				),
			),
			'data_not_accepted'   => array(
				'title'       => __( 'The server rejected the content', 'mailspur-email-log' ),
				'explanation' => __( 'Login and recipients were accepted, but the email itself was refused – typically spam filtering, a sending limit or a policy of the provider (reply code 554).', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Look for the server reply in the error message; it usually names the reason.', 'mailspur-email-log' ),
					__( 'Check the sending limits of your mail account and the notes of this email.', 'mailspur-email-log' ),
					$smtp_settings,
				),
			),
			'api_key'             => array(
				'title'       => __( 'The API key was rejected', 'mailspur-email-log' ),
				'explanation' => __( 'The mail service refused the request because the API key is wrong, expired, revoked or lacks the permission to send.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Create a new API key with send permission at your mail service and enter it in the mailer plugin.', 'mailspur-email-log' ),
					__( 'Check that the key belongs to the right region / account (e.g. EU vs. US endpoint).', 'mailspur-email-log' ),
				),
			),
			'api_domain'          => array(
				'title'       => __( 'Sender domain or address not verified', 'mailspur-email-log' ),
				'explanation' => __( 'The mail service only sends from domains or addresses you have verified there.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Verify the sender domain at your mail service (add its DNS records) or use an already verified sender address.', 'mailspur-email-log' ),
					__( 'Make sure the From address in the mailer plugin matches the verified domain.', 'mailspur-email-log' ),
				),
			),
			'api_quota'           => array(
				'title'       => __( 'Sending limit or quota reached', 'mailspur-email-log' ),
				'explanation' => __( 'The mail service or mailbox refused further emails because a rate limit, daily quota or plan limit was hit.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Check the limits and current usage in your mail service account; upgrade the plan if needed.', 'mailspur-email-log' ),
					__( 'Look for unexpected mass sending (spam form submissions, loops) in the log.', 'mailspur-email-log' ),
					__( 'Resend the failed emails once the limit resets.', 'mailspur-email-log' ),
				),
			),
			'api_generic'         => array(
				'title'       => __( 'The mailer plugin reported a failure', 'mailspur-email-log' ),
				'explanation' => __( 'A mailer plugin that sends via an API took over delivery and reported that sending failed, without passing on a reason.', 'mailspur-email-log' ),
				'steps'       => array(
					__( 'Open the log or debug view of your mailer plugin for the exact error.', 'mailspur-email-log' ),
					__( 'Send a test email from the mailer plugin\'s settings.', 'mailspur-email-log' ),
				),
			),
		);
	}
}
