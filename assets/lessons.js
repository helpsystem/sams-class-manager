/* Sam's Class Manager — BTW lessons & refunds screens */
( function () {
	'use strict';
	var D = window.SDSC_LS || { people: [] };

	/* Student finder: pick from the registration list, the fields fill in. */
	document.querySelectorAll( '.sdl-find' ).forEach( function ( inp ) {
		var form = inp.closest( 'form' );
		var list = document.getElementById( 'sdl-people' );
		var map = {};
		if ( list && D.people && ! list.children.length ) {
			D.people.forEach( function ( p ) {
				var label = ( p[1] + ' ' + p[2] ).trim() + ' — ' + p[3];
				map[ label ] = p;
				var o = document.createElement( 'option' ); o.value = label; list.appendChild( o );
			} );
		}
		inp.addEventListener( 'change', function () {
			var p = map[ inp.value ]; if ( ! p ) { return; }
			var set = function ( sel, v ) { var el = form.querySelector( sel ); if ( el ) { el.value = v || ''; } };
			set( '.sdl-ckey', p[0] ); set( '.sdl-first', p[1] ); set( '.sdl-last', p[2] ); set( '.sdl-email', p[3] ); set( '.sdl-phone', p[4] ); set( '.sdl-lang', p[5] );
			pickInstructor( form );
		} );
	} );

	/* The student's own instructor is chosen by itself; the instructor's usual car follows. */
	var skey = function ( email, phone ) {
		var e = String( email || '' ).trim().toLowerCase(); if ( e ) { return e; }
		var d = String( phone || '' ).replace( /\D/g, '' ); return d ? 'tel:' + d.slice( -10 ) : '';
	};
	function pickInstructor( form ) {
		var sel = form.querySelector( 'select.sdl-instructor' ); if ( ! sel || ! D.asg ) { return; }
		var em = form.querySelector( '.sdl-email' ), ph = form.querySelector( '.sdl-phone' );
		var id = D.asg[ skey( em && em.value, '' ) ] || D.asg[ skey( '', ph && ph.value ) ];
		if ( id && '0' === sel.value && sel.querySelector( 'option[value="' + id + '"]' ) ) { sel.value = String( id ); sel.dispatchEvent( new Event( 'change' ) ); }
	}
	document.querySelectorAll( 'form.sdl-form' ).forEach( function ( form ) {
		var sel = form.querySelector( 'select.sdl-instructor' ), car = form.querySelector( 'select.sdl-car' );
		if ( sel && car ) {
			sel.addEventListener( 'change', function () {
				var o = sel.options[ sel.selectedIndex ], c = o && o.dataset.car;
				if ( c && '0' !== c && '0' === car.value && car.querySelector( 'option[value="' + c + '"]' ) ) { car.value = c; }
			} );
		}
		[ '.sdl-email', '.sdl-phone' ].forEach( function ( s ) { var el = form.querySelector( s ); if ( el ) { el.addEventListener( 'change', function () { pickInstructor( form ); } ); } } );
	} );

	/* Cancel form: say right away whether the fee applies. */
	document.querySelectorAll( '.sdl-notice' ).forEach( function ( inp ) {
		var out = inp.closest( 'form' ).querySelector( '.sdl-verdict' );
		var run = function () {
			var late = inp.value && inp.value > inp.dataset.deadline;
			out.textContent = late ? out.dataset.late : out.dataset.ok;
			out.className = 'sdl-verdict ' + ( late ? 'is-late' : 'is-ok' );
		};
		inp.addEventListener( 'input', run ); run();
	} );

	/* Refund calculator. */
	var calc = document.querySelector( '.sdl-calc' );
	if ( calc ) {
		var cr = parseFloat( calc.dataset.class ) || 0, br = parseFloat( calc.dataset.btw ) || 0;
		var q = function ( s ) { return calc.querySelector( s ); };
		var money = function ( n ) { return '$' + ( Math.round( n * 100 ) / 100 ).toFixed( 2 ).replace( /\.00$/, '' ); };
		var run = function () {
			var pay = calc.querySelector( 'input[name="pay"]:checked' );
			var manual = pay && 'manual' === pay.value;
			q( '.sdl-manual' ).hidden = ! manual;
			var paid = manual ? parseFloat( q( '.sdl-mpaid' ).value ) || 0 : parseFloat( pay ? pay.dataset.paid : 0 ) || 0;
			var max = manual ? paid : parseFloat( pay ? pay.dataset.max : 0 ) || 0;
			var full = q( '.sdl-full' ).checked;
			[ '.sdl-c', '.sdl-b', '.sdl-o' ].forEach( function ( s ) { q( s ).disabled = full; } );
			var c = full ? 0 : parseInt( q( '.sdl-c' ).value, 10 ) || 0;
			var b = full ? 0 : parseInt( q( '.sdl-b' ).value, 10 ) || 0;
			var o = full ? 0 : parseFloat( q( '.sdl-o' ).value ) || 0;
			var kept = c * cr + b * br + o;
			var ref = Math.max( 0, Math.min( max, paid - kept ) );
			var r = q( '.sdl-result' );
			if ( ! paid ) { r.textContent = ''; return; }
			r.innerHTML = 'Paid <b>' + money( paid ) + '</b> − kept <b>' + money( kept ) + '</b>' +
				( c ? ' (' + c + ' × ' + money( cr ) + ' Zoom' : ' (' ) + ( b ? ( c ? ' + ' : '' ) + b + ' × ' + money( br ) + ' BTW' : '' ) + ( o ? ' + ' + money( o ) + ' other' : '' ) + ( c || b || o ? ')' : 'nothing)' ) +
				'<br>Refund: <b class="sdl-r">' + money( ref ) + '</b>' + ( max < paid - kept ? ' <span class="sdo-muted">(limited to what can still be refunded on this payment)</span>' : '' );
		};
		calc.addEventListener( 'input', run ); calc.addEventListener( 'change', run ); run();
	}
} )();
