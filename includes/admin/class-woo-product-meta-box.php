<?php
/**
 * Woo Bridge Meta Box — Phase 4 / Day 2 (D-27).
 *
 * Renders a meta box on `product` and `product_variation` edit screens
 * that surfaces the JEDB bridge(s) governing the current product. The
 * meta box is a *view* of existing flatten configs — never an authoring
 * surface for a separate concept (per D-25 / D-27 / L-026).
 *
 * Resolution at render time:
 *   1. Walk wp_jedb_flatten_configs for rows whose target_target matches
 *      the current post's type (e.g. `posts::product`).
 *   2. For each candidate config, call JEDB_Reverse_Flattener's existing
 *      resolve_source_id() in read-only mode (no auto-create) to find
 *      the linked source CCT row. Found → that bridge governs this
 *      product → render a linked panel. None found → render the
 *      unlinked picker for that bridge.
 *
 * Linked panel renders (per BUILD-PLAN §4.5):
 *   - Linked source row label + edit link
 *   - Bridge config slug + deep link to its row in the Flatten admin tab
 *   - Last 3 sync_log rows for this product (direction · status · age)
 *   - Surfaced field inputs (per-mapping `surface_on_target = true` AND
 *     target_adapter->is_natively_rendered($field) = false), grouped by
 *     `group` label
 *   - Per-product Lock checkbox → writes `_jedb_bridge_locked` post meta
 *   - Per-product Direction override radio → writes
 *     `_jedb_bridge_direction_override` post meta
 *   - Sync now button (POST to admin-post.php)
 *   - Unlink button (POST to admin-post.php)
 *
 * Unlinked panel renders:
 *   - List of compatible flatten configs (those whose target_target
 *     matches the post type)
 *   - Per config: an Ajax CCT picker (reuses the existing
 *     `wp_ajax_jedb_relation_search_items` endpoint from Phase 2)
 *   - Link button (calls JEDB_Relation_Attacher::attach() for
 *     je_relation bridges, OR writes cct_single_post_id for
 *     Has-Single-Page bridges).
 *
 * Reuses the verbatim patterns from JFB-WC for meta box registration,
 * save handler 4-guard preamble, conditional asset enqueue, and
 * admin_notices for save-time feedback (see BUILD-PLAN §12 audit table).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Woo_Product_Meta_Box {

	const META_BOX_ID         = 'jedb_bridge_meta_box';
	const NONCE_SAVE          = 'jedb_meta_box_save';
	const NONCE_SAVE_FIELD    = 'jedb_meta_box_save_nonce';
	const ACTION_SYNC_NOW     = 'jedb_meta_box_sync_now';
	const ACTION_UNLINK       = 'jedb_meta_box_unlink';
	const ACTION_LINK         = 'jedb_meta_box_link';
	const META_LOCKED         = '_jedb_bridge_locked';
	const META_DIRECTION_OVR  = '_jedb_bridge_direction_override';
	const META_LAST_MANUAL    = '_jedb_bridge_last_manual_sync_id';

	/** @var JEDB_Woo_Product_Meta_Box|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks() {

		// Field-preview helper — used by the meta box template's
		// read-only field rendering (alpha.6).
		require_once JEDB_PLUGIN_DIR . 'includes/helpers/field-preview.php';

		add_action( 'add_meta_boxes',                          array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_product',                       array( $this, 'handle_save' ), 20, 1 );
		add_action( 'save_post_product_variation',             array( $this, 'handle_save' ), 20, 1 );

		add_action( 'admin_post_' . self::ACTION_SYNC_NOW,     array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_' . self::ACTION_UNLINK,       array( $this, 'handle_unlink' ) );
		add_action( 'admin_post_' . self::ACTION_LINK,         array( $this, 'handle_link' ) );

		add_action( 'admin_enqueue_scripts',                   array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_notices',                           array( $this, 'maybe_render_notice' ) );

		// alpha.6 (L-027): when the JE CCT edit page is opened in our
		// modal iframe with `?jedb_chrome=stripped`, inject CSS that
		// hides WP chrome + a "Done · Return to product" button that
		// postMessages the parent window to close the modal and reload.
		// Hooked at admin_head so we run before JE's own admin_head
		// emissions; check_chrome_stripping_request() bails fast on
		// any page other than jet-cct-* with the query param.
		add_action( 'admin_head',                              array( $this, 'maybe_inject_cct_chrome_strip' ) );
	}

	/* -----------------------------------------------------------------------
	 * Notice renderer — JFB-WC admin_notices pattern (BUILD-PLAN §12)
	 * -------------------------------------------------------------------- */

	public function maybe_render_notice() {

		if ( ! isset( $_GET['jedb_meta_box_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['jedb_meta_box_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$map = array(
			'meta_box_sync_done'        => array( 'updated', __( 'Bridge sync executed. Status: %s. See the Debug tab\'s sync log for details.', 'je-data-bridge-cc' ) ),
			'meta_box_sync_invalid'     => array( 'error',   __( 'Sync failed — invalid post or bridge id.', 'je-data-bridge-cc' ) ),
			'meta_box_sync_no_source'   => array( 'warning', __( 'Sync skipped — this product has no linked source CCT row. Link it first.', 'je-data-bridge-cc' ) ),
			'meta_box_unlink_done'      => array( 'updated', __( 'Bridge unlinked. The product is no longer connected to its source CCT row via this bridge.', 'je-data-bridge-cc' ) ),
			'meta_box_unlink_already'   => array( 'warning', __( 'Already unlinked — nothing to do.', 'je-data-bridge-cc' ) ),
			'meta_box_unlink_invalid'   => array( 'error',   __( 'Unlink failed — invalid post or bridge id.', 'je-data-bridge-cc' ) ),
			'meta_box_link_done'        => array( 'updated', __( 'Linked successfully. The bridge will now sync this product on every save.', 'je-data-bridge-cc' ) ),
			'meta_box_link_invalid'     => array( 'error',   __( 'Link failed — invalid post, bridge id, or source id.', 'je-data-bridge-cc' ) ),
			'meta_box_link_no_relation' => array( 'error',   __( 'Link failed — the bridge has no JE Relation configured. Edit the bridge in the Flatten admin tab to set link_via.relation_id.', 'je-data-bridge-cc' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $msg_template ) = $map[ $notice ];

		$msg = $msg_template;
		if ( false !== strpos( $msg_template, '%s' ) ) {
			$msg = sprintf( $msg_template, $status !== '' ? $status : '—' );
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $msg )
		);
	}

	/* -----------------------------------------------------------------------
	 * Registration
	 * -------------------------------------------------------------------- */

	public function register_meta_boxes() {

		$post_types = array( 'product', 'product_variation' );

		// alpha.9 (L-031): one meta box per enabled bridge, not one
		// umbrella box for all bridges. Each registered box uses the
		// bridge's `meta_box.title` (fallback to its `label`) as its WP
		// header so editor edits in the Flatten admin tab propagate
		// natively. Each box uses its bridge's `meta_box.position`
		// (`normal` | `side` | `advanced`). Two CCTs linked to one
		// product = two stacked collapsible boxes, each clearly named.
		foreach ( $post_types as $pt ) {

			$target_slug = 'posts::' . $pt;
			$bridges     = $this->find_bridges_for_target( $target_slug );

			foreach ( $bridges as $bridge ) {

				$config       = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
				$meta_box_cfg = isset( $config['meta_box'] ) && is_array( $config['meta_box'] ) ? $config['meta_box'] : array();

				// Honor the bridge config's `enabled` flag: if a bridge
				// has the meta box disabled, skip registration entirely
				// (no empty placeholder, no screen-options entry).
				if ( isset( $meta_box_cfg['enabled'] ) && ! $meta_box_cfg['enabled'] ) {
					continue;
				}

				$bridge_id = (int) ( $bridge['id'] ?? 0 );
				if ( ! $bridge_id ) {
					continue;
				}

				$title = ! empty( $meta_box_cfg['title'] )
					? (string) $meta_box_cfg['title']
					: $this->bridge_display_label( $bridge );

				$position = isset( $meta_box_cfg['position'] ) ? (string) $meta_box_cfg['position'] : 'normal';
				if ( ! in_array( $position, array( 'normal', 'side', 'advanced' ), true ) ) {
					$position = 'normal';
				}

				add_meta_box(
					self::META_BOX_ID . '_' . $bridge_id,
					$title,
					function ( $post ) use ( $bridge ) {
						$this->render_meta_box_for_bridge( $post, $bridge );
					},
					$pt,
					$position,
					'default'
				);
			}
		}
	}

	/**
	 * Per JFB-WC pattern — only load JS/CSS on product / variation edit screens.
	 *
	 * @param string $hook
	 */
	public function maybe_enqueue_assets( $hook ) {

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		global $post_type;
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}

		wp_enqueue_style(
			'jedb-bridge-meta-box',
			JEDB_PLUGIN_URL . 'assets/css/bridge-meta-box.css',
			array(),
			JEDB_VERSION
		);

		wp_enqueue_script(
			'jedb-bridge-meta-box',
			JEDB_PLUGIN_URL . 'assets/js/bridge-meta-box.js',
			array( 'jquery' ),
			JEDB_VERSION,
			true
		);

		// alpha.6: pass the modal-reopen marker to JS so a previous-save
		// flagged "open the CCT editor for bridge X" can auto-launch the
		// modal on this page render. Transient is keyed per user+post
		// for safety and TTL=60s so it can't dangle.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reopen  = 0;
		if ( $post_id ) {
			$key    = 'jedb_reopen_cct_' . get_current_user_id() . '_' . (int) $post_id;
			$reopen = (int) get_transient( $key );
			if ( $reopen ) {
				delete_transient( $key );
			}
		}

		wp_localize_script(
			'jedb-bridge-meta-box',
			'jedbMetaBoxBootstrap',
			array(
				'reopenBridgeId' => (int) $reopen,
				'postId'         => (int) $post_id,
				'i18n'           => array(
					'closeConfirmDirty' => __( 'You have unsaved changes in the CCT editor. Close anyway?', 'je-data-bridge-cc' ),
					'closeButtonLabel'  => __( 'Done · Return to product', 'je-data-bridge-cc' ),
					'cancelButtonLabel' => __( 'Cancel · Discard CCT changes', 'je-data-bridge-cc' ),
					'modalTitleFormat'  => __( 'Edit: %s', 'je-data-bridge-cc' ),
				),
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Chrome-strip for the JE CCT edit page (alpha.6 / L-027)
	 * --------------------------------------------------------------------
	 *
	 * When the JE CCT edit page is loaded inside our modal iframe with
	 * `?jedb_chrome=stripped`, we hide the WP admin bar / sidebar /
	 * footer so the iframe shows only the CCT edit form. A "Done" button
	 * appears top-right that postMessages the parent window to close
	 * the modal and reload the product edit page (so it picks up any
	 * pushed-back values from JE's save → forward push flow).
	 *
	 * JE's save form is a standard HTML POST to `?cct_action=save-item`.
	 * After save, JE redirects to the edit URL again (or to the list
	 * page). We don't need to detect save success programmatically —
	 * the editor clicks "Done" when they're satisfied. Much simpler than
	 * fragile DOM observation.
	 */

	public function maybe_inject_cct_chrome_strip() {

		if ( ! is_admin() ) {
			return;
		}

		// Fast gate: only fire on jet-cct-* pages. Two-tier injection:
		//   Tier 1 (ALWAYS on jet-cct-* pages): the iframe-aware close-
		//     on-save handler. Runs only when in an iframe and only when
		//     sessionStorage flag is set. Needed because JE's post-save
		//     redirect strips our `jedb_chrome=stripped` query param,
		//     so the chrome-strip code (tier 2) doesn't run on the
		//     post-save page — but we still need to close the modal
		//     when the editor's save completes. (alpha.7 bug fix.)
		//   Tier 2 (only when ?jedb_chrome=stripped): the CSS that
		//     hides WP admin chrome + the Done/Cancel top bar +
		//     interception of JE's form submit to set the close flag.
		//
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['page'] )         ? sanitize_text_field( wp_unslash( $_GET['page'] ) )         : '';
		$chrome = isset( $_GET['jedb_chrome'] )  ? sanitize_key( wp_unslash( $_GET['jedb_chrome'] ) )         : '';
		$return = isset( $_GET['jedb_return'] )  ? absint( $_GET['jedb_return'] )                             : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $page || 0 !== strpos( $page, 'jet-cct-' ) ) {
			return;
		}
		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			return;
		}

		/* ------------------------------------------------------------
		 * TIER 1 — Always injected on jet-cct-* pages.
		 *
		 * Iframe-aware close-on-save handler. Listens for the
		 * sessionStorage `jedb_close_modal_on_load` flag (set by Tier 2's
		 * form-submit interceptor) and, if present, postMessages the
		 * parent to close the modal and reload the product page.
		 *
		 * Why this runs even WITHOUT `jedb_chrome=stripped`: JE's CCT
		 * save form posts to `?cct_action=save-item&page=...`, and
		 * after the save JE redirects to a URL constructed from its
		 * own state — typically the edit URL WITHOUT our extra query
		 * params. So the chrome-strip CSS/Done bar (Tier 2) doesn't
		 * apply on the post-save page, but we still need to detect
		 * that we just came back from a save inside the modal and
		 * close it. Tier 1 handles that.
		 *
		 * Validation-error guard: if the page contains a `.notice-error`
		 * (JE renders one for validation failures), we do NOT close the
		 * modal — the editor needs to see the error and fix their
		 * input. We clear the flag in either case so we don't loop.
		 *
		 * Page-flash mitigation: when we ARE closing, we set
		 * `html.style.visibility = 'hidden'` immediately so the editor
		 * doesn't see a flash of WP chrome before the parent closes the
		 * iframe.
		 * ----------------------------------------------------------- */
		?>
		<script id="jedb-cct-iframe-close-handler">
			(function () {
				if ( window.top === window.self ) {
					// Not in an iframe — these mechanics only apply
					// inside our modal. Direct visits to jet-cct pages
					// behave normally.
					return;
				}

				var FLAG_KEY = 'jedb_close_modal_on_load';

				// Read flag immediately (sessionStorage is available
				// before DOM is ready). If set, hide the page right now
				// so the editor doesn't see any flash of WP chrome
				// between paint and the parent's modal close.
				var shouldClose = false;
				try {
					shouldClose = sessionStorage.getItem( FLAG_KEY ) === '1';
				} catch ( e ) {}

				if ( ! shouldClose ) {
					return;
				}

				// Hide the page NOW (during head parsing — body doesn't
				// yet exist, but documentElement does).
				document.documentElement.style.visibility = 'hidden';

				// After DOM is parsed, inspect for validation error
				// notices. JE-style validation failure produces a
				// `.notice-error` near the top of the form. If found,
				// the save was rejected — un-hide so the editor can
				// see and fix the error. Otherwise postMessage parent
				// to close.
				document.addEventListener( 'DOMContentLoaded', function () {
					try { sessionStorage.removeItem( FLAG_KEY ); } catch ( e ) {}

					var hasError = !! document.querySelector(
						'.notice-error, .notice.notice-error'
					);

					if ( hasError ) {
						// Validation failure inside JE — show the page
						// so the editor can read and address the error.
						// Also tell the parent to hide its "Saving…"
						// overlay so the editor can interact with the
						// form again. The modal stays open.
						//
						// Note: chrome-strip CSS only runs when
						// `jedb_chrome=stripped` is in the URL, which
						// JE's post-save redirect typically drops, so
						// the editor may see admin bar / sidebar here.
						// Acceptable fallback: error states are
						// recoverable (fix input, click Done again).
						document.documentElement.style.visibility = '';

						try {
							window.parent.postMessage(
								{ type: 'jedb:cct-save-error' },
								window.location.origin
							);
						} catch ( e ) {}
						return;
					}

					try {
						window.parent.postMessage(
							{ type: 'jedb:cct-modal-close', reload: true },
							window.location.origin
						);
					} catch ( err ) {
						// postMessage failed; restore visibility so the
						// editor isn't stuck on a blank page.
						document.documentElement.style.visibility = '';
					}
				} );
			})();
		</script>
		<?php

		/* ------------------------------------------------------------
		 * TIER 2 — Only when explicitly opened from the modal launcher.
		 *
		 * Chrome strip + Done/Cancel top bar + JE form submit
		 * interceptor (which sets the sessionStorage close flag so
		 * Tier 1 closes the modal on the post-save reload).
		 * ----------------------------------------------------------- */
		if ( 'stripped' !== $chrome ) {
			return;
		}

		$return_url = $return ? get_edit_post_link( $return, 'raw' ) : '';
		?>
		<style id="jedb-cct-chrome-strip">
			html.wp-toolbar { padding-top: 0 !important; }
			#wpadminbar, #adminmenuwrap, #adminmenuback, #adminmenu, #wpfooter, #screen-meta, #screen-meta-links { display: none !important; }
			#wpcontent, #wpbody-content { margin-left: 0 !important; padding-top: 0 !important; }
			#wpbody { padding-top: 0 !important; }
			body.wp-admin { background: #f6f7f7; }
			/* Reserve room for the floating Done bar at the top. */
			.wrap { padding-top: 56px !important; }
			.jedb-cct-frame-bar {
				position: fixed;
				top: 0; left: 0; right: 0;
				height: 48px;
				background: #1d2327;
				color: #fff;
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 0 16px;
				z-index: 99999;
				box-shadow: 0 2px 6px rgba(0,0,0,0.15);
				font-size: 13px;
			}
			.jedb-cct-frame-bar .jedb-cct-frame-title { font-weight: 600; }
			.jedb-cct-frame-bar .jedb-cct-frame-actions { display: flex; gap: 8px; }
			.jedb-cct-frame-bar button {
				background: #2271b1;
				color: #fff;
				border: 1px solid transparent;
				padding: 6px 14px;
				border-radius: 3px;
				cursor: pointer;
				font-size: 13px;
				font-weight: 500;
			}
			.jedb-cct-frame-bar button.jedb-cct-frame-cancel {
				background: transparent;
				border-color: rgba(255,255,255,0.3);
			}
			.jedb-cct-frame-bar button:hover { opacity: 0.9; }
		</style>
		<script id="jedb-cct-chrome-strip-js">
			(function () {
				if ( window.top === window.self ) {
					// Not in an iframe — abort. Direct visits with the
					// query param shouldn't strip chrome.
					return;
				}

				var FLAG_KEY = 'jedb_close_modal_on_load';

				function setCloseFlag() {
					try { sessionStorage.setItem( FLAG_KEY, '1' ); } catch ( e ) {}
				}

				function notifyParent( msg ) {
					try {
						window.parent.postMessage( msg, window.location.origin );
					} catch ( e ) {}
				}

				function postClose( reload ) {
					try {
						window.parent.postMessage(
							{ type: 'jedb:cct-modal-close', reload: !! reload },
							window.location.origin
						);
					} catch ( e ) {
						<?php if ( $return_url ) : ?>
						window.parent.location = <?php echo wp_json_encode( $return_url ); ?>;
						<?php endif; ?>
					}
				}

				document.addEventListener( 'DOMContentLoaded', function () {

					// ----- JE form submit interceptor -----
					// JE's CCT save form posts to ?cct_action=save-item.
					// We attach a submit listener that:
					//   (a) sets the close flag so Tier 1 closes the
					//       modal on the post-save reload, AND
					//   (b) tells the parent to show a "Saving…" overlay
					//       so the editor has feedback during the
					//       form POST → server save → redirect → reload
					//       round-trip (typically 200-800ms).
					// Fires for both JE's native Save button click AND
					// for our Done button below (which clicks JE's
					// submit button programmatically).
					var jeForm = document.querySelector( 'form[action*="jet-cct-save-item"], form[action*="cct_action=save-item"]' );
					if ( jeForm ) {
						jeForm.addEventListener( 'submit', function () {
							setCloseFlag();
							notifyParent( { type: 'jedb:cct-save-starting' } );
						} );
					}

					// ----- Top bar with Done and Cancel buttons -----
					var bar = document.createElement( 'div' );
					bar.className = 'jedb-cct-frame-bar';

					var title = document.createElement( 'span' );
					title.className = 'jedb-cct-frame-title';
					title.textContent = <?php echo wp_json_encode( __( 'Editing linked CCT row — Done saves & closes; Cancel discards changes.', 'je-data-bridge-cc' ) ); ?>;

					var actions = document.createElement( 'span' );
					actions.className = 'jedb-cct-frame-actions';

					// Done = save JE's form (via clicking its submit
					// button so submit events fire) then let Tier 1
					// close the modal on the post-save reload.
					var doneBtn = document.createElement( 'button' );
					doneBtn.type = 'button';
					doneBtn.textContent = <?php echo wp_json_encode( __( 'Done · Save & return to product', 'je-data-bridge-cc' ) ); ?>;
					doneBtn.addEventListener( 'click', function () {
						if ( ! jeForm ) {
							// No JE form found — fall back to closing
							// without saving so the user isn't stuck.
							postClose( false );
							return;
						}

						// Set the close flag + notify parent immediately
						// (belt-and-suspenders — the submit listener
						// above will also fire, but covering the
						// edge case where `form.submit()` is used as
						// fallback and doesn't trigger submit events).
						setCloseFlag();
						notifyParent( { type: 'jedb:cct-save-starting' } );

						// Prefer clicking JE's actual submit button so
						// validation / event handlers fire normally. Fall
						// back to form.submit() if no button is found.
						var submitBtn = jeForm.querySelector( 'button[type="submit"], input[type="submit"]' );
						if ( submitBtn ) {
							submitBtn.click();
						} else {
							jeForm.submit();
						}
					} );

					// Cancel = close without saving. Editor's in-iframe
					// edits are discarded (they never POSTed anywhere).
					var cancelBtn = document.createElement( 'button' );
					cancelBtn.type = 'button';
					cancelBtn.className = 'jedb-cct-frame-cancel';
					cancelBtn.textContent = <?php echo wp_json_encode( __( 'Cancel · Discard changes', 'je-data-bridge-cc' ) ); ?>;
					cancelBtn.addEventListener( 'click', function () {
						postClose( false );
					} );

					actions.appendChild( cancelBtn );
					actions.appendChild( doneBtn );
					bar.appendChild( title );
					bar.appendChild( actions );

					document.body.insertBefore( bar, document.body.firstChild );
				} );
			})();
		</script>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * Render
	 * -------------------------------------------------------------------- */

	/**
	 * Top-level render orchestrator. Resolves which bridge(s) govern this
	 * post, then includes the appropriate template for each.
	 *
	 * @param WP_Post $post
	 */
	/**
	 * Render the meta box for ONE bridge (alpha.9 one-box-per-bridge
	 * model). Replaces the alpha.4-alpha.8 `render_meta_box()` which
	 * looped all bridges inside a single umbrella box.
	 *
	 * Resolves linked-vs-unlinked state for this specific bridge × post
	 * pair, then delegates to the linked or unlinked template. Each
	 * call writes its own nonce so per-bridge save handlers can verify
	 * against the same NONCE_SAVE constant.
	 *
	 * @param WP_Post $post
	 * @param array   $bridge  Decoded flatten config row.
	 */
	public function render_meta_box_for_bridge( $post, $bridge ) {

		wp_nonce_field( self::NONCE_SAVE, self::NONCE_SAVE_FIELD );

		$resolution     = $this->resolve_for_post( $bridge, $post );
		$lock_value     = (bool) get_post_meta( $post->ID, self::META_LOCKED, true );
		$override_value = (string) get_post_meta( $post->ID, self::META_DIRECTION_OVR, true );

		echo '<div class="jedb-meta-box-wrap" data-post-id="' . esc_attr( (int) $post->ID ) . '" data-bridge-id="' . esc_attr( (int) ( $bridge['id'] ?? 0 ) ) . '">';

		if ( ! empty( $resolution['source_id'] ) ) {
			$this->render_linked_panel( $post, $bridge, $resolution, $lock_value, $override_value );
		} else {
			$this->render_unlinked_panel( $post, $bridge, $resolution );
		}

		echo '</div>';
	}

	/**
	 * Render the linked-state template for one bridge.
	 *
	 * @param WP_Post $post
	 * @param array   $bridge
	 * @param array   $resolution { source_id, source_data, source_adapter, target_adapter, ... }
	 * @param bool    $lock_value
	 * @param string  $override_value
	 */
	private function render_linked_panel( $post, $bridge, $resolution, $lock_value, $override_value ) {

		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$meta_box_cfg  = isset( $config['meta_box'] ) && is_array( $config['meta_box'] ) ? $config['meta_box'] : JEDB_Flatten_Config_Manager::default_meta_box();
		$mappings      = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : array();

		$bridge_label  = $this->bridge_display_label( $bridge );
		$panel_title   = ! empty( $meta_box_cfg['title'] ) ? (string) $meta_box_cfg['title'] : $bridge_label;

		// alpha.9 (L-031): show_advanced gates the bottom "<details>
		// Advanced Details" collapsible. When false (default), the
		// panel renders only surfaced field previews + the "Save & edit"
		// modal launcher button. When true, an additional <details>
		// section appears with per-product overrides, recent sync log,
		// and Sync now / Unlink action buttons.
		$show_advanced = ! empty( $meta_box_cfg['show_advanced'] );

		// Build the surfaced-field display rows. Per alpha.5 design
		// (response to user's "does it need a target field?" finding):
		// surface_on_target is decoupled from target_field requirement
		// AND from the D-16 native-rendering skip. Editor's tick is the
		// authoritative signal — the meta box trusts their intent.
		// build_surfaced_groups returns both the rendered groups AND a
		// diagnostic list of any mappings that COULDN'T surface (e.g.
		// missing source_field) so the template can show useful "why
		// not?" messages instead of a misleading blank state.
		$target_adapter   = $resolution['target_adapter'];
		$source_adapter   = $resolution['source_adapter'];
		$source_id        = (int) $resolution['source_id'];
		$source_data      = $resolution['source_data'];
		$source_label     = $this->source_record_label( $source_adapter, $source_id, $source_data );
		$surface_result   = $this->build_surfaced_groups( $mappings, $target_adapter, $source_adapter, $source_data, $meta_box_cfg );
		$surfaced_groups  = $surface_result['groups'];
		$surface_skipped  = $surface_result['skipped'];
		$recent_log       = $show_advanced ? $this->recent_log_for_post( $bridge, $post->ID ) : array();
		$last_manual_id   = (int) get_post_meta( $post->ID, self::META_LAST_MANUAL, true );

		// alpha.12 (Phase 4 Day 4): compute mandatory coverage for the
		// Advanced Details section. Only meaningful when show_advanced
		// is on — keep the compact surface uncluttered. Combines the
		// target adapter's required fields with the bridge's
		// required_overrides (provenance-tagged), then cross-references
		// the bridge's mappings to flag covered vs missing.
		$coverage_required = array();
		$coverage_missing  = array();
		if ( $show_advanced && class_exists( 'JEDB_Field_Presets_Manager' ) && $target_adapter && method_exists( $target_adapter, 'get_required_fields' ) ) {
			$adapter_required  = (array) $target_adapter->get_required_fields();
			$coverage_required = JEDB_Field_Presets_Manager::compute_effective_required_fields( $config, $adapter_required );

			$mapped_targets = array();
			foreach ( $mappings as $m ) {
				if ( is_array( $m ) && ! empty( $m['target_field'] ) ) {
					$mapped_targets[ (string) $m['target_field'] ] = true;
				}
			}
			foreach ( $coverage_required as $row ) {
				if ( ! isset( $mapped_targets[ $row['name'] ] ) ) {
					$coverage_missing[] = $row;
				}
			}
		}

		// alpha.13 (Phase 4b / §4.7): variations status snapshot for
		// Advanced Details. Lists each variations[] entry with its
		// current managed variation ID (if any) and whether show_when
		// currently evaluates true against the source row. Read-only
		// diagnostic — editors author variations in the Flatten admin
		// tab. Only meaningful for Woo product targets.
		$variations_status = array();
		if ( $show_advanced && ! empty( $config['variations'] ) && 'posts::product' === ( $bridge['target_target'] ?? '' ) && class_exists( 'JEDB_Target_Woo_Variation' ) ) {
			$variation_adapter = JEDB_Target_Registry::instance()->get( 'posts::product_variation' );
			$evaluator         = class_exists( 'JEDB_Condition_Evaluator' ) ? JEDB_Condition_Evaluator::instance() : null;
			$bridge_id_int     = (int) ( $bridge['id'] ?? 0 );
			$dsl_context       = array(
				'source' => $source_data,
				'target' => $target_adapter ? (array) $target_adapter->get( (int) $post->ID ) : array(),
			);

			foreach ( $config['variations'] as $variation_rule ) {
				if ( ! is_array( $variation_rule ) || empty( $variation_rule['slug'] ) ) {
					continue;
				}
				$v_slug      = sanitize_text_field( (string) $variation_rule['slug'] );
				$v_enabled   = isset( $variation_rule['enabled'] ) ? (bool) $variation_rule['enabled'] : true;
				$v_when      = isset( $variation_rule['show_when'] ) ? (string) $variation_rule['show_when'] : '';
				$existing_id = 0;
				if ( $variation_adapter && method_exists( $variation_adapter, 'find_managed_variation' ) ) {
					$existing_id = (int) $variation_adapter->find_managed_variation( (int) $post->ID, $bridge_id_int, $v_slug );
				}
				$should_show = true;
				if ( '' !== trim( $v_when ) && $evaluator ) {
					try { $should_show = (bool) $evaluator->evaluate( $v_when, $dsl_context ); } catch ( \Throwable $t ) { $should_show = false; }
				}
				$variations_status[] = array(
					'slug'        => $v_slug,
					'label'       => isset( $variation_rule['label'] ) ? (string) $variation_rule['label'] : '',
					'enabled'     => $v_enabled,
					'should_show' => $should_show,
					'existing_id' => $existing_id,
				);
			}
		}

		// Flatten admin tab deep link for "Edit this bridge".
		$flatten_edit_url = add_query_arg(
			array(
				'page' => 'jedb',
				'tab'  => 'flatten',
				'edit' => (int) $bridge['id'],
			),
			admin_url( 'admin.php' )
		);

		include JEDB_PLUGIN_DIR . 'templates/admin/meta-box-bridge.php';
	}

	/**
	 * Render the unlinked-state template for one bridge.
	 *
	 * @param WP_Post $post
	 * @param array   $bridge
	 * @param array   $resolution
	 */
	private function render_unlinked_panel( $post, $bridge, $resolution ) {

		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$meta_box_cfg  = isset( $config['meta_box'] ) && is_array( $config['meta_box'] ) ? $config['meta_box'] : JEDB_Flatten_Config_Manager::default_meta_box();
		$bridge_label  = $this->bridge_display_label( $bridge );
		$panel_title   = ! empty( $meta_box_cfg['title'] ) ? (string) $meta_box_cfg['title'] : $bridge_label;

		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$relations_nonce = wp_create_nonce( 'jedb_relations' );

		include JEDB_PLUGIN_DIR . 'templates/admin/meta-box-bridge-unlinked.php';
	}

	/* -----------------------------------------------------------------------
	 * Resolution helpers
	 * -------------------------------------------------------------------- */

	/**
	 * Return the enabled flatten configs whose target_target matches the
	 * given slug. Bridges where the meta box is explicitly disabled
	 * (config.meta_box.enabled === false) are still returned here — the
	 * render method filters them so they don't render a panel, but
	 * we still walk the resolution path so callers (e.g. AJAX endpoints)
	 * can pick them up.
	 *
	 * @param string $target_slug
	 * @return array<int,array>
	 */
	public function find_bridges_for_target( $target_slug ) {

		$mgr = JEDB_Flatten_Config_Manager::instance();

		return $mgr->get_all( array(
			'enabled'       => 1,
			'target_target' => $target_slug,
		) );
	}

	/**
	 * Given a bridge config + a post, return:
	 *   - source_id        — int (the linked CCT row id) or 0 if unlinked
	 *   - source_data      — array (the row data) or array()
	 *   - source_adapter   — JEDB_Data_Target or null
	 *   - target_adapter   — JEDB_Data_Target or null
	 *   - resolution       — 'relation_row' | 'cct_single_post_id' | 'none'
	 *
	 * Uses JEDB_Reverse_Flattener::resolve_source_id() in read-only mode
	 * (auto_create_target_when_unlinked is ignored at the reverse engine
	 * level; we don't trigger it here).
	 *
	 * @param array   $bridge
	 * @param WP_Post $post
	 * @return array
	 */
	private function resolve_for_post( array $bridge, $post ) {

		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();

		$registry       = JEDB_Target_Registry::instance();
		$source_adapter = $registry->get( $source_target );
		$target_adapter = $registry->get( $target_target );

		$out = array(
			'source_id'      => 0,
			'source_data'    => array(),
			'source_adapter' => $source_adapter,
			'target_adapter' => $target_adapter,
			'resolution'     => 'none',
		);

		if ( ! $source_adapter || ! $target_adapter ) {
			return $out;
		}

		$target_data = $target_adapter->get( (int) $post->ID );
		if ( ! is_array( $target_data ) ) {
			$target_data = array();
		}

		// We want read-only resolution — explicitly clone the config
		// without auto_create_target_when_unlinked so that the reverse
		// flattener's resolve_source_id() doesn't create a CCT row as a
		// side effect of a meta box render.
		$ro_config = $config;
		$ro_config['auto_create_target_when_unlinked'] = false;

		list( $source_id, $resolution_method, $auto_created, $auto_attached ) =
			JEDB_Reverse_Flattener::instance()->resolve_source_id(
				$ro_config,
				$source_target,
				$target_target,
				(int) $post->ID,
				$target_data
			);

		$source_id   = (int) $source_id;
		$source_data = array();
		if ( $source_id ) {
			// alpha.8 (L-030): meta box render must see the freshest source
			// data. After a modal Done save, the CCT row is updated and the
			// forward push fires (in the iframe's request) to update the
			// product target. The parent then reloads and we render here.
			// If a persistent object cache (Redis/Memcached) is in play AND
			// JE doesn't `wp_cache_delete()` after its low-level $db->update()
			// — same asymmetric-API quirk as L-022 — `$source_adapter->get()`
			// can return the pre-save cached row. Forward push got fresh
			// data because it runs in the same request as the save; we don't.
			//
			// Workaround: when the adapter exposes a `get_fresh()` method
			// (Target_CCT does), call that — it bypasses every JE-side
			// caching layer and reads direct from the underlying table.
			// Non-CCT adapters fall through to standard `get()`.
			if ( method_exists( $source_adapter, 'get_fresh' ) ) {
				$got = $source_adapter->get_fresh( $source_id );
			} else {
				$got = $source_adapter->get( $source_id );
			}
			if ( is_array( $got ) ) {
				$source_data = $got;
			}
		}

		return array(
			'source_id'      => $source_id,
			'source_data'    => $source_data,
			'source_adapter' => $source_adapter,
			'target_adapter' => $target_adapter,
			'resolution'     => (string) $resolution_method,
		);
	}

	/**
	 * Compute the surfaced-field groups payload that the linked-panel
	 * template iterates over.
	 *
	 * Per alpha.5 (response to user's "does it need a target field?"
	 * finding): surface is decoupled from sync. A mapping is surfaced
	 * when `surface_on_target=true`, regardless of whether `target_field`
	 * is set or whether the target adapter natively renders the field.
	 *
	 *   - `source_field` set, `target_field=''`     → "pure-surface": renders an editor for the source field only. No sync side effects.
	 *   - both set, target natively rendered (D-16) → "sync + surface" — editor opted in by ticking the box; D-2 (CCT-canonical) resolves any conflict if they also use Woo's native input.
	 *   - both set, target NOT natively rendered   → "sync + surface" — standard alpha.4 behavior.
	 *
	 * Mappings that can't be rendered get logged into the `skipped[]`
	 * array with a reason so the template can show "why didn't my
	 * flagged field render?" diagnostics instead of a blank state.
	 *
	 * Groups are ordered per the bridge config's `meta_box.groups[]`
	 * list (explicit ordering), then alphabetically for any unlisted
	 * groups, with an unnamed group ("") going last and labeled
	 * "Ungrouped".
	 *
	 * @return array{groups:array<int,array{label:string,fields:array<int,array>}>,skipped:array<int,array{source_field:string,target_field:string,reason:string}>}
	 */
	private function build_surfaced_groups( array $mappings, $target_adapter, $source_adapter, array $source_data, array $meta_box_cfg ) {

		$groups  = array();
		$skipped = array();

		foreach ( $mappings as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			if ( empty( $m['surface_on_target'] ) ) {
				// Not surfaced at all — don't even record as skipped.
				continue;
			}

			$tgt_field = isset( $m['target_field'] ) ? (string) $m['target_field'] : '';
			$src_field = isset( $m['source_field'] ) ? (string) $m['source_field'] : '';

			$skip_entry = array(
				'source_field' => $src_field,
				'target_field' => $tgt_field,
			);

			if ( empty( $m['enabled'] ) ) {
				$skip_entry['reason'] = __( 'mapping is disabled', 'je-data-bridge-cc' );
				$skipped[] = $skip_entry;
				continue;
			}

			if ( '' === $src_field ) {
				$skip_entry['reason'] = __( 'no source_field set — surface needs a source value to read/write', 'je-data-bridge-cc' );
				$skipped[] = $skip_entry;
				continue;
			}

			// alpha.5: surface_on_target works WITHOUT a target_field
			// (pure-surface mode) AND for target fields that Woo natively
			// renders. The editor's tick is authoritative.

			$group_key = isset( $m['group'] ) ? trim( (string) $m['group'] ) : '';

			// Label / type lookup: prefer target schema (if target_field
			// set and resolvable), fall back to source schema, fall back
			// to whichever field name is non-empty.
			$schema = null;
			if ( '' !== $tgt_field && $target_adapter ) {
				$schema = $this->find_schema_entry( $target_adapter, $tgt_field );
			}
			if ( ! $schema && $source_adapter ) {
				$schema = $this->find_schema_entry( $source_adapter, $src_field );
			}

			$label_fallback = '' !== $tgt_field ? $tgt_field : $src_field;

			$current_source_value = array_key_exists( $src_field, $source_data ) ? $source_data[ $src_field ] : '';

			// Annotate the mode for the template (renders a small hint
			// next to each field — useful for editors to understand what
			// this input actually does).
			$mode = '' === $tgt_field
				? 'pure_surface'
				: ( ( $target_adapter && method_exists( $target_adapter, 'is_natively_rendered' ) && $target_adapter->is_natively_rendered( $tgt_field ) )
					? 'native_overlay'
					: 'sync_and_surface' );

			$row = array(
				'source_field' => $src_field,
				'target_field' => $tgt_field,
				'label'        => isset( $schema['label'] ) && '' !== $schema['label'] ? (string) $schema['label'] : $label_fallback,
				'type'         => isset( $schema['type'] ) ? (string) $schema['type'] : 'text',
				'note'         => isset( $m['note'] ) ? (string) $m['note'] : '',
				'value'        => $current_source_value,
				'mode'         => $mode,
			);

			$gk = '' === $group_key ? '__ungrouped__' : $group_key;
			if ( ! isset( $groups[ $gk ] ) ) {
				$groups[ $gk ] = array(
					'label'  => '' === $group_key ? __( 'Ungrouped', 'je-data-bridge-cc' ) : $group_key,
					'fields' => array(),
				);
			}
			$groups[ $gk ]['fields'][] = $row;
		}

		// Order groups: explicit order from meta_box.groups[] first, then
		// alphabetical for any unlisted, ungrouped last.
		$ordered = array();
		$listed  = is_array( $meta_box_cfg['groups'] ?? null ) ? $meta_box_cfg['groups'] : array();
		foreach ( $listed as $name ) {
			if ( isset( $groups[ $name ] ) ) {
				$ordered[] = $groups[ $name ];
				unset( $groups[ $name ] );
			}
		}
		$ungrouped = null;
		if ( isset( $groups['__ungrouped__'] ) ) {
			$ungrouped = $groups['__ungrouped__'];
			unset( $groups['__ungrouped__'] );
		}
		uasort( $groups, static function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );
		foreach ( $groups as $g ) {
			$ordered[] = $g;
		}
		if ( $ungrouped ) {
			$ordered[] = $ungrouped;
		}

		return array(
			'groups'  => $ordered,
			'skipped' => $skipped,
		);
	}

	/**
	 * @param JEDB_Data_Target $adapter
	 * @param string           $field_name
	 * @return array|null
	 */
	private function find_schema_entry( $adapter, $field_name ) {

		if ( ! $adapter || ! method_exists( $adapter, 'get_field_schema' ) ) {
			return null;
		}
		$schema = $adapter->get_field_schema();
		if ( ! is_array( $schema ) ) {
			return null;
		}
		foreach ( $schema as $entry ) {
			if ( isset( $entry['name'] ) && (string) $entry['name'] === (string) $field_name ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Top-3 sync_log rows for the given (bridge, post). Used in the
	 * "Last syncs" block of the linked panel.
	 *
	 * @return array<int,array>
	 */
	private function recent_log_for_post( array $bridge, $post_id ) {

		global $wpdb;

		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$table         = $wpdb->prefix . 'jedb_sync_log';

		$sql = "SELECT * FROM `{$table}` WHERE target_target = %s AND target_id = %s AND source_target = %s ORDER BY id DESC LIMIT 3";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $target_target, (string) (int) $post_id, $source_target ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$r ) {
			$ctx = ! empty( $r['context_json'] ) ? json_decode( (string) $r['context_json'], true ) : null;
			$r['context'] = is_array( $ctx ) ? $ctx : array();
		}
		unset( $r );

		return $rows;
	}

	/* -----------------------------------------------------------------------
	 * Save handler — per-product overrides + surfaced field inline edits
	 * -------------------------------------------------------------------- */

	public function handle_save( $post_id ) {

		// Canonical 4-guard preamble (JFB-WC pattern):
		if ( ! isset( $_POST[ self::NONCE_SAVE_FIELD ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_SAVE_FIELD ], self::NONCE_SAVE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		// Per-product Lock + Direction override always written (whether
		// the meta box was visible or not). The post meta is the
		// canonical source of truth that the engine guards (alpha.3)
		// read.
		$lock_was_submitted = isset( $_POST['jedb_meta_box_present'] );
		if ( $lock_was_submitted ) {
			if ( isset( $_POST['jedb_bridge_locked'] ) ) {
				update_post_meta( $post_id, self::META_LOCKED, 1 );
			} else {
				delete_post_meta( $post_id, self::META_LOCKED );
			}

			$override = isset( $_POST['jedb_bridge_direction_override'] ) ? sanitize_key( wp_unslash( $_POST['jedb_bridge_direction_override'] ) ) : '';
			if ( ! in_array( $override, array( '', 'push', 'pull', 'bidirectional', 'none' ), true ) ) {
				$override = '';
			}
			if ( '' === $override ) {
				delete_post_meta( $post_id, self::META_DIRECTION_OVR );
			} else {
				update_post_meta( $post_id, self::META_DIRECTION_OVR, $override );
			}
		}

		// alpha.6: the meta box no longer writes to source from inline
		// edits — those are now delegated to JE's CCT edit page via the
		// modal-iframe flow (L-027). The only thing handle_save still
		// does is persist per-product override post meta (lock and
		// direction override) which are pure product-side concerns.
		//
		// One forward-compat marker we DO store here: if the editor
		// clicked "Save & edit CCT row" on a specific bridge, the form
		// submits with `_jedb_reopen_cct_bridge` set so the next page
		// render can auto-open the modal. We just persist that to a
		// short-lived user transient so the bootstrap reads it on the
		// next request without polluting the post or its URL.
		$reopen_bridge_id = isset( $_POST['_jedb_reopen_cct_bridge'] ) ? absint( $_POST['_jedb_reopen_cct_bridge'] ) : 0;
		if ( $reopen_bridge_id > 0 ) {
			set_transient(
				'jedb_reopen_cct_' . get_current_user_id() . '_' . (int) $post_id,
				(int) $reopen_bridge_id,
				60
			);
		}
	}

	/* -----------------------------------------------------------------------
	 * Action handlers: Sync now, Unlink, Link
	 * -------------------------------------------------------------------- */

	public function handle_sync_now() {

		$this->guard_action( self::NONCE_SAVE );

		$post_id   = isset( $_POST['post_id'] )   ? absint( $_POST['post_id'] )   : 0;
		$bridge_id = isset( $_POST['bridge_id'] ) ? absint( $_POST['bridge_id'] ) : 0;

		if ( ! $post_id || ! $bridge_id ) {
			$this->redirect_back( $post_id, 'meta_box_sync_invalid' );
		}

		$bridge = JEDB_Flatten_Config_Manager::instance()->get_by_id( $bridge_id );
		if ( ! $bridge ) {
			$this->redirect_back( $post_id, 'meta_box_sync_invalid' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->redirect_back( $post_id, 'meta_box_sync_invalid' );
		}

		$resolution = $this->resolve_for_post( $bridge, $post );
		if ( empty( $resolution['source_id'] ) ) {
			$this->redirect_back( $post_id, 'meta_box_sync_no_source' );
		}

		// Forward push from source → this post.
		$status = JEDB_Flattener::instance()->apply_bridge(
			$bridge,
			(int) $resolution['source_id'],
			'meta_box_sync_now'
		);

		// Best-effort: capture the most recent sync_log row id for the
		// status badge in the meta box.
		global $wpdb;
		$table  = $wpdb->prefix . 'jedb_sync_log';
		$row_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE target_id = %s AND target_target = %s ORDER BY id DESC LIMIT 1", (string) (int) $post_id, isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '' ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		if ( $row_id ) {
			update_post_meta( $post_id, self::META_LAST_MANUAL, $row_id );
		}

		$this->redirect_back( $post_id, 'meta_box_sync_done', array( 'status' => $status ) );
	}

	public function handle_unlink() {

		$this->guard_action( self::NONCE_SAVE );

		$post_id   = isset( $_POST['post_id'] )   ? absint( $_POST['post_id'] )   : 0;
		$bridge_id = isset( $_POST['bridge_id'] ) ? absint( $_POST['bridge_id'] ) : 0;

		if ( ! $post_id || ! $bridge_id ) {
			$this->redirect_back( $post_id, 'meta_box_unlink_invalid' );
		}

		$bridge = JEDB_Flatten_Config_Manager::instance()->get_by_id( $bridge_id );
		if ( ! $bridge ) {
			$this->redirect_back( $post_id, 'meta_box_unlink_invalid' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->redirect_back( $post_id, 'meta_box_unlink_invalid' );
		}

		$resolution = $this->resolve_for_post( $bridge, $post );
		if ( empty( $resolution['source_id'] ) ) {
			$this->redirect_back( $post_id, 'meta_box_unlink_already' );
		}

		$config   = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$link_via = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
		$link_typ = isset( $link_via['type'] ) ? (string) $link_via['type'] : 'je_relation';

		if ( 'je_relation' === $link_typ ) {
			$relation_id = isset( $link_via['relation_id'] ) ? (string) $link_via['relation_id'] : '';
			if ( '' !== $relation_id ) {
				$attacher = JEDB_Relation_Attacher::instance();
				// Try both side conventions — detach is idempotent.
				$attacher->detach( $relation_id, (int) $resolution['source_id'], (int) $post_id );
				$attacher->detach( $relation_id, (int) $post_id, (int) $resolution['source_id'] );
			}
		}
		// For cct_single_post_id we intentionally do NOT clear the column —
		// that's a JE-managed value tied to the CCT row's "Has Single Page"
		// setting, not a per-product link the editor created.

		$this->redirect_back( $post_id, 'meta_box_unlink_done' );
	}

	public function handle_link() {

		$this->guard_action( self::NONCE_SAVE );

		$post_id   = isset( $_POST['post_id'] )   ? absint( $_POST['post_id'] )   : 0;
		$bridge_id = isset( $_POST['bridge_id'] ) ? absint( $_POST['bridge_id'] ) : 0;
		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;

		if ( ! $post_id || ! $bridge_id || ! $source_id ) {
			$this->redirect_back( $post_id, 'meta_box_link_invalid' );
		}

		$bridge = JEDB_Flatten_Config_Manager::instance()->get_by_id( $bridge_id );
		if ( ! $bridge ) {
			$this->redirect_back( $post_id, 'meta_box_link_invalid' );
		}

		$config   = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$link_via = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
		$link_typ = isset( $link_via['type'] ) ? (string) $link_via['type'] : 'je_relation';

		if ( 'je_relation' === $link_typ ) {
			$relation_id = isset( $link_via['relation_id'] ) ? (string) $link_via['relation_id'] : '';
			if ( '' === $relation_id ) {
				$this->redirect_back( $post_id, 'meta_box_link_no_relation' );
			}
			JEDB_Relation_Attacher::instance()->attach(
				$relation_id,
				$source_id, // parent (CCT row id)
				$post_id    // child (post id)
			);
		} elseif ( 'cct_single_post_id' === $link_typ ) {
			// Has-Single-Page link: write the column on the source CCT row.
			$source_adapter = JEDB_Target_Registry::instance()->get( $bridge['source_target'] );
			if ( $source_adapter ) {
				$source_adapter->update( $source_id, array( 'cct_single_post_id' => $post_id ) );
			}
		}

		$this->redirect_back( $post_id, 'meta_box_link_done' );
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	private function guard_action( $nonce_action ) {

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'je-data-bridge-cc' ) );
		}
		check_admin_referer( $nonce_action, self::NONCE_SAVE_FIELD );
	}

	private function redirect_back( $post_id, $notice = '', array $extra = array() ) {

		$args = array( 'action' => 'edit', 'post' => (int) $post_id );
		if ( '' !== $notice ) {
			$args['jedb_meta_box_notice'] = $notice;
		}
		foreach ( $extra as $k => $v ) {
			$args[ $k ] = $v;
		}

		$url = add_query_arg( $args, admin_url( 'post.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function bridge_display_label( array $bridge ) {

		if ( ! empty( $bridge['label'] ) ) {
			return (string) $bridge['label'];
		}
		if ( ! empty( $bridge['config_slug'] ) ) {
			return (string) $bridge['config_slug'];
		}
		return sprintf( 'Bridge #%d', (int) ( $bridge['id'] ?? 0 ) );
	}

	private function source_record_label( $source_adapter, $source_id, array $source_data ) {

		// Adapters report a `label_field` in their metadata when possible
		// (e.g. CCT might report `mosaic_name`). Fall back to `_ID`.
		if ( $source_adapter && method_exists( $source_adapter, 'get_display_label' ) ) {
			$lbl = $source_adapter->get_display_label( $source_id, $source_data );
			if ( '' !== (string) $lbl ) {
				return (string) $lbl;
			}
		}

		// Generic fallbacks — try common label fields.
		foreach ( array( 'name', 'title', 'mosaic_name', 'product_name', 'label' ) as $k ) {
			if ( isset( $source_data[ $k ] ) && '' !== (string) $source_data[ $k ] ) {
				return (string) $source_data[ $k ];
			}
		}

		return sprintf( '#%d', (int) $source_id );
	}
}
