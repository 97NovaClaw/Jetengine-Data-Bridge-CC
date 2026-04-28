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

		add_action( 'init',          array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->load_admin();
		}

		do_action( 'jedb/booted', $this );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			JEDB_TEXT_DOMAIN,
			false,
			dirname( JEDB_PLUGIN_BASE ) . '/languages/'
		);
	}

	/**
	 * Detect schema-version mismatch and re-run dbDelta if needed.
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
		update_option( JEDB_OPTION_DB_VERSION, JEDB_DB_VERSION, 'no' );
	}

	private function load_admin() {

		require_once JEDB_PLUGIN_DIR . 'includes/admin/class-admin-shell.php';

		JEDB_Admin_Shell::instance();
	}
}
