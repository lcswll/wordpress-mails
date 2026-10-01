<?php
/**
 * Admin screen: menu, assets, log shell and settings form.
 *
 * The log itself is rendered client-side from the REST API; mail data is only
 * ever inserted into the DOM via textContent or a sandboxed iframe.
 *
 * @package OutboxMailLog
 */

namespace OutboxMailLog;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'outbox-mail-log';

	/** @var Repository */
	private $repository;

	/** @var string */
	private $hook = '';

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		$cap   = current_user_can( Settings::capability() ) ? Settings::capability() : 'manage_options';
		$title = __( 'Mail Log', 'outbox-mail-log' );

		if ( 'tools' === Settings::get( 'menu_location' ) ) {
			$this->hook = (string) add_management_page( $title, $title, $cap, self::SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = (string) add_menu_page( $title, $title, $cap, self::SLUG, array( $this, 'render' ), 'dashicons-email-alt', 81 );
		}
	}

	/**
	 * @param array<string,string> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		$base = 'tools' === Settings::get( 'menu_location' ) ? 'tools.php' : 'admin.php';
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( $base ) );
	}

	public function register_settings(): void {
		register_setting(
			'outbox_mail_log',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param array<int|string,string> $links
	 * @return array<int|string,string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Log', 'outbox-mail-log' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'tab' => 'settings' ) ) ), esc_html__( 'Settings', 'outbox-mail-log' ) )
		);
		return $links;
	}

	public function assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		$base = plugin_dir_url( FILE ) . 'assets/';
		wp_enqueue_style( 'outbox-mail-log-admin', $base . 'admin.css', array(), VERSION );

		if ( 'settings' === $this->current_tab() ) {
			return;
		}

		wp_enqueue_script(
			'outbox-mail-log-admin',
			$base . 'admin.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'restUrl'      => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'remoteImages' => (bool) Settings::get( 'remote_images' ),
			'canPurge'     => current_user_can( 'manage_options' ),
			'i18n'         => array(
				'sent'          => __( 'Sent', 'outbox-mail-log' ),
				'failed'        => __( 'Failed', 'outbox-mail-log' ),
				'pending'       => __( 'Unknown', 'outbox-mail-log' ),
				'empty'         => __( 'No emails found.', 'outbox-mail-log' ),
				'emptyFiltered' => __( 'No emails match these filters.', 'outbox-mail-log' ),
				/* translators: %s: number of log entries */
				'entries'       => __( '%s entries', 'outbox-mail-log' ),
				/* translators: %s: number of selected entries */
				'selected'      => __( '%s selected', 'outbox-mail-log' ),
				'noSubject'     => __( '(no subject)', 'outbox-mail-log' ),
				'core'          => __( 'WordPress', 'outbox-mail-log' ),
				'resent'        => __( 'Resent from log', 'outbox-mail-log' ),
				'view'          => __( 'View', 'outbox-mail-log' ),
				'resend'        => __( 'Resend', 'outbox-mail-log' ),
				'delete'        => __( 'Delete', 'outbox-mail-log' ),
				'confirmDelete' => __( 'Delete this log entry?', 'outbox-mail-log' ),
				/* translators: %s: number of selected entries */
				'confirmBulk'   => __( 'Delete %s selected log entries?', 'outbox-mail-log' ),
				'purge'         => __( 'Empty log', 'outbox-mail-log' ),
				'confirmPurge'  => __( 'Delete ALL log entries? This cannot be undone.', 'outbox-mail-log' ),
				/* translators: %s: recipient email address(es) */
				'confirmResend' => __( 'Send this email again to %s?', 'outbox-mail-log' ),
				'resendOk'      => __( 'Email sent again.', 'outbox-mail-log' ),
				'resendFail'    => __( 'Sending failed – see the new log entry for details.', 'outbox-mail-log' ),
				/* translators: %s: comma-separated attachment file names */
				'missingFiles'  => __( 'Attachments no longer available: %s', 'outbox-mail-log' ),
				'deleted'       => __( 'Deleted.', 'outbox-mail-log' ),
				/* translators: %s: error message */
				'requestFailed' => __( 'Request failed: %s', 'outbox-mail-log' ),
				'attachments'   => __( 'Attachments', 'outbox-mail-log' ),
				'from'          => __( 'From', 'outbox-mail-log' ),
				'to'            => __( 'To', 'outbox-mail-log' ),
				'date'          => __( 'Date', 'outbox-mail-log' ),
				'status'        => __( 'Status', 'outbox-mail-log' ),
				'source'        => __( 'Source', 'outbox-mail-log' ),
				'contentType'   => __( 'Format', 'outbox-mail-log' ),
				'error'         => __( 'Error', 'outbox-mail-log' ),
				'remoteBlocked' => __( 'Remote images and fonts are blocked so senders cannot track when you open this entry.', 'outbox-mail-log' ),
				'remoteLoaded'  => __( 'Remote content is loaded.', 'outbox-mail-log' ),
				'loadRemote'    => __( 'Load remote content', 'outbox-mail-log' ),
				'blockRemote'   => __( 'Block again', 'outbox-mail-log' ),
				/* translators: %s: total number of pages */
				'pageOf'        => __( 'of %s', 'outbox-mail-log' ),
			),
		);

		wp_add_inline_script( 'outbox-mail-log-admin', 'window.outboxMailLogConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return ( 'settings' === $tab && current_user_can( 'manage_options' ) ) ? 'settings' : 'log';
	}

	public function render(): void {
		if ( ! Settings::current_user_can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'outbox-mail-log' ), 403 );
		}
		$tab = $this->current_tab();
		?>
		<div class="wrap outbox">
			<header class="outbox-header">
				<h1 class="outbox-title"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> <?php esc_html_e( 'Mail Log', 'outbox-mail-log' ); ?></h1>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<nav class="outbox-nav" aria-label="<?php esc_attr_e( 'Mail Log sections', 'outbox-mail-log' ); ?>">
						<a href="<?php echo esc_url( self::url() ); ?>" class="<?php echo 'log' === $tab ? 'is-active' : ''; ?>" <?php echo 'log' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Log', 'outbox-mail-log' ); ?></a>
						<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>" class="<?php echo 'settings' === $tab ? 'is-active' : ''; ?>" <?php echo 'settings' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'outbox-mail-log' ); ?></a>
					</nav>
				<?php endif; ?>
			</header>
			<hr class="wp-header-end">
			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_log();
			}
			?>
		</div>
		<?php
	}

	private function render_log(): void {
		?>
		<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The mail log requires JavaScript.', 'outbox-mail-log' ); ?></p></div></noscript>

		<div id="outbox-app" class="outbox-app" aria-busy="true">
			<div class="outbox-toolbar">
				<div class="outbox-status" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'outbox-mail-log' ); ?>">
					<?php
					$statuses = array(
						'all'     => __( 'All', 'outbox-mail-log' ),
						'sent'    => __( 'Sent', 'outbox-mail-log' ),
						'failed'  => __( 'Failed', 'outbox-mail-log' ),
						'pending' => __( 'Unknown', 'outbox-mail-log' ),
					);
					foreach ( $statuses as $key => $label ) :
						?>
						<button type="button" class="outbox-chip is-<?php echo esc_attr( $key ); ?>" data-status="<?php echo esc_attr( $key ); ?>" aria-pressed="false">
							<?php echo esc_html( $label ); ?> <span class="outbox-count" data-count="<?php echo esc_attr( $key ); ?>"></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="outbox-filters">
					<div class="outbox-search">
						<label class="screen-reader-text" for="outbox-search"><?php esc_html_e( 'Search recipient or subject', 'outbox-mail-log' ); ?></label>
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input type="search" id="outbox-search" maxlength="200" autocomplete="off" placeholder="<?php esc_attr_e( 'Search recipient or subject …', 'outbox-mail-log' ); ?>">
						<kbd aria-hidden="true">/</kbd>
					</div>
					<label class="outbox-check"><input type="checkbox" id="outbox-in-body"> <?php esc_html_e( 'Also search content', 'outbox-mail-log' ); ?></label>
					<span class="outbox-dates">
						<label class="screen-reader-text" for="outbox-after"><?php esc_html_e( 'From date', 'outbox-mail-log' ); ?></label>
						<input type="date" id="outbox-after">
						<span aria-hidden="true">–</span>
						<label class="screen-reader-text" for="outbox-before"><?php esc_html_e( 'To date', 'outbox-mail-log' ); ?></label>
						<input type="date" id="outbox-before">
					</span>
					<button type="button" class="button-link" id="outbox-reset" hidden><?php esc_html_e( 'Reset filters', 'outbox-mail-log' ); ?></button>
				</div>
			</div>

			<div class="outbox-bulk" id="outbox-bulk" hidden>
				<span id="outbox-selected"></span>
				<button type="button" class="button" id="outbox-bulk-delete"><?php esc_html_e( 'Delete selected', 'outbox-mail-log' ); ?></button>
				<button type="button" class="button-link" id="outbox-bulk-clear"><?php esc_html_e( 'Clear selection', 'outbox-mail-log' ); ?></button>
			</div>

			<div class="outbox-table-wrap">
				<table class="outbox-table">
					<thead>
						<tr>
							<td class="col-check"><label class="screen-reader-text" for="outbox-select-all"><?php esc_html_e( 'Select all', 'outbox-mail-log' ); ?></label><input type="checkbox" id="outbox-select-all"></td>
							<th scope="col" class="col-date" aria-sort="descending"><button type="button" data-sort="date"><?php esc_html_e( 'Date', 'outbox-mail-log' ); ?></button></th>
							<th scope="col" class="col-status"><?php esc_html_e( 'Status', 'outbox-mail-log' ); ?></th>
							<th scope="col" class="col-to" aria-sort="none"><button type="button" data-sort="to"><?php esc_html_e( 'Recipient', 'outbox-mail-log' ); ?></button></th>
							<th scope="col" class="col-subject" aria-sort="none"><button type="button" data-sort="subject"><?php esc_html_e( 'Subject', 'outbox-mail-log' ); ?></button></th>
							<th scope="col" class="col-source"><?php esc_html_e( 'Source', 'outbox-mail-log' ); ?></th>
							<th scope="col" class="col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'outbox-mail-log' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="outbox-rows"></tbody>
				</table>
			</div>

			<div class="outbox-footer">
				<span id="outbox-summary" aria-live="polite"></span>
				<label class="outbox-per-page">
					<?php esc_html_e( 'Per page', 'outbox-mail-log' ); ?>
					<select id="outbox-per-page">
						<option>25</option>
						<option>50</option>
						<option>100</option>
						<option>200</option>
					</select>
				</label>
				<nav class="outbox-pager" aria-label="<?php esc_attr_e( 'Pagination', 'outbox-mail-log' ); ?>">
					<button type="button" class="button" data-page="first" aria-label="<?php esc_attr_e( 'First page', 'outbox-mail-log' ); ?>">«</button>
					<button type="button" class="button" data-page="prev" aria-label="<?php esc_attr_e( 'Previous page', 'outbox-mail-log' ); ?>">‹</button>
					<label class="screen-reader-text" for="outbox-page"><?php esc_html_e( 'Current page', 'outbox-mail-log' ); ?></label>
					<input type="number" id="outbox-page" min="1" value="1" inputmode="numeric">
					<span id="outbox-pages"></span>
					<button type="button" class="button" data-page="next" aria-label="<?php esc_attr_e( 'Next page', 'outbox-mail-log' ); ?>">›</button>
					<button type="button" class="button" data-page="last" aria-label="<?php esc_attr_e( 'Last page', 'outbox-mail-log' ); ?>">»</button>
				</nav>
			</div>
		</div>

		<dialog id="outbox-dialog" class="outbox-dialog" aria-labelledby="outbox-d-subject">
			<div class="outbox-d-head">
				<div class="outbox-d-title">
					<span class="outbox-badge" id="outbox-d-status"></span>
					<h2 id="outbox-d-subject"></h2>
				</div>
				<div class="outbox-d-actions">
					<button type="button" class="button outbox-icon" data-action="prev" aria-label="<?php esc_attr_e( 'Previous email', 'outbox-mail-log' ); ?>" title="<?php esc_attr_e( 'Previous email (k)', 'outbox-mail-log' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
					<button type="button" class="button outbox-icon" data-action="next" aria-label="<?php esc_attr_e( 'Next email', 'outbox-mail-log' ); ?>" title="<?php esc_attr_e( 'Next email (j)', 'outbox-mail-log' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
					<button type="button" class="button" data-action="resend"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span> <?php esc_html_e( 'Resend', 'outbox-mail-log' ); ?></button>
					<button type="button" class="button outbox-danger" data-action="delete"><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Delete', 'outbox-mail-log' ); ?></button>
					<button type="button" class="button outbox-icon" data-action="close" aria-label="<?php esc_attr_e( 'Close', 'outbox-mail-log' ); ?>" title="<?php esc_attr_e( 'Close (Esc)', 'outbox-mail-log' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
				</div>
			</div>
			<dl class="outbox-d-meta" id="outbox-d-meta"></dl>
			<div class="outbox-d-tabs" role="tablist">
				<button type="button" role="tab" id="outbox-tab-preview" data-view="preview" aria-selected="true" aria-controls="outbox-d-body"><?php esc_html_e( 'Preview', 'outbox-mail-log' ); ?></button>
				<button type="button" role="tab" id="outbox-tab-source" data-view="source" aria-selected="false" aria-controls="outbox-d-body"><?php esc_html_e( 'Source', 'outbox-mail-log' ); ?></button>
				<button type="button" role="tab" id="outbox-tab-headers" data-view="headers" aria-selected="false" aria-controls="outbox-d-body"><?php esc_html_e( 'Headers', 'outbox-mail-log' ); ?></button>
				<span class="outbox-d-remote" id="outbox-d-remote" hidden>
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<span id="outbox-d-remote-text"></span>
					<button type="button" class="button-link" id="outbox-d-remote-toggle"></button>
				</span>
			</div>
			<div class="outbox-d-body" id="outbox-d-body" role="tabpanel"></div>
		</dialog>

		<div class="outbox-toast" id="outbox-toast" role="status" aria-live="polite"></div>
		<?php
	}

	private function render_settings(): void {
		$s     = Settings::all();
		$name  = Settings::OPTION;
		$stats = $this->repository->stats();
		$caps  = array(
			'manage_options'    => __( 'Administrators', 'outbox-mail-log' ),
			'edit_others_posts' => __( 'Editors and above', 'outbox-mail-log' ),
		);
		if ( class_exists( 'WooCommerce' ) || 'manage_woocommerce' === $s['capability'] ) {
			$caps['manage_woocommerce'] = __( 'Shop managers and above', 'outbox-mail-log' );
		}

		settings_errors();
		?>
		<form method="post" action="options.php" class="outbox-settings">
			<?php settings_fields( 'outbox_mail_log' ); ?>

			<h2><?php esc_html_e( 'Access', 'outbox-mail-log' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="outbox-capability"><?php esc_html_e( 'Who can view the log', 'outbox-mail-log' ); ?></label></th>
					<td>
						<select id="outbox-capability" name="<?php echo esc_attr( $name ); ?>[capability]">
							<?php foreach ( $caps as $cap => $label ) : ?>
								<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $s['capability'], $cap ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Logged emails can contain personal data. Grant access as narrowly as possible. Settings always require administrator rights.', 'outbox-mail-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Menu position', 'outbox-mail-log' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[menu_location]" value="top" <?php checked( $s['menu_location'], 'top' ); ?>> <?php esc_html_e( 'Own top-level menu item', 'outbox-mail-log' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[menu_location]" value="tools" <?php checked( $s['menu_location'], 'tools' ); ?>> <?php esc_html_e( 'Under Tools', 'outbox-mail-log' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Privacy & security', 'outbox-mail-log' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Redact secrets', 'outbox-mail-log' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[redact_secrets]" value="1" <?php checked( $s['redact_secrets'] ); ?>> <?php esc_html_e( 'Mask password-reset, activation and access keys in logged links', 'outbox-mail-log' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended. Otherwise anyone with log access could use a logged reset link to take over an account. Resending such an email sends the masked version.', 'outbox-mail-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Remote content', 'outbox-mail-log' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remote_images]" value="1" <?php checked( $s['remote_images'] ); ?>> <?php esc_html_e( 'Always load remote images and fonts in the preview', 'outbox-mail-log' ); ?></label>
						<p class="description"><?php esc_html_e( 'When off, tracking pixels cannot tell when you open an entry. You can still load remote content for a single email. Scripts and forms are always blocked.', 'outbox-mail-log' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Retention', 'outbox-mail-log' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="outbox-retention"><?php esc_html_e( 'Delete entries after', 'outbox-mail-log' ); ?></label></th>
					<td>
						<input type="number" class="small-text" id="outbox-retention" min="0" max="3650" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>"> <?php esc_html_e( 'days', 'outbox-mail-log' ); ?>
						<p class="description"><?php esc_html_e( '0 keeps entries forever. Cleanup runs once a day.', 'outbox-mail-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="outbox-max"><?php esc_html_e( 'Keep at most', 'outbox-mail-log' ); ?></label></th>
					<td>
						<input type="number" class="regular-text outbox-narrow" id="outbox-max" min="0" name="<?php echo esc_attr( $name ); ?>[max_entries]" value="<?php echo esc_attr( (string) $s['max_entries'] ); ?>"> <?php esc_html_e( 'entries', 'outbox-mail-log' ); ?>
						<p class="description"><?php esc_html_e( '0 means no limit. The oldest entries are removed first.', 'outbox-mail-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'outbox-mail-log' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete the log table and settings when the plugin is deleted', 'outbox-mail-log' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Current size', 'outbox-mail-log' ); ?></th>
					<td>
						<?php
						if ( $stats['bytes'] > 0 ) {
							printf(
								/* translators: 1: number of entries, 2: size, e.g. "4 MB" */
								esc_html__( '%1$s entries, %2$s', 'outbox-mail-log' ),
								esc_html( number_format_i18n( $stats['rows'] ) ),
								esc_html( (string) size_format( $stats['bytes'], 1 ) )
							);
						} else {
							/* translators: %s: number of entries */
							printf( esc_html__( '%s entries', 'outbox-mail-log' ), esc_html( number_format_i18n( $stats['rows'] ) ) );
						}
						?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}
}
