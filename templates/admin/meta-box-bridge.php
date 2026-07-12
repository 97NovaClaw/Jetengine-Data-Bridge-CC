<?php
/**
 * Linked-state template for one bridge in the Woo product Bridge meta box.
 *
 * alpha.9 (L-031) rewrite. The WP meta box itself now serves as the bridge's
 * named container (one box per bridge — see register_meta_boxes), so this
 * template is just the INNER content: surfaced field previews + the modal
 * launcher button + (optionally, when meta_box.show_advanced=true) an
 * Advanced Details collapsible at the bottom.
 *
 * Visual goal: native WP look. <table class="form-table"> for surfaced
 * fields, plain .description for helper text, .button.button-primary for
 * the modal launcher, native <details> for the advanced section. No
 * custom panel chrome / pills / colored borders.
 *
 * Rendered by JEDB_Woo_Product_Meta_Box::render_linked_panel(). Variables
 * in scope (set by the caller):
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
 *   @var array                  $recent_log        Top-3 sync_log rows (empty when show_advanced=false).
 *   @var bool                   $lock_value
 *   @var string                 $override_value
 *   @var int                    $last_manual_id
 *   @var string                 $bridge_label
 *   @var string                 $panel_title
 *   @var string                 $flatten_edit_url
 *   @var bool                   $show_advanced     Render the <details> Advanced section at bottom.
 *   @var array                  $coverage_required Effective mandatory fields (empty when show_advanced=false).
 *   @var array                  $coverage_missing  Subset of coverage_required not yet mapped.
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

	<?php
	/* ----- Surfaced fields — READ-ONLY previews -----
	 *
	 * Per L-027: the meta box never renders editable inputs for CCT
	 * fields. Each surfaced mapping is a type-aware read-only preview;
	 * editing is delegated to JE's CCT edit page via the chrome-stripped
	 * modal iframe launched by the button below.
	 *
	 * alpha.9: rendered as a native WP <table class="form-table"> for a
	 * consistent admin look. Groups become <tbody> sections with an
	 * <th colspan="2"> header row when there's more than one group.
	 */
	?>
	<?php if ( ! empty( $surfaced_groups ) ) : ?>
		<table class="form-table jedb-surfaced-table" role="presentation">
			<?php
			$show_group_headers = count( $surfaced_groups ) > 1;
			foreach ( $surfaced_groups as $group ) :
			?>
				<?php if ( $show_group_headers ) : ?>
					<tbody>
						<tr>
							<th colspan="2" scope="row" class="jedb-surfaced-group-header">
								<?php echo esc_html( $group['label'] ); ?>
							</th>
						</tr>
					</tbody>
				<?php endif; ?>
				<tbody>
					<?php foreach ( $group['fields'] as $field ) :
						$field_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
						$value      = $field['value'];
					?>
						<tr class="jedb-surfaced-row">
							<th scope="row">
								<?php echo esc_html( $field['label'] ); ?>
							</th>
							<td>
								<?php echo jedb_render_field_preview( $value, $field_type, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes per branch ?>
								<?php if ( '' !== (string) $field['note'] ) : ?>
									<p class="description"><?php echo esc_html( $field['note'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
	<?php elseif ( ! empty( $surface_skipped ) ) : ?>
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
					— <span class="jedb-surface-skipped-reason"><?php echo esc_html( $sk['reason'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Fix the source_field (or other skip reason) in the Flatten admin tab to surface these fields here.', 'je-data-bridge-cc' ); ?>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No fields configured for surfacing here. Tick "Target" in the Meta box column of any mapping in the Flatten admin tab to surface a field on this screen.', 'je-data-bridge-cc' ); ?></p>
	<?php endif; ?>

	<?php
	/* ----- "Save & edit CCT row" button — modal launcher ----- */
	$cct_edit_url    = '';
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
		<p class="jedb-bridge-cct-edit-launch">
			<button
				type="button"
				class="button button-primary jedb-open-cct-modal"
				data-cct-edit-url="<?php echo esc_url( $cct_edit_url ); ?>"
				data-bridge-id="<?php echo esc_attr( $bridge_id ); ?>"
				data-source-id="<?php echo esc_attr( $source_id ); ?>"
				data-source-label="<?php echo esc_attr( $source_label ); ?>"
			>
				<?php
				/* translators: %s = the linked CCT row's display label */
				printf( esc_html__( 'Save & edit "%s" in JetEngine', 'je-data-bridge-cc' ), esc_html( $source_label ) );
				?>
			</button>
		</p>
	<?php endif; ?>

	<?php
	/* =================================================================
	 * Advanced Details — opt-in via meta_box.show_advanced
	 *
	 * When the editor enables `show_advanced` on this bridge in the
	 * Flatten admin tab, the rest of the meta box surface (linked
	 * source info, bridge config link, per-product overrides, recent
	 * syncs, Sync now / Unlink actions) appears here inside a
	 * collapsed <details> element. Defaults closed so the visible
	 * surface stays minimal until the editor expands.
	 * ============================================================== */
	?>
	<?php if ( $show_advanced ) : ?>
	<details class="jedb-bridge-advanced">
		<summary><?php esc_html_e( 'Advanced Details', 'je-data-bridge-cc' ); ?></summary>

		<p class="description">
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

		<p class="description">
			<strong><?php esc_html_e( 'Bridge config:', 'je-data-bridge-cc' ); ?></strong>
			<code><?php echo esc_html( $bridge['config_slug'] ?? '' ); ?></code>
			· <code><?php echo esc_html( $direction ); ?></code>
			· <a href="<?php echo esc_url( $flatten_edit_url ); ?>"><?php esc_html_e( 'Edit in Flatten tab →', 'je-data-bridge-cc' ); ?></a>
		</p>

		<?php if ( ! empty( $has_variation_mappings ) ) : ?>
			<p class="description" style="padding:8px 10px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:2px;">
				<strong><?php esc_html_e( 'Managed variations:', 'je-data-bridge-cc' ); ?></strong>
				<?php
				printf(
					/* translators: %d = number of bridge-managed variations */
					esc_html( _n( '%d variation on this product is synced from the linked CCT\'s variation fields.', '%d variations on this product are synced from the linked CCT\'s variation fields.', (int) ( $managed_variation_count ?? 0 ), 'je-data-bridge-cc' ) ),
					(int) ( $managed_variation_count ?? 0 )
				);
				?>
				<?php esc_html_e( 'Manual edits to those variations (except stock) are overwritten on the next CCT save. Variations created manually are never touched.', 'je-data-bridge-cc' ); ?>
			</p>
		<?php endif; ?>

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

		<?php
		/* Mandatory coverage subsection — alpha.12 (Phase 4 Day 4).
		 * Compact summary of "how many required fields are mapped?" with
		 * a list of missing fields when any are absent. Editors who need
		 * more detail (apply preset, scaffold) go to the Flatten admin
		 * tab — the meta box surface only reports, doesn't author. */
		?>
		<?php if ( ! empty( $coverage_required ) ) :
			$total_required = count( $coverage_required );
			$missing_count  = count( $coverage_missing );
			$covered_count  = $total_required - $missing_count;
		?>
			<p><strong><?php esc_html_e( 'Mandatory coverage:', 'je-data-bridge-cc' ); ?></strong>
				<?php
				printf(
					/* translators: %1$d = covered, %2$d = total required */
					esc_html__( '%1$d of %2$d required fields mapped.', 'je-data-bridge-cc' ),
					(int) $covered_count,
					(int) $total_required
				);
				?>
			</p>
			<?php if ( $missing_count > 0 ) : ?>
				<ul class="jedb-bridge-coverage-missing">
					<?php foreach ( $coverage_missing as $row ) : ?>
						<li>
							<span class="jedb-coverage-badge" aria-hidden="true">&#9888;</span>
							<code><?php echo esc_html( $row['name'] ); ?></code>
							<small><?php
								if ( 'adapter' === $row['origin'] ) {
									esc_html_e( '(required by adapter)', 'je-data-bridge-cc' );
								} else {
									esc_html_e( '(required by override / preset)', 'je-data-bridge-cc' );
								}
							?></small>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description" style="margin-top:4px;">
					<?php
					printf(
						/* translators: %s = link to Flatten admin tab editor for this bridge */
						wp_kses_post( __( 'Add mappings for these fields in the <a href="%s">Flatten admin tab</a>, or apply / scaffold from a Field Preset there.', 'je-data-bridge-cc' ) ),
						esc_url( $flatten_edit_url )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! empty( $recent_log ) ) : ?>
			<p><strong><?php esc_html_e( 'Recent syncs:', 'je-data-bridge-cc' ); ?></strong></p>
			<ul class="jedb-bridge-recent-log">
				<?php foreach ( $recent_log as $row ) :
					$row_status = (string) ( $row['status'] ?? '' );
					$row_msg    = (string) ( $row['message'] ?? '' );
					$row_dir    = (string) ( $row['direction'] ?? '' );
					$row_origin = (string) ( $row['origin'] ?? '' );
					$row_when   = (string) ( $row['created_at'] ?? '' );
				?>
					<li>
						<code><?php echo esc_html( $row_status ); ?></code>
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
		<?php endif; ?>

		<?php
		/* Action buttons — `<button type="button">` + data attributes
		 * (no inline <form> per L-028; the JS handler builds an off-DOM
		 * form on click to avoid HTML nested-form invalidation against
		 * the parent #post). */
		?>
		<div
			class="jedb-bridge-actions"
			data-jedb-form-action="<?php echo esc_url( $ajax_url ); ?>"
			data-jedb-nonce-field="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::NONCE_SAVE_FIELD ); ?>"
			data-jedb-nonce-value="<?php echo esc_attr( wp_create_nonce( JEDB_Woo_Product_Meta_Box::NONCE_SAVE ) ); ?>"
			data-jedb-post-id="<?php echo (int) $post->ID; ?>"
			data-jedb-bridge-id="<?php echo (int) $bridge_id; ?>"
		>
			<button
				type="button"
				class="button jedb-bridge-action-btn"
				data-jedb-action="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::ACTION_SYNC_NOW ); ?>"
			><?php esc_html_e( 'Sync now (push from source)', 'je-data-bridge-cc' ); ?></button>

			<button
				type="button"
				class="button button-link-delete jedb-bridge-action-btn"
				data-jedb-action="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::ACTION_UNLINK ); ?>"
				data-jedb-confirm="<?php echo esc_attr__( 'Unlink this product from its source CCT row? The CCT row will not be deleted — only the JE Relation row between them. You can re-link below.', 'je-data-bridge-cc' ); ?>"
			><?php esc_html_e( 'Unlink', 'je-data-bridge-cc' ); ?></button>
		</div>
	</details>
	<?php endif; ?>

</div>
