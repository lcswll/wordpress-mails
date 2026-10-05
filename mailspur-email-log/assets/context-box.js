/**
 * Mailspur – context module: "Resend" in the "Emails" box of the WooCommerce order screen.
 * Uses the log's REST resend route (same checks, same logging). Dependency-free; text only via textContent.
 */
( function () {
	'use strict';

	const cfg = window.mailspurContextBox;
	if ( ! cfg ) {
		return;
	}
	const t = cfg.i18n;
	const fmt = ( str, ...args ) => {
		let i = 0;
		return str.replace( /%(\d\$)?s/g, ( _, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : i++ ] ) );
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

	async function resend( id ) {
		const res = await fetch( endpoint( 'mails/' + id + '/resend' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
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

	document.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '.mailspur-ctx .mailspur-ctx-resend' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();
		if ( ! window.confirm( fmt( t.confirmResend, btn.dataset.to || '' ) ) ) {
			return;
		}
		const box = btn.closest( '.mailspur-ctx' );
		const message = box.querySelector( '.mailspur-ctx-message' );
		btn.disabled = true;
		try {
			const res = await resend( parseInt( btn.dataset.id, 10 ) );
			message.textContent = res && res.sent ? t.resendOk : t.resendFail;
			message.classList.toggle( 'is-error', ! ( res && res.sent ) );
		} catch ( err ) {
			message.textContent = fmt( t.requestFailed, err.message );
			message.classList.add( 'is-error' );
		} finally {
			btn.disabled = false;
		}
	} );
}() );
