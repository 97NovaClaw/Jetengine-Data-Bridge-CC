<?php
/**
 * Target_Registry — flat map of slug → JEDB_Data_Target instance.
 *
 * Auto-registers default adapters from JEDB_Discovery on first access:
 * a JEDB_Target_CCT for every CCT, a JEDB_Target_CPT for every public post
 * type, and the WooCommerce-specific adapters for "product" and
 * "product_variation" when WC is active (replacing the generic CPT adapter
 * for those two post types).
 *
 * Third-party code can register additional targets via the
 * `jedb/data_target/register` action:
 *
 *   add_action( 'jedb/data_target/register', function ( $registry ) {
 *       $registry->register( new My_Custom_Target() );
 *   } );
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Target_Registry {

	/** @var JEDB_Target_Registry|null */
	private static $instance = null;

	/** @var array<string,JEDB_Data_Target> */
	private $targets = array();

	/** @var bool */
	private $bootstrapped = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register a single adapter. Last-writer-wins on slug collision so a
	 * Woo adapter can replace the generic CPT adapter for the same post type.
	 */
	public function register( JEDB_Data_Target $target ) {
		$this->targets[ $target->get_slug() ] = $target;
		return $this;
	}

	public function unregister( $slug ) {
		unset( $this->targets[ $slug ] );
		return $this;
	}

	/**
	 * @return JEDB_Data_Target|null
	 */
	public function get( $slug ) {
		$this->bootstrap_defaults();
		return isset( $this->targets[ $slug ] ) ? $this->targets[ $slug ] : null;
	}

	public function has( $slug ) {
		$this->bootstrap_defaults();
		return isset( $this->targets[ $slug ] );
	}

	/**
	 * @return array<string,JEDB_Data_Target>
	 */
	public function all() {
		$this->bootstrap_defaults();
		return $this->targets;
	}

	/**
	 * @return array<string,JEDB_Data_Target>
	 */
	public function all_of_kind( $kind ) {
		$this->bootstrap_defaults();
		$out = array();
		foreach ( $this->targets as $slug => $target ) {
			if ( $target->get_kind() === $kind ) {
				$out[ $slug ] = $target;
			}
		}
		return $out;
	}

	/**
	 * Drop discovery and re-bootstrap on next access.
	 */
	public function reset() {
		$this->targets      = array();
		$this->bootstrapped = false;
	}

	/**
	 * Idempotent first-access registration of every discovered target.
	 *
	 * Order matters: CCTs and generic CPTs go in first, then Woo adapters
	 * overwrite the post::product and post::product_variation slots when
	 * WooCommerce is active. Finally we fire the third-party hook so custom
	 * targets can either add new slugs or replace ours.
	 */
	private function bootstrap_defaults() {

		if ( $this->bootstrapped ) {
			return;
		}

		$this->bootstrapped = true;

		try {

			$discovery = JEDB_Discovery::instance();

			$ccts = $discovery->get_all_ccts();
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Registry bootstrap: discovered CCTs', 'debug', array( 'count' => count( $ccts ) ) );
			}
			foreach ( $ccts as $cct ) {
				try {
					$this->register( new JEDB_Target_CCT( $cct['slug'] ) );
				} catch ( \Throwable $t ) {
					if ( function_exists( 'jedb_log' ) ) {
						jedb_log( 'Registry bootstrap: CCT adapter ctor threw', 'error', array( 'slug' => $cct['slug'], 'error' => $t->getMessage() ) );
					}
				}
			}

			$post_types = $discovery->get_all_public_post_types();
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Registry bootstrap: discovered post types', 'debug', array(
					'count' => count( $post_types ),
					'slugs' => wp_list_pluck( $post_types, 'slug' ),
				) );
			}
			foreach ( $post_types as $pt ) {
				try {
					$this->register( new JEDB_Target_CPT( $pt['slug'] ) );
				} catch ( \Throwable $t ) {
					if ( function_exists( 'jedb_log' ) ) {
						jedb_log( 'Registry bootstrap: CPT adapter ctor threw', 'error', array( 'slug' => $pt['slug'], 'error' => $t->getMessage() ) );
					}
				}
			}

			if ( $discovery->is_wc_active() ) {

				if ( class_exists( 'JEDB_Target_Woo_Product' ) ) {
					try {
						$this->register( new JEDB_Target_Woo_Product() );
					} catch ( \Throwable $t ) {
						if ( function_exists( 'jedb_log' ) ) {
							jedb_log( 'Registry bootstrap: Woo Product adapter ctor threw', 'error', array( 'error' => $t->getMessage() ) );
						}
					}
				}

				if ( class_exists( 'JEDB_Target_Woo_Variation' ) ) {
					try {
						$this->register( new JEDB_Target_Woo_Variation() );
					} catch ( \Throwable $t ) {
						if ( function_exists( 'jedb_log' ) ) {
							jedb_log( 'Registry bootstrap: Woo Variation adapter ctor threw', 'error', array( 'error' => $t->getMessage() ) );
						}
					}
				}
			}

			do_action( 'jedb/data_target/register', $this );

			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Registry bootstrap: complete', 'debug', array(
					'total_targets' => count( $this->targets ),
					'slugs'         => array_keys( $this->targets ),
				) );
			}
		} catch ( \Throwable $t ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Registry bootstrap: top-level exception', 'error', array(
					'error' => $t->getMessage(),
					'file'  => $t->getFile(),
					'line'  => $t->getLine(),
				) );
			}
		}
	}
}
