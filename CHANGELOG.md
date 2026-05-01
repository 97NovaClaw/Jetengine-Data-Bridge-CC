# Changelog

All notable changes to this plugin are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.1] — 2026-05-01

### Fixed

- **Picker on CCT edit screen now sees JE-auto-created products.**
  Phase 2 used `wc_get_products()` to populate the picker's search
  results, which filters by `_visibility` meta and the
  `wc_product_meta_lookup` table — both populated only by
  `WC_Product->save()`. Posts created via raw `wp_insert_post()`
  (which is what JetEngine Relations' auto-create does) are
  therefore invisible to `wc_get_products()` until they've been saved
  through the WC API once. `Target_Woo_Product::list_records()` now
  uses `WP_Query` directly. Documented as L-017.

### Added — design documentation for Phase 3 + Phase 4

No new feature code beyond the picker bug fix above. Substantial
documentation locking in the bidirectional sync architecture before
Phase 3 implementation begins:

- **L-016** — JetEngine auto-creates the related post on CCT save in
  ONE direction only. Reverse direction (post → CCT) is not handled
  by JE; our plugin owns it entirely.
- **L-017** — `wc_get_products()` is unreliable for picker / discovery
  use cases because of its visibility-meta + lookup-table filtering.
  Use `WP_Query` for discovery, reserve `WC_Product` for read/write
  on already-identified records.
- **L-018** — Phase 3 flatten engine MUST register at priority >= 20
  on JE CCT save hooks so JE's own auto-create has finished first.
  Phase 2's transaction processor stays at priority 10 because it
  handles explicit picker-driven attaches (no JE-auto-create
  conflict possible).
- **L-019** — RI's primary historical purpose was taxonomy
  attachment, not relation attachment. Plugin's `terms::*` adapter
  support is a deferred capability for Phase 2.5+ / Phase 3.
- **L-020** — Bidirectional sync requires explicit reverse-direction
  handling. The two flows (CCT → post and post → CCT) are NOT
  inverses, run on different hooks, and need separate engine paths.

- **BUILD-PLAN updates:**
  - §4.9 expanded with explicit **trigger taxonomy** (the *when* axis)
    separate from condition (the *whether* axis). v1 trigger types:
    `cct_save`, `cct_field_changed`, `post_save`, `wc_product_save`,
    `term_assigned`, `manual`, `bulk`. Cron-based triggers deferred.
  - New §4.10 — Reverse-direction sync (post → CCT) — full engine
    flow including the `auto_create_target_when_unlinked` opt-in
    flag (default off) and explicit cycle-prevention notes via
    `Sync_Guard` origin tagging.
  - Decisions Log additions:
    - **D-17** JE auto-create is one-directional; reverse is ours.
    - **D-18** Trigger taxonomy as a separate axis from condition.
    - **D-19** Hook priority contract (>= 20 for Phase 3+ engines).

### Changed

- Plugin version bumped to **0.3.1** (patch — picker bug fix +
  documentation expansion; no schema changes; DB version stays at
  1.1.0).

## [0.3.0] — 2026-02-28

### Added — Phase 2: Relation Injector port

First phase that writes to JE-managed tables. Implementation strictly
follows the verified contract documented in `LESSONS-LEARNED.md` L-014
(direct `$wpdb->insert()` on `{prefix}jet_rel_{id}` with the exact
column set, idempotent duplicate-check, type-aware clearing).

**JE Relations themselves are NEVER created or edited by this plugin.**
They live entirely in JetEngine → Relations. The Relations tab in our
admin only configures *which existing relations* the picker UI exposes
on each CCT edit screen. Locked decision D-13.

#### New files (10)

- `includes/relations/class-relation-config-manager.php` — CRUD wrapper
  around `wp_jedb_relation_configs`. **One row per CCT** (matches RI's
  storage model). Each row's `config_json` carries the array of which
  JE Relation IDs to enable on that CCT, plus per-relation display-field
  choices and UI preferences. The Phase-0 schema's `relation_id` and
  `direction` columns stay NULL/empty for relation-config rows
  (vestigial; will be cleaned up in a future schema migration —
  decision A from the Phase 2 design discussion).
- `includes/relations/class-relation-attacher.php` — direct-SQL writer
  per L-014. Public API: `attach()`, `detach()`, `relation_exists()`,
  `clear_existing_for_side()`, `get_relation_object()`,
  `determine_side()`, `determine_side_for_post_type()`. Idempotent
  duplicate-check, type-aware clearing for 1:1 and 1:M, append for
  M:M. Reusable from Phase 4's product-side processor.
- `includes/relations/class-data-broker.php` — single AJAX endpoint
  `wp_ajax_jedb_relation_search_items` that delegates to whichever
  `JEDB_Data_Target` matches the requested object slug. Adapter-aware,
  so the same endpoint serves CCT, CPT, Woo product, and Woo
  variation searches uniformly.
- `includes/relations/class-runtime-loader.php` — detects CCT edit
  pages (`admin.php?page=jet-cct-{slug}`), looks up the config, builds
  per-relation payload (with `cct_side` resolved from the relation's
  parent/child object strings), enqueues JS + CSS, localizes via
  `wp_localize_script` as `window.jedbRelationConfig`.
- `includes/relations/class-transaction-processor.php` — registers
  BOTH `jet-engine/custom-content-types/created-item/{slug}` AND
  `updated-item/{slug}` hooks for every CCT with an enabled relation
  config. Different argument shapes per L-014 (created has
  `$item_id`, updated does not — extracted from `$item['_ID']`). Each
  hook reads `$_POST['jedb_relations']`, verifies the
  `jedb_relations_nonce`, dispatches to the attacher. Wrapped in
  try/catch end-to-end so a fatal in our code never blocks the CCT
  save itself.
- `includes/admin/class-tab-relations.php` — admin tab class. Three
  POST handlers: save config, toggle enabled, delete. Helper
  `get_relations_per_cct()` returns the list of valid JE Relations
  per CCT (filtered to ones whose endpoints resolve to registered
  targets) for the picker dropdowns.
- `templates/admin/tab-relations.php` — main template. Lists existing
  config cards + the "Add a new configuration" form with a CCT
  dropdown that, on change, populates the relations checkbox list
  client-side from a JSON map embedded in the page.
- `templates/admin/relation-config-card.php` — single config card.
  Per-relation checkbox row with type, this-CCT-side, other-side
  label, and storage-table OK/MISSING pill. Toggle, edit, delete
  forms.
- `assets/js/relation-injector.js` — picker UI. Ports RI's verified
  flow: form-poll for `form[action*="jet-cct-save-item"]` (or
  fallback selector); inject "Relations" block before submit
  button; modal-based search with 300ms debounce; chip rendering for
  selected items; serialize selections into hidden input on form
  submit. **No cascading / hierarchical UI in v1** — deferred to
  Phase 2.5.
- `assets/css/relation-injector.css` — styles for both the CCT
  edit-screen picker block + modal, and the Relations admin tab's
  config cards.

#### Modified files (2)

- `includes/class-plugin.php` — `load_core()` now requires every new
  relation class file and instantiates the three runtime singletons
  (data broker, runtime loader, transaction processor).
- `includes/admin/class-admin-shell.php` — registers `JEDB_Tab_Relations`
  alongside the existing tab classes.

### Deferred to Phase 2.5+

- **Cascading hierarchical relations** (grandparent / grandchild). RI's
  most complex code (~600 lines including the cascading modal); doesn't
  apply to BBHQ's flat 1:1 bridges. Phase 2.5 if/when needed.
- **"Add New" related-item creation from the picker modal.** Cleaner
  to build alongside Phase 4's Bridge meta box which has the same UX
  needs. Picker is select-existing only in v1.
- **Per-relation `display_field` selection.** Auto-default via Phase 1's
  `Target_*::list_records()` heuristic (`name`/`title`/`set_name`/
  `mosaic_name`/`label` → first match). Add an explicit picker in
  Phase 2.5 if the heuristic ever picks wrong.
- **Per-config `injection_point` setting** (`before_save` vs
  `after_fields`). Hardcoded to `before_save` in v1. RI editors never
  touched this knob.

### Phase 2 punch list (verified by writing test bridges, NOT by static review)

These three remain to confirm/refute on staging. From L-014:

1. **JE cache invalidation post-insert.** Direct SQL works, but does it
   leave stale data in JE listing-grid result caches, smart-filter
   query caches, or transients? Test plan: configure relation #8
   (Available Set → Product), create a new CCT row with picker
   selection, immediately load a JE Listing on the front-end — does
   the new connection appear?
2. **`many_to_many` UNIQUE constraint.** RI assumes you can re-insert
   the same `(parent_object_id, child_object_id)` pair on M:M. Need
   to test with a real M:M relation if/when one exists on the test
   site.
3. **Relation row "updated" timestamp.** Probably we just leave it
   alone (relation rows are connection records, not data records),
   but worth confirming once.

Findings will be appended to LESSONS-LEARNED.md as L-016+ once tested.

### Changed

- Plugin version bumped to **0.3.0** (minor bump because this is the
  first phase that writes to JE-managed tables; major architectural
  milestone). DB version stays at 1.1.0 (no schema changes).

## [0.2.7] — 2026-02-28

### Fixed — JE 3.8+ field-schema resolution + prefix discipline

- **CCT field types now resolve correctly on JetEngine 3.8+.** New
  primary channel `JEDB_Discovery::get_cct_fields_from_jet_post_types_table()`
  reads from `{prefix}jet_post_types WHERE slug=%s AND status='content-type'`
  and `maybe_unserialize`s the `meta_fields` blob. Becomes channel #1 in
  the resolver; older channels remain as fallbacks for older JE versions.
  Each returned field carries a `source` key so the diagnostic shows
  exactly which channel produced the data on this site. See
  `LESSONS-LEARNED.md` L-007 for the full investigation.
- **Prefix discipline bug.** `Discovery::get_all_relations()` was
  emitting a hardcoded `'wp_jet_rel_' . $relation_id` display string;
  now uses `$wpdb->prefix . 'jet_rel_' . $relation_id`. Display-only
  but matters on sites with non-default `$table_prefix`. Caught by an
  audit grep on 2026-04-29; documented as L-008.

### Added — JE Glossary discovery + deep-probe enhancement

- **`JEDB_Discovery::get_all_glossaries()`** — reads
  `WHERE status='glossary'` from `{prefix}jet_post_types` and returns
  `[ id, slug, label, values:[{value, label}, ...] ]` per glossary. The
  Phase 4 Bridge meta box will use this to resolve `select`/`radio`
  field options to human-readable labels. Cached via the existing
  transient layer.
- **Deep probe gains `{prefix}jet_post_types` lookup.** New rows in
  the per-CCT diagnostic show: table presence, this CCT's row presence,
  the row's `status` value, the `meta_fields` count, and a 3-entry
  preview of `name [type]` pairs. Future regressions of this storage
  model will be obvious in one screenshot.

### Documentation — major BUILD-PLAN + LESSONS-LEARNED expansion

- **`LESSONS-LEARNED.md`** — created in the previous session, expanded
  this version with:
  - L-012: WC product-edit meta-box injection has rough edges; Phase 4
    field-render-hint is adapter-owned via `is_natively_rendered()`.
  - L-013: Conditional bridges (DSL + snippet fallback) keep individual
    bridges 1:1 while supporting M:1 and 1:N source→targets.
  - L-014: Verified `{prefix}jet_rel_{id}` table structure (DESCRIBE
    output captured) and write semantics. Outstanding: confirm exact
    JE write-API method via RI source review before Phase 2.
  - L-015: Woo product variations are for purchase options, NOT for
    bridge-type disambiguation. Phase 4b unchanged; bridge-type
    routing handled by §4.9 conditional engine.
- **`BUILD-PLAN.md`** added six new sections / decisions:
  - §3.4 — JetEngine storage model (canonical reference for where each
    kind of JE data lives, with `wp_jet_post_types` `status` value
    dictionary and the resolver's channel order).
  - §3.5 — Bridge condition model (declarative DSL grammar v1 +
    snippet escape hatch).
  - §4.5 — Rewritten link strategy (JE Relations primary,
    `cct_single_post_id` special case, NO `_jedb_bridge_cct_id` meta).
  - §4.7 — Tightened variation framing (variations = purchase options
    for ONE source record, not bridge-type routing).
  - §4.8 — Updated to document push/pull split per mapping.
  - §4.9 — Conditional Sync Engine spec (engine flow, `$context`
    shape, sync-log status taxonomy, failure-mode policy).
  - Decisions Log additions: D-10 (link strategy), D-11 (bidirectional
    transformer chains), D-12 (explicit-only mapping), D-13 (manual
    JE Relation creation), D-14 (conditional bridges), D-15 (mandatory
    fields), D-16 (field-render-hint).

### Changed

- Plugin version bumped to **0.2.7** (no schema changes; DB version
  stays at 1.1.0).

## [0.2.6] — 2026-02-28

### Added — Deep JE 3.8+ field-storage probe

The 0.2.4 multi-source resolver tried every previously-known channel
for CCT field configs (`get_arg("fields")`, `get_arg("meta_fields")`,
`$instance->args["fields"]`, `$instance->args["meta_fields"]`, the
persisted `jet_engine_active_content_types` option). Brick Builder
HQ's diagnostic showed all four returning empty AND that
`$instance->args` on JE 3.8.5 has no `fields`/`meta_fields` key at
all — the args carry only CCT-level settings (single-page support,
REST permissions, admin column config). So fields must live somewhere
we haven't looked yet.

- New `JEDB_Discovery::deep_probe_je_field_storage()` introspects
  every reachable JetEngine surface and reports what it finds.
  Tested channels:
  1. `$instance->meta_fields` (direct property)
  2. `$instance->fields` (direct property)
  3. `$instance->get_meta_fields()` (method)
  4. `$instance->get_fields()` (method)
  5. Manager class + sibling property names (`meta_boxes`,
     `fields_manager`, etc.)
  6. `jet_engine()->meta_boxes` (the global meta-boxes service) —
     class name and public method list
  7. Posts of type `jet-engine` (JE stores meta-box configs as posts
     of this type) — count + sample of meta keys
  8. `wp_options` entries matching `jet_engine_%` / `jet-engine_%`
- Each probe is wrapped in try/catch and reports presence + sample
  preview + count where applicable.
- New "Deep JE 3.8+ probe" collapsible panel in the per-CCT
  diagnostic renders all of this, plus class names + public method
  lists for everything reachable. Once we see which channel
  contains the field config on JE 3.8+, the resolver gets a new
  channel and field types come back.

### Changed

- Plugin version bumped to **0.2.6** (no schema changes; DB version
  stays at 1.1.0).

## [0.2.5] — 2026-02-28

### Changed — JE system columns surfaced as readonly system fields

Earlier versions hard-filtered every JetEngine system column
(`cct_status`, `cct_author_id`, `cct_created`, `cct_modified`,
`cct_single_post_id`) out of the schema. Several of those columns are
useful for upcoming phases — particularly `cct_modified` for the
Phase 7+ last-write-wins conflict resolution (BUILD-PLAN D-2) and
`cct_single_post_id` for the Phase 4 Bridge meta box's "use the JE
native single-page link" path (BUILD-PLAN §4.6) — so they're now kept
in the schema as **readonly system fields** instead of being hidden.

- New constant `JEDB_Discovery::CCT_SYSTEM_COLUMN_NAMES` (alias for
  the deprecated `CCT_INTERNAL_COLUMN_NAMES`). Discovery still strips
  these from the user-fields list so `cct_meta['fields']` contains only
  what the editor authored — the system fields are injected separately
  by the target adapter.
- `JEDB_Target_CCT::get_field_schema()` now lays out the schema as:
  1. `_ID` (system, readonly, group=system)
  2. JE system columns that exist on this CCT, each with `readonly=true`,
     `group=system`, friendly labels (`Status (system)`, `Last Modified
     (system)`, etc.), and a `jedb_role` marker (`jet_status`,
     `jet_modified_at`, `jet_created_at`, `jet_author`,
     `native_single_page_link`).
  3. User fields from `cct_meta['fields']`.
  - `cct_single_post_id` is added **only when the column physically
    exists** in the CCT table — i.e. when "Has Single Page" is enabled
    on that CCT. The `jedb_role => 'native_single_page_link'` marker
    will let the Phase 4 Bridge meta box detect this and offer the JE
    native link as the bridge target on those CCTs (e.g.
    `featured_parts_data` and `story_bricks_data` in the Brick Builder
    HQ workspace) without needing duplicate `_jedb_bridge_*` post meta.
- New `JEDB_Target_CCT::get_db_columns()` helper — cached `SHOW COLUMNS`
  on `wp_jet_cct_{slug}`. Drives the conditional inclusion of
  `cct_single_post_id` and is generally reusable.

### Fixed

- **`JEDB_Target_CCT::update()` and `create()` now block writes to
  readonly fields.** Any attempt to write `_ID` or any system column is
  silently dropped with a `warning`-level log entry. Defense in depth so
  a future bridge config can't accidentally clobber the JE-managed
  `cct_modified` timestamp (which would defeat the entire last-write-wins
  use case the column unlocks).

### Improved

- **Targets-tab field-count column** now reads
  `<strong>14</strong> / +5 system` instead of just `19`. Visually
  separates user-authored fields from JE-managed system fields so the
  count makes sense at a glance and matches the JE UI's user-field
  count.

### Changed

- Plugin version bumped to **0.2.5** (no schema changes; DB version
  stays at 1.1.0).

## [0.2.4] — 2026-02-28

### Fixed

- **CCT field discovery on JetEngine 3.8+** — JE moved its field config
  out of the `'fields'` arg some time after the source plugins were
  written. Result on Brick Builder HQ: every CCT showed
  `JE get_arg("fields") raw (0)` in the diagnostic, the resolver fell
  through to `get_fields_list()` (names only, no types), and the
  schema rendered every field as `[text]` while including 4–5
  internal columns (`cct_status`, `cct_author_id`, `cct_created`,
  `cct_modified`, `cct_single_post_id`).
- New multi-source resolver in `JEDB_Discovery::get_cct_fields_from_instance()`
  tries every known channel in order:
  1. `$instance->get_arg('meta_fields')` — JE 3.8+ canonical key
  2. `$instance->get_arg('fields')` — older JE alias
  3. `$instance->args['meta_fields']` / `['fields']` — direct property
  4. `get_option('jet_engine_active_content_types')[N]['meta_fields']`
     / `['fields']` — persisted config in `wp_options`, last-resort
  5. `get_fields_list()` — names-only fallback, no types
- Each returned field now carries a `source` key so the diagnostic can
  show **exactly which channel produced the data** for that CCT.
- New constant `JEDB_Discovery::CCT_INTERNAL_COLUMN_NAMES` is enforced
  by both the resolver AND `JEDB_Target_CCT::get_field_schema()`.
  Internal columns can never appear in the schema regardless of which
  source produced the field list.

### Added

- **CCT diagnostic now dumps every field-source attempt** side by
  side, including the count and `name [type]` summary for each:
  - JE `get_arg("fields")`
  - JE `get_arg("meta_fields")`
  - `$instance->args["meta_fields"]` / `["fields"]` (direct property)
  - `get_option("jet_engine_active_content_types").meta_fields` /
    `.fields`
  - JE `get_fields_list()`
- New "Field source actually used" row colored green/red shows which
  resolution path the plugin ended up using for each CCT (or
  `none` in red if every channel failed).
- New "All `instance->args` keys" row prints every top-level arg key
  on the CCT factory so we can see what JE 3.8+ actually exposes.
- Same data is still written to the debug log for sharing.

### Changed

- Plugin version bumped to **0.2.4** (no schema changes; DB version
  stays at 1.1.0).

## [0.2.3] — 2026-02-28

### Fixed

- **`JEDB_Target_CCT` now actually reads CCT data.** The original
  implementation guarded every `$inst->db` access with
  `method_exists( $inst, 'db' )`, but `db` is a public **property** on
  the JE CCT factory, not a method. Every check returned false, so
  `count()`, `get()`, `update()`, `create()`, and `list_records()`
  silently fell through to a slower or null-returning fallback. Result
  on Phase 1's Targets tab: every CCT showed "0 items" even when the
  underlying table had rows.
- All five methods now use the correct `isset( $inst->db ) && is_object( $inst->db )`
  check, then call the documented JE db API: `db->get_item( $id )`,
  `db->query( $args, $limit, $offset, $order )`, `db->update( $data, $where )`,
  `db->insert( $data )`. Each path is wrapped in try/catch with a
  direct-SQL fallback on `wp_jet_cct_{slug}` so a JE API change can no
  longer take counting/listing offline.
- **CCT field schema now filters out non-data field types** (`tab`,
  `section`, `section_separator`, `heading`, `group_separator`, etc.).
  These appear in `get_arg('fields')` as visual organizers but never
  have a DB column or value, and were previously inflating the field
  count vs the JE UI count.
- Schema also de-duplicates by field name in case the same name appears
  twice in the JE config.

### Added

- **Per-CCT diagnostic** under Debug → "Run CCT diagnostic". For every
  registered CCT, dumps:
  - Table name + table existence pill.
  - Item count via direct SQL AND via `$inst->db->query()`.
  - Live DB columns from `SHOW COLUMNS` (so deleted-but-not-rebuilt
    columns are visible).
  - Raw `get_arg('fields')` output with each entry's name + type.
  - `get_fields_list()` output for comparison.
  - The schema after the plugin's filter.
  - The list of non-data fields the filter dropped (with type names),
    so you can see at a glance whether the JE-UI/Targets-tab field
    count mismatch is explained by tabs/sections, repeater containers,
    or actually-stale config entries.
- Same data is written to the debug log on every run (one log line per
  CCT) so it can be downloaded and shared.
- New `JEDB_Target_CCT::diagnose()` method exposes the per-CCT raw
  state — useful for any future REST/CLI tooling too.
- New `JEDB_Target_CCT::get_table_name()` helper.

### Changed

- Plugin version bumped to **0.2.3** (no schema changes; DB version
  stays at 1.1.0).

## [0.2.2] — 2026-02-28

### Fixed

- **Discovery returned `null` for non-empty results** — root cause of the
  "0 / 0 / 1 / 1" failure on first visit to the Targets tab. The
  `JEDB_Discovery::memo_set()` helper was missing its `return $value;`
  statement, so every `get_all_*()` method that ended with
  `return $this->maybe_cache(...)` propagated `null` even when the
  underlying call succeeded. The reason it appeared to "fix itself" on
  the second visit is that `memo_set` had the *side effect* of populating
  the transient — subsequent requests hit the transient cache and got
  the real array. One missing return now restored.
- **Defensive guards in `JEDB_Target_Registry::bootstrap_defaults()`** —
  `get_all_ccts()` / `get_all_public_post_types()` non-array returns are
  now coerced to `[]` with a warning log, instead of fataling on
  `count()`. Belt-and-suspenders so this class of bug can never blank
  the page again.
- **Diagnostic now reports the actual type** when discovery returns a
  non-array: e.g. `NOT-ARRAY (NULL)` instead of just `NOT-ARRAY`. The
  next regression of this kind will be obvious in one glance.

### Changed

- Plugin version bumped to **0.2.2** (no schema changes — DB version
  stays at 1.1.0).

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
