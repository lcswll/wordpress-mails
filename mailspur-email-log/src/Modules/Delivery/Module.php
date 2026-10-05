<?php
/**
 * Delivery module: sender DNS check (SPF/DKIM/DMARC/MX), staging mode (hold or redirect all mails), the emergency
 * brake for mail floods, problem recipients, the delivery status reported by the email provider (webhooks) and
 * "send to another address" / "send now" actions in the log dialog.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Admin;
use Mailspur\Modules\Insights\Stats;
use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		( new Staging() )->register();
		( new Brake() )->register();
		( new Problems() )->register(); // After the brake: staging mode and the brake decide first.
		( new Feedback() )->register();

		add_filter( 'mailspur_settings_defaults', array( $this, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( $this, 'sanitize' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $this, 'render_settings' ), 10, 2 );
		add_action( 'mailspur_settings_after', array( $this, 'render_sender_check' ) );
		add_action( 'mailspur_admin_enqueue', array( $this, 'enqueue' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_notices', array( $this, 'brake_notice' ), 5 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action(
			'rest_api_init',
			function (): void {
				( new Controller( $this->repository ) )->register_routes();
			}
		);
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'mailspur brake', new BrakeCli( new Brake() ) );
		}
	}

	/**
	 * @param array<string,string|int|bool> $defaults
	 * @return array<string,string|int|bool>
	 */
	public function defaults( $defaults ) {
		$defaults                         = (array) $defaults;
		$defaults['staging_mode']         = Staging::OFF;
		$defaults['staging_redirect_to']  = '';
		$defaults['brake_mode']           = Brake::ALERT;
		$defaults['brake_threshold']      = 0;
		$defaults['problem_hold']         = false;
		$defaults['feedback_provider']    = '';
		$defaults['feedback_signing_key'] = '';
		return $defaults;
	}

	/**
	 * @param array<string,string|int|bool> $clean
	 * @param array<string,mixed>           $input
	 * @return array<string,string|int|bool>
	 */
	public function sanitize( $clean, $input ) {
		$clean = (array) $clean;
		$input = (array) $input;
		$mode  = isset( $input['staging_mode'] ) ? sanitize_key( (string) $input['staging_mode'] ) : Staging::OFF;

		$clean['staging_mode']        = in_array( $mode, Staging::MODES, true ) ? $mode : Staging::OFF;
		$clean['staging_redirect_to'] = implode( ', ', Staging::parse_addresses( isset( $input['staging_redirect_to'] ) && is_string( $input['staging_redirect_to'] ) ? $input['staging_redirect_to'] : '' ) );

		$brake                    = isset( $input['brake_mode'] ) ? sanitize_key( (string) $input['brake_mode'] ) : Brake::ALERT;
		$clean['brake_mode']      = in_array( $brake, Brake::MODES, true ) ? $brake : Brake::ALERT;
		$threshold                = isset( $input['brake_threshold'] ) && is_scalar( $input['brake_threshold'] ) ? (int) $input['brake_threshold'] : 0;
		$clean['brake_threshold'] = max( 0, min( 1000000, $threshold ) );

		$clean['problem_hold']      = ! empty( $input['problem_hold'] );
		$provider                   = isset( $input['feedback_provider'] ) && is_string( $input['feedback_provider'] ) ? sanitize_key( $input['feedback_provider'] ) : '';
		$clean['feedback_provider'] = in_array( $provider, Feedback::PROVIDERS, true ) ? $provider : '';
		// Never shown again after saving: an empty field keeps the saved key.
		$key                           = isset( $input['feedback_signing_key'] ) && is_string( $input['feedback_signing_key'] ) ? (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['feedback_signing_key'] ) : '';
		$clean['feedback_signing_key'] = '' !== $key ? substr( $key, 0, 200 ) : (string) Settings::get( 'feedback_signing_key' );
		return $clean;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public function render_settings( $settings, string $name ): void {
		$mode   = in_array( $settings['staging_mode'] ?? '', Staging::MODES, true ) ? (string) $settings['staging_mode'] : Staging::OFF;
		$labels = array(
			Staging::OFF      => __( 'Off – deliver emails normally', 'mailspur-email-log' ),
			Staging::HOLD     => __( 'Log only – do not send any email', 'mailspur-email-log' ),
			Staging::REDIRECT => __( 'Redirect all emails to the addresses below', 'mailspur-email-log' ),
		);
		?>
		<h2 id="mailspur-staging"><?php esc_html_e( 'Staging mode', 'mailspur-email-log' ); ?></h2>
		<p class="description"><?php esc_html_e( 'For staging and development copies of a site: make sure customers never get emails from the copy. Every email is still logged.', 'mailspur-email-log' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Staging mode', 'mailspur-email-log' ); ?></th>
				<td>
					<fieldset class="mailspur-staging-modes">
						<?php foreach ( $labels as $value => $label ) : ?>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[staging_mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Held emails get the status "Held" and can still be sent one by one from the log ("Send now").', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mailspur-staging-redirect"><?php esc_html_e( 'Redirect to', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="mailspur-staging-redirect" name="<?php echo esc_attr( $name ); ?>[staging_redirect_to]" value="<?php echo esc_attr( (string) ( $settings['staging_redirect_to'] ?? '' ) ); ?>" placeholder="dev@example.com" autocomplete="off">
					<p class="description"><?php esc_html_e( 'Up to 10 addresses, separated by commas. Cc and Bcc are removed and the subject shows the original recipients. Without a valid address, emails are held instead.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		$this->render_brake_settings( $settings, $name );
		$this->render_problem_settings( $settings, $name );
		$this->render_feedback_settings( $settings, $name );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_problem_settings( $settings, string $name ): void {
		$problems = Problems::problems();
		?>
		<h2 id="mailspur-problems"><?php esc_html_e( 'Problem recipients', 'mailspur-email-log' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: number of failures */
				esc_html__( 'Addresses with at least %s hard failures: the receiving server rejected the mailbox, the domain has no mail server, or your email provider reported a hard bounce. Entries expire with the retention period of the log.', 'mailspur-email-log' ),
				esc_html( number_format_i18n( Problems::THRESHOLD ) )
			);
			?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Problem recipients', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[problem_hold]" value="1" <?php checked( ! empty( $settings['problem_hold'] ) ); ?>> <?php esc_html_e( 'Hold further emails to problem recipients', 'mailspur-email-log' ); ?></label>
					<p class="description"><?php esc_html_e( 'Only emails whose recipients are all problem recipients. Password reset emails always go out; held emails can still be sent from the log ("Send now").', 'mailspur-email-log' ); ?></p>
					<?php if ( ! $problems ) : ?>
						<p class="mailspur-problems-empty"><?php esc_html_e( 'No problem recipients.', 'mailspur-email-log' ); ?></p>
					<?php else : ?>
						<table class="widefat striped mailspur-problems" id="mailspur-problems-list">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Recipient', 'mailspur-email-log' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Hard failures', 'mailspur-email-log' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Last failure', 'mailspur-email-log' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Reason', 'mailspur-email-log' ); ?></th>
									<td></td>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( $problems, 0, 100 ) as $problem ) : ?>
									<tr>
										<td><?php echo esc_html( $problem['email'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $problem['count'] ) ); ?></td>
										<td><?php echo esc_html( (string) wp_date( (string) get_option( 'date_format' ), $problem['last'] ) ); ?></td>
										<td><?php echo esc_html( Problems::reason_label( $problem['why'] ) ); ?></td>
										<td><button type="button" class="button button-small" data-mailspur-allow="<?php echo esc_attr( $problem['email'] ); ?>"><?php esc_html_e( 'Allow again', 'mailspur-email-log' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_feedback_settings( $settings, string $name ): void {
		$provider = in_array( $settings['feedback_provider'] ?? '', Feedback::PROVIDERS, true ) ? (string) $settings['feedback_provider'] : '';
		$labels   = array_merge( array( '' => __( 'Off', 'mailspur-email-log' ) ), self::provider_labels() );
		$hints    = array(
			'postmark' => __( 'In Postmark, open your server → Webhooks → Add webhook, paste the URL and select Delivery, Bounce and Spam complaint.', 'mailspur-email-log' ),
			'mailgun'  => __( 'In Mailgun, open Sending → Webhooks, add the URL for Delivered, Permanent failure, Temporary failure and Spam complaints, and paste the HTTP webhook signing key below.', 'mailspur-email-log' ),
			'brevo'    => __( 'In Brevo, open Transactional → Settings → Webhook, add the URL and select Delivered, Hard bounce, Soft bounce, Invalid email and Complaint.', 'mailspur-email-log' ),
			'ses'      => __( 'In Amazon SNS, subscribe the URL (protocol HTTPS) to the topic that receives the Delivery, Bounce and Complaint notifications of Amazon SES. Mailspur confirms the subscription automatically.', 'mailspur-email-log' ),
		);
		$urls     = array();
		foreach ( Feedback::PROVIDERS as $key ) {
			$urls[ $key ] = Feedback::url( $key );
		}
		$has_key = '' !== (string) ( $settings['feedback_signing_key'] ?? '' );
		?>
		<h2 id="mailspur-feedback"><?php esc_html_e( 'Delivery status from your email provider', 'mailspur-email-log' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Postmark, Mailgun, Brevo or Amazon SES can report to this site whether an email was delivered, bounced or marked as spam. The status is shown on the logged email, and hard bounces count for the problem recipients. To match the reports, Mailspur adds a reference header to outgoing emails.', 'mailspur-email-log' ); ?></p>
		<table class="form-table mailspur-feedback" role="presentation" id="mailspur-feedback-settings">
			<tr>
				<th scope="row"><label for="mailspur-feedback-provider"><?php esc_html_e( 'Email provider', 'mailspur-email-log' ); ?></label></th>
				<td>
					<select id="mailspur-feedback-provider" name="<?php echo esc_attr( $name ); ?>[feedback_provider]" data-urls="<?php echo esc_attr( (string) wp_json_encode( $urls ) ); ?>">
						<?php foreach ( $labels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $provider, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr data-feedback-row="url" <?php echo '' === $provider ? 'hidden' : ''; ?>>
				<th scope="row"><label for="mailspur-feedback-url"><?php esc_html_e( 'Webhook URL', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="text" readonly class="large-text code" id="mailspur-feedback-url" value="<?php echo esc_attr( '' !== $provider ? $urls[ $provider ] : '' ); ?>">
					<button type="button" class="button" id="mailspur-feedback-copy"><?php esc_html_e( 'Copy', 'mailspur-email-log' ); ?></button>
					<?php foreach ( $hints as $key => $hint ) : ?>
						<p class="description" data-feedback-hint="<?php echo esc_attr( $key ); ?>" <?php echo $key !== $provider ? 'hidden' : ''; ?>><?php echo esc_html( $hint ); ?></p>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Keep this URL private: it contains a secret key of this site. Save the settings before you test the webhook.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr data-feedback-row="mailgun" <?php echo 'mailgun' !== $provider ? 'hidden' : ''; ?>>
				<th scope="row"><label for="mailspur-feedback-key"><?php esc_html_e( 'Webhook signing key', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="mailspur-feedback-key" name="<?php echo esc_attr( $name ); ?>[feedback_signing_key]" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $has_key ? __( 'Saved – leave empty to keep it', 'mailspur-email-log' ) : '' ); ?>">
					<p class="description"><?php esc_html_e( 'Mailgun signs every webhook with this key (Sending → Webhooks → HTTP webhook signing key); reports without a valid signature are rejected.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	public static function provider_labels(): array {
		return array(
			'postmark' => 'Postmark',
			'mailgun'  => 'Mailgun',
			'brevo'    => 'Brevo',
			'ses'      => __( 'Amazon SES (via SNS)', 'mailspur-email-log' ),
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_brake_settings( $settings, string $name ): void {
		$mode      = in_array( $settings['brake_mode'] ?? '', Brake::MODES, true ) ? (string) $settings['brake_mode'] : Brake::ALERT;
		$threshold = (int) ( $settings['brake_threshold'] ?? 0 );
		$labels    = array(
			Brake::OFF   => __( 'Off', 'mailspur-email-log' ),
			Brake::ALERT => __( 'Alert only', 'mailspur-email-log' ),
			Brake::HOLD  => __( 'Alert and hold further emails', 'mailspur-email-log' ),
		);
		$auto      = 0;
		try {
			$auto = Brake::automatic( ( new Brake() )->baseline() );
		} catch ( \Throwable $e ) {
			$auto = Brake::FLOOR;
		}
		?>
		<h2 id="mailspur-brake"><?php esc_html_e( 'Emergency brake', 'mailspur-email-log' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Protects your domain when far more emails leave than usual, e.g. because spam bots abuse a contact form. Alerts use the channels of the monitoring alerts; password reset emails are never held.', 'mailspur-email-log' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Emergency brake', 'mailspur-email-log' ); ?></th>
				<td>
					<fieldset class="mailspur-brake-modes">
						<?php foreach ( $labels as $value => $label ) : ?>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[brake_mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset>
					<p>
						<label for="mailspur-brake-threshold"><?php esc_html_e( 'Threshold (emails per hour)', 'mailspur-email-log' ); ?></label>
						<input type="number" class="small-text" id="mailspur-brake-threshold" min="0" max="1000000" name="<?php echo esc_attr( $name ); ?>[brake_threshold]" value="<?php echo esc_attr( $threshold > 0 ? (string) $threshold : '' ); ?>" placeholder="<?php echo esc_attr( (string) $auto ); ?>">
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: number of emails per hour */
							esc_html__( 'Empty = automatic: three times the busiest hour of the last 14 days, at least 50 – currently %s.', 'mailspur-email-log' ),
							esc_html( number_format_i18n( $auto ) )
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function render_sender_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<h2 id="mailspur-sender-check"><?php esc_html_e( 'Sender check (SPF, DKIM, DMARC)', 'mailspur-email-log' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Checks the DNS records of the domains your site sends from: the default sender address and the most frequent senders in the log. Missing or weak records are the most common reason why emails land in spam. Only your server\'s own DNS resolver is used; results are kept for one hour.', 'mailspur-email-log' ); ?></p>
		<div class="mailspur-delivery-check" id="mailspur-delivery-check">
			<p class="mailspur-delivery-controls">
				<label for="mailspur-dkim-selector"><?php esc_html_e( 'Your DKIM selector (optional)', 'mailspur-email-log' ); ?></label>
				<input type="text" id="mailspur-dkim-selector" class="regular-text mailspur-narrow" maxlength="63" pattern="[A-Za-z0-9._\-]*" autocomplete="off" spellcheck="false">
				<button type="button" class="button" id="mailspur-delivery-run"><?php esc_html_e( 'Check sender domains', 'mailspur-email-log' ); ?></button>
				<span class="mailspur-delivery-state" id="mailspur-delivery-state" aria-live="polite"></span>
			</p>
			<div id="mailspur-delivery-results"></div>
		</div>
		<?php
	}

	public function enqueue( string $tab, string $base ): void {
		$deps = 'log' === $tab ? array( 'mailspur-email-log-admin' ) : array();
		wp_enqueue_style( 'mailspur-delivery', $base . 'delivery.css', array( 'mailspur-email-log-admin' ), \Mailspur\VERSION );
		wp_enqueue_script(
			'mailspur-delivery',
			$base . 'delivery.js',
			$deps,
			\Mailspur\VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'restUrl'   => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'isAdmin'   => current_user_can( 'manage_options' ),
			'tab'       => $tab,
			'i18n'      => array(
				'sendTo'          => __( 'Send to…', 'mailspur-email-log' ),
				'sendToPrompt'    => __( 'Send this email to (up to 10 addresses, separated by commas):', 'mailspur-email-log' ),
				'invalidAddress'  => __( 'Please enter valid email addresses.', 'mailspur-email-log' ),
				/* translators: %s: recipient email address(es) */
				'sentTo'          => __( 'Email sent to %s.', 'mailspur-email-log' ),
				'sendFailed'      => __( 'Sending failed – see the new log entry for details.', 'mailspur-email-log' ),
				'release'         => __( 'Send now', 'mailspur-email-log' ),
				/* translators: %s: recipient email address(es) */
				'confirmRelease'  => __( 'Send this held email now to %s? Staging mode is bypassed for this one email.', 'mailspur-email-log' ),
				'released'        => __( 'Held email sent. It is logged as a new entry.', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'requestFailed'   => __( 'Request failed: %s', 'mailspur-email-log' ),
				'originalTo'      => __( 'Original recipients', 'mailspur-email-log' ),
				'originalCc'      => __( 'Original Cc', 'mailspur-email-log' ),
				'originalBcc'     => __( 'Original Bcc', 'mailspur-email-log' ),
				'staging'         => __( 'Staging', 'mailspur-email-log' ),
				'heldStaging'     => __( 'Held by staging mode – not delivered.', 'mailspur-email-log' ),
				'heldNoAddress'   => __( 'Held: staging mode is set to redirect, but no valid redirect address is configured.', 'mailspur-email-log' ),
				'redirected'      => __( 'Redirected by staging mode.', 'mailspur-email-log' ),
				/* translators: %s: log entry number */
				'releasedFrom'    => __( 'Released from staging (held entry #%s).', 'mailspur-email-log' ),
				'check'           => __( 'Check sender domains', 'mailspur-email-log' ),
				'checkAgain'      => __( 'Check again', 'mailspur-email-log' ),
				'checking'        => __( 'Checking DNS records …', 'mailspur-email-log' ),
				/* translators: %s: date and time */
				'checkedAt'       => __( 'Checked %s.', 'mailspur-email-log' ),
				'cachedNote'      => __( 'Result from the cache – use "Check again" after changing DNS records.', 'mailspur-email-log' ),
				'unavailable'     => __( 'DNS lookups are not available on this server (dns_get_record() is disabled), so the sender check cannot run here.', 'mailspur-email-log' ),
				'timedOut'        => __( 'Some lookups were skipped because DNS answered too slowly.', 'mailspur-email-log' ),
				'noDomains'       => __( 'No public sender domain found (the site address is not a domain name and the log has no senders yet).', 'mailspur-email-log' ),
				'defaultSender'   => __( 'Default sender', 'mailspur-email-log' ),
				/* translators: %s: number of log entries */
				'seenInLog'       => __( 'Sender of %s of the last 200 emails', 'mailspur-email-log' ),
				'record'          => __( 'Current record', 'mailspur-email-log' ),
				/* translators: %s: DNS host name, e.g. _dmarc.example.com */
				'suggestion'      => __( 'Suggested TXT record for %s', 'mailspur-email-log' ),
				'statusOk'        => __( 'OK', 'mailspur-email-log' ),
				'statusWarn'      => __( 'Needs attention', 'mailspur-email-log' ),
				'statusBad'       => __( 'Problem', 'mailspur-email-log' ),
				'statusUnknown'   => __( 'Unknown', 'mailspur-email-log' ),
				'invalidSelector' => __( 'A DKIM selector may only contain letters, digits, dots, hyphens and underscores.', 'mailspur-email-log' ),
				'brake'           => __( 'Emergency brake', 'mailspur-email-log' ),
				'heldBrake'       => __( 'Held by emergency brake – not delivered.', 'mailspur-email-log' ),
				'brakeReleased'   => __( 'Held by emergency brake – released later.', 'mailspur-email-log' ),
				'brakeDiscarded'  => __( 'Held by emergency brake – discarded.', 'mailspur-email-log' ),
				/* translators: %s: log entry number */
				'releasedBrake'   => __( 'Released from the emergency brake (held entry #%s).', 'mailspur-email-log' ),
				/* translators: %s: number of emails */
				'confirmBrake'    => __( 'Send all %s held emails to their original recipients now?', 'mailspur-email-log' ),
				'confirmDiscard'  => __( 'Discard all held emails? They stay in the log but are not sent.', 'mailspur-email-log' ),
				/* translators: 1: number of emails sent, 2: number of emails still held */
				'brakeProgress'   => __( '%1$s sent, %2$s still held …', 'mailspur-email-log' ),
				/* translators: 1: number of emails sent, 2: number of failed emails */
				'brakeDone'       => __( 'Done: %1$s emails sent, %2$s failed.', 'mailspur-email-log' ),
				/* translators: %s: number of emails */
				'discarded'       => __( '%s held emails discarded.', 'mailspur-email-log' ),
				'brakeReset'      => __( 'Emergency brake reset.', 'mailspur-email-log' ),
				'problems'        => __( 'Problem recipients', 'mailspur-email-log' ),
				'heldProblem'     => __( 'Held: every recipient failed hard before (problem recipients).', 'mailspur-email-log' ),
				/* translators: %s: email address(es) */
				'problemNote'     => __( 'Earlier emails to %s failed hard more than once.', 'mailspur-email-log' ),
				'allowed'         => __( 'Allowed again.', 'mailspur-email-log' ),
				'providerStatus'  => __( 'Provider status', 'mailspur-email-log' ),
				'delivered'       => __( 'Delivered', 'mailspur-email-log' ),
				'bouncedHard'     => __( 'Bounced (permanent)', 'mailspur-email-log' ),
				'bouncedSoft'     => __( 'Bounced (temporary)', 'mailspur-email-log' ),
				'complaint'       => __( 'Marked as spam by the recipient', 'mailspur-email-log' ),
				/* translators: 1: delivery status, 2: email provider, 3: date and time */
				'reportedBy'      => __( '%1$s – reported by %2$s, %3$s', 'mailspur-email-log' ),
				'copied'          => __( 'Copied', 'mailspur-email-log' ),
			),
			'providers' => self::provider_labels(),
		);
		wp_add_inline_script( 'mailspur-delivery', 'window.mailspurDelivery = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/** Notices on the Mail Log screen only: staging mode active, or a suggestion to enable it. */
	public function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only detects the current admin screen.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( Admin::SLUG !== $page || ! Settings::current_user_can_view() ) {
			return;
		}

		$settings = Admin::url( array( 'tab' => 'settings' ) ) . '#mailspur-staging';
		$mode     = Staging::mode();
		if ( Staging::OFF !== $mode ) {
			if ( Staging::REDIRECT === Staging::effective_mode() ) {
				$text = sprintf(
					/* translators: %s: email address(es) */
					__( 'Staging mode is active: all emails are redirected to %s.', 'mailspur-email-log' ),
					implode( ', ', Staging::addresses() )
				);
			} elseif ( Staging::REDIRECT === $mode ) {
				$text = __( 'Staging mode is active: emails should be redirected, but no valid redirect address is set – all emails are held and not delivered.', 'mailspur-email-log' );
			} else {
				$text = __( 'Staging mode is active: emails are logged but not delivered.', 'mailspur-email-log' );
			}
			?>
			<div class="notice notice-warning mailspur-staging-notice">
				<p>
					<strong><?php echo esc_html( $text ); ?></strong>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Change staging mode', 'mailspur-email-log' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<?php
			return;
		}

		if ( ! self::suggest_staging() ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible mailspur-staging-hint" data-mailspur-hint="1">
			<p>
				<?php
				printf(
					/* translators: %s: environment type, e.g. "staging" or "development" */
					esc_html__( 'This site runs as a %s environment. Turn on staging mode so that no real customer gets an email from it.', 'mailspur-email-log' ),
					'<code>' . esc_html( self::environment() ) . '</code>'
				);
				?>
				<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Set up staging mode', 'mailspur-email-log' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** Emergency brake on the Mail Log screen only: incident with count, main source and the release/discard buttons. */
	public function brake_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only detects the current admin screen.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( Admin::SLUG !== $page || ! Settings::current_user_can_view() ) {
			return;
		}
		$state = Brake::state();
		if ( empty( $state['active'] ) && empty( $state['holding'] ) ) {
			return;
		}
		try {
			$status = ( new Brake() )->status();
		} catch ( \Throwable $e ) {
			return;
		}
		if ( ! $status['active'] && ! $status['held'] ) {
			return;
		}

		$lines = array();
		if ( $status['active'] ) {
			$lines[] = sprintf(
				/* translators: 1: number of emails, 2: number of emails per hour */
				__( 'Emergency brake: %1$s emails in the last hour – far more than usual (threshold: %2$s per hour).', 'mailspur-email-log' ),
				number_format_i18n( (int) $status['count'] ),
				number_format_i18n( (int) $status['threshold'] )
			);
			$lines[] = Brake::HOLD === $status['mode']
				? __( 'New emails are held until you release or discard them. Password reset emails still go out.', 'mailspur-email-log' )
				: __( 'Emails are still being sent. Check the main source; switch the emergency brake to "Alert and hold further emails" to stop them.', 'mailspur-email-log' );
		}
		if ( $status['held'] ) {
			/* translators: %s: number of emails */
			$lines[] = sprintf( __( '%s emails are held by the emergency brake.', 'mailspur-email-log' ), number_format_i18n( (int) $status['held'] ) );
		}

		$details = array();
		if ( ! empty( $status['sources'][0] ) ) {
			$top = $status['sources'][0];
			/* translators: 1: plugin or theme name, 2: number of emails */
			$details[] = sprintf( __( 'Main source: %1$s (%2$s emails).', 'mailspur-email-log' ), Stats::source_label( (string) $top['value'] ), number_format_i18n( (int) $top['count'] ) );
		}
		$recipients = array();
		foreach ( (array) $status['recipients'] as $item ) {
			$recipients[] = sprintf( '%s (%s)', $item['value'], number_format_i18n( (int) $item['count'] ) );
		}
		if ( $recipients ) {
			/* translators: %s: list of recipients with counts */
			$details[] = sprintf( __( 'Top recipients: %s', 'mailspur-email-log' ), implode( ', ', $recipients ) );
		}
		?>
		<div class="notice notice-error mailspur-brake-notice" id="mailspur-brake-notice">
			<p><strong><?php echo esc_html( implode( ' ', $lines ) ); ?></strong></p>
			<?php if ( $details ) : ?>
				<p class="mailspur-brake-details"><?php echo esc_html( implode( ' ', $details ) ); ?></p>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<p class="mailspur-brake-actions">
					<?php if ( $status['held'] ) : ?>
						<button type="button" class="button button-primary" data-mailspur-brake="release" data-held="<?php echo esc_attr( (string) $status['held'] ); ?>"><?php esc_html_e( 'Release held emails', 'mailspur-email-log' ); ?></button>
						<button type="button" class="button" data-mailspur-brake="discard"><?php esc_html_e( 'Discard held emails', 'mailspur-email-log' ); ?></button>
						<a href="<?php echo esc_url( Admin::url( array( 'status' => 'held' ) ) ); ?>"><?php esc_html_e( 'Review held emails', 'mailspur-email-log' ); ?></a>
					<?php else : ?>
						<button type="button" class="button" data-mailspur-brake="reset"><?php esc_html_e( 'Mark as resolved', 'mailspur-email-log' ); ?></button>
					<?php endif; ?>
					<span class="mailspur-brake-progress" aria-live="polite"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Non-production site, staging mode off, suggestion not dismissed, user may change settings. */
	public static function suggest_staging(): bool {
		return 'production' !== self::environment()
			&& ! Staging::active()
			&& current_user_can( 'manage_options' )
			&& ! get_option( Controller::HINT_OPTION );
	}

	private static function environment(): string {
		/**
		 * Environment type used for the staging mode suggestion (default: wp_get_environment_type()).
		 *
		 * @param string $type production, staging, development or local.
		 */
		return (string) apply_filters( 'mailspur_environment_type', wp_get_environment_type() );
	}

	/** Warning in the admin bar while staging mode is active or the emergency brake holds emails (administrators only). */
	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Brake::holding() ) {
			$bar->add_node(
				array(
					'id'     => 'mailspur-brake',
					'parent' => 'top-secondary',
					'title'  => esc_html__( 'Mailspur: emails held', 'mailspur-email-log' ),
					'href'   => Admin::url(),
					'meta'   => array( 'title' => __( 'The emergency brake holds outgoing emails until you release or discard them.', 'mailspur-email-log' ) ),
				)
			);
		}
		if ( ! Staging::active() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'mailspur-staging',
				'parent' => 'top-secondary',
				'title'  => esc_html__( 'Mailspur: staging mode', 'mailspur-email-log' ),
				'href'   => Admin::url( array( 'tab' => 'settings' ) ) . '#mailspur-staging',
				'meta'   => array(
					'title' => Staging::REDIRECT === Staging::effective_mode()
						? __( 'All emails are redirected to test addresses.', 'mailspur-email-log' )
						: __( 'Emails are logged but not delivered.', 'mailspur-email-log' ),
				),
			)
		);
	}

	public function admin_bar_style(): void {
		if ( ( Staging::active() || Brake::holding() ) && is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-mailspur-staging > .ab-item,#wpadminbar #wp-admin-bar-mailspur-brake > .ab-item{background:#b32d2e;color:#fff}' );
		}
	}
}
