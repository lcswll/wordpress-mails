<?php
/**
 * Email types: "Edit template" – what is remembered while an email is sent (form, core email) and where the
 * resolver points per sender, only for users who may open it.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Templates;

final class TypesTemplatesTest extends TestCase {

	/** @var string[] Capabilities of the current user. */
	private $caps = array( 'manage_options', 'manage_woocommerce' );

	/** @var string[] */
	private $active = array( 'woocommerce/woocommerce.php', 'contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php', 'gravityforms/gravityforms.php', 'fluentform/fluentform.php', 'shop-mailer/shop-mailer.php' );

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'admin_url'         => static function ( $path = '' ) {
					return 'https://example.com/wp-admin/' . $path;
				},
				'add_query_arg'     => static function ( ...$a ) {
					return is_array( $a[0] ) ? $a[1] . '?' . http_build_query( $a[0] ) : $a[2] . '?' . http_build_query( array( $a[0] => $a[1] ) );
				},
				'is_multisite'      => false,
				'determine_locale'  => 'en_US',
				'get_locale'        => 'en_US',
				'wp_strip_all_tags' => static function ( $html ) {
					return strip_tags( $html ); // phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- test stub.
				},
				'get_option'        => function ( $name, $fallback = false ) {
					return 'active_plugins' === $name ? $this->active : $fallback;
				},
			)
		);
	}

	private function templates(): Templates {
		return new Templates(
			function ( $cap ): bool {
				return in_array( $cap, $this->caps, true );
			},
			array(
				'customer_completed_order' => 'wc_email_customer_completed_order',
				'subscription_renewal'     => 'wcs_email_customer_renewal_invoice',
			)
		);
	}

	public function test_core_email_gets_hint_and_probe_from_the_captured_kind(): void {
		$t = $this->templates();
		$t->password_reset( array() );
		$t->promote( array() );
		$meta = (array) $t->meta( array(), 'capture' );
		$this->assertSame( array( 'kind' => 'password-reset' ), $meta['template'] );
		// One email only: the next one is not a password reset.
		$t->promote( array() );
		$this->assertSame( array(), $t->meta( array(), 'capture' ) );

		$out = $t->resolve( 'core', $meta, array( '[{name}]', 'Passwort', 'zurücksetzen' ) );
		$this->assertSame( '', $out['url'] );
		$this->assertStringContainsString( 'no editor', $out['hint'] );
		$this->assertSame( 'password-reset', $out['probe'] );

		$t->new_user_admin( array() );
		$t->promote( array() );
		$this->assertSame( 'new-user', $t->resolve( 'core', (array) $t->meta( array(), 'capture' ) )['probe'] );
	}

	public function test_core_probe_falls_back_to_english_subjects_and_needs_manage_options(): void {
		$t = $this->templates();
		$this->assertSame( 'password-reset', $t->resolve( 'core', array(), array( '[{name}]', 'Password', 'Reset' ) )['probe'] );
		$this->assertSame( 'new-user', $t->resolve( '', array(), array( '[{name}]', 'Login', 'Details' ) )['probe'] );
		$this->assertSame( '', $t->resolve( 'core', array(), array( '[{name}]', 'Password', 'Changed' ) )['probe'] );

		$this->caps = array();
		$out        = $t->resolve( 'core', array(), array( '[{name}]', 'Password', 'Reset' ) );
		$this->assertSame( '', $out['probe'] );
		$this->assertNotSame( '', $out['hint'] );
	}

	public function test_woocommerce_links_to_the_email_settings_section(): void {
		$t    = $this->templates();
		$meta = array(
			'context' => array(
				'order'    => 12,
				'wc_email' => 'customer_completed_order',
			),
		);
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=wc-settings&tab=email&section=wc_email_customer_completed_order', $t->resolve( 'plugin:woocommerce', $meta )['url'] );

		// Emails of extensions keep their own class; unknown ids fall back to the core naming.
		$meta['context']['wc_email'] = 'subscription_renewal';
		$this->assertStringEndsWith( 'section=wcs_email_customer_renewal_invoice', $t->resolve( 'plugin:woocommerce', $meta )['url'] );
		$meta['context']['wc_email'] = 'customer_note';
		$this->assertStringEndsWith( 'section=wc_email_customer_note', $t->resolve( 'plugin:woocommerce', $meta )['url'] );
		$this->assertStringEndsWith( 'tab=email', $t->resolve( 'plugin:woocommerce', array() )['url'] );

		$this->caps = array( 'manage_options' );
		$this->assertSame( '', $t->resolve( 'plugin:woocommerce', $meta )['url'] );
	}

	public function test_form_plugins_link_the_form_announced_before_sending(): void {
		$t = $this->templates();

		$t->cf7(
			new class() {
				public function id(): int {
					return 7;
				}
			}
		);
		$this->caps[] = 'wpcf7_edit_contact_form';
		$t->promote( array() );
		$meta = (array) $t->meta( array(), 'capture' );
		$this->assertSame(
			array(
				'plugin' => 'cf7',
				'form'   => 7,
			),
			$meta['template']
		);
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=wpcf7&post=7&action=edit', $t->resolve( 'plugin:contact-form-7', $meta )['url'] );
		// A submission sends several emails: the form stays for the rest of the request.
		$t->promote( array() );
		$this->assertSame( $meta, $t->meta( array(), 'capture' ) );

		$t->wpforms( array(), array(), array( 'id' => 31 ) );
		$t->promote( array() );
		$meta = (array) $t->meta( array(), 'capture' );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=wpforms-builder&view=settings&section=notifications&form_id=31', $t->resolve( 'plugin:wpforms-lite', $meta )['url'] );
		// Another plugin's form is ignored: the list of forms instead.
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=wpcf7', $this->with_caps( array( 'wpcf7_read_contact_forms' ) )->resolve( 'plugin:contact-form-7', $meta )['url'] );

		$this->assertSame( array( 'x' ), $t->gravityforms( array( 'x' ), array( 'id' => 4 ) ) );
		$t->promote( array() );
		$meta = (array) $t->meta( array(), 'capture' );
		$this->assertSame( '', $t->resolve( 'plugin:gravityforms', $meta )['url'] );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=gf_edit_forms&view=settings&subview=notification&id=4', $this->with_caps( array( 'gravityforms_edit_forms' ) )->resolve( 'plugin:gravityforms', $meta )['url'] );

		$form     = new \stdClass();
		$form->id = 9;
		$t->fluentform( 120, array(), $form );
		$t->promote( array() );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=fluent_forms&form_id=9&route=settings&sub_route=form_settings#/email-settings', $t->resolve( 'plugin:fluentform', (array) $t->meta( array(), 'capture' ) )['url'] );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=fluent_forms', $t->resolve( 'plugin:fluentform', array() )['url'] );
	}

	/**
	 * @param string[] $caps
	 */
	private function with_caps( array $caps ): Templates {
		return new Templates(
			static function ( $cap ) use ( $caps ): bool {
				return in_array( $cap, $caps, true );
			},
			array()
		);
	}

	public function test_other_plugins_use_their_settings_link_and_inactive_ones_nothing(): void {
		Filters\expectApplied( 'plugin_action_links_shop-mailer/shop-mailer.php' )->once()->with( array(), 'shop-mailer/shop-mailer.php', array(), 'all' )->andReturn(
			array(
				'deactivate' => '<a href="plugins.php?action=deactivate&amp;plugin=shop-mailer">Deactivate</a>',
				0            => '<a href="options-general.php?page=shop-mailer&amp;tab=mail">Settings</a>',
			)
		);
		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=shop-mailer&tab=mail', $this->templates()->resolve( 'plugin:shop-mailer', array() )['url'] );

		$this->assertSame( '', $this->templates()->resolve( 'plugin:gone-plugin', array() )['url'] );
		$this->assertSame( '', $this->templates()->resolve( 'theme:storefront', array() )['url'] );
		$this->caps = array();
		$this->assertSame( '', $this->templates()->resolve( 'plugin:shop-mailer', array() )['url'] );
	}

	public function test_settings_link_never_leaves_wp_admin(): void {
		$admin = 'https://example.com/wp-admin/';
		$this->assertSame( '', Templates::settings_link( array( 'settings' => '<a href="https://evil.example/wp-admin/">Settings</a>' ), $admin ) );
		$this->assertSame( '', Templates::settings_link( array( 'settings' => '<a href="javascript:alert(1)">Settings</a>' ), $admin ) );
		$this->assertSame( '', Templates::settings_link( array( '<a href="admin.php?page=x">Docs</a>' ), $admin ) );
		$this->assertSame( $admin . 'admin.php?page=x', Templates::settings_link( array( 'settings' => "<a class='s' href='admin.php?page=x'>Configure</a>" ), $admin ) );
		$this->assertSame( $admin . 'admin.php?page=y', Templates::settings_link( array( '<a href="' . $admin . 'admin.php?page=y">Settings</a>' ), $admin ) );
	}

	public function test_for_items_reads_the_latest_emails_in_one_query(): void {
		$this->wpdb->results[] = array(
			array(
				'id'   => 50,
				'meta' => '{"context":{"wc_email":"customer_completed_order"}}',
			),
		);
		$out                   = $this->templates()->for_items(
			array(
				array(
					'id'      => 1,
					'source'  => 'plugin:woocommerce',
					'pattern' => array( 'Order' ),
					'last_id' => 50,
				),
				array(
					'id'      => 2,
					'source'  => 'core',
					'pattern' => array( '[{name}]', 'Password', 'Reset' ),
					'last_id' => 0,
				),
			)
		);
		$this->assertCount( 1, $this->wpdb->prepared );
		$this->assertSame( array( 'wp_mailspur', 50 ), $this->wpdb->prepared[0]['args'] );
		$this->assertStringEndsWith( 'section=wc_email_customer_completed_order', $out[1]['url'] );
		$this->assertSame( 'password-reset', $out[2]['probe'] );
	}
}
