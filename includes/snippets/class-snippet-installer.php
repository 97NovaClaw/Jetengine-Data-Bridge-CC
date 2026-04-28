<?php
/**
 * Creates and verifies the snippet uploads directory.
 *
 * Snippets live OUTSIDE the plugin directory so they survive plugin updates and
 * back-up alongside the rest of /uploads. Two guard files protect the folder
 * from being executed or listed via HTTP:
 *
 *   uploads/jedb-snippets/.htaccess  → "deny from all"  (Apache)
 *   uploads/jedb-snippets/index.php  → silent exit       (any server)
 *
 * Nginx hosts get the same protection because PHP only loads snippets when
 * explicitly requested by JEDB_Snippet_Loader; direct HTTP access is irrelevant
 * unless a misconfigured server exposes /uploads/.../*.php (which is itself a
 * configuration bug, not something this plugin can fully fix from PHP).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Snippet_Installer {

	const HTACCESS_CONTENTS = "# JetEngine Data Bridge CC — block direct execution of snippet files\nOrder Deny,Allow\nDeny from all\n";
	const INDEX_CONTENTS    = "<?php\n// Silence is golden.\n";

	public static function install() {
		$dir = self::get_dir();

		if ( ! $dir ) {
			return false;
		}

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::write_guard_files( $dir );

		return self::verify( $dir );
	}

	/**
	 * Cheap, idempotent check called on every plugin boot. Re-creates missing
	 * guard files (e.g., if an admin manually deleted them).
	 */
	public static function verify( $dir = null ) {
		$dir = $dir ?: self::get_dir();

		if ( ! $dir || ! is_dir( $dir ) ) {
			return false;
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		$index    = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $htaccess ) || ! file_exists( $index ) ) {
			self::write_guard_files( $dir );
		}

		return file_exists( $htaccess ) && file_exists( $index );
	}

	/**
	 * Resolve the absolute filesystem path to uploads/jedb-snippets/.
	 *
	 * Returns false on hosts where uploads is unwritable.
	 */
	public static function get_dir() {
		$uploads = wp_upload_dir( null, false );

		if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
			return false;
		}

		return trailingslashit( $uploads['basedir'] ) . JEDB_SNIPPETS_DIR_NAME;
	}

	private static function write_guard_files( $dir ) {

		$dir = trailingslashit( $dir );

		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', self::HTACCESS_CONTENTS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		if ( ! file_exists( $dir . 'index.php' ) ) {
			@file_put_contents( $dir . 'index.php', self::INDEX_CONTENTS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}
}
