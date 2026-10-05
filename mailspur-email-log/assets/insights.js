/**
 * Mailspur – Email Log: statistics tab (charts) and the "Send test alert" / "Send report now" buttons in the settings.
 *
 * Dependency-free, hand-rolled inline SVG. All data goes into the DOM via textContent / attribute setters.
 * Every chart has a keyboard path (focus + arrow keys, Enter opens the log) and a table view.
 */
( function () {
	'use strict';

	const cfg = window.mailspurInsights;
	if ( ! cfg ) {
		return;
	}

	const t = cfg.i18n;
	// The SVG namespace URI, taken from a parsed element (keeps external-looking URLs out of the bundle).
	const SVG = ( () => {
		const tmp = document.createElement( 'div' );
		tmp.innerHTML = '<svg></svg>';
		return tmp.firstChild.namespaceURI;
	} )();
	const STATUSES = [ 'sent', 'failed', 'held', 'pending' ];
	// Validated categorical slots (light surface); order = stack order bottom → top.
	const COLORS = { sent: '#5b3ff0', failed: '#e0442b', held: '#b4a6ff', pending: '#eda100' };
	// Sequential violet ramp (brand) for the heatmap, light → dark; zero gets a neutral gray.
	const RAMP = [ '#e9e4ff', '#c4b6ff', '#9781ff', '#6c4bff', '#3f24c4' ];
	const ZERO = '#f0f0f1';

	const locale = cfg.locale || undefined;
	const fmt = ( str, ...args ) => {
		let i = 0;
		return str.replace( /%(\d\$)?s/g, ( _, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : i++ ] ) );
	};
	const numFormat = new Intl.NumberFormat( locale );
	const num = ( n ) => numFormat.format( n );
	const dec = ( n, digits ) => new Intl.NumberFormat( locale, { minimumFractionDigits: digits, maximumFractionDigits: digits } ).format( n );
	const pct = ( n ) => dec( n, 1 ) + ' %';

	function node( tag, cls, text ) {
		const el = document.createElement( tag );
		if ( cls ) {
			el.className = cls;
		}
		if ( undefined !== text && null !== text ) {
			el.textContent = String( text );
		}
		return el;
	}

	function svg( tag, attrs, text ) {
		const el = document.createElementNS( SVG, tag );
		Object.keys( attrs || {} ).forEach( ( key ) => el.setAttribute( key, String( attrs[ key ] ) ) );
		if ( undefined !== text ) {
			el.textContent = String( text );
		}
		return el;
	}

	function endpoint( path, params ) {
		const url = new URL( cfg.restUrl, location.href );
		// Sites without pretty permalinks use ?rest_route=/mailspur-email-log/v1.
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/?$/, '/' ) + path;
		}
		Object.entries( params || {} ).forEach( ( [ key, value ] ) => {
			if ( value ) {
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

	/* ------------------------------------------------------------------ dates */

	// Dates are plain "YYYY-MM-DD" strings (site-local days); computed in UTC so DST never shifts them.
	const toDate = ( iso ) => new Date( iso + 'T00:00:00Z' );
	const toIso = ( date ) => date.toISOString().slice( 0, 10 );
	const addDays = ( iso, n ) => {
		const d = toDate( iso );
		d.setUTCDate( d.getUTCDate() + n );
		return toIso( d );
	};
	const dateFormat = ( options ) => new Intl.DateTimeFormat( locale, Object.assign( { timeZone: 'UTC' }, options ) );
	const shortDate = dateFormat( { day: 'numeric', month: 'short' } );
	const longDate = dateFormat( { day: 'numeric', month: 'short', year: 'numeric' } );
	const weekdayDate = dateFormat( { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
	const showShort = ( iso ) => shortDate.format( toDate( iso ) );
	const showLong = ( iso ) => longDate.format( toDate( iso ) );
	const hourLabel = ( h ) => new Intl.DateTimeFormat( locale, { hour: 'numeric', timeZone: 'UTC' } ).format( new Date( Date.UTC( 2020, 0, 1, h ) ) );

	function logUrl( params ) {
		const url = new URL( cfg.logUrl, location.href );
		Object.entries( params ).forEach( ( [ key, value ] ) => {
			if ( value ) {
				url.searchParams.set( key, value );
			}
		} );
		return url.toString();
	}

	/* ------------------------------------------------------------ test alert */

	const testButton = document.getElementById( 'mailspur-test-alert' );
	if ( testButton ) {
		const result = document.getElementById( 'mailspur-test-result' );
		testButton.addEventListener( 'click', async () => {
			testButton.disabled = true;
			result.classList.remove( 'is-error' );
			result.textContent = t.testSending;
			try {
				const res = await api( 'alerts/test', { method: 'POST' } );
				const parts = [];
				if ( null !== res.email ) {
					parts.push( res.email ? t.testEmailOk : t.testEmailFail );
				}
				if ( null !== res.webhook ) {
					parts.push( fmt( t.testWebhook, 'number' === typeof res.webhook ? 'HTTP ' + res.webhook : res.webhook ) );
				}
				result.textContent = parts.join( ' · ' );
				result.classList.toggle( 'is-error', false === res.email || ( null !== res.webhook && ! ( res.webhook >= 200 && res.webhook < 300 ) ) );
			} catch ( err ) {
				result.textContent = fmt( t.requestFailed, err.message );
				result.classList.add( 'is-error' );
			} finally {
				testButton.disabled = false;
			}
		} );
	}

	/* --------------------------------------------------------- weekly report */

	const reportButton = document.getElementById( 'mailspur-send-report' );
	if ( reportButton ) {
		const result = document.getElementById( 'mailspur-report-result' );
		reportButton.addEventListener( 'click', async () => {
			reportButton.disabled = true;
			result.classList.remove( 'is-error' );
			result.textContent = t.reportSending;
			try {
				const res = await api( 'alerts/report', { method: 'POST' } );
				result.textContent = res.sent ? t.testEmailOk : t.testEmailFail;
				result.classList.toggle( 'is-error', ! res.sent );
			} catch ( err ) {
				result.textContent = fmt( t.requestFailed, err.message );
				result.classList.add( 'is-error' );
			} finally {
				reportButton.disabled = false;
			}
		} );
	}

	/* ------------------------------------------------------------ statistics */

	const root = document.getElementById( 'mailspur-insights' );
	if ( ! root ) {
		return;
	}

	const $ = ( id ) => document.getElementById( id );
	const el = {
		body: $( 'msi-body' ),
		error: $( 'msi-error' ),
		range: $( 'msi-range' ),
		custom: $( 'msi-custom' ),
		from: $( 'msi-from' ),
		to: $( 'msi-to' ),
		kpis: $( 'msi-kpis' ),
		volume: $( 'msi-volume' ),
		volumeSub: $( 'msi-volume-sub' ),
		volumeLegend: $( 'msi-volume-legend' ),
		rate: $( 'msi-rate' ),
		heat: $( 'msi-heat' ),
		heatScale: $( 'msi-heat-scale' ),
		sources: $( 'msi-sources' ),
		domains: $( 'msi-domains' ),
		subjects: $( 'msi-subjects' ),
		sample: $( 'msi-sample' ),
		tooltip: $( 'msi-tooltip' ),
	};
	const live = node( 'span', 'screen-reader-text' );
	live.setAttribute( 'aria-live', 'polite' );
	root.append( live );

	const PRESETS = [ '7', '30', '90', '365' ];
	const state = readUrl();
	let data = null;
	let controller = null;

	function readUrl() {
		const params = new URLSearchParams( location.search );
		const valid = ( v ) => /^\d{4}-\d{2}-\d{2}$/.test( v || '' );
		if ( 'custom' === params.get( 'range' ) && valid( params.get( 'from' ) ) && valid( params.get( 'to' ) ) ) {
			return { range: 'custom', from: params.get( 'from' ), to: params.get( 'to' ) };
		}
		return { range: PRESETS.includes( params.get( 'range' ) ) ? params.get( 'range' ) : '30' };
	}

	function writeUrl() {
		const url = new URL( location.href );
		url.searchParams.set( 'range', state.range );
		[ 'from', 'to' ].forEach( ( key ) => {
			if ( 'custom' === state.range ) {
				url.searchParams.set( key, state[ key ] );
			} else {
				url.searchParams.delete( key );
			}
		} );
		history.replaceState( null, '', url );
	}

	function period() {
		if ( 'custom' === state.range ) {
			return { from: state.from, to: state.to };
		}
		return { from: addDays( cfg.today, 1 - parseInt( state.range, 10 ) ), to: cfg.today };
	}

	function syncControls() {
		root.querySelectorAll( '[data-range]' ).forEach( ( button ) => {
			button.setAttribute( 'aria-pressed', String( button.dataset.range === state.range ) );
		} );
		const p = period();
		el.custom.hidden = 'custom' !== state.range;
		el.from.value = p.from;
		el.to.value = p.to;
		el.from.max = cfg.today;
		el.to.max = cfg.today;
	}

	async function load() {
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();
		syncControls();
		writeUrl();
		root.setAttribute( 'aria-busy', 'true' );
		el.body.classList.add( 'is-loading' );
		try {
			data = await api( 'stats', { params: period(), signal: controller.signal } );
			el.error.hidden = true;
			render();
		} catch ( err ) {
			if ( 'AbortError' === err.name ) {
				return;
			}
			el.error.querySelector( 'p' ).textContent = fmt( t.requestFailed, err.message );
			el.error.hidden = false;
		} finally {
			root.setAttribute( 'aria-busy', 'false' );
			el.body.classList.remove( 'is-loading' );
		}
	}

	root.querySelectorAll( '[data-range]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			if ( 'custom' === button.dataset.range ) {
				const p = period();
				Object.assign( state, { range: 'custom', from: p.from, to: p.to } );
				syncControls();
				el.from.focus();
				return;
			}
			state.range = button.dataset.range;
			load();
		} );
	} );

	el.custom.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		let from = el.from.value;
		let to = el.to.value;
		if ( ! from || ! to ) {
			return;
		}
		if ( from > to ) {
			[ from, to ] = [ to, from ];
		}
		Object.assign( state, { range: 'custom', from, to } );
		load();
	} );

	/* ---------------------------------------------------------------- render */

	function render() {
		if ( ! data ) {
			return;
		}
		const range = data.range;
		el.range.textContent = showLong( range.from ) + ' – ' + showLong( range.to );
		renderKpis();

		const buckets = bucketize( data.days, range );
		const weekly = buckets.weekly;
		el.volumeSub.textContent = data.totals.all ? t.openLog : '';
		renderLegend();
		renderVolume( buckets.list, weekly );
		renderRate( buckets.list, weekly );
		renderHeatmap();
		renderBars( el.sources, data.top.sources, 'key', 'source' );
		renderBars( el.domains, data.top.domains, 'search' );
		renderBars( el.subjects, data.top.subjects, 'search' );
		el.sample.hidden = ! data.sample.limited;
		el.sample.textContent = fmt( t.sample, num( data.sample.rows ) );
		lastWidth = el.body.clientWidth;
	}

	/* KPI tiles ------------------------------------------------------------ */

	function delta( current, previous, upIsBad ) {
		const prev = data.previous;
		const span = node( 'span', 'msi-kpi-delta' );
		let text;
		let dir = 0;
		if ( 0 === previous ) {
			text = current > 0 ? fmt( t.deltaNew, showShort( prev.from ), showShort( prev.to ) ) : t.deltaSame;
		} else {
			const change = ( current - previous ) / previous;
			dir = Math.sign( Math.round( change * 100 ) );
			const value = new Intl.NumberFormat( locale, { style: 'percent', maximumFractionDigits: 0, signDisplay: 'exceptZero' } ).format( change );
			text = 0 === dir ? t.deltaSame : fmt( t.delta, value, showShort( prev.from ), showShort( prev.to ) );
		}
		decorateDelta( span, dir, upIsBad, text );
		return span;
	}

	function decorateDelta( span, dir, upIsBad, text ) {
		span.classList.add( dir > 0 ? 'is-up' : dir < 0 ? 'is-down' : 'is-flat' );
		if ( null !== upIsBad && 0 !== dir ) {
			span.classList.add( ( dir > 0 ) === upIsBad ? 'is-bad' : 'is-good' );
		}
		const arrow = node( 'span', 'msi-arrow', dir > 0 ? '▲' : dir < 0 ? '▼' : '–' );
		arrow.setAttribute( 'aria-hidden', 'true' );
		span.append( arrow, ' ', text );
	}

	function tile( label, value, ...extra ) {
		const div = node( 'div', 'msi-kpi' );
		div.append( node( 'span', 'msi-kpi-label', label ), node( 'span', 'msi-kpi-value', value ) );
		extra.filter( Boolean ).forEach( ( item ) => div.append( 'string' === typeof item ? node( 'span', 'msi-kpi-sub', item ) : item ) );
		return div;
	}

	function renderKpis() {
		const totals = data.totals;
		const prev = data.previous;

		const rateDelta = node( 'span', 'msi-kpi-delta' );
		const points = Math.round( ( totals.rate - prev.rate ) * 10 ) / 10;
		const rateText = 0 === points ? t.deltaSame : fmt( t.deltaPoints, ( points > 0 ? '+' : '−' ) + dec( Math.abs( points ), 1 ), showShort( prev.from ), showShort( prev.to ) );
		decorateDelta( rateDelta, Math.sign( points ), true, rateText );

		el.kpis.replaceChildren(
			tile( t.emails, num( totals.all ), delta( totals.all, prev.all, null ) ),
			tile( t.failed, num( totals.failed ), totals.all ? fmt( t.rateOf, pct( totals.rate ) ) : '', rateDelta ),
			tile( t.held, num( totals.held ), totals.pending ? t.pending + ': ' + num( totals.pending ) : '' ),
			tile( t.average, dec( data.average, data.average < 10 ? 1 : 0 ), fmt( t.perDay, num( totals.all ), num( data.range.days ) ) ),
			tile( t.busiest, data.busiest ? num( data.busiest.count ) : '–', data.busiest ? weekdayDate.format( toDate( data.busiest.date ) ) : t.noData )
		);
	}

	/* Buckets (daily, weekly for long ranges) --------------------------------- */

	function bucketize( days, range ) {
		const weekly = days.length > 92;
		const list = [];
		const map = new Map();
		days.forEach( ( day ) => {
			let key = day.date;
			if ( weekly ) {
				const dow = toDate( day.date ).getUTCDay();
				key = addDays( day.date, -( ( dow - cfg.startOfWeek + 7 ) % 7 ) );
			}
			let bucket = map.get( key );
			if ( ! bucket ) {
				bucket = { key, from: day.date < range.from ? range.from : day.date, to: day.date, sent: 0, failed: 0, held: 0, pending: 0, total: 0 };
				map.set( key, bucket );
				list.push( bucket );
			}
			bucket.to = day.date;
			STATUSES.forEach( ( s ) => {
				bucket[ s ] += day[ s ];
				bucket.total += day[ s ];
			} );
		} );
		list.forEach( ( b ) => {
			b.label = weekly ? fmt( t.weekOf, showLong( b.key ) ) : weekdayDate.format( toDate( b.key ) );
			b.tick = showShort( b.key );
			b.rate = b.total ? ( 100 * b.failed ) / b.total : null;
		} );
		return { list, weekly };
	}

	/* Shared chart pieces -------------------------------------------------- */

	function niceMax( max ) {
		if ( max <= 0 ) {
			return { max: 4, step: 1 };
		}
		const raw = max / 4;
		const mag = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		const step = [ 1, 2, 2.5, 5, 10 ].map( ( m ) => m * mag ).find( ( s ) => s >= raw && ( s >= 1 || mag < 1 ) ) || 10 * mag;
		return { max: step * Math.ceil( max / step ), step };
	}

	function barPath( x, y, w, h, r ) {
		const f = ( n ) => Math.round( n * 100 ) / 100;
		if ( r <= 0 ) {
			return `M${ f( x ) } ${ f( y + h ) }V${ f( y ) }H${ f( x + w ) }V${ f( y + h ) }Z`;
		}
		return `M${ f( x ) } ${ f( y + h ) }V${ f( y + r ) }Q${ f( x ) } ${ f( y ) } ${ f( x + r ) } ${ f( y ) }H${ f( x + w - r ) }Q${ f( x + w ) } ${ f( y ) } ${ f( x + w ) } ${ f( y + r ) }V${ f( y + h ) }Z`;
	}

	function yAxis( g, m, plotW, plotH, scale, label ) {
		for ( let v = 0; v <= scale.max + 1e-9; v += scale.step ) {
			const y = m.t + plotH - ( v / scale.max ) * plotH;
			g.append( svg( 'line', { x1: m.l, x2: m.l + plotW, y1: Math.round( y ) + 0.5, y2: Math.round( y ) + 0.5, class: 0 === v ? 'msi-axis' : 'msi-gridline' } ) );
			g.append( svg( 'text', { x: m.l - 8, y: y + 4, 'text-anchor': 'end', class: 'msi-tick' }, label( v ) ) );
		}
	}

	function xTicks( g, m, plotH, band, buckets, minGap ) {
		const step = Math.max( 1, Math.ceil( minGap / band ) );
		buckets.forEach( ( b, i ) => {
			if ( 0 === i % step ) {
				g.append( svg( 'text', { x: m.l + i * band + band / 2, y: m.t + plotH + 18, 'text-anchor': 'middle', class: 'msi-tick' }, b.tick ) );
			}
		} );
	}

	/** Tooltip: one title line, then rows of [color key, value, label]. */
	function showTip( title, rows, anchor ) {
		const tip = el.tooltip;
		tip.replaceChildren( node( 'strong', 'msi-tip-title', title ) );
		rows.forEach( ( row ) => {
			const line = node( 'span', 'msi-tip-row' );
			if ( row.color ) {
				const key = node( 'span', 'msi-tip-key' );
				key.style.background = row.color;
				line.append( key );
			}
			line.append( node( 'b', '', row.value ), ' ', node( 'span', 'msi-tip-label', row.label ) );
			tip.append( line );
		} );
		tip.hidden = false;
		const box = root.getBoundingClientRect();
		const a = anchor.getBoundingClientRect();
		const left = Math.min( Math.max( 0, a.left - box.left + a.width / 2 - tip.offsetWidth / 2 ), box.width - tip.offsetWidth );
		let top = a.top - box.top - tip.offsetHeight - 8;
		if ( top < 0 ) {
			top = a.bottom - box.top + 8;
		}
		tip.style.left = Math.round( left ) + 'px';
		tip.style.top = Math.round( top ) + 'px';
	}

	function hideTip() {
		el.tooltip.hidden = true;
	}

	function announce( title, rows ) {
		live.textContent = title + ': ' + rows.map( ( r ) => r.value + ' ' + r.label ).join( ', ' );
	}

	/**
	 * Index-based interaction for column charts: pointer snaps to the nearest column, the focused chart
	 * moves with ←/→/Home/End, Enter or click opens the log for that column.
	 */
	function interact( wrap, svgEl, count, geometry, onActive, onOpen ) {
		let active = -1;
		const set = ( i, fromKeyboard ) => {
			if ( i < 0 || i >= count ) {
				return;
			}
			active = i;
			onActive( i, fromKeyboard );
		};
		const indexAt = ( event ) => {
			const rect = svgEl.getBoundingClientRect();
			const x = event.clientX - rect.left;
			return Math.max( 0, Math.min( count - 1, Math.floor( ( x - geometry.left ) / geometry.band ) ) );
		};
		svgEl.addEventListener( 'pointermove', ( event ) => set( indexAt( event ), false ) );
		svgEl.addEventListener( 'pointerleave', () => {
			if ( document.activeElement !== wrap ) {
				onActive( -1 );
				active = -1;
			}
		} );
		svgEl.addEventListener( 'click', ( event ) => {
			if ( onOpen ) {
				onOpen( indexAt( event ) );
			}
		} );
		wrap.addEventListener( 'keydown', ( event ) => {
			const keys = { ArrowRight: active + 1, ArrowLeft: active - 1, Home: 0, End: count - 1 };
			if ( event.key in keys ) {
				event.preventDefault();
				set( active < 0 && 'ArrowLeft' === event.key ? count - 1 : Math.max( 0, keys[ event.key ] ), true );
			} else if ( 'Enter' === event.key && active >= 0 && onOpen ) {
				onOpen( active );
			} else if ( 'Escape' === event.key ) {
				onActive( -1 );
			}
		} );
		wrap.addEventListener( 'focus', () => set( active < 0 ? count - 1 : active, true ) );
		wrap.addEventListener( 'blur', () => onActive( -1 ) );
	}

	function chartWrap( container, label, hint ) {
		container.replaceChildren();
		const wrap = node( 'div', 'msi-plot' );
		wrap.tabIndex = 0;
		wrap.setAttribute( 'role', 'group' );
		wrap.setAttribute( 'aria-label', label + '. ' + hint );
		container.append( wrap );
		return wrap;
	}

	function table( details, head, rows ) {
		details.querySelector( 'summary' ).textContent = t.showTable;
		const tableEl = node( 'table', 'widefat striped' );
		const thead = node( 'thead' );
		const tr = node( 'tr' );
		head.forEach( ( h ) => {
			const th = node( 'th', '', h );
			th.scope = 'col';
			tr.append( th );
		} );
		thead.append( tr );
		const tbody = node( 'tbody' );
		rows.forEach( ( cells ) => {
			const row = node( 'tr' );
			cells.forEach( ( c, i ) => {
				const cell = node( 0 === i ? 'th' : 'td', '', c );
				if ( 0 === i ) {
					cell.scope = 'row';
				}
				row.append( cell );
			} );
			tbody.append( row );
		} );
		tableEl.append( thead, tbody );
		details.querySelector( 'div' ).replaceChildren( tableEl );
	}

	function empty( container ) {
		container.replaceChildren( node( 'p', 'msi-empty', t.noData ) );
	}

	function openBucket( b, extra ) {
		location.href = logUrl( Object.assign( { after: b.from, before: b.to }, extra || {} ) );
	}

	/* Emails over time: stacked columns -------------------------------------- */

	function renderLegend() {
		el.volumeLegend.replaceChildren(
			...STATUSES.map( ( s ) => {
				const li = node( 'li' );
				const sw = node( 'span', 'msi-swatch' );
				sw.style.background = COLORS[ s ];
				li.append( sw, t[ s ] );
				return li;
			} )
		);
	}

	function renderVolume( buckets, weekly ) {
		const details = el.volume.parentElement.querySelector( 'details' );
		table(
			details,
			[ weekly ? t.week : t.date, t.sent, t.failed, t.held, t.pending, t.total ],
			buckets.map( ( b ) => [ b.label, num( b.sent ), num( b.failed ), num( b.held ), num( b.pending ), num( b.total ) ] )
		);
		if ( ! data.totals.all ) {
			empty( el.volume );
			return;
		}

		const wrap = chartWrap( el.volume, el.volume.closest( 'section' ).querySelector( 'h2' ).textContent, t.chartLabel );
		const width = Math.max( 280, wrap.clientWidth );
		const height = 240;
		const m = { t: 12, r: 8, b: 28, l: 48 };
		const plotW = width - m.l - m.r;
		const plotH = height - m.t - m.b;
		const band = plotW / buckets.length;
		const barW = Math.max( 1, Math.min( 24, band - 2 ) );
		const scale = niceMax( Math.max( ...buckets.map( ( b ) => b.total ) ) );

		const root2 = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, class: 'msi-svg', 'aria-hidden': 'true', focusable: 'false' } );
		const grid = svg( 'g' );
		yAxis( grid, m, plotW, plotH, scale, ( v ) => num( v ) );
		const hover = svg( 'rect', { x: 0, y: m.t, width: band, height: plotH, class: 'msi-hover', visibility: 'hidden' } );
		const marks = svg( 'g' );
		const columns = buckets.map( ( b, i ) => {
			const g = svg( 'g', { class: 'msi-col' } );
			const x = m.l + i * band + ( band - barW ) / 2;
			let y = m.t + plotH;
			const parts = STATUSES.filter( ( s ) => b[ s ] > 0 );
			parts.forEach( ( s, k ) => {
				const gap = k > 0 ? 2 : 0;
				const h = Math.max( 1, ( b[ s ] / scale.max ) * plotH - gap );
				y -= h + gap;
				const r = k === parts.length - 1 ? Math.min( 4, h, barW / 2 ) : 0;
				g.append( svg( 'path', { d: barPath( x, y, barW, h, r ), fill: COLORS[ s ] } ) );
			} );
			marks.append( g );
			return g;
		} );
		const ticks = svg( 'g' );
		xTicks( ticks, m, plotH, band, buckets, weekly ? 64 : 56 );
		root2.append( grid, hover, marks, ticks );
		wrap.append( root2 );

		const rowsFor = ( b ) => STATUSES.filter( ( s ) => b[ s ] > 0 ).map( ( s ) => ( { color: COLORS[ s ], value: num( b[ s ] ), label: t[ s ] } ) ).concat( [ { value: num( b.total ), label: t.total } ] );
		interact(
			wrap,
			root2,
			buckets.length,
			{ left: m.l, band },
			( i, fromKeyboard ) => {
				columns.forEach( ( g, k ) => g.classList.toggle( 'is-active', k === i ) );
				if ( i < 0 ) {
					hover.setAttribute( 'visibility', 'hidden' );
					hideTip();
					return;
				}
				hover.setAttribute( 'x', m.l + i * band );
				hover.setAttribute( 'visibility', 'visible' );
				const rows = rowsFor( buckets[ i ] );
				showTip( buckets[ i ].label, rows, hover );
				if ( fromKeyboard ) {
					announce( buckets[ i ].label, rows );
				}
			},
			( i ) => openBucket( buckets[ i ] )
		);
	}

	/* Failure rate: line --------------------------------------------------- */

	function renderRate( buckets, weekly ) {
		const details = el.rate.parentElement.querySelector( 'details' );
		table(
			details,
			[ weekly ? t.week : t.date, t.failureRate, t.failed, t.total ],
			buckets.map( ( b ) => [ b.label, null === b.rate ? t.noRate : pct( b.rate ), num( b.failed ), num( b.total ) ] )
		);
		if ( ! data.totals.all ) {
			empty( el.rate );
			return;
		}

		const wrap = chartWrap( el.rate, t.failureRate, t.chartLabel );
		const width = Math.max( 280, wrap.clientWidth );
		const height = 150;
		const m = { t: 12, r: 8, b: 28, l: 48 };
		const plotW = width - m.l - m.r;
		const plotH = height - m.t - m.b;
		const band = plotW / buckets.length;
		const maxRate = Math.max( ...buckets.map( ( b ) => b.rate || 0 ) );
		const scale = niceMax( Math.max( 1, maxRate ) );
		const x = ( i ) => m.l + i * band + band / 2;
		const y = ( v ) => m.t + plotH - ( v / scale.max ) * plotH;

		const root2 = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, class: 'msi-svg', 'aria-hidden': 'true', focusable: 'false' } );
		const grid = svg( 'g' );
		yAxis( grid, m, plotW, plotH, scale, ( v ) => dec( v, scale.step < 1 ? 1 : 0 ) + ' %' );
		root2.append( grid );

		// Runs of consecutive days with emails; days without emails leave a gap (no rate, not 0 %).
		const runs = [];
		let run = [];
		buckets.forEach( ( b, i ) => {
			if ( null === b.rate ) {
				if ( run.length ) {
					runs.push( run );
				}
				run = [];
			} else {
				run.push( i );
			}
		} );
		if ( run.length ) {
			runs.push( run );
		}
		runs.forEach( ( r ) => {
			const line = r.map( ( i, k ) => ( k ? 'L' : 'M' ) + x( i ).toFixed( 2 ) + ' ' + y( buckets[ i ].rate ).toFixed( 2 ) ).join( '' );
			const area = line + `L${ x( r[ r.length - 1 ] ).toFixed( 2 ) } ${ m.t + plotH }L${ x( r[ 0 ] ).toFixed( 2 ) } ${ m.t + plotH }Z`;
			root2.append( svg( 'path', { d: area, class: 'msi-area' } ) );
			root2.append( svg( 'path', { d: line, class: 'msi-line' } ) );
			if ( 1 === r.length ) {
				root2.append( svg( 'circle', { cx: x( r[ 0 ] ), cy: y( buckets[ r[ 0 ] ].rate ), r: 3, class: 'msi-dot-small' } ) );
			}
		} );

		const crosshair = svg( 'line', { x1: 0, x2: 0, y1: m.t, y2: m.t + plotH, class: 'msi-crosshair', visibility: 'hidden' } );
		const dot = svg( 'circle', { cx: 0, cy: 0, r: 4, class: 'msi-dot', visibility: 'hidden' } );
		const ticks = svg( 'g' );
		xTicks( ticks, m, plotH, band, buckets, weekly ? 64 : 56 );
		root2.append( crosshair, dot, ticks );
		wrap.append( root2 );

		interact(
			wrap,
			root2,
			buckets.length,
			{ left: m.l, band },
			( i, fromKeyboard ) => {
				if ( i < 0 ) {
					crosshair.setAttribute( 'visibility', 'hidden' );
					dot.setAttribute( 'visibility', 'hidden' );
					hideTip();
					return;
				}
				const b = buckets[ i ];
				crosshair.setAttribute( 'x1', x( i ) );
				crosshair.setAttribute( 'x2', x( i ) );
				crosshair.setAttribute( 'visibility', 'visible' );
				dot.setAttribute( 'visibility', null === b.rate ? 'hidden' : 'visible' );
				if ( null !== b.rate ) {
					dot.setAttribute( 'cx', x( i ) );
					dot.setAttribute( 'cy', y( b.rate ) );
				}
				const rows = null === b.rate
					? [ { value: '–', label: t.noRate } ]
					: [ { color: COLORS.failed, value: pct( b.rate ), label: t.failureRate }, { value: num( b.failed ), label: t.failed }, { value: num( b.total ), label: t.total } ];
				showTip( b.label, rows, null === b.rate ? crosshair : dot );
				if ( fromKeyboard ) {
					announce( b.label, rows );
				}
			},
			( i ) => buckets[ i ].failed && openBucket( buckets[ i ], { status: 'failed' } )
		);
	}

	/* Heatmap weekday × hour ------------------------------------------------ */

	function renderHeatmap() {
		const order = [ 0, 1, 2, 3, 4, 5, 6 ].map( ( k ) => ( k + cfg.startOfWeek ) % 7 );
		const matrix = data.heatmap;
		const max = Math.max( 0, ...matrix.flat() );
		const color = ( v ) => ( v <= 0 ? ZERO : RAMP[ Math.min( RAMP.length - 1, Math.max( 0, Math.floor( ( v / max ) * RAMP.length - 1e-9 ) ) ) ] );
		const hours = [ ...Array( 24 ).keys() ];
		const details = el.heat.parentElement.querySelector( 'details' );
		table( details, [ t.weekday ].concat( hours.map( hourLabel ) ), order.map( ( d ) => [ t.weekdays[ d ] ].concat( matrix[ d ].map( num ) ) ) );

		// Scale legend: None, then the five ramp steps from fewer to more.
		const scale = [ node( 'span', 'msi-scale-label', t.none ) ];
		const zero = node( 'span', 'msi-swatch' );
		zero.style.background = ZERO;
		scale.push( zero, node( 'span', 'msi-scale-label', t.fewer ) );
		RAMP.forEach( ( c ) => {
			const sw = node( 'span', 'msi-swatch' );
			sw.style.background = c;
			scale.push( sw );
		} );
		scale.push( node( 'span', 'msi-scale-label', t.more ) );
		el.heatScale.replaceChildren( ...scale );
		el.heatScale.setAttribute( 'aria-hidden', 'true' );

		if ( ! data.totals.all ) {
			empty( el.heat );
			return;
		}

		const wrap = chartWrap( el.heat, el.heat.closest( 'section' ).querySelector( 'h2' ).textContent, t.heatmapLabel );
		const width = Math.max( 280, wrap.clientWidth );
		const narrow = width < 560;
		const labelW = narrow ? 36 : 96;
		const cell = ( width - labelW ) / 24;
		const cellH = Math.max( 14, Math.min( 28, cell ) );
		const top = 20;
		const height = top + 7 * cellH;
		const root2 = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, class: 'msi-svg', 'aria-hidden': 'true', focusable: 'false' } );
		const dayName = ( d ) => ( narrow ? t.weekdays[ d ].slice( 0, 2 ) : t.weekdays[ d ] );
		hours.forEach( ( h ) => {
			if ( 0 === h % ( narrow ? 6 : 3 ) ) {
				root2.append( svg( 'text', { x: labelW + h * cell + 1, y: 13, class: 'msi-tick' }, hourLabel( h ) ) );
			}
		} );
		const cells = [];
		order.forEach( ( d, row ) => {
			root2.append( svg( 'text', { x: labelW - 8, y: top + row * cellH + cellH / 2 + 4, 'text-anchor': 'end', class: 'msi-tick' }, dayName( d ) ) );
			cells[ row ] = hours.map( ( h ) => {
				const rect = svg( 'rect', {
					x: labelW + h * cell + 1,
					y: top + row * cellH + 1,
					width: Math.max( 1, cell - 2 ),
					height: Math.max( 1, cellH - 2 ),
					rx: 2,
					fill: color( matrix[ d ][ h ] ),
					'data-row': row,
					'data-hour': h,
					class: 'msi-cell',
				} );
				root2.append( rect );
				return rect;
			} );
		} );
		wrap.append( root2 );

		let active = null;
		const set = ( row, hour, fromKeyboard ) => {
			if ( active ) {
				active.classList.remove( 'is-active' );
			}
			if ( null === row ) {
				active = null;
				hideTip();
				return;
			}
			active = cells[ row ][ hour ];
			active.classList.add( 'is-active' );
			const d = order[ row ];
			const title = fmt( t.cell, t.weekdays[ d ], hourLabel( hour ) );
			const rows = [ { color: color( matrix[ d ][ hour ] ), value: num( matrix[ d ][ hour ] ), label: t.count } ];
			showTip( title, rows, active );
			if ( fromKeyboard ) {
				announce( title, rows );
			}
		};
		root2.addEventListener( 'pointermove', ( event ) => {
			const target = event.target;
			if ( target.dataset && undefined !== target.dataset.row ) {
				set( parseInt( target.dataset.row, 10 ), parseInt( target.dataset.hour, 10 ), false );
			}
		} );
		root2.addEventListener( 'pointerleave', () => document.activeElement !== wrap && set( null ) );
		wrap.addEventListener( 'focus', () => set( active ? parseInt( active.dataset.row, 10 ) : 0, active ? parseInt( active.dataset.hour, 10 ) : 0, true ) );
		wrap.addEventListener( 'blur', () => set( null ) );
		wrap.addEventListener( 'keydown', ( event ) => {
			const row = active ? parseInt( active.dataset.row, 10 ) : 0;
			const hour = active ? parseInt( active.dataset.hour, 10 ) : 0;
			const moves = { ArrowRight: [ 0, 1 ], ArrowLeft: [ 0, -1 ], ArrowDown: [ 1, 0 ], ArrowUp: [ -1, 0 ] };
			if ( event.key in moves ) {
				event.preventDefault();
				set( Math.max( 0, Math.min( 6, row + moves[ event.key ][ 0 ] ) ), Math.max( 0, Math.min( 23, hour + moves[ event.key ][ 1 ] ) ), true );
			} else if ( 'Escape' === event.key ) {
				set( null );
			}
		} );
	}

	/* Top lists: horizontal bars (HTML) ------------------------------------- */

	/**
	 * @param {string|null} linkKey Item field that filters the log, e.g. 'search'.
	 * @param {string}      param   Log URL parameter it goes into ('s' search, 'source' sender filter).
	 */
	function renderBars( list, items, linkKey, param = 's' ) {
		if ( ! items.length ) {
			list.replaceChildren( node( 'li', 'msi-empty', t.noData ) );
			return;
		}
		const max = Math.max( ...items.map( ( i ) => i.count ) );
		const range = data.range;
		list.replaceChildren(
			...items.map( ( item ) => {
				const li = node( 'li' );
				const label = item.label || t.noSubject;
				const row = linkKey && item[ linkKey ] ? node( 'a', 'msi-bar' ) : node( 'div', 'msi-bar' );
				if ( 'A' === row.tagName ) {
					row.href = logUrl( { [ param ]: item[ linkKey ], after: range.from, before: range.to } );
					row.setAttribute( 'aria-label', fmt( t.openInLog, label ) + ': ' + num( item.count ) );
				}
				const head = node( 'span', 'msi-bar-head' );
				const value = node( 'span', 'msi-bar-value', num( item.count ) );
				if ( item.failed ) {
					value.append( ' ', node( 'span', 'msi-bar-failed', fmt( t.failedCount, num( item.failed ) ) ) );
				}
				head.append( node( 'span', 'msi-bar-label', label ), value );
				const track = node( 'span', 'msi-bar-track' );
				const fill = node( 'span', 'msi-bar-fill' );
				fill.style.width = Math.max( 1, ( 100 * item.count ) / max ).toFixed( 2 ) + '%';
				track.append( fill );
				track.setAttribute( 'aria-hidden', 'true' );
				row.append( head, track );
				li.append( row );
				return li;
			} )
		);
	}

	/* Resize --------------------------------------------------------------- */

	let lastWidth = 0;
	let frame = 0;
	if ( window.ResizeObserver ) {
		new ResizeObserver( () => {
			cancelAnimationFrame( frame );
			frame = requestAnimationFrame( () => {
				if ( data && el.body.clientWidth !== lastWidth ) {
					hideTip();
					render();
				}
			} );
		} ).observe( el.body );
	}

	load();
} )();
