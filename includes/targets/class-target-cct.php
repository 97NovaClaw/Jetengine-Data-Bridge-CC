<?php
/**
 * Target_CCT — read/write JetEngine Custom Content Type items.
 *
 * One instance per CCT slug; the registry creates them at boot.
 * Storage path: JetEngine's CCT manager → `$cct_instance->db` (public property)
 * → row in `wp_jet_cct_{slug}`. The `db` API exposes `get_item($id)`,
 * `query($args, $limit, $offset, $order)`, `insert($data)`,
 * `update($data, $where)`, and `table()`.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Target_CCT extends JEDB_Target_Abstract {

	/**
	 * Field types that are visual organizers, not real data fields.
	 * They appear in the JE config but don't have a DB column or value.
	 */
	const NON_DATA_FIELD_TYPES = array(
		'tab', 'section', 'section_separator', 'heading', 'group_separator',
		'group_break', 'wysiwyg_separator',
	);

	/** @var string  Bare CCT slug (without "cct::" prefix). */
	protected $cct_slug = '';

	/** @var array|null  Cached CCT meta from Discovery. */
	protected $cct_meta = null;

	/** @var array<int,string>|null  Cached column list from SHOW COLUMNS. */
	protected $db_columns_cache = null;

	public function __construct( $cct_slug ) {
		$this->cct_slug = $cct_slug;
		$this->slug     = 'cct::' . $cct_slug;
		$this->kind     = 'cct';

		$cct = JEDB_Discovery::instance()->get_cct( $cct_slug );
		if ( $cct ) {
			$this->cct_meta = $cct;
			$this->label    = $cct['name'] . ' (CCT)';
		} else {
			$this->label = $cct_slug . ' (CCT — not found)';
		}
	}

	public function supports_relations() {
		return true;
	}

	public function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jet_cct_' . $this->cct_slug;
	}

	/* -----------------------------------------------------------------------
	 * CCT manager + db handle
	 * -------------------------------------------------------------------- */

	/**
	 * @return object|null  JetEngine CCT factory instance.
	 */
	private function get_cct_instance() {

		if ( ! class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
			return null;
		}

		$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();
		if ( ! $module || ! isset( $module->manager ) ) {
			return null;
		}

		$inst = $module->manager->get_content_types( $this->cct_slug );
		return is_object( $inst ) ? $inst : null;
	}

	/**
	 * Return the CCT instance's DB handle, or null if it isn't usable.
	 * NOTE: `db` is a PUBLIC PROPERTY on the JE CCT factory — not a method.
	 * (Earlier versions of this adapter used method_exists() and silently
	 * always failed.)
	 *
	 * @return object|null
	 */
	private function get_db() {
		$inst = $this->get_cct_instance();
		if ( ! $inst ) {
			return null;
		}
		if ( ! isset( $inst->db ) || ! is_object( $inst->db ) ) {
			return null;
		}
		return $inst->db;
	}

	/* -----------------------------------------------------------------------
	 * Read / write — uses the JE db API with direct-SQL fallback for count
	 * -------------------------------------------------------------------- */

	public function exists( $id ) {
		return null !== $this->get( $id );
	}

	public function get( $id ) {

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$db = $this->get_db();
		if ( $db ) {

			if ( method_exists( $db, 'get_item' ) ) {
				try {
					$item = $db->get_item( $id );
					if ( ! empty( $item ) ) {
						return is_object( $item ) ? get_object_vars( $item ) : (array) $item;
					}
				} catch ( \Throwable $t ) {
					$this->log( 'CCT db->get_item threw', 'warning', array( 'id' => $id, 'error' => $t->getMessage() ) );
				}
			}

			if ( method_exists( $db, 'query' ) ) {
				try {
					$rows = $db->query( array( '_ID' => $id ), 1 );
					if ( ! empty( $rows ) ) {
						$row = is_array( $rows ) ? $rows[0] : $rows;
						return is_object( $row ) ? get_object_vars( $row ) : (array) $row;
					}
				} catch ( \Throwable $t ) {
					$this->log( 'CCT db->query threw', 'warning', array( 'id' => $id, 'error' => $t->getMessage() ) );
				}
			}
		}

		global $wpdb;
		$table = $this->get_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE _ID = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return $row ?: null;
	}

	public function update( $id, array $fields ) {

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$db = $this->get_db();
		if ( ! $db || ! method_exists( $db, 'update' ) ) {
			$this->log( 'CCT update unavailable — db handle missing', 'error' );
			return false;
		}

		$readonly_names = $this->get_readonly_field_names();
		$blocked        = array_intersect_key( $fields, array_flip( $readonly_names ) );
		if ( ! empty( $blocked ) ) {
			$this->log(
				'CCT update blocked write to readonly system fields — silently dropped',
				'warning',
				array(
					'id'             => $id,
					'blocked_fields' => array_keys( $blocked ),
				)
			);
			$fields = array_diff_key( $fields, array_flip( $readonly_names ) );
		}

		if ( empty( $fields ) ) {
			$this->log( 'CCT update: nothing to write after stripping readonly fields', 'debug', array( 'id' => $id ) );
			return false;
		}

		try {
			$result = $db->update( $fields, array( '_ID' => $id ) );
			return false !== $result;
		} catch ( \Throwable $t ) {
			$this->log( 'CCT db->update threw', 'error', array( 'id' => $id, 'error' => $t->getMessage() ) );
			return false;
		}
	}

	/**
	 * @return array<int,string>  Names of every field in the schema marked
	 *                            readonly (system fields, _ID, etc.).
	 */
	protected function get_readonly_field_names() {
		$names = array();
		foreach ( $this->get_field_schema() as $field ) {
			if ( ! empty( $field['readonly'] ) ) {
				$names[] = $field['name'];
			}
		}
		return $names;
	}

	public function create( array $fields ) {

		$db = $this->get_db();
		if ( ! $db || ! method_exists( $db, 'insert' ) ) {
			$this->log( 'CCT insert unavailable — db handle missing', 'error' );
			return null;
		}

		$readonly_names = $this->get_readonly_field_names();
		$blocked        = array_intersect_key( $fields, array_flip( $readonly_names ) );
		if ( ! empty( $blocked ) ) {
			$this->log(
				'CCT insert blocked write to readonly system fields — silently dropped',
				'warning',
				array( 'blocked_fields' => array_keys( $blocked ) )
			);
			$fields = array_diff_key( $fields, array_flip( $readonly_names ) );
		}

		try {
			$new_id = $db->insert( $fields );
			return $new_id ? absint( $new_id ) : null;
		} catch ( \Throwable $t ) {
			$this->log( 'CCT db->insert threw', 'error', array( 'error' => $t->getMessage() ) );
			return null;
		}
	}

	/* -----------------------------------------------------------------------
	 * Schema / count / list
	 * -------------------------------------------------------------------- */

	/**
	 * Schema layout:
	 *   1. _ID (system, readonly)
	 *   2. JE-managed system columns that exist on this CCT, each readonly:
	 *        - cct_status, cct_created, cct_modified, cct_author_id always
	 *        - cct_single_post_id only when "Has Single Page" is enabled
	 *          (i.e. the column physically exists in the CCT table)
	 *   3. User fields from cct_meta (already stripped of system columns by
	 *      Discovery::normalize_field_array).
	 *
	 * Marked-readonly fields cannot be written by update() — they're available
	 * for read / PULL / display only. cct_single_post_id additionally carries
	 * a `jedb_role => 'native_single_page_link'` marker so the Phase 4 Bridge
	 * meta box can detect "this CCT has Has Single Page enabled" and offer
	 * the native single-page link as the bridge target instead of (or in
	 * addition to) the plugin's own _jedb_bridge_* meta on the product.
	 */
	public function get_field_schema() {

		$out = array();

		$out[] = array(
			'name'     => '_ID',
			'label'    => __( 'Item ID', 'je-data-bridge-cc' ),
			'type'     => 'number',
			'group'    => 'system',
			'is_meta'  => false,
			'meta_key' => null,
			'readonly' => true,
		);

		$db_columns         = $this->get_db_columns();
		$has_single_page    = in_array( 'cct_single_post_id', $db_columns, true );

		$system_field_specs = array(
			'cct_status' => array(
				'label' => __( 'Status (system)', 'je-data-bridge-cc' ),
				'type'  => 'text',
				'role'  => 'jet_status',
			),
			'cct_created' => array(
				'label' => __( 'Created (system)', 'je-data-bridge-cc' ),
				'type'  => 'datetime',
				'role'  => 'jet_created_at',
			),
			'cct_modified' => array(
				'label' => __( 'Last Modified (system)', 'je-data-bridge-cc' ),
				'type'  => 'datetime',
				'role'  => 'jet_modified_at',
			),
			'cct_author_id' => array(
				'label' => __( 'Author ID (system)', 'je-data-bridge-cc' ),
				'type'  => 'number',
				'role'  => 'jet_author',
			),
		);

		foreach ( $system_field_specs as $name => $spec ) {

			if ( ! empty( $db_columns ) && ! in_array( $name, $db_columns, true ) ) {
				continue;
			}

			$out[] = array(
				'name'      => $name,
				'label'     => $spec['label'],
				'type'      => $spec['type'],
				'group'     => 'system',
				'is_meta'   => false,
				'meta_key'  => null,
				'readonly'  => true,
				'jedb_role' => $spec['role'],
			);
		}

		if ( $has_single_page ) {
			$out[] = array(
				'name'      => 'cct_single_post_id',
				'label'     => __( 'Linked Single Page Post ID (system)', 'je-data-bridge-cc' ),
				'type'      => 'number',
				'group'     => 'system',
				'is_meta'   => false,
				'meta_key'  => null,
				'readonly'  => true,
				'jedb_role' => 'native_single_page_link',
			);
		}

		if ( ! $this->cct_meta || empty( $this->cct_meta['fields'] ) ) {
			return $out;
		}

		$seen   = array( '_ID' );
		$system = JEDB_Discovery::CCT_SYSTEM_COLUMN_NAMES;

		foreach ( $out as $existing ) {
			$seen[] = $existing['name'];
		}

		foreach ( $this->cct_meta['fields'] as $field ) {

			$name = isset( $field['name'] ) ? trim( (string) $field['name'] ) : '';
			$type = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : 'text';

			if ( '' === $name ) {
				continue;
			}

			if ( in_array( $type, self::NON_DATA_FIELD_TYPES, true ) ) {
				continue;
			}

			if ( in_array( $name, $system, true ) ) {
				continue;
			}

			if ( in_array( $name, $seen, true ) ) {
				continue;
			}
			$seen[] = $name;

			$out[] = array(
				'name'     => $name,
				'label'    => isset( $field['title'] ) && $field['title'] ? $field['title'] : $name,
				'type'     => $type,
				'group'    => 'fields',
				'is_meta'  => false,
				'meta_key' => null,
				'options'  => isset( $field['options'] ) ? $field['options'] : array(),
			);
		}

		return $out;
	}

	/**
	 * Cached `SHOW COLUMNS` for this CCT's table. Used to decide which JE
	 * system fields actually exist on this CCT (cct_single_post_id only
	 * appears when Has Single Page is enabled).
	 *
	 * Returns an empty array if the table doesn't exist or the query fails;
	 * downstream code treats empty as "unknown — include all standard system
	 * fields and skip the conditional ones".
	 *
	 * @return array<int,string>
	 */
	public function get_db_columns() {

		if ( null !== $this->db_columns_cache ) {
			return $this->db_columns_cache;
		}

		global $wpdb;
		$table = $this->get_table_name();

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			$this->db_columns_cache = array();
			return $this->db_columns_cache;
		}

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $rows ) ) {
			$this->db_columns_cache = array();
			return $this->db_columns_cache;
		}

		$this->db_columns_cache = wp_list_pluck( $rows, 'Field' );
		return $this->db_columns_cache;
	}

	/**
	 * Cheap row count: prefers the JE db handle's built-in if available,
	 * falls back to direct SQL `SELECT COUNT(*)` on the `wp_jet_cct_{slug}`
	 * table. Returns 0 (not -1, not null) on any failure.
	 */
	public function count() {

		global $wpdb;
		$table = $this->get_table_name();

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			return 0;
		}

		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		return null === $count ? 0 : (int) $count;
	}

	public function list_records( array $args = array() ) {

		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25;
		$page     = isset( $args['page'] )     ? absint( $args['page'] )     : 1;
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		$rows = array();

		$db = $this->get_db();
		if ( $db && method_exists( $db, 'query' ) ) {
			try {
				$rows = $db->query( array(), $per_page, $offset, array( '_ID' => 'DESC' ) );
				if ( ! is_array( $rows ) ) {
					$rows = array();
				}
			} catch ( \Throwable $t ) {
				$this->log( 'CCT db->query for list threw, falling back to SQL', 'warning', array( 'error' => $t->getMessage() ) );
				$rows = array();
			}
		}

		if ( empty( $rows ) ) {
			global $wpdb;
			$table = $this->get_table_name();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY _ID DESC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
					ARRAY_A
				);
				if ( ! is_array( $rows ) ) {
					$rows = array();
				}
			}
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$schema      = $this->get_field_schema();
		$label_field = '';

		foreach ( $schema as $field ) {
			if ( in_array( $field['name'], array( 'name', 'title', 'set_name', 'mosaic_name', 'label' ), true ) ) {
				$label_field = $field['name'];
				break;
			}
		}

		$out = array();

		foreach ( $rows as $row ) {

			$row_arr = is_object( $row ) ? get_object_vars( $row ) : (array) $row;
			$id      = isset( $row_arr['_ID'] ) ? (int) $row_arr['_ID'] : 0;

			$label = '';
			if ( $label_field && ! empty( $row_arr[ $label_field ] ) ) {
				$label = (string) $row_arr[ $label_field ];
			} else {
				$label = sprintf( '%s #%d', $this->cct_slug, $id );
			}

			$out[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}

		return $out;
	}

	/**
	 * Diagnostic data for a single CCT — used by the Debug tab's CCT
	 * diagnostic. Returns the field config as JE sees it, the live DB
	 * columns, the item count from each source, and any mismatch flags.
	 *
	 * @return array
	 */
	public function diagnose() {

		global $wpdb;
		$table = $this->get_table_name();

		$out = array(
			'slug'                       => $this->cct_slug,
			'label'                      => $this->label,
			'table_name'                 => $table,
			'table_exists'               => false,
			'item_count_sql'             => 0,
			'item_count_via_db'          => null,
			'db_columns'                 => array(),
			'fields_via_get_arg'         => array(),
			'fields_via_get_arg_meta'    => array(),
			'fields_via_args_property'   => array(),
			'fields_via_option'          => array(),
			'fields_via_get_list'        => array(),
			'top_level_args_keys'        => array(),
			'schema_after_filter'        => array(),
			'non_data_filtered_out'      => array(),
			'field_source_used'          => 'none',
			'deep_probe'                 => array(),
		);

		$out['table_exists']    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out['item_count_sql']  = $out['table_exists'] ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		if ( $out['table_exists'] ) {
			$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			if ( is_array( $cols ) ) {
				foreach ( $cols as $c ) {
					$out['db_columns'][] = isset( $c['Field'] ) ? $c['Field'] : '';
				}
			}
		}

		$inst = $this->get_cct_instance();
		if ( $inst ) {

			if ( method_exists( $inst, 'get_arg' ) ) {
				try {
					$rich = $inst->get_arg( 'fields' );
					$out['fields_via_get_arg'] = $this->summarize_field_array( $rich );
				} catch ( \Throwable $t ) {
					$out['fields_via_get_arg'] = array( 'ERROR' => $t->getMessage() );
				}

				try {
					$rich_meta = $inst->get_arg( 'meta_fields' );
					$out['fields_via_get_arg_meta'] = $this->summarize_field_array( $rich_meta );
				} catch ( \Throwable $t ) {
					$out['fields_via_get_arg_meta'] = array( 'ERROR' => $t->getMessage() );
				}
			}

			if ( isset( $inst->args ) && is_array( $inst->args ) ) {
				$out['top_level_args_keys'] = array_keys( $inst->args );
				foreach ( array( 'meta_fields', 'fields' ) as $key ) {
					if ( isset( $inst->args[ $key ] ) && is_array( $inst->args[ $key ] ) ) {
						$out['fields_via_args_property'][ $key ] = $this->summarize_field_array( $inst->args[ $key ] );
					}
				}
			}

			$stored = get_option( 'jet_engine_active_content_types' );
			if ( is_array( $stored ) ) {
				foreach ( $stored as $cct_config ) {
					if ( ! is_array( $cct_config ) ) {
						continue;
					}
					$config_slug = isset( $cct_config['slug'] ) ? $cct_config['slug'] : '';
					if ( $config_slug !== $this->cct_slug ) {
						continue;
					}
					foreach ( array( 'meta_fields', 'fields' ) as $key ) {
						if ( isset( $cct_config[ $key ] ) && is_array( $cct_config[ $key ] ) ) {
							$out['fields_via_option'][ $key ] = $this->summarize_field_array( $cct_config[ $key ] );
						}
					}
					break;
				}
			}

			if ( method_exists( $inst, 'get_fields_list' ) ) {
				try {
					$slim = $inst->get_fields_list();
					if ( is_array( $slim ) ) {
						$out['fields_via_get_list'] = array_keys( $slim );
					}
				} catch ( \Throwable $t ) {
					$out['fields_via_get_list'] = array( 'ERROR' => $t->getMessage() );
				}
			}

			if ( isset( $inst->db ) && is_object( $inst->db ) && method_exists( $inst->db, 'query' ) ) {
				try {
					$rows = $inst->db->query( array(), 0, 0 );
					$out['item_count_via_db'] = is_array( $rows ) ? count( $rows ) : ( null === $rows ? 'NULL' : 'NOT-ARRAY (' . gettype( $rows ) . ')' );
				} catch ( \Throwable $t ) {
					$out['item_count_via_db'] = 'ERROR: ' . $t->getMessage();
				}
			}
		}

		if ( $this->cct_meta && ! empty( $this->cct_meta['fields'] ) ) {
			$first = $this->cct_meta['fields'][0];
			$out['field_source_used'] = isset( $first['source'] ) ? $first['source'] : 'unknown';
		}

		if ( $inst ) {
			try {
				$out['deep_probe'] = JEDB_Discovery::instance()->deep_probe_je_field_storage( $inst );
			} catch ( \Throwable $t ) {
				$out['deep_probe'] = array( 'ERROR' => $t->getMessage() );
			}
		}

		foreach ( $this->get_field_schema() as $f ) {
			$out['schema_after_filter'][] = array( 'name' => $f['name'], 'type' => $f['type'] );
		}

		foreach ( $out['fields_via_get_arg'] as $raw ) {
			if ( is_array( $raw ) && isset( $raw['type'] ) && in_array( strtolower( (string) $raw['type'] ), self::NON_DATA_FIELD_TYPES, true ) ) {
				$out['non_data_filtered_out'][] = $raw;
			}
		}

		return $out;
	}

	/**
	 * Compress a raw JE fields array into [{name, type}, ...] for the
	 * diagnostic UI. Returns an empty array on null / non-array input so
	 * the diagnostic can render "(0)" cleanly.
	 */
	private function summarize_field_array( $raw ) {

		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$out[] = array(
				'name' => isset( $field['name'] ) ? $field['name'] : '',
				'type' => isset( $field['type'] ) ? $field['type'] : '',
			);
		}
		return $out;
	}
}
