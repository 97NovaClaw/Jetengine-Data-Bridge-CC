<?php
/**
 * Dependency detection helpers.
 *
 * JetEngine has shipped its version through several different channels over the
 * years (global constant, class constant, instance method, instance property,
 * and—as a last resort—the plugin header). These helpers try every known
 * channel so the plugin reliably detects JetEngine even on installs where the
 * `JET_ENGINE_VERSION` global constant is absent.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'jedb_is_jet_engine_active' ) ) {

	/**
	 * Cheap "is JetEngine present?" check used everywhere in the codebase.
	 */
	function jedb_is_jet_engine_active() {
		return function_exists( 'jet_engine' );
	}
}

if ( ! function_exists( 'jedb_get_jet_engine_version' ) ) {

	/**
	 * Resolve the active JetEngine version, trying every known channel.
	 *
	 * Order matters — cheapest checks first.
	 *
	 * @return string|null Semver string (e.g. "3.7.4") or null if undetectable.
	 */
	function jedb_get_jet_engine_version() {

		static $cached = null;
		if ( null !== $cached ) {
			return '' === $cached ? null : $cached;
		}

		$version = null;

		if ( defined( 'JET_ENGINE_VERSION' ) ) {
			$version = JET_ENGINE_VERSION;
		}

		if ( ! $version && class_exists( 'Jet_Engine' ) && defined( 'Jet_Engine::VERSION' ) ) {
			$version = constant( 'Jet_Engine::VERSION' );
		}

		if ( ! $version && function_exists( 'jet_engine' ) ) {
			$instance = jet_engine();

			if ( $instance && method_exists( $instance, 'get_version' ) ) {
				$maybe = $instance->get_version();
				if ( $maybe ) {
					$version = $maybe;
				}
			}

			if ( ! $version && $instance && isset( $instance->version ) && $instance->version ) {
				$version = $instance->version;
			}
		}

		if ( ! $version && function_exists( 'get_file_data' ) && defined( 'WP_PLUGIN_DIR' ) ) {

			$candidates = array(
				WP_PLUGIN_DIR . '/jet-engine/jet-engine.php',
				WP_PLUGIN_DIR . '/jetengine/jet-engine.php',
			);

			foreach ( $candidates as $file ) {
				if ( file_exists( $file ) ) {
					$data = get_file_data( $file, array( 'Version' => 'Version' ) );
					if ( ! empty( $data['Version'] ) ) {
						$version = $data['Version'];
						break;
					}
				}
			}
		}

		$cached = $version ? $version : '';

		return $version;
	}
}

if ( ! function_exists( 'jedb_get_woocommerce_version' ) ) {

	function jedb_get_woocommerce_version() {

		if ( defined( 'WC_VERSION' ) ) {
			return WC_VERSION;
		}

		if ( class_exists( 'WooCommerce' ) && defined( 'WooCommerce::VERSION' ) ) {
			return constant( 'WooCommerce::VERSION' );
		}

		return null;
	}
}

if ( ! function_exists( 'jedb_is_hpos_enabled' ) ) {

	function jedb_is_hpos_enabled() {

		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
