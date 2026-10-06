<?php
/**
 * Admin screen: menu, assets, log shell and settings form.
 *
 * The log itself is rendered client-side from the REST API; mail data is only
 * ever inserted into the DOM via textContent or a sandboxed iframe.
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'mailspur-email-log';

	/** Menu icon: envelope with its trail (monochrome SVG, recoloured by WordPress to match the admin colour scheme). */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTcuNSA0LjVoOS41YTIgMiAwIDAgMSAyIDJ2N2EyIDIgMCAwIDEtMiAySDcuNWEyIDIgMCAwIDEtMi0ydi03YTIgMiAwIDAgMSAyLTJ6TTcuNiA2LjlsNC42NSAzLjUgNC42NS0zLjV2MS42bC00LjY1IDMuNS00LjY1LTMuNXoiLz48Y2lyY2xlIGZpbGw9ImJsYWNrIiBjeD0iMy42IiBjeT0iMTYuNCIgcj0iMS4yNSIvPjxjaXJjbGUgZmlsbD0iYmxhY2siIGN4PSIxLjQiIGN5PSIxOC43IiByPSIwLjkiLz48L3N2Zz4=';

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
		$title = __( 'Mail Log', 'mailspur-email-log' );

		if ( 'tools' === Settings::get( 'menu_location' ) ) {
			$this->hook = (string) add_management_page( $title, $title, $cap, self::SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = (string) add_menu_page( $title, $title, $cap, self::SLUG, array( $this, 'render' ), self::MENU_ICON, 81 );
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
			'mailspur',
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
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Log', 'mailspur-email-log' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'tab' => 'settings' ) ) ), esc_html__( 'Settings', 'mailspur-email-log' ) )
		);
		return $links;
	}

	public function assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		$base = plugin_dir_url( FILE ) . 'assets/';
		$tab  = $this->current_tab();
		wp_enqueue_style( 'mailspur-email-log-admin', $base . 'admin.css', array(), VERSION );

		if ( 'log' === $tab ) {
			$this->log_assets( $base );
		} elseif ( 'settings' === $tab ) {
			$this->import_assets( $base );
		}

		/**
		 * Modules enqueue their own assets here. On the log tab, scripts depending on the
		 * 'mailspur-email-log-admin' handle can use the window.mailspur API (docs/MODULES.md).
		 *
		 * @param string $tab  Current tab key.
		 * @param string $base URL of the plugin's assets folder (with trailing slash).
		 */
		do_action( 'mailspur_admin_enqueue', $tab, $base );
	}

	private function log_assets( string $base ): void {

		wp_enqueue_script(
			'mailspur-email-log-admin',
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
			'importLabels' => array_map(
				static function ( Import\Source $source ): string {
					return $source->label();
				},
				Import\Importer::sources()
			),
			'i18n'         => array(
				'sent'          => __( 'Sent', 'mailspur-email-log' ),
				'failed'        => __( 'Failed', 'mailspur-email-log' ),
				'pending'       => __( 'Unknown', 'mailspur-email-log' ),
				'held'          => __( 'Held', 'mailspur-email-log' ),
				'empty'         => __( 'No emails found.', 'mailspur-email-log' ),
				'emptyFiltered' => __( 'No emails match these filters.', 'mailspur-email-log' ),
				/* translators: %s: number of log entries */
				'entries'       => __( '%s entries', 'mailspur-email-log' ),
				/* translators: %s: number of selected entries */
				'selected'      => __( '%s selected', 'mailspur-email-log' ),
				'noSubject'     => __( '(no subject)', 'mailspur-email-log' ),
				'core'          => __( 'WordPress', 'mailspur-email-log' ),
				'resent'        => __( 'Resent from log', 'mailspur-email-log' ),
				/* translators: %s: name of another plugin, e.g. "WP Mail Logging" */
				'imported'      => __( 'Imported from %s', 'mailspur-email-log' ),
				'view'          => __( 'View', 'mailspur-email-log' ),
				'resend'        => __( 'Resend', 'mailspur-email-log' ),
				'delete'        => __( 'Delete', 'mailspur-email-log' ),
				'confirmDelete' => __( 'Delete this log entry?', 'mailspur-email-log' ),
				/* translators: %s: number of selected entries */
				'confirmBulk'   => __( 'Delete %s selected log entries?', 'mailspur-email-log' ),
				'purge'         => __( 'Empty log', 'mailspur-email-log' ),
				'confirmPurge'  => __( 'Delete ALL log entries? This cannot be undone.', 'mailspur-email-log' ),
				/* translators: %s: recipient email address(es) */
				'confirmResend' => __( 'Send this email again to %s?', 'mailspur-email-log' ),
				'resendOk'      => __( 'Email sent again.', 'mailspur-email-log' ),
				'resendFail'    => __( 'Sending failed – see the new log entry for details.', 'mailspur-email-log' ),
				/* translators: %s: comma-separated attachment file names */
				'missingFiles'  => __( 'Attachments no longer available: %s', 'mailspur-email-log' ),
				'deleted'       => __( 'Deleted.', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'requestFailed' => __( 'Request failed: %s', 'mailspur-email-log' ),
				'attachments'   => __( 'Attachments', 'mailspur-email-log' ),
				'from'          => __( 'From', 'mailspur-email-log' ),
				'to'            => __( 'To', 'mailspur-email-log' ),
				'date'          => __( 'Date', 'mailspur-email-log' ),
				'status'        => __( 'Status', 'mailspur-email-log' ),
				'source'        => __( 'Source', 'mailspur-email-log' ),
				'contentType'   => __( 'Format', 'mailspur-email-log' ),
				'error'         => __( 'Error', 'mailspur-email-log' ),
				'remoteBlocked' => __( 'Remote images and fonts are blocked so senders cannot track when you open this entry.', 'mailspur-email-log' ),
				'remoteLoaded'  => __( 'Remote content is loaded.', 'mailspur-email-log' ),
				'loadRemote'    => __( 'Load remote content', 'mailspur-email-log' ),
				'blockRemote'   => __( 'Block again', 'mailspur-email-log' ),
				'viewAs'        => __( 'Show as', 'mailspur-email-log' ),
				'viewDesktop'   => __( 'Desktop', 'mailspur-email-log' ),
				'viewPhone'     => __( 'Phone', 'mailspur-email-log' ),
				'viewText'      => __( 'Plain text', 'mailspur-email-log' ),
				'scheme'        => __( 'Colour scheme', 'mailspur-email-log' ),
				'schemeLight'   => __( 'Light', 'mailspur-email-log' ),
				'schemeDark'    => __( 'Dark', 'mailspur-email-log' ),
				'schemeForced'  => __( 'Forced dark', 'mailspur-email-log' ),
				'hintPhone'     => __( 'Phone width (375 px). If you can scroll sideways, the email is wider than most phone screens.', 'mailspur-email-log' ),
				'hintDark'      => __( 'Simulated dark mode, using the dark-mode styles of the email itself. Every mail app handles dark mode a little differently.', 'mailspur-email-log' ),
				'hintNoDark'    => __( 'This email has no dark-mode styles of its own. Some apps show it unchanged, others recolour it – see Forced dark.', 'mailspur-email-log' ),
				'hintForced'    => __( 'Simulation of apps that force dark mode by inverting the colours. Images keep their colours; real apps differ in detail.', 'mailspur-email-log' ),
				'hintTextOwn'   => __( 'This email has its own plain-text version, but the log does not store it. Shown here: a text version derived from the HTML.', 'mailspur-email-log' ),
				'hintTextNone'  => __( 'This email has no plain-text version – some clients and spam filters prefer one. Shown here: a text version derived from the HTML.', 'mailspur-email-log' ),
				'hintText'      => __( 'Text version derived from the HTML. Whether the email also had its own plain-text version was not recorded.', 'mailspur-email-log' ),
				'noText'        => __( '(no text content)', 'mailspur-email-log' ),
				/* translators: %s: total number of pages */
				'pageOf'        => __( 'of %s', 'mailspur-email-log' ),
			),
		);

		wp_add_inline_script( 'mailspur-email-log-admin', 'window.mailspurConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Tabs of the admin screen: key => array( label, capability ). Modules add tabs through the
	 * mailspur_admin_tabs filter and render them on the mailspur_render_tab_{key} action.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function tabs(): array {
		$tabs = (array) apply_filters(
			'mailspur_admin_tabs',
			array(
				'log' => array( __( 'Log', 'mailspur-email-log' ), Settings::capability() ),
			)
		);
		// Settings always last and always for administrators only.
		$tabs['settings'] = array( __( 'Settings', 'mailspur-email-log' ), 'manage_options' );

		$out = array();
		foreach ( $tabs as $key => $tab ) {
			if ( is_array( $tab ) && isset( $tab[0], $tab[1] ) && ( current_user_can( (string) $tab[1] ) || current_user_can( 'manage_options' ) ) ) {
				$out[ sanitize_key( (string) $key ) ] = array( (string) $tab[0], (string) $tab[1] );
			}
		}
		return $out;
	}

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return isset( $this->tabs()[ $tab ] ) ? $tab : 'log';
	}

	public function render(): void {
		if ( ! Settings::current_user_can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'mailspur-email-log' ), 403 );
		}
		$tab = $this->current_tab();
		?>
		<div class="wrap mailspur">
			<header class="mailspur-header">
				<h1 class="mailspur-title"><span class="mailspur-logo" aria-hidden="true"></span><span class="mailspur-title-text"><span class="mailspur-brand">Mailspur</span> <?php esc_html_e( 'Mail Log', 'mailspur-email-log' ); ?></span></h1>
				<?php if ( count( $this->tabs() ) > 1 ) : ?>
					<nav class="mailspur-nav" aria-label="<?php esc_attr_e( 'Mail Log sections', 'mailspur-email-log' ); ?>">
						<?php
						foreach ( $this->tabs() as $key => list( $label ) ) :
							$href = 'log' === $key ? self::url() : self::url( array( 'tab' => $key ) );
							?>
							<a href="<?php echo esc_url( $href ); ?>" class="<?php echo esc_attr( $key === $tab ? 'is-active' : '' ); ?>" aria-current="<?php echo esc_attr( $key === $tab ? 'page' : 'false' ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
			</header>
			<hr class="wp-header-end">
			<?php
			/** Above every tab: e.g. the review request (Review). */
			do_action( 'mailspur_admin_top' );

			if ( 'settings' === $tab ) {
				$this->render_settings();
			} elseif ( 'log' === $tab ) {
				$this->render_log();
			} else {
				/** Module tabs render themselves. */
				do_action( 'mailspur_render_tab_' . $tab );
			}
			?>
		</div>
		<?php
	}

	private function render_log(): void {
		/**
		 * Whether to offer the provider status filter before any status arrived (the Delivery module: a provider
		 * is configured). Otherwise it appears once an entry has a status.
		 *
		 * @param bool $show
		 */
		$delivery = (bool) apply_filters( 'mailspur_delivery_filter', false ) || $this->repository->has_delivery();
		?>
		<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The mail log requires JavaScript.', 'mailspur-email-log' ); ?></p></div></noscript>

		<div id="mailspur-app" class="mailspur-app" aria-busy="true">
			<div class="mailspur-toolbar">
				<div class="mailspur-status" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'mailspur-email-log' ); ?>">
					<?php
					$statuses = array(
						'all'     => __( 'All', 'mailspur-email-log' ),
						'sent'    => __( 'Sent', 'mailspur-email-log' ),
						'failed'  => __( 'Failed', 'mailspur-email-log' ),
						'pending' => __( 'Unknown', 'mailspur-email-log' ),
						'held'    => __( 'Held', 'mailspur-email-log' ),
					);
					foreach ( $statuses as $key => $label ) :
						?>
						<button type="button" class="mailspur-chip is-<?php echo esc_attr( $key ); ?>" data-status="<?php echo esc_attr( $key ); ?>" aria-pressed="false">
							<?php echo esc_html( $label ); ?> <span class="mailspur-count" data-count="<?php echo esc_attr( $key ); ?>"></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="mailspur-filters">
					<div class="mailspur-search">
						<label class="screen-reader-text" for="mailspur-search"><?php esc_html_e( 'Search recipient or subject', 'mailspur-email-log' ); ?></label>
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input type="search" id="mailspur-search" maxlength="200" autocomplete="off" placeholder="<?php esc_attr_e( 'Search recipient or subject …', 'mailspur-email-log' ); ?>">
						<kbd aria-hidden="true">/</kbd>
					</div>
					<label class="mailspur-check"><input type="checkbox" id="mailspur-in-body"> <?php esc_html_e( 'Also search content', 'mailspur-email-log' ); ?></label>
					<span class="mailspur-dates">
						<label class="screen-reader-text" for="mailspur-after"><?php esc_html_e( 'From date', 'mailspur-email-log' ); ?></label>
						<input type="date" id="mailspur-after">
						<span aria-hidden="true">–</span>
						<label class="screen-reader-text" for="mailspur-before"><?php esc_html_e( 'To date', 'mailspur-email-log' ); ?></label>
						<input type="date" id="mailspur-before">
					</span>
					<button type="button" class="button mailspur-more-toggle" id="mailspur-more-toggle" aria-expanded="false" aria-controls="mailspur-more">
						<span class="dashicons dashicons-filter" aria-hidden="true"></span>
						<?php esc_html_e( 'More filters', 'mailspur-email-log' ); ?>
						<span class="mailspur-more-count" id="mailspur-more-count"></span>
					</button>
					<button type="button" class="button-link" id="mailspur-reset" hidden><?php esc_html_e( 'Reset filters', 'mailspur-email-log' ); ?></button>
				</div>

				<div class="mailspur-more" id="mailspur-more" role="group" aria-label="<?php esc_attr_e( 'More filters', 'mailspur-email-log' ); ?>" hidden>
					<label class="mailspur-field" id="mailspur-source-field">
						<span><?php esc_html_e( 'Source', 'mailspur-email-log' ); ?></span>
						<select id="mailspur-source">
							<option value=""><?php esc_html_e( 'All sources', 'mailspur-email-log' ); ?></option>
						</select>
					</label>
					<label class="mailspur-field">
						<span><?php esc_html_e( 'Format', 'mailspur-email-log' ); ?></span>
						<select id="mailspur-format">
							<option value=""><?php esc_html_e( 'Any format', 'mailspur-email-log' ); ?></option>
							<option value="html"><?php esc_html_e( 'HTML', 'mailspur-email-log' ); ?></option>
							<option value="text"><?php esc_html_e( 'Plain text', 'mailspur-email-log' ); ?></option>
						</select>
					</label>
					<label class="mailspur-check"><input type="checkbox" id="mailspur-attachments"> <?php esc_html_e( 'With attachments', 'mailspur-email-log' ); ?></label>
					<label class="mailspur-check"><input type="checkbox" id="mailspur-notes"> <?php esc_html_e( 'With notes', 'mailspur-email-log' ); ?></label>
					<label class="mailspur-field" id="mailspur-delivery-field" <?php echo $delivery ? '' : 'hidden'; ?>>
						<span><?php esc_html_e( 'Provider status', 'mailspur-email-log' ); ?></span>
						<select id="mailspur-delivery">
							<option value=""><?php esc_html_e( 'Any status', 'mailspur-email-log' ); ?></option>
							<option value="delivered"><?php esc_html_e( 'Delivered', 'mailspur-email-log' ); ?></option>
							<option value="bounced"><?php esc_html_e( 'Bounced (permanent)', 'mailspur-email-log' ); ?></option>
							<option value="soft_bounce"><?php esc_html_e( 'Bounced (temporary)', 'mailspur-email-log' ); ?></option>
							<option value="complaint"><?php esc_html_e( 'Marked as spam', 'mailspur-email-log' ); ?></option>
						</select>
					</label>
				</div>
			</div>

			<div class="mailspur-bulk" id="mailspur-bulk" hidden>
				<span id="mailspur-selected"></span>
				<button type="button" class="button" id="mailspur-bulk-delete"><?php esc_html_e( 'Delete selected', 'mailspur-email-log' ); ?></button>
				<button type="button" class="button-link" id="mailspur-bulk-clear"><?php esc_html_e( 'Clear selection', 'mailspur-email-log' ); ?></button>
			</div>

			<div class="mailspur-table-wrap">
				<table class="mailspur-table">
					<thead>
						<tr>
							<td class="col-check"><label class="screen-reader-text" for="mailspur-select-all"><?php esc_html_e( 'Select all', 'mailspur-email-log' ); ?></label><input type="checkbox" id="mailspur-select-all"></td>
							<th scope="col" class="col-date" aria-sort="descending"><button type="button" data-sort="date"><?php esc_html_e( 'Date', 'mailspur-email-log' ); ?></button></th>
							<th scope="col" class="col-status"><?php esc_html_e( 'Status', 'mailspur-email-log' ); ?></th>
							<th scope="col" class="col-to" aria-sort="none"><button type="button" data-sort="to"><?php esc_html_e( 'Recipient', 'mailspur-email-log' ); ?></button></th>
							<th scope="col" class="col-subject" aria-sort="none"><button type="button" data-sort="subject"><?php esc_html_e( 'Subject', 'mailspur-email-log' ); ?></button></th>
							<th scope="col" class="col-source"><?php esc_html_e( 'Source', 'mailspur-email-log' ); ?></th>
							<th scope="col" class="col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mailspur-email-log' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="mailspur-rows"></tbody>
				</table>
			</div>

			<div class="mailspur-footer">
				<span id="mailspur-summary" aria-live="polite"></span>
				<label class="mailspur-per-page">
					<?php esc_html_e( 'Per page', 'mailspur-email-log' ); ?>
					<select id="mailspur-per-page">
						<option>25</option>
						<option>50</option>
						<option>100</option>
						<option>200</option>
					</select>
				</label>
				<nav class="mailspur-pager" aria-label="<?php esc_attr_e( 'Pagination', 'mailspur-email-log' ); ?>">
					<button type="button" class="button" data-page="first" aria-label="<?php esc_attr_e( 'First page', 'mailspur-email-log' ); ?>">«</button>
					<button type="button" class="button" data-page="prev" aria-label="<?php esc_attr_e( 'Previous page', 'mailspur-email-log' ); ?>">‹</button>
					<label class="screen-reader-text" for="mailspur-page"><?php esc_html_e( 'Current page', 'mailspur-email-log' ); ?></label>
					<input type="number" id="mailspur-page" min="1" value="1" inputmode="numeric">
					<span id="mailspur-pages"></span>
					<button type="button" class="button" data-page="next" aria-label="<?php esc_attr_e( 'Next page', 'mailspur-email-log' ); ?>">›</button>
					<button type="button" class="button" data-page="last" aria-label="<?php esc_attr_e( 'Last page', 'mailspur-email-log' ); ?>">»</button>
				</nav>
			</div>
		</div>

		<dialog id="mailspur-dialog" class="mailspur-dialog" aria-labelledby="mailspur-d-subject">
			<div class="mailspur-d-head">
				<div class="mailspur-d-title">
					<span class="mailspur-badge" id="mailspur-d-status"></span>
					<h2 id="mailspur-d-subject"></h2>
				</div>
				<div class="mailspur-d-actions">
					<button type="button" class="button mailspur-icon" data-action="prev" aria-label="<?php esc_attr_e( 'Previous email', 'mailspur-email-log' ); ?>" title="<?php esc_attr_e( 'Previous email (k)', 'mailspur-email-log' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
					<button type="button" class="button mailspur-icon" data-action="next" aria-label="<?php esc_attr_e( 'Next email', 'mailspur-email-log' ); ?>" title="<?php esc_attr_e( 'Next email (j)', 'mailspur-email-log' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
					<button type="button" class="button" data-action="resend"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span> <?php esc_html_e( 'Resend', 'mailspur-email-log' ); ?></button>
					<button type="button" class="button mailspur-danger" data-action="delete"><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Delete', 'mailspur-email-log' ); ?></button>
					<button type="button" class="button mailspur-icon" data-action="close" aria-label="<?php esc_attr_e( 'Close', 'mailspur-email-log' ); ?>" title="<?php esc_attr_e( 'Close (Esc)', 'mailspur-email-log' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
				</div>
			</div>
			<dl class="mailspur-d-meta" id="mailspur-d-meta"></dl>
			<div class="mailspur-d-tabs" role="tablist">
				<button type="button" role="tab" id="mailspur-tab-preview" data-view="preview" aria-selected="true" aria-controls="mailspur-d-body"><?php esc_html_e( 'Preview', 'mailspur-email-log' ); ?></button>
				<button type="button" role="tab" id="mailspur-tab-source" data-view="source" aria-selected="false" aria-controls="mailspur-d-body"><?php esc_html_e( 'Source', 'mailspur-email-log' ); ?></button>
				<button type="button" role="tab" id="mailspur-tab-headers" data-view="headers" aria-selected="false" aria-controls="mailspur-d-body"><?php esc_html_e( 'Headers', 'mailspur-email-log' ); ?></button>
				<span class="mailspur-d-remote" id="mailspur-d-remote" hidden>
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<span id="mailspur-d-remote-text"></span>
					<button type="button" class="button-link" id="mailspur-d-remote-toggle"></button>
				</span>
			</div>
			<div class="mailspur-d-body" id="mailspur-d-body" role="tabpanel"></div>
		</dialog>

		<div class="mailspur-toast" id="mailspur-toast" role="status" aria-live="polite"></div>
		<?php
	}

	private function render_settings(): void {
		$s     = Settings::all();
		$name  = Settings::OPTION;
		$stats = $this->repository->stats();
		$caps  = array(
			'manage_options'    => __( 'Administrators', 'mailspur-email-log' ),
			'edit_others_posts' => __( 'Editors and above', 'mailspur-email-log' ),
		);
		if ( class_exists( 'WooCommerce' ) || 'manage_woocommerce' === $s['capability'] ) {
			$caps['manage_woocommerce'] = __( 'Shop managers and above', 'mailspur-email-log' );
		}

		settings_errors();
		?>
		<form method="post" action="options.php" class="mailspur-settings">
			<?php settings_fields( 'mailspur' ); ?>

			<h2><?php esc_html_e( 'Access', 'mailspur-email-log' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mailspur-capability"><?php esc_html_e( 'Who can view the log', 'mailspur-email-log' ); ?></label></th>
					<td>
						<select id="mailspur-capability" name="<?php echo esc_attr( $name ); ?>[capability]">
							<?php foreach ( $caps as $cap => $label ) : ?>
								<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $s['capability'], $cap ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Logged emails can contain personal data. Grant access as narrowly as possible. Settings always require administrator rights.', 'mailspur-email-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Menu position', 'mailspur-email-log' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[menu_location]" value="top" <?php checked( $s['menu_location'], 'top' ); ?>> <?php esc_html_e( 'Own top-level menu item', 'mailspur-email-log' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[menu_location]" value="tools" <?php checked( $s['menu_location'], 'tools' ); ?>> <?php esc_html_e( 'Under Tools', 'mailspur-email-log' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Privacy & security', 'mailspur-email-log' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Redact secrets', 'mailspur-email-log' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[redact_secrets]" value="1" <?php checked( $s['redact_secrets'] ); ?>> <?php esc_html_e( 'Mask password-reset, activation and access keys in logged links as well as passwords, API keys and card numbers sent in plain text', 'mailspur-email-log' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended. Otherwise anyone with log access could use a logged reset link or password to take over an account. Resending such an email sends the masked version.', 'mailspur-email-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Remote content', 'mailspur-email-log' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remote_images]" value="1" <?php checked( $s['remote_images'] ); ?>> <?php esc_html_e( 'Always load remote images and fonts in the preview', 'mailspur-email-log' ); ?></label>
						<p class="description"><?php esc_html_e( 'When off, tracking pixels cannot tell when you open an entry. You can still load remote content for a single email. Scripts and forms are always blocked.', 'mailspur-email-log' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Retention', 'mailspur-email-log' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mailspur-retention"><?php esc_html_e( 'Delete entries after', 'mailspur-email-log' ); ?></label></th>
					<td>
						<input type="number" class="small-text" id="mailspur-retention" min="0" max="3650" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>"> <?php esc_html_e( 'days', 'mailspur-email-log' ); ?>
						<p class="description"><?php esc_html_e( '0 keeps entries forever. Cleanup runs once a day.', 'mailspur-email-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mailspur-max"><?php esc_html_e( 'Keep at most', 'mailspur-email-log' ); ?></label></th>
					<td>
						<input type="number" class="regular-text mailspur-narrow" id="mailspur-max" min="0" name="<?php echo esc_attr( $name ); ?>[max_entries]" value="<?php echo esc_attr( (string) $s['max_entries'] ); ?>"> <?php esc_html_e( 'entries', 'mailspur-email-log' ); ?>
						<p class="description"><?php esc_html_e( '0 means no limit. The oldest entries are removed first.', 'mailspur-email-log' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'mailspur-email-log' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete the log table and settings when the plugin is deleted', 'mailspur-email-log' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Current size', 'mailspur-email-log' ); ?></th>
					<td>
						<?php
						if ( $stats['bytes'] > 0 ) {
							printf(
								/* translators: 1: number of entries, 2: size, e.g. "4 MB" */
								esc_html__( '%1$s entries, %2$s', 'mailspur-email-log' ),
								esc_html( number_format_i18n( $stats['rows'] ) ),
								esc_html( (string) size_format( $stats['bytes'], 1 ) )
							);
						} else {
							/* translators: %s: number of entries */
							printf( esc_html__( '%s entries', 'mailspur-email-log' ), esc_html( number_format_i18n( $stats['rows'] ) ) );
						}
						?>
					</td>
				</tr>
			</table>

			<?php
			/**
			 * Module settings: render <h2> + form-table rows with inputs named
			 * mailspur_settings[your_key]; register defaults/sanitizing via the
			 * mailspur_settings_defaults / mailspur_settings_sanitize filters.
			 *
			 * @param array<string,mixed> $settings Current settings.
			 * @param string              $name     Option name for input names.
			 */
			do_action( 'mailspur_settings_sections', $s, $name );
			submit_button();
			?>
		</form>
		<?php
		$this->render_import();

		/** Module content below the settings form (tools, checks …), outside the options form. */
		do_action( 'mailspur_settings_after' );
	}

	private function import_assets( string $base ): void {
		wp_enqueue_script(
			'mailspur-import',
			$base . 'import.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				/* translators: 1: entries imported so far, 2: entries in total */
				'progress'    => __( '%1$s of %2$s entries processed …', 'mailspur-email-log' ),
				/* translators: 1: imported entries, 2: duplicates skipped, 3: entries older than the retention period */
				'done'        => __( 'Done: %1$s imported, %2$s duplicates, %3$s too old.', 'mailspur-email-log' ),
				/* translators: %s: name of another plugin */
				'confirmUndo' => __( 'Remove all entries imported from %s? The data in the other plugin is not affected.', 'mailspur-email-log' ),
				/* translators: %s: number of removed entries */
				'undone'      => __( '%s imported entries removed.', 'mailspur-email-log' ),
				/* translators: %s: error message */
				'failed'      => __( 'Import failed: %s', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-import', 'window.mailspurImportConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	private function render_import(): void {
		$sources = ( new Import\Importer( $this->repository ) )->overview();
		$cutoff  = Import\Importer::retention_cutoff();
		$names   = array_map(
			static function ( Import\Source $source ): string {
				return $source->label();
			},
			array_values( Import\Importer::sources() )
		);
		?>
		<h2 id="mailspur-import"><?php esc_html_e( 'Import from other plugins', 'mailspur-email-log' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Copies the existing log of another mail logging plugin into Mailspur, so old emails can be searched and previewed here. The other plugin and its data stay untouched; running the import again only adds new entries.', 'mailspur-email-log' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Emails that are already in the log – logged by Mailspur itself or imported from another plugin – are recognized by recipients, subject and send time and skipped as duplicates.', 'mailspur-email-log' ); ?>
		</p>
		<?php if ( $cutoff ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of days */
					esc_html__( 'Entries older than %s days are skipped because the retention setting would delete them anyway. Change the retention above first to import everything.', 'mailspur-email-log' ),
					esc_html( number_format_i18n( (int) Settings::get( 'retention_days' ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( ! $sources ) : ?>
			<p><em><?php esc_html_e( 'No log of another mail logging plugin was found on this site.', 'mailspur-email-log' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped mailspur-import" role="presentation">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Plugin', 'mailspur-email-log' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Entries', 'mailspur-email-log' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Imported', 'mailspur-email-log' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mailspur-email-log' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sources as $source ) : ?>
						<tr data-source="<?php echo esc_attr( $source['id'] ); ?>" data-label="<?php echo esc_attr( $source['label'] ); ?>" data-total="<?php echo esc_attr( (string) $source['total'] ); ?>" data-remaining="<?php echo esc_attr( (string) $source['remaining'] ); ?>">
							<td><strong><?php echo esc_html( $source['label'] ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $source['total'] ) ); ?></td>
							<td class="mailspur-import-status" aria-live="polite">
								<?php
								printf(
									/* translators: 1: imported entries, 2: duplicates skipped, 3: entries older than the retention period */
									esc_html__( '%1$s imported, %2$s duplicates, %3$s too old', 'mailspur-email-log' ),
									esc_html( number_format_i18n( $source['imported'] ) ),
									esc_html( number_format_i18n( $source['duplicates'] ) ),
									esc_html( number_format_i18n( $source['skipped'] ) )
								);
								?>
							</td>
							<td class="mailspur-import-actions">
								<button type="button" class="button button-primary" data-import-action="run" <?php disabled( 0 === $source['remaining'] ); ?>>
									<?php 0 === $source['imported'] ? esc_html_e( 'Import', 'mailspur-email-log' ) : esc_html_e( 'Import new entries', 'mailspur-email-log' ); ?>
								</button>
								<?php if ( $source['imported'] > 0 ) : ?>
									<button type="button" class="button-link mailspur-purge" data-import-action="undo"><?php esc_html_e( 'Remove imported entries', 'mailspur-email-log' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: comma-separated list of plugin names */
				esc_html__( 'Supported: %s.', 'mailspur-email-log' ),
				esc_html( implode( ', ', $names ) )
			);
			?>
		</p>
		<?php
	}
}
