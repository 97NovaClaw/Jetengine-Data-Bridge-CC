# JetEngine Data Bridge CC

> A WordPress plugin that bridges JetEngine CCTs / CPTs / Relations and WooCommerce products with bidirectional, loop-safe sync, relation pre-attachment, field flattening, and a sandboxed custom-snippet transformer system.

**Status:** v0.6.0-alpha.13 (Phase 4b under architectural reset per L-032). Phases 0-3.6 + **all of Phase 4** are complete and **verified end-to-end on Brick Builder HQ staging**. **Phase 4b is being rebuilt** — alpha.13's declarative `variations[]` reconciler (shipped end-to-end, exit-criterion-met, but architecturally wrong) is being retired in alpha.14 + alpha.15 in favor of an iframe-flip pattern (the L-027 symmetric mirror). The new model launches WC's native product edit page in a chrome-stripped modal iframe from a per-bridge CCT-edit-screen panel — delegates 100% of variation UI to WC and gets all WC features for free with no schema bloat. See L-032 for the architectural retrospective and BUILD-PLAN §4.7 for the new spec. **Phase 4 Day 4 shipped in alpha.12 — Field Presets admin tab + Mandatory coverage integration (§4.12)**. New `JEDB_Tab_Field_Presets` with full CRUD + JSON export/import. `JEDB_Field_Presets_Manager` extended with full CRUD + a `compute_effective_required_fields()` helper used by both the Flatten admin tab's coverage panel and the Bridge meta box's Advanced Details. Flatten admin tab's Mandatory coverage panel rebuilt with green/red badges + "X of Y covered" summary + Apply preset dropdown (snapshot model) + Scaffold missing mappings button + provenance labels. Bridge meta box's Advanced Details gained the same coverage breakdown when `meta_box.show_advanced=true`. All client-side mutations after Apply / Scaffold — no extra AJAX. **Phase 4 Day 3 shipped earlier in alpha.11 — CCT-single → linked-post redirect shim (§4.6)**. New `JEDB_CCT_Single_Redirect` class hooked at `template_redirect` priority 5. Opt-in per bridge via the existing `cct_single_redirect` flag (in schema since alpha.3, runtime now wired). Reverse-lookup detection via `cct_single_post_id` works across JE versions. Direction guard skips pull-only bridges. Loop guard silently no-ops when bridge target IS the queried post (BBHQ Pattern X). Admin escape hatch via `?jedb_no_redirect=1` requires the JEDB capability. No engine code touched. **alpha.10 Flatten admin tab UI sweep shipped previously** — rewrote stale post-L-027 copy in the meta box settings intro, stripped phase / D-NNN / BUILD-PLAN-§ references from editor-facing strings, marked the CCT-single redirect description as "Not yet active," added a Label-field description explaining the meta-box-title fallback chain, and auto-hid the Reverse-direction options row when direction=push (silent no-op there). BUILD-PLAN forward-look notes tightened for Phases 4 Day 3, 4 Day 4, 4b, and 5 with explicit alpha.9 meta-box-reshape interactions documented. No engine code touched. **Phase 4 Day 2 final form shipped in alpha.9 (L-031)**: ONE WP meta box per enabled bridge (was: one umbrella box looping bridges internally since alpha.4). Each box uses its bridge's `meta_box.title` (fallback `label`) as the WP gray header and honors `meta_box.position`. Linked-panel template rewritten with minimal native WP look — `<table class="form-table">` for surfaced field previews, no custom pills/borders/panel chrome. New `meta_box.show_advanced` opt-in flag (default `false`) tucks per-product overrides + recent sync log + Sync now/Unlink into a collapsed `<details>` "Advanced Details" section. Multi-CCT-per-product = two clearly-separated WP boxes, each named, independently collapsible/draggable. Variation use case (§4.7 `has_instructions_pdf` pattern) remains compatible — Phase 4b will add the `variations[]` reconciliation engine to the SAME bridge config (L-015). **alpha.8 stale-data hotfix shipped (L-030)** — Bridge meta box surfaced previews were reading via JE's `$db->get_item()` which can return cached pre-save rows on the request after a write (especially Redis-cached setups). New `Target_CCT::get_fresh()` direct-SQL bypass; wired into meta-box render, forward push, and reverse pull source reads. Engine semantics unchanged. **Phase 4 Day 2 alpha.7 shipped (L-029)** — Bridge meta box modal flow fixes: dropped the broken dirty-check confirm dialog (always save-first); Done button now actually submits JE's form (was a no-op closer); JE's native Save now also closes the modal via a two-tier injection that lets a sessionStorage-driven close handler survive JE's chrome-stripping post-save redirect. "Saving…" overlay added during the save round-trip. **Phase 4 Day 2 alpha.6.1 hotfix shipped (L-028)** — fixed a critical nested-form bug in the Bridge meta box that was causing every product save (since alpha.4) to redirect to `wp-admin/edit.php` instead of returning to the product edit page. The fix converts the Sync now / Unlink / Link buttons to `<button type="button">` elements; a JS handler builds the real `<form>` off-DOM at click time. No engine behavior change. **Phase 4 Day 2 alpha.6 architectural pivot also part of this release (L-027)** — CCT editing from the Woo product edit screen is now delegated to JE's own CCT edit page via a chrome-stripped modal iframe. The meta box renders type-aware READ-ONLY previews of surfaced fields and a "Save & edit \"{label}\" in JetEngine" button launches the editor in a modal. Every JE field type (select, media, gallery, WYSIWYG, repeater, etc.) renders natively because JE itself is rendering them — zero per-type renderer code in our plugin. The alpha.5 explicit-`apply_bridge` workaround for L-022 is retired: JE's natural save flow fires its hooks normally because JE is doing the save, not our adapter. The meta box reads flatten configs directly — no template layer — and surfaces CCT-managed fields on the Woo product edit screen so editors don't have to context-switch to JE's CCT admin for fields that are operationally meaningful while looking at a product. Linked-state panel: surfaced field inputs grouped by `group` label, per-product Lock + Direction override (writes the post meta the alpha.3 engine guards consume), Last 3 sync rows pill-coded by status, Sync now + Unlink action buttons. Unlinked-state panel: live CCT search reusing the Phase 2 relation-search AJAX endpoint + Link button. Phase 4 Day 1 (alpha.3 reshape) is in place underneath: bridge type template layer retired, flatten config schema extended with `meta_box` block + per-mapping `surface_*` flags + `group` + `cct_single_redirect`, engine guards for `_jedb_bridge_locked` and `_jedb_bridge_direction_override`. Phase 4 Day 3 (CCT-single → linked-post redirect shim) and Day 4 (Field Presets admin tab + Mandatory coverage integration) are next. **Engine paths are byte-identical to v0.5.3** outside the alpha.3 per-product guards (which are skip-only). Existing 0.5.x flatten configs work unchanged. See [`CHANGELOG.md`](./CHANGELOG.md) for the full alpha.4 entry, [`LESSONS-LEARNED.md`](./LESSONS-LEARNED.md) for L-026 (template-layer post-mortem that drove the Phase 4 reshape), and [`BUILD-PLAN.md`](./BUILD-PLAN.md) §12 for the 4-day Phase 4 plan.

