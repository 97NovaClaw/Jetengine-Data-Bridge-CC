<?php
/**
 * Plugin Name:       JetEngine Data Bridge CC
 * Plugin URI:        https://github.com/legworkmedia/je-data-bridge-cc
 * Description:       Bridges JetEngine CCTs, CPTs, and WooCommerce products with bidirectional, loop-safe sync, relation pre-attachment, field flattening, and a sandboxed custom-snippet transformer system.
 * Version:           0.5.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Legwork Media
 * Author URI:        https://legworkmedia.ca
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       je-data-bridge-cc
 * Domain Path:       /languages
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/* -----------------------------------------------------------------------------
 * Plugin constants
 * -------------------------------------------------------------------------- */

define( 'JEDB_VERSION',              '0.5.3' );
define( 'JEDB_DB_VERSION',           '1.1.0' );
define( 'JEDB_PLUGIN_FILE',          __FILE__ );
define( 'JEDB_PLUGIN_DIR',           plugin_dir_path( __FILE__ ) );
define( 'JEDB_PLUGIN_URL',           plugin_dir_url( __FILE__ ) );
define( 'JEDB_PLUGIN_BASE',          plugin_basename( __FILE__ ) );
define( 'JEDB_TEXT_DOMAIN',          'je-data-bridge-cc' );

define( 'JEDB_MIN_PHP_VERSION',      '7.4' );
define( 'JEDB_MIN_WP_VERSION',       '6.0' );
define( 'JEDB_MIN_JE_VERSION',       '3.3.1' );

define( 'JEDB_OPTION_SETTINGS',      'jedb_settings' );
define( 'JEDB_OPTION_BRIDGE_TYPES',  'jedb_bridge_types' );
define( 'JEDB_OPTION_META_WHITELIST','jedb_meta_whitelist' );
define( 'JEDB_OPTION_DB_VERSION',    'jedb_db_version' );

define( 'JEDB_TABLE_RELATION_CONFIGS','jedb_relation_configs' );
define( 'JEDB_TABLE_FLATTEN_CONFIGS', 'jedb_flatten_configs' );
define( 'JEDB_TABLE_SYNC_LOG',        'jedb_sync_log' );
define( 'JEDB_TABLE_SNIPPETS',        'jedb_snippets' );

define( 'JEDB_CAPABILITY',           'manage_options' );
define( 'JEDB_SNIPPETS_DIR_NAME',    'jedb-snippets' );
define( 'JEDB_DEBUG_LOG_NAME',       'jedb-debug.log' );

/**
 * Hook priority contract — every flatten / reverse-flatten engine that
 * listens on JE CCT save hooks or post save hooks for sync purposes
 * MUST register at this priority or higher (per D-19 / L-018) so JE's
 * own auto-create handlers and the relation transaction processor
 * (priority 10) finish first.
 */
define( 'JEDB_FLATTEN_HOOK_PRIORITY', 20 );

/**
 * Phase 3.6 / D-24: max number of terms per taxonomy returned by the
 * Flatten admin tab's `jedb_flatten_get_post_type_taxonomies` AJAX
 * endpoint. For larger taxonomies the editor edits via the raw JSON
 * fallback or waits for a Phase 4 search/autocomplete enhancement.
 * Override via define() in wp-config.php if your storefront has more.
 */
if ( ! defined( 'JEDB_TAX_TERMS_LIMIT' ) ) {
	define( 'JEDB_TAX_TERMS_LIMIT', 100 );
}

