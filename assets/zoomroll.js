/* Zoom attendance: "Mark everyone present" (only fills students who have no mark yet). */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-szr-all]' );
		if ( ! b ) { return; }
		var form = b.closest( 'form' ), v = b.getAttribute( 'data-szr-all' );
		form.querySelectorAll( '.szr-seg' ).forEach( function ( seg ) {
			if ( seg.querySelector( 'input:checked' ) ) { return; }
			var r = seg.querySelector( 'input[value="' + v + '"]' );
			if ( r ) { r.checked = true; }
		} );
	} );
} )();