**Author:** Legwork Media · GPL v2 or later
**Min versions:** WordPress 6.0 · PHP 7.4 · JetEngine 3.3.1
**WC support:** Optional. Plugin runs in CCT-only mode if WooCommerce isn't active. HPOS-safe via `WC_Product->save()`.

---

## Documentation map — read these first

The plugin's documentation is split across four files, each with a specific job. **If you're touching any code, read in this order:**

| Doc | Purpose | Read when |
|---|---|---|
| [`BUILD-PLAN.md`](./BUILD-PLAN.md) | Authoritative architecture spec. Every section, sub-system, decision, and phase deliverable. **27 locked decisions (D-1 through D-27, with D-5 superseded by D-25)** are the contracts the plugin honors. §7.1 contains the staging verification log; §4.12 covers Field Presets architecture; §12 is the alpha.3 readiness gate + 4-day Phase 4 plan. | Always — before starting any new work. |
| [`LESSONS-LEARNED.md`](./LESSONS-LEARNED.md) | 26 entries (L-001 through L-026) capturing every false assumption, API surprise, and architectural correction we've made. Each entry: context, wrong, evidence, reality, affected code, fix shipped, prevention rule. L-026 is the template-layer post-mortem that drove the Phase 4 reshape. | Before touching CCT/CPT/Woo data adapters, the JE config-storage resolver, the relation-attachment subsystem, sync direction, taxonomy applier, transformer ordering, snippet runtime, table-prefix discipline, or any schema that bridges between two storage surfaces. |
| [`CHANGELOG.md`](./CHANGELOG.md) | Per-version delta. Each release lists Added / Fixed / Changed with cross-references to L-NNN and D-NN identifiers. | When you need to know what changed between two versions. |
| `README.md` *(this file)* | Capability snapshot, install instructions, doc map, current roadmap status. | First read for new contributors; ongoing reference for "what does this thing actually do right now". |
| `readme.txt` | WP.org-style readme used by WP-Admin updater. Mirrors the most-recent two minor versions of CHANGELOG. | When publishing or shipping a release. |

