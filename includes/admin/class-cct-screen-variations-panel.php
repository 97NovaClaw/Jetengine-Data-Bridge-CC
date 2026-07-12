<?php
/**
 * CCT-screen Variations Panel — Phase 4b alpha.14 (§4.7 / L-032).
 *
 * Injects a "WooCommerce Variations" panel beneath the JE save button on
 * JE CCT edit pages whose CCT slug matches at least one enabled bridge
 * with `cct_screen.wc_variations.enabled = true`. Clicking the panel's
 * "Open variations editor →" button opens the linked WC product's edit
 * page in a chrome-stripped modal iframe — editors manage variations
 * via WC's full native UI rather than through a declarative schema.
 *
 * This class is the symmetric mirror of `JEDB_Woo_Product_Meta_Box`'s
 * modal-iframe pattern (L-027 / L-029) — same overlay UI, same
 * postMessage protocol, same sessionStorage close-on-save flag.
 * Replaces the alpha.13 declarative variations[] reconciler that was
 * retired per L-032.
 *
 * Phase A (alpha.14) — this release — ships the panel + iframe modal
 * WITHOUT the chrome strip. The iframe loads the full WC product edit
 * page; the Done/Cancel top bar overlays. Phase B (alpha.15) will
 * chrome-strip the iframe to Product Data + Submit meta boxes only.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_CCT_Screen_Variations_Panel {

	const PAGE_PREFIX = 'jet-cct-';

	/** @var JEDB_CCT_Screen_Variations_Panel|null */
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
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 25 );

		// Phase B (alpha.15): chrome-strip injection on WC product edit
		// pages. Symmetric mirror of JEDB_Woo_Product_Meta_Box::
		// maybe_inject_cct_chrome_strip() — same two-tier structure,
		// same fallback safety, just targeting `post.php?post_type=
		// product` instead of `?page=jet-cct-*`. Hook on admin_head
		// runs early enough to set visibility:hidden BEFORE any flash
		// of WP chrome paints.
		add_action( 'admin_head', array( $this, 'maybe_inject_wc_chrome_strip' ) );
	}

	/* -----------------------------------------------------------------------
	 * Asset enqueue + bootstrap localization
	 * -------------------------------------------------------------------- */

	public function maybe_enqueue() {

		if ( ! $this->is_cct_edit_page() ) {
			return;
		}

		$cct_slug = $this->get_current_cct_slug();
		$item_id  = $this->get_current_cct_item_id();
		if ( ! $cct_slug || ! $item_id ) {
			// No item context — fresh "add new" form. The panel only
			// makes sense for existing CCT rows that have been linked.
			// Leave the relations picker alone.
			return;
		}

		$panels = $this->build_panels_for( $cct_slug, $item_id );
		if ( empty( $panels ) ) {
			return;
		}

		wp_enqueue_style(
			'jedb-cct-screen-variations',
			JEDB_PLUGIN_URL . 'assets/css/cct-screen-variations-panel.css',
			array(),
			JEDB_VERSION
		);

		wp_enqueue_script(
			'jedb-cct-screen-variations',
			JEDB_PLUGIN_URL . 'assets/js/cct-screen-variations-panel.js',
			array( 'jquery' ),
			JEDB_VERSION,
			true
		);

		wp_localize_script(
			'jedb-cct-screen-variations',
			'jedbCctScreenVariationsConfig',
			array(
				'cct_slug' => $cct_slug,
				'item_id'  => (int) $item_id,
				'panels'   => $panels,
				'i18n'     => array(
					'fallback_title'     => __( 'WooCommerce Variations', 'je-data-bridge-cc' ),
					'helper_text'        => __( 'After initial save you can add variations to this post.', 'je-data-bridge-cc' ),
					'open_button'        => __( 'Open variations editor →', 'je-data-bridge-cc' ),
					'modal_done'         => __( 'Done · Save & return to CCT', 'je-data-bridge-cc' ),
					'modal_cancel'       => __( 'Cancel · Discard changes', 'je-data-bridge-cc' ),
					'modal_close'        => __( 'Close', 'je-data-bridge-cc' ),
					'modal_saving'       => __( 'Saving variations…', 'je-data-bridge-cc' ),
					'missing_link'       => __( 'No linked product found. Re-check the bridge\'s relation configuration.', 'je-data-bridge-cc' ),
					// D-32 coexistence notice (Phase 4c): shown when the
					// bridge also syncs repeater rows to variations.
					'managed_notice'     => __( 'Heads up: variations created from this item\'s variation fields are overwritten on every save here (stock changes sync back automatically). Use the editor below for variation images, shipping class, or extra manual variations.', 'je-data-bridge-cc' ),
				),
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Detection / config building
	 *
	 * Mirrors the URL detection pattern from JEDB_Relation_Runtime_Loader
	 * so the two CCT-edit-screen subsystems share consistent semantics.
	 * -------------------------------------------------------------------- */

	private function is_cct_edit_page() {

		global $pagenow;

		if ( ! is_admin() ) {
			return false;
		}
		if ( 'admin.php' !== $pagenow ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		return ( '' !== $page && 0 === strpos( $page, self::PAGE_PREFIX ) );
	}

	private function get_current_cct_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page || 0 !== strpos( $page, self::PAGE_PREFIX ) ) {
			return null;
		}
		$slug = substr( $page, strlen( self::PAGE_PREFIX ) );
		return $slug ? sanitize_key( $slug ) : null;
	}

	private function get_current_cct_item_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['item_id'] ) ) {
			$id = absint( $_GET['item_id'] );
			return $id > 0 ? $id : null;
		}
		return null;
	}

	/**
	 * Build the per-panel config payload for the JS bootstrap.
	 *
	 * One entry per enabled bridge that:
	 *   - Has `cct_screen.wc_variations.enabled = true`
	 *   - Has `source_target = cct::{$cct_slug}` matching the current page
	 *   - Has `target_target = posts::product` (D6 — feature is Woo-product-only)
	 *
	 * Each entry resolves the linked WC product post ID via the same
	 * `JEDB_Flattener::resolve_target_id()` the engine uses elsewhere.
	 * If no link exists, the entry still ships (the JS shows a "save
	 * first" disabled-button state).
	 *
	 * @param string $cct_slug  Current CCT slug from URL.
	 * @param int    $item_id   Current CCT row _ID from URL.
	 * @return array<int,array{
	 *   bridge_id:                int,
	 *   title:                    string,
	 *   auto_force_variable_type: bool,
	 *   target_post_id:           int,
	 *   edit_url:                 string,
	 *   return_url:               string
	 * }>
	 */
	private function build_panels_for( $cct_slug, $item_id ) {

		if ( ! class_exists( 'JEDB_Flatten_Config_Manager' ) ) {
			return array();
		}

		$source_target = 'cct::' . $cct_slug;
		$bridges       = JEDB_Flatten_Config_Manager::instance()->get_all( array(
			'enabled'       => 1,
			'source_target' => $source_target,
		) );

		if ( empty( $bridges ) ) {
			return array();
		}

		$source_adapter = class_exists( 'JEDB_Target_Registry' )
			? JEDB_Target_Registry::instance()->get( $source_target )
			: null;

		// Read source data fresh so the linked-product resolution sees
		// the most recently persisted state (L-030 freshness pattern).
		$source_data = array();
		if ( $source_adapter ) {
			$got = method_exists( $source_adapter, 'get_fresh' )
				? $source_adapter->get_fresh( $item_id )
				: $source_adapter->get( $item_id );
			if ( is_array( $got ) ) {
				$source_data = $got;
			}
		}

		$return_url = $this->build_cct_return_url( $cct_slug, $item_id );

		$out = array();
		foreach ( $bridges as $bridge ) {

			$config = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();

			$cct_screen = isset( $config['cct_screen'] ) && is_array( $config['cct_screen'] ) ? $config['cct_screen'] : array();
			$wc_var     = isset( $cct_screen['wc_variations'] ) && is_array( $cct_screen['wc_variations'] ) ? $cct_screen['wc_variations'] : array();

			if ( empty( $wc_var['enabled'] ) ) {
				continue;
			}

			// D6: feature is Woo-product-target-only. The Flatten admin
			// tab UI hides the section for non-product targets, but
			// guard here too in case someone enables it via raw JSON.
			$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
			if ( 'posts::product' !== $target_target ) {
				continue;
			}

			$target_post_id = 0;
			if ( class_exists( 'JEDB_Flattener' ) && ! empty( $source_data ) ) {
				$resolution = JEDB_Flattener::instance()->resolve_target_id(
					$config,
					$source_target,
					(int) $item_id,
					$source_data
				);
				$target_post_id = isset( $resolution[0] ) ? (int) $resolution[0] : 0;
			}

			$edit_url = '';
			if ( $target_post_id > 0 ) {
				// alpha.16: per-bridge admin choice between two chrome
				// modes.
				//   - `stripped` (default): hide WP chrome + strip down
				//      to ONLY #woocommerce-product-data + #submitdiv.
				//      Focused variations work.
				//   - `light`: hide WP chrome only (admin bar, sidebar,
				//      footer, notices, page title). Render the FULL
				//      WC product editor inside the modal. Admin
				//      discretion per bridge.
				// Both modes still get the Done/Cancel top bar +
				// form-submit interceptor + close-on-save handler.
				$chrome_mode = ! empty( $wc_var['show_full_page'] ) ? 'light' : 'stripped';
				$args = array(
					'post'        => $target_post_id,
					'action'      => 'edit',
					'jedb_chrome' => $chrome_mode,
					'jedb_return' => rawurlencode( $return_url ),
				);
				// D3 (alpha.15): the chrome-strip script reads this
				// param and auto-flips #product-type to "variable" on
				// DOMContentLoaded so editors don't need to manually
				// change the product type dropdown. Admin opt-in per
				// bridge — off by default.
				if ( ! empty( $wc_var['auto_force_variable_type'] ) ) {
					$args['jedb_force_variable'] = 1;
				}
				$edit_url = add_query_arg( $args, admin_url( 'post.php' ) );
			}

			$title = isset( $wc_var['title'] ) ? trim( (string) $wc_var['title'] ) : '';
			if ( '' === $title ) {
				$title = isset( $bridge['label'] ) && '' !== (string) $bridge['label']
					? (string) $bridge['label']
					: __( 'WooCommerce Variations', 'je-data-bridge-cc' );
			}

			$out[] = array(
				'bridge_id'                => (int) ( $bridge['id'] ?? 0 ),
				'title'                    => $title,
				// D-32 (Phase 4c): when the bridge also runs repeater →
				// variation sync, the panel shows a coexistence notice —
				// managed variations edited in the iframe get overwritten
				// on the next CCT save.
				'has_managed_mappings'     => ! empty( $config['variation_mappings'] ),
				'auto_force_variable_type' => ! empty( $wc_var['auto_force_variable_type'] ),
				'target_post_id'           => $target_post_id,
				'edit_url'                 => $edit_url,
				'return_url'               => $return_url,
			);
		}

		return $out;
	}

	private function build_cct_return_url( $cct_slug, $item_id ) {
		return add_query_arg(
			array(
				'page'       => self::PAGE_PREFIX . $cct_slug,
				'cct_action' => 'edit',
				'item_id'    => (int) $item_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/* -----------------------------------------------------------------------
	 * Chrome-strip for the WC product edit page (Phase B / alpha.15+)
	 * --------------------------------------------------------------------
	 *
	 * Symmetric mirror of JEDB_Woo_Product_Meta_Box::
	 * maybe_inject_cct_chrome_strip() (alpha.6 / L-027). Same two-tier
	 * structure, same fallback safety, same sessionStorage-bridged
	 * close-on-save flow that L-029 worked out for JE's redirect quirks.
	 *
	 * Tier 1 (always on `post.php?post_type=product`): iframe-aware
	 *   close-on-save handler. Reads sessionStorage `jedb_close_wc_
	 *   modal_on_load` flag (distinct from the CCT-side key to prevent
	 *   cross-contamination). Hides the page immediately on flag-hit
	 *   to avoid a flash of WP chrome, then on DOMContentLoaded either
	 *   postMessages the parent to close (clean save) or postMessages
	 *   an error + un-hides (validation failure).
	 *
	 * Tier 2 (when `?jedb_chrome=stripped` or `?jedb_chrome=light`):
	 *   chrome-hide CSS + Done/Cancel top bar + form submit interceptor.
	 *
	 *   Two modes (alpha.16):
	 *     - `stripped` (default): hide everything inside `.wrap`
	 *       except `#poststuff`, then hide every `.postbox` except
	 *       `#woocommerce-product-data` and `#submitdiv`. Focused
	 *       variations work — only Product Data + Submit visible.
	 *     - `light`: hide WP chrome only (admin bar, sidebar, footer,
	 *       page title, admin notices). The full product editor
	 *       renders inside the modal — title editor, all meta boxes,
	 *       sidebars. Admin discretion per bridge.
	 *
	 *   In both modes: kills the floating notice / Activity / Dismiss
	 *   chrome that plugins inject into `.wrap` (Deployer for Git, Jet
	 *   Woo Builder Data Update, etc.) — these were leaking through in
	 *   alpha.15 and clipping the Publish meta box.
	 *
	 * D3 implementation (alpha.15): when the bridge has
	 *   `cct_screen.wc_variations.auto_force_variable_type=true`, the
	 *   iframe URL includes `&jedb_force_variable=1` and the chrome-
	 *   strip script auto-triggers
	 *   `jQuery('#product-type').val('variable').trigger('change')`
	 *   on DOMContentLoaded.
	 *
	 * D4 (explicit non-action): we do NOT auto-jump to the Variations
	 *   sub-tab inside #woocommerce-product-data. Editors may need to
	 *   configure attributes first (General → Attributes tab), so we
	 *   land on whatever WC's default tab is.
	 */
	public function maybe_inject_wc_chrome_strip() {

		global $pagenow;

		if ( ! is_admin() ) {
			return;
		}

		// Fast gate: only fire on post.php pages where the post type
		// is product. The action vs query-string approach mirrors WP
		// core's own page detection.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'post.php' !== $pagenow ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$chrome     = isset( $_GET['jedb_chrome'] )         ? sanitize_key( wp_unslash( $_GET['jedb_chrome'] ) )                            : '';
		$return_raw = isset( $_GET['jedb_return'] )         ? esc_url_raw( wp_unslash( $_GET['jedb_return'] ) )                             : '';
		$force_var  = isset( $_GET['jedb_force_variable'] ) ? '1' === (string) $_GET['jedb_force_variable']                                 : false;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			return;
		}

		// Valid chrome modes per alpha.16. Anything else → no Tier 2.
		$valid_chrome_modes = array( 'stripped', 'light' );
		$is_chrome_mode     = in_array( $chrome, $valid_chrome_modes, true );
		$is_strict_mode     = ( 'stripped' === $chrome );

		/* ------------------------------------------------------------
		 * TIER 1 — Always injected on product edit pages.
		 *
		 * Iframe-aware close-on-save handler. Mirrors the CCT-side
		 * Tier 1 verbatim (only the flag key + message names differ).
		 * Listens for sessionStorage `jedb_close_wc_modal_on_load` and
		 * postMessages the parent to close the modal on the next page
		 * load if set. The flag is set by Tier 2's form-submit
		 * interceptor — the post-save redirect strips our
		 * `jedb_chrome=stripped` query param (just like JE's does for
		 * the CCT side per L-029), so Tier 2 doesn't run on the post-
		 * save page, but we still need to close the modal.
		 *
		 * Validation-error guard: WC's product save can fail with
		 * `.notice-error` (e.g., invalid SKU). On error, un-hide the
		 * page so the editor can fix the issue, and postMessage the
		 * parent to hide its "Saving…" overlay. The modal stays open.
		 * ----------------------------------------------------------- */
		?>
		<script id="jedb-wc-iframe-close-handler">
			(function () {
				if ( window.top === window.self ) {
					return;
				}

				var FLAG_KEY = 'jedb_close_wc_modal_on_load';

				var shouldClose = false;
				try {
					shouldClose = sessionStorage.getItem( FLAG_KEY ) === '1';
				} catch ( e ) {}

				if ( ! shouldClose ) {
					return;
				}

				document.documentElement.style.visibility = 'hidden';

				document.addEventListener( 'DOMContentLoaded', function () {
					try { sessionStorage.removeItem( FLAG_KEY ); } catch ( e ) {}

					var hasError = !! document.querySelector(
						'.notice-error, .notice.notice-error, #message.error'
					);

					if ( hasError ) {
						document.documentElement.style.visibility = '';

						try {
							window.parent.postMessage(
								{ type: 'jedb:wc-save-error' },
								window.location.origin
							);
						} catch ( e ) {}
						return;
					}

					try {
						window.parent.postMessage(
							{ type: 'jedb:wc-modal-close', reload: true },
							window.location.origin
						);
					} catch ( err ) {
						document.documentElement.style.visibility = '';
					}
				} );
			})();
		</script>
		<?php

		/* ------------------------------------------------------------
		 * TIER 2 — Only when explicitly opened from the modal launcher.
		 *
		 * Two modes (alpha.16):
		 *   - `stripped` (default): focused variations work — only
		 *     #woocommerce-product-data + #submitdiv visible.
		 *   - `light`: full product editor with WP chrome hidden.
		 *
		 * In both modes: kill the floating notice / Activity / Dismiss
		 * chrome that plugins inject into `.wrap` (Deployer for Git,
		 * Jet Woo Builder Data Update, etc.). These were clipping the
		 * Publish meta box in alpha.15.
		 * ----------------------------------------------------------- */
		if ( ! $is_chrome_mode ) {
			return;
		}
		?>
		<style id="jedb-wc-chrome-strip">
			/* =====================================================
			 * Base layer (both `stripped` and `light` modes)
			 * ===================================================== */

			/* ---- Hide WP admin chrome ---- */
			html.wp-toolbar { padding-top: 0 !important; }
			#wpadminbar, #adminmenuwrap, #adminmenuback, #adminmenu, #wpfooter, #screen-meta, #screen-meta-links { display: none !important; }
			/* Also reset margin-right — WC's new admin layout JS reserves
			 * a right gutter for the activity panel even when we hide
			 * the panel itself (see WC layout selectors below). Without
			 * this reset, the Publish meta box is clipped on the right
			 * edge by an empty grey gutter. */
			#wpcontent, #wpbody-content { margin-left: 0 !important; margin-right: 0 !important; padding-top: 0 !important; }
			#wpbody { padding-top: 0 !important; }
			body.wp-admin { background: #f6f7f7; }

			/* ---- Kill WC admin layout's React-injected chrome ----
			 *
			 * WC's new "wc-admin" React layer injects elements outside
			 * the legacy `.wrap` structure even on legacy product edit
			 * pages — notably the right-edge activity panel that
			 * appears as a fixed grey gutter clipping the Publish
			 * meta box (staging report alpha.16). Hide them. */
			.woocommerce-layout__activity-panel-wrapper,
			.woocommerce-layout__activity-panel,
			.woocommerce-layout__header,
			.woocommerce-layout__notice-list,
			[class*="woocommerce-layout__activity"],
			[class*="woocommerce-layout__header"] { display: none !important; }

			/* ---- Kill Freemius SDK plugin marketing notices ----
			 *
			 * Freemius-powered plugins (Deployer for Git, etc.) inject
			 * promotional notices via `.fs-notice` that use the
			 * `.updated` admin-notice class but fight our generic
			 * `display: none !important` rule via inline styles or
			 * late injection. Hit them directly. */
			.fs-notice,
			.fs-sticky,
			[class^="fs-notice"],
			[class*=" fs-notice"] { display: none !important; }

			/* ---- Hide page chrome (title bar, "Add New" button) ---- */
			.wrap h1,
			.wrap > h1.wp-heading-inline,
			.wrap > .page-title-action,
			.wrap > .wp-header-end,
			.wrap > hr.wp-header-end { display: none !important; }

			/* ---- Kill admin notices ----
			 *
			 * Plugins (Deployer for Git, Jet Woo Builder, Yoast SEO,
			 * WC's own update prompts, etc.) inject notices into
			 * `.wrap` between the title and `#poststuff`. These leak
			 * into the focused modal and frequently overlap the
			 * Publish meta box via floating .notice-dismiss buttons.
			 * Kill them all inside the iframe — editors can dismiss
			 * them on a direct admin visit.
			 */
			.notice,
			.notice-info,
			.notice-success,
			.notice-warning,
			.notice-error,
			.updated,
			.update-nag,
			.error,
			div.notice,
			div[class*="-notice"],
			.wrap > .notice,
			.wrap > .updated,
			.wrap > .error,
			.wrap > .update-nag,
			#wpbody-content > .notice,
			#wpbody-content > .updated,
			#wpbody-content > .error,
			.notice-dismiss { display: none !important; }

			/* ---- Reserve room for the floating Done bar at the top ---- */
			#wpbody-content > .wrap { padding-top: 56px !important; }

			/* ---- Top bar styling (mirrors .jedb-cct-frame-bar visual design) ---- */
			.jedb-wc-frame-bar {
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
			.jedb-wc-frame-bar .jedb-wc-frame-title { font-weight: 600; }
			.jedb-wc-frame-bar .jedb-wc-frame-actions { display: flex; gap: 8px; }
			.jedb-wc-frame-bar button {
				background: #2271b1;
				color: #fff;
				border: 1px solid transparent;
				padding: 6px 14px;
				border-radius: 3px;
				cursor: pointer;
				font-size: 13px;
				font-weight: 500;
			}
			.jedb-wc-frame-bar button.jedb-wc-frame-cancel {
				background: transparent;
				border-color: rgba(255,255,255,0.3);
			}
			.jedb-wc-frame-bar button:hover { opacity: 0.9; }

			<?php if ( $is_strict_mode ) : ?>
			/* =====================================================
			 * Strict layer — `stripped` mode only.
			 *
			 * Hide title editor + permalink + visual editor + every
			 * postbox EXCEPT #woocommerce-product-data + #submitdiv.
			 * Forces survivors visible even if WP's user_meta postbox
			 * state had them collapsed.
			 * ===================================================== */
			#post-body-content { display: none !important; }
			.postbox:not(#woocommerce-product-data):not(#submitdiv) { display: none !important; }
			#woocommerce-product-data,
			#submitdiv { display: block !important; }
			#woocommerce-product-data.closed > .inside,
			#submitdiv.closed > .inside { display: block !important; }
			#woocommerce-product-data > .inside,
			#submitdiv > .inside { display: block !important; }
			<?php endif; ?>
		</style>
		<script id="jedb-wc-chrome-strip-js">
			(function () {
				if ( window.top === window.self ) {
					return;
				}

				var FLAG_KEY      = 'jedb_close_wc_modal_on_load';
				var FORCE_VARIABLE = <?php echo $force_var ? 'true' : 'false'; ?>;

				function setCloseFlag() {
					try { sessionStorage.setItem( FLAG_KEY, '1' ); } catch ( e ) {}
				}

				function notifyParent( msg ) {
					try {
						window.parent.postMessage( msg, window.location.origin );
					} catch ( e ) {}
				}

				function startSave() {
					setCloseFlag();
					notifyParent( { type: 'jedb:wc-save-starting' } );
				}

				function postClose( reload ) {
					try {
						window.parent.postMessage(
							{ type: 'jedb:wc-modal-close', reload: !! reload },
							window.location.origin
						);
					} catch ( e ) {
						<?php if ( '' !== $return_raw ) : ?>
						window.parent.location = <?php echo wp_json_encode( $return_raw ); ?>;
						<?php endif; ?>
					}
				}

				document.addEventListener( 'DOMContentLoaded', function () {

					var wcForm = document.querySelector( 'form#post' );

					/* ---- WC form submit interceptor ----
					 *
					 * Belt-and-suspenders coverage for Issue 3 (alpha.15
					 * staging report): the close flag wasn't always
					 * being set on save, so Tier 1 couldn't auto-close
					 * the modal after WC's redirect.
					 *
					 * Three triggers, in priority order:
					 *   1. `submit` event on `form#post` — fires for
					 *      ANY form submission path, including direct
					 *      user clicks on the Publish button.
					 *   2. `click` event on each submit button
					 *      (`#publish`, `#save-post`, `input[name="save"]`).
					 *      Fires BEFORE the form submit so we definitely
					 *      set the flag even if WC's own submit handler
					 *      stops propagation on the submit event.
					 *   3. `beforeunload` on the window — last-ditch
					 *      fallback to catch programmatic form.submit()
					 *      calls that don't fire submit events.
					 *
					 * `beforeunload` is guarded by an `armed` flag — we
					 * only set the close flag if the user has actually
					 * intent-signalled a save (clicked Done or a save
					 * button). Otherwise navigation from the iframe to
					 * a non-product page (e.g., clicking a link in
					 * "Linked Products") would incorrectly close the
					 * modal.
					 */
					var saveArmed = false;

					if ( wcForm ) {
						wcForm.addEventListener( 'submit', function () {
							saveArmed = true;
							startSave();
						}, true ); // capture phase — fires even if WC's handlers stopPropagation
					}

					var submitButtons = document.querySelectorAll(
						'form#post #publish, form#post #save-post, form#post input[name="save"], form#post input[type="submit"][name="save"]'
					);
					Array.prototype.forEach.call( submitButtons, function ( btn ) {
						btn.addEventListener( 'click', function () {
							saveArmed = true;
							startSave();
						} );
					} );

					window.addEventListener( 'beforeunload', function () {
						if ( saveArmed ) {
							setCloseFlag();
						}
					} );

					/* ---- D3: auto-flip product type to variable ---- */
					if ( FORCE_VARIABLE ) {
						try {
							if ( typeof jQuery !== 'undefined' ) {
								var $productType = jQuery( '#product-type' );
								if ( $productType.length && 'variable' !== $productType.val() ) {
									$productType.val( 'variable' ).trigger( 'change' );
								}
							}
						} catch ( e ) {}
					}

					/* ---- Top bar with Done + Cancel buttons ---- */
					var bar = document.createElement( 'div' );
					bar.className = 'jedb-wc-frame-bar';

					var title = document.createElement( 'span' );
					title.className = 'jedb-wc-frame-title';
					title.textContent = <?php echo wp_json_encode( __( 'Managing variations on the linked product — Done saves & closes; Cancel discards changes.', 'je-data-bridge-cc' ) ); ?>;

					var actions = document.createElement( 'span' );
					actions.className = 'jedb-wc-frame-actions';

					var doneBtn = document.createElement( 'button' );
					doneBtn.type = 'button';
					doneBtn.textContent = <?php echo wp_json_encode( __( 'Done · Save & return to CCT', 'je-data-bridge-cc' ) ); ?>;
					doneBtn.addEventListener( 'click', function () {
						if ( ! wcForm ) {
							postClose( false );
							return;
						}

						saveArmed = true;
						startSave();

						// Prefer clicking WC's Update button so all its
						// submit handlers fire. WC's Publish meta box
						// uses #publish (or #save-post for drafts);
						// we'll grab whichever is present. Last resort:
						// requestSubmit() (modern) or form.submit().
						var submitBtn = wcForm.querySelector( '#publish, #save-post, input[type="submit"][name="save"]' );
						if ( submitBtn ) {
							submitBtn.click();
							return;
						}

						if ( typeof wcForm.requestSubmit === 'function' ) {
							wcForm.requestSubmit();
						} else {
							wcForm.submit();
						}
					} );

					var cancelBtn = document.createElement( 'button' );
					cancelBtn.type = 'button';
					cancelBtn.className = 'jedb-wc-frame-cancel';
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
}
