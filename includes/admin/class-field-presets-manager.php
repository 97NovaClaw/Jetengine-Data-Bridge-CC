<?php
/**
 * Field Presets Manager — Phase 4 Day 1 skeleton (D-26).
 *
 * Read-only API in this release. Full CRUD + admin UI ships in Phase 4
 * Day 4 (BUILD-PLAN §4.12, §7 Phase 4 Day 4 deliverable).
 *
 * Field presets are *target-scoped* (single adapter slug like
 * "posts::product" or "cct::mosaics_data"), curated lists of fields
 * with `mandatory` flags + freeform `group` labels + `hint` text. They
 * answer the operational question "for this target adapter, what does
 * a complete bridge look like?" — knowledge that's site-portable in
 * a way the bridge config itself isn't.
 *
 * Stored in the `jedb_field_presets` site option (created at activation
 * with an empty array). Flat indexed array of preset entries:
 *
 *   array<int, array{
 *       slug:        string,   // unique, sanitize_key()
 *       label:       string,
 *       description: string,
 *       target:      string,   // single adapter slug per D-26
 *       fields: array<int, array{
 *           name:      string,   // field key matching the adapter's schema
 *           label:     string,   // human-readable display name
 *           mandatory: bool,
 *           group:     string,   // freeform per D-26
 *           hint:      string,
 *       }>,
 *       notes:      string,
 *       created_at: string,
 *       updated_at: string,
 *   }>
 *
 * Phase 4 Day 4 will add:
 *   - upsert() / delete() / replace_all() write methods
 *   - prepare_for_storage() validation + sanitization
 *   - JSON export / import wrapper
 *   - Admin tab UI (class-tab-field-presets.php + template + JS)
 *   - "Apply preset" + "Scaffold missing mappings" engine integration on
 *     the Flatten admin tab's Mandatory coverage panel
 *
 * Day 1's job is just the read path so:
 *   1. The activation default is created without errors.
 *   2. Future code that wants to overlay preset coverage onto a bridge
 *      can already call get_for_target() / get_by_slug() and get back
 *      well-formed defaults.
 *   3. The schema shape is committed in code, not just in BUILD-PLAN.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Field_Presets_Manager {

	/** @var JEDB_Field_Presets_Manager|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* -----------------------------------------------------------------------
	 * Default shapes (D-26)
	 * -------------------------------------------------------------------- */

	/**
	 * Canonical default shape for one preset entry.
	 *
	 * @return array
	 */
	public static function default_preset() {
		return array(
			'slug'        => '',
			'label'       => '',
			'description' => '',
			'target'      => '',
			'fields'      => array(),
			'notes'       => '',
			'created_at'  => '',
			'updated_at'  => '',
		);
	}

	/**
	 * Canonical default shape for one field entry inside a preset.
	 *
	 * @return array
	 */
	public static function default_field() {
		return array(
			'name'      => '',
			'label'     => '',
			'mandatory' => false,
			'group'     => '',
			'hint'      => '',
		);
	}

	/* -----------------------------------------------------------------------
	 * Reads (Phase 4 Day 1 ships these; writes land in Day 4)
	 * -------------------------------------------------------------------- */

	/**
	 * Return all presets, sorted by label.
	 *
	 * @return array<int,array>
	 */
	public function get_all() {

		$raw = get_option( JEDB_OPTION_FIELD_PRESETS, array() );

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
	 * Presets whose `target` matches the given adapter slug.
	 * Day 4's "Apply preset" dropdown on the Flatten admin tab uses this.
	 *
	 * @param string $target_slug e.g. "posts::product"
	 * @return array<int,array>
	 */
	public function get_for_target( $target_slug ) {

		$target_slug = (string) $target_slug;
		if ( '' === $target_slug ) {
			return array();
		}

		$out = array();
		foreach ( $this->get_all() as $preset ) {
			if ( $preset['target'] === $target_slug ) {
				$out[] = $preset;
			}
		}
		return $out;
	}

	/**
	 * @param string $slug
	 * @return array|null
	 */
	public function get_by_slug( $slug ) {

		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->get_all() as $preset ) {
			if ( $preset['slug'] === $slug ) {
				return $preset;
			}
		}

		return null;
	}

	public function count_all() {
		return count( $this->get_all() );
	}

	/* -----------------------------------------------------------------------
	 * Writes (Phase 4 Day 4 / alpha.12)
	 * -------------------------------------------------------------------- */

	/**
	 * Create or update a preset (by slug). If a preset with the same slug
	 * already exists, it is replaced wholesale; if not, the entry is
	 * appended. Returns the canonical slug on success or a WP_Error on
	 * validation failure.
	 *
	 * `created_at` and `updated_at` are stamped automatically — incoming
	 * values for those fields are ignored.
	 *
	 * @param array $entry  Raw preset entry from a form / import.
	 * @return string|WP_Error  Canonical slug or error.
	 */
	public function upsert( array $entry ) {

		$prepared = $this->prepare_for_storage( $entry );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$now      = current_time( 'mysql', true );
		$existing = get_option( JEDB_OPTION_FIELD_PRESETS, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$found = false;
		foreach ( $existing as $idx => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$candidate_slug = isset( $candidate['slug'] ) ? sanitize_key( (string) $candidate['slug'] ) : '';
			if ( $candidate_slug === $prepared['slug'] ) {
				$prepared['created_at'] = isset( $candidate['created_at'] ) && '' !== (string) $candidate['created_at']
					? (string) $candidate['created_at']
					: $now;
				$prepared['updated_at'] = $now;
				$existing[ $idx ]       = $prepared;
				$found                  = true;
				break;
			}
		}

		if ( ! $found ) {
			$prepared['created_at'] = $now;
			$prepared['updated_at'] = $now;
			$existing[]             = $prepared;
		}

		update_option( JEDB_OPTION_FIELD_PRESETS, $existing, 'no' );

		return $prepared['slug'];
	}

	/**
	 * Delete a preset by slug. Idempotent — returns true whether the
	 * preset existed or not (caller can distinguish via count_all() if
	 * needed).
	 *
	 * @param string $slug
	 * @return bool
	 */
	public function delete( $slug ) {

		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return false;
		}

		$existing = get_option( JEDB_OPTION_FIELD_PRESETS, array() );
		if ( ! is_array( $existing ) ) {
			return true;
		}

		$out = array();
		foreach ( $existing as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$candidate_slug = isset( $candidate['slug'] ) ? sanitize_key( (string) $candidate['slug'] ) : '';
			if ( $candidate_slug === $slug ) {
				continue;
			}
			$out[] = $candidate;
		}

		update_option( JEDB_OPTION_FIELD_PRESETS, $out, 'no' );

		return true;
	}

	/**
	 * Replace the entire presets option with a freshly validated list.
	 * Used by JSON import with "replace all" enabled.
	 *
	 * Each entry is passed through prepare_for_storage; entries that fail
	 * validation are dropped from the final list (their slugs are listed
	 * in the returned `dropped` array). The transaction is atomic per
	 * `update_option()` — either the option gets the full sanitized list
	 * or nothing changes.
	 *
	 * @param array $entries
	 * @return array{accepted:array<int,string>,dropped:array<int,array{slug:string,reason:string}>}
	 */
	public function replace_all( array $entries ) {

		$accepted = array();
		$dropped  = array();
		$out      = array();
		$now      = current_time( 'mysql', true );

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				$dropped[] = array(
					'slug'   => '',
					'reason' => __( 'not a JSON object', 'je-data-bridge-cc' ),
				);
				continue;
			}

			$prepared = $this->prepare_for_storage( $entry );
			if ( is_wp_error( $prepared ) ) {
				$dropped[] = array(
					'slug'   => isset( $entry['slug'] ) ? (string) $entry['slug'] : '',
					'reason' => $prepared->get_error_message(),
				);
				continue;
			}

			$prepared['created_at'] = isset( $entry['created_at'] ) && '' !== (string) $entry['created_at']
				? sanitize_text_field( (string) $entry['created_at'] )
				: $now;
			$prepared['updated_at'] = $now;

			$accepted[] = $prepared['slug'];
			$out[]      = $prepared;
		}

		update_option( JEDB_OPTION_FIELD_PRESETS, $out, 'no' );

		return array(
			'accepted' => $accepted,
			'dropped'  => $dropped,
		);
	}

	/**
	 * Merge a list of import entries into the existing option (without
	 * replacing). Used by JSON import when "replace all" is OFF. Existing
	 * presets with the same slug are overwritten by the incoming version;
	 * presets not in the import payload are kept untouched.
	 *
	 * @param array $entries
	 * @return array{accepted:array<int,string>,dropped:array<int,array{slug:string,reason:string}>}
	 */
	public function merge_import( array $entries ) {

		$accepted = array();
		$dropped  = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				$dropped[] = array(
					'slug'   => '',
					'reason' => __( 'not a JSON object', 'je-data-bridge-cc' ),
				);
				continue;
			}
			$result = $this->upsert( $entry );
			if ( is_wp_error( $result ) ) {
				$dropped[] = array(
					'slug'   => isset( $entry['slug'] ) ? (string) $entry['slug'] : '',
					'reason' => $result->get_error_message(),
				);
				continue;
			}
			$accepted[] = $result;
		}

		return array(
			'accepted' => $accepted,
			'dropped'  => $dropped,
		);
	}

	/**
	 * Validate and sanitize a single preset entry for storage. Returns a
	 * canonical array shape on success, or a WP_Error describing the
	 * first validation failure.
	 *
	 * Validation rules:
	 *   - `slug` must be present, non-empty, and uniquely identifiable
	 *     after sanitize_key().
	 *   - `label` must be present and non-empty.
	 *   - `target` must be present and resolvable to a registered
	 *     target adapter slug (e.g. "posts::product"). Verified via
	 *     JEDB_Target_Registry when available — falls back to a regex
	 *     match if the registry isn't loaded yet (e.g. during early
	 *     test contexts).
	 *   - Each field entry must have a non-empty `name`. Fields with
	 *     empty names are silently dropped (not a fatal error).
	 *
	 * @param array $entry
	 * @return array|WP_Error
	 */
	public function prepare_for_storage( array $entry ) {

		$defaults = self::default_preset();
		$merged   = wp_parse_args( $entry, $defaults );

		$slug = sanitize_key( (string) $merged['slug'] );
		if ( '' === $slug ) {
			return new WP_Error( 'jedb_preset_missing_slug', __( 'Preset slug is required.', 'je-data-bridge-cc' ) );
		}

		$label = trim( (string) $merged['label'] );
		if ( '' === $label ) {
			return new WP_Error( 'jedb_preset_missing_label', __( 'Preset label is required.', 'je-data-bridge-cc' ) );
		}

		$target = trim( (string) $merged['target'] );
		if ( '' === $target ) {
			return new WP_Error( 'jedb_preset_missing_target', __( 'Preset target adapter slug is required.', 'je-data-bridge-cc' ) );
		}

		if ( class_exists( 'JEDB_Target_Registry' ) ) {
			$registry = JEDB_Target_Registry::instance();
			if ( ! $registry->get( $target ) ) {
				return new WP_Error(
					'jedb_preset_unknown_target',
					sprintf(
						/* translators: %s = target adapter slug */
						__( 'Preset target "%s" is not a registered adapter on this site.', 'je-data-bridge-cc' ),
						$target
					)
				);
			}
		} else {
			// Pre-registry fallback (extremely unlikely in admin contexts).
			if ( ! preg_match( '#^(cct|posts)::[a-z0-9_-]+$#i', $target ) ) {
				return new WP_Error(
					'jedb_preset_unknown_target',
					__( 'Preset target must look like "cct::slug" or "posts::slug".', 'je-data-bridge-cc' )
				);
			}
		}

		$description = isset( $merged['description'] ) ? wp_kses_post( (string) $merged['description'] ) : '';
		$notes       = isset( $merged['notes'] )       ? wp_kses_post( (string) $merged['notes'] )       : '';

		$fields_out = array();
		$seen       = array();
		if ( is_array( $merged['fields'] ) ) {
			foreach ( $merged['fields'] as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$f_merged = wp_parse_args( $f, self::default_field() );

				$name = sanitize_key( (string) $f_merged['name'] );
				if ( '' === $name || isset( $seen[ $name ] ) ) {
					continue;
				}
				$seen[ $name ] = true;

				$fields_out[] = array(
					'name'      => $name,
					'label'     => sanitize_text_field( (string) $f_merged['label'] ),
					'mandatory' => ! empty( $f_merged['mandatory'] ),
					'group'     => sanitize_text_field( (string) $f_merged['group'] ),
					'hint'      => wp_kses_post( (string) $f_merged['hint'] ),
				);
			}
		}

		return array(
			'slug'        => $slug,
			'label'       => sanitize_text_field( $label ),
			'description' => $description,
			'target'      => sanitize_text_field( $target ),
			'fields'      => $fields_out,
			'notes'       => $notes,
			'created_at'  => isset( $merged['created_at'] ) ? sanitize_text_field( (string) $merged['created_at'] ) : '',
			'updated_at'  => isset( $merged['updated_at'] ) ? sanitize_text_field( (string) $merged['updated_at'] ) : '',
		);
	}

	/**
	 * Compute a bridge's effective mandatory field list by combining:
	 *   - Adapter-reported required fields (via $target_adapter->get_required_fields())
	 *   - The bridge's required_overrides.add
	 *   - Minus the bridge's required_overrides.remove
	 *
	 * Each entry in the returned list carries `origin` provenance so the
	 * UI can label fields as coming from the adapter / overrides /
	 * preset-applied. (Preset-applied fields end up in
	 * required_overrides.add at apply time, so their origin tag becomes
	 * "override" once persisted — the preset itself isn't preserved as a
	 * back-reference per BUILD-PLAN §4.12 snapshot model.)
	 *
	 * @param array       $bridge_config  The bridge config_json (or array equivalent).
	 * @param array<int,string> $adapter_required  Required field names from the target adapter.
	 * @return array<int,array{name:string,origin:string}>
	 */
	public static function compute_effective_required_fields( array $bridge_config, array $adapter_required ) {

		$out  = array();
		$seen = array();

		foreach ( $adapter_required as $name ) {
			$name = (string) $name;
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$out[]         = array( 'name' => $name, 'origin' => 'adapter' );
		}

		$overrides = isset( $bridge_config['required_overrides'] ) && is_array( $bridge_config['required_overrides'] )
			? $bridge_config['required_overrides']
			: array();

		$add_list = isset( $overrides['add'] ) && is_array( $overrides['add'] ) ? $overrides['add'] : array();
		foreach ( $add_list as $name ) {
			$name = (string) $name;
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$out[]         = array( 'name' => $name, 'origin' => 'override' );
		}

		$remove_list = isset( $overrides['remove'] ) && is_array( $overrides['remove'] ) ? $overrides['remove'] : array();
		if ( ! empty( $remove_list ) ) {
			$remove_lookup = array_flip( array_map( 'strval', $remove_list ) );
			$filtered      = array();
			foreach ( $out as $row ) {
				if ( isset( $remove_lookup[ $row['name'] ] ) ) {
					continue;
				}
				$filtered[] = $row;
			}
			$out = $filtered;
		}

		return $out;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Fill in default keys for any entry read from the option, regardless
	 * of which version of the schema saved it. Idempotent.
	 *
	 * @param array $entry
	 * @return array
	 */
	private function merge_with_defaults( array $entry ) {

		$merged = wp_parse_args( $entry, self::default_preset() );

		if ( ! is_array( $merged['fields'] ) ) {
			$merged['fields'] = array();
		}

		$fields_out = array();
		foreach ( $merged['fields'] as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$fields_out[] = wp_parse_args( $f, self::default_field() );
		}
		$merged['fields'] = $fields_out;

		return $merged;
	}
}
