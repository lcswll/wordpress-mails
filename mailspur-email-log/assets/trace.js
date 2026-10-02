/**
 * Mailspur – Trace module: "Trace" dialog tab and "Download .eml" action.
 *
 * Uses the window.mailspur API of admin.js. All data is inserted via textContent.
 */
( function () {
	'use strict';

	const m = window.mailspur;
	const cfg = window.mailspurTraceConfig;
	if ( ! m || ! cfg ) {
		return;
	}
	const t = cfg.i18n;
	const node = m.node;

	const MAILERS = { smtp: 'SMTP', mail: 'PHP mail()', sendmail: 'Sendmail', qmail: 'Qmail' };

	function ms( value ) {
		const n = Number( value ) || 0;
		return n.toLocaleString( document.documentElement.lang || undefined, { maximumFractionDigits: n < 10 ? 1 : 0 } );
	}

	function section( title, ...content ) {
		const s = node( 'section', 'mailspur-trace-section' );
		s.append( node( 'h3', 'mailspur-trace-heading', title ), ...content );
		return s;
	}

	/** Two-column table; rows with empty values are skipped. */
	function table( rows ) {
		const tbl = node( 'table', 'mailspur-trace-table' );
		const body = node( 'tbody' );
		rows.forEach( ( [ label, value ] ) => {
			if ( undefined === value || null === value || '' === value ) {
				return;
			}
			const tr = node( 'tr' );
			const th = node( 'th', '', label );
			th.scope = 'row';
			const td = node( 'td' );
			if ( value instanceof Node ) {
				td.append( value );
			} else {
				td.textContent = String( value );
			}
			tr.append( th, td );
			body.append( tr );
		} );
		tbl.append( body );
		return tbl;
	}

	function code( text ) {
		return node( 'code', '', text );
	}

	/* -------------------------------------------------------------- timeline */

	function timeline( trace ) {
		const tl = trace.timeline || {};
		const total = Number( trace.total_ms ) || 0;
		const hasMailer = undefined !== tl.phpmailer;
		const prepare = hasMailer ? Number( tl.phpmailer ) || 0 : 0;
		const segments = [];
		if ( hasMailer ) {
			segments.push( [ 'prepare', t.prepare, prepare ] );
		}
		segments.push( [ 'delivery', t.delivery, Math.max( 0, total - prepare ) ] );

		const bar = node( 'div', 'mailspur-trace-bar' );
		bar.setAttribute( 'aria-hidden', 'true' );
		segments.forEach( ( [ key, label, value ] ) => {
			const seg = node( 'span', 'mailspur-trace-seg is-' + key );
			// At least a sliver, so short phases stay visible.
			seg.style.flexGrow = String( Math.max( value, total * 0.02, 0.001 ) );
			seg.title = label + ': ' + ms( value ) + ' ms';
			bar.append( seg );
		} );

		const legend = node( 'ol', 'mailspur-trace-phases' );
		const phases = [ [ 'capture', t.phaseCapture, 0 ] ];
		if ( hasMailer ) {
			phases.push( [ 'phpmailer', t.phasePhpmailer, tl.phpmailer ] );
		}
		phases.push( [ 'result', t.phaseResult, tl.result ] );
		phases.forEach( ( [ key, label, value ] ) => {
			const li = node( 'li', 'is-' + key );
			li.append( node( 'span', 'mailspur-trace-at', '+' + ms( value ) + ' ms' ), ' ', label );
			legend.append( li );
		} );

		return section( t.timeline, node( 'p', 'mailspur-trace-total', m.fmt( t.total, ms( total ) ) ), bar, legend );
	}

	/* ------------------------------------------------------------- transport */

	function handlerName( h ) {
		const c = String( h.component || '' );
		const i = c.indexOf( ':' );
		if ( i > -1 ) {
			return c.slice( i + 1 );
		}
		if ( h.callback && '{closure}' !== h.callback ) {
			return h.callback;
		}
		return String( h.file || '' ).replace( /^…\//, '' ) || h.callback || '';
	}

	function transport( trace ) {
		const tr = trace.transport;
		if ( 'unknown' === trace.via && ! tr ) {
			return section( t.transport, node( 'p', 'mailspur-trace-note', t.viaUnknown ) );
		}
		if ( ! tr ) {
			return null;
		}
		if ( 'api' === tr.mailer ) {
			const handlers = tr.handlers || [];
			const first = handlers[ 0 ];
			const name = first ? handlerName( first ) : '';
			const p = node( 'p', 'mailspur-trace-api', name ? m.fmt( t.deliveredBy, name ) : t.deliveredUnknown );
			const rows = handlers.map( ( h ) => [ t.handler, code( [ h.callback, h.file ].filter( Boolean ).join( ' – ' ) ) ] );
			return section( t.transport, p, rows.length ? table( rows ) : '' );
		}
		const yesNo = ( v ) => ( v ? t.yes : t.no );
		const rows = [ [ t.mailer, MAILERS[ tr.mailer ] || tr.mailer ] ];
		if ( 'smtp' === tr.mailer ) {
			rows.push(
				[ t.host, tr.host ? code( tr.host ) : '' ],
				[ t.port, tr.port ],
				[ t.encryption, tr.secure ? tr.secure.toUpperCase() : t.none ],
				[ t.autoTls, yesNo( tr.auto_tls ) ],
				[ t.authentication, yesNo( tr.auth ) ],
				[ t.username, tr.user ]
			);
		}
		return section( t.transport, table( rows ) );
	}

	/* ---------------------------------------------------------------- origin */

	function origin( trace ) {
		const o = trace.origin;
		const hooks = trace.hooks || [];
		if ( ! o && ! hooks.length ) {
			return null;
		}
		let hookList = '';
		if ( hooks.length ) {
			hookList = node( 'ol', 'mailspur-trace-hooks' );
			hooks.forEach( ( h ) => hookList.append( node( 'li', '', h ) ) );
		}
		return section(
			t.origin,
			table( [
				[ t.file, o ? code( o.file + ( o.line ? ':' + o.line : '' ) ) : '' ],
				[ t.function, o && o.function ? code( o.function + '()' ) : '' ],
				[ t.component, o ? o.component : '' ],
				[ t.hooks, hookList ],
			] )
		);
	}

	/* --------------------------------------------------------------- request */

	function request( trace ) {
		const r = trace.request;
		if ( ! r ) {
			return null;
		}
		const user = r.user_id ? r.user_login + ' (#' + r.user_id + ')' : '';
		return section(
			t.request,
			table( [
				[ t.type, t.types[ r.type ] || r.type ],
				[ t.method, r.method ],
				[ t.path, r.path ? code( r.path ) : '' ],
				[ t.route, r.route ? code( r.route ) : '' ],
				[ t.user, user ],
			] )
		);
	}

	/* ------------------------------------------------------------ transcript */

	async function copy( text, pre ) {
		try {
			await navigator.clipboard.writeText( text );
			m.toast( t.copied );
		} catch ( e ) {
			// No clipboard permission: select the text so Ctrl+C works.
			const range = document.createRange();
			range.selectNodeContents( pre );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
		}
	}

	function transcript( trace ) {
		if ( trace.transcript ) {
			const pre = node( 'pre', 'mailspur-pre mailspur-trace-transcript', trace.transcript );
			const btn = node( 'button', 'button button-small mailspur-trace-copy' );
			btn.type = 'button';
			const icon = node( 'span', 'dashicons dashicons-clipboard' );
			icon.setAttribute( 'aria-hidden', 'true' );
			btn.append( icon, ' ', t.copy );
			btn.addEventListener( 'click', () => copy( trace.transcript, pre ) );
			return section( t.transcript, btn, pre );
		}
		const state = trace.transcript_status;
		if ( ! state && trace.transport && 'smtp' !== trace.transport.mailer ) {
			return null; // Not an SMTP mail – nothing to say.
		}
		return section( t.transcript, node( 'p', 'mailspur-trace-note', t.transcriptStates[ state ] || t.transcriptStates.none ) );
	}

	/* ------------------------------------------------------------------- tab */

	function render( container, mail ) {
		const wrap = node( 'div', 'mailspur-trace' );
		const trace = mail.meta && mail.meta.trace;
		const imported = 0 === String( mail.source || '' ).indexOf( 'import:' );

		if ( ! trace || 'object' !== typeof trace || Array.isArray( trace ) ) {
			wrap.append( node( 'p', 'mailspur-trace-empty', imported ? t.notImported : t.notAvailable ) );
		} else {
			[ timeline( trace ), transport( trace ), origin( trace ), request( trace ), transcript( trace ) ]
				.filter( Boolean )
				.forEach( ( s ) => wrap.append( s ) );
		}
		wrap.append( node( 'p', 'mailspur-trace-raw description', mail.trace_raw ? t.rawExact : t.rawReconstructed ) );
		container.append( wrap );
	}

	m.registerTab( { id: 'trace', label: t.tab, render } );

	/* -------------------------------------------------------------- download */

	async function download( mail ) {
		try {
			const res = await fetch( m.endpoint( 'mails/' + mail.id + '/eml' ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': m.cfg.nonce, Accept: 'message/rfc822' },
			} );
			if ( ! res.ok ) {
				let message = res.status + ' ' + res.statusText;
				try {
					message = ( await res.json() ).message || message;
				} catch ( e ) {
					// Not JSON.
				}
				throw new Error( message );
			}
			const blob = await res.blob();
			const url = URL.createObjectURL( new Blob( [ blob ], { type: 'message/rfc822' } ) );
			const a = node( 'a' );
			a.href = url;
			a.download = 'mailspur-' + Number( mail.id ) + '.eml';
			a.hidden = true;
			document.body.append( a );
			a.click();
			a.remove();
			setTimeout( () => URL.revokeObjectURL( url ), 10000 );
			m.toast( t.downloaded );
		} catch ( err ) {
			m.toast( m.fmt( m.t.requestFailed, err.message ), true );
		}
	}

	m.registerAction( { id: 'trace-eml', label: t.download, icon: 'download', run: download } );
}() );
