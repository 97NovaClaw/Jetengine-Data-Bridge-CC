# JetEngine Data Bridge CC

> A WordPress plugin that bridges JetEngine CCTs / CPTs / Relations and WooCommerce products with bidirectional, loop-safe sync, relation pre-attachment, field flattening, and a sandboxed custom-snippet transformer system.

**Status:** v0.5.1 — Phases 3 + 3.5 complete and verified on staging. Bidirectional sync works end-to-end: editing a CCT pushes mapped fields onto the linked Woo / CPT record, AND editing the post propagates back to the CCT via the per-mapping `pull_transform` chain. Per-direction triggers, mutual cascade prevention via `Sync_Guard::is_locked()` cross-checks, optional auto-create of CCT rows for unlinked posts (D-17 opt-in), and L-021 self-heal in both directions. v0.5.1 documents L-022 (JE `$db->update()` hook bypass) and L-023 (the categorization architecture). **Phase 3.6 — the categorization layer (`term_lookup` transformer + `taxonomies[]` array, push-only) — design locked per D-20 → D-24 and ships as v0.5.2.** Phase 4 (Bridge meta box on Woo product edit screen) follows.

**Author:** Legwork Media · GPL v2 or later
**Min versions:** WordPress 6.0 · PHP 7.4 · JetEngine 3.3.1
**WC support:** Optional. Plugin runs in CCT-only mode if WooCommerce isn't active. HPOS-safe via `WC_Product->save()`.

---

## Documentation map — read these first

The plugin's documentation is split across four files, each with a specific job. **If you're touching any code, read in this order:**

| Doc | Purpose | Read when |
|---|---|---|
| [`BUILD-PLAN.md`](./BUILD-PLAN.md) | Authoritative architecture spec. Every section, sub-system, decision, and phase deliverable. **17 locked decisions (D-1 through D-19)** are the contracts the plugin honors. | Always — before starting any new work. |
| [`LESSONS-LEARNED.md`](./LESSONS-LEARNED.md) | 20 entries (L-001 through L-020) capturing every false assumption, API surprise, and architectural correction we've made. Each entry: context, wrong, evidence, reality, affected code, fix shipped, prevention rule. | Before touching CCT/CPT/Woo data adapters, the JE config-storage resolver, the relation-attachment subsystem, sync direction, snippet runtime, or table-prefix discipline. |
| [`CHANGELOG.md`](./CHANGELOG.md) | Per-version delta. Each release lists Added / Fixed / Changed with cross-references to L-NNN and D-NN identifiers. | When you need to know what changed between two versions. |
| `README.md` *(this file)* | Capability snapshot, install instructions, doc map, current roadmap status. | First read for new contributors; ongoing reference for "what does this thing actually do right now". |

The `Refrence but block from git/` folder at the workspace root contains the three reference plugins (Jet Engine Relation Injector, PAC Vehicle Data Manager, JFB WC Quotes Advanced) we ported and learned from. It's gitignored at the workspace level — those plugins live in their own repos.

---

## What this plugin does (or will, by end of roadmap)

This plugin consolidates three earlier bespoke plugins (Jet Engine Relation Injector, PAC Vehicle Data Manager, and patterns from JFB WC Quotes Advanced) into a single portable codebase. End state:

- **Relation pre-attachment** on CCT edit screens — pick a related parent before the CCT row is saved (the "save twice" UX problem JetEngine has natively, eliminated).
- **PULL/PUSH field flattening** between related records, so derived fields stay in sync without editor effort. **Bidirectional but explicitly asymmetric** per D-17 — JE handles auto-create one direction, our plugin handles the other.
- **Field locker** — fields whose value is sourced from another record render greyed-out with a "source" tooltip.
- **WooCommerce product bridge** — a CCT row and a Woo product (or a specific variation) can be linked 1:1 via JE Relations (D-10 — JE Relations primary, no parallel `_jedb_bridge_cct_id` meta), edited from either side, and kept in sync via HPOS-safe writes.
- **Variation reconciliation** — a bridge type can declare variations with `show_when` rules so toggles like "Has Instructions PDF" automatically materialize the right Woo variation.
- **Conditional sync** — multiple bridge configs can share a source target with disjoint conditions (D-14). Trigger taxonomy (D-18) handles the *when* axis; condition DSL or snippet handles the *whether* axis.
- **Custom Code Snippets** — admins with the right capability can write small PHP transformers in a CodeMirror editor; snippets live in `uploads/jedb-snippets/` (protected by `.htaccess`), are syntax-checked on save, and are wrapped in a try/catch sandbox so a fatal in user code can't kill a save. Push/pull chains are separate (D-11).

See [`BUILD-PLAN.md`](./BUILD-PLAN.md) for the full architecture, file-level migration map, locked decisions log, and phased roadmap.

---

## What's actually shipped right now (v0.5.1)

