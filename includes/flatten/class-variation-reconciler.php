<?php
/**
 * Variation Reconciler — Phase 4b / §4.7.
 *
 * Walks a bridge's `variations[]` array on every successful push, evaluating
 * each entry's `show_when` against the source CCT row and ensuring the
 * corresponding WooCommerce variation exists / is up-to-date / is
 * soft-deleted accordingly.
 *
 * Bridge-managed variations carry two post meta keys
 * (`_jedb_variation_slug` + `_jedb_variation_bridge`) — see
 * Target_Woo_Variation::find_managed_variation(). The reconciler only
 * touches variations matching those meta keys; variations the bridge
 * doesn't know about (third-party plugins, manual variations) stay
 * untouched.
 *
 * Per L-015 (locked decision), variations are NOT separate bridges —
 * each variation comes from the SAME source CCT row as the parent
 * product. The reconciler is a Woo-specific layer; the broader bridge
 * architecture (mappings, taxonomies, push/pull engines, conditional
 * sync) remains post-type-agnostic and supports any CPT target.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Variation_Reconciler {

	/** @var JEDB_Variation_Reconciler|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* -----------------------------------------------------------------------
	 * Public reconciler entrypoint
	 *
	 * Called from JEDB_Flattener::apply_bridge() after mappings + taxonomies.
	 * Returns a summary describing what happened to each variation entry so
	 * the caller can include it in the sync_log row.
	 * -------------------------------------------------------------------- */

	/**
	 * Reconcile all variations[] entries for one bridge × source-row pair.
	 *
	 * @param array  $bridge           Decoded flatten config row (top-level
	 *                                  with `id`, `config`, etc.).
	 * @param int    $source_id        Source CCT row's _ID.
	 * @param array  $source_data      Fresh source CCT row data.
	 * @param int    $target_post_id   The linked parent product's post ID.
	 * @param string $target_target    The bridge's target_target slug
	 *                                  (must be `posts::product` for the
	 *                                  reconciler to run — variations live
	 *                                  under variable products).
	 * @param array  $context          Same DSL context the main condition
	 *                                  evaluator uses (per BUILD-PLAN §4.9).
	 * @return array{
	 *   ran:      bool,
	 *   examined: int,
	 *   created:  int,
	 *   updated:  int,
	 *   hidden:   int,
	 *   skipped:  int,
	 *   errors:   int,
	 *   per_variation: array<int,array{slug:string,outcome:string,variation_id:int}>
	 * }
	 */
	public function reconcile( array $bridge, $source_id, array $source_data, $target_post_id, $target_target, array $context = array() ) {

		$summary = array(
			'ran'           => false,
			'examined'      => 0,
			'created'       => 0,
			'updated'       => 0,
			'hidden'        => 0,
			'skipped'       => 0,
			'errors'        => 0,
			'per_variation' => array(),
		);

		$config     = isset( $bridge['config'] ) && is_array( $bridge['config'] ) ? $bridge['config'] : array();
		$variations = isset( $config['variations'] ) && is_array( $config['variations'] ) ? $config['variations'] : array();
		$bridge_id  = isset( $bridge['id'] ) ? (int) $bridge['id'] : 0;

		// Bail conditions — these are not errors, just "nothing to do."
		if ( empty( $variations ) ) {
			return $summary;
		}
		if ( 'posts::product' !== $target_target ) {
			// Variations are a Woo concept. Bridges targeting non-product
			// post types (CPTs, etc.) MAY define variations[] for forward-
			// compat, but the reconciler won't act on them today. Log
			// once-per-call so editors can spot misconfigurations.
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log(
					'[Variation_Reconciler] non-product target — variations[] block ignored',
					'info',
					array(
						'bridge_id'      => $bridge_id,
						'target_target'  => $target_target,
						'variation_count' => count( $variations ),
					)
				);
			}
			return $summary;
		}
		if ( ! class_exists( 'WC_Product_Variation' ) ) {
			// Woo not loaded — bail quietly. This can happen on early
			// admin requests before WC's classes are available.
			return $summary;
		}
		if ( $target_post_id <= 0 ) {
			return $summary;
		}

		// Acquire the Woo Variation adapter via the registry so we get
		// the same instance the engine uses elsewhere.
		$adapter = null;
		if ( class_exists( 'JEDB_Target_Registry' ) ) {
			$adapter = JEDB_Target_Registry::instance()->get( 'posts::product_variation' );
		}
		if ( ! $adapter || ! ( $adapter instanceof JEDB_Target_Woo_Variation ) ) {
			$summary['errors'] = count( $variations );
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log(
					'[Variation_Reconciler] Woo variation adapter not registered — cannot reconcile',
					'error',
					array( 'bridge_id' => $bridge_id )
				);
			}
			return $summary;
		}

		$summary['ran']      = true;
		$summary['examined'] = count( $variations );

		$evaluator = class_exists( 'JEDB_Condition_Evaluator' ) ? JEDB_Condition_Evaluator::instance() : null;

		foreach ( $variations as $variation_rule ) {

			if ( ! is_array( $variation_rule ) ) {
				$summary['skipped']++;
				continue;
			}

			$slug = isset( $variation_rule['slug'] ) ? sanitize_text_field( (string) $variation_rule['slug'] ) : '';
			if ( '' === $slug ) {
				$summary['skipped']++;
				$summary['per_variation'][] = array(
					'slug'         => '',
					'outcome'      => 'skipped_no_slug',
					'variation_id' => 0,
				);
				continue;
			}

			if ( isset( $variation_rule['enabled'] ) && ! $variation_rule['enabled'] ) {
				$summary['skipped']++;
				$summary['per_variation'][] = array(
					'slug'         => $slug,
					'outcome'      => 'skipped_disabled',
					'variation_id' => 0,
				);
				continue;
			}

			// Evaluate show_when. Empty DSL = always show.
			$show_when = isset( $variation_rule['show_when'] ) ? (string) $variation_rule['show_when'] : '';
			$should_show = true;
			if ( '' !== trim( $show_when ) && $evaluator ) {
				try {
					$should_show = (bool) $evaluator->evaluate( $show_when, $context );
				} catch ( \Throwable $t ) {
					// Treat DSL errors as "don't show" — matches the
					// existing condition evaluator's failure semantics
					// (false on parse error) so editors can't accidentally
					// expose variations via a typo.
					$should_show = false;
					if ( function_exists( 'jedb_log' ) ) {
						jedb_log(
							'[Variation_Reconciler] show_when DSL error — treating as false',
							'warning',
							array(
								'bridge_id' => $bridge_id,
								'slug'      => $slug,
								'show_when' => $show_when,
								'error'     => $t->getMessage(),
							)
						);
					}
				}
			}

			$existing_id = $adapter->find_managed_variation( $target_post_id, $bridge_id, $slug );

			if ( $should_show ) {
				$outcome_id = $this->ensure_variation( $adapter, $bridge_id, $target_post_id, $slug, $variation_rule, $source_data, $existing_id, $summary );
				$summary['per_variation'][] = array(
					'slug'         => $slug,
					'outcome'      => $existing_id > 0 ? 'updated' : 'created',
					'variation_id' => (int) $outcome_id,
				);
			} else {
				$this->hide_variation_if_exists( $adapter, $existing_id, $bridge_id, $slug, $summary );
				$summary['per_variation'][] = array(
					'slug'         => $slug,
					'outcome'      => $existing_id > 0 ? 'hidden' : 'noop_show_false',
					'variation_id' => (int) $existing_id,
				);
			}
		}

		return $summary;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Create or update the variation for `$slug`. Returns the variation
	 * post ID (whether existing or just-created) or 0 on failure.
	 */
	private function ensure_variation( $adapter, $bridge_id, $target_post_id, $slug, array $variation_rule, array $source_data, $existing_id, array &$summary ) {

		$fields = $this->compute_variation_fields( $variation_rule, $source_data );

		if ( $existing_id > 0 ) {
			// Ensure the variation is visible (counters a previous
			// soft-delete) and apply field updates.
			$fields['status'] = 'publish';

			$ok = $adapter->update( $existing_id, $fields );
			if ( $ok ) {
				$summary['updated']++;
				return (int) $existing_id;
			}
			$summary['errors']++;
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log(
					'[Variation_Reconciler] update failed',
					'error',
					array(
						'bridge_id'    => $bridge_id,
						'slug'         => $slug,
						'variation_id' => $existing_id,
					)
				);
			}
			return 0;
		}

		// Create path — set attributes from the rule (or fall back to a
		// generated plugin-managed attribute) and stamp the JEDB
		// tracking meta keys.
		$attributes = isset( $variation_rule['attributes'] ) && is_array( $variation_rule['attributes'] )
			? $variation_rule['attributes']
			: array();

		if ( empty( $attributes ) ) {
			// Fallback: use a plugin-managed attribute slot so the
			// variation has SOME attribute (Woo requires this). Editors
			// will typically pre-configure their own attribute taxonomy
			// and declare it in `variation_rule.attributes` — this
			// fallback exists for "just try it out" first-run scenarios.
			$attributes = array(
				'jedb_variant' => sanitize_title( $slug ),
			);
		}

		$fields['attributes'] = $attributes;

		$new_id = $adapter->create_for_bridge( $target_post_id, $bridge_id, $slug, $fields );
		if ( $new_id ) {
			$summary['created']++;
			return (int) $new_id;
		}

		$summary['errors']++;
		if ( function_exists( 'jedb_log' ) ) {
			jedb_log(
				'[Variation_Reconciler] create failed',
				'error',
				array(
					'bridge_id'      => $bridge_id,
					'slug'           => $slug,
					'target_post_id' => $target_post_id,
				)
			);
		}
		return 0;
	}

	/**
	 * Soft-delete an existing managed variation by setting its status
	 * to `private`. Idempotent — calling with status=private already
	 * is a no-op.
	 */
	private function hide_variation_if_exists( $adapter, $existing_id, $bridge_id, $slug, array &$summary ) {

		if ( $existing_id <= 0 ) {
			return;
		}

		$ok = $adapter->update( $existing_id, array( 'status' => 'private' ) );
		if ( $ok ) {
			$summary['hidden']++;
		} else {
			$summary['errors']++;
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log(
					'[Variation_Reconciler] hide (set status=private) failed',
					'warning',
					array(
						'bridge_id'    => $bridge_id,
						'slug'         => $slug,
						'variation_id' => $existing_id,
					)
				);
			}
		}
	}

	/**
	 * Translate a `variations[]` rule + source CCT row into a fields[]
	 * payload suitable for Target_Woo_Variation::create()/update().
	 *
	 * Currently handles:
	 *   - `price_field` → `regular_price` (passthrough)
	 *   - `downloads`[] of source field names → `downloads` array of
	 *     WC_Product_Download data (auto-builds id/name/file shape)
	 *
	 * Future Phase 4b enhancements: SKU template, stock, menu_order,
	 * dimensions.
	 *
	 * @param array $variation_rule
	 * @param array $source_data
	 * @return array
	 */
	private function compute_variation_fields( array $variation_rule, array $source_data ) {

		$out = array();

		// Description / label — apply the rule's label as the variation
		// description if non-empty. Editors see this in WC's variations
		// list. Not required.
		if ( ! empty( $variation_rule['label'] ) ) {
			$out['description'] = (string) $variation_rule['label'];
		}

		// Price: pull from the named CCT field if it has a value.
		$price_field = isset( $variation_rule['price_field'] ) ? (string) $variation_rule['price_field'] : '';
		if ( '' !== $price_field && isset( $source_data[ $price_field ] ) ) {
			$price_value = $source_data[ $price_field ];
			if ( is_scalar( $price_value ) && '' !== (string) $price_value ) {
				$out['regular_price'] = (string) $price_value;
			}
		}

		// Downloads: each entry in $variation_rule['downloads'] is a
		// source-CCT field name whose value is either a single
		// attachment ID, a single URL, or an array of mixed IDs/URLs.
		// We translate to WC's downloads[] shape: each entry needs an
		// `id` (any unique string) + `name` + `file` URL.
		$download_fields = isset( $variation_rule['downloads'] ) && is_array( $variation_rule['downloads'] )
			? $variation_rule['downloads']
			: array();

		$downloads = array();
		foreach ( $download_fields as $field_name ) {
			$field_name = (string) $field_name;
			if ( '' === $field_name || ! isset( $source_data[ $field_name ] ) ) {
				continue;
			}
			$raw = $source_data[ $field_name ];

			$candidates = is_array( $raw ) ? $raw : array( $raw );
			foreach ( $candidates as $candidate ) {
				$entry = $this->build_download_entry( $candidate );
				if ( $entry ) {
					$downloads[] = $entry;
				}
			}
		}

		if ( ! empty( $downloads ) ) {
			$out['downloads']    = $downloads;
			$out['downloadable'] = true;
			$out['virtual']      = true; // Downloadable products are typically virtual.
		}

		return $out;
	}

	/**
	 * Turn one downloads-list entry into WC's expected shape:
	 *   { id, name, file }
	 *
	 * Accepts attachment IDs (resolves URL + filename) or raw URLs.
	 * Returns null when the value can't be coerced.
	 */
	private function build_download_entry( $value ) {

		// Numeric value = attachment ID.
		if ( is_numeric( $value ) ) {
			$att_id = absint( $value );
			if ( ! $att_id ) {
				return null;
			}
			$url      = wp_get_attachment_url( $att_id );
			$filename = basename( (string) get_attached_file( $att_id ) );
			if ( ! $url ) {
				return null;
			}
			return array(
				'id'   => md5( 'jedb-att-' . $att_id ),
				'name' => '' !== $filename ? $filename : sprintf( 'attachment-%d', $att_id ),
				'file' => $url,
			);
		}

		// String — could be a URL.
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$url = esc_url_raw( trim( $value ) );
			if ( '' === $url ) {
				return null;
			}
			$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			return array(
				'id'   => md5( 'jedb-url-' . $url ),
				'name' => '' !== $filename ? $filename : __( 'Download', 'je-data-bridge-cc' ),
				'file' => $url,
			);
		}

		return null;
	}
}
