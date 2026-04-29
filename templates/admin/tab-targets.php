<?php
/**
 * Phase 1 Targets tab — read-only inventory of every discovered data target.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'JEDB_Discovery' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/class-discovery.php';
}
if ( ! class_exists( 'JEDB_Target_Registry' ) ) {
	require_once JEDB_PLUGIN_DIR . 'includes/targets/class-target-registry.php';
}

$discovery = JEDB_Discovery::instance();
$registry  = JEDB_Target_Registry::instance();
$deps      = $discovery->verify_dependencies();
$targets   = $registry->all();

$ccts          = $registry->all_of_kind( 'cct' );
$cpts          = $registry->all_of_kind( 'cpt' );
$woo_products  = $registry->all_of_kind( 'woo_product' );
$woo_variants  = $registry->all_of_kind( 'woo_variation' );

$relations     = $discovery->get_all_relations();
$wc_active     = $discovery->is_wc_active();
$product_types = $wc_active ? $discovery->get_woo_product_type_counts() : array();
$variation_count = $wc_active ? $discovery->get_woo_variation_count() : 0;
$woo_taxes     = $wc_active ? $discovery->get_woo_taxonomies() : array();

$notice = isset( $_GET['jedb_notice'] ) ? sanitize_key( wp_unslash( $_GET['jedb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>

<?php if ( 'cache_flushed' === $notice ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e( 'Discovery cache flushed. The lists below are freshly rebuilt.', 'je-data-bridge-cc' ); ?></p>
	</div>
<?php endif; ?>

<div class="jedb-targets-header">
	<div>
		<h2><?php esc_html_e( 'Phase 1 — Discovered Data Targets', 'je-data-bridge-cc' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Read-only inventory of every CCT, public CPT, WooCommerce product, variation, and active relation visible to the bridge. Use the Refresh button after creating/deleting CCTs, post types, or relations.', 'je-data-bridge-cc' ); ?>
		</p>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jedb-targets-refresh">
		<?php wp_nonce_field( 'jedb_flush_discovery_cache' ); ?>
		<input type="hidden" name="action" value="jedb_flush_discovery_cache" />
		<button type="submit" class="button button-secondary">
			<span class="dashicons dashicons-update" style="vertical-align:text-bottom;"></span>
			<?php esc_html_e( 'Refresh discovery cache', 'je-data-bridge-cc' ); ?>
		</button>
	</form>
</div>

<div class="jedb-targets-summary">
	<div class="jedb-summary-card">
		<div class="jedb-summary-num"><?php echo esc_html( count( $ccts ) ); ?></div>
		<div class="jedb-summary-label"><?php esc_html_e( 'Custom Content Types', 'je-data-bridge-cc' ); ?></div>
	</div>
	<div class="jedb-summary-card">
		<div class="jedb-summary-num"><?php echo esc_html( count( $cpts ) ); ?></div>
		<div class="jedb-summary-label"><?php esc_html_e( 'Public Post Types', 'je-data-bridge-cc' ); ?></div>
	</div>
	<div class="jedb-summary-card">
		<div class="jedb-summary-num"><?php echo esc_html( count( $woo_products ) ); ?></div>
		<div class="jedb-summary-label"><?php esc_html_e( 'Woo Product Adapter', 'je-data-bridge-cc' ); ?></div>
	</div>
	<div class="jedb-summary-card">
		<div class="jedb-summary-num"><?php echo esc_html( count( $woo_variants ) ); ?></div>
		<div class="jedb-summary-label"><?php esc_html_e( 'Woo Variation Adapter', 'je-data-bridge-cc' ); ?></div>
	</div>
	<div class="jedb-summary-card">
		<div class="jedb-summary-num"><?php echo esc_html( count( $relations ) ); ?></div>
		<div class="jedb-summary-label"><?php esc_html_e( 'JetEngine Relations', 'je-data-bridge-cc' ); ?></div>
	</div>
</div>

<?php /* ----------------------------------------------------------------- */ ?>
<h3><?php esc_html_e( 'Custom Content Types', 'je-data-bridge-cc' ); ?></h3>

<?php if ( empty( $ccts ) ) : ?>
	<p class="description"><?php esc_html_e( 'No CCTs discovered. JetEngine module may not be loaded yet, or none have been defined on this site.', 'je-data-bridge-cc' ); ?></p>
<?php else : ?>
	<table class="widefat striped jedb-status-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Slug', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Label', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Items', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Fields (user / +system)', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Adapter slug', 'je-data-bridge-cc' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $ccts as $slug => $target ) : ?>
				<?php
				$schema      = $target->get_field_schema();
				$user_count  = 0;
				$system_count = 0;
				foreach ( $schema as $field ) {
					if ( isset( $field['group'] ) && 'system' === $field['group'] ) {
						$system_count++;
					} else {
						$user_count++;
					}
				}
				?>
				<tr>
					<td><code><?php echo esc_html( str_replace( 'cct::', '', $slug ) ); ?></code></td>
					<td><?php echo esc_html( $target->get_label() ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $target->count() ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $user_count ); ?></strong>
						<span style="color:#646970;"> / +<?php echo esc_html( $system_count ); ?> system</span>
					</td>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php /* ----------------------------------------------------------------- */ ?>
<h3><?php esc_html_e( 'Public Post Types', 'je-data-bridge-cc' ); ?></h3>

