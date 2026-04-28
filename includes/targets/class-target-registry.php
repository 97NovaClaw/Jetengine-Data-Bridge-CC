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

		$discovery = JEDB_Discovery::instance();

		foreach ( $discovery->get_all_ccts() as $cct ) {
			$this->register( new JEDB_Target_CCT( $cct['slug'] ) );
		}

		foreach ( $discovery->get_all_public_post_types() as $pt ) {
			$this->register( new JEDB_Target_CPT( $pt['slug'] ) );
		}

		if ( $discovery->is_wc_active() ) {

			if ( class_exists( 'JEDB_Target_Woo_Product' ) ) {
				$this->register( new JEDB_Target_Woo_Product() );
			}

			if ( class_exists( 'JEDB_Target_Woo_Variation' ) ) {
				$this->register( new JEDB_Target_Woo_Variation() );
			}
		}

		do_action( 'jedb/data_target/register', $this );
	}
}