The `Refrence but block from git/` folder at the workspace root contains the three reference plugins (Jet Engine Relation Injector, PAC Vehicle Data Manager, JFB WC Quotes Advanced) we ported and learned from. It's gitignored at the workspace level — those plugins live in their own repos.

---

## What this plugin does (or will, by end of roadmap)

This plugin consolidates three earlier bespoke plugins (Jet Engine Relation Injector, PAC Vehicle Data Manager, and patterns from JFB WC Quotes Advanced) into a single portable codebase. End state:

- **Relation pre-attachment** on CCT edit screens — pick a related parent before the CCT row is saved (the "save twice" UX problem JetEngine has natively, eliminated).
- **PULL/PUSH field flattening** between related records, so derived fields stay in sync without editor effort. **Bidirectional but explicitly asymmetric** per D-17 — JE handles auto-create one direction, our plugin handles the other.
- **Field locker** — fields whose value is sourced from another record render greyed-out with a "source" tooltip.
- **Generic record-store bridges** — the engine speaks four adapter kinds: `cct::*` (JE CCTs), `posts::{post_type}` (CPTs), `posts::product` (HPOS-safe Woo product), `posts::product_variation` (HPOS-safe Woo variation). Any source ↔ target pair is supported (CCT↔Woo, CCT↔CPT, CPT↔CCT, CCT↔CCT, CPT↔CPT). The CCT ↔ Woo product example below is the headline use case for Brick Builder HQ — not a constraint of the engine. See BUILD-PLAN §4.5.1 for the full source/target compatibility matrix.
- **WooCommerce product bridge** — a CCT row and a Woo product (or a specific variation) can be linked 1:1 via JE Relations (D-10 — JE Relations primary, no parallel `_jedb_bridge_cct_id` meta), edited from either side, and kept in sync via HPOS-safe writes.
- **Variation reconciliation** — a bridge type can declare variations with `show_when` rules so toggles like "Has Instructions PDF" automatically materialize the right Woo variation.
- **Conditional sync** — multiple bridge configs can share a source target with disjoint conditions (D-14). Trigger taxonomy (D-18) handles the *when* axis; condition DSL or snippet handles the *whether* axis.
- **Custom Code Snippets** — admins with the right capability can write small PHP transformers in a CodeMirror editor; snippets live in `uploads/jedb-snippets/` (protected by `.htaccess`), are syntax-checked on save, and are wrapped in a try/catch sandbox so a fatal in user code can't kill a save. Push/pull chains are separate (D-11).

See [`BUILD-PLAN.md`](./BUILD-PLAN.md) for the full architecture, file-level migration map, locked decisions log, and phased roadmap.

---

## What's actually shipped right now (v0.6.0-alpha.13)

**Functional capabilities (cumulative through Phase 3.6 + Phase 4 Day 1 alpha.3 + Phase 4 Day 2 alpha.4 → alpha.5):**

