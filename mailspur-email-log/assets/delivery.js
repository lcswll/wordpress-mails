/**
 * Mailspur – delivery module: "Send to…" / "Send now" dialog actions, staging details, sender check UI
 * and the dismissible staging suggestion. Dependency-free; text only via textContent.
 */
( function () {
	'use strict';

	const cfg = window.mailspurDelivery;
	if ( ! cfg ) {
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

	/** JSON request; the body never goes into the URL (addresses are personal data). */
	async function request( path, method, body ) {
		const res = await fetch( endpoint( path ), {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json', 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
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

	/* ------------------------------------------------- staging suggestion */

	document.addEventListener( 'click', ( e ) => {
		if ( e.target.closest( '[data-mailspur-hint] .notice-dismiss' ) ) {
			request( 'delivery/hint', 'POST' ).catch( () => {} );
		}
	} );

	/* ------------------------------------------------------- log dialog */

	const m = window.mailspur;
	if ( m ) {
		const delivery = ( mail ) => ( mail && mail.meta && mail.meta.delivery ) || null;

		m.registerDetail( ( dl, mail ) => {
			const d = delivery( mail );
			if ( ! d ) {
				return;
			}
			const add = ( label, value ) => {
				if ( value ) {
					dl.append( node( 'dt', 'mailspur-delivery-dt', label ), node( 'dd', 'mailspur-delivery-dd', value ) );
				}
			};
			const list = ( value ) => ( Array.isArray( value ) ? value.join( ', ' ) : '' );
			add( t.originalTo, list( d.original_to ) );
			add( t.originalCc, list( d.original_cc ) );
			add( t.originalBcc, list( d.original_bcc ) );
			if ( 'no_redirect_address' === d.held ) {
				add( t.staging, t.heldNoAddress );
			} else if ( d.held ) {
				add( t.staging, t.heldStaging );
			} else if ( d.redirected ) {
				add( t.staging, t.redirected );
			}
			if ( d.released_from ) {
				add( t.staging, fmt( t.releasedFrom, d.released_from ) );
			}
		} );

		if ( cfg.isAdmin ) {
			m.registerAction( {
				id: 'delivery-send-to',
				label: t.sendTo,
				icon: 'email-alt',
				run: async ( mail ) => {
					const input = window.prompt( t.sendToPrompt, '' );
					if ( null === input ) {
						return;
					}
					const to = input.split( /[\s,;]+/ ).filter( Boolean );
					if ( ! to.length || to.length > 10 || to.some( ( a ) => ! /^[^\s@]+@[^\s@]+$/.test( a ) ) ) {
						m.toast( t.invalidAddress, true );
						return;
					}
					try {
						const res = await request( 'mails/' + mail.id + '/resend', 'POST', { to } );
						m.toast( res.sent ? fmt( t.sentTo, ( res.to || to ).join( ', ' ) ) : t.sendFailed, ! res.sent );
						m.reload();
					} catch ( err ) {
						m.toast( fmt( t.requestFailed, err.message ), true );
					}
				},
			} );

			m.registerAction( {
				id: 'delivery-release',
				label: t.release,
				icon: 'unlock',
				visible: ( mail ) => 'held' === mail.status,
				run: async ( mail ) => {
					if ( ! window.confirm( fmt( t.confirmRelease, mail.to ) ) ) {
						return;
					}
					try {
						const res = await request( 'mails/' + mail.id + '/release', 'POST' );
						m.toast( res.sent ? t.released : t.sendFailed, ! res.sent );
						m.reload();
					} catch ( err ) {
						m.toast( fmt( t.requestFailed, err.message ), true );
					}
				},
			} );
		}
	}

	/* ------------------------------------------------------ sender check */

	const box = document.getElementById( 'mailspur-delivery-check' );
	if ( ! box ) {
		return;
	}
	const button = document.getElementById( 'mailspur-delivery-run' );
	const state = document.getElementById( 'mailspur-delivery-state' );
	const results = document.getElementById( 'mailspur-delivery-results' );
	const selector = document.getElementById( 'mailspur-dkim-selector' );
	const STATUS = { ok: t.statusOk, warn: t.statusWarn, bad: t.statusBad, unknown: t.statusUnknown };

	function render( data ) {
		results.replaceChildren();
		if ( ! data ) {
			return;
		}
		button.textContent = t.checkAgain;
		if ( ! data.available ) {
			results.append( node( 'p', 'mailspur-delivery-note is-bad', t.unavailable ) );
			return;
		}
		const when = new Date( data.checked_at * 1000 ).toLocaleString( document.documentElement.lang || undefined );
		state.textContent = fmt( t.checkedAt, when ) + ( data.cached ? ' ' + t.cachedNote : '' );
		if ( data.timed_out ) {
			results.append( node( 'p', 'mailspur-delivery-note is-warn', t.timedOut ) );
		}
		if ( ! data.domains.length ) {
			results.append( node( 'p', 'mailspur-delivery-note', t.noDomains ) );
			return;
		}
		data.domains.forEach( ( domain ) => {
			const section = node( 'section', 'mailspur-delivery-domain' );
			section.dataset.domain = domain.domain;
			const head = node( 'h3', '', domain.domain );
			const seen = [];
			if ( domain.default ) {
				seen.push( t.defaultSender );
			}
			if ( domain.count ) {
				seen.push( fmt( t.seenInLog, domain.count ) );
			}
			if ( seen.length ) {
				head.append( ' ', node( 'span', 'mailspur-delivery-seen', seen.join( ' · ' ) ) );
			}
			section.append( head );

			const list = node( 'ul', 'mailspur-delivery-checks' );
			domain.checks.forEach( ( check ) => {
				const li = node( 'li', 'mailspur-delivery-item is-' + check.status );
				li.dataset.check = check.id;
				const light = node( 'span', 'mailspur-light is-' + check.status );
				light.setAttribute( 'role', 'img' );
				light.setAttribute( 'aria-label', STATUS[ check.status ] || check.status );
				light.title = STATUS[ check.status ] || check.status;
				const body = node( 'div', 'mailspur-delivery-body' );
				const title = node( 'p', 'mailspur-delivery-title' );
				title.append( node( 'strong', '', check.label ), ' ', node( 'span', 'mailspur-delivery-summary', check.summary ) );
				body.append( title );
				( check.notes || [] ).forEach( ( note ) => body.append( node( 'p', 'mailspur-delivery-hint', note ) ) );
				if ( check.record ) {
					body.append( node( 'p', 'mailspur-delivery-label', t.record ), node( 'pre', 'mailspur-delivery-record', check.record ) );
				}
				if ( check.suggestion ) {
					body.append(
						node( 'p', 'mailspur-delivery-label', fmt( t.suggestion, check.suggestion_host ) ),
						node( 'pre', 'mailspur-delivery-record is-suggestion', check.suggestion )
					);
				}
				li.append( light, body );
				list.append( li );
			} );
			section.append( list );
			results.append( section );
		} );
	}

	async function run( force ) {
		const value = selector.value.trim();
		if ( ! /^[A-Za-z0-9._-]*$/.test( value ) ) {
			state.textContent = t.invalidSelector;
			return;
		}
		button.disabled = true;
		box.setAttribute( 'aria-busy', 'true' );
		state.textContent = t.checking;
		try {
			render( await request( 'delivery/check', 'POST', { selector: value, force } ) );
		} catch ( err ) {
			state.textContent = fmt( t.requestFailed, err.message );
		} finally {
			button.disabled = false;
			box.setAttribute( 'aria-busy', 'false' );
		}
	}

	button.addEventListener( 'click', () => run( button.textContent === t.checkAgain ) );
	selector.addEventListener( 'keydown', ( e ) => {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			run( true );
		}
	} );

	// Show the last result (if still cached) without running new lookups.
	request( 'delivery/check' )
		.then( ( data ) => data && render( data ) )
		.catch( () => {} );
}() );
