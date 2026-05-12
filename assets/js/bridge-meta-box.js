/**
 * Bridge meta box — Phase 4 / Day 2 (D-27), reshaped alpha.6 (L-027).
 *
 * Frontend behavior for the JEDB Bridge meta box on Woo product /
 * variation edit screens:
 *
 *   - Unlinked panel: live-search CCT rows via the existing
 *     `wp_ajax_jedb_relation_search_items` endpoint (Phase 2), populate
 *     a <select> with results, enable the Link button when a row is
 *     selected.
 *
 *   - Linked panel (alpha.6): the meta box no longer renders editable
 *     CCT-field inputs. Instead, surfaced mappings are read-only
 *     previews and a "Save & edit CCT row" button launches a modal
 *     iframe pointed at JE's CCT edit page (chrome-stripped server-side
 *     by JEDB_Woo_Product_Meta_Box::maybe_inject_cct_chrome_strip).
 *     When the editor clicks "Done" inside the iframe, this script
 *     receives a postMessage, closes the modal, and reloads the
 *     product edit page so it picks up any pushed-back values from
 *     JE's forward push.
 *
 *   - Linked panel lock confirm: when the editor ticks the "freeze
 *     sync" checkbox, show a confirm dialog explaining the effect.
 *
 * Per-session bootstrap is provided by `jedbMetaBoxBootstrap` (injected
 * via wp_localize_script): contains the optional `reopenBridgeId` set
 * by a previous "Save & edit" submission so this page render can
 * auto-launch the modal.
 *
 * @package JEDB
 */

