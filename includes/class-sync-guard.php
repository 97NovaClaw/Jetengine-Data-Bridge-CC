<?php
/**
 * Sync Guard — centralized loop prevention for every PUSH/PULL operation.
 *
 * Without this, a CCT save → PUSH writes the bridged Woo product → that
 * product save fires our reverse PULL → which writes the CCT row again →
 * which fires PUSH again → and so on until WP runs out of memory or PHP
 * tracks down recursion. Two complementary defenses:
 *
 *   1. Per-request static lock keyed on
 *      "{direction}:{source_target}:{source_id}:{target_target}:{target_id}".
 *      An in-flight write whose key is already locked returns false from
 *      acquire() and the caller bails. This catches every same-request
 *      cycle in O(1).
 *
 *   2. Transient lock with the same key, 30-second TTL. Catches the
 *      cross-request case (Ajax save followed by REST callback fired
 *      from the user's response of the same Ajax). Transient is opaque;
 *      we don't need to read the value, only the existence.
 *
 * The `origin` tag travels with each acquire so debug logs read like:
 *
 *   [push] cct::mosaics_data#7 → posts::product#392  (origin=admin_save)
 *   [pull] posts::product#392 → cct::mosaics_data#7  (skipped: locked, origin=jedb_push)
 *
 * Phase 3 uses only the forward (push) direction. Phase 3.5 adds the
 * reverse (pull) hooks; the same guard catches both.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Sync_Guard {

	const TRANSIENT_PREFIX = 'jedb_lock_';
	const TRANSIENT_TTL    = 30;

	/** @var JEDB_Sync_Guard|null */
	private static $instance = null;

	/**
	 * Per-request locks. Key → origin tag (string).
	 *
	 * @var array<string,string>
	 */
	private $locks = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Try to acquire the lock for one sync operation.
	 *
	 * @param string $direction      'push' | 'pull'
	 * @param string $source_target  e.g. 'cct::mosaics_data'
	 * @param mixed  $source_id      Source record's PK
	 * @param string $target_target  e.g. 'posts::product'
	 * @param mixed  $target_id      Target record's PK (may be 0 / null
	 *                               when not yet known — a "create" path)
	 * @param string $origin         Free-form caller tag for debug logs.
	 *
	 * @return bool  true → lock acquired, caller MUST release(); false → already
	 *               locked, caller MUST bail (cycle detected).
	 */
	public function acquire( $direction, $source_target, $source_id, $target_target, $target_id, $origin = '' ) {

		$key = $this->build_key( $direction, $source_target, $source_id, $target_target, $target_id );

		if ( isset( $this->locks[ $key ] ) ) {
			$this->log_skip( $key, $origin, 'in-request' );
			return false;
		}

		if ( false !== get_transient( self::TRANSIENT_PREFIX . $key ) ) {
			$this->log_skip( $key, $origin, 'cross-request' );
			return false;
		}

		$this->locks[ $key ] = (string) $origin;
		set_transient( self::TRANSIENT_PREFIX . $key, (string) $origin, self::TRANSIENT_TTL );

		do_action( 'jedb/sync/lock_acquired', $direction, $source_target, $source_id, $target_target, $target_id, $origin );

		return true;
	}

	/**
	 * Release a previously-acquired lock. Idempotent — releasing a key that
	 * isn't locked is a no-op.
	 */
	public function release( $direction, $source_target, $source_id, $target_target, $target_id ) {

		$key = $this->build_key( $direction, $source_target, $source_id, $target_target, $target_id );

		unset( $this->locks[ $key ] );
		delete_transient( self::TRANSIENT_PREFIX . $key );

		do_action( 'jedb/sync/lock_released', $direction, $source_target, $source_id, $target_target, $target_id );
	}

	/**
	 * Helper: run a closure inside an acquired lock. The closure receives
	 * the guard instance so it can short-circuit early if needed. If the
	 * lock can't be acquired, the closure is NOT called and the method
	 * returns null.
	 *
	 * @param array    $coords  ['direction','source_target','source_id','target_target','target_id','origin']
	 * @param callable $fn      function( JEDB_Sync_Guard $guard ): mixed
	 * @return mixed|null
	 */
	public function with_lock( array $coords, callable $fn ) {

		$direction     = isset( $coords['direction'] )     ? (string) $coords['direction']     : 'push';
		$source_target = isset( $coords['source_target'] ) ? (string) $coords['source_target'] : '';
		$source_id     = isset( $coords['source_id'] )     ? $coords['source_id']              : 0;
		$target_target = isset( $coords['target_target'] ) ? (string) $coords['target_target'] : '';
		$target_id     = isset( $coords['target_id'] )     ? $coords['target_id']              : 0;
		$origin        = isset( $coords['origin'] )        ? (string) $coords['origin']        : '';

		if ( ! $this->acquire( $direction, $source_target, $source_id, $target_target, $target_id, $origin ) ) {
			return null;
		}

		try {
			return $fn( $this );
		} finally {
			$this->release( $direction, $source_target, $source_id, $target_target, $target_id );
		}
	}

	/**
	 * Read-only check without acquiring. Used by the reverse-direction
	 * engine to decide whether a save event came FROM us (don't react)
	 * or from the user (do react).
	 */
	public function is_locked( $direction, $source_target, $source_id, $target_target, $target_id ) {

		$key = $this->build_key( $direction, $source_target, $source_id, $target_target, $target_id );

		if ( isset( $this->locks[ $key ] ) ) {
			return true;
		}

		return false !== get_transient( self::TRANSIENT_PREFIX . $key );
	}

	/**
	 * Wildcard variant: returns true if ANY lock exists for the given
	 * source record (regardless of direction or target). Cheaper guard
	 * for hot paths that just need to know "is any sync in progress for
	 * this record".
	 */
	public function is_source_locked( $source_target, $source_id ) {
		$prefix = "push:{$source_target}:{$source_id}:";
		foreach ( array_keys( $this->locks ) as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		$prefix = "pull:{$source_target}:{$source_id}:";
		foreach ( array_keys( $this->locks ) as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Internal: build the canonical lock key.
	 */
	private function build_key( $direction, $source_target, $source_id, $target_target, $target_id ) {
		return sprintf(
			'%s:%s:%s:%s:%s',
			$direction,
			$source_target,
			(string) $source_id,
			$target_target,
			(string) $target_id
		);
	}

	private function log_skip( $key, $origin, $type ) {
		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( '[Sync_Guard] skip — already locked', 'debug', array(
				'key'    => $key,
				'origin' => $origin,
				'type'   => $type,
			) );
		}
	}
}
