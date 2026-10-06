<?php
/**
 * Notes module: storing notes with a mail, settings, REST payloads and the dynamic checks.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Modules\Notes\Catalog;
use Mailspur\Modules\Notes\Dynamic;
use Mailspur\Modules\Notes\Engine;
use Mailspur\Modules\Notes\Module;
use Mailspur\Repository;
use PHPMailer\PHPMailer\PHPMailer;
use WP_REST_Request;
use WP_REST_Response;


final class NotesModuleTest extends TestCase {

	/** @var Module */
	private $module;

	/** @var array<string,mixed> */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Catalog::reset();
		Engine::reset();
		$this->transients = array();

		Functions\stubs(
			array(
				'home_url'                => 'https://shop.example.de',
				'wp_parse_url'            => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				},
				'wp_get_environment_type' => 'production',
				'is_multisite'            => false,
				'get_transient'           => function ( $key ) {
					return $this->transients[ $key ] ?? false;
				},
				'set_transient'           => function ( $key, $value ) {
					$this->transients[ $key ] = $value;
					return true;
				},
			)
		);

		$this->module = new Module( new Repository() );
		// The module's defaults are part of the settings (as when it is registered).
		Filters\expectApplied( 'mailspur_settings_defaults' )->andReturnUsing( array( $this->module, 'defaults' ) );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 7,
				'created_at'   => '2026-10-01 12:00:00',
				'recipients'   => 'anna@gmial.com',
				'subject'      => 'Hello {first_name}',
				'message'      => 'Plain text',
				'headers'      => '',
				'content_type' => 'text/plain',
				'sender'       => 'shop@example.de',
				'source'       => 'core',
				'error'        => '',
				'meta'         => array( 'trace' => 'x' ),
			),
			$overrides
		);
	}

	public function test_finalize_row_stores_codes_and_count(): void {
		$data = $this->module->finalize_row( array( 'meta' => array( 'trace' => 'x' ) ), $this->row() );

		$this->assertSame( 2, $data['notes'] );
		$this->assertSame( 'x', $data['meta']['trace'] );
		$this->assertSame(
			array(
				array(
					'code'     => 'recipient_typo',
					'severity' => 'error',
					'params'   => array( 'gmial.com', 'gmail.com' ),
				),
				array(
					'code'     => 'placeholder',
					'severity' => 'warning',
					'params'   => array( '{first_name}' ),
				),
			),
			$data['meta']['notes']
		);
	}

	public function test_finalize_row_for_a_clean_mail_resets_stale_notes(): void {
		$data = $this->module->finalize_row(
			array( 'meta' => array( 'notes' => array( 'old' ) ) ),
			$this->row(
				array(
					'recipients' => 'anna@gmail.com',
					'subject'    => 'Hello',
				)
			)
		);
		$this->assertSame( 0, $data['notes'] );
		$this->assertSame( array(), $data['meta'] );
	}

	public function test_finalize_row_respects_ignored_codes_and_the_switch(): void {
		$this->settings = array( 'notes_ignore' => 'recipient_typo' );
		$data           = $this->module->finalize_row( array( 'meta' => array() ), $this->row() );
		$this->assertSame( 1, $data['notes'] );
		$this->assertSame( 'placeholder', $data['meta']['notes'][0]['code'] );

		$this->settings = array( 'notes_enabled' => false );
		$untouched      = array( 'meta' => array() );
		$this->assertSame( $untouched, $this->module->finalize_row( $untouched, $this->row() ) );
	}

	public function test_meta_records_secrets_of_the_mail_as_sent_without_the_secret(): void {
		$meta = $this->module->meta( array( 'trace' => 'x' ), 'capture', array( 'message' => "Password: Xy7!kq99\nCard: 4111 1111 1111 1111" ) );
		$this->assertSame(
			array(
				'trace'              => 'x',
				Module::SECRETS_META => array(
					array(
						'kind' => 'password',
						'hint' => '',
					),
					array(
						'kind' => 'card',
						'hint' => '…1111',
					),
				),
			),
			$meta
		);
		$this->assertStringNotContainsString( 'Xy7', (string) wp_json_encode( $meta ) );

		// A template plugin wrapped the body: the PHPMailer phase adds what is new, without duplicates.
		$mailer       = new PHPMailer();
		$mailer->Body = '<p>Password: Xy7!kq99</p><p>sk_' . 'live_51HxYzAbCdEfGhIjKlMn4f2a</p>'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$meta         = $this->module->meta( $meta, 'phpmailer', $mailer );
		$this->assertSame( array( 'password', 'card', 'api_key' ), array_column( $meta[ Module::SECRETS_META ], 'kind' ) );

		// Imported mails: the importer passes the unredacted row.
		$meta = $this->module->meta( array( 'import' => 'x' ), 'import', array( 'message' => 'Passwort: Ab3$xyz9' ) );
		$this->assertSame( array( 'password' ), array_column( $meta[ Module::SECRETS_META ], 'kind' ) );
	}

	public function test_meta_stays_untouched_without_secrets_or_when_switched_off(): void {
		$this->assertSame( array( 'a' => 1 ), $this->module->meta( array( 'a' => 1 ), 'capture', array( 'message' => 'Hello Anna' ) ) );
		$this->assertSame( array( 'a' => 1 ), $this->module->meta( array( 'a' => 1 ), 'result', array( 'status' => 1 ) ) );
		$this->assertSame( 'x', $this->module->meta( 'x', 'capture', array( 'message' => 'Password: Xy7!kq99' ) ) );

		// Without masking the rules see the secret in the stored body anyway.
		$this->settings = array( 'redact_secrets' => false );
		$this->assertSame( array(), $this->module->meta( array(), 'capture', array( 'message' => 'Password: Xy7!kq99' ) ) );

		$this->settings = array( 'notes_enabled' => false );
		$this->assertSame( array(), $this->module->meta( array(), 'capture', array( 'message' => 'Password: Xy7!kq99' ) ) );
	}

	public function test_finalize_row_turns_recorded_secrets_into_notes_and_drops_the_key(): void {
		$secrets = array(
			array(
				'kind' => 'password',
				'hint' => '',
			),
		);
		$row     = $this->row(
			array(
				'recipients' => 'anna@gmail.com',
				'subject'    => 'Welcome',
				'message'    => 'Password: [redacted]',
				'meta'       => array( Module::SECRETS_META => $secrets ),
			)
		);
		$data    = $this->module->finalize_row( array( 'meta' => array( Module::SECRETS_META => $secrets ) ), $row );
		$this->assertSame( 1, $data['notes'] );
		$this->assertSame( 'secret_password', $data['meta']['notes'][0]['code'] );
		$this->assertArrayNotHasKey( Module::SECRETS_META, $data['meta'] );

		$this->settings = array( 'notes_enabled' => false );
		$data           = $this->module->finalize_row( array( 'meta' => array( Module::SECRETS_META => $secrets ) ), $row );
		$this->assertSame( array( 'meta' => array() ), $data );
	}

	public function test_reply_to_set_on_phpmailer_silences_the_no_reply_note_and_is_not_stored(): void {
		$mailer = new PHPMailer();
		$this->assertSame( array( 'a' => 1 ), $this->module->meta( array( 'a' => 1 ), 'phpmailer', $mailer ) );

		$mailer->addReplyTo( 'help@example.de' );
		$meta = $this->module->meta( array( 'a' => 1 ), 'phpmailer', $mailer );
		$this->assertTrue( $meta[ Module::REPLY_TO_META ] );

		$row  = $this->row(
			array(
				'recipients' => 'anna@gmail.com',
				'subject'    => 'Your order',
				'sender'     => 'noreply@example.de',
			)
		);
		$data = $this->module->finalize_row( array( 'meta' => $meta ), array_merge( $row, array( 'meta' => $meta ) ) );
		$this->assertSame( 0, $data['notes'] );
		$this->assertSame( array( 'a' => 1 ), $data['meta'] );

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_users' )->justReturn( array() );
		$data = $this->module->finalize_row( array( 'meta' => array() ), $row );
		$this->assertSame( 'no_reply_to', $data['meta']['notes'][0]['code'] );
	}

	public function test_finalize_row_leaves_unexpected_input_alone(): void {
		$this->assertSame( 'x', $this->module->finalize_row( 'x', $this->row() ) );
		$this->assertSame( array( 'status' => 1 ), $this->module->finalize_row( array( 'status' => 1 ), $this->row() ) );
	}

	public function test_import_rows_are_checked_like_logged_mails(): void {
		// The importer passes the same array as data and row, meta already decoded.
		$row  = $this->row( array( 'meta' => array( 'import' => 'wp-mail-logging' ) ) );
		$data = $this->module->finalize_row( $row, $row );
		$this->assertSame( 2, $data['notes'] );
		$this->assertSame( 'wp-mail-logging', $data['meta']['import'] );
	}

	public function test_settings_defaults_and_sanitizing(): void {
		$defaults = $this->module->defaults( array( 'capability' => 'manage_options' ) );
		$this->assertTrue( $defaults['notes_enabled'] );
		$this->assertSame( '', $defaults['notes_ignore'] );

		$clean = $this->module->sanitize(
			array( 'capability' => 'manage_options' ),
			array( 'notes_ignore' => array( 'img_no_alt', 'subject_long', '<script>', 'unknown_code' ) )
		);
		$this->assertFalse( $clean['notes_enabled'] ); // Unchecked checkbox.
		$this->assertSame( 'subject_long,img_no_alt', $clean['notes_ignore'] );

		$clean = $this->module->sanitize( array(), array( 'notes_enabled' => '1' ) );
		$this->assertTrue( $clean['notes_enabled'] );
		$this->assertSame( '', $clean['notes_ignore'] );
	}

	public function test_settings_section_lists_every_code(): void {
		Functions\stubEscapeFunctions();
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true ): string {
				$attr = (string) $checked === (string) $current ? ' checked="checked"' : '';
				echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- like WordPress' checked().
				return $attr;
			}
		);
		ob_start();
		$this->module->settings(
			array(
				'notes_enabled' => true,
				'notes_ignore'  => 'img_no_alt',
			),
			'mailspur_settings'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="mailspur_settings[notes_enabled]" value="1"  checked="checked"', $html );
		$this->assertSame( count( Catalog::texts() ), substr_count( $html, 'name="mailspur_settings[notes_ignore][]"' ) );
		$this->assertStringContainsString( 'value="img_no_alt"  checked="checked"> Images without alt text</label>', $html );
		$this->assertStringContainsString( '> Gmail will clip this email</label>', $html );
		$this->assertStringContainsString( '> Typo in recipient domain?</label>', $html );
		$this->assertStringContainsString( '> Relative links or images</label>', $html );
		$this->assertStringContainsString( '> Sent repeatedly</label>', $html );
	}

	public function test_summary_adds_a_short_error_hint(): void {
		$item = $this->module->summary( array( 'error' => 'SMTP Error: Could not authenticate.' ), array() );
		$this->assertSame( 'SMTP login failed', $item['error_hint'] );

		$this->assertArrayNotHasKey( 'error_hint', $this->module->summary( array( 'error' => 'weird' ), array() ) );
		$this->assertArrayNotHasKey( 'error_hint', $this->module->summary( array( 'error' => '' ), array() ) );
	}

	public function test_item_renders_stored_and_dynamic_notes_and_the_error_help(): void {
		Filters\expectApplied( 'mailspur_notes_mx_lookup' )->andReturn( 'none' );
		$this->wpdb->results = array( '2' ); // Two identical mails within the window.

		$meta = array(
			'notes' => array(
				array(
					'code'     => 'img_no_alt',
					'severity' => 'info',
					'params'   => array( '3' ),
				),
				array(
					'code'     => 'placeholder',
					'severity' => 'warning',
					'params'   => array( '{first_name}' ),
				),
			),
		);
		$row  = $this->row(
			array(
				'recipients' => 'anna@nonexistent-domain-xyz.de',
				'meta'       => (string) wp_json_encode( $meta ),
				'error'      => 'Could not instantiate mail function.',
			)
		);
		$item = $this->module->item(
			array(
				'error' => $row['error'],
				'meta'  => $meta,
			),
			$row
		);

		$this->assertSame( 'mail_function', $item['error_help']['key'] );
		$this->assertCount( 3, $item['error_help']['steps'] );

		$this->assertSame( array( 'no_mx', 'placeholder', 'duplicate', 'img_no_alt' ), array_column( $item['notes_list'], 'code' ) );
		$this->assertSame( array( true, false, true, false ), array_column( $item['notes_list'], 'dynamic' ) );
		$this->assertSame( 'Recipient domain cannot receive email: nonexistent-domain-xyz.de', $item['notes_list'][0]['title'] );
		$this->assertSame( 'Sent repeatedly: 2 more identical emails within 10 minutes', $item['notes_list'][2]['title'] );
		$this->assertSame( 'Images without alt text (3)', $item['notes_list'][3]['title'] );
		$this->assertNotSame( '', $item['notes_list'][3]['fix'] );

		// The repeat check is one query on the created_at window, excluding the entry itself and resends.
		$query = end( $this->wpdb->prepared );
		$this->assertStringContainsString( 'created_at BETWEEN %s AND %s AND id <> %d AND source <> %s AND recipients = %s AND subject = %s', $query['sql'] );
		$this->assertSame( array( 'wp_mailspur', '2026-10-01 11:50:00', '2026-10-01 12:10:00', 7, 'mailspur:resend', 'anna@nonexistent-domain-xyz.de', 'Hello {first_name}' ), $query['args'] );
	}

	public function test_item_hides_ignored_notes_and_everything_when_disabled(): void {
		$this->settings = array( 'notes_ignore' => 'img_no_alt,duplicate' );
		Filters\expectApplied( 'mailspur_notes_mx_lookup' )->andReturn( 'ok' );
		$this->wpdb->results = array( '4' );

		$meta = array(
			'notes' => array(
				array(
					'code'     => 'img_no_alt',
					'severity' => 'info',
					'params'   => array( '1' ),
				),
			),
		);
		$item = $this->module->item( array( 'meta' => $meta ), $this->row( array( 'recipients' => 'anna@shop.de' ) ) );
		$this->assertSame( array(), $item['notes_list'] );
		$this->assertNull( $item['error_help'] );

		$this->settings = array( 'notes_enabled' => false );
		$item           = $this->module->item( array( 'meta' => $meta ), $this->row() );
		$this->assertArrayNotHasKey( 'notes_list', $item );
	}

	public function test_list_response_adds_severity_and_titles_with_one_query(): void {
		$this->settings      = array( 'notes_ignore' => 'subject_long' );
		$this->wpdb->results = array(
			array(
				array(
					'id'   => '3',
					'meta' => (string) wp_json_encode(
						array(
							'notes' => array(
								array(
									'code'     => 'subject_long',
									'severity' => 'info',
									'params'   => array( '120' ),
								),
								array(
									'code'     => 'relative_urls',
									'severity' => 'error',
									'params'   => array( '2', '/logo.png' ),
								),
							),
						)
					),
				),
				array(
					'id'   => '4',
					'meta' => (string) wp_json_encode(
						array(
							'notes' => array(
								array(
									'code'     => 'subject_long',
									'severity' => 'info',
									'params'   => array( '120' ),
								),
							),
						)
					),
				),
			),
		);
		$response            = new WP_REST_Response(
			array(
				'items' => array(
					array(
						'id'    => 3,
						'notes' => 2,
					),
					array(
						'id'    => 4,
						'notes' => 1,
					),
					array(
						'id'    => 5,
						'notes' => 0,
					),
				),
				'total' => 3,
			)
		);

		$result = $this->module->list_response( $response, null, new WP_REST_Request( 'GET', '/mailspur-email-log/v1/mails' ) );
		$items  = $result->get_data()['items'];

		$this->assertSame(
			array(
				'count'  => 1,
				'level'  => 'error',
				'titles' => array( 'Relative links or images (2), e.g. /logo.png' ),
			),
			$items[0]['notes_info']
		);
		$this->assertNull( $items[1]['notes_info'] ); // Only ignored notes.
		$this->assertNull( $items[2]['notes_info'] );

		$this->assertCount( 1, $this->wpdb->prepared );
		$this->assertStringContainsString( 'WHERE id IN (%d,%d)', $this->wpdb->prepared[0]['sql'] );
		$this->assertSame( array( 'wp_mailspur', 3, 4 ), $this->wpdb->prepared[0]['args'] );
	}

	public function test_list_response_ignores_other_routes(): void {
		$response = new WP_REST_Response( array( 'items' => array( array( 'id' => 1, 'notes' => 1 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( $response, $this->module->list_response( $response, null, new WP_REST_Request( 'GET', '/mailspur-email-log/v1/mails/1' ) ) );
		$this->assertSame( $response, $this->module->list_response( $response, null, new WP_REST_Request( 'DELETE', '/mailspur-email-log/v1/mails' ) ) );
		$this->assertSame( array( 'items' => array( array( 'id' => 1, 'notes' => 1 ) ) ), $response->get_data() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_mx_results_are_cached_per_domain_and_reserved_domains_skipped(): void {
		$lookups = 0;
		Filters\expectApplied( 'mailspur_notes_mx_lookup' )->andReturnUsing(
			static function ( $pre, string $domain ) use ( &$lookups ) {
				++$lookups;
				return 'gone.de' === $domain ? 'null' : 'unknown';
			}
		);
		$this->wpdb->results = array( '0' );
		$notes               = Dynamic::notes( $this->row( array( 'recipients' => 'a@gone.de, b@example.com, c@shop.test, d@other.de' ) ) );

		$this->assertSame( array( 'null_mx' ), array_column( $notes, 'code' ) );
		$this->assertSame( 2, $lookups ); // example.com and shop.test are never looked up.
	}

	public function test_real_lookup_states_are_cached(): void {
		$this->transients[ Dynamic::TRANSIENT_PREFIX . md5( 'cached.de' ) ] = 'none';
		$this->assertSame( 'none', Dynamic::mx_state( 'Cached.de' ) );
	}

	public function test_resends_are_not_counted_as_repeats(): void {
		$this->assertSame( 0, Dynamic::repeats( $this->row( array( 'source' => 'mailspur:resend' ) ) ) );
		$this->assertSame( array(), $this->wpdb->prepared );
	}

	public function test_assets_only_on_the_log_tab(): void {
		Functions\expect( 'wp_enqueue_script' )->once();
		Functions\expect( 'wp_enqueue_style' )->once();
		$inline = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( ...$args ) use ( &$inline ): bool {
				$inline[] = $args;
				return true;
			}
		);
		$this->module->assets( 'settings', 'https://x/assets/' );
		$this->module->assets( 'log', 'https://x/assets/' );

		$this->assertCount( 1, $inline );
		$this->assertSame( 'mailspur-notes', $inline[0][0] );
		$this->assertMatchesRegularExpression( '/^window\.mailspurNotes = \{"enabled":true,"i18n":\{"tab":"Notes"/', $inline[0][1] );
	}
}
