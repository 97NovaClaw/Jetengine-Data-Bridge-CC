<?php
/**
 * Bridges admin tab template — Phase 4 / Day 1.
 *
 * Lists every bridge type (templates that the Phase 4 Bridge meta box
 * will clone into individual flatten configs on Day 2) and provides:
 *   - Add / edit form for one bridge type
 *   - Enable / disable / delete actions
 *   - JSON export (download all)
 *   - JSON import (paste, with replace-all option)
 *
 * Bridge types are templates only — editing a bridge type after some
 * products are already linked does NOT retroactively change those
 * products' flatten configs. Surface this clearly in the UI so editors
 * don't expect template→instance live binding.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$tab     = JEDB_Tab_Bridges::instance();
$manager = JEDB_Bridge_Types_Manager::instance();

$edit_slug_raw = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$edit_slug     = sanitize_key( $edit_slug_raw );
$editing       = '' !== $edit_slug ? $manager->get_by_slug( $edit_slug ) : null;
$is_edit       = (bool) $editing;
$bridge_type   = $editing ? $editing : JEDB_Bridge_Types_Manager::default_bridge_type();

$all_types       = $manager->get_all();
$target_options  = $tab->get_eligible_targets();

if ( isset( $_GET['jedb_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice_map = array(
		'config_saved'         => array( 'updated', __( 'Bridge type saved.', 'je-data-bridge-cc' ) ),
		'config_enabled'       => array( 'updated', __( 'Bridge type enabled.', 'je-data-bridge-cc' ) ),
		'config_disabled'      => array( 'updated', __( 'Bridge type disabled.', 'je-data-bridge-cc' ) ),
		'config_deleted'       => array( 'updated', __( 'Bridge type deleted.', 'je-data-bridge-cc' ) ),
		'save_failed'          => array( 'error',   __( 'Save failed.', 'je-data-bridge-cc' ) ),
		'invalid_slug'         => array( 'error',   __( 'Invalid bridge type slug.', 'je-data-bridge-cc' ) ),
		'import_invalid_json'  => array( 'error',   __( 'Import failed — payload is not valid JSON.', 'je-data-bridge-cc' ) ),
		'import_invalid_shape' => array( 'error',   __( 'Import failed — JSON did not contain a "bridge_types" array or top-level array.', 'je-data-bridge-cc' ) ),
		'import_failed'        => array( 'error',   __( 'Import failed.', 'je-data-bridge-cc' ) ),
		'import_done'          => array( 'updated', __( 'Import complete.', 'je-data-bridge-cc' ) ),
	);
	$key   = sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$extra = '';
	if ( 'import_done' === $key ) {
		$imp = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skp = isset( $_GET['skipped'] )  ? (int) $_GET['skipped']  : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$extra = sprintf( ' (%d imported, %d skipped)', $imp, $skp );
	}
	if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err_key = sanitize_text_field( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err_msg = $tab->read_stashed_error( $err_key );
		if ( '' !== $err_msg ) {
			$extra .= ' — ' . $err_msg;
		}
	}
	if ( isset( $notice_map[ $key ] ) ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
			esc_attr( $notice_map[ $key ][0] ),
			esc_html( $notice_map[ $key ][1] ),
			esc_html( $extra )
		);
	}
endif;
?>

<div class="jedb-bridges-tab">

	<div class="jedb-targets-header">
		<div>
			<h2><?php esc_html_e( 'Bridge Types', 'je-data-bridge-cc' ); ?></h2>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Bridge types are templates. Each one declares what kind of CCT and what kind of post (CPT, Woo product, etc.) belong together, plus default field mappings, taxonomies, and link mechanism. The Bridge meta box on the Woo product edit screen (Phase 4 Day 2) clones a bridge type into a concrete flatten config when you wire up an individual product.', 'je-data-bridge-cc' ); ?>
			</p>
			<p class="description" style="max-width:760px;color:#996800;">
				<strong><?php esc_html_e( 'Templates, not bindings.', 'je-data-bridge-cc' ); ?></strong>
				<?php esc_html_e( 'Editing a bridge type after some products are already linked does NOT retroactively change those products. Existing flatten configs keep their copy of the defaults. New links pick up the latest defaults at clone time.', 'je-data-bridge-cc' ); ?>
			</p>
		</div>
		<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( JEDB_Admin_Shell::MENU_SLUG ); ?>" />
				<input type="hidden" name="tab"  value="<?php echo esc_attr( JEDB_Tab_Bridges::TAB_SLUG ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add new bridge type', 'je-data-bridge-cc' ); ?></button>
			</form>
			<button type="button" class="button" id="jedb_bridges_export_btn"><?php esc_html_e( 'Export all (JSON)', 'je-data-bridge-cc' ); ?></button>
			<button type="button" class="button" id="jedb_bridges_import_btn"><?php esc_html_e( 'Import (JSON)', 'je-data-bridge-cc' ); ?></button>
		</div>
	</div>

	<?php if ( empty( $all_types ) ) : ?>
		<p><em><?php esc_html_e( 'No bridge types configured yet. Use the form below to create your first one — typical Brick Builder HQ setup is one per Woo product category (Available Set, Mosaic, ...).', 'je-data-bridge-cc' ); ?></em></p>
	<?php else : ?>

		<table class="widefat striped jedb-bridges-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label / slug', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Source', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Target', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Direction', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Link via', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Defaults', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Status', 'je-data-bridge-cc' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $all_types as $bt ) :
				$mappings_count = is_array( $bt['default_field_mappings'] ) ? count( $bt['default_field_mappings'] ) : 0;
				$tax_count      = is_array( $bt['default_taxonomies'] )     ? count( $bt['default_taxonomies'] )     : 0;
				$is_on          = ! empty( $bt['enabled'] );
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $bt['label'] !== '' ? $bt['label'] : $bt['slug'] ); ?></strong>
						<br>
						<code style="font-size:11px;"><?php echo esc_html( $bt['slug'] ); ?></code>
						<?php if ( '' !== $bt['description'] ) : ?>
							<div class="description" style="margin-top:4px;max-width:300px;"><?php echo esc_html( $bt['description'] ); ?></div>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $bt['source_target'] ); ?></code></td>
					<td><code><?php echo esc_html( $bt['target_target'] ); ?></code></td>
					<td><code><?php echo esc_html( $bt['default_direction'] ); ?></code></td>
					<td>
						<code><?php echo esc_html( $bt['link_via']['type'] ); ?></code>
						<?php if ( 'je_relation' === $bt['link_via']['type'] && '' !== $bt['link_via']['relation_id'] ) : ?>
							<br><small>rel #<?php echo esc_html( $bt['link_via']['relation_id'] ); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php
						printf(
							/* translators: 1: mappings count, 2: taxonomy rules count */
							esc_html__( '%1$d mapping(s), %2$d tax rule(s)', 'je-data-bridge-cc' ),
							(int) $mappings_count,
							(int) $tax_count
						);
						?>
						<?php if ( ! empty( $bt['cct_single_redirect'] ) ) : ?>
							<br><span class="jedb-pill jedb-pill-info" style="font-size:10px;"><?php esc_html_e( 'CCT redirect ON', 'je-data-bridge-cc' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $is_on ) : ?>
							<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'Enabled', 'je-data-bridge-cc' ); ?></span>
						<?php else : ?>
							<span class="jedb-pill jedb-pill-warn"><?php esc_html_e( 'Disabled', 'je-data-bridge-cc' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $bt['slug'], JEDB_Admin_Shell::tab_url( JEDB_Tab_Bridges::TAB_SLUG ) ) ); ?>"><?php esc_html_e( 'Edit', 'je-data-bridge-cc' ); ?></a>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
							<?php wp_nonce_field( 'jedb_bridges_toggle' ); ?>
							<input type="hidden" name="action"  value="jedb_bridges_toggle" />
							<input type="hidden" name="slug"    value="<?php echo esc_attr( $bt['slug'] ); ?>" />
							<input type="hidden" name="enabled" value="<?php echo $is_on ? '0' : '1'; ?>" />
							<button type="submit" class="button button-small">
								<?php echo $is_on ? esc_html__( 'Disable', 'je-data-bridge-cc' ) : esc_html__( 'Enable', 'je-data-bridge-cc' ); ?>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this bridge type? Existing flatten configs cloned from it will be untouched.', 'je-data-bridge-cc' ) ); ?>');">
							<?php wp_nonce_field( 'jedb_bridges_delete' ); ?>
							<input type="hidden" name="action" value="jedb_bridges_delete" />
							<input type="hidden" name="slug"   value="<?php echo esc_attr( $bt['slug'] ); ?>" />
							<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'je-data-bridge-cc' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>

	<hr style="margin:32px 0;">

	<h2><?php echo $is_edit ? esc_html__( 'Edit bridge type', 'je-data-bridge-cc' ) : esc_html__( 'Add new bridge type', 'je-data-bridge-cc' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="jedb-bridges-form">
		<?php wp_nonce_field( 'jedb_bridges_save' ); ?>
		<input type="hidden" name="action" value="jedb_bridges_save" />
		<input type="hidden" name="original_slug" value="<?php echo esc_attr( $is_edit ? $bridge_type['slug'] : '' ); ?>" />

		<table class="form-table">
			<tr>
				<th><label for="jedb_bridges_label"><?php esc_html_e( 'Label', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_bridges_label" name="label" type="text" class="regular-text" required value="<?php echo esc_attr( $bridge_type['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Mosaic', 'je-data-bridge-cc' ); ?>" />
					<p class="description"><?php esc_html_e( 'Human-readable name. Shown in the Bridge meta box dropdown on the Woo product edit screen.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_slug"><?php esc_html_e( 'Slug', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_bridges_slug" name="slug" type="text" class="regular-text" required value="<?php echo esc_attr( $bridge_type['slug'] ); ?>" pattern="[a-z0-9_\-]+" placeholder="mosaic" />
					<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores, hyphens. Stored on each linked product as _jedb_bridge_type meta. Renaming a slug after products are linked WILL break those links — be careful.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_description"><?php esc_html_e( 'Description', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<textarea id="jedb_bridges_description" name="description" rows="2" cols="60" class="large-text"><?php echo esc_textarea( $bridge_type['description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional. Shown to editors in the meta box. Useful for explaining "what does this bridge type DO".', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_source"><?php esc_html_e( 'Source target', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<select id="jedb_bridges_source" name="source_target" required>
						<option value=""><?php esc_html_e( '— Select —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $target_options as $opt ) : ?>
							<option value="<?php echo esc_attr( $opt['slug'] ); ?>" data-kind="<?php echo esc_attr( $opt['kind'] ); ?>" <?php selected( $bridge_type['source_target'], $opt['slug'] ); ?>>
								<?php echo esc_html( $opt['label'] ); ?> · <code><?php echo esc_html( $opt['slug'] ); ?></code>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The "canonical" record store. CCTs are listed first.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_target"><?php esc_html_e( 'Target target', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<select id="jedb_bridges_target" name="target_target" required>
						<option value=""><?php esc_html_e( '— Select —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $target_options as $opt ) : ?>
							<option value="<?php echo esc_attr( $opt['slug'] ); ?>" data-kind="<?php echo esc_attr( $opt['kind'] ); ?>" <?php selected( $bridge_type['target_target'], $opt['slug'] ); ?>>
								<?php echo esc_html( $opt['label'] ); ?> · <code><?php echo esc_html( $opt['slug'] ); ?></code>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Phase 4 surfaces a meta box on Woo products specifically. Other CPT/CCT targets work today via the Flatten admin tab; a non-Woo meta box is parked as a deferred Phase 4c.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default direction', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label><input type="radio" name="default_direction" value="push"          <?php checked( $bridge_type['default_direction'], 'push' ); ?> /> <?php esc_html_e( 'Push (source → target)', 'je-data-bridge-cc' ); ?></label><br>
					<label><input type="radio" name="default_direction" value="pull"          <?php checked( $bridge_type['default_direction'], 'pull' ); ?> /> <?php esc_html_e( 'Pull (target → source)', 'je-data-bridge-cc' ); ?></label><br>
					<label><input type="radio" name="default_direction" value="bidirectional" <?php checked( $bridge_type['default_direction'], 'bidirectional' ); ?> /> <?php esc_html_e( 'Bidirectional', 'je-data-bridge-cc' ); ?></label>
					<p class="description"><?php esc_html_e( 'Per-product override is exposed in the Bridge meta box (Day 2).', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_priority"><?php esc_html_e( 'Default priority', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_bridges_priority" name="default_priority" type="number" class="small-text" min="0" max="999" value="<?php echo esc_attr( (int) $bridge_type['default_priority'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Lower runs first when multiple bridges share the same source.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_default_condition"><?php esc_html_e( 'Default condition', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<textarea id="jedb_bridges_default_condition" name="default_condition" rows="2" cols="80" class="large-text code" placeholder='{source.display_price_publicly} == "yes"'><?php echo esc_textarea( $bridge_type['default_condition'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional. Cloned into the flatten config on first link. Editors can override per-product later. v1 DSL — see BUILD-PLAN §3.5.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Link via', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label><input type="radio" name="link_via_type" value="je_relation"        <?php checked( $bridge_type['link_via']['type'], 'je_relation' ); ?> /> <?php esc_html_e( 'JetEngine Relation', 'je-data-bridge-cc' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="link_via_type" value="cct_single_post_id" <?php checked( $bridge_type['link_via']['type'], 'cct_single_post_id' ); ?> /> <?php esc_html_e( '"Has Single Page" post ID (cct_single_post_id)', 'je-data-bridge-cc' ); ?></label>

					<div id="jedb_bridges_relation_picker_wrap" style="margin-top:12px;">
						<label for="jedb_bridges_link_via_relation_id"><?php esc_html_e( 'Relation:', 'je-data-bridge-cc' ); ?></label>
						<select id="jedb_bridges_link_via_relation_id" name="link_via_relation_id" data-current-id="<?php echo esc_attr( $bridge_type['link_via']['relation_id'] ); ?>">
							<option value=""><?php esc_html_e( '— Select source + target first, then pick —', 'je-data-bridge-cc' ); ?></option>
							<?php if ( '' !== $bridge_type['link_via']['relation_id'] ) : ?>
								<option value="<?php echo esc_attr( $bridge_type['link_via']['relation_id'] ); ?>" selected>
									<?php
									/* translators: %s = relation ID */
									printf( esc_html__( 'Relation #%s (current — refresh after save to verify endpoints)', 'je-data-bridge-cc' ), esc_html( $bridge_type['link_via']['relation_id'] ) );
									?>
								</option>
							<?php endif; ?>
						</select>
						<button type="button" class="button button-small" id="jedb_bridges_refresh_relations"><?php esc_html_e( 'Refresh list for current source/target', 'je-data-bridge-cc' ); ?></button>
						<span id="jedb_bridges_relations_status" class="description" style="margin-left:8px;"></span>
					</div>

					<fieldset style="margin-top:12px;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;max-width:760px;">
						<legend style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#50575e;padding:0 6px;">
							<?php esc_html_e( 'Self-heal options (when JE Relation is the link type)', 'je-data-bridge-cc' ); ?>
						</legend>
						<label style="display:block;margin:6px 0;">
							<input type="checkbox" name="link_via_fallback_to_single_page" value="1" <?php checked( ! empty( $bridge_type['link_via']['fallback_to_single_page'] ) ); ?> />
							<?php esc_html_e( 'Fall back to cct_single_post_id when no relation row exists', 'je-data-bridge-cc' ); ?>
							<br>
							<small style="color:#646970;display:block;margin-left:24px;">
								<?php esc_html_e( 'Per L-021 — JetEngine Has-Single-Page creates the linked post on CCT save but does NOT write a relation row. With this on, the bridge resolves the target via the single-page link when the relation row is missing — so the bridge works on the very first sync without a manual picker click.', 'je-data-bridge-cc' ); ?>
							</small>
						</label>
						<label style="display:block;margin:6px 0;">
							<input type="checkbox" name="link_via_auto_attach_relation" value="1" <?php checked( ! empty( $bridge_type['link_via']['auto_attach_relation'] ) ); ?> />
							<?php esc_html_e( 'Auto-attach the missing relation row when the fallback fires', 'je-data-bridge-cc' ); ?>
							<br>
							<small style="color:#646970;display:block;margin-left:24px;">
								<?php esc_html_e( 'After the first sync, the relation row exists — JE Smart Filters / Listing Grids / Query Builder traversals work natively from then on. Idempotent.', 'je-data-bridge-cc' ); ?>
							</small>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Reverse-direction options', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="auto_create_target_when_unlinked" value="1" <?php checked( ! empty( $bridge_type['auto_create_target_when_unlinked'] ) ); ?> />
						<?php esc_html_e( 'Default: auto-create source CCT row when an unlinked post saves (D-17)', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description" style="margin-left:24px;">
						<?php esc_html_e( 'Cloned into the flatten config as default. Editors can flip per-product later. Default OFF because the action creates data.', 'je-data-bridge-cc' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'CCT-single redirect', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="cct_single_redirect" value="1" <?php checked( ! empty( $bridge_type['cct_single_redirect'] ) ); ?> />
						<?php esc_html_e( 'Redirect the CCT single page to the linked post permalink (per BUILD-PLAN §4.6)', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description" style="margin-left:24px;">
						<?php esc_html_e( 'When ON and the bridge type\'s direction includes "push", visiting the CCT row\'s "Has Single Page" URL 301s to the linked post permalink. Phase 4 Day 3 implements the runtime shim. Admins with manage_options can pass ?jedb_no_redirect=1 to bypass for debugging.', 'je-data-bridge-cc' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Enabled', 'je-data-bridge-cc' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $bridge_type['enabled'] ) ); ?> />
						<?php esc_html_e( 'Available in the Bridge meta box dropdown', 'je-data-bridge-cc' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Disabled bridge types stay listed here for reference but won\'t appear when an editor wires up a new product.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_bridges_json"><?php esc_html_e( 'Defaults JSON', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<details <?php echo $is_edit ? 'open' : ''; ?>>
						<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Default field mappings, taxonomies, variations (raw JSON)', 'je-data-bridge-cc' ); ?></summary>
						<p class="description" style="margin-top:8px;">
							<?php esc_html_e( 'Phase 4 Day 1 ships the "shape" — the Bridge meta box (Day 2) reads this JSON when an editor first links a product, clones it into a flatten config, then editors fine-tune per-product in the Flatten admin tab. For now, the easiest workflow is:', 'je-data-bridge-cc' ); ?>
							<br>
							1. <?php esc_html_e( 'Configure one bridge in the Flatten admin tab manually (mappings + taxonomies).', 'je-data-bridge-cc' ); ?>
							<br>
							2. <?php esc_html_e( 'Copy its mappings[] and taxonomies[] arrays into this JSON.', 'je-data-bridge-cc' ); ?>
							<br>
							3. <?php esc_html_e( 'Save the bridge type. Future products linked to this type will start from those defaults.', 'je-data-bridge-cc' ); ?>
						</p>
						<textarea id="jedb_bridges_json" name="bridge_type_json" rows="14" cols="100" class="large-text code"><?php
							$json_payload = array(
								'default_field_mappings' => $bridge_type['default_field_mappings'],
								'default_taxonomies'     => $bridge_type['default_taxonomies'],
								'variations'             => $bridge_type['variations'],
							);
							echo esc_textarea( wp_json_encode( $json_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
						?></textarea>
						<p class="description">
							<?php esc_html_e( 'Top-level keys: default_field_mappings (array), default_taxonomies (array), variations (array — Phase 4b). Other top-level keys (slug, label, source_target, target_target, default_direction, etc.) are read from the form fields above and override anything you put in the JSON.', 'je-data-bridge-cc' ); ?>
						</p>
					</details>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Save bridge type', 'je-data-bridge-cc' ) : esc_html__( 'Create bridge type', 'je-data-bridge-cc' ); ?></button>
			<a class="button" href="<?php echo esc_url( JEDB_Admin_Shell::tab_url( JEDB_Tab_Bridges::TAB_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'je-data-bridge-cc' ); ?></a>
		</p>
	</form>

	<div id="jedb_bridges_import_dialog" class="jedb-bridges-import-dialog" style="display:none;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'jedb_bridges_import' ); ?>
			<input type="hidden" name="action" value="jedb_bridges_import" />

			<h2><?php esc_html_e( 'Import bridge types', 'je-data-bridge-cc' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Paste a JSON payload exported by this plugin (or a hand-crafted one). Top-level shape: either a "bridge_types" array (the export format) or a bare array of bridge type entries.', 'je-data-bridge-cc' ); ?>
			</p>
			<textarea name="import_json" rows="14" cols="100" class="large-text code" placeholder='{"bridge_types":[...]}'></textarea>
			<p>
				<label>
					<input type="checkbox" name="replace_all" value="1" />
					<strong><?php esc_html_e( 'Replace ALL existing bridge types', 'je-data-bridge-cc' ); ?></strong>
					— <?php esc_html_e( 'destructive, can\'t be undone. Off by default — imports merge by slug otherwise.', 'je-data-bridge-cc' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'je-data-bridge-cc' ); ?></button>
				<button type="button" class="button" id="jedb_bridges_import_cancel"><?php esc_html_e( 'Cancel', 'je-data-bridge-cc' ); ?></button>
			</p>
		</form>
	</div>

</div>

<script type="application/json" id="jedb-bridges-bootstrap"><?php
echo wp_json_encode( array(
	'ajax_url'       => admin_url( 'admin-ajax.php' ),
	'nonce'          => wp_create_nonce( 'jedb_bridges_admin' ),
	'editing_slug'   => $is_edit ? $bridge_type['slug'] : '',
	'current_link_via_relation_id' => (string) $bridge_type['link_via']['relation_id'],
) );
?></script>
