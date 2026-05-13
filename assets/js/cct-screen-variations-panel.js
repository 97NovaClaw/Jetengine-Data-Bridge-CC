/**
 * CCT-screen Variations Panel — Phase 4b alpha.14 (§4.7 / L-032).
 *
 * Injects a panel beneath the JE save button on the CCT edit page. The
 * panel offers an "Open variations editor →" button that opens the
 * linked WC product's edit page in a chrome-stripped modal iframe.
 *
 * Reuses the SAME modal mechanics as the L-027/L-029 CCT-edit-from-WC
 * modal in bridge-meta-box.js — same overlay + same postMessage protocol
 * + same sessionStorage close-on-save flag. Only the iframe URL differs
 * (WC product edit page vs JE CCT edit page).
 *
 * Decisions implemented:
 *   - D1 / R3 contextual hide: when any panel has a linked product
 *     (`target_post_id > 0`), hide the existing `.jedb-relations-block`
 *     because the row is already linked. The relations picker remains
 *     visible for unlinked rows.
 *   - D5 silent hide: when this script runs inside an iframe context
 *     (`window.top !== window.self`), the panel buttons are hidden so
 *     editors can't trigger nested-iframe chaos from inside the L-027
 *     modal.
 *
 * @package JEDB
 */

( function ( $ ) {
	'use strict';

	var config = window.jedbCctScreenVariationsConfig;
	if ( ! config || ! config.panels || ! config.panels.length ) {
		return;
	}

	var FORM_POLL_INTERVAL_MS = 200;
	var FORM_POLL_MAX_ATTEMPTS = 30;

	// D5: hide silently if we're already inside an iframe.
	var inIframeContext = ( window.top !== window.self );

	$( function () {
		waitForForm( injectPanels );
	} );

	function waitForForm( onReady ) {
		var attempts = 0;
		var tick = setInterval( function () {
			attempts++;
			var $form = $( 'form[action*="jet-cct-save-item"]' );
			if ( ! $form.length ) {
				$form = $( 'form[method="post"]' ).filter( function () {
					return $( this ).find( '[name="cct_action"]' ).length > 0;
				} );
			}
			if ( $form.length ) {
				clearInterval( tick );
				onReady( $form.first() );
				return;
			}
			if ( attempts >= FORM_POLL_MAX_ATTEMPTS ) {
				clearInterval( tick );
				if ( window.console && console.warn ) {
					console.warn( '[JEDB CCT Variations Panel] CCT save form not found after ' + attempts + ' polls; panel not injected.' );
				}
			}
		}, FORM_POLL_INTERVAL_MS );
	}

	function injectPanels( $form ) {

		// D1 / R3: if any panel has a linked product, the CCT row IS
		// already linked. Hide the relations picker block (it's only
		// useful when unlinked). Otherwise leave the relations block
		// visible so editors can link from here.
		var anyLinked = config.panels.some( function ( p ) { return p.target_post_id > 0; } );
		if ( anyLinked ) {
			$( '.jedb-relations-block' ).hide();
		}

		// Container that holds all per-bridge panels for this CCT row.
		var $wrap = $( '<div class="jedb-cct-screen-variations-wrap"/>' );

		config.panels.forEach( function ( panel ) {
			$wrap.append( buildPanel( panel ) );
		} );

		// Insert before the form's submit button (mirrors the relations
		// picker's injection point). Fallback: append to the form.
		var $submit = $form.find( '[type="submit"]' ).last();
		if ( $submit.length ) {
			$submit.before( $wrap );
		} else {
			$form.append( $wrap );
		}

		// Build the modal once on first use; reused across panels.
		ensureModal();
		bindMessageListener();
	}

	function buildPanel( panel ) {

		var $panel = $( '<div class="jedb-cct-screen-variations-panel"/>' )
			.attr( 'data-bridge-id', panel.bridge_id );

		var titleText = panel.title && panel.title.length ? panel.title : config.i18n.fallback_title;
		$panel.append( $( '<h3 class="jedb-cctv-title"/>' ).text( titleText ) );

		// If we couldn't resolve a linked product, show the link-first
		// helper text + a disabled button. Otherwise the normal helper
		// text + an active button that opens the modal.
		if ( ! panel.edit_url ) {
			$panel.append(
				$( '<p class="jedb-cctv-helper jedb-cctv-helper-missing"/>' )
					.text( config.i18n.missing_link )
			);
			$panel.append(
				$( '<button type="button" class="button"/>' )
					.text( config.i18n.open_button )
					.prop( 'disabled', true )
			);
			return $panel;
		}

		$panel.append(
			$( '<p class="jedb-cctv-helper"/>' ).text( config.i18n.helper_text )
		);

		var $btn = $( '<button type="button" class="button button-primary jedb-cctv-open-btn"/>' )
			.text( config.i18n.open_button )
			.attr( 'data-edit-url', panel.edit_url )
			.attr( 'data-auto-force', panel.auto_force_variable_type ? '1' : '0' );

		// D5: silent hide when in iframe context — prevents nested-iframe
		// chaos when this CCT edit page is itself loaded inside the
		// L-027 modal from a WC product page.
		if ( inIframeContext ) {
			$btn.hide();
		}

		$btn.on( 'click', function ( e ) {
			e.preventDefault();
			openModal( $btn.attr( 'data-edit-url' ) );
		} );

		$panel.append( $btn );

		return $panel;
	}

	/* =================================================================
	 * Modal mechanics — mirrors bridge-meta-box.js L-027/L-029 pattern
	 * ============================================================== */

	var $modal       = null;
	var $modalIframe = null;
	var escBound     = false;

	function ensureModal() {

		if ( $modal && $modal.length ) {
			return $modal;
		}

		$modal = $(
			'<div class="jedb-cctv-modal-overlay" role="dialog" aria-modal="true" style="display:none;">' +
				'<div class="jedb-cctv-modal-frame">' +
					'<button type="button" class="jedb-cctv-modal-close" aria-label="' + escAttr( config.i18n.modal_close ) + '" title="' + escAttr( config.i18n.modal_close ) + '">&times;</button>' +
					'<iframe class="jedb-cctv-modal-iframe" frameborder="0" allow="clipboard-write"></iframe>' +
					'<div class="jedb-cctv-modal-saving" style="display:none;">' +
						'<div class="jedb-cctv-modal-saving-inner">' +
							'<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' +
							'<span>' + escText( config.i18n.modal_saving ) + '</span>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $modal );
		$modalIframe = $modal.find( '.jedb-cctv-modal-iframe' );

		$modal.on( 'click', function ( e ) {
			if ( e.target === $modal.get( 0 ) ) {
				closeModal( false );
			}
		} );
		$modal.find( '.jedb-cctv-modal-close' ).on( 'click', function () {
			closeModal( false );
		} );

		if ( ! escBound ) {
			$( document ).on( 'keydown', function ( e ) {
				if ( $modal && $modal.is( ':visible' ) && e.key === 'Escape' ) {
					closeModal( false );
				}
			} );
			escBound = true;
		}

		return $modal;
	}

	function openModal( url ) {
		ensureModal();
		$modal.find( '.jedb-cctv-modal-saving' ).hide();
		$modalIframe.attr( 'src', url );
		$modal.show();
		$( 'body' ).addClass( 'jedb-cctv-modal-open' );
	}

	function closeModal( shouldReload ) {
		if ( ! $modal || ! $modal.is( ':visible' ) ) {
			return;
		}
		$( 'body' ).removeClass( 'jedb-cctv-modal-open' );
		$modal.hide();
		$modalIframe.attr( 'src', 'about:blank' );
		if ( shouldReload ) {
			window.location.reload();
		}
	}

	function showSavingOverlay() {
		if ( $modal && $modal.is( ':visible' ) ) {
			$modal.find( '.jedb-cctv-modal-saving' ).show();
		}
	}

	function hideSavingOverlay() {
		if ( $modal ) {
			$modal.find( '.jedb-cctv-modal-saving' ).hide();
		}
	}

	function bindMessageListener() {
		// postMessage protocol from the iframe. Emitted by the WC
		// product edit page's chrome-strip script (Phase B alpha.15)
		// rendered server-side by JEDB_CCT_Screen_Variations_Panel::
		// maybe_inject_wc_chrome_strip(). Message names use the `wc-`
		// prefix to distinguish from the symmetric `jedb:cct-*` traffic
		// flowing in the opposite direction (CCT edit page inside a
		// modal launched from a WC product page) — see L-027/L-029.
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin ) { return; }
			var data = event.data || {};
			if ( ! data || ! data.type ) { return; }

			switch ( data.type ) {
				case 'jedb:wc-save-starting':
					showSavingOverlay();
					break;
				case 'jedb:wc-save-error':
					hideSavingOverlay();
					break;
				case 'jedb:wc-modal-close':
					closeModal( !! data.reload );
					break;
			}
		} );
	}

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

} )( jQuery );