- ✅ **Custom plugin tables created on activation** (`wp_jedb_relation_configs`, `wp_jedb_flatten_configs`, `wp_jedb_sync_log`, `wp_jedb_snippets`).
- ✅ **Snippets uploads folder** (`wp-content/uploads/jedb-snippets/`) with `.htaccess` (`deny from all`) + silent `index.php`.
- ✅ **Discovery layer** — finds every CCT, public CPT, JE Relation, JE Glossary, Woo product type, Woo variation, and Woo taxonomy on the site. JE 3.8+ field-schema resolution via `wp_jet_post_types` (channel #1 in resolver chain — see L-007).
- ✅ **Four target adapters** (CCT, CPT, Woo Product, Woo Variation) with HPOS-safe writes. Each with `get_field_schema()`, `get`, `update`, `create`, `list_records`, `count`, `get_required_fields()` (D-15), `is_natively_rendered()` (D-16).
- ✅ **Targets admin tab** — read-only inventory of every discovered record store, with field counts split into `<user-fields> / +<system-fields>`.
- ✅ **Relations admin tab** — configure which JE Relations the picker UI exposes per CCT. **Relations themselves are still authored in JetEngine → Relations** (D-13).
- ✅ **Picker UI on CCT edit screens** — appears above the save button when a config is enabled. Modal-based search with 300ms debounce. Uses `WP_Query` directly so it sees products created by JE's auto-create (L-017).
- ✅ **Direct-SQL relation writes per L-014 verified contract** — idempotent duplicate-check, type-aware clearing for 1:1 / 1:M, append for M:M.
- ✅ **Forward-direction flatten engine** (Phase 3, v0.4.0) — editing a CCT row pushes mapped values onto its linked Woo / CPT record. Hooks at priority 20 per D-19 / L-018.
- ✅ **JE Relation row self-heal** (v0.4.1) — when the relation row is missing, the engine falls back to `cct_single_post_id` and auto-attaches the relation row so JE Smart Filters / Listings work natively from the first sync. Per L-021. Two opt-out flags exposed in the Flatten admin tab.
- ✅ **Reverse-direction flatten engine** (Phase 3.5, v0.5.0) — editing a Woo product / CPT propagates mapped fields back to the linked CCT row via the per-mapping `pull_transform` chain. Hooks: `woocommerce_update_product` (+ variations) and `save_post_{type}` for non-Woo CPTs, both at priority 20.
- ✅ **Bidirectional bridges** (v0.5.0) — `direction = bidirectional` registers both engines for one bridge. Mutual cascade prevention via cross-direction `Sync_Guard::is_locked()` checks at the top of each engine's `apply_bridge()`.
- ✅ **Auto-create CCT row** (v0.5.0, D-17 opt-in) — when a post saves with no linked CCT row, the reverse engine can optionally create a fresh CCT row in the bridge's source target and auto-attach the relation. Default OFF; opt-in per bridge via `auto_create_target_when_unlinked` checkbox.
- ✅ **`term_lookup` transformer** (Phase 3.6, v0.5.2) — push: names/slugs/IDs → term IDs (array); pull: term IDs → names/slugs. Use to map a CCT string field onto a Woo taxonomy field like `category_ids` via the existing per-mapping transformer chain. Composes with the `taxonomies[]` array.
- ✅ **`taxonomies[]` array on flatten configs** (Phase 3.6, v0.5.2) — bridge configs carry per-bridge taxonomy rules with per-rule merge strategy (append/replace), explicit term removal via `apply_terms_inverse`, optional `create_if_missing`, and a forward-compat `snippet` slot for Phase 5b. Push-only semantics in v1 per D-21.
- ✅ **Live taxonomy UI** (Phase 3.6, v0.5.2) — Flatten admin tab gains a Taxonomies section visible only when `target_target` is `posts::*`. Dropdowns are populated via the new `wp_ajax_jedb_flatten_get_post_type_taxonomies` endpoint; editors pick from registered taxonomies + existing terms instead of typing slugs by hand. `JEDB_TAX_TERMS_LIMIT` (default 100) caps the per-taxonomy term return count.
- ✅ **Phase 4 Day 1 alpha.3 reshape (v0.6.0-alpha.3)** — the alpha.1/alpha.2 Bridges admin tab + `JEDB_Bridge_Types_Manager` template layer was retired (~1,500 lines deleted) per D-25 / L-026. In its place: flatten config schema extended with a `meta_box` block (title, position, groups), per-mapping `surface_on_target` / `surface_on_source` flags + freeform `group` label, and a top-level `cct_single_redirect` opt-in. New `JEDB_OPTION_FIELD_PRESETS` site option + read-only `JEDB_Field_Presets_Manager` skeleton (full UI ships Phase 4 Day 4). New `STATUS_SKIPPED_DIRECTION_OVERRIDE` sync_log constant. Per-product engine guards in both flatteners read `_jedb_bridge_locked` and `_jedb_bridge_direction_override` post meta to allow editor-set freezing / direction constraint without touching the bridge config. Existing 0.5.x flatten configs work unchanged (back-compat handled in `merge_with_defaults()`).
- ✅ **Phase 4 Day 2 — Bridge meta box on Woo product / variation edit screens (v0.6.0-alpha.4 → alpha.5)** — new `JEDB_Woo_Product_Meta_Box` class hooked on `add_meta_boxes` for `product` + `product_variation` post types. Reads `wp_jedb_flatten_configs` directly per D-27 — no template layer involved. At render time, walks the bridges whose `target_target` matches the current post type, runs `JEDB_Reverse_Flattener::resolve_source_id()` in read-only mode per candidate to determine which bridges govern THIS specific post, renders one panel per resolved bridge (linked or unlinked). **Surface mechanics decoupled from sync mechanics (alpha.5):** mappings can be "pure surface" (`source_field` set, `target_field=''`, edits write to CCT only, no shadow product data), "native overlay" (target IS Woo-native — the editor explicitly opted in by ticking the box; CCT-canonical D-2 wins on conflict), or "sync+surface" (both fields set, both render + sync). The meta box shows a mode pill next to each field so editors see what each does. Linked panel surfaces editable inputs grouped by freeform `group` label, shows Last 3 sync_log rows pill-coded by status, exposes per-product Lock + Direction override controls that write the post meta the alpha.3 engine guards consume, provides Sync now + Unlink action buttons. Unlinked panel includes a live CCT search (debounced 250ms, reuses the Phase 2 `wp_ajax_jedb_relation_search_items` AJAX endpoint) + Link button calling `JEDB_Relation_Attacher::attach()`. **Data-loss bug fixed in alpha.5:** the alpha.4 pull-lock hack is replaced by an explicit `JEDB_Flattener::apply_bridge()` call after the meta box's source write — this works around L-022 (Target_CCT::update doesn't fire JE hooks) within bounded scope. Diagnostic improvement: when surface flags are ticked but no fields render, the panel lists each skipped mapping + reason. All engine code untouched — meta box is a pure UI layer on top of the alpha.3 schema + guards. WC-active gate: only loaded when `class_exists('WooCommerce')`.
- ✅ **Sync Guard** — per-request + transient locks with origin tagging prevent recursive saves.
- ✅ **Sync Log** — every bridge invocation writes a row to `wp_jedb_sync_log` with status from the BUILD-PLAN §4.9 taxonomy (`success`, `partial`, `errored`, `skipped_condition`, `skipped_error`, `skipped_locked`, `skipped_no_target`, `noop`).
- ✅ **Transformer registry** — 9 built-in transformers (`passthrough`, `yes_no_to_bool`, `regex_replace`, `format_number`, `lookup_table`, `name_builder`, `truncate_words`, `strip_html`, `year_expander`). Per D-11 / L-010 each transformer defines push and pull explicitly.
- ✅ **Condition Evaluator** — v1 declarative DSL parser per BUILD-PLAN §3.5. Operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `not_contains`, `starts_with`, `ends_with`, `in`, `not_in`. Logical: `AND`, `OR`, `NOT`.
- ✅ **Flatten admin tab** — bridge list + add/edit form with: source/target picker, link-via picker (JE Relation or `cct_single_post_id`), priority, condition DSL with live "Validate" button, mandatory-coverage panel (D-15), explicit two-column field-mapping table with per-direction transformer chain pickers (D-11), native-rendered hint per target field (D-16), manual "Sync now" button, raw-JSON `<details>` editor.
- ✅ **Debug tab** — log viewer (last 500 lines tailing), enable/disable toggle, clear/download buttons, deep-probe diagnostic for JE field storage and per-CCT internals.

