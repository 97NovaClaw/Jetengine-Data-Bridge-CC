<?php
/**
 * Uninstall handler for JetEngine Data Bridge CC.
 *
 * Drops every custom table, removes every plugin option, and (if the user opted in
 * via the Settings tab) wipes the snippet uploads directory. By default snippets
 * are PRESERVED so accidental uninstalls don't destroy admin-authored code.
 *
 * @package JEDB
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'jedb_relation_configs',
	$wpdb->prefix . 'jedb_flatten_configs',
	$wpdb->prefix . 'jedb_sync_log',
	$wpdb->prefix . 'jedb_snippets',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
}

$options = array(
	'jedb_settings',
	'jedb_bridge_types',
	'jedb_bridge_types__previous',
	'jedb_meta_whitelist',
	'jedb_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

$settings_pre_delete = get_option( 'jedb_settings_uninstall_intent', array() );
$wipe_snippets       = ! empty( $settings_pre_delete['wipe_snippets_on_uninstall'] );

if ( $wipe_snippets ) {
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['basedir'] ) ) {
		$dir = trailingslashit( $uploads['basedir'] ) . 'jedb-snippets';
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}

delete_option( 'jedb_settings_uninstall_intent' );
