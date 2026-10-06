<?php
/**
 * Email types: probe emails – only the administrator receives them, they are marked and exempt from the
 * emergency brake while the probe runs (and nothing stays hooked afterwards), and the result is read back.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Types\Probe;
use Mailspur\Modules\Types\ProbeCli;
use Mailspur\Repository;

require_once dirname( __DIR__ ) . '/stubs/class-context-fakes.php';

final class TypesProbeTest extends TestCase {

	/** @var Probe */
	private $probe;

	/** @var array<string,array<int,callable>> Hooks added by the probe and still registered. */
	private $hooks = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$this->probe = new Probe();
		$hooks       = &$this->hooks;
		$add         = static function ( $hook, $callback ) use ( &$hooks ) {
			$hooks[ $hook ][] = $callback;
			return true;
		};
		$remove      = static function ( $hook, $callback ) use ( &$hooks ) {
			$hooks[ $hook ] = array_values(
				array_filter(
					$hooks[ $hook ] ?? array(),
					static function ( $registered ) use ( $callback ) {
						return $registered !== $callback;
					}
				)
			);
			if ( ! $hooks[ $hook ] ) {
				unset( $hooks[ $hook ] );
			}
			return true;
		};
		Functions\when( 'add_filter' )->alias( $add );
		Functions\when( 'add_action' )->alias( $add );
		Functions\when( 'remove_filter' )->alias( $remove );
		Functions\when( 'remove_action' )->alias( $remove );
		Functions\when( 'user_can' )->alias(
			static function ( $user, $cap ) {
				return 'manage_options' === $cap && 1 === $user->ID;
			}
		);
	}

	/**
	 * What WordPress does inside wp_mail() for one email, with the probe's hooks: filters, logging, result.
	 *
	 * @param array<string,mixed> $atts
	 * @return array{0:array<string,mixed>,1:array<string,mixed>} Filtered arguments and meta.
	 */
	private function mail( array $atts, int $id, int $status, int $notes = 0 ): array {
		foreach ( $this->hooks['wp_mail'] as $callback ) {
			$atts = $callback( $atts );
		}
		$meta = array();
		foreach ( $this->hooks['mailspur_meta'] as $callback ) {
			$meta = $callback( $meta, 'capture' );
		}
		$this->assertTrue( $this->hooks['mailspur_brake_exempt'][0]( false, $atts ) );
		$this->hooks['mailspur_logged'][0](
			$id,
			array(
				'subject' => $atts['subject'],
				'status'  => $status,
				'notes'   => $notes,
				'error'   => Repository::STATUS_FAILED === $status ? 'SMTP connect() failed.' : '',
			)
		);
		return array( $atts, $meta );
	}

	public function test_password_reset_goes_only_to_the_administrator_and_is_marked(): void {
		$seen = array();
		Functions\expect( 'retrieve_password' )->once()->with( 'anna' )->andReturnUsing(
			function () use ( &$seen ) {
				$seen = $this->mail(
					array(
						'to'      => 'someone-else@example.com',
						'subject' => '[Shop] Password Reset',
						'headers' => "From: Shop <shop@example.com>\r\nCc: boss@example.com\r\nBCC: archive@example.com",
					),
					41,
					Repository::STATUS_SENT,
					2
				);
				return true;
			}
		);

		$result = $this->probe->run( 'password-reset', new \WP_User( 1, 'anna@example.com', 'anna' ) );

		$this->assertSame( array( 'anna@example.com' ), $seen[0]['to'] );
		$this->assertSame( array( 'From: Shop <shop@example.com>' ), $seen[0]['headers'] );
		$this->assertSame( array( 'probe' => 'password-reset' ), $seen[1] );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 41, $result[0]['id'] );
		$this->assertSame( 'sent', $result[0]['status'] );
		$this->assertSame( 2, $result[0]['notes'] );
		$this->assertGreaterThanOrEqual( 0, $result[0]['duration'] );
		$this->assertSame( array(), $this->hooks, 'nothing stays hooked after the probe' );
	}

	public function test_new_user_notifications_report_each_email(): void {
		Functions\expect( 'wp_new_user_notification' )->once()->with( 1, null, 'both' )->andReturnUsing(
			function () {
				$this->mail(
					array(
						'to'      => 'admin@example.com',
						'subject' => '[Shop] New User Registration',
						'headers' => array( 'Bcc: x@example.com' ),
					),
					50,
					Repository::STATUS_SENT
				);
				$this->mail(
					array(
						'to'      => 'anna@example.com',
						'subject' => '[Shop] Login Details',
					),
					51,
					Repository::STATUS_FAILED
				);
			}
		);
		$result = $this->probe->run( 'new-user', new \WP_User( 1, 'anna@example.com', 'anna' ) );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'sent', 'failed' ), array_column( $result, 'status' ) );
		$this->assertSame( 'SMTP connect() failed.', $result[1]['error'] );
		$this->assertSame( array(), $this->hooks );
	}

	public function test_refuses_non_administrators_unknown_kinds_and_reports_silence(): void {
		Functions\expect( 'retrieve_password' )->once()->andReturn( true ); // A plugin turned the email off.

		$this->assertInstanceOf( \WP_Error::class, $this->probe->run( 'password-reset', new \WP_User( 2, 'bob@example.com', 'bob' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->probe->run( 'password-change', new \WP_User( 1, 'anna@example.com', 'anna' ) ) );
		$none = $this->probe->run( 'password-reset', new \WP_User( 1, 'anna@example.com', 'anna' ) );
		$this->assertInstanceOf( \WP_Error::class, $none );
		$this->assertStringContainsString( 'did not send an email', $none->get_error_message() );

		Functions\expect( 'retrieve_password' )->once()->andReturn( new \WP_Error( 'no_password_reset', 'Password reset is not allowed for this user' ) );
		$error = $this->probe->run( 'password-reset', new \WP_User( 1, 'anna@example.com', 'anna' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'Password reset is not allowed for this user', $error->get_error_message() );
		$this->assertSame( array(), $this->hooks );
	}

	public function test_kind_of_translated_core_subjects(): void {
		// German site, English administrator: the reset email in the user's language, the admin notice in German.
		$german   = array(
			'[%s] Password Reset'        => '[%s] Passwort zurücksetzen',
			'[%s] New User Registration' => '[%s] Neue Benutzerregistrierung',
			'[%s] Login Details'         => '[%1$s] Deine Zugangsdaten',
		);
		$switched = array();
		Functions\when( 'determine_locale' )->justReturn( 'en_GB' );
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		Functions\when( 'switch_to_locale' )->alias(
			static function ( $locale ) use ( &$switched ) {
				$switched[] = $locale;
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			static function () use ( &$switched ) {
				$switched[] = 'restored';
				return true;
			}
		);
		Functions\when( 'translate' )->alias(
			static function ( $text ) use ( $german, &$switched ) {
				return 'de_DE' === end( $switched ) ? ( $german[ $text ] ?? $text ) : $text;
			}
		);

		$this->assertSame( 'password-reset', Probe::kind( '[{…}] Passwort zurücksetzen' ) );
		$this->assertSame( 'new-user', Probe::kind( '[Meine Seite] Neue Benutzerregistrierung' ) );
		$this->assertSame( 'new-user', Probe::kind( '[{…}] DEINE ZUGANGSDATEN' ), 'case-insensitive' );
		$this->assertSame( 'password-reset', Probe::kind( '[{…}] Password Reset' ), 'English still works' );
		$this->assertNull( Probe::kind( 'Passwort zurücksetzen' ), 'the whole subject is no ending of itself' );
		$this->assertNull( Probe::kind( '[{…}] Passwort geändert' ) );
		$this->assertSame( array( 'de_DE', 'restored' ), $switched, 'site language looked up once, then cached' );
	}

	public function test_kind_of_template_kinds_and_core_subjects(): void {
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$this->assertSame( 'password-reset', Probe::kind( 'password-reset' ) );
		$this->assertSame( 'new-user', Probe::kind( 'new-user-admin' ) );
		$this->assertSame( 'new-user', Probe::kind( '[{name}] New User Registration' ) );
		$this->assertSame( 'password-reset', Probe::kind( '[{name}] Password Reset' ) );
		$this->assertNull( Probe::kind( '[{name}] Password Reset Request received' ) );
		$this->assertNull( Probe::kind( '' ) );
	}

	public function test_cli_probes_only_administrators(): void {
		$admin = new \WP_User( 1, 'anna@example.com', 'anna' );
		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $value ) use ( $admin ) {
				if ( 'bob@example.com' === $value ) {
					return new \WP_User( 2, 'bob@example.com', 'bob' );
				}
				return 'anna@example.com' === $value ? $admin : false;
			}
		);
		$this->assertSame( $admin, ProbeCli::user( 'anna@example.com' ) );
		$this->assertNull( ProbeCli::user( 'bob@example.com' ) );
		$this->assertNull( ProbeCli::user( 'nobody@example.com' ) );

		// Default: the administration email address – or the first administrator when it is no admin account.
		Functions\when( 'get_option' )->justReturn( 'bob@example.com' );
		Functions\when( 'get_users' )->justReturn( array( $admin ) );
		$this->assertSame( $admin, ProbeCli::user( '' ) );
	}
}
