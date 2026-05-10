<?php
/**
 * Bridge Types Manager — Phase 4 / Day 1.
 *
 * CRUD wrapper for the `jedb_bridge_types` site option. Each bridge type is
 * a *template* that the Phase 4 Bridge meta box (Day 2) can clone into a
 * concrete `wp_jedb_flatten_configs` row when an editor wires up an
 * individual product.
 *
 * Storage shape (the option holds the array):
 *
 *   array<int, array{
 *       slug:                              string,   // unique, sanitize_key()
 *       label:                             string,
 *       description:                       string,
 *       source_target:                     string,   // e.g. "cct::mosaics_data"
 *       target_target:                     string,   // e.g. "posts::product"
 *       default_direction:                 string,   // push|pull|bidirectional
 *       link_via:                          array,    // same shape as flatten config link_via
 *       default_field_mappings:            array,    // cloned into flatten config on first link
 *       default_taxonomies:                array,    // ditto
 *       default_condition:                 string,
 *       default_priority:                  int,
 *       auto_create_target_when_unlinked:  bool,
 *       cct_single_redirect:               bool,     // §4.6 redirect shim opt-in
 *       variations:                        array,    // Phase 4b — stored but unused in Phase 4
 *       enabled:                           bool,
 *       created_at:                        string,   // mysql datetime, immutable on save
 *       updated_at:                        string,   // mysql datetime, refreshed on every save
 *   }>
 *
 * Why a flat indexed array (not keyed by slug):
 *   - Editors can reorder bridge types in the UI and we'd lose the order
 *     if we used the slug as the array key. The slug is enforced unique
 *     via validation on save.
 *
 * Why a site option (not a custom table):
 *   - Bridge types are a SMALL list (a handful per site). The total
 *     payload is tiny, change frequency is low, and editing is human-driven.
 *     A custom table would be overkill.
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
	 * Default shape
	 * -------------------------------------------------------------------- */

	/**
	 * Canonical default shape for one bridge type.
	 *
	 * @return array
	 */
	public static function default_bridge_type() {
		return array(
			'slug'                             => '',
			'label'                            => '',
			'description'                      => '',
			'source_target'                    => '',
			'target_target'                    => '',
			'default_direction'                => 'push',
			'link_via'                         => array(
				'type'                    => 'je_relation',
				'relation_id'             => '',
				'side'                    => 'auto',
				'fallback_to_single_page' => true,
				'auto_attach_relation'    => true,
			),
			'default_field_mappings'           => array(),
			'default_taxonomies'               => array(),
			'default_condition'                => '',
			'default_priority'                 => 100,
			'auto_create_target_when_unlinked' => false,
			'cct_single_redirect'              => false,
			'variations'                       => array(),
			'enabled'                          => true,
			'created_at'                       => '',
			'updated_at'                       => '',
		);
	}

	/* -----------------------------------------------------------------------
	 * Reads
	 * -------------------------------------------------------------------- */

	/**
	 * Return all bridge types, sorted by label ASC (after merging with defaults).
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

	/**
	 * Return only enabled bridge types.
	 *
	 * @return array<int,array>
	 */
	public function get_enabled() {

		$out = array();
		foreach ( $this->get_all() as $bt ) {
			if ( ! empty( $bt['enabled'] ) ) {
				$out[] = $bt;
			}
		}
		return $out;
	}

	/**
	 * Return one bridge type by its slug.
	 *
	 * @param string $slug
	 * @return array|null
	 */
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
	 *                               is being renamed, this is the OLD slug
	 *                               (so we know which row to overwrite).
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

	/**
	 * Toggle enabled state for one bridge type.
	 *
	 * @param string $slug
	 * @param bool   $enabled
	 * @return bool
	 */
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

	/**
	 * Delete one bridge type by slug.
	 *
	 * @param string $slug
	 * @return bool
	 */
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
	 * @param array $list  Array of bridge type entries.
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

		$defaults = self::default_bridge_type();
		$bt       = wp_parse_args( $input, $defaults );

		$bt['slug']        = sanitize_key( (string) $bt['slug'] );
		$bt['label']       = sanitize_text_field( (string) $bt['label'] );
		$bt['description'] = sanitize_textarea_field( (string) $bt['description'] );

		$bt['source_target']     = $this->sanitize_target_slug( $bt['source_target'] );
		$bt['target_target']     = $this->sanitize_target_slug( $bt['target_target'] );
		$bt['default_direction'] = $this->sanitize_direction( $bt['default_direction'] );
		$bt['default_priority']  = (int) $bt['default_priority'];
		if ( $bt['default_priority'] < 0 )   { $bt['default_priority'] = 0; }
		if ( $bt['default_priority'] > 999 ) { $bt['default_priority'] = 999; }

		$bt['default_condition'] = (string) $bt['default_condition'];

		$bt['auto_create_target_when_unlinked'] = (bool) $bt['auto_create_target_when_unlinked'];
		$bt['cct_single_redirect']              = (bool) $bt['cct_single_redirect'];
		$bt['enabled']                          = (bool) $bt['enabled'];

		$bt['link_via']               = $this->sanitize_link_via( $bt['link_via'] );
		$bt['default_field_mappings'] = $this->sanitize_mappings( $bt['default_field_mappings'] );
		$bt['default_taxonomies']     = $this->sanitize_taxonomies( $bt['default_taxonomies'] );
		$bt['variations']             = is_array( $bt['variations'] ) ? array_values( $bt['variations'] ) : array();

		$errors = $this->validate( $bt );

		return array(
			'bridge_type' => $bt,
			'errors'      => $errors,
		);
	}

	/**
	 * Catch structural problems that would make a bridge type unusable.
	 *
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

		if ( 'je_relation' === $bt['link_via']['type'] && '' === (string) $bt['link_via']['relation_id'] ) {
			$errors[] = 'When "JE Relation" is the link mechanism, a relation must be selected.';
		}

		if ( 'cct_single_post_id' === $bt['link_via']['type'] && 0 !== strpos( $bt['source_target'], 'cct::' ) ) {
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
		$value = sanitize_key( (string) $value );
		$allowed = array( 'push', 'pull', 'bidirectional' );
		return in_array( $value, $allowed, true ) ? $value : 'push';
	}

	private function sanitize_link_via( $raw ) {

		$defaults = self::default_bridge_type()['link_via'];

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$out                            = wp_parse_args( $raw, $defaults );
		$out['type']                    = in_array( (string) $out['type'], array( 'je_relation', 'cct_single_post_id' ), true ) ? (string) $out['type'] : 'je_relation';
		$out['relation_id']             = (string) $out['relation_id'];
		$out['side']                    = sanitize_key( (string) $out['side'] );
		if ( ! in_array( $out['side'], array( 'auto', 'parent', 'child' ), true ) ) {
			$out['side'] = 'auto';
		}
		$out['fallback_to_single_page'] = (bool) $out['fallback_to_single_page'];
		$out['auto_attach_relation']    = (bool) $out['auto_attach_relation'];

		return $out;
	}

	private function sanitize_mappings( $raw ) {

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$mapping = array(
				'source_field'   => isset( $m['source_field'] ) ? sanitize_text_field( (string) $m['source_field'] ) : '',
				'target_field'   => isset( $m['target_field'] ) ? sanitize_text_field( (string) $m['target_field'] ) : '',
				'push_transform' => is_array( $m['push_transform'] ?? null ) ? $m['push_transform'] : array( array( 'name' => 'passthrough', 'args' => array() ) ),
				'pull_transform' => is_array( $m['pull_transform'] ?? null ) ? $m['pull_transform'] : array( array( 'name' => 'passthrough', 'args' => array() ) ),
				'enabled'        => isset( $m['enabled'] ) ? (bool) $m['enabled'] : true,
				'note'           => isset( $m['note'] ) ? sanitize_text_field( (string) $m['note'] ) : '',
			);
			$out[] = $mapping;
		}
		return $out;
	}

	private function sanitize_taxonomies( $raw ) {

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$out[] = array(
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
		return $out;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Return the raw stored array WITHOUT default merging.
	 * Used internally by writes so we don't accidentally fatten the row
	 * with computed defaults before persisting.
	 *
	 * @return array<int,array>
	 */
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

		$defaults = self::default_bridge_type();
		$merged   = wp_parse_args( $entry, $defaults );

		if ( ! is_array( $merged['link_via'] ) ) {
			$merged['link_via'] = $defaults['link_via'];
		} else {
			$merged['link_via'] = wp_parse_args( $merged['link_via'], $defaults['link_via'] );
		}

		foreach ( array( 'default_field_mappings', 'default_taxonomies', 'variations' ) as $arr_key ) {
			if ( ! is_array( $merged[ $arr_key ] ) ) {
				$merged[ $arr_key ] = array();
			}
		}

		return $merged;
	}
}
