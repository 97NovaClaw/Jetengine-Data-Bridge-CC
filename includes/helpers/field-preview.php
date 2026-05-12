<?php
/**
 * Field-preview helpers for the Bridge meta box (alpha.6 / L-027).
 *
 * `jedb_render_field_preview()` returns an HTML-safe read-only preview
 * for a single field value, formatted per the JE field type. The
 * meta box template uses this for each surfaced mapping. All output
 * is escaped per-branch — the caller may emit our return value
 * without further wrapping (this function is the trust boundary).
 *
 * Why this exists: editing is delegated to JE's CCT edit page via the
 * modal-iframe flow (L-027). We only need to PREVIEW field values on
 * the product edit screen, which is much simpler than rendering
 * editable inputs for every JE field type.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'jedb_render_field_preview' ) ) :

/**
 * Render a read-only preview of one CCT field value.
 *
 * @param mixed  $value      The raw value from the source CCT row.
 * @param string $field_type JE-style field type slug: text, textarea,
 *                           wysiwyg, checkbox, boolean, select, radio,
 *                           media, gallery, date, time, repeater, etc.
 * @param array  $field      Optional context — currently unused except
 *                           for forward-compat (e.g. field['options']
 *                           for select label resolution if available).
 * @return string  HTML-safe preview markup.
 */
