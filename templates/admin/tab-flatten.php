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
					<p class="description"><?php esc_html_e( 'Phase 3 supports CCT sources only. CPT/Woo sources land in Phase 3.5 (reverse direction).', 'je-data-bridge-cc' ); ?></p>
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
			<tr>
				<th><?php esc_html_e( 'Reverse-direction options', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="auto_create_target_when_unlinked" value="1" <?php checked( ! empty( $config['auto_create_target_when_unlinked'] ) ); ?> />
						<?php esc_html_e( 'Auto-create the source CCT row when an unlinked post saves', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description" style="margin-left:24px;margin-top:4px;color:#646970;">
						<?php esc_html_e( 'Only relevant for Pull / Bidirectional bridges. When ON, saving a post that has no matching JE relation row AND no CCT row pointing at it via cct_single_post_id will create a fresh CCT row from scratch. Default OFF (per D-17) because the action creates data.', 'je-data-bridge-cc' ); ?>
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
						<?php esc_html_e( 'v1 DSL — see BUILD-PLAN §3.5. Empty = always apply. Operators: == != > < >= <= contains not_contains starts_with ends_with in not_in. Combine with AND OR NOT. Reference fields with {source.field} / {target.field}.', 'je-data-bridge-cc' ); ?>
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

		<h3><?php esc_html_e( 'Mandatory coverage (target side)', 'je-data-bridge-cc' ); ?></h3>
		<div id="jedb_flatten_required_panel" class="jedb-flatten-required">
			<?php if ( empty( $target_required ) ) : ?>
				<p><em><?php esc_html_e( 'The selected target reports no inherent required fields. (You can still add some via the JSON editor below.)', 'je-data-bridge-cc' ); ?></em></p>
			<?php else : ?>
				<p><?php esc_html_e( 'These fields are required by the target adapter. The Mappings table below should map at least one source field onto each:', 'je-data-bridge-cc' ); ?></p>
				<ul class="jedb-required-list">
					<?php foreach ( $target_required as $f ) : ?>
						<li><code><?php echo esc_html( $f ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<h3><?php esc_html_e( 'Field mappings', 'je-data-bridge-cc' ); ?></h3>

		<p class="description">
			<?php esc_html_e( 'Each row pushes one source field through a transformer chain into one target field. Per-direction chains (push and pull) are stored independently per D-11.', 'je-data-bridge-cc' ); ?>
		</p>

		<table class="widefat jedb-flatten-mappings" id="jedb_flatten_mappings">
			<thead>
				<tr>
					<th style="width:25%;"><?php esc_html_e( 'Source field', 'je-data-bridge-cc' ); ?></th>
					<th style="width:25%;"><?php esc_html_e( 'Target field', 'je-data-bridge-cc' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( '→ Push transformer', 'je-data-bridge-cc' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( '← Pull transformer', 'je-data-bridge-cc' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody></tbody>
			<tfoot>
				<tr>
					<td colspan="5">
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
echo wp_json_encode( array(
	'ajax_url'         => admin_url( 'admin-ajax.php' ),
	'nonce'            => wp_create_nonce( 'jedb_flatten_admin' ),
	'transformers'     => array_values( array_map(
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
	'source_schema'    => $source_schema,
	'target_schema'    => $target_schema,
	'target_required'  => $target_required,
	'initial_mappings' => isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : array(),
) ); ?>
</script>
