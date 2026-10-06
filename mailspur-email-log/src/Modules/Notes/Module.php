<?php
/**
 * Notes: hints that explain real delivery and display problems of a mail, plus explanations of
 * error messages of failed mails.
 *
 * - Static rules (Rules, filter mailspur_note_rules) run in mailspur_finalize_row – for logged and
 *   imported mails – and store codes + params in meta "notes" and their count in the notes column.
 * - Dynamic checks (DNS, repeated sending) run only when an entry is opened (Dynamic).
 * - Texts are looked up at display time (Catalog, Explainer), so they follow the user's language.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

use Mailspur\Repository;
use Mailspur\Rest;
use Mailspur\Secrets;
use Mailspur\Settings;

defined( 'ABSPATH' ) || exit;

final class Module implements \Mailspur\Module {

	/**
	 * Meta key for secrets found in the mail as sent (kinds and masked hints only). The log masks them
	 * in the stored body ("Redact secrets"), so the rules would not see them any more; removed again
	 * in finalize_row().
	 */
	const SECRETS_META = 'notes_secrets';

	/**
	 * Meta flag: a plugin set a Reply-To on PHPMailer (e.g. an SMTP plugin's "Reply-To" setting), which the logged
	 * headers do not show. Read by Mail, removed again in finalize_row().
	 */
	const REPLY_TO_META = 'notes_reply_to';

	/**
	 * The module reads the log table only for its two lookups (list severities, repeated sending)
	 * that the Repository has no method for; it does not need the instance.
	 */
	public function __construct( Repository $repository ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the Module interface.

	public function register(): void {
		add_filter( 'mailspur_settings_defaults', array( $this, 'defaults' ) );
		add_filter( 'mailspur_settings_sanitize', array( $this, 'sanitize' ), 10, 2 );
		add_action( 'mailspur_settings_sections', array( $this, 'settings' ), 10, 2 );
		add_filter( 'mailspur_meta', array( $this, 'meta' ), 10, 3 );
		add_filter( 'mailspur_finalize_row', array( $this, 'finalize_row' ), 10, 2 );
		add_filter( 'mailspur_rest_summary', array( $this, 'summary' ), 10, 2 );
		add_filter( 'mailspur_rest_item', array( $this, 'item' ), 10, 2 );
		// Not rest_post_dispatch: that one is skipped for internal rest_do_request() calls.
		add_filter( 'rest_request_after_callbacks', array( $this, 'list_response' ), 10, 3 );
		add_action( 'mailspur_admin_enqueue', array( $this, 'assets' ), 10, 2 );
	}

	/**
	 * @param array<string,string|int|bool> $defaults
	 * @return array<string,string|int|bool>
	 */
	public function defaults( $defaults ): array {
		$defaults                  = (array) $defaults;
		$defaults['notes_enabled'] = true;
		$defaults['notes_ignore']  = '';
		return $defaults;
	}

	/**
	 * @param array<string,string|int|bool> $clean
	 * @param array<string,mixed>           $input
	 * @return array<string,string|int|bool>
	 */
	public function sanitize( $clean, $input ): array {
		$clean = (array) $clean;
		$input = (array) $input;

		$clean['notes_enabled'] = ! empty( $input['notes_enabled'] );

		$raw   = $input['notes_ignore'] ?? array();
		$codes = is_array( $raw ) ? array_map( 'strval', $raw ) : Engine::parse_codes( (string) $raw );
		$known = array_keys( Catalog::texts() );

		$clean['notes_ignore'] = implode( ',', array_values( array_intersect( $known, array_map( 'sanitize_key', $codes ) ) ) );
		return $clean;
	}

	/**
	 * Settings section "Notes".
	 *
	 * @param array<string,mixed> $settings
	 */
	public function settings( $settings, string $name ): void {
		$settings = (array) $settings;
		$ignored  = Engine::parse_codes( (string) ( $settings['notes_ignore'] ?? '' ) );
		?>
		<h2><?php esc_html_e( 'Notes', 'mailspur-email-log' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Check emails', 'mailspur-email-log' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[notes_enabled]" value="1" <?php checked( ! empty( $settings['notes_enabled'] ) ); ?>> <?php esc_html_e( 'Point out problems that keep emails from arriving or displaying correctly', 'mailspur-email-log' ); ?></label>
					<p class="description"><?php esc_html_e( 'Each email is checked once when it is logged or imported (no external requests). Changes here only affect emails logged afterwards; older entries are not checked again. DNS and repeated-sending checks run when you open an entry.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ignored notes', 'mailspur-email-log' ); ?></th>
				<td>
					<fieldset class="mailspur-notes-ignore">
						<legend class="screen-reader-text"><?php esc_html_e( 'Ignored notes', 'mailspur-email-log' ); ?></legend>
						<?php foreach ( array_keys( Catalog::texts() ) as $code ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[notes_ignore][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $ignored, true ) ); ?>> <?php echo esc_html( self::label( $code ) ); ?></label><br>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Ignored notes are no longer recorded for new emails and are hidden for existing entries.', 'mailspur-email-log' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Filter mailspur_meta: notes a Reply-To set on PHPMailer, and looks for secrets in the mail as sent – the wp_mail() message, the body
	 * PHPMailer sends, an imported message – because the stored body has them masked already.
	 * Only kinds and masked hints are kept; finalize_row() turns them into notes and drops the key.
	 *
	 * @param mixed $meta
	 * @param mixed $phase
	 * @param mixed $context
	 * @return mixed
	 */
	public function meta( $meta, $phase = '', $context = null ) {
		if ( ! is_array( $meta ) || ! Engine::enabled() ) {
			return $meta;
		}
		if ( 'phpmailer' === $phase && $context instanceof \PHPMailer\PHPMailer\PHPMailer && $context->getReplyToAddresses() ) {
			$meta[ self::REPLY_TO_META ] = true;
		}
		if ( ! Settings::get( 'redact_secrets' ) ) {
			return $meta;
		}
		try {
			if ( ( 'capture' === $phase || 'import' === $phase ) && is_array( $context ) ) {
				$text = (string) ( $context['message'] ?? '' );
			} elseif ( 'phpmailer' === $phase && $context instanceof \PHPMailer\PHPMailer\PHPMailer ) {
				$text = (string) $context->Body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			} else {
				return $meta;
			}
			$found = Secrets::find( $text );
			if ( $found ) {
				$known = isset( $meta[ self::SECRETS_META ] ) && is_array( $meta[ self::SECRETS_META ] ) ? $meta[ self::SECRETS_META ] : array();
				$all   = array();
				foreach ( array_merge( $known, $found ) as $secret ) {
					if ( is_array( $secret ) && isset( $secret['kind'] ) ) {
						$all[ $secret['kind'] . "\0" . ( $secret['hint'] ?? '' ) ] = $secret;
					}
				}
				$meta[ self::SECRETS_META ] = array_slice( array_values( $all ), 0, 10 );
			}
		} catch ( \Throwable $e ) { // Never lose the rest of the meta.
			unset( $e );
		}
		return $meta;
	}

	/**
	 * Runs the static rules (logged and imported mails) and stores codes + count.
	 *
	 * @param mixed $data Columns of the final UPDATE / imported row.
	 * @param mixed $row  Complete row incl. message, headers and meta (array).
	 * @return mixed
	 */
	public function finalize_row( $data, $row ) {
		if ( ! is_array( $data ) || ! is_array( $row ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $data;
		}
		unset( $data['meta'][ self::SECRETS_META ], $data['meta'][ self::REPLY_TO_META ] );
		if ( ! Engine::enabled() ) {
			return $data;
		}
		try {
			$notes = Engine::analyze( $row, null, Engine::ignored() );
		} catch ( \Throwable $e ) {
			return $data;
		}
		unset( $data['meta']['notes'] );
		if ( $notes ) {
			$data['meta']['notes'] = $notes;
		}
		$data['notes'] = count( $notes );
		return $data;
	}

	/**
	 * Short error explanation for the list row.
	 *
	 * @param array<string,mixed>  $item
	 * @param array<string,string> $row
	 * @return array<string,mixed>
	 */
	public function summary( $item, $row ): array {
		$item  = (array) $item;
		$error = (string) ( $item['error'] ?? '' );
		if ( '' !== $error ) {
			$hint = Explainer::title( $error );
			if ( '' !== $hint ) {
				$item['error_hint'] = $hint;
			}
		}
		return $item;
	}

	/**
	 * Detail view: rendered notes (stored + dynamic) and the error explanation.
	 *
	 * @param array<string,mixed>  $item
	 * @param array<string,string> $row
	 * @return array<string,mixed>
	 */
	public function item( $item, $row ): array {
		$item = (array) $item;
		$row  = (array) $row;

		$error              = (string) ( $item['error'] ?? '' );
		$item['error_help'] = '' !== $error ? Explainer::explain( $error ) : null;

		if ( ! Engine::enabled() ) {
			return $item;
		}

		$meta   = is_array( $item['meta'] ?? null ) ? $item['meta'] : Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
		$ignore = Engine::ignored();
		$list   = array();
		foreach ( Engine::prepare( $meta['notes'] ?? array(), $ignore ) as $note ) {
			$list[] = Catalog::render( $note ) + array( 'dynamic' => false );
		}
		try {
			$dynamic = Engine::prepare( Dynamic::notes( $row ), $ignore );
		} catch ( \Throwable $e ) {
			$dynamic = array();
		}
		foreach ( $dynamic as $note ) {
			$list[] = Catalog::render( $note ) + array( 'dynamic' => true );
		}
		$item['notes_list'] = Engine::sort( $list );
		return $item;
	}

	/**
	 * Adds the worst severity and titles of the stored notes to each list row – one query per page,
	 * only for rows that have notes. Ignored codes are left out here too.
	 *
	 * @param mixed $response
	 * @param mixed $response Result of the route callback.
	 * @param mixed $handler  Route handler (unused).
	 * @param mixed $request
	 * @return mixed
	 */
	public function list_response( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || $response->is_error()
			|| 'GET' !== $request->get_method() || '/' . Rest::NS . '/mails' !== $request->get_route() || ! Engine::enabled() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		$ids = array();
		foreach ( $data['items'] as $item ) {
			if ( is_array( $item ) && ! empty( $item['notes'] ) ) {
				$ids[] = (int) $item['id'];
			}
		}
		$metas  = $this->metas( $ids );
		$ignore = Engine::ignored();

		foreach ( $data['items'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$notes = Engine::prepare( $metas[ (int) $item['id'] ]['notes'] ?? array(), $ignore );

			$data['items'][ $i ]['notes_info'] = $notes ? array(
				'count'  => count( $notes ),
				'level'  => Engine::worst( $notes ),
				'titles' => array_map(
					static function ( array $note ): string {
						return Catalog::render( $note )['title'];
					},
					array_slice( $notes, 0, 5 )
				),
			) : null;
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Decoded meta of the given entries.
	 *
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	private function metas( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own log table, one %d per id.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, meta FROM %i WHERE id IN ({$placeholders})", Repository::table(), ...$ids ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$out[ (int) $row['id'] ] = Rest::decode_meta( (string) ( $row['meta'] ?? '' ) );
			}
		}
		return $out;
	}

	public function assets( string $tab, string $base ): void {
		if ( 'log' !== $tab ) {
			return;
		}
		wp_enqueue_style( 'mailspur-notes', $base . 'notes.css', array( 'mailspur-email-log-admin' ), \Mailspur\VERSION );
		wp_enqueue_script(
			'mailspur-notes',
			$base . 'notes.js',
			array( 'mailspur-email-log-admin' ),
			\Mailspur\VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'enabled' => Engine::enabled(),
			'i18n'    => array(
				'tab'     => __( 'Notes', 'mailspur-email-log' ),
				/* translators: 1: number of notes, 2: list of note titles */
				'badge'   => __( 'Notes: %1$s. %2$s', 'mailspur-email-log' ),
				'error'   => __( 'Errors', 'mailspur-email-log' ),
				'warning' => __( 'Warnings', 'mailspur-email-log' ),
				'info'    => __( 'Information', 'mailspur-email-log' ),
				'fix'     => __( 'How to fix:', 'mailspur-email-log' ),
				'dynamic' => __( 'Checked just now', 'mailspur-email-log' ),
				'none'    => __( 'No problems found in this email.', 'mailspur-email-log' ),
				'intro'   => __( 'Hints about problems that can keep this email from arriving or displaying correctly.', 'mailspur-email-log' ),
				'help'    => __( 'What to do', 'mailspur-email-log' ),
			),
		);
		wp_add_inline_script( 'mailspur-notes', 'window.mailspurNotes = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/** Title of a code without its values, for the settings list. */
	public static function label( string $code ): string {
		$title = Catalog::render(
			array(
				'code'     => $code,
				'severity' => 'info',
				'params'   => array_fill( 0, 5, '…' ),
			)
		)['title'];
		// Drop value parts: parentheses with a value, and everything after ": value", "? value" or ", e.g. value".
		$title = (string) preg_replace( '/\s*\([^()]*…[^()]*\)/u', '', $title );
		$title = (string) preg_replace( '/:\s*….*$|(\?)\s*….*$|,[^,…]*….*$/u', '$1', $title );
		return trim( $title );
	}
}
