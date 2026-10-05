/**
 * Mailspur – "Email types" tab: the "Compare" view of a content change, "Send latest to me" and "Trigger to me"
 * (probe email for core types).
 *
 * The tab itself is server-rendered and works without JavaScript; this script only reveals and drives the two
 * actions that need it. Dependency-free; mail data only via textContent / attribute setters. HTML previews use
 * the same isolation as the log dialog (admin.js): an empty-origin sandbox without scripts, forms or same-origin
 * access, plus a CSP that blocks every remote resource.
 */
( function () {
	'use strict';

	const cfg = window.mailspurTypesTab;
	const root = document.getElementById( 'mailspur-types' );
	if ( ! cfg || ! root ) {
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
	const button = ( className, text ) => {
		const b = node( 'button', className, text );
		b.type = 'button';
		return b;
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

	/* --------------------------------------------------------------- preview */

	/** Same rules as the log dialog with remote content blocked (admin.js defuseRemote / previewDocument). */
	function previewDocument( html ) {
		const remote = '(?:https?:)?\\/\\/';
		html = html
			.replace( new RegExp( '(\\s)(src|srcset|background|poster)(\\s*=\\s*["\']?\\s*' + remote + ')', 'gi' ), '$1data-blocked-$2$3' )
			.replace( new RegExp( '(<link\\b[^>]*\\s)href(\\s*=\\s*["\']?\\s*' + remote + ')', 'gi' ), '$1data-blocked-href$2' )
			.replace( new RegExp( 'url\\(\\s*(["\']?)\\s*' + remote, 'gi' ), 'url($1data:,' )
			.replace( new RegExp( '@import\\s+(["\'])\\s*' + remote, 'gi' ), '@import $1data:,' );
		const csp = [ "default-src 'none'", "style-src 'unsafe-inline'", 'img-src data: cid:', 'font-src data:', "form-action 'none'" ].join( '; ' );
		return (
			'<!doctype html><html><head><meta charset="utf-8">' +
			'<meta http-equiv="Content-Security-Policy" content="' + csp + '">' +
			'<meta name="referrer" content="no-referrer">' +
			'<base target="_blank">' +
			'<style>html{color-scheme:light}body{margin:0;padding:16px;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;word-wrap:break-word}img{max-width:100%;height:auto}</style>' +
			'</head><body>' + html + '</body></html>'
		);
	}

	function preview( mail ) {
		if ( ! mail.isHtml ) {
			return node( 'pre', 'mst-pre', mail.message );
		}
		const frame = node( 'iframe', 'mst-frame' );
		// Unique opaque origin: no scripts, forms, storage or access to wp-admin.
		frame.setAttribute( 'sandbox', 'allow-popups allow-popups-to-escape-sandbox' );
		frame.setAttribute( 'referrerpolicy', 'no-referrer' );
		frame.title = mail.subject;
		frame.srcdoc = previewDocument( mail.message );
		return frame;
	}

	/* --------------------------------------------------------------- compare */

	const CONTEXT = 2;

	/** Diff lines; long unchanged stretches collapse to "n unchanged lines". */
	function diffList( ops ) {
		const list = node( 'ol', 'mst-diff' );
		const changed = ops.map( ( op ) => ' ' !== op[ 0 ] );
		const near = ( i ) => {
			for ( let k = Math.max( 0, i - CONTEXT ); k <= Math.min( ops.length - 1, i + CONTEXT ); k++ ) {
				if ( changed[ k ] ) {
					return true;
				}
			}
			return false;
		};
		let skipped = 0;
		const flush = () => {
			if ( skipped ) {
				list.append( node( 'li', 'mst-diff-skip', fmt( t.unchanged, skipped ) ) );
				skipped = 0;
			}
		};
		ops.forEach( ( [ op, text ], i ) => {
			if ( ' ' === op && ! near( i ) ) {
				skipped++;
				return;
			}
			flush();
			const cls = '+' === op ? 'is-add' : '-' === op ? 'is-del' : 'is-same';
			const li = node( 'li', cls );
			const sign = node( 'span', 'mst-diff-sign', '+' === op ? '+' : '-' === op ? '−' : '' );
			sign.setAttribute( 'aria-hidden', 'true' );
			li.append( sign );
			if ( ' ' !== op ) {
				li.append( node( 'span', 'screen-reader-text', '+' === op ? t.added : t.removed ) );
			}
			li.append( node( 'span', 'mst-diff-text', text ) );
			list.append( li );
		} );
		flush();
		return list;
	}

	let dialog = null;

	function openDialog() {
		if ( ! dialog ) {
			dialog = node( 'dialog', 'mst-dialog' );
			dialog.setAttribute( 'aria-labelledby', 'mst-dialog-title' );
			dialog.addEventListener( 'click', ( e ) => {
				if ( e.target === dialog || e.target.closest( '.mst-dialog-close' ) ) {
					dialog.close();
				}
			} );
			dialog.addEventListener( 'close', () => dialog.replaceChildren() ); // Drop the iframes.
			document.body.append( dialog );
		}
		const close = button( 'button-link mst-dialog-close', '×' );
		close.setAttribute( 'aria-label', t.close );
		const title = node( 'h2', '', t.loading );
		title.id = 'mst-dialog-title';
		const head = node( 'div', 'mst-dialog-head' );
		head.append( title, close );
		const body = node( 'div', 'mst-dialog-body' );
		dialog.replaceChildren( head, body );
		if ( ! dialog.open ) {
			dialog.showModal();
		}
		return { title, body };
	}

	async function compare( typeId ) {
		const { title, body } = openDialog();
		let data;
		try {
			data = await request( 'types/' + typeId + '/compare' );
		} catch ( err ) {
			title.textContent = fmt( t.failed, err.message );
			return;
		}
		title.textContent = data.label;
		if ( data.updates && data.updates.length ) {
			body.append( node( 'p', 'mst-dialog-updates', fmt( t.updated, data.updates.join( ', ' ) ) ) );
		}
		if ( ! data.available ) {
			body.append( node( 'p', 'mst-dialog-gone', data.reason ) );
			return;
		}

		const tabs = node( 'div', 'mst-dialog-tabs' );
		tabs.setAttribute( 'role', 'tablist' );
		const panel = node( 'div', 'mst-dialog-panel' );
		panel.setAttribute( 'role', 'tabpanel' );
		const views = {
			diff: () => {
				const anyChange = data.diff.some( ( op ) => ' ' !== op[ 0 ] );
				return anyChange ? diffList( data.diff ) : node( 'p', '', t.noDiff );
			},
			previews: () => {
				const grid = node( 'div', 'mst-previews' );
				[ [ t.before, data.before ], [ t.after, data.after ] ].forEach( ( [ label, mail ] ) => {
					const col = node( 'figure', 'mst-preview' );
					col.append( node( 'figcaption', '', label + ' · ' + mail.date ), preview( mail ) );
					grid.append( col );
				} );
				return grid;
			},
		};
		const show = ( view ) => {
			tabs.querySelectorAll( '[role="tab"]' ).forEach( ( tab ) => tab.setAttribute( 'aria-selected', String( tab.dataset.view === view ) ) );
			panel.replaceChildren( views[ view ]() );
		};
		[ [ 'diff', t.textChanges ], [ 'previews', t.previews ] ].forEach( ( [ view, label ] ) => {
			const tab = button( 'mst-dialog-tab', label );
			tab.setAttribute( 'role', 'tab' );
			tab.dataset.view = view;
			tab.addEventListener( 'click', () => show( view ) );
			tabs.append( tab );
		} );

		const dates = node( 'p', 'mst-dialog-dates' );
		dates.append(
			node( 'span', 'is-del', t.before + ': ' + data.before.date ),
			node( 'span', 'is-add', t.after + ': ' + data.after.date )
		);
		body.append( dates, tabs, panel );
		show( 'diff' );
	}

	/* ------------------------------------------------------ send latest to me */

	function confirmSend( trigger ) {
		const menu = trigger.closest( '.mst-menu' );
		if ( ! menu || menu.querySelector( '.mst-confirm' ) ) {
			return;
		}
		const box = node( 'div', 'mst-confirm' );
		const status = node( 'p', 'mst-confirm-text', fmt( t.confirmSend, cfg.email ) );
		status.setAttribute( 'role', 'status' );
		const send = button( 'button button-small button-primary', t.send );
		const cancel = button( 'button button-small', t.cancel );
		const actions = node( 'p', 'mst-confirm-actions' );
		actions.append( send, ' ', cancel );
		box.append( status, actions );
		trigger.hidden = true;
		menu.append( box );
		send.focus();

		const done = () => {
			box.remove();
			trigger.hidden = false;
			trigger.focus();
		};
		cancel.addEventListener( 'click', done );
		send.addEventListener( 'click', async () => {
			send.disabled = true;
			cancel.disabled = true;
			status.textContent = t.sending;
			try {
				const res = await request( 'mails/' + trigger.dataset.mail + '/resend', 'POST', { to: [ cfg.email ] } );
				let text = res.sent ? fmt( t.sent, cfg.email ) : t.notSent;
				if ( res.missing_attachments && res.missing_attachments.length ) {
					text += ' ' + fmt( t.missingFiles, res.missing_attachments.join( ', ' ) );
				}
				status.textContent = text;
				status.classList.toggle( 'is-error', ! res.sent );
			} catch ( err ) {
				status.textContent = fmt( t.failed, err.message );
				status.classList.add( 'is-error' );
			}
			actions.replaceChildren( cancel );
			cancel.disabled = false;
			cancel.textContent = t.close;
			cancel.focus();
		} );
	}

	/* --------------------------------------------------------- trigger to me */

	/** One result line per email: status, duration, notes and a link to the new log entry. */
	function probeLine( mail ) {
		const li = node( 'li', 'is-' + mail.status );
		const parts = [ t.status[ mail.status ] || mail.status ];
		if ( mail.duration ) {
			parts.push( fmt( t.seconds, ( mail.duration / 1000 ).toFixed( 1 ) ) );
		}
		parts.push( fmt( t.notes, mail.notes ) );
		li.append( node( 'span', '', parts.join( ' · ' ) ) );
		if ( mail.error ) {
			li.append( ' ', node( 'span', 'mst-probe-error', mail.error ) );
		}
		if ( mail.url ) {
			const link = node( 'a', '', t.openLog );
			link.href = mail.url;
			li.append( ' ', link );
		}
		return li;
	}

	function confirmProbe( trigger ) {
		const menu = trigger.closest( '.mst-menu' );
		if ( ! menu || menu.querySelector( '.mst-confirm' ) ) {
			return;
		}
		const box = node( 'div', 'mst-confirm' );
		const status = node( 'div', 'mst-confirm-text', fmt( t.probe[ trigger.dataset.kind ] || '', cfg.email ) );
		status.setAttribute( 'role', 'status' );
		const run = button( 'button button-small button-primary', t.trigger );
		const cancel = button( 'button button-small', t.cancel );
		const actions = node( 'p', 'mst-confirm-actions' );
		actions.append( run, ' ', cancel );
		box.append( status, actions );
		trigger.hidden = true;
		menu.append( box );
		run.focus();

		cancel.addEventListener( 'click', () => {
			box.remove();
			trigger.hidden = false;
			trigger.focus();
		} );
		run.addEventListener( 'click', async () => {
			run.disabled = true;
			cancel.disabled = true;
			status.textContent = t.triggering;
			try {
				const res = await request( 'types/probe', 'POST', { kind: trigger.dataset.kind } );
				const list = node( 'ul', 'mst-probe-result' );
				res.mails.forEach( ( mail ) => list.append( probeLine( mail ) ) );
				status.replaceChildren( list );
				status.classList.toggle( 'is-error', res.mails.some( ( mail ) => 'sent' !== mail.status ) );
			} catch ( err ) {
				status.textContent = fmt( t.failed, err.message );
				status.classList.add( 'is-error' );
			}
			actions.replaceChildren( cancel );
			cancel.disabled = false;
			cancel.textContent = t.close;
			cancel.focus();
		} );
	}

	/* ------------------------------------------------------------------ wire */

	root.querySelectorAll( '.mst-compare' ).forEach( ( b ) => ( b.hidden = false ) );
	if ( cfg.email ) {
		root.querySelectorAll( '.mst-send, .mst-probe' ).forEach( ( b ) => ( b.hidden = false ) );
	}
	root.addEventListener( 'click', ( e ) => {
		const cmp = e.target.closest( '.mst-compare' );
		if ( cmp ) {
			compare( cmp.dataset.type );
			return;
		}
		const send = e.target.closest( '.mst-send' );
		if ( send ) {
			confirmSend( send );
			return;
		}
		const probe = e.target.closest( '.mst-probe' );
		if ( probe ) {
			confirmProbe( probe );
		}
	} );
}() );
