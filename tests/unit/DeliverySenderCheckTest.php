<?php
/**
 * Delivery module: sender check record parsing and evaluation (fixtures instead of live DNS).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Delivery\SenderCheck;

final class DeliverySenderCheckTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Resolver backed by a fixture zone: host => array( 'TXT' => strings, 'MX' => targets ).
	 *
	 * @param array<string,array<string,array<int,string>>> $zone
	 * @param array<int,string>                             $log  Receives every looked-up "TYPE host".
	 */
	private function resolver( array $zone, array &$log = array() ): callable {
		return static function ( string $host, string $type ) use ( $zone, &$log ) {
			$log[] = $type . ' ' . $host;
			if ( 'fail.example' === $host ) {
				return null;
			}
			$out = array();
			foreach ( $zone[ $host ][ $type ] ?? array() as $i => $value ) {
				$out[] = 'MX' === $type
					? array(
						'target' => $value,
						'pri'    => 10 * ( $i + 1 ),
					)
					: array(
						'txt'     => $value,
						'entries' => str_split( $value, 255 ),
					);
			}
			return $out;
		};
	}

	/**
	 * @param array<int,array<string,mixed>> $checks
	 * @return array<string,array<string,mixed>>
	 */
	private static function by_id( array $checks ): array {
		return array_column( $checks, null, 'id' );
	}

	public function test_spf_missing_suggests_a_starter_record(): void {
		$check = SenderCheck::evaluate_spf( array(), 0, 'example.com' );
		$this->assertSame( 'bad', $check['status'] );
		$this->assertSame( 'v=spf1 a mx ~all', $check['suggestion'] );
		$this->assertSame( 'example.com', $check['suggestion_host'] );
	}

	public function test_spf_multiple_records_are_an_error(): void {
		$check = SenderCheck::evaluate_spf( array( 'v=spf1 mx -all', 'v=spf1 include:_spf.google.com ~all' ), 2, 'example.com' );
		$this->assertSame( 'bad', $check['status'] );
		$this->assertStringContainsString( 'Multiple SPF records', $check['summary'] );
		$this->assertSame( "v=spf1 mx -all\nv=spf1 include:_spf.google.com ~all", $check['record'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function all_qualifiers(): array {
		return array(
			'fail'      => array( 'v=spf1 mx -all', 'ok', '' ),
			'softfail'  => array( 'v=spf1 mx ~all', 'ok', '' ),
			'neutral'   => array( 'v=spf1 mx ?all', 'warn', 'v=spf1 mx ~all' ),
			'pass'      => array( 'v=spf1 mx +all', 'bad', 'v=spf1 mx ~all' ),
			'implicit'  => array( 'v=spf1 mx all', 'bad', 'v=spf1 mx ~all' ),
			'no all'    => array( 'v=spf1 mx', 'warn', 'v=spf1 mx ~all' ),
			'redirect'  => array( 'v=spf1 redirect=_spf.example.net', 'ok', '' ),
			'uppercase' => array( 'V=SPF1 MX -ALL', 'ok', '' ),
		);
	}

	/**
	 * @dataProvider all_qualifiers
	 */
	public function test_spf_all_qualifier( string $record, string $status, string $suggestion ): void {
		$check = SenderCheck::evaluate_spf( array( $record ), 1, 'example.com' );
		$this->assertSame( $status, $check['status'], $check['summary'] );
		$this->assertSame( $suggestion, $check['suggestion'] );
		$this->assertSame( $record, $check['record'] );
	}

	public function test_spf_too_many_lookups_is_an_error(): void {
		$check = SenderCheck::evaluate_spf( array( 'v=spf1 include:a.example -all' ), 12, 'example.com' );
		$this->assertSame( 'bad', $check['status'] );
		$this->assertStringContainsString( 'About 12 DNS lookups', implode( ' ', $check['notes'] ) );
	}

	public function test_spf_lookup_count_follows_includes(): void {
		$check = new SenderCheck(
			$this->resolver(
				array(
					'a.example' => array( 'TXT' => array( 'v=spf1 include:b.example ip4:192.0.2.1 mx ~all' ) ),
					'b.example' => array( 'TXT' => array( 'v=spf1 a a:mail.b.example exists:%{i}.x.example include:a.example -all' ) ),
				)
			)
		);
		// Own: include(1) + a(1) + mx(1) + ptr(1) + redirect-free; a.example: include(1) + mx(1); b.example: a, a:, exists, include(4).
		$this->assertSame( 4 + 2 + 4, $check->spf_lookups( 'v=spf1 include:a.example a mx ptr ip6:2001:db8::/32 ~all', array( 'example.com' ) ) );
	}

	public function test_spf_lookup_count_stops_on_loops(): void {
		$log   = array();
		$check = new SenderCheck(
			$this->resolver(
				array(
					'loop.example' => array( 'TXT' => array( 'v=spf1 include:loop.example -all' ) ),
				),
				$log
			)
		);
		$this->assertSame( 2, $check->spf_lookups( 'v=spf1 include:loop.example -all', array( 'example.com' ) ) );
		$this->assertCount( 1, $log );
	}

	public function test_dmarc_missing_suggests_a_monitoring_record(): void {
		$check = SenderCheck::evaluate_dmarc( array( 'google-site-verification=abc' ), 'example.com' );
		$this->assertSame( 'bad', $check['status'] );
		$this->assertSame( 'v=DMARC1; p=none; rua=mailto:dmarc-reports@example.com', $check['suggestion'] );
		$this->assertSame( '_dmarc.example.com', $check['suggestion_host'] );
	}

	public function test_dmarc_report_address_uses_the_admin_email_of_the_same_domain(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				return 'admin_email' === $name ? 'owner@example.com' : $fallback;
			}
		);
		$check = SenderCheck::evaluate_dmarc( array(), 'example.com' );
		$this->assertSame( 'v=DMARC1; p=none; rua=mailto:owner@example.com', $check['suggestion'] );
	}

	public function test_dmarc_policies(): void {
		$none = SenderCheck::evaluate_dmarc( array( 'v=DMARC1; p=none; rua=mailto:d@example.com' ), 'example.com' );
		$this->assertSame( 'warn', $none['status'] );
		$this->assertSame( 'v=DMARC1; p=quarantine; rua=mailto:d@example.com', $none['suggestion'] );
		$this->assertStringContainsString( 'mailto:d@example.com', implode( ' ', $none['notes'] ) );

		$reject = SenderCheck::evaluate_dmarc( array( 'v=DMARC1;p=reject;rua=mailto:d@example.com' ), 'example.com' );
		$this->assertSame( 'ok', $reject['status'] );
		$this->assertSame( '', $reject['suggestion'] );

		$partial = SenderCheck::evaluate_dmarc( array( 'v=DMARC1; p=quarantine; pct=25' ), 'example.com' );
		$this->assertSame( 'warn', $partial['status'] );
		$notes = implode( ' ', $partial['notes'] );
		$this->assertStringContainsString( '25%', $notes );
		$this->assertStringContainsString( 'No report address', $notes );

		$invalid = SenderCheck::evaluate_dmarc( array( 'v=DMARC1; p=maybe' ), 'example.com' );
		$this->assertSame( 'bad', $invalid['status'] );

		$double = SenderCheck::evaluate_dmarc( array( 'v=DMARC1; p=none', 'v=DMARC1; p=reject' ), 'example.com' );
		$this->assertSame( 'bad', $double['status'] );
	}

	public function test_failed_lookups_are_unknown(): void {
		$this->assertSame( 'unknown', SenderCheck::evaluate_spf( null, 0, 'example.com' )['status'] );
		$this->assertSame( 'unknown', SenderCheck::evaluate_dmarc( null, 'example.com' )['status'] );
		$this->assertSame( 'unknown', SenderCheck::evaluate_mx( null )['status'] );
	}

	public function test_dkim(): void {
		$found = SenderCheck::evaluate_dkim( array( 'google' => 'v=DKIM1; k=rsa; p=MIIBIjANBg' ), false );
		$this->assertSame( 'ok', $found['status'] );
		$this->assertStringContainsString( 'google', $found['summary'] );

		$revoked = SenderCheck::evaluate_dkim( array( 'old' => 'v=DKIM1; p=' ), false, 'mysel' );
		$this->assertSame( 'warn', $revoked['status'] );
		$notes = implode( ' ', $revoked['notes'] );
		$this->assertStringContainsString( 'Revoked keys (empty p=): old', $notes );
		$this->assertStringContainsString( '"mysel"', $notes );

		$this->assertSame( 'unknown', SenderCheck::evaluate_dkim( array(), true )['status'] );
	}

	public function test_mx(): void {
		$this->assertSame( 'warn', SenderCheck::evaluate_mx( array() )['status'] );
		$check = SenderCheck::evaluate_mx(
			array(
				array(
					'target' => 'mx2.example.com',
					'pri'    => 20,
				),
				array(
					'target' => 'mx1.example.com',
					'pri'    => 10,
				),
			)
		);
		$this->assertSame( 'ok', $check['status'] );
		$this->assertSame( "10 mx1.example.com\n20 mx2.example.com", $check['record'] );
	}

	public function test_check_domain_with_fixture_zone(): void {
		$log    = array();
		$long   = 'v=DKIM1; k=rsa; p=' . str_repeat( 'A', 300 );
		$checks = ( new SenderCheck(
			$this->resolver(
				array(
					'example.com'                      => array(
						'TXT' => array( 'google-site-verification=x', 'v=spf1 include:_spf.example.net ~all' ),
						'MX'  => array( 'mx.example.com' ),
					),
					'_spf.example.net'                 => array( 'TXT' => array( 'v=spf1 ip4:192.0.2.0/24 -all' ) ),
					'_dmarc.example.com'               => array( 'TXT' => array( 'v=DMARC1; p=reject; rua=mailto:d@example.com' ) ),
					'custom._domainkey.example.com'    => array( 'TXT' => array( $long ) ),
					'selector1._domainkey.example.com' => array( 'TXT' => array( 'not a key' ) ),
				),
				$log
			)
		) )->check_domain( 'example.com', 'custom' );

		$checks = self::by_id( $checks );
		$this->assertSame( array( 'spf', 'dmarc', 'dkim', 'mx' ), array_keys( $checks ) );
		$this->assertSame( 'ok', $checks['spf']['status'] );
		$this->assertStringContainsString( 'about 1 of 10', implode( ' ', $checks['spf']['notes'] ) );
		$this->assertSame( 'ok', $checks['dmarc']['status'] );
		$this->assertSame( 'ok', $checks['dkim']['status'] );
		$this->assertStringContainsString( 'custom', $checks['dkim']['summary'] );
		// Long keys arrive in 255-byte chunks and are shown shortened.
		$this->assertStringEndsWith( '…', $checks['dkim']['record'] );
		$this->assertSame( 'ok', $checks['mx']['status'] );
		// The user's selector is tried first, then the common ones.
		$this->assertContains( 'TXT custom._domainkey.example.com', $log );
		$this->assertContains( 'TXT amazonses._domainkey.example.com', $log );
	}

	public function test_lookup_failure_is_reported_per_check(): void {
		$checks = self::by_id( ( new SenderCheck( $this->resolver( array() ) ) )->check_domain( 'fail.example' ) );
		$this->assertSame( 'unknown', $checks['spf']['status'] );
		$this->assertSame( 'unknown', $checks['mx']['status'] );
		$this->assertSame( 'warn', $checks['dkim']['status'] );
	}

	public function test_domain_parsing(): void {
		$this->assertSame( 'example.com', SenderCheck::domain_of( 'Shop <Info@Example.COM>' ) );
		$this->assertSame( 'mail.example.co.uk', SenderCheck::domain_of( 'x@mail.example.co.uk.' ) );
		$this->assertSame( 'xn--mller-kva.de', SenderCheck::domain_of( 'a@xn--mller-kva.de' ) );
		$this->assertSame( '', SenderCheck::domain_of( 'wordpress@127.0.0.1' ) );
		$this->assertSame( '', SenderCheck::domain_of( 'wordpress@localhost' ) );
		$this->assertSame( '', SenderCheck::domain_of( 'no address' ) );
		$this->assertSame( '', SenderCheck::domain_of( 'a@exa mple.com' ) );
	}

	public function test_tags(): void {
		$this->assertSame(
			array(
				'v'   => 'DMARC1',
				'p'   => 'none',
				'rua' => 'mailto:a@b.c',
			),
			SenderCheck::tags( 'v=DMARC1; P = none ;rua=mailto:a@b.c;' )
		);
	}

	public function test_domains_combine_default_sender_and_log(): void {
		Functions\when( 'network_home_url' )->justReturn( 'https://www.shop.example.com/' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->wpdb->results[] = array(
			array( 'sender' => 'Shop <shop@shop.example.com>' ),
			array( 'sender' => 'news@news.example.org' ),
			array( 'sender' => 'news@news.example.org' ),
			array( 'sender' => '' ),
			array( 'sender' => 'x@localhost' ),
		);

		$domains = ( new SenderCheck( $this->resolver( array() ) ) )->domains();

		$this->assertSame( array( 'shop.example.com', 'news.example.org' ), array_keys( $domains ) );
		$this->assertTrue( $domains['shop.example.com']['default'] );
		$this->assertSame( 1, $domains['shop.example.com']['count'] );
		$this->assertFalse( $domains['news.example.org']['default'] );
		$this->assertSame( 2, $domains['news.example.org']['count'] );
		$this->assertStringContainsString( 'LIMIT %d', $this->wpdb->prepared[0]['sql'] );
		$this->assertSame( 200, $this->wpdb->prepared[0]['args'][1] );
	}

	public function test_run_caches_for_an_hour(): void {
		$stored = array();
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$stored ) {
				return $stored[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl ) use ( &$stored ): bool {
				$stored[ $key ] = $value;
				return HOUR_IN_SECONDS === $ttl;
			}
		);

		$log     = array();
		$check   = new SenderCheck( $this->resolver( array( 'example.com' => array( 'MX' => array( 'mx.example.com' ) ) ), $log ) );
		$domains = array(
			'example.com' => array(
				'default' => true,
				'count'   => 0,
			),
		);

		$first = $check->run( $domains );
		$this->assertTrue( $first['available'] );
		$this->assertFalse( $first['cached'] );
		$this->assertSame( 'example.com', $first['domains'][0]['domain'] );
		$this->assertCount( 4, $first['domains'][0]['checks'] );
		$lookups = count( $log );

		$this->assertTrue( $check->run( $domains )['cached'] );
		$this->assertCount( $lookups, $log, 'cached result needs no lookups' );
		$this->assertNotNull( SenderCheck::cached( $domains ) );
		$this->assertNull( SenderCheck::cached( $domains, 'other' ) );

		$this->assertFalse( $check->run( $domains, '', true )['cached'] );
		$this->assertGreaterThan( $lookups, count( $log ) );
	}
}
