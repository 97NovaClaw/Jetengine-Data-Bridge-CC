<?php
/**
 * Admin shell — top-level menu + tab router.
 *
 * Phase 0 ships a single "Hello" tab that surfaces a green/red status grid for
 * the four custom tables and the snippet directory. This is enough to confirm
 * the activation hook ran cleanly.
 *
 * Future phases register additional tabs through `jedb/admin/tabs` filter.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Admin_Shell {

	const MENU_SLUG = 'jedb';

	/** @var JEDB_Admin_Shell|null */
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
		add_action( 'admin_menu',           array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts',array( $this, 'enqueue_assets' ) );
		$this->load_tabs();
	}

	private function load_tabs() {
		require_once JEDB_PLUGIN_DIR . 'includes/admin/class-tab-targets.php';
		JEDB_Tab_Targets::instance();
	}

	public function register_menu() {

		add_menu_page(
			esc_html__( 'JE Data Bridge', 'je-data-bridge-cc' ),
			esc_html__( 'JE Data Bridge', 'je-data-bridge-cc' ),
			JEDB_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-randomize',
			58
		);
	}

	public function enqueue_assets( $hook_suffix ) {

		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'jedb-admin',
			JEDB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			JEDB_VERSION
		);
	}

	public function render_page() {

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'je-data-bridge-cc' ) );
		}

		$tabs        = $this->get_tabs();
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'hello'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $current_tab ] ) ) {
			$current_tab = 'hello';
		}

		$tab_template = JEDB_PLUGIN_DIR . 'templates/admin/tab-' . $current_tab . '.php';

		include JEDB_PLUGIN_DIR . 'templates/admin/shell.php';
	}

	/**
	 * Return the list of registered admin tabs.
	 *
	 * Future subsystems append themselves via the `jedb/admin/tabs` filter:
	 *   add_filter( 'jedb/admin/tabs', function ( $tabs ) {
	 *       $tabs['flatten'] = array(
	 *           'label'    => __( 'Flatten', 'je-data-bridge-cc' ),
	 *           'priority' => 30,
	 *       );
	 *       return $tabs;
	 *   } );
	 *
	 * @return array<string,array{label:string,priority:int}>
	 */
	public function get_tabs() {

		$tabs = array(
			'hello' => array(
				'label'    => __( 'Status', 'je-data-bridge-cc' ),
				'priority' => 10,
			),
		);

		$tabs = apply_filters( 'jedb/admin/tabs', $tabs );

		uasort(
			$tabs,
			static function ( $a, $b ) {
				$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 100;
				$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 100;
				return $pa <=> $pb;
			}
		);

		return $tabs;
	}

	public static function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}
}
