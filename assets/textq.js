/* Sam's Class Manager — "Texts to send": one tap opens Messages, the card ticks itself off. */
( function () {
	'use strict';
	var D = window.SDSC_TQ || {};
	var ua = navigator.userAgent || '';
	var apple = /iPhone|iPad|iPod|Macintosh/.test( ua );
	var smsHref = function ( to, body ) { return 'sms:' + to + ( apple ? '&' : '?' ) + 'body=' + encodeURIComponent( body ); };
	var textOf = function ( a ) { var l = a.getAttribute( 'data-lang' ) || 'en'; return a.getAttribute( 'data-' + l ) || a.getAttribute( 'data-en' ) || ''; };
	var refresh = function ( a ) { a.href = smsHref( a.getAttribute( 'data-sms' ), textOf( a ) ); };
	var left = document.querySelector( '.sdq-left' );

	function post( k, st, or ) {
		var fd = new FormData();
		fd.append( 'action', 'sdsc_tq_mark' ); fd.append( '_ajax_nonce', D.nonce ); fd.append( 'k', k ); fd.append( 'st', st );
		if ( or ) { fd.append( 'or', or ); }
		return fetch( D.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } );
	}
	function count() {
		var n = document.querySelectorAll( '.sdq-card:not(.is-done)' ).length;
		if ( left ) { left.textContent = n; }
		if ( ! n ) {
			var nx = document.querySelector( '.sdq-next' ); if ( nx ) { nx.hidden = true; }
		}
	}
	function done( card, st ) {
		card.classList.add( 'is-done' );
		var a = card.querySelector( '.sdq-go' );
		post( card.getAttribute( 'data-k' ), st, a && a.getAttribute( 'data-or' ) );
		var note = document.createElement( 'p' );
		note.className = 'sdq-note';
		note.innerHTML = ( 'skip' === st ? 'Skipped' : '✓ Texted' ) + ' · <button type="button" class="button-link sdq-undo">Undo</button>';
		card.appendChild( note );
		count();
	}

	document.querySelectorAll( '.sdq-go' ).forEach( refresh );

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		var go = t.closest && t.closest( '.sdq-go' );
		if ( go ) {
			refresh( go );
			// Inside the dashboard frame a phone only opens Messages when the whole page goes to the sms: link.
			if ( window.top !== window ) { go.target = '_top'; }
			var card = go.closest( '.sdq-card' );
			setTimeout( function () { if ( ! card.classList.contains( 'is-done' ) ) { done( card, 'sent' ); } }, 400 );
			return; // let the sms: link open Messages
		}
		if ( t.classList.contains( 'sdq-skip' ) ) { done( t.closest( '.sdq-card' ), 'skip' ); return; }
		if ( t.classList.contains( 'sdq-undo' ) ) {
			var c = t.closest( '.sdq-card' );
			post( c.getAttribute( 'data-k' ), 'undo' );
			c.classList.remove( 'is-done' );
			t.closest( '.sdq-note' ).remove();
			var nx0 = document.querySelector( '.sdq-next' ); if ( nx0 ) { nx0.hidden = false; }
			count();
			return;
		}
		var set = t.getAttribute && t.getAttribute( 'data-set' );
		if ( set ) {
			var box = t.closest( '.sdq-card' );
			box.querySelectorAll( '[data-set]' ).forEach( function ( b ) { b.classList.toggle( 'is-on', b === t ); } );
			var a2 = box.querySelector( '.sdq-go' );
			a2.setAttribute( 'data-lang', set ); refresh( a2 );
			box.querySelector( '.sdo-bubble' ).textContent = textOf( a2 );
			return;
		}
		if ( t.classList.contains( 'sdq-next' ) ) {
			e.preventDefault();
			var next = document.querySelector( '.sdq-card:not(.is-done) .sdq-go' );
			if ( next ) { next.closest( '.sdq-card' ).scrollIntoView( { behavior: 'smooth', block: 'center' } ); next.click(); }
		}
	} );
	count();
} )();
