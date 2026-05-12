<?php
/**
 * Linked-state template for one bridge in the Woo product Bridge meta box.
 *
 * Phase 4 / Day 2 (D-27), rewritten alpha.6 (modal-iframe architecture
 * per L-027): no editable inputs are rendered here. Each surfaced
 * mapping becomes a read-only, type-aware preview. Editing is
 * delegated to JE's actual CCT edit page via a chrome-stripped modal
 * iframe — every JE field type (select / media / gallery / WYSIWYG /
 * repeater / etc.) works because JE itself renders them. JE's normal
 * save fires its `updated-item/{slug}` hook, which triggers the
 * forward push engine naturally, so the alpha.5 explicit-apply_bridge
 * workaround is no longer needed (L-022's "broken-hook" disposition
 * becomes a non-issue for this flow).
 *
 * Rendered by JEDB_Woo_Product_Meta_Box::render_linked_panel().
 * Variables in scope (set by the caller):
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
 *   @var array                  $surface_skipped   array<int,array{source_field:string,target_field:string,reason:string}>
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

	<?php
	/* ----- Surfaced fields — READ-ONLY previews (alpha.6) -----
	 * Per the modal-iframe architecture: the meta box no longer renders
	 * editable inputs for CCT fields. Instead, each surfaced field is a
	 * type-aware READ-ONLY PREVIEW. Editing happens by clicking the
	 * "Save & edit CCT row" button below, which launches a modal with
	 * the JE CCT edit page chrome-stripped — every JE field type works
	 * natively because JE itself is doing the rendering.
	 *
	 * This eliminates:
	 *   - The alpha.5 explicit-apply_bridge hack (no source writes from
	 *     meta box, so L-022 doesn't bite — JE's normal save fires its
	 *     `updated-item/{slug}` hook and forward push runs naturally).
	 *   - The need to reimplement every JE field type.
	 *   - The "double work" concern the user raised in alpha.5 review.
	 */
	?>
	<?php if ( ! empty( $surfaced_groups ) ) : ?>
		<div class="jedb-surfaced-fields jedb-surfaced-fields-readonly">
			<?php foreach ( $surfaced_groups as $group ) : ?>
				<fieldset class="jedb-surfaced-group">
					<legend><?php echo esc_html( $group['label'] ); ?></legend>
					<?php foreach ( $group['fields'] as $field ) :
						$field_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
						$value      = $field['value'];
					?>
						<div class="jedb-surfaced-row jedb-surfaced-readonly">
							<div class="jedb-surfaced-row-label"><?php echo esc_html( $field['label'] ); ?></div>
							<div class="jedb-surfaced-row-value">
								<?php echo jedb_render_field_preview( $value, $field_type, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes internally per field type ?>
							</div>
							<?php if ( '' !== (string) $field['note'] ) : ?>
								<p class="description"><?php echo esc_html( $field['note'] ); ?></p>
							<?php endif; ?>
							<p class="jedb-surfaced-meta">
								<code><?php echo esc_html( $field['source_field'] ); ?></code>
								<?php if ( ! empty( $field['target_field'] ) ) : ?>
									→
									<code><?php echo esc_html( $field['target_field'] ); ?></code>
								<?php endif; ?>
							</p>
						</div>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
	<?php elseif ( ! empty( $surface_skipped ) ) : ?>
		<div class="jedb-surfaced-fields jedb-surfaced-fields-empty">
			<p class="description">
				<strong><?php esc_html_e( 'Mappings flagged for surface but skipped:', 'je-data-bridge-cc' ); ?></strong>
			</p>
			<ul class="jedb-surface-skipped-list">
				<?php foreach ( $surface_skipped as $sk ) :
					$lbl_src = '' !== $sk['source_field'] ? $sk['source_field'] : __( '(empty)', 'je-data-bridge-cc' );
					$lbl_tgt = '' !== $sk['target_field'] ? $sk['target_field'] : __( '(empty)', 'je-data-bridge-cc' );
				?>
					<li>
						<code><?php echo esc_html( $lbl_src ); ?></code>
						→
						<code><?php echo esc_html( $lbl_tgt ); ?></code>
						—
						<span class="jedb-surface-skipped-reason"><?php echo esc_html( $sk['reason'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php esc_html_e( 'Fix the source_field (or other skip reason) in the Flatten admin tab to surface these fields here.', 'je-data-bridge-cc' ); ?>
			</p>
		</div>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No fields configured for surfacing here. Tick "Target" in the Meta box column of any mapping in the Flatten admin tab to surface a field on this screen.', 'je-data-bridge-cc' ); ?></p>
	<?php endif; ?>

	<?php
	/* ----- "Save & edit CCT row" button (alpha.6 modal launcher) ----- */
	$cct_edit_url = '';
	$source_cct_slug = '';
	if ( '' !== $source_target && 0 === strpos( $source_target, 'cct::' ) ) {
		$source_cct_slug = substr( $source_target, 5 );
		$cct_edit_url    = add_query_arg(
			array(
				'page'        => 'jet-cct-' . $source_cct_slug,
				'cct_action'  => 'edit',
				'item_id'     => (int) $source_id,
				'jedb_chrome' => 'stripped',
				'jedb_return' => (int) $post->ID,
			),
			admin_url( 'admin.php' )
		);
	}
	?>
	<?php if ( '' !== $cct_edit_url ) : ?>
		<div class="jedb-bridge-cct-edit-launch">
			<button
				type="button"
				class="button button-secondary jedb-open-cct-modal"
				data-cct-edit-url="<?php echo esc_url( $cct_edit_url ); ?>"
				data-bridge-id="<?php echo esc_attr( $bridge_id ); ?>"
				data-source-id="<?php echo esc_attr( $source_id ); ?>"
				data-source-label="<?php echo esc_attr( $source_label ); ?>"
			>
				<span class="dashicons dashicons-edit"></span>
				<?php
				/* translators: %s = the linked CCT row's display label */
				printf( esc_html__( 'Save & edit "%s" in JetEngine', 'je-data-bridge-cc' ), esc_html( $source_label ) );
				?>
			</button>
			<p class="description" style="margin-top:6px;">
				<?php esc_html_e( 'Opens the linked CCT row in a focused editor. All JE field types (select, media, gallery, WYSIWYG, repeater, etc.) render natively. When you save in there, this product page reloads and the new values flow back via the existing sync engine.', 'je-data-bridge-cc' ); ?>
			</p>
		</div>
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
