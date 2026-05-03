<?php
/**
 * Lookup Table — JSON map applied as forward and reverse dictionary.
 *
 * args.map is a JSON object: {"sealed": "Sealed Box", "good": "Good Condition"}.
 *   push: key → value (sealed → Sealed Box)
 *   pull: value → key (Sealed Box → sealed)
 *
 * Reverse lookup is built lazily and cached per-call. Unknown keys/values
 * pass through unchanged unless `strict` is on, in which case a missing
 * mapping returns the configured `fallback` value (default empty string).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Lookup_Table implements JEDB_Transformer {

	public function get_name()        { return 'lookup_table'; }
	public function get_label()       { return __( 'Lookup Table (JSON map)', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Translate values via a JSON dictionary. Push goes key→value; pull goes value→key.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'map',
				'label'   => __( 'Mapping (JSON)', 'je-data-bridge-cc' ),
				'type'    => 'textarea',
				'default' => '{}',
				'help'    => __( 'JSON object e.g. {"sealed":"Sealed Box","good":"Good Condition"}.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'strict',
				'label'   => __( 'Strict mode', 'je-data-bridge-cc' ),
				'type'    => 'checkbox',
				'default' => false,
				'help'    => __( 'When ON, an unmapped value returns the fallback below instead of passing through.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'fallback',
				'label'   => __( 'Strict-mode fallback', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Value returned when strict mode is ON and the input is not in the map.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {
		return $this->lookup( $value, $args, false );
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $this->lookup( $value, $args, true );
	}

	private function lookup( $value, array $args, $reverse ) {

		$raw = isset( $args['map'] ) ? (string) $args['map'] : '{}';
		$map = json_decode( $raw, true );
		if ( ! is_array( $map ) ) {
			return $value;
		}

		if ( $reverse ) {
			$flipped = array();
			foreach ( $map as $k => $v ) {
				$flipped[ (string) $v ] = $k;
			}
			$map = $flipped;
		}

		$key = is_scalar( $value ) ? (string) $value : '';

		if ( array_key_exists( $key, $map ) ) {
			return $map[ $key ];
		}

		if ( ! empty( $args['strict'] ) ) {
			return isset( $args['fallback'] ) ? $args['fallback'] : '';
		}

		return $value;
	}
}
