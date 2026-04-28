<?php
/**
 * Target_CPT — read/write generic posts (any post type) via the WP API.
 *
 * Used for everything that is NOT a CCT and NOT a Woo product/variation.
 * Field schema is the union of standard post columns (title, content,
 * excerpt, status, slug, …) plus every meta key in the per-target whitelist
 * or, if the whitelist is empty, sampled from up to 25 existing posts.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Target_CPT extends JEDB_Target_Abstract {

	/** @var string  Bare post-type slug. */
	protected $post_type = '';

	public function __construct( $post_type ) {
		$this->post_type = $post_type;
		$this->slug      = 'posts::' . $post_type;
		$this->kind      = 'cpt';

		$obj = get_post_type_object( $post_type );
		if ( $obj ) {
			$this->label = $obj->labels->name . ' (' . $post_type . ')';
		} else {
			$this->label = $post_type . ' (post type — not registered)';
		}
	}

	public function supports_relations() {
		return true;
	}

	/* -----------------------------------------------------------------------
	 * Read / write
	 * -------------------------------------------------------------------- */

	public function exists( $id ) {
		$post = get_post( absint( $id ) );
		return $post && $post->post_type === $this->post_type;
	}

	public function get( $id ) {

		$post = get_post( absint( $id ) );
		if ( ! $post || $post->post_type !== $this->post_type ) {
			return null;
		}

		$out = array(
			'ID'           => $post->ID,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_name'    => $post->post_name,
			'post_date'    => $post->post_date,
			'post_author'  => $post->post_author,
		);

		foreach ( $this->resolve_meta_keys() as $meta_key ) {
			$out[ $meta_key ] = get_post_meta( $post->ID, $meta_key, true );
		}

		return $out;
	}

	public function update( $id, array $fields ) {

		$id = absint( $id );
		if ( ! $id || ! $this->exists( $id ) ) {
			return false;
		}

		$post_columns = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_date', 'post_author' );
		$post_data    = array( 'ID' => $id );
		$has_post     = false;

		foreach ( $post_columns as $col ) {
			if ( array_key_exists( $col, $fields ) ) {
				$post_data[ $col ] = $fields[ $col ];
				$has_post          = true;
			}
		}

		if ( $has_post ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				$this->log( 'wp_update_post failed', 'error', array( 'wp_error' => $result->get_error_message() ) );
				return false;
			}
		}

		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $post_columns, true ) || 'ID' === $key ) {
				continue;
			}
			update_post_meta( $id, $key, $value );
		}

		return true;
	}

	public function create( array $fields ) {

		$post_data = array(
			'post_type'   => $this->post_type,
			'post_status' => isset( $fields['post_status'] ) ? $fields['post_status'] : 'publish',
		);

		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_date', 'post_author' ) as $col ) {
			if ( array_key_exists( $col, $fields ) ) {
				$post_data[ $col ] = $fields[ $col ];
			}
		}

		$new_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $new_id ) ) {
			$this->log( 'wp_insert_post failed', 'error', array( 'wp_error' => $new_id->get_error_message() ) );
			return null;
		}

		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_date', 'post_author', 'post_status' ), true ) ) {
				continue;
			}
			update_post_meta( $new_id, $key, $value );
		}

		return absint( $new_id );
	}

	/* -----------------------------------------------------------------------
	 * Schema / count / list
	 * -------------------------------------------------------------------- */

	public function get_field_schema() {

		$out = array(
			array( 'name' => 'ID',           'label' => __( 'Post ID',     'je-data-bridge-cc' ), 'type' => 'number', 'group' => 'system',  'is_meta' => false, 'meta_key' => null, 'readonly' => true ),
			array( 'name' => 'post_title',   'label' => __( 'Title',       'je-data-bridge-cc' ), 'type' => 'text',   'group' => 'core',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'post_content', 'label' => __( 'Content',     'je-data-bridge-cc' ), 'type' => 'wysiwyg','group' => 'core',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'post_excerpt', 'label' => __( 'Excerpt',     'je-data-bridge-cc' ), 'type' => 'textarea','group'=> 'core',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'post_status',  'label' => __( 'Status',      'je-data-bridge-cc' ), 'type' => 'select', 'group' => 'core',    'is_meta' => false, 'meta_key' => null ),
			array( 'name' => 'post_name',    'label' => __( 'Slug',        'je-data-bridge-cc' ), 'type' => 'text',   'group' => 'core',    'is_meta' => false, 'meta_key' => null ),
		);

		foreach ( $this->resolve_meta_keys() as $key ) {
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
		$counts = wp_count_posts( $this->post_type );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	public function list_records( array $args = array() ) {

		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25;
		$page     = isset( $args['page'] )     ? absint( $args['page'] )     : 1;
		$search   = isset( $args['search'] )   ? (string) $args['search']    : '';

		$query = new WP_Query( array(
			'post_type'      => $this->post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		) );

		$out = array();

		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'label' => $post->post_title ? $post->post_title : sprintf( '%s #%d', $this->post_type, $post->ID ),
			);
		}

		return $out;
	}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	/**
	 * Resolve the effective meta-key list for this target — whitelist first,
	 * sampled keys as a fallback. Cached per-instance.
	 *
	 * @return array<int,string>
	 */
	protected function resolve_meta_keys() {

		static $cache = array();

		if ( isset( $cache[ $this->slug ] ) ) {
			return $cache[ $this->slug ];
		}

		$discovery = JEDB_Discovery::instance();
		$keys      = $discovery->get_meta_whitelist_for( $this->slug );

		if ( empty( $keys ) ) {
			$keys = $discovery->sample_meta_keys_for_post_type( $this->post_type );
		}

		$cache[ $this->slug ] = $keys;
		return $keys;
	}
}
