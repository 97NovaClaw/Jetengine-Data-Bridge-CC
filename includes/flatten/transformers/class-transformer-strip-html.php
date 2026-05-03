<?php
/**
 * Strip HTML — push converts HTML to plain text; pull is a no-op
 * (HTML can't be reconstructed from plain text).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Strip_HTML implements JEDB_Transformer {

	public function get_name()        { return 'strip_html'; }
	public function get_label()       { return __( 'Strip HTML', 'je-data-bridge-cc' ); }
	public function get_description() { return __( 'Push: removes all HTML tags. Pull: no-op (HTML cannot be recovered from plain text).', 'je-data-bridge-cc' ); }

	public function get_args_schema() {
		return array(
			array(
				'name'    => 'allowed_tags',
				'label'   => __( 'Allowed tags', 'je-data-bridge-cc' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Optional space-separated list of tags to keep, e.g. "p br". Empty = strip everything.', 'je-data-bridge-cc' ),
			),
			array(
				'name'    => 'collapse_whitespace',
				'label'   => __( 'Collapse whitespace', 'je-data-bridge-cc' ),
				'type'    => 'checkbox',
				'default' => true,
				'help'    => __( 'Replace runs of whitespace (including newlines) with a single space and trim.', 'je-data-bridge-cc' ),
			),
		);
	}

	public function apply_push( $value, array $args = array(), array $context = array() ) {

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$allowed = '';
		if ( ! empty( $args['allowed_tags'] ) ) {
			$tags = preg_split( '/\s+/', (string) $args['allowed_tags'] );
			$bits = array();
			foreach ( $tags as $tag ) {
				$tag = trim( $tag );
				if ( '' !== $tag ) {
					$bits[] = '<' . $tag . '>';
				}
			}
			$allowed = implode( '', $bits );
		}

		$result = wp_strip_all_tags( $allowed ? strip_tags( $value, $allowed ) : $value, true );

		if ( ! empty( $args['collapse_whitespace'] ) ) {
			$result = trim( preg_replace( '/\s+/', ' ', $result ) );
		}

		return $result;
	}

	public function apply_pull( $value, array $args = array(), array $context = array() ) {
		return $value;
	}
}
