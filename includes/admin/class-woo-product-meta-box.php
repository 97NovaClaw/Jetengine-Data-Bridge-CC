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

		add_action( 'add_meta_boxes',                          array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_product',                       array( $this, 'handle_save' ), 20, 1 );
		add_action( 'save_post_product_variation',             array( $this, 'handle_save' ), 20, 1 );

		add_action( 'admin_post_' . self::ACTION_SYNC_NOW,     array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_' . self::ACTION_UNLINK,       array( $this, 'handle_unlink' ) );
		add_action( 'admin_post_' . self::ACTION_LINK,         array( $this, 'handle_link' ) );

		add_action( 'admin_enqueue_scripts',                   array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_notices',                           array( $this, 'maybe_render_notice' ) );
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

		foreach ( $post_types as $pt ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'JE Data Bridge', 'je-data-bridge-cc' ),
				array( $this, 'render_meta_box' ),
				$pt,
				'normal',
				'default'
			);
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
	public function render_meta_box( $post ) {

		wp_nonce_field( self::NONCE_SAVE, self::NONCE_SAVE_FIELD );

		$post_type      = $post->post_type;
		$target_slug    = 'posts::' . $post_type;
		$bridges        = $this->find_bridges_for_target( $target_slug );

		if ( empty( $bridges ) ) {
			echo '<p class="description">';
			esc_html_e( 'No JEDB bridge configs target this post type. Configure one in JE Data Bridge → Flatten.', 'je-data-bridge-cc' );
			echo '</p>';
			return;
		}

		// For each candidate bridge, resolve whether THIS specific post
		// is linked through it. Each bridge gets its own panel — linked
		// or unlinked — so a product matched by multiple bridges (rare
		// but legal) shows them all.
		$resolutions = array();
		foreach ( $bridges as $bridge ) {
			$resolutions[] = array(
				'bridge'     => $bridge,
				'resolution' => $this->resolve_for_post( $bridge, $post ),
			);
		}

		$lock_value     = (bool) get_post_meta( $post->ID, self::META_LOCKED, true );
		$override_value = (string) get_post_meta( $post->ID, self::META_DIRECTION_OVR, true );

		echo '<div class="jedb-meta-box-wrap" data-post-id="' . esc_attr( (int) $post->ID ) . '">';

		foreach ( $resolutions as $entry ) {

			$bridge     = $entry['bridge'];
			$resolution = $entry['resolution'];
			$config     = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
			$meta_box   = isset( $config['meta_box'] ) && is_array( $config['meta_box'] ) ? $config['meta_box'] : array();

			// If the bridge config explicitly disables meta box rendering
			// (meta_box.enabled = false), skip its panel.
			if ( isset( $meta_box['enabled'] ) && ! $meta_box['enabled'] ) {
				continue;
			}

			if ( ! empty( $resolution['source_id'] ) ) {
				$this->render_linked_panel( $post, $bridge, $resolution, $lock_value, $override_value );
			} else {
				$this->render_unlinked_panel( $post, $bridge, $resolution );
			}
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

		// Build the surfaced-field display rows: only mappings where
		// surface_on_target=true AND target adapter doesn't natively
		// render the field (D-16).
		$target_adapter   = $resolution['target_adapter'];
		$source_adapter   = $resolution['source_adapter'];
		$source_id        = (int) $resolution['source_id'];
		$source_data      = $resolution['source_data'];
		$source_label     = $this->source_record_label( $source_adapter, $source_id, $source_data );
		$surfaced_groups  = $this->build_surfaced_groups( $mappings, $target_adapter, $source_adapter, $source_data, $meta_box_cfg );
		$recent_log       = $this->recent_log_for_post( $bridge, $post->ID );
		$last_manual_id   = (int) get_post_meta( $post->ID, self::META_LAST_MANUAL, true );

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
			$got = $source_adapter->get( $source_id );
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
	 * template iterates over. Returns an ordered array of group blocks,
	 * each with a label and an array of mapping-rows where:
	 *   - surface_on_target === true on the mapping
	 *   - target_adapter->is_natively_rendered($target_field) === false
	 *
	 * Groups are ordered per the bridge config's `meta_box.groups[]`
	 * list (explicit ordering), then alphabetically for any unlisted
	 * groups, with an unnamed group ("") going last and labeled
	 * "Ungrouped".
	 *
	 * @return array<int,array{label:string,fields:array<int,array>}>
	 */
	private function build_surfaced_groups( array $mappings, $target_adapter, $source_adapter, array $source_data, array $meta_box_cfg ) {

		if ( ! $target_adapter ) {
			return array();
		}

		$groups = array();
		foreach ( $mappings as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			if ( empty( $m['enabled'] ) ) {
				continue;
			}
			if ( empty( $m['surface_on_target'] ) ) {
				continue;
			}
			$tgt_field = isset( $m['target_field'] ) ? (string) $m['target_field'] : '';
			$src_field = isset( $m['source_field'] ) ? (string) $m['source_field'] : '';
			if ( '' === $tgt_field ) {
				continue;
			}
			// Skip fields the target adapter natively renders — Woo's
			// own UI already has them (D-16).
			if ( method_exists( $target_adapter, 'is_natively_rendered' ) && $target_adapter->is_natively_rendered( $tgt_field ) ) {
				continue;
			}

			$group_key = isset( $m['group'] ) ? trim( (string) $m['group'] ) : '';
			$schema    = $this->find_schema_entry( $target_adapter, $tgt_field );

			$current_source_value = '';
			if ( '' !== $src_field && array_key_exists( $src_field, $source_data ) ) {
				$current_source_value = $source_data[ $src_field ];
			}

			$row = array(
				'source_field' => $src_field,
				'target_field' => $tgt_field,
				'label'        => isset( $schema['label'] ) && '' !== $schema['label'] ? (string) $schema['label'] : $tgt_field,
				'type'         => isset( $schema['type'] ) ? (string) $schema['type'] : 'text',
				'note'         => isset( $m['note'] ) ? (string) $m['note'] : '',
				'value'        => $current_source_value,
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
		// Sort remaining (excluding ungrouped) alphabetically by label.
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

		return $ordered;
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

		// Surfaced field inline edits: each writes back to the source
		// CCT row via the source adapter. Posted as
		// jedb_surfaced[<bridge_id>][<source_field>] = value.
		$surfaced_raw = isset( $_POST['jedb_surfaced'] ) ? wp_unslash( $_POST['jedb_surfaced'] ) : array();
		if ( ! is_array( $surfaced_raw ) ) {
			return;
		}

		foreach ( $surfaced_raw as $bridge_id => $field_map ) {
			if ( ! is_array( $field_map ) || empty( $field_map ) ) {
				continue;
			}
			$this->apply_surfaced_edits_for_bridge( $post, (int) $bridge_id, $field_map );
		}
	}

	/**
	 * Write back surfaced-field edits for one bridge → its linked CCT
	 * source row.
	 *
	 * @param WP_Post $post
	 * @param int     $bridge_id
	 * @param array   $field_map  source_field => value
	 */
	private function apply_surfaced_edits_for_bridge( $post, $bridge_id, array $field_map ) {

		$bridge = JEDB_Flatten_Config_Manager::instance()->get_by_id( $bridge_id );
		if ( ! $bridge ) {
			return;
		}

		$resolution = $this->resolve_for_post( $bridge, $post );
		if ( empty( $resolution['source_id'] ) || ! $resolution['source_adapter'] ) {
			return;
		}

		$source_id      = (int) $resolution['source_id'];
		$source_adapter = $resolution['source_adapter'];
		$source_data    = $resolution['source_data'];
		$source_target  = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';

		$payload = array();
		foreach ( $field_map as $source_field => $value ) {
			$source_field = sanitize_text_field( (string) $source_field );
			if ( '' === $source_field ) {
				continue;
			}
			// Skip if unchanged — the reverse pull engine's diff would
			// catch it too, but avoiding the write is cheaper.
			$current = array_key_exists( $source_field, $source_data ) ? $source_data[ $source_field ] : null;
			if ( (string) $current === (string) $value ) {
				continue;
			}
			$payload[ $source_field ] = is_array( $value ) ? $value : (string) wp_unslash( $value );
		}

		if ( empty( $payload ) ) {
			return;
		}

		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';

		// Acquire a 'pull' lock for the duration of this write so the
		// reverse pull engine that fires LATER in this same request
		// (on `woocommerce_update_product` at priority 20) bails out at
		// its own same-direction acquire() — otherwise it would read
		// the stale post.name (we only touched the CCT side), diff
		// against the fresh CCT.mosaic_name we just wrote, and try to
		// pull the stale post value back, clobbering our edit.
		//
		// The reverse engine's same-direction lock check is at
		// class-reverse-flattener.php ~342. It logs `skipped_locked,
		// "sync_guard already locked — same-direction cycle detected"`
		// — semantically correct: a meta box surfaced-field edit IS a
		// pull-direction effect, and the reverse engine running afterward
		// would be a redundant duplicate pull.
		//
		// KNOWN LIMITATION (Day 2): if the editor edits BOTH a surfaced
		// field AND a Woo-native field (e.g. regular_price) in the same
		// save, the native-field change won't pull back to CCT until the
		// next product save fires the reverse engine again. Eventually
		// consistent on the second save; acceptable for Phase 4.
		$guard = JEDB_Sync_Guard::instance();
		$guard->acquire(
			'pull',
			$source_target,
			$source_id,
			$target_target,
			(int) $post->ID,
			'meta_box_inline_save'
		);

		// NOTE: no release() here. The static lock dissolves at end of
		// request; the transient has a short TTL. Releasing mid-request
		// would re-open the cascade hole.
		$source_adapter->update( $source_id, $payload );

		// Mirror Phase 3.5 sync log shape so the meta box's "Last syncs"
		// reader picks this up. Origin tag distinguishes from automatic
		// reverse pulls. Direction is `pull` because semantically the
		// data flowed from the meta box (post-side edit surface) back
		// into the source CCT — same direction the reverse engine writes.
		if ( class_exists( 'JEDB_Sync_Log' ) ) {
			JEDB_Sync_Log::instance()->record( array(
				'direction'     => 'pull',
				'source_target' => $source_target,
				'source_id'     => (string) $source_id,
				'target_target' => $target_target,
				'target_id'     => (string) $post->ID,
				'origin'        => 'meta_box_inline_save',
				'status'        => JEDB_Sync_Log::STATUS_SUCCESS,
				'message'       => sprintf( 'wrote %d surfaced field(s) from meta box', count( $payload ) ),
				'context'       => array(
					'bridge_id'    => $bridge_id,
					'bridge_slug'  => isset( $bridge['config_slug'] ) ? $bridge['config_slug'] : '',
					'fields'       => array_keys( $payload ),
					'cascade_hold' => 'pull_in_flight',
				),
			) );
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