/* -----------------------------------------------------------------------------
 * Activation: dependency checks before anything is created
 * -------------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'jedb_activate' );

function jedb_activate() {

	if ( version_compare( PHP_VERSION, JEDB_MIN_PHP_VERSION, '<' ) ) {
		deactivate_plugins( JEDB_PLUGIN_BASE );
		wp_die(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'JetEngine Data Bridge CC requires PHP %1$s or higher. You are running %2$s.', 'je-data-bridge-cc' ),
				esc_html( JEDB_MIN_PHP_VERSION ),
				esc_html( PHP_VERSION )
			),
			esc_html__( 'Plugin Activation Error', 'je-data-bridge-cc' ),
			array( 'back_link' => true )
		);
	}

	global $wp_version;
	if ( version_compare( $wp_version, JEDB_MIN_WP_VERSION, '<' ) ) {
		deactivate_plugins( JEDB_PLUGIN_BASE );
		wp_die(
			sprintf(
				/* translators: 1: required WP version, 2: current WP version */
				esc_html__( 'JetEngine Data Bridge CC requires WordPress %1$s or higher. You are running %2$s.', 'je-data-bridge-cc' ),
				esc_html( JEDB_MIN_WP_VERSION ),
				esc_html( $wp_version )
			),
			esc_html__( 'Plugin Activation Error', 'je-data-bridge-cc' ),
			array( 'back_link' => true )
		);
	}

	require_once JEDB_PLUGIN_DIR . 'includes/class-config-db.php';
	require_once JEDB_PLUGIN_DIR . 'includes/snippets/class-snippet-installer.php';

	JEDB_Config_DB::install();
	JEDB_Snippet_Installer::install();

	add_option( JEDB_OPTION_DB_VERSION, JEDB_DB_VERSION, '', 'no' );

	if ( false === get_option( JEDB_OPTION_SETTINGS ) ) {
		add_option(
			JEDB_OPTION_SETTINGS,
			array(
				'enable_debug_log'        => true,
				'enable_custom_snippets'  => false,
				'default_sync_direction'  => 'cct_canonical',
			),
			'',
			'no'
		);
	}

	if ( false === get_option( JEDB_OPTION_BRIDGE_TYPES ) ) {
		add_option( JEDB_OPTION_BRIDGE_TYPES, array(), '', 'no' );
	}

	if ( false === get_option( JEDB_OPTION_META_WHITELIST ) ) {
		add_option( JEDB_OPTION_META_WHITELIST, array(), '', 'no' );
	}
}

/* -----------------------------------------------------------------------------
 * Deactivation: nothing destructive
 * -------------------------------------------------------------------------- */

register_deactivation_hook( __FILE__, 'jedb_deactivate' );

function jedb_deactivate() {
	flush_rewrite_rules();
}

/* -----------------------------------------------------------------------------
 * Boot the plugin (after JetEngine + WC have a chance to load)
 * -------------------------------------------------------------------------- */

add_action( 'plugins_loaded', 'jedb_boot', 20 );

function jedb_boot() {

	require_once JEDB_PLUGIN_DIR . 'includes/helpers/dependencies.php';

	if ( ! jedb_dependencies_ok() ) {
		add_action( 'admin_notices', 'jedb_render_dependency_notice' );
		return;
	}

	require_once JEDB_PLUGIN_DIR . 'includes/helpers/debug.php';
	require_once JEDB_PLUGIN_DIR . 'includes/class-plugin.php';

	JEDB_Plugin::instance();
}

/**
 * Soft dependency check — JE is required, WC is recommended.
 *
 * Returns false only when JE is missing OR detected at a version below the
 * required minimum. If JE is present but the version cannot be read (some
 * builds expose it through neither a constant nor an instance method), we
 * proceed and surface the unknown-version state in the Status tab — JE itself
 * is still active and usable.
 *
 * WC missing triggers an admin notice but does NOT block boot, since some
 * sites may use the plugin for CCT↔CCT only.
 */
function jedb_dependencies_ok() {

	if ( ! jedb_is_jet_engine_active() ) {
		return false;
	}

	$je_version = jedb_get_jet_engine_version();

	if ( $je_version && version_compare( $je_version, JEDB_MIN_JE_VERSION, '<' ) ) {
		return false;
	}

	return true;
}

function jedb_render_dependency_notice() {
	if ( ! current_user_can( JEDB_CAPABILITY ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: %s: required JetEngine version */
		esc_html__( 'JetEngine Data Bridge CC requires JetEngine %s or higher to be active. The plugin will not function until JetEngine is installed and activated.', 'je-data-bridge-cc' ),
		esc_html( JEDB_MIN_JE_VERSION )
	);
	echo '</p></div>';
}

/* -----------------------------------------------------------------------------
 * Optional: warn if WooCommerce is missing (non-blocking)
 * -------------------------------------------------------------------------- */

add_action( 'admin_notices', 'jedb_maybe_warn_missing_wc' );

function jedb_maybe_warn_missing_wc() {

	if ( ! current_user_can( JEDB_CAPABILITY ) ) {
		return;
	}

	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false === strpos( (string) $screen->id, 'jedb' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	esc_html_e( 'WooCommerce is not active. JetEngine Data Bridge CC will run in CCT-only mode; Woo product bridging is unavailable.', 'je-data-bridge-cc' );
	echo '</p></div>';
}
