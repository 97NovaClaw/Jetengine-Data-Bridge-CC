<?php
/**
 * Custom-table installer.
 *
 * Manages the four plugin tables:
 *   - {prefix}jedb_relation_configs   relation pre-attachment configs (RI port)
 *   - {prefix}jedb_flatten_configs    PULL/PUSH flatten configs (PAC VDM port)
 *   - {prefix}jedb_sync_log           append-only audit trail of every sync op
 *   - {prefix}jedb_snippets           registry for Custom Code Snippets
 *
 * dbDelta() is used for safe upgrade-in-place across plugin versions; bump
 * JEDB_DB_VERSION in the bootstrap when any schema below changes.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Config_DB {

	/**
	 * Create or upgrade every plugin table.
	 *
	 * Safe to call repeatedly. Called on activation and again whenever the stored
	 * JEDB_OPTION_DB_VERSION drifts from JEDB_DB_VERSION (handled in JEDB_Plugin).
	 */
	public static function install() {

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$relation_configs = $wpdb->prefix . 'jedb_relation_configs';
		$flatten_configs  = $wpdb->prefix . 'jedb_flatten_configs';
		$sync_log         = $wpdb->prefix . 'jedb_sync_log';
		$snippets         = $wpdb->prefix . 'jedb_snippets';

		$schemas = array();

		$schemas[] = "CREATE TABLE {$relation_configs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			config_slug VARCHAR(100) NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			source_target VARCHAR(150) NOT NULL DEFAULT '',
			relation_id VARCHAR(100) NOT NULL DEFAULT '',
			direction VARCHAR(20) NOT NULL DEFAULT 'parent_to_child',
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			config_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY config_slug (config_slug),
			KEY enabled (enabled),
			KEY source_target (source_target)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$flatten_configs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			config_slug VARCHAR(100) NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			source_target VARCHAR(150) NOT NULL DEFAULT '',
			target_target VARCHAR(150) NOT NULL DEFAULT '',
			relation_id VARCHAR(100) NOT NULL DEFAULT '',
			direction VARCHAR(20) NOT NULL DEFAULT 'pull',
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			config_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY config_slug (config_slug),
			KEY enabled (enabled),
			KEY source_target (source_target),
			KEY target_target (target_target)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$sync_log} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			direction VARCHAR(20) NOT NULL DEFAULT '',
			source_target VARCHAR(150) NOT NULL DEFAULT '',
			source_id VARCHAR(64) NOT NULL DEFAULT '',
			target_target VARCHAR(150) NOT NULL DEFAULT '',
			target_id VARCHAR(64) NOT NULL DEFAULT '',
			origin VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			message TEXT NULL,
			context_json LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY direction (direction),
			KEY source_lookup (source_target, source_id),
			KEY target_lookup (target_target, target_id)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$snippets} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			snippet_slug VARCHAR(100) NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NULL,
			file_hash VARCHAR(64) NOT NULL DEFAULT '',
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			last_edited_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY snippet_slug (snippet_slug),
			KEY status (status),
			KEY enabled (enabled)
		) {$charset_collate};";

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * @return array<string,string>  Map of internal table key → fully-prefixed table name.
	 */
	public static function table_names() {
		global $wpdb;

		return array(
			'relation_configs' => $wpdb->prefix . 'jedb_relation_configs',
			'flatten_configs'  => $wpdb->prefix . 'jedb_flatten_configs',
			'sync_log'         => $wpdb->prefix . 'jedb_sync_log',
			'snippets'         => $wpdb->prefix . 'jedb_snippets',
		);
	}

	/**
	 * Verify all four tables physically exist. Useful for the Debug tab and tests.
	 *
	 * @return array<string,bool>  table key => exists
	 */
	public static function tables_exist() {
		global $wpdb;

		$result = array();
		foreach ( self::table_names() as $key => $name ) {
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result[ $key ] = $exists;
		}
		return $result;
	}
}
