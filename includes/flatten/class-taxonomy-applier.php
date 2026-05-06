<?php
/**
 * Taxonomy Applier — runs the bridge config's `taxonomies[]` rules
 * against a target post during forward push.
 *
 * Per BUILD-PLAN §4.11 / D-20-D-24 / L-023:
 *
 *   - Only fires on the FORWARD direction. The reverse pull engine
 *     skips this entirely (D-21 — taxonomies are push-only in v1).
 *   - Runs AFTER the condition check, BEFORE the field mappings
 *     pipeline. Editorial intent: taxonomy assignments are "where
 *     does this product live in the storefront taxonomy?" and that
 *     decision should be made before — not after — the field-level
 *     copy-paste of names/prices.
 *   - Each rule is applied independently. A failure in one (e.g.,
 *     unregistered taxonomy) doesn't abort the others or the
 *     downstream mappings.
 *   - Returns a structured outcome that the caller threads into
 *     `wp_jedb_sync_log.context_json` so editors can audit which
 *     terms were added, removed, or auto-created on each save.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Taxonomy_Applier {

	/** @var JEDB_Taxonomy_Applier|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Apply the bridge's `taxonomies[]` rules against a target post.
	 *
	 * Outcome shape (one entry per rule processed):
	 *
	 *   [
	 *     [
	 *       'taxonomy'      => 'product_cat',
	 *       'status'        => 'applied' | 'skipped_disabled' | 'skipped_invalid' | 'errored',
	 *       'message'       => string,
	 *       'added_ids'     => [15, 27],
	 *       'removed_ids'   => [42],
	 *       'created_terms' => [{name:'Cityscape', id:99, slug:'cityscape'}],
	 *       'merge_strategy'=> 'append',
	 *     ],
	 *     ...
	 *   ]
	 *
	 * Plus a top-level summary:
	 *
	 *   [
	 *     'rules_processed' => int,
	 *     'rules_applied'   => int,
	 *     'terms_added'     => int (sum across all rules),
	 *     'terms_removed'   => int,
	 *     'terms_created'   => int,
	 *     'rules'           => [...the per-rule entries above...],
	 *   ]
	 *
	 * @param array $rules    The bridge config's `taxonomies[]` array
	 *                        (already merged with defaults via
	 *                        JEDB_Flatten_Config_Manager::merge_with_defaults).
	 * @param int   $post_id  Target post ID to apply the rules against.
	 * @param array $context  Bridge $context (for logging / future
	 *                        snippet support).
	 * @return array          Summary + per-rule outcomes.
	 */
	public function apply_for_bridge( array $rules, $post_id, array $context = array() ) {

		$post_id = absint( $post_id );

		$summary = array(
			'rules_processed' => 0,
			'rules_applied'   => 0,
			'terms_added'     => 0,
			'terms_removed'   => 0,
			'terms_created'   => 0,
			'rules'           => array(),
		);

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $summary;
		}

		foreach ( $rules as $rule ) {

			$summary['rules_processed']++;

			if ( ! is_array( $rule ) ) {
				$summary['rules'][] = array(
					'taxonomy' => '',
					'status'   => 'skipped_invalid',
					'message'  => 'rule is not an array',
				);
				continue;
			}

			if ( isset( $rule['enabled'] ) && empty( $rule['enabled'] ) ) {
				$summary['rules'][] = array(
					'taxonomy' => isset( $rule['taxonomy'] ) ? (string) $rule['taxonomy'] : '',
					'status'   => 'skipped_disabled',
					'message'  => 'rule disabled',
				);
				continue;
			}

			$taxonomy = isset( $rule['taxonomy'] ) ? (string) $rule['taxonomy'] : '';

			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				$summary['rules'][] = array(
					'taxonomy' => $taxonomy,
					'status'   => 'skipped_invalid',
					'message'  => 'taxonomy not registered',
				);
				continue;
			}

			$post_type = get_post_type( $post_id );
			if ( $post_type && ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				$summary['rules'][] = array(
					'taxonomy' => $taxonomy,
					'status'   => 'skipped_invalid',
					'message'  => sprintf( 'taxonomy "%s" is not registered for post_type "%s"', $taxonomy, $post_type ),
				);
				continue;
			}

			// Phase 5b stub: snippet override goes here once the runtime ships.
			$snippet_slug = isset( $rule['snippet'] ) ? (string) $rule['snippet'] : '';
			if ( '' !== $snippet_slug ) {
				$summary['rules'][] = array(
					'taxonomy' => $taxonomy,
					'status'   => 'skipped_invalid',
					'message'  => 'taxonomy_rule.snippet ignored — Snippet runtime ships in Phase 5b',
					'snippet'  => $snippet_slug,
				);
				continue;
			}

			$rule_outcome = $this->apply_single_rule( $rule, $post_id, $taxonomy );
			$summary['rules'][] = $rule_outcome;

			if ( 'applied' === $rule_outcome['status'] ) {
				$summary['rules_applied']++;
				$summary['terms_added']   += count( $rule_outcome['added_ids'] );
				$summary['terms_removed'] += count( $rule_outcome['removed_ids'] );
				$summary['terms_created'] += count( $rule_outcome['created_terms'] );
			}
		}

		do_action( 'jedb/taxonomies/applied', $post_id, $rules, $summary, $context );

		return $summary;
	}

	/**
	 * Apply one rule. Caller has already validated the taxonomy is
	 * registered and applicable to the post type.
	 */
	private function apply_single_rule( array $rule, $post_id, $taxonomy ) {

		$match_by          = isset( $rule['match_by'] )       ? (string) $rule['match_by'] : 'slug';
		$merge_strategy    = isset( $rule['merge_strategy'] ) ? (string) $rule['merge_strategy'] : 'append';
		$create_if_missing = ! empty( $rule['create_if_missing'] );

		$apply_raw   = isset( $rule['apply_terms'] )         && is_array( $rule['apply_terms'] )         ? $rule['apply_terms']         : array();
		$inverse_raw = isset( $rule['apply_terms_inverse'] ) && is_array( $rule['apply_terms_inverse'] ) ? $rule['apply_terms_inverse'] : array();

		$created_terms = array();

		$apply_ids = $this->resolve_term_refs(
			$apply_raw,
			$taxonomy,
			$match_by,
			$create_if_missing,
			$created_terms
		);

		$inverse_ids = $this->resolve_term_refs(
			$inverse_raw,
			$taxonomy,
			$match_by,
			false,
			$created_terms
		);

		$append_flag = ( 'append' === $merge_strategy );

		// Pre-state: the post's current terms in this taxonomy. Used to
		// compute per-rule "added" diff for the sync log; replace mode
		// loses this info from wp_set_object_terms's perspective so we
		// snapshot it ourselves.
		$pre_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$pre_ids   = is_array( $pre_terms ) ? array_map( 'intval', $pre_terms ) : array();

		$set_result = wp_set_object_terms( $post_id, $apply_ids, $taxonomy, $append_flag );

		if ( is_wp_error( $set_result ) ) {
			return array(
				'taxonomy'       => $taxonomy,
				'status'         => 'errored',
				'message'        => 'wp_set_object_terms returned WP_Error: ' . $set_result->get_error_message(),
				'added_ids'      => array(),
				'removed_ids'    => array(),
				'created_terms'  => $created_terms,
				'merge_strategy' => $merge_strategy,
			);
		}

		// Apply inverse-removal AFTER the apply step so the inverse list
		// can clean up anything the apply step left behind that shouldn't
		// be there (e.g., previously-set terms that aren't in
		// apply_terms but ARE in apply_terms_inverse).
		if ( ! empty( $inverse_ids ) ) {
			$remove_result = wp_remove_object_terms( $post_id, $inverse_ids, $taxonomy );
			if ( is_wp_error( $remove_result ) ) {
				return array(
					'taxonomy'       => $taxonomy,
					'status'         => 'errored',
					'message'        => 'wp_remove_object_terms returned WP_Error: ' . $remove_result->get_error_message(),
					'added_ids'      => array(),
					'removed_ids'    => array(),
					'created_terms'  => $created_terms,
					'merge_strategy' => $merge_strategy,
				);
			}
		}

		$post_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$post_ids   = is_array( $post_terms ) ? array_map( 'intval', $post_terms ) : array();

		$added_ids   = array_values( array_diff( $post_ids, $pre_ids ) );
		$removed_ids = array_values( array_diff( $pre_ids,  $post_ids ) );

		return array(
			'taxonomy'       => $taxonomy,
			'status'         => 'applied',
			'message'        => sprintf( 'apply=%d, inverse=%d, added=%d, removed=%d, created=%d, strategy=%s',
				count( $apply_ids ),
				count( $inverse_ids ),
				count( $added_ids ),
				count( $removed_ids ),
				count( $created_terms ),
				$merge_strategy
			),
			'added_ids'      => $added_ids,
			'removed_ids'    => $removed_ids,
			'created_terms'  => $created_terms,
			'merge_strategy' => $merge_strategy,
		);
	}

	/**
	 * Resolve a list of references (names / slugs / ids) to existing
	 * term IDs. When $create_if_missing is true and $match_by is name
	 * or slug, missing terms are wp_insert_term'd and tracked in the
	 * $created_out list (modified by reference for the caller's
	 * sync_log context).
	 *
	 * @param array  $refs              Raw values from the rule (mixed).
	 * @param string $taxonomy
	 * @param string $match_by          'name' | 'slug' | 'id'
	 * @param bool   $create_if_missing
	 * @param array  $created_out       (out) appended to with created term info.
	 * @return array<int>                Resolved term IDs (de-duplicated).
	 */
	private function resolve_term_refs( array $refs, $taxonomy, $match_by, $create_if_missing, array &$created_out ) {

		$ids = array();

		foreach ( $refs as $ref ) {

			if ( null === $ref || '' === $ref ) {
				continue;
			}

			if ( 'id' === $match_by ) {
				$id   = (int) $ref;
				$term = $id ? get_term( $id, $taxonomy ) : null;
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
				}
				continue;
			}

			$ref_str = is_scalar( $ref ) ? (string) $ref : '';
			if ( '' === $ref_str ) {
				continue;
			}

			$term = get_term_by( $match_by, $ref_str, $taxonomy );

			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
				continue;
			}

			if ( ! $create_if_missing ) {
				continue;
			}

			$args = array();
			if ( 'slug' === $match_by ) {
				$args['slug'] = $ref_str;
				$name         = ucwords( str_replace( array( '-', '_' ), ' ', $ref_str ) );
			} else {
				$name = $ref_str;
			}

			$inserted = wp_insert_term( $name, $taxonomy, $args );

			if ( is_wp_error( $inserted ) ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Taxonomy_Applier] wp_insert_term failed', 'warning', array(
						'taxonomy' => $taxonomy,
						'ref'      => $ref_str,
						'match_by' => $match_by,
						'wp_error' => $inserted->get_error_message(),
					) );
				}
				continue;
			}

			$new_id = isset( $inserted['term_id'] ) ? (int) $inserted['term_id'] : 0;
			if ( $new_id ) {
				$ids[]           = $new_id;
				$new_term        = get_term( $new_id, $taxonomy );
				$created_out[]   = array(
					'name' => $new_term ? $new_term->name : $name,
					'slug' => $new_term ? $new_term->slug : '',
					'id'   => $new_id,
				);

				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Taxonomy_Applier] auto-created term (D-22 create_if_missing)', 'info', array(
						'taxonomy' => $taxonomy,
						'ref'      => $ref_str,
						'match_by' => $match_by,
						'term_id'  => $new_id,
					) );
				}
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}
