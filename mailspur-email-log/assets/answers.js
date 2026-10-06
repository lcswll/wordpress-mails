/**
 * Mailspur – "Overview" tab: the "Did my email arrive?" lookup.
 *
 * The other answers are server-rendered. This script reveals the form, sends the address or order number in the
 * body of POST /answers/arrived (never in a URL – addresses are personal data) and shows the answer with the
 * same markup as Page::render_answer(). Dependency-free; text only via textContent.
 */
( function () {
	'use strict';

	const cfg = window.mailspurAnswers;
	const form = document.getElementById( 'msa-arrived-form' );
	const input = document.getElementById( 'msa-query' );
	const output = document.getElementById( 'msa-arrived-answer' );
	if ( ! cfg || ! form || ! input || ! output ) {
		return;
	}
	const t = cfg.i18n;
	const fmt = ( str, ...args ) => {
		let i = 0;
		return str.replace( /%(\d\$)?s/g, ( _, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : i++ ] ) );
	};
	const node = ( tag, className, text ) => {
		const n = document.createElement( tag );
		if ( className ) {
			n.className = className;
		}
		if ( undefined !== text && null !== text ) {
			n.textContent = text;
		}
		return n;
	};
	const link = ( url, text, className ) => {
		const a = node( 'a', className, text );
		a.href = url;
		return a;
	};

	function endpoint( path ) {
		const url = new URL( cfg.restUrl, location.href );
		// Sites without pretty permalinks use ?rest_route=/mailspur-email-log/v1.
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/?$/, '/' ) + path;
		}
		return url.toString();
	}

	/** Same markup as Page::render_answer(). */
	function render( answer ) {
		const box = node( 'div', 'msa-answer is-' + String( answer.tone || 'info' ).replace( /[^a-z]/g, '' ) );
		( answer.parts || [] ).forEach( ( part ) => {
			box.append( node( 'p', 'msa-text', part.text ) );
			if ( part.items && part.items.length ) {
				const list = node( 'ul', 'msa-items' );
				part.items.forEach( ( item ) => {
					const li = node( 'li' );
					if ( item.url && ! item.label ) {
						li.append( link( item.url, item.text ) );
					} else {
						li.append( document.createTextNode( item.text ) );
						if ( item.url ) {
							li.append( ' ', link( item.url, item.label, 'msa-item-link' ) );
						}
					}
					list.append( li );
				} );
				box.append( list );
			}
		} );
		if ( answer.link && answer.link.url ) {
			const more = node( 'p', 'msa-more' );
			more.append( link( answer.link.url, answer.link.label ) );
			box.append( more );
		}
		return box;
	}

	async function ask( query ) {
		const res = await fetch( endpoint( 'answers/arrived' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify( { q: query } ),
		} );
		let data = null;
		try {
			data = await res.json();
		} catch ( e ) {
			// Non-JSON response (PHP notice, proxy error page …).
		}
		if ( ! res.ok ) {
			throw new Error( ( data && data.message ) || res.status + ' ' + res.statusText );
		}
		return data;
	}

	form.hidden = false;
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const query = input.value.trim();
		if ( ! query ) {
			return;
		}
		const button = form.querySelector( 'button' );
		button.disabled = true;
		output.setAttribute( 'aria-busy', 'true' );
		output.replaceChildren( node( 'p', 'msa-loading', t.checking ) );
		try {
			output.replaceChildren( render( await ask( query ) ) );
		} catch ( e ) {
			output.replaceChildren( render( { tone: 'bad', parts: [ { text: fmt( t.requestFailed, e.message ), items: [] } ] } ) );
		} finally {
			button.disabled = false;
			output.removeAttribute( 'aria-busy' );
		}
	} );
} )();
