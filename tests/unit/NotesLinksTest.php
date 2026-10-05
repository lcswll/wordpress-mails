<?php
/**
 * Notes: dead links to the site itself – which links are checked, how, and that nothing but the own
 * site is ever contacted.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Notes\Dynamic;
use Mailspur\Modules\Notes\Links;
use Mailspur\Modules\Notes\Mail;
use WP_Error;

final class NotesLinksTest extends TestCase {

	const SITE = array(
		'home'        => 'shop.example.de',
		'environment' => 'production',
		'multisite'   => false,
	);

	/** @var array<string,mixed> */
	private $transients = array();

	/** @var string[] URLs requested. */
	private $requests = array();

	/** @var array<string,array{0:int,1:string}|WP_Error> URL => response (status, location). */
	private $responses = array();

	/** @var array<string,int> URL => post ID found by url_to_postid(). */
	private $posts = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$this->transients = array();
		$this->requests   = array();
		$this->responses  = array();
		$this->posts      = array();

		Functions\stubs(
			array(
				'home_url'                         => static function ( $path = '' ) {
					return 'https://shop.example.de' . $path;
				},
				'site_url'                         => 'https://shop.example.de',
				'wp_parse_url'                     => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				},
				'wp_get_environment_type'          => 'production',
				'is_multisite'                     => false,
				'get_transient'                    => function ( $key ) {
					return $this->transients[ $key ] ?? false;
				},
				'set_transient'                    => function ( $key, $value ) {
					$this->transients[ $key ] = $value;
					return true;
				},
				'url_to_postid'                    => function ( $url ) {
					return $this->posts[ $url ] ?? 0;
				},
				'get_post_status'                  => static function ( $id ) {
					return 99 === $id ? 'draft' : 'publish';
				},
				'wp_http_validate_url'             => static function ( $url ) {
					return $url;
				},
				'wp_safe_remote_head'              => function ( $url ) {
					$this->requests[] = $url;
					$response         = $this->responses[ $url ] ?? array( 200, '' );
					if ( $response instanceof WP_Error ) {
						return $response;
					}
					return array(
						'response' => array( 'code' => $response[0] ),
						'headers'  => array( 'location' => $response[1] ),
					);
				},
				'wp_remote_retrieve_response_code' => static function ( $response ) {
					return $response['response']['code'];
				},
				'wp_remote_retrieve_header'        => static function ( $response, $name ) {
					return $response['headers'][ $name ] ?? '';
				},
			)
		);
	}

	private function mail( string $message, string $type = 'text/html' ): Mail {
		return new Mail(
			array(
				'recipients'   => 'anna@gmail.com',
				'subject'      => 'Welcome',
				'message'      => $message,
				'content_type' => $type,
			),
			self::SITE
		);
	}

	public function test_only_page_links_to_the_own_site_are_candidates(): void {
		$html = '<a href="https://shop.example.de/old-page/">Old</a>'
			. '<a href="https://www.shop.example.de/about/#team">About (www, anchor)</a>'
			. '<a href="https://shop.example.de/old-page/">Same again</a>'
			. '<a href="https://shop.example.de/?p=12&amp;utm_source=mail">Plain permalink</a>'
			. '<a href="https://other.example.com/old-page/">Other site</a>'
			. '<a href="https://shop.example.de.evil.test/x/">Look-alike host</a>'
			. '<a href="https://shop.example.de:8443/x/">Other port</a>'
			. '<a href="https://shop.example.de/">Front page</a>'
			. '<a href="https://shop.example.de/wp-admin/post.php?post=1">Admin</a>'
			. '<a href="https://shop.example.de/wp-login.php?action=rp&amp;key=[redacted]">Reset</a>'
			. '<a href="https://shop.example.de/checkout/order-received/12/?key=wc_order_abc">Order</a>'
			. '<a href="https://shop.example.de/?add-to-cart=12">Add to cart</a>'
			. '<a href="https://shop.example.de/?action=unsubscribe&amp;nonce=abc">Unsubscribe</a>'
			. '<a href="https://shop.example.de/wp-content/uploads/2026/10/invoice.pdf">PDF</a>'
			. '<a href="https://shop.example.de/download/manual.zip">ZIP</a>'
			. '<a href="https://user:pw@shop.example.de/private/">Credentials</a>'
			. '<a href="https://shop.example.de/my-account/[redacted]/">Redacted</a>'
			. '<a href="#top">Anchor</a>'
			. '<a href="mailto:shop@example.de">Mail</a>'
			. '<img src="https://shop.example.de/gone.png" alt="">';

		$this->assertSame(
			array(
				'https://shop.example.de/old-page/' => '/old-page/',
				'https://shop.example.de/about/'    => '/about/',
				'https://shop.example.de/?p=12'     => '/?p=12',
			),
			Links::candidates( $this->mail( $html ) )
		);
	}

	public function test_plain_text_links_and_trailing_punctuation(): void {
		$text = 'Visit https://shop.example.de/welcome/. Or (https://shop.example.de/faq/) and https://shop.example.de/news.html, https://shop.example.de/feed/';
		$this->assertSame(
			array(
				'https://shop.example.de/welcome/'  => '/welcome/',
				'https://shop.example.de/faq/'      => '/faq/',
				'https://shop.example.de/news.html' => '/news.html',
			),
			Links::candidates( $this->mail( $text, 'text/plain' ) )
		);
	}

	public function test_at_most_ten_urls_per_mail(): void {
		$html = '';
		for ( $i = 1; $i <= 15; $i++ ) {
			$html .= '<a href="https://shop.example.de/page-' . $i . '/">' . $i . '</a>';
		}
		$this->assertCount( Links::MAX_URLS, Links::candidates( $this->mail( $html ) ) );
	}

	public function test_a_mail_without_own_links_makes_no_lookups(): void {
		Functions\expect( 'home_url' )->never();
		$this->assertSame( array(), Links::notes( $this->mail( '<p>No links at all.</p>' ) ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_dead_link_is_reported_and_live_ones_are_not(): void {
		$this->responses['https://shop.example.de/old-page/'] = array( 404, '' );
		$this->responses['https://shop.example.de/gone/']     = array( 410, '' );

		$notes = Links::notes( $this->mail( '<a href="https://shop.example.de/old-page/">x</a><a href="https://shop.example.de/new-page/">y</a><a href="https://shop.example.de/gone/">z</a>' ) );
		$this->assertSame(
			array(
				array(
					'code'     => 'dead_link',
					'severity' => 'warning',
					'params'   => array( '/old-page/' ),
				),
				array(
					'code'     => 'dead_link',
					'severity' => 'warning',
					'params'   => array( '/gone/' ),
				),
			),
			$notes
		);
		$this->assertSame( array( 'https://shop.example.de/old-page/', 'https://shop.example.de/new-page/', 'https://shop.example.de/gone/' ), $this->requests );
	}

	public function test_results_are_cached_per_url(): void {
		$this->responses['https://shop.example.de/old-page/'] = array( 404, '' );
		$mail = $this->mail( '<a href="https://shop.example.de/old-page/">x</a>' );
		Links::notes( $mail );
		Links::notes( $mail );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'dead', $this->transients[ Links::TRANSIENT_PREFIX . md5( 'https://shop.example.de/old-page/' ) ] );
	}

	public function test_published_posts_need_no_request_but_drafts_do(): void {
		$this->posts['https://shop.example.de/hello/']     = 12;
		$this->posts['https://shop.example.de/draft/']     = 99;
		$this->responses['https://shop.example.de/draft/'] = array( 404, '' );

		$this->assertSame( 'ok', Links::state( 'https://shop.example.de/hello/' ) );
		$this->assertSame( 'dead', Links::state( 'https://shop.example.de/draft/' ) );
		$this->assertSame( array( 'https://shop.example.de/draft/' ), $this->requests );
	}

	public function test_only_404_and_410_count_as_dead(): void {
		foreach ( array(
			200 => 'ok',
			204 => 'ok',
			401 => 'unknown',
			403 => 'unknown',
			405 => 'unknown',
			429 => 'unknown',
			500 => 'unknown',
			503 => 'unknown',
		) as $code => $state ) {
			$url                     = 'https://shop.example.de/status-' . $code . '/';
			$this->responses[ $url ] = array( $code, '' );
			$this->assertSame( $state, Links::state( $url ), (string) $code );
		}
	}

	public function test_redirects_are_followed_only_on_the_own_host(): void {
		$this->responses['https://shop.example.de/old/']         = array( 301, '/new/' );
		$this->responses['https://shop.example.de/new/']         = array( 302, 'https://www.shop.example.de/newer/' );
		$this->responses['https://www.shop.example.de/newer/']   = array( 404, '' );
		$this->responses['https://shop.example.de/moved/']       = array( 301, 'https://other.example.com/page/' );
		$this->responses['https://shop.example.de/loop/']        = array( 302, 'https://shop.example.de/loop/' );
		$this->responses['https://shop.example.de/no-location/'] = array( 302, '' );

		$this->assertSame( 'dead', Links::state( 'https://shop.example.de/old/' ) );
		$this->assertSame( 'ok', Links::state( 'https://shop.example.de/moved/' ) );
		$this->assertSame( 'unknown', Links::state( 'https://shop.example.de/loop/' ) );
		$this->assertSame( 'unknown', Links::state( 'https://shop.example.de/no-location/' ) );

		foreach ( $this->requests as $url ) {
			$this->assertMatchesRegularExpression( '#^https://(?:www\.)?shop\.example\.de/#', $url );
		}
	}

	public function test_a_failing_loopback_reports_nothing_and_pauses_the_checks(): void {
		$this->responses['https://shop.example.de/a/'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$notes = Links::notes( $this->mail( '<a href="https://shop.example.de/a/">a</a><a href="https://shop.example.de/b/">b</a>' ) );
		$this->assertSame( array(), $notes );
		$this->assertSame( array( 'https://shop.example.de/a/' ), $this->requests );
		$this->assertSame( 1, $this->transients[ Links::LOOPBACK_DOWN ] );
		$this->assertArrayNotHasKey( Links::TRANSIENT_PREFIX . md5( 'https://shop.example.de/a/' ), $this->transients );
	}

	public function test_urls_rejected_by_wordpress_are_never_requested(): void {
		Functions\when( 'wp_http_validate_url' )->justReturn( false );
		$this->assertSame( 'unknown', Links::state( 'https://shop.example.de/a/' ) );
		$this->assertSame( array(), $this->requests );
		$this->assertArrayNotHasKey( Links::LOOPBACK_DOWN, $this->transients );
	}

	public function test_no_requests_after_the_time_budget(): void {
		$this->assertSame( 'unknown', Links::state( 'https://shop.example.de/a/', microtime( true ) - 1 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_www_link_is_requested_on_the_canonical_host_and_ports_must_match(): void {
		Functions\when( 'home_url' )->justReturn( 'http://localhost:9411' );
		Functions\when( 'site_url' )->justReturn( 'http://localhost:9411' );

		$origins = Links::origins();
		$this->assertSame( array( 'localhost' => array( 'http://localhost:9411', 9411 ) ), $origins );
		$this->assertSame( array( 'http://localhost:9411/x/', '/x/' ), Links::checkable( 'http://localhost:9411/x/', $origins ) );
		$this->assertNull( Links::checkable( 'http://localhost/x/', $origins ) );
		$this->assertNull( Links::checkable( 'http://localhost:8080/x/', $origins ) );

		$origins = array( 'shop.example.de' => array( 'https://shop.example.de', 0 ) );
		$this->assertSame( array( 'https://shop.example.de/x/', '/x/' ), Links::checkable( 'http://www.shop.example.de:443/x/', $origins ) );
	}

	public function test_dynamic_notes_include_dead_links_unless_ignored(): void {
		$this->responses['https://shop.example.de/old-page/'] = array( 404, '' );
		$row = array(
			'id'           => 3,
			'created_at'   => '2026-10-01 12:00:00',
			'recipients'   => '',
			'subject'      => 'Welcome',
			'message'      => '<a href="https://shop.example.de/old-page/">Your account</a>',
			'content_type' => 'text/html',
			'source'       => 'core',
		);
		$this->assertSame( array( 'dead_link' ), array_column( Dynamic::notes( $row ), 'code' ) );

		$this->settings   = array( 'notes_ignore' => 'dead_link' );
		$this->requests   = array();
		$this->transients = array();
		$this->assertSame( array(), Dynamic::notes( $row ) );
		$this->assertSame( array(), $this->requests );
	}
}
