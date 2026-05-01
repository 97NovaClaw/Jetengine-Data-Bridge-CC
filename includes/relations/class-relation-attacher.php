<?php
/**
 * Relation Attacher — direct-SQL writer for JE relation tables.
 *
 * Implements the verified contract from L-014:
 *   - INSERT against `{prefix}jet_rel_{id}` with rel_id (string) + parent_rel
 *     + parent_object_id + child_object_id; `created` defaults via DB.
 *   - Idempotent: pre-insert duplicate-check makes re-attaches a no-op.
 *   - Cardinality-aware: `one_to_one` and `one_to_many` clear existing rows
 *     on the appropriate side before insert; `many_to_many` appends.
 *
 * RI's `class-transaction-processor.php` is the canonical reference for this
 * flow (see L-014 update). We extracted the write logic into a dedicated
 * class so Phase 4 (Bridge meta box on the WC product side) can reuse it
 * verbatim — the same direct-SQL pattern, just called from a different
 * save hook.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Relation_Attacher {

	/* -----------------------------------------------------------------------
	 * Public API
	 * -------------------------------------------------------------------- */

	/**
	 * Attach a single relation row between two records.
	 *
	 * @param int|string $relation_id The JE relation's ID.
	 * @param int        $parent_id   The parent record's primary key.
	 * @param int        $child_id    The child record's primary key.
	 * @return bool|string  true on insert; 'exists' if already connected;
	 *                      false on failure.
	 */
	public function attach( $relation_id, $parent_id, $child_id ) {

		global $wpdb;

		$relation_id = (string) $relation_id;
		$parent_id   = absint( $parent_id );
		$child_id    = absint( $child_id );

		if ( '' === $relation_id || ! $parent_id || ! $child_id ) {
			$this->log( 'attach: invalid arguments', 'error', array(
				'relation_id' => $relation_id,
				'parent_id'   => $parent_id,
				'child_id'    => $child_id,
			) );
			return false;
		}

		$table = $this->table_name( $relation_id );

		if ( ! $this->table_exists( $table ) ) {
			$this->log( 'attach: relation table missing', 'error', array(
				'relation_id' => $relation_id,
				'table'       => $table,
			) );
			return false;
		}

		if ( $this->relation_exists( $relation_id, $parent_id, $child_id ) ) {
			$this->log( 'attach: connection already exists, returning idempotent ok', 'debug', array(
				'relation_id' => $relation_id,
				'parent_id'   => $parent_id,
				'child_id'    => $child_id,
			) );
			return 'exists';
		}

		$relation_object = $this->get_relation_object( $relation_id );
		$type            = 'one_to_many';
		$parent_rel      = null;

		if ( $relation_object && method_exists( $relation_object, 'get_args' ) ) {
			$args       = $relation_object->get_args();
			$type       = isset( $args['type'] ) ? (string) $args['type'] : 'one_to_many';
			$parent_rel = isset( $args['parent_rel'] ) ? absint( $args['parent_rel'] ) : null;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'rel_id'           => $relation_id,
				'parent_rel'       => $parent_rel,
				'parent_object_id' => $parent_id,
				'child_object_id'  => $child_id,
			),
			array( '%s', '%d', '%d', '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $result ) {
			$this->log( 'attach: insert failed', 'error', array(
				'relation_id' => $relation_id,
				'parent_id'   => $parent_id,
				'child_id'    => $child_id,
				'wpdb_error'  => $wpdb->last_error,
			) );
			return false;
		}

		$this->log( 'attach: connection inserted', 'info', array(
			'relation_id' => $relation_id,
			'type'        => $type,
			'parent_id'   => $parent_id,
			'child_id'    => $child_id,
			'inserted_id' => (int) $wpdb->insert_id,
		) );

		do_action( 'jedb/relations/attached', $relation_id, $parent_id, $child_id, $type );

		return true;
	}

	/**
	 * Detach a specific connection.
	 *
	 * @return int|false  Number of rows affected, or false on failure.
	 */
	public function detach( $relation_id, $parent_id, $child_id ) {

		global $wpdb;

		$relation_id = (string) $relation_id;
		$parent_id   = absint( $parent_id );
		$child_id    = absint( $child_id );

		$table = $this->table_name( $relation_id );
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$deleted = $wpdb->delete(
			$table,
			array(
				'parent_object_id' => $parent_id,
				'child_object_id'  => $child_id,
			),
			array( '%d', '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false !== $deleted ) {
			do_action( 'jedb/relations/detached', $relation_id, $parent_id, $child_id );
		}

		return $deleted;
	}

	/**
	 * Clear every connection on the given side for the given record. Used by
	 * 1:1 and 1:M relations before inserting a new connection so the editor's
	 * picker behaves as "replace" rather than "append".
	 *
	 * @param int|string $relation_id
	 * @param int        $item_id
	 * @param string     $side  'parent' or 'child' (the side $item_id is on).
	 * @return int|false  Number of rows deleted, or false on failure.
	 */
	public function clear_existing_for_side( $relation_id, $item_id, $side ) {

		global $wpdb;

		$relation_id = (string) $relation_id;
		$item_id     = absint( $item_id );
		$side        = ( 'parent' === $side ) ? 'parent_object_id' : 'child_object_id';

		$table = $this->table_name( $relation_id );
		if ( ! $this->table_exists( $table ) || ! $item_id ) {
			return false;
		}

		$deleted = $wpdb->delete(
			$table,
			array( $side => $item_id ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->log( 'clear_existing_for_side', 'debug', array(
			'relation_id' => $relation_id,
			'side'        => $side,
			'item_id'     => $item_id,
			'deleted'     => $deleted,
		) );

		return $deleted;
	}

	/**
	 * Pre-insert duplicate check. Returns true if a (parent, child) row
	 * already exists in this relation's table.
	 */
	public function relation_exists( $relation_id, $parent_id, $child_id ) {

		global $wpdb;

		$table = $this->table_name( $relation_id );
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT _ID FROM `{$table}` WHERE parent_object_id = %d AND child_object_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $parent_id ),
				absint( $child_id )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ! empty( $found );
	}

	/**
	 * Resolve a JetEngine relation object by ID via the JE API.
	 *
	 * @return object|null
	 */
	public function get_relation_object( $relation_id ) {

		if ( ! function_exists( 'jet_engine' ) || ! jet_engine() || ! isset( jet_engine()->relations ) ) {
			return null;
		}

		$relations = jet_engine()->relations->get_active_relations();
		if ( ! is_array( $relations ) ) {
			return null;
		}

		return isset( $relations[ $relation_id ] ) ? $relations[ $relation_id ] : null;
	}

	/**
	 * Determine whether a CCT slug is on the parent or child side of a
	 * relation. Used by the transaction processor to decide attach direction
	 * and which side to clear for 1:1/1:M.
	 *
	 * @return string  'parent' | 'child' | 'none'
	 */
	public function determine_side( $relation_id, $cct_slug ) {

		$relation = $this->get_relation_object( $relation_id );
		if ( ! $relation || ! method_exists( $relation, 'get_args' ) ) {
			return 'none';
		}

		$args   = $relation->get_args();
		$parent = isset( $args['parent_object'] ) ? (string) $args['parent_object'] : '';
		$child  = isset( $args['child_object']  ) ? (string) $args['child_object']  : '';

		$discovery = JEDB_Discovery::instance();
		$parent_p  = $discovery->parse_relation_object( $parent );
		$child_p   = $discovery->parse_relation_object( $child );

		if ( 'cct' === $parent_p['type'] && $parent_p['slug'] === $cct_slug ) {
			return 'parent';
		}
		if ( 'cct' === $child_p['type'] && $child_p['slug'] === $cct_slug ) {
			return 'child';
		}
		return 'none';
	}

	/**
	 * Returns 'parent' / 'child' / 'none' for a posts::{type} object on a
	 * relation. Used by Phase 4's product-side processor.
	 */
	public function determine_side_for_post_type( $relation_id, $post_type ) {

		$relation = $this->get_relation_object( $relation_id );
		if ( ! $relation || ! method_exists( $relation, 'get_args' ) ) {
			return 'none';
		}

		$args   = $relation->get_args();
		$parent = isset( $args['parent_object'] ) ? (string) $args['parent_object'] : '';
		$child  = isset( $args['child_object']  ) ? (string) $args['child_object']  : '';

		$discovery = JEDB_Discovery::instance();
		$parent_p  = $discovery->parse_relation_object( $parent );
		$child_p   = $discovery->parse_relation_object( $child );

		if ( 'posts' === $parent_p['type'] && $parent_p['slug'] === $post_type ) {
			return 'parent';
		}
		if ( 'posts' === $child_p['type'] && $child_p['slug'] === $post_type ) {
			return 'child';
		}
		return 'none';
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	private function table_name( $relation_id ) {
		global $wpdb;
		return $wpdb->prefix . 'jet_rel_' . absint( $relation_id );
	}

	private function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ! empty( $found );
	}

	private function log( $message, $level = 'info', array $context = array() ) {
		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( '[Attacher] ' . $message, $level, $context );
		}
	}
}
