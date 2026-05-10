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
