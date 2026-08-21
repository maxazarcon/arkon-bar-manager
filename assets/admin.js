/* global jQuery, wp, ABM */
( function ( $ ) {
	'use strict';

	/**
	 * Wire a media-uploader trio: hidden id input, preview container, and
	 * upload/remove buttons. Used for both the flyer field and the global
	 * placeholder setting.
	 *
	 * @param {string} uploadSel  Upload button selector.
	 * @param {string} removeSel  Remove button selector.
	 * @param {string} idSel      Hidden input selector.
	 * @param {string} previewSel Preview container selector.
	 */
	function bindUploader( uploadSel, removeSel, idSel, previewSel ) {
		var frame;

		$( document ).on( 'click', uploadSel, function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: ABM.frameTitle,
				button: { text: ABM.frameButton },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.url;
				if ( attachment.sizes && attachment.sizes.medium ) {
					url = attachment.sizes.medium.url;
				}
				$( idSel ).val( attachment.id );
				$( previewSel ).html(
					$( '<img>', {
						src: url,
						css: { maxWidth: '200px', height: 'auto', display: 'block', margin: '6px 0' }
					} )
				);
				$( removeSel ).show();
			} );

			frame.open();
		} );

		$( document ).on( 'click', removeSel, function ( e ) {
			e.preventDefault();
			$( idSel ).val( '' );
			$( previewSel ).empty();
			$( this ).hide();
		} );
	}

	$( function () {
		// Global placeholder (settings page). The event flyer is the post's
		// Featured Image, handled by the native Featured Image panel.
		bindUploader( '.abm-placeholder-upload', '.abm-placeholder-remove', '#abm_placeholder_id', '.abm-placeholder-preview' );

		// End-time mode: enable/disable the time input based on the radio.
		$( document ).on( 'change', '.abm-end-mode', function () {
			var isClose = $( 'input[name="abm_end_mode"]:checked' ).val() === 'close';
			$( '#abm_event_time_end' ).prop( 'disabled', isClose );
		} );

		// Recurrence: only show the detail fields for a repeating event, and only
		// show the weekday picker for a weekly rule.
		function syncRecur() {
			var type = $( '.abm-recur-type' ).val() || '';
			var units = {
				daily: ABM.unitDays,
				weekly: ABM.unitWeeks,
				monthly_date: ABM.unitMonths,
				monthly_day: ABM.unitMonths
			};
			$( '.abm-recur-detail' ).toggle( '' !== type );
			$( '.abm-recur-weekly' ).toggle( 'weekly' === type );
			$( '.abm-recur-unit' ).text( units[ type ] || '' );
		}

		$( document ).on( 'change', '.abm-recur-type', syncRecur );

		// Picking an end date or a count should select its own radio, so the
		// value the user just typed is the one that gets saved.
		$( document ).on( 'input change', 'input[name="abm_recur_until"]', function () {
			if ( $( this ).val() ) {
				$( 'input[name="abm_recur_end_mode"][value="until"]' ).prop( 'checked', true );
			}
		} );
		$( document ).on( 'input change', 'input[name="abm_recur_count"]', function () {
			if ( $( this ).val() ) {
				$( 'input[name="abm_recur_end_mode"][value="count"]' ).prop( 'checked', true );
			}
		} );

		syncRecur();
	} );
} )( jQuery );
