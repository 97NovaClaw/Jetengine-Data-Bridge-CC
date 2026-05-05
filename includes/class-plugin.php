<?php
/**
 * Singleton entry point for the plugin.
 *
 * Owns subsystem registration. Phase 0 keeps this minimal — only the admin shell
 * is wired. Future phases add: Target Registry, Discovery, Sync Guard, Flattener,
 * Relation Runtime Loader, Transaction Processor, Field Locker, Snippet Manager,
 * Woo Product Meta Box, Settings registration.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Plugin {

	/** @var JEDB_Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return JEDB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Wire the (Phase 0) subsystems.
	 *
	 * Each subsystem is loaded lazily and only when needed (admin vs runtime).
	 */
	private function boot() {

		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->maybe_run_db_upgrade();
		$this->load_core();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->load_admin();
		}

		do_action( 'jedb/booted', $this );
	}

	/**
	 * Load the always-on subsystems (Discovery + Target Registry + adapters,
	 * plus the relation runtime which has to be available on both admin and
	 * the CCT save hooks fire during admin_init).
	 *
	 * Registry bootstrap is lazy — instantiating it doesn't trigger discovery
	 * until something calls ->all() / ->get() / ->has().
	 */
	private function load_core() {

		require_once JEDB_PLUGIN_DIR . 'includes/class-discovery.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/interface-data-target.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/abstract-target.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/class-target-cct.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/class-target-cpt.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/class-target-woo-product.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/class-target-woo-variation.php';
		require_once JEDB_PLUGIN_DIR . 'includes/targets/class-target-registry.php';

		require_once JEDB_PLUGIN_DIR . 'includes/relations/class-relation-config-manager.php';
		require_once JEDB_PLUGIN_DIR . 'includes/relations/class-relation-attacher.php';
		require_once JEDB_PLUGIN_DIR . 'includes/relations/class-data-broker.php';
		require_once JEDB_PLUGIN_DIR . 'includes/relations/class-runtime-loader.php';
		require_once JEDB_PLUGIN_DIR . 'includes/relations/class-transaction-processor.php';

		require_once JEDB_PLUGIN_DIR . 'includes/class-sync-guard.php';
		require_once JEDB_PLUGIN_DIR . 'includes/class-sync-log.php';

		require_once JEDB_PLUGIN_DIR . 'includes/flatten/transformers/interface-transformer.php';
		require_once JEDB_PLUGIN_DIR . 'includes/flatten/transformers/class-transformer-registry.php';
		require_once JEDB_PLUGIN_DIR . 'includes/flatten/class-condition-evaluator.php';
		require_once JEDB_PLUGIN_DIR . 'includes/flatten/class-flatten-config-manager.php';
		require_once JEDB_PLUGIN_DIR . 'includes/flatten/class-flattener.php';
		require_once JEDB_PLUGIN_DIR . 'includes/flatten/class-reverse-flattener.php';

		JEDB_Relation_Data_Broker::instance();
		JEDB_Relation_Runtime_Loader::instance();
		JEDB_Relation_Transaction_Processor::instance();

		JEDB_Sync_Guard::instance();
		JEDB_Sync_Log::instance();
		JEDB_Flattener::instance();
		JEDB_Reverse_Flattener::instance();
	}

	/**
	 * @return JEDB_Target_Registry
	 */
	public function targets() {
		return JEDB_Target_Registry::instance();
	}

	/**
	 * @return JEDB_Discovery
	 */
	public function discovery() {
		return JEDB_Discovery::instance();
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			JEDB_TEXT_DOMAIN,
			false,
			dirname( JEDB_PLUGIN_BASE ) . '/languages/'
		);
	}

	/**
	 * Detect schema-version mismatch and re-run dbDelta if needed; apply any
	 * version-specific data migrations.
	 *
	 * Cheap on every request: only reads one option and short-circuits when
	 * versions match.
	 */
	private function maybe_run_db_upgrade() {

		$installed = get_option( JEDB_OPTION_DB_VERSION );

		if ( JEDB_DB_VERSION === $installed ) {
			return;
		}

		require_once JEDB_PLUGIN_DIR . 'includes/class-config-db.php';
		JEDB_Config_DB::install();

		$this->run_migrations( $installed );

		update_option( JEDB_OPTION_DB_VERSION, JEDB_DB_VERSION, 'no' );
	}

	/**
	 * Apply per-version data migrations.
	 *
	 * @param string|false $from_version The DB version we're upgrading FROM,
	 *                                   or false on a fresh install.
	 */
	private function run_migrations( $from_version ) {

		if ( false === $from_version || version_compare( (string) $from_version, '1.1.0', '<' ) ) {
			$settings = get_option( JEDB_OPTION_SETTINGS, array() );
			if ( empty( $settings['enable_debug_log'] ) ) {
				$settings['enable_debug_log'] = true;
				update_option( JEDB_OPTION_SETTINGS, $settings, 'no' );
			}
		}
	}

	private function load_admin() {

		require_once JEDB_PLUGIN_DIR . 'includes/admin/class-admin-shell.php';

		JEDB_Admin_Shell::instance();
	}
}
