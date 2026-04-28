<?php
/**
 * Data_Target interface — the universal contract for "where data lives".
 *
 * Every record store the bridge can read or write (CCT items, generic CPT
 * posts, Woo products, Woo variations, future things) implements this
 * interface. The rest of the codebase never branches on storage type — it
 * asks the registry for an adapter by slug and uses these methods.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

interface JEDB_Data_Target {

	/**
	 * Stable string identifier the registry uses to look up this target.
	 *
	 * Format: "{kind}::{slug}", e.g. "cct::available_sets_data",
	 * "posts::story_bricks", "posts::product", "posts::product_variation".
	 */
	public function get_slug();

	/**
	 * Human-readable label used in admin UI.
	 */
	public function get_label();

	/**
	 * Coarse grouping for admin UI ("cct" | "cpt" | "woo_product" | "woo_variation").
	 */
	public function get_kind();

	/**
	 * @param mixed $id Storage-native primary key (int post ID, int CCT _ID, …).
	 */
	public function exists( $id );

	/**
	 * Read a record into a flat associative array of fields.
	 *
	 * @param mixed $id
	 * @return array|null  Null when the record doesn't exist.
	 */
	public function get( $id );

	/**
	 * Write field values to an existing record.
	 *
	 * @param mixed $id
	 * @param array $fields  Field name → value map.
	 * @return bool          True on success.
	 */
	public function update( $id, array $fields );

	/**
	 * Create a new record from a field map.
	 *
	 * @param array $fields
	 * @return mixed|null  New record's primary key, or null on failure.
	 */
	public function create( array $fields );

	/**
	 * Schema describing every field this target understands. Used to populate
	 * dropdowns in the admin UI.
	 *
	 * @return array<int,array{name:string,label:string,type:string,group:string,is_meta:bool,meta_key:string|null}>
	 */
	public function get_field_schema();

	/**
	 * Whether this target type can participate in JetEngine relations.
	 */
	public function supports_relations();

	/**
	 * Total count of records of this type. Cheap implementations are encouraged
	 * (e.g. wp_count_posts) — used only for the Targets admin tab.
	 */
	public function count();

	/**
	 * Return up to N records' IDs and a display label, for admin pickers.
	 *
	 * @param array $args  ['per_page' => int, 'page' => int, 'search' => string]
	 * @return array<int,array{id:mixed,label:string}>
	 */
	public function list_records( array $args = array() );
}
