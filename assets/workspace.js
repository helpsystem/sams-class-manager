/* Workspace: close the drop-down on outside click / Escape, size the screen frame to its content,
   and highlight the matching sidebar item. */
( function () {
	var menu = document.querySelector( '.sws-menu' );
	if ( menu ) {
		document.addEventListener( 'click', function ( e ) { if ( menu.open && ! menu.contains( e.target ) ) { menu.open = false; } } );
		document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { menu.open = false; } } );
	}
	var f = document.querySelector( '.sws-frame' );
	if ( f ) {
		var fit = function () {
			try {
				var d = f.contentDocument;
				if ( d && d.body ) { f.style.height = Math.max( 600, d.documentElement.scrollHeight ) + 'px'; }
			} catch ( x ) {}
		};
		f.addEventListener( 'load', function () {
			fit();
			try {
				if ( window.ResizeObserver ) { new ResizeObserver( fit ).observe( f.contentDocument.body ); }
				f.contentWindow.scrollTo( 0, 0 );
			} catch ( x ) {}
		} );
		setInterval( fit, 1500 );
	}
	var screen = new URLSearchParams( location.search ).get( 'screen' );
	if ( screen ) {
		var li = document.querySelector( '.tutor-dashboard-menu-sdsc-w-' + screen + ' a' );
		if ( li ) {
			document.querySelectorAll( '[class^="tutor-dashboard-menu-"] a.active, [class*=" tutor-dashboard-menu-"] a.active' ).forEach( function ( a ) { a.classList.remove( 'active' ); } );
			li.classList.add( 'active' );
		}
	}
} )();
