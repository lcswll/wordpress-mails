<?php
/**
 * Statistics tab, alert settings section and their assets.
 *
 * The charts are drawn client-side (assets/insights.js, inline SVG, no library) from GET /stats.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Insights;

use Mailspur\Admin;
use Mailspur\Rest;
use Mailspur\Settings;
use const Mailspur\VERSION;

defined( 'ABSPATH' ) || exit;

final class Page {

	const TAB = 'stats';

	/**
	 * @param mixed $tabs Tabs.
	 * @return array<string,array{0:string,1:string}>
	 */
	public function tabs( $tabs ): array {
		$tabs              = is_array( $tabs ) ? $tabs : array();
		$tabs[ self::TAB ] = array( __( 'Statistics', 'mailspur-email-log' ), Settings::capability() );
		return $tabs;
	}

	/**
	 * @param string $tab  Current tab.
	 * @param string $base Assets URL.
	 */
	public function enqueue( $tab, $base ): void {
		if ( self::TAB !== $tab && 'settings' !== $tab ) {
			return;
		}
		wp_enqueue_style( 'mailspur-insights', $base . 'insights.css', array(), VERSION );
		wp_enqueue_script(
			'mailspur-insights',
			$base . 'insights.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'mailspur-insights', 'window.mailspurInsights = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function config(): array {
		return array(
			'restUrl'     => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'logUrl'      => esc_url_raw( Admin::url() ),
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			'today'       => (string) wp_date( 'Y-m-d' ),
			'locale'      => str_replace( '_', '-', determine_locale() ),
			'i18n'        => array(
				'sent'          => __( 'Sent', 'mailspur-email-log' ),
				'failed'        => __( 'Failed', 'mailspur-email-log' ),
				'pending'       => __( 'Unknown', 'mailspur-email-log' ),
				'held'          => __( 'Held', 'mailspur-email-log' ),
				'emails'        => __( 'Emails', 'mailspur-email-log' ),
				'failureRate'   => __( 'Failure rate', 'mailspur-email-log' ),
				'average'       => __( 'Average per day', 'mailspur-email-log' ),
				'busiest'       => __( 'Busiest day', 'mailspur-email-log' ),
				'total'         => __( 'Total', 'mailspur-email-log' ),
				'date'          => __( 'Date', 'mailspur-email-log' ),
				'week'          => __( 'Week', 'mailspur-email-log' ),
				/* translators: %s: start date of a calendar week */
				'weekOf'        => __( 'Week of %s', 'mailspur-email-log' ),
				'count'         => __( 'Emails', 'mailspur-email-log' ),
				'weekday'       => __( 'Weekday', 'mailspur-email-log' ),
				'fewer'         => __( 'Fewer', 'mailspur-email-log' ),
				'more'          => __( 'More', 'mailspur-email-log' ),
				'none'          => __( 'None', 'mailspur-email-log' ),
				'noData'        => __( 'No emails in this period.', 'mailspur-email-log' ),
				'noRate'        => __( 'No emails', 'mailspur-email-log' ),
				/* translators: %s: number of failed emails */
				'failedCount'   => __( '%s failed', 'mailspur-email-log' ),
				/* translators: %s: failure rate in percent */
				'rateOf'        => __( '%s of all emails', 'mailspur-email-log' ),
				/* translators: 1: change in percent, e.g. "+12%", 2: start date, 3: end date of the previous period */
				'delta'         => __( '%1$s vs. %2$s – %3$s', 'mailspur-email-log' ),
				/* translators: 1: change in percentage points, e.g. "+1.5", 2: start date, 3: end date of the previous period */
				'deltaPoints'   => __( '%1$s pp vs. %2$s – %3$s', 'mailspur-email-log' ),
				/* translators: 1: start date, 2: end date of the previous period */
				'deltaNew'      => __( 'none in %1$s – %2$s', 'mailspur-email-log' ),
				'deltaSame'     => __( 'unchanged', 'mailspur-email-log' ),
				/* translators: 1: number of emails, 2: number of days */
				'perDay'        => __( '%1$s emails in %2$s days', 'mailspur-email-log' ),
				/* translators: %s: number of emails */
				'sample'        => __( 'Top lists are based on the latest %s emails of this period.', 'mailspur-email-log' ),
				'openLog'       => __( 'Click a bar to open these emails in the log.', 'mailspur-email-log' ),
				'showTable'     => __( 'Show data as table', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'requestFailed' => __( 'Request failed: %s', 'mailspur-email-log' ),
				'weekdays'      => array(
					__( 'Sunday', 'mailspur-email-log' ),
					__( 'Monday', 'mailspur-email-log' ),
					__( 'Tuesday', 'mailspur-email-log' ),
					__( 'Wednesday', 'mailspur-email-log' ),
					__( 'Thursday', 'mailspur-email-log' ),
					__( 'Friday', 'mailspur-email-log' ),
					__( 'Saturday', 'mailspur-email-log' ),
				),
				'testSending'   => __( 'Sending test alert …', 'mailspur-email-log' ),
				'testEmailOk'   => __( 'Email: sent', 'mailspur-email-log' ),
				'testEmailFail' => __( 'Email: failed – see the log', 'mailspur-email-log' ),
				/* translators: %s: HTTP status code or error message */
				'testWebhook'   => __( 'Webhook: %s', 'mailspur-email-log' ),
				'chartLabel'    => __( 'Use the arrow keys to move between values, Enter to open them in the log.', 'mailspur-email-log' ),
				'heatmapLabel'  => __( 'Use the arrow keys to move between cells.', 'mailspur-email-log' ),
				/* translators: 1: weekday, 2: hour, e.g. "Monday, 14:00" */
				'cell'          => __( '%1$s, %2$s', 'mailspur-email-log' ),
				'noSubject'     => __( '(no subject)', 'mailspur-email-log' ),
				/* translators: %s: label of a bar, e.g. a recipient domain */
				'openInLog'     => __( 'Show emails for %s in the log', 'mailspur-email-log' ),
			),
		);
	}

	public function render(): void {
		if ( ! Settings::current_user_can_view() ) {
			return;
		}
		$presets = array(
			'7'   => __( '7 days', 'mailspur-email-log' ),
			'30'  => __( '30 days', 'mailspur-email-log' ),
			'90'  => __( '90 days', 'mailspur-email-log' ),
			'365' => __( '12 months', 'mailspur-email-log' ),
		);
		?>
		<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The statistics require JavaScript.', 'mailspur-email-log' ); ?></p></div></noscript>

		<div id="mailspur-insights" class="mailspur-insights" aria-busy="true">
			<div class="msi-filters" role="group" aria-label="<?php esc_attr_e( 'Period', 'mailspur-email-log' ); ?>">
				<div class="msi-presets">
					<?php foreach ( $presets as $days => $label ) : ?>
						<button type="button" class="msi-preset" data-range="<?php echo esc_attr( (string) $days ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
					<button type="button" class="msi-preset" data-range="custom" aria-pressed="false" aria-controls="msi-custom"><?php esc_html_e( 'Custom', 'mailspur-email-log' ); ?></button>
				</div>
				<form class="msi-custom" id="msi-custom" hidden>
					<label for="msi-from"><?php esc_html_e( 'From', 'mailspur-email-log' ); ?></label>
					<input type="date" id="msi-from" required>
					<label for="msi-to"><?php esc_html_e( 'To', 'mailspur-email-log' ); ?></label>
					<input type="date" id="msi-to" required>
					<button type="submit" class="button"><?php esc_html_e( 'Apply', 'mailspur-email-log' ); ?></button>
				</form>
				<span class="msi-range" id="msi-range" aria-live="polite"></span>
			</div>

			<div class="notice notice-error inline msi-error" id="msi-error" role="alert" hidden><p></p></div>

			<div class="msi-body" id="msi-body">
				<section class="msi-kpis" id="msi-kpis" aria-label="<?php esc_attr_e( 'Key figures', 'mailspur-email-log' ); ?>"></section>

				<section class="msi-card" aria-labelledby="msi-volume-title">
					<header class="msi-card-head">
						<div>
							<h2 id="msi-volume-title"><?php esc_html_e( 'Emails over time', 'mailspur-email-log' ); ?></h2>
							<p class="msi-sub" id="msi-volume-sub"></p>
						</div>
						<ul class="msi-legend" id="msi-volume-legend"></ul>
					</header>
					<div class="msi-chart" id="msi-volume"></div>
					<details class="msi-table"><summary></summary><div></div></details>
				</section>

				<section class="msi-card" aria-labelledby="msi-rate-title">
					<header class="msi-card-head">
						<div>
							<h2 id="msi-rate-title"><?php esc_html_e( 'Failure rate', 'mailspur-email-log' ); ?></h2>
							<p class="msi-sub"><?php esc_html_e( 'Share of failed emails per day (per week for long periods).', 'mailspur-email-log' ); ?></p>
						</div>
					</header>
					<div class="msi-chart msi-chart-small" id="msi-rate"></div>
					<details class="msi-table"><summary></summary><div></div></details>
				</section>

				<section class="msi-card" aria-labelledby="msi-heat-title">
					<header class="msi-card-head">
						<div>
							<h2 id="msi-heat-title"><?php esc_html_e( 'When emails are sent', 'mailspur-email-log' ); ?></h2>
							<p class="msi-sub"><?php esc_html_e( 'Emails per weekday and hour (site time zone).', 'mailspur-email-log' ); ?></p>
						</div>
						<div class="msi-scale" id="msi-heat-scale"></div>
					</header>
					<div class="msi-chart" id="msi-heat"></div>
					<details class="msi-table"><summary></summary><div></div></details>
				</section>

				<div class="msi-grid">
					<section class="msi-card" aria-labelledby="msi-sources-title">
						<h2 id="msi-sources-title"><?php esc_html_e( 'Top sources', 'mailspur-email-log' ); ?></h2>
						<p class="msi-sub"><?php esc_html_e( 'Plugin or theme that sent the email.', 'mailspur-email-log' ); ?></p>
						<ol class="msi-bars" id="msi-sources"></ol>
					</section>
					<section class="msi-card" aria-labelledby="msi-domains-title">
						<h2 id="msi-domains-title"><?php esc_html_e( 'Top recipient domains', 'mailspur-email-log' ); ?></h2>
						<p class="msi-sub"><?php esc_html_e( 'Counted once per email.', 'mailspur-email-log' ); ?></p>
						<ol class="msi-bars" id="msi-domains"></ol>
					</section>
					<section class="msi-card" aria-labelledby="msi-subjects-title">
						<h2 id="msi-subjects-title"><?php esc_html_e( 'Top subjects', 'mailspur-email-log' ); ?></h2>
						<p class="msi-sub"><?php esc_html_e( 'Numbers are ignored, so “Order #123” and “Order #124” count together.', 'mailspur-email-log' ); ?></p>
						<ol class="msi-bars" id="msi-subjects"></ol>
					</section>
				</div>
				<p class="description msi-sample" id="msi-sample" hidden></p>
			</div>

			<?php $this->render_history(); ?>
			<div class="msi-tooltip" id="msi-tooltip" role="presentation" hidden></div>
		</div>
		<?php
	}

	private function render_history(): void {
		$settings = Settings::all();
		$history  = Alerts::history();
		$active   = array();
		if ( Alerts::has_channel( $settings ) ) {
			if ( ! empty( $settings['alert_failures'] ) ) {
				$active[] = __( 'failure spikes', 'mailspur-email-log' );
			}
			if ( ! empty( $settings['alert_silence'] ) ) {
				$active[] = __( 'unusual silence', 'mailspur-email-log' );
			}
			if ( ! empty( $settings['alert_types'] ) ) {
				$active[] = __( 'stopped email types', 'mailspur-email-log' );
			}
		}
		?>
		<section class="msi-card msi-history" aria-labelledby="msi-history-title">
			<h2 id="msi-history-title"><?php esc_html_e( 'Monitoring alerts', 'mailspur-email-log' ); ?></h2>
			<p class="msi-sub">
				<?php
				if ( $active ) {
					/* translators: %s: comma-separated list of alert types */
					printf( esc_html__( 'Active: alerts on %s.', 'mailspur-email-log' ), esc_html( implode( ', ', $active ) ) );
				} else {
					esc_html_e( 'Alerts are off.', 'mailspur-email-log' );
				}
				if ( current_user_can( 'manage_options' ) ) {
					printf( ' <a href="%s">%s</a>', esc_url( Admin::url( array( 'tab' => 'settings' ) ) . '#mailspur-alerts' ), esc_html__( 'Configure alerts', 'mailspur-email-log' ) );
				}
				?>
			</p>
			<?php if ( ! $history ) : ?>
				<p class="msi-empty"><?php esc_html_e( 'No alerts sent yet.', 'mailspur-email-log' ); ?></p>
			<?php else : ?>
				<div class="msi-history-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'mailspur-email-log' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Alert', 'mailspur-email-log' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'mailspur-email-log' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Delivery', 'mailspur-email-log' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
									<td><span class="msi-kind is-<?php echo esc_attr( (string) ( $entry['kind'] ?? '' ) ); ?>"><?php echo esc_html( Alerts::title( (string) ( $entry['type'] ?? '' ), (string) ( $entry['kind'] ?? '' ) ) ); ?></span></td>
									<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( self::delivery( $entry ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed> $entry History entry.
	 */
	public static function delivery( array $entry ): string {
		$parts = array();
		if ( isset( $entry['email'] ) ) {
			$parts[] = $entry['email'] ? __( 'Email: sent', 'mailspur-email-log' ) : __( 'Email: failed', 'mailspur-email-log' );
		}
		if ( isset( $entry['webhook'] ) ) {
			/* translators: %s: HTTP status code or error message */
			$parts[] = sprintf( __( 'Webhook: %s', 'mailspur-email-log' ), is_int( $entry['webhook'] ) ? 'HTTP ' . $entry['webhook'] : (string) $entry['webhook'] );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Alert settings (inside the settings form).
	 *
	 * @param mixed $s    Current settings.
	 * @param mixed $name Option name.
	 */
	public function settings_section( $s, $name ): void {
		$s       = is_array( $s ) ? array_merge( Alerts::defaults(), $s ) : Alerts::defaults();
		$name    = (string) $name;
		$num     = static function ( string $key, int $min, int $max, string $label ) use ( $s, $name ): string {
			return sprintf(
				'<input type="number" class="small-text" id="mailspur-%1$s" name="%2$s[%3$s]" min="%4$d" max="%5$d" value="%6$s" aria-label="%7$s">',
				esc_attr( str_replace( '_', '-', $key ) ),
				esc_attr( $name ),
				esc_attr( $key ),
				$min,
				$max,
				esc_attr( (string) $s[ $key ] ),
				esc_attr( $label )
			);
		};
		$allowed = array(
			'input' => array(
				'type'       => true,
				'class'      => true,
				'id'         => true,
				'name'       => true,
				'min'        => true,
				'max'        => true,
				'value'      => true,
				'aria-label' => true,
			),
		);
		?>
		<h2 id="mailspur-alerts"><?php esc_html_e( 'Monitoring alerts', 'mailspur-email-log' ); ?></h2>
		<p class="description mailspur-alerts-intro">
			<?php esc_html_e( 'Optional and off by default. When enabled, Mailspur checks the log regularly and notifies you about failure spikes, unusual silence or stopped email types. Alerts go only to the addresses and the webhook you enter here; they contain the site name, the alert text (for a stopped email type: its sender and subject pattern) and a link to the log – never email contents or recipients.', 'mailspur-email-log' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Failure spike', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[alert_failures]" value="1" <?php checked( ! empty( $s['alert_failures'] ) ); ?>> <?php esc_html_e( 'Alert when emails fail repeatedly', 'mailspur-email-log' ); ?></label>
					<p class="mailspur-alert-rule">
						<?php
						printf(
							/* translators: 1: number input (emails), 2: number input (minutes) */
							esc_html__( 'At least %1$s failed emails within %2$s minutes', 'mailspur-email-log' ),
							wp_kses( $num( 'alert_failures_count', 1, 1000, __( 'Number of failed emails', 'mailspur-email-log' ) ), $allowed ),
							wp_kses( $num( 'alert_failures_minutes', 1, 1440, __( 'Minutes', 'mailspur-email-log' ) ), $allowed )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Silence', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[alert_silence]" value="1" <?php checked( ! empty( $s['alert_silence'] ) ); ?>> <?php esc_html_e( 'Alert when the site unexpectedly stops sending emails', 'mailspur-email-log' ); ?></label>
					<p class="mailspur-alert-rule">
						<?php
						printf(
							/* translators: %s: number input (hours) */
							esc_html__( 'No email logged for %s hours', 'mailspur-email-log' ),
							wp_kses( $num( 'alert_silence_hours', 1, 168, __( 'Hours', 'mailspur-email-log' ) ), $allowed )
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'Only alerts when the last 14 days show that emails are normally sent in this time window (on at least 10 of 14 days, including the same weekday), so quiet nights or weekends do not trigger it.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mailspur-alert-email"><?php esc_html_e( 'Send alerts by email to', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="mailspur-alert-email" name="<?php echo esc_attr( $name ); ?>[alert_email]" value="<?php echo esc_attr( (string) $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" autocomplete="off">
					<p class="description"><?php esc_html_e( 'Up to 5 addresses, separated by commas. Alert emails appear in the log with the source “Mailspur alerts” and never trigger alerts themselves.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mailspur-alert-webhook"><?php esc_html_e( 'Webhook URL', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="url" class="regular-text code" id="mailspur-alert-webhook" name="<?php echo esc_attr( $name ); ?>[alert_webhook]" value="<?php echo esc_attr( (string) $s['alert_webhook'] ); ?>" placeholder="https://hooks.slack.com/services/…" autocomplete="off">
					<p class="description"><?php esc_html_e( 'Optional, https only. Slack and Discord webhook URLs receive a chat message, any other URL a JSON POST request. The request is made by your server to exactly this URL – no other service is involved.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mailspur-alert-cooldown"><?php esc_html_e( 'Repeat alerts after', 'mailspur-email-log' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="mailspur-alert-cooldown" min="5" max="1440" name="<?php echo esc_attr( $name ); ?>[alert_cooldown]" value="<?php echo esc_attr( (string) $s['alert_cooldown'] ); ?>"> <?php esc_html_e( 'minutes', 'mailspur-email-log' ); ?>
					<p class="description"><?php esc_html_e( 'At most one alert per type within this time, even if the problem persists.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recovery', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[alert_recovery]" value="1" <?php checked( ! empty( $s['alert_recovery'] ) ); ?>> <?php esc_html_e( 'Send a message when the problem is resolved', 'mailspur-email-log' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Test', 'mailspur-email-log' ); ?></th>
				<td>
					<button type="button" class="button" id="mailspur-test-alert"><?php esc_html_e( 'Send test alert', 'mailspur-email-log' ); ?></button>
					<span class="mailspur-test-result" id="mailspur-test-result" role="status" aria-live="polite"></span>
					<p class="description"><?php esc_html_e( 'Uses the saved settings – save your changes first.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
