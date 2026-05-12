<?php
/**
 * Unlinked-state template for one bridge in the Woo product Bridge meta box.
 *
 * Phase 4 / Day 2 (D-27). Rendered by JEDB_Woo_Product_Meta_Box::
 * render_unlinked_panel(). Variables in scope (set by the caller):
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

$bridge_id     = (int) ( $bridge['id'] ?? 0 );
$direction     = isset( $bridge['direction'] ) ? (string) $bridge['direction'] : 'push';
$ajax_url      = admin_url( 'admin-post.php' );

$link_via      = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
$link_type     = isset( $link_via['type'] ) ? (string) $link_via['type'] : 'je_relation';
$relation_id   = isset( $link_via['relation_id'] ) ? (string) $link_via['relation_id'] : '';
?>
<div class="jedb-bridge-panel jedb-bridge-panel-unlinked" data-bridge-id="<?php echo esc_attr( $bridge_id ); ?>" data-source-target="<?php echo esc_attr( $source_target ); ?>" data-relations-nonce="<?php echo esc_attr( $relations_nonce ); ?>">

	<input type="hidden" name="jedb_meta_box_present" value="1" />

	<h3 class="jedb-bridge-panel-title">
		<?php echo esc_html( $panel_title ); ?>
		<span class="jedb-bridge-panel-status jedb-pill jedb-pill-warn"><?php esc_html_e( 'Not linked', 'je-data-bridge-cc' ); ?></span>
	</h3>

	<div class="jedb-bridge-panel-meta">
		<p>
			<strong><?php esc_html_e( 'Bridge config:', 'je-data-bridge-cc' ); ?></strong>
			<code><?php echo esc_html( $bridge['config_slug'] ?? '' ); ?></code>
			· <code><?php echo esc_html( $direction ); ?></code>
			·
			<?php
			/* translators: %s = source target slug */
			printf( esc_html__( 'source: %s', 'je-data-bridge-cc' ), '<code>' . esc_html( $source_target ) . '</code>' );
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'This product is not yet linked to a source CCT row via this bridge. Search for a CCT row below to attach it. Once linked, the bridge will sync on every save event in both directions (per the bridge\'s direction setting).', 'je-data-bridge-cc' ); ?>
		</p>
	</div>

	<?php if ( 'je_relation' === $link_type ) : ?>
		<?php
		/* alpha.6.1: NO `<form>` here — see meta-box-bridge.php for
		 * the rationale (HTML5 forbids nested forms; meta boxes are
		 * already inside `#post`; the broken parse pushes the WP
		 * Update button outside any form and breaks regular product
		 * saves). Container is a `<div>` with data attributes; the
		 * JS handler builds the real form off-DOM on submit. */
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
			<div class="jedb-link-picker">
				<label for="jedb-link-search-<?php echo (int) $bridge_id; ?>">
					<?php esc_html_e( 'Search for a source CCT row:', 'je-data-bridge-cc' ); ?>
				</label>
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
			</div>

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
