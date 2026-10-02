<?php
/**
 * Settings: sanitizing untrusted form input.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_apply_when_nothing_is_stored(): void {
		$this->assertSame( Settings::defaults(), Settings::all() );
		$this->assertSame( 'manage_options', Settings::capability() );
	}

	public function test_sanitize_rejects_unknown_capabilities(): void {
		$this->assertSame( 'manage_options', Settings::sanitize( array( 'capability' => 'read' ) )['capability'] );
		$this->assertSame( 'edit_others_posts', Settings::sanitize( array( 'capability' => 'edit_others_posts' ) )['capability'] );
	}

	public function test_stored_invalid_capability_falls_back_to_administrators(): void {
		$this->settings = array( 'capability' => 'exist' );
		$this->assertSame( 'manage_options', Settings::capability() );
	}

	public function test_sanitize_clamps_numbers_and_casts_flags(): void {
		$clean = Settings::sanitize(
			array(
				'retention_days' => '-5',
				'max_entries'    => '99999999999',
				'menu_location'  => '<script>',
				'remote_images'  => 'yes',
			)
		);

		$this->assertSame( 5, $clean['retention_days'] );
		$this->assertSame( 10000000, $clean['max_entries'] );
		$this->assertSame( 'top', $clean['menu_location'] );
		$this->assertTrue( $clean['remote_images'] );
		// Unchecked checkboxes are absent from the POST data and must turn the option off.
		$this->assertFalse( $clean['redact_secrets'] );
		$this->assertFalse( $clean['delete_data_on_uninstall'] );
	}

	public function test_sanitize_handles_garbage(): void {
		$clean = Settings::sanitize( 'not an array' );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $clean ) );
	}

	public function test_admins_can_always_view(): void {
		$this->settings = array( 'capability' => 'manage_woocommerce' );
		Functions\when( 'current_user_can' )->alias(
			static function ( string $cap ): bool {
				return 'manage_options' === $cap;
			}
		);
		$this->assertTrue( Settings::current_user_can_view() );
	}
}
