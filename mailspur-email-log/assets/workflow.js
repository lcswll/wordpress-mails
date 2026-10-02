/**
 * Mailspur – Workflow module (log tab): source filter options, CSV/JSON export of the current
 * filter, and the "anonymised" badge / dialog note.
 *
 * Uses window.mailspur (admin.js). Text only via textContent.
 */
( function () {
	'use strict';

	const m = window.mailspur;
	const cfg = window.mailspurWorkflow;
	const app = document.getElementById( 'mailspur-app' );
	if ( ! m || ! cfg || ! app ) {
		return;
	}
	const t = cfg.i18n;

	/* --------------------------------------------------------- source filter */

	const select = document.getElementById( 'mailspur-source' );

	async function loadSources() {
		let res;
		try {
			res = await m.api( 'sources' );
		} catch ( err ) {
			return; // The select keeps "All sources" plus the active value (admin.js).
		}
		const current = m.state().source;
		const options = res.sources
			.filter( ( s ) => '' !== s.value )
			.map( ( s ) => {
				const option = m.node( 'option', '', s.label + ' (' + m.num( s.count ) + ')' );
				option.value = s.value;
				option.title = s.value;
				return option;
			} );
		if ( current && ! res.sources.some( ( s ) => s.value === current ) ) {
			const option = m.node( 'option', '', current );
			option.value = current;
			options.push( option );
		}
		select.replaceChildren( select.options[ 0 ], ...options );
		select.value = current;
	}

	/* ---------------------------------------------------------------- export */

	const footer = app.querySelector( '.mailspur-footer' );
	const group = m.node( 'span', 'mailspur-export' );
	group.setAttribute( 'role', 'group' );
	group.setAttribute( 'aria-label', t.export );
	group.append( m.node( 'span', 'mailspur-export-label', t.export ) );

	[ [ 'csv', t.exportCsv ], [ 'json', t.exportJson ] ].forEach( ( [ format, label ] ) => {
		const button = m.node( 'button', 'button', label );
		button.type = 'button';
		button.dataset.export = format;
		button.title = m.fmt( t.exportTitle, label );
		group.append( button );
	} );

	const bodiesLabel = m.node( 'label', 'mailspur-check' );
	const bodies = m.node( 'input' );
	bodies.type = 'checkbox';
	bodies.id = 'mailspur-export-bodies';
	bodiesLabel.append( bodies, ' ', t.exportBodies );
	group.append( bodiesLabel );

	footer.insertBefore( group, footer.querySelector( '.mailspur-per-page' ) );

	/** Posts the current filters to admin-post.php; the response is a download, the page stays. */
	function exportFile( format ) {
		const state = m.state();
		const fields = {
			action: cfg.exportAction,
			_wpnonce: cfg.exportNonce,
			file: format,
			bodies: bodies.checked ? '1' : '',
		};
		cfg.exportKeys.forEach( ( key ) => {
			const value = state[ key ];
			fields[ key ] = true === value ? '1' : false === value || undefined === value || null === value ? '' : String( value );
		} );

		const form = m.node( 'form' );
		form.method = 'post';
		form.action = cfg.exportUrl;
		form.hidden = true;
		Object.entries( fields ).forEach( ( [ name, value ] ) => {
			const input = m.node( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.append( input );
		} );
		document.body.append( form );
		form.submit();
		form.remove();
		m.toast( t.exportStarted );
	}

	group.addEventListener( 'click', ( e ) => {
		const button = e.target.closest( '[data-export]' );
		if ( button ) {
			exportFile( button.dataset.export );
		}
	} );

	/* ----------------------------------------------------------- anonymised */

	m.registerRowDecorator( ( tr, item ) => {
		if ( ! item.anonymised ) {
			return;
		}
		tr.classList.add( 'is-anonymised' );
		const badge = m.node( 'span', 'mailspur-anon-badge', t.anonymised );
		badge.title = t.anonymisedTip;
		const open = tr.querySelector( '.col-subject .mailspur-open' );
		if ( open ) {
			open.after( badge );
		}
		// Nothing left to send.
		const resend = tr.querySelector( '.col-actions [data-action="resend"]' );
		if ( resend ) {
			resend.remove();
		}
	} );

	m.registerDetail( ( dl, mail ) => {
		const marker = mail.meta && mail.meta.anonymised;
		const resend = document.querySelector( '#mailspur-dialog .mailspur-d-actions [data-action="resend"]' );
		if ( resend ) {
			resend.hidden = !! marker;
		}
		if ( ! marker ) {
			return;
		}
		const text = marker.days ? m.fmt( t.removedAfter, m.num( marker.days ) ) : t.removed;
		dl.append( m.node( 'dt', 'is-anonymised', t.privacy ), m.node( 'dd', 'is-anonymised', text + ' – ' + t.anonymisedTip ) );
	} );

	loadSources();
}() );
