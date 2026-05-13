<?php
/**
 * Flatten admin tab template.
 *
 * Renders:
 *   - List of existing flatten configs as cards
 *   - Add/edit form (always visible at the bottom; pre-filled when ?edit=ID)
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$tab     = JEDB_Tab_Flatten::instance();
$manager = JEDB_Flatten_Config_Manager::instance();

$edit_id   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing   = $edit_id ? $manager->get_by_id( $edit_id ) : null;
$is_edit   = (bool) $editing;
$config    = $editing && isset( $editing['config'] ) ? $editing['config'] : JEDB_Flatten_Config_Manager::default_config_json();

$all_bridges = $manager->get_all();

$source_options = $tab->get_eligible_source_targets();
$target_options = $tab->get_eligible_target_targets();

$current_source = $editing ? $editing['source_target'] : '';
$current_target = $editing ? $editing['target_target'] : '';
$relations      = $tab->get_relations_between( $current_source, $current_target );

$registry = JEDB_Target_Registry::instance();

$source_schema = array();
$target_schema = array();
$target_required = array();

if ( $current_source ) {
	$src_a = $registry->get( $current_source );
	if ( $src_a ) {
		$source_schema = $src_a->get_field_schema();
	}
}
if ( $current_target ) {
	$tgt_a = $registry->get( $current_target );
	if ( $tgt_a ) {
		$target_schema   = $tgt_a->get_field_schema();
		$target_required = $tgt_a->get_required_fields();
	}
}

if ( isset( $_GET['jedb_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice_map = array(
		'config_saved'     => array( 'updated', __( 'Bridge saved.', 'je-data-bridge-cc' ) ),
		'config_enabled'   => array( 'updated', __( 'Bridge enabled.', 'je-data-bridge-cc' ) ),
		'config_disabled'  => array( 'updated', __( 'Bridge disabled.', 'je-data-bridge-cc' ) ),
		'config_deleted'   => array( 'updated', __( 'Bridge deleted.', 'je-data-bridge-cc' ) ),
		'sync_run'         => array( 'updated', __( 'Manual sync executed — see status code in the URL and the Debug tab’s sync log for the full result.', 'je-data-bridge-cc' ) ),
		'save_failed'      => array( 'error',   __( 'Save failed — see the debug log for details.', 'je-data-bridge-cc' ) ),
		'invalid_id'       => array( 'error',   __( 'Invalid bridge id.', 'je-data-bridge-cc' ) ),
		'invalid_sync_args'=> array( 'error',   __( 'Manual sync needs both a bridge id and a source record id.', 'je-data-bridge-cc' ) ),
	);
	$key = sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $notice_map[ $key ] ) ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice_map[ $key ][0] ),
			esc_html( $notice_map[ $key ][1] )
		);
	}
endif;
?>

<div class="jedb-flatten-tab">

	<div class="jedb-targets-header">
		<div>
			<h2><?php esc_html_e( 'Flatten Bridges', 'je-data-bridge-cc' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Forward-direction (source → target) field-mapping bridges. Each bridge fires when its source record saves and pushes mapped values onto the linked target.', 'je-data-bridge-cc' ); ?></p>
		</div>
		<form method="get" action="">
			<input type="hidden" name="page" value="<?php echo esc_attr( JEDB_Admin_Shell::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab"  value="<?php echo esc_attr( JEDB_Tab_Flatten::TAB_SLUG ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Add new bridge', 'je-data-bridge-cc' ); ?></button>
		</form>
	</div>

	<?php if ( empty( $all_bridges ) ) : ?>
		<p><em><?php esc_html_e( 'No bridges configured yet. Use the form below to create your first one.', 'je-data-bridge-cc' ); ?></em></p>
	<?php else : ?>

		<table class="widefat striped jedb-flatten-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label / slug', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Source', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Target', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Direction', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Mappings', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Status', 'je-data-bridge-cc' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $all_bridges as $b ) :
				$cfg      = $b['config'];
				$mapcount = isset( $cfg['mappings'] ) && is_array( $cfg['mappings'] ) ? count( $cfg['mappings'] ) : 0;
				$is_on    = ! empty( $b['enabled'] );
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $b['label'] !== '' ? $b['label'] : $b['config_slug'] ); ?></strong>
						<br>
						<code style="font-size:11px;"><?php echo esc_html( $b['config_slug'] ); ?></code>
					</td>
					<td><code><?php echo esc_html( $b['source_target'] ); ?></code></td>
					<td><code><?php echo esc_html( $b['target_target'] ); ?></code></td>
					<td><code><?php echo esc_html( $b['direction'] ); ?></code></td>
					<td><?php echo (int) $mapcount; ?></td>
					<td>
						<?php if ( $is_on ) : ?>
							<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'Enabled', 'je-data-bridge-cc' ); ?></span>
						<?php else : ?>
							<span class="jedb-pill jedb-pill-warn"><?php esc_html_e( 'Disabled', 'je-data-bridge-cc' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', (int) $b['id'], JEDB_Admin_Shell::tab_url( JEDB_Tab_Flatten::TAB_SLUG ) ) ); ?>"><?php esc_html_e( 'Edit', 'je-data-bridge-cc' ); ?></a>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
							<?php wp_nonce_field( 'jedb_flatten_toggle' ); ?>
							<input type="hidden" name="action"  value="jedb_flatten_toggle" />
							<input type="hidden" name="id"      value="<?php echo (int) $b['id']; ?>" />
							<input type="hidden" name="enabled" value="<?php echo $is_on ? '0' : '1'; ?>" />
							<button type="submit" class="button button-small">
								<?php echo $is_on ? esc_html__( 'Disable', 'je-data-bridge-cc' ) : esc_html__( 'Enable', 'je-data-bridge-cc' ); ?>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this bridge?', 'je-data-bridge-cc' ) ); ?>');">
							<?php wp_nonce_field( 'jedb_flatten_delete' ); ?>
							<input type="hidden" name="action" value="jedb_flatten_delete" />
							<input type="hidden" name="id"     value="<?php echo (int) $b['id']; ?>" />
							<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'je-data-bridge-cc' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>

	<hr style="margin:32px 0;">

	<h2><?php echo $is_edit ? esc_html__( 'Edit bridge', 'je-data-bridge-cc' ) : esc_html__( 'Add new bridge', 'je-data-bridge-cc' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="jedb-flatten-form">
		<?php wp_nonce_field( 'jedb_flatten_save' ); ?>
		<input type="hidden" name="action" value="jedb_flatten_save" />
		<input type="hidden" name="id"     value="<?php echo (int) $edit_id; ?>" />

		<table class="form-table">
			<tr>
				<th><label for="jedb_flatten_label"><?php esc_html_e( 'Label', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_flatten_label" name="label" type="text" class="regular-text" value="<?php echo esc_attr( $editing['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Mosaics → Product', 'je-data-bridge-cc' ); ?>" />
					<p class="description"><?php esc_html_e( 'Identifies the bridge in admin lists and is used as the WP meta box header on the linked product edit screen (unless "Meta box title" below is set).', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_flatten_source"><?php esc_html_e( 'Source target', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<select id="jedb_flatten_source" name="source_target" required>
						<option value=""><?php esc_html_e( '— Select —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $source_options as $opt ) : ?>
							<option value="<?php echo esc_attr( $opt['slug'] ); ?>" <?php selected( $current_source, $opt['slug'] ); ?>><?php echo esc_html( $opt['label'] ); ?> · <code><?php echo esc_html( $opt['slug'] ); ?></code></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_flatten_target"><?php esc_html_e( 'Target target', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<select id="jedb_flatten_target" name="target_target" required>
						<option value=""><?php esc_html_e( '— Select —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $target_options as $opt ) : ?>
							<option value="<?php echo esc_attr( $opt['slug'] ); ?>" data-kind="<?php echo esc_attr( $opt['kind'] ); ?>" <?php selected( $current_target, $opt['slug'] ); ?>><?php echo esc_html( $opt['label'] ); ?> · <code><?php echo esc_html( $opt['slug'] ); ?></code></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Direction', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="radio" name="direction" value="push" <?php checked( ( $editing['direction'] ?? 'push' ), 'push' ); ?> />
						<?php esc_html_e( 'Push (source → target) — fires on CCT save', 'je-data-bridge-cc' ); ?>
					</label>
					<br>
					<label>
						<input type="radio" name="direction" value="pull" <?php checked( ( $editing['direction'] ?? '' ), 'pull' ); ?> />
						<?php esc_html_e( 'Pull (target → source) — fires on post save', 'je-data-bridge-cc' ); ?>
					</label>
					<br>
					<label>
						<input type="radio" name="direction" value="bidirectional" <?php checked( ( $editing['direction'] ?? '' ), 'bidirectional' ); ?> />
						<?php esc_html_e( 'Bidirectional — registers both hooks, mutual cascade prevention', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description" style="margin-top:6px;">
						<?php esc_html_e( 'Push uses each mapping\'s push_transform chain. Pull uses pull_transform. Bidirectional uses both — the Sync Guard\'s cross-direction check prevents the two hooks from ping-ponging the same data.', 'je-data-bridge-cc' ); ?>
					</p>
				</td>
			</tr>
			<?php
			// Reverse-direction options are only meaningful for bridges
			// that include the pull direction. The wrapper carries a
			// class so the JS toggles its visibility when the editor
			// picks a direction radio. Initial visibility computed
			// server-side from the persisted direction.
			$initial_direction = $editing['direction'] ?? 'push';
			$reverse_visible   = in_array( $initial_direction, array( 'pull', 'bidirectional' ), true );
			?>
			<tr class="jedb-reverse-direction-row"<?php echo $reverse_visible ? '' : ' style="display:none;"'; ?>>
				<th><?php esc_html_e( 'Reverse-direction options', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="auto_create_target_when_unlinked" value="1" <?php checked( ! empty( $config['auto_create_target_when_unlinked'] ) ); ?> />
						<?php esc_html_e( 'Auto-create the source CCT row when an unlinked post saves', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description" style="margin-left:24px;margin-top:4px;color:#646970;">
						<?php esc_html_e( 'When ON, saving a post that has no matching JE relation row AND no CCT row pointing at it via cct_single_post_id will create a fresh CCT row from scratch. Default OFF because the action creates data — turn it on only when you want post saves to spawn source CCT rows automatically.', 'je-data-bridge-cc' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'CCT-single redirect', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="cct_single_redirect" value="1" <?php checked( ! empty( $config['cct_single_redirect'] ) ); ?> />
						<?php esc_html_e( 'Redirect the source CCT single page to the linked post permalink', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description" style="margin-left:24px;margin-top:4px;color:#646970;">
						<strong><?php esc_html_e( 'Not yet active —', 'je-data-bridge-cc' ); ?></strong>
						<?php esc_html_e( 'the runtime redirect shim ships in a future release. The setting persists now so existing bridges are ready when the shim lands. Intended behavior: when ON and direction includes "push", visiting the source CCT row\'s "Has Single Page" URL 301s to the linked post permalink. Admins with manage_options will be able to pass ?jedb_no_redirect=1 to bypass for debugging. Default OFF — the CCT may want to remain frontend-visible for some bridges.', 'je-data-bridge-cc' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Enabled', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( $editing ? (int) $editing['enabled'] : 1, 1 ); ?> /> <?php esc_html_e( 'Active — fire on save events', 'je-data-bridge-cc' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Link via', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label><input type="radio" name="link_via_type" value="je_relation" <?php checked( ( $config['link_via']['type'] ?? 'je_relation' ), 'je_relation' ); ?> /> <?php esc_html_e( 'JetEngine Relation', 'je-data-bridge-cc' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="link_via_type" value="cct_single_post_id" <?php checked( ( $config['link_via']['type'] ?? '' ), 'cct_single_post_id' ); ?> /> <?php esc_html_e( '"Has Single Page" post ID (cct_single_post_id)', 'je-data-bridge-cc' ); ?></label>
					<br><br>
					<label for="jedb_flatten_relation_id"><?php esc_html_e( 'Relation:', 'je-data-bridge-cc' ); ?></label>
					<select id="jedb_flatten_relation_id" name="link_via_relation_id">
						<option value=""><?php esc_html_e( '— Select —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $relations as $r ) : ?>
							<option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( ( $config['link_via']['relation_id'] ?? '' ), $r['id'] ); ?>>
								<?php echo esc_html( $r['name'] ); ?> · <?php echo esc_html( $r['parent_lb'] ); ?> → <?php echo esc_html( $r['child_lb'] ); ?> · <code><?php echo esc_html( $r['type'] ); ?></code>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only relations whose endpoints involve both the chosen source and target are listed. (Re-pick source/target then save once for this list to refresh.)', 'je-data-bridge-cc' ); ?></p>

					<fieldset style="margin-top:12px;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
						<legend style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#50575e;padding:0 6px;">
							<?php esc_html_e( 'Self-heal options (when JE Relation is the link type)', 'je-data-bridge-cc' ); ?>
						</legend>
						<label style="display:block;margin:6px 0;">
							<input type="checkbox" name="link_via_fallback_to_single_page" value="1" <?php checked( ! isset( $config['link_via']['fallback_to_single_page'] ) || ! empty( $config['link_via']['fallback_to_single_page'] ) ); ?> />
							<?php esc_html_e( 'Fall back to cct_single_post_id when no relation row exists', 'je-data-bridge-cc' ); ?>
							<br>
							<small style="color:#646970;display:block;margin-left:24px;">
								<?php esc_html_e( 'JetEngine Has-Single-Page creates the linked post on CCT save but does NOT write a relation row. Per L-021, this fallback resolves the target via the single-page link when the relation row is missing — so the bridge works on the very first sync without a manual picker click.', 'je-data-bridge-cc' ); ?>
							</small>
						</label>
						<label style="display:block;margin:6px 0;">
							<input type="checkbox" name="link_via_auto_attach_relation" value="1" <?php checked( ! isset( $config['link_via']['auto_attach_relation'] ) || ! empty( $config['link_via']['auto_attach_relation'] ) ); ?> />
							<?php esc_html_e( 'Auto-attach the missing relation row when the fallback fires', 'je-data-bridge-cc' ); ?>
							<br>
							<small style="color:#646970;display:block;margin-left:24px;">
								<?php esc_html_e( 'After the first sync, the relation row exists in the JE relation table. JE Smart Filters / Listing Grids / Query Builder traversals work natively from then on. Subsequent syncs use the fast path. Idempotent — never duplicates rows.', 'je-data-bridge-cc' ); ?>
							</small>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_flatten_condition"><?php esc_html_e( 'Condition (optional)', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<textarea id="jedb_flatten_condition" name="condition" rows="2" cols="80" class="large-text code" placeholder='{product.product_cat} contains "Mosaics"'><?php echo esc_textarea( $config['condition'] ?? '' ); ?></textarea>
					<button type="button" class="button button-small" id="jedb_flatten_validate_condition"><?php esc_html_e( 'Validate', 'je-data-bridge-cc' ); ?></button>
					<span id="jedb_flatten_condition_status" class="jedb-pill" style="display:none;"></span>
					<p class="description">
						<?php esc_html_e( 'Empty = always apply. Operators: == != > < >= <= contains not_contains starts_with ends_with in not_in. Combine with AND OR NOT. Reference fields with {source.field} / {target.field}.', 'je-data-bridge-cc' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_flatten_priority"><?php esc_html_e( 'Priority', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_flatten_priority" name="priority" type="number" class="small-text" value="<?php echo esc_attr( (int) ( $config['priority'] ?? 100 ) ); ?>" min="0" max="999" />
					<p class="description"><?php esc_html_e( 'Lower runs first when multiple bridges share the same source.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
		</table>

		<?php
		// Meta box settings — control how this bridge's surfaced fields
		// are presented on the linked product / variation edit screen.
		// Per-mapping `surface_on_target` flags below select WHICH fields
		// render; this section controls HOW the meta box is presented
		// as a whole. Currently the meta box renderer is Woo-scoped
		// (product + product_variation post types); the underlying
		// engine itself is post-type-agnostic.
		$meta_box_cfg  = isset( $config['meta_box'] ) && is_array( $config['meta_box'] ) ? $config['meta_box'] : JEDB_Flatten_Config_Manager::default_meta_box();
		$mb_groups_csv = is_array( $meta_box_cfg['groups'] ?? null ) ? implode( ', ', $meta_box_cfg['groups'] ) : '';
		?>
		<details class="jedb-flatten-meta-box-section" id="jedb_flatten_meta_box_section" open>
			<summary>
				<h3 style="display:inline-block;margin:0;"><?php esc_html_e( 'Meta box settings', 'je-data-bridge-cc' ); ?></h3>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( '— controls the Bridge meta box on the linked product edit screen', 'je-data-bridge-cc' ); ?></span>
			</summary>

			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Each enabled bridge gets its own native WP meta box on the linked product / variation edit screen, named with the title below (falling back to this bridge\'s Label). The meta box shows read-only previews of any mapping flagged for surfacing in the table below, plus a "Save & edit" button that opens JE\'s CCT edit page in a focused modal. When the editor saves there, the CCT row updates, the existing sync engine pushes the new values to the product, and the meta box previews refresh on the next page load.', 'je-data-bridge-cc' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Render meta box', 'je-data-bridge-cc' ); ?></th>
					<td>
						<label><input type="checkbox" name="meta_box_enabled" value="1" <?php checked( ! empty( $meta_box_cfg['enabled'] ) ); ?> /> <?php esc_html_e( 'Show the Bridge meta box for this bridge on linked product edit screens', 'je-data-bridge-cc' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off = no meta box rendered for products governed by this bridge. The bridge still syncs normally — this only hides the editor surface.', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="jedb_flatten_meta_box_title"><?php esc_html_e( 'Meta box title', 'je-data-bridge-cc' ); ?></label></th>
					<td>
						<input id="jedb_flatten_meta_box_title" name="meta_box_title" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $meta_box_cfg['title'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Defaults to the bridge label if blank', 'je-data-bridge-cc' ); ?>" />
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Position', 'je-data-bridge-cc' ); ?></th>
					<td>
						<?php $mb_position = isset( $meta_box_cfg['position'] ) ? (string) $meta_box_cfg['position'] : 'normal'; ?>
						<label><input type="radio" name="meta_box_position" value="normal"   <?php checked( $mb_position, 'normal' ); ?> /> <?php esc_html_e( 'Normal (main column)', 'je-data-bridge-cc' ); ?></label>
						&nbsp;&nbsp;
						<label><input type="radio" name="meta_box_position" value="side"     <?php checked( $mb_position, 'side' ); ?> /> <?php esc_html_e( 'Side', 'je-data-bridge-cc' ); ?></label>
						&nbsp;&nbsp;
						<label><input type="radio" name="meta_box_position" value="advanced" <?php checked( $mb_position, 'advanced' ); ?> /> <?php esc_html_e( 'Advanced (below main)', 'je-data-bridge-cc' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="jedb_flatten_meta_box_groups"><?php esc_html_e( 'Group order (optional)', 'je-data-bridge-cc' ); ?></label></th>
					<td>
						<input id="jedb_flatten_meta_box_groups" name="meta_box_groups" type="text" class="regular-text" value="<?php echo esc_attr( $mb_groups_csv ); ?>" placeholder="<?php esc_attr_e( 'Identity, Pricing, Variations', 'je-data-bridge-cc' ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated list of group labels controlling display order on the meta box. Groups are freeform per-mapping — type any label that matches the per-mapping "Group" column below. Groups not listed here render after listed ones in alphabetical order.', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Advanced Details', 'je-data-bridge-cc' ); ?></th>
					<td>
						<label><input type="checkbox" name="meta_box_show_advanced" value="1" <?php checked( ! empty( $meta_box_cfg['show_advanced'] ) ); ?> /> <?php esc_html_e( 'Show "Advanced Details" collapsible on this bridge\'s meta box', 'je-data-bridge-cc' ); ?></label>
						<p class="description"><?php esc_html_e( 'When ON, a collapsed <details> "Advanced Details" section appears at the bottom of the meta box exposing per-product overrides (Freeze / Direction override), the last 3 sync log rows, and Sync now / Unlink action buttons. When OFF (default), the meta box renders only the surfaced field previews and the "Save & edit" button — clean native-WP look.', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="description" style="max-width:760px;color:#996800;">
				<strong><?php esc_html_e( 'Surfacing fields:', 'je-data-bridge-cc' ); ?></strong>
				<?php esc_html_e( 'In the field mappings table below, tick the "Target" checkbox in the "Meta box" column to surface a mapping on the product edit screen as a read-only preview. Optionally type a freeform "Group" label to cluster related surfaced fields. The "Group order" field above controls the display order of groups.', 'je-data-bridge-cc' ); ?>
			</p>
		</details>

		<?php
		// Mandatory coverage — alpha.12 (Phase 4 Day 4) integration.
		// Combines adapter-required fields + bridge's required_overrides
		// into one provenance-tagged list. Provides:
		//   - Apply preset dropdown (writes preset's mandatory fields
		//     into required_overrides.add — snapshot model per §4.12).
		//   - Scaffold missing mappings button (stubs passthrough rows
		//     for any required field not yet mapped).
		//   - Coverage badges: green when a mapping exists for the field,
		//     red when it doesn't. "X of Y covered" summary.
		//   - Provenance labels: adapter / override (preset-applied
		//     fields end up tagged as override per the snapshot model).
		$effective_required = class_exists( 'JEDB_Field_Presets_Manager' )
			? JEDB_Field_Presets_Manager::compute_effective_required_fields( $config, $target_required )
			: array();

		$mapped_target_fields = array();
		foreach ( (array) ( $config['mappings'] ?? array() ) as $m ) {
			if ( is_array( $m ) && ! empty( $m['target_field'] ) ) {
				$mapped_target_fields[ (string) $m['target_field'] ] = true;
			}
		}

		$matching_presets = ( class_exists( 'JEDB_Field_Presets_Manager' ) && '' !== $current_target )
			? JEDB_Field_Presets_Manager::instance()->get_for_target( $current_target )
			: array();

		$covered_count = 0;
		$missing_count = 0;
		foreach ( $effective_required as $row ) {
			if ( isset( $mapped_target_fields[ $row['name'] ] ) ) {
				$covered_count++;
			} else {
				$missing_count++;
			}
		}
		$total_required = $covered_count + $missing_count;
		?>
		<h3><?php esc_html_e( 'Mandatory coverage (target side)', 'je-data-bridge-cc' ); ?></h3>
		<div id="jedb_flatten_required_panel" class="jedb-flatten-required" data-bridge-id="<?php echo (int) $edit_id; ?>" data-target="<?php echo esc_attr( $current_target ); ?>">

			<?php if ( $total_required > 0 ) : ?>
				<p class="jedb-coverage-summary">
					<strong>
						<?php
						printf(
							/* translators: %1$d = covered count, %2$d = total required */
							esc_html__( 'Coverage: %1$d of %2$d required fields mapped.', 'je-data-bridge-cc' ),
							$covered_count,
							$total_required
						);
						?>
					</strong>
					<?php if ( $missing_count > 0 ) : ?>
						<span class="jedb-coverage-missing-pill"><?php
							/* translators: %d = number of missing fields */
							echo esc_html( sprintf( _n( '%d missing', '%d missing', $missing_count, 'je-data-bridge-cc' ), $missing_count ) );
						?></span>
					<?php endif; ?>
				</p>

				<ul class="jedb-required-list jedb-coverage-list">
					<?php foreach ( $effective_required as $row ) :
						$covered = isset( $mapped_target_fields[ $row['name'] ] );
						$origin  = $row['origin'];
					?>
						<li class="jedb-coverage-row jedb-coverage-<?php echo $covered ? 'ok' : 'missing'; ?>">
							<span class="jedb-coverage-badge" aria-hidden="true"><?php echo $covered ? '&#10003;' : '&#9888;'; ?></span>
							<code><?php echo esc_html( $row['name'] ); ?></code>
							<small class="jedb-coverage-origin">
								<?php
								if ( 'adapter' === $origin ) {
									esc_html_e( 'required by adapter', 'je-data-bridge-cc' );
								} else {
									esc_html_e( 'required by override / preset', 'je-data-bridge-cc' );
								}
								?>
							</small>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="jedb-coverage-empty-placeholder"><em><?php esc_html_e( 'No required fields detected for this target. Apply a preset below to seed mandatory coverage, or add fields manually via the JSON editor.', 'je-data-bridge-cc' ); ?></em></p>
			<?php endif; ?>

			<?php if ( ! empty( $matching_presets ) ) : ?>
				<div class="jedb-coverage-actions">
					<label for="jedb_flatten_preset_select"><?php esc_html_e( 'Apply preset:', 'je-data-bridge-cc' ); ?></label>
					<select id="jedb_flatten_preset_select">
						<option value=""><?php esc_html_e( '— Select a preset —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $matching_presets as $p ) :
							$mandatory_count = 0;
							foreach ( $p['fields'] as $f ) {
								if ( ! empty( $f['mandatory'] ) ) { $mandatory_count++; }
							}
						?>
							<option value="<?php echo esc_attr( $p['slug'] ); ?>"><?php
								echo esc_html( sprintf(
									/* translators: %1$s = preset label, %2$d = mandatory field count */
									_n( '%1$s (%2$d mandatory field)', '%1$s (%2$d mandatory fields)', $mandatory_count, 'je-data-bridge-cc' ),
									$p['label'],
									$mandatory_count
								) );
							?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="jedb_flatten_apply_preset"><?php esc_html_e( 'Apply (adds mandatory fields to required_overrides)', 'je-data-bridge-cc' ); ?></button>
					<?php if ( $missing_count > 0 ) : ?>
						<button type="button" class="button" id="jedb_flatten_scaffold_missing"><?php esc_html_e( 'Scaffold missing mappings', 'je-data-bridge-cc' ); ?></button>
					<?php endif; ?>
					<span id="jedb_flatten_coverage_status" class="description"></span>
				</div>
				<p class="description" style="max-width:760px;">
					<?php esc_html_e( 'Apply writes the preset\'s mandatory fields into this bridge\'s required_overrides.add (snapshot model — editing the preset later does not auto-update applied bridges). Scaffold stubs a passthrough mapping for every missing required field; you fill in the source side. Both actions modify the form locally — save the bridge to persist.', 'je-data-bridge-cc' ); ?>
				</p>
			<?php elseif ( '' !== $current_target ) : ?>
				<p class="description" style="max-width:760px;color:#646970;">
					<?php
					printf(
						/* translators: %s = target adapter slug */
						esc_html__( 'No field presets exist for target "%s" yet. Create one in the Field Presets tab to enable Apply / Scaffold actions here.', 'je-data-bridge-cc' ),
						esc_html( $current_target )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<?php
		// Phase 3.6 / D-20-D-24: Taxonomies section. Visible only when
		// target_target is `posts::*`. JS hides/shows based on the
		// target dropdown's current value.
		$tax_visible = ( $current_target && 0 === strpos( $current_target, 'posts::' ) );
		?>
		<details
			class="jedb-flatten-taxonomies-section"
			id="jedb_flatten_taxonomies_section"
			data-visible="<?php echo $tax_visible ? '1' : '0'; ?>"
			<?php echo $tax_visible ? 'open' : ''; ?>
			<?php echo $tax_visible ? '' : 'style="display:none;"'; ?>
		>
			<summary>
				<h3 style="display:inline-block;margin:0;"><?php esc_html_e( 'Taxonomies (push only)', 'je-data-bridge-cc' ); ?></h3>
				<span class="jedb-tax-summary-pill jedb-pill" style="margin-left:8px;"></span>
			</summary>

			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Each rule below applies a set of terms in one taxonomy on every successful push. Pull never modifies taxonomies — editors can hand-tag the target with extra terms and the bridge won\'t strip them. Multiple rules per bridge are first-class — use one rule per taxonomy. Works for any post-type target (products, CPTs, etc.) with any associated taxonomy.', 'je-data-bridge-cc' ); ?>
			</p>

			<table class="widefat jedb-flatten-taxonomies" id="jedb_flatten_taxonomies">
				<thead>
					<tr>
						<th style="width:18%;"><?php esc_html_e( 'Taxonomy', 'je-data-bridge-cc' ); ?></th>
						<th style="width:24%;"><?php esc_html_e( 'Apply terms', 'je-data-bridge-cc' ); ?></th>
						<th style="width:22%;"><?php esc_html_e( 'Inverse (remove)', 'je-data-bridge-cc' ); ?></th>
						<th style="width:9%;"><?php esc_html_e( 'Match by', 'je-data-bridge-cc' ); ?></th>
						<th style="width:11%;"><?php esc_html_e( 'Strategy', 'je-data-bridge-cc' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Create?', 'je-data-bridge-cc' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody></tbody>
				<tfoot>
					<tr>
						<td colspan="7">
							<button type="button" class="button" id="jedb_flatten_add_taxonomy_rule"><?php esc_html_e( '+ Add taxonomy rule', 'je-data-bridge-cc' ); ?></button>
							<button type="button" class="button" id="jedb_flatten_refresh_taxonomies" style="margin-left:8px;"><?php esc_html_e( 'Refresh taxonomies + terms from site', 'je-data-bridge-cc' ); ?></button>
							<span id="jedb_flatten_taxonomies_status" class="description" style="margin-left:12px;"></span>
						</td>
					</tr>
				</tfoot>
			</table>

			<p class="description" style="max-width:760px;">
				<strong><?php esc_html_e( 'Snippet support:', 'je-data-bridge-cc' ); ?></strong>
				<?php esc_html_e( 'Each rule reserves a "snippet" slot for dynamic term computation (e.g., compute categories from CCT field values). The snippet runtime ships in a future release — rules with a snippet set today log skipped_invalid in sync_log.', 'je-data-bridge-cc' ); ?>
			</p>
		</details>

		<?php
		/* Phase 4b — alpha.14 / §4.7 / L-032: per-bridge "Enable
		 * WooCommerce Variations" panel. When enabled, the CCT edit
		 * screen for source rows of this bridge gains an "Open
		 * variations editor" panel that launches WC's native product
		 * edit page in a chrome-stripped modal iframe. Hidden in this
		 * admin UI when target_target isn't posts::product per D6 —
		 * the feature only applies to Woo product targets. The alpha.13
		 * declarative variations[] reconciler was retired per L-032;
		 * see BUILD-PLAN §4.7 for the current architecture. */
		$wc_variations_section_visible = ( 'posts::product' === $current_target );
		$cct_screen_cfg = isset( $config['cct_screen'] ) && is_array( $config['cct_screen'] ) ? $config['cct_screen'] : JEDB_Flatten_Config_Manager::default_cct_screen();
		$wc_var_cfg     = isset( $cct_screen_cfg['wc_variations'] ) && is_array( $cct_screen_cfg['wc_variations'] ) ? $cct_screen_cfg['wc_variations'] : JEDB_Flatten_Config_Manager::default_wc_variations_panel();
		?>
		<details
			class="jedb-flatten-wc-variations-section"
			id="jedb_flatten_wc_variations_section"
			data-visible="<?php echo $wc_variations_section_visible ? '1' : '0'; ?>"
			<?php echo $wc_variations_section_visible ? 'open' : ''; ?>
			<?php echo $wc_variations_section_visible ? '' : 'style="display:none;"'; ?>
		>
			<summary>
				<h3 style="display:inline-block;margin:0;"><?php esc_html_e( 'Enable WooCommerce Variations', 'je-data-bridge-cc' ); ?></h3>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( '— iframe-launch WC variations editor from the CCT edit screen', 'je-data-bridge-cc' ); ?></span>
			</summary>

			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'When enabled, the JE CCT edit screen for source rows of this bridge gains a panel beneath the save button with an "Open variations editor →" button. Clicking it opens the linked WC product\'s edit page in a focused modal iframe — editors manage variations using WC\'s native admin (attributes, prices, downloads, stock, images, everything), no declarative configuration needed in this plugin. The panel only appears after the CCT row has been linked to a WC product via the relations picker.', 'je-data-bridge-cc' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enabled', 'je-data-bridge-cc' ); ?></th>
					<td>
						<label><input type="checkbox" name="cct_screen_wc_variations_enabled" value="1" <?php checked( ! empty( $wc_var_cfg['enabled'] ) ); ?> /> <?php esc_html_e( 'Show the "Open variations editor" panel on this bridge\'s CCT edit screens', 'je-data-bridge-cc' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off = no panel rendered. The bridge still syncs CCT → product normally; this only adds the variations-editor launcher to the CCT edit screen.', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="jedb_flatten_wc_variations_title"><?php esc_html_e( 'Panel title', 'je-data-bridge-cc' ); ?></label></th>
					<td>
						<input id="jedb_flatten_wc_variations_title" name="cct_screen_wc_variations_title" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $wc_var_cfg['title'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'WooCommerce Variations', 'je-data-bridge-cc' ); ?>" />
						<p class="description"><?php esc_html_e( 'Heading shown on the CCT-edit-screen panel. Empty = "WooCommerce Variations".', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto-force variable type', 'je-data-bridge-cc' ); ?></th>
					<td>
						<label><input type="checkbox" name="cct_screen_wc_variations_auto_force_variable_type" value="1" <?php checked( ! empty( $wc_var_cfg['auto_force_variable_type'] ) ); ?> /> <?php esc_html_e( 'Automatically set the linked product\'s type to "Variable product" on iframe load', 'je-data-bridge-cc' ); ?></label>
						<p class="description"><?php esc_html_e( 'Helpful for first-time setup so editors don\'t have to manually flip the product type dropdown inside the iframe. Default off — admin opts in per bridge. Activates when the chrome-strip script is in place (alpha.15+).', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="description" style="max-width:820px;color:#646970;">
				<strong><?php esc_html_e( 'Why this exists:', 'je-data-bridge-cc' ); ?></strong>
				<?php esc_html_e( 'Per L-032, WC variation management is delegated to WC\'s native UI. The plugin doesn\'t try to model variations declaratively — WC already has a polished variations admin (drag-reorder, per-variation images, stock, attributes, downloads, etc.) and reimplementing any of that is open-ended scope. The iframe-flip pattern (the mirror image of the L-027 modal that lets editors edit CCT data from the WC product edit screen) gives editors quick access to WC\'s variations UI without leaving the CCT row they\'re editing.', 'je-data-bridge-cc' ); ?>
			</p>
		</details>

		<h3><?php esc_html_e( 'Field mappings', 'je-data-bridge-cc' ); ?></h3>

		<p class="description">
			<?php esc_html_e( 'Each row pushes one source field through a transformer chain into one target field. Push and pull chains are stored independently — they don\'t have to be inverses.', 'je-data-bridge-cc' ); ?>
		</p>

		<table class="widefat jedb-flatten-mappings" id="jedb_flatten_mappings">
			<thead>
				<tr>
					<th style="width:20%;"><?php esc_html_e( 'Source field', 'je-data-bridge-cc' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Target field', 'je-data-bridge-cc' ); ?></th>
					<th style="width:18%;"><?php esc_html_e( '→ Push transformer', 'je-data-bridge-cc' ); ?></th>
					<th style="width:18%;"><?php esc_html_e( '← Pull transformer', 'je-data-bridge-cc' ); ?></th>
					<th style="width:14%;"><?php esc_html_e( 'Meta box', 'je-data-bridge-cc' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody></tbody>
			<tfoot>
				<tr>
					<td colspan="6">
						<button type="button" class="button" id="jedb_flatten_add_mapping"><?php esc_html_e( '+ Add mapping', 'je-data-bridge-cc' ); ?></button>
					</td>
				</tr>
			</tfoot>
		</table>

		<input type="hidden" name="config_json" id="jedb_flatten_config_json" value="" />

		<h3><?php esc_html_e( 'Raw config (advanced)', 'je-data-bridge-cc' ); ?></h3>
		<details>
			<summary><?php esc_html_e( 'Show / edit JSON', 'je-data-bridge-cc' ); ?></summary>
			<textarea id="jedb_flatten_config_raw" rows="14" class="large-text code" spellcheck="false" style="font-family:Consolas,Menlo,Monaco,monospace;font-size:12px;"><?php echo esc_textarea( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'The form fields above feed this JSON. Edits here win on submit.', 'je-data-bridge-cc' ); ?></p>
		</details>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Save bridge', 'je-data-bridge-cc' ) : esc_html__( 'Create bridge', 'je-data-bridge-cc' ); ?></button>
		</p>
	</form>

	<?php if ( $is_edit ) : ?>
		<hr>
		<h3><?php esc_html_e( 'Manual sync', 'je-data-bridge-cc' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jedb-flatten-manual-sync">
			<?php wp_nonce_field( 'jedb_flatten_sync_now' ); ?>
			<input type="hidden" name="action" value="jedb_flatten_sync_now" />
			<input type="hidden" name="id"     value="<?php echo (int) $edit_id; ?>" />
			<label><?php esc_html_e( 'Source record _ID:', 'je-data-bridge-cc' ); ?>
				<input type="number" name="source_id" min="1" required class="small-text" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Sync now', 'je-data-bridge-cc' ); ?></button>
			<p class="description"><?php esc_html_e( 'Runs the bridge once for the given source record. Outcome is recorded in wp_jedb_sync_log; see Debug tab for details.', 'je-data-bridge-cc' ); ?></p>
		</form>
	<?php endif; ?>

</div>

<script type="application/json" id="jedb-flatten-bootstrap">
<?php
$initial_post_type = '';
if ( $current_target && 0 === strpos( $current_target, 'posts::' ) ) {
	$initial_post_type = substr( $current_target, 7 );
}

echo wp_json_encode( array(
	'ajax_url'              => admin_url( 'admin-ajax.php' ),
	'nonce'                 => wp_create_nonce( 'jedb_flatten_admin' ),
	'transformers'          => array_values( array_map(
		static function ( $t ) {
			return array(
				'name'        => $t->get_name(),
				'label'       => $t->get_label(),
				'description' => $t->get_description(),
				'args'        => $t->get_args_schema(),
			);
		},
		JEDB_Transformer_Registry::instance()->all()
	) ),
	'source_schema'         => $source_schema,
	'target_schema'         => $target_schema,
	'target_required'       => $target_required,
	'initial_mappings'      => isset( $config['mappings'] )   && is_array( $config['mappings'] )   ? $config['mappings']   : array(),
	'initial_taxonomies'    => isset( $config['taxonomies'] ) && is_array( $config['taxonomies'] ) ? $config['taxonomies'] : array(),
	'initial_post_type'     => $initial_post_type,
	'taxonomy_default_rule' => JEDB_Flatten_Config_Manager::default_taxonomy_rule(),
	'required_overrides'    => isset( $config['required_overrides'] ) && is_array( $config['required_overrides'] ) ? $config['required_overrides'] : array( 'add' => array(), 'remove' => array() ),
	// alpha.12: presets matching this bridge's target so the JS Apply /
	// Scaffold handlers can resolve field lists without an extra AJAX
	// round trip. Empty array when no target is selected or no matching
	// presets exist.
	'matching_presets'      => ( class_exists( 'JEDB_Field_Presets_Manager' ) && '' !== $current_target )
		? array_values( JEDB_Field_Presets_Manager::instance()->get_for_target( $current_target ) )
		: array(),
) ); ?>
</script>