function jedb_render_field_preview( $value, $field_type = 'text', array $field = array() ) {

	$field_type = (string) $field_type;
	$field_type = '' !== $field_type ? strtolower( $field_type ) : 'text';

	switch ( $field_type ) {

		case 'checkbox':
		case 'boolean':
		case 'switch':
			$on = false;
			if ( is_bool( $value ) ) {
				$on = $value;
			} elseif ( is_string( $value ) ) {
				$lv = strtolower( trim( $value ) );
				$on = in_array( $lv, array( '1', 'true', 'yes', 'on' ), true );
			} elseif ( is_numeric( $value ) ) {
				$on = (int) $value !== 0;
			}
			$cls   = $on ? 'jedb-preview-bool jedb-preview-bool-on'  : 'jedb-preview-bool jedb-preview-bool-off';
			$glyph = $on ? '&#10003; ' . esc_html__( 'Yes', 'je-data-bridge-cc' )
			              : '&#9711; ' . esc_html__( 'No',  'je-data-bridge-cc' );
			return '<span class="' . esc_attr( $cls ) . '">' . $glyph . '</span>';

		case 'wysiwyg':
		case 'html':
			$html = is_scalar( $value ) ? (string) $value : '';
			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(empty)', 'je-data-bridge-cc' ) . '</span>';
			}
			$safe_html = wp_kses_post( $html );
			$plain     = wp_strip_all_tags( $safe_html );
			if ( strlen( $plain ) > 220 ) {
				$preview = esc_html( mb_substr( $plain, 0, 220 ) ) . '…';
				return '<details class="jedb-preview-wysiwyg"><summary>' . $preview . '</summary><div class="jedb-preview-wysiwyg-full">' . $safe_html . '</div></details>';
			}
			return '<div class="jedb-preview-wysiwyg">' . $safe_html . '</div>';

		case 'textarea':
			$s = is_scalar( $value ) ? (string) $value : '';
			if ( '' === trim( $s ) ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(empty)', 'je-data-bridge-cc' ) . '</span>';
			}
			if ( strlen( $s ) > 220 ) {
				return '<details class="jedb-preview-textarea"><summary>' . esc_html( mb_substr( $s, 0, 220 ) ) . '…</summary><pre>' . esc_html( $s ) . '</pre></details>';
			}
			return '<span class="jedb-preview-text">' . nl2br( esc_html( $s ) ) . '</span>';

		case 'media':
		case 'image':
		case 'attachment':
			$att_id = 0;
			if ( is_numeric( $value ) ) {
				$att_id = (int) $value;
			} elseif ( is_array( $value ) && isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
				$att_id = (int) $value['id'];
			} elseif ( is_string( $value ) && '' !== trim( $value ) && wp_attachment_is_image( (int) $value ) ) {
				$att_id = (int) $value;
			}

			if ( ! $att_id ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					return '<code class="jedb-preview-text">' . esc_html( $value ) . '</code>';
				}
				return '<span class="jedb-preview-empty">' . esc_html__( '(no media)', 'je-data-bridge-cc' ) . '</span>';
			}

			// alpha.9: image attachments still render a thumbnail (keep the
			// rich preview for the common case). Non-image attachments —
			// PDFs, audio, video, etc. — collapse to a plain "Has
			// attachment" label per user feedback: cheaper to render and
			// editors who want to inspect/replace open the modal anyway.
			$mime = (string) get_post_mime_type( $att_id );
			if ( 0 === strpos( $mime, 'image/' ) ) {
				$img = wp_get_attachment_image( $att_id, array( 80, 80 ), false, array( 'class' => 'jedb-preview-media-thumb' ) );
				if ( '' !== $img ) {
					return '<div class="jedb-preview-media">' . $img . '<small class="jedb-preview-media-id">#' . (int) $att_id . '</small></div>';
				}
			}

			return '<span class="jedb-preview-attachment">'
				. '<span class="dashicons dashicons-media-default"></span> '
				. esc_html__( 'Has attachment', 'je-data-bridge-cc' )
				. ' <small class="jedb-preview-media-id">#' . (int) $att_id . '</small>'
				. '</span>';

		case 'gallery':
		case 'media_gallery':
			$ids = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					if ( is_numeric( $v ) ) {
						$ids[] = (int) $v;
					} elseif ( is_array( $v ) && isset( $v['id'] ) && is_numeric( $v['id'] ) ) {
						$ids[] = (int) $v['id'];
					}
				}
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				foreach ( preg_split( '/[\s,]+/', $value ) as $chunk ) {
					if ( is_numeric( $chunk ) ) {
						$ids[] = (int) $chunk;
					}
				}
			}

			$ids = array_values( array_unique( array_filter( $ids ) ) );
			if ( empty( $ids ) ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(no gallery items)', 'je-data-bridge-cc' ) . '</span>';
			}

			$shown   = array_slice( $ids, 0, 5 );
			$extra   = count( $ids ) - count( $shown );
			$thumbs  = '';
			foreach ( $shown as $att_id ) {
				$img = wp_get_attachment_image( $att_id, array( 60, 60 ), false, array( 'class' => 'jedb-preview-media-thumb' ) );
				$thumbs .= '' !== $img ? $img : '<span class="jedb-preview-media-id">#' . (int) $att_id . '</span>';
			}
			$tail = $extra > 0
				? '<span class="jedb-preview-gallery-more">+' . (int) $extra . '</span>'
				: '';
			return '<div class="jedb-preview-gallery">' . $thumbs . $tail . '</div>';

		case 'select':
		case 'radio':
			$display = '';
			if ( is_scalar( $value ) ) {
				$display = (string) $value;
			} elseif ( is_array( $value ) && isset( $value['value'] ) && is_scalar( $value['value'] ) ) {
				$display = (string) $value['value'];
			}

			$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
			if ( ! empty( $options ) && isset( $options[ $display ] ) ) {
				$display = (string) $options[ $display ];
			}

			if ( '' === $display ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(none selected)', 'je-data-bridge-cc' ) . '</span>';
			}
			return '<span class="jedb-preview-select"><code>' . esc_html( $display ) . '</code></span>';

		case 'checkbox_multi':
		case 'multiselect':
			$list = array();
			if ( is_array( $value ) ) {
				$list = array_map( 'strval', $value );
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				$list = array_map( 'trim', explode( ',', $value ) );
			}
			if ( empty( $list ) ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(no selections)', 'je-data-bridge-cc' ) . '</span>';
			}
			$out = '';
			foreach ( $list as $item ) {
				$out .= '<code class="jedb-preview-multi-item">' . esc_html( $item ) . '</code>';
			}
			return '<div class="jedb-preview-multi">' . $out . '</div>';

		case 'date':
		case 'date-picker':
		case 'datepicker':
			if ( '' === (string) $value ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(no date)', 'je-data-bridge-cc' ) . '</span>';
			}
			$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
			if ( ! $ts ) {
				return '<code>' . esc_html( (string) $value ) . '</code>';
			}
			return '<span class="jedb-preview-date">' . esc_html( date_i18n( get_option( 'date_format' ), $ts ) ) . '</span>';

		case 'time':
		case 'datetime':
		case 'datetime-local':
			if ( '' === (string) $value ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(no time)', 'je-data-bridge-cc' ) . '</span>';
			}
			$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
			if ( ! $ts ) {
				return '<code>' . esc_html( (string) $value ) . '</code>';
			}
			$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			return '<span class="jedb-preview-date">' . esc_html( date_i18n( $fmt, $ts ) ) . '</span>';

		case 'number':
		case 'integer':
		case 'float':
			if ( '' === (string) $value || null === $value ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(empty)', 'je-data-bridge-cc' ) . '</span>';
			}
			return '<span class="jedb-preview-number">' . esc_html( (string) $value ) . '</span>';

		case 'repeater':
			$count = 0;
			if ( is_array( $value ) ) {
				$count = count( $value );
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				$decoded = json_decode( $value, true );
				$count   = is_array( $decoded ) ? count( $decoded ) : 0;
			}
			if ( $count <= 0 ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(no items)', 'je-data-bridge-cc' ) . '</span>';
			}
			return '<span class="jedb-preview-repeater">' . sprintf(
				/* translators: %d = number of repeater items */
				esc_html( _n( '%d item', '%d items', (int) $count, 'je-data-bridge-cc' ) ),
				(int) $count
			) . ' — <em>' . esc_html__( 'open the editor to view / edit', 'je-data-bridge-cc' ) . '</em></span>';

		case 'posts':
		case 'relation':
			$ids = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					if ( is_numeric( $v ) ) {
						$ids[] = (int) $v;
					}
				}
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				foreach ( preg_split( '/[\s,]+/', $value ) as $chunk ) {
					if ( is_numeric( $chunk ) ) {
						$ids[] = (int) $chunk;
					}
				}
			} elseif ( is_numeric( $value ) ) {
				$ids[] = (int) $value;
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			if ( empty( $ids ) ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(no linked posts)', 'je-data-bridge-cc' ) . '</span>';
			}
			$out = array();
			foreach ( array_slice( $ids, 0, 5 ) as $pid ) {
				$title = (string) get_the_title( $pid );
				$out[] = '' !== $title ? esc_html( $title ) . ' <small>#' . (int) $pid . '</small>' : '<code>#' . (int) $pid . '</code>';
			}
			$tail = count( $ids ) > 5 ? ' <small>(+' . ( count( $ids ) - 5 ) . ' more)</small>' : '';
			return '<div class="jedb-preview-posts">' . implode( ', ', $out ) . $tail . '</div>';

		case 'text':
		case 'slug':
		case 'email':
		case 'url':
		default:
			if ( null === $value || '' === (string) $value ) {
				return '<span class="jedb-preview-empty">' . esc_html__( '(empty)', 'je-data-bridge-cc' ) . '</span>';
			}
			if ( is_array( $value ) ) {
				return '<code class="jedb-preview-text">' . esc_html( wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</code>';
			}
			$s = (string) $value;
			if ( 'url' === $field_type && filter_var( $s, FILTER_VALIDATE_URL ) ) {
				return '<a class="jedb-preview-link" href="' . esc_url( $s ) . '" target="_blank" rel="noopener">' . esc_html( $s ) . '</a>';
			}
			if ( 'email' === $field_type && is_email( $s ) ) {
				return '<a class="jedb-preview-link" href="mailto:' . esc_attr( $s ) . '">' . esc_html( $s ) . '</a>';
			}
			if ( strlen( $s ) > 160 ) {
				return '<details class="jedb-preview-text-long"><summary>' . esc_html( mb_substr( $s, 0, 160 ) ) . '…</summary><pre>' . esc_html( $s ) . '</pre></details>';
			}
			return '<span class="jedb-preview-text">' . esc_html( $s ) . '</span>';
	}
}

endif;
