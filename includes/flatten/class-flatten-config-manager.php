<?php
/**
 * Flatten Config Manager — CRUD wrapper for `wp_jedb_flatten_configs`.
 *
 * Each row represents ONE bridge config (source target → target target,
 * with mappings, transformer chains, conditions, and triggers).
 *
 * The schema's column-level fields (config_slug, label, source_target,
 * target_target, relation_id, direction, enabled) are kept in sync with
 * the matching keys in `config_json` so simple WHERE filters still work
 * without needing to JSON-decode every row. The full canonical
 * representation lives in `config_json`.
 *
 * `config_slug` is a stable user-facing identifier auto-derived from
 * `source_target` + `target_target` + `direction` (with a numeric suffix
 * if needed for uniqueness when two configs share the same trio — e.g.,
 * conditional bridges per BUILD-PLAN §4.9 / D-14).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Flatten_Config_Manager {

	/** @var JEDB_Flatten_Config_Manager|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'jedb_flatten_configs';
	}

	/**
	 * Canonical default shape for a flatten config's `config_json`.
	 */
	public static function default_config_json() {
		return array(
			'mappings'                          => array(),
			// Phase 3.6 / D-20: dedicated taxonomy rules array, parallel
			// to mappings[]. Push-only per D-21 — reverse pull engine
			// skips this entirely. Each entry shape comes from
			// default_taxonomy_rule(). Empty by default.
			'taxonomies'                        => array(),
			'condition'                         => '',
			'condition_snippet'                 => '',
			'priority'                          => 100,
			'trigger'                           => array(
				'type' => 'cct_save',
				'args' => array(),
			),
			'link_via'                          => array(
				'type'                    => 'je_relation',
				'relation_id'             => '',
				'side'                    => 'auto',
				'fallback_to_single_page' => true,
				'auto_attach_relation'    => true,
			),
			// Forward-direction auto-create (D-17 default OFF).
			//
			// When ON, the forward push engine creates a fresh TARGET post
			// in the target adapter if no linked target exists.
			//
			// NAMING HISTORY: this flag historically also drove reverse-
			// direction source auto-creation in `JEDB_Reverse_Flattener::
			// resolve_source_id()`. That overload was the L-033 footgun —
			// see lesson. As of alpha.21 the reverse direction reads
			// `auto_create_source_when_unlinked` (below) instead. This
			// flag is now forward-only as the name implies.
			'auto_create_target_when_unlinked'  => false,
			// Reverse-direction auto-create (alpha.21, post-L-033).
			//
			// When ON, the reverse pull engine creates a fresh SOURCE CCT
			// row if a target post saves and no linked CCT row exists.
			//
			// Defaults to false because the cascade scenario in L-033
			// proved this is almost never what the editor wants:
			// multiple bridges sharing a target post type would each
			// auto-create a source row on every product save, spawning
			// orphan CCT rows and relation attachments.
			//
			// Editors who genuinely want product-driven CCT creation
			// (rare — typically only useful when the WC product is the
			// canonical source and the CCT is a derived snapshot) can
			// opt in per bridge.
			'auto_create_source_when_unlinked'  => false,
			// Bridge applicability gate (alpha.21, post-L-033).
			//
			// Declares the categorical scope of this bridge against the
			// TARGET post type. When the target post doesn't satisfy
			// the gate's match condition, the engine logs
			// `STATUS_SKIPPED_NOT_APPLICABLE` and bails BEFORE any
			// source resolution, auto-create, or relation attach
			// happens.
			//
			// Solves the L-033 cross-bridge cascade where multiple
			// bridges with the same `target_target` would all fire on
			// any save of a target of that type, regardless of which
			// CCT actually "owns" the target.
			//
			// Empty taxonomy OR empty terms = no gate (back-compat with
			// every saved bridge before alpha.21). When the gate is
			// empty AND the bridge has a non-empty `taxonomies[]` rule
			// with `apply_terms`, `merge_with_defaults()` auto-derives
			// the gate from the taxonomies block so existing bridges
			// get the correct scope without editing.
			//
			// `match_mode`: `any` (target has ≥1 term in `terms`),
			// `all` (target has all of `terms`), `none` (target has
			// none of `terms` — useful as an exclusion gate).
			//
			// `applies_to`: which direction(s) the gate applies in.
			// `pull` is the default because forward push is the bridge
			// that SETS the category and shouldn't gate itself out.
			// `push` enables defensive forward-direction gating.
			// `both` applies to both directions.
			'applies_when_target_in_terms'      => array(
				'taxonomy'   => '',
				'terms'      => array(),
				'match_by'   => 'slug',
				'match_mode' => 'any',
				'applies_to' => 'pull',
			),
			// Phase 4 alpha.3 (D-27 / §4.5): Bridge meta box configuration
			// on the Woo product / variation edit screen for this bridge.
			// Day 1 stores the schema; Day 2 builds the meta box that
			// reads it. Per-mapping `surface_on_*` flags select WHICH
			// fields render; this block controls HOW the meta box is
			// presented as a whole.
			'meta_box'                          => array(
				'enabled'       => true,     // when false, no meta box rendered for this bridge
				'title'         => '',       // empty = use the flatten config's `label` column
				'position'      => 'normal', // normal | side | advanced — passed to add_meta_box()
				'groups'        => array(),  // optional explicit ordering of freeform group labels: ['Identity','Pricing',...]
				// Phase 4 alpha.9 (L-031): when false, the meta box renders
				// ONLY the surfaced field previews + the "Save & edit"
				// modal launcher button — minimal native-WP look. When
				// true, an additional <details> "Advanced Details"
				// section appears at the bottom of the panel exposing
				// per-product overrides, recent sync log, and Sync now
				// / Unlink action buttons. Default false (clean look
				// out of the box; verbose chrome is opt-in per bridge).
				'show_advanced' => false,
			),
			// Phase 4 alpha.3 (D-27 / §4.6): when true and `direction`
			// includes `push`, a `template_redirect` shim 301-redirects
			// the JE CCT-single URL to the linked post permalink for any
			// row whose target is resolved through this bridge's
			// `link_via`. Default OFF — opt-in per bridge so CCTs that
			// remain frontend-visible aren't accidentally hidden.
			'cct_single_redirect'               => false,
			'required_overrides'                => array(
				'add'    => array(),
				'remove' => array(),
			),
			// Phase 4b (alpha.14, post-L-032): per-bridge CCT-edit-screen
			// panels. Currently the only panel is `wc_variations` — opens
			// the linked WC product's edit page in a chrome-stripped modal
			// iframe so editors manage variations via WC's native UI. See
			// BUILD-PLAN §4.7 for the architecture spec and L-032 for the
			// retrospective on why this replaced the alpha.13 declarative
			// `variations[]` reconciler.
			'cct_screen'                        => array(
				'wc_variations' => array(
					'enabled'                  => false,
					'title'                    => '',      // empty → fallback "WooCommerce Variations"
					'auto_force_variable_type' => false,   // D3: admin opt-in to auto-flip product type to variable on iframe load
				),
			),
			// Phase 4c-A (post-L-033 spec, §4.14): DATA-driven variation
			// sync. Each entry maps ONE source repeater field to WC
			// variations on the linked product. Distinct from the retired
			// alpha.13 `variations[]` (config-driven rules, L-032): here
			// the CCT row's repeater DATA is the variation content; this
			// block only declares the structural mapping once. Empty by
			// default. See default_variation_mapping() for entry shape.
			'variation_mappings'                => array(),
			'origin_tag'                        => 'flatten',
		);
	}

	/**
	 * Default shape for the meta_box block. Used by merge_with_defaults()
	 * for back-compat with existing 0.5.x flatten configs that were saved
	 * before the block existed.
	 *
	 * @return array
	 */
	public static function default_meta_box() {
		return array(
			'enabled'       => true,
			'title'         => '',
			'position'      => 'normal',
			'groups'        => array(),
			'show_advanced' => false,
		);
	}

	/**
	 * Default shape for one entry in `taxonomies[]` (per BUILD-PLAN
	 * §4.11 / D-22). Used as the merge target when reading existing
	 * rules from saved config_json so editors who saved bridges
	 * before fields existed get sensible defaults filled in.
	 *
	 * @return array
	 */
	public static function default_taxonomy_rule() {
		return array(
			'taxonomy'            => '',
			'apply_terms'         => array(),
			'apply_terms_inverse' => array(),
			'match_by'            => 'slug',     // D-22: most stable identifier
			'merge_strategy'      => 'append',   // D-22: editor-friendly default
			'create_if_missing'   => false,      // D-22: editor opt-in
			'snippet'             => null,       // forward-compat with Phase 5b
			'enabled'             => true,
			'note'                => '',
		);
	}

	/**
	 * Default shape for one entry in `variation_mappings[]` (Phase 4c-A,
	 * §4.14.3). Each entry maps one source-CCT repeater field to managed
	 * WC variations on the linked product.
	 *
	 *   - `source_repeater`: name of the repeater field on the source CCT
	 *     (e.g. `physical_variations`). The column stores a PHP-serialized
	 *     array of rows keyed `item-N` — see DATA-MAP.md "Repeater
	 *     storage format".
	 *   - `attribute_terms`: PARENT-LEVEL classification terms,
	 *     `{ taxonomy: term_slug }` (e.g. `{"pa_physical-or-pdf":
	 *     "physical-art"}`). Assigned to the parent product for
	 *     filtering; created with `is_variation=0` — NOT placed on the
	 *     variations themselves (single-selector amendment after
	 *     alpha.22 staging showed a redundant second dropdown).
	 *   - `variant_attribute`: THE storefront variation selector.
	 *     `taxonomy` + `from_subfield` (whose value becomes the term) +
	 *     `create_if_missing`. Every mapping should declare one so all
	 *     variations are picked via a single dropdown (PDF rows use
	 *     their `variant_label` too, defaulting "Instructions PDF").
	 *   - `subfield_map[]`: `{ subfield, target, pull?, transform? }` —
	 *     repeater subfield → variation typed-setter field. `transform:
	 *     "date"` marks sale-schedule fields; `pull: true` marks Phase
	 *     4c-B reverse-sync participants (stock only, initially).
	 *   - `price_fallback_field`: source CCT scalar whose value is used
	 *     for `regular_price` when the row's mapped price subfield is
	 *     empty (BBHQ: `price`).
	 *   - `downloads_subfield`: repeater subfield holding an attachment
	 *     ID that becomes the variation's downloadable file (PDF flow).
	 *     Empty = no downloads handling.
	 *   - `variation_defaults`: fixed variation fields applied on every
	 *     reconcile (e.g. `manage_stock`, `virtual`, `downloadable`).
	 *   - `enabled_subfield`: repeater subfield acting as the row's
	 *     on/off switch (`'true'`/`'false'` strings per JE switcher).
	 *   - `on_sale_subfield`: gate subfield (glossary yes/no). When not
	 *     `yes`, the reconciler CLEARS the variation's sale fields so
	 *     switching a sale off actually ends it storefront-side.
	 *   - `delete_policy`: `trash` (default) or `private` for rows
	 *     removed from the repeater.
	 *
	 * @return array
	 */
	public static function default_variation_mapping() {
		return array(
			'source_repeater'      => '',
			'attribute_terms'      => array(),
			'variant_attribute'    => null,
			'subfield_map'         => array(),
			'price_fallback_field' => '',
			'downloads_subfield'   => '',
			'variation_defaults'   => array(),
			'enabled_subfield'     => 'enabled',
			'on_sale_subfield'     => 'on_sale',
			'delete_policy'        => 'trash',
			// §4.14.11: when non-empty, the reconciler derives a display
			// string from this mapping's first enabled row's L/W/H and
			// writes it into the named source-CCT column (e.g.
			// `approximate_size`). Empty = derivation off.
			'derived_size_field'   => '',
			'enabled'              => true,
		);
	}

	/**
	 * Default shape for the `applies_when_target_in_terms` block
	 * (alpha.21, post-L-033). Used by `merge_with_defaults()` to fill in
	 * missing keys on bridges saved before alpha.21.
	 *
	 * Empty `taxonomy` or empty `terms` array = no gate (bridge applies
	 * to all targets — current behavior). Editors opt in by populating
	 * the block; `merge_with_defaults()` also auto-derives a sensible
	 * default from the `taxonomies[]` block when both are eligible.
	 *
	 * @return array
	 */
	public static function default_applies_when_target_in_terms() {
		return array(
			'taxonomy'   => '',
			'terms'      => array(),
			'match_by'   => 'slug',
			'match_mode' => 'any',
			'applies_to' => 'pull',
		);
	}

	/**
	 * Default shape for the `cct_screen` block (Phase 4b / §4.7, alpha.14).
	 *
	 * This block configures per-bridge panels that render on the JE CCT
	 * edit screen for the bridge's source CCT. The L-027 iframe-flip
	 * pattern is applied in the reverse direction here — open WC's
	 * native admin UI inside a chrome-stripped modal from the CCT side.
	 *
	 * Currently the only sub-panel is `wc_variations`. Future CCT-edit-
	 * screen panels can be added as sibling keys without breaking
	 * existing bridges (deep-merge in merge_with_defaults handles
	 * back-compat).
	 *
	 * @return array
	 */
	public static function default_cct_screen() {
		return array(
			'wc_variations' => self::default_wc_variations_panel(),
		);
	}

	/**
	 * Default shape for the `cct_screen.wc_variations` panel.
	 *
	 * Drives the "Open variations editor →" button that the alpha.14
	 * `JEDB_CCT_Screen_Variations_Panel` injects beneath the JE save
	 * button on CCT edit pages whose CCT slug matches this bridge's
	 * `source_target`.
	 *
	 *   - `enabled`: master toggle for the panel on this bridge. Off by
	 *     default. Hidden from the Flatten admin tab UI entirely when
	 *     `target_target !== 'posts::product'` per D6 — the feature
	 *     only makes sense for Woo product targets.
	 *   - `title`: heading shown on the panel. Empty → fallback to
	 *     "WooCommerce Variations".
	 *   - `auto_force_variable_type`: when true, the chrome-strip script
	 *     inside the iframe (Phase B, alpha.15) auto-triggers
	 *     `jQuery('#product-type').val('variable').trigger('change')` on
	 *     load so the editor doesn't have to manually flip the product
	 *     type dropdown. D3 admin opt-in — off by default because some
	 *     bridges may want to leave product type management entirely to
	 *     the editor.
	 *   - `show_full_page` (alpha.16): when true, the iframe renders the
	 *     FULL WC product edit page inside the modal — title editor,
	 *     all meta boxes (SEO, attributes, featured image, etc.), the
	 *     works — with only WP admin chrome (admin bar, sidebar,
	 *     footer, notices, page title) hidden. The Done/Cancel top bar
	 *     + close-on-save still apply. When false (default), the
	 *     iframe is stripped down to ONLY the Product Data + Submit
	 *     meta boxes for focused variations work. Per-bridge admin
	 *     discretion — different bridges may want different UX
	 *     scopes.
	 *
	 * @return array
	 */
	public static function default_wc_variations_panel() {
		return array(
			'enabled'                  => false,
			'title'                    => '',
			'auto_force_variable_type' => false,
			'show_full_page'           => false,
		);
	}

	/**
	 * Canonical default shape for one mapping row.
	 */
	public static function default_mapping() {
		return array(
			'source_field'      => '',
			'target_field'      => '',
			'push_transform'    => array(
				array( 'name' => 'passthrough', 'args' => array() ),
			),
			'pull_transform'    => array(
				array( 'name' => 'passthrough', 'args' => array() ),
			),
			'enabled'           => true,
			'note'              => '',
			// Phase 4 alpha.3 (D-26 / D-27): meta box surfacing flags +
			// freeform group label. The Woo bridge meta box renders an
			// editable input for fields where surface_on_target=true AND
			// the target adapter's is_natively_rendered() returns false
			// (D-16 composes naturally). surface_on_source is forward-
			// compat for an eventual CCT-side meta box and is stored
			// but not yet consumed in Phase 4. group is a freeform
			// per-mapping label used by the meta box for visual
			// grouping ("Pricing", "Identity", etc. — admin types
			// whatever, no enum).
			'surface_on_source' => false,
			'surface_on_target' => false,
			'group'             => '',
		);
	}

	/* -----------------------------------------------------------------------
	 * Slug helpers
	 * -------------------------------------------------------------------- */

	public static function build_slug( $source_target, $target_target, $direction = 'push' ) {

		$source = sanitize_title( str_replace( '::', '-', (string) $source_target ) );
		$target = sanitize_title( str_replace( '::', '-', (string) $target_target ) );
		$dir    = sanitize_key( (string) $direction );

		return $source . '__' . $target . '__' . $dir;
	}

	private function ensure_unique_slug( $base, $exclude_id = 0 ) {

		global $wpdb;

		$base       = (string) $base;
		$candidate  = $base;
		$attempt    = 1;

		while ( true ) {

			$where = 'config_slug = %s';
			$params = array( $candidate );

			if ( $exclude_id ) {
				$where   .= ' AND id != %d';
				$params[] = (int) $exclude_id;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$this->table()}` WHERE {$where} LIMIT 1", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

			if ( ! $exists ) {
				return $candidate;
			}

			$attempt++;
			$candidate = $base . '-' . $attempt;

			if ( $attempt > 99 ) {
				return $base . '-' . wp_generate_password( 6, false, false );
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Decode / encode
	 * -------------------------------------------------------------------- */

	private function decode_row( $row ) {

		$row = is_object( $row ) ? get_object_vars( $row ) : (array) $row;

		$decoded = array();
		if ( ! empty( $row['config_json'] ) ) {
			$tmp = json_decode( (string) $row['config_json'], true );
			if ( is_array( $tmp ) ) {
				$decoded = $tmp;
			}
		}

		$row['config'] = $this->merge_with_defaults( $decoded );
		unset( $row['config_json'] );

		return $row;
	}

	private function merge_with_defaults( array $decoded ) {

		$config = wp_parse_args( $decoded, self::default_config_json() );

		if ( ! is_array( $config['mappings'] ) ) {
			$config['mappings'] = array();
		}
		foreach ( $config['mappings'] as &$m ) {
			if ( ! is_array( $m ) ) {
				$m = self::default_mapping();
				continue;
			}
			$m = wp_parse_args( $m, self::default_mapping() );
			if ( ! is_array( $m['push_transform'] ) ) { $m['push_transform'] = array(); }
			if ( ! is_array( $m['pull_transform'] ) ) { $m['pull_transform'] = array(); }
		}
		unset( $m );

		// Phase 3.6 / D-20: deep-merge taxonomies[] entries so existing
		// 0.5.x bridges that were saved before the array existed get a
		// well-formed empty array on read, and rules saved before any
		// individual key existed get sensible defaults applied per
		// default_taxonomy_rule().
		if ( ! is_array( $config['taxonomies'] ) ) {
			$config['taxonomies'] = array();
		}
		foreach ( $config['taxonomies'] as &$rule ) {
			if ( ! is_array( $rule ) ) {
				$rule = self::default_taxonomy_rule();
				continue;
			}
			$rule = wp_parse_args( $rule, self::default_taxonomy_rule() );
			if ( ! is_array( $rule['apply_terms'] ) )         { $rule['apply_terms']         = array(); }
			if ( ! is_array( $rule['apply_terms_inverse'] ) ) { $rule['apply_terms_inverse'] = array(); }
		}
		unset( $rule );

		// alpha.21 (post-L-033): bridge applicability gate. Existing
		// pre-alpha.21 bridges saved without the block get the default
		// (empty taxonomy / empty terms = no gate). When the block is
		// empty AND the bridge has a non-empty `taxonomies[]` rule with
		// a non-empty `apply_terms` list, AUTO-DERIVE the gate from the
		// first such rule:
		//   taxonomy   = taxonomies[0].taxonomy
		//   terms      = taxonomies[0].apply_terms
		//   match_by   = taxonomies[0].match_by
		//   match_mode = 'any'
		//   applies_to = 'pull'
		// This gives every BBHQ-style category-scoped bridge the
		// correct reverse-pull gate at read time without requiring the
		// editor to edit and re-save. Editors who want different
		// semantics (push gating, exclusion modes) can override the
		// derived defaults by populating the block explicitly.
		if ( ! isset( $config['applies_when_target_in_terms'] ) || ! is_array( $config['applies_when_target_in_terms'] ) ) {
			$config['applies_when_target_in_terms'] = self::default_applies_when_target_in_terms();
		} else {
			$config['applies_when_target_in_terms'] = wp_parse_args(
				$config['applies_when_target_in_terms'],
				self::default_applies_when_target_in_terms()
			);
		}
		if ( ! is_array( $config['applies_when_target_in_terms']['terms'] ) ) {
			$config['applies_when_target_in_terms']['terms'] = array();
		}
		// Auto-derive from taxonomies[] when the gate is empty and a
		// well-formed taxonomy rule exists.
		$gate = $config['applies_when_target_in_terms'];
		if ( '' === (string) $gate['taxonomy'] && empty( $gate['terms'] ) && ! empty( $config['taxonomies'] ) ) {
			foreach ( $config['taxonomies'] as $tax_rule ) {
				if ( ! is_array( $tax_rule ) ) { continue; }
				$rule_tax   = isset( $tax_rule['taxonomy'] )    ? (string) $tax_rule['taxonomy']    : '';
				$rule_terms = isset( $tax_rule['apply_terms'] ) && is_array( $tax_rule['apply_terms'] ) ? $tax_rule['apply_terms'] : array();
				if ( '' !== $rule_tax && ! empty( $rule_terms ) ) {
					$config['applies_when_target_in_terms']['taxonomy']   = $rule_tax;
					$config['applies_when_target_in_terms']['terms']      = array_values( $rule_terms );
					$config['applies_when_target_in_terms']['match_by']   = isset( $tax_rule['match_by'] ) && '' !== (string) $tax_rule['match_by'] ? (string) $tax_rule['match_by'] : 'slug';
					$config['applies_when_target_in_terms']['_derived_from_taxonomies'] = true;
					break;
				}
			}
		}

		// Phase 4c-A: variation_mappings back-compat. Bridges saved
		// before the block existed read an empty array; each saved
		// entry deep-merges against the factory so entries saved
		// before newer keys existed get defaults filled in.
		if ( ! isset( $config['variation_mappings'] ) || ! is_array( $config['variation_mappings'] ) ) {
			$config['variation_mappings'] = array();
		}
		foreach ( $config['variation_mappings'] as &$vm_entry ) {
			if ( ! is_array( $vm_entry ) ) {
				$vm_entry = self::default_variation_mapping();
				continue;
			}
			$vm_entry = wp_parse_args( $vm_entry, self::default_variation_mapping() );
			if ( ! is_array( $vm_entry['attribute_terms'] ) )    { $vm_entry['attribute_terms']    = array(); }
			if ( ! is_array( $vm_entry['subfield_map'] ) )       { $vm_entry['subfield_map']       = array(); }
			if ( ! is_array( $vm_entry['variation_defaults'] ) ) { $vm_entry['variation_defaults'] = array(); }
		}
		unset( $vm_entry );

		// alpha.21 (post-L-033): explicit reverse auto-create flag. For
		// bridges saved before this key existed, default to false (do
		// NOT inherit from `auto_create_target_when_unlinked`) so the
		// reverse-pull cascade footgun is closed by default. Editors
		// who genuinely want product-driven CCT creation opt in
		// explicitly per bridge.
		if ( ! isset( $config['auto_create_source_when_unlinked'] ) ) {
			$config['auto_create_source_when_unlinked'] = false;
		} else {
			$config['auto_create_source_when_unlinked'] = (bool) $config['auto_create_source_when_unlinked'];
		}

		// Phase 4b (alpha.14): cct_screen back-compat. Existing alpha.3-
		// alpha.13 bridges saved before this block existed get filled in
		// with default panel configs. Each sub-panel is deep-merged
		// against its own default factory so older saved configs missing
		// newer keys read cleanly.
		//
		// alpha.13 saved a `variations[]` array that's no longer read by
		// the engine (the reconciler was retired per L-032). The field
		// is intentionally left in saved configs as inert data — we
		// don't strip it on read so editors who manually re-enable the
		// helpers (find_managed_variation, create_for_bridge) for custom
		// automation hooks keep the data they had. wp_parse_args adds
		// missing keys but doesn't remove existing ones.
		if ( ! isset( $config['cct_screen'] ) || ! is_array( $config['cct_screen'] ) ) {
			$config['cct_screen'] = self::default_cct_screen();
		} else {
			$config['cct_screen'] = wp_parse_args( $config['cct_screen'], self::default_cct_screen() );
			if ( ! isset( $config['cct_screen']['wc_variations'] ) || ! is_array( $config['cct_screen']['wc_variations'] ) ) {
				$config['cct_screen']['wc_variations'] = self::default_wc_variations_panel();
			} else {
				$config['cct_screen']['wc_variations'] = wp_parse_args(
					$config['cct_screen']['wc_variations'],
					self::default_wc_variations_panel()
				);
			}
		}

		$defaults = self::default_config_json();

		if ( ! is_array( $config['link_via'] ) ) {
			$config['link_via'] = $defaults['link_via'];
		} else {
			// Deep-merge so existing bridge configs that were saved before
			// fallback_to_single_page / auto_attach_relation existed still
			// get sensible defaults applied on read (per L-021 self-heal).
			$config['link_via'] = wp_parse_args( $config['link_via'], $defaults['link_via'] );
		}
		if ( ! is_array( $config['trigger'] ) ) {
			$config['trigger'] = $defaults['trigger'];
		}
		if ( ! is_array( $config['required_overrides'] ) ) {
			$config['required_overrides'] = $defaults['required_overrides'];
		}

		// Phase 4 alpha.3 (D-27): deep-merge meta_box block for back-compat
		// with 0.5.x flatten configs that were saved before this block
		// existed. Idempotent for new configs (already present from
		// default_config_json()).
		if ( ! is_array( $config['meta_box'] ) ) {
			$config['meta_box'] = self::default_meta_box();
		} else {
			$config['meta_box'] = wp_parse_args( $config['meta_box'], self::default_meta_box() );
			if ( ! is_array( $config['meta_box']['groups'] ) ) {
				$config['meta_box']['groups'] = array();
			}
		}

		// Phase 4 alpha.3 (D-27): cct_single_redirect default — false for
		// existing configs that pre-date the flag.
		if ( ! isset( $config['cct_single_redirect'] ) ) {
			$config['cct_single_redirect'] = false;
		} else {
			$config['cct_single_redirect'] = (bool) $config['cct_single_redirect'];
		}

		// Phase 4 alpha.3 (D-26 / D-27): per-mapping surface_* flags +
		// group default. Back-compat fill for existing mappings — the
		// inner foreach earlier already runs wp_parse_args with the
		// updated default_mapping() shape, so the new keys land
		// automatically. This explicit cast block just ensures types
		// are stable when raw JSON gets edited by hand.
		foreach ( $config['mappings'] as &$m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$m['surface_on_source'] = ! empty( $m['surface_on_source'] );
			$m['surface_on_target'] = ! empty( $m['surface_on_target'] );
			$m['group']             = isset( $m['group'] ) ? (string) $m['group'] : '';
		}
		unset( $m );

		return $config;
	}

	/* -----------------------------------------------------------------------
	 * Reads
	 * -------------------------------------------------------------------- */

	public function get_by_id( $id ) {

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$this->table()}` WHERE id = %d LIMIT 1", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		return $row ? $this->decode_row( $row ) : null;
	}

	public function get_by_slug( $slug ) {

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$this->table()}` WHERE config_slug = %s LIMIT 1", (string) $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * @param array $args  ['enabled' => 0|1|null, 'source_target' => string|null,
	 *                      'target_target' => string|null, 'direction' => string|null,
	 *                      'orderby' => string, 'order' => 'ASC'|'DESC']
	 * @return array<int,array>
	 */
	public function get_all( array $args = array() ) {

		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'enabled'       => null,
				'source_target' => null,
				'target_target' => null,
				'direction'     => null,
				'orderby'       => 'updated_at',
				'order'         => 'DESC',
			)
		);

		$where  = array();
		$params = array();

		if ( null !== $args['enabled'] ) {
			$where[]  = 'enabled = %d';
			$params[] = (int) $args['enabled'];
		}
		foreach ( array( 'source_target', 'target_target', 'direction' ) as $col ) {
			if ( null !== $args[ $col ] && '' !== $args[ $col ] ) {
				$where[]  = "{$col} = %s";
				$params[] = (string) $args[ $col ];
			}
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$orderby   = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$order_sql = $orderby ? " ORDER BY {$orderby}" : '';

		$sql = "SELECT * FROM `{$this->table()}`{$where_sql}{$order_sql}";

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $this->decode_row( $row );
		}
		return $out;
	}

	public function get_enabled() {
		return $this->get_all( array( 'enabled' => 1 ) );
	}

	/**
	 * Bridges that PUSH from a specific source target. Used by the flattener
	 * to wire the right hooks per CCT at boot.
	 */
	public function get_enabled_for_source( $source_target, $direction = 'push' ) {

		return $this->get_all( array(
			'enabled'       => 1,
			'source_target' => $source_target,
			'direction'     => $direction,
			'orderby'       => 'updated_at',
		) );
	}

	public function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table()}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
	}

	public function count_enabled() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table()}` WHERE enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
	}

	/* -----------------------------------------------------------------------
	 * Writes
	 * -------------------------------------------------------------------- */

	/**
	 * Insert or update a flatten config.
	 *
	 * @param array $input  ['id' => int (optional, for update),
	 *                       'config_slug' => string (optional, auto-built),
	 *                       'label' => string,
	 *                       'source_target' => string,
	 *                       'target_target' => string,
	 *                       'direction' => 'push'|'pull',
	 *                       'enabled' => 0|1,
	 *                       'config' => array  (the full inner JSON)]
	 * @return int|false  Row id on success.
	 */
	public function upsert( array $input ) {

		global $wpdb;

		$id            = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$source_target = isset( $input['source_target'] ) ? sanitize_text_field( (string) $input['source_target'] ) : '';
		$target_target = isset( $input['target_target'] ) ? sanitize_text_field( (string) $input['target_target'] ) : '';
		$direction     = isset( $input['direction'] )     ? sanitize_key( (string) $input['direction'] )            : 'push';
		$label         = isset( $input['label'] )         ? sanitize_text_field( (string) $input['label'] )         : '';
		$enabled       = ! empty( $input['enabled'] ) ? 1 : 0;

		if ( '' === $source_target || '' === $target_target ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Flatten_Config] upsert rejected — missing source/target', 'error', $input );
			}
			return false;
		}

		$config = isset( $input['config'] ) && is_array( $input['config'] ) ? $input['config'] : array();
		$config = $this->merge_with_defaults( $config );

		$slug = isset( $input['config_slug'] ) && '' !== $input['config_slug']
			? sanitize_key( (string) $input['config_slug'] )
			: self::build_slug( $source_target, $target_target, $direction );
		$slug = $this->ensure_unique_slug( $slug, $id );

		$relation_id = '';
		if ( isset( $config['link_via']['relation_id'] ) ) {
			$relation_id = sanitize_text_field( (string) $config['link_via']['relation_id'] );
		}

		$payload = array(
			'config_slug'   => $slug,
			'label'         => $label,
			'source_target' => $source_target,
			'target_target' => $target_target,
			'relation_id'   => $relation_id,
			'direction'     => $direction,
			'enabled'       => $enabled,
			'config_json'   => wp_json_encode( $config ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		if ( $id ) {

			$result = $wpdb->update(
				$this->table(),
				$payload,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false === $result ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Flatten_Config] update failed', 'error', array( 'wpdb_error' => $wpdb->last_error, 'id' => $id ) );
				}
				return false;
			}

			do_action( 'jedb/flatten_config/saved', $id, $payload );
			return $id;
		}

		$payload['created_at'] = current_time( 'mysql', true );

		$result = $wpdb->insert(
			$this->table(),
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $result ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Flatten_Config] insert failed', 'error', array( 'wpdb_error' => $wpdb->last_error ) );
			}
			return false;
		}

		$new_id = (int) $wpdb->insert_id;
		do_action( 'jedb/flatten_config/saved', $new_id, $payload );
		return $new_id;
	}

	public function set_enabled( $id, $enabled ) {

		global $wpdb;
		$result = $wpdb->update(
			$this->table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	public function delete( $id ) {

		global $wpdb;
		$result = $wpdb->delete( $this->table(), array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false !== $result ) {
			do_action( 'jedb/flatten_config/deleted', absint( $id ) );
		}

		return false !== $result;
	}
}
