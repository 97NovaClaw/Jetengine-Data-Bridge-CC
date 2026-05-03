<?php
/**
 * Regex Replace — pattern + replacement, both directions optional.
 *
 * Pulls and pushes can be different patterns (e.g., push strips leading
 * whitespace; pull leaves the value alone). When the corresponding
 * direction's pattern is empty the value is returned unchanged.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Regex_Replace implements JEDB_Transformer {

	public function get_name()        { return 'regex_replace'; }
	public function get_label()       { return __( 'Regex Replace', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Apply a PCRE pattern + replacement. Push and pull patterns are independent.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'push_pattern',
				'label'   => __( 'Push pattern', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'PCRE pattern with delimiters, e.g. /LEGO\\s*®/iu. Leave empty to skip on push.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'push_replacement',
				'label'   => __( 'Push replacement', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Replacement string used on push.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'pull_pattern',
				'label'   => __( 'Pull pattern', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Optional inverse pattern. Leave empty to skip on pull.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'pull_replacement',
				'label'   => __( 'Pull replacement', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Replacement string used on pull.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {
		return $this->run( $value, isset( $args['push_pattern'] ) ? $args['push_pattern'] : '', isset( $args['push_replacement'] ) ? $args['push_replacement'] : '' );
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $this->run( $value, isset( $args['pull_pattern'] ) ? $args['pull_pattern'] : '', isset( $args['pull_replacement'] ) ? $args['pull_replacement'] : '' );
	}

	private function run( $value, $pattern, $replacement ) {

		if ( '' === (string) $pattern || ! is_string( $value ) ) {
			return $value;
		}

		$result = @preg_replace( $pattern, (string) $replacement, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( null === $result ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Transformer:regex_replace] preg_replace returned null — invalid pattern?', 'warning', array( 'pattern' => $pattern ) );
			}
			return $value;
		}

		return $result;
	}
}
