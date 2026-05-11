/**
 * Bridge meta box — Phase 4 / Day 2 (D-27).
 *
 * Frontend behavior for the JEDB Bridge meta box on Woo product /
 * variation edit screens:
 *
 *   - Unlinked panel: live-search CCT rows via the existing
 *     `wp_ajax_jedb_relation_search_items` endpoint (Phase 2), populate
 *     a <select> with results, enable the Link button when a row is
 *     selected.
 *
 *   - Linked panel: native HTML form behavior is sufficient for the
 *     Sync now / Unlink buttons (separate <form>s posting to
 *     admin-post.php). This file just adds a small confirm dialog on
 *     the lock checkbox toggle so editors get a heads-up that flipping
 *     it stops auto-sync entirely.
 *
 * @package JEDB
 */

( function ( $ ) {
	'use strict';

	$( function () {

		var ajaxUrl = ( window.ajaxurl || '/wp-admin/admin-ajax.php' );

		/* -----------------------------------------------------------------
		 * Unlinked panel — live CCT search
		 * -------------------------------------------------------------- */

		$( '.jedb-bridge-panel-unlinked' ).each( function () {

			var $panel        = $( this );
			var sourceTarget  = String( $panel.data( 'source-target' ) || '' );
			var relationsNonce= String( $panel.data( 'relations-nonce' ) || '' );

			var $search   = $panel.find( '.jedb-link-search' );
			var $results  = $panel.find( '.jedb-link-results' );
			var $status   = $panel.find( '.jedb-link-status' );

			if ( ! sourceTarget || ! $search.length || ! $results.length ) {
				return;
			}

			var lastQuery = null;
			var inFlight  = null;
			var debounce  = null;

			function search( query ) {

				if ( query === lastQuery ) { return; }
				lastQuery = query;

				if ( inFlight && inFlight.readyState !== 4 ) {
					try { inFlight.abort(); } catch ( e ) {}
				}

				$status.text( 'Searching…' );

				inFlight = $.post( ajaxUrl, {
					action:      'jedb_relation_search_items',
					nonce:       relationsNonce,
					object_slug: sourceTarget,
					search:      query,
					limit:       25
				} ).done( function ( resp ) {

					if ( ! resp || ! resp.success || ! resp.data ) {
						$status.text( 'Search failed.' );
						return;
					}

					var items = resp.data.items || [];
					var html  = '';

					if ( items.length === 0 ) {
						html = '<option value="">No results.</option>';
						$status.text( 'No matches.' );
					} else {
						$status.text( items.length + ' result' + ( items.length === 1 ? '' : 's' ) + '.' );
						items.forEach( function ( it ) {
							var id    = String( it._ID || it.id || '' );
							if ( ! id ) { return; }
							var label = String( it.mosaic_name || it.name || it.title || it.label || ( '#' + id ) );
							html += '<option value="' + escAttr( id ) + '">' + escText( label ) + ' (#' + escText( id ) + ')</option>';
						} );
					}

					$results.html( html );

				} ).fail( function ( xhr, status ) {
					if ( status === 'abort' ) { return; }
					$status.text( 'Network error during search.' );
				} );
			}

			$search.on( 'input', function () {
				if ( debounce ) { window.clearTimeout( debounce ); }
				var q = $.trim( $search.val() || '' );
				debounce = window.setTimeout( function () { search( q ); }, 250 );
			} );

			// Trigger an initial search-all query when the search box gains
			// focus for the first time — saves a typing step for sites
			// with only a few CCT rows.
			$search.one( 'focus', function () {
				search( '' );
			} );
		} );

		/* -----------------------------------------------------------------
		 * Linked panel — lock checkbox confirm
		 * -------------------------------------------------------------- */

		$( '.jedb-override-lock input[type="checkbox"]' ).on( 'change', function () {
			var $chk = $( this );
			if ( ! $chk.is( ':checked' ) ) {
				return;
			}
			var ok = window.confirm(
				'Freeze sync for this product?\n\n' +
				'This stops both forward push and reverse pull from running on save events ' +
				'for THIS specific product. The bridge config itself is unchanged — other products ' +
				'linked through the same bridge keep syncing normally. ' +
				'You can untick this anytime to restore normal behavior.'
			);
			if ( ! ok ) {
				$chk.prop( 'checked', false );
			}
		} );

		/* -----------------------------------------------------------------
		 * Helpers
		 * -------------------------------------------------------------- */

		function escAttr( s ) {
			return String( s ).replace( /[&<>"']/g, function ( c ) {
				return ( {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;'
				} )[ c ];
			} );
		}

		function escText( s ) {
			return String( s ).replace( /[&<>]/g, function ( c ) {
				return ( {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;'
				} )[ c ];
			} );
		}
	} );

} )( jQuery );
