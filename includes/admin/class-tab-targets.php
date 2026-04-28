<?php
/**
 * Targets admin tab — Phase 1 read-only inventory.
 *
 * Shows every discovered CCT, every public CPT, every WooCommerce product
 * type (with counts), every variation, every Woo taxonomy, and every active
 * JetEngine relation. Includes a "Refresh discovery cache" button that
 * flushes the JEDB_Discovery transient.
 *
 * Future phases hang per-target field-schema viewers off this tab.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Tab_Targets {

	const TAB_SLUG = 'targets';

	/** @var JEDB_Tab_Targets|null */
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
		add_filter( 'jedb/admin/tabs',  array( $this, 'register_tab' ) );
		add_action( 'admin_post_jedb_flush_discovery_cache', array( $this, 'handle_cache_flush' ) );
	}

	public function register_tab( $tabs ) {
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Targets', 'je-data-bridge-cc' ),
			'priority' => 20,
		);
		return $tabs;
	}

	public function handle_cache_flush() {

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'je-data-bridge-cc' ) );
		}

		check_admin_referer( 'jedb_flush_discovery_cache' );

		JEDB_Discovery::instance()->flush_cache();
		JEDB_Target_Registry::instance()->reset();

		jedb_log( 'Discovery cache flushed via admin action', 'info', array( 'user_id' => get_current_user_id() ) );

		wp_safe_redirect(
			add_query_arg(
				array( 'jedb_notice' => 'cache_flushed' ),
				JEDB_Admin_Shell::tab_url( self::TAB_SLUG )
			)
		);
		exit;
	}
}
