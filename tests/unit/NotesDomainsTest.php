<?php
/**
 * Notes: typo detection for recipient domains (no false positives on real providers).
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Mailspur\Modules\Notes\Domains;

final class NotesDomainsTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function typos(): array {
		return array(
			'transposition gmial' => array( 'gmial.com', 'gmail.com' ),
			'transposition gamil' => array( 'gamil.com', 'gmail.com' ),
			'missing letter'      => array( 'gmai.com', 'gmail.com' ),
			'hotmial'             => array( 'hotmial.com', 'hotmail.com' ),
			'outlok'              => array( 'outlok.com', 'outlook.com' ),
			'yaho'                => array( 'yaho.com', 'yahoo.com' ),
			't-onlne'             => array( 't-onlne.de', 't-online.de' ),
			'two typos long name' => array( 'googelmial.com', 'googlemail.com' ),
			'hotmial.de'          => array( 'hotmial.de', 'hotmail.de' ),
			'gmx.dee'             => array( 'gmx.dee', 'gmx.de' ),
			'web.dee'             => array( 'web.dee', 'web.de' ),
			'gmail.con'           => array( 'gmail.con', 'gmail.com' ),
			'hotmail.co'          => array( 'hotmail.co', 'hotmail.com' ),
			'gmail.de'            => array( 'gmail.de', 'gmail.com' ),
			'googlemial'          => array( 'googlemial.com', 'googlemail.com' ),
			'icloud.de'           => array( 'icloud.de', 'icloud.com' ),
			'freenett'            => array( 'freenet.dee', 'freenet.de' ),
			'case'                => array( 'GMIAL.COM', 'gmail.com' ),
		);
	}

	/**
	 * @dataProvider typos
	 */
	public function test_typos_are_found( string $domain, string $expected ): void {
		$this->assertSame( $expected, Domains::typo_of( $domain ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function real_domains(): array {
		$cases = array();
		foreach ( array_merge( Domains::PROVIDERS, array( 'example.com', 'online.de', 'gmx.at', 'mail.de', 'web.com', 'wed.de', 'yahoo.es', 'company.de', 'mail.company.de', 'hotmail.de', 'uni-hamburg.de', 'gmail', 'outlook.office365.com', 'live.nl' ) ) as $domain ) {
			$cases[ $domain ] = array( $domain );
		}
		return $cases;
	}

	/**
	 * @dataProvider real_domains
	 */
	public function test_real_domains_are_not_typos( string $domain ): void {
		$this->assertSame( '', Domains::typo_of( $domain ) );
	}

	public function test_distance_counts_transpositions_once(): void {
		$this->assertSame( 1, Domains::distance( 'gmial', 'gmail' ) );
		$this->assertSame( 0, Domains::distance( 'gmail', 'gmail' ) );
		$this->assertSame( 2, Domains::distance( 'tonlie', 't-online' ) );
		$this->assertSame( 3, Domains::distance( 'a', 'abcdef' ) ); // Early exit: limit + 1.
	}

	public function test_free_mailers_and_reserved_domains(): void {
		$this->assertTrue( Domains::is_free_mailer( 'GMX.de' ) );
		$this->assertFalse( Domains::is_free_mailer( 'example.de' ) );

		foreach ( array( 'localhost', 'example.com', 'shop.example.org', 'site.test', 'printer.local', '127.0.0.1', '[::1]', 'x.invalid', 'router.home.arpa' ) as $domain ) {
			$this->assertTrue( Domains::is_reserved( $domain ), $domain );
		}
		foreach ( array( 'gmail.com', 'example.de', 'testing.com', 'local.de' ) as $domain ) {
			$this->assertFalse( Domains::is_reserved( $domain ), $domain );
		}
	}
}
