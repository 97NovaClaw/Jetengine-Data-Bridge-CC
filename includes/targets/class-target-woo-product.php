<?php
/**
 * Target_Woo_Product — HPOS-safe read/write for WooCommerce parent products.
 *
 * Every read goes through wc_get_product(); every write goes through the
 * matching typed setter on WC_Product (->set_name, ->set_regular_price, …)
 * with a fallback to ->update_meta_data() for unknown keys, then ->save().
 * This is what keeps the wc_product_meta_lookup cache table in sync; direct
 * update_post_meta() calls are NEVER used for product fields.
 *
 * Variations live in JEDB_Target_Woo_Variation. Variable parents are still
 * managed here — the variation-aware fields show up in get_field_schema()
 * but the actual variation reconciliation logic is Phase 4b.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Target_Woo_Product extends JEDB_Target_Abstract {

	const POST_TYPE = 'product';

	/** @var array  Slug → typed-setter method-name map. */
	protected static $typed_setters = array(
		'name'                => 'set_name',
		'slug'                => 'set_slug',
		'description'         => 'set_description',
		'short_description'   => 'set_short_description',
		'status'              => 'set_status',
		'featured'            => 'set_featured',
		'catalog_visibility'  => 'set_catalog_visibility',
		'sku'                 => 'set_sku',
		'regular_price'       => 'set_regular_price',
		'sale_price'          => 'set_sale_price',
		'date_on_sale_from'   => 'set_date_on_sale_from',
		'date_on_sale_to'     => 'set_date_on_sale_to',
		'manage_stock'        => 'set_manage_stock',
		'stock_quantity'      => 'set_stock_quantity',
		'stock_status'        => 'set_stock_status',
		'backorders'          => 'set_backorders',
		'low_stock_amount'    => 'set_low_stock_amount',
		'sold_individually'   => 'set_sold_individually',
		'weight'              => 'set_weight',
		'length'              => 'set_length',
		'width'               => 'set_width',
		'height'              => 'set_height',
		'tax_status'          => 'set_tax_status',
		'tax_class'           => 'set_tax_class',
		'shipping_class_id'   => 'set_shipping_class_id',
		'reviews_allowed'     => 'set_reviews_allowed',
		'purchase_note'       => 'set_purchase_note',
		'menu_order'          => 'set_menu_order',
		'virtual'             => 'set_virtual',
		'downloadable'        => 'set_downloadable',
		'image_id'            => 'set_image_id',
		'gallery_image_ids'   => 'set_gallery_image_ids',
		'category_ids'        => 'set_category_ids',
		'tag_ids'             => 'set_tag_ids',
		'cross_sell_ids'      => 'set_cross_sell_ids',
		'upsell_ids'          => 'set_upsell_ids',
		'downloads'           => 'set_downloads',
	);

	public function __construct() {
		$this->slug  = 'posts::' . self::POST_TYPE;
		$this->kind  = 'woo_product';
		$this->label = __( 'WooCommerce Products', 'je-data-bridge-cc' );
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
		$product = wc_get_product( absint( $id ) );
		return $product && self::POST_TYPE === $product->get_type() ? true : ( $product ? true : false );
	}

	public function get( $id ) {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( absint( $id ) );
		if ( ! $product ) {
			return null;
		}

		$out = $product->get_data();

		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( ! empty( $data['key'] ) && ! isset( $out[ $data['key'] ] ) ) {
				$out[ $data['key'] ] = $data['value'];
			}
		}

		$out['type'] = $product->get_type();

		return $out;
	}

	public function update( $id, array $fields ) {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( absint( $id ) );
		if ( ! $product ) {
			$this->log( 'Update target product not found', 'error', array( 'product_id' => $id ) );
			return false;
		}

		foreach ( $fields as $key => $value ) {

			if ( in_array( $key, array( 'id', 'ID', 'type', 'date_created', 'date_modified' ), true ) ) {
				continue;
			}

			if ( isset( self::$typed_setters[ $key ] ) ) {
				$setter = self::$typed_setters[ $key ];
				if ( method_exists( $product, $setter ) ) {
					try {
						$product->{$setter}( $value );
					} catch ( \Exception $e ) {
						$this->log( 'Typed setter threw', 'error', array( 'field' => $key, 'msg' => $e->getMessage() ) );
					}
					continue;
				}
			}

			$product->update_meta_data( $key, $value );
		}

		try {
			$saved = $product->save();
		} catch ( \Exception $e ) {
			$this->log( 'WC_Product::save() threw', 'error', array( 'msg' => $e->getMessage() ) );
			return false;
		}

		return (bool) $saved;
	}

	public function create( array $fields ) {

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return null;
		}

		$type = isset( $fields['type'] ) ? (string) $fields['type'] : 'simple';
		unset( $fields['type'] );

		$class_map = array(
			'simple'   => 'WC_Product_Simple',
			'variable' => 'WC_Product_Variable',
			'grouped'  => 'WC_Product_Grouped',
			'external' => 'WC_Product_External',
		);

		$class = isset( $class_map[ $type ] ) ? $class_map[ $type ] : 'WC_Product_Simple';
		if ( ! class_exists( $class ) ) {
			$class = 'WC_Product_Simple';
		}

		$product = new $class();

		foreach ( $fields as $key => $value ) {
			if ( isset( self::$typed_setters[ $key ] ) ) {
				$setter = self::$typed_setters[ $key ];
				if ( method_exists( $product, $setter ) ) {
					try {
						$product->{$setter}( $value );
					} catch ( \Exception $e ) {
						$this->log( 'Typed setter threw on create', 'error', array( 'field' => $key, 'msg' => $e->getMessage() ) );
					}
					continue;
				}
			}
			$product->update_meta_data( $key, $value );
		}

		try {
			$new_id = $product->save();
		} catch ( \Exception $e ) {
			$this->log( 'WC_Product::save() threw on create', 'error', array( 'msg' => $e->getMessage() ) );
			return null;
		}

		return $new_id ? absint( $new_id ) : null;
	}

	/* -----------------------------------------------------------------------
	 * Schema / count / list
	 * -------------------------------------------------------------------- */

	public function get_field_schema() {

		$out = array(
			array( 'name' => 'id',                  'label' => __( 'Product ID',          'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'system',     'is_meta' => false, 'meta_key' => null, 'readonly' => true ),
			array( 'name' => 'name',                'label' => __( 'Name',                'je-data-bridge-cc' ), 'type' => 'text',     'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'slug',                'label' => __( 'Slug',                'je-data-bridge-cc' ), 'type' => 'text',     'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'description',         'label' => __( 'Description',         'je-data-bridge-cc' ), 'type' => 'wysiwyg',  'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'short_description',   'label' => __( 'Short Description',   'je-data-bridge-cc' ), 'type' => 'textarea', 'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'status',              'label' => __( 'Status',              'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'type',                'label' => __( 'Product Type',        'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'featured',            'label' => __( 'Featured',            'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'core',       'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'catalog_visibility',  'label' => __( 'Catalog Visibility',  'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'core',       'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'sku',                 'label' => __( 'SKU',                 'je-data-bridge-cc' ), 'type' => 'text',     'group' => 'inventory',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'manage_stock',        'label' => __( 'Manage Stock',        'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'inventory',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'stock_quantity',      'label' => __( 'Stock Quantity',      'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'inventory',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'stock_status',        'label' => __( 'Stock Status',        'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'inventory',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'sold_individually',   'label' => __( 'Sold Individually',   'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'inventory',  'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'regular_price',       'label' => __( 'Regular Price',       'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'pricing',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'sale_price',          'label' => __( 'Sale Price',          'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'pricing',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'date_on_sale_from',   'label' => __( 'Sale From',           'je-data-bridge-cc' ), 'type' => 'date',     'group' => 'pricing',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'date_on_sale_to',     'label' => __( 'Sale To',             'je-data-bridge-cc' ), 'type' => 'date',     'group' => 'pricing',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'tax_status',          'label' => __( 'Tax Status',          'je-data-bridge-cc' ), 'type' => 'select',   'group' => 'pricing',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'tax_class',           'label' => __( 'Tax Class',           'je-data-bridge-cc' ), 'type' => 'text',     'group' => 'pricing',    'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'weight',              'label' => __( 'Weight',              'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'length',              'label' => __( 'Length',              'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'width',               'label' => __( 'Width',               'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'height',              'label' => __( 'Height',              'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'shipping_class_id',   'label' => __( 'Shipping Class ID',   'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'shipping',   'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'image_id',            'label' => __( 'Featured Image ID',   'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'media',      'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'gallery_image_ids',   'label' => __( 'Gallery Image IDs',   'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'media',      'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'category_ids',        'label' => __( 'Category IDs',        'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'taxonomy',   'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'tag_ids',             'label' => __( 'Tag IDs',             'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'taxonomy',   'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'virtual',             'label' => __( 'Virtual',             'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'downloads',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'downloadable',        'label' => __( 'Downloadable',        'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'downloads',  'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'downloads',           'label' => __( 'Downloads',           'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'downloads',  'is_meta' => false, 'meta_key' => null ),

			array( 'name' => 'cross_sell_ids',      'label' => __( 'Cross-sell IDs',      'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'linked',     'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'upsell_ids',          'label' => __( 'Upsell IDs',          'je-data-bridge-cc' ), 'type' => 'array',    'group' => 'linked',     'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'menu_order',          'label' => __( 'Menu Order',          'je-data-bridge-cc' ), 'type' => 'number',   'group' => 'linked',     'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'reviews_allowed',     'label' => __( 'Reviews Allowed',     'je-data-bridge-cc' ), 'type' => 'checkbox', 'group' => 'linked',     'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'purchase_note',       'label' => __( 'Purchase Note',       'je-data-bridge-cc' ), 'type' => 'textarea', 'group' => 'linked',     'is_meta' => false, 'meta_key' => null ),
		);

		$discovery = JEDB_Discovery::instance();
		$meta_keys = $discovery->get_meta_whitelist_for( $this->slug );

		if ( empty( $meta_keys ) ) {
			$meta_keys = $discovery->sample_meta_keys_for_post_type( self::POST_TYPE );
		}

		foreach ( $meta_keys as $key ) {
			if ( 0 === strpos( $key, '_' ) && in_array( $key, array(
				'_sku', '_price', '_regular_price', '_sale_price', '_stock', '_stock_status',
				'_manage_stock', '_weight', '_length', '_width', '_height', '_thumbnail_id',
				'_product_image_gallery', '_visibility', '_featured', '_tax_status', '_tax_class',
			), true ) ) {
				continue;
			}

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
		$counts = wp_count_posts( self::POST_TYPE );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Per D-15: WooCommerce parent products require `name` and `status` to
	 * be a usable product on the storefront. SKU is recommended but not
	 * truly required (Woo allows empty SKU). Pricing is type-specific (a
	 * variable parent has no price of its own; the variations carry it),
	 * so we don't list it here — bridge configs can opt in via
	 * `required_overrides.add`.
	 */
	public function get_required_fields() {
		return array( 'name', 'status' );
	}

	/**
	 * Every typed-setter field maps 1:1 to a native input on the WC product
	 * edit screen, so the bridge meta box should NOT re-render those.
	 * Standard taxonomies (`category_ids`, `tag_ids`) are also natively
	 * rendered via the Categories / Tags meta boxes.
	 *
	 * Anything else (custom meta, third-party plugin fields) returns false
	 * → Phase 4's Bridge meta box will render an input for it.
	 */
	public function is_natively_rendered( $field_name ) {

		if ( isset( self::$typed_setters[ $field_name ] ) ) {
			return true;
		}

		$natively_rendered_extra = array(
			'id', 'date_created', 'date_modified', 'parent_id',
		);

		return in_array( $field_name, $natively_rendered_extra, true );
	}

	/**
	 * List candidate products for the picker. Uses WP_Query directly (NOT
	 * `wc_get_products()`) per L-017 — `wc_get_products()` filters by
	 * `_visibility` meta and the `wc_product_meta_lookup` table, both of
	 * which are populated only by `WC_Product->save()`. Posts created via
	 * raw `wp_insert_post()` (e.g., JE Relations' auto-create) are
	 * therefore invisible to `wc_get_products()` until they've been saved
	 * through the WC API once.
	 *
	 * For picker / discovery use cases we want the COMPLETE list of
	 * products regardless of WC's internal visibility flags, so WP_Query
	 * is the right tool. We then load each match through `wc_get_product()`
	 * to format the label with the SKU when available; if WC can't load a
	 * particular ID, we fall back to the post title.
	 */
	public function list_records( array $args = array() ) {

		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25;
		$page     = isset( $args['page'] )     ? absint( $args['page'] )     : 1;
		$search   = isset( $args['search'] )   ? (string) $args['search']    : '';

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );
		$out   = array();

		if ( empty( $query->posts ) ) {
			return $out;
		}

		foreach ( $query->posts as $post_id ) {

			$post_id = (int) $post_id;
			$label   = '';
			$sku     = '';

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$label = (string) $product->get_name();
					$sku   = (string) $product->get_sku();
				}
			}

			if ( '' === $label ) {
				$post  = get_post( $post_id );
				$label = $post ? (string) $post->post_title : sprintf( 'Product #%d', $post_id );
			}

			$out[] = array(
				'id'    => $post_id,
				'label' => $sku ? $label . ' [' . $sku . ']' : $label,
			);
		}

		return $out;
	}
}
