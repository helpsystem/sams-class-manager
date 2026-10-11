/* Sam's Class Manager — Instructors & cars board */
( function () {
	'use strict';
	var D = window.SDSC_BD || {};
	var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };
	var hm = function ( m ) { var h = Math.floor( m / 60 ) % 24, i = m % 60, ap = h >= 12 ? 'p' : 'a'; return ( h % 12 || 12 ) + ( i ? ':' + pad( i ) : '' ) + ap; };
	var e164 = function ( p ) { var d = String( p || '' ).replace( /\D/g, '' ); if ( 10 === d.length ) { d = '1' + d; } return 11 === d.length && '1' === d[0] ? '+' + d : ''; };
	var store = { get: function ( k ) { try { return window.localStorage.getItem( k ); } catch ( e ) { return null; } }, set: function ( k, v ) { try { window.localStorage.setItem( k, v ); } catch ( e ) {} } };

	/* ---------------- Day board */
	var grid = document.querySelector( '.sdb-grid' );
	if ( grid ) {
		var date = grid.dataset.date, g0 = parseInt( grid.dataset.g0, 10 ) || 0;

		// Compact columns (remembered on this computer).
		var ct = document.querySelector( '.sdb-compact-t' );
		if ( ct ) {
			ct.checked = '1' === store.get( 'sdb-compact' );
			grid.classList.toggle( 'is-compact', ct.checked );
			ct.addEventListener( 'change', function () { grid.classList.toggle( 'is-compact', ct.checked ); store.set( 'sdb-compact', ct.checked ? '1' : '0' ); } );
		}

		// Scroll to the morning (or to now) so the first screen is useful.
		var sc = document.querySelector( '.sdb-scroll' );
		var now = grid.querySelector( '.sdb-now' );
		if ( sc ) { sc.scrollTop = now ? Math.max( 0, parseInt( now.style.top, 10 ) - 120 ) : 0; }

		var minuteAt = function ( body, clientY, snap ) {
			var r = body.getBoundingClientRect();
			var m = g0 + Math.max( 0, clientY - r.top );
			return Math.floor( m / snap ) * snap;
		};
		var stamp = function ( m ) { return date + 'T' + pad( Math.floor( m / 60 ) ) + ':' + pad( m % 60 ); };

		// Hover: show the half hour under the mouse; click books it.
		grid.querySelectorAll( '.sdb-body' ).forEach( function ( body ) {
			if ( body.classList.contains( 'is-orphan' ) ) { return; }
			var hv = document.createElement( 'div' ); hv.className = 'sdb-hover'; hv.hidden = true; body.appendChild( hv );
			body.addEventListener( 'mousemove', function ( e ) {
				if ( e.target.closest( '.sdb-ev' ) ) { hv.hidden = true; return; }
				var m = minuteAt( body, e.clientY, 30 );
				hv.hidden = false; hv.style.top = ( m - g0 ) + 'px'; hv.textContent = '+ ' + hm( m );
			} );
			body.addEventListener( 'mouseleave', function () { hv.hidden = true; } );
			body.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.sdb-ev' ) ) { return; }
				var m = minuteAt( body, e.clientY, 30 );
				var u = new URL( D.book, window.location.href );
				u.searchParams.set( 'start', stamp( m ) );
				u.searchParams.set( 'car' === body.dataset.kind ? 'car' : 'ins', body.dataset.res );
				window.location.href = u.toString();
			} );
		} );

		// Drag a lesson to another time / instructor / car.
		var drag = null, ghost = document.createElement( 'div' ); ghost.className = 'sdb-ghost'; ghost.hidden = true;
		grid.querySelectorAll( '.sdb-ev[draggable="true"]' ).forEach( function ( ev ) {
			ev.addEventListener( 'dragstart', function ( e ) {
				var r = ev.getBoundingClientRect();
				drag = { el: ev, id: ev.dataset.id, min: parseInt( ev.dataset.min, 10 ), a: parseInt( ev.dataset.a, 10 ), who: ev.dataset.who, off: e.clientY - r.top, from: ev.closest( '.sdb-body' ) };
				e.dataTransfer.effectAllowed = 'move';
				try { e.dataTransfer.setData( 'text/plain', ev.dataset.id ); } catch ( x ) {}
				setTimeout( function () { ev.classList.add( 'is-dragging' ); }, 0 );
			} );
			ev.addEventListener( 'dragend', function () { ev.classList.remove( 'is-dragging' ); ghost.hidden = true; grid.querySelectorAll( '.is-drop' ).forEach( function ( b ) { b.classList.remove( 'is-drop' ); } ); } );
		} );
		var dropMinute = function ( body, e ) {
			var r = body.getBoundingClientRect();
			var m = g0 + ( e.clientY - r.top - drag.off );
			return Math.max( g0, Math.round( m / 15 ) * 15 );
		};
		grid.querySelectorAll( '.sdb-body' ).forEach( function ( body ) {
			body.addEventListener( 'dragover', function ( e ) {
				if ( ! drag ) { return; }
				// A lesson keeps its kind of column: instructor → instructor, car → car.
				if ( body.dataset.kind !== drag.from.dataset.kind ) { return; }
				e.preventDefault();
				var m = dropMinute( body, e );
				body.classList.add( 'is-drop' );
				if ( ghost.parentNode !== body ) { body.appendChild( ghost ); }
				ghost.hidden = false;
				ghost.style.top = ( m - g0 ) + 'px'; ghost.style.height = drag.min + 'px';
				ghost.style.setProperty( '--c', getComputedStyle( drag.el ).getPropertyValue( '--c' ) );
				ghost.textContent = hm( m ) + '–' + hm( m + drag.min ) + ' · ' + body.dataset.name;
			} );
			body.addEventListener( 'dragleave', function ( e ) { if ( ! body.contains( e.relatedTarget ) ) { body.classList.remove( 'is-drop' ); } } );
			body.addEventListener( 'drop', function ( e ) {
				if ( ! drag || body.dataset.kind !== drag.from.dataset.kind ) { return; }
				e.preventDefault();
				var m = dropMinute( body, e );
				var d = drag; drag = null; ghost.hidden = true; body.classList.remove( 'is-drop' );
				if ( m === d.a && body === d.from ) { return; }
				ask( {
					id: d.id, start: stamp( m ), kind: body.dataset.kind, res: body.dataset.res,
					text: 'Move ' + d.who + ' to ' + hm( m ) + '–' + hm( m + d.min ) + ( body !== d.from ? ' with ' + body.dataset.name : '' ) + '?'
				} );
			} );
		} );
	}

	/* ---------------- Move dialog */
	var modal = document.querySelector( '.sdb-modal' );
	function ask( job ) {
		if ( ! modal ) { return; }
		var q = function ( s ) { return modal.querySelector( s ); };
		var ok = q( '.sdb-m-ok' ), no = q( '.sdb-m-no' ), warn = q( '.sdb-m-warn' );
		var force = 0, busy = false;
		q( '.sdb-m-txt' ).textContent = job.text;
		warn.hidden = true; warn.innerHTML = ''; ok.textContent = 'Move'; ok.disabled = false;
		modal.hidden = false; ok.focus();
		var close = function () { modal.hidden = true; ok.onclick = null; no.onclick = null; document.removeEventListener( 'keydown', esc ); };
		var esc = function ( e ) { if ( 'Escape' === e.key ) { close(); } };
		document.addEventListener( 'keydown', esc );
		no.onclick = close;
		modal.onclick = function ( e ) { if ( e.target === modal ) { close(); } };
		ok.onclick = function () {
			if ( busy ) { return; }
			busy = true; ok.disabled = true; ok.textContent = 'Moving…';
			var fd = new FormData();
			fd.append( 'action', 'sdsc_fl_move' ); fd.append( 'nonce', D.nonce ); fd.append( 'id', job.id );
			fd.append( 'start', job.start ); fd.append( 'kind', job.kind ); fd.append( 'res', job.res );
			fd.append( 'mail', q( '.sdb-m-mail' ).checked ? '1' : '' ); fd.append( 'force', force ? '1' : '' );
			fetch( D.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( r ) {
				busy = false; ok.disabled = false;
				var data = r && r.data ? r.data : {};
				if ( r && r.success && data.conflicts ) {
					warn.innerHTML = ''; data.conflicts.forEach( function ( c ) { var li = document.createElement( 'li' ); li.textContent = c; warn.appendChild( li ); } );
					warn.hidden = false; force = 1; ok.textContent = 'Move anyway';
					return;
				}
				if ( r && r.success ) { window.location.reload(); return; }
				warn.innerHTML = ''; var li = document.createElement( 'li' ); li.textContent = data.msg || 'Could not move the lesson.'; warn.appendChild( li ); warn.hidden = false; ok.textContent = 'Try again';
			} ).catch( function () { busy = false; ok.disabled = false; ok.textContent = 'Try again'; } );
		};
	}

	/* ---------------- People lists (student name → phone / e-mail) */
	var plist = document.getElementById( 'sdb-people' ), pmap = {};
	if ( plist && D.people ) {
		D.people.forEach( function ( p ) {
			if ( ! p[0] ) { return; }
			pmap[ p[0] ] = p;
			var o = document.createElement( 'option' ); o.value = p[0]; o.label = p[1] || p[2] || ''; plist.appendChild( o );
		} );
	}
	var fillFrom = function ( inp, emailSel, phoneSel ) {
		if ( ! inp ) { return; }
		inp.addEventListener( 'change', function () {
			var p = pmap[ inp.value ]; if ( ! p ) { return; }
			var form = inp.closest( 'form, section' );
			var e = emailSel && form.querySelector( emailSel ), ph = form.querySelector( phoneSel );
			if ( e && ! e.value ) { e.value = p[1] || ''; }
			if ( ph && ! ph.value ) { ph.value = p[2] || ''; ph.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
		} );
	};
	fillFrom( document.querySelector( '.sdb-a-name' ), '.sdb-a-email', '.sdb-a-phone' );
	fillFrom( document.querySelector( '.sdb-o-name' ), null, '.sdb-o-phone' );

	/* ---------------- Free times → text the options */
	var offer = document.querySelector( '.sdb-offer' );
	if ( offer ) {
		var q = function ( s ) { return offer.querySelector( s ); };
		var out = q( '.sdb-o-text' ), send = q( '.sdb-o-send' ), list = q( '.sdb-o-book' );
		var lenSel = document.querySelector( '.sdb-filter select[name="len"]' );
		var build = function () {
			var picks = Array.prototype.slice.call( document.querySelectorAll( '.sdb-pick:checked' ) );
			var name = ( q( '.sdb-o-name' ).value || '' ).trim(), first = name.split( /\s+/ )[0] || '';
			var es = 'es' === q( '.sdb-o-lang' ).value;
			list.innerHTML = '';
			if ( ! picks.length ) {
				out.textContent = 'Tick two or three times on the left.';
				send.setAttribute( 'aria-disabled', 'true' ); send.href = '#';
				list.innerHTML = '<li class="sdo-muted">The times you tick appear here with a Book button.</li>';
				return;
			}
			var lines = picks.map( function ( p ) { return '• ' + p.value; } ).join( '\n' );
			var txt = es
				? 'Hola' + ( first ? ' ' + first : '' ) + ', estos son los horarios disponibles para su clase de manejo:\n' + lines + '\n¿Cuál le funciona? Traiga su permiso de aprendizaje. – Sam’s Driving School'
				: 'Hi' + ( first ? ' ' + first : '' ) + ', here are the times we have for your driving lesson:\n' + lines + '\nWhich one works for you? Please bring your learner’s permit. – Sam’s Driving School';
			out.textContent = txt;
			var to = e164( q( '.sdb-o-phone' ).value );
			if ( to ) { send.href = 'sms:' + to + '?&body=' + encodeURIComponent( txt ); send.removeAttribute( 'aria-disabled' ); }
			else { send.href = '#'; send.setAttribute( 'aria-disabled', 'true' ); }
			var parts = name.split( /\s+/ );
			picks.forEach( function ( p ) {
				var u = new URL( D.book, window.location.href );
				u.searchParams.set( 'start', p.dataset.start );
				if ( '0' !== p.dataset.ins ) { u.searchParams.set( 'ins', p.dataset.ins ); }
				if ( lenSel ) { u.searchParams.set( 'len', lenSel.value ); }
				if ( parts[0] ) { u.searchParams.set( 'first', parts[0] ); }
				if ( parts.length > 1 ) { u.searchParams.set( 'last', parts.slice( 1 ).join( ' ' ) ); }
				if ( q( '.sdb-o-phone' ).value ) { u.searchParams.set( 'phone', q( '.sdb-o-phone' ).value ); }
				if ( pmap[ name ] && pmap[ name ][1] ) { u.searchParams.set( 'email', pmap[ name ][1] ); }
				var li = document.createElement( 'li' );
				var s = document.createElement( 'span' ); s.textContent = p.value;
				var a = document.createElement( 'a' ); a.className = 'sdo-btn'; a.href = u.toString(); a.textContent = 'Book';
				li.appendChild( s ); li.appendChild( a ); list.appendChild( li );
			} );
		};
		document.addEventListener( 'change', function ( e ) { if ( e.target.matches( '.sdb-pick, .sdb-o-lang, .sdb-o-name' ) ) { build(); } } );
		offer.addEventListener( 'input', build );
		q( '.sdb-o-copy' ).addEventListener( 'click', function () {
			var b = this, t = out.textContent;
			var done = function () { b.textContent = 'Copied ✓'; setTimeout( function () { b.textContent = 'Copy text'; }, 1500 ); };
			if ( navigator.clipboard ) { navigator.clipboard.writeText( t ).then( done, function () {} ); }
		} );
		build();
	}
} )();