**What's NOT shipped yet (Phase 4+):**

- ❌ Snippet-mode for `condition_snippet` — bridges that set it log `skipped_error` until Phase 5b ships the snippet runtime. Declarative DSL conditions work fully.
- ❌ Bridge meta box on Woo product edit screen — Phase 4 Day 2 (next).
- ❌ CCT-single → linked-post redirect shim (BUILD-PLAN §4.6 — target-agnostic; works for any bridged post type) — Phase 4 Day 3.
- ❌ Variation reconciliation engine + `show_when` mini-DSL — Phase 4b.
- ❌ `term_assigned` trigger (D-18) — Phase 4.5.
- ❌ Custom Code Snippets runtime — Phase 5b. Settings table reserved.
- ❌ Setup-tab presets — Phase 6.
- ❌ Capability gating beyond `manage_options`, REST endpoint hardening, i18n .pot — Phase 7.

---

## Verification snapshot (Brick Builder HQ staging)

Each phase that ships runtime behavior is verified end-to-end on `bbhq.legworklabs.com` before being marked complete. Every assertion below cites the actual sync_log row, debug log line, or SQL inspection that proved it.

| Phase | Version | Verified behavior | Top evidence |
|---|---|---|---|
| 2 | v0.3.0 | Picker UI attaches relations on save | `wp_jet_rel_8` row created with `parent=1, child=395` after first picker save. |
| 2.5 | v0.3.1 | Picker sees products created by JE auto-create (L-017) | Picker dropdown lists products created by raw `wp_insert_post` after switching to `WP_Query` direct. |
| 3 | v0.4.0 | Forward push at hook priority 20 (D-19); diff engine NOOPs cleanly | `wp_jedb_sync_log` row id 6, status=success, fields=["regular_price"]. Rows 2/3/5: status=noop. |
| 3 hotfix | v0.4.1 | L-021 self-heal auto-attaches missing relation rows via `cct_single_post_id` fallback | `[Flattener] auto-attached JE relation via cct_single_post_id fallback` at 2026-05-05 23:28:42. |
| 3.5 | v0.5.0 | Reverse pull engine fires on `woocommerce_update_product`; D-17 auto-create works | Sync_log row at 2026-05-06 00:20:12 — `direction=pull, auto_created=true, target_id=405`. |
| 3.5 hotfix | v0.5.1 | L-022 architectural finding: JE's `$db->update()` doesn't fire its hooks; cascade asymmetry is benign | Zero `cascade=push_in_flight` markers ever appeared on pull writes through 0.5.0/0.5.1 testing. |
| 3.6 | v0.5.2 | Static `taxonomies[]` rules apply on push; reverse pull skips taxonomies (D-21) | Sync_log row id 33 at 2026-05-06 03:30:34 — `terms_added=1, added_ids=[17]`. |
| 3.6 hotfix | v0.5.3 | L-024 ordering: mappings run BEFORE taxonomies so rules get the final word | Sync_log row at 2026-05-06 03:59:29 — `wrote 1 field(s) + 1 taxonomy term change(s)`, both `category_ids` and term 17 applied. `apply_terms_inverse` correctly removes a term written by a `term_lookup` mapping. |

