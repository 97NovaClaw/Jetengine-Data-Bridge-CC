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
			// Phase 3.5 reverse-direction opt-in (D-17 default OFF). When ON,
			// the reverse pull engine will create a fresh CCT row in the
			// source target if a post saves and no linked CCT row exists.
			// Defaults to false because the action is destructive (creates
			// data); editors must explicitly opt in per bridge.
			'auto_create_target_when_unlinked'  => false,
			'required_overrides'                => array(
				'add'    => array(),
				'remove' => array(),
			),
			'origin_tag'                        => 'flatten',
		);
	}

	/**
	 * Canonical default shape for one mapping row.
	 */
	public static function default_mapping() {
		return array(
			'source_field'   => '',
			'target_field'   => '',
			'push_transform' => array(
				array( 'name' => 'passthrough', 'args' => array() ),
			),
			'pull_transform' => array(
				array( 'name' => 'passthrough', 'args' => array() ),
			),
			'enabled'        => true,
			'note'           => '',
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
