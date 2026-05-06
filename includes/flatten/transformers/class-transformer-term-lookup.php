<?php
/**
 * Term Lookup — taxonomy term resolver, both directions.
 *
 *   push: name/slug/id → term IDs (array)
 *   pull: term IDs (array) → first/all names/slugs (string or array)
 *
 * Designed to slot into the per-mapping push/pull transformer chain
 * when the editor wants to drive a taxonomy field (typically
 * `category_ids` / `tag_ids` on Woo products) from a CCT field that
 * holds a string like "Cityscape" or "Mosaics".
 *
 * Per BUILD-PLAN §4.11 / D-22: defaults are conservative —
 * `match_by = 'name'`, `create_if_missing = false`. Editors must
 * opt into term creation explicitly.
 *
 * Composes with the `taxonomies[]` array on the bridge config: the
 * array runs taxonomy-level static rules; this transformer handles
 * per-row dynamic categorization driven by individual CCT field
 * values. Use both, neither, or one — your call.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Term_Lookup implements JEDB_Transformer {

	public function get_name()        { return 'term_lookup'; }
	public function get_label()       { return __( 'Term Lookup (taxonomy)', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Push: names/slugs/ids → term IDs. Pull: term IDs → names/slugs. Use to map a CCT string field onto a Woo taxonomy field like category_ids.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'taxonomy',
				'label'   => __( 'Taxonomy', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => 'product_cat',
				'help'    => __( 'WP taxonomy slug (e.g. product_cat, product_tag, pa_color). Must be registered for the target post type.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'match_by',
				'label'   => __( 'Match incoming value by', 'je-data-bridge-cc' ),
				'type'    => 'select',
				'default' => 'name',
				'options' => array( 'name', 'slug', 'id' ),
				'help'    => __( 'Push: how to interpret the value coming in (a string of "Cityscape" matched by name, slug, or id).', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'output',
				'label'   => __( 'Push output / Pull output', 'je-data-bridge-cc' ),
				'type'    => 'select',
				'default' => 'ids_array',
				'options' => array(
					'ids_array',     // default for push: [15, 27]
					'first_name',    // default-feel for pull: "Cityscape"
					'first_slug',    // for pull
					'first_id',      // for pull
					'names_array',   // for pull: ["Cityscape", "Toronto"]
					'slugs_array',   // for pull: ["cityscape", "toronto"]
				),
				'help'    => __( 'Push side: ids_array is the only useful output (Woo expects term IDs). Pull side: pick what the source CCT field expects — typically first_name or first_slug.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'create_if_missing',
				'label'   => __( 'Create term if not found (push only)', 'je-data-bridge-cc' ),
				'type'    => 'checkbox',
				'default' => false,
				'help'    => __( 'When ON, an unknown push value triggers wp_insert_term() instead of being dropped. OFF by default — keeps editors in control of taxonomy hygiene. Has no effect on pull.', 'je-data-bridge-cc' ),
			),
		);
	}

	/* -----------------------------------------------------------------------
	 * Push: arbitrary input → array of term IDs
	 * -------------------------------------------------------------------- */

	public function apply_push( $value, array $args = array(), array $context = array() ) {

		$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			$this->warn_invalid_taxonomy( $taxonomy );
			return array();
		}

		$match_by          = isset( $args['match_by'] )          ? (string) $args['match_by'] : 'name';
		$create_if_missing = ! empty( $args['create_if_missing'] );

		$candidates = $this->normalize_value_to_array( $value );
		$ids        = array();
		$missed     = array();

		foreach ( $candidates as $candidate ) {
			$id = $this->resolve_term_id( $candidate, $taxonomy, $match_by, $create_if_missing );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$missed[] = is_scalar( $candidate ) ? (string) $candidate : '';
			}
		}

		// Per L-024: when the editor fed real values but NONE of them
		// resolved to a term, surface a warning. Most common cause is a
		// match_by / value-shape mismatch (e.g., match_by='name' but the
		// CCT field stores slug-style values like "available-sets" while
		// the actual term name is "Available Sets"). Without this log
		// line the engine silently writes [] to the target field, which
		// often gets interpreted by typed setters as "clear all terms"
		// and the editor sees no categories on the product with no
		// indication of why.
		if ( ! empty( $candidates ) && empty( $ids ) ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Transformer:term_lookup] resolved 0 term IDs from non-empty input — likely a match_by / value-shape mismatch', 'warning', array(
					'taxonomy'         => $taxonomy,
					'match_by'         => $match_by,
					'create_if_missing'=> $create_if_missing,
					'unmatched_values' => $missed,
					'hint'             => 'try match_by="slug" if your CCT field stores slug-style values, or match_by="name" if it stores display names',
				) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/* -----------------------------------------------------------------------
	 * Pull: array of term IDs → name/slug/array per `output`
	 * -------------------------------------------------------------------- */

	public function apply_pull( $value, array $args = array(), array $context = array() ) {

		$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			$this->warn_invalid_taxonomy( $taxonomy );
			return $value;
		}

		$output = isset( $args['output'] ) ? (string) $args['output'] : 'first_name';

		$ids = array();
		foreach ( $this->normalize_value_to_array( $value ) as $candidate ) {
			if ( is_numeric( $candidate ) ) {
				$ids[] = (int) $candidate;
			}
		}

		if ( empty( $ids ) ) {
			return ( false !== strpos( $output, 'array' ) ) ? array() : '';
		}

		$names = array();
		$slugs = array();
		$id_l  = array();

		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
				$slugs[] = $term->slug;
				$id_l[]  = (int) $term->term_id;
			}
		}

		switch ( $output ) {
			case 'first_name':
				return ! empty( $names ) ? $names[0] : '';
			case 'first_slug':
				return ! empty( $slugs ) ? $slugs[0] : '';
			case 'first_id':
				return ! empty( $id_l ) ? $id_l[0] : 0;
			case 'names_array':
				return $names;
			case 'slugs_array':
				return $slugs;
			case 'ids_array':
			default:
				return $id_l;
		}
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Coerce an incoming value (string, int, comma-separated string,
	 * already-an-array) into an array of scalar candidates ready for
	 * term lookup.
	 */
	private function normalize_value_to_array( $value ) {

		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( is_array( $value ) ) {
			return array_values( array_filter( $value, static function ( $v ) {
				return null !== $v && '' !== $v;
			} ) );
		}

		if ( is_scalar( $value ) ) {
			$str = (string) $value;
			if ( false !== strpos( $str, ',' ) ) {
				return array_values( array_filter( array_map( 'trim', explode( ',', $str ) ), static function ( $v ) {
					return '' !== $v;
				} ) );
			}
			return array( $str );
		}

		return array();
	}

	/**
	 * Resolve a single candidate to a term_id, or return 0 when no
	 * match exists. When $create_if_missing is true and $match_by is
	 * 'name' or 'slug', insert the term and return the new ID.
	 *
	 * @return int  term_id, or 0 when not found and not creating.
	 */
	private function resolve_term_id( $candidate, $taxonomy, $match_by, $create_if_missing ) {

		if ( 'id' === $match_by ) {
			$id   = (int) $candidate;
			$term = $id ? get_term( $id, $taxonomy ) : null;
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}

		$candidate = is_scalar( $candidate ) ? (string) $candidate : '';
		if ( '' === $candidate ) {
			return 0;
		}

		$term = get_term_by( $match_by, $candidate, $taxonomy );

		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}

		if ( ! $create_if_missing ) {
			return 0;
		}

		$args = array();
		if ( 'slug' === $match_by ) {
			$args['slug'] = $candidate;
			$name         = ucwords( str_replace( array( '-', '_' ), ' ', $candidate ) );
		} else {
			$name = $candidate;
		}

		$inserted = wp_insert_term( $name, $taxonomy, $args );

		if ( is_wp_error( $inserted ) ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Transformer:term_lookup] wp_insert_term failed', 'warning', array(
					'taxonomy'  => $taxonomy,
					'candidate' => $candidate,
					'match_by'  => $match_by,
					'wp_error'  => $inserted->get_error_message(),
				) );
			}
			return 0;
		}

		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( '[Transformer:term_lookup] auto-created term', 'info', array(
				'taxonomy'  => $taxonomy,
				'candidate' => $candidate,
				'match_by'  => $match_by,
				'term_id'   => isset( $inserted['term_id'] ) ? (int) $inserted['term_id'] : 0,
			) );
		}

		return isset( $inserted['term_id'] ) ? (int) $inserted['term_id'] : 0;
	}

	private function warn_invalid_taxonomy( $taxonomy ) {
		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( '[Transformer:term_lookup] taxonomy not registered', 'warning', array(
				'taxonomy' => (string) $taxonomy,
			) );
		}
	}
}
