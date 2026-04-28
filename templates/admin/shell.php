<?php
/**
 * Outer shell template for the JE Data Bridge admin page.
 *
 * Provided by the caller (JEDB_Admin_Shell::render_page):
 *   @var array  $tabs          ['slug' => ['label' => ..., 'priority' => ...]]
 *   @var string $current_tab   Active tab slug.
 *   @var string $tab_template  Absolute path to the tab body template (may be missing).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap jedb-wrap">

	<h1 class="jedb-page-title">
		<span class="dashicons dashicons-randomize"></span>
		<?php esc_html_e( 'JetEngine Data Bridge CC', 'je-data-bridge-cc' ); ?>
		<span class="jedb-version">v<?php echo esc_html( JEDB_VERSION ); ?></span>
	</h1>

	<nav class="nav-tab-wrapper jedb-tabs">
		<?php foreach ( $tabs as $slug => $tab ) : ?>
			<a
				href="<?php echo esc_url( JEDB_Admin_Shell::tab_url( $slug ) ); ?>"
				class="nav-tab <?php echo $slug === $current_tab ? 'nav-tab-active' : ''; ?>"
			>
				<?php echo esc_html( $tab['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="jedb-tab-body">
		<?php
		if ( file_exists( $tab_template ) ) {
			include $tab_template;
		} else {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: tab template path */
				esc_html__( 'Missing tab template: %s', 'je-data-bridge-cc' ),
				'<code>' . esc_html( $tab_template ) . '</code>'
			);
			echo '</p></div>';
		}
		?>
	</div>

</div>
