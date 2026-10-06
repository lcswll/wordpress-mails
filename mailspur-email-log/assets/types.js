/**
 * Mailspur – email types module (log tab).
 *
 * Dialog: an "Email type" line linking to the type on the "Email types" tab, with its rhythm, and a "Daily digest"
 * line for bundled emails (waiting, sent on their own or delivered in the digest).
 *
 * Uses the window.mailspur module API; all text is inserted via textContent.
 */
( function () {
	'use strict';

	const m = window.mailspur;
	const cfg = window.mailspurTypes;
	if ( ! m || ! cfg ) {
		return;
	}

	const t = cfg.i18n;

	m.registerDetail( ( dl, mail ) => {
		const type = mail.mailtype;
		if ( ! type || ! type.label ) {
			return;
		}
		const dd = m.node( 'dd', 'mailspur-type-dd' );
		const link = m.node( 'a', '', type.label );
		link.href = type.url;
		dd.append( link );
		if ( 'silent' === type.state ) {
			dd.append( ' ', m.node( 'span', 'mst-state is-silent', t.stopped ) );
		}
		dd.append( m.node( 'span', 'mailspur-type-rhythm', m.fmt( t.rhythm, type.rhythm ) ) );
		dl.append( m.node( 'dt', '', t.type ), dd );
	} );

	m.registerDetail( ( dl, mail ) => {
		const d = mail.meta && mail.meta.delivery;
		if ( ! d || ! d.bundle ) {
			return;
		}
		let text = '';
		if ( 'bundled' === d.held ) {
			text = t.bundled;
		} else if ( 'bundle_released' === d.held ) {
			text = t.bundleReleased;
		} else if ( d.digest ) {
			text = m.fmt( t.digested, new Date( d.digest * 1000 ).toLocaleString( document.documentElement.lang || undefined ) );
		}
		if ( text ) {
			dl.append( m.node( 'dt', '', t.digest ), m.node( 'dd', '', text ) );
		}
	} );
}() );
