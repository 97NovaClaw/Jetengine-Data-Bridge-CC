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

		$bridges = JEDB_Flatten_Config_Manager::instance()->get_all( array(
			'enabled'   => 1,
			'direction' => 'push',
		) );

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

		$target_id = $this->resolve_target_id( $config, $source_target, $source_id, $source_data );

		if ( ! $target_id ) {
			$this->log_status( $bridge, $source_id, '', JEDB_Sync_Log::STATUS_SKIPPED_NO_TARGET, $origin_tag, 'no linked target — Phase 3.5 will optionally auto-create', array(
				'link_via' => isset( $config['link_via'] ) ? $config['link_via'] : null,
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

		$dsl = isset( $config['condition'] ) ? (string) $config['condition'] : '';
		if ( '' !== trim( $dsl ) ) {
			$ok = JEDB_Condition_Evaluator::instance()->evaluate( $dsl, $context );
			if ( ! $ok ) {
				$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_CONDITION, $origin_tag, 'condition returned false', array( 'dsl' => $dsl ) );
				return JEDB_Sync_Log::STATUS_SKIPPED_CONDITION;
			}
		}

		$snippet_slug = isset( $config['condition_snippet'] ) ? (string) $config['condition_snippet'] : '';
		if ( '' !== $snippet_slug ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_ERROR, $origin_tag, 'condition_snippet ignored — Snippet runtime ships in Phase 5b', array( 'snippet' => $snippet_slug ) );
			return JEDB_Sync_Log::STATUS_SKIPPED_ERROR;
		}

		$mappings = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : array();
		if ( empty( $mappings ) ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_NOOP, $origin_tag, 'bridge has no mappings', array() );
			return JEDB_Sync_Log::STATUS_NOOP;
		}

		$guard = JEDB_Sync_Guard::instance();
		if ( ! $guard->acquire( 'push', $source_target, $source_id, $target_target, $target_id, $origin_tag ) ) {
			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_SKIPPED_LOCKED, $origin_tag, 'sync_guard already locked — cycle detected', array() );
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
				$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_NOOP, $origin_tag, 'every mapped value already matched target — nothing to write', array(
					'fields_examined' => count( $per_field ),
				) );
				return JEDB_Sync_Log::STATUS_NOOP;
			}

			$ok = $target_adapter->update( $target_id, $payload );

			$status  = $ok ? JEDB_Sync_Log::STATUS_SUCCESS : JEDB_Sync_Log::STATUS_ERRORED;
			$message = $ok ? sprintf( 'wrote %d field(s)', count( $payload ) ) : 'target adapter update returned false';

			$this->log_status( $bridge, $source_id, $target_id, $status, $origin_tag, $message, array(
				'fields'      => array_keys( $payload ),
				'noop_fields' => $noop_count,
				'per_field'   => $per_field,
			) );

			return $status;

		} catch ( \Throwable $e ) {

			$this->log_status( $bridge, $source_id, $target_id, JEDB_Sync_Log::STATUS_ERRORED, $origin_tag, $e->getMessage(), array(
				'file' => $e->getFile(),
				'line' => $e->getLine(),
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
	 * @return int
	 */
	public function resolve_target_id( array $config, $source_target, $source_id, array $source_data ) {

		$link = isset( $config['link_via'] ) && is_array( $config['link_via'] ) ? $config['link_via'] : array();
		$type = isset( $link['type'] ) ? (string) $link['type'] : 'je_relation';

		if ( 'cct_single_post_id' === $type ) {
			return isset( $source_data['cct_single_post_id'] ) ? absint( $source_data['cct_single_post_id'] ) : 0;
		}

		$relation_id = isset( $link['relation_id'] ) ? (string) $link['relation_id'] : '';
		if ( '' === $relation_id ) {
			return 0;
		}

		$attacher = new JEDB_Relation_Attacher();
		$relation = $attacher->get_relation_object( $relation_id );
		if ( ! $relation ) {
			return 0;
		}

		$cct_slug = 0 === strpos( $source_target, 'cct::' ) ? substr( $source_target, 5 ) : '';
		if ( '' === $cct_slug ) {
			return 0;
		}

		$declared_side = isset( $link['side'] ) ? (string) $link['side'] : 'auto';
		if ( in_array( $declared_side, array( 'parent', 'child' ), true ) ) {
			$source_side = $declared_side;
		} else {
			$source_side = $attacher->determine_side( $relation_id, $cct_slug );
		}

		if ( 'none' === $source_side ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jet_rel_' . absint( $relation_id );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			return 0;
		}

		if ( 'parent' === $source_side ) {
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT child_object_id FROM `{$table}` WHERE parent_object_id = %d ORDER BY _ID DESC LIMIT 1", absint( $source_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		} else {
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT parent_object_id FROM `{$table}` WHERE child_object_id = %d ORDER BY _ID DESC LIMIT 1", absint( $source_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		}

		return $row ? absint( $row ) : 0;
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
