<?php
/**
 * Flattener — forward-direction (push) sync engine.
 *
 * Hooks into JE's CCT save events at priority JEDB_FLATTEN_HOOK_PRIORITY
 * (20 per D-19 / L-018 — let JE's own auto-create finish first), then for
 * each matching enabled flatten config:
 *
 *   1. Resolves the linked TARGET record via the bridge's `link_via`:
 *      - `je_relation`         → lookup in `{prefix}jet_rel_{relation_id}`
 *      - `cct_single_post_id`  → read `cct_single_post_id` off the source row
 *
 *   2. Builds the BUILD-PLAN §4.9 $context shape.
 *
 *   3. Evaluates the bridge's condition (declarative DSL — Phase 3 — or
 *      condition_snippet — Phase 5b stubbed for now).
 *
 *   4. For each enabled mapping in `config['mappings']`, runs the
 *      `push_transform` chain on the source value and writes through the
 *      target adapter.
 *
 *   5. Wraps the entire write in `JEDB_Sync_Guard` so the resulting
 *      target-side save event can't recurse back into us.
 *
 *   6. Records every outcome (success / partial / errored / skipped*) to
 *      `wp_jedb_sync_log` — one row per bridge invocation.
 *
 * Reverse direction (pull, post→CCT) ships in Phase 3.5; the engine path
 * is mostly symmetric and will reuse the same components plumbed here.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Flattener {

	/** @var JEDB_Flattener|null */
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
		add_action( 'init', array( $this, 'register_cct_save_hooks' ), 30 );
	}

	/* -----------------------------------------------------------------------
	 * Hook registration
	 * -------------------------------------------------------------------- */

	/**
	 * Walk the enabled push bridges grouped by source CCT and wire one
	 * save-hook pair per CCT. Hook priority is JEDB_FLATTEN_HOOK_PRIORITY
	 * (defined in the bootstrap, defaults to 20). Per L-018: this MUST be
	 * later than the relation transaction processor (priority 10) AND
	 * later than JE's own internal auto-create handlers, so the related
	 * post the bridge writes onto already exists by the time we read.
	 */
	public function register_cct_save_hooks() {

		if ( $this->hooks_registered ) {
			return;
		}
		$this->hooks_registered = true;

		$bridges_all = JEDB_Flatten_Config_Manager::instance()->get_all( array(
			'enabled' => 1,
		) );

		// Forward (push) flattener fires for `push` AND `bidirectional`
		// bridges. The reverse (pull) flattener (Phase 3.5) handles `pull`
		// AND `bidirectional` from the post-side hooks. A bidirectional
		// bridge therefore registers BOTH hook sets and relies on the
		// cross-direction Sync_Guard cascade check in apply_bridge() to
		// prevent infinite loops.
		$bridges = array_values( array_filter( $bridges_all, static function ( $b ) {
			$dir = isset( $b['direction'] ) ? (string) $b['direction'] : '';
			return 'push' === $dir || 'bidirectional' === $dir;
		} ) );

		if ( empty( $bridges ) ) {
			return;
		}

		$by_cct = array();
		foreach ( $bridges as $bridge ) {
			$src = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
			if ( 0 !== strpos( $src, 'cct::' ) ) {
				continue;
			}
			$cct_slug = substr( $src, 5 );
			if ( '' === $cct_slug ) {
				continue;
			}
			if ( ! isset( $by_cct[ $cct_slug ] ) ) {
				$by_cct[ $cct_slug ] = array();
			}
			$by_cct[ $cct_slug ][] = $bridge;
		}

		$priority = defined( 'JEDB_FLATTEN_HOOK_PRIORITY' ) ? (int) JEDB_FLATTEN_HOOK_PRIORITY : 20;

		foreach ( $by_cct as $cct_slug => $cct_bridges ) {

			add_action(
				'jet-engine/custom-content-types/created-item/' . $cct_slug,
				function ( $item, $item_id, $handler ) use ( $cct_slug, $cct_bridges ) {
					$this->run_for_cct_event( $cct_slug, absint( $item_id ), $cct_bridges, 'created' );
				},
				$priority,
				3
			);

			add_action(
				'jet-engine/custom-content-types/updated-item/' . $cct_slug,
				function ( $item, $prev_item, $handler ) use ( $cct_slug, $cct_bridges ) {
					$id = is_array( $item ) && isset( $item['_ID'] ) ? absint( $item['_ID'] ) : 0;
					$this->run_for_cct_event( $cct_slug, $id, $cct_bridges, 'updated' );
				},
				$priority,
				3
			);

			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Flattener] hooks registered', 'debug', array(
					'cct_slug' => $cct_slug,
					'bridges'  => count( $cct_bridges ),
					'priority' => $priority,
				) );
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Per-event dispatch
	 * -------------------------------------------------------------------- */

	private function run_for_cct_event( $cct_slug, $source_id, array $bridges, $context_label ) {

		if ( ! $source_id ) {
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
				$this->apply_bridge( $bridge, $source_id, 'cct_save_' . $context_label );
			} catch ( \Throwable $t ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Flattener] bridge threw — continuing', 'error', array(
						'bridge_id' => isset( $bridge['id'] ) ? $bridge['id'] : null,
						'cct_slug'  => $cct_slug,
						'source_id' => $source_id,
						'error'     => $t->getMessage(),
					) );
				}
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * The bridge apply path — the actual work
	 * -------------------------------------------------------------------- */

	/**
	 * Apply one bridge config against one source record.
	 *
	 * Public so the admin "Sync now" button + Phase 5 bulk-sync can call it.
	 *
	 * @param array  $bridge      A decoded row from JEDB_Flatten_Config_Manager.
	 * @param int    $source_id   Source record's PK.
	 * @param string $origin_tag  Free-form tag used by Sync_Guard + Sync_Log.
	 * @return string             One of JEDB_Sync_Log::STATUS_* constants.
	 */
	public function apply_bridge( array $bridge, $source_id, $origin_tag = 'manual' ) {

		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();

		$registry      = JEDB_Target_Registry::instance();
		$source_adapter = $registry->get( $source_target );
		$target_adapter = $registry->get( $target_target );

		if ( ! $source_adapter || ! $target_adapter ) {
			$this->log_status( $bridge, $source_id, '', JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, 'adapter missing', array(
				'source_target' => $source_target,
				'target_target' => $target_target,
				'source_loaded' => (bool) $source_adapter,
				'target_loaded' => (bool) $target_adapter,
			) );
			return JEDB_Sync_Log::STATUS_ERRORED;
		}

		$source_data = $source_adapter->get( $source_id );
		if ( ! is_array( $source_data ) ) {
			$this->log_status( $bridge, $source_id, '', JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, 'source record not found', array() );
			return JEDB_Sync_Log::STATUS_ERRORED;
		}

		list( $target_id, $resolution_method, $auto_attached ) = $this->resolve_target_id( $config, $source_target, $source_id, $source_data );

		if ( ! $target_id ) {
			$this->log_status( $bridge, $source_id, '', JEDB_Sync_Log::STATUS_SKIPPED_NO_TARGET, $origin_tag, 'no linked target — Phase 3.5 will optionally auto-create', array(
				'link_via'         => isset( $config['link_via'] ) ? $config['link_via'] : null,
				'resolution'       => $resolution_method,
				'has_single_page'  => isset( $source_data['cct_single_post_id'] ) && (int) $source_data['cct_single_post_id'] > 0,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_NO_TARGET;
		}

		$target_data = $target_adapter->get( $target_id );
		if ( ! is_array( $target_data ) ) {
			$target_data = array();
		}

		$context = array(
			'source_target' => $source_adapter,
			'source_id'     => $source_id,
			'source_data'   => $source_data,
			'target_target' => $target_adapter,
			'target_id'     => $target_id,
			'target_data'   => $target_data,
			'direction'     => 'push',
			'bridge_type'   => isset( $bridge['config_slug'] ) ? $bridge['config_slug'] : '',
			'trigger'       => $origin_tag,
		);

		$guard = JEDB_Sync_Guard::instance();

		// Cross-direction cascade check (Phase 3.5): if the reverse pull
		// engine is currently writing to this CCT row from the bridged
		// post, that's a cascade event — bail rather than push the same
		// values back. Both engines do this symmetric check before
		// acquiring their own direction's lock; together they prevent
		// infinite ping-pong on bidirectional bridges.
		if ( $guard->is_locked( 'pull', $source_target, $source_id, $target_target, $target_id ) ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_LOCKED, $origin_tag, 'reverse-pull cascade detected — push suppressed', array(
				'cascade'    => 'pull_in_flight',
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_LOCKED;
		}

		$dsl = isset( $config['condition'] ) ? (string) $config['condition'] : '';
		if ( '' !== trim( $dsl ) ) {
			$ok = JEDB_Condition_Evaluator::instance()->evaluate( $dsl, $context );
			if ( ! $ok ) {
				$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_CONDITION, $origin_tag, 'condition returned false', array(
					'dsl'           => $dsl,
					'resolution'    => $resolution_method,
					'auto_attached' => $auto_attached,
				) );
				return JEDB_Sync_Log::STATUS_SKIPPED_CONDITION;
			}
		}

		$snippet_slug = isset( $config['condition_snippet'] ) ? (string) $config['condition_snippet'] : '';
		if ( '' !== $snippet_slug ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_ERROR, $origin_tag, 'condition_snippet ignored — Snippet runtime ships in Phase 5b', array(
				'snippet'    => $snippet_slug,
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_ERROR;
		}

		$mappings   = isset( $config['mappings'] )   && is_array( $config['mappings'] )   ? $config['mappings']   : array();
		$taxonomies = isset( $config['taxonomies'] ) && is_array( $config['taxonomies'] ) ? $config['taxonomies'] : array();

		// Phase 3.6 / D-20: a bridge with ONLY taxonomies (no mappings)
		// is still a valid bridge that can do meaningful work on push.
		// Only short-circuit when BOTH arrays are empty.
		if ( empty( $mappings ) && empty( $taxonomies ) ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_NOOP, $origin_tag, 'bridge has no mappings or taxonomy rules', array(
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_NOOP;
		}

		if ( ! $guard->acquire( 'push', $source_target, $source_id, $target_target, $target_id, $origin_tag ) ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_LOCKED, $origin_tag, 'sync_guard already locked — same-direction cycle detected', array(
				'resolution' => $resolution_method,
			) );
			return JEDB_Sync_Log::STATUS_SKIPPED_LOCKED;
		}

		try {

			// Phase 3.6 / D-20-D-24: apply taxonomy rules BEFORE field
			// mappings. The categorization decision ("which storefront
			// taxonomy slot does this product live in?") is conceptually
			// upstream of the field-level copy-paste of names / prices,
			// and editors expect the category to be set before any
			// taxonomy-aware transformer in the mappings chain runs.
			$taxonomy_summary = array(
				'rules_processed' => 0,
				'rules_applied'   => 0,
				'terms_added'     => 0,
				'terms_removed'   => 0,
				'terms_created'   => 0,
				'rules'           => array(),
			);

			if ( ! empty( $taxonomies ) ) {
				$taxonomy_summary = JEDB_Taxonomy_Applier::instance()->apply_for_bridge(
					$taxonomies,
					$target_id,
					$context
				);
			}

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

				$source_value = array_key_exists( $source_field, $source_data ) ? $source_data[ $source_field ] : null;
				$chain        = isset( $m['push_transform'] ) && is_array( $m['push_transform'] ) ? $m['push_transform'] : array();

				$transformed = $registry_t->run_chain( $source_value, $chain, 'push', $context );

				$existing_target_value = array_key_exists( $target_field, $target_data ) ? $target_data[ $target_field ] : null;

				if ( $this->loose_equals( $existing_target_value, $transformed ) ) {
					$per_field[ $target_field ] = 'noop';
					$noop_count++;
					continue;
				}

				$payload[ $target_field ]   = $transformed;
				$per_field[ $target_field ] = 'will_write';
				$any_change                 = true;
			}

			if ( ! $any_change ) {
				// Even if no mappings wrote, taxonomies may have changed —
				// reflect that in status. If both are noops, log noop;
				// if taxonomies actually moved terms, log success with a
				// `taxonomies_only` marker so editors can audit.
				$taxonomies_changed = ( $taxonomy_summary['terms_added'] > 0
					|| $taxonomy_summary['terms_removed'] > 0
					|| $taxonomy_summary['terms_created'] > 0 );

				if ( ! $taxonomies_changed ) {
					$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_NOOP, $origin_tag, 'every mapped value already matched target — nothing to write', array(
						'fields_examined'  => count( $per_field ),
						'per_field'        => $per_field,
						'resolution'       => $resolution_method,
						'auto_attached'    => $auto_attached,
						'taxonomies'       => $taxonomy_summary,
					) );
					return JEDB_Sync_Log::STATUS_NOOP;
				}

				$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SUCCESS, $origin_tag, sprintf( 'fields all noop, but %d taxonomy term(s) changed', $taxonomy_summary['terms_added'] + $taxonomy_summary['terms_removed'] ), array(
					'fields_examined'   => count( $per_field ),
					'per_field'         => $per_field,
					'resolution'        => $resolution_method,
					'auto_attached'     => $auto_attached,
					'taxonomies'        => $taxonomy_summary,
					'taxonomies_only'   => true,
				) );
				return JEDB_Sync_Log::STATUS_SUCCESS;
			}

			$ok = $target_adapter->update( $target_id, $payload );

			$status  = $ok ? JEDB_Sync_Log::STATUS_SUCCESS : JEDB_Sync_Log::STATUS_ERRORED;
			$message = $ok ? sprintf( 'wrote %d field(s)', count( $payload ) ) : 'target adapter update returned false';

			$this->log_status( $bridge, $source_id, $target_id, $status, $origin_tag, $message, array(
				'fields'        => array_keys( $payload ),
				'noop_fields'   => $noop_count,
				'per_field'     => $per_field,
				'resolution'    => $resolution_method,
				'auto_attached' => $auto_attached,
				'taxonomies'    => $taxonomy_summary,
			) );

			return $status;

		} catch ( \Throwable $e ) {

			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, $e->getMessage(), array(
				'file'          => $e->getFile(),
				'line'          => $e->getLine(),
				'resolution'    => $resolution_method,
				'auto_attached' => $auto_attached,
				'taxonomies'    => isset( $taxonomy_summary ) ? $taxonomy_summary : null,
			) );
			return JEDB_Sync_Log::STATUS_ERRORED;

		} finally {
			$guard->release( 'push', $source_target, $source_id, $target_target, $target_id );
		}
	}

	/* -----------------------------------------------------------------------
	 * Link resolution
	 * -------------------------------------------------------------------- */

	/**
	 * Resolve the linked target record's primary key for this source.
	 *
	 * Returns 0 when no link is found — callers treat that as
	 * "skipped_no_target" rather than a hard error (Phase 3.5's auto-create
	 * flag turns it into a create instead).
	 *
	 * Resolution order for `link_via.type = 'je_relation'`:
	 *   1. JE Relation row in `{prefix}jet_rel_{id}` — fast path.
	 *   2. **Fallback** to `cct_single_post_id` when (a) no relation row
	 *      exists, (b) `link_via.fallback_to_single_page` is on (default
	 *      true), (c) the source CCT row has Has-Single-Page enabled with
	 *      `cct_single_post_id` populated, (d) the linked post's type
	 *      matches the relation's other endpoint.
	 *   3. **Auto-attach**: when fallback resolves successfully and
	 *      `link_via.auto_attach_relation` is on (default true), write
	 *      a relation row so JE Smart Filters / Listing Grids see the link
	 *      from this point forward. The next sync uses the fast path.
	 *
	 * Background (L-021): JE's "auto-create" on CCT save creates the linked
	 * post via Has-Single-Page (`cct_single_post_id`); it does NOT write a
	 * relation row. Without this fallback, a fresh CCT row that has its
	 * single-page post but no picker-driven attach would log
	 * `skipped_no_target` forever and frontend filters would never see the
	 * link. The auto-attach makes the bridge self-heal on the first sync.
	 *
	 * The third return value of the array signals to the caller whether an
	 * auto-attach happened, for sync-log context.
	 *
	 * @return array{int, string, bool}  [target_id, resolution_method, auto_attached]
	 *                                   resolution_method ∈ 'relation_row' | 'cct_single_post_id'
	 *                                                       | 'fallback_single_page' | 'none'
	 */
	public function resolve_target_id( array $config, $source_target, $source_id, array $source_data ) {

		$link = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
		$type = isset( $link['type'] ) ? (string) $link['type'] : 'je_relation';

		if ( 'cct_single_post_id' === $type ) {
			$id = isset( $source_data['cct_single_post_id'] ) ? absint( $source_data['cct_single_post_id'] ) : 0;
			return array( $id, $id ? 'cct_single_post_id' : 'none', false );
		}

		$relation_id = isset( $link['relation_id'] ) ? (string) $link['relation_id'] : '';
		if ( '' === $relation_id ) {
			return array( 0, 'none', false );
		}

		$attacher = new JEDB_Relation_Attacher();
		$relation = $attacher->get_relation_object( $relation_id );
		if ( ! $relation ) {
			return array( 0, 'none', false );
		}

		$cct_slug = 0 === strpos( $source_target, 'cct::' ) ? substr( $source_target, 5 ) : '';
		if ( '' === $cct_slug ) {
			return array( 0, 'none', false );
		}

		$declared_side = isset( $link['side'] ) ? (string) $link['side'] : 'auto';
		if ( in_array( $declared_side, array( 'parent', 'child' ), true ) ) {
			$source_side = $declared_side;
		} else {
			$source_side = $attacher->determine_side( $relation_id, $cct_slug );
		}

		if ( 'none' === $source_side ) {
			return array( 0, 'none', false );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jet_rel_' . absint( $relation_id );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			return array( 0, 'none', false );
		}

		if ( 'parent' === $source_side ) {
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT child_object_id FROM `{$table}` WHERE parent_object_id = %d ORDER BY _ID DESC LIMIT 1", absint( $source_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		} else {
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT parent_object_id FROM `{$table}` WHERE child_object_id = %d ORDER BY _ID DESC LIMIT 1", absint( $source_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		}

		if ( $row ) {
			return array( absint( $row ), 'relation_row', false );
		}

		$fallback_enabled = ! isset( $link['fallback_to_single_page'] ) || ! empty( $link['fallback_to_single_page'] );
		if ( ! $fallback_enabled ) {
			return array( 0, 'none', false );
		}

		$single_post_id = isset( $source_data['cct_single_post_id'] ) ? absint( $source_data['cct_single_post_id'] ) : 0;
		if ( ! $single_post_id ) {
			return array( 0, 'none', false );
		}

		if ( ! $this->verify_single_post_matches_relation( $relation, $source_side, $single_post_id ) ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Flattener] cct_single_post_id fallback rejected — post type mismatch', 'warning', array(
					'relation_id'   => $relation_id,
					'source_id'     => $source_id,
					'single_post_id' => $single_post_id,
				) );
			}
			return array( 0, 'none', false );
		}

		$auto_attach = ! isset( $link['auto_attach_relation'] ) || ! empty( $link['auto_attach_relation'] );
		$attached    = false;

		if ( $auto_attach ) {
			$parent_id = ( 'parent' === $source_side ) ? absint( $source_id )    : $single_post_id;
			$child_id  = ( 'parent' === $source_side ) ? $single_post_id          : absint( $source_id );

			$result = $attacher->attach( $relation_id, $parent_id, $child_id );
			$attached = ( true === $result || 'exists' === $result );

			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Flattener] auto-attached JE relation via cct_single_post_id fallback (L-021 self-heal)', 'info', array(
					'relation_id' => $relation_id,
					'cct_slug'    => $cct_slug,
					'side'        => $source_side,
					'parent_id'   => $parent_id,
					'child_id'    => $child_id,
					'result'      => $result,
				) );
			}
		}

		return array( $single_post_id, 'fallback_single_page', $attached );
	}

	/**
	 * Verify a candidate `cct_single_post_id` value points to a post whose
	 * type matches the relation's other endpoint (i.e. NOT the source CCT's
	 * side). Prevents the fallback from incorrectly attaching, e.g., a
	 * `story_bricks` post to a `mosaics_data → product` relation.
	 *
	 * @param object $relation     JE relation instance.
	 * @param string $source_side  'parent' | 'child' — which side the source CCT is on.
	 * @param int    $post_id
	 * @return bool
	 */
	private function verify_single_post_matches_relation( $relation, $source_side, $post_id ) {

		if ( ! $relation || ! method_exists( $relation, 'get_args' ) ) {
			return false;
		}

		$args  = $relation->get_args();
		$other = ( 'parent' === $source_side )
			? ( isset( $args['child_object'] )  ? (string) $args['child_object']  : '' )
			: ( isset( $args['parent_object'] ) ? (string) $args['parent_object'] : '' );

		if ( '' === $other ) {
			return false;
		}

		$parsed = JEDB_Discovery::instance()->parse_relation_object( $other );

		if ( 'posts' !== $parsed['type'] ) {
			return false;
		}

		$post = get_post( absint( $post_id ) );
		return $post && $post->post_type === $parsed['slug'];
	}

	/* -----------------------------------------------------------------------
	 * Helpers
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
			'direction'     => 'push',
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
