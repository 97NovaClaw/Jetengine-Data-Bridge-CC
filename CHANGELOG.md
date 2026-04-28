# Changelog

All notable changes to this plugin are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.1] — 2026-02-28

### Added — Debug tab + Phase 1 hardening

- **Debug tab** under JE Data Bridge → Debug:
  - Toggle button to enable/disable file logging from one click.
  - Live tail of the last 500 lines (256 KB cap), styled console-dark.
  - One-click "Download log" that streams the file as a timestamped
    attachment for easy sharing.
  - "Clear log" with a confirm prompt; also wipes the rotated `.1.log`.
  - "Run discovery diagnostic" button that auto-enables logging,
    runs a deep dump of every discovery channel (CCT module presence,
    raw manager output, every `get_post_types` flavor, JEDB_Discovery
    output, every catch path), writes a structured summary to the log,
    and renders a result panel with green/red pills for each check plus
    any caught exceptions.
- Sample meta-key discovery added to the resilient code path; previously
  the bootstrap could silently fail at this stage with no log entry.

### Fixed — Phase 1 hardening

- **Discovery: every method now wraps every external call in try/catch.**
  Exceptions are logged with file/line and the method returns the partial
  result rather than blanking the page. Per-record exceptions skip just
  that record.
- **Discovery: empty results are no longer persisted to the transient.**
  Previously, an early-init request that found 0 CCTs/CPTs would lock
  every later request to "0 forever" until manual cache flush. Now empty
  results are memoized for the request only and re-tried next request.
- **Registry bootstrap is now exception-safe end to end.** Every adapter
  constructor is wrapped individually; a single broken adapter no longer
  prevents the rest from registering.
- **Excluded post-type list expanded** to cover all WP 6.x block-editor
  internals (`wp_block`, `wp_template`, `wp_template_part`,
  `wp_navigation`, `wp_global_styles`, `customize_changeset`, etc.) so
  the Targets list isn't polluted with internal types.
- **Defensive WC version + JE version detection** continues to apply — no
  regression vs 0.2.0.

### Changed

- Default value of `enable_debug_log` is now `true` for fresh installs.
- DB version bumped to **1.1.0** with a one-time migration that
  auto-enables `enable_debug_log` for existing installs upgrading from
  1.0.0. (Idempotent; only flips the toggle if it was off.)
- `JEDB_Plugin::run_migrations()` introduced as the single home for
  per-version data migrations going forward.
- Plugin version bumped to **0.2.1**.

## [0.2.0] — 2026-02-28

### Added — Phase 1: Discovery + Targets

- **`JEDB_Discovery`** — single source of truth for "what data lives on this
  site". Merges and generalizes the discovery classes from Jet Engine Relation
  Injector (CCTs, Relations, recursive grandparent / grandchild traversal) and
  PAC Vehicle Data Manager (CCT field schemas), plus new bits: public CPTs,
  WooCommerce product types and counts, variations, taxonomies, and per-target
  meta-key whitelisting with auto-sampling fallback. Results cached to a 5-min
  transient with manual flush.
- **`JEDB_Data_Target`** interface — universal contract every record store
  implements (`get_slug`, `get_label`, `get_kind`, `exists`, `get`, `update`,
  `create`, `get_field_schema`, `supports_relations`, `count`, `list_records`).
- **`JEDB_Target_Abstract`** base class for shared utilities.
- Four concrete adapters:
  - `JEDB_Target_CCT` — read/write CCT items via the JE manager API.
  - `JEDB_Target_CPT` — read/write any post type via the WP API, with
    schema = standard post columns + per-target meta whitelist (or sampled
    keys when whitelist is empty).
  - `JEDB_Target_Woo_Product` — HPOS-safe via `WC_Product` typed setters
    and `->save()`. Schema covers core, inventory, pricing, shipping, media,
    taxonomy, downloads, and linked-product fields. Meta whitelist appended,
    with default-meta noise filtered out.
  - `JEDB_Target_Woo_Variation` — HPOS-safe via `WC_Product_Variation`
    typed setters. Smaller schema (no taxonomies / cross-sells), includes
    attribute selection.
- **`JEDB_Target_Registry`** — flat slug → adapter map. Auto-bootstraps on
  first access: registers a `Target_CCT` per CCT, `Target_CPT` per public
  post type, then replaces `posts::product` and `posts::product_variation`
  with the Woo-specific adapters when WooCommerce is active. Fires
  `jedb/data_target/register` so third-party code can register custom
  targets or replace ours.
- **Targets admin tab** — new read-only inventory under JE Data Bridge →
  Targets, summarizing every CCT, every public CPT (with adapter type
  pills), every Woo product type and count, every variation, every Woo
  taxonomy (standard vs attribute), and every active JE relation (with
  storage table existence check). "Refresh discovery cache" button flushes
  both the discovery transient and the registry bootstrap.
- `JEDB_Plugin` exposes `targets()` and `discovery()` accessors so future
  subsystems can grab the singletons without re-requiring class files.
- Admin CSS extended with summary cards and section heading styling.

### Notes

- Update / create paths on every adapter are wired and HPOS-safe but are
  not exercised yet — Phase 2 (Relation Injector) is the first phase that
  actually writes through them.
- `JEDB_VERSION` bumped to 0.2.0 to mark the first feature-complete phase
  beyond the Phase 0 scaffold.

## [0.1.0] — 2026-02-28

### Added — Phase 0 scaffold

- Bootstrap (`je-data-bridge-cc.php`) with plugin header, constants, activation hook, and JetEngine ≥ 3.3.1 dependency check.
- `JEDB_Plugin` singleton with lazy admin loading and schema-version upgrade dispatch.
- `JEDB_Config_DB` installer that creates four custom tables via `dbDelta`:
  - `wp_jedb_relation_configs`
  - `wp_jedb_flatten_configs`
  - `wp_jedb_sync_log`
  - `wp_jedb_snippets`
- `JEDB_Snippet_Installer` that creates `wp-content/uploads/jedb-snippets/` with `.htaccess` (`deny from all`) and a silent `index.php`.
- `JEDB_Admin_Shell` with top-level "JE Data Bridge" menu, tab router, and the `jedb/admin/tabs` filter for future tab registration.
- Phase 0 "Status" tab showing table existence, snippet folder readiness, and detected dependency versions (JE / WC / HPOS / PHP / WP / DB schema).
- `jedb_log()` debug helper writing to `wp-content/uploads/jedb-debug.log` with 5 MB rotation, gated by the `enable_debug_log` setting (default OFF).
- `uninstall.php` that drops every custom table, deletes every plugin option, and optionally wipes the snippets folder (off by default).
- Minimal admin CSS, MD/TXT readme files, GPL v2 license, `.gitignore`.

### Notes

- No sync logic is shipped in 0.1.0 — this release only verifies that the activation pipeline runs cleanly. See `BUILD-PLAN.md` §7 for what each future phase delivers.
