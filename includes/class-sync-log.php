<?php
/**
 * Sync Log — append-only audit trail for every PUSH/PULL operation.
 *
 * Backed by `{prefix}jedb_sync_log` (created by JEDB_Config_DB::install()).
 * Every flatten apply writes one row regardless of outcome — success,
 * partial, errored, skipped_condition, skipped_error, skipped_locked, noop
 * (per BUILD-PLAN §4.9). The Debug tab reads the most recent N rows; the
 * Phase 5 retention setting prunes old rows on a daily cron.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Sync_Log {

	const STATUS_SUCCESS           = 'success';
	const STATUS_PARTIAL           = 'partial';
	const STATUS_ERRORED           = 'errored';
	const STATUS_SKIPPED_CONDITION = 'skipped_condition';
	const STATUS_SKIPPED_ERROR     = 'skipped_error';
	const STATUS_SKIPPED_LOCKED    = 'skipped_locked';
	const STATUS_SKIPPED_NO_TARGET = 'skipped_no_target';
	const STATUS_NOOP              = 'noop';

	/** @var JEDB_Sync_Log|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * @return string  Fully prefixed table name.
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'jedb_sync_log';
	}

	/**
	 * Write one row.
	 *
	 * @param array $row  All fields optional; sensible defaults applied.
	 *                    'direction'     => 'push' | 'pull'
	 *                    'source_target' => string
	 *                    'source_id'     => mixed (cast to string, 64 chars max)
	 *                    'target_target' => string
	 *                    'target_id'     => mixed
	 *                    'origin'        => string  (free-form caller tag)
	 *                    'status'        => one of the STATUS_* constants
	 *                    'message'       => string
	 *                    'context'       => array  (json-encoded into context_json)
	 *
	 * @return int|false  Insert ID on success, false on failure.
	 */
	public function record( array $row ) {

		global $wpdb;

		$row = wp_parse_args(
			$row,
			array(
				'direction'     => '',
				'source_target' => '',
				'source_id'     => '',
				'target_target' => '',
				'target_id'     => '',
				'origin'        => '',
				'status'        => self::STATUS_SUCCESS,
				'message'       => '',
				'context'       => array(),
			)
		);

		$payload = array(
			'created_at'    => current_time( 'mysql', true ),
			'direction'     => substr( (string) $row['direction'],     0, 20 ),
			'source_target' => substr( (string) $row['source_target'], 0, 150 ),
			'source_id'     => substr( (string) $row['source_id'],     0, 64 ),
			'target_target' => substr( (string) $row['target_target'], 0, 150 ),
			'target_id'     => substr( (string) $row['target_id'],     0, 64 ),
			'origin'        => substr( (string) $row['origin'],        0, 50 ),
			'status'        => substr( (string) $row['status'],        0, 20 ),
			'message'       => (string) $row['message'],
			'context_json'  => is_array( $row['context'] ) && ! empty( $row['context'] )
				? wp_json_encode( $row['context'] )
				: null,
		);

		$result = $wpdb->insert(
			$this->table(),
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $result ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Sync_Log] insert failed', 'error', array(
					'wpdb_error' => $wpdb->last_error,
					'payload'    => $payload,
				) );
			}
			return false;
		}

		do_action( 'jedb/sync_log/recorded', (int) $wpdb->insert_id, $payload );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Convenience for the most-common case.
	 */
	public function success( $direction, $source_target, $source_id, $target_target, $target_id, $origin = '', $message = '', array $context = array() ) {
		return $this->record( array(
			'direction'     => $direction,
			'source_target' => $source_target,
			'source_id'     => $source_id,
			'target_target' => $target_target,
			'target_id'     => $target_id,
			'origin'        => $origin,
			'status'        => self::STATUS_SUCCESS,
			'message'       => $message,
			'context'       => $context,
		) );
	}

	/**
	 * Read the most-recent N rows (newest first). For the Debug tab.
	 *
	 * @param array $args  ['per_page' => int, 'page' => int, 'status' => string|null,
	 *                      'source_target' => string|null, 'target_target' => string|null]
	 * @return array<int,array>
	 */
	public function recent( array $args = array() ) {

		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'per_page'      => 100,
				'page'          => 1,
				'status'        => null,
				'source_target' => null,
				'target_target' => null,
			)
		);

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$where  = array();
		$params = array();

		if ( null !== $args['status'] && '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( null !== $args['source_target'] && '' !== $args['source_target'] ) {
			$where[]  = 'source_target = %s';
			$params[] = (string) $args['source_target'];
		}
		if ( null !== $args['target_target'] && '' !== $args['target_target'] ) {
			$where[]  = 'target_target = %s';
			$params[] = (string) $args['target_target'];
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = "SELECT * FROM `{$this->table()}`{$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[]  = $per_page;
		$params[]  = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$r ) {
			if ( ! empty( $r['context_json'] ) ) {
				$decoded     = json_decode( (string) $r['context_json'], true );
				$r['context'] = is_array( $decoded ) ? $decoded : array();
			} else {
				$r['context'] = array();
			}
		}
		unset( $r );

		return $rows;
	}

	/**
	 * Total row count (for retention UI / pagination).
	 */
	public function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table()}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Drop everything older than N days. Called from a Phase 5 cron.
	 *
	 * @param int $days
	 * @return int  Rows deleted.
	 */
	public function purge_older_than( $days ) {

		global $wpdb;

		$days = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			"DELETE FROM `{$this->table()}` WHERE created_at < %s",
			$cutoff
		) );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