Full verification log with per-phase assertion tables lives in [`BUILD-PLAN.md`](./BUILD-PLAN.md) §7.1.

---

## Current file tree (v0.6.0-alpha.13)

```
je-data-bridge-cc/
├── je-data-bridge-cc.php                    Plugin bootstrap, constants, dep check
├── uninstall.php                            Drops tables, removes options
├── README.md / readme.txt                   This file + WP.org-style readme
├── BUILD-PLAN.md                            Authoritative architecture spec
├── LESSONS-LEARNED.md                       L-001 through L-026
├── CHANGELOG.md                             Per-version delta
├── LICENSE                                  GPL v2
│
├── includes/
│   ├── class-plugin.php                     Singleton, schema upgrade dispatcher
│   ├── class-config-db.php                  4 custom tables via dbDelta
│   ├── class-discovery.php                  CCTs / Relations / CPTs / Woo / Glossaries / wp_jet_post_types
│   ├── class-sync-guard.php                 Per-request + transient locks; origin-tagged
│   ├── class-sync-log.php                   wp_jedb_sync_log writer + reader
│   │
│   ├── helpers/
│   │   ├── debug.php                        jedb_log() with file rotation
│   │   └── dependencies.php                 jedb_is_jet_engine_active() + version detection (L-001)
│   │
│   ├── targets/
│   │   ├── interface-data-target.php        JEDB_Data_Target contract (incl. D-15/D-16 methods)
│   │   ├── abstract-target.php              Shared base (slug parsing, log helper, default impls)
│   │   ├── class-target-cct.php             CCT items via $inst->db API + direct SQL (L-003, L-004)
│   │   ├── class-target-cpt.php             Standard posts / post-meta via WP API
│   │   ├── class-target-woo-product.php     HPOS-safe via WC_Product->save() — WP_Query for picker (L-017)
│   │   ├── class-target-woo-variation.php   HPOS-safe via WC_Product_Variation->save()
│   │   └── class-target-registry.php        Slug → adapter map; auto-bootstrap from Discovery
│   │
│   ├── relations/
│   │   ├── class-relation-config-manager.php   wp_jedb_relation_configs CRUD (one row per CCT)
│   │   ├── class-relation-attacher.php         Direct-SQL writer per L-014 contract
│   │   ├── class-data-broker.php               wp_ajax_jedb_relation_search_items endpoint
│   │   ├── class-runtime-loader.php            Detect CCT edit page, enqueue picker assets
│   │   └── class-transaction-processor.php     CCT save hooks (priority 10 for picker)
│   │
│   ├── flatten/
│   │   ├── class-condition-evaluator.php       v1 DSL parser + evaluator (BUILD-PLAN §3.5)
│   │   ├── class-flatten-config-manager.php    wp_jedb_flatten_configs CRUD (incl. taxonomies[])
│   │   ├── class-flattener.php                 Forward push engine (priority 20)
│   │   ├── class-reverse-flattener.php         Reverse pull engine (priority 20)
│   │   ├── class-taxonomy-applier.php          Phase 3.6 push-only taxonomy rule applier
│   │   └── transformers/
│   │       ├── interface-transformer.php
│   │       ├── class-transformer-registry.php
│   │       ├── class-transformer-passthrough.php
│   │       ├── class-transformer-yes-no-bool.php
│   │       ├── class-transformer-regex-replace.php
│   │       ├── class-transformer-format-number.php
│   │       ├── class-transformer-lookup-table.php
│   │       ├── class-transformer-name-builder.php
│   │       ├── class-transformer-truncate-words.php
│   │       ├── class-transformer-strip-html.php
│   │       ├── class-transformer-year-expander.php
│   │       └── class-transformer-term-lookup.php
│   │
│   ├── snippets/
│   │   └── class-snippet-installer.php      Creates uploads/jedb-snippets/ + guards
│   │
│   └── admin/
│       ├── class-admin-shell.php            Top-level menu + tab router
│       ├── class-field-presets-manager.php  Phase 4 Day 1 alpha.3 — read-only API for jedb_field_presets (CRUD + UI ship Day 4)
│       ├── class-tab-targets.php            Targets inventory tab
│       ├── class-tab-relations.php          Relations picker config tab
│       ├── class-tab-flatten.php            Forward-flatten bridge editor (Phase 3+) — extended in alpha.3 with Meta box settings + per-mapping surface flags
│       ├── class-tab-debug.php              Debug log viewer + diagnostics
│       └── class-woo-product-meta-box.php   Phase 4 Day 2 alpha.4 — Bridge meta box on Woo product/variation edit screens
│
├── templates/admin/
│   ├── shell.php                            Outer page with tabs nav
│   ├── tab-hello.php                        Status tab
│   ├── tab-targets.php                      Targets inventory
│   ├── tab-relations.php                    Relations config + per-CCT cards
│   ├── tab-flatten.php                      Flatten bridge list + add/edit form (alpha.3: Meta box section, per-mapping surface flags, cct_single_redirect)
│   ├── relation-config-card.php             Single CCT's relation config
│   ├── tab-debug.php                        Log viewer + Discovery / CCT diagnostics
│   ├── meta-box-bridge.php                  Phase 4 Day 2 alpha.4 — linked-state Bridge meta box template
│   └── meta-box-bridge-unlinked.php         Phase 4 Day 2 alpha.4 — unlinked-state Bridge meta box (CCT picker)
│
└── assets/
    ├── css/
    │   ├── admin.css                        Admin-shell + tab styling
    │   ├── bridge-meta-box.css              Phase 4 Day 2 alpha.4 — Bridge meta box scoped styles
    │   └── relation-injector.css            Picker block + modal + relation cards
    └── js/
        ├── relation-injector.js             Picker UI on CCT edit screens
        ├── flatten-admin.js                 Mapping editor + transformer args + condition validate (alpha.3: meta box settings + surface flags)
        └── bridge-meta-box.js               Phase 4 Day 2 alpha.4 — CCT picker AJAX + lock confirm
```

