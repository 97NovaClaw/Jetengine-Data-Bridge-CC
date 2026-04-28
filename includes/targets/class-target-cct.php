<?php
/**
 * Target_CCT — read/write JetEngine Custom Content Type items.
 *
 * One instance per CCT slug; the registry creates them at boot.
 * Storage path: JetEngine's CCT manager → row in `wp_jet_cct_{slug}`.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Target_CCT extends JEDB_Target_Abstract {

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

	/* -----------------------------------------------------------------------
	 * CCT manager handle
	 * -------------------------------------------------------------------- */

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

	/* -----------------------------------------------------------------------
	 * Read / write
	 * -------------------------------------------------------------------- */

	public function exists( $id ) {
		return null !== $this->get( $id );
	}

	public function get( $id ) {

		$inst = $this->get_cct_instance();
		if ( ! $inst || ! method_exists( $inst, 'get_items' ) ) {
			return null;
		}

		$items = $inst->get_items( array(
			'where' => array( '_ID' => absint( $id ) ),
			'limit' => 1,
		) );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return null;
		}

		$row = is_object( $items[0] ) ? get_object_vars( $items[0] ) : (array) $items[0];
		return $row;
	}

	public function update( $id, array $fields ) {

		$inst = $this->get_cct_instance();
		if ( ! $inst || ! method_exists( $inst, 'db' ) || ! method_exists( $inst->db, 'update' ) ) {
			$this->log( 'CCT update unavailable', 'error' );
			return false;
		}

		$result = $inst->db->update( $fields, array( '_ID' => absint( $id ) ) );
		return false !== $result;
	}

	public function create( array $fields ) {

		$inst = $this->get_cct_instance();
		if ( ! $inst || ! method_exists( $inst, 'db' ) || ! method_exists( $inst->db, 'insert' ) ) {
			$this->log( 'CCT insert unavailable', 'error' );
			return null;
		}

		$new_id = $inst->db->insert( $fields );
		return $new_id ? absint( $new_id ) : null;
	}

	/* -----------------------------------------------------------------------
	 * Schema, count, listing
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

		foreach ( $this->cct_meta['fields'] as $field ) {

			if ( empty( $field['name'] ) ) {
				continue;
			}

			$out[] = array(
				'name'     => $field['name'],
				'label'    => isset( $field['title'] ) && $field['title'] ? $field['title'] : $field['name'],
				'type'     => isset( $field['type'] ) ? $field['type'] : 'text',
				'group'    => 'fields',
				'is_meta'  => false,
				'meta_key' => null,
				'options'  => isset( $field['options'] ) ? $field['options'] : array(),
			);
		}

		return $out;
	}

	public function count() {

		$inst = $this->get_cct_instance();
		if ( ! $inst ) {
			return 0;
		}

		if ( method_exists( $inst, 'db' ) && method_exists( $inst->db, 'count' ) ) {
			return (int) $inst->db->count();
		}

		if ( method_exists( $inst, 'get_items' ) ) {
			$rows = $inst->get_items( array() );
			return is_array( $rows ) ? count( $rows ) : 0;
		}

		return 0;
	}

	public function list_records( array $args = array() ) {

		$inst = $this->get_cct_instance();
		if ( ! $inst || ! method_exists( $inst, 'get_items' ) ) {
			return array();
		}

		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25;
		$page     = isset( $args['page'] )     ? absint( $args['page'] )     : 1;
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		$rows = $inst->get_items( array(
			'limit'  => $per_page,
			'offset' => $offset,
			'order'  => 'DESC',
		) );

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
}
