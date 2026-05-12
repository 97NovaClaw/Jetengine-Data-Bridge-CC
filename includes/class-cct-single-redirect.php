<?php
/**
 * CCT-single → linked-post redirect shim (Phase 4 / Day 3, per §4.6).
 *
 * Each flatten config carries a `cct_single_redirect` boolean (default false,
 * added in alpha.3 schema extension). When the editor turns it on for a
 * bridge whose direction includes push, this shim 301-redirects the JE
 * CCT-single page URL to the bridge's resolved linked-post permalink.
 *
 * Detection model
 * ----------------
 * JE's "Has Single Page" feature creates a backing WP post for each CCT
 * row (stored as `cct_single_post_id` on the CCT row). The CCT single URL
 * resolves to that backing post via standard WP permalink rules. We catch
 * the visit at `template_redirect` by:
 *
 *   1. Bailing on admin / AJAX / cron / REST / non-singular requests.
 *   2. Bailing if the editor (with manage capability) passed
 *      `?jedb_no_redirect=1` — debug escape hatch per §4.6.
 *   3. Reading the queried post ID, then scanning each enabled bridge
 *      whose `cct_single_redirect=true` and direction includes push:
 *      query that bridge's source CCT table for any row whose
 *      `cct_single_post_id` matches the queried post ID. If found,
 *      that's a CCT single page render — and we have both the source
 *      CCT row's ID and the bridge that governs it.
 *   4. Resolve the bridge's link_via target. If the target post ID
 *      differs from the currently-queried post ID, redirect 301 to
 *      its permalink. If they match (the common "Has Single Page
 *      points at the bridge target" pattern — BBHQ's setup), we
 *      silently no-op since redirecting to ourselves would loop.
 *
 * Why reverse-lookup instead of JE-native detection
 * --------------------------------------------------
 * JE's CCT single template detection varies across JE versions —
 * some versions render through a backing WP post, some through a
 * dedicated rewrite + template. The reverse-lookup approach works
 * whenever JE has populated `cct_single_post_id` (the standard
 * pattern). For JE installs where there's no backing post, the
 * shim is a no-op and we rely on JE's own routing — which is
 * acceptable because the shim's primary use case is BBHQ-style
 * setups where the linked post IS the canonical display.
 *
 * Modal-iframe interaction (post L-027 / alpha.6)
 * ------------------------------------------------
 * The shim hooks `template_redirect`, which fires only on frontend
 * requests. The alpha.6 modal iframe loads JE's admin CCT edit URL
 * (`wp-admin/admin.php?page=jet-cct-{slug}&cct_action=edit`), so the
 * modal flow is completely unaffected. The shim's main consumer is
 * public-storefront visitors who somehow land on the public CCT
 * single URL; editors using the alpha.9 meta box never see a
 * frontend CCT page in their daily workflow.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_CCT_Single_Redirect {

	const QUERY_BYPASS_PARAM = 'jedb_no_redirect';

	/** @var JEDB_CCT_Single_Redirect|null */
	private static $instance = null;

	/**
	 * Per-request memoization. The shim runs at most once per request —
	 * `wp_safe_redirect()` + `exit` terminates the response — but the
	 * `redirected` flag is here for safety in case something hooks into
	 * `template_redirect` and re-enters before exit.
	 *
	 * @var bool
	 */
	private $redirected = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks() {
		// Priority 5 so we run BEFORE most theme-side template_redirect
		// handlers (which often run at the default priority 10) — that
		// way we don't waste cycles loading template files for a page
		// we're about to redirect away from.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 5 );
	}

	/* -----------------------------------------------------------------------
	 * Main entry point
	 * -------------------------------------------------------------------- */

	public function maybe_redirect() {

		if ( $this->redirected ) {
			return;
		}

		// Frontend-only — admin requests, AJAX (admin-ajax), cron,
		// REST, and CLI never serve a CCT single page that an end
		// user would visit.
		if ( is_admin() ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Admin escape hatch: a logged-in user with the JEDB capability
		// can pass `?jedb_no_redirect=1` to bypass the shim and inspect
		// the underlying CCT-single template (or "no single template"
		// 404). The capability check keeps anonymous visitors from
		// trivially circumventing the redirect by adding the query arg.
		if ( $this->has_bypass_query_arg() ) {
			return;
		}

		// Require a singular request — JE's "Has Single Page" feature
		// surfaces CCT singles as backing WP posts, so any CCT-single
		// page render goes through `is_singular()` against the backing
		// post type. Non-singular requests (archives, search, etc.)
		// are out of scope for this shim.
		if ( ! is_singular() ) {
			return;
		}

		$queried_id = (int) get_queried_object_id();
		if ( $queried_id <= 0 ) {
			return;
		}

		// Find any enabled bridge whose source CCT row has
		// `cct_single_post_id` matching this post AND has
		// `cct_single_redirect=true` AND direction includes push.
		$match = $this->find_bridge_for_singular_post( $queried_id );
		if ( ! $match ) {
			return;
		}

		$bridge    = $match['bridge'];
		$source_id = (int) $match['source_id'];

		$target_post_id = $this->resolve_target_post_for_bridge( $bridge, $source_id );
		if ( $target_post_id <= 0 ) {
			return;
		}

		// Loop guard: in the common "Has Single Page points at the
		// bridge target" pattern (BBHQ-style setups), the bridge
		// target IS the queried post. Redirecting to ourselves would
		// loop forever. Silent no-op.
		if ( $target_post_id === $queried_id ) {
			return;
		}

		$target_url = get_permalink( $target_post_id );
		if ( ! $target_url ) {
			return;
		}

		if ( function_exists( 'jedb_log' ) ) {
			jedb_log(
				'[CCT_Single_Redirect] redirecting CCT-single page → linked post',
				'debug',
				array(
					'bridge_id'      => isset( $bridge['id'] ) ? (int) $bridge['id'] : 0,
					'source_id'      => $source_id,
					'queried_id'     => $queried_id,
					'target_post_id' => $target_post_id,
				)
			);
		}

		$this->redirected = true;
		wp_safe_redirect( $target_url, 301 );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Detection helpers
	 * -------------------------------------------------------------------- */

	/**
	 * Walk enabled bridges that opt into the redirect, and for each, query
	 * its source CCT table for a row whose `cct_single_post_id` matches
	 * the given post ID. First match wins.
	 *
	 * Multiple bridges can theoretically target the same source CCT; the
	 * first matching bridge (in `priority`-then-id order from
	 * JEDB_Flatten_Config_Manager::get_all`) gets the redirect. Editors
	 * who configure conflicting bridges intentionally can resolve the
	 * ambiguity via the `priority` field.
	 *
	 * @param int $post_id
	 * @return array{bridge:array,source_id:int}|null
	 */
	private function find_bridge_for_singular_post( $post_id ) {

		if ( ! class_exists( 'JEDB_Flatten_Config_Manager' ) ) {
			return null;
		}

		$bridges = JEDB_Flatten_Config_Manager::instance()->get_all( array( 'enabled' => 1 ) );
		if ( empty( $bridges ) ) {
			return null;
		}

		global $wpdb;

		// Cache table-has-column checks across the loop. Querying
		// `DESCRIBE` once per CCT table per request is fine; this
		// avoids re-checking the same table multiple times when
		// several bridges share the same source CCT.
		$column_cache = array();

		foreach ( $bridges as $bridge ) {

			$config = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();

			if ( empty( $config['cct_single_redirect'] ) ) {
				continue;
			}

			$direction = isset( $bridge['direction'] ) ? (string) $bridge['direction'] : 'push';
			if ( ! in_array( $direction, array( 'push', 'bidirectional' ), true ) ) {
				// Direction guard (§4.6): for pull-only bridges, the CCT
				// is the display surface and the redirect would invert
				// the intended flow.
				continue;
			}

			$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
			if ( '' === $source_target || 0 !== strpos( $source_target, 'cct::' ) ) {
				continue;
			}

			$cct_slug = substr( $source_target, 5 );
			$table    = $wpdb->prefix . 'jet_cct_' . $cct_slug;

			if ( ! isset( $column_cache[ $table ] ) ) {
				$column_cache[ $table ] = $this->table_has_cct_single_post_id( $table );
			}
			if ( ! $column_cache[ $table ] ) {
				continue;
			}

			$found_source_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					"SELECT _ID FROM `{$table}` WHERE cct_single_post_id = %d LIMIT 1",
					$post_id
				)
			);

			if ( $found_source_id > 0 ) {
				return array(
					'bridge'    => $bridge,
					'source_id' => $found_source_id,
				);
			}
		}

		return null;
	}

	/**
	 * Resolve the bridge's linked target post ID for the given source row.
	 * Reuses JEDB_Flattener::resolve_target_id() so the shim and the engine
	 * stay in lock-step — any improvement to the resolution logic (e.g.
	 * L-021 self-heal) automatically benefits the shim.
	 *
	 * @param array $bridge
	 * @param int   $source_id
	 * @return int  Post ID, or 0 if unresolvable.
	 */
	private function resolve_target_post_for_bridge( $bridge, $source_id ) {

		if ( ! class_exists( 'JEDB_Flattener' ) || ! class_exists( 'JEDB_Target_Registry' ) ) {
			return 0;
		}

		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';

		$source_adapter = JEDB_Target_Registry::instance()->get( $source_target );
		if ( ! $source_adapter ) {
			return 0;
		}

		// Use the fresh-read path (L-030) so the shim doesn't act on a
		// stale cached CCT row. Frontend visits are rare enough relative
		// to admin reads that the direct-SQL cost is irrelevant.
		$source_data = method_exists( $source_adapter, 'get_fresh' )
			? $source_adapter->get_fresh( $source_id )
			: $source_adapter->get( $source_id );

		if ( ! is_array( $source_data ) ) {
			return 0;
		}

		// resolve_target_id returns [ target_id, resolution_method, auto_attached ]
		$result = JEDB_Flattener::instance()->resolve_target_id(
			$config,
			$source_target,
			$source_id,
			$source_data
		);

		$target_id = isset( $result[0] ) ? (int) $result[0] : 0;
		return $target_id > 0 ? $target_id : 0;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Truthy when the editor has the bypass query arg set AND the
	 * required capability. Anonymous visitors can't bypass.
	 */
	private function has_bypass_query_arg() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_BYPASS_PARAM ] ) ) {
			return false;
		}
		// Constant defined in je-data-bridge-cc.php
		$cap = defined( 'JEDB_CAPABILITY' ) ? JEDB_CAPABILITY : 'manage_options';
		return current_user_can( $cap );
	}

	/**
	 * Verify the given table exists AND has a `cct_single_post_id` column.
	 * Returns false on any structural mismatch (e.g. CCT was renamed,
	 * "Has Single Page" was disabled and the column was dropped, etc.).
	 *
	 * @param string $table  Fully-prefixed table name.
	 * @return bool
	 */
	private function table_has_cct_single_post_id( $table ) {

		global $wpdb;

		// Defensive: confirm the table exists. SHOW TABLES LIKE is
		// cheap and safer than blindly DESCRIBE'ing a missing table
		// (which logs PHP warnings on some setups).
		$table_safe = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_safe}'" );
		if ( ! $exists ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! is_array( $columns ) ) {
			return false;
		}

		return in_array( 'cct_single_post_id', $columns, true );
	}
}
