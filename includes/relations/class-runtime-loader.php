<?php
/**
 * Relation Runtime Loader — detects CCT edit pages and enqueues the picker
 * UI assets with localized config.
 *
 * URL detection mirrors RI's verified approach: JetEngine renders CCT
 * edit screens at `admin.php?page=jet-cct-{slug}`. We listen to
 * `admin_enqueue_scripts`, check the URL pattern, look up our config
 * for that CCT, and only enqueue when there's an enabled config with at
 * least one enabled relation.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Relation_Runtime_Loader {

	const PAGE_PREFIX = 'jet-cct-';

	/** @var JEDB_Relation_Runtime_Loader|null */
	private static $instance = null;

	/** @var string|null  Cached current-page CCT slug. */
	private $current_cct = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	public function maybe_enqueue() {

		if ( ! $this->is_cct_edit_page() ) {
			return;
		}

		$cct_slug = $this->get_current_cct_slug();
		if ( ! $cct_slug ) {
			return;
		}

		$this->current_cct = $cct_slug;

		$manager = JEDB_Relation_Config_Manager::instance();
		$config  = $manager->get_by_cct( $cct_slug );

		if ( ! $config || empty( $config['enabled'] ) ) {
			return;
		}

		$enabled_relation_ids = isset( $config['config']['enabled_relations'] )
			? array_filter( array_map( 'strval', (array) $config['config']['enabled_relations'] ) )
			: array();

		if ( empty( $enabled_relation_ids ) ) {
			return;
		}

		$relations_payload = $this->build_relations_payload( $cct_slug, $enabled_relation_ids, $config );
		if ( empty( $relations_payload ) ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Runtime loader: every configured relation resolved as invalid; skipping enqueue', 'warning', array(
					'cct_slug'             => $cct_slug,
					'enabled_relation_ids' => $enabled_relation_ids,
				) );
			}
			return;
		}

		wp_enqueue_style(
			'jedb-relation-injector',
			JEDB_PLUGIN_URL . 'assets/css/relation-injector.css',
			array(),
			JEDB_VERSION
		);

		wp_enqueue_script(
			'jedb-relation-injector',
			JEDB_PLUGIN_URL . 'assets/js/relation-injector.js',
			array( 'jquery' ),
			JEDB_VERSION,
			true
		);

		$ui_settings = isset( $config['config']['ui_settings'] ) && is_array( $config['config']['ui_settings'] )
			? $config['config']['ui_settings']
			: JEDB_Relation_Config_Manager::default_config_json()['ui_settings'];

		wp_localize_script(
			'jedb-relation-injector',
			'jedbRelationConfig',
			array(
				'cct_slug'    => $cct_slug,
				'relations'   => array_values( $relations_payload ),
				'ui_settings' => $ui_settings,
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'jedb_relations' ),
				'i18n'        => array(
					'block_title'      => __( 'Relations (JE Data Bridge)', 'je-data-bridge-cc' ),
					'select'           => __( 'Select…', 'je-data-bridge-cc' ),
					'search_placeholder'=> __( 'Search…', 'je-data-bridge-cc' ),
					'no_results'       => __( 'No items found', 'je-data-bridge-cc' ),
					'loading'          => __( 'Loading…', 'je-data-bridge-cc' ),
					'error'            => __( 'An error occurred.', 'je-data-bridge-cc' ),
					'cancel'           => __( 'Cancel', 'je-data-bridge-cc' ),
					'remove'           => __( 'Remove', 'je-data-bridge-cc' ),
					'one_to_one_warning'=> __( 'This relation is 1:1. Existing connection on this side will be replaced when saved.', 'je-data-bridge-cc' ),
				),
			)
		);

		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( 'Runtime loader: enqueued picker assets', 'info', array(
				'cct_slug'   => $cct_slug,
				'relations'  => count( $relations_payload ),
			) );
		}
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	private function is_cct_edit_page() {

		global $pagenow;

		if ( ! is_admin() ) {
			return false;
		}

		if ( 'admin.php' !== $pagenow ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ( '' !== $page && 0 === strpos( $page, self::PAGE_PREFIX ) );
	}

	private function get_current_cct_slug() {

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page || 0 !== strpos( $page, self::PAGE_PREFIX ) ) {
			return null;
		}

		$slug = substr( $page, strlen( self::PAGE_PREFIX ) );
		return sanitize_key( $slug );
	}

	/**
	 * Build the per-relation payload the JS needs to render the picker:
	 * relation id, name, type, side this CCT is on, and the related
	 * object's adapter slug + label so the picker can search the right
	 * target.
	 */
	private function build_relations_payload( $cct_slug, array $enabled_relation_ids, array $config ) {

		$discovery = JEDB_Discovery::instance();
		$attacher  = new JEDB_Relation_Attacher();
		$registry  = JEDB_Target_Registry::instance();

		$out = array();

		foreach ( $discovery->get_all_relations() as $relation ) {

			$relation_id = (string) $relation['id'];
			if ( ! in_array( $relation_id, $enabled_relation_ids, true ) ) {
				continue;
			}

			$side = $attacher->determine_side( $relation_id, $cct_slug );
			if ( 'none' === $side ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( 'Runtime loader: configured relation does not include this CCT — skipping', 'warning', array(
						'cct_slug'    => $cct_slug,
						'relation_id' => $relation_id,
					) );
				}
				continue;
			}

			$other_object_slug = ( 'parent' === $side )
				? $relation['child_object']
				: $relation['parent_object'];

			$other_target_slug  = $discovery->relation_object_to_target_slug( $other_object_slug );
			$other_target       = $registry->has( $other_target_slug ) ? $registry->get( $other_target_slug ) : null;
			$other_target_label = $other_target ? $other_target->get_label() : $discovery->get_relation_object_name( $other_object_slug );

			$out[] = array(
				'id'                => $relation_id,
				'name'              => $relation['name'],
				'type'              => isset( $relation['type'] ) ? $relation['type'] : 'one_to_many',
				'cct_side'          => $side,                       // 'parent' or 'child'
				'other_object'      => $other_object_slug,           // raw JE relation-object string
				'other_target_slug' => $other_target_slug,           // registry slug for AJAX search
				'other_target_label'=> $other_target_label,
				'table_exists'      => ! empty( $relation['table_exists'] ),
			);
		}

		return $out;
	}
}
