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
				$edit_url = add_query_arg(
					array(
						'post'        => $target_post_id,
						'action'      => 'edit',
						'jedb_chrome' => 'stripped',     // Tier 1 close-on-save handler shipped in L-027 already runs on this — Phase B will add the chrome strip CSS + Done bar
						'jedb_return' => rawurlencode( $return_url ),
					),
					admin_url( 'post.php' )
				);
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
}
