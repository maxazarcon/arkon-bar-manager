/* Arkon Event Manager — [abm_calendar] front end.
   Two behaviours: Load More (keyset cursor, appends the next batch) and
   collapsible month headings. Vanilla JS, no dependencies.

   The collapse state lives in a Set of month keys rather than on the DOM nodes,
   because Load More can append articles belonging to a month the visitor has
   already collapsed — those have to arrive hidden, not pop open. */
( function () {
	'use strict';

	function initCalendar( cal ) {
		var list = cal.querySelector( '.abm-calendar-list' );
		if ( ! list ) {
			return;
		}

		/* Month keys the visitor has collapsed. */
		var collapsed = Object.create( null );

		function applyMonth( key ) {
			var isCollapsed = !! collapsed[ key ];
			var i;

			var articles = list.querySelectorAll( '.abm-event[data-month="' + key + '"]' );
			for ( i = 0; i < articles.length; i++ ) {
				articles[ i ].hidden = isCollapsed;
			}

			var heads = list.querySelectorAll( '.abm-month[data-month="' + key + '"]' );
			for ( i = 0; i < heads.length; i++ ) {
				heads[ i ].classList.toggle( 'abm-month-collapsed', isCollapsed );
				var btn = heads[ i ].querySelector( '.abm-month-toggle' );
				if ( btn ) {
					btn.setAttribute( 'aria-expanded', isCollapsed ? 'false' : 'true' );
				}
			}
		}

		/* Re-hide anything that just arrived into an already-collapsed month. */
		function syncAll() {
			for ( var key in collapsed ) {
				if ( collapsed[ key ] ) {
					applyMonth( key );
				}
			}
		}

		/* One delegated listener, so appended headings work without rebinding. */
		list.addEventListener( 'click', function ( ev ) {
			var btn = ev.target.closest ? ev.target.closest( '.abm-month-toggle' ) : null;
			if ( ! btn || ! list.contains( btn ) ) {
				return;
			}
			var head = btn.closest( '.abm-month' );
			if ( ! head ) {
				return;
			}
			var key = head.getAttribute( 'data-month' );
			if ( ! key ) {
				return;
			}
			collapsed[ key ] = ! collapsed[ key ];
			applyMonth( key );
		} );

		var btn = cal.querySelector( '.abm-load-more' );
		if ( ! btn ) {
			return;
		}

		/* Independent of btn.disabled: a theme (or an over-eager script) can
		   re-enable the button mid-flight, and two overlapping responses would
		   append the same batch twice. */
		var pending = false;

		btn.addEventListener( 'click', function () {
			var ajax = cal.getAttribute( 'data-ajax' );
			if ( ! ajax || pending || btn.disabled ) {
				return;
			}

			pending = true;
			var original = btn.textContent;
			btn.disabled = true;
			btn.textContent = btn.getAttribute( 'data-loading' ) || original;

			var body = new URLSearchParams();
			body.set( 'action', 'abm_load_events' );
			body.set( 'cursor', cal.getAttribute( 'data-cursor' ) || '' );
			body.set( 'count', cal.getAttribute( 'data-more' ) || '6' );
			body.set( 'last_month', cal.getAttribute( 'data-last-month' ) || '' );
			body.set( 'category', cal.getAttribute( 'data-category' ) || '' );

			fetch( ajax, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
				credentials: 'same-origin'
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data ) {
						throw new Error( 'Bad response' );
					}

					list.insertAdjacentHTML( 'beforeend', res.data.html );
					cal.setAttribute( 'data-cursor', res.data.cursor );
					cal.setAttribute( 'data-last-month', res.data.last_month );
					syncAll();

					pending = false;
					btn.textContent = original;
					if ( res.data.has_more ) {
						btn.disabled = false;
					} else {
						var wrap = btn.parentNode;
						if ( wrap ) {
							wrap.hidden = true;
						}
					}
				} )
				.catch( function () {
					pending = false;
					btn.disabled = false;
					btn.textContent = original;
				} );
		} );
	}

	function init() {
		var calendars = document.querySelectorAll( '.abm-calendar' );
		for ( var i = 0; i < calendars.length; i++ ) {
			initCalendar( calendars[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
