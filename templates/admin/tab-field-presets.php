<?php
/**
 * Field Presets admin tab template — Phase 4 Day 4 (§4.12).
 *
 * Lists all saved presets and renders an add/edit form for one preset.
 * The form's per-field rows are added/removed dynamically by
 * field-presets-admin.js. Export/import are handled by separate
 * admin-post forms below the editor.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$tab     = JEDB_Tab_Field_Presets::instance();
$manager = JEDB_Field_Presets_Manager::instance();

$edit_slug    = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing      = '' !== $edit_slug ? $manager->get_by_slug( $edit_slug ) : null;
$is_edit      = (bool) $editing;
$preset       = $editing ? $editing : JEDB_Field_Presets_Manager::default_preset();

$all_presets  = $manager->get_all();
$target_opts  = $tab->get_target_options_for_select();

// Notice resolution (admin-post handlers stamp `jedb_notice` query args).
if ( isset( $_GET['jedb_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key   = sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error = isset( $_GET['jedb_error'] ) ? sanitize_text_field( wp_unslash( $_GET['jedb_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$accepted = isset( $_GET['jedb_accepted'] ) ? (int) $_GET['jedb_accepted'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$dropped  = isset( $_GET['jedb_dropped'] )  ? (int) $_GET['jedb_dropped']  : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$messages = array(
		'preset_saved'              => array( 'updated', __( 'Preset saved.', 'je-data-bridge-cc' ) ),
		'preset_deleted'            => array( 'updated', __( 'Preset deleted.', 'je-data-bridge-cc' ) ),
		'preset_save_failed'        => array( 'error',   sprintf( __( 'Save failed: %s', 'je-data-bridge-cc' ), $error ) ),
		'preset_imported'           => array( 'updated', sprintf( __( 'Import complete — %1$d accepted, %2$d dropped.', 'je-data-bridge-cc' ), $accepted, $dropped ) ),
		'preset_import_invalid_json'=> array( 'error',   __( 'Import failed — payload was not valid JSON or wasn\'t an array / { presets: [...] } envelope.', 'je-data-bridge-cc' ) ),
		'invalid_id'                => array( 'error',   __( 'Invalid preset slug.', 'je-data-bridge-cc' ) ),
	);

	if ( isset( $messages[ $key ] ) ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $key ][0] ),
			esc_html( $messages[ $key ][1] )
		);
	}
endif;
?>

<div class="jedb-field-presets-tab">

	<div class="jedb-targets-header">
		<div>
			<h2><?php esc_html_e( 'Field Presets', 'je-data-bridge-cc' ); ?></h2>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Portable, target-scoped lists of fields that answer "for adapter X, what does a complete bridge look like?". Each preset is bound to one target adapter (e.g. posts::product, cct::mosaics_data) and carries field names + labels + mandatory flags + freeform group labels. Apply a preset to a bridge in the Flatten tab to seed its mandatory coverage list, or scaffold missing mappings to auto-stub passthrough rows.', 'je-data-bridge-cc' ); ?>
			</p>
		</div>
		<form method="get" action="">
			<input type="hidden" name="page" value="<?php echo esc_attr( JEDB_Admin_Shell::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab"  value="<?php echo esc_attr( JEDB_Tab_Field_Presets::TAB_SLUG ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Add new preset', 'je-data-bridge-cc' ); ?></button>
		</form>
	</div>

	<?php if ( empty( $all_presets ) ) : ?>
		<p><em><?php esc_html_e( 'No presets yet. Use the form below to create your first preset.', 'je-data-bridge-cc' ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped jedb-field-presets-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label / slug', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Target adapter', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Fields', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Mandatory', 'je-data-bridge-cc' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'je-data-bridge-cc' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $all_presets as $p ) :
				$total_fields    = count( $p['fields'] );
				$mandatory_count = 0;
				foreach ( $p['fields'] as $f ) {
					if ( ! empty( $f['mandatory'] ) ) {
						$mandatory_count++;
					}
				}
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $p['label'] !== '' ? $p['label'] : $p['slug'] ); ?></strong>
						<br>
						<code style="font-size:11px;"><?php echo esc_html( $p['slug'] ); ?></code>
					</td>
					<td><code><?php echo esc_html( $p['target'] ); ?></code></td>
					<td><?php echo (int) $total_fields; ?></td>
					<td><?php echo (int) $mandatory_count; ?></td>
					<td><small><?php echo esc_html( $p['updated_at'] ); ?></small></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $p['slug'], JEDB_Admin_Shell::tab_url( JEDB_Tab_Field_Presets::TAB_SLUG ) ) ); ?>"><?php esc_html_e( 'Edit', 'je-data-bridge-cc' ); ?></a>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this preset? Bridges that already applied it keep their required_overrides entries — deletion only removes the preset itself.', 'je-data-bridge-cc' ) ); ?>');">
							<?php wp_nonce_field( 'jedb_field_presets_delete' ); ?>
							<input type="hidden" name="action" value="jedb_field_presets_delete" />
							<input type="hidden" name="slug"   value="<?php echo esc_attr( $p['slug'] ); ?>" />
							<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'je-data-bridge-cc' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr style="margin:32px 0;">

	<h2><?php echo $is_edit ? esc_html__( 'Edit preset', 'je-data-bridge-cc' ) : esc_html__( 'Add new preset', 'je-data-bridge-cc' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="jedb-field-presets-form">
		<?php wp_nonce_field( 'jedb_field_presets_save' ); ?>
		<input type="hidden" name="action" value="jedb_field_presets_save" />

		<table class="form-table">
			<tr>
				<th><label for="jedb_preset_slug"><?php esc_html_e( 'Slug', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_preset_slug" name="slug" type="text" class="regular-text" value="<?php echo esc_attr( $preset['slug'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. woocommerce-storefront-visible', 'je-data-bridge-cc' ); ?>" <?php echo $is_edit ? 'readonly' : ''; ?> required />
					<p class="description"><?php esc_html_e( 'Lowercase, kebab-case. Used as the unique identifier across sites — pick something descriptive so you recognize it after import.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_preset_label"><?php esc_html_e( 'Label', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<input id="jedb_preset_label" name="label" type="text" class="regular-text" value="<?php echo esc_attr( $preset['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. WooCommerce — Storefront Visible', 'je-data-bridge-cc' ); ?>" required />
				</td>
			</tr>
			<tr>
				<th><label for="jedb_preset_target"><?php esc_html_e( 'Target adapter', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<select id="jedb_preset_target" name="target" required>
						<option value=""><?php esc_html_e( '— Select —', 'je-data-bridge-cc' ); ?></option>
						<?php foreach ( $target_opts as $opt ) : ?>
							<option value="<?php echo esc_attr( $opt['slug'] ); ?>" <?php selected( $preset['target'], $opt['slug'] ); ?>><?php echo esc_html( $opt['label'] ); ?> · <code><?php echo esc_html( $opt['slug'] ); ?></code></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Bind this preset to one target adapter. Only bridges whose target_target matches this adapter slug will see it in their "Apply preset" dropdown.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_preset_description"><?php esc_html_e( 'Description', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<textarea id="jedb_preset_description" name="description" rows="2" class="large-text"><?php echo esc_textarea( $preset['description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Short summary that explains what this preset is for. Shown to editors choosing presets from the Flatten admin tab.', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="jedb_preset_notes"><?php esc_html_e( 'Notes', 'je-data-bridge-cc' ); ?></label></th>
				<td>
					<textarea id="jedb_preset_notes" name="notes" rows="2" class="large-text"><?php echo esc_textarea( $preset['notes'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Internal notes (carries through JSON export/import).', 'je-data-bridge-cc' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Fields', 'je-data-bridge-cc' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Each row is one field this target adapter SHOULD have a mapping for in a "complete" bridge. Tick Mandatory for fields the bridge must cover. Group lets you cluster related fields visually.', 'je-data-bridge-cc' ); ?></p>

		<table class="widefat jedb-field-presets-fields" id="jedb_field_presets_fields">
			<thead>
				<tr>
					<th style="width:24%;"><?php esc_html_e( 'Field name', 'je-data-bridge-cc' ); ?></th>
					<th style="width:22%;"><?php esc_html_e( 'Label', 'je-data-bridge-cc' ); ?></th>
					<th style="width:10%;"><?php esc_html_e( 'Mandatory', 'je-data-bridge-cc' ); ?></th>
					<th style="width:18%;"><?php esc_html_e( 'Group', 'je-data-bridge-cc' ); ?></th>
					<th style="width:22%;"><?php esc_html_e( 'Hint', 'je-data-bridge-cc' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $preset['fields'] ) ) : ?>
					<?php foreach ( $preset['fields'] as $f ) : ?>
						<tr class="jedb-preset-field-row">
							<td><input type="text" name="field_name[]"      value="<?php echo esc_attr( $f['name'] ); ?>"  class="regular-text" placeholder="<?php esc_attr_e( 'e.g. regular_price', 'je-data-bridge-cc' ); ?>" /></td>
							<td><input type="text" name="field_label[]"     value="<?php echo esc_attr( $f['label'] ); ?>" class="regular-text" /></td>
							<td><input type="checkbox" name="field_mandatory[<?php echo (int) array_search( $f, $preset['fields'], true ); ?>]" value="1" <?php checked( ! empty( $f['mandatory'] ) ); ?> /></td>
							<td><input type="text" name="field_group[]"     value="<?php echo esc_attr( $f['group'] ); ?>" class="regular-text" /></td>
							<td><input type="text" name="field_hint[]"      value="<?php echo esc_attr( $f['hint'] ); ?>"  class="regular-text" /></td>
							<td><button type="button" class="button button-small button-link-delete jedb-preset-field-remove"><?php esc_html_e( 'Remove', 'je-data-bridge-cc' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="6">
						<button type="button" class="button" id="jedb_field_presets_add_field"><?php esc_html_e( '+ Add field', 'je-data-bridge-cc' ); ?></button>
					</td>
				</tr>
			</tfoot>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Save preset', 'je-data-bridge-cc' ) : esc_html__( 'Create preset', 'je-data-bridge-cc' ); ?></button>
		</p>
	</form>

	<hr style="margin:32px 0;">

	<h2><?php esc_html_e( 'Export / Import', 'je-data-bridge-cc' ); ?></h2>
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Presets are site-portable knowledge. Export them as a JSON file on one site, drop into a fresh site, import. Choose "Replace all" to overwrite the destination site\'s presets entirely; otherwise the import merges (presets with the same slug are overwritten by the incoming version).', 'je-data-bridge-cc' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:16px;">
		<?php wp_nonce_field( 'jedb_field_presets_export' ); ?>
		<input type="hidden" name="action" value="jedb_field_presets_export" />
		<button type="submit" class="button"><?php esc_html_e( 'Export presets as JSON', 'je-data-bridge-cc' ); ?></button>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;max-width:820px;">
		<?php wp_nonce_field( 'jedb_field_presets_import' ); ?>
		<input type="hidden" name="action" value="jedb_field_presets_import" />
		<p>
			<label for="jedb_preset_import_payload"><strong><?php esc_html_e( 'Paste JSON to import:', 'je-data-bridge-cc' ); ?></strong></label>
		</p>
		<textarea id="jedb_preset_import_payload" name="payload" rows="10" class="large-text code" spellcheck="false" style="font-family:Consolas,Menlo,Monaco,monospace;font-size:12px;" placeholder='{ "presets": [ ... ] } or [ ... ]'></textarea>
		<p>
			<label><input type="checkbox" name="replace_all" value="1" /> <?php esc_html_e( 'Replace all existing presets (destructive — current presets are deleted)', 'je-data-bridge-cc' ); ?></label>
		</p>
		<p>
			<button type="submit" class="button"><?php esc_html_e( 'Import', 'je-data-bridge-cc' ); ?></button>
		</p>
	</form>

</div>

<script type="application/json" id="jedb-field-presets-bootstrap">
<?php echo wp_json_encode( array(
	'is_edit'      => $is_edit,
	'edit_slug'    => $edit_slug,
) ); ?>
</script>
