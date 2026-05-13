<?php
/**
 * Field Presets admin tab — Phase 4 Day 4 (BUILD-PLAN §4.12).
 *
 * Curates portable, target-scoped lists of fields ("for adapter X, what
 * does a complete bridge look like?"). Stored in the `jedb_field_presets`
 * site option. CRUD + JSON export/import. Drives the "Apply preset" and
 * "Scaffold missing mappings" actions on the Flatten admin tab's
 * Mandatory coverage panel.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Tab_Field_Presets {

	const TAB_SLUG = 'field-presets';

	/** @var JEDB_Tab_Field_Presets|null */
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
		add_filter( 'jedb/admin/tabs', array( $this, 'register_tab' ) );

		add_action( 'admin_post_jedb_field_presets_save',   array( $this, 'handle_save' ) );
		add_action( 'admin_post_jedb_field_presets_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_jedb_field_presets_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_jedb_field_presets_export', array( $this, 'handle_export' ) );
	}

	public function register_tab( $tabs ) {
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Field Presets', 'je-data-bridge-cc' ),
			'priority' => 35,
		);
		return $tabs;
	}

	/**
	 * Convenience for the template: returns [ {slug, label, kind} ] for
	 * every registered target adapter, sorted with CCT sources first.
	 * Used to populate the "Target adapter" dropdown when creating /
	 * editing a preset.
	 *
	 * @return array<int,array{slug:string,label:string,kind:string}>
	 */
	public function get_target_options_for_select() {

		if ( ! class_exists( 'JEDB_Target_Registry' ) ) {
			return array();
		}

		$out = array();
		foreach ( JEDB_Target_Registry::instance()->all() as $slug => $adapter ) {
			$kind = '';
			if ( 0 === strpos( (string) $slug, 'cct::' ) ) {
				$kind = 'cct';
			} elseif ( 0 === strpos( (string) $slug, 'posts::' ) ) {
				$kind = 'posts';
			}
			$out[] = array(
				'slug'  => (string) $slug,
				'label' => method_exists( $adapter, 'get_label' ) ? (string) $adapter->get_label() : (string) $slug,
				'kind'  => $kind,
			);
		}

		usort( $out, static function ( $a, $b ) {
			// CCT options first, then by label.
			if ( $a['kind'] !== $b['kind'] ) {
				$rank = array( 'cct' => 0, 'posts' => 1, '' => 2 );
				return ( $rank[ $a['kind'] ] ?? 9 ) <=> ( $rank[ $b['kind'] ] ?? 9 );
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return $out;
	}

	/* -----------------------------------------------------------------------
	 * Admin-post handlers
	 * -------------------------------------------------------------------- */

	public function handle_save() {

		$this->guard( 'jedb_field_presets_save' );

		$manager = JEDB_Field_Presets_Manager::instance();

		$slug        = isset( $_POST['slug'] )        ? sanitize_key( wp_unslash( $_POST['slug'] ) )            : '';
		$label       = isset( $_POST['label'] )       ? sanitize_text_field( wp_unslash( $_POST['label'] ) )    : '';
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) )     : '';
		$target      = isset( $_POST['target'] )      ? sanitize_text_field( wp_unslash( $_POST['target'] ) )   : '';
		$notes       = isset( $_POST['notes'] )       ? wp_kses_post( wp_unslash( $_POST['notes'] ) )           : '';

		// Per-field rows arrive as parallel arrays from the table editor.
		$names      = isset( $_POST['field_name'] )      ? (array) wp_unslash( $_POST['field_name'] )      : array();
		$labels     = isset( $_POST['field_label'] )     ? (array) wp_unslash( $_POST['field_label'] )     : array();
		$mandatory  = isset( $_POST['field_mandatory'] ) ? (array) wp_unslash( $_POST['field_mandatory'] ) : array();
		$groups     = isset( $_POST['field_group'] )     ? (array) wp_unslash( $_POST['field_group'] )     : array();
		$hints      = isset( $_POST['field_hint'] )      ? (array) wp_unslash( $_POST['field_hint'] )      : array();

		$fields = array();
		foreach ( $names as $i => $raw_name ) {
			$name = sanitize_key( (string) $raw_name );
			if ( '' === $name ) {
				continue;
			}
			$fields[] = array(
				'name'      => $name,
				'label'     => isset( $labels[ $i ] )    ? sanitize_text_field( (string) $labels[ $i ] ) : '',
				'mandatory' => isset( $mandatory[ $i ] ) && '1' === (string) $mandatory[ $i ],
				'group'     => isset( $groups[ $i ] )    ? sanitize_text_field( (string) $groups[ $i ] ) : '',
				'hint'      => isset( $hints[ $i ] )     ? wp_kses_post( (string) $hints[ $i ] )         : '',
			);
		}

		$entry = array(
			'slug'        => $slug,
			'label'       => $label,
			'description' => $description,
			'target'      => $target,
			'fields'      => $fields,
			'notes'       => $notes,
		);

		$result = $manager->upsert( $entry );

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( 'preset_save_failed', array( 'jedb_error' => rawurlencode( $result->get_error_message() ) ) );
			return;
		}

		$this->redirect_back( 'preset_saved', array( 'edit' => $result ) );
	}

	public function handle_delete() {

		$this->guard( 'jedb_field_presets_delete' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			$this->redirect_back( 'invalid_id' );
			return;
		}

		JEDB_Field_Presets_Manager::instance()->delete( $slug );
		$this->redirect_back( 'preset_deleted' );
	}

	public function handle_import() {

		$this->guard( 'jedb_field_presets_import' );

		$raw_json    = isset( $_POST['payload'] )      ? (string) wp_unslash( $_POST['payload'] ) : '';
		$replace_all = isset( $_POST['replace_all'] ) && '1' === (string) wp_unslash( $_POST['replace_all'] );

		$decoded = json_decode( $raw_json, true );
		if ( ! is_array( $decoded ) ) {
			$this->redirect_back( 'preset_import_invalid_json' );
			return;
		}

		// Accept either { presets: [...] } or a bare top-level array.
		$entries = isset( $decoded['presets'] ) && is_array( $decoded['presets'] ) ? $decoded['presets'] : $decoded;
		if ( ! is_array( $entries ) ) {
			$this->redirect_back( 'preset_import_invalid_json' );
			return;
		}

		$manager = JEDB_Field_Presets_Manager::instance();
		$result  = $replace_all ? $manager->replace_all( $entries ) : $manager->merge_import( $entries );

		$accepted_count = count( $result['accepted'] );
		$dropped_count  = count( $result['dropped'] );

		$this->redirect_back(
			'preset_imported',
			array(
				'jedb_accepted' => $accepted_count,
				'jedb_dropped'  => $dropped_count,
			)
		);
	}

	/**
	 * Stream a JSON download of every saved preset. Triggered by the
	 * Export button (a form POST so the nonce travels with the request).
	 */
	public function handle_export() {

		$this->guard( 'jedb_field_presets_export' );

		$presets = JEDB_Field_Presets_Manager::instance()->get_all();
		$payload = array(
			'jedb_field_presets_version' => 1,
			'exported_at'                => current_time( 'mysql', true ),
			'site_url'                   => home_url( '/' ),
			'presets'                    => $presets,
		);

		$filename = sprintf( 'jedb-field-presets-%s.json', gmdate( 'Y-m-d-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	private function guard( $nonce_action ) {
		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'je-data-bridge-cc' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect_back( $notice, array $extra = array() ) {

		$url = JEDB_Admin_Shell::tab_url( self::TAB_SLUG );
		$url = add_query_arg( array_merge( array( 'jedb_notice' => $notice ), $extra ), $url );

		wp_safe_redirect( $url );
		exit;
	}
}
