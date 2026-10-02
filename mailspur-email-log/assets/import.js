/**
 * Mailspur – import from other mail logging plugins (Settings tab).
 *
 * Imports in batches (one REST call each) until the source reports done, so large logs never hit
 * PHP time limits. Text is only inserted via textContent.
 */
( function () {
	'use strict';

	const cfg = window.mailspurImportConfig;
	const table = document.querySelector( '.mailspur-import' );
	if ( ! cfg || ! table ) {
		return;
	}

	const t = cfg.i18n;
	const fmt = ( str, ...args ) => {
		let i = 0;
		return str.replace( /%(\d\$)?s/g, ( _, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : i++ ] ) );
	};
	const num = ( n ) => Number( n ).toLocaleString( document.documentElement.lang || undefined );

	function endpoint( path ) {
		const url = new URL( cfg.restUrl, location.href );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/?$/, '/' ) + path;
		}
		return url.toString();
	}

	async function api( path, method ) {
		const res = await fetch( endpoint( path ), {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
		} );
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			// Non-JSON error page.
		}
		if ( ! res.ok ) {
			throw new Error( ( body && body.message ) || res.status + ' ' + res.statusText );
		}
		return body;
	}

	async function runImport( row ) {
		const status = row.querySelector( '.mailspur-import-status' );
		const buttons = row.querySelectorAll( 'button' );
		const total = parseInt( row.dataset.total, 10 ) || 0;
		const startRemaining = parseInt( row.dataset.remaining, 10 ) || 0;
		let imported = 0;
		let skipped = 0;
		let duplicates = 0;

		buttons.forEach( ( b ) => ( b.disabled = true ) );
		try {
			for ( ;; ) {
				const res = await api( 'import/' + row.dataset.source, 'POST' );
				imported += res.imported;
				skipped += res.skipped;
				duplicates += res.duplicates;
				status.textContent = fmt( t.progress, num( total - startRemaining + imported + skipped + duplicates ), num( total ) );
				if ( res.done || 0 === res.remaining ) {
					break;
				}
			}
			status.textContent = fmt( t.done, num( imported ), num( duplicates ), num( skipped ) );
			// Reload so buttons and counts reflect the new state.
			setTimeout( () => location.reload(), 1200 );
		} catch ( err ) {
			status.textContent = fmt( t.failed, err.message );
			buttons.forEach( ( b ) => ( b.disabled = false ) );
		}
	}

	async function undoImport( row ) {
		if ( ! window.confirm( fmt( t.confirmUndo, row.dataset.label ) ) ) {
			return;
		}
		const status = row.querySelector( '.mailspur-import-status' );
		try {
			const res = await api( 'import/' + row.dataset.source, 'DELETE' );
			status.textContent = fmt( t.undone, num( res.deleted ) );
			setTimeout( () => location.reload(), 1200 );
		} catch ( err ) {
			status.textContent = fmt( t.failed, err.message );
		}
	}

	table.addEventListener( 'click', ( e ) => {
		const button = e.target.closest( '[data-import-action]' );
		const row = button && button.closest( 'tr[data-source]' );
		if ( ! row ) {
			return;
		}
		if ( 'run' === button.dataset.importAction ) {
			runImport( row );
		} else if ( 'undo' === button.dataset.importAction ) {
			undoImport( row );
		}
	} );
}() );
