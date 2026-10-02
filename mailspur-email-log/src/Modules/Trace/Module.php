<?php
/**
 * Trace ("Spur"): the technical trail of every mail – timeline, transport, call site, request
 * context, optional SMTP transcript and the raw source as .eml download.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Trace;

use Mailspur\Repository;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/** @var Repository */
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		$collector = new Collector();
		add_filter( 'mailspur_meta', array( $collector, 'meta' ), 10, 3 );
		add_filter( 'mailspur_finalize_row', array( $collector, 'finalize' ) );

		$download = new Download( $this->repository );
		add_action( 'rest_api_init', array( $download, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $download, 'serve' ), 10, 3 );
		add_filter( 'mailspur_rest_item', array( $this, 'rest_item' ), 10, 2 );

		add_filter( 'mailspur_settings_defaults', array( $this, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( $this, 'sanitize' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $this, 'render_settings' ), 10, 2 );
		add_action( 'mailspur_admin_enqueue', array( $this, 'assets' ), 10, 2 );
	}

	/**
	 * @param array<string,mixed> $defaults
	 * @return array<string,mixed>
	 */
	public function defaults( $defaults ) {
		$defaults['trace_transcript'] = Collector::TRANSCRIPT_FAILED;
		$defaults['trace_raw']        = false;
		return $defaults;
	}

	/**
	 * @param array<string,mixed> $clean
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $clean, $input ) {
		$mode                      = isset( $input['trace_transcript'] ) ? sanitize_key( (string) $input['trace_transcript'] ) : Collector::TRANSCRIPT_FAILED;
		$clean['trace_transcript'] = in_array( $mode, array( Collector::TRANSCRIPT_OFF, Collector::TRANSCRIPT_FAILED, Collector::TRANSCRIPT_ALWAYS ), true ) ? $mode : Collector::TRANSCRIPT_FAILED;
		// The unredacted raw source needs the explicit acknowledgement.
		$clean['trace_raw'] = ! empty( $input['trace_raw'] ) && ! empty( $input['trace_raw_ack'] );
		return $clean;
	}

	/**
	 * Detail payload: tells the dialog whether the exact raw source is stored.
	 *
	 * @param array<string,mixed>  $item
	 * @param array<string,string> $row
	 * @return array<string,mixed>
	 */
	public function rest_item( $item, $row ) {
		$item['trace_raw'] = '' !== (string) ( $row['raw'] ?? '' );
		return $item;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public function render_settings( $settings, string $name ): void {
		$mode = Collector::transcript_mode();
		$raw  = ! empty( $settings['trace_raw'] );
		?>
		<h2><?php esc_html_e( 'Trace', 'mailspur-email-log' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'SMTP transcript', 'mailspur-email-log' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[trace_transcript]" value="off" <?php checked( $mode, Collector::TRANSCRIPT_OFF ); ?>> <?php esc_html_e( 'Off', 'mailspur-email-log' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[trace_transcript]" value="failed" <?php checked( $mode, Collector::TRANSCRIPT_FAILED ); ?>> <?php esc_html_e( 'Record for failed emails only', 'mailspur-email-log' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[trace_transcript]" value="always" <?php checked( $mode, Collector::TRANSCRIPT_ALWAYS ); ?>> <?php esc_html_e( 'Record for every email', 'mailspur-email-log' ); ?></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Records the conversation with the SMTP server (only when emails are sent via SMTP). Login data and the message content are never recorded. Skipped while another plugin has SMTP debugging switched on.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Raw source', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" id="mailspur-trace-raw" name="<?php echo esc_attr( $name ); ?>[trace_raw]" value="1" <?php checked( $raw ); ?>> <?php esc_html_e( 'Store the exact raw MIME source of every email', 'mailspur-email-log' ); ?></label><br>
					<label><input type="checkbox" id="mailspur-trace-raw-ack" name="<?php echo esc_attr( $name ); ?>[trace_raw_ack]" value="1" <?php checked( $raw ); ?>> <?php esc_html_e( 'I understand that the raw source is NOT redacted and roughly doubles the storage per email', 'mailspur-email-log' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off by default. Without it, "Download .eml" rebuilds the message from the logged (redacted) data. The raw source contains password-reset links and other secrets exactly as sent – only enable it when you need it and both boxes are checked.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function assets( string $tab, string $base ): void {
		if ( 'log' !== $tab ) {
			return;
		}
		wp_enqueue_style( 'mailspur-trace', $base . 'trace.css', array( 'mailspur-email-log-admin' ), \Mailspur\VERSION );
		wp_enqueue_script(
			'mailspur-trace',
			$base . 'trace.js',
			array( 'mailspur-email-log-admin' ),
			\Mailspur\VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'transcript' => Collector::transcript_mode(),
			'i18n'       => array(
				'tab'              => __( 'Trace', 'mailspur-email-log' ),
				'download'         => __( 'Download .eml', 'mailspur-email-log' ),
				'downloaded'       => __( 'Download started.', 'mailspur-email-log' ),
				'notAvailable'     => __( 'No trace is available for this entry. It was logged before the trace existed or by a version without it.', 'mailspur-email-log' ),
				'notImported'      => __( 'Imported entries have no trace: the other plugin did not record these details.', 'mailspur-email-log' ),
				'timeline'         => __( 'Timeline', 'mailspur-email-log' ),
				'prepare'          => __( 'Preparation', 'mailspur-email-log' ),
				'delivery'         => __( 'Delivery', 'mailspur-email-log' ),
				/* translators: %s: duration in milliseconds */
				'total'            => __( 'Total: %s ms', 'mailspur-email-log' ),
				'phaseCapture'     => __( 'wp_mail() called', 'mailspur-email-log' ),
				'phasePhpmailer'   => __( 'PHPMailer ready (phpmailer_init)', 'mailspur-email-log' ),
				'phaseResult'      => __( 'Result', 'mailspur-email-log' ),
				'transport'        => __( 'Transport', 'mailspur-email-log' ),
				'mailer'           => __( 'Mailer', 'mailspur-email-log' ),
				'host'             => __( 'SMTP host', 'mailspur-email-log' ),
				'port'             => __( 'Port', 'mailspur-email-log' ),
				'encryption'       => __( 'Encryption', 'mailspur-email-log' ),
				'none'             => __( 'none', 'mailspur-email-log' ),
				'authentication'   => __( 'Authentication', 'mailspur-email-log' ),
				'username'         => __( 'Username', 'mailspur-email-log' ),
				'autoTls'          => __( 'Automatic TLS', 'mailspur-email-log' ),
				'yes'              => __( 'yes', 'mailspur-email-log' ),
				'no'               => __( 'no', 'mailspur-email-log' ),
				/* translators: %s: plugin, theme or function name */
				'deliveredBy'      => __( 'Delivered by %s (API)', 'mailspur-email-log' ),
				'deliveredUnknown' => __( 'Delivered by code hooked into pre_wp_mail (API)', 'mailspur-email-log' ),
				'handler'          => __( 'Handler', 'mailspur-email-log' ),
				'viaUnknown'       => __( 'Unknown: no delivery result was reported (replaced wp_mail() function?).', 'mailspur-email-log' ),
				'origin'           => __( 'Origin', 'mailspur-email-log' ),
				'file'             => __( 'File', 'mailspur-email-log' ),
				'function'         => __( 'Function', 'mailspur-email-log' ),
				'component'        => __( 'Component', 'mailspur-email-log' ),
				'hooks'            => __( 'Hooks', 'mailspur-email-log' ),
				'request'          => __( 'Request', 'mailspur-email-log' ),
				'type'             => __( 'Type', 'mailspur-email-log' ),
				'method'           => __( 'Method', 'mailspur-email-log' ),
				'path'             => __( 'Path', 'mailspur-email-log' ),
				'route'            => __( 'REST route', 'mailspur-email-log' ),
				'user'             => __( 'User', 'mailspur-email-log' ),
				'types'            => array(
					'cron'     => __( 'WP-Cron', 'mailspur-email-log' ),
					'rest'     => __( 'REST API', 'mailspur-email-log' ),
					'ajax'     => __( 'Ajax', 'mailspur-email-log' ),
					'cli'      => __( 'Command line', 'mailspur-email-log' ),
					'admin'    => __( 'Admin screen', 'mailspur-email-log' ),
					'frontend' => __( 'Front end', 'mailspur-email-log' ),
					'xmlrpc'   => __( 'XML-RPC', 'mailspur-email-log' ),
				),
				'transcript'       => __( 'SMTP transcript', 'mailspur-email-log' ),
				'copy'             => __( 'Copy', 'mailspur-email-log' ),
				'copied'           => __( 'Transcript copied.', 'mailspur-email-log' ),
				'transcriptStates' => array(
					'off'          => __( 'Not recorded: the SMTP transcript is switched off in the settings.', 'mailspur-email-log' ),
					'not_smtp'     => __( 'Not recorded: this email was not sent via SMTP.', 'mailspur-email-log' ),
					'debug_in_use' => __( 'Not recorded: another plugin had SMTP debugging switched on.', 'mailspur-email-log' ),
					'not_failed'   => __( 'Not stored: transcripts are only kept for failed emails.', 'mailspur-email-log' ),
					'empty'        => __( 'The SMTP server conversation was empty.', 'mailspur-email-log' ),
					'none'         => __( 'No SMTP transcript for this email.', 'mailspur-email-log' ),
				),
				'rawExact'         => __( 'The exact raw source is stored – "Download .eml" returns it unchanged (not redacted).', 'mailspur-email-log' ),
				'rawReconstructed' => __( '"Download .eml" rebuilds the message from the logged, redacted data (without attachments).', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-trace', 'window.mailspurTraceConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
