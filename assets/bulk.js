/* Arkon Event Manager — Bulk Add.
 *
 * Row add/remove and the per-row flyer picker. Everything else the screen does
 * is a plain form POST, deliberately: the table is submitted in one go and
 * parsed server-side, so there is no client-side model to keep in step with the
 * markup, and the screen still works if this file fails to load — you just lose
 * the ability to add rows beyond the ones already rendered.
 */
( function ( $ ) {
	'use strict';

	var $rows = $( '#abm-bulk-rows' );
	if ( ! $rows.length ) {
		return;
	}

	var template = $( '#tmpl-abm-bulk-row' ).html() || '';

	/* Row indexes only have to be unique, not contiguous: the server iterates
	   whatever it is given and ignores blanks. Counting from the current row
	   count would collide after a removal. */
	var nextIndex = $rows.find( '.abm-bulk-row' ).length;

	function status( message ) {
		$( '.abm-bulk-status' ).text( message || '' );
	}

	$( '#abm-bulk-add' ).on( 'click', function () {
		if ( ! template ) {
			return;
		}
		var html = template.replace( /__i__/g, String( nextIndex++ ) );
		$rows.append( html );
		$rows.find( '.abm-bulk-row' ).last().find( 'input[type="date"]' ).trigger( 'focus' );
		status( '' );
	} );

	$rows.on( 'click', '.abm-bulk-remove', function () {
		var $row = $( this ).closest( '.abm-bulk-row' );
		/* Keep one row on the table. An empty table offers nothing to type into
		   and no obvious way back. */
		if ( $rows.find( '.abm-bulk-row' ).length <= 1 ) {
			$row.find( 'input[type="text"], input[type="date"], input[type="time"]' ).val( '' );
			$row.find( 'select' ).prop( 'selectedIndex', 0 );
			$row.find( '.abm-flyer-id' ).val( '' );
			$row.find( '.abm-flyer-pick' ).text( 'Choose' );
			$row.removeClass( 'has-note' ).find( '.abm-bulk-note' ).remove();
			status( '' );
			return;
		}
		$row.remove();
		status( $rows.find( '.abm-bulk-row' ).length + ' rows' );
	} );

	/* Flyer picker. One frame, reused, retargeted at whichever row was clicked —
	   opening a fresh wp.media frame per row leaks them. */
	var frame = null;
	var $target = null;

	$rows.on( 'click', '.abm-flyer-pick', function () {
		$target = $( this ).closest( '.abm-bulk-flyer' );

		if ( ! frame ) {
			frame = wp.media( {
				title: ( window.ABM_BULK && ABM_BULK.chooseFlyer ) || 'Choose flyer',
				button: { text: ( window.ABM_BULK && ABM_BULK.useFlyer ) || 'Use as flyer' },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				if ( ! $target ) {
					return;
				}
				$target.find( '.abm-flyer-id' ).val( attachment.id );

				var thumb =
					attachment.sizes && attachment.sizes.thumbnail
						? attachment.sizes.thumbnail.url
						: attachment.url;

				$target
					.find( '.abm-flyer-pick' )
					.empty()
					.append( $( '<img>', { src: thumb, width: 40, height: 40, alt: '' } ) );
			} );
		}

		frame.open();
	} );
} )( jQuery );
