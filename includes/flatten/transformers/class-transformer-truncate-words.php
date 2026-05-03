<?php
/**
 * Truncate Words — push-only word cap with a configurable suffix.
 *
 * Pull is a no-op: word counts can't be inverted.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Truncate_Words implements JEDB_Transformer {

	public function get_name()        { return 'truncate_words'; }
	public function get_label()       { return __( 'Truncate Words', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Cap a string at N words. Push only — pull passes through unchanged.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'limit',
				'label'   => __( 'Word limit', 'je-data-bridge-cc' ),
				'type'    => 'number',
				'default' => 30,
				'help'    => __( 'Max words to keep.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'suffix',
				'label'   => __( 'Suffix when truncated', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '…',
				'help'    => __( 'Appended only when truncation actually happened.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		$limit  = isset( $args['limit'] )  ? max( 1, (int) $args['limit'] ) : 30;
		$suffix = isset( $args['suffix'] ) ? (string) $args['suffix']        : '…';

		return wp_trim_words( $value, $limit, $suffix );
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $value;
	}
}
