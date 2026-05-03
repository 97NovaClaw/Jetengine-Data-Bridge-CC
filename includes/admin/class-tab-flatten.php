<?php
/**
 * Flatten admin tab — Phase 3 forward-direction bridge configurator.
 *
 * Lists every flatten config as a card; provides an add/edit form with:
 *   - Source target picker (CCT only in Phase 3)
 *   - Target target picker (CCT / CPT / Woo Product / Woo Variation)
 *   - Link-via section (JE Relation OR cct_single_post_id)
 *   - Trigger picker (Phase 3 supports cct_save / manual; rest stubbed)
 *   - Mandatory coverage panel (D-15) — adapter-declared + bridge overrides
 *   - Field-mapping table — explicit two-column picker (D-12) with
 *     per-direction transformer chains (D-11) and a "natively rendered"
 *     hint (D-16)
 *   - Condition (DSL string + validation)
 *   - Save / Sync now / Delete actions
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Tab_Flatten {

	const TAB_SLUG = 'flatten';

	/** @var JEDB_Tab_Flatten|null */
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
		add_filter( 'jedb/admin/tabs',                       array( $this, 'register_tab' ) );
		add_action( 'admin_post_jedb_flatten_save',          array( $this, 'handle_save' ) );
		add_action( 'admin_post_jedb_flatten_toggle',        array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_jedb_flatten_delete',        array( $this, 'handle_delete' ) );
		add_action( 'admin_post_jedb_flatten_sync_now',      array( $this, 'handle_sync_now' ) );

		add_action( 'wp_ajax_jedb_flatten_get_target_schema', array( $this, 'ajax_get_target_schema' ) );
		add_action( 'wp_ajax_jedb_flatten_validate_condition', array( $this, 'ajax_validate_condition' ) );
	}

	public function register_tab( $tabs ) {
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Flatten', 'je-data-bridge-cc' ),
			'priority' => 30,
		);
		return $tabs;
	}

	/* -----------------------------------------------------------------------
	 * Form handlers
	 * -------------------------------------------------------------------- */

	public function handle_save() {

		$this->guard( 'jedb_flatten_save' );

		$id            = isset( $_POST['id'] )            ? absint( $_POST['id'] ) : 0;
		$label         = isset( $_POST['label'] )         ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$source_target = isset( $_POST['source_target'] ) ? sanitize_text_field( wp_unslash( $_POST['source_target'] ) ) : '';
		$target_target = isset( $_POST['target_target'] ) ? sanitize_text_field( wp_unslash( $_POST['target_target'] ) ) : '';
		$direction     = isset( $_POST['direction'] )     ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : 'push';
		$enabled       = isset( $_POST['enabled'] )       ? 1 : 0;

		$config_raw = isset( $_POST['config_json'] ) ? (string) wp_unslash( $_POST['config_json'] ) : '';
		$config     = json_decode( $config_raw, true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$saved_id = JEDB_Flatten_Config_Manager::instance()->upsert( array(
			'id'            => $id,
			'label'         => $label,
			'source_target' => $source_target,
			'target_target' => $target_target,
			'direction'     => $direction,
			'enabled'       => $enabled,
			'config'        => $config,
		) );

		if ( ! $saved_id ) {
			$this->redirect_back( 'save_failed' );
		}

		$this->redirect_back( 'config_saved', array( 'edit' => $saved_id ) );
	}

	public function handle_toggle() {

		$this->guard( 'jedb_flatten_toggle' );

		$id      = isset( $_POST['id'] )      ? absint( $_POST['id'] ) : 0;
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'] ? 1 : 0;

		if ( ! $id ) {
			$this->redirect_back( 'invalid_id' );
		}

		JEDB_Flatten_Config_Manager::instance()->set_enabled( $id, $enabled );

		$this->redirect_back( $enabled ? 'config_enabled' : 'config_disabled' );
	}

	public function handle_delete() {

		$this->guard( 'jedb_flatten_delete' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			$this->redirect_back( 'invalid_id' );
		}

		JEDB_Flatten_Config_Manager::instance()->delete( $id );

		$this->redirect_back( 'config_deleted' );
	}

	public function handle_sync_now() {

		$this->guard( 'jedb_flatten_sync_now' );

		$id        = isset( $_POST['id'] )        ? absint( $_POST['id'] ) : 0;
		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;

		if ( ! $id || ! $source_id ) {
			$this->redirect_back( 'invalid_sync_args' );
		}

		$bridge = JEDB_Flatten_Config_Manager::instance()->get_by_id( $id );
		if ( ! $bridge ) {
			$this->redirect_back( 'invalid_id' );
		}

		$status = JEDB_Flattener::instance()->apply_bridge( $bridge, $source_id, 'manual' );

		$this->redirect_back( 'sync_run', array( 'edit' => $id, 'sync_status' => $status, 'sync_source_id' => $source_id ) );
	}

	/* -----------------------------------------------------------------------
	 * AJAX endpoints
	 * -------------------------------------------------------------------- */

	public function ajax_get_target_schema() {

		check_ajax_referer( 'jedb_flatten_admin', 'nonce' );

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$slug   = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$target = JEDB_Target_Registry::instance()->get( $slug );

		if ( ! $target ) {
			wp_send_json_error( array( 'message' => 'unknown target' ), 404 );
		}

		$schema   = $target->get_field_schema();
		$required = $target->get_required_fields();

		$rows = array();
		foreach ( $schema as $f ) {
			$rows[] = array(
				'name'              => isset( $f['name'] )     ? $f['name']     : '',
				'label'             => isset( $f['label'] )    ? $f['label']    : '',
				'type'              => isset( $f['type'] )     ? $f['type']     : '',
				'group'             => isset( $f['group'] )    ? $f['group']    : '',
				'readonly'          => ! empty( $f['readonly'] ),
				'is_meta'           => ! empty( $f['is_meta'] ),
				'natively_rendered' => $target->is_natively_rendered( isset( $f['name'] ) ? $f['name'] : '' ),
				'required'          => in_array( isset( $f['name'] ) ? $f['name'] : '', $required, true ),
			);
		}

		wp_send_json_success( array(
			'slug'     => $slug,
			'fields'   => $rows,
			'required' => $required,
		) );
	}

	public function ajax_validate_condition() {

		check_ajax_referer( 'jedb_flatten_admin', 'nonce' );

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$dsl    = isset( $_POST['dsl'] ) ? (string) wp_unslash( $_POST['dsl'] ) : '';
		$result = JEDB_Condition_Evaluator::instance()->validate( $dsl );

		wp_send_json_success( $result );
	}

	/* -----------------------------------------------------------------------
	 * Helpers used by the template
	 * -------------------------------------------------------------------- */

	public function get_eligible_source_targets() {

		$registry = JEDB_Target_Registry::instance();
		$out      = array();

		foreach ( $registry->all_of_kind( 'cct' ) as $slug => $target ) {
			$out[] = array( 'slug' => $slug, 'label' => $target->get_label() );
		}

		return $out;
	}

	public function get_eligible_target_targets() {

		$registry = JEDB_Target_Registry::instance();
		$out      = array();

		foreach ( $registry->all() as $slug => $target ) {
			if ( in_array( $target->get_kind(), array( 'cpt', 'woo_product', 'woo_variation' ), true ) ) {
				$out[] = array( 'slug' => $slug, 'label' => $target->get_label(), 'kind' => $target->get_kind() );
			}
		}

		usort( $out, static function ( $a, $b ) {
			return strcmp( $a['label'], $b['label'] );
		} );

		return $out;
	}

	/**
	 * For the link-via picker. Returns relations whose endpoints involve
	 * the given source CCT, scoped by the candidate target if provided.
	 */
	public function get_relations_between( $source_target, $target_target = '' ) {

		$discovery = JEDB_Discovery::instance();
		$relations = $discovery->get_all_relations();
		$out       = array();

		foreach ( $relations as $rel ) {

			$pp = $discovery->parse_relation_object( $rel['parent_object'] );
			$cp = $discovery->parse_relation_object( $rel['child_object'] );

			$src_match = $this->endpoint_matches( $pp, $source_target ) || $this->endpoint_matches( $cp, $source_target );
			$tgt_match = '' === $target_target
				? true
				: ( $this->endpoint_matches( $pp, $target_target ) || $this->endpoint_matches( $cp, $target_target ) );

			if ( ! $src_match || ! $tgt_match ) {
				continue;
			}

			$out[] = array(
				'id'        => (string) $rel['id'],
				'name'      => $rel['name'],
				'type'      => $rel['type'],
				'parent'    => $rel['parent_object'],
				'child'     => $rel['child_object'],
				'parent_lb' => $discovery->get_relation_object_name( $rel['parent_object'] ),
				'child_lb'  => $discovery->get_relation_object_name( $rel['child_object'] ),
			);
		}

		return $out;
	}

	private function endpoint_matches( array $parsed, $target_slug ) {

		if ( '' === $target_slug ) {
			return false;
		}

		$target_parsed = JEDB_Target_Abstract::parse_slug( $target_slug );

		return $parsed['type'] === $target_parsed['kind']
			&& $parsed['slug'] === $target_parsed['object'];
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
