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
					'</div>' +
				'</div>'
			);
			$( 'body' ).append( $modal );

			$modalIframe = $modal.find( '.jedb-cct-modal-iframe' );

			$modal.on( 'click', function ( e ) {
				if ( e.target === $modal.get( 0 ) ) {
					closeModal( /* reload */ false, /* confirm */ true );
				}
			} );

			$modal.find( '.jedb-cct-modal-close' ).on( 'click', function () {
				closeModal( /* reload */ false, /* confirm */ true );
			} );

			if ( ! modalCloseOnEscBound ) {
				$( document ).on( 'keydown', function ( e ) {
					if ( $modal && $modal.is( ':visible' ) && e.key === 'Escape' ) {
						closeModal( /* reload */ false, /* confirm */ true );
					}
				} );
				modalCloseOnEscBound = true;
			}

			return $modal;
		}

		function openModal( url ) {
			var $m = ensureModal();
			$modalIframe.attr( 'src', url );
			$m.show();
			$( 'body' ).addClass( 'jedb-cct-modal-open' );
		}

		function closeModal( shouldReload, confirmDirty ) {
			if ( ! $modal || ! $modal.is( ':visible' ) ) {
				return;
			}
			if ( confirmDirty && ! shouldReload ) {
				// We don't actually know if the iframe form is dirty (cross-frame
				// dirty-state detection is unreliable). Skip the confirm by default
				// — JE's own form will warn via beforeunload if it has its own
				// dirty-tracking, and the editor already had Cancel/Done buttons
				// inside the chrome-stripped iframe. A blanket "are you sure?"
				// here is more annoying than helpful.
			}
			$( 'body' ).removeClass( 'jedb-cct-modal-open' );
			$modal.hide();
			$modalIframe.attr( 'src', 'about:blank' );

			if ( shouldReload ) {
				window.location.reload();
			}
		}

		// Listen for postMessages from the chrome-stripped CCT edit iframe.
		// Only accept messages from the same origin as the parent (the iframe
		// is loaded from the same WP install, so origins match).
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin ) { return; }
			var data = event.data || {};
			if ( ! data || data.type !== 'jedb:cct-modal-close' ) { return; }
			closeModal( !! data.reload, false );
		} );

		/* -----------------------------------------------------------------
		 * "Save & edit CCT row" buttons — each linked-panel has one
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

			// If the product form is dirty, the parent product page should
			// save first so the editor doesn't lose any product-side changes.
			// Detection is best-effort — WP's #post form sets `wp.autosave`
			// dirty tracking when fields change.
			var dirty = isProductFormDirty();

			if ( dirty ) {
				var save = window.confirm(
					'You have unsaved product changes.\n\n' +
					'Click OK to SAVE the product first, then re-open the CCT editor.\n' +
					'Click Cancel to open the CCT editor anyway (your product changes will not be saved yet).'
				);

				if ( save ) {
					// Stamp a hidden marker so handle_save() sets a transient
					// that this script will read on the NEXT page render and
					// auto-launch the modal.
					var $form = $( '#post' );
					if ( $form.length ) {
						$form.append(
							$( '<input>', {
								type:  'hidden',
								name:  '_jedb_reopen_cct_bridge',
								value: bridgeId
							} )
						);
						$( '#publish' ).trigger( 'click' );
						return;
					}
				}
				// Cancel pressed or form not found → open modal without saving.
			}

			openModal( url );
		} );

		// Auto-open the modal on this page render if a previous submission
		// flagged it (the editor clicked Save & edit, product saved, page
		// reloaded — we now open the modal for them).
		if ( bootstrap.reopenBridgeId > 0 ) {
			var $btn = $( '.jedb-open-cct-modal[data-bridge-id="' + bootstrap.reopenBridgeId + '"]' );
			if ( $btn.length ) {
				// Small delay so the page is fully painted first.
				window.setTimeout( function () {
					$btn.trigger( 'click' );
				}, 250 );
			}
		}

		function isProductFormDirty() {
			// WP doesn't expose a clean "is this form dirty?" API outside
			// the block editor. Best-effort: if any input value differs
			// from its `defaultValue`, treat as dirty.
			var dirty = false;
			$( '#post :input' ).each( function () {
				var el = this;
				if ( ! el.name ) { return; }
				if ( el.type === 'checkbox' || el.type === 'radio' ) {
					if ( el.checked !== el.defaultChecked ) {
						dirty = true; return false;
					}
				} else if ( el.tagName === 'SELECT' ) {
					var sel = '';
					$( el ).find( 'option' ).each( function () {
						if ( this.defaultSelected ) { sel = this.value; }
					} );
					if ( sel !== $( el ).val() ) {
						dirty = true; return false;
					}
				} else if ( typeof el.defaultValue !== 'undefined' ) {
					if ( el.value !== el.defaultValue ) {
						dirty = true; return false;
					}
				}
			} );
			return dirty;
		}

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
