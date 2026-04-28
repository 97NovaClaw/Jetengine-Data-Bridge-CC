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

		try {
			$result = $db->update( $fields, array( '_ID' => $id ) );
			return false !== $result;
		} catch ( \Throwable $t ) {
			$this->log( 'CCT db->update threw', 'error', array( 'id' => $id, 'error' => $t->getMessage() ) );
			return false;
		}
	}

	public function create( array $fields ) {

		$db = $this->get_db();
		if ( ! $db || ! method_exists( $db, 'insert' ) ) {
			$this->log( 'CCT insert unavailable — db handle missing', 'error' );
			return null;
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

	public function get_field_schema() {

		$out = array(
			array(
				'name'     => '_ID',
				'label'    => __( 'Item ID', 'je-data-bridge-cc' ),
				'type'     => 'number',
				'group'    => 'system',
				'is_meta'  => false,
				'meta_key' => null,
				'readonly' => true,
			),
		);

		if ( ! $this->cct_meta || empty( $this->cct_meta['fields'] ) ) {
			return $out;
		}

		$seen = array( '_ID' );

		foreach ( $this->cct_meta['fields'] as $field ) {

			$name = isset( $field['name'] ) ? trim( (string) $field['name'] ) : '';
			$type = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : 'text';

			if ( '' === $name ) {
				continue;
			}

			if ( in_array( $type, self::NON_DATA_FIELD_TYPES, true ) ) {
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
			'slug'                  => $this->cct_slug,
			'label'                 => $this->label,
			'table_name'            => $table,
			'table_exists'          => false,
			'item_count_sql'        => 0,
			'item_count_via_db'     => null,
			'db_columns'            => array(),
			'fields_via_get_arg'    => array(),
			'fields_via_get_list'   => array(),
			'schema_after_filter'   => array(),
			'non_data_filtered_out' => array(),
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
					if ( is_array( $rich ) ) {
						foreach ( $rich as $field ) {
							if ( ! is_array( $field ) ) {
								continue;
							}
							$out['fields_via_get_arg'][] = array(
								'name' => isset( $field['name'] ) ? $field['name'] : '',
								'type' => isset( $field['type'] ) ? $field['type'] : '',
							);
						}
					}
				} catch ( \Throwable $t ) {
					$out['fields_via_get_arg'] = array( 'ERROR' => $t->getMessage() );
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

		foreach ( $this->get_field_schema() as $f ) {
			$out['schema_after_filter'][] = array( 'name' => $f['name'], 'type' => $f['type'] );
		}

		foreach ( $out['fields_via_get_arg'] as $raw ) {
			if ( is_array( $raw ) && in_array( strtolower( (string) $raw['type'] ), self::NON_DATA_FIELD_TYPES, true ) ) {
				$out['non_data_filtered_out'][] = $raw;
			}
		}

		return $out;
	}
}
