<?php
/**
 * Relation Config Manager — CRUD wrapper for `wp_jedb_relation_configs`.
 *
 * Stores ONE row per CCT (matching RI's storage model). Each row's
 * `config_json` carries the array of which JE Relation IDs to expose in the
 * picker on that CCT's edit screen, plus per-relation display-field choices
 * and UI preferences.
 *
 * Schema decision (D-13 follow-up, A): the table's `relation_id` and
 * `direction` columns from Phase 0 stay NULL/empty for relation-config rows.
 * They were over-design and will be cleaned up in a future minor schema
 * migration. Vestigial but harmless.
 *
 * IMPORTANT: This config governs which JE Relations the picker UI exposes.
 * It does NOT create JE Relations. Relations themselves live entirely in
 * JetEngine's own admin (JetEngine → Relations).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Relation_Config_Manager {

	/** @var JEDB_Relation_Config_Manager|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'jedb_relation_configs';
	}

	/**
	 * Default config_json shape. Used when creating a new config and as a
	 * defensive merge target when reading malformed rows.
	 *
	 * @return array
	 */
	public static function default_config_json() {
		return array(
			'enabled_relations' => array(),
			'display_fields'    => array(),
			'ui_settings'       => array(
				'show_create_button' => false,
			),
		);
	}

	/**
	 * Build the canonical config_slug for a CCT-level config row.
	 *
	 * @param string $cct_slug
	 * @return string
	 */
	public static function slug_for_cct( $cct_slug ) {
		return 'cct_' . sanitize_key( $cct_slug );
	}

	/**
	 * Decode a row's `config_json` column into an array, merged with defaults.
	 *
	 * @param object|array $row
	 * @return array
	 */
	private function decode_row( $row ) {
		$row = is_object( $row ) ? get_object_vars( $row ) : (array) $row;

		$decoded = array();
		if ( ! empty( $row['config_json'] ) ) {
			$decoded = json_decode( (string) $row['config_json'], true );
			if ( ! is_array( $decoded ) ) {
				$decoded = array();
			}
		}

		$row['config'] = wp_parse_args( $decoded, self::default_config_json() );
		unset( $row['config_json'] );

		return $row;
	}

	/* -----------------------------------------------------------------------
	 * Reads
	 * -------------------------------------------------------------------- */

	/**
	 * @param string $cct_slug
	 * @return array|null
	 */
	public function get_by_cct( $cct_slug ) {

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$this->table()}` WHERE config_slug = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				self::slug_for_cct( $cct_slug )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	public function get_by_id( $id ) {

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$this->table()}` WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * @param array $args  ['enabled' => 0|1|null, 'orderby' => string, 'order' => 'ASC'|'DESC']
	 * @return array<int,array>
	 */
	public function get_all( array $args = array() ) {

		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'enabled' => null,
				'orderby' => 'updated_at',
				'order'   => 'DESC',
			)
		);

		$where = array();
		$values = array();

		if ( null !== $args['enabled'] ) {
			$where[]  = 'enabled = %d';
			$values[] = (int) $args['enabled'];
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$orderby   = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$order_sql = $orderby ? "ORDER BY {$orderby}" : '';

		$query = "SELECT * FROM `{$this->table()}` {$where_sql} {$order_sql}";
		if ( $values ) {
			$query = $wpdb->prepare( $query, $values ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

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

	/* -----------------------------------------------------------------------
	 * Writes (idempotent upsert based on config_slug)
	 * -------------------------------------------------------------------- */

	/**
	 * Insert or update a CCT-level relation config.
	 *
	 * @param string $cct_slug
	 * @param array  $config_data  Merged with default_config_json() on save.
	 * @param array  $args         ['label' => string, 'enabled' => 0|1]
	 * @return int|false  The row id on success, false on failure.
	 */
	public function upsert_for_cct( $cct_slug, array $config_data, array $args = array() ) {

		global $wpdb;

		$cct_slug = sanitize_key( $cct_slug );
		if ( '' === $cct_slug ) {
			return false;
		}

		$args = wp_parse_args(
			$args,
			array(
				'label'   => '',
				'enabled' => 1,
			)
		);

		$config_data = wp_parse_args( $config_data, self::default_config_json() );

		$payload = array(
			'config_slug'   => self::slug_for_cct( $cct_slug ),
			'label'         => sanitize_text_field( (string) $args['label'] ),
			'source_target' => 'cct::' . $cct_slug,
			'relation_id'   => '',
			'direction'     => '',
			'enabled'       => $args['enabled'] ? 1 : 0,
			'config_json'   => wp_json_encode( $config_data ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		$existing = $this->get_by_cct( $cct_slug );

		if ( $existing ) {
			$result = $wpdb->update(
				$this->table(),
				$payload,
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( 'Relation config upsert: update failed', 'error', array( 'cct' => $cct_slug, 'wpdb_error' => $wpdb->last_error ) );
				}
				return false;
			}
			return (int) $existing['id'];
		}

		$payload['created_at'] = current_time( 'mysql', true );
		$result = $wpdb->insert(
			$this->table(),
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Relation config upsert: insert failed', 'error', array( 'cct' => $cct_slug, 'wpdb_error' => $wpdb->last_error ) );
			}
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Toggle a config's `enabled` flag without touching anything else.
	 */
	public function set_enabled( $cct_slug, $enabled ) {

		global $wpdb;

		$result = $wpdb->update(
			$this->table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'config_slug' => self::slug_for_cct( $cct_slug ) ),
			array( '%d', '%s' ),
			array( '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/**
	 * Delete a CCT's config row.
	 */
	public function delete_for_cct( $cct_slug ) {

		global $wpdb;

		$result = $wpdb->delete(
			$this->table(),
			array( 'config_slug' => self::slug_for_cct( $cct_slug ) ),
			array( '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/* -----------------------------------------------------------------------
	 * Convenience
	 * -------------------------------------------------------------------- */

	public function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table()}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
	}

	public function count_enabled() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table()}` WHERE enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
	}
}
