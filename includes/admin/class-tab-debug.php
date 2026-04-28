<?php
/**
 * Debug tab — log viewer, log control, and diagnostic runner.
 *
 * Phase 1 hotfix: gives the operator a one-click way to enable file logging,
 * see the last N lines of the log, download the full log to share, and run a
 * one-shot Discovery diagnostic that dumps the entire discovery state to the
 * log (CCT module presence, raw arrays, every catch path).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Tab_Debug {

	const TAB_SLUG = 'debug';

	/** @var JEDB_Tab_Debug|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks() {
		add_filter( 'jedb/admin/tabs',                            array( $this, 'register_tab' ) );
		add_action( 'admin_post_jedb_toggle_debug_log',           array( $this, 'handle_toggle_debug_log' ) );
		add_action( 'admin_post_jedb_clear_debug_log',            array( $this, 'handle_clear_debug_log' ) );
		add_action( 'admin_post_jedb_download_debug_log',         array( $this, 'handle_download_debug_log' ) );
		add_action( 'admin_post_jedb_run_discovery_diagnostic',   array( $this, 'handle_run_diagnostic' ) );
	}

	public function register_tab( $tabs ) {
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Debug', 'je-data-bridge-cc' ),
			'priority' => 90,
		);
		return $tabs;
	}

	/* -----------------------------------------------------------------------
	 * Action handlers
	 * -------------------------------------------------------------------- */

	public function handle_toggle_debug_log() {
		$this->guard( 'jedb_toggle_debug_log' );

		$settings = get_option( JEDB_OPTION_SETTINGS, array() );
		$current  = ! empty( $settings['enable_debug_log'] );
		$settings['enable_debug_log'] = ! $current;
		update_option( JEDB_OPTION_SETTINGS, $settings, 'no' );

		$message = $current ? 'log_disabled' : 'log_enabled';
		jedb_log( 'Debug log ' . ( $current ? 'DISABLED' : 'ENABLED' ) . ' via Debug tab', 'info', array( 'user_id' => get_current_user_id() ) );

		$this->redirect_back( $message );
	}

	public function handle_clear_debug_log() {
		$this->guard( 'jedb_clear_debug_log' );

		$path = jedb_log_path();
		if ( $path && file_exists( $path ) ) {
			@file_put_contents( $path, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		$rotated = $path ? preg_replace( '/\.log$/', '.1.log', $path ) : '';
		if ( $rotated && file_exists( $rotated ) ) {
			@unlink( $rotated ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$this->redirect_back( 'log_cleared' );
	}

	public function handle_download_debug_log() {
		$this->guard( 'jedb_download_debug_log' );

		$path = jedb_log_path();
		if ( ! $path || ! file_exists( $path ) ) {
			$this->redirect_back( 'log_missing' );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="jedb-debug-' . gmdate( 'Y-m-d-H-i-s' ) . '.log"' );
		header( 'Content-Length: ' . filesize( $path ) );

		readfile( $path );
		exit;
	}

	public function handle_run_diagnostic() {
		$this->guard( 'jedb_run_discovery_diagnostic' );

		$settings = get_option( JEDB_OPTION_SETTINGS, array() );
		if ( empty( $settings['enable_debug_log'] ) ) {
			$settings['enable_debug_log'] = true;
			update_option( JEDB_OPTION_SETTINGS, $settings, 'no' );
		}

		$result = $this->run_discovery_diagnostic();

		set_transient( 'jedb_diagnostic_result', $result, 5 * MINUTE_IN_SECONDS );

		$this->redirect_back( 'diagnostic_done' );
	}

	/**
	 * Run a deep dump of every discovery surface and write to the log.
	 * Returns a small summary array used to render an at-a-glance result on
	 * the next page render.
	 *
	 * @return array
	 */
	public function run_discovery_diagnostic() {

		$summary = array(
			'started_at'           => gmdate( 'Y-m-d H:i:s' ),
			'jet_engine_loaded'    => function_exists( 'jet_engine' ),
			'jet_engine_version'   => function_exists( 'jedb_get_jet_engine_version' ) ? jedb_get_jet_engine_version() : null,
			'cct_module_class'     => class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ),
			'cct_module_instance'  => false,
			'cct_manager_present'  => false,
			'raw_cct_count'        => 0,
			'cct_slugs'            => array(),
			'public_post_types_total'    => 0,
			'public_post_types_builtin'  => 0,
			'public_post_types_custom'   => 0,
			'public_post_types_returned' => 0,
			'all_post_type_slugs'        => array(),
			'wc_active'            => class_exists( 'WooCommerce' ),
			'errors'               => array(),
		);

		jedb_log( '=== JEDB Discovery Diagnostic START ===', 'info' );

		try {
			if ( $summary['cct_module_class'] ) {
				$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();
				$summary['cct_module_instance'] = is_object( $module );
				$summary['cct_manager_present'] = $summary['cct_module_instance'] && isset( $module->manager );

				if ( $summary['cct_manager_present'] ) {
					$raw = $module->manager->get_content_types();
					if ( is_array( $raw ) ) {
						$summary['raw_cct_count'] = count( $raw );
						$summary['cct_slugs']     = array_keys( $raw );
					} else {
						$summary['errors'][] = 'CCT manager->get_content_types() did NOT return array (got ' . gettype( $raw ) . ')';
					}
				}
			}
		} catch ( \Throwable $t ) {
			$summary['errors'][] = 'CCT discovery threw: ' . $t->getMessage() . ' at ' . $t->getFile() . ':' . $t->getLine();
		}

		try {
			$builtin = get_post_types( array( '_builtin' => true  ), 'objects' );
			$custom  = get_post_types( array( '_builtin' => false, 'public' => true ), 'objects' );

			$summary['public_post_types_builtin'] = is_array( $builtin ) ? count( $builtin ) : 0;
			$summary['public_post_types_custom']  = is_array( $custom )  ? count( $custom )  : 0;
			$summary['public_post_types_total']   = $summary['public_post_types_builtin'] + $summary['public_post_types_custom'];

			$summary['all_post_type_slugs'] = array_merge(
				is_array( $builtin ) ? array_keys( $builtin ) : array(),
				is_array( $custom )  ? array_keys( $custom )  : array()
			);
		} catch ( \Throwable $t ) {
			$summary['errors'][] = 'get_post_types threw: ' . $t->getMessage();
		}

		try {
			$discovery = JEDB_Discovery::instance();
			$discovery->flush_cache();

			$ccts_via_discovery       = $discovery->get_all_ccts();
			$cpts_via_discovery       = $discovery->get_all_public_post_types();
			$relations_via_discovery  = $discovery->get_all_relations();

			$summary['discovery_ccts']      = is_array( $ccts_via_discovery )      ? count( $ccts_via_discovery )      : 'NOT-ARRAY (' . gettype( $ccts_via_discovery )      . ')';
			$summary['discovery_cpts']      = is_array( $cpts_via_discovery )      ? count( $cpts_via_discovery )      : 'NOT-ARRAY (' . gettype( $cpts_via_discovery )      . ')';
			$summary['discovery_relations'] = is_array( $relations_via_discovery ) ? count( $relations_via_discovery ) : 'NOT-ARRAY (' . gettype( $relations_via_discovery ) . ')';
			$summary['public_post_types_returned'] = $summary['discovery_cpts'];
		} catch ( \Throwable $t ) {
			$summary['errors'][] = 'JEDB_Discovery threw: ' . $t->getMessage() . ' at ' . $t->getFile() . ':' . $t->getLine();
		}

		jedb_log( 'Diagnostic summary', 'info', $summary );
		jedb_log( '=== JEDB Discovery Diagnostic END ===',   'info' );

		return $summary;
	}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	private function guard( $nonce_action ) {
		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'je-data-bridge-cc' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect_back( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array( 'jedb_notice' => $notice ),
				JEDB_Admin_Shell::tab_url( self::TAB_SLUG )
			)
		);
		exit;
	}

	/**
	 * Read the last $lines of the debug log. Cheap on small files; uses a
	 * tail-style read for large ones.
	 *
	 * @return string
	 */
	public static function tail_log( $lines = 500 ) {

		$path = jedb_log_path();
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}

		$max_bytes = 256 * 1024;
		$size      = filesize( $path );
		$offset    = $size > $max_bytes ? $size - $max_bytes : 0;

		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return '';
		}

		fseek( $fh, $offset );
		$contents = stream_get_contents( $fh );
		fclose( $fh );

		if ( false === $contents ) {
			return '';
		}

		$arr = explode( "\n", trim( $contents ) );
		if ( count( $arr ) > $lines ) {
			$arr = array_slice( $arr, -$lines );
		}

		return implode( "\n", $arr );
	}

	public static function log_size_human() {
		$path = jedb_log_path();
		if ( ! $path || ! file_exists( $path ) ) {
			return __( 'No log file', 'je-data-bridge-cc' );
		}
		return size_format( filesize( $path ), 1 );
	}

	public static function log_last_modified() {
		$path = jedb_log_path();
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', filemtime( $path ) ) . ' UTC';
	}
}
