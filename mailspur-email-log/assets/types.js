/**
 * Mailspur – email types module (log tab).
 *
 * Dialog: an "Email type" line linking to the type on the "Email types" tab, with its rhythm.
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
}() );
