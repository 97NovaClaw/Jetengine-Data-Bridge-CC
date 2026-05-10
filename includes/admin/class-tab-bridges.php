<?php
/**
 * Bridges admin tab — Phase 4 / Day 1.
 *
 * Surfaces the long-reserved `jedb_bridge_types` site option as a UI.
 * Each bridge type is a *template* — the Phase 4 Bridge meta box (Day 2)
 * clones a bridge type's defaults into a concrete `wp_jedb_flatten_configs`
 * row when an editor wires up an individual product.
 *
 * Tab priority: 28 (between Relations at 25 and Flatten at 30) so the
 * conceptual flow Targets (20) → Relations (25) → **Bridges (28)** →
 * Flatten (30) reads naturally — definitions on the left, instances on
 * the right.
 *
 * Form actions (admin-post.php):
 *   - jedb_bridges_save           — upsert one bridge type
 *   - jedb_bridges_toggle         — enable/disable
 *   - jedb_bridges_delete         — delete by slug
 *   - jedb_bridges_import         — replace_all() from a JSON paste
 *
 * AJAX endpoints:
 *   - wp_ajax_jedb_bridges_export — download all bridge types as JSON
 *   - wp_ajax_jedb_bridges_get_relations_for_pair — link-via picker
 *
 * Reuses the existing schema/taxonomies endpoints from JEDB_Tab_Flatten;
 * no need to duplicate.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Tab_Bridges {

	const TAB_SLUG = 'bridges';

	/** @var JEDB_Tab_Bridges|null */
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
		add_filter( 'jedb/admin/tabs',                              array( $this, 'register_tab' ) );

		add_action( 'admin_post_jedb_bridges_save',                 array( $this, 'handle_save' ) );
		add_action( 'admin_post_jedb_bridges_toggle',               array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_jedb_bridges_delete',               array( $this, 'handle_delete' ) );
		add_action( 'admin_post_jedb_bridges_import',               array( $this, 'handle_import' ) );

		add_action( 'wp_ajax_jedb_bridges_export',                  array( $this, 'ajax_export' ) );
		add_action( 'wp_ajax_jedb_bridges_get_relations_for_pair',  array( $this, 'ajax_get_relations_for_pair' ) );
	}

	public function register_tab( $tabs ) {
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Bridges', 'je-data-bridge-cc' ),
			'priority' => 28,
		);
		return $tabs;
	}

	/* -----------------------------------------------------------------------
	 * Form handlers
	 * -------------------------------------------------------------------- */

	public function handle_save() {

		$this->guard( 'jedb_bridges_save' );

		$original_slug = isset( $_POST['original_slug'] ) ? sanitize_key( wp_unslash( $_POST['original_slug'] ) ) : '';

		$config_raw = isset( $_POST['bridge_type_json'] ) ? (string) wp_unslash( $_POST['bridge_type_json'] ) : '';
		$decoded    = json_decode( $config_raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$decoded['slug']                             = isset( $_POST['slug'] )              ? sanitize_key( wp_unslash( $_POST['slug'] ) )                                       : '';
		$decoded['label']                            = isset( $_POST['label'] )             ? sanitize_text_field( wp_unslash( $_POST['label'] ) )                              : '';
		$decoded['description']                      = isset( $_POST['description'] )       ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) )                    : '';
		$decoded['source_target']                    = isset( $_POST['source_target'] )     ? sanitize_text_field( wp_unslash( $_POST['source_target'] ) )                       : '';
		$decoded['target_target']                    = isset( $_POST['target_target'] )     ? sanitize_text_field( wp_unslash( $_POST['target_target'] ) )                       : '';
		$decoded['default_direction']                = isset( $_POST['default_direction'] ) ? sanitize_key( wp_unslash( $_POST['default_direction'] ) )                          : 'push';
		$decoded['default_priority']                 = isset( $_POST['default_priority'] )  ? (int) $_POST['default_priority']                                                  : 100;
		$decoded['default_condition']                = isset( $_POST['default_condition'] ) ? (string) wp_unslash( $_POST['default_condition'] )                                : '';
		$decoded['enabled']                          = isset( $_POST['enabled'] );
		$decoded['auto_create_target_when_unlinked'] = isset( $_POST['auto_create_target_when_unlinked'] );
		$decoded['cct_single_redirect']              = isset( $_POST['cct_single_redirect'] );

		$decoded['link_via'] = array(
			'type'                    => isset( $_POST['link_via_type'] )                    ? sanitize_key( wp_unslash( $_POST['link_via_type'] ) )                                  : 'je_relation',
			'relation_id'             => isset( $_POST['link_via_relation_id'] )             ? (string) wp_unslash( $_POST['link_via_relation_id'] )                                  : '',
			'side'                    => isset( $_POST['link_via_side'] )                    ? sanitize_key( wp_unslash( $_POST['link_via_side'] ) )                                  : 'auto',
			'fallback_to_single_page' => isset( $_POST['link_via_fallback_to_single_page'] ),
			'auto_attach_relation'    => isset( $_POST['link_via_auto_attach_relation'] ),
		);

		$result = JEDB_Bridge_Types_Manager::instance()->upsert( $decoded, $original_slug );

		if ( ! $result['ok'] ) {
			$this->redirect_back( 'save_failed', array(
				'edit'  => $original_slug !== '' ? $original_slug : $decoded['slug'],
				'error' => $this->stash_error( $result['error'] ),
			) );
		}

		$this->redirect_back( 'config_saved', array( 'edit' => $result['bridge_type']['slug'] ) );
	}

	public function handle_toggle() {

		$this->guard( 'jedb_bridges_toggle' );

		$slug    = isset( $_POST['slug'] )    ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'];

		if ( '' === $slug ) {
			$this->redirect_back( 'invalid_slug' );
		}

		$ok = JEDB_Bridge_Types_Manager::instance()->set_enabled( $slug, $enabled );

		$this->redirect_back( $ok ? ( $enabled ? 'config_enabled' : 'config_disabled' ) : 'save_failed' );
	}

	public function handle_delete() {

		$this->guard( 'jedb_bridges_delete' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			$this->redirect_back( 'invalid_slug' );
		}

		$ok = JEDB_Bridge_Types_Manager::instance()->delete( $slug );

		$this->redirect_back( $ok ? 'config_deleted' : 'save_failed' );
	}

	public function handle_import() {

		$this->guard( 'jedb_bridges_import' );

		$payload_raw = isset( $_POST['import_json'] ) ? (string) wp_unslash( $_POST['import_json'] ) : '';
		$replace_all = isset( $_POST['replace_all'] );

		$decoded = json_decode( $payload_raw, true );
		if ( ! is_array( $decoded ) ) {
			$this->redirect_back( 'import_invalid_json' );
		}

		if ( isset( $decoded['bridge_types'] ) && is_array( $decoded['bridge_types'] ) ) {
			$list = $decoded['bridge_types'];
		} elseif ( $this->looks_like_indexed_array( $decoded ) ) {
			$list = $decoded;
		} else {
			$this->redirect_back( 'import_invalid_shape' );
		}

		$mgr = JEDB_Bridge_Types_Manager::instance();

		if ( $replace_all ) {
			$result = $mgr->replace_all( $list );
		} else {
			$imported = 0;
			$skipped  = 0;
			$errors   = array();
			foreach ( $list as $i => $entry ) {
				if ( ! is_array( $entry ) ) {
					$skipped++;
					continue;
				}
				$slug = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
				$res  = $mgr->upsert( $entry, $slug );
				if ( $res['ok'] ) {
					$imported++;
				} else {
					$skipped++;
					$errors[] = sprintf( '#%d: %s', $i + 1, $res['error'] );
				}
			}
			$result = array(
				'ok'       => true,
				'imported' => $imported,
				'skipped'  => $skipped,
			);
			if ( ! empty( $errors ) ) {
				$result['error'] = implode( ' · ', $errors );
			}
		}

		if ( ! $result['ok'] ) {
			$this->redirect_back( 'import_failed', array( 'error' => $this->stash_error( $result['error'] ) ) );
		}

		$args = array(
			'imported' => isset( $result['imported'] ) ? (int) $result['imported'] : 0,
			'skipped'  => isset( $result['skipped'] )  ? (int) $result['skipped']  : 0,
		);
		if ( ! empty( $result['error'] ) ) {
			$args['error'] = $this->stash_error( $result['error'] );
		}
		$this->redirect_back( 'import_done', $args );
	}

	/* -----------------------------------------------------------------------
	 * AJAX endpoints
	 * -------------------------------------------------------------------- */

	public function ajax_export() {

		check_ajax_referer( 'jedb_bridges_admin', 'nonce' );

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$all = JEDB_Bridge_Types_Manager::instance()->get_all();

		wp_send_json_success( array(
			'meta' => array(
				'plugin'      => 'je-data-bridge-cc',
				'version'     => defined( 'JEDB_VERSION' ) ? JEDB_VERSION : 'unknown',
				'exported_at' => current_time( 'mysql', false ),
				'count'       => count( $all ),
			),
			'bridge_types' => $all,
		) );
	}

	public function ajax_get_relations_for_pair() {

		check_ajax_referer( 'jedb_bridges_admin', 'nonce' );

		if ( ! current_user_can( JEDB_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$source_target = isset( $_POST['source_target'] ) ? sanitize_text_field( wp_unslash( $_POST['source_target'] ) ) : '';
		$target_target = isset( $_POST['target_target'] ) ? sanitize_text_field( wp_unslash( $_POST['target_target'] ) ) : '';

		if ( '' === $source_target || '' === $target_target ) {
			wp_send_json_error( array( 'message' => 'source_target and target_target are required' ), 400 );
		}

		$flatten_tab = JEDB_Tab_Flatten::instance();
		$relations   = $flatten_tab->get_relations_between( $source_target, $target_target );

		wp_send_json_success( array(
			'source_target' => $source_target,
			'target_target' => $target_target,
			'relations'     => $relations,
		) );
	}

	/* -----------------------------------------------------------------------
	 * Helpers used by the template
	 * -------------------------------------------------------------------- */

	/**
	 * Every CCT + every CPT/Woo target — bridge types are target-agnostic
	 * so we don't filter by kind here. The Bridge meta box (Day 2) will
	 * be Woo-specific, but the bridge type definitions are not.
	 *
	 * @return array<int,array{slug:string,label:string,kind:string}>
	 */
	public function get_eligible_targets() {

		$registry = JEDB_Target_Registry::instance();
		$out      = array();

		foreach ( $registry->all() as $slug => $target ) {
			$out[] = array(
				'slug'  => $slug,
				'label' => $target->get_label(),
				'kind'  => $target->get_kind(),
			);
		}

		usort( $out, static function ( $a, $b ) {
			$ka = ( 'cct' === $a['kind'] ) ? '0' : '1';
			$kb = ( 'cct' === $b['kind'] ) ? '0' : '1';
			$cmp = strcmp( $ka, $kb );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return $out;
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

	/**
	 * Notice messages can include long validator output. We don't want
	 * to round-trip the full text through the URL, so we store it in a
	 * short-lived transient keyed by user id and pass only the key.
	 *
	 * @param string $message
	 * @return string  The transient key (passed via ?error=KEY in the redirect).
	 */
	private function stash_error( $message ) {
		$key = 'jedb_bridges_err_' . get_current_user_id() . '_' . wp_generate_password( 8, false, false );
		set_transient( $key, (string) $message, 60 );
		return $key;
	}

	/**
	 * Read + delete a stashed error from the transient store.
	 *
	 * @param string $key
	 * @return string
	 */
	public function read_stashed_error( $key ) {
		$key = (string) $key;
		if ( '' === $key || 0 !== strpos( $key, 'jedb_bridges_err_' ) ) {
			return '';
		}
		$msg = (string) get_transient( $key );
		delete_transient( $key );
		return $msg;
	}

	private function looks_like_indexed_array( array $arr ) {
		if ( empty( $arr ) ) {
			return true;
		}
		$i = 0;
		foreach ( $arr as $k => $_v ) {
			if ( $k !== $i ) {
				return false;
			}
			$i++;
		}
		return true;
	}
}
