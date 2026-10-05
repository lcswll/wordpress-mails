<?php
/**
 * "Edit template": where the text of an email type is maintained, per sender – plus the little the resolver
 * needs to know about a sent email (stored as $meta['template'] while it is sent, without queries).
 *
 * At send time: form plugins announce their form right before they mail (Contact Form 7, WPForms, Gravity Forms,
 * Fluent Forms – kept for the rest of the request, a submission sends several emails) and core announces its
 * password reset / new user notifications (used by exactly one email). WooCommerce emails already carry their
 * email id in $meta['context']['wc_email'] (Context module).
 *
 * At view time: the latest email of each type is read (one query for all types) and turned into a link the
 * current user can open, a hint for WordPress core emails, and the probe that can trigger such an email.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Types;

use Mailspur\Repository;
use Mailspur\Rest;

defined( 'ABSPATH' ) || exit;

final class Templates {

	/** Form plugins: source slugs => family. */
	const FORMS = array(
		'contact-form-7' => 'cf7',
		'wpforms'        => 'wpforms',
		'wpforms-lite'   => 'wpforms',
		'gravityforms'   => 'gf',
		'fluentform'     => 'fluentform',
	);

	/**
	 * Form of the current submission.
	 *
	 * @var array{plugin:string,form:int}|array{}
	 */
	private $form = array();

	/** @var string Core email announced for the next wp_mail() call. */
	private $pending = '';

	/** @var string Core email of the wp_mail() call in progress. */
	private $current = '';

	/** @var callable Capability check (current_user_can). */
	private $can;

	/** @var array<string,string>|null WooCommerce settings sections by email id. */
	private $wc_sections;

	/**
	 * @param callable|null            $can         Capability check, default current_user_can.
	 * @param array<string,string>|null $wc_sections WooCommerce sections by email id (tests), default from WooCommerce.
	 */
	public function __construct( ?callable $can = null, ?array $wc_sections = null ) {
		$this->can         = $can ?? 'current_user_can';
		$this->wc_sections = $wc_sections;
	}

	public function register(): void {
		add_filter( 'retrieve_password_notification_email', array( $this, 'password_reset' ) );
		add_filter( 'wp_new_user_notification_email', array( $this, 'new_user' ) );
		add_filter( 'wp_new_user_notification_email_admin', array( $this, 'new_user_admin' ) );

		// Form plugins (the hooks only fire when the plugin is active; their objects are read defensively).
		add_action( 'wpcf7_before_send_mail', array( $this, 'cf7' ) );
		add_action( 'wpforms_process', array( $this, 'wpforms' ), 10, 3 );
		add_filter( 'gform_notification', array( $this, 'gravityforms' ), 10, 2 );
		add_action( 'fluentform/before_form_actions_processing', array( $this, 'fluentform' ), 10, 3 );
		add_action( 'fluentform_before_form_actions_processing', array( $this, 'fluentform' ), 10, 3 );

		add_filter( 'wp_mail', array( $this, 'promote' ), 1 );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 10, 2 );
	}

	/* ------------------------------------------------------------ send time */

	/**
	 * @param mixed $email Filtered email array.
	 * @return mixed Unchanged.
	 */
	public function password_reset( $email ) {
		$this->pending = Probe::PASSWORD_RESET;
		return $email;
	}

	/**
	 * @param mixed $email Filtered email array.
	 * @return mixed Unchanged.
	 */
	public function new_user( $email ) {
		$this->pending = Probe::NEW_USER;
		return $email;
	}

	/**
	 * @param mixed $email Filtered email array.
	 * @return mixed Unchanged.
	 */
	public function new_user_admin( $email ) {
		$this->pending = Probe::NEW_USER . '-admin';
		return $email;
	}

	/**
	 * @param mixed $form WPCF7_ContactForm.
	 */
	public function cf7( $form ): void {
		$id = is_object( $form ) && method_exists( $form, 'id' ) ? (int) call_user_func( array( $form, 'id' ) ) : 0;
		$this->announce( 'cf7', $id );
	}

	/**
	 * @param mixed $fields    Fields (unused).
	 * @param mixed $entry     Entry (unused).
	 * @param mixed $form_data Form settings with "id".
	 */
	public function wpforms( $fields, $entry = null, $form_data = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		$this->announce( 'wpforms', is_array( $form_data ) ? (int) ( $form_data['id'] ?? 0 ) : 0 );
	}

	/**
	 * @param mixed $notification Notification settings.
	 * @param mixed $form         Form array with "id".
	 * @return mixed Unchanged.
	 */
	public function gravityforms( $notification, $form = null ) {
		$this->announce( 'gf', is_array( $form ) ? (int) ( $form['id'] ?? 0 ) : 0 );
		return $notification;
	}

	/**
	 * @param mixed $insert_id Entry id (unused).
	 * @param mixed $data      Submitted data (unused).
	 * @param mixed $form      Form object with "id".
	 */
	public function fluentform( $insert_id, $data = null, $form = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		$this->announce( 'fluentform', is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0 );
	}

	private function announce( string $plugin, int $id ): void {
		$this->form = $id > 0 ? array(
			'plugin' => $plugin,
			'form'   => $id,
		) : array();
	}

	/**
	 * A wp_mail() call starts: it takes over the announced core email.
	 *
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed Unchanged.
	 */
	public function promote( $atts ) {
		$this->current = $this->pending;
		$this->pending = '';
		return $atts;
	}

	/**
	 * @param mixed $meta  Meta collected so far.
	 * @param mixed $phase capture|phpmailer|result|import.
	 * @return mixed
	 */
	public function meta( $meta, $phase = '' ) {
		if ( 'capture' !== $phase || ! is_array( $meta ) ) {
			return $meta;
		}
		if ( '' !== $this->current ) {
			$meta['template'] = array( 'kind' => $this->current );
			$this->current    = '';
		} elseif ( $this->form ) {
			$meta['template'] = $this->form;
		}
		return $meta;
	}

	/* ------------------------------------------------------------ view time */

	/**
	 * Shortcuts of report items, from the meta of each type's latest email (one query).
	 *
	 * @param array<int,array<string,mixed>> $items Report items.
	 * @return array<int,array{url:string,hint:string,probe:string}> By type id.
	 */
	public function for_items( array $items ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $items, 'last_id' ) ) ) ) );
		$out = array();
		$all = array();
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, primary keys.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, meta FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					array_merge( array( Repository::table() ), $ids )
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$all[ (int) $row['id'] ] = Rest::decode_meta( (string) $row['meta'] );
			}
		}
		foreach ( $items as $item ) {
			try {
				$out[ (int) $item['id'] ] = $this->resolve( (string) $item['source'], $all[ (int) ( $item['last_id'] ?? 0 ) ] ?? array(), (array) $item['pattern'] );
			} catch ( \Throwable $e ) { // A third-party plugin must not break the tab.
				$out[ (int) $item['id'] ] = self::none();
			}
		}
		return $out;
	}

	/**
	 * Edit link, hint and probe of one type.
	 *
	 * @param array<string,mixed> $meta    Meta of the type's latest email.
	 * @param string[]            $pattern Subject pattern of the type.
	 * @return array{url:string,hint:string,probe:string}
	 */
	public function resolve( string $source, array $meta, array $pattern = array() ): array {
		$out      = self::none();
		$template = isset( $meta['template'] ) && is_array( $meta['template'] ) ? $meta['template'] : array();

		if ( '' === $source || 'core' === $source ) {
			$out['hint'] = __( 'WordPress core emails have no editor; they can be changed with filters.', 'mailspur-email-log' );
			$kind        = (string) ( $template['kind'] ?? ( $meta['probe'] ?? '' ) );
			$probe       = Probe::kind( '' !== $kind ? $kind : implode( ' ', $pattern ) );
			if ( null !== $probe && $this->can( 'manage_options' ) ) {
				$out['probe'] = $probe;
			}
			return $out;
		}
		if ( 0 !== strpos( $source, 'plugin:' ) ) {
			return $out; // Themes, must-use plugins, imports: no reliable place.
		}
		$slug     = substr( $source, 7 );
		$basename = self::basename( $slug );
		if ( '' === $basename ) {
			return $out; // Deactivated since: its screens are gone.
		}
		if ( 'woocommerce' === $slug ) {
			$out['url'] = $this->woocommerce( $meta );
			return $out;
		}
		if ( isset( self::FORMS[ $slug ] ) ) {
			$family     = self::FORMS[ $slug ];
			$form       = ( $template['plugin'] ?? '' ) === $family ? (int) ( $template['form'] ?? 0 ) : 0;
			$out['url'] = $this->form( $family, $form );
			return $out;
		}
		$out['url'] = $this->can( 'manage_options' ) ? $this->settings( $basename ) : '';
		return $out;
	}

	/** @return array{url:string,hint:string,probe:string} */
	private static function none(): array {
		return array(
			'url'   => '',
			'hint'  => '',
			'probe' => '',
		);
	}

	/** Capability check, with an object id for meta capabilities. */
	private function can( string $cap, int $id = 0 ): bool {
		return (bool) ( $id ? call_user_func( $this->can, $cap, $id ) : call_user_func( $this->can, $cap ) );
	}

	/**
	 * WooCommerce → Settings → Emails, opened at the email that was sent.
	 *
	 * @param array<string,mixed> $meta
	 */
	private function woocommerce( array $meta ): string {
		if ( ! $this->can( 'manage_woocommerce' ) ) {
			return '';
		}
		$args = array(
			'page' => 'wc-settings',
			'tab'  => 'email',
		);
		$id   = isset( $meta['context']['wc_email'] ) && is_string( $meta['context']['wc_email'] ) ? sanitize_key( $meta['context']['wc_email'] ) : '';
		if ( '' !== $id ) {
			$sections        = $this->wc_sections();
			$args['section'] = $sections[ $id ] ?? 'wc_email_' . $id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Settings sections (lower-cased WC_Email class names) by email id – also for emails added by extensions.
	 *
	 * @return array<string,string>
	 */
	private function wc_sections(): array {
		if ( null !== $this->wc_sections ) {
			return $this->wc_sections;
		}
		$this->wc_sections = array();
		$wc                = function_exists( 'WC' ) ? WC() : null;
		$mailer            = is_object( $wc ) && method_exists( $wc, 'mailer' ) ? $wc->mailer() : null;
		$emails            = is_object( $mailer ) && method_exists( $mailer, 'get_emails' ) ? $mailer->get_emails() : array();
		foreach ( is_array( $emails ) ? $emails : array() as $class => $email ) {
			if ( is_object( $email ) && isset( $email->id ) && is_string( $email->id ) ) {
				$this->wc_sections[ sanitize_key( $email->id ) ] = strtolower( (string) $class );
			}
		}
		return $this->wc_sections;
	}

	/**
	 * Form editor (notifications) when the form is known, the plugin's form list otherwise.
	 */
	private function form( string $family, int $form ): string {
		switch ( $family ) {
			case 'cf7':
				if ( $form && $this->can( 'wpcf7_edit_contact_form', $form ) ) {
					return add_query_arg(
						array(
							'page'   => 'wpcf7',
							'post'   => $form,
							'action' => 'edit',
						),
						admin_url( 'admin.php' )
					);
				}
				return $this->can( 'wpcf7_read_contact_forms' ) ? add_query_arg( 'page', 'wpcf7', admin_url( 'admin.php' ) ) : '';
			case 'wpforms':
				if ( $form && $this->wpforms_can( 'edit_form_single', $form ) ) {
					return add_query_arg(
						array(
							'page'    => 'wpforms-builder',
							'view'    => 'settings',
							'section' => 'notifications',
							'form_id' => $form,
						),
						admin_url( 'admin.php' )
					);
				}
				return $this->wpforms_can( 'view_forms' ) ? add_query_arg( 'page', 'wpforms-overview', admin_url( 'admin.php' ) ) : '';
			case 'gf':
				if ( ! $this->can( 'gravityforms_edit_forms' ) && ! $this->can( 'gform_full_access' ) ) {
					return '';
				}
				$args = array( 'page' => 'gf_edit_forms' );
				if ( $form ) {
					$args += array(
						'view'    => 'settings',
						'subview' => 'notification',
						'id'      => $form,
					);
				}
				return add_query_arg( $args, admin_url( 'admin.php' ) );
			case 'fluentform':
				if ( ! $this->can( 'fluentform_forms_manager' ) && ! $this->can( 'manage_options' ) ) {
					return '';
				}
				$args = array( 'page' => 'fluent_forms' );
				if ( $form ) {
					$args += array(
						'form_id'   => $form,
						'route'     => 'settings',
						'sub_route' => 'form_settings',
					);
				}
				return add_query_arg( $args, admin_url( 'admin.php' ) ) . ( $form ? '#/email-settings' : '' );
		}
		return '';
	}

	/** WPForms' own permission check (falls back to manage_options). */
	private function wpforms_can( string $cap, int $form = 0 ): bool {
		if ( function_exists( 'wpforms_current_user_can' ) ) {
			return (bool) wpforms_current_user_can( $cap, $form ? $form : null );
		}
		return $this->can( 'manage_options' );
	}

	/**
	 * The "Settings" link the plugin adds to its row on the Plugins screen – only when it points into wp-admin.
	 */
	private function settings( string $basename ): string {
		$links = apply_filters( 'plugin_action_links_' . $basename, array(), $basename, array(), 'all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, read only.
		return self::settings_link( is_array( $links ) ? $links : array(), admin_url() );
	}

	/** Active plugin file of a source slug ("akismet" → "akismet/akismet.php"). */
	private static function basename( string $slug ): string {
		$active = array_merge( (array) get_option( 'active_plugins', array() ), is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array() );
		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( 0 === strpos( $file, $slug . '/' ) || $slug . '.php' === $file ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * URL of the "Settings" entry among plugin action links (HTML strings), or ''.
	 *
	 * @param array<int|string,mixed> $links Action links.
	 * @param string                  $admin admin_url().
	 */
	public static function settings_link( array $links, string $admin ): string {
		$names = array( 'settings', strtolower( __( 'Settings', 'mailspur-email-log' ) ) );
		foreach ( $links as $key => $html ) {
			if ( ! is_string( $html ) || ! preg_match( '/<a\s[^>]*href=(["\'])(.*?)\1/i', $html, $m ) ) {
				continue;
			}
			$text = strtolower( trim( wp_strip_all_tags( $html ) ) );
			if ( 'settings' !== $key && ! in_array( $text, $names, true ) ) {
				continue;
			}
			$url = html_entity_decode( $m[2], ENT_QUOTES );
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && 0 !== strpos( $url, '/' ) ) {
				$url = $admin . ltrim( $url, '/' ); // Relative to wp-admin, e.g. "options-general.php?page=foo".
			}
			if ( 0 === strpos( $url, $admin ) ) {
				return $url;
			}
		}
		return '';
	}
}
