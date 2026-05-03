<?php
/**
 * Name Builder — assemble a string from other source fields via a template.
 *
 * Generalized from PAC VDM's `class-config-name-generator.php`. The template
 * uses `{field_name}` placeholders that resolve against the SOURCE record
 * (read from `$context['source_data']`).
 *
 * Push only: pull is intentionally a no-op because there is no general way
 * to factor a built name back into its component fields. To round-trip,
 * pair this with regex_replace or a snippet on the pull side.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Name_Builder implements JEDB_Transformer {

	public function get_name()        { return 'name_builder'; }
	public function get_label()       { return __( 'Name Builder (template)', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Assemble a string from source fields. e.g. "{set_name} ({set_number}) — {theme}". Push only.', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'template',
				'label'   => __( 'Template', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '{name}',
				'help'    => __( 'Use {field_name} placeholders. Resolves against the source record. Empty placeholders become empty strings.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'collapse_whitespace',
				'label'   => __( 'Collapse multiple spaces', 'je-data-bridge-cc' ),
				'type'    => 'checkbox',
				'default' => true,
				'help'    => __( 'Replace runs of whitespace with a single space and trim the result.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {

		$template = isset( $args['template'] ) ? (string) $args['template'] : '';
		if ( '' === $template ) {
			return $value;
		}

		$source_data = isset( $context['source_data'] ) && is_array( $context['source_data'] )
			? $context['source_data']
			: array();

		$result = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( $m ) use ( $source_data, $value ) {
				$key = $m[1];
				if ( '_value' === $key ) {
					return is_scalar( $value ) ? (string) $value : '';
				}
				if ( array_key_exists( $key, $source_data ) ) {
					$v = $source_data[ $key ];
					return is_scalar( $v ) ? (string) $v : '';
				}
				return '';
			},
			$template
		);

		if ( ! empty( $args['collapse_whitespace'] ) ) {
			$result = trim( preg_replace( '/\s+/', ' ', (string) $result ) );
		}

		return $result;
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $value;
	}
}
