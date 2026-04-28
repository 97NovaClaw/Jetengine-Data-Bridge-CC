<?php
/**
 * Common base class for every Data_Target implementation.
 *
 * Holds shared utilities so concrete adapters stay small. Concrete adapters
 * MUST implement every interface method; this base only provides defaults
 * for things every adapter does the same way (kind extraction, slug parsing,
 * standard error logging).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

abstract class JEDB_Target_Abstract implements JEDB_Data_Target {

	/** @var string  e.g. "cct::available_sets_data" */
	protected $slug = '';

	/** @var string */
	protected $label = '';

	/** @var string  "cct" | "cpt" | "woo_product" | "woo_variation" */
	protected $kind = '';

	public function get_slug() {
		return $this->slug;
	}

	public function get_label() {
		return $this->label;
	}

	public function get_kind() {
		return $this->kind;
	}

	public function supports_relations() {
		return false;
	}

	public function count() {
		return 0;
	}

	public function list_records( array $args = array() ) {
		return array();
	}

	/**
	 * Helper: parse our composite slug into ['kind' => ..., 'object' => ...].
	 */
	public static function parse_slug( $slug ) {
		if ( false !== strpos( $slug, '::' ) ) {
			list( $kind, $object ) = explode( '::', $slug, 2 );
			return array( 'kind' => $kind, 'object' => $object );
		}
		return array( 'kind' => '', 'object' => $slug );
	}

	protected function log( $message, $level = 'info', array $context = array() ) {
		if ( function_exists( 'jedb_log' ) ) {
			$context['target_slug'] = $this->slug;
			jedb_log( $message, $level, $context );
		}
	}
}