<?php if ( empty( $cpts ) && empty( $woo_products ) && empty( $woo_variants ) ) : ?>
	<p class="description"><?php esc_html_e( 'No public post types discovered.', 'je-data-bridge-cc' ); ?></p>
<?php else : ?>
	<table class="widefat striped jedb-status-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Slug', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Label', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Items (published)', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Adapter', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Adapter slug', 'je-data-bridge-cc' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$post_targets = array_merge( $cpts, $woo_products, $woo_variants );
			ksort( $post_targets );
			foreach ( $post_targets as $slug => $target ) :
				$kind   = $target->get_kind();
				$pillcls = 'jedb-pill-ok';
				$kindlabel = $kind;
				if ( 'woo_product' === $kind ) {
					$kindlabel = __( 'Woo Product (HPOS-safe)', 'je-data-bridge-cc' );
				} elseif ( 'woo_variation' === $kind ) {
					$kindlabel = __( 'Woo Variation (HPOS-safe)', 'je-data-bridge-cc' );
				} elseif ( 'cpt' === $kind ) {
					$kindlabel = __( 'Generic CPT', 'je-data-bridge-cc' );
				}
			?>
				<tr>
					<td><code><?php echo esc_html( str_replace( 'posts::', '', $slug ) ); ?></code></td>
					<td><?php echo esc_html( $target->get_label() ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $target->count() ) ); ?></td>
					<td><span class="jedb-pill <?php echo esc_attr( $pillcls ); ?>"><?php echo esc_html( $kindlabel ); ?></span></td>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php /* ----------------------------------------------------------------- */ ?>
<?php if ( $wc_active ) : ?>
	<h3><?php esc_html_e( 'WooCommerce Product Types', 'je-data-bridge-cc' ); ?></h3>
	<table class="widefat striped jedb-status-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Type', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Published count', 'je-data-bridge-cc' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $product_types as $pt ) : ?>
				<tr>
					<td><?php echo esc_html( $pt['label'] ); ?></td>
					<td><code><?php echo esc_html( $pt['slug'] ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( $pt['count'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<td><strong><?php esc_html_e( 'Variations (all parents)', 'je-data-bridge-cc' ); ?></strong></td>
				<td><code>product_variation</code></td>
				<td><strong><?php echo esc_html( number_format_i18n( $variation_count ) ); ?></strong></td>
			</tr>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'WooCommerce Taxonomies', 'je-data-bridge-cc' ); ?></h3>
	<table class="widefat striped jedb-status-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Taxonomy', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Terms', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Kind', 'je-data-bridge-cc' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $woo_taxes as $tax ) : ?>
				<tr>
					<td><?php echo esc_html( $tax['label'] ); ?></td>
					<td><code><?php echo esc_html( $tax['slug'] ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( $tax['count'] ) ); ?></td>
					<td>
						<?php if ( $tax['is_attribute'] ) : ?>
							<span class="jedb-pill jedb-pill-warn"><?php esc_html_e( 'Attribute', 'je-data-bridge-cc' ); ?></span>
						<?php else : ?>
							<span class="jedb-pill jedb-pill-ok"><?php esc_html_e( 'Standard', 'je-data-bridge-cc' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php /* ----------------------------------------------------------------- */ ?>
<h3><?php esc_html_e( 'JetEngine Relations', 'je-data-bridge-cc' ); ?></h3>

<?php if ( empty( $relations ) ) : ?>
	<p class="description"><?php esc_html_e( 'No active JetEngine relations found.', 'je-data-bridge-cc' ); ?></p>
<?php else : ?>
	<table class="widefat striped jedb-status-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Name', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Parent → Child', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Type', 'je-data-bridge-cc' ); ?></th>
				<th><?php esc_html_e( 'Storage table', 'je-data-bridge-cc' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $relations as $rel ) : ?>
				<tr>
					<td><code><?php echo esc_html( $rel['id'] ); ?></code></td>
					<td><?php echo esc_html( $rel['name'] ); ?></td>
					<td>
						<code><?php echo esc_html( $rel['parent_object'] ); ?></code>
						&rarr;
						<code><?php echo esc_html( $rel['child_object'] ); ?></code>
					</td>
					<td><code><?php echo esc_html( $rel['type'] ); ?></code></td>
					<td>
						<code><?php echo esc_html( $rel['table_name'] ); ?></code>
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
<?php endif; ?>

<?php /* ----------------------------------------------------------------- */ ?>
<h3><?php esc_html_e( 'Dependency snapshot', 'je-data-bridge-cc' ); ?></h3>
<table class="widefat striped jedb-status-table">
	<tbody>
		<tr><th><?php esc_html_e( 'JetEngine loaded',     'je-data-bridge-cc' ); ?></th><td><?php echo $deps['jetengine']        ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>'; ?></td></tr>
		<tr><th><?php esc_html_e( 'CCT module',           'je-data-bridge-cc' ); ?></th><td><?php echo $deps['cct_module']       ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>'; ?></td></tr>
		<tr><th><?php esc_html_e( 'Relations module',     'je-data-bridge-cc' ); ?></th><td><?php echo $deps['relations_module'] ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-bad">NO</span>'; ?></td></tr>
		<tr><th><?php esc_html_e( 'WooCommerce active',   'je-data-bridge-cc' ); ?></th><td><?php echo $deps['woocommerce']      ? '<span class="jedb-pill jedb-pill-ok">YES</span>' : '<span class="jedb-pill jedb-pill-warn">NO (CCT-only mode)</span>'; ?></td></tr>
	</tbody>
</table>
