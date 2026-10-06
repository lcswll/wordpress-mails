<?php
/**
 * Email types: "Bundle into one daily email" – matching at send time (sender + exact subject pattern), which
 * emails are never bundled, the held status with its reason, the mirrored option, "Send now" and the digest
 * (subject, body, excerpt, first link, size cap, next run).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mailspur\Logger;
use Mailspur\Modules\Types\Bundle;
use Mailspur\Modules\Types\Digest;
use Mailspur\Modules\Types\Fingerprint;
use Mailspur\Modules\Types\Store;
use Mailspur\Repository;

final class TypesBundleTest extends TestCase {

	/** @var array<string,mixed> */
	private $options = array();

	/** @var Bundle */
	private $bundle;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\stubs(
			array(
				'number_format_i18n' => static function ( $n ) {
					return (string) $n;
				},
				'wp_date'            => static function ( $format, $time ) {
					return gmdate( 'Y-m-d H:i', (int) $time );
				},
				'add_query_arg'      => static function ( $key, $value, $url ) {
					return $url . '&' . $key . '=' . $value;
				},
				'get_users'          => array( 'boss@example.com' ),
			)
		);
		$this->options['admin_email']    = 'Admin@Example.com';
		$this->options[ Bundle::OPTION ] = array(
			12 => array( 'core', array( '[Site]', 'Please', 'moderate:', '{text}' ) ),
			13 => array( 'plugin:shop', array( 'New', 'order', '#{#}' ) ),
		);
		Bundle::reset_request();
		Logger::$source_override = '';
		Logger::$current_source  = 'core';
		$this->bundle            = new Bundle();
	}

	protected function tearDown(): void {
		Bundle::reset_request();
		Logger::$source_override = '';
		Logger::$current_source  = '';
		parent::tearDown();
	}

	/**
	 * Runs the capture phase and pre_wp_mail like the logger does.
	 *
	 * @param array<string,mixed> $atts
	 * @return mixed
	 */
	private function send( array $atts ) {
		$this->bundle->meta( array(), 'capture' );
		return $this->bundle->hold( null, $atts );
	}

	public function test_matches_sender_and_exact_pattern(): void {
		$map = Bundle::map();
		$this->assertSame( 12, Bundle::match( $map, 'core', '[Site] Please moderate: "Hello world"' ) );
		$this->assertSame( 13, Bundle::match( $map, 'plugin:shop', 'New order #1042' ) );
		$this->assertSame( 0, Bundle::match( $map, 'plugin:other', 'New order #1042' ), 'another sender' );
		$this->assertSame( 0, Bundle::match( $map, 'plugin:shop', 'New order #1042 cancelled' ), 'other wording' );
		$this->assertSame( 0, Bundle::match( $map, 'plugin:shop', 'New invoice #1042' ), 'never widened' );
	}

	public function test_only_emails_to_administrators(): void {
		$admins = array( 'admin@example.com', 'boss@example.com' );
		$this->assertTrue( Bundle::admins_only( array( 'to' => 'Admin <ADMIN@example.com>' ), $admins ) );
		$this->assertTrue( Bundle::admins_only( array( 'to' => array( 'admin@example.com', 'boss@example.com' ) ), $admins ) );
		$this->assertFalse( Bundle::admins_only( array( 'to' => 'admin@example.com, customer@example.org' ), $admins ), 'one recipient is not an administrator' );
		$this->assertFalse( Bundle::admins_only( array( 'to' => '' ), $admins ), 'no recipient' );
		$this->assertFalse(
			Bundle::admins_only(
				array(
					'to'      => 'admin@example.com',
					'headers' => array( 'From: x@example.com', 'Bcc: other@example.org' ),
				),
				$admins
			),
			'Bcc'
		);
		$this->assertFalse(
			Bundle::admins_only(
				array(
					'to'      => 'admin@example.com',
					'headers' => "Reply-To: a@example.com\ncc: other@example.org",
				),
				$admins
			),
			'Cc'
		);
		$this->assertFalse(
			Bundle::admins_only(
				array(
					'to'          => 'admin@example.com',
					'attachments' => array( '/tmp/a.pdf' ),
				),
				$admins
			),
			'attachments'
		);
		$this->assertFalse( Bundle::admins_only( array( 'to' => 'admin@example.com' ), array() ) );
	}

	public function test_holds_a_bundled_email_to_the_admin_and_marks_it(): void {
		$this->assertTrue(
			$this->send(
				array(
					'to'      => 'admin@example.com',
					'subject' => '[Site] Please moderate: "Hello"',
				)
			)
		);
		$data = $this->bundle->finalize(
			array(
				'status' => Repository::STATUS_SENT,
				'meta'   => array( 'trace' => array( 'x' => 1 ) ),
			)
		);
		$this->assertSame( Repository::STATUS_HELD, $data['status'] );
		$this->assertSame(
			array(
				'held'   => Bundle::HELD,
				'bundle' => 12,
			),
			$data['meta']['delivery']
		);
		$this->assertSame( array( 'x' => 1 ), $data['meta']['trace'] );

		// Consumed: the next mail is left alone.
		$this->assertSame( array( 'status' => 1 ), $this->bundle->finalize( array( 'status' => 1 ) ) );
	}

	public function test_never_bundled(): void {
		$moderation = array(
			'to'      => 'admin@example.com',
			'subject' => '[Site] Please moderate: "Hello"',
		);

		$this->assertNull( $this->bundle->hold( null, $moderation ), 'not logged' );
		$this->assertFalse( $this->bundle->hold( false, $moderation ), 'already handled (staging, brake)' );

		$this->bundle->password_reset( 'Reset' );
		$this->assertNull( $this->send( $moderation ), 'password reset' );

		Logger::$source_override = 'mailspur:alert';
		$this->assertNull( $this->send( $moderation ), 'own alerts and digests' );
		Logger::$source_override = '';

		$this->assertNull( $this->send( array( 'to' => 'customer@example.org' ) + $moderation ), 'customer' );

		Logger::$current_source = 'plugin:shop';
		$this->assertNull( $this->send( $moderation ), 'same subject from another sender' );
		Logger::$current_source = 'core';

		Filters\expectApplied( 'mailspur_brake_exempt' )->once()->andReturn( true );
		$this->assertNull( $this->send( $moderation ), 'exempt filter' );

		$this->options[ Bundle::OPTION ] = array();
		$this->assertNull( $this->send( $moderation ), 'nothing bundled' );

		$this->assertSame( array( 'status' => 1 ), $this->bundle->finalize( array( 'status' => 1 ) ), 'nothing held' );
	}

	public function test_sync_mirrors_bundled_types_only_on_change(): void {
		$types  = array(
			3 => array(
				'source'  => 'core',
				'pattern' => array( 'Hello', '{…}' ),
				'bundle'  => true,
			),
			4 => array(
				'source'  => 'core',
				'pattern' => array( 'Other' ),
				'bundle'  => false,
			),
			5 => array(
				'source'  => 'plugin:x',
				'pattern' => array( Fingerprint::OTHER ),
				'bundle'  => true,
			),
		);
		$writes = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( &$writes ) {
				$writes[]               = $autoload;
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Bundle::sync( new Store(), $types );
		$this->assertSame( array( 3 => array( 'core', array( 'Hello', '{…}' ) ) ), Bundle::map(), 'catch-all types are never bundled' );
		Bundle::sync( new Store(), $types );
		$this->assertSame( array( true ), $writes, 'written once (unchanged the second time), autoloaded – read for every email' );
	}

	public function test_send_now_takes_the_email_out_of_the_digest(): void {
		Bundle::released(
			array(
				'id'   => 9,
				'meta' => '{"delivery":{"held":"bundled","bundle":12}}',
			)
		);
		$update = $this->writes( 'update' );
		$this->assertCount( 1, $update );
		$this->assertSame( '{"delivery":{"held":"bundle_released","bundle":12}}', $update[0][2]['meta'] );

		Bundle::released(
			array(
				'id'   => 10,
				'meta' => '{"delivery":{"held":"brake"}}',
			)
		);
		$this->assertCount( 1, $this->writes( 'update' ), 'other held emails stay as they are' );
	}

	/**
	 * @return array<int,array{id:int,subject:string,time:int,excerpt:string,link:string,type:string}>
	 */
	private function entries( int $count, string $type = '[Site] Please moderate: “…”' ): array {
		$out = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$out[] = array(
				'id'      => $i,
				'subject' => '[Site] Please moderate: "Post ' . $i . '"',
				'time'    => 1790000000 + $i * 60,
				'excerpt' => 'A new comment on the post "Post ' . $i . '" is waiting for your approval',
				'link'    => 'https://site.example/wp-admin/comment.php?action=approve&c=' . $i,
				'type'    => $type,
			);
		}
		return $out;
	}

	public function test_digest_subject_names_count_and_type(): void {
		$one = Digest::compose( $this->entries( 1 ), 'My Site', 'https://site.example/wp-admin/admin.php?page=mailspur-email-log', 'https://site.example/types' );
		$this->assertSame( '[My Site] 1 notification: Please moderate: “…”', $one['subject'], 'the site prefix of the type is not repeated' );

		$entries   = $this->entries( 11 );
		$entries[] = $this->entries( 1, 'Some plugins were automatically updated' )[0];
		$many      = Digest::compose( $entries, 'My Site', 'https://site.example/log', 'https://site.example/types' );
		$this->assertSame( '[My Site] 12 notifications: Please moderate: “…” …', $many['subject'], 'most frequent type first, … for more types' );
	}

	public function test_digest_body_lists_every_email_with_links(): void {
		$entries               = $this->entries( 2 );
		$entries[1]['subject'] = '<script>alert(1)</script>';
		$mail                  = Digest::compose( $entries, 'My Site', 'https://site.example/log?page=x', 'https://site.example/types' );
		$this->assertStringContainsString( 'Daily digest', $mail['body'] );
		$this->assertStringContainsString( '2 emails bundled since the last digest', $mail['body'] );
		$this->assertStringContainsString( '[Site] Please moderate: &quot;Post 1&quot;', $mail['body'] );
		$this->assertMatchesRegularExpression( '#href="https://site\.example/log\?page=x&(amp;)?mail=1"#', $mail['body'], 'link to the entry in the log' );
		$this->assertStringContainsString( '>https://site.example/wp-admin/comment.php?action=approve&amp;c=2</a>', $mail['body'], 'first link of the email' );
		$this->assertStringContainsString( 'is waiting for your approval', $mail['body'] );
		$this->assertStringContainsString( '2026', $mail['body'], 'time of the email' );
		$this->assertStringNotContainsString( '<script>', $mail['body'] );
		$this->assertStringContainsString( 'https://site.example/types', $mail['body'] );
	}

	public function test_digest_size_is_capped(): void {
		$mail = Digest::compose( $this->entries( Digest::LIST_MAX + 7 ), 'S', 'https://site.example/log', 'https://site.example/types' );
		$this->assertSame( Digest::LIST_MAX, substr_count( $mail['body'], 'is waiting for your approval' ) );
		$this->assertStringContainsString( 'and 7 more – see the log', $mail['body'] );
		$this->assertStringContainsString( ( Digest::LIST_MAX + 7 ) . ' notifications', $mail['subject'] );
	}

	public function test_excerpt_and_first_link(): void {
		$text = "A new comment on the post \"Hello world\" is waiting for your approval\nhttps://site.example/?p=1\n\nAuthor: Anna\nApprove it: https://site.example/wp-admin/comment.php?action=approve&c=7#wpbody-content";
		$this->assertSame( 'https://site.example/?p=1', Digest::first_link( $text, false ) );
		$this->assertStringStartsWith( 'A new comment on the post "Hello world" is waiting for your approval', Digest::excerpt( $text, false ) );

		$html = '<html><head><style>p{color:red}</style></head><body><p>Hello <b>admin</b>,</p><p>see <a href="https://site.example/wp-admin/?a=1&amp;b=2">the order</a>.</p></body></html>';
		$this->assertSame( 'https://site.example/wp-admin/?a=1&b=2', Digest::first_link( $html, true ) );
		$this->assertSame( 'Hello admin, see the order.', Digest::excerpt( $html, true ), 'readable text without markup and link targets' );

		$long = str_repeat( 'word ', 100 );
		$this->assertSame( Digest::EXCERPT, mb_strlen( Digest::excerpt( $long, false ) ) );
		$this->assertStringEndsWith( '…', Digest::excerpt( $long, false ) );
		$this->assertSame( '', Digest::first_link( 'No link here.', false ) );
	}

	public function test_first_run_is_the_next_8am_site_time(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Berlin' ) );
		// 2026-10-06 05:00 UTC = 07:00 in Berlin → today 08:00 Berlin = 06:00 UTC.
		$this->assertSame( gmmktime( 6, 0, 0, 10, 6, 2026 ), Digest::first_run( gmmktime( 5, 0, 0, 10, 6, 2026 ) ) );
		// 09:00 in Berlin → tomorrow.
		$this->assertSame( gmmktime( 6, 0, 0, 10, 7, 2026 ), Digest::first_run( gmmktime( 7, 0, 0, 10, 6, 2026 ) ) );
	}
}