( function ( $ ) {
	'use strict';

	$( function () {

		var ajaxUrl   = ( window.ajaxurl || '/wp-admin/admin-ajax.php' );
		var bootstrap = ( window.jedbMetaBoxBootstrap || { reopenBridgeId: 0, postId: 0, i18n: {} } );
		var i18n      = bootstrap.i18n || {};

		/* =================================================================
		 * Modal — opens JE's CCT edit page in an iframe (alpha.6 / L-027)
		 * ============================================================== */

		var $modal     = null;
		var $modalIframe = null;
		var modalCloseOnEscBound = false;

		function ensureModal() {

			if ( $modal && $modal.length ) {
				return $modal;
			}

			$modal = $(
				'<div class="jedb-cct-modal-overlay" role="dialog" aria-modal="true" style="display:none;">' +
					'<div class="jedb-cct-modal-frame">' +
						'<button type="button" class="jedb-cct-modal-close" aria-label="Close" title="Close">&times;</button>' +
						'<iframe class="jedb-cct-modal-iframe" frameborder="0" allow="clipboard-write"></iframe>' +
						'<div class="jedb-cct-modal-saving" style="display:none;">' +
							'<div class="jedb-cct-modal-saving-inner">' +
								'<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' +
								'<span>Saving CCT changes…</span>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>'
			);
			$( 'body' ).append( $modal );

			$modalIframe = $modal.find( '.jedb-cct-modal-iframe' );

			$modal.on( 'click', function ( e ) {
				if ( e.target === $modal.get( 0 ) ) {
					closeModal( /* reload */ false );
				}
			} );

			$modal.find( '.jedb-cct-modal-close' ).on( 'click', function () {
				closeModal( /* reload */ false );
			} );

			if ( ! modalCloseOnEscBound ) {
				$( document ).on( 'keydown', function ( e ) {
					if ( $modal && $modal.is( ':visible' ) && e.key === 'Escape' ) {
						closeModal( /* reload */ false );
					}
				} );
				modalCloseOnEscBound = true;
			}

			return $modal;
		}

		function openModal( url ) {
			var $m = ensureModal();
			$m.find( '.jedb-cct-modal-saving' ).hide();
			$modalIframe.attr( 'src', url );
			$m.show();
			$( 'body' ).addClass( 'jedb-cct-modal-open' );
		}

		function closeModal( shouldReload ) {
			if ( ! $modal || ! $modal.is( ':visible' ) ) {
				return;
			}
			$( 'body' ).removeClass( 'jedb-cct-modal-open' );
			$modal.hide();
			$modalIframe.attr( 'src', 'about:blank' );

			if ( shouldReload ) {
				window.location.reload();
			}
		}

		function showSavingOverlay() {
			if ( $modal && $modal.is( ':visible' ) ) {
				$modal.find( '.jedb-cct-modal-saving' ).show();
			}
		}

		function hideSavingOverlay() {
			if ( $modal ) {
				$modal.find( '.jedb-cct-modal-saving' ).hide();
			}
		}

		// Listen for postMessages from the chrome-stripped CCT edit iframe.
		// Only accept messages from the same origin as the parent (the iframe
		// is loaded from the same WP install, so origins match).
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin ) { return; }
			var data = event.data || {};
			if ( ! data || ! data.type ) { return; }

			switch ( data.type ) {
				case 'jedb:cct-save-starting':
					// JE form is being submitted (Done click or native
					// Save click inside iframe). Show a "Saving…" overlay
					// so the editor has feedback during the round-trip.
					showSavingOverlay();
					break;

				case 'jedb:cct-save-error':
					// Validation failed inside JE. The iframe stays open
					// so the editor can read and fix the error. Hide our
					// overlay so they can interact with the form again.
					hideSavingOverlay();
					break;

				case 'jedb:cct-modal-close':
					closeModal( !! data.reload );
					break;
			}
		} );

		/* -----------------------------------------------------------------
		 * "Save & edit CCT row" buttons — each linked-panel has one
		 *
		 * alpha.7 (post L-027 bug report): always save the product form
		 * first, no dirty-check confirm dialog. The button label
		 * ("Save & edit ... in JetEngine") sets the expectation that a
		 * save happens. Saving a clean form is a harmless WP no-op.
		 *
		 * The alpha.6 dirty-check + confirm dialog caused an auto-launch
		 * loop because WP's autosave/heartbeat leaves the #post form
		 * looking "dirty" even immediately after a save (compare
		 * `defaultValue` to `value` on inputs that WP / 3rd-party
		 * plugins mutate post-load — many do). The confirm fired again
		 * on the reloaded page, click OK → save again → loop.
		 * -------------------------------------------------------------- */

		$( document ).on( 'click', '.jedb-open-cct-modal', function ( e ) {
			e.preventDefault();
			var $btn      = $( this );
			var url       = String( $btn.data( 'cct-edit-url' ) || '' );
			var bridgeId  = parseInt( $btn.data( 'bridge-id' ), 10 ) || 0;

			if ( ! url ) {
				window.alert( 'No CCT edit URL configured for this bridge.' );
				return;
			}

			// Stamp the reopen marker and submit the WP product form.
			// handle_save() reads `_jedb_reopen_cct_bridge`, persists a
			// 60-second transient keyed to this user+post. On the post-
			// save reload, maybe_enqueue_assets() reads the transient
			// and passes the bridge id to JS as
			// `jedbMetaBoxBootstrap.reopenBridgeId`. The auto-launch
			// block below opens the modal directly (bypassing this
			// click handler, so no loop).
			var $form = $( '#post' );
			if ( ! $form.length ) {
				// No #post form found (shouldn't happen on a product
				// edit screen). Fall back to opening the modal without
				// saving — the editor's product-side changes (if any)
				// will be lost on the parent reload after Done, but
				// that's better than doing nothing.
				openModal( url );
				return;
			}

			$form.append(
				$( '<input>', {
					type:  'hidden',
					name:  '_jedb_reopen_cct_bridge',
					value: bridgeId
				} )
			);
			$( '#publish' ).trigger( 'click' );
		} );

		// Auto-open the modal on this page render if a previous submission
		// flagged it (the editor clicked Save & edit, product saved, page
		// reloaded — we now open the modal for them).
		//
		// CRITICAL: this path opens the modal DIRECTLY via openModal()
		// rather than triggering a click on the button. Triggering a
		// click would re-enter the save-first flow above and submit the
		// form AGAIN, causing the alpha.6 loop. Auto-launch means "the
		// save already happened; just open the modal."
		if ( bootstrap.reopenBridgeId > 0 ) {
			var $reopenBtn = $( '.jedb-open-cct-modal[data-bridge-id="' + bootstrap.reopenBridgeId + '"]' );
			if ( $reopenBtn.length ) {
				var autoOpenUrl = String( $reopenBtn.data( 'cct-edit-url' ) || '' );
				if ( autoOpenUrl ) {
					// Small delay so the page is fully painted first.
					window.setTimeout( function () {
						openModal( autoOpenUrl );
					}, 250 );
				}
			}
		}

		/* =================================================================
		 * Bridge action buttons — Sync now / Unlink / Link
		 * (alpha.6.1 nested-form fix; see meta-box templates for context)
		 *
		 * WP meta boxes render inside the `#post` form. HTML5 forbids
		 * nested forms — the parser closes `#post` early on the inner
		 * `</form>`, pushing the WP Update button outside any form and
		 * breaking regular product saves (they fall through to
		 * admin-post.php with no action and WP redirects to
		 * wp-admin/edit.php). The templates therefore render plain
		 * <div>s with data attributes; we build the actual <form>
		 * here, append it to <body> (well outside `#post`), populate
		 * it, and submit it programmatically.
		 * ============================================================== */

		function buildAndSubmitForm( config ) {
			/* config: {
			 *   action      : '<admin-post.php URL>',
			 *   wpAction    : 'jedb_sync_now' | 'jedb_unlink' | 'jedb_link',
			 *   nonceField  : '_jedb_nonce',
			 *   nonceValue  : 'abc123…',
			 *   postId      : 123,
			 *   bridgeId    : 4,
			 *   extras      : { source_id: 5 }   // optional, action-specific
			 * }
			 */
			var $form = $( '<form>', {
				method: 'post',
				action: config.action,
				style:  'display:none;'
			} );

			function addHidden( name, value ) {
				$form.append( $( '<input>', { type: 'hidden', name: name, value: value } ) );
			}

			addHidden( 'action',                 config.wpAction );
			addHidden( config.nonceField,        config.nonceValue );
			addHidden( '_wp_http_referer',       window.location.href );
			addHidden( 'post_id',                config.postId );
			addHidden( 'bridge_id',              config.bridgeId );

			if ( config.extras ) {
				$.each( config.extras, function ( k, v ) {
					addHidden( k, v );
				} );
			}

			$( 'body' ).append( $form );
			$form.trigger( 'submit' );
		}

		// Linked panel: Sync now / Unlink buttons
		$( document ).on( 'click', '.jedb-bridge-action-btn', function ( e ) {
			e.preventDefault();
			var $btn      = $( this );
			var $wrap     = $btn.closest( '[data-jedb-form-action]' );
			if ( ! $wrap.length ) { return; }

			var confirmMsg = String( $btn.data( 'jedb-confirm' ) || '' );
			if ( confirmMsg && ! window.confirm( confirmMsg ) ) { return; }

			buildAndSubmitForm( {
				action:     String( $wrap.data( 'jedb-form-action' ) || '' ),
				wpAction:   String( $btn.data( 'jedb-action' ) || '' ),
				nonceField: String( $wrap.data( 'jedb-nonce-field' ) || '' ),
				nonceValue: String( $wrap.data( 'jedb-nonce-value' ) || '' ),
				postId:     parseInt( $wrap.data( 'jedb-post-id' ), 10 ) || 0,
				bridgeId:   parseInt( $wrap.data( 'jedb-bridge-id' ), 10 ) || 0
			} );
		} );

		// Unlinked panel: Link button — picks up the selected source_id from
		// the picker's <select data-jedb-field-name="source_id"> element.
		$( document ).on( 'click', '.jedb-bridge-link-btn', function ( e ) {
			e.preventDefault();
			var $btn  = $( this );
			var $wrap = $btn.closest( '[data-jedb-form-action]' );
			if ( ! $wrap.length ) { return; }

			var $picker  = $wrap.find( 'select[data-jedb-field-name]' );
			var fieldName = String( $picker.data( 'jedb-field-name' ) || 'source_id' );
			var sourceId  = $picker.val();

			if ( ! sourceId ) {
				window.alert( 'Pick a CCT row from the search results first.' );
				$picker.focus();
				return;
			}

			var extras = {};
			extras[ fieldName ] = sourceId;

			buildAndSubmitForm( {
				action:     String( $wrap.data( 'jedb-form-action' ) || '' ),
				wpAction:   String( $wrap.data( 'jedb-action' ) || '' ),
				nonceField: String( $wrap.data( 'jedb-nonce-field' ) || '' ),
				nonceValue: String( $wrap.data( 'jedb-nonce-value' ) || '' ),
				postId:     parseInt( $wrap.data( 'jedb-post-id' ), 10 ) || 0,
				bridgeId:   parseInt( $wrap.data( 'jedb-bridge-id' ), 10 ) || 0,
				extras:     extras
			} );
		} );

		/* =================================================================
		 * Unlinked panel — live CCT search (unchanged from alpha.4)
		 * ============================================================== */

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

			$search.one( 'focus', function () {
				search( '' );
			} );
		} );

		/* =================================================================
		 * Linked panel — lock checkbox confirm (unchanged)
		 * ============================================================== */

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

		/* =================================================================
		 * Helpers
		 * ============================================================== */

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
