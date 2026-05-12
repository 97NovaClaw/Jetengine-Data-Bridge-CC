<?php
/**
 * Unlinked-state template for one bridge in the Woo product Bridge meta box.
 *
 * alpha.9 rewrite. Minimal native-WP look, paired with the linked template's
 * native form-table style. The WP meta box itself is the bridge's named
 * container (one box per bridge — see register_meta_boxes), so this template
 * is just the inner content: a brief explanation + the CCT row search picker
 * + a Link button. No custom panel chrome / pills / colored borders.
 *
 * Rendered by JEDB_Woo_Product_Meta_Box::render_unlinked_panel(). Variables
 * in scope (set by the caller):
 *
 *   @var WP_Post $post              The product / variation being edited.
 *   @var array   $bridge            Decoded flatten config row.
 *   @var array   $config            Inner config payload.
 *   @var array   $meta_box_cfg      The bridge's meta_box block.
 *   @var array   $resolution        Read-only resolution result (source_id = 0).
 *   @var string  $bridge_label
 *   @var string  $panel_title
 *   @var string  $source_target     "cct::mosaics_data" etc.
 *   @var string  $relations_nonce   Nonce for the relation-search AJAX endpoint.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$bridge_id   = (int) ( $bridge['id'] ?? 0 );
$direction   = isset( $bridge['direction'] ) ? (string) $bridge['direction'] : 'push';
$ajax_url    = admin_url( 'admin-post.php' );

$link_via    = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
$link_type   = isset( $link_via['type'] ) ? (string) $link_via['type'] : 'je_relation';
$relation_id = isset( $link_via['relation_id'] ) ? (string) $link_via['relation_id'] : '';
?>
<div
	class="jedb-bridge-panel jedb-bridge-panel-unlinked"
	data-bridge-id="<?php echo esc_attr( $bridge_id ); ?>"
	data-source-target="<?php echo esc_attr( $source_target ); ?>"
	data-relations-nonce="<?php echo esc_attr( $relations_nonce ); ?>"
>

	<input type="hidden" name="jedb_meta_box_present" value="1" />

	<p class="description">
		<?php esc_html_e( 'This product is not yet linked to a source CCT row via this bridge. Once linked, the bridge will sync on every save event in both directions (per the bridge\'s direction setting).', 'je-data-bridge-cc' ); ?>
	</p>

	<?php if ( 'je_relation' === $link_type ) : ?>
		<?php
		/* No inner <form> per L-028 — meta boxes are inside #post and HTML5
		 * forbids nested forms. JS builds the real form off-DOM on click. */
		?>
		<div
			class="jedb-bridge-link-form"
			data-jedb-form-action="<?php echo esc_url( $ajax_url ); ?>"
			data-jedb-nonce-field="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::NONCE_SAVE_FIELD ); ?>"
			data-jedb-nonce-value="<?php echo esc_attr( wp_create_nonce( JEDB_Woo_Product_Meta_Box::NONCE_SAVE ) ); ?>"
			data-jedb-post-id="<?php echo (int) $post->ID; ?>"
			data-jedb-bridge-id="<?php echo (int) $bridge_id; ?>"
			data-jedb-action="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::ACTION_LINK ); ?>"
		>
			<p>
				<label for="jedb-link-search-<?php echo (int) $bridge_id; ?>">
					<strong><?php esc_html_e( 'Search for a source CCT row:', 'je-data-bridge-cc' ); ?></strong>
				</label>
			</p>
			<input
				id="jedb-link-search-<?php echo (int) $bridge_id; ?>"
				type="search"
				class="jedb-link-search regular-text"
				autocomplete="off"
				placeholder="<?php esc_attr_e( 'Type to search…', 'je-data-bridge-cc' ); ?>"
			/>
			<select
				class="jedb-link-results"
				data-jedb-field-name="source_id"
				size="6"
				required
				style="display:block;width:100%;margin-top:6px;min-height:120px;"
			>
				<option value=""><?php esc_html_e( '— Search above, then pick a result —', 'je-data-bridge-cc' ); ?></option>
			</select>
			<p class="description jedb-link-status"></p>

			<p>
				<button type="button" class="button button-primary jedb-bridge-link-btn">
					<?php
					/* translators: %s = relation id */
					printf( esc_html__( 'Link via JE Relation #%s', 'je-data-bridge-cc' ), esc_html( $relation_id ) );
					?>
				</button>
			</p>
		</div>
	<?php elseif ( 'cct_single_post_id' === $link_type ) : ?>
		<p class="description">
			<?php esc_html_e( 'This bridge links via "Has Single Page" — JetEngine manages the link from the CCT side. Edit the linked CCT row in JE → Custom Content Types and set its single page to this product.', 'je-data-bridge-cc' ); ?>
		</p>
	<?php else : ?>
		<p class="description">
			<?php
			/* translators: %s = link_via type */
			printf( esc_html__( 'Unknown link_via type "%s" on this bridge. Edit the bridge in the Flatten admin tab to fix the configuration.', 'je-data-bridge-cc' ), esc_html( $link_type ) );
			?>
		</p>
	<?php endif; ?>

</div>
