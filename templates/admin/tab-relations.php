<?php
/**
 * Relations admin tab template.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'JEDB_Relation_Config_Manager' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/relations/class-relation-config-manager.php';
}
if ( ! class_exists( 'JEDB_Relation_Attacher' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/relations/class-relation-attacher.php';
}
if ( ! class_exists( 'JEDB_Tab_Relations' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/admin/class-tab-relations.php';
}

$manager       = JEDB_Relation_Config_Manager::instance();
$tab           = JEDB_Tab_Relations::instance();
$configs       = $manager->get_all();
$relations_map = $tab->get_relations_per_cct();
$registry      = JEDB_Target_Registry::instance();

$notice = isset( $_GET['jedb_notice'] ) ? sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$notice_map = array(
	'config_saved'    => array( 'success', __( 'Relation config saved.', 'je-data-bridge-cc' ) ),
	'config_enabled'  => array( 'success', __( 'Relation config enabled.', 'je-data-bridge-cc' ) ),
	'config_disabled' => array( 'success', __( 'Relation config disabled.', 'je-data-bridge-cc' ) ),
	'config_deleted'  => array( 'success', __( 'Relation config deleted.', 'je-data-bridge-cc' ) ),
	'invalid_cct'     => array( 'error',   __( 'Missing or invalid CCT slug.', 'je-data-bridge-cc' ) ),
	'save_failed'     => array( 'error',   __( 'Failed to save relation config — see debug log.', 'je-data-bridge-cc' ) ),
);

$cct_targets   = $registry->all_of_kind( 'cct' );
$existing_ccts = array();
foreach ( $configs as $config ) {
	if ( isset( $config['source_target'] ) && 0 === strpos( (string) $config['source_target'], 'cct::' ) ) {
		$existing_ccts[] = substr( $config['source_target'], 5 );
	}
}

$ccts_without_config = array();
foreach ( $cct_targets as $slug => $target ) {
	$cct_slug = str_replace( 'cct::', '', $slug );
	if ( ! in_array( $cct_slug, $existing_ccts, true ) && ! empty( $relations_map[ $cct_slug ] ) ) {
		$ccts_without_config[ $cct_slug ] = $target->get_label();
	}
}
?>

<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
		<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Phase 2 — Relation picker configurations', 'je-data-bridge-cc' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Pick which existing JetEngine Relations should show as a picker on each CCT\'s edit screen. Relations themselves are still created and edited in JetEngine → Relations — this tab only controls which ones the picker UI exposes.', 'je-data-bridge-cc' ); ?>
</p>

<?php /* ----------------------------------------------------------------- */ ?>
<h3><?php esc_html_e( 'Configured CCTs', 'je-data-bridge-cc' ); ?></h3>

<?php if ( empty( $configs ) ) : ?>
	<p class="description">
		<em><?php esc_html_e( 'No relation configs yet. Use the form below to add the first one.', 'je-data-bridge-cc' ); ?></em>
	</p>
<?php else : ?>
	<div class="jedb-relation-cards">
		<?php
		foreach ( $configs as $config ) {
			$cct_slug      = isset( $config['source_target'] ) ? str_replace( 'cct::', '', (string) $config['source_target'] ) : '';
			$tab_relations = isset( $relations_map[ $cct_slug ] ) ? $relations_map[ $cct_slug ] : array();
			include JEDB_PLUGIN_DIR . 'templates/admin/relation-config-card.php';
		}
		?>
	</div>
<?php endif; ?>

<?php /* ----------------------------------------------------------------- */ ?>
<h3 style="margin-top:32px;"><?php esc_html_e( 'Add a new configuration', 'je-data-bridge-cc' ); ?></h3>

<?php if ( empty( $ccts_without_config ) ) : ?>
	<p class="description">
		<?php
		if ( empty( $cct_targets ) ) {
			esc_html_e( 'No CCTs discovered yet. Refresh discovery on the Targets tab.', 'je-data-bridge-cc' );
		} else {
			esc_html_e( 'Every discovered CCT that has at least one JetEngine Relation already has a config. Edit the cards above to change which relations are enabled.', 'je-data-bridge-cc' );
		}
		?>
	</p>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jedb-relation-add-form">
		<?php wp_nonce_field( 'jedb_relation_config_save' ); ?>
		<input type="hidden" name="action" value="jedb_relation_config_save" />

		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="jedb-add-cct-slug"><?php esc_html_e( 'CCT', 'je-data-bridge-cc' ); ?></label>
					</th>
					<td>
						<select name="cct_slug" id="jedb-add-cct-slug" required>
							<option value=""><?php esc_html_e( '— select a CCT —', 'je-data-bridge-cc' ); ?></option>
							<?php foreach ( $ccts_without_config as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only CCTs without an existing config and with at least one matching JE Relation are listed.', 'je-data-bridge-cc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled relations', 'je-data-bridge-cc' ); ?></th>
					<td>
						<div id="jedb-add-relations-list">
							<p class="description">
								<em><?php esc_html_e( 'Pick a CCT first; available relations will appear here.', 'je-data-bridge-cc' ); ?></em>
							</p>
						</div>
						<script type="application/json" id="jedb-relations-map">
							<?php echo wp_json_encode( $relations_map ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</script>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Save configuration', 'je-data-bridge-cc' ); ?>
			</button>
		</p>
	</form>

	<script>
	(function () {
		var select = document.getElementById('jedb-add-cct-slug');
		var listEl = document.getElementById('jedb-add-relations-list');
		var mapEl  = document.getElementById('jedb-relations-map');
		if (!select || !listEl || !mapEl) { return; }

		var relationsMap = {};
		try { relationsMap = JSON.parse(mapEl.textContent || '{}'); } catch (e) { relationsMap = {}; }

		select.addEventListener('change', function () {
			var slug = select.value;
			var rels = relationsMap[slug] || [];

			if (!slug) {
				listEl.innerHTML = '<p class="description"><em><?php echo esc_js( __( 'Pick a CCT first; available relations will appear here.', 'je-data-bridge-cc' ) ); ?></em></p>';
				return;
			}
			if (!rels.length) {
				listEl.innerHTML = '<p class="description"><em><?php echo esc_js( __( 'No JetEngine Relations involve this CCT yet. Create one in JetEngine → Relations first.', 'je-data-bridge-cc' ) ); ?></em></p>';
				return;
			}

			var html = '<ul style="margin:0;list-style:none;padding:0;">';
			rels.forEach(function (rel) {
				html += '<li style="margin-bottom:6px;">' +
					'<label>' +
						'<input type="checkbox" name="enabled_relations[]" value="' + rel.id + '" />' +
						' <strong>' + escapeHtml(rel.name) + '</strong>' +
						' <code style="font-size:11px;color:#646970;">' + rel.type + '</code>' +
						' &middot; ' +
						'<span style="font-size:12px;color:#646970;"><?php echo esc_js( __( 'this CCT is the', 'je-data-bridge-cc' ) ); ?> ' + rel.cct_side + ', <?php echo esc_js( __( 'other side:', 'je-data-bridge-cc' ) ); ?> ' + escapeHtml(rel.other_label) + '</span>' +
					'</label>' +
					'</li>';
			});
			html += '</ul>';
			listEl.innerHTML = html;
		});

		function escapeHtml(s) {
			return String(s)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}
	})();
	</script>
<?php endif; ?>
