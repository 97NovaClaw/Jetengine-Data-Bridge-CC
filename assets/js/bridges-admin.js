/**
 * Bridges admin tab — Phase 4 / Day 1.
 *
 * Behavior:
 *   - Refresh the relation picker when source_target / target_target change
 *     (or when the user clicks "Refresh list").
 *   - Show/hide the relation picker block based on link_via_type radio.
 *   - Handle JSON export — fetches all bridge types via admin-ajax and
 *     triggers a browser download of a pretty-printed JSON file.
 *   - Open / close the import dialog.
 *
 * This file is intentionally vanilla DOM + minimal jQuery (matches the
 * existing flatten-admin.js style). No build step.
 *
 * @package JEDB
 */

( function ( $ ) {
	'use strict';

	$( function () {

		var bootstrapEl = document.getElementById( 'jedb-bridges-bootstrap' );
		if ( ! bootstrapEl ) {
			return;
		}

		var bootstrap = {};
		try {
			bootstrap = JSON.parse( bootstrapEl.textContent || '{}' );
		} catch ( e ) {
			bootstrap = {};
		}

		var ajaxUrl = bootstrap.ajax_url || ( window.ajaxurl || '' );
		var nonce   = bootstrap.nonce || '';

		/* -----------------------------------------------------------------
		 * Link-via picker visibility
		 * -------------------------------------------------------------- */

		var $linkViaTypeRadios = $( 'input[name="link_via_type"]' );
		var $relationPickerWrap = $( '#jedb_bridges_relation_picker_wrap' );

		function updateLinkViaVisibility() {
			var current = $linkViaTypeRadios.filter( ':checked' ).val() || 'je_relation';
			if ( current === 'je_relation' ) {
				$relationPickerWrap.show();
			} else {
				$relationPickerWrap.hide();
			}
		}

		$linkViaTypeRadios.on( 'change', updateLinkViaVisibility );
		updateLinkViaVisibility();

		/* -----------------------------------------------------------------
		 * Relation picker — refresh on source/target change or click
		 * -------------------------------------------------------------- */

		var $sourceSelect    = $( '#jedb_bridges_source' );
		var $targetSelect    = $( '#jedb_bridges_target' );
		var $relationSelect  = $( '#jedb_bridges_link_via_relation_id' );
		var $refreshBtn      = $( '#jedb_bridges_refresh_relations' );
		var $relationsStatus = $( '#jedb_bridges_relations_status' );

		var currentRelationId = String( bootstrap.current_link_via_relation_id || '' );

		function refreshRelations( opts ) {
			opts = opts || {};
			var src = $sourceSelect.val() || '';
			var tgt = $targetSelect.val() || '';

			if ( ! src || ! tgt ) {
				$relationsStatus.text( 'Pick source + target first.' );
				return;
			}

			$relationsStatus.text( 'Loading…' );
			$refreshBtn.prop( 'disabled', true );

			$.post( ajaxUrl, {
				action:        'jedb_bridges_get_relations_for_pair',
				nonce:         nonce,
				source_target: src,
				target_target: tgt
			} ).done( function ( resp ) {
				$refreshBtn.prop( 'disabled', false );

				if ( ! resp || ! resp.success || ! resp.data ) {
					$relationsStatus.text( 'Lookup failed.' );
					return;
				}

				var relations = resp.data.relations || [];
				renderRelationOptions( relations, opts.preserveSelected ? currentRelationId : ( $relationSelect.val() || '' ) );
				$relationsStatus.text( relations.length === 0
					? 'No relations match this source/target pair.'
					: ( relations.length + ' relation' + ( relations.length === 1 ? '' : 's' ) + ' available.' ) );
			} ).fail( function () {
				$refreshBtn.prop( 'disabled', false );
				$relationsStatus.text( 'Network error.' );
			} );
		}

		function renderRelationOptions( relations, selectedId ) {
			var options = [];
			options.push( '<option value="">— Select a relation —</option>' );
			relations.forEach( function ( r ) {
				var id   = String( r.id || '' );
				var name = String( r.name || ( 'Relation #' + id ) );
				var type = String( r.type || '' );
				var pl   = String( r.parent_lb || r.parent || '' );
				var cl   = String( r.child_lb || r.child || '' );
				var sel  = ( id === String( selectedId ) ) ? ' selected' : '';
				options.push(
					'<option value="' + escapeAttr( id ) + '"' + sel + '>' +
						escapeText( name ) + ' · ' +
						escapeText( pl ) + ' → ' + escapeText( cl ) +
						' · ' + escapeText( type ) +
					'</option>'
				);
			} );
			$relationSelect.html( options.join( '' ) );
		}

		$refreshBtn.on( 'click', function () {
			refreshRelations( { preserveSelected: false } );
		} );

		var refreshTimer = null;
		function debouncedRefresh() {
			if ( refreshTimer ) {
				window.clearTimeout( refreshTimer );
			}
			refreshTimer = window.setTimeout( function () {
				refreshRelations( { preserveSelected: false } );
			}, 250 );
		}

		$sourceSelect.on( 'change', debouncedRefresh );
		$targetSelect.on( 'change', debouncedRefresh );

		if ( $sourceSelect.val() && $targetSelect.val() ) {
			refreshRelations( { preserveSelected: true } );
		}

		/* -----------------------------------------------------------------
		 * Export — fetch JSON, trigger download
		 * -------------------------------------------------------------- */

		$( '#jedb_bridges_export_btn' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Exporting…' );

			$.post( ajaxUrl, {
				action: 'jedb_bridges_export',
				nonce:  nonce
			} ).done( function ( resp ) {
				$btn.prop( 'disabled', false ).text( 'Export all (JSON)' );

				if ( ! resp || ! resp.success || ! resp.data ) {
					alert( 'Export failed.' );
					return;
				}
				var json = JSON.stringify( resp.data, null, 2 );
				var blob = new Blob( [ json ], { type: 'application/json' } );
				var url  = URL.createObjectURL( blob );
				var a    = document.createElement( 'a' );
				var ts   = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );
				a.href      = url;
				a.download  = 'jedb-bridge-types-' + ts + '.json';
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( 'Export all (JSON)' );
				alert( 'Network error during export.' );
			} );
		} );

		/* -----------------------------------------------------------------
		 * Import dialog
		 * -------------------------------------------------------------- */

		var $importDialog = $( '#jedb_bridges_import_dialog' );

		$( '#jedb_bridges_import_btn' ).on( 'click', function () {
			$importDialog.show();
			$( 'html, body' ).animate( { scrollTop: $importDialog.offset().top - 60 }, 200 );
			$importDialog.find( 'textarea' ).first().focus();
		} );

		$( '#jedb_bridges_import_cancel' ).on( 'click', function () {
			$importDialog.hide();
		} );

		/* -----------------------------------------------------------------
		 * JSON-textarea live validate (low-stakes — server validates again)
		 * -------------------------------------------------------------- */

		var $jsonTextarea = $( '#jedb_bridges_json' );
		$jsonTextarea.on( 'blur', function () {
			var raw = $.trim( $jsonTextarea.val() );
			if ( '' === raw ) {
				$jsonTextarea.css( 'border-color', '' );
				return;
			}
			try {
				JSON.parse( raw );
				$jsonTextarea.css( 'border-color', '#46b450' );
			} catch ( e ) {
				$jsonTextarea.css( 'border-color', '#dc3232' );
			}
		} );

		/* -----------------------------------------------------------------
		 * Slug auto-fill — derive from label on first edit (only when
		 * adding a new bridge type and slug is still empty).
		 * -------------------------------------------------------------- */

		var $slug  = $( '#jedb_bridges_slug' );
		var $label = $( '#jedb_bridges_label' );

		var slugDirty = ( bootstrap.editing_slug && bootstrap.editing_slug !== '' ) || ( $.trim( $slug.val() ) !== '' );

		$slug.on( 'input', function () {
			slugDirty = true;
		} );

		$label.on( 'input', function () {
			if ( slugDirty ) {
				return;
			}
			var s = $.trim( $label.val() ).toLowerCase()
				.replace( /[^a-z0-9_\-\s]/g, '' )
				.replace( /\s+/g, '_' );
			$slug.val( s );
		} );

		/* -----------------------------------------------------------------
		 * Helpers
		 * -------------------------------------------------------------- */

		function escapeAttr( s ) {
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

		function escapeText( s ) {
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
