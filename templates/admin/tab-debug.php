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
$cct_diagnostic  = get_transient( 'jedb_cct_diagnostic_result' );
$notice          = isset( $_GET['jedb_notice'] ) ? sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$notice_map = array(
	'log_enabled'         => array( 'success', __( 'Debug logging ENABLED.', 'je-data-bridge-cc' ) ),
	'log_disabled'        => array( 'success', __( 'Debug logging DISABLED.', 'je-data-bridge-cc' ) ),
	'log_cleared'         => array( 'success', __( 'Debug log file cleared.', 'je-data-bridge-cc' ) ),
	'log_missing'         => array( 'warning', __( 'No log file to download yet.', 'je-data-bridge-cc' ) ),
	'diagnostic_done'     => array( 'success', __( 'Discovery diagnostic complete — see the result panel and the log below.', 'je-data-bridge-cc' ) ),
	'cct_diagnostic_done' => array( 'success', __( 'CCT diagnostic complete — see the per-CCT panel and the log below.', 'je-data-bridge-cc' ) ),
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

<h3 style="margin-top:32px;"><?php esc_html_e( 'Per-CCT diagnostic', 'je-data-bridge-cc' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Dumps each CCT\'s field config (as JE sees it), live DB columns, item counts via SQL and via the JE db handle, and a list of fields the schema filter dropped (tabs, section separators, etc.). Use this to diagnose mismatches between the JE UI field count and the Targets-tab field count, or 0-item counts when items exist.', 'je-data-bridge-cc' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
	<?php wp_nonce_field( 'jedb_run_cct_diagnostic' ); ?>
	<input type="hidden" name="action" value="jedb_run_cct_diagnostic" />
	<button type="submit" class="button button-primary">
		<span class="dashicons dashicons-database-view" style="vertical-align:text-bottom;"></span>
		<?php esc_html_e( 'Run CCT diagnostic', 'je-data-bridge-cc' ); ?>
	</button>
</form>

<?php if ( is_array( $cct_diagnostic ) && ! empty( $cct_diagnostic['ccts'] ) ) : ?>
	<?php foreach ( $cct_diagnostic['ccts'] as $cct_slug => $dump ) : ?>
		<div class="jedb-cct-diag">
			<h4 style="margin:0 0 8px 0;">
				<code><?php echo esc_html( $cct_slug ); ?></code>
				<span class="jedb-pill <?php echo $dump['table_exists'] ? 'jedb-pill-ok' : 'jedb-pill-bad'; ?>">
					<?php echo $dump['table_exists'] ? esc_html__( 'TABLE OK', 'je-data-bridge-cc' ) : esc_html__( 'TABLE MISSING', 'je-data-bridge-cc' ); ?>
				</span>
				<span class="jedb-pill jedb-pill-ok"><?php printf( esc_html__( '%d items (SQL)', 'je-data-bridge-cc' ), (int) $dump['item_count_sql'] ); ?></span>
				<?php if ( null !== $dump['item_count_via_db'] ) : ?>
					<span class="jedb-pill jedb-pill-warn"><?php printf( esc_html__( 'JE db: %s', 'je-data-bridge-cc' ), esc_html( (string) $dump['item_count_via_db'] ) ); ?></span>
				<?php endif; ?>
			</h4>
			<?php
			$render_field_array = static function ( $arr ) {
				if ( empty( $arr ) || ! is_array( $arr ) ) {
					return '<em>—</em>';
				}
				$bits = array();
				foreach ( $arr as $f ) {
					if ( ! is_array( $f ) ) { continue; }
					$bits[] = ( isset( $f['name'] ) ? $f['name'] : '?' ) . ' [' . ( isset( $f['type'] ) ? $f['type'] : '?' ) . ']';
				}
				return '<code style="font-size:11px;">' . esc_html( implode( ', ', $bits ) ) . '</code>';
			};
			?>
			<table class="widefat striped jedb-status-table" style="margin-bottom:14px;">
				<tbody>
					<tr>
						<th style="width:280px;"><?php esc_html_e( 'Table name', 'je-data-bridge-cc' ); ?></th>
						<td><code><?php echo esc_html( $dump['table_name'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Field source actually used', 'je-data-bridge-cc' ); ?></th>
						<td><code style="font-weight:bold; color:<?php echo 'none' === $dump['field_source_used'] ? '#b32d2e' : '#1e6b2c'; ?>;"><?php echo esc_html( $dump['field_source_used'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php printf( esc_html__( 'DB columns (%d)', 'je-data-bridge-cc' ), count( $dump['db_columns'] ) ); ?></th>
						<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', $dump['db_columns'] ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php printf( esc_html__( 'JE get_arg("fields") (%d)', 'je-data-bridge-cc' ), count( $dump['fields_via_get_arg'] ) ); ?></th>
						<td><?php echo wp_kses_post( $render_field_array( $dump['fields_via_get_arg'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php printf( esc_html__( 'JE get_arg("meta_fields") (%d)', 'je-data-bridge-cc' ), count( $dump['fields_via_get_arg_meta'] ) ); ?></th>
						<td><?php echo wp_kses_post( $render_field_array( $dump['fields_via_get_arg_meta'] ) ); ?></td>
					</tr>
					<?php if ( ! empty( $dump['fields_via_args_property'] ) ) : ?>
						<?php foreach ( $dump['fields_via_args_property'] as $key => $value ) : ?>
							<tr>
								<th><?php printf( esc_html__( '$instance->args["%1$s"] (%2$d)', 'je-data-bridge-cc' ), esc_html( $key ), count( $value ) ); ?></th>
								<td><?php echo wp_kses_post( $render_field_array( $value ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( ! empty( $dump['fields_via_option'] ) ) : ?>
						<?php foreach ( $dump['fields_via_option'] as $key => $value ) : ?>
							<tr>
								<th><?php printf( esc_html__( 'option(jet_engine_active_content_types).%1$s (%2$d)', 'je-data-bridge-cc' ), esc_html( $key ), count( $value ) ); ?></th>
								<td><?php echo wp_kses_post( $render_field_array( $value ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( ! empty( $dump['top_level_args_keys'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'All instance->args keys', 'je-data-bridge-cc' ); ?></th>
							<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', $dump['top_level_args_keys'] ) ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php printf( esc_html__( 'JE get_fields_list (%d)', 'je-data-bridge-cc' ), count( $dump['fields_via_get_list'] ) ); ?></th>
						<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', $dump['fields_via_get_list'] ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php printf( esc_html__( 'Plugin schema (%d)', 'je-data-bridge-cc' ), count( $dump['schema_after_filter'] ) ); ?></th>
						<td>
							<code style="font-size:11px;">
							<?php
							$bits = array();
							foreach ( $dump['schema_after_filter'] as $f ) {
								$bits[] = $f['name'] . ' [' . $f['type'] . ']';
							}
							echo esc_html( implode( ', ', $bits ) );
							?>
							</code>
						</td>
					</tr>
					<?php if ( ! empty( $dump['non_data_filtered_out'] ) ) : ?>
						<tr>
							<th><?php printf( esc_html__( 'Filtered out (%d non-data)', 'je-data-bridge-cc' ), count( $dump['non_data_filtered_out'] ) ); ?></th>
							<td>
								<code style="font-size:11px; color:#8a6d00;">
								<?php
								$bits = array();
								foreach ( $dump['non_data_filtered_out'] as $f ) {
									$bits[] = ( isset( $f['name'] ) ? $f['name'] : '?' ) . ' [' . ( isset( $f['type'] ) ? $f['type'] : '?' ) . ']';
								}
								echo esc_html( implode( ', ', $bits ) );
								?>
								</code>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $dump['deep_probe'] ) && is_array( $dump['deep_probe'] ) ) : ?>
				<?php $probe = $dump['deep_probe']; ?>
				<details class="jedb-deep-probe">
					<summary><strong><?php esc_html_e( 'Deep JE 3.8+ probe — where the field types might live', 'je-data-bridge-cc' ); ?></strong></summary>
					<table class="widefat striped jedb-status-table">
						<tbody>
							<tr>
								<th style="width:280px;"><?php esc_html_e( 'Instance class', 'je-data-bridge-cc' ); ?></th>
								<td><code><?php echo esc_html( isset( $probe['instance_class'] ) ? $probe['instance_class'] : '?' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Instance public properties', 'je-data-bridge-cc' ); ?></th>
								<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', (array) ( $probe['instance_public_props'] ?? array() ) ) ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Instance public methods', 'je-data-bridge-cc' ); ?></th>
								<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', (array) ( $probe['instance_public_methods'] ?? array() ) ) ); ?></code></td>
							</tr>
							<?php
							$probe_rows = array(
								'$instance->meta_fields'        => $probe['probe_meta_fields_property']    ?? null,
								'$instance->fields'             => $probe['probe_fields_property']         ?? null,
								'$instance->get_meta_fields()'  => $probe['probe_get_meta_fields_method']  ?? null,
								'$instance->get_fields()'       => $probe['probe_get_fields_method']       ?? null,
							);
							foreach ( $probe_rows as $label => $value ) :
								?>
								<tr>
									<th><code style="font-size:12px;"><?php echo esc_html( $label ); ?></code></th>
									<td>
										<?php if ( null === $value ) : ?>
											<em><?php esc_html_e( 'absent', 'je-data-bridge-cc' ); ?></em>
										<?php elseif ( isset( $value['ERROR'] ) ) : ?>
											<code style="color:#b32d2e;"><?php echo esc_html( $value['ERROR'] ); ?></code>
										<?php elseif ( isset( $value['count'] ) && $value['count'] ) : ?>
											<span class="jedb-pill jedb-pill-ok"><?php printf( esc_html__( '%d items', 'je-data-bridge-cc' ), (int) $value['count'] ); ?></span>
											<code style="font-size:11px;">first_keys: <?php echo esc_html( implode( ',', (array) ( $value['first_keys'] ?? array() ) ) ); ?></code>
											<?php if ( ! empty( $value['first_item'] ) && is_array( $value['first_item'] ) ) : ?>
												<br><code style="font-size:11px;">first_item: <?php echo esc_html( wp_json_encode( $value['first_item'] ) ); ?></code>
											<?php endif; ?>
										<?php else : ?>
											<em><?php echo esc_html( isset( $value['php_type'] ) ? $value['php_type'] : 'present but empty' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<th><?php esc_html_e( 'Manager class', 'je-data-bridge-cc' ); ?></th>
								<td><code><?php echo esc_html( isset( $probe['manager_class'] ) ? $probe['manager_class'] : '?' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Manager public properties', 'je-data-bridge-cc' ); ?></th>
								<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', (array) ( $probe['manager_public_props'] ?? array() ) ) ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'jet_engine() public properties', 'je-data-bridge-cc' ); ?></th>
								<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', (array) ( $probe['jet_engine_top_level_props'] ?? array() ) ) ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'jet_engine()->meta_boxes', 'je-data-bridge-cc' ); ?></th>
								<td>
									<?php if ( ! empty( $probe['jet_engine_meta_boxes_present'] ) ) : ?>
										<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'PRESENT', 'je-data-bridge-cc' ); ?></span>
										<code style="font-size:11px;"><?php echo esc_html( $probe['jet_engine_meta_boxes_class'] ); ?></code>
										<br><code style="font-size:11px;">methods: <?php echo esc_html( implode( ', ', (array) ( $probe['jet_engine_meta_boxes_methods'] ?? array() ) ) ); ?></code>
									<?php else : ?>
										<em><?php esc_html_e( 'absent', 'je-data-bridge-cc' ); ?></em>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( '`jet-engine` posts', 'je-data-bridge-cc' ); ?></th>
								<td>
									<?php printf( esc_html__( '%d posts', 'je-data-bridge-cc' ), (int) ( $probe['jet_engine_post_count'] ?? 0 ) ); ?>
									<?php if ( ! empty( $probe['jet_engine_post_meta_keys_sample'] ) ) : ?>
										<br><code style="font-size:11px;">meta keys: <?php echo esc_html( implode( ', ', (array) $probe['jet_engine_post_meta_keys_sample'] ) ); ?></code>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Matching wp_options', 'je-data-bridge-cc' ); ?></th>
								<td><code style="font-size:11px;"><?php echo esc_html( implode( ', ', (array) ( $probe['option_keys_matched'] ?? array() ) ) ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( '{prefix}jet_post_types table', 'je-data-bridge-cc' ); ?></th>
								<td>
									<?php if ( ! empty( $probe['jet_post_types_table_present'] ) ) : ?>
										<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'PRESENT', 'je-data-bridge-cc' ); ?></span>
									<?php else : ?>
										<span class="jedb-pill jedb-pill-bad"><?php esc_html_e( 'NOT FOUND', 'je-data-bridge-cc' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'jet_post_types row for this CCT', 'je-data-bridge-cc' ); ?></th>
								<td>
									<?php if ( ! empty( $probe['jet_post_types_row_found'] ) ) : ?>
										<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'FOUND', 'je-data-bridge-cc' ); ?></span>
										<code style="font-size:11px;">status=<?php echo esc_html( (string) ( $probe['jet_post_types_status'] ?? '' ) ); ?></code>
										<code style="font-size:11px;">meta_fields=<?php echo esc_html( (string) ( $probe['jet_post_types_meta_fields_count'] ?? 0 ) ); ?></code>
										<?php if ( ! empty( $probe['jet_post_types_meta_fields_preview'] ) ) : ?>
											<br><code style="font-size:11px;">preview: <?php
												$bits = array();
												foreach ( (array) $probe['jet_post_types_meta_fields_preview'] as $entry ) {
													if ( ! is_array( $entry ) ) { continue; }
													$bits[] = ( isset( $entry['name'] ) ? $entry['name'] : '?' ) . ' [' . ( isset( $entry['type'] ) ? $entry['type'] : '?' ) . ']';
												}
												echo esc_html( implode( ', ', $bits ) );
											?></code>
										<?php endif; ?>
									<?php else : ?>
										<em><?php esc_html_e( 'no row', 'je-data-bridge-cc' ); ?></em>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<?php if ( ! empty( $cct_diagnostic['errors'] ) ) : ?>
		<div class="notice notice-error inline">
			<p><strong><?php esc_html_e( 'Errors during diagnostic:', 'je-data-bridge-cc' ); ?></strong></p>
			<ul style="margin:8px 0 8px 18px;list-style:disc;">
				<?php foreach ( $cct_diagnostic['errors'] as $err ) : ?>
					<li><code style="color:#b32d2e;"><?php echo esc_html( $err ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
<?php endif; ?>

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
