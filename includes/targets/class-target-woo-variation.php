<?php
/**
 * Target_Woo_Variation — HPOS-safe read/write for product variations.
 *
 * Variations are bridged independently of their parent variable products. The
 * parent product is bridged through JEDB_Target_Woo_Product; this adapter
 * handles the per-variation typed setters and the variation reconciliation
 * engine added in Phase 4b uses it under the hood.
 *
 * Field schema is intentionally smaller than the parent: variations carry
 * pricing, stock, dimensions, downloads, and attribute selections, but not
 * categories/tags/cross-sells/etc.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Target_Woo_Variation extends JEDB_Target_Abstract {

	const POST_TYPE = 'product_variation';

	/** @var array  Slug → typed-setter method name. */
	protected static $typed_setters = array(
		'description'         => 'set_description',
		'status'              => 'set_status',
		'sku'                 => 'set_sku',
		'regular_price'       => 'set_regular_price',
		'sale_price'          => 'set_sale_price',
		'date_on_sale_from'   => 'set_date_on_sale_from',
		'date_on_sale_to'     => 'set_date_on_sale_to',
		'manage_stock'        => 'set_manage_stock',
		'stock_quantity'      => 'set_stock_quantity',
		'stock_status'        => 'set_stock_status',
		'backorders'          => 'set_backorders',
		'weight'              => 'set_weight',
		'length'              => 'set_length',
		'width'               => 'set_width',
		'height'              => 'set_height',
		'tax_status'          => 'set_tax_status',
		'tax_class'           => 'set_tax_class',
		'shipping_class_id'   => 'set_shipping_class_id',
		'menu_order'          => 'set_menu_order',
		'virtual'             => 'set_virtual',
		'downloadable'        => 'set_downloadable',
		'image_id'            => 'set_image_id',
		'parent_id'           => 'set_parent_id',
		'attributes'          => 'set_attributes',
		'downloads'           => 'set_downloads',
	);

	public function __construct() {
		$this->slug  = 'posts::' . self::POST_TYPE;
		$this->kind  = 'woo_variation';
		$this->label = __( 'WooCommerce Product Variations', 'je-data-bridge-cc' );
	}

	public function supports_relations() {
		return true;
	}

	/* -----------------------------------------------------------------------
	 * Read / write
	 * -------------------------------------------------------------------- */

	public function exists( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$variation = wc_get_product( absint( $id ) );
		return $variation && self::POST_TYPE === $variation->get_type();
	}

	public function get( $id ) {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$variation = wc_get_product( absint( $id ) );
		if ( ! $variation || self::POST_TYPE !== $variation->get_type() ) {
			return null;
		}

		$out = $variation->get_data();

		foreach ( $variation->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( ! empty( $data['key'] ) && ! isset( $out[ $data['key'] ] ) ) {
				$out[ $data['key'] ] = $data['value'];
			}
		}

		return $out;
	}

	public function update( $id, array $fields ) {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$variation = wc_get_product( absint( $id ) );
		if ( ! $variation || self::POST_TYPE !== $variation->get_type() ) {
			$this->log( 'Update target variation not found', 'error', array( 'id' => $id ) );
			return false;
		}

		foreach ( $fields as $key => $value ) {

			if ( in_array( $key, array( 'id', 'ID', 'type', 'date_created', 'date_modified' ), true ) ) {
				continue;
			}

			if ( isset( self::$typed_setters[ $key ] ) ) {
				$setter = self::$typed_setters[ $key ];
				if ( method_exists( $variation, $setter ) ) {
					try {
						$variation->{$setter}( $value );
					} catch ( \Exception $e ) {
						$this->log( 'Typed setter threw on variation', 'error', array( 'field' => $key, 'msg' => $e->getMessage() ) );
					}
					continue;
				}
			}

			$variation->update_meta_data( $key, $value );
		}

		try {
			$saved = $variation->save();
		} catch ( \Exception $e ) {
			$this->log( 'WC_Product_Variation::save() threw', 'error', array( 'msg' => $e->getMessage() ) );
			return false;
		}

		return (bool) $saved;
	}

	public function create( array $fields ) {

		if ( ! class_exists( 'WC_Product_Variation' ) ) {
			return null;
		}

		$variation = new WC_Product_Variation();

		foreach ( $fields as $key => $value ) {
			if ( isset( self::$typed_setters[ $key ] ) ) {
				$setter = self::$typed_setters[ $key ];
				if ( method_exists( $variation, $setter ) ) {
					try {
						$variation->{$setter}( $value );
					} catch ( \Exception $e ) {
						$this->log( 'Typed setter threw on variation create', 'error', array( 'field' => $key, 'msg' => $e->getMessage() ) );
					}
					continue;
				}
			}
			$variation->update_meta_data( $key, $value );
		}

		try {
			$new_id = $variation->save();
		} catch ( \Exception $e ) {
			$this->log( 'WC_Product_Variation::save() threw on create', 'error', array( 'msg' => $e->getMessage() ) );
			return null;
		}

		return $new_id ? absint( $new_id ) : null;
	}

	/* -----------------------------------------------------------------------
	 * Schema / count / list
	 * -------------------------------------------------------------------- */

	public function get_field_schema() {

		$out = array(
			array( 'name' => 'id',                  'label' => __( 'Variation ID',   'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'system',    'is_meta' => false, 'meta_key' => null, 'readonly' => true ),
			array( 'name' => 'parent_id',           'label' => __( 'Parent Product', 'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'system',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'description',         'label' => __( 'Description',    'je-data-bridge-cc' ), 'type' => 'textarea', 'group' => 'core',      'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'status',              'label' => __( 'Status',         'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'core',      'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'sku',                 'label' => __( 'SKU',            'je-data-bridge-cc' ), 'type' => 'text',     'group' => 'inventory', 'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'manage_stock',        'label' => __( 'Manage Stock',   'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'inventory', 'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'stock_quantity',      'label' => __( 'Stock Quantity', 'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'inventory', 'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'stock_status',        'label' => __( 'Stock Status',   'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'inventory', 'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'regular_price',       'label' => __( 'Regular Price',  'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'pricing',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'sale_price',          'label' => __( 'Sale Price',     'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'pricing',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'date_on_sale_from',   'label' => __( 'Sale From',      'je-data-bridge-cc' ), 'type' => 'date',     'group' => 'pricing',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'date_on_sale_to',     'label' => __( 'Sale To',        'je-data-bridge-cc' ), 'type' => 'date',     'group' => 'pricing',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'tax_status',          'label' => __( 'Tax Status',     'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'pricing',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'tax_class',           'label' => __( 'Tax Class',      'je-data-bridge-cc' ), 'type' => 'text',     'group' => 'pricing',   'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'weight',              'label' => __( 'Weight',         'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'length',              'label' => __( 'Length',         'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'width',               'label' => __( 'Width',          'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'height',              'label' => __( 'Height',         'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'shipping_class_id',   'label' => __( 'Shipping Class', 'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',  'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'image_id',            'label' => __( 'Image ID',       'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'media',     'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'attributes',          'label' => __( 'Attributes',     'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'attributes','is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'virtual',             'label' => __( 'Virtual',        'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'downloads', 'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'downloadable',        'label' => __( 'Downloadable',   'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'downloads', 'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'downloads',           'label' => __( 'Downloads',      'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'downloads', 'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'menu_order',          'label' => __( 'Menu Order',     'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'linked',    'is_meta' => false, 'meta_key' => null ),
		);

		$discovery = JEDB_Discovery::instance();
		$meta_keys = $discovery->get_meta_whitelist_for( $this->slug );

		foreach ( $meta_keys as $key ) {
			$out[] = array(
				'name'     => $key,
				'label'    => $key,
				'type'     => 'text',
				'group'    => 'meta',
				'is_meta'  => true,
				'meta_key' => $key,
			);
		}

		return $out;
	}

	public function count() {
		return JEDB_Discovery::instance()->get_woo_variation_count();
	}

	/**
	 * A variation MUST belong to a parent product and must declare its
	 * variation attributes — without those, Woo treats it as orphaned.
	 * Per D-15 we surface that in the Phase 3 mandatory-coverage panel.
	 */
	public function get_required_fields() {
		return array( 'parent_id', 'attributes' );
	}

	/**
	 * Variations are rendered natively by Woo's variations panel inside the
	 * variable parent product's edit screen. Every typed-setter field is
	 * thus natively rendered; arbitrary meta is not.
	 */
	public function is_natively_rendered( $field_name ) {

		if ( isset( self::$typed_setters[ $field_name ] ) ) {
			return true;
		}

		return in_array( $field_name, array( 'id', 'date_created', 'date_modified' ), true );
	}

	public function list_records( array $args = array() ) {

		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$per_page  = isset( $args['per_page'] )  ? absint( $args['per_page'] )  : 25;
		$page      = isset( $args['page'] )      ? absint( $args['page'] )      : 1;
		$parent_id = isset( $args['parent_id'] ) ? absint( $args['parent_id'] ) : 0;

		$query_args = array(
			'limit'   => $per_page,
			'page'    => $page,
			'type'    => 'variation',
			'status'  => array( 'publish', 'private' ),
			'orderby' => 'menu_order',
			'order'   => 'ASC',
		);

		if ( $parent_id ) {
			$query_args['parent'] = $parent_id;
		}

		$variations = wc_get_products( $query_args );
		$out        = array();

		foreach ( $variations as $variation ) {

			$attrs = $variation->get_attributes();
			$desc  = '';
			if ( is_array( $attrs ) && ! empty( $attrs ) ) {
				$bits = array();
				foreach ( $attrs as $attr_key => $attr_value ) {
					$bits[] = $attr_value;
				}
				$desc = implode( ' / ', array_filter( $bits ) );
			}

			$out[] = array(
				'id'    => (int) $variation->get_id(),
				'label' => sprintf(
					'#%d %s%s',
					$variation->get_id(),
					$variation->get_sku() ? '[' . $variation->get_sku() . '] ' : '',
					$desc
				),
			);
		}

		return $out;
	}
}
