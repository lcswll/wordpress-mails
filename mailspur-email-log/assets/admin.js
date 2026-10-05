/**
 * Mailspur – Email Log admin screen.
 *
 * Dependency-free. Security rules:
 *  - Mail data is only inserted via textContent / attribute setters, never innerHTML.
 *  - HTML bodies render in an iframe with an empty-origin sandbox (no scripts,
 *    no forms, no same-origin access) plus a CSP that blocks remote content
 *    unless the user opts in.
 */
( function () {
	'use strict';

	const cfg = window.mailspurConfig;
	const app = document.getElementById( 'mailspur-app' );
	if ( ! cfg || ! app ) {
		return;
	}

	const t = cfg.i18n;
	const $ = ( id ) => document.getElementById( id );
	const fmt = ( str, ...args ) => {
		let i = 0;
		return str.replace( /%(\d\$)?s/g, ( _, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : i++ ] ) );
	};
	const num = ( n ) => Number( n ).toLocaleString( document.documentElement.lang || undefined );

	const el = {
		rows: $( 'mailspur-rows' ),
		search: $( 'mailspur-search' ),
		inBody: $( 'mailspur-in-body' ),
		after: $( 'mailspur-after' ),
		before: $( 'mailspur-before' ),
		reset: $( 'mailspur-reset' ),
		moreToggle: $( 'mailspur-more-toggle' ),
		more: $( 'mailspur-more' ),
		moreCount: $( 'mailspur-more-count' ),
		source: $( 'mailspur-source' ),
		format: $( 'mailspur-format' ),
		attachments: $( 'mailspur-attachments' ),
		notes: $( 'mailspur-notes' ),
		perPage: $( 'mailspur-per-page' ),
		page: $( 'mailspur-page' ),
		pages: $( 'mailspur-pages' ),
		summary: $( 'mailspur-summary' ),
		selectAll: $( 'mailspur-select-all' ),
		bulk: $( 'mailspur-bulk' ),
		selected: $( 'mailspur-selected' ),
		toast: $( 'mailspur-toast' ),
		dialog: $( 'mailspur-dialog' ),
		dStatus: $( 'mailspur-d-status' ),
		dSubject: $( 'mailspur-d-subject' ),
		dMeta: $( 'mailspur-d-meta' ),
		dBody: $( 'mailspur-d-body' ),
		dRemote: $( 'mailspur-d-remote' ),
		dRemoteText: $( 'mailspur-d-remote-text' ),
		dRemoteToggle: $( 'mailspur-d-remote-toggle' ),
	};

	/* ------------------------------------------------------------------ state */

	const DEFAULTS = {
		page: 1,
		search: '',
		in_body: false,
		status: 'all',
		orderby: 'date',
		order: 'desc',
		after: '',
		before: '',
		source: '',
		format: '',
		attachments: false,
		notes: false,
	};
	// URL keys (WordPress already owns "page").
	const URL_KEYS = {
		page: 'paged',
		search: 's',
		in_body: 'body',
		status: 'status',
		orderby: 'orderby',
		order: 'order',
		after: 'after',
		before: 'before',
		source: 'source',
		format: 'format',
		attachments: 'att',
		notes: 'notes',
	};
	const FLAGS = [ 'in_body', 'attachments', 'notes' ];
	// Filters behind the "More filters" toggle.
	const MORE = [ 'source', 'format', 'attachments', 'notes' ];
	// Everything that narrows the list (not paging/sorting).
	const FILTERS = [ 'search', 'after', 'before', 'status' ].concat( MORE );

	const state = Object.assign( {}, DEFAULTS, readUrl(), { per_page: readPerPage() } );
	let data = { items: [], total: 0, pages: 0, counts: {} };
	const selection = new Set();
	let controller = null;
	let current = null; // Mail shown in the dialog.
	let view = 'preview';
	let allowRemote = !! cfg.remoteImages;
	// How the HTML preview is shown: width (desktop/phone/text) × colour scheme (light/dark/forced).
	const LOOKS = { width: [ 'desktop', 'phone', 'text' ], scheme: [ 'light', 'dark', 'forced' ] };
	const look = readLook();
	let lookBar = null;

	/* ------------------------------------------------------------ module API */

	// Extension points for feature modules (docs/MODULES.md). Module scripts depend on this script's
	// handle, run after it and register before the first render (DOMContentLoaded).
	const registry = { tabs: [], rows: [], details: [], actions: [], listeners: [] };
	const safely = ( fn, ...args ) => {
		try {
			return fn( ...args );
		} catch ( err ) {
			window.console.error( '[mailspur module]', err );
		}
	};
	window.mailspur = {
		cfg,
		t,
		fmt,
		num,
		node: ( ...args ) => node( ...args ),
		api: ( ...args ) => api( ...args ),
		endpoint: ( ...args ) => endpoint( ...args ),
		toast: ( ...args ) => toast( ...args ),
		reload: () => load(),
		/** Opens a log entry in the dialog by id. */
		open: ( id ) => openMail( id ),
		/** Current list filters, sorting and paging (a copy), e.g. for exports. */
		state: () => Object.assign( {}, state ),
		/** Mail shown in the dialog (detail payload incl. meta), or null. */
		current: () => current,
		/** Extra dialog tab: { id, label, render( container, mail ) }. */
		registerTab: ( def ) => registry.tabs.push( def ),
		/** Called for every list row: fn( tr, item ) – add badges, classes … */
		registerRowDecorator: ( fn ) => registry.rows.push( fn ),
		/** Called when the dialog opens: fn( dl, mail ) – add <dt>/<dd> pairs or other details. */
		registerDetail: ( fn ) => registry.details.push( fn ),
		/** Extra dialog button: { id, label, icon (dashicon name), run( mail ), visible?( mail ) }. */
		registerAction: ( def ) => registry.actions.push( def ),
		/** fn( data ) after every list load (items, total, counts). */
		onList: ( fn ) => registry.listeners.push( fn ),
	};

	function readUrl() {
		const params = new URLSearchParams( location.search );
		const out = {};
		Object.keys( URL_KEYS ).forEach( ( key ) => {
			const value = params.get( URL_KEYS[ key ] );
			if ( null === value ) {
				return;
			}
			if ( 'page' === key ) {
				out.page = Math.max( 1, parseInt( value, 10 ) || 1 );
			} else if ( FLAGS.includes( key ) ) {
				out[ key ] = '1' === value;
			} else {
				out[ key ] = value;
			}
		} );
		return out;
	}

	function writeUrl() {
		const url = new URL( location.href );
		Object.keys( URL_KEYS ).forEach( ( key ) => {
			const value = state[ key ];
			if ( value === DEFAULTS[ key ] ) {
				url.searchParams.delete( URL_KEYS[ key ] );
			} else {
				url.searchParams.set( URL_KEYS[ key ], true === value ? '1' : String( value ) );
			}
		} );
		history.replaceState( null, '', url );
	}

	function readPerPage() {
		try {
			const n = parseInt( localStorage.getItem( 'mailspur.perPage' ), 10 );
			return [ 25, 50, 100, 200 ].includes( n ) ? n : 25;
		} catch ( e ) {
			return 25;
		}
	}

	function readLook() {
		const out = { width: 'desktop', scheme: 'light' };
		try {
			const [ width, scheme ] = String( localStorage.getItem( 'mailspur.previewLook' ) || '' ).split( '/' );
			if ( LOOKS.width.includes( width ) ) {
				out.width = width;
			}
			if ( LOOKS.scheme.includes( scheme ) ) {
				out.scheme = scheme;
			}
		} catch ( e ) {
			// Storage unavailable – start with the defaults.
		}
		return out;
	}

	/* -------------------------------------------------------------------- api */

	function endpoint( path, params ) {
		const url = new URL( cfg.restUrl, location.href );
		// Sites without pretty permalinks use ?rest_route=/mailspur-email-log/v1.
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/?$/, '/' ) + path;
		}
		Object.entries( params || {} ).forEach( ( [ key, value ] ) => {
			if ( '' !== value && null !== value && undefined !== value && false !== value ) {
				url.searchParams.set( key, String( value ) );
			}
		} );
		return url.toString();
	}

	async function api( path, { method = 'GET', params, signal } = {} ) {
		const res = await fetch( endpoint( path, params ), {
			method,
			signal,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
		} );
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			// Non-JSON response (PHP notice, proxy error page …).
		}
		if ( ! res.ok ) {
			throw new Error( ( body && body.message ) || res.status + ' ' + res.statusText );
		}
		return body;
	}

	/* ------------------------------------------------------------------- list */

	async function load() {
		if ( controller ) {
			controller.abort();
		}
		const own = ( controller = new AbortController() );
		app.setAttribute( 'aria-busy', 'true' );
		writeUrl();
		syncControls();

		try {
			const params = Object.assign( {}, state );
			const result = await api( 'mails', { params, signal: own.signal } );
			if ( result.pages && state.page > result.pages ) {
				state.page = result.pages;
				return load();
			}
			data = result;
			render();
		} catch ( err ) {
			if ( 'AbortError' !== err.name ) {
				toast( fmt( t.requestFailed, err.message ), true );
			}
		} finally {
			if ( own === controller ) {
				app.setAttribute( 'aria-busy', 'false' );
			}
		}
	}

	function syncControls() {
		if ( el.search.value.trim() !== state.search ) {
			el.search.value = state.search;
		}
		el.inBody.checked = state.in_body;
		el.after.value = state.after;
		el.before.value = state.before;
		el.perPage.value = String( state.per_page );
		el.reset.hidden = ! isFiltered();

		if ( state.source && ! [ ...el.source.options ].some( ( o ) => o.value === state.source ) ) {
			// Sources are loaded asynchronously (or not at all); keep the active one selectable.
			const option = node( 'option', '', sourceLabel( state.source ) );
			option.value = state.source;
			el.source.append( option );
		}
		el.source.value = state.source;
		el.format.value = state.format;
		el.attachments.checked = state.attachments;
		el.notes.checked = state.notes;
		const extra = MORE.filter( ( key ) => state[ key ] !== DEFAULTS[ key ] ).length;
		el.moreCount.textContent = extra ? num( extra ) : '';

		app.querySelectorAll( '[data-status]' ).forEach( ( btn ) => {
			btn.setAttribute( 'aria-pressed', String( btn.dataset.status === state.status ) );
		} );
		app.querySelectorAll( 'th[aria-sort]' ).forEach( ( th ) => {
			const key = th.querySelector( '[data-sort]' ).dataset.sort;
			th.setAttribute( 'aria-sort', key === state.orderby ? ( 'asc' === state.order ? 'ascending' : 'descending' ) : 'none' );
		} );
	}

	function render() {
		registry.listeners.forEach( ( fn ) => safely( fn, data ) );
		Object.keys( data.counts || {} ).forEach( ( key ) => {
			const node = app.querySelector( '[data-count="' + key + '"]' );
			if ( node ) {
				node.textContent = num( data.counts[ key ] );
			}
		} );

		el.rows.replaceChildren( ...( data.items.length ? data.items.map( row ) : [ emptyRow() ] ) );

		el.summary.textContent = fmt( t.entries, num( data.total ) );
		el.page.value = String( state.page );
		el.page.max = String( Math.max( 1, data.pages ) );
		el.pages.textContent = fmt( t.pageOf, num( Math.max( 1, data.pages ) ) );
		app.querySelector( '[data-page="first"]' ).disabled = state.page <= 1;
		app.querySelector( '[data-page="prev"]' ).disabled = state.page <= 1;
		app.querySelector( '[data-page="next"]' ).disabled = state.page >= data.pages;
		app.querySelector( '[data-page="last"]' ).disabled = state.page >= data.pages;

		updateSelection();
	}

	function node( tag, className, text ) {
		const n = document.createElement( tag );
		if ( className ) {
			n.className = className;
		}
		if ( undefined !== text && null !== text ) {
			n.textContent = text;
		}
		return n;
	}

	function iconButton( action, icon, label ) {
		const b = node( 'button', 'mailspur-row-action' );
		b.type = 'button';
		b.dataset.action = action;
		b.title = label;
		b.setAttribute( 'aria-label', label );
		b.append( node( 'span', 'dashicons dashicons-' + icon ) );
		return b;
	}

	function badge( status ) {
		return node( 'span', 'mailspur-badge is-' + status, t[ status ] || status );
	}

	function sourceLabel( source ) {
		if ( ! source || 'core' === source ) {
			return t.core;
		}
		if ( 'mailspur:resend' === source ) {
			return t.resent;
		}
		if ( 0 === source.indexOf( 'import:' ) ) {
			const id = source.slice( 7 );
			return fmt( t.imported, ( cfg.importLabels && cfg.importLabels[ id ] ) || id );
		}
		const i = source.indexOf( ':' );
		return i > -1 ? source.slice( i + 1 ) : source;
	}

	function row( item ) {
		const tr = node( 'tr', 'is-' + item.status );
		tr.dataset.id = String( item.id );

		const check = node( 'th', 'col-check' );
		check.scope = 'row';
		const cb = node( 'input' );
		cb.type = 'checkbox';
		cb.value = String( item.id );
		cb.checked = selection.has( item.id );
		cb.setAttribute( 'aria-label', item.subject || t.noSubject );
		check.append( cb );

		const date = node( 'td', 'col-date' );
		const time = node( 'time', '', item.date );
		time.dateTime = item.date_iso;
		date.append( time );

		const status = node( 'td', 'col-status' );
		status.append( badge( item.status ) );

		const to = node( 'td', 'col-to', item.to );
		to.title = item.to;

		const subject = node( 'td', 'col-subject' );
		const open = node( 'button', 'mailspur-open', item.subject || t.noSubject );
		open.type = 'button';
		open.dataset.action = 'view';
		subject.append( open );
		if ( item.attachments.length ) {
			const clip = node( 'span', 'mailspur-clip dashicons dashicons-paperclip' );
			clip.title = item.attachments.join( ', ' );
			clip.setAttribute( 'aria-label', t.attachments + ': ' + item.attachments.join( ', ' ) );
			subject.append( clip );
		}
		if ( item.error ) {
			subject.append( node( 'span', 'mailspur-error', item.error ) );
		}

		const source = node( 'td', 'col-source', sourceLabel( item.source ) );
		source.title = item.source;

		const actions = node( 'td', 'col-actions' );
		actions.append( iconButton( 'view', 'visibility', t.view ), iconButton( 'resend', 'controls-repeat', t.resend ), iconButton( 'delete', 'trash', t.delete ) );

		tr.append( check, date, status, to, subject, source, actions );
		registry.rows.forEach( ( fn ) => safely( fn, tr, item ) );
		return tr;
	}

	/** Whether anything narrows the list (status included). */
	function isFiltered() {
		return FILTERS.some( ( key ) => state[ key ] !== DEFAULTS[ key ] );
	}

	function emptyRow() {
		const tr = node( 'tr', 'mailspur-empty' );
		const unfiltered = FILTERS.every( ( key ) => 'status' === key || state[ key ] === DEFAULTS[ key ] );
		const td = node( 'td', '', data.counts && data.counts.all === 0 && unfiltered ? t.empty : t.emptyFiltered );
		td.colSpan = 7;
		tr.append( td );
		return tr;
	}

	function updateSelection() {
		const ids = data.items.map( ( i ) => i.id );
		const onPage = ids.filter( ( id ) => selection.has( id ) ).length;
		el.selectAll.checked = ids.length > 0 && onPage === ids.length;
		el.selectAll.indeterminate = onPage > 0 && onPage < ids.length;
		el.bulk.hidden = 0 === selection.size;
		el.selected.textContent = fmt( t.selected, num( selection.size ) );
	}

	/* ---------------------------------------------------------------- actions */

	async function remove( ids ) {
		if ( 1 === ids.length ) {
			await api( 'mails/' + ids[ 0 ], { method: 'DELETE' } );
		} else {
			await api( 'mails', { method: 'DELETE', params: { ids: ids.join( ',' ) } } );
		}
		ids.forEach( ( id ) => selection.delete( id ) );
		toast( t.deleted );
		await load();
	}

	async function resend( item ) {
		if ( ! window.confirm( fmt( t.confirmResend, item.to ) ) ) {
			return;
		}
		try {
			const res = await api( 'mails/' + item.id + '/resend', { method: 'POST' } );
			let message = res.sent ? t.resendOk : t.resendFail;
			if ( res.missing_attachments && res.missing_attachments.length ) {
				message += ' ' + fmt( t.missingFiles, res.missing_attachments.join( ', ' ) );
			}
			toast( message, ! res.sent );
			state.page = 1;
			await load();
		} catch ( err ) {
			toast( fmt( t.requestFailed, err.message ), true );
		}
	}

	let toastTimer;
	function toast( message, isError ) {
		el.toast.textContent = message;
		el.toast.classList.toggle( 'is-error', !! isError );
		el.toast.classList.add( 'is-visible' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => el.toast.classList.remove( 'is-visible' ), isError ? 8000 : 3500 );
	}

	/* ----------------------------------------------------------------- dialog */

	async function openMail( id ) {
		try {
			current = await api( 'mails/' + id );
		} catch ( err ) {
			toast( fmt( t.requestFailed, err.message ), true );
			return;
		}
		allowRemote = !! cfg.remoteImages;
		view = 'preview';
		renderDialog();
		if ( ! el.dialog.open ) {
			el.dialog.showModal();
		}
		el.rows.querySelectorAll( 'tr.is-current' ).forEach( ( tr ) => tr.classList.remove( 'is-current' ) );
		const tr = el.rows.querySelector( 'tr[data-id="' + id + '"]' );
		if ( tr ) {
			tr.classList.add( 'is-current' );
		}
	}

	function renderDialog() {
		const m = current;
		el.dStatus.className = 'mailspur-badge is-' + m.status;
		el.dStatus.textContent = t[ m.status ] || m.status;
		el.dSubject.textContent = m.subject || t.noSubject;

		const meta = [
			[ t.from, m.sender ],
			[ t.to, m.to ],
			[ t.date, m.date ],
			[ t.source, sourceLabel( m.source ) ],
			[ t.contentType, m.content_type ],
			[ t.attachments, m.attachments.join( ', ' ) ],
			[ t.error, m.error, 'is-error' ],
		];
		el.dMeta.replaceChildren(
			...meta
				.filter( ( [ , value ] ) => value )
				.flatMap( ( [ label, value, cls ] ) => [ node( 'dt', cls, label ), node( 'dd', cls, value ) ] )
		);
		registry.details.forEach( ( fn ) => safely( fn, el.dMeta, m ) );
		el.dialog.querySelectorAll( '[data-module-action]' ).forEach( ( btn ) => {
			const def = registry.actions.find( ( a ) => a.id === btn.dataset.moduleAction );
			btn.hidden = !! ( def && def.visible && ! safely( def.visible, m ) );
		} );

		const index = data.items.findIndex( ( i ) => i.id === m.id );
		el.dialog.querySelector( '[data-action="prev"]' ).disabled = index <= 0;
		el.dialog.querySelector( '[data-action="next"]' ).disabled = index < 0 || index >= data.items.length - 1;

		renderView();
	}

	// Only what the browser would fetch on render (images, backgrounds, CSS, fonts) – not ordinary links.
	const REMOTE_RE = /\b(?:src|srcset|background)\s*=\s*["']?\s*(?:https?:)?\/\/|url\(\s*["']?\s*(?:https?:)?\/\/|<link\b[^>]*\bhref\s*=\s*["']?\s*(?:https?:)?\/\/|@import\s+["']?(?:https?:)?\/\//i;

	function renderView() {
		el.dialog.querySelectorAll( '[role="tab"]' ).forEach( ( tab ) => {
			tab.setAttribute( 'aria-selected', String( tab.dataset.view === view ) );
			tab.tabIndex = tab.dataset.view === view ? 0 : -1;
		} );
		el.dBody.setAttribute( 'aria-labelledby', 'mailspur-tab-' + view );

		const html = 'preview' === view && current.is_html;
		const showRemote = html && 'text' !== look.width && REMOTE_RE.test( current.message );
		el.dRemote.hidden = ! showRemote;
		if ( showRemote ) {
			el.dRemoteText.textContent = allowRemote ? t.remoteLoaded : t.remoteBlocked;
			el.dRemoteToggle.textContent = allowRemote ? t.blockRemote : t.loadRemote;
		}
		el.dBody.classList.toggle( 'has-looks', html );

		const moduleTab = registry.tabs.find( ( def ) => def.id === view );
		if ( moduleTab ) {
			el.dRemote.hidden = true;
			el.dBody.replaceChildren();
			safely( moduleTab.render, el.dBody, current );
			return;
		}

		if ( html ) {
			renderLook();
			return;
		}

		const text = 'headers' === view ? current.headers : current.message;
		const pre = node( 'pre', 'mailspur-pre', text );
		el.dBody.replaceChildren( pre );
	}

	/* ------------------------------------------------------ preview looks */

	/**
	 * HTML preview with the look switcher (desktop / phone / plain text × light / dark / forced dark).
	 * The bar stays in place while switching, so keyboard focus is kept.
	 */
	function renderLook() {
		if ( ! lookBar ) {
			lookBar = buildLookBar();
		}
		lookBar.querySelectorAll( '[data-look]' ).forEach( ( btn ) => {
			btn.setAttribute( 'aria-pressed', String( look[ btn.dataset.look ] === btn.dataset.value ) );
		} );
		const text = 'text' === look.width;
		lookBar.querySelector( '[data-group="scheme"]' ).hidden = text;

		const ownDark = DARK_CSS_RE.test( current.message );
		const hints = [];
		if ( text ) {
			const flag = current.meta ? current.meta.plain_text : undefined;
			hints.push( true === flag ? t.hintTextOwn : false === flag ? t.hintTextNone : t.hintText );
		} else {
			if ( 'phone' === look.width ) {
				hints.push( t.hintPhone );
			}
			if ( 'dark' === look.scheme ) {
				hints.push( ownDark ? t.hintDark : t.hintNoDark );
			} else if ( 'forced' === look.scheme ) {
				hints.push( t.hintForced );
			}
		}
		lookBar.querySelector( '.mailspur-look-hint' ).textContent = hints.join( ' ' );

		// Without dark styles of its own the email stays light (as in most apps); the hint says so.
		const scheme = 'dark' === look.scheme && ! ownDark ? 'light' : look.scheme;
		const stage = node( 'div', 'mailspur-stage is-' + look.width + ( text ? '' : ' is-' + scheme ) );
		if ( text ) {
			stage.append( node( 'pre', 'mailspur-pre', htmlToText( current.message ) || t.noText ) );
		} else {
			const frame = node( 'iframe', 'mailspur-frame' );
			// Unique opaque origin: no scripts, forms, storage or access to wp-admin.
			frame.setAttribute( 'sandbox', 'allow-popups allow-popups-to-escape-sandbox' );
			frame.setAttribute( 'referrerpolicy', 'no-referrer' );
			frame.title = current.subject || t.noSubject;
			frame.srcdoc = previewDocument( current.message, allowRemote, scheme );
			stage.append( frame );
		}

		if ( lookBar.parentNode === el.dBody ) {
			[ ...el.dBody.children ].forEach( ( child ) => child !== lookBar && child.remove() );
			el.dBody.append( stage );
		} else {
			el.dBody.replaceChildren( lookBar, stage );
		}
	}

	function buildLookBar() {
		const bar = node( 'div', 'mailspur-looks' );
		const groups = [
			[ 'width', t.viewAs, [ [ 'desktop', t.viewDesktop ], [ 'phone', t.viewPhone ], [ 'text', t.viewText ] ] ],
			[ 'scheme', t.scheme, [ [ 'light', t.schemeLight ], [ 'dark', t.schemeDark ], [ 'forced', t.schemeForced ] ] ],
		];
		groups.forEach( ( [ key, label, options ] ) => {
			const group = node( 'div', 'mailspur-segmented' );
			group.setAttribute( 'role', 'group' );
			group.setAttribute( 'aria-label', label );
			group.dataset.group = key;
			options.forEach( ( [ value, text ] ) => {
				const btn = node( 'button', '', text );
				btn.type = 'button';
				btn.dataset.look = key;
				btn.dataset.value = value;
				group.append( btn );
			} );
			bar.append( group );
		} );
		const hint = node( 'p', 'mailspur-look-hint' );
		hint.setAttribute( 'aria-live', 'polite' );
		bar.append( hint );
		return bar;
	}

	function setLook( key, value ) {
		if ( ! LOOKS[ key ] || ! LOOKS[ key ].includes( value ) || look[ key ] === value ) {
			return;
		}
		look[ key ] = value;
		try {
			localStorage.setItem( 'mailspur.previewLook', look.width + '/' + look.scheme );
		} catch ( e ) {
			// Storage unavailable (private mode) – keep the in-memory choice.
		}
		renderView();
	}

	// The email ships its own dark-mode styles (media query or color-scheme declaration).
	const DARK_CSS_RE = /prefers-color-scheme\s*:\s*dark|color-scheme\s*(?::|["']?\s+content\s*=\s*["'])[^;}"'>]*\bdark\b/i;

	/**
	 * Renders the email's dark-mode styles without scripts: media features asking for the colour scheme are
	 * replaced by conditions that are always true (dark) or always false (light) – in <style>, media
	 * attributes and nested queries alike, so "not" and "and" keep their meaning.
	 */
	function forceDarkQueries( html ) {
		return html
			.replace( /\(\s*prefers-color-scheme\s*:\s*dark\s*\)/gi, '(min-width:0px)' )
			.replace( /\(\s*prefers-color-scheme\s*:\s*light\s*\)/gi, '(max-width:0px)' );
	}

	/**
	 * Plain-text rendering of an HTML body, roughly as mail clients and html2text tools produce it.
	 * DOMParser documents are inert: no scripts run and nothing is fetched. Only text is read back.
	 */
	function htmlToText( html ) {
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		const SKIP = /^(HEAD|STYLE|SCRIPT|TITLE|TEMPLATE|NOSCRIPT|META|LINK|BUTTON|INPUT|SELECT|TEXTAREA)$/;
		const BLOCK = /^(ADDRESS|ARTICLE|ASIDE|BLOCKQUOTE|CENTER|DD|DIV|DL|DT|FIELDSET|FIGCAPTION|FIGURE|FOOTER|FORM|H[1-6]|HEADER|MAIN|NAV|OL|P|SECTION|TABLE|TBODY|THEAD|TFOOT|TR|UL)$/;
		const PARAGRAPH = /^(BLOCKQUOTE|H[1-6]|OL|P|TABLE|UL)$/;
		const out = [];
		const walk = ( n, pre ) => {
			if ( 3 === n.nodeType ) {
				out.push( pre ? n.nodeValue : n.nodeValue.replace( /\s+/g, ' ' ) );
				return;
			}
			if ( 1 !== n.nodeType ) {
				return;
			}
			const tag = n.tagName;
			const style = ( n.getAttribute( 'style' ) || '' ).toLowerCase().replace( /\s+/g, '' );
			// Hidden preheaders and Outlook-only blocks are not part of the readable text.
			if ( SKIP.test( tag ) || n.hasAttribute( 'hidden' ) || /display:none|mso-hide:all/.test( style ) ) {
				return;
			}
			if ( 'BR' === tag ) {
				out.push( '\n' );
				return;
			}
			if ( 'HR' === tag ) {
				out.push( '\n\n----------\n\n' );
				return;
			}
			if ( 'IMG' === tag ) {
				const alt = ( n.getAttribute( 'alt' ) || '' ).trim();
				if ( alt ) {
					out.push( '[' + alt + ']' );
				}
				return;
			}
			const sep = PARAGRAPH.test( tag ) ? '\n\n' : BLOCK.test( tag ) ? '\n' : '';
			out.push( sep );
			if ( 'LI' === tag ) {
				out.push( '\n- ' );
			}
			const start = out.length;
			n.childNodes.forEach( ( child ) => walk( child, pre || 'PRE' === tag ) );
			if ( 'A' === tag ) {
				const href = ( n.getAttribute( 'href' ) || '' ).trim();
				const label = out.slice( start ).join( '' ).trim();
				if ( /^(https?:|mailto:)/i.test( href ) && label !== href && label !== href.replace( /^mailto:/i, '' ) ) {
					out.push( ' (' + href.replace( /^mailto:/i, '' ) + ')' );
				}
			} else if ( 'TD' === tag || 'TH' === tag ) {
				out.push( '  ' );
			}
			out.push( sep );
		};
		walk( doc.body, false );
		return out
			.join( '' )
			.replace( /[^\S\n]+\n/g, '\n' )
			.replace( /\n[^\S\n]+/g, '\n' )
			.replace( /\n{3,}/g, '\n\n' )
			.trim();
	}

	/**
	 * Defuses references the browser would fetch on render, so blocked remote content is never even requested.
	 * Defence in depth: the CSP in previewDocument() blocks anything this misses.
	 */
	function defuseRemote( html ) {
		const remote = '(?:https?:)?\\/\\/';
		return html
			.replace( new RegExp( '(\\s)(src|srcset|background|poster)(\\s*=\\s*["\']?\\s*' + remote + ')', 'gi' ), '$1data-blocked-$2$3' )
			.replace( new RegExp( '(<link\\b[^>]*\\s)href(\\s*=\\s*["\']?\\s*' + remote + ')', 'gi' ), '$1data-blocked-href$2' )
			.replace( new RegExp( 'url\\(\\s*(["\']?)\\s*' + remote, 'gi' ), 'url($1data:,' )
			.replace( new RegExp( '@import\\s+(["\'])\\s*' + remote, 'gi' ), '@import $1data:,' );
	}

	// Colour scheme of the preview document. Dark: the email's own dark styles (see forceDarkQueries()).
	// Forced: inverts the whole page like apps that force dark mode; images and backgrounds are inverted back.
	const INVERT = 'filter:invert(1) hue-rotate(180deg)!important';
	const SCHEMES = {
		light: 'html{color-scheme:light}',
		dark: 'html{color-scheme:dark}',
		forced:
			// The filter also covers the canvas (the email's page background); an unset one shows the dark frame.
			'html{color-scheme:light;' + INVERT + '}' +
			'img,picture,video,svg,[background],[style*="background-image"],[style*="url("]{' + INVERT + '}' +
			':is([background],[style*="background-image"],[style*="url("]) :is(img,picture,video,svg){filter:none!important}',
	};

	function previewDocument( html, remote, scheme ) {
		if ( ! remote ) {
			html = defuseRemote( html );
		}
		if ( 'dark' === scheme ) {
			html = forceDarkQueries( html );
		}
		const ext = remote ? ' https: http:' : '';
		const csp = [
			"default-src 'none'",
			"style-src 'unsafe-inline'" + ext,
			'img-src data: cid:' + ext,
			'font-src data:' + ext,
			"form-action 'none'",
		].join( '; ' );
		// Our <head> comes first, so the CSP applies before any mail markup is parsed.
		return (
			'<!doctype html><html><head><meta charset="utf-8">' +
			'<meta http-equiv="Content-Security-Policy" content="' + csp + '">' +
			'<meta name="referrer" content="no-referrer">' +
			'<base target="_blank">' +
			'<style>' + ( SCHEMES[ scheme ] || SCHEMES.light ) + 'body{margin:0;padding:16px;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;word-wrap:break-word}img{max-width:100%;height:auto}</style>' +
			'</head><body>' + html + '</body></html>'
		);
	}

	function step( dir ) {
		if ( ! current ) {
			return;
		}
		const index = data.items.findIndex( ( i ) => i.id === current.id ) + dir;
		if ( index >= 0 && index < data.items.length ) {
			openMail( data.items[ index ].id );
		}
	}

	el.dialog.addEventListener( 'click', async ( e ) => {
		if ( e.target === el.dialog ) {
			el.dialog.close(); // Backdrop click.
			return;
		}
		const lookBtn = e.target.closest( '[data-look]' );
		if ( lookBtn ) {
			setLook( lookBtn.dataset.look, lookBtn.dataset.value );
			return;
		}
		const tab = e.target.closest( '[role="tab"]' );
		if ( tab ) {
			view = tab.dataset.view;
			renderView();
			return;
		}
		if ( e.target.closest( '#mailspur-d-remote-toggle' ) ) {
			allowRemote = ! allowRemote;
			renderView();
			return;
		}
		const btn = e.target.closest( '[data-action]' );
		if ( ! btn ) {
			return;
		}
		switch ( btn.dataset.action ) {
			case 'close':
				el.dialog.close();
				break;
			case 'prev':
				step( -1 );
				break;
			case 'next':
				step( 1 );
				break;
			case 'resend':
				await resend( current );
				break;
			case 'delete':
				if ( window.confirm( t.confirmDelete ) ) {
					const index = data.items.findIndex( ( i ) => i.id === current.id );
					await remove( [ current.id ] ).catch( ( err ) => toast( fmt( t.requestFailed, err.message ), true ) );
					const next = data.items[ Math.min( index, data.items.length - 1 ) ];
					next ? openMail( next.id ) : el.dialog.close();
				}
				break;
			case 'module': {
				const def = registry.actions.find( ( a ) => a.id === btn.dataset.moduleAction );
				if ( def ) {
					await safely( def.run, current );
				}
				break;
			}
		}
	} );

	el.dialog.addEventListener( 'keydown', ( e ) => {
		if ( e.altKey || e.ctrlKey || e.metaKey ) {
			return;
		}
		if ( 'j' === e.key ) {
			step( 1 );
		} else if ( 'k' === e.key ) {
			step( -1 );
		} else if ( ( 'ArrowRight' === e.key || 'ArrowLeft' === e.key ) && e.target.closest( '[role="tab"]' ) ) {
			const tabs = [ ...el.dialog.querySelectorAll( '[role="tab"]' ) ];
			const i = ( tabs.indexOf( e.target ) + ( 'ArrowRight' === e.key ? 1 : -1 ) + tabs.length ) % tabs.length;
			view = tabs[ i ].dataset.view;
			renderView();
			tabs[ i ].focus();
		}
	} );

	el.dialog.addEventListener( 'close', () => {
		el.dBody.replaceChildren(); // Drop the iframe so nothing keeps running/loading.
		const tr = current && el.rows.querySelector( 'tr[data-id="' + current.id + '"] .mailspur-open' );
		if ( tr ) {
			tr.focus();
		}
		current = null;
	} );

	/* ----------------------------------------------------------------- events */

	let searchTimer;
	el.search.addEventListener( 'input', () => {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => {
			const value = el.search.value.trim();
			if ( value !== state.search ) {
				state.search = value;
				state.page = 1;
				load();
			}
		}, 250 );
	} );

	el.inBody.addEventListener( 'change', () => {
		state.in_body = el.inBody.checked;
		state.page = 1;
		if ( state.search ) {
			load();
		} else {
			writeUrl();
		}
	} );

	[ el.after, el.before ].forEach( ( input ) =>
		input.addEventListener( 'change', () => {
			state.after = el.after.value;
			state.before = el.before.value;
			state.page = 1;
			load();
		} )
	);

	function toggleMore( open ) {
		el.more.hidden = ! open;
		el.moreToggle.setAttribute( 'aria-expanded', String( open ) );
	}
	el.moreToggle.addEventListener( 'click', () => toggleMore( el.more.hidden ) );
	// Open on load when one of its filters is active, so nothing is filtered invisibly.
	toggleMore( MORE.some( ( key ) => state[ key ] !== DEFAULTS[ key ] ) );

	[ el.source, el.format, el.attachments, el.notes ].forEach( ( input ) =>
		input.addEventListener( 'change', () => {
			state.source = el.source.value;
			state.format = el.format.value;
			state.attachments = el.attachments.checked;
			state.notes = el.notes.checked;
			state.page = 1;
			load();
		} )
	);

	el.reset.addEventListener( 'click', () => {
		Object.assign( state, DEFAULTS );
		el.search.value = '';
		load();
		el.search.focus();
	} );

	el.perPage.addEventListener( 'change', () => {
		state.per_page = parseInt( el.perPage.value, 10 );
		state.page = 1;
		try {
			localStorage.setItem( 'mailspur.perPage', String( state.per_page ) );
		} catch ( e ) {
			// Storage unavailable (private mode) – keep the in-memory value.
		}
		load();
	} );

	el.page.addEventListener( 'change', () => {
		const page = Math.min( Math.max( 1, parseInt( el.page.value, 10 ) || 1 ), Math.max( 1, data.pages ) );
		if ( page !== state.page ) {
			state.page = page;
			load();
		}
	} );

	el.selectAll.addEventListener( 'change', () => {
		data.items.forEach( ( i ) => ( el.selectAll.checked ? selection.add( i.id ) : selection.delete( i.id ) ) );
		el.rows.querySelectorAll( '.col-check input' ).forEach( ( cb ) => ( cb.checked = el.selectAll.checked ) );
		updateSelection();
	} );

	$( 'mailspur-bulk-clear' ).addEventListener( 'click', () => {
		selection.clear();
		el.rows.querySelectorAll( '.col-check input' ).forEach( ( cb ) => ( cb.checked = false ) );
		updateSelection();
	} );

	$( 'mailspur-bulk-delete' ).addEventListener( 'click', () => {
		if ( window.confirm( fmt( t.confirmBulk, num( selection.size ) ) ) ) {
			remove( [ ...selection ] ).catch( ( err ) => toast( fmt( t.requestFailed, err.message ), true ) );
		}
	} );

	app.addEventListener( 'click', ( e ) => {
		const status = e.target.closest( '[data-status]' );
		if ( status ) {
			state.status = status.dataset.status;
			state.page = 1;
			load();
			return;
		}

		const sort = e.target.closest( '[data-sort]' );
		if ( sort ) {
			const key = sort.dataset.sort;
			state.order = state.orderby === key && 'desc' === state.order ? 'asc' : 'desc';
			state.orderby = key;
			state.page = 1;
			load();
			return;
		}

		const pager = e.target.closest( '[data-page]' );
		if ( pager ) {
			const target = { first: 1, prev: state.page - 1, next: state.page + 1, last: data.pages }[ pager.dataset.page ];
			state.page = Math.min( Math.max( 1, target ), Math.max( 1, data.pages ) );
			load();
			return;
		}

		const tr = e.target.closest( '#mailspur-rows tr[data-id]' );
		if ( ! tr ) {
			return;
		}
		const id = parseInt( tr.dataset.id, 10 );
		const item = data.items.find( ( i ) => i.id === id );

		if ( e.target.matches( '.col-check input' ) ) {
			e.target.checked ? selection.add( id ) : selection.delete( id );
			updateSelection();
			return;
		}
		if ( e.target.closest( '.col-check' ) ) {
			return;
		}

		const action = e.target.closest( '[data-action]' );
		if ( action && 'resend' === action.dataset.action ) {
			resend( item );
		} else if ( action && 'delete' === action.dataset.action ) {
			if ( window.confirm( t.confirmDelete ) ) {
				remove( [ id ] ).catch( ( err ) => toast( fmt( t.requestFailed, err.message ), true ) );
			}
		} else if ( ! window.getSelection().toString() ) {
			// Whole row opens the mail, unless the user is selecting text.
			openMail( id );
		}
	} );

	document.addEventListener( 'keydown', ( e ) => {
		if ( '/' === e.key && ! el.dialog.open && ! e.target.closest( 'input, textarea, select, [contenteditable]' ) ) {
			e.preventDefault();
			el.search.focus();
			el.search.select();
		}
	} );

	// Purge button only for administrators.
	if ( cfg.canPurge ) {
		const purge = node( 'button', 'button-link mailspur-purge', t.purge );
		purge.type = 'button';
		purge.addEventListener( 'click', async () => {
			if ( ! window.confirm( t.confirmPurge ) ) {
				return;
			}
			try {
				await api( 'mails', { method: 'DELETE', params: { all: 1 } } );
				selection.clear();
				state.page = 1;
				toast( t.deleted );
				load();
			} catch ( err ) {
				toast( fmt( t.requestFailed, err.message ), true );
			}
		} );
		app.querySelector( '.mailspur-footer' ).append( purge );
	}

	/** Adds module tabs and dialog buttons, then loads the list. */
	function init() {
		const tablist = el.dialog.querySelector( '.mailspur-d-tabs' );
		const remote = el.dRemote;
		registry.tabs.forEach( ( def ) => {
			const tab = node( 'button', '', def.label );
			tab.type = 'button';
			tab.id = 'mailspur-tab-' + def.id;
			tab.dataset.view = def.id;
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'aria-selected', 'false' );
			tab.setAttribute( 'aria-controls', 'mailspur-d-body' );
			tablist.insertBefore( tab, remote );
		} );
		const close = el.dialog.querySelector( '[data-action="close"]' );
		registry.actions.forEach( ( def ) => {
			const btn = node( 'button', 'button' );
			btn.type = 'button';
			btn.dataset.action = 'module';
			btn.dataset.moduleAction = def.id;
			if ( def.icon ) {
				const icon = node( 'span', 'dashicons dashicons-' + def.icon );
				icon.setAttribute( 'aria-hidden', 'true' );
				btn.append( icon, ' ' );
			}
			btn.append( def.label );
			close.parentNode.insertBefore( btn, close );
		} );

		// Deep link ?mail=ID (Email types, order box, user profile): open that entry once, then drop the parameter.
		const url = new URL( location.href );
		const deepLink = parseInt( url.searchParams.get( 'mail' ) || '', 10 );
		if ( deepLink > 0 ) {
			url.searchParams.delete( 'mail' );
			history.replaceState( null, '', url );
		}
		load();
		if ( deepLink > 0 ) {
			openMail( deepLink );
		}
	}

	// Deferred module scripts run after this one but before DOMContentLoaded.
	if ( 'complete' === document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
}() );
