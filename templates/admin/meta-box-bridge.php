<?php
/**
 * Linked-state template for one bridge in the Woo product Bridge meta box.
 *
 * Phase 4 / Day 2 (D-27). Rendered by JEDB_Woo_Product_Meta_Box::
 * render_linked_panel(). Variables in scope (set by the caller):
 *
 *   @var WP_Post                $post              The product / variation being edited.
 *   @var array                  $bridge            Decoded flatten config row.
 *   @var array                  $config            Inner config payload (= $bridge['config']).
 *   @var array                  $meta_box_cfg      The bridge's meta_box block.
 *   @var array                  $mappings          The bridge's mappings.
 *   @var array                  $resolution        { source_id, source_data, source_adapter, target_adapter, resolution }
 *   @var int                    $source_id
 *   @var array                  $source_data
 *   @var string                 $source_label
 *   @var array                  $surfaced_groups   array<int,array{label:string,fields:array<int,array>}>
 *   @var array                  $recent_log        Top-3 sync_log rows.
 *   @var bool                   $lock_value        Current `_jedb_bridge_locked` post meta.
 *   @var string                 $override_value    Current `_jedb_bridge_direction_override` post meta.
 *   @var int                    $last_manual_id    Last manual sync's sync_log row id (for status badge).
 *   @var string                 $bridge_label
 *   @var string                 $panel_title
 *   @var string                 $flatten_edit_url  Deep link to the bridge's row in the Flatten admin tab.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$bridge_id     = (int) ( $bridge['id'] ?? 0 );
$direction     = isset( $bridge['direction'] ) ? (string) $bridge['direction'] : 'push';
$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
$ajax_url      = admin_url( 'admin-post.php' );
?>
<div class="jedb-bridge-panel jedb-bridge-panel-linked" data-bridge-id="<?php echo esc_attr( $bridge_id ); ?>">

	<input type="hidden" name="jedb_meta_box_present" value="1" />

	<h3 class="jedb-bridge-panel-title">
		<?php echo esc_html( $panel_title ); ?>
		<span class="jedb-bridge-panel-status jedb-pill jedb-pill-ok"><?php esc_html_e( 'Linked', 'je-data-bridge-cc' ); ?></span>
	</h3>

	<div class="jedb-bridge-panel-meta">
		<p>
			<strong><?php esc_html_e( 'Linked source:', 'je-data-bridge-cc' ); ?></strong>
			<code><?php echo esc_html( $source_target ); ?> #<?php echo esc_html( $source_id ); ?></code>
			— <?php echo esc_html( $source_label ); ?>
			<span class="jedb-bridge-resolution">
				<?php
				/* translators: %s = resolution method (relation_row / fallback_single_page / cct_single_post_id) */
				printf( esc_html__( 'via %s', 'je-data-bridge-cc' ), '<code>' . esc_html( $resolution['resolution'] ) . '</code>' );
				?>
			</span>
		</p>
		<p>
			<strong><?php esc_html_e( 'Bridge config:', 'je-data-bridge-cc' ); ?></strong>
			<code><?php echo esc_html( $bridge['config_slug'] ?? '' ); ?></code>
			· <code><?php echo esc_html( $direction ); ?></code>
			· <a href="<?php echo esc_url( $flatten_edit_url ); ?>"><?php esc_html_e( 'Edit in Flatten tab →', 'je-data-bridge-cc' ); ?></a>
		</p>
	</div>

	<?php /* ----- Surfaced fields ----- */ ?>
	<?php if ( ! empty( $surfaced_groups ) ) : ?>
		<div class="jedb-surfaced-fields">
			<?php foreach ( $surfaced_groups as $group ) : ?>
				<fieldset class="jedb-surfaced-group">
					<legend><?php echo esc_html( $group['label'] ); ?></legend>
					<?php foreach ( $group['fields'] as $field ) :
						$input_id    = sprintf( 'jedb-surfaced-%d-%s', $bridge_id, sanitize_html_class( $field['source_field'] ) );
						$input_name  = sprintf( 'jedb_surfaced[%d][%s]', $bridge_id, $field['source_field'] );
						$field_type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
						$value       = $field['value'];
					?>
						<div class="jedb-surfaced-row">
							<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
							<?php if ( 'textarea' === $field_type || 'wysiwyg' === $field_type ) : ?>
								<textarea id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" rows="3" class="widefat"><?php echo esc_textarea( is_scalar( $value ) ? (string) $value : '' ); ?></textarea>
							<?php elseif ( 'checkbox' === $field_type || 'boolean' === $field_type ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $input_name ); ?>" value="0" />
								<input id="<?php echo esc_attr( $input_id ); ?>" type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> />
							<?php else : ?>
								<input id="<?php echo esc_attr( $input_id ); ?>" type="text" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>" class="widefat" />
							<?php endif; ?>
							<?php if ( '' !== (string) $field['note'] ) : ?>
								<p class="description"><?php echo esc_html( $field['note'] ); ?></p>
							<?php endif; ?>
							<p class="jedb-surfaced-meta">
								<code><?php echo esc_html( $field['source_field'] ); ?></code>
								→
								<code><?php echo esc_html( $field['target_field'] ); ?></code>
							</p>
						</div>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'These fields are sourced from the linked CCT row. Edits saved here write back through the reverse-pull engine — same code path as a normal CCT edit.', 'je-data-bridge-cc' ); ?>
			</p>
		</div>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No fields configured for surfacing here. Tick "Target" in the Meta box column of any mapping in the Flatten admin tab to surface a field on this screen.', 'je-data-bridge-cc' ); ?></p>
	<?php endif; ?>

	<?php /* ----- Per-product overrides ----- */ ?>
	<fieldset class="jedb-bridge-overrides">
		<legend><?php esc_html_e( 'Per-product overrides', 'je-data-bridge-cc' ); ?></legend>

		<label class="jedb-override-lock">
			<input type="checkbox" name="jedb_bridge_locked" value="1" <?php checked( $lock_value ); ?> />
			<?php esc_html_e( 'Freeze sync for this product (skip both directions)', 'je-data-bridge-cc' ); ?>
		</label>

		<div class="jedb-override-direction">
			<strong><?php esc_html_e( 'Direction override:', 'je-data-bridge-cc' ); ?></strong>
			<label><input type="radio" name="jedb_bridge_direction_override" value=""              <?php checked( $override_value, '' ); ?> /> <?php esc_html_e( 'None (use bridge default)', 'je-data-bridge-cc' ); ?></label>
			<label><input type="radio" name="jedb_bridge_direction_override" value="push"          <?php checked( $override_value, 'push' ); ?> /> <?php esc_html_e( 'Push only', 'je-data-bridge-cc' ); ?></label>
			<label><input type="radio" name="jedb_bridge_direction_override" value="pull"          <?php checked( $override_value, 'pull' ); ?> /> <?php esc_html_e( 'Pull only', 'je-data-bridge-cc' ); ?></label>
			<label><input type="radio" name="jedb_bridge_direction_override" value="bidirectional" <?php checked( $override_value, 'bidirectional' ); ?> /> <?php esc_html_e( 'Bidirectional', 'je-data-bridge-cc' ); ?></label>
			<label><input type="radio" name="jedb_bridge_direction_override" value="none"          <?php checked( $override_value, 'none' ); ?> /> <?php esc_html_e( 'None — disable both directions', 'je-data-bridge-cc' ); ?></label>
		</div>
		<p class="description">
			<?php esc_html_e( 'These overrides are stored as post meta and respected by the engine guards added in v0.6.0-alpha.3. They do NOT modify the bridge config itself — toggle them off to restore default behavior.', 'je-data-bridge-cc' ); ?>
		</p>
	</fieldset>

	<?php /* ----- Last syncs ----- */ ?>
	<?php if ( ! empty( $recent_log ) ) : ?>
		<div class="jedb-bridge-recent-log">
			<strong><?php esc_html_e( 'Recent syncs:', 'je-data-bridge-cc' ); ?></strong>
			<ul>
				<?php foreach ( $recent_log as $row ) :
					$row_status = (string) ( $row['status'] ?? '' );
					$row_msg    = (string) ( $row['message'] ?? '' );
					$row_dir    = (string) ( $row['direction'] ?? '' );
					$row_origin = (string) ( $row['origin'] ?? '' );
					$row_when   = (string) ( $row['created_at'] ?? '' );
					$pill_class = 'jedb-pill';
					if ( 'success' === $row_status )       { $pill_class .= ' jedb-pill-ok'; }
					elseif ( 'noop' === $row_status )      { $pill_class .= ' jedb-pill-info'; }
					elseif ( 0 === strpos( $row_status, 'skipped' ) ) { $pill_class .= ' jedb-pill-warn'; }
					else                                   { $pill_class .= ' jedb-pill-bad'; }
				?>
					<li>
						<span class="<?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( $row_status ); ?></span>
						<code><?php echo esc_html( $row_dir ); ?></code>
						·
						<small><?php echo esc_html( $row_when ); ?> UTC</small>
						·
						<small><?php echo esc_html( $row_origin ); ?></small>
						<?php if ( '' !== $row_msg ) : ?>
							<br><span class="jedb-recent-msg"><?php echo esc_html( $row_msg ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php /* ----- Action buttons (separate forms so they don't double-submit the parent product save) ----- */ ?>
	<div class="jedb-bridge-actions">

		<form method="post" action="<?php echo esc_url( $ajax_url ); ?>" style="display:inline-block;">
			<?php wp_nonce_field( JEDB_Woo_Product_Meta_Box::NONCE_SAVE, JEDB_Woo_Product_Meta_Box::NONCE_SAVE_FIELD ); ?>
			<input type="hidden" name="action"    value="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::ACTION_SYNC_NOW ); ?>" />
			<input type="hidden" name="post_id"   value="<?php echo (int) $post->ID; ?>" />
			<input type="hidden" name="bridge_id" value="<?php echo (int) $bridge_id; ?>" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Sync now (push from source)', 'je-data-bridge-cc' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( $ajax_url ); ?>" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('<?php echo esc_js( __( 'Unlink this product from its source CCT row? The CCT row will not be deleted — only the JE Relation row between them. You can re-link below.', 'je-data-bridge-cc' ) ); ?>');">
			<?php wp_nonce_field( JEDB_Woo_Product_Meta_Box::NONCE_SAVE, JEDB_Woo_Product_Meta_Box::NONCE_SAVE_FIELD ); ?>
			<input type="hidden" name="action"    value="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::ACTION_UNLINK ); ?>" />
			<input type="hidden" name="post_id"   value="<?php echo (int) $post->ID; ?>" />
			<input type="hidden" name="bridge_id" value="<?php echo (int) $bridge_id; ?>" />
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Unlink', 'je-data-bridge-cc' ); ?></button>
		</form>

	</div>

</div>
