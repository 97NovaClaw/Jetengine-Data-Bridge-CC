<?php
/**
 * Passthrough — return the value unchanged in both directions.
 *
 * The default for every newly-created mapping. Always available; never
 * fails. The `comment` arg is purely documentational and lets editors
 * note "I deliberately chose not to transform this field".
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Passthrough implements JEDB_Transformer {

	public function get_name()        { return 'passthrough'; }
	public function get_label()       { return __( 'Passthrough (no transform)', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Returns the value unchanged. Use as the default when no transformation is needed.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'comment',
				'label'   => __( 'Comment (optional)', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Documents intent for future editors; not used at runtime.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {
		return $value;
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $value;
	}
}
