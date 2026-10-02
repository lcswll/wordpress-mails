<?php
/**
 * Curated domain lists: popular mailbox providers (international + DACH) for typo detection and
 * free mailers whose DMARC policy breaks mails sent "as" them from another server.
 *
 * @package Mailspur
 */

namespace Mailspur\Modules\Notes;

defined( 'ABSPATH' ) || exit;

final class Domains {

	/** Popular mailbox domains. Exact matches are never typos. */
	const PROVIDERS = array(
		'gmail.com',
		'googlemail.com',
		'yahoo.com',
		'yahoo.de',
		'yahoo.co.uk',
		'yahoo.fr',
		'yahoo.es',
		'yahoo.it',
		'ymail.com',
		'rocketmail.com',
		'hotmail.com',
		'hotmail.de',
		'hotmail.co.uk',
		'hotmail.fr',
		'hotmail.it',
		'outlook.com',
		'outlook.de',
		'outlook.at',
		'live.com',
		'live.de',
		'live.at',
		'msn.com',
		'aol.com',
		'aol.de',
		'icloud.com',
		'me.com',
		'mac.com',
		'proton.me',
		'protonmail.com',
		'mail.com',
		'email.com',
		'zoho.com',
		'yandex.com',
		'yandex.ru',
		'mail.ru',
		'gmx.de',
		'gmx.net',
		'gmx.at',
		'gmx.ch',
		'gmx.com',
		'web.de',
		't-online.de',
		'freenet.de',
		'arcor.de',
		'posteo.de',
		'mailbox.org',
		'online.de',
		'email.de',
		'mail.de',
		'kabelmail.de',
		'vodafone.de',
		'ionos.de',
		'1und1.de',
		'magenta.de',
		'aon.at',
		'a1.net',
		'chello.at',
		'bluewin.ch',
		'hispeed.ch',
		'sunrise.ch',
		'orange.fr',
		'free.fr',
		'libero.it',
		'virgilio.it',
		'btinternet.com',
		'comcast.net',
		'verizon.net',
		'att.net',
	);

	/** Free mailers with a strict DMARC policy (or treated like one by the big inbox providers). */
	const FREE_MAILERS = array(
		'gmail.com',
		'googlemail.com',
		'yahoo.com',
		'yahoo.de',
		'yahoo.co.uk',
		'yahoo.fr',
		'ymail.com',
		'hotmail.com',
		'hotmail.de',
		'outlook.com',
		'outlook.de',
		'live.com',
		'live.de',
		'msn.com',
		'aol.com',
		'aol.de',
		'icloud.com',
		'me.com',
		'mac.com',
		'proton.me',
		'protonmail.com',
		'gmx.de',
		'gmx.net',
		'gmx.at',
		'gmx.ch',
		'web.de',
		't-online.de',
		'freenet.de',
		'mail.com',
		'mail.ru',
		'yandex.com',
		'yandex.ru',
		'posteo.de',
	);

	/** Providers whose mailboxes exist under exactly one domain (name => domain). */
	const SINGLE_DOMAIN = array(
		'gmail'      => 'gmail.com',
		'googlemail' => 'googlemail.com',
		'icloud'     => 'icloud.com',
		'protonmail' => 'protonmail.com',
		't-online'   => 't-online.de',
	);

	/** Top-level-domain typos that are not real TLDs (or not used for mail). */
	const TLD_TYPOS = array( 'con', 'cmo', 'cpm', 'comm', 'vom', 'xom', 'ocm', 'om', 'nte', 'ent', 'nett', 'dee', 'dde', 'ed', 'ded' );

	public static function is_free_mailer( string $domain ): bool {
		return in_array( strtolower( $domain ), self::FREE_MAILERS, true );
	}

	/**
	 * Popular provider the domain is probably a typo of, or '' (no typo, or not close enough).
	 */
	public static function typo_of( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		if ( '' === $domain || in_array( $domain, self::PROVIDERS, true ) ) {
			return '';
		}
		$parts = explode( '.', $domain, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		list( $name, $tld ) = $parts;

		// Providers that exist under one domain only: gmail.de, icloud.de, t-online.com …
		if ( isset( self::SINGLE_DOMAIN[ $name ] ) ) {
			return self::SINGLE_DOMAIN[ $name ];
		}

		foreach ( self::PROVIDERS as $provider ) {
			list( $p_name, $p_tld ) = explode( '.', $provider, 2 );

			// gmx.dee, web.dee, hotmail.con, yahoo.co (Colombia, practically always a typo of .com).
			if ( $name === $p_name && ( in_array( $tld, self::TLD_TYPOS, true ) || ( 'co' === $tld && 'com' === $p_tld ) ) ) {
				return $provider;
			}
		}

		foreach ( self::PROVIDERS as $provider ) {
			list( $p_name, $p_tld ) = explode( '.', $provider, 2 );

			// gmial.com, gamil.com, hotmial.com, outlok.com, yaho.com, t-onlne.de.
			if ( $tld === $p_tld ) {
				$shorter  = min( strlen( $name ), strlen( $p_name ) );
				$allowed  = $shorter >= 7 ? 2 : ( $shorter >= 4 ? 1 : 0 );
				$distance = self::distance( $name, $p_name, $allowed );
				if ( $allowed > 0 && $distance > 0 && $distance <= $allowed ) {
					return $provider;
				}
			}
		}
		return '';
	}

	/**
	 * Optimal string alignment distance (Levenshtein + adjacent transpositions count as 1),
	 * so "gmial" → "gmail" is one typo. Returns $limit + 1 early when the lengths differ too much.
	 */
	public static function distance( string $a, string $b, int $limit = 2 ): int {
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( abs( $la - $lb ) > $limit ) {
			return $limit + 1;
		}
		$d = array();
		for ( $i = 0; $i <= $la; $i++ ) {
			$d[ $i ][0] = $i;
		}
		for ( $j = 0; $j <= $lb; $j++ ) {
			$d[0][ $j ] = $j;
		}
		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost          = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
				}
			}
		}
		return $d[ $la ][ $lb ];
	}

	/**
	 * Reserved / non-public domains (RFC 2606, RFC 6761, .local): never looked up, never flagged as typos.
	 */
	public static function is_reserved( string $domain ): bool {
		$domain = strtolower( $domain );
		if ( '' === $domain || false === strpos( $domain, '.' ) || preg_match( '/^\[?[\d.:]+\]?$/', $domain ) ) {
			return true;
		}
		return 1 === preg_match( '/(?:^|\.)example\.(?:com|org|net)$|\.(?:test|example|invalid|localhost|local|lan|internal|home\.arpa)$/', $domain );
	}
}
