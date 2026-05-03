<?php
/**
 * Year Expander — domain-specific helper carried over from PAC VDM.
 *
 *   push: "2018-2022" → [2018, 2019, 2020, 2021, 2022]
 *         "2018,2020" → [2018, 2020]
 *         single year → [2018]
 *
 *   pull: array of years → "2018-2022" when contiguous, else "2018,2020,2022".
 *
 * Useful for any "year range" field that needs to be exploded into a
 * relation-friendly array. Generalizes to any 4-digit numeric range.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Year_Expander implements JEDB_Transformer {

	public function get_name()        { return 'year_expander'; }
	public function get_label()       { return __( 'Year Expander', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Push: "2018-2022" → [2018,2019,2020,2021,2022]. Pull: array → range string.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'min_year',
				'label'   => __( 'Min year (clamp)', 'je-data-bridge-cc' ),
				'type'    => 'number',
				'default' => 1900,
				'help'    => __( 'Floor for sanity check; values below this are dropped.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'max_year',
				'label'   => __( 'Max year (clamp)', 'je-data-bridge-cc' ),
				'type'    => 'number',
				'default' => 2100,
				'help'    => __( 'Ceiling for sanity check; values above this are dropped.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {

		$min = isset( $args['min_year'] ) ? (int) $args['min_year'] : 1900;
		$max = isset( $args['max_year'] ) ? (int) $args['max_year'] : 2100;

		if ( is_array( $value ) ) {
			return $this->clamp_array( $value, $min, $max );
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$str   = trim( $value );
		$years = array();

		foreach ( explode( ',', $str ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}

			if ( preg_match( '/^(\d{4})\s*-\s*(\d{4})$/', $chunk, $m ) ) {
				$start = (int) $m[1];
				$end   = (int) $m[2];
				if ( $start > $end ) {
					list( $start, $end ) = array( $end, $start );
				}
				for ( $y = $start; $y <= $end; $y++ ) {
					$years[] = $y;
				}
			} elseif ( preg_match( '/^\d{4}$/', $chunk ) ) {
				$years[] = (int) $chunk;
			}
		}

		return $this->clamp_array( $years, $min, $max );
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) || empty( $value ) ) {
			return '';
		}

		$years = array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
		sort( $years );

		if ( empty( $years ) ) {
			return '';
		}

		if ( count( $years ) === ( end( $years ) - reset( $years ) + 1 ) ) {
			return reset( $years ) . '-' . end( $years );
		}

		return implode( ',', $years );
	}

	private function clamp_array( array $years, $min, $max ) {
		$years = array_filter( array_map( 'intval', $years ), static function ( $y ) use ( $min, $max ) {
			return $y >= $min && $y <= $max;
		} );
		$years = array_values( array_unique( $years ) );
		sort( $years );
		return $years;
	}
}
