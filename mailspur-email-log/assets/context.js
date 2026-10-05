/**
 * Mailspur – context module (log tab).
 *
 * Dialog: "Belongs to" line with links to the order / user profile, and the WooCommerce email id.
 * Links from the order box and profile use the core deep link ?mail=<id> (see admin.js).
 *
 * Uses the window.mailspur module API; all text is inserted via textContent.
 */
( function () {
	'use strict';

	const m = window.mailspur;
	const cfg = window.mailspurContext;
	if ( ! m || ! cfg ) {
		return;
	}
	const t = cfg.i18n;

	m.registerDetail( ( dl, mail ) => {
		const ctx = mail.context;
		if ( ! ctx ) {
			return;
		}
		const links = ( ctx.links || [] ).filter( ( link ) => link && link.label );
		if ( links.length ) {
			const dd = m.node( 'dd', 'mailspur-ctx-links' );
			links.forEach( ( link, i ) => {
				if ( i ) {
					dd.append( ' · ' );
				}
				if ( link.url ) {
					const a = m.node( 'a', '', link.label );
					a.href = link.url;
					dd.append( a );
				} else {
					dd.append( link.label );
				}
			} );
			dl.append( m.node( 'dt', '', t.linked ), dd );
		}
		if ( ctx.wc_email ) {
			const dd = m.node( 'dd' );
			dd.append( m.node( 'code', '', ctx.wc_email ) );
			dl.append( m.node( 'dt', '', t.wcEmail ), dd );
		}
	} );
}() );
