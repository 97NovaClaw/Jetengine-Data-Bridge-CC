<?php
/**
 * Relation Data Broker — AJAX endpoints used by the picker JS.
 *
 * Phase 2 v1 ships a single search endpoint. The "create new related item"
 * endpoint is deferred to Phase 2.5 (cleaner to build alongside the Phase 4
 * Bridge meta box's create flow, which has the same UX needs).
 *
 * Adapter-aware: the search delegates to whichever `JEDB_Data_Target`
 * matches the requested object slug, so the same endpoint serves CCT
 * lookups, public CPT lookups, and Woo product / variation lookups
 * uniformly.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Relation_Data_Broker {

	/** @var JEDB_Relation_Data_Broker|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks() {
		add_action( 'wp_ajax_jedb_relation_search_items', array( $this, 'ajax_search_items' ) );
	}

	/**
	 * AJAX: search records via the registered Data_Target for the requested
	 * object slug. Returns up to N matches as `[{id, label}]`.
	 *
	 * Expected POST:
	 *   - nonce       (string)  : `jedb_relations`
	 *   - object_slug (string)  : registry slug, e.g. `cct::available_sets_data`
	 *                             or `posts::product`. Also accepts JE-style
	 *                             relation object strings (`cct::*`,
	 *                             `posts::*`) — same shape.
	 *   - search      (string)  : optional substring filter
	 *   - limit       (int)     : optional, default 25, max 100
	 */
	public function ajax_search_items() {

		try {
			check_ajax_referer( 'jedb_relations', 'nonce' );
		} catch ( \Throwable $t ) {
			wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'je-data-bridge-cc' ) ), 400 );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'je-data-bridge-cc' ) ), 403 );
		}

		$object_slug = isset( $_POST['object_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['object_slug'] ) ) : '';
		$search      = isset( $_POST['search'] )      ? sanitize_text_field( wp_unslash( $_POST['search'] ) )      : '';
		$limit       = isset( $_POST['limit'] )       ? min( 100, max( 1, absint( $_POST['limit'] ) ) ) : 25;

		if ( '' === $object_slug ) {
			wp_send_json_error( array( 'message' => __( 'Missing object_slug.', 'je-data-bridge-cc' ) ), 400 );
		}

		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( 'Relation broker: search', 'debug', array(
				'object_slug' => $object_slug,
				'search'      => $search,
				'limit'       => $limit,
			) );
		}

		$target = $this->resolve_target( $object_slug );
		if ( ! $target ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: object slug */
					__( 'No registered target adapter for "%s".', 'je-data-bridge-cc' ),
					$object_slug
				),
			), 404 );
		}

		try {
			$rows = $target->list_records( array(
				'per_page' => $limit,
				'page'     => 1,
				'search'   => $search,
			) );
		} catch ( \Throwable $t ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Relation broker: list_records threw', 'error', array(
					'object_slug' => $object_slug,
					'error'       => $t->getMessage(),
				) );
			}
			wp_send_json_error( array( 'message' => __( 'Search failed; see debug log.', 'je-data-bridge-cc' ) ), 500 );
		}

		wp_send_json_success( array(
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
		) );
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Resolve a target adapter for either our registry slug format
	 * (`cct::*`, `posts::*`) or a JE-style relation object string (same
	 * shape — they're identical).
	 *
	 * @param string $object_slug
	 * @return JEDB_Data_Target|null
	 */
	private function resolve_target( $object_slug ) {

		$registry = JEDB_Target_Registry::instance();

		if ( $registry->has( $object_slug ) ) {
			return $registry->get( $object_slug );
		}

		$discovery = JEDB_Discovery::instance();
		$resolved  = $discovery->relation_object_to_target_slug( $object_slug );

		if ( $resolved && $registry->has( $resolved ) ) {
			return $registry->get( $resolved );
		}

		return null;
	}
}