**Functional capabilities (cumulative through Phase 3):**

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
- ✅ **Sync Guard** — per-request + transient locks with origin tagging prevent recursive saves.
- ✅ **Sync Log** — every bridge invocation writes a row to `wp_jedb_sync_log` with status from the BUILD-PLAN §4.9 taxonomy (`success`, `partial`, `errored`, `skipped_condition`, `skipped_error`, `skipped_locked`, `skipped_no_target`, `noop`).
- ✅ **Transformer registry** — 9 built-in transformers (`passthrough`, `yes_no_to_bool`, `regex_replace`, `format_number`, `lookup_table`, `name_builder`, `truncate_words`, `strip_html`, `year_expander`). Per D-11 / L-010 each transformer defines push and pull explicitly.
- ✅ **Condition Evaluator** — v1 declarative DSL parser per BUILD-PLAN §3.5. Operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `not_contains`, `starts_with`, `ends_with`, `in`, `not_in`. Logical: `AND`, `OR`, `NOT`.
- ✅ **Flatten admin tab** — bridge list + add/edit form with: source/target picker, link-via picker (JE Relation or `cct_single_post_id`), priority, condition DSL with live "Validate" button, mandatory-coverage panel (D-15), explicit two-column field-mapping table with per-direction transformer chain pickers (D-11), native-rendered hint per target field (D-16), manual "Sync now" button, raw-JSON `<details>` editor.
- ✅ **Debug tab** — log viewer (last 500 lines tailing), enable/disable toggle, clear/download buttons, deep-probe diagnostic for JE field storage and per-CCT internals.

**What's NOT shipped yet (Phase 4+):**

- ❌ Snippet-mode for `condition_snippet` — bridges that set it log `skipped_error` until Phase 5b ships the snippet runtime. Declarative DSL conditions work fully.
- ❌ Bridge meta box on Woo product edit screen — Phase 4.
- ❌ Variation reconciliation engine — Phase 4b.
- ❌ Custom Code Snippets runtime — Phase 5b. Settings table reserved.
- ❌ Setup-tab presets — Phase 6.
- ❌ Capability gating beyond `manage_options`, REST endpoint hardening, i18n .pot — Phase 7.

---

## Current file tree (v0.5.1)

```
je-data-bridge-cc/
├── je-data-bridge-cc.php                    Plugin bootstrap, constants, dep check
├── uninstall.php                            Drops tables, removes options
├── README.md / readme.txt                   This file + WP.org-style readme
├── BUILD-PLAN.md                            Authoritative architecture spec
├── LESSONS-LEARNED.md                       L-001 through L-020
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
│   │   ├── class-flatten-config-manager.php    wp_jedb_flatten_configs CRUD
│   │   ├── class-flattener.php                 Forward push engine (priority 20)
│   │   ├── class-reverse-flattener.php         Reverse pull engine (priority 20)
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
│   │       └── class-transformer-year-expander.php
│   │
│   ├── snippets/
│   │   └── class-snippet-installer.php      Creates uploads/jedb-snippets/ + guards
│   │
│   └── admin/
│       ├── class-admin-shell.php            Top-level menu + tab router
│       ├── class-tab-targets.php            Targets inventory tab
│       ├── class-tab-relations.php          Relations picker config tab
│       ├── class-tab-flatten.php            Forward-flatten bridge editor (Phase 3)
│       └── class-tab-debug.php              Debug log viewer + diagnostics
│
├── templates/admin/
│   ├── shell.php                            Outer page with tabs nav
│   ├── tab-hello.php                        Status tab
│   ├── tab-targets.php                      Targets inventory
│   ├── tab-relations.php                    Relations config + per-CCT cards
│   ├── tab-flatten.php                      Flatten bridge list + add/edit form
│   ├── relation-config-card.php             Single CCT's relation config
│   └── tab-debug.php                        Log viewer + Discovery / CCT diagnostics
│
└── assets/
    ├── css/
    │   ├── admin.css                        Admin-shell + tab styling (incl. Flatten)
    │   └── relation-injector.css            Picker block + modal + relation cards
    └── js/
        ├── relation-injector.js             Picker UI on CCT edit screens
        └── flatten-admin.js                 Mapping editor + transformer args + condition validate
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
- `jedb_bridge_types` — JSON array of bridge-type definitions (Phase 4 source of truth).
- `jedb_meta_whitelist` — per-target meta-key allowlists.
- `jedb_db_version` — schema version, drives in-place upgrades via `JEDB_Plugin::run_migrations()`.

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
| 3.6 | Categorization layer: `term_lookup` transformer + `taxonomies[]` array + post-only push semantics (D-20 → D-24, L-023, BUILD-PLAN §4.11). Architecture locked; implementation in progress | **▶ In progress** (ships as v0.5.2) |
| 4  | Bridge meta box on Woo product edit screen + Bridges admin tab | Pending |
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
