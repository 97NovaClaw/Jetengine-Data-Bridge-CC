<?php
/**
 * Debug-log helper.
 *
 * Single function `jedb_log()` writes to `wp-content/uploads/jedb-debug.log` when
 * the global Settings toggle `enable_debug_log` is ON. The file lives outside
 * the plugin dir so it survives updates and rotates at 5 MB to avoid runaway
 * disk usage.
 *
 * Phase 0 ships only the writer. The admin viewer (tail + JS auto-refresh) lands
 * in the Debug tab during Phase 5.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'jedb_log' ) ) {

	/**
	 * Write a debug message if logging is enabled in Settings.
	 *
	 * @param string|array|object $message Message or structured payload (will be json-encoded).
	 * @param string              $level   info|warning|error|debug
	 * @param array               $context Optional structured context (json-encoded into a trailing field).
	 */
	function jedb_log( $message, $level = 'info', array $context = array() ) {

		$settings = get_option( JEDB_OPTION_SETTINGS, array() );
		if ( empty( $settings['enable_debug_log'] ) ) {
			return;
		}

		$path = jedb_log_path();
		if ( ! $path ) {
			return;
		}

		jedb_maybe_rotate_log( $path );

		if ( ! is_string( $message ) ) {
			$message = wp_json_encode( $message );
		}

		$line = sprintf(
			"[%s] [%s] %s%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? ' ' . wp_json_encode( $context ) : ''
		);

		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'jedb_log_path' ) ) {

	function jedb_log_path() {
		$uploads = wp_upload_dir( null, false );

		if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
			return false;
		}

		return trailingslashit( $uploads['basedir'] ) . JEDB_DEBUG_LOG_NAME;
	}
}

if ( ! function_exists( 'jedb_maybe_rotate_log' ) ) {

	/**
	 * Rotate the log when it grows past 5 MB. Keeps one previous file
	 * (`*.1.log`); older rotations are discarded.
	 */
	function jedb_maybe_rotate_log( $path ) {

		if ( ! file_exists( $path ) ) {
			return;
		}

		$max_bytes = 5 * 1024 * 1024;
		if ( filesize( $path ) < $max_bytes ) {
			return;
		}

		$rotated = preg_replace( '/\.log$/', '.1.log', $path );

		if ( $rotated && file_exists( $rotated ) ) {
			@unlink( $rotated ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( $rotated ) {
			@rename( $path, $rotated ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
