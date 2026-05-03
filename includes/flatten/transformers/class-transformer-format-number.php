<?php
/**
 * Format Number — round / cast / decimal-cap.
 *
 *   push: cast to float, round to N decimals, optionally cast to int.
 *   pull: same behavior unless override args provided.
 *
 * Useful for the price field where the CCT carries an int "100" but Woo
 * expects a string "100.00", or vice versa.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Format_Number implements JEDB_Transformer {

	public function get_name()        { return 'format_number'; }
	public function get_label()       { return __( 'Format Number', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Cast to numeric, round to N decimals, optionally output as integer or string.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'decimals',
				'label'   => __( 'Decimal places', 'je-data-bridge-cc' ),
				'type'    => 'number',
				'default' => 2,
				'help'    => __( 'Number of decimals to round to. -1 = no rounding.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'output',
				'label'   => __( 'Output as', 'je-data-bridge-cc' ),
				'type'    => 'select',
				'default' => 'string',
				'options' => array( 'string', 'float', 'int' ),
				'help'    => __( 'string keeps trailing zeros (e.g. "12.50"); float/int do not.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {
		return $this->format( $value, $args );
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $this->format( $value, $args );
	}

	private function format( $value, array $args ) {

		if ( null === $value || '' === $value ) {
			return $value;
		}

		$decimals = isset( $args['decimals'] ) ? (int) $args['decimals'] : 2;
		$output   = isset( $args['output'] )   ? (string) $args['output'] : 'string';

		$num = is_numeric( $value )
			? (float) $value
			: (float) preg_replace( '/[^0-9.\-]/', '', (string) $value );

		if ( $decimals >= 0 ) {
			$num = round( $num, $decimals );
		}

		switch ( $output ) {
			case 'int':
				return (int) $num;
			case 'float':
				return $num;
			case 'string':
			default:
				return $decimals >= 0 ? number_format( $num, $decimals, '.', '' ) : (string) $num;
		}
	}
}
