<?php
/**
 * Debug tab template.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$settings        = get_option( JEDB_OPTION_SETTINGS, array() );
$log_enabled     = ! empty( $settings['enable_debug_log'] );
$log_path        = jedb_log_path();
$log_size        = JEDB_Tab_Debug::log_size_human();
$log_last_mod    = JEDB_Tab_Debug::log_last_modified();
$log_tail        = JEDB_Tab_Debug::tail_log( 500 );
$diagnostic      = get_transient( 'jedb_diagnostic_result' );
$notice          = isset( $_GET['jedb_notice'] ) ? sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$notice_map = array(
	'log_enabled'      => array( 'success', __( 'Debug logging ENABLED.', 'je-data-bridge-cc' ) ),
	'log_disabled'     => array( 'success', __( 'Debug logging DISABLED.', 'je-data-bridge-cc' ) ),
	'log_cleared'      => array( 'success', __( 'Debug log file cleared.', 'je-data-bridge-cc' ) ),
	'log_missing'      => array( 'warning', __( 'No log file to download yet.', 'je-data-bridge-cc' ) ),
	'diagnostic_done'  => array( 'success', __( 'Discovery diagnostic complete — see the result panel and the log below.', 'je-data-bridge-cc' ) ),
);
?>

<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
		<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Debug', 'je-data-bridge-cc' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Enable file logging, view the tail, download the full log, and run a one-shot Discovery diagnostic to capture exactly what the plugin sees.', 'je-data-bridge-cc' ); ?>
</p>

<div class="jedb-debug-grid">

	<div class="jedb-debug-card">
		<h3><?php esc_html_e( 'Debug log', 'je-data-bridge-cc' ); ?></h3>

		<table class="widefat striped jedb-status-table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Status', 'je-data-bridge-cc' ); ?></th>
					<td>
						<?php if ( $log_enabled ) : ?>
							<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'ENABLED', 'je-data-bridge-cc' ); ?></span>
						<?php else : ?>
							<span class="jedb-pill jedb-pill-warn"><?php esc_html_e( 'DISABLED', 'je-data-bridge-cc' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Path', 'je-data-bridge-cc' ); ?></th>
					<td><code><?php echo esc_html( $log_path ?: '—' ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Size', 'je-data-bridge-cc' ); ?></th>
					<td><?php echo esc_html( $log_size ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last write', 'je-data-bridge-cc' ); ?></th>
					<td><?php echo esc_html( $log_last_mod ?: '—' ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="jedb-debug-actions">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'jedb_toggle_debug_log' ); ?>
				<input type="hidden" name="action" value="jedb_toggle_debug_log" />
				<button type="submit" class="button button-primary">
					<?php echo $log_enabled ? esc_html__( 'Disable debug log', 'je-data-bridge-cc' ) : esc_html__( 'Enable debug log', 'je-data-bridge-cc' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'jedb_clear_debug_log' ); ?>
				<input type="hidden" name="action" value="jedb_clear_debug_log" />
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Clear the entire debug log? This cannot be undone.', 'je-data-bridge-cc' ) ); ?>');">
					<?php esc_html_e( 'Clear log', 'je-data-bridge-cc' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'jedb_download_debug_log' ); ?>
				<input type="hidden" name="action" value="jedb_download_debug_log" />
				<button type="submit" class="button">
					<span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Download log', 'je-data-bridge-cc' ); ?>
				</button>
			</form>

		</div>
	</div>

	<div class="jedb-debug-card">
		<h3><?php esc_html_e( 'Discovery diagnostic', 'je-data-bridge-cc' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Runs a deep dump of every discovery channel: CCT module, post-type queries, and JEDB_Discovery itself. Auto-enables debug logging if it was off so the run is captured. Result is written to the log AND shown in the result panel below.', 'je-data-bridge-cc' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'jedb_run_discovery_diagnostic' ); ?>
			<input type="hidden" name="action" value="jedb_run_discovery_diagnostic" />
			<button type="submit" class="button button-primary button-large">
				<span class="dashicons dashicons-search" style="vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Run discovery diagnostic', 'je-data-bridge-cc' ); ?>
			</button>
		</form>

		<?php if ( is_array( $diagnostic ) ) : ?>
			<h4 style="margin-top:24px;"><?php esc_html_e( 'Last result', 'je-data-bridge-cc' ); ?></h4>
			<table class="widefat striped jedb-status-table">
				<tbody>
					<?php
					$rows = array(
						__( 'Run at',                          'je-data-bridge-cc' ) => esc_html( $diagnostic['started_at'] ) . ' UTC',
						__( 'jet_engine() function exists',    'je-data-bridge-cc' ) => $diagnostic['jet_engine_loaded']    ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>',
						__( 'JE version detected',             'je-data-bridge-cc' ) => esc_html( $diagnostic['jet_engine_version'] ?: '—' ),
						__( 'CCT module class autoloads',      'je-data-bridge-cc' ) => $diagnostic['cct_module_class']     ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>',
						__( 'CCT module instance',             'je-data-bridge-cc' ) => $diagnostic['cct_module_instance']  ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>',
						__( 'CCT manager property present',    'je-data-bridge-cc' ) => $diagnostic['cct_manager_present']  ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>',
						__( 'Raw CCT count from manager',      'je-data-bridge-cc' ) => esc_html( (string) $diagnostic['raw_cct_count'] ),
						__( 'CCT slugs',                       'je-data-bridge-cc' ) => empty( $diagnostic['cct_slugs'] ) ? '<em>—</em>' : '<code>' . esc_html( implode( ', ', (array) $diagnostic['cct_slugs'] ) ) . '</code>',
						__( 'get_post_types builtin count',    'je-data-bridge-cc' ) => esc_html( (string) $diagnostic['public_post_types_builtin'] ),
						__( 'get_post_types custom public',    'je-data-bridge-cc' ) => esc_html( (string) $diagnostic['public_post_types_custom'] ),
						__( 'All post type slugs',             'je-data-bridge-cc' ) => empty( $diagnostic['all_post_type_slugs'] ) ? '<em>—</em>' : '<code>' . esc_html( implode( ', ', (array) $diagnostic['all_post_type_slugs'] ) ) . '</code>',
						__( 'JEDB_Discovery CCTs returned',    'je-data-bridge-cc' ) => esc_html( (string) ( isset( $diagnostic['discovery_ccts'] )      ? $diagnostic['discovery_ccts']      : '—' ) ),
						__( 'JEDB_Discovery CPTs returned',    'je-data-bridge-cc' ) => esc_html( (string) ( isset( $diagnostic['discovery_cpts'] )      ? $diagnostic['discovery_cpts']      : '—' ) ),
						__( 'JEDB_Discovery Relations returned','je-data-bridge-cc' ) => esc_html( (string) ( isset( $diagnostic['discovery_relations'] ) ? $diagnostic['discovery_relations'] : '—' ) ),
						__( 'WooCommerce active',              'je-data-bridge-cc' ) => $diagnostic['wc_active']             ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-warn">NO</span>',
					);
					foreach ( $rows as $label => $value ) :
						?>
						<tr>
							<th style="width:280px;"><?php echo esc_html( $label ); ?></th>
							<td><?php echo wp_kses_post( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! empty( $diagnostic['errors'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Errors caught', 'je-data-bridge-cc' ); ?></th>
							<td>
								<ul style="margin:0;list-style:disc;padding-left:18px;">
									<?php foreach ( $diagnostic['errors'] as $err ) : ?>
										<li><code style="color:#b32d2e;"><?php echo esc_html( $err ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<h3 style="margin-top:32px;"><?php esc_html_e( 'Log tail (last 500 lines)', 'je-data-bridge-cc' ); ?></h3>
<?php if ( '' === $log_tail ) : ?>
	<p class="description">
		<?php
		if ( $log_enabled ) {
			esc_html_e( 'Log file is empty. Run the diagnostic above or interact with the plugin to generate entries.', 'je-data-bridge-cc' );
		} else {
			esc_html_e( 'Debug logging is disabled. Click "Enable debug log" above to start capturing.', 'je-data-bridge-cc' );
		}
		?>
	</p>
<?php else : ?>
	<pre class="jedb-log-viewer"><?php echo esc_html( $log_tail ); ?></pre>
<?php endif; ?>
