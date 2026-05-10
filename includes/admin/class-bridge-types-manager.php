<?php
/**
 * Bridge Types Manager — Phase 4 / Day 1 (alpha.2 schema).
 *
 * CRUD wrapper for the `jedb_bridge_types` site option. Each bridge type is
 * a *template* that the Phase 4 Bridge meta box (Day 2) clones into a
 * concrete `wp_jedb_flatten_configs` row when an editor wires up an
 * individual product.
 *
 * ----------------------------------------------------------------------
 * Schema (alpha.2 onwards) — INTENTIONAL ALIGNMENT WITH FLATTEN CONFIG
 * ----------------------------------------------------------------------
 *
 *   array<int, array{
 *       slug:                 string,   // unique, sanitize_key()
 *       label:                string,
 *       description:          string,
 *
 *       source_target:        string,   // e.g. "cct::mosaics_data"
 *       target_target:        string,   // e.g. "posts::product"
 *       direction:            string,   // push|pull|bidirectional
 *       enabled:              bool,
 *
 *       cct_single_redirect:  bool,     // §4.6 redirect shim opt-in
 *       variations:           array,    // Phase 4b — stored but unused in Phase 4
 *
 *       // The actual config payload — mirrors flatten config inner shape
 *       // EXACTLY (see JEDB_Flatten_Config_Manager::default_config_json()).
 *       // The Bridge meta box (Day 2) will literally copy this block into
 *       // a new flatten config row's config_json. Pasting a raw flatten
 *       // config's "Advanced JSON" into the Bridges admin tab Just Works.
 *       flatten_defaults: array{
 *           mappings:                          array,
 *           taxonomies:                        array,
 *           condition:                         string,
 *           condition_snippet:                 string,
 *           priority:                          int,
 *           trigger:                           array,
 *           link_via:                          array,
 *           auto_create_target_when_unlinked:  bool,
 *           required_overrides:                array,
 *           origin_tag:                        string,
 *       },
 *
 *       created_at:           string,   // mysql datetime, immutable on save
 *       updated_at:           string,   // mysql datetime, refreshed on every save
 *   }>
 *
 * ----------------------------------------------------------------------
 * Why this shape (per L-025)
 * ----------------------------------------------------------------------
 *
 * v0.6.0-alpha.1 used `default_field_mappings`, `default_taxonomies`,
 * `default_condition`, `default_priority`, `default_direction` keys at the
 * top level. The `default_` prefix was meant to signal "this is a template",
 * but it created a silent-data-loss trap: pasting a raw flatten config JSON
 * (which uses `mappings`, `taxonomies`, etc.) into the Bridges admin tab
 * would save successfully but drop every mapping/taxonomy on the floor
 * because key names didn't match. Documented as L-025.
 *
 * The fix locks in the principle: "bridge type is a flatten config template;
 * inner shapes must match exactly." Bridge type adds metadata at top level
 * and wraps the flatten config payload as `flatten_defaults`.
 *
 * Back-compat: if an alpha.1-shaped bridge type is read from the option,
 * `merge_with_defaults()` migrates it silently on first read. Subsequent
 * saves persist the new shape. No data loss, no manual migration needed.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Bridge_Types_Manager {

	/** @var JEDB_Bridge_Types_Manager|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* -----------------------------------------------------------------------
	 * Default shapes
	 * -------------------------------------------------------------------- */

	/**
	 * Canonical default shape for one bridge type.
	 *
	 * @return array
	 */
	public static function default_bridge_type() {
		return array(
			'slug'                => '',
			'label'               => '',
			'description'         => '',

			'source_target'       => '',
			'target_target'       => '',
			'direction'           => 'push',
			'enabled'             => true,

			'cct_single_redirect' => false,
			'variations'          => array(),

			'flatten_defaults'    => self::default_flatten_defaults(),

			'created_at'          => '',
			'updated_at'          => '',
		);
	}

	/**
	 * Canonical default for the flatten_defaults inner block. Mirrors
	 * `JEDB_Flatten_Config_Manager::default_config_json()` exactly so that
	 * the Day 2 meta box clone is a one-liner.
	 *
	 * Kept inline (not a require_once'd dependency) because this class loads
	 * before the flatten subsystem on the admin tab boot path. If
	 * JEDB_Flatten_Config_Manager IS available we delegate; otherwise we
	 * return a hard-coded mirror that should be kept in sync with it
	 * (covered by the unit verification in §7.1 Phase 4 Day 1 onward).
	 *
	 * @return array
	 */
	public static function default_flatten_defaults() {

		if ( class_exists( 'JEDB_Flatten_Config_Manager' ) ) {
			return JEDB_Flatten_Config_Manager::default_config_json();
		}

		// Hard-coded mirror — keep aligned with
		// JEDB_Flatten_Config_Manager::default_config_json().
		return array(
			'mappings'                         => array(),
			'taxonomies'                       => array(),
			'condition'                        => '',
			'condition_snippet'                => '',
			'priority'                         => 100,
			'trigger'                          => array(
				'type' => 'cct_save',
				'args' => array(),
			),
			'link_via'                         => array(
				'type'                    => 'je_relation',
				'relation_id'             => '',
				'side'                    => 'auto',
				'fallback_to_single_page' => true,
				'auto_attach_relation'    => true,
			),
			'auto_create_target_when_unlinked' => false,
			'required_overrides'               => array(
				'add'    => array(),
				'remove' => array(),
			),
			'origin_tag'                       => 'flatten',
		);
	}

	/* -----------------------------------------------------------------------
	 * Reads
	 * -------------------------------------------------------------------- */

	/**
	 * Return all bridge types, sorted by label ASC (after merging with defaults
	 * AND silently migrating alpha.1 shapes if any are still in storage).
	 *
	 * @return array<int,array>
	 */
	public function get_all() {

		$raw = get_option( JEDB_OPTION_BRIDGE_TYPES, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = $this->merge_with_defaults( $entry );
		}

		usort( $out, static function ( $a, $b ) {
			return strcasecmp( (string) $a['label'], (string) $b['label'] );
		} );

		return $out;
	}

	public function get_enabled() {

		$out = array();
		foreach ( $this->get_all() as $bt ) {
			if ( ! empty( $bt['enabled'] ) ) {
				$out[] = $bt;
			}
		}
		return $out;
	}

	public function get_by_slug( $slug ) {

		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->get_all() as $bt ) {
			if ( $bt['slug'] === $slug ) {
				return $bt;
			}
		}

		return null;
	}

	/**
	 * Bridge types whose target post type matches the given post.
	 * Day 2's meta box uses this to populate its dropdown.
	 *
	 * @param string $post_type
	 * @return array<int,array>
	 */
	public function get_for_post_type( $post_type ) {

		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type ) {
			return array();
		}
		$slug = 'posts::' . $post_type;

		$out = array();
		foreach ( $this->get_enabled() as $bt ) {
			if ( $bt['target_target'] === $slug ) {
				$out[] = $bt;
			}
		}
		return $out;
	}

	public function count_all() {
		return count( $this->get_all() );
	}

	public function count_enabled() {
		return count( $this->get_enabled() );
	}

	/* -----------------------------------------------------------------------
	 * Writes
	 * -------------------------------------------------------------------- */

	/**
	 * Insert or update a bridge type. If a bridge type with the same slug
	 * already exists, it's overwritten (preserving created_at).
	 *
	 * @param array  $input
	 * @param string $original_slug  When editing an existing type whose slug
	 *                               is being renamed, this is the OLD slug.
	 *                               Pass '' for inserts and same-slug updates.
	 * @return array{ok:bool,error?:string,bridge_type?:array}
	 */
	public function upsert( array $input, $original_slug = '' ) {

		$prepared = $this->prepare_for_storage( $input );
		if ( ! empty( $prepared['errors'] ) ) {
			return array(
				'ok'    => false,
				'error' => implode( ' · ', $prepared['errors'] ),
			);
		}
		$bridge_type = $prepared['bridge_type'];

		$all = $this->get_all_raw();

		$now = current_time( 'mysql', false );

		$lookup_slug = sanitize_key( (string) $original_slug );
		if ( '' === $lookup_slug ) {
			$lookup_slug = $bridge_type['slug'];
		}

		$found_index = $this->find_index_by_slug( $all, $lookup_slug );

		if ( '' !== $original_slug && $original_slug !== $bridge_type['slug'] ) {
			$collision = $this->find_index_by_slug( $all, $bridge_type['slug'] );
			if ( null !== $collision && $collision !== $found_index ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'A bridge type with slug "%s" already exists.', $bridge_type['slug'] ),
				);
			}
		} elseif ( null === $found_index ) {
			$collision = $this->find_index_by_slug( $all, $bridge_type['slug'] );
			if ( null !== $collision ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'A bridge type with slug "%s" already exists.', $bridge_type['slug'] ),
				);
			}
		}

		$bridge_type['updated_at'] = $now;

		if ( null === $found_index ) {
			$bridge_type['created_at'] = $now;
			$all[] = $bridge_type;
		} else {
			$existing                  = $all[ $found_index ];
			$bridge_type['created_at'] = ! empty( $existing['created_at'] ) ? $existing['created_at'] : $now;
			$all[ $found_index ]       = $bridge_type;
		}

		$saved = $this->persist( $all );
		if ( ! $saved ) {
			return array(
				'ok'    => false,
				'error' => 'Failed to persist option (write returned false).',
			);
		}

		return array(
			'ok'          => true,
			'bridge_type' => $this->merge_with_defaults( $bridge_type ),
		);
	}

	public function set_enabled( $slug, $enabled ) {

		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return false;
		}

		$all = $this->get_all_raw();
		$idx = $this->find_index_by_slug( $all, $slug );
		if ( null === $idx ) {
			return false;
		}

		$all[ $idx ]['enabled']    = $enabled ? true : false;
		$all[ $idx ]['updated_at'] = current_time( 'mysql', false );

		return $this->persist( $all );
	}

	public function delete( $slug ) {

		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return false;
		}

		$all = $this->get_all_raw();
		$idx = $this->find_index_by_slug( $all, $slug );
		if ( null === $idx ) {
			return false;
		}

		array_splice( $all, $idx, 1 );

		return $this->persist( $all );
	}

	/**
	 * Replace the entire bridge types list. Used by JSON import.
	 *
	 * @param array $list
	 * @return array{ok:bool,error?:string,imported?:int,skipped?:int}
	 */
	public function replace_all( array $list ) {

		$prepared = array();
		$skipped  = 0;
		$errors   = array();
		$now      = current_time( 'mysql', false );
		$seen     = array();

		foreach ( $list as $i => $entry ) {

			if ( ! is_array( $entry ) ) {
				$skipped++;
				continue;
			}

			$one = $this->prepare_for_storage( $entry );
			if ( ! empty( $one['errors'] ) ) {
				$skipped++;
				$errors[] = sprintf( '#%d: %s', $i + 1, implode( ', ', $one['errors'] ) );
				continue;
			}
			$bt = $one['bridge_type'];

			if ( isset( $seen[ $bt['slug'] ] ) ) {
				$skipped++;
				$errors[] = sprintf( '#%d: duplicate slug "%s" within import payload.', $i + 1, $bt['slug'] );
				continue;
			}
			$seen[ $bt['slug'] ] = true;

			$bt['created_at'] = ! empty( $entry['created_at'] ) ? (string) $entry['created_at'] : $now;
			$bt['updated_at'] = $now;

			$prepared[] = $bt;
		}

		$saved = $this->persist( $prepared );
		if ( ! $saved ) {
			return array(
				'ok'    => false,
				'error' => 'Failed to persist option after import.',
			);
		}

		$result = array(
			'ok'       => true,
			'imported' => count( $prepared ),
			'skipped'  => $skipped,
		);
		if ( ! empty( $errors ) ) {
			$result['error'] = implode( ' · ', $errors );
		}
		return $result;
	}

	/* -----------------------------------------------------------------------
	 * Validation + sanitization
	 * -------------------------------------------------------------------- */

	/**
	 * Sanitize + validate one bridge type entry.
	 *
	 * @param array $input
	 * @return array{bridge_type:array,errors:array<int,string>}
	 */
	public function prepare_for_storage( array $input ) {

		$input = $this->upgrade_alpha1_shape( $input );

		$defaults = self::default_bridge_type();
		$bt       = wp_parse_args( $input, $defaults );

		$bt['slug']        = sanitize_key( (string) $bt['slug'] );
		$bt['label']       = sanitize_text_field( (string) $bt['label'] );
		$bt['description'] = sanitize_textarea_field( (string) $bt['description'] );

		$bt['source_target']       = $this->sanitize_target_slug( $bt['source_target'] );
		$bt['target_target']       = $this->sanitize_target_slug( $bt['target_target'] );
		$bt['direction']           = $this->sanitize_direction( $bt['direction'] );
		$bt['enabled']             = (bool) $bt['enabled'];
		$bt['cct_single_redirect'] = (bool) $bt['cct_single_redirect'];

		$bt['variations']       = is_array( $bt['variations'] ) ? array_values( $bt['variations'] ) : array();
		$bt['flatten_defaults'] = $this->sanitize_flatten_defaults( $bt['flatten_defaults'] );

		$errors = $this->validate( $bt );

		return array(
			'bridge_type' => $bt,
			'errors'      => $errors,
		);
	}

	/**
	 * @param array $bt
	 * @return array<int,string>
	 */
	private function validate( array $bt ) {

		$errors = array();

		if ( '' === $bt['slug'] ) {
			$errors[] = 'Slug is required.';
		}
		if ( '' === $bt['label'] ) {
			$errors[] = 'Label is required.';
		}
		if ( '' === $bt['source_target'] ) {
			$errors[] = 'Source target is required (e.g. "cct::available_sets_data").';
		}
		if ( '' === $bt['target_target'] ) {
			$errors[] = 'Target target is required (e.g. "posts::product").';
		}
		if ( $bt['source_target'] === $bt['target_target'] && '' !== $bt['source_target'] ) {
			$errors[] = 'Source and target must be different record stores.';
		}

		$lv = isset( $bt['flatten_defaults']['link_via'] ) && is_array( $bt['flatten_defaults']['link_via'] )
			? $bt['flatten_defaults']['link_via']
			: array();

		if ( isset( $lv['type'] ) && 'je_relation' === $lv['type'] && '' === (string) ( $lv['relation_id'] ?? '' ) ) {
			$errors[] = 'When "JE Relation" is the link mechanism, a relation must be selected.';
		}

		if ( isset( $lv['type'] ) && 'cct_single_post_id' === $lv['type'] && 0 !== strpos( $bt['source_target'], 'cct::' ) ) {
			$errors[] = '"Has Single Page" link can only be used when the source target is a CCT.';
		}

		return $errors;
	}

	private function sanitize_target_slug( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^(cct|posts)::[a-z0-9_\-]+$/i', $value ) ) {
			return '';
		}
		return $value;
	}

	private function sanitize_direction( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'push', 'pull', 'bidirectional' );
		return in_array( $value, $allowed, true ) ? $value : 'push';
	}

	/**
	 * Sanitize the flatten_defaults block. Accepts either the canonical
	 * shape or a raw flatten config payload (key names match exactly so
	 * there's no actual translation to do — just defensive shape checks).
	 *
	 * @param mixed $raw
	 * @return array
	 */
	private function sanitize_flatten_defaults( $raw ) {

		$defaults = self::default_flatten_defaults();

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$fd = wp_parse_args( $raw, $defaults );

		// mappings[] — mirrors JEDB_Flatten_Config_Manager::default_mapping().
		if ( ! is_array( $fd['mappings'] ) ) {
			$fd['mappings'] = array();
		} else {
			$mappings = array();
			foreach ( $fd['mappings'] as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}
				$mappings[] = array(
					'source_field'   => isset( $m['source_field'] ) ? sanitize_text_field( (string) $m['source_field'] ) : '',
					'target_field'   => isset( $m['target_field'] ) ? sanitize_text_field( (string) $m['target_field'] ) : '',
					'push_transform' => is_array( $m['push_transform'] ?? null ) ? $m['push_transform'] : array( array( 'name' => 'passthrough', 'args' => array() ) ),
					'pull_transform' => is_array( $m['pull_transform'] ?? null ) ? $m['pull_transform'] : array( array( 'name' => 'passthrough', 'args' => array() ) ),
					'enabled'        => isset( $m['enabled'] ) ? (bool) $m['enabled'] : true,
					'note'           => isset( $m['note'] ) ? sanitize_text_field( (string) $m['note'] ) : '',
				);
			}
			$fd['mappings'] = $mappings;
		}

		// taxonomies[] — mirrors JEDB_Flatten_Config_Manager::default_taxonomy_rule().
		if ( ! is_array( $fd['taxonomies'] ) ) {
			$fd['taxonomies'] = array();
		} else {
			$rules = array();
			foreach ( $fd['taxonomies'] as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$rules[] = array(
					'taxonomy'            => isset( $rule['taxonomy'] ) ? sanitize_key( (string) $rule['taxonomy'] ) : '',
					'apply_terms'         => isset( $rule['apply_terms'] ) && is_array( $rule['apply_terms'] ) ? array_values( array_map( 'sanitize_text_field', $rule['apply_terms'] ) ) : array(),
					'apply_terms_inverse' => isset( $rule['apply_terms_inverse'] ) && is_array( $rule['apply_terms_inverse'] ) ? array_values( array_map( 'sanitize_text_field', $rule['apply_terms_inverse'] ) ) : array(),
					'match_by'            => isset( $rule['match_by'] ) ? sanitize_key( (string) $rule['match_by'] ) : 'slug',
					'merge_strategy'      => isset( $rule['merge_strategy'] ) ? sanitize_key( (string) $rule['merge_strategy'] ) : 'append',
					'create_if_missing'   => ! empty( $rule['create_if_missing'] ),
					'snippet'             => isset( $rule['snippet'] ) && '' !== $rule['snippet'] ? sanitize_text_field( (string) $rule['snippet'] ) : null,
					'enabled'             => isset( $rule['enabled'] ) ? (bool) $rule['enabled'] : true,
					'note'                => isset( $rule['note'] ) ? sanitize_text_field( (string) $rule['note'] ) : '',
				);
			}
			$fd['taxonomies'] = $rules;
		}

		$fd['condition']         = (string) ( $fd['condition'] ?? '' );
		$fd['condition_snippet'] = (string) ( $fd['condition_snippet'] ?? '' );

		$fd['priority'] = (int) ( $fd['priority'] ?? 100 );
		if ( $fd['priority'] < 0 )   { $fd['priority'] = 0; }
		if ( $fd['priority'] > 999 ) { $fd['priority'] = 999; }

		// trigger
		if ( ! is_array( $fd['trigger'] ) ) {
			$fd['trigger'] = $defaults['trigger'];
		} else {
			$fd['trigger'] = wp_parse_args( $fd['trigger'], $defaults['trigger'] );
			if ( ! is_array( $fd['trigger']['args'] ) ) {
				$fd['trigger']['args'] = array();
			}
		}

		// link_via
		if ( ! is_array( $fd['link_via'] ) ) {
			$fd['link_via'] = $defaults['link_via'];
		} else {
			$fd['link_via']                            = wp_parse_args( $fd['link_via'], $defaults['link_via'] );
			$fd['link_via']['type']                    = in_array( (string) $fd['link_via']['type'], array( 'je_relation', 'cct_single_post_id' ), true ) ? (string) $fd['link_via']['type'] : 'je_relation';
			$fd['link_via']['relation_id']             = (string) $fd['link_via']['relation_id'];
			$fd['link_via']['side']                    = sanitize_key( (string) $fd['link_via']['side'] );
			if ( ! in_array( $fd['link_via']['side'], array( 'auto', 'parent', 'child' ), true ) ) {
				$fd['link_via']['side'] = 'auto';
			}
			$fd['link_via']['fallback_to_single_page'] = (bool) $fd['link_via']['fallback_to_single_page'];
			$fd['link_via']['auto_attach_relation']    = (bool) $fd['link_via']['auto_attach_relation'];
		}

		$fd['auto_create_target_when_unlinked'] = (bool) ( $fd['auto_create_target_when_unlinked'] ?? false );

		// required_overrides
		if ( ! is_array( $fd['required_overrides'] ) ) {
			$fd['required_overrides'] = $defaults['required_overrides'];
		} else {
			$fd['required_overrides'] = wp_parse_args( $fd['required_overrides'], $defaults['required_overrides'] );
			if ( ! is_array( $fd['required_overrides']['add'] ) ) {
				$fd['required_overrides']['add'] = array();
			}
			if ( ! is_array( $fd['required_overrides']['remove'] ) ) {
				$fd['required_overrides']['remove'] = array();
			}
		}

		$fd['origin_tag'] = sanitize_key( (string) ( $fd['origin_tag'] ?? 'flatten' ) );
		if ( '' === $fd['origin_tag'] ) {
			$fd['origin_tag'] = 'flatten';
		}

		return $fd;
	}

	/* -----------------------------------------------------------------------
	 * Back-compat: alpha.1 → alpha.2 shape migration (per L-025)
	 * -------------------------------------------------------------------- */

	/**
	 * Detect alpha.1-shaped bridge types (top-level `default_field_mappings`,
	 * `default_taxonomies`, `default_condition`, `default_priority`,
	 * `default_direction`, `auto_create_target_when_unlinked` at top level,
	 * `link_via` at top level) and silently migrate to alpha.2 shape on read
	 * by lifting those keys into `flatten_defaults`.
	 *
	 * Idempotent: if the entry already has a `flatten_defaults` block, the
	 * migration is a no-op.
	 *
	 * @param array $entry
	 * @return array
	 */
	private function upgrade_alpha1_shape( array $entry ) {

		// Already new shape — nothing to do.
		if ( isset( $entry['flatten_defaults'] ) && is_array( $entry['flatten_defaults'] ) ) {
			// But still honor `default_direction` as a top-level migration
			// for the rare case where direction was renamed but flatten_defaults
			// already existed.
			if ( ! isset( $entry['direction'] ) && isset( $entry['default_direction'] ) ) {
				$entry['direction'] = $entry['default_direction'];
				unset( $entry['default_direction'] );
			}
			return $entry;
		}

		$fd = self::default_flatten_defaults();

		// Lift the renamed keys into flatten_defaults.
		if ( isset( $entry['default_field_mappings'] ) ) {
			$fd['mappings'] = $entry['default_field_mappings'];
			unset( $entry['default_field_mappings'] );
		}
		if ( isset( $entry['default_taxonomies'] ) ) {
			$fd['taxonomies'] = $entry['default_taxonomies'];
			unset( $entry['default_taxonomies'] );
		}
		if ( isset( $entry['default_condition'] ) ) {
			$fd['condition'] = $entry['default_condition'];
			unset( $entry['default_condition'] );
		}
		if ( isset( $entry['default_priority'] ) ) {
			$fd['priority'] = $entry['default_priority'];
			unset( $entry['default_priority'] );
		}
		if ( isset( $entry['link_via'] ) ) {
			$fd['link_via'] = $entry['link_via'];
			unset( $entry['link_via'] );
		}
		if ( isset( $entry['auto_create_target_when_unlinked'] ) ) {
			$fd['auto_create_target_when_unlinked'] = (bool) $entry['auto_create_target_when_unlinked'];
			unset( $entry['auto_create_target_when_unlinked'] );
		}

		// direction (top-level rename: default_direction → direction)
		if ( ! isset( $entry['direction'] ) && isset( $entry['default_direction'] ) ) {
			$entry['direction'] = $entry['default_direction'];
		}
		unset( $entry['default_direction'] );

		$entry['flatten_defaults'] = $fd;

		return $entry;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	private function get_all_raw() {

		$raw = get_option( JEDB_OPTION_BRIDGE_TYPES, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array_values( array_filter( $raw, 'is_array' ) );
	}

	private function find_index_by_slug( array $list, $slug ) {

		$slug = (string) $slug;
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $list as $i => $entry ) {
			if ( isset( $entry['slug'] ) && (string) $entry['slug'] === $slug ) {
				return (int) $i;
			}
		}
		return null;
	}

	private function persist( array $list ) {

		$ok = update_option( JEDB_OPTION_BRIDGE_TYPES, array_values( $list ), false );

		if ( $ok ) {
			do_action( 'jedb/bridge_types/changed', $list );
		}

		return (bool) $ok;
	}

	private function merge_with_defaults( array $entry ) {

		$entry = $this->upgrade_alpha1_shape( $entry );

		$defaults = self::default_bridge_type();
		$merged   = wp_parse_args( $entry, $defaults );

		if ( ! is_array( $merged['flatten_defaults'] ) ) {
			$merged['flatten_defaults'] = self::default_flatten_defaults();
		} else {
			$merged['flatten_defaults'] = wp_parse_args( $merged['flatten_defaults'], self::default_flatten_defaults() );
		}

		if ( ! is_array( $merged['variations'] ) ) {
			$merged['variations'] = array();
		}

		return $merged;
	}
}
