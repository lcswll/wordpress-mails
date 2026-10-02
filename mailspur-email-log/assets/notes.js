/**
 * Mailspur – notes module (log tab).
 *
 * - List: badge "⚠ n" in the subject cell (color of the worst note) and a short explanation under errors.
 * - Dialog: "Notes" tab grouped by severity, explanation + steps under the error message.
 *
 * Uses the window.mailspur module API; all text is inserted via textContent.
 */
( function () {
	'use strict';

	const m = window.mailspur;
	const cfg = window.mailspurNotes;
	if ( ! m || ! cfg ) {
		return;
	}

	const t = cfg.i18n;
	const ORDER = [ 'error', 'warning', 'info' ];
	let showNotesNext = false; // Badge clicked: open the dialog on the notes tab.

	m.registerRowDecorator( ( tr, item ) => {
		const subject = tr.querySelector( '.col-subject' );
		if ( ! subject ) {
			return;
		}
		if ( item.error_hint ) {
			subject.append( m.node( 'span', 'mailspur-notes-hint', item.error_hint ) );
		}

		const info = cfg.enabled && item.notes_info;
		if ( ! info ) {
			return;
		}
		const label = m.fmt( t.badge, m.num( info.count ), info.titles.join( '; ' ) );
		const badge = m.node( 'button', 'mailspur-notes-badge is-' + info.level );
		badge.type = 'button';
		badge.title = label;
		badge.setAttribute( 'aria-label', label );
		const icon = m.node( 'span', '', '⚠' );
		icon.setAttribute( 'aria-hidden', 'true' );
		badge.append( icon, ' ' + m.num( info.count ) );
		// No stopPropagation: the row's own click handler opens the dialog.
		badge.addEventListener( 'click', () => {
			showNotesNext = true;
		} );

		const open = subject.querySelector( '.mailspur-open' );
		if ( open ) {
			open.after( badge );
		} else {
			subject.append( badge );
		}
	} );

	m.registerDetail( ( dl, mail ) => {
		const help = mail.error_help;
		if ( help ) {
			const dd = m.node( 'dd', 'mailspur-notes-help' );
			dd.append( m.node( 'strong', '', help.title ), m.node( 'p', '', help.explanation ) );
			if ( help.steps && help.steps.length ) {
				const ol = m.node( 'ol' );
				help.steps.forEach( ( step ) => ol.append( m.node( 'li', '', step ) ) );
				dd.append( ol );
			}
			dl.append( m.node( 'dt', 'mailspur-notes-help-label', t.help ), dd );
		}

		if ( ! cfg.enabled ) {
			return;
		}
		const tab = document.getElementById( 'mailspur-tab-notes' );
		if ( tab ) {
			const list = mail.notes_list || [];
			tab.textContent = list.length ? t.tab + ' (' + m.num( list.length ) + ')' : t.tab;
			tab.className = list.length ? 'mailspur-notes-tab is-' + list[ 0 ].severity : 'mailspur-notes-tab';
			if ( showNotesNext ) {
				// The dialog renders its default view right after the details; switch afterwards.
				setTimeout( () => tab.click(), 0 );
			}
		}
		showNotesNext = false;
	} );

	if ( cfg.enabled ) {
		m.registerTab( { id: 'notes', label: t.tab, render: renderNotes } );
	}

	function renderNotes( container, mail ) {
		const wrap = m.node( 'div', 'mailspur-notes' );
		const list = mail.notes_list || [];
		wrap.append( m.node( 'p', 'mailspur-notes-intro', t.intro ) );

		if ( ! list.length ) {
			wrap.append( m.node( 'p', 'mailspur-notes-none', t.none ) );
		}

		ORDER.forEach( ( severity ) => {
			const notes = list.filter( ( n ) => n.severity === severity );
			if ( ! notes.length ) {
				return;
			}
			const section = m.node( 'section', 'mailspur-notes-group is-' + severity );
			section.append( m.node( 'h3', '', t[ severity ] + ' (' + m.num( notes.length ) + ')' ) );
			const ul = m.node( 'ul' );
			notes.forEach( ( n ) => {
				const li = m.node( 'li', 'mailspur-note is-' + severity );
				li.dataset.code = n.code;
				li.append( m.node( 'strong', 'mailspur-note-title', n.title ) );
				if ( n.dynamic ) {
					li.append( ' ', m.node( 'span', 'mailspur-note-dynamic', t.dynamic ) );
				}
				if ( n.text ) {
					li.append( m.node( 'p', '', n.text ) );
				}
				if ( n.fix ) {
					const fix = m.node( 'p', 'mailspur-note-fix' );
					fix.append( m.node( 'strong', '', t.fix ), ' ', n.fix );
					li.append( fix );
				}
				ul.append( li );
			} );
			section.append( ul );
			wrap.append( section );
		} );

		container.append( wrap );
	}
}() );
