<?php
/**
 * Phase 0 status tab.
 *
 * Sanity-check screen: shows whether each custom table exists, whether the
 * snippet uploads directory is present and protected, and which JetEngine /
 * WooCommerce versions the plugin can see. If any cell is red on a fresh
 * install, the activation hook didn't run cleanly.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'JEDB_Config_DB' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/class-config-db.php';
}
if ( ! class_exists( 'JEDB_Snippet_Installer' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/snippets/class-snippet-installer.php';
}

$tables_status   = JEDB_Config_DB::tables_exist();
$snippet_dir     = JEDB_Snippet_Installer::get_dir();
$snippet_dir_ok  = $snippet_dir && JEDB_Snippet_Installer::verify( $snippet_dir );
$jet_engine_ver  = defined( 'JET_ENGINE_VERSION' ) ? JET_ENGINE_VERSION : null;
$wc_active       = class_exists( 'WooCommerce' );
$wc_version      = $wc_active && defined( 'WC_VERSION' ) ? WC_VERSION : null;
$hpos_enabled    = false;
if ( $wc_active && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
	$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}
?>

<h2><?php esc_html_e( 'Phase 0 — Plugin Status', 'je-data-bridge-cc' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'This screen verifies that the activation hook ran cleanly. If anything below is red, deactivate and reactivate the plugin to re-run the installer.', 'je-data-bridge-cc' ); ?>
</p>

<h3><?php esc_html_e( 'Custom database tables', 'je-data-bridge-cc' ); ?></h3>
<table class="widefat striped jedb-status-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Table', 'je-data-bridge-cc' ); ?></th>
			<th><?php esc_html_e( 'Status', 'je-data-bridge-cc' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( JEDB_Config_DB::table_names() as $key => $name ) : ?>
			<?php $exists = ! empty( $tables_status[ $key ] ); ?>
			<tr>
				<td><code><?php echo esc_html( $name ); ?></code></td>
				<td>
					<?php if ( $exists ) : ?>
						<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'OK', 'je-data-bridge-cc' ); ?></span>
					<?php else : ?>
						<span class="jedb-pill jedb-pill-bad"><?php esc_html_e( 'MISSING', 'je-data-bridge-cc' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h3><?php esc_html_e( 'Snippet uploads directory', 'je-data-bridge-cc' ); ?></h3>
<table class="widefat striped jedb-status-table">
	<tbody>
		<tr>
			<th><?php esc_html_e( 'Path', 'je-data-bridge-cc' ); ?></th>
			<td><code><?php echo esc_html( $snippet_dir ?: '—' ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Status', 'je-data-bridge-cc' ); ?></th>
			<td>
				<?php if ( $snippet_dir_ok ) : ?>
					<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'OK (.htaccess + index.php present)', 'je-data-bridge-cc' ); ?></span>
				<?php else : ?>
					<span class="jedb-pill jedb-pill-bad"><?php esc_html_e( 'NOT READY', 'je-data-bridge-cc' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<h3><?php esc_html_e( 'Detected dependencies', 'je-data-bridge-cc' ); ?></h3>
<table class="widefat striped jedb-status-table">
	<tbody>
		<tr>
			<th><?php esc_html_e( 'JetEngine', 'je-data-bridge-cc' ); ?></th>
			<td>
				<?php if ( $jet_engine_ver ) : ?>
					<span class="jedb-pill jedb-pill-ok"><?php echo esc_html( 'v' . $jet_engine_ver ); ?></span>
					<?php if ( version_compare( $jet_engine_ver, JEDB_MIN_JE_VERSION, '<' ) ) : ?>
						<span class="jedb-pill jedb-pill-warn">
							<?php
							printf(
								/* translators: %s: required JetEngine version */
								esc_html__( 'Below required minimum (%s)', 'je-data-bridge-cc' ),
								esc_html( JEDB_MIN_JE_VERSION )
							);
							?>
						</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="jedb-pill jedb-pill-bad"><?php esc_html_e( 'NOT DETECTED', 'je-data-bridge-cc' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'WooCommerce', 'je-data-bridge-cc' ); ?></th>
			<td>
				<?php if ( $wc_active ) : ?>
					<span class="jedb-pill jedb-pill-ok"><?php echo esc_html( $wc_version ? 'v' . $wc_version : 'active' ); ?></span>
					<span class="jedb-pill <?php echo $hpos_enabled ? 'jedb-pill-ok' : 'jedb-pill-warn'; ?>">
						<?php
						echo $hpos_enabled
							? esc_html__( 'HPOS: enabled', 'je-data-bridge-cc' )
							: esc_html__( 'HPOS: disabled', 'je-data-bridge-cc' );
						?>
					</span>
				<?php else : ?>
					<span class="jedb-pill jedb-pill-warn"><?php esc_html_e( 'Not active (CCT-only mode)', 'je-data-bridge-cc' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'PHP', 'je-data-bridge-cc' ); ?></th>
			<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'WordPress', 'je-data-bridge-cc' ); ?></th>
			<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Plugin DB schema', 'je-data-bridge-cc' ); ?></th>
			<td><code><?php echo esc_html( get_option( JEDB_OPTION_DB_VERSION, '—' ) ); ?></code> / <code><?php echo esc_html( JEDB_DB_VERSION ); ?></code></td>
		</tr>
	</tbody>
</table>

<p class="description" style="margin-top:1.5em;">
	<?php
	printf(
		/* translators: %s: GitHub URL */
		wp_kses(
			__( 'Phase 0 scaffold complete. See <a href="%s" target="_blank" rel="noopener">BUILD-PLAN.md</a> for the full roadmap.', 'je-data-bridge-cc' ),
			array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
		),
		'https://github.com/legworkmedia/je-data-bridge-cc/blob/main/BUILD-PLAN.md'
	);
	?>
</p>
