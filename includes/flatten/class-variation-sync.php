<?php
/**
 * Variation Sync — Phase 4c-A data-driven variation reconciler (§4.14).
 *
 * Maps source-CCT REPEATER rows to managed WooCommerce variations on the
 * linked product. Runs as phase 3 of the forward push (mappings →
 * taxonomies → variations), invoked by `JEDB_Flattener::apply_bridge()`
 * inside the push sync-guard lock.
 *
 * NOT a revival of the retired alpha.13 `JEDB_Variation_Reconciler`
 * (L-032): that engine was CONFIG-driven — the bridge config authored
 * variation rules via a `show_when` DSL and every CCT row got the same
 * structure. This engine is DATA-driven — each CCT row's repeater data IS
 * the variation content; the bridge's `variation_mappings[]` block only
 * declares the structural mapping once (see
 * `JEDB_Flatten_Config_Manager::default_variation_mapping()` and
 * BUILD-PLAN §4.14.1 for the full framing).
 *
 * Key behaviors (decision references):
 *   - Row identity: `_jedb_row_id` subfield ↔ variation `META_VARIATION_SLUG`
 *     meta (§4.14.5, amended 2026-07-12 — real subfield, not injected JSON).
 *     Empty ids are filled with UUIDs on first reconcile via direct SQL
 *     (L-030 pattern; JE fires no hooks on direct writes per L-022).
 *   - Always-variable (D-30): a product with ≥1 enabled repeater row is
 *     forced to `variable` type. No simple↔variable auto-flip machine.
 *   - Two-attribute strategy (D-31): fixed `attribute_terms` discriminate
 *     the type (physical vs PDF); the per-row `variant_attribute` term
 *     (from `variant_label`) satisfies WC's unique-combination rule for
 *     multiple physical variants. Parent attribute assignments are
 *     maintained automatically so the 2026-07-12 attribute-audit failure
 *     modes can't recur on managed products.
 *   - Managed-only contract (D-32): variations without our tracking meta
 *     are NEVER touched — manual / iframe-created variations coexist.
 *   - Derived size (§4.14.11): when a mapping sets `derived_size_field`,
 *     the first enabled row's non-empty L/W/H become a display string
 *     (`18″ × 18″`) written back to that source-CCT column.
 *
 * Storage format ground truth (DATA-MAP.md "Repeater storage format"):
 * repeater columns hold PHP-SERIALIZED arrays keyed `item-N` (positional,
 * NOT identity), every value is a string (`"true"`/`"false"` switchers,
 * `"yes"`/`"no"` glossary selects, `"407"` media ids, `"2026-07-13"` dates).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Variation_Sync {

	/** @var JEDB_Variation_Sync|null */
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
		// Hide the `_jedb_row_id` system subfield on JE CCT edit screens
		// (§4.14.5 — the field must exist as a real form input to survive
		// JE saves, but editors should never see or touch it).
		add_action( 'admin_head', array( $this, 'maybe_hide_row_id_field' ) );

		// Phase 4c-B (§4.14.8): reverse stock sync. WC fires this ONLY
		// when a variation's stock_quantity actually changed, for BOTH
		// paths that matter: an admin editing stock on the product page
		// AND a customer purchase decrementing it (wc_update_product_stock
		// → data store updated-props). Priority 20 = after WC's own
		// internal listeners.
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_change' ), 20, 1 );
	}

	/* -----------------------------------------------------------------------
	 * Public API — called by JEDB_Flattener::apply_bridge() (phase 3)
	 * -------------------------------------------------------------------- */

	/**
	 * Reconcile managed variations for one bridge + one source row.
	 *
	 * Assumes the caller already resolved the target product and holds the
	 * push sync-guard lock (cascade events from variation saves are
	 * suppressed by the reverse engine's cross-direction check).
	 *
	 * @param array $bridge         Full bridge row (id, config, targets).
	 * @param int   $source_id      Source CCT row _ID.
	 * @param array $source_data    Fresh source row (repeater columns RAW —
	 *                              serialized strings, per adapter contract).
	 * @param int   $target_post_id Linked WC product ID.
	 * @return array Summary for sync_log context.
	 */
	public function reconcile( array $bridge, $source_id, array $source_data, $target_post_id ) {

		$summary = array(
			'ran'          => false,
			'mappings'     => 0,
			'created'      => 0,
			'updated'      => 0,
			'hidden'       => 0,
			'trashed'      => 0,
			'noop'         => 0,
			'errors'       => array(),
			'derived_size' => null,
			'per_row'      => array(),
		);

		$config   = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$mappings = isset( $config['variation_mappings'] ) && is_array( $config['variation_mappings'] ) ? $config['variation_mappings'] : array();
		$mappings = array_values( array_filter( $mappings, static function ( $m ) {
			return is_array( $m ) && ! empty( $m['enabled'] ) && ! empty( $m['source_repeater'] );
		} ) );

		if ( empty( $mappings ) || ! function_exists( 'wc_get_product' ) ) {
			return $summary;
		}

		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
		if ( 'posts::product' !== $target_target ) {
			return $summary;
		}

		$product = wc_get_product( absint( $target_post_id ) );
		if ( ! $product ) {
			$summary['errors'][] = 'target product not found';
			return $summary;
		}

		$bridge_id     = isset( $bridge['id'] ) ? (int) $bridge['id'] : 0;
		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$cct_slug      = 0 === strpos( $source_target, 'cct::' ) ? substr( $source_target, 5 ) : '';
		if ( ! $bridge_id || '' === $cct_slug ) {
			$summary['errors'][] = 'bridge id or cct slug missing';
			return $summary;
		}

		$summary['ran']      = true;
		$summary['mappings'] = count( $mappings );

		// ---- Pass 1: parse repeaters + fill missing row ids ----------------
		$parsed = array(); // mapping index => rows (item-key => row array, ids guaranteed)
		foreach ( $mappings as $mi => $mapping ) {
			$rows = $this->parse_and_ensure_row_ids( $cct_slug, $source_id, $mapping['source_repeater'], $source_data );
			$parsed[ $mi ] = $rows;
		}

		// ---- Pass 2: D-30 always-variable + parent attribute maintenance ---
		$any_enabled = false;
		foreach ( $mappings as $mi => $mapping ) {
			foreach ( $parsed[ $mi ] as $row ) {
				if ( $this->row_enabled( $row, $mapping ) ) { $any_enabled = true; break 2; }
			}
		}

		if ( $any_enabled && 'variable' !== $product->get_type() ) {
			// wp_set_object_terms on product_type is how WC stores type.
			wp_set_object_terms( $target_post_id, 'variable', 'product_type', false );
			// Re-fetch as the correct class for downstream calls.
			$product = wc_get_product( $target_post_id );
			$summary['per_row'][] = 'product type forced to variable (D-30)';
		}

		$this->maintain_parent_attributes( $target_post_id, $mappings, $parsed, $summary );

		// ---- Pass 3: reconcile each row to a managed variation -------------
		$variation_adapter = JEDB_Target_Registry::instance()->get( 'posts::product_variation' );
		if ( ! $variation_adapter ) {
			$summary['errors'][] = 'variation adapter missing';
			return $summary;
		}

		$live_row_ids = array();

		foreach ( $mappings as $mi => $mapping ) {
			foreach ( $parsed[ $mi ] as $item_key => $row ) {

				$row_id = (string) ( $row['_jedb_row_id'] ?? '' );
				if ( '' === $row_id ) {
					// parse_and_ensure_row_ids failed to persist — skip defensively.
					$summary['errors'][] = "row {$item_key} of {$mapping['source_repeater']}: no row id";
					continue;
				}
				$live_row_ids[] = $row_id;

				$enabled  = $this->row_enabled( $row, $mapping );
				$fields   = $this->build_variation_fields( $mapping, $row, $source_data, $enabled );
				$existing = $variation_adapter->find_managed_variation( $target_post_id, $bridge_id, $row_id );

				if ( ! $existing ) {
					if ( ! $enabled ) {
						$summary['noop']++;
						$summary['per_row'][ $row_id ] = 'disabled + no variation — skipped';
						continue;
					}
					$new_id = $variation_adapter->create_for_bridge( $target_post_id, $bridge_id, $row_id, $fields );
					if ( $new_id ) {
						$summary['created']++;
						$summary['per_row'][ $row_id ] = 'created variation #' . $new_id;
					} else {
						$summary['errors'][] = "row {$row_id}: create failed";
					}
					continue;
				}

				// Un-trash if a previously-deleted row came back.
				if ( 'trash' === get_post_status( $existing ) && $enabled ) {
					wp_untrash_post( $existing );
					wp_update_post( array( 'ID' => $existing, 'post_status' => 'publish' ) );
				}

				$ok = $variation_adapter->update( $existing, $fields );
				if ( $ok ) {
					if ( ! $enabled ) {
						$summary['hidden']++;
						$summary['per_row'][ $row_id ] = 'variation #' . $existing . ' soft-hidden (row disabled)';
					} else {
						$summary['updated']++;
						$summary['per_row'][ $row_id ] = 'variation #' . $existing . ' updated';
					}
				} else {
					$summary['errors'][] = "row {$row_id}: update of variation #{$existing} failed";
				}
			}
		}

		// ---- Pass 4: rows deleted from the repeater → delete_policy --------
		$orphans = $this->find_orphan_managed_variations( $target_post_id, $bridge_id, $live_row_ids );
		foreach ( $orphans as $orphan ) {
			$policy = 'trash';
			foreach ( $mappings as $mapping ) {
				if ( in_array( $mapping['delete_policy'], array( 'trash', 'private' ), true ) ) {
					$policy = $mapping['delete_policy'];
					break;
				}
			}
			if ( 'private' === $policy ) {
				wp_update_post( array( 'ID' => $orphan, 'post_status' => 'private' ) );
				$summary['hidden']++;
				$summary['per_row'][] = 'variation #' . $orphan . ' set private (row removed)';
			} else {
				wp_trash_post( $orphan );
				$summary['trashed']++;
				$summary['per_row'][] = 'variation #' . $orphan . ' trashed (row removed)';
			}
		}

		// ---- Pass 5: refresh parent rollups (price range, child cache) -----
		if ( class_exists( 'WC_Product_Variable' ) && ( $summary['created'] || $summary['updated'] || $summary['hidden'] || $summary['trashed'] ) ) {
			WC_Product_Variable::sync( $target_post_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $target_post_id );
			}
		}

		// ---- Pass 6: derived size (§4.14.11) --------------------------------
		foreach ( $mappings as $mi => $mapping ) {
			$size_field = trim( (string) $mapping['derived_size_field'] );
			if ( '' === $size_field ) {
				continue;
			}
			$derived = '';
			foreach ( $parsed[ $mi ] as $row ) {
				if ( $this->row_enabled( $row, $mapping ) ) {
					$derived = $this->derive_size_string( $row );
					break;
				}
			}
			if ( '' !== $derived ) {
				$written = $this->write_source_column( $cct_slug, $source_id, $size_field, $derived, $source_data );
				$summary['derived_size'] = array( 'field' => $size_field, 'value' => $derived, 'written' => $written );
			}
			break; // one derivation per bridge
		}

		return $summary;
	}

	/* -----------------------------------------------------------------------
	 * Phase 4c-B — reverse stock sync (WC variation → CCT repeater row)
	 * -------------------------------------------------------------------- */

	/**
	 * A managed variation's stock changed (admin edit OR purchase
	 * decrement). Write the new quantity back into the owning repeater
	 * row's stock subfield.
	 *
	 * Managed-only by construction: unmanaged variations carry no
	 * `META_VARIATION_SLUG` meta and return immediately. Scope is limited
	 * to `subfield_map` entries with `pull: true` whose target is
	 * `stock_quantity` (4c-B scope — broader pull fields are a 4c-C
	 * question).
	 *
	 * Cascade safety: when OUR OWN reconcile sets stock during a forward
	 * push, this hook fires inside the push lock — the `is_locked('push')`
	 * check suppresses the echo. The CCT write itself is direct SQL, so no
	 * JE hooks fire (L-022) and no forward push can retrigger.
	 *
	 * @param WC_Product $variation The variation object (stock already updated).
	 */
	public function on_variation_stock_change( $variation ) {

		if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_id' ) ) {
			return;
		}

		$variation_id = (int) $variation->get_id();
		$row_id       = (string) get_post_meta( $variation_id, JEDB_Target_Woo_Variation::META_VARIATION_SLUG, true );
		$bridge_id    = (int) get_post_meta( $variation_id, JEDB_Target_Woo_Variation::META_VARIATION_BRIDGE, true );

		if ( '' === $row_id || ! $bridge_id ) {
			return; // unmanaged — never touched (D-32)
		}

		$bridge = JEDB_Flatten_Config_Manager::instance()->get_by_id( $bridge_id );
		if ( ! $bridge || empty( $bridge['enabled'] ) ) {
			return;
		}

		// Respect the bridge's direction contract: stock pull is a PULL.
		$direction = isset( $bridge['direction'] ) ? (string) $bridge['direction'] : 'push';
		if ( ! in_array( $direction, array( 'pull', 'bidirectional' ), true ) ) {
			return;
		}

		$config        = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$source_target = isset( $bridge['source_target'] ) ? (string) $bridge['source_target'] : '';
		$target_target = isset( $bridge['target_target'] ) ? (string) $bridge['target_target'] : '';
		$cct_slug      = 0 === strpos( $source_target, 'cct::' ) ? substr( $source_target, 5 ) : '';
		if ( '' === $cct_slug ) {
			return;
		}

		$parent_id = (int) $variation->get_parent_id();
		$new_stock = $variation->get_stock_quantity();
		$new_stock = null === $new_stock ? '' : (string) (int) $new_stock; // JE string convention

		// Locate the owning CCT row + repeater column by the row UUID
		// (globally unique — a LIKE probe per mapped repeater is exact).
		global $wpdb;
		$table    = $wpdb->prefix . 'jet_cct_' . sanitize_key( $cct_slug );
		$mappings = isset( $config['variation_mappings'] ) && is_array( $config['variation_mappings'] ) ? $config['variation_mappings'] : array();

		foreach ( $mappings as $mapping ) {

			if ( ! is_array( $mapping ) || empty( $mapping['enabled'] ) || empty( $mapping['source_repeater'] ) ) {
				continue;
			}

			// Which repeater subfield feeds stock_quantity, and is it pull-enabled?
			$stock_subfield = '';
			foreach ( (array) $mapping['subfield_map'] as $sm ) {
				if ( is_array( $sm ) && 'stock_quantity' === ( $sm['target'] ?? '' ) && ! empty( $sm['pull'] ) ) {
					$stock_subfield = (string) $sm['subfield'];
					break;
				}
			}
			if ( '' === $stock_subfield ) {
				continue;
			}

			$column = preg_replace( '/[^a-z0-9_]/i', '', (string) $mapping['source_repeater'] );

			// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			$source_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT _ID, `{$column}` AS repeater FROM `{$table}` WHERE `{$column}` LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $row_id ) . '%'
			), ARRAY_A );
			// phpcs:enable

			if ( ! $source_row ) {
				continue;
			}

			$source_id = (int) $source_row['_ID'];

			// Cascade check: our own reconcile (forward push) sets stock
			// while holding the push lock — suppress the echo.
			$guard = JEDB_Sync_Guard::instance();
			if ( $guard->is_locked( 'push', $source_target, $source_id, $target_target, $parent_id ) ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Variation_Sync] stock pull suppressed — own push in flight', 'debug', array(
						'variation_id' => $variation_id, 'row_id' => $row_id,
					) );
				}
				return;
			}

			$rows = maybe_unserialize( (string) $source_row['repeater'] );
			if ( ! is_array( $rows ) ) {
				return;
			}

			$dirty = false;
			$old   = null;
			foreach ( $rows as $key => $row ) {
				if ( is_array( $row ) && (string) ( $row['_jedb_row_id'] ?? '' ) === $row_id ) {
					$old = (string) ( $row[ $stock_subfield ] ?? '' );
					if ( $old !== $new_stock ) {
						$rows[ $key ][ $stock_subfield ] = $new_stock;
						$dirty = true;
					}
					break;
				}
			}

			$status = JEDB_Sync_Log::STATUS_NOOP;
			if ( $dirty ) {
				$acquired = $guard->acquire( 'pull', $source_target, $source_id, $target_target, $parent_id, 'wc_variation_stock' );
				try {
					$written = (bool) $wpdb->update( $table, array( $column => serialize( $rows ) ), array( '_ID' => $source_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$status  = $written ? JEDB_Sync_Log::STATUS_SUCCESS : JEDB_Sync_Log::STATUS_ERRORED;
				} finally {
					if ( $acquired ) {
						$guard->release( 'pull', $source_target, $source_id, $target_target, $parent_id );
					}
				}
			}

			JEDB_Sync_Log::instance()->record( array(
				'direction'     => 'pull',
				'source_target' => $source_target,
				'source_id'     => $source_id,
				'target_target' => $target_target,
				'target_id'     => $parent_id,
				'origin'        => 'wc_variation_stock',
				'status'        => $status,
				'message'       => $dirty
					? sprintf( 'variation #%d stock %s → %s written to %s row', $variation_id, $old, $new_stock, $column )
					: sprintf( 'variation #%d stock unchanged in repeater (%s)', $variation_id, $new_stock ),
				'context'       => array(
					'bridge_id'      => $bridge_id,
					'variation_id'   => $variation_id,
					'row_id'         => $row_id,
					'repeater'       => $column,
					'stock_subfield' => $stock_subfield,
					'old'            => $old,
					'new'            => $new_stock,
				),
			) );

			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Variation_Sync] stock pull ' . $status, 'info', array(
					'variation_id' => $variation_id,
					'row_id'       => $row_id,
					'old'          => $old,
					'new'          => $new_stock,
				) );
			}

			return; // row found + handled — done
		}
	}

	/* -----------------------------------------------------------------------
	 * Row parsing + identity
	 * -------------------------------------------------------------------- */

	/**
	 * Unserialize a repeater column and guarantee every row carries a
	 * `_jedb_row_id`. Newly-assigned ids are persisted back to the CCT
	 * column immediately (direct SQL — no JE hooks fire, L-022) so the id
	 * survives even if the reconcile later fails.
	 *
	 * @return array item-key => row array (with ids)
	 */
	private function parse_and_ensure_row_ids( $cct_slug, $source_id, $repeater_field, array &$source_data ) {

		$raw  = isset( $source_data[ $repeater_field ] ) ? $source_data[ $repeater_field ] : '';
		$rows = is_array( $raw ) ? $raw : maybe_unserialize( (string) $raw );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$dirty = false;
		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				unset( $rows[ $key ] );
				continue;
			}
			if ( '' === trim( (string) ( $row['_jedb_row_id'] ?? '' ) ) ) {
				$rows[ $key ]['_jedb_row_id'] = wp_generate_uuid4();
				$dirty = true;
			}
		}

		if ( $dirty ) {
			$this->write_source_column( $cct_slug, $source_id, $repeater_field, serialize( $rows ), $source_data );
		}

		// Keep the caller's source_data in sync (raw serialized form, same
		// shape the adapter delivered it in).
		$source_data[ $repeater_field ] = serialize( $rows );

		return $rows;
	}

	/**
	 * Direct-SQL write to one column of the source CCT row (L-030 pattern).
	 * Verifies the column exists first so a mis-configured field name
	 * can't fatal the sync.
	 *
	 * @return bool
	 */
	private function write_source_column( $cct_slug, $source_id, $column, $value, array &$source_data ) {

		global $wpdb;

		$table  = $wpdb->prefix . 'jet_cct_' . sanitize_key( $cct_slug );
		$column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );

		$col_exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		if ( ! $col_exists ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Variation_Sync] source column missing — write skipped', 'warning', array(
					'table' => $table, 'column' => $column,
				) );
			}
			return false;
		}

		$updated = $wpdb->update( $table, array( $column => $value ), array( '_ID' => absint( $source_id ) ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false !== $updated ) {
			$source_data[ $column ] = $value;
			return true;
		}
		return false;
	}

	private function row_enabled( array $row, array $mapping ) {
		$key = ! empty( $mapping['enabled_subfield'] ) ? (string) $mapping['enabled_subfield'] : 'enabled';
		// JE switcher stores string 'true' / 'false'. A missing key counts
		// as enabled (rows saved before the subfield existed).
		$val = $row[ $key ] ?? 'true';
		return in_array( (string) $val, array( 'true', '1', 'yes' ), true );
	}

	/* -----------------------------------------------------------------------
	 * Field building
	 * -------------------------------------------------------------------- */

	/**
	 * Build the typed-setter field array for one variation from one
	 * repeater row. All repeater values arrive as strings (DATA-MAP
	 * storage table) — cast discipline lives here.
	 */
	private function build_variation_fields( array $mapping, array $row, array $source_data, $enabled ) {

		$fields = array();

		// Fixed defaults first (manage_stock / virtual / downloadable ...).
		foreach ( (array) $mapping['variation_defaults'] as $k => $v ) {
			$fields[ $k ] = $this->cast_for_target( $k, $v );
		}

		// Subfield map.
		foreach ( (array) $mapping['subfield_map'] as $sm ) {
			if ( ! is_array( $sm ) || empty( $sm['subfield'] ) || empty( $sm['target'] ) ) {
				continue;
			}
			$val = (string) ( $row[ $sm['subfield'] ] ?? '' );
			if ( '' === trim( $val ) ) {
				continue; // empty repeater value = leave target untouched (except sale-clear below)
			}
			$fields[ $sm['target'] ] = $this->cast_for_target( (string) $sm['target'], $val );
		}

		// Price fallback: mapped price empty → inherit the named source scalar.
		$fallback_field = trim( (string) $mapping['price_fallback_field'] );
		if ( '' !== $fallback_field && ( ! isset( $fields['regular_price'] ) || '' === (string) $fields['regular_price'] ) ) {
			$fallback = trim( (string) ( $source_data[ $fallback_field ] ?? '' ) );
			if ( '' !== $fallback && is_numeric( $fallback ) && (float) $fallback > 0 ) {
				$fields['regular_price'] = $fallback;
			}
		}

		// Sale gate: when the on_sale select isn't "yes", CLEAR sale fields
		// so switching a sale off actually ends it on the storefront.
		$gate_key = ! empty( $mapping['on_sale_subfield'] ) ? (string) $mapping['on_sale_subfield'] : 'on_sale';
		$on_sale  = strtolower( trim( (string) ( $row[ $gate_key ] ?? 'no' ) ) );
		if ( 'yes' !== $on_sale ) {
			$fields['sale_price']        = '';
			$fields['date_on_sale_from'] = null;
			$fields['date_on_sale_to']   = null;
		}

		// Downloads: attachment id subfield → WC downloads array.
		$dl_key = trim( (string) $mapping['downloads_subfield'] );
		if ( '' !== $dl_key ) {
			$attachment_id = absint( (string) ( $row[ $dl_key ] ?? '' ) );
			if ( $attachment_id ) {
				$url = wp_get_attachment_url( $attachment_id );
				if ( $url ) {
					$fields['downloads'] = array(
						array(
							'name' => get_the_title( $attachment_id ) ?: basename( $url ),
							'file' => $url,
						),
					);
				}
			} else {
				$fields['downloads'] = array();
			}
		}

		// Attributes — SINGLE-SELECTOR model (amended post-alpha.22
		// staging review): only the per-row `variant_attribute` term goes
		// on the variation. The `attribute_terms` type discriminator is
		// parent-level classification (assigned to the product for
		// filtering, is_variation=0) — putting it on variations produced
		// a redundant second storefront dropdown that "did nothing"
		// because the variant term alone already uniquely identifies
		// every variation (physical labels + "Instructions PDF" are all
		// terms of the same variant taxonomy).
		$attributes = array();
		$va = $mapping['variant_attribute'];
		if ( is_array( $va ) && ! empty( $va['taxonomy'] ) && ! empty( $va['from_subfield'] ) ) {
			$label = trim( (string) ( $row[ $va['from_subfield'] ] ?? '' ) );
			if ( '' !== $label ) {
				$attributes[ sanitize_title( (string) $va['taxonomy'] ) ] = sanitize_title( $label );
			}
		}
		if ( ! empty( $attributes ) ) {
			$fields['attributes'] = $attributes;
		}

		// Status: enabled rows publish, disabled soft-hide.
		$fields['status'] = $enabled ? 'publish' : 'private';

		return $fields;
	}

	/**
	 * Cast a repeater string value for a WC typed setter.
	 */
	private function cast_for_target( $target, $value ) {

		switch ( $target ) {
			case 'stock_quantity':
				return (int) $value;
			case 'image_id':
			case 'shipping_class_id':
			case 'menu_order':
				return absint( $value );
			case 'manage_stock':
			case 'virtual':
			case 'downloadable':
				return in_array( (string) $value, array( 'true', '1', 'yes' ), true );
			case 'date_on_sale_from':
			case 'date_on_sale_to':
				// WC setters accept 'YYYY-MM-DD' strings directly.
				return (string) $value;
			default:
				// Prices, dims, weight, sku: WC setters normalize strings.
				return (string) $value;
		}
	}

	/* -----------------------------------------------------------------------
	 * Parent attribute maintenance (D-31)
	 * -------------------------------------------------------------------- */

	/**
	 * Ensure the parent product carries every attribute taxonomy + term the
	 * managed variations need — while PRESERVING any unrelated attributes
	 * the editor configured manually.
	 *
	 * SINGLE-SELECTOR model (amended post-alpha.22 staging review):
	 *   - `variant_attribute` taxonomy → the storefront variation selector.
	 *     Terms created per row label (create_if_missing), assigned to the
	 *     parent, `_product_attributes` entry forced `is_variation=1`.
	 *   - `attribute_terms` taxonomies → parent-level CLASSIFICATION only
	 *     (useful for filtering). Terms assigned to the parent; the
	 *     `_product_attributes` entry is created with `is_variation=0` and
	 *     an EXISTING entry's `is_variation` is never modified (products
	 *     with manual variations keyed on that attribute — e.g. Koala —
	 *     must keep working; D-32 spirit).
	 */
	private function maintain_parent_attributes( $product_id, array $mappings, array $parsed, array &$summary ) {

		// taxonomy => array( 'slugs' => [...], 'is_variation' => 0|1 )
		$needed = array();

		foreach ( $mappings as $mi => $mapping ) {

			foreach ( (array) $mapping['attribute_terms'] as $tax => $term_slug ) {
				$tax = sanitize_title( (string) $tax );
				$needed[ $tax ]['slugs'][]     = sanitize_title( (string) $term_slug );
				$needed[ $tax ]['is_variation'] = isset( $needed[ $tax ]['is_variation'] ) ? $needed[ $tax ]['is_variation'] : 0;
			}

			$va = $mapping['variant_attribute'];
			if ( is_array( $va ) && ! empty( $va['taxonomy'] ) && ! empty( $va['from_subfield'] ) ) {
				$tax = sanitize_title( (string) $va['taxonomy'] );
				$needed[ $tax ]['is_variation'] = 1;
				foreach ( $parsed[ $mi ] as $row ) {
					$label = trim( (string) ( $row[ $va['from_subfield'] ] ?? '' ) );
					if ( '' === $label ) {
						continue;
					}
					$slug = sanitize_title( $label );
					$needed[ $tax ]['slugs'][] = $slug;

					// Create the term when allowed and missing (term NAME
					// keeps the human label; slug is sanitized).
					if ( ! empty( $va['create_if_missing'] ) && taxonomy_exists( $tax ) && ! term_exists( $slug, $tax ) ) {
						$created = wp_insert_term( $label, $tax, array( 'slug' => $slug ) );
						if ( ! is_wp_error( $created ) ) {
							$summary['per_row'][] = "term '{$label}' created in {$tax}";
						}
					}
				}
			}
		}

		if ( empty( $needed ) ) {
			return;
		}

		$attr_meta = get_post_meta( $product_id, '_product_attributes', true );
		$attr_meta = is_array( $attr_meta ) ? $attr_meta : array();
		$meta_dirty = false;

		$position = count( $attr_meta );
		foreach ( $needed as $tax => $info ) {

			if ( ! taxonomy_exists( $tax ) ) {
				$summary['errors'][] = "attribute taxonomy {$tax} not registered — create the global attribute in WC first";
				continue;
			}

			$slugs = array_values( array_unique( (array) ( $info['slugs'] ?? array() ) ) );
			if ( ! empty( $slugs ) ) {
				// Assign terms to the parent (append — never clobber).
				wp_set_post_terms( $product_id, $slugs, $tax, true );
			}

			$want_variation = (int) ( $info['is_variation'] ?? 0 );

			if ( ! isset( $attr_meta[ $tax ] ) ) {
				// New entry: is_variation per role.
				$attr_meta[ $tax ] = array(
					'name'         => $tax,
					'value'        => '',
					'position'     => $position++,
					'is_visible'   => 1,
					'is_variation' => $want_variation,
					'is_taxonomy'  => 1,
				);
				$meta_dirty = true;
			} elseif ( $want_variation && empty( $attr_meta[ $tax ]['is_variation'] ) ) {
				// Variant selector must be variation-enabled — upgrade.
				$attr_meta[ $tax ]['is_variation'] = 1;
				$meta_dirty = true;
			} elseif ( ! $want_variation && ! empty( $attr_meta[ $tax ]['is_variation'] ) ) {
				// alpha.27 SAFE-DOWNGRADE: an attribute_terms (classification)
				// taxonomy is flagged is_variation=1 on the parent — legacy
				// state from before the single-selector model (alpha.23) or
				// a WC-admin save that re-promoted it. Downgrading is only
				// safe when NO unmanaged variation still keys on it (D-32:
				// manual setups like pre-repeater Koala must keep working).
				if ( ! $this->unmanaged_variations_use_attribute( $product_id, $tax ) ) {
					$attr_meta[ $tax ]['is_variation'] = 0;
					$meta_dirty = true;
					$summary['per_row'][] = "parent attribute {$tax} demoted to is_variation=0 (classification-only, no unmanaged users)";
				}
			}
		}

		if ( $meta_dirty ) {
			update_post_meta( $product_id, '_product_attributes', $attr_meta );
			$summary['per_row'][] = 'parent _product_attributes updated';
		}
	}

	/**
	 * Does any UNMANAGED (non-bridge) variation of this product carry a
	 * value for the given attribute taxonomy? Used by the alpha.27
	 * safe-downgrade check in maintain_parent_attributes().
	 *
	 * @return bool
	 */
	private function unmanaged_variations_use_attribute( $product_id, $tax ) {

		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} attr ON attr.post_id = p.ID AND attr.meta_key = %s AND attr.meta_value != ''
			 LEFT JOIN {$wpdb->postmeta} jedb ON jedb.post_id = p.ID AND jedb.meta_key = %s
			 WHERE p.post_parent = %d AND p.post_type = %s AND p.post_status != 'trash' AND jedb.meta_id IS NULL",
			'attribute_' . sanitize_title( $tax ),
			JEDB_Target_Woo_Variation::META_VARIATION_SLUG,
			absint( $product_id ),
			JEDB_Target_Woo_Variation::POST_TYPE
		) );
		// phpcs:enable

		return $count > 0;
	}

	/* -----------------------------------------------------------------------
	 * Orphan detection (rows deleted from the repeater)
	 * -------------------------------------------------------------------- */

	/**
	 * Managed variations of this bridge+parent whose row id is no longer in
	 * the repeater. Never returns unmanaged variations (D-32 contract).
	 *
	 * @return int[] variation post IDs
	 */
	private function find_orphan_managed_variations( $parent_id, $bridge_id, array $live_row_ids ) {

		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, slug_meta.meta_value AS row_id
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} slug_meta   ON slug_meta.post_id = p.ID AND slug_meta.meta_key = %s
			 INNER JOIN {$wpdb->postmeta} bridge_meta ON bridge_meta.post_id = p.ID AND bridge_meta.meta_key = %s AND bridge_meta.meta_value = %s
			 WHERE p.post_parent = %d AND p.post_type = %s AND p.post_status != 'trash'",
			JEDB_Target_Woo_Variation::META_VARIATION_SLUG,
			JEDB_Target_Woo_Variation::META_VARIATION_BRIDGE,
			(string) (int) $bridge_id,
			absint( $parent_id ),
			JEDB_Target_Woo_Variation::POST_TYPE
		) );
		// phpcs:enable

		$orphans = array();
		foreach ( (array) $rows as $r ) {
			if ( ! in_array( (string) $r->row_id, $live_row_ids, true ) ) {
				$orphans[] = (int) $r->ID;
			}
		}
		return $orphans;
	}

	/* -----------------------------------------------------------------------
	 * Derived size (§4.14.11)
	 * -------------------------------------------------------------------- */

	/**
	 * `18″ × 18″ × 2″` from a row's length/width/height — non-empty dims
	 * joined in L→W→H order, trailing zeros trimmed, format-stable when
	 * dimensions are missing.
	 */
	private function derive_size_string( array $row ) {

		$parts = array();
		foreach ( array( 'length', 'width', 'height' ) as $dim ) {
			$v = trim( (string) ( $row[ $dim ] ?? '' ) );
			if ( '' === $v || ! is_numeric( $v ) ) {
				continue;
			}
			$f = (float) $v;
			$n = ( $f == (int) $f ) ? (string) (int) $f : rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
			$parts[] = $n . "\u{2033}";
		}
		return implode( " \u{00D7} ", $parts );
	}

	/* -----------------------------------------------------------------------
	 * Admin: hide the `_jedb_row_id` subfield on JE CCT edit screens
	 * -------------------------------------------------------------------- */

	public function maybe_hide_row_id_field() {

		global $pagenow;
		if ( 'admin.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page || 0 !== strpos( $page, 'jet-cct-' ) ) {
			return;
		}
		// JE's CCT edit form is Vue-rendered (cx-vue-ui); repeater rows
		// mount asynchronously and grow as editors add rows, so a
		// MutationObserver hides matching field wrappers as they appear.
		// Matching is defensive: input name/id containing the key OR a
		// component label reading "Row ID (system)".
		?>
		<script id="jedb-hide-row-id-field">
		( function () {
			var KEY = '_jedb_row_id';
			var LABEL = 'Row ID (system)';
			function sweep( root ) {
				var nodes = ( root || document ).querySelectorAll( '.cx-vui-component:not([data-jedb-hidden])' );
				Array.prototype.forEach.call( nodes, function ( comp ) {
					var hit = false;
					var input = comp.querySelector( 'input[name*="' + KEY + '"], input[id*="' + KEY + '"]' );
					if ( input ) { hit = true; }
					if ( ! hit ) {
						var label = comp.querySelector( '.cx-vui-component__label' );
						if ( label && label.textContent.indexOf( LABEL ) !== -1 ) { hit = true; }
					}
					if ( hit ) {
						comp.setAttribute( 'data-jedb-hidden', '1' );
						comp.style.display = 'none';
					}
				} );
			}
			document.addEventListener( 'DOMContentLoaded', function () {
				sweep( document );
				var observer = new MutationObserver( function () { sweep( document ); } );
				observer.observe( document.body, { childList: true, subtree: true } );
			} );
		} )();
		</script>
		<?php
	}
}
