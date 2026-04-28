<?php
/**
 * Discovery — single source of truth for "what data lives on this site".
 *
 * Merges and generalizes the discovery classes from Jet Engine Relation Injector
 * (CCTs, Relations, recursive grandparent/grandchild traversal) and PAC Vehicle
 * Data Manager (CCT field schemas), and adds the new bits this plugin needs:
 * public CPTs, WooCommerce product types, variations, taxonomies, and per-target
 * meta-key whitelisting.
 *
 * Caches results to a transient (5 min TTL) plus an in-memory copy. Flush via
 * flush_cache() — exposed as a "Refresh discovery cache" button in the
 * Targets tab.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Discovery {

	const TRANSIENT_KEY = 'jedb_discovery_cache_v1';
	const TRANSIENT_TTL = 300; // 5 minutes

	/** @var JEDB_Discovery|null */
	private static $instance = null;

	/** @var array  Per-request memoization: { 'ccts' => [...], 'relations' => [...], ... } */
	private $memo = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* -----------------------------------------------------------------------
	 * Cache management
	 * -------------------------------------------------------------------- */

	public function flush_cache() {
		$this->memo = array();
		delete_transient( self::TRANSIENT_KEY );
	}

	private function memo_get( $key ) {

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$transient = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $transient ) && array_key_exists( $key, $transient ) ) {
			$this->memo[ $key ] = $transient[ $key ];
			return $transient[ $key ];
		}

		return null;
	}

	private function memo_set( $key, $value ) {

		$this->memo[ $key ] = $value;

		$transient = get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $transient ) ) {
			$transient = array();
		}
		$transient[ $key ] = $value;
		set_transient( self::TRANSIENT_KEY, $transient, self::TRANSIENT_TTL );
	}

	/* -----------------------------------------------------------------------
	 * CCT discovery
	 * -------------------------------------------------------------------- */

	/**
	 * @return array<int,array{slug:string,name:string,singular_name:string,fields:array,type_id:int|null}>
	 */
	public function get_all_ccts() {

		$cached = $this->memo_get( 'ccts' );
		if ( null !== $cached ) {
			return $cached;
		}

		if ( ! class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
			jedb_log( 'CCT module not found', 'warning' );
			return $this->memo_set( 'ccts', array() );
		}

		$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();

		if ( ! $module || ! isset( $module->manager ) ) {
			jedb_log( 'CCT manager not available', 'warning' );
			return $this->memo_set( 'ccts', array() );
		}

		$raw = $module->manager->get_content_types();

		if ( empty( $raw ) ) {
			return $this->memo_set( 'ccts', array() );
		}

		$ccts = array();

		foreach ( $raw as $slug => $cct_instance ) {

			if ( ! is_object( $cct_instance ) ) {
				continue;
			}

			$ccts[] = array(
				'slug'          => $slug,
				'name'          => $cct_instance->get_arg( 'name' ) ?: $slug,
				'singular_name' => $cct_instance->get_arg( 'name' ) ?: $slug,
				'fields'        => $this->get_cct_fields_from_instance( $cct_instance ),
				'type_id'       => property_exists( $cct_instance, 'type_id' ) ? $cct_instance->type_id : null,
			);
		}

		return $this->memo_set( 'ccts', $ccts );
	}

	/**
	 * @param string $cct_slug
	 * @return array|null
	 */
	public function get_cct( $cct_slug ) {

		foreach ( $this->get_all_ccts() as $cct ) {
			if ( $cct['slug'] === $cct_slug ) {
				return $cct;
			}
		}

		return null;
	}

	/**
	 * Resolve CCT field schema. Prefers the rich `fields` arg (with type info);
	 * falls back to the slim `get_fields_list()` map when only labels are exposed.
	 *
	 * @return array<int,array{name:string,title:string,type:string,options:array}>
	 */
	private function get_cct_fields_from_instance( $cct_instance ) {

		$fields = array();

		$rich = $cct_instance->get_arg( 'fields' );

		if ( ! empty( $rich ) && is_array( $rich ) ) {
			foreach ( $rich as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$fields[] = array(
					'name'    => isset( $field['name'] ) ? $field['name'] : '',
					'title'   => isset( $field['title'] ) ? $field['title'] : ( isset( $field['name'] ) ? $field['name'] : '' ),
					'type'    => isset( $field['type'] ) ? $field['type'] : 'text',
					'options' => isset( $field['options'] ) ? $field['options'] : array(),
				);
			}
			return $fields;
		}

		if ( method_exists( $cct_instance, 'get_fields_list' ) ) {
			$slim = $cct_instance->get_fields_list();
			if ( ! empty( $slim ) ) {
				foreach ( $slim as $name => $title ) {
					$fields[] = array(
						'name'    => $name,
						'title'   => $title,
						'type'    => 'text',
						'options' => array(),
					);
				}
			}
		}

		return $fields;
	}

	/* -----------------------------------------------------------------------
	 * Relation discovery
	 * -------------------------------------------------------------------- */

	public function get_all_relations() {

		$cached = $this->memo_get( 'relations' );
		if ( null !== $cached ) {
			return $cached;
		}

		if ( ! function_exists( 'jet_engine' ) || ! jet_engine()->relations ) {
			static $logged = false;
			if ( ! $logged ) {
				jedb_log( 'JetEngine Relations not loaded yet (normal during early init)', 'debug' );
				$logged = true;
			}
			return array();
		}

		$raw = jet_engine()->relations->get_active_relations();

		if ( empty( $raw ) ) {
			return $this->memo_set( 'relations', array() );
		}

		$relations = array();

		foreach ( $raw as $relation_id => $relation_obj ) {

			if ( ! is_object( $relation_obj ) || ! method_exists( $relation_obj, 'get_args' ) ) {
				continue;
			}

			$args = $relation_obj->get_args();

			$name = '';
			if ( ! empty( $args['name'] ) ) {
				$name = $args['name'];
			} elseif ( ! empty( $args['labels']['name'] ) ) {
				$name = $args['labels']['name'];
			} else {
				$parent_name = $this->get_relation_object_name( isset( $args['parent_object'] ) ? $args['parent_object'] : '' );
				$child_name  = $this->get_relation_object_name( isset( $args['child_object']  ) ? $args['child_object']  : '' );
				$name        = $parent_name . ' → ' . $child_name;
			}

			$relations[] = array(
				'id'            => $relation_id,
				'name'          => $name,
				'parent_object' => isset( $args['parent_object'] ) ? $args['parent_object'] : '',
				'child_object'  => isset( $args['child_object']  ) ? $args['child_object']  : '',
				'type'          => isset( $args['type'] )          ? $args['type']          : 'one_to_many',
				'parent_rel'    => isset( $args['parent_rel'] )    ? $args['parent_rel']    : null,
				'is_hierarchy'  => ! empty( $args['parent_rel'] ),
				'table_exists'  => $this->relation_table_exists( $relation_id ),
				'table_name'    => 'wp_jet_rel_' . $relation_id,
			);
		}

		return $this->memo_set( 'relations', $relations );
	}

	public function get_relation( $relation_id ) {
		foreach ( $this->get_all_relations() as $rel ) {
			if ( (string) $rel['id'] === (string) $relation_id ) {
				return $rel;
			}
		}
		return null;
	}

	public function relation_table_exists( $relation_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_rel_' . absint( $relation_id );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ! empty( $found );
	}

	/* -----------------------------------------------------------------------
	 * Relation object parsing helpers (cct::, posts::, terms::)
	 * -------------------------------------------------------------------- */

	public function parse_relation_object( $relation_obj ) {

		if ( ! is_string( $relation_obj ) ) {
			return array( 'type' => 'unknown', 'slug' => '' );
		}

		if ( false !== strpos( $relation_obj, '::' ) ) {
			list( $type, $slug ) = explode( '::', $relation_obj, 2 );
			return array( 'type' => $type, 'slug' => $slug );
		}

		return array( 'type' => 'cct', 'slug' => $relation_obj );
	}

	public function get_relation_object_name( $relation_obj ) {

		$parsed = $this->parse_relation_object( $relation_obj );

		switch ( $parsed['type'] ) {
			case 'cct':
				$cct = $this->get_cct( $parsed['slug'] );
				return $cct ? $cct['name'] : ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );

			case 'terms':
				$tax = get_taxonomy( $parsed['slug'] );
				return $tax ? $tax->label : ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );

			case 'posts':
				$pt = get_post_type_object( $parsed['slug'] );
				return $pt ? $pt->label : ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );

			default:
				return ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );
		}
	}

	/**
	 * Convert a discovery target to its registry-side slug.
	 * "cct::available_sets_data" stays "cct::available_sets_data"; legacy bare
	 * CCT slugs get the "cct::" prefix. This is what Target_Registry uses to
	 * resolve adapters from relation objects.
	 */
	public function relation_object_to_target_slug( $relation_obj ) {

		$parsed = $this->parse_relation_object( $relation_obj );

		switch ( $parsed['type'] ) {
			case 'cct':
				return 'cct::' . $parsed['slug'];
			case 'posts':
				return 'posts::' . $parsed['slug'];
			case 'terms':
				return 'terms::' . $parsed['slug'];
			default:
				return $parsed['slug'];
		}
	}

	/* -----------------------------------------------------------------------
	 * Relations involving a given CCT (with recursive ancestor / descendant)
	 * -------------------------------------------------------------------- */

	public function get_relations_for_cct( $cct_slug, $position = 'both', $include_hierarchy = false ) {

		$all = $this->get_all_relations();
		$out = array();

		foreach ( $all as $relation ) {

			$is_parent = $this->is_cct_in_relation( $cct_slug, $relation['parent_object'] );
			$is_child  = $this->is_cct_in_relation( $cct_slug, $relation['child_object'] );

			$include = false;
			switch ( $position ) {
				case 'parent': $include = $is_parent; break;
				case 'child':  $include = $is_child;  break;
				default:       $include = $is_parent || $is_child;
			}

			if ( $include ) {
				$relation['cct_position'] = $is_parent ? 'parent' : 'child';
				$out[] = $relation;
			}
		}

		if ( $include_hierarchy ) {
			$out = array_merge(
				$out,
				$this->get_grandparent_relations( $cct_slug ),
				$this->get_grandchild_relations( $cct_slug )
			);
		}

		return $out;
	}

	public function get_grandparent_relations( $cct_slug, $max_depth = 10 ) {

		$all     = $this->get_all_relations();
		$results = array();
		$direct  = $this->get_relations_for_cct( $cct_slug, 'child', false );

		foreach ( $direct as $parent_rel ) {
			$results = array_merge(
				$results,
				$this->find_ancestors_recursive(
					$parent_rel['parent_object'],
					$parent_rel['id'],
					$cct_slug,
					$all,
					1,
					$max_depth
				)
			);
		}

		return $results;
	}

	public function get_grandchild_relations( $cct_slug, $max_depth = 10 ) {

		$all     = $this->get_all_relations();
		$results = array();
		$direct  = $this->get_relations_for_cct( $cct_slug, 'parent', false );

		foreach ( $direct as $child_rel ) {
			$results = array_merge(
				$results,
				$this->find_descendants_recursive(
					$child_rel['child_object'],
					$child_rel['id'],
					$cct_slug,
					$all,
					1,
					$max_depth
				)
			);
		}

		return $results;
	}

	private function find_ancestors_recursive( $current, $connecting_id, $original, $all, $level, $max_depth ) {

		if ( $level > $max_depth ) {
			jedb_log( 'Max depth reached in ancestor traversal', 'debug', array( 'current' => $current, 'level' => $level ) );
			return array();
		}

		$out = array();

		foreach ( $all as $rel ) {
			if ( $current === $rel['child_object'] ) {
				$rel['cct_position']    = 'grandparent';
				$rel['hierarchy_level'] = $level;
				$rel['grandparent_path'] = array(
					'grandparent_object' => $rel['parent_object'],
					'parent_object'      => $current,
					'child_object'       => $original,
					'parent_relation_id' => $connecting_id,
					'hierarchy_level'    => $level,
				);
				$out[] = $rel;

				$out = array_merge(
					$out,
					$this->find_ancestors_recursive(
						$rel['parent_object'],
						$rel['id'],
						$original,
						$all,
						$level + 1,
						$max_depth
					)
				);
			}
		}

		return $out;
	}

	private function find_descendants_recursive( $current, $connecting_id, $original, $all, $level, $max_depth ) {

		if ( $level > $max_depth ) {
			jedb_log( 'Max depth reached in descendant traversal', 'debug', array( 'current' => $current, 'level' => $level ) );
			return array();
		}

		$out = array();

		foreach ( $all as $rel ) {
			if ( $current === $rel['parent_object'] ) {
				$rel['cct_position']    = 'grandchild';
				$rel['hierarchy_level'] = $level;
				$rel['grandchild_path'] = array(
					'grandparent_object' => $original,
					'parent_object'      => $current,
					'child_object'       => $rel['child_object'],
					'parent_relation_id' => $connecting_id,
					'hierarchy_level'    => $level,
				);
				$out[] = $rel;

				$out = array_merge(
					$out,
					$this->find_descendants_recursive(
						$rel['child_object'],
						$rel['id'],
						$original,
						$all,
						$level + 1,
						$max_depth
					)
				);
			}
		}

		return $out;
	}

	private function is_cct_in_relation( $cct_slug, $relation_obj ) {

		if ( ! is_string( $relation_obj ) ) {
			return false;
		}

		if ( 0 === strpos( $relation_obj, 'cct::' ) ) {
			return substr( $relation_obj, 5 ) === $cct_slug;
		}

		if ( 0 === strpos( $relation_obj, 'terms::' ) || 0 === strpos( $relation_obj, 'posts::' ) ) {
			return false;
		}

		return $relation_obj === $cct_slug;
	}

	/* -----------------------------------------------------------------------
	 * Post-type discovery
	 * -------------------------------------------------------------------- */

	/**
	 * Discover public post types. Excludes the WP defaults that aren't useful
	 * as bridge targets unless the caller opts them in.
	 *
	 * @param array $exclude  Slugs to skip.
	 * @return array<int,array{slug:string,label:string,count:int,supports_woo_adapter:bool}>
	 */
	public function get_all_public_post_types( $exclude = array( 'attachment', 'revision', 'nav_menu_item' ) ) {

		$cached_key = 'post_types::' . md5( wp_json_encode( $exclude ) );
		$cached     = $this->memo_get( $cached_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		$builtin = get_post_types(
			array( '_builtin' => true ),
			'objects'
		);

		$all_pts = array_merge( $post_types, $builtin );

		$wc_active = class_exists( 'WooCommerce' );
		$out       = array();

		foreach ( $all_pts as $slug => $obj ) {

			if ( in_array( $slug, $exclude, true ) ) {
				continue;
			}

			$count = (int) wp_count_posts( $slug )->publish;

			$out[] = array(
				'slug'                 => $slug,
				'label'                => $obj->labels->name,
				'singular_label'       => $obj->labels->singular_name,
				'count'                => $count,
				'supports_woo_adapter' => $wc_active && in_array( $slug, array( 'product', 'product_variation' ), true ),
				'is_woo'               => in_array( $slug, array( 'product', 'product_variation' ), true ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		return $this->memo_set( $cached_key, $out );
	}

	/* -----------------------------------------------------------------------
	 * WooCommerce discovery
	 * -------------------------------------------------------------------- */

	public function is_wc_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Return product counts grouped by product type (simple, variable, grouped, …).
	 */
	public function get_woo_product_type_counts() {

		if ( ! $this->is_wc_active() ) {
			return array();
		}

		$cached = $this->memo_get( 'woo_product_types' );
		if ( null !== $cached ) {
			return $cached;
		}

		$types = function_exists( 'wc_get_product_types' ) ? wc_get_product_types() : array(
			'simple'   => 'Simple',
			'variable' => 'Variable',
			'grouped'  => 'Grouped',
			'external' => 'External',
		);

		$out = array();

		foreach ( $types as $type_slug => $type_label ) {

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_type',
							'field'    => 'slug',
							'terms'    => $type_slug,
						),
					),
				)
			);

			$out[] = array(
				'slug'  => $type_slug,
				'label' => $type_label,
				'count' => (int) $query->found_posts,
			);
		}

		return $this->memo_set( 'woo_product_types', $out );
	}

	public function get_woo_variation_count() {

		if ( ! $this->is_wc_active() ) {
			return 0;
		}

		$cached = $this->memo_get( 'woo_variation_count' );
		if ( null !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $this->memo_set( 'woo_variation_count', (int) $query->found_posts );
	}

	public function get_woo_taxonomies() {

		if ( ! $this->is_wc_active() ) {
			return array();
		}

		$cached = $this->memo_get( 'woo_taxonomies' );
		if ( null !== $cached ) {
			return $cached;
		}

		$taxonomies = get_object_taxonomies( 'product', 'objects' );
		$out        = array();

		foreach ( $taxonomies as $tax_slug => $tax_obj ) {

			$count = (int) wp_count_terms(
				array(
					'taxonomy'   => $tax_slug,
					'hide_empty' => false,
				)
			);

			$out[] = array(
				'slug'  => $tax_slug,
				'label' => $tax_obj->label,
				'count' => $count,
				'is_attribute' => 0 === strpos( $tax_slug, 'pa_' ),
			);
		}

		return $this->memo_set( 'woo_taxonomies', $out );
	}

	/* -----------------------------------------------------------------------
	 * Meta-key discovery (whitelist + sample)
	 * -------------------------------------------------------------------- */

	/**
	 * Get the user-defined meta-key whitelist for a given target slug.
	 *
	 * @return array<int,string>
	 */
	public function get_meta_whitelist_for( $target_slug ) {

		$opt = get_option( JEDB_OPTION_META_WHITELIST, array() );
		if ( ! is_array( $opt ) ) {
			return array();
		}

		if ( ! empty( $opt[ $target_slug ] ) && is_array( $opt[ $target_slug ] ) ) {
			return array_values( array_filter( array_map( 'strval', $opt[ $target_slug ] ) ) );
		}

		return array();
	}

	/**
	 * Sample N existing posts of a post type and return all unique meta keys.
	 * Used as a fallback when the whitelist for that target is empty.
	 *
	 * @param string $post_type
	 * @param int    $sample_size
	 * @return array<int,string>
	 */
	public function sample_meta_keys_for_post_type( $post_type, $sample_size = 25 ) {

		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','private','draft') ORDER BY ID DESC LIMIT %d",
				$post_type,
				absint( $sample_size )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) ORDER BY meta_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				...array_map( 'absint', $ids )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_values( array_filter( $keys, static function ( $k ) {
			return is_string( $k ) && '' !== $k;
		} ) );
	}

	/* -----------------------------------------------------------------------
	 * Top-level dependency check (used in Targets tab + Debug)
	 * -------------------------------------------------------------------- */

	public function verify_dependencies() {

		$status = array(
			'jetengine'       => function_exists( 'jet_engine' ),
			'cct_module'      => class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ),
			'relations_module'=> function_exists( 'jet_engine' ) && jet_engine() && isset( jet_engine()->relations ),
			'woocommerce'     => $this->is_wc_active(),
		);

		$status['core_ok'] = $status['jetengine'] && $status['cct_module'] && $status['relations_module'];

		return $status;
	}
}
