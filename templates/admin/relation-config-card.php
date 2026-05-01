<?php
/**
 * Single relation config card.
 *
 * Provided by the caller:
 *   @var array  $config         Decoded row from JEDB_Relation_Config_Manager.
 *   @var string $cct_slug       The bare CCT slug.
 *   @var array  $tab_relations  Per-CCT relation list from the tab class.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$enabled            = ! empty( $config['enabled'] );
$enabled_relations  = isset( $config['config']['enabled_relations'] ) ? array_map( 'strval', (array) $config['config']['enabled_relations'] ) : array();
$label              = isset( $config['label'] ) ? (string) $config['label'] : $cct_slug;
$updated_at         = isset( $config['updated_at'] ) ? (string) $config['updated_at'] : '';
?>

<div class="jedb-relation-card<?php echo $enabled ? '' : ' is-disabled'; ?>" data-cct-slug="<?php echo esc_attr( $cct_slug ); ?>">

	<div class="jedb-relation-card-header">
		<div>
			<h4>
				<?php echo esc_html( $label ); ?>
				<code><?php echo esc_html( $cct_slug ); ?></code>
			</h4>
			<p class="description">
				<?php
				printf(
					/* translators: %d: count of enabled relations */
					esc_html( _n( '%d relation enabled', '%d relations enabled', count( $enabled_relations ), 'je-data-bridge-cc' ) ),
					(int) count( $enabled_relations )
				);
				?>
				<?php if ( $updated_at ) : ?>
					&middot; <?php printf( esc_html__( 'updated %s UTC', 'je-data-bridge-cc' ), esc_html( $updated_at ) ); ?>
				<?php endif; ?>
			</p>
		</div>

		<div class="jedb-relation-card-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0;">
				<?php wp_nonce_field( 'jedb_relation_config_toggle' ); ?>
				<input type="hidden" name="action" value="jedb_relation_config_toggle" />
				<input type="hidden" name="cct_slug" value="<?php echo esc_attr( $cct_slug ); ?>" />
				<input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>" />
				<button type="submit" class="button button-secondary">
					<?php echo $enabled ? esc_html__( 'Disable', 'je-data-bridge-cc' ) : esc_html__( 'Enable', 'je-data-bridge-cc' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0;">
				<?php wp_nonce_field( 'jedb_relation_config_delete' ); ?>
				<input type="hidden" name="action" value="jedb_relation_config_delete" />
				<input type="hidden" name="cct_slug" value="<?php echo esc_attr( $cct_slug ); ?>" />
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this relation config? The picker will stop appearing on this CCT until you re-add it.', 'je-data-bridge-cc' ) ); ?>');">
					<?php esc_html_e( 'Delete', 'je-data-bridge-cc' ); ?>
				</button>
			</form>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jedb-relation-card-body">
		<?php wp_nonce_field( 'jedb_relation_config_save' ); ?>
		<input type="hidden" name="action" value="jedb_relation_config_save" />
		<input type="hidden" name="cct_slug" value="<?php echo esc_attr( $cct_slug ); ?>" />

		<?php if ( empty( $tab_relations ) ) : ?>
			<p class="description">
				<em><?php esc_html_e( 'No JetEngine Relations involve this CCT. Create one in JetEngine → Relations to enable picker functionality.', 'je-data-bridge-cc' ); ?></em>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="margin-top:8px;">
				<thead>
					<tr>
						<th style="width:40px;"><?php esc_html_e( 'On', 'je-data-bridge-cc' ); ?></th>
						<th><?php esc_html_e( 'Relation', 'je-data-bridge-cc' ); ?></th>
						<th><?php esc_html_e( 'Type', 'je-data-bridge-cc' ); ?></th>
						<th><?php esc_html_e( 'This CCT is', 'je-data-bridge-cc' ); ?></th>
						<th><?php esc_html_e( 'Other side', 'je-data-bridge-cc' ); ?></th>
						<th><?php esc_html_e( 'Storage table', 'je-data-bridge-cc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tab_relations as $rel ) : ?>
						<?php $is_enabled = in_array( (string) $rel['id'], $enabled_relations, true ); ?>
						<tr>
							<td>
								<input
									type="checkbox"
									name="enabled_relations[]"
									value="<?php echo esc_attr( $rel['id'] ); ?>"
									<?php checked( $is_enabled ); ?>
								/>
							</td>
							<td><strong><?php echo esc_html( $rel['name'] ); ?></strong> <code style="font-size:11px;color:#646970;">id=<?php echo esc_html( $rel['id'] ); ?></code></td>
							<td><code><?php echo esc_html( $rel['type'] ); ?></code></td>
							<td><code><?php echo esc_html( $rel['cct_side'] ); ?></code></td>
							<td><?php echo esc_html( $rel['other_label'] ); ?></td>
							<td>
								<?php if ( $rel['table_exists'] ) : ?>
									<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'OK', 'je-data-bridge-cc' ); ?></span>
								<?php else : ?>
									<span class="jedb-pill jedb-pill-bad"><?php esc_html_e( 'MISSING', 'je-data-bridge-cc' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit" style="margin-top:10px;">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save changes', 'je-data-bridge-cc' ); ?>
				</button>
			</p>
		<?php endif; ?>
	</form>
</div>
