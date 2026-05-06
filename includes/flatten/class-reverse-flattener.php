<?php
/**
 * Reverse Flattener — pull-direction sync engine (post → CCT).
 *
 * Mirror of `JEDB_Flattener` for bridges with `direction = pull` or
 * `direction = bidirectional`. Hooks into post-side save events at
 * priority `JEDB_FLATTEN_HOOK_PRIORITY` (= 20):
 *
 *   - `woocommerce_update_product` + `woocommerce_new_product` for
 *     `posts::product` and `posts::product_variation` targets.
 *   - `save_post_{post_type}` for any other CPT target, with auto-save /
 *     revision filtering.
 *
 * Per-bridge flow on hook fire:
 *
 *   1. Resolve the linked source CCT row (ID) given the post ID:
 *        a. Look up the relation row in `{prefix}jet_rel_{id}` (post is
 *           on the side opposite the source CCT).
 *        b. Fallback to a CCT row whose `cct_single_post_id` equals the
 *           post id (mirror of the L-021 forward fallback).
 *        c. Optionally `auto_create_target_when_unlinked` (D-17, default
 *           OFF) creates a brand-new CCT row, attaches the relation, and
 *           lets the normal apply flow populate it.
 *
 *   2. Cross-direction cascade check: if the forward push engine is
 *      currently writing to this exact (source, target) pair, this is a
 *      ping-pong from the forward direction — bail with `skipped_locked`.
 *
 *   3. Evaluate the bridge's condition against the same `$context` shape
 *      as forward-push (just with `direction = 'pull'`).
 *
 *   4. For each enabled mapping, read the TARGET (post) field, run it
 *      through the `pull_transform` chain, diff against the SOURCE (CCT)
 *      field, write only the changes.
 *
 *   5. Write through `JEDB_Target_CCT::update()`. The CCT save fires JE's
 *      `updated-item/{slug}` hook — the forward engine listens there at
 *      the same priority but its own cross-direction check sees our
 *      pull lock and bails. No infinite loop.
 *
 *   6. Sync log records the outcome with `resolution`, `auto_attached`,
 *      `auto_created` flags so the user can see how the link was found.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Reverse_Flattener {

	/** @var JEDB_Reverse_Flattener|null */
	private static $instance = null;

	/** @var bool */
	private $hooks_registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks() {
		add_action( 'init', array( $this, 'register_post_save_hooks' ), 30 );
	}

	/* -----------------------------------------------------------------------
	 * Hook registration
	 * -------------------------------------------------------------------- */

	public function register_post_save_hooks() {

		if ( $this->hooks_registered ) {
			return;
		}
		$this->hooks_registered = true;

		$bridges_all = JEDB_Flatten_Config_Manager::instance()->get_all( array(
			'enabled' => 1,
		) );

		$bridges = array_values( array_filter( $bridges_all, static function ( $b ) {
			$dir = isset( $b['direction'] ) ? (string) $b['direction'] : '';
			return 'pull' === $dir || 'bidirectional' === $dir;
		} ) );

		if ( empty( $bridges ) ) {
			return;
		}

		$by_post_type = array();
		foreach ( $bridges as $bridge ) {

			$target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
			if ( 0 !== strpos( $target, 'posts::' ) ) {
				continue;
			}
			$post_type = substr( $target, 7 );
			if ( '' === $post_type ) {
				continue;
			}

			if ( ! isset( $by_post_type[ $post_type ] ) ) {
				$by_post_type[ $post_type ] = array();
			}
			$by_post_type[ $post_type ][] = $bridge;
		}

		$priority = defined( 'JEDB_FLATTEN_HOOK_PRIORITY' ) ? (int) JEDB_FLATTEN_HOOK_PRIORITY : 20;

		foreach ( $by_post_type as $post_type => $pt_bridges ) {

			if ( in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
				// WooCommerce-typed events fire AFTER the WC_Product / variation
				// has fully saved (lookup table refreshed, meta written), and
				// they don't fire on auto-saves or revisions, which is exactly
				// what we want.
				add_action(
					'woocommerce_update_' . $post_type,
					function ( $product_id, $product = null ) use ( $post_type, $pt_bridges ) {
						$this->run_for_post_event( $post_type, absint( $product_id ), $pt_bridges, 'wc_product_save' );
					},
					$priority,
					2
				);

				add_action(
					'woocommerce_new_' . $post_type,
					function ( $product_id, $product = null ) use ( $post_type, $pt_bridges ) {
						$this->run_for_post_event( $post_type, absint( $product_id ), $pt_bridges, 'wc_product_new' );
					},
					$priority,
					2
				);
			} else {
				// Generic CPT — filter out auto-saves, revisions, and bulk-edit
				// noise to avoid running on every minor draft churn.
				add_action(
					'save_post_' . $post_type,
					function ( $post_id, $post = null, $update = null ) use ( $post_type, $pt_bridges ) {
						if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
							return;
						}
						if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
							return;
						}
						$this->run_for_post_event( $post_type, absint( $post_id ), $pt_bridges, 'post_save' );
					},
					$priority,
					3
				);
			}

			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Reverse_Flattener] hooks registered', 'debug', array(
					'post_type' => $post_type,
					'bridges'   => count( $pt_bridges ),
					'priority'  => $priority,
				) );
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Per-event dispatch
	 * -------------------------------------------------------------------- */

	private function run_for_post_event( $post_type, $post_id, array $bridges, $context_label ) {

		if ( ! $post_id ) {
			return;
		}

		usort( $bridges, static function ( $a, $b ) {
			$pa = isset( $a['config']['priority'] ) ? (int) $a['config']['priority'] : 100;
			$pb = isset( $b['config']['priority'] ) ? (int) $b['config']['priority'] : 100;
			if ( $pa === $pb ) {
				return ( (int) $a['id'] ) <=> ( (int) $b['id'] );
			}
			return $pa <=> $pb;
		} );

		foreach ( $bridges as $bridge ) {
			try {
				$this->apply_bridge( $bridge, $post_id, $context_label );
			} catch ( \Throwable $t ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Reverse_Flattener] bridge threw — continuing', 'error', array(
						'bridge_id' => isset( $bridge['id'] ) ? $bridge['id'] : null,
						'post_type' => $post_type,
						'post_id'   => $post_id,
						'error'     => $t->getMessage(),
					) );
				}
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * The bridge apply path
	 *
	 * Public so the admin "Sync from this post" button (Phase 4) and the
	 * Phase 5 bulk-sync utility can call it. Returns one of the
	 * JEDB_Sync_Log::STATUS_* constants.
	 * -------------------------------------------------------------------- */

	public function apply_bridge( array $bridge, $post_id, $origin_tag = 'manual_pull' ) {

		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();

		$registry      = JEDB_Target_Registry::instance();
		$source_adapter = $registry->get( $source_target );
		$target_adapter = $registry->get( $target_target );

		if ( ! $source_adapter || ! $target_adapter ) {
			$this->log_status( $bridge, '', $post_id, JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, 'adapter missing', array(
				'source_target' => $source_target,
				'target_target' => $target_target,
				'source_loaded' => (bool) $source_adapter,
				'target_loaded' => (bool) $target_adapter,
			) );
			return JEDB_Sync_Log::STATUS_ERRORED;
		}

		$target_data = $target_adapter->get( $post_id );
		if ( ! is_array( $target_data ) ) {
			$this->log_status( $bridge, '', $post_id, JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, 'target post not found', array() );
			return JEDB_Sync_Log::STATUS_ERRORED;
		}

		list( $source_id, $resolution_method, $auto_created, $auto_attached ) =
			$this->resolve_source_id( $config, $source_target, $target_target, $post_id, $target_data );

		if ( ! $source_id ) {
			$this->log_status( $bridge, '', $post_id, JEDB_Sync_Log::STATUS_SKIPPED_NO_TARGET, $origin_tag, 'no linked source CCT row — set link_via.auto_create_target_when_unlinked to opt in', array(
				'link_via'      => isset( $config['link_via'] ) ? $config['link_via'] : null,
				'resolution'    => $resolution_method,
				'auto_create'   => ! empty( $config['auto_create_target_when_unlinked'] ),
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_NO_TARGET;
		}

		$source_data = $source_adapter->get( $source_id );
		if ( ! is_array( $source_data ) ) {
			$source_data = array();
		}

		$context = array(
			'source_target' => $source_adapter,
			'source_id'     => $source_id,
			'source_data'   => $source_data,
			'target_target' => $target_adapter,
			'target_id'     => $post_id,
			'target_data'   => $target_data,
			'direction'     => 'pull',
			'bridge_type'   => isset( $bridge['config_slug'] ) ? $bridge['config_slug'] : '',
			'trigger'       => $origin_tag,
		);

		$guard = JEDB_Sync_Guard::instance();

		// Cross-direction cascade check: if the forward push engine is
		// currently mid-write to THIS exact (source, target) pair, the
		// post-save event we're servicing is the forward-engine's own
		// nested side effect — bail rather than mirror the values back.
		if ( $guard->is_locked( 'push', $source_target, $source_id, $target_target, $post_id ) ) {
			$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_SKIPPED_LOCKED, $origin_tag, 'forward-push cascade detected — pull suppressed', array(
				'cascade'       => 'push_in_flight',
				'resolution'    => $resolution_method,
				'auto_attached' => $auto_attached,
				'auto_created'  => $auto_created,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_LOCKED;
		}

		$dsl = isset( $config['condition'] ) ? (string) $config['condition'] : '';
		if ( '' !== trim( $dsl ) ) {
			$ok = JEDB_Condition_Evaluator::instance()->evaluate( $dsl, $context );
			if ( ! $ok ) {
				$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_SKIPPED_CONDITION, $origin_tag, 'condition returned false', array(
					'dsl'           => $dsl,
					'resolution'    => $resolution_method,
					'auto_attached' => $auto_attached,
					'auto_created'  => $auto_created,
				) );
				return JEDB_Sync_Log::STATUS_SKIPPED_CONDITION;
			}
		}

		$snippet_slug = isset( $config['condition_snippet'] ) ? (string) $config['condition_snippet'] : '';
		if ( '' !== $snippet_slug ) {
			$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_SKIPPED_ERROR, $origin_tag, 'condition_snippet ignored — Snippet runtime ships in Phase 5b', array(
				'snippet'    => $snippet_slug,
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_ERROR;
		}

		$mappings = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : array();
		if ( empty( $mappings ) ) {
			$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_NOOP, $origin_tag, 'bridge has no mappings', array(
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_NOOP;
		}

		if ( ! $guard->acquire( 'pull', $source_target, $source_id, $target_target, $post_id, $origin_tag ) ) {
			$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_SKIPPED_LOCKED, $origin_tag, 'sync_guard already locked — same-direction cycle detected', array(
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_LOCKED;
		}

		try {

			$registry_t = JEDB_Transformer_Registry::instance();
			$payload    = array();
			$per_field  = array();
			$any_change = false;
			$noop_count = 0;

			foreach ( $mappings as $m ) {

				if ( ! is_array( $m ) || empty( $m['enabled'] ) ) {
					continue;
				}

				$source_field = isset( $m['source_field'] ) ? (string) $m['source_field'] : '';
				$target_field = isset( $m['target_field'] ) ? (string) $m['target_field'] : '';
				if ( '' === $source_field || '' === $target_field ) {
					continue;
				}

				$target_value = array_key_exists( $target_field, $target_data ) ? $target_data[ $target_field ] : null;
				$chain        = isset( $m['pull_transform'] ) && is_array( $m['pull_transform'] ) ? $m['pull_transform'] : array();

				$transformed = $registry_t->run_chain( $target_value, $chain, 'pull', $context );

				$existing_source_value = array_key_exists( $source_field, $source_data ) ? $source_data[ $source_field ] : null;

				if ( $this->loose_equals( $existing_source_value, $transformed ) ) {
					$per_field[ $source_field ] = 'noop';
					$noop_count++;
					continue;
				}

				$payload[ $source_field ]   = $transformed;
				$per_field[ $source_field ] = 'will_write';
				$any_change                 = true;
			}

			if ( ! $any_change ) {
				$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_NOOP, $origin_tag, 'every mapped value already matched source — nothing to write', array(
					'fields_examined' => count( $per_field ),
					'per_field'       => $per_field,
					'resolution'      => $resolution_method,
					'auto_attached'   => $auto_attached,
					'auto_created'    => $auto_created,
				) );
				return JEDB_Sync_Log::STATUS_NOOP;
			}

			$ok = $source_adapter->update( $source_id, $payload );

			$status  = $ok ? JEDB_Sync_Log::STATUS_SUCCESS : JEDB_Sync_Log::STATUS_ERRORED;
			$message = $ok ? sprintf( 'wrote %d field(s)', count( $payload ) ) : 'source adapter update returned false';

			$this->log_status( $bridge, $source_id, $post_id, $status, $origin_tag, $message, array(
				'fields'        => array_keys( $payload ),
				'noop_fields'   => $noop_count,
				'per_field'     => $per_field,
				'resolution'    => $resolution_method,
				'auto_attached' => $auto_attached,
				'auto_created'  => $auto_created,
			) );

			return $status;

		} catch ( \Throwable $e ) {

			$this->log_status( $bridge, $source_id, $post_id, JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, $e->getMessage(), array(
				'file'          => $e->getFile(),
				'line'          => $e->getLine(),
				'resolution'    => $resolution_method,
				'auto_attached' => $auto_attached,
				'auto_created'  => $auto_created,
			) );
			return JEDB_Sync_Log::STATUS_ERRORED;

		} finally {
			$guard->release( 'pull', $source_target, $source_id, $target_target, $post_id );
		}
	}

	/* -----------------------------------------------------------------------
	 * Source-row resolution
	 * -------------------------------------------------------------------- */

	/**
	 * Resolve the source CCT row's _ID given a target post id.
	 *
	 * Resolution order for `link_via.type = 'je_relation'`:
	 *   1. Relation row in `{prefix}jet_rel_{id}` with the post on the
	 *      OPPOSITE side from the source CCT.
	 *   2. Fallback to a CCT row whose `cct_single_post_id` equals
	 *      `$post_id` (mirror of the L-021 forward fallback).
	 *   3. If `auto_create_target_when_unlinked` is true (default OFF
	 *      per D-17), create a fresh CCT row with empty seed; the
	 *      caller's apply flow then populates it via the normal pull
	 *      transform pipeline. Auto-attach the relation row when
	 *      `link_via.auto_attach_relation` is also on.
	 *
	 * For `link_via.type = 'cct_single_post_id'` we skip step 1 and
	 * just look up the CCT row whose `cct_single_post_id` equals the
	 * post id. Auto-create is supported there too.
	 *
	 * @return array{int, string, bool, bool}  [source_id, resolution, auto_created, auto_attached]
	 */
	public function resolve_source_id( array $config, $source_target, $target_target, $post_id, array $target_data ) {

		global $wpdb;

		$cct_slug = 0 === strpos( $source_target, 'cct::' ) ? substr( $source_target, 5 ) : '';
		if ( '' === $cct_slug ) {
			return array( 0, 'none', false, false );
		}

		$cct_table = $wpdb->prefix . 'jet_cct_' . $cct_slug;

		$link        = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
		$type        = isset( $link['type'] ) ? (string) $link['type'] : 'je_relation';
		$auto_create = ! empty( $config['auto_create_target_when_unlinked'] );

		// --- Path A: cct_single_post_id is the declared link mechanism ---

		if ( 'cct_single_post_id' === $type ) {
			$cct_id = $this->lookup_cct_by_single_post_id( $cct_table, $post_id );
			if ( $cct_id ) {
				return array( $cct_id, 'cct_single_post_id', false, false );
			}
			if ( $auto_create ) {
				$new_id = $this->auto_create_cct( $source_target, $post_id );
				if ( $new_id ) {
					// Note: cct_single_post_id is JE-managed; we don't write to it
					// directly. The link will be visible on the next CCT save when
					// JE backfills the column for the new row's single page.
					return array( $new_id, 'auto_created', true, false );
				}
			}
			return array( 0, 'none', false, false );
		}

		// --- Path B: je_relation is the declared link mechanism ---

		$relation_id = isset( $link['relation_id'] ) ? (string) $link['relation_id'] : '';
		if ( '' === $relation_id ) {
			return array( 0, 'none', false, false );
		}

		$attacher = new JEDB_Relation_Attacher();
		$relation = $attacher->get_relation_object( $relation_id );
		if ( ! $relation ) {
			return array( 0, 'none', false, false );
		}

		$declared_side = isset( $link['side'] ) ? (string) $link['side'] : 'auto';
		if ( in_array( $declared_side, array( 'parent', 'child' ), true ) ) {
			$source_side = $declared_side;
		} else {
			$source_side = $attacher->determine_side( $relation_id, $cct_slug );
		}

		if ( 'none' === $source_side ) {
			return array( 0, 'none', false, false );
		}

		$rel_table = $wpdb->prefix . 'jet_rel_' . absint( $relation_id );
		$rel_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rel_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $rel_table_exists ) {
			// Source CCT is on $source_side; therefore post is on the OPPOSITE side.
			if ( 'parent' === $source_side ) {
				$row = $wpdb->get_var( $wpdb->prepare( "SELECT parent_object_id FROM `{$rel_table}` WHERE child_object_id = %d ORDER BY _ID DESC LIMIT 1", absint( $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			} else {
				$row = $wpdb->get_var( $wpdb->prepare( "SELECT child_object_id FROM `{$rel_table}` WHERE parent_object_id = %d ORDER BY _ID DESC LIMIT 1", absint( $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			}

			if ( $row ) {
				return array( absint( $row ), 'relation_row', false, false );
			}
		}

		// Fallback: cct_single_post_id (mirror of L-021 forward self-heal)
		$fallback_enabled = ! isset( $link['fallback_to_single_page'] ) || ! empty( $link['fallback_to_single_page'] );

		if ( $fallback_enabled ) {

			$cct_id = $this->lookup_cct_by_single_post_id( $cct_table, $post_id );

			if ( $cct_id ) {

				$auto_attach = ! isset( $link['auto_attach_relation'] ) || ! empty( $link['auto_attach_relation'] );
				$attached    = false;

				if ( $auto_attach && $rel_table_exists ) {
					$parent_id = ( 'parent' === $source_side ) ? $cct_id    : absint( $post_id );
					$child_id  = ( 'parent' === $source_side ) ? absint( $post_id ) : $cct_id;

					$result   = $attacher->attach( $relation_id, $parent_id, $child_id );
					$attached = ( true === $result || 'exists' === $result );

					if ( function_exists( 'jedb_log' ) ) {
						jedb_log( '[Reverse_Flattener] auto-attached JE relation via cct_single_post_id fallback', 'info', array(
							'relation_id' => $relation_id,
							'cct_slug'    => $cct_slug,
							'side'        => $source_side,
							'parent_id'   => $parent_id,
							'child_id'    => $child_id,
							'result'      => $result,
						) );
					}
				}

				return array( $cct_id, 'fallback_single_page', false, $attached );
			}
		}

		// No CCT row exists at all. Auto-create if the bridge opts in.
		if ( $auto_create ) {

			$new_id = $this->auto_create_cct( $source_target, $post_id );

			if ( $new_id ) {

				$auto_attach = ! isset( $link['auto_attach_relation'] ) || ! empty( $link['auto_attach_relation'] );
				$attached    = false;

				if ( $auto_attach && $rel_table_exists ) {
					$parent_id = ( 'parent' === $source_side ) ? $new_id          : absint( $post_id );
					$child_id  = ( 'parent' === $source_side ) ? absint( $post_id ) : $new_id;

					$result   = $attacher->attach( $relation_id, $parent_id, $child_id );
					$attached = ( true === $result || 'exists' === $result );
				}

				return array( $new_id, 'auto_created', true, $attached );
			}
		}

		return array( 0, 'none', false, false );
	}

	/**
	 * Find the CCT _ID whose `cct_single_post_id` column equals the given
	 * post id. Returns 0 when no row matches or the table doesn't have
	 * the column (Has-Single-Page disabled on this CCT).
	 */
	private function lookup_cct_by_single_post_id( $cct_table, $post_id ) {

		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cct_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			return 0;
		}

		// Probe column existence so we don't error out on CCTs without
		// "Has Single Page" enabled.
		$col = $wpdb->get_var( $wpdb->prepare(
			"SHOW COLUMNS FROM `{$cct_table}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
			'cct_single_post_id'
		) );
		if ( ! $col ) {
			return 0;
		}

		$row = $wpdb->get_var( $wpdb->prepare( "SELECT _ID FROM `{$cct_table}` WHERE cct_single_post_id = %d LIMIT 1", absint( $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		return $row ? absint( $row ) : 0;
	}

	/**
	 * Create an empty CCT row for auto-create flow. The caller's apply
	 * pipeline will then populate the row via the normal pull transform
	 * chain — we don't seed fields here so the user's transformer config
	 * stays the single source of truth for what gets written.
	 *
	 * Returns the new _ID, or 0 on failure.
	 */
	private function auto_create_cct( $source_target, $post_id ) {

		$registry = JEDB_Target_Registry::instance();
		$adapter  = $registry->get( $source_target );

		if ( ! $adapter ) {
			return 0;
		}

		try {
			$new_id = $adapter->create( array() );
		} catch ( \Throwable $t ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Reverse_Flattener] auto-create CCT row threw — refusing to continue', 'error', array(
					'source_target' => $source_target,
					'post_id'       => $post_id,
					'error'         => $t->getMessage(),
				) );
			}
			return 0;
		}

		$new_id = absint( $new_id );

		if ( $new_id && function_exists( 'jedb_log' ) ) {
			jedb_log( '[Reverse_Flattener] auto-created CCT row (D-17 opt-in)', 'info', array(
				'source_target' => $source_target,
				'post_id'       => $post_id,
				'new_id'        => $new_id,
			) );
		}

		return $new_id;
	}

	/* -----------------------------------------------------------------------
	 * Helpers (mirrored from forward Flattener; kept inline rather than
	 * extracted to a trait so each engine reads top-to-bottom with no
	 * cross-class context required for debugging).
	 * -------------------------------------------------------------------- */

	private function loose_equals( $a, $b ) {

		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}

		if ( is_array( $a ) || is_array( $b ) ) {
			return wp_json_encode( $a ) === wp_json_encode( $b );
		}

		if ( null === $a || null === $b ) {
			return $a === $b;
		}

		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}

		return (string) $a === (string) $b;
	}

	private function log_status( array $bridge, $source_id, $target_id, $status, $origin, $message, array $context ) {

		$context['bridge_id']   = isset( $bridge['id'] ) ? (int) $bridge['id'] : 0;
		$context['bridge_slug'] = isset( $bridge['config_slug'] ) ? (string) $bridge['config_slug'] : '';

		JEDB_Sync_Log::instance()->record( array(
			'direction'     => 'pull',
			'source_target' => isset( $bridge['source_target'] ) ? $bridge['source_target'] : '',
			'source_id'     => $source_id,
			'target_target' => isset( $bridge['target_target'] ) ? $bridge['target_target'] : '',
			'target_id'     => $target_id,
			'origin'        => $origin,
			'status'        => $status,
			'message'       => $message,
			'context'       => $context,
		) );
	}
}
