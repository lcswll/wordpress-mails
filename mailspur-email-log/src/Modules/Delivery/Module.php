<?php
/**
 * Delivery module: sender DNS check (SPF/DKIM/DMARC/MX), staging mode (hold or redirect all mails) and
 * "send to another address" / "send now" actions in the log dialog.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Delivery;

use Mailspur\Admin;
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

		add_filter( 'mailspur_settings_defaults', array( $this, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( $this, 'sanitize' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $this, 'render_settings' ), 10, 2 );
		add_action( 'mailspur_settings_after', array( $this, 'render_sender_check' ) );
		add_action( 'mailspur_admin_enqueue', array( $this, 'enqueue' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action(
			'rest_api_init',
			function (): void {
				( new Controller( $this->repository ) )->register_routes();
			}
		);
	}

	/**
	 * @param array<string,string|int|bool> $defaults
	 * @return array<string,string|int|bool>
	 */
	public function defaults( $defaults ) {
		$defaults                        = (array) $defaults;
		$defaults['staging_mode']        = Staging::OFF;
		$defaults['staging_redirect_to'] = '';
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
			'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'isAdmin' => current_user_can( 'manage_options' ),
			'tab'     => $tab,
			'i18n'    => array(
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
			),
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

	/** Warning in the admin bar while staging mode is active (administrators only). */
	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! Staging::active() || ! current_user_can( 'manage_options' ) ) {
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
		if ( Staging::active() && is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-mailspur-staging > .ab-item{background:#b32d2e;color:#fff}' );
		}
	}
}
