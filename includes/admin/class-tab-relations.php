<?php
/**
 * Relations admin tab — manages picker configs (which JE Relations to expose
 * on which CCT edit screens).
 *
 * MIRRORS RI's UX: one config card per CCT, with a checkbox-list of which
 * JE Relations the picker should show. We never create or edit JE Relations
 * themselves — those live entirely in JetEngine (JetEngine → Relations).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Tab_Relations {

	const TAB_SLUG = 'relations';

	/** @var JEDB_Tab_Relations|null */
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
		add_action( 'admin_post_jedb_relation_config_save',       array( $this, 'handle_save' ) );
		add_action( 'admin_post_jedb_relation_config_toggle',     array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_jedb_relation_config_delete',     array( $this, 'handle_delete' ) );
	}

	public function register_tab( $tabs ) {
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Relations', 'je-data-bridge-cc' ),
			'priority' => 25,
		);
		return $tabs;
	}

	/* -----------------------------------------------------------------------
	 * Action handlers (admin-post.php targets)
	 * -------------------------------------------------------------------- */

	public function handle_save() {
		$this->guard( 'jedb_relation_config_save' );

		$cct_slug = isset( $_POST['cct_slug'] ) ? sanitize_key( wp_unslash( $_POST['cct_slug'] ) ) : '';
		if ( '' === $cct_slug ) {
			$this->redirect_back( 'invalid_cct' );
		}

		$enabled_relations = array();
		if ( ! empty( $_POST['enabled_relations'] ) && is_array( $_POST['enabled_relations'] ) ) {
			foreach ( wp_unslash( $_POST['enabled_relations'] ) as $relation_id ) {
				$relation_id = sanitize_text_field( (string) $relation_id );
				if ( '' !== $relation_id ) {
					$enabled_relations[] = $relation_id;
				}
			}
		}
		$enabled_relations = array_values( array_unique( $enabled_relations ) );

		$config_data = array(
			'enabled_relations' => $enabled_relations,
			'display_fields'    => array(),
			'ui_settings'       => array(
				'show_create_button' => false,
			),
		);

		$cct_meta = JEDB_Discovery::instance()->get_cct( $cct_slug );
		$label    = $cct_meta && ! empty( $cct_meta['name'] ) ? $cct_meta['name'] : $cct_slug;

		$enabled_flag = isset( $_POST['enabled'] ) ? 1 : 1;

		$id = JEDB_Relation_Config_Manager::instance()->upsert_for_cct(
			$cct_slug,
			$config_data,
			array(
				'label'   => $label,
				'enabled' => $enabled_flag,
			)
		);

		if ( ! $id ) {
			$this->redirect_back( 'save_failed' );
		}

		$this->redirect_back( 'config_saved' );
	}

	public function handle_toggle() {
		$this->guard( 'jedb_relation_config_toggle' );

		$cct_slug = isset( $_POST['cct_slug'] ) ? sanitize_key( wp_unslash( $_POST['cct_slug'] ) ) : '';
		$enabled  = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'] ? 1 : 0;

		if ( '' === $cct_slug ) {
			$this->redirect_back( 'invalid_cct' );
		}

		JEDB_Relation_Config_Manager::instance()->set_enabled( $cct_slug, $enabled );

		$this->redirect_back( $enabled ? 'config_enabled' : 'config_disabled' );
	}

	public function handle_delete() {
		$this->guard( 'jedb_relation_config_delete' );

		$cct_slug = isset( $_POST['cct_slug'] ) ? sanitize_key( wp_unslash( $_POST['cct_slug'] ) ) : '';
		if ( '' === $cct_slug ) {
			$this->redirect_back( 'invalid_cct' );
		}

		JEDB_Relation_Config_Manager::instance()->delete_for_cct( $cct_slug );

		$this->redirect_back( 'config_deleted' );
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
	 * For each registered CCT, return the list of JE Relations whose
	 * parent_object or child_object resolves to that CCT — i.e. the list of
	 * relations the editor can pick for that CCT in this admin tab.
	 *
	 * @return array<string,array<int,array{id:string,name:string,type:string,cct_side:string,other_label:string,table_exists:bool}>>
	 */
	public function get_relations_per_cct() {

		$discovery = JEDB_Discovery::instance();
		$attacher  = new JEDB_Relation_Attacher();
		$registry  = JEDB_Target_Registry::instance();

		$ccts          = $registry->all_of_kind( 'cct' );
		$all_relations = $discovery->get_all_relations();
		$out           = array();

		foreach ( $ccts as $slug => $target ) {
			$cct_slug    = str_replace( 'cct::', '', $slug );
			$out[ $cct_slug ] = array();

			foreach ( $all_relations as $relation ) {
				$side = $attacher->determine_side( $relation['id'], $cct_slug );
				if ( 'none' === $side ) {
					continue;
				}

				$other_object = ( 'parent' === $side ) ? $relation['child_object'] : $relation['parent_object'];

				$out[ $cct_slug ][] = array(
					'id'           => (string) $relation['id'],
					'name'         => isset( $relation['name'] ) ? $relation['name'] : '',
					'type'         => isset( $relation['type'] ) ? $relation['type'] : 'one_to_many',
					'cct_side'     => $side,
					'other_label'  => $discovery->get_relation_object_name( $other_object ),
					'table_exists' => ! empty( $relation['table_exists'] ),
				);
			}
		}

		return $out;
	}
}
