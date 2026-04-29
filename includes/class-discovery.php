<?php
/**
 * Discovery — single source of truth for "what data lives on this site".
 *
 * Merges and generalizes the discovery classes from Jet Engine Relation Injector
 * (CCTs, Relations, recursive grandparent/grandchild traversal) and PAC Vehicle
 * Data Manager (CCT field schemas), and adds the new bits this plugin needs:
 * public CPTs, WooCommerce product types, variations, taxonomies, and per-target
 * meta-key whitelisting.
 *
 * Caches results to a transient (5 min TTL) plus an in-memory copy. Flush via
 * flush_cache() — exposed as a "Refresh discovery cache" button in the
 * Targets tab.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Discovery {

	const TRANSIENT_KEY = 'jedb_discovery_cache_v1';
	const TRANSIENT_TTL = 300; // 5 minutes

	/** @var JEDB_Discovery|null */
	private static $instance = null;

	/** @var array  Per-request memoization: { 'ccts' => [...], 'relations' => [...], ... } */
	private $memo = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* -----------------------------------------------------------------------
	 * Cache management
	 * -------------------------------------------------------------------- */

	public function flush_cache() {
		$this->memo = array();
		delete_transient( self::TRANSIENT_KEY );
	}

	private function memo_get( $key ) {

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$transient = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $transient ) && array_key_exists( $key, $transient ) ) {
			$this->memo[ $key ] = $transient[ $key ];
			return $transient[ $key ];
		}

		return null;
	}

	private function memo_set( $key, $value ) {

		$this->memo[ $key ] = $value;

		$transient = get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $transient ) ) {
			$transient = array();
		}
		$transient[ $key ] = $value;
		set_transient( self::TRANSIENT_KEY, $transient, self::TRANSIENT_TTL );

		return $value;
	}

	/* -----------------------------------------------------------------------
	 * CCT discovery
	 * -------------------------------------------------------------------- */

	/**
	 * @return array<int,array{slug:string,name:string,singular_name:string,fields:array,type_id:int|null}>
	 */
	public function get_all_ccts() {

		$cached = $this->memo_get( 'ccts' );
		if ( null !== $cached && ! empty( $cached ) ) {
			return $cached;
		}

		$ccts = array();

		try {

			if ( ! class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
				jedb_log( 'get_all_ccts: CCT module class not autoloadable', 'warning' );
				return $this->maybe_cache( 'ccts', $ccts );
			}

			$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();

			if ( ! $module || ! isset( $module->manager ) ) {
				jedb_log( 'get_all_ccts: CCT module/manager not available', 'warning', array(
					'module_is_object'  => is_object( $module ),
					'has_manager_prop'  => is_object( $module ) && isset( $module->manager ),
				) );
				return $this->maybe_cache( 'ccts', $ccts );
			}

			$raw = $module->manager->get_content_types();

			if ( empty( $raw ) ) {
				jedb_log( 'get_all_ccts: manager returned empty', 'debug' );
				return $this->maybe_cache( 'ccts', $ccts );
			}

			if ( ! is_array( $raw ) ) {
				jedb_log( 'get_all_ccts: manager returned non-array', 'warning', array( 'type' => gettype( $raw ) ) );
				return $this->maybe_cache( 'ccts', $ccts );
			}

			foreach ( $raw as $slug => $cct_instance ) {

				if ( ! is_object( $cct_instance ) ) {
					jedb_log( 'get_all_ccts: skipped non-object instance', 'warning', array( 'slug' => $slug, 'type' => gettype( $cct_instance ) ) );
					continue;
				}

				try {
					$name = method_exists( $cct_instance, 'get_arg' ) ? $cct_instance->get_arg( 'name' ) : null;
					$ccts[] = array(
						'slug'          => $slug,
						'name'          => $name ?: $slug,
						'singular_name' => $name ?: $slug,
						'fields'        => $this->get_cct_fields_from_instance( $cct_instance ),
						'type_id'       => property_exists( $cct_instance, 'type_id' ) ? $cct_instance->type_id : null,
					);
				} catch ( \Throwable $t ) {
					jedb_log( 'get_all_ccts: instance threw — skipping', 'error', array(
						'slug'  => $slug,
						'error' => $t->getMessage(),
					) );
				}
			}
		} catch ( \Throwable $t ) {
			jedb_log( 'get_all_ccts: top-level exception', 'error', array(
				'error' => $t->getMessage(),
				'file'  => $t->getFile(),
				'line'  => $t->getLine(),
			) );
		}

		return $this->maybe_cache( 'ccts', $ccts );
	}

	/**
	 * Cache writes that don't poison subsequent requests. Empty arrays are
	 * memoized for this request only — never persisted to the transient — so
	 * an early-init request that finds no CCTs doesn't lock all subsequent
	 * page loads into "0 CCTs forever until manual flush".
	 */
	private function maybe_cache( $key, $value ) {
		if ( empty( $value ) && in_array( $key, array( 'ccts', 'relations' ), true ) ) {
			$this->memo[ $key ] = $value;
			return $value;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			foreach ( array( 'post_types::', 'woo_' ) as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$this->memo[ $key ] = $value;
					return $value;
				}
			}
		}
		return $this->memo_set( $key, $value );
	}

	/**
	 * @param string $cct_slug
	 * @return array|null
	 */
	public function get_cct( $cct_slug ) {

		foreach ( $this->get_all_ccts() as $cct ) {
			if ( $cct['slug'] === $cct_slug ) {
				return $cct;
			}
		}

		return null;
	}

	/**
	 * JetEngine system column names. These exist on every CCT table (or on
	 * CCTs with "Has Single Page" enabled, in the case of cct_single_post_id)
	 * and are NOT user-defined fields.
	 *
	 * Discovery strips these from the user-fields list so the cct_meta['fields']
	 * array contains only what the editor authored. JEDB_Target_CCT then
	 * re-injects them in get_field_schema() as readonly system fields with
	 * group="system", so they remain visible to the bridge UI for read /
	 * conditional / display purposes (e.g. cct_modified for last-write-wins
	 * conflict resolution, cct_single_post_id for the JE native single-page
	 * bridge link) while being protected from accidental overwrites.
	 */
	const CCT_SYSTEM_COLUMN_NAMES = array(
		'cct_status', 'cct_author_id', 'cct_created', 'cct_modified', 'cct_single_post_id',
	);

	/**
	 * Backward-compat alias. Will be removed in a future version once no
	 * external code references the old name.
	 *
	 * @deprecated 0.2.5 Use CCT_SYSTEM_COLUMN_NAMES instead.
	 */
	const CCT_INTERNAL_COLUMN_NAMES = self::CCT_SYSTEM_COLUMN_NAMES;

	/**
	 * Resolve CCT field schema with type information.
	 *
	 * JetEngine has shifted where the field config lives between versions.
	 * See LESSONS-LEARNED.md L-007 for the full investigation. Resolution
	 * order (each channel returns the first non-empty result):
	 *
	 *   1. {prefix}jet_post_types row WHERE slug = $slug AND status = 'content-type'
	 *      — JE 3.8+ canonical home (verified on bbhq.legworklabs.com 2026-04-29)
	 *   2. $instance->get_arg('meta_fields')        — JE 3.x intermediate
	 *   3. $instance->get_arg('fields')             — older JE alias
	 *   4. $instance->args['meta_fields']/['fields'] — direct property
	 *   5. get_option('jet_engine_active_content_types')[N]['meta_fields']
	 *      — pre-3.8 storage
	 *   6. $instance->get_fields_list()             — names-only fallback
	 *
	 * Each returned field carries a `source` key so the diagnostic can show
	 * exactly which channel produced the data on this site.
	 *
	 * @return array<int,array{name:string,title:string,type:string,options:array,source:string}>
	 */
	private function get_cct_fields_from_instance( $cct_instance ) {

		$slug = $this->resolve_cct_slug( $cct_instance );

		if ( $slug ) {
			$from_table = $this->get_cct_fields_from_jet_post_types_table( $slug );
			if ( ! empty( $from_table ) ) {
				return $from_table;
			}
		}

		foreach ( array( 'meta_fields', 'fields' ) as $arg_key ) {
			if ( method_exists( $cct_instance, 'get_arg' ) ) {
				try {
					$raw = $cct_instance->get_arg( $arg_key );
					$normalized = $this->normalize_field_array( $raw, 'get_arg("' . $arg_key . '")' );
					if ( ! empty( $normalized ) ) {
						return $normalized;
					}
				} catch ( \Throwable $t ) {
					jedb_log( 'get_arg threw resolving CCT fields', 'warning', array( 'arg' => $arg_key, 'slug' => $slug, 'error' => $t->getMessage() ) );
				}
			}
		}

		if ( isset( $cct_instance->args ) && is_array( $cct_instance->args ) ) {
			foreach ( array( 'meta_fields', 'fields' ) as $key ) {
				if ( ! empty( $cct_instance->args[ $key ] ) && is_array( $cct_instance->args[ $key ] ) ) {
					$normalized = $this->normalize_field_array( $cct_instance->args[ $key ], '$instance->args["' . $key . '"]' );
					if ( ! empty( $normalized ) ) {
						return $normalized;
					}
				}
			}
		}

		if ( $slug ) {
			$option_field_set = $this->lookup_cct_fields_in_option( $slug );
			if ( ! empty( $option_field_set ) ) {
				return $option_field_set;
			}
		}

		if ( method_exists( $cct_instance, 'get_fields_list' ) ) {
			try {
				$slim = $cct_instance->get_fields_list();
				if ( is_array( $slim ) && ! empty( $slim ) ) {
					$out = array();
					foreach ( $slim as $name => $title ) {
						if ( '' === $name || '_ID' === $name ) {
							continue;
						}
						if ( in_array( $name, self::CCT_SYSTEM_COLUMN_NAMES, true ) ) {
							continue;
						}
						$out[] = array(
							'name'    => $name,
							'title'   => $title,
							'type'    => 'text',
							'options' => array(),
							'source'  => 'get_fields_list (no type info)',
						);
					}
					return $out;
				}
			} catch ( \Throwable $t ) {
				jedb_log( 'get_fields_list threw', 'warning', array( 'slug' => $slug, 'error' => $t->getMessage() ) );
			}
		}

		return array();
	}

	/**
	 * Normalize a raw JetEngine fields array into our internal shape, filtering
	 * out the system internal columns by name.
	 *
	 * @return array<int,array{name:string,title:string,type:string,options:array,source:string}>
	 */
	private function normalize_field_array( $raw, $source ) {

		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] ) ? trim( (string) $field['name'] ) : '';
			if ( '' === $name || '_ID' === $name ) {
				continue;
			}
			if ( in_array( $name, self::CCT_SYSTEM_COLUMN_NAMES, true ) ) {
				continue;
			}

			$out[] = array(
				'name'    => $name,
				'title'   => isset( $field['title'] ) && '' !== $field['title'] ? $field['title'] : $name,
				'type'    => isset( $field['type'] ) && '' !== $field['type'] ? $field['type'] : 'text',
				'options' => isset( $field['options'] ) ? $field['options'] : array(),
				'source'  => $source,
			);
		}

		return $out;
	}

	/**
	 * **Channel #1** — read CCT field schema from `{prefix}jet_post_types`,
	 * the canonical home in JetEngine 3.8+.
	 *
	 * Schema confirmed on bbhq.legworklabs.com (JE 3.8.5) by direct SQL
	 * inspection on 2026-04-29. The `meta_fields` column is a serialized
	 * PHP array of `{name, type, title, options, ...}` per field.
	 *
	 * Status discriminator: `content-type` for CCTs (vs `publish` for
	 * JE-registered CPTs, `relation` for JE Relations, `query` for JE
	 * Queries, `glossary` for JE Glossaries — see §3.4 in BUILD-PLAN).
	 *
	 * @return array<int,array{name:string,title:string,type:string,options:array,source:string}>
	 */
	public function get_cct_fields_from_jet_post_types_table( $cct_slug ) {

		global $wpdb;
		$table = $wpdb->prefix . 'jet_post_types';

		try {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $exists ) {
				return array();
			}

			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_fields FROM `{$table}` WHERE slug = %s AND status = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
					$cct_slug,
					'content-type'
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( null === $raw || '' === $raw ) {
				return array();
			}

			$decoded = maybe_unserialize( $raw );
			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				return array();
			}

			return $this->normalize_field_array( $decoded, 'wp_jet_post_types.meta_fields' );

		} catch ( \Throwable $t ) {
			jedb_log( 'get_cct_fields_from_jet_post_types_table threw', 'warning', array(
				'slug'  => $cct_slug,
				'error' => $t->getMessage(),
			) );
			return array();
		}
	}

	/**
	 * Discover JE Glossaries — also rows in `{prefix}jet_post_types`,
	 * differentiated by `status = 'glossary'`.
	 *
	 * Each glossary's `meta_fields` is a serialized array of
	 * `{value, label, ...}` entries. Used by Phase 4's bridge UI to
	 * resolve `select`/`radio` field options to human-readable labels.
	 *
	 * @return array<int,array{slug:string,label:string,values:array<int,array{value:string,label:string}>}>
	 */
	public function get_all_glossaries() {

		$cached = $this->memo_get( 'glossaries' );
		if ( null !== $cached && ! empty( $cached ) ) {
			return $cached;
		}

		$out = array();

		try {
			global $wpdb;
			$table = $wpdb->prefix . 'jet_post_types';

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $exists ) {
				return $this->maybe_cache( 'glossaries', $out );
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, slug, labels, meta_fields FROM `{$table}` WHERE status = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
					'glossary'
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( ! is_array( $rows ) ) {
				return $this->maybe_cache( 'glossaries', $out );
			}

			foreach ( $rows as $row ) {

				$labels      = isset( $row['labels'] ) ? maybe_unserialize( $row['labels'] ) : null;
				$meta_fields = isset( $row['meta_fields'] ) ? maybe_unserialize( $row['meta_fields'] ) : null;

				$label = '';
				if ( is_array( $labels ) && ! empty( $labels['name'] ) ) {
					$label = (string) $labels['name'];
				}

				$values = array();
				if ( is_array( $meta_fields ) ) {
					foreach ( $meta_fields as $entry ) {
						if ( ! is_array( $entry ) ) {
							continue;
						}
						$values[] = array(
							'value' => isset( $entry['value'] ) ? (string) $entry['value'] : '',
							'label' => isset( $entry['label'] ) ? (string) $entry['label'] : '',
						);
					}
				}

				$out[] = array(
					'id'     => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'slug'   => isset( $row['slug'] ) ? (string) $row['slug'] : '',
					'label'  => $label ? $label : ( isset( $row['slug'] ) ? (string) $row['slug'] : '' ),
					'values' => $values,
				);
			}
		} catch ( \Throwable $t ) {
			jedb_log( 'get_all_glossaries threw', 'warning', array( 'error' => $t->getMessage() ) );
		}

		return $this->maybe_cache( 'glossaries', $out );
	}

	/**
	 * Last-resort field source: read directly from JetEngine's persisted
	 * option `jet_engine_active_content_types`, find the entry matching this
	 * CCT's slug, and pull `meta_fields` (or `fields`) out of it.
	 *
	 * @return array<int,array{name:string,title:string,type:string,options:array,source:string}>
	 */
	private function lookup_cct_fields_in_option( $cct_slug ) {

		$stored = get_option( 'jet_engine_active_content_types' );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		foreach ( $stored as $cct_config ) {
			if ( ! is_array( $cct_config ) ) {
				continue;
			}
			$config_slug = isset( $cct_config['slug'] ) ? $cct_config['slug'] : '';
			if ( $config_slug !== $cct_slug ) {
				continue;
			}

			foreach ( array( 'meta_fields', 'fields' ) as $key ) {
				if ( ! empty( $cct_config[ $key ] ) && is_array( $cct_config[ $key ] ) ) {
					return $this->normalize_field_array( $cct_config[ $key ], 'option:jet_engine_active_content_types[' . $key . ']' );
				}
			}
		}

		return array();
	}

	/**
	 * Deep introspection probe for "where does JetEngine 3.8+ store CCT field
	 * definitions?". The 0.2.4 resolver tried every previously-known channel
	 * (get_arg fields/meta_fields, $args property, persisted option) and
	 * Brick Builder HQ's diagnostic showed all four returning empty AND the
	 * factory's $args has no fields/meta_fields key at all. So fields must
	 * live somewhere we haven't looked yet.
	 *
	 * This method introspects every reachable JE surface and reports what it
	 * finds. The result is a structured array suitable for rendering in the
	 * Debug tab and for ingestion by the field resolver — once we know which
	 * channel actually contains data, the resolver can claim it.
	 *
	 * Tested channels:
	 *   1. $instance->meta_fields       (direct property)
	 *   2. $instance->fields            (direct property)
	 *   3. $instance->get_meta_fields() (method)
	 *   4. $instance->get_fields()      (method)
	 *   5. Manager->meta_boxes / ->fields_manager / ->meta_fields (siblings)
	 *   6. jet_engine()->meta_boxes->* probe (the global meta-boxes service)
	 *   7. Posts of type `jet-engine` whose meta links back to this CCT slug
	 *      (JE stores meta-box configs as posts of this type)
	 *   8. wp_options entries matching `jet_engine_meta_boxes_%` /
	 *      `jet_engine_cct_%`
	 *
	 * For each channel it records: presence, sample preview, count where
	 * applicable. NEVER throws — every probe is wrapped in try/catch.
	 *
	 * @param object $cct_instance The CCT factory.
	 * @return array
	 */
	public function deep_probe_je_field_storage( $cct_instance ) {

		$slug = $this->resolve_cct_slug( $cct_instance );

		$out = array(
			'cct_slug'                         => $slug,
			'instance_class'                   => is_object( $cct_instance ) ? get_class( $cct_instance ) : 'NOT-OBJECT',
			'instance_public_props'            => array(),
			'instance_public_methods'          => array(),
			'probe_meta_fields_property'       => null,
			'probe_fields_property'            => null,
			'probe_get_meta_fields_method'     => null,
			'probe_get_fields_method'          => null,
			'manager_class'                    => null,
			'manager_public_props'             => array(),
			'jet_engine_top_level_props'       => array(),
			'jet_engine_meta_boxes_present'    => false,
			'jet_engine_meta_boxes_class'      => null,
			'jet_engine_meta_boxes_methods'    => array(),
			'jet_engine_post_count'            => 0,
			'jet_engine_post_meta_keys_sample' => array(),
			'option_keys_matched'              => array(),
			'jet_post_types_table_present'     => false,
			'jet_post_types_row_found'         => false,
			'jet_post_types_status'            => null,
			'jet_post_types_meta_fields_count' => 0,
			'jet_post_types_meta_fields_preview' => array(),
		);

		try {
			if ( is_object( $cct_instance ) ) {
				$out['instance_public_props']   = array_keys( get_object_vars( $cct_instance ) );
				$out['instance_public_methods'] = array_values( array_filter( get_class_methods( $cct_instance ), static function ( $m ) {
					return 0 !== strpos( $m, '__' );
				} ) );

				if ( isset( $cct_instance->meta_fields ) ) {
					$mf = $cct_instance->meta_fields;
					$out['probe_meta_fields_property'] = $this->summarize_field_value( $mf );
				}
				if ( isset( $cct_instance->fields ) ) {
					$f = $cct_instance->fields;
					$out['probe_fields_property'] = $this->summarize_field_value( $f );
				}

				if ( method_exists( $cct_instance, 'get_meta_fields' ) ) {
					try {
						$mf = $cct_instance->get_meta_fields();
						$out['probe_get_meta_fields_method'] = $this->summarize_field_value( $mf );
					} catch ( \Throwable $t ) {
						$out['probe_get_meta_fields_method'] = array( 'ERROR' => $t->getMessage() );
					}
				}
				if ( method_exists( $cct_instance, 'get_fields' ) ) {
					try {
						$f = $cct_instance->get_fields();
						$out['probe_get_fields_method'] = $this->summarize_field_value( $f );
					} catch ( \Throwable $t ) {
						$out['probe_get_fields_method'] = array( 'ERROR' => $t->getMessage() );
					}
				}
			}
		} catch ( \Throwable $t ) {
			$out['instance_introspection_error'] = $t->getMessage();
		}

		try {
			if ( class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
				$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();
				if ( $module && isset( $module->manager ) && is_object( $module->manager ) ) {
					$out['manager_class']        = get_class( $module->manager );
					$out['manager_public_props'] = array_keys( get_object_vars( $module->manager ) );
				}
			}
		} catch ( \Throwable $t ) {
			$out['manager_introspection_error'] = $t->getMessage();
		}

		try {
			if ( function_exists( 'jet_engine' ) ) {
				$je = jet_engine();
				if ( is_object( $je ) ) {
					$out['jet_engine_top_level_props'] = array_keys( get_object_vars( $je ) );

					if ( isset( $je->meta_boxes ) ) {
						$out['jet_engine_meta_boxes_present'] = true;
						$out['jet_engine_meta_boxes_class']   = is_object( $je->meta_boxes ) ? get_class( $je->meta_boxes ) : 'NOT-OBJECT';
						if ( is_object( $je->meta_boxes ) ) {
							$out['jet_engine_meta_boxes_methods'] = array_values( array_filter( get_class_methods( $je->meta_boxes ), static function ( $m ) {
								return 0 !== strpos( $m, '__' );
							} ) );
						}
					}
				}
			}
		} catch ( \Throwable $t ) {
			$out['jet_engine_introspection_error'] = $t->getMessage();
		}

		try {
			$je_posts = get_posts( array(
				'post_type'      => 'jet-engine',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );
			$out['jet_engine_post_count'] = is_array( $je_posts ) ? count( $je_posts ) : 0;

			if ( ! empty( $je_posts ) ) {
				global $wpdb;
				$ids_in = implode( ',', array_map( 'absint', array_slice( $je_posts, 0, 25 ) ) );
				$keys   = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_in}) ORDER BY meta_key ASC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
				$out['jet_engine_post_meta_keys_sample'] = is_array( $keys ) ? $keys : array();
			}
		} catch ( \Throwable $t ) {
			$out['jet_engine_post_probe_error'] = $t->getMessage();
		}

		try {
			global $wpdb;
			$rows = $wpdb->get_col(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'jet_engine_%' OR option_name LIKE 'jet-engine_%' ORDER BY option_name ASC LIMIT 100" // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			);
			$out['option_keys_matched'] = is_array( $rows ) ? $rows : array();
		} catch ( \Throwable $t ) {
			$out['option_probe_error'] = $t->getMessage();
		}

		try {
			global $wpdb;
			$jpt_table = $wpdb->prefix . 'jet_post_types';
			$jpt_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jpt_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out['jet_post_types_table_present'] = (bool) $jpt_exists;

			if ( $jpt_exists && $slug ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT status, meta_fields FROM `{$jpt_table}` WHERE slug = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
						$slug
					),
					ARRAY_A
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				if ( $row ) {
					$out['jet_post_types_row_found'] = true;
					$out['jet_post_types_status']    = isset( $row['status'] ) ? $row['status'] : null;

					$decoded = isset( $row['meta_fields'] ) ? maybe_unserialize( $row['meta_fields'] ) : null;
					if ( is_array( $decoded ) ) {
						$out['jet_post_types_meta_fields_count']   = count( $decoded );
						$out['jet_post_types_meta_fields_preview'] = array_slice( $decoded, 0, 3 );
					}
				}
			}
		} catch ( \Throwable $t ) {
			$out['jet_post_types_probe_error'] = $t->getMessage();
		}

		return $out;
	}

	/**
	 * Helper for deep_probe: convert an unknown value into a tiny preview that
	 * can be rendered safely in the diagnostic UI.
	 *
	 * @param mixed $value
	 * @return array
	 */
	private function summarize_field_value( $value ) {
		if ( is_array( $value ) ) {
			$first_keys = array_slice( array_keys( $value ), 0, 5 );
			$first_item = reset( $value );
			return array(
				'present'    => true,
				'php_type'   => 'array',
				'count'      => count( $value ),
				'first_keys' => $first_keys,
				'first_item' => is_array( $first_item ) ? array_intersect_key( $first_item, array_flip( array( 'name', 'type', 'title', 'label' ) ) ) : gettype( $first_item ),
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'present'  => true,
				'php_type' => 'object (' . get_class( $value ) . ')',
				'count'    => null,
			);
		}
		return array(
			'present'  => null !== $value,
			'php_type' => gettype( $value ),
			'value'    => is_scalar( $value ) ? (string) $value : null,
		);
	}

	/**
	 * Resolve a JE CCT instance's slug, trying the documented APIs first then
	 * falling back to the known property names.
	 */
	public function resolve_cct_slug( $cct_instance ) {

		if ( method_exists( $cct_instance, 'get_arg' ) ) {
			try {
				$slug = $cct_instance->get_arg( 'slug' );
				if ( ! empty( $slug ) ) {
					return $slug;
				}
			} catch ( \Throwable $t ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		foreach ( array( 'slug', 'type_id', 'type', 'cct_slug' ) as $prop ) {
			if ( property_exists( $cct_instance, $prop ) && ! empty( $cct_instance->{$prop} ) ) {
				return $cct_instance->{$prop};
			}
		}

		return '';
	}

	/* -----------------------------------------------------------------------
	 * Relation discovery
	 * -------------------------------------------------------------------- */

	public function get_all_relations() {

		$cached = $this->memo_get( 'relations' );
		if ( null !== $cached && ! empty( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$relations = array();

		try {

			if ( ! function_exists( 'jet_engine' ) || ! jet_engine() || ! isset( jet_engine()->relations ) || ! jet_engine()->relations ) {
				static $logged = false;
				if ( ! $logged ) {
					jedb_log( 'get_all_relations: JE relations not loaded yet (normal during early init)', 'debug' );
					$logged = true;
				}
				return $this->maybe_cache( 'relations', $relations );
			}

			$raw = jet_engine()->relations->get_active_relations();

			if ( empty( $raw ) || ! is_array( $raw ) ) {
				return $this->maybe_cache( 'relations', $relations );
			}

			foreach ( $raw as $relation_id => $relation_obj ) {

				if ( ! is_object( $relation_obj ) || ! method_exists( $relation_obj, 'get_args' ) ) {
					continue;
				}

				try {
					$args = $relation_obj->get_args();

					$name = '';
					if ( ! empty( $args['name'] ) ) {
						$name = $args['name'];
					} elseif ( ! empty( $args['labels']['name'] ) ) {
						$name = $args['labels']['name'];
					} else {
						$parent_name = $this->get_relation_object_name( isset( $args['parent_object'] ) ? $args['parent_object'] : '' );
						$child_name  = $this->get_relation_object_name( isset( $args['child_object']  ) ? $args['child_object']  : '' );
						$name        = $parent_name . ' → ' . $child_name;
					}

					$relations[] = array(
						'id'            => $relation_id,
						'name'          => $name,
						'parent_object' => isset( $args['parent_object'] ) ? $args['parent_object'] : '',
						'child_object'  => isset( $args['child_object']  ) ? $args['child_object']  : '',
						'type'          => isset( $args['type'] )          ? $args['type']          : 'one_to_many',
						'parent_rel'    => isset( $args['parent_rel'] )    ? $args['parent_rel']    : null,
						'is_hierarchy'  => ! empty( $args['parent_rel'] ),
						'table_exists'  => $this->relation_table_exists( $relation_id ),
						'table_name'    => $wpdb->prefix . 'jet_rel_' . $relation_id,
					);
				} catch ( \Throwable $t ) {
					jedb_log( 'get_all_relations: relation threw — skipping', 'error', array( 'relation_id' => $relation_id, 'error' => $t->getMessage() ) );
				}
			}
		} catch ( \Throwable $t ) {
			jedb_log( 'get_all_relations: top-level exception', 'error', array(
				'error' => $t->getMessage(),
				'file'  => $t->getFile(),
				'line'  => $t->getLine(),
			) );
		}

		return $this->maybe_cache( 'relations', $relations );
	}

	public function get_relation( $relation_id ) {
		foreach ( $this->get_all_relations() as $rel ) {
			if ( (string) $rel['id'] === (string) $relation_id ) {
				return $rel;
			}
		}
		return null;
	}

	public function relation_table_exists( $relation_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_rel_' . absint( $relation_id );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ! empty( $found );
	}

	/* -----------------------------------------------------------------------
	 * Relation object parsing helpers (cct::, posts::, terms::)
	 * -------------------------------------------------------------------- */

	public function parse_relation_object( $relation_obj ) {

		if ( ! is_string( $relation_obj ) ) {
			return array( 'type' => 'unknown', 'slug' => '' );
		}

		if ( false !== strpos( $relation_obj, '::' ) ) {
			list( $type, $slug ) = explode( '::', $relation_obj, 2 );
			return array( 'type' => $type, 'slug' => $slug );
		}

		return array( 'type' => 'cct', 'slug' => $relation_obj );
	}

	public function get_relation_object_name( $relation_obj ) {

		$parsed = $this->parse_relation_object( $relation_obj );

		switch ( $parsed['type'] ) {
			case 'cct':
				$cct = $this->get_cct( $parsed['slug'] );
				return $cct ? $cct['name'] : ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );

			case 'terms':
				$tax = get_taxonomy( $parsed['slug'] );
				return $tax ? $tax->label : ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );

			case 'posts':
				$pt = get_post_type_object( $parsed['slug'] );
				return $pt ? $pt->label : ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );

			default:
				return ucfirst( str_replace( '_', ' ', $parsed['slug'] ) );
		}
	}

	/**
	 * Convert a discovery target to its registry-side slug.
	 * "cct::available_sets_data" stays "cct::available_sets_data"; legacy bare
	 * CCT slugs get the "cct::" prefix. This is what Target_Registry uses to
	 * resolve adapters from relation objects.
	 */
	public function relation_object_to_target_slug( $relation_obj ) {

		$parsed = $this->parse_relation_object( $relation_obj );

		switch ( $parsed['type'] ) {
			case 'cct':
				return 'cct::' . $parsed['slug'];
			case 'posts':
				return 'posts::' . $parsed['slug'];
			case 'terms':
				return 'terms::' . $parsed['slug'];
			default:
				return $parsed['slug'];
		}
	}

	/* -----------------------------------------------------------------------
	 * Relations involving a given CCT (with recursive ancestor / descendant)
	 * -------------------------------------------------------------------- */

	public function get_relations_for_cct( $cct_slug, $position = 'both', $include_hierarchy = false ) {

		$all = $this->get_all_relations();
		$out = array();

		foreach ( $all as $relation ) {

			$is_parent = $this->is_cct_in_relation( $cct_slug, $relation['parent_object'] );
			$is_child  = $this->is_cct_in_relation( $cct_slug, $relation['child_object'] );

			$include = false;
			switch ( $position ) {
				case 'parent': $include = $is_parent; break;
				case 'child':  $include = $is_child;  break;
				default:       $include = $is_parent || $is_child;
			}

			if ( $include ) {
				$relation['cct_position'] = $is_parent ? 'parent' : 'child';
				$out[] = $relation;
			}
		}

		if ( $include_hierarchy ) {
			$out = array_merge(
				$out,
				$this->get_grandparent_relations( $cct_slug ),
				$this->get_grandchild_relations( $cct_slug )
			);
		}

		return $out;
	}

	public function get_grandparent_relations( $cct_slug, $max_depth = 10 ) {

		$all     = $this->get_all_relations();
		$results = array();
		$direct  = $this->get_relations_for_cct( $cct_slug, 'child', false );

		foreach ( $direct as $parent_rel ) {
			$results = array_merge(
				$results,
				$this->find_ancestors_recursive(
					$parent_rel['parent_object'],
					$parent_rel['id'],
					$cct_slug,
					$all,
					1,
					$max_depth
				)
			);
		}

		return $results;
	}

	public function get_grandchild_relations( $cct_slug, $max_depth = 10 ) {

		$all     = $this->get_all_relations();
		$results = array();
		$direct  = $this->get_relations_for_cct( $cct_slug, 'parent', false );

		foreach ( $direct as $child_rel ) {
			$results = array_merge(
				$results,
				$this->find_descendants_recursive(
					$child_rel['child_object'],
					$child_rel['id'],
					$cct_slug,
					$all,
					1,
					$max_depth
				)
			);
		}

		return $results;
	}

	private function find_ancestors_recursive( $current, $connecting_id, $original, $all, $level, $max_depth ) {

		if ( $level > $max_depth ) {
			jedb_log( 'Max depth reached in ancestor traversal', 'debug', array( 'current' => $current, 'level' => $level ) );
			return array();
		}

		$out = array();

		foreach ( $all as $rel ) {
			if ( $current === $rel['child_object'] ) {
				$rel['cct_position']    = 'grandparent';
				$rel['hierarchy_level'] = $level;
				$rel['grandparent_path'] = array(
					'grandparent_object' => $rel['parent_object'],
					'parent_object'      => $current,
					'child_object'       => $original,
					'parent_relation_id' => $connecting_id,
					'hierarchy_level'    => $level,
				);
				$out[] = $rel;

				$out = array_merge(
					$out,
					$this->find_ancestors_recursive(
						$rel['parent_object'],
						$rel['id'],
						$original,
						$all,
						$level + 1,
						$max_depth
					)
				);
			}
		}

		return $out;
	}

	private function find_descendants_recursive( $current, $connecting_id, $original, $all, $level, $max_depth ) {

		if ( $level > $max_depth ) {
			jedb_log( 'Max depth reached in descendant traversal', 'debug', array( 'current' => $current, 'level' => $level ) );
			return array();
		}

		$out = array();

		foreach ( $all as $rel ) {
			if ( $current === $rel['parent_object'] ) {
				$rel['cct_position']    = 'grandchild';
				$rel['hierarchy_level'] = $level;
				$rel['grandchild_path'] = array(
					'grandparent_object' => $original,
					'parent_object'      => $current,
					'child_object'       => $rel['child_object'],
					'parent_relation_id' => $connecting_id,
					'hierarchy_level'    => $level,
				);
				$out[] = $rel;

				$out = array_merge(
					$out,
					$this->find_descendants_recursive(
						$rel['child_object'],
						$rel['id'],
						$original,
						$all,
						$level + 1,
						$max_depth
					)
				);
			}
		}

		return $out;
	}

	private function is_cct_in_relation( $cct_slug, $relation_obj ) {

		if ( ! is_string( $relation_obj ) ) {
			return false;
		}

		if ( 0 === strpos( $relation_obj, 'cct::' ) ) {
			return substr( $relation_obj, 5 ) === $cct_slug;
		}

		if ( 0 === strpos( $relation_obj, 'terms::' ) || 0 === strpos( $relation_obj, 'posts::' ) ) {
			return false;
		}

		return $relation_obj === $cct_slug;
	}

	/* -----------------------------------------------------------------------
	 * Post-type discovery
	 * -------------------------------------------------------------------- */

	/**
	 * Discover public post types. Excludes the WP defaults that aren't useful
	 * as bridge targets unless the caller opts them in.
	 *
	 * @param array $exclude  Slugs to skip.
	 * @return array<int,array{slug:string,label:string,count:int,supports_woo_adapter:bool}>
	 */
	public function get_all_public_post_types( $exclude = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) ) {

		$cached_key = 'post_types::' . md5( wp_json_encode( $exclude ) );
		$cached     = $this->memo_get( $cached_key );
		if ( null !== $cached && ! empty( $cached ) ) {
			return $cached;
		}

		$out = array();

		try {

			$custom = get_post_types(
				array(
					'public'   => true,
					'_builtin' => false,
				),
				'objects'
			);

			$builtin = get_post_types(
				array( '_builtin' => true ),
				'objects'
			);

			$all_pts = array_merge(
				is_array( $custom )  ? $custom  : array(),
				is_array( $builtin ) ? $builtin : array()
			);

			$wc_active = class_exists( 'WooCommerce' );

			foreach ( $all_pts as $slug => $obj ) {

				if ( in_array( $slug, $exclude, true ) ) {
					continue;
				}

				try {
					$counts = wp_count_posts( $slug );
					$count  = isset( $counts->publish ) ? (int) $counts->publish : 0;
				} catch ( \Throwable $t ) {
					$count = 0;
					jedb_log( 'get_all_public_post_types: wp_count_posts threw', 'warning', array(
						'slug'  => $slug,
						'error' => $t->getMessage(),
					) );
				}

				$label          = isset( $obj->labels->name )          ? $obj->labels->name          : $slug;
				$singular_label = isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $slug;

				$out[] = array(
					'slug'                 => $slug,
					'label'                => $label,
					'singular_label'       => $singular_label,
					'count'                => $count,
					'supports_woo_adapter' => $wc_active && in_array( $slug, array( 'product', 'product_variation' ), true ),
					'is_woo'               => in_array( $slug, array( 'product', 'product_variation' ), true ),
				);
			}

			usort(
				$out,
				static function ( $a, $b ) {
					return strcmp( $a['label'], $b['label'] );
				}
			);
		} catch ( \Throwable $t ) {
			jedb_log( 'get_all_public_post_types: top-level exception', 'error', array(
				'error' => $t->getMessage(),
				'file'  => $t->getFile(),
				'line'  => $t->getLine(),
			) );
		}

		return $this->maybe_cache( $cached_key, $out );
	}

	/* -----------------------------------------------------------------------
	 * WooCommerce discovery
	 * -------------------------------------------------------------------- */

	public function is_wc_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Return product counts grouped by product type (simple, variable, grouped, …).
	 */
	public function get_woo_product_type_counts() {

		if ( ! $this->is_wc_active() ) {
			return array();
		}

		$cached = $this->memo_get( 'woo_product_types' );
		if ( null !== $cached ) {
			return $cached;
		}

		$types = function_exists( 'wc_get_product_types' ) ? wc_get_product_types() : array(
			'simple'   => 'Simple',
			'variable' => 'Variable',
			'grouped'  => 'Grouped',
			'external' => 'External',
		);

		$out = array();

		foreach ( $types as $type_slug => $type_label ) {

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_type',
							'field'    => 'slug',
							'terms'    => $type_slug,
						),
					),
				)
			);

			$out[] = array(
				'slug'  => $type_slug,
				'label' => $type_label,
				'count' => (int) $query->found_posts,
			);
		}

		return $this->memo_set( 'woo_product_types', $out );
	}

	public function get_woo_variation_count() {

		if ( ! $this->is_wc_active() ) {
			return 0;
		}

		$cached = $this->memo_get( 'woo_variation_count' );
		if ( null !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $this->memo_set( 'woo_variation_count', (int) $query->found_posts );
	}

	public function get_woo_taxonomies() {

		if ( ! $this->is_wc_active() ) {
			return array();
		}

		$cached = $this->memo_get( 'woo_taxonomies' );
		if ( null !== $cached ) {
			return $cached;
		}

		$taxonomies = get_object_taxonomies( 'product', 'objects' );
		$out        = array();

		foreach ( $taxonomies as $tax_slug => $tax_obj ) {

			$count = (int) wp_count_terms(
				array(
					'taxonomy'   => $tax_slug,
					'hide_empty' => false,
				)
			);

			$out[] = array(
				'slug'  => $tax_slug,
				'label' => $tax_obj->label,
				'count' => $count,
				'is_attribute' => 0 === strpos( $tax_slug, 'pa_' ),
			);
		}

		return $this->memo_set( 'woo_taxonomies', $out );
	}

	/* -----------------------------------------------------------------------
	 * Meta-key discovery (whitelist + sample)
	 * -------------------------------------------------------------------- */

	/**
	 * Get the user-defined meta-key whitelist for a given target slug.
	 *
	 * @return array<int,string>
	 */
	public function get_meta_whitelist_for( $target_slug ) {

		$opt = get_option( JEDB_OPTION_META_WHITELIST, array() );
		if ( ! is_array( $opt ) ) {
			return array();
		}

		if ( ! empty( $opt[ $target_slug ] ) && is_array( $opt[ $target_slug ] ) ) {
			return array_values( array_filter( array_map( 'strval', $opt[ $target_slug ] ) ) );
		}

		return array();
	}

	/**
	 * Sample N existing posts of a post type and return all unique meta keys.
	 * Used as a fallback when the whitelist for that target is empty.
	 *
	 * @param string $post_type
	 * @param int    $sample_size
	 * @return array<int,string>
	 */
	public function sample_meta_keys_for_post_type( $post_type, $sample_size = 25 ) {

		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','private','draft') ORDER BY ID DESC LIMIT %d",
				$post_type,
				absint( $sample_size )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) ORDER BY meta_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				...array_map( 'absint', $ids )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_values( array_filter( $keys, static function ( $k ) {
			return is_string( $k ) && '' !== $k;
		} ) );
	}

	/* -----------------------------------------------------------------------
	 * Top-level dependency check (used in Targets tab + Debug)
	 * -------------------------------------------------------------------- */

	public function verify_dependencies() {

		$status = array(
			'jetengine'       => function_exists( 'jet_engine' ),
			'cct_module'      => class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ),
			'relations_module'=> function_exists( 'jet_engine' ) && jet_engine() && isset( jet_engine()->relations ),
			'woocommerce'     => $this->is_wc_active(),
		);

		$status['core_ok'] = $status['jetengine'] && $status['cct_module'] && $status['relations_module'];

		return $status;
	}
}