### Custom tables created on activation

| Table | Purpose |
|---|---|
| `wp_jedb_relation_configs` | Per-CCT relation pre-attachment configs (Phase 2). One row per CCT. |
| `wp_jedb_flatten_configs`  | PULL/PUSH flatten configs (Phase 3 — table exists, engine not yet implemented). |
| `wp_jedb_sync_log`         | Append-only audit trail of every PUSH/PULL operation (used from Phase 3 onward). |
| `wp_jedb_snippets`         | Registry of Custom Code Snippets (Phase 5b). |

### Options created

- `jedb_settings` — global toggles (debug log, custom snippets, default sync direction).
- `jedb_field_presets` — JSON array of curated field-preset definitions per target adapter (D-26 / §4.12). Read-only API in alpha.3; full CRUD + UI Phase 4 Day 4.
- `jedb_meta_whitelist` — per-target meta-key allowlists.
- `jedb_db_version` — schema version, drives in-place upgrades via `JEDB_Plugin::run_migrations()`.

*(`jedb_bridge_types` was retired in alpha.3 per D-25 / L-026 — the flatten config IS the bridge identity; no separate template layer.)*

---

## Live verification capability — JetEngine MCP

The workspace's `.cursor/mcp.json` is wired to `https://bbhq.legworklabs.com/wp-json/jet-engine/v1/mcp/` (JetEngine's MCP endpoint with Basic Auth credentials). When the MCP is connected and the agent has tools exposed:

