<?php
/**
 * Yes/No ↔ Bool — bidirectional inverse.
 *
 *   push:  "yes" / "y" / "1" / true   → true
 *          "no"  / "n" / "0" / false  → false
 *          anything else              → false (configurable via 'strict')
 *
 *   pull:  true  → "yes"
 *          false → "no"
 *
 * Used heavily in the Brick Builder HQ bridges where CCT fields like
 * `display_price_publicly` and `has_instructions_pdf` are stored as
 * "yes"/"no" but Woo expects bools.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Yes_No_Bool implements JEDB_Transformer {

	public function get_name()        { return 'yes_no_to_bool'; }
	public function get_label()       { return __( 'Yes/No ↔ Boolean', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Push: "yes"/"no" → true/false. Pull: true/false → "yes"/"no". Inverse pair.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'true_label',
				'label'   => __( 'Pull: value for true', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => 'yes',
				'help'    => __( 'String written to the source side when the target value is true.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'false_label',
				'label'   => __( 'Pull: value for false', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => 'no',
				'help'    => __( 'String written to the source side when the target value is false.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) (int) $value;
		}

		if ( is_string( $value ) ) {
			$lc = strtolower( trim( $value ) );
			if ( in_array( $lc, array( 'yes', 'y', 'true', 't', '1', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $lc, array( 'no', 'n', 'false', 'f', '0', 'off', '' ), true ) ) {
				return false;
			}
		}

		return (bool) $value;
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {

		$true_label  = isset( $args['true_label'] )  ? (string) $args['true_label']  : 'yes';
		$false_label = isset( $args['false_label'] ) ? (string) $args['false_label'] : 'no';

		if ( is_bool( $value ) ) {
			return $value ? $true_label : $false_label;
		}

		if ( is_numeric( $value ) ) {
			return ( (int) $value ) ? $true_label : $false_label;
		}

		if ( is_string( $value ) ) {
			$lc = strtolower( trim( $value ) );
			if ( in_array( $lc, array( 'yes', 'y', 'true', 't', '1', 'on' ), true ) ) {
				return $true_label;
			}
			return $false_label;
		}

		return $value ? $true_label : $false_label;
	}
}