- CCT/CPT/Relation/Glossary discovery can be cross-checked against the live site without uploading the plugin.
- `wp_jet_post_types` rows can be inspected without phpMyAdmin.
- Auto-create configs on each JE Relation can be verified directly.

If you're working on this plugin and the JE MCP is available, prefer using it over speculative reasoning when verifying JE behavior. See `LESSONS-LEARNED.md` L-007 / L-014 / L-016 for examples of the kind of facts MCP can confirm.

---

## Installation (development)

1. Copy the plugin folder to `wp-content/plugins/je-data-bridge-cc/` (rename the dev folder to remove spaces — WP doesn't love spaces in plugin folder names).
2. Activate from **Plugins → Installed Plugins**.
3. Visit **JE Data Bridge → Status** in the admin sidebar. Every row should be green.
4. Visit **Targets** to see a read-only inventory of every CCT, CPT, Woo product, variation, and JE relation discovered on the site.
5. Visit **Relations** to configure which JE Relations the picker UI exposes per CCT.
6. If anything is red on Status, deactivate and reactivate to re-run the installer; if still red, open **Debug** for the discovery diagnostic.

### Building a release zip (later)

For now this is dev-only. When the plugin reaches a release-worthy phase a `bin/build.sh` will produce a clean zip.

---

## Roadmap

See [`BUILD-PLAN.md`](./BUILD-PLAN.md) §7 for the full eight-phase plan and exit criteria.

| Phase | Scope | Status |
|---|---|---|
| 0  | Skeleton, tables, snippet folder, status screen | **✅ Complete** (v0.1.x) |
| 1  | Discovery + Targets (CCT, CPT, Woo Product, Woo Variation) | **✅ Complete** (v0.2.x) |
| 2  | Relation Injector port (picker on CCT edit screens) | **✅ Complete** (v0.3.0) |
| 2.5 | Bidirectional architecture lock + picker visibility fix (L-016 → L-020, D-17 → D-19) | **✅ Complete** (v0.3.1) |
| 3  | Flattener (forward direction): wp_jedb_flatten_configs admin tab + push engine + transformers + L-021 self-heal | **✅ Complete** (v0.4.0 → v0.4.1) |
| 3.5 | Reverse-direction flatten (post → CCT) + bidirectional bridges + auto-create CCT (D-17) per BUILD-PLAN §4.10 + L-022 cascade-asymmetry doc | **✅ Complete** (v0.5.0 → v0.5.1, verified on staging) |
| 3.6 | Categorization layer: `term_lookup` transformer + `taxonomies[]` array + post-only push semantics (D-20 → D-24, L-023, BUILD-PLAN §4.11) | **✅ Complete** (v0.5.2 → v0.5.3, L-024 ordering fix) |
| 4  | Bridge meta box on Woo product edit screen + Field Presets (4 days, reshaped per D-25 / D-26 / D-27 / L-026) | **▶ In progress** — Day 1 alpha.3 SHIPPED in v0.6.0-alpha.3; **Day 2 alpha.4 → alpha.5 SHIPPED** (Bridge meta box, surface decoupled from sync per alpha.5 + data-loss bug fix); Day 3 redirect shim; Day 4 Field Presets admin tab + Mandatory coverage integration. |
| 4b | Variation bridging + reconciliation engine + `show_when` mini-DSL | Pending |
| 4.5 | `term_assigned` trigger (term changes as wakeup events for reverse engine; D-18 trigger taxonomy implementation) | Pending |
| 5  | Settings API + debug log viewer enhancements + utilities export/import | Pending |
| 5b | Custom Code Snippets subsystem | Pending |
| 6  | Setup tab + presets (Brick Builder HQ preset) | Pending |
| 7  | Hardening (caps, nonces, REST auth, i18n, security pass) | Pending |

---

## License

GPL v2 or later. See [`LICENSE`](./LICENSE).

## Author

Legwork Media · [legworkmedia.ca](https://legworkmedia.ca)
