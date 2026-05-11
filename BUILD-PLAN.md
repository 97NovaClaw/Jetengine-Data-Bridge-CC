# Jetengine Data Bridge CC — Consolidation Build Plan

> **Purpose of this document**
> A complete, file-level merger plan for collapsing three existing custom plugins into one portable, multi-site WordPress plugin: **Jetengine Data Bridge CC** (working slug: `je-data-bridge-cc`).
>
> Source plugins:
> 1. **Jet Engine Relation Injector (RI)** — relation pre-attachment + transaction-based item creation
> 2. **PAC Vehicle Data Manager (PAC VDM)** — CCT discovery, data flattening (PULL/PUSH), field locking, year expansion, bulk sync, programmatic CCT builder
> 3. **JFB WC Quotes Advanced (JFB-WC)** — Settings-API-driven admin UI, JSON config files, site-specific field whitelisting, file-based debug log
>
> Target use case (immediate): Brick Builder HQ — manage `available_sets_data` & `mosaics_data` CCTs that are bridged to WooCommerce `product` posts so editors can work in one canonical place and the other side stays in sync. Future use cases: any JetEngine + Woo site that needs the same bridge.

---

## 1. Vision & Design Tenets

### 1.1 The "one plugin, one house" thesis
Each of the three source plugins solves a slice of the same underlying problem: **JetEngine data does not natively talk to WooCommerce, and CCTs cannot hold relations until they are saved**. Each one was built site-first and now needs to be generalized.

We collapse them into a single plugin under one stable namespace because:
- Editors only need **one settings menu** to configure data flow.
- Cross-feature concerns (e.g., field-locking a flattened field that came from a relation) become trivial when everything lives in one option store and one class graph.
- We can ship the plugin to other sites unchanged and configure it entirely through the admin UI (no per-site code).
- WooCommerce HPOS / new product tables only need to be handled in **one** adapter.

### 1.2 Non-goals
- Not a generic ETL tool. Scope is JetEngine ↔ JetEngine ↔ WooCommerce only.
- Not a replacement for JetEngine Relations. We *use* Relations; we just make them usable from CCTs and from the WC product editor.
- Not a frontend plugin. Output is via Elementor / JetEngine listings as today.

### 1.3 Design tenets
1. **Discover, don't configure.** Whenever possible, scan the live site (RI's and PAC VDM's `class-discovery.php` pattern) and present checkboxes/dropdowns rather than asking the editor to type slugs.
2. **Adapter pattern for "where does data live?"** — CCT items, post-meta on a CPT, post-meta on a Woo product, Woo product variations, the WC HPOS order table, etc. all sit behind a `Data_Target` interface so the rest of the codebase never branches on storage type.
3. **Site-specific, not site-coupled.** All site-specific knowledge lives in `wp_options` (JSON) or in upload-able JSON files (the JFB-WC pattern). Nothing in PHP files.
4. **Loop-safe by default.** Every sync direction (CCT→Woo, Woo→CCT) MUST go through a central `Sync_Guard` so that updating one side never recursively re-fires the other.
5. **Read-only by visual contract.** Field locker stays — when a value is sourced from another record, the editor sees it greyed out with a "source" tooltip.
6. **Extensible without forking.** Built-in transformers cover 80% of cases; the remaining 20% is handled by a sandboxed **Custom Code Snippets** system in admin (see §4.8). Sites can also register custom transformers / targets in their own code via documented `jedb/...` hooks.

---

## 2. What the Three Source Plugins Actually Do

A side-by-side audit of the existing capabilities. Knowing this is what makes a clean merger possible.

### 2.1 Jet Engine Relation Injector (RI)

| Capability | Where it lives today | Why we need it |
|---|---|---|
| Per-CCT config storage in custom DB table `wp_jet_injector_configs` | `class-config-db.php`, `class-config-manager.php` | Lets editors save mapping rules without bloating `wp_options`. Preserves config across plugin updates. |
| Discovery of CCTs, CPTs, Relations, parent/child sides | `class-discovery.php` | Powers every dropdown in the admin UI. |
| Render relation selectors on the CCT edit screen *before* the item exists | `class-runtime-loader.php` + `assets/js/injector.js` | This is the entire reason RI exists. Solves the "save first, relate later" problem. |
| Atomic create-CCT-item + attach-relations + create-related-record(s) in one POST | `class-transaction-processor.php` | Ensures that if any step fails, none of them persist. This is the bridge mechanic we extend to Woo. |
| Utilities tab (clear caches, reset configs, export/import) | `class-utilities.php`, `templates/admin/utilities-tab.php` | Operations love this. Ship it. |
| Admin tabs UI (Configurations / Utilities / Debug) | `class-admin-page.php` + templates | Becomes our primary admin shell. |

### 2.2 PAC Vehicle Data Manager (PAC VDM)

| Capability | Where it lives today | Why we need it |
|---|---|---|
| **Data Flattener — PULL** | `class-data-flattener.php` | When a CCT item is saved, copy fields *from* its related parent record(s) onto itself. (E.g., when a "trim" item is saved, pull `make` and `model` from the related vehicle.) |
| **Data Flattener — PUSH** | `class-data-flattener.php` | When the parent changes, push the new value down onto every related child. Bidirectional intent without bidirectional pain. |
| **Field Locker** (UI) | `class-field-locker.php` + `assets/js/field-locker.js` + `assets/css/field-locker.css` | Greys out fields whose values are sourced from a related record so editors don't accidentally edit derived data. |
| **Year Expander** | `class-year-expander.php` | Domain-specific helper that expands "2018-2022" → 2018,2019,2020,2021,2022. Generalize this concept into a "field transformer" pipeline. |
| **Bulk Sync** | `class-bulk-sync.php` | Re-runs PULL across every existing item in a CCT. Critical for migrations and recovery. |
| **CCT Builder (programmatic)** | `class-cct-builder.php` | Creates CCTs and Relations from code. Powers the "first-run setup" admin tab. |
| **Config Name Generator** | `class-config-name-generator.php` | Auto-generates a human-readable name for each new flatten config. Pure utility. |
| **Settings + Debug + Setup tabs** | `class-admin-page.php` + `templates/admin/*.php` | Same admin shell pattern as RI but with more tabs; we merge both shells into one. |

### 2.3 JFB WC Quotes Advanced (JFB-WC)

JFB-WC is the most loosely-coupled of the three, but it contributes **patterns**, not modules:

| Pattern | Where to find it | How it informs the new plugin |
|---|---|---|
| WP Settings API with sections/fields callbacks | `jfbwqa_settings_init()` (line ~1026) | Use the same pattern for the global settings page (debug toggle, default behaviors, WC consumer keys if needed). |
| JSON config files in plugin dir + UI to upload/regenerate | `jfbwqa_read_mapping()` / `jfbwqa_write_mapping()` (lines ~82-125) | Allow operators to **export/import** an entire site config as JSON. Crucial for staging→prod and for shipping the plugin pre-configured. |
| Site-specific meta-key whitelist via textarea | `jetengine_keys` setting (line ~1040) | When configuring a flatten, the dropdown of available meta keys is populated from the whitelist — keeps the UI clean. |
| File-based debug log with toggle | `debug/debug.log` + `enable_debug` checkbox + `jfbwqa_write_log()` (line ~127) | Replaces our scattered `error_log()` calls. One toggle, one file, viewable from the Debug tab. |
| Custom WC order action buttons + modal | `jfbwqa_add_order_action()` (line ~507) | Same hook pattern lets us add "Sync to CCT" / "Resync from CCT" buttons on the Woo product edit screen. |
| Dynamic placeholder replacement | `jfbwqa_replace_email_placeholders()` (line ~808) | The transformer pipeline (year expander, name builder, etc.) gets a generic templating layer. |

---

## 3. Target Architecture

### 3.1 Naming & namespacing
- **Plugin slug**: `je-data-bridge-cc`
- **Plugin display name**: `JetEngine Data Bridge CC`
- **Author / License**: Legwork Media · GPL v2 or later (matches existing JFB-WC convention).
- **PHP namespace** (light — class-prefix style to match WP conventions): `JEDB_*`
- **Constants prefix**: `JEDB_*` (e.g., `JEDB_VERSION`, `JEDB_PLUGIN_DIR`)
- **Option keys**:
  - `jedb_settings` — global settings (debug toggle, defaults, WC consumer keys, "Enable Custom PHP Snippets" toggle).
  - `jedb_meta_whitelist` — per-target meta-key allowlist (JFB-WC pattern).
  - ~~`jedb_bridge_types`~~ — *retired in v0.6.0-alpha.3 per D-25 / L-026. The flatten config IS the bridge identity; no separate template layer.*
  - `jedb_field_presets` *(added by D-26 for v0.6.0-alpha.3)* — JSON array of field-preset definitions, each target-scoped (single adapter slug) with curated mandatory-field lists + freeform group labels. Overlays onto flatten configs at edit time. Export/importable. The "I discovered the right Woo storefront-visibility field list, package it, drop it on the next site" portable knowledge layer. See §4.12.
- **Custom DB tables** (carried over and renamed):
  - `wp_jedb_relation_configs` (was `wp_jet_injector_configs`)
  - `wp_jedb_flatten_configs` (new — PAC VDM stored these in `wp_options`; promote to its own table for parity)
  - `wp_jedb_sync_log` (new — append-only audit trail for every PUSH/PULL operation, including snippet-transform failures)
  - `wp_jedb_snippets` (new — registry of custom transformer snippets; row stores slug, label, description, scope, file hash; the actual PHP code lives on disk under `wp-content/uploads/jedb-snippets/`)
- **Snippet storage on disk**: `wp-content/uploads/jedb-snippets/` — protected by an auto-written `.htaccess` (`deny from all`) plus an `index.php` to block directory listing. One file per snippet: `{slug}.php`. Lives outside the plugin dir so snippets survive plugin updates and can be backed up alongside `uploads/`.
- **Capability**: `manage_jedb` (mapped to `manage_options` by default; allows future role separation). Snippet editing further gated by a separate `jedb_can_edit_snippets` filter so super-admins can lock snippet authoring down even further.
- **Hooks** (public extension points): all prefixed `jedb/...` (e.g., `jedb/before_push`, `jedb/data_target/register`, `jedb/transformer/register`, `jedb/snippet/before_run`).

### 3.2 Top-level directory layout

```
je-data-bridge-cc/
├── je-data-bridge-cc.php              ← bootstrap (constants, dependency check, autoload)
├── uninstall.php                      ← clean drop of tables + options
├── readme.txt                         ← WP.org-style readme
├── BUILD-PLAN.md                      ← this document
├── CHANGELOG.md
├── includes/
│   ├── class-plugin.php               ← singleton, wires every subsystem
│   ├── class-config-db.php            ← table installer + low-level CRUD (RI's)
│   ├── class-config-manager.php       ← high-level façade over Config_DB
│   ├── class-discovery.php            ← merged RI + PAC VDM discoverers
│   ├── class-sync-guard.php           ← NEW: loop prevention (static flags + transient locks)
│   ├── class-debug.php                ← merged debug helpers (file log + admin viewer)
│   ├── class-utilities.php            ← cache flush, export/import config, reset
│   │
│   ├── targets/                       ← NEW: Data_Target adapter family
│   │   ├── interface-data-target.php
│   │   ├── class-target-cct.php       ← reads/writes CCT items via JE API
│   │   ├── class-target-cpt.php       ← reads/writes CPT post + post_meta
│   │   ├── class-target-woo-product.php       ← reads/writes via WC_Product API (HPOS-safe)
│   │   ├── class-target-woo-variation.php     ← NEW: variations are bridgeable too (see §4.7)
│   │   └── class-target-registry.php  ← register + look up targets by slug
│   │
│   ├── relations/                     ← from RI
│   │   ├── class-runtime-loader.php
│   │   ├── class-transaction-processor.php
│   │   └── class-relation-attacher.php  ← extracted helper from transaction processor
│   │
│   ├── flatten/                       ← from PAC VDM
│   │   ├── class-flattener.php        ← merged engine (was class-data-flattener.php)
│   │   ├── class-field-locker.php
│   │   ├── class-bulk-sync.php
│   │   └── transformers/              ← pluggable value transformers
│   │       ├── interface-transformer.php
│   │       ├── class-transformer-registry.php   ← built-ins + snippet-backed ones registered together
│   │       ├── class-year-expander.php
│   │       ├── class-name-builder.php           ← generalized from PAC VDM's name generator
│   │       ├── class-regex-replace.php          ← built-in: pattern + replacement
│   │       ├── class-format-number.php          ← built-in: currency / decimals / thousands
│   │       ├── class-lookup-table.php           ← built-in: JSON map (e.g., size code → label)
│   │       ├── class-passthrough.php
│   │       └── class-snippet-transformer.php    ← NEW: thin wrapper that runs a user-defined snippet (see §4.8)
│   │
│   ├── snippets/                      ← NEW: Custom Code Snippets subsystem (see §4.8)
│   │   ├── class-snippet-manager.php  ← CRUD, file write, syntax check, capability gate
│   │   ├── class-snippet-loader.php   ← lazy require_once of snippet files when invoked
│   │   ├── class-snippet-runner.php   ← invokes snippet inside try/catch, captures errors → debug log
│   │   └── class-snippet-installer.php  ← creates uploads/jedb-snippets/ + .htaccess + index.php on activation
│   │
│   ├── builders/
│   │   └── class-cct-builder.php      ← from PAC VDM, generalized
│   │
│   ├── admin/
│   │   ├── class-admin-shell.php      ← top-level menu + tab router
│   │   ├── class-tab-relations.php    ← RI's CCT config cards live here
│   │   ├── class-tab-flatten.php      ← PAC VDM's flatten cards live here
│   │   ├── class-tab-bridges.php      ← NEW: list/edit Bridge Types (the Q5 settings JSON UI)
│   │   ├── class-tab-targets.php      ← list discovered targets, register custom ones
│   │   ├── class-tab-snippets.php     ← NEW: code-editor UI for Custom Code Snippets
│   │   ├── class-tab-utilities.php    ← export/import/reset
│   │   ├── class-tab-setup.php        ← one-click programmatic CCT/relation creation
│   │   ├── class-tab-debug.php        ← log viewer + toggle
│   │   ├── class-settings.php         ← Settings API registration (JFB-WC pattern)
│   │   └── class-woo-product-meta-box.php   ← NEW: "Bridge to CCT" panel on product edit
│   │
│   └── helpers/
│       ├── debug.php                  ← jedb_log(), jedb_dump()
│       ├── hpos.php                   ← HPOS detection + safe order/product access
│       ├── filesystem.php             ← writes/reads uploads/jedb-snippets safely
│       └── arrays.php
│
├── templates/
│   └── admin/
│       ├── shell.php                  ← outer wrapper + tabs nav
│       ├── tab-relations.php
│       ├── tab-flatten.php
│       ├── tab-bridges.php
│       ├── tab-targets.php
│       ├── tab-snippets.php
│       ├── tab-utilities.php
│       ├── tab-setup.php
│       ├── tab-debug.php
│       ├── tab-settings.php
│       ├── relation-config-card.php    ← from RI
│       ├── flatten-config-card.php     ← from PAC VDM
│       ├── snippet-editor.php          ← CodeMirror-backed PHP editor + Test panel
│       └── woo-product-meta-box.php
│
├── assets/
│   ├── js/
│   │   ├── admin.js                    ← shared
│   │   ├── relation-injector.js        ← from RI (renamed from injector.js)
│   │   ├── field-locker.js             ← from PAC VDM
│   │   ├── snippet-editor.js           ← NEW: wires WP's bundled CodeMirror, Test button, syntax-error display
│   │   └── woo-product-bridge.js       ← NEW: handles "type" select + dynamic field reveal on product edit (incl. variations)
│   └── css/
│       ├── admin.css
│       └── field-locker.css            ← from PAC VDM
│
└── languages/
    └── je-data-bridge-cc.pot
```

### 3.3 Class-graph (high-level)

```
JEDB_Plugin (singleton, 'plugins_loaded')
 ├── boots → JEDB_Config_DB::install() + JEDB_Snippet_Installer::install() on activation
 ├── registers → JEDB_Target_Registry (CCT / CPT / Woo_Product / Woo_Variation targets)
 ├── registers → JEDB_Transformer_Registry (built-ins + every enabled snippet)
 ├── instantiates → JEDB_Discovery (read-only, cached)
 ├── instantiates → JEDB_Sync_Guard (singleton)
 ├── instantiates → JEDB_Debug (singleton)
 ├── instantiates → JEDB_Snippet_Manager (singleton, admin-only fully)
 │
 ├── ADMIN context:
 │   └── JEDB_Admin_Shell → JEDB_Tab_*  (renders tabs, owns Settings API)
 │       ├── JEDB_Woo_Product_Meta_Box (registers on 'add_meta_boxes')
 │       └── JEDB_Tab_Snippets (CodeMirror editor + Test runner)
 │
 └── RUNTIME context:
     ├── JEDB_Relation_Runtime_Loader  → injects JS on CCT edit
     ├── JEDB_Transaction_Processor    → on CCT save with $_POST['jedb_relations']
     ├── JEDB_Flattener                → on CCT save (PULL) + on parent update (PUSH)
     │     └── pipes through JEDB_Transformer chain
     │           └── JEDB_Snippet_Transformer → JEDB_Snippet_Runner (try/catch + log)
     ├── JEDB_Condition_Evaluator      → DSL parser + snippet runner for bridge conditions
     └── JEDB_Field_Locker             → injects locker JS/CSS on CCT/CPT/Woo edit
```

### 3.4 JetEngine storage model — what lives where

> **Critical:** This is the canonical reference for where each kind of
> JetEngine data lives. See `LESSONS-LEARNED.md` L-007 for the bug that
> motivated this section. Update this table whenever JE moves something.

JetEngine uses two distinct storage systems for two distinct purposes:

| Concern | Storage | Notes |
|---|---|---|
| **CCT data** (one row = one CCT item) | Per-CCT dedicated table: `{prefix}jet_cct_{slug}` | Reads via `$cct_factory->db->get_item($id)` / `db->query($args, $limit, $offset, $order)`. Writes via `db->insert($data)` / `db->update($data, $where)`. **Never** in `wp_posts`. **Never** in `wp_postmeta`. |
| **JE relation data** (one row = one connection) | Per-relation table: `{prefix}jet_rel_{relation_id}` | Schema verified in L-014. Reads/writes via `jet_engine()->relations->...` API preferred over direct SQL. |
| **CCT / CPT / Relation / Query / Glossary CONFIG** (the schema, not the data) | Single master table: `{prefix}jet_post_types` | Discriminated by the `status` column (see table below). `meta_fields` column is a serialized PHP array. **Canonical home for field schemas in JE 3.8+.** |
| WP-registered CPTs (post-type registration via `register_post_type()`) | WP code at `init` priority + JE meta-box configs in `{prefix}jet_post_types` rows with `status='publish'` | Data lives in `wp_posts` + `wp_postmeta` like every other CPT. JE's row in `jet_post_types` carries the meta-box config (currently empty in our discovery — JE may hold field configs elsewhere for `status='publish'` rows; needs verification before Phase 4). |

**`{prefix}jet_post_types` `status` column dictionary:**

| `status` value | Object kind | `meta_fields` content |
|---|---|---|
| `content-type` | A CCT (e.g. `mosaics_data`) | The serialized field schema for that CCT |
| `publish` | A JE-registered CPT (e.g. `story_bricks`) | Empty in samples; field configs likely in JE meta-boxes elsewhere |
| `relation` | A JE Relation (e.g. `Mosaic → Product`) | Empty unless the relation has user-defined meta fields |
| `query` | A JE Query Builder query | Empty (queries' definitions live in `args`) |
| `glossary` | A JE Glossary (e.g. `Story Types`) | Serialized array of `{value, label}` entries |

**Discovery resolution order** for CCT field schemas (in `JEDB_Discovery::get_cct_fields_from_instance()`):

1. **`{prefix}jet_post_types` SELECT** — JE 3.8+ canonical home. **Channel #1.**
2. `$cct_factory->get_arg('meta_fields')` — older JE compatibility.
3. `$cct_factory->get_arg('fields')` — even older alias.
4. `$cct_factory->args['meta_fields']` / `['fields']` — direct property fallback.
5. `get_option('jet_engine_active_content_types')[N]['meta_fields']` — pre-3.8 storage (kept for back-compat).
6. `$cct_factory->get_fields_list()` — names-only fallback if every other channel returns nothing.

Each returned field carries a `source` key so the diagnostic shows exactly which channel produced the data on this site.

**Prefix discipline:** every table reference in code MUST be `$wpdb->prefix . 'name'`. Even display strings — they end up in screenshots and shared logs. See `LESSONS-LEARNED.md` L-008.

### 3.5 Bridge condition model — declarative DSL with snippet escape hatch

> **Critical:** This section governs how multiple bridge configs can share
> a source target without colliding (the M:1 / 1:N pattern). See
> `LESSONS-LEARNED.md` L-013 for the design rationale.

A bridge config carries an optional `condition` field. The sync engine
evaluates the condition against the source and target context before
applying the bridge. If the condition returns false, the bridge is skipped
for that sync event and a `skipped_condition` row is written to
`wp_jedb_sync_log`.

**Two evaluation modes:**

1. **Declarative DSL** — a tiny expression language for simple cases. No
   loops, no function calls, no side effects. Versioned via `dsl_version: 1`
   in the bridge config so we can extend without breaking existing configs.
2. **Snippet escape hatch** — `condition_snippet: my_complex_condition_slug`
   references a sandboxed snippet (Phase 5b runtime). Snippet returns bool;
   throws are treated as "skip this bridge" and logged.

**v1 DSL grammar:**

```
condition  := expr
expr       := and_expr ( "OR" and_expr )*
and_expr   := not_expr ( "AND" not_expr )*
not_expr   := "NOT"? primary
primary    := "(" expr ")" | comparison
comparison := value op value
op         := "==" | "!=" | ">" | "<" | ">=" | "<="
              | "contains" | "not_contains"
              | "starts_with" | "ends_with"
              | "in" | "not_in"
value      := PATH | LITERAL
PATH       := "{" SCOPE "." FIELD_NAME "}"
SCOPE      := "source" | "target" | "cct" | "product" | "variation"
              -- "cct" / "product" / "variation" are aliases that resolve
              -- to source/target depending on bridge type
LITERAL    := QUOTED_STRING | NUMBER | BOOLEAN | ARRAY_LITERAL
ARRAY_LITERAL := "[" LITERAL ( "," LITERAL )* "]"
```

**Examples:**

```
{product.product_cat} contains "Mosaics"
{cct.has_instructions_pdf} == "yes"
{product.status} == "publish" AND {cct.featured} == "yes"
{cct.price} >= 500 AND {cct.price} <= 1500
{product.product_type} in ["simple", "variable"]
NOT ({product.stock_status} == "outofstock")
```

**Conditional sync engine flow** (covered in §4.9):

1. Sync trigger fires (CCT save, product save, manual sync, bulk sync).
2. Engine collects every bridge config whose `source_target` matches the
   triggering source kind.
3. For each, the `JEDB_Condition_Evaluator` runs the condition.
4. Bridges with `condition` returning true (or no condition) execute in
   declared `priority` order (default 100; lower runs first).
5. Each individual bridge application is still 1:1 and atomic per D-1.
6. Every applied / skipped / errored bridge writes one row to
   `wp_jedb_sync_log` with the appropriate status.

**Editor docs (Phase 6 / Phase 7 deliverable):** Bridges admin tab includes
a "Condition syntax" help drawer documenting every operator with copy-paste
examples drawn from the user's actual schema. Snippet authors get a
separate "Writing condition snippets" appendix in the developer docs.

---

## 4. The Big New Piece: WooCommerce Target Adapter

This is the only genuinely new code, and it deserves its own section because it has the highest risk surface.

### 4.1 Why a dedicated Target adapter
RI's transaction processor and PAC VDM's flattener both currently assume the destination is either a CCT row or a CPT post. WooCommerce products are technically CPTs (`product`), but writing to them naively via `update_post_meta()` will:
- Break HPOS-aware product reads (the `wc_product_meta_lookup` table won't update).
- Skip Woo's variation handling, type changes, stock sync, and price formatting.
- Bypass `WC_Product` filters that other Woo plugins rely on.

Solution: every read/write to a Woo product goes through `WC_Product` / `WC_Product_Factory` / `wc_get_product()` / `$product->save()`.

### 4.2 The interface

```php
interface JEDB_Data_Target {
    public function get_slug(): string;             // 'cct::available_sets_data', 'posts::product', etc.
    public function get_label(): string;            // 'Available Sets (CCT)', 'WooCommerce Product', ...
    public function exists( $id ): bool;
    public function get( $id ): array;              // returns flat assoc array of fields
    public function update( $id, array $fields ): bool;
    public function create( array $fields );        // returns new id
    public function get_field_schema(): array;      // for UI dropdowns
    public function supports_relations(): bool;
}
```

### 4.3 The Woo product target — what's special

`JEDB_Target_Woo_Product` lives in `includes/targets/class-target-woo-product.php` and implements:

- `get( $id )` → `wc_get_product( $id )`, then maps `$product->get_data()` plus `$product->get_meta_data()` into the flat array.
- `update( $id, $fields )` → uses the typed setters (`$product->set_name()`, `$product->set_regular_price()`, `$product->update_meta_data()`, etc.) with a fallback `update_meta_data()` for unknown keys.
- After every write: `$product->save()` (this is what triggers HPOS sync + lookup table refresh).
- `get_field_schema()` returns the union of: core Woo product fields (name, sku, price, stock_status, categories, tags, image, gallery, downloadable_files…) + any registered custom meta keys (the JFB-WC `jetengine_keys` pattern, but generalized to "woo product meta keys").

### 4.4 HPOS / new tables — the gotchas

| Concern | Detail | Mitigation in plugin |
|---|---|---|
| `wc_product_meta_lookup` cache table | Used by Woo for fast product queries. Direct `update_post_meta()` does NOT update it. | Always go through `$product->save()`. Helper `jedb_hpos_safe_save( $product )` provided. |
| HPOS for **orders** | Woo 8.x+ moves orders out of `wp_posts` into `wp_wc_orders`. | We don't write orders, only products. But `helpers/hpos.php` exposes `jedb_is_hpos_enabled()` for any future order-touching code (e.g., when JFB-WC patterns get pulled in). |
| Product variations | Variations are their own posts (`product_variation`) hanging off a parent variable product. Bridging requires deciding whether the CCT row maps to the parent or to a specific variation. | **In scope.** Both `Target_Woo_Product` and a separate `Target_Woo_Variation` adapter are shipped. Bridge config picks one. See §4.7 for the full pattern (and how "Has Instructions PDF" becomes a variation). |
| Product type changes (simple → variable, etc.) | Change of type can lose meta. | When the bridge config detects a product-type field in the mapping, the UI warns the editor and the flattener refuses PUSH unless `force=true`. |
| Image / Gallery fields | Woo expects integer attachment IDs, JE galleries store comma-separated IDs. | Conversion happens in the target adapter, not in the flattener. Single source of truth. |
| Categories / tags | Woo uses taxonomies (`product_cat`, `product_tag`). | Adapter accepts term IDs OR slugs OR names; resolves to IDs and uses `wp_set_object_terms()`. |
| Custom product meta from third-party Woo plugins | Need to be visible to bridge UI without hardcoding. | Whitelist textarea in Settings tab (JFB-WC pattern) OR auto-discover by sampling N existing products. |

### 4.5 The "Bridge to CCT" meta box (view of flatten config)

> **Locked decision (D-10):** JE Relations are the **primary** link
> mechanism. `cct_single_post_id` is a special-case alternative for CCTs
> that have JE's "Has Single Page" enabled. **No `_jedb_bridge_cct_id`
> meta** is stored on the product — the link lives where JE owns it.
> See `LESSONS-LEARNED.md` L-013/L-015.

> **Locked decision (D-27 — added 2026-05-10):** the meta box is a *view*
> of an existing flatten config, **not** an authoring surface for a
> separate "bridge type" template. The flatten config IS the bridge.
> The meta box resolves the relevant flatten config(s) at render time
> and surfaces controls + selected fields on the Woo product edit
> screen. Bridge types as a separate template layer are out — see D-25
> in §8 and L-026 in `LESSONS-LEARNED.md` for the post-mortem.

> **Scope note (Phase 4):** the Bridge meta box is intentionally Woo-specific
> — it lives on `product` and `product_variation` edit screens because that's
> where editors spend their time managing shop content. **The bridge engine
> itself is target-agnostic** (see §4.5.1) and supports CCT ↔ CPT, CCT ↔ CCT,
> and CPT ↔ CPT bridges through the Flatten admin tab today. A generic
> non-Woo CPT meta box is parked as a deferred future addition if/when needed.

#### Why the meta box exists at all (the core value)

WooCommerce products are notoriously hard to extend with custom fields. The Mosaic CCT carries fields like `theme_idea`, `pdf_link`, `internal_notes` that editors operationally care about while looking at a product, but Woo's product edit screen has no native home for them. The meta box's primary job is to **surface CCT fields directly on the product edit screen** so editors can edit them without context-switching to JE's CCT admin — and have those edits sync back to the CCT via the existing reverse-pull engine.

Secondary jobs: status display, manual sync trigger, per-product locks/overrides, and link/unlink controls.

#### How the meta box resolves "which bridge applies to this product"

No `_jedb_bridge_type` meta. No bridge type lookup. The meta box at render time:

1. **Walks `wp_jedb_flatten_configs`** for any row whose `target_target` matches this post's type (`posts::product` or `posts::product_variation`).
2. **For each candidate flatten config**, checks whether the resolution path (`link_via.type`) yields a linked source for THIS specific product:
   - `je_relation` → query `{prefix}jet_rel_{relation_id}` for a row whose `child_object_id` = current post ID (or `parent_object_id`, depending on `side`). Found → that flatten config governs this product.
   - `cct_single_post_id` → query the source CCT for any row whose `cct_single_post_id` = current post ID. Found → that flatten config governs this product.
3. **Renders one panel per resolved flatten config** (typically zero or one — if a product matches multiple, each gets its own panel and the editor sees them all). When zero are resolved, renders the "Not linked yet" picker (below).

This is the same resolution logic the engine already uses to find the linked source on save events. **No new resolver code** — the meta box just calls the existing path in read mode.

#### Controls per resolved bridge

1. **Linked CCT row label + edit link** — "Linked to: Mosaic CCT row #2 — *Sunset Vista* (via JE Relation #9, auto-attached). [Edit in JetEngine →]"
2. **Bridge config quick reference** — slug of the governing flatten config + a deep link to its row in the Flatten admin tab. ("Edit in Flatten tab →")
3. **Last syncs** — top 3 rows from `wp_jedb_sync_log` for this product, including direction / status / message / time. "View full sync log →" links to a filtered Debug-tab view.
4. **Surfaced fields** — for each mapping in the flatten config where `surface_on_target = true` AND the target adapter's `is_natively_rendered($field)` returns false (D-16), the meta box renders an editable input. Grouping uses the per-mapping freeform `group` label so editors see "Pricing", "Identity", "Variations" headings (D-26 — groups are free-form per preset / per mapping).
5. **Per-product overrides** — a hidden field always submitted alongside the surfaced inputs:
   - **Lock checkbox** → writes `_jedb_bridge_locked` post meta. Engine guard at the top of `apply_bridge()` checks this and logs `skipped_locked, reason: per_product_lock` when set.
   - **Direction override** radio → writes `_jedb_bridge_direction_override` post meta. Engine treats this product as if the bridge's direction were the override (push-only / pull-only / no-sync) without touching the bridge config itself.
6. **Sync now button** → calls `JEDB_Flattener::apply_bridge()` directly with `origin: manual`. Same engine path, same Sync_Guard, same sync log row.
7. **Unlink button** → drops the JE relation row (or clears `cct_single_post_id`) for this product. Idempotent. Surfaces a confirm dialog.

#### Controls when the product is NOT linked yet

1. **Available bridge configs for this product** — list of flatten configs whose `target_target` matches this product type. Editor picks one.
2. **Linked CCT row picker** — Ajax dropdown of source CCT rows from the chosen bridge's `source_target`. Reuses the `wp_ajax_jedb_relation_search_items` endpoint from Phase 2.
3. **Link button** → calls `JEDB_Relation_Attacher::attach()` (idempotent) for `je_relation` bridges, OR writes `cct_single_post_id` for Has-Single-Page bridges. Reloads the meta box in "linked" state.

#### Variation scope (Phase 4b)

When the product type is `variable`, the meta box adds a top-level radio: *"Bridge the parent product"* vs *"Bridge a specific variation"*. The latter shows a second dropdown picking which variation. The variation reconciliation engine (Phase 4b) consumes the flatten config's `variations[]` array to auto-create / update / soft-delete variations from CCT fields.

#### Stored product-side meta (minimal — link is NOT here)

```
_jedb_bridge_locked              bool   "Don't sync at all" override for this specific product
_jedb_bridge_direction_override  enum   ''|push|pull|bidirectional|none — overrides flatten config direction for this product
_jedb_bridge_last_manual_sync_id int    sync_log row id of last manual "Sync now" — drives the status block
```

**No `_jedb_bridge_type`.** The flatten config IS the bridge identity; product-level meta is purely for behavior overrides on this one product.

**No `_jedb_bridge_cct_id`.** Per D-10, the link lives in JE Relations / `cct_single_post_id` only.

#### Engine integration (per-product overrides)

Both `JEDB_Flattener::apply_bridge()` and `JEDB_Reverse_Flattener::apply_bridge()` get a 3-line guard near the top of the method:

```
if ( get_post_meta( $target_id, '_jedb_bridge_locked', true ) ) {
    return $this->log_skipped_locked( $bridge, $source_id, $target_id, 'per_product_lock', $origin );
}

$override = (string) get_post_meta( $target_id, '_jedb_bridge_direction_override', true );
if ( '' !== $override ) {
    if ( ! $this->call_direction_allowed_under_override( $current_call_direction, $override ) ) {
        return $this->log_skipped_direction_override( $bridge, $source_id, $target_id, $override, $origin );
    }
}
```

The lock guard is universal. The override guard is direction-aware: a forward-push call respects an override of `pull` (skip), `none` (skip), but allows `push` and `bidirectional`.

Both new statuses (`skipped_locked` with `reason: per_product_lock`, and `skipped_direction_override`) extend the existing §4.9 status taxonomy. Sync log records them with full context.

#### Link mechanism (D-10)

A bridge type declares its `link_via` in the bridge config JSON (mutually exclusive):

| `link_via` value | Mechanism | When to use |
|---|---|---|
| `je_relation` | A row in `{prefix}jet_rel_{relation_id}` with `parent_object_id` = source `_ID` and `child_object_id` = product ID (or vice-versa). The `relation_id` is declared in the bridge config. | Default for CCT ↔ Woo bridges. Required when you want JetEngine Smart Filters / Listings / Query Builder to traverse the link natively. |
| `cct_single_post_id` | The JE-managed `cct_single_post_id` column on the CCT row stores the linked WP post ID. No relation row needed; JE already maintains the column. | **Only available** when the CCT has JE's "Has Single Page" enabled. Detected via the schema's `jedb_role: 'native_single_page_link'` marker (added in 0.2.5). |

**Auto-create policy (D-13):** the plugin does NOT auto-create **the JE Relation definition** (the row in `wp_jet_post_types` that defines what counts as related to what — that lives in JetEngine → Relations and is created manually for v1). However, the plugin DOES auto-write **relation table rows** (in `{prefix}jet_rel_{id}`) when it can self-heal a missing link — see "Self-heal" below. For declarative provisioning of relation *definitions* (Phase 6 setup-tab presets), the cct-builder pattern from PAC VDM creates them programmatically — but only when invoked via a preset, never as a side effect of bridge-config save.

#### Self-heal: auto-attach when JE Relation row is missing (per L-021)

> **Reality check, post-L-021:** JE's Has-Single-Page feature creates the
> linked post on CCT save (populates `cct_single_post_id`). It does NOT
> write a row in `{prefix}jet_rel_{id}`. The relation row only appears
> when the user explicitly attaches via the picker UI. Without our
> self-heal, brand-new CCT rows whose linked post exists via Has-Single-
> Page would log `skipped_no_target` forever and JE Smart Filters /
> Listings would never see them.

`link_via.type = 'je_relation'` carries two opt-in flags (both default
**true** to make sensible behavior the default):

| Flag | Default | Effect |
|---|---|---|
| `fallback_to_single_page` | true | When the relation table lookup returns 0 rows, retry via the source CCT row's `cct_single_post_id` column. The fallback verifies the linked post's type matches the relation's other endpoint before accepting. |
| `auto_attach_relation` | true | When the fallback resolves successfully, write the missing row to `{prefix}jet_rel_{id}` via `JEDB_Relation_Attacher::attach()` (idempotent). After the first sync, the relation row exists, JE Smart Filters / Listings work natively, and future syncs use the fast path. |

Both flags are exposed in the Flatten admin tab's "Self-heal options"
fieldset under the link-via picker. Sync-log `context_json` records the
resolution method (`relation_row` / `fallback_single_page` /
`cct_single_post_id` / `none`) and an `auto_attached` boolean so the
user can verify at a glance whether a particular sync used the fallback
or the fast path.

This pattern explicitly resolves the "Path 4" question from
2026-05-03: editors expect to set up a JE Relation, save a CCT row, and
have frontend filters work — without touching the picker. The
self-heal is what makes that expectation hold.

*(Stored product-side meta is documented in the "Per-product overrides" controls block above. It deliberately does NOT include a `_jedb_bridge_type` meta — the flatten config IS the bridge identity, no template layer is involved. Per D-25, see L-026 for the post-mortem on why an earlier alpha had one.)*

### 4.5.1 Supported source / target combinations

The bridge engine is target-agnostic. The CCT ↔ Woo Product example is the **headline use case** (driven by Brick Builder HQ's Available Sets and Mosaics shop), but the underlying machinery does not assume either side is Woo.

Every record store the plugin can read or write is wrapped in an adapter implementing the `JEDB_Data_Target` interface (§3.4). There are exactly four adapters:

| Adapter | Slug format | Backing store | Hook for change events |
|---|---|---|---|
| `JEDB_Target_CCT` | `cct::{slug}` (e.g. `cct::available_sets_data`) | JetEngine CCT items via `$inst->db->update()` + direct SQL fallback (L-003 / L-004) | `jet-engine/custom-content-types/created-item/{slug}` AND `updated-item/{slug}` at priority 20 (D-19) |
| `JEDB_Target_CPT` | `posts::{post_type}` (e.g. `posts::story_bricks`) | Standard WP posts + post-meta via `wp_insert_post()` / `update_post_meta()` | `save_post_{post_type}` at priority 20 |
| `JEDB_Target_Woo_Product` | `posts::product` | WC products via `WC_Product->save()` (HPOS-safe) | `woocommerce_update_product` + `woocommerce_new_product` |
| `JEDB_Target_Woo_Variation` | `posts::product_variation` | WC variations via `WC_Product_Variation->save()` (HPOS-safe) | Same Woo hooks, scoped to variation post type |

The `JEDB_Flattener::apply_bridge()` and `JEDB_Reverse_Flattener::apply_bridge()` engines take a `source_target` slug and a `target_target` slug from the bridge config. They don't know or care which adapter kind sits behind each — they call `Target_Registry::get( $slug )` and then `->get()` / `->update()` on whatever comes back.

**All of these source/target combinations work today through the engine path:**

| Source | Target | Real example | Status |
|---|---|---|---|
| `cct::available_sets_data` | `posts::product` | Available Sets ↔ WC product (cat: `available-sets`) via JE Relation #8 | Verified on staging through Phase 3.6. |
| `cct::mosaics_data` | `posts::product` | Mosaics ↔ WC product (cat: `mosaics`) via JE Relation #9 | Verified on staging through Phase 3.6 (incl. bidirectional). |
| `cct::story_bricks_data` | `posts::story_bricks` | Story Bricks (CCT) ↔ Story Bricks (CPT) via JE native Has-Single-Page | Engine supports it; no flatten config wired up yet. |
| `cct::featured_parts_data` | `posts::featured_parts` | Featured Parts CCT ↔ CPT (on hold per site-structure.md) | Engine supports it; on hold. |
| `posts::product` | `cct::mosaics_data` | Reverse leg of bidirectional Mosaics bridge | Verified on staging through Phase 3.5. |
| `posts::story_bricks` | `cct::story_bricks_data` | Reverse direction of Story Bricks bridge | Engine supports it; would just need `direction: pull` on the flatten config. |
| `cct::A` | `cct::B` | CCT-to-CCT bridge (e.g. derived data sync between two CCTs) | Engine supports it; no test coverage yet. |
| `posts::A` | `posts::B` | CPT-to-CPT bridge | Engine supports it; no test coverage yet. |

#### Where the Bridge meta box (§4.5) is intentionally Woo-specific

The Phase 4 Bridge meta box lives on `product` and `product_variation` edit screens — that's the **whole point** of the meta box pattern. Editors live in the Woo product editor day-to-day, so the high-traffic surface is where the controls go.

For non-Woo bridges (CCT ↔ CPT, CCT ↔ CCT, CPT ↔ CPT), the **Flatten admin tab is the editor** AND surfaces all the operational controls Phase 4 was originally going to put in a meta box (per D-25/D-27, the Flatten admin tab is now the only authoring surface and the meta box is purely a per-product control panel rendered on Woo edit screens specifically).

#### Future expansion — generic CPT meta box

If editors later want the same "view of flatten config" meta box on a non-Woo CPT edit screen (e.g. on the Story Bricks CPT), it's a clean extraction from the Phase 4 Day 2 meta box — same template, just a different `add_meta_box()` post type. Not in current scope; would only be built when a real consumer drives the requirement.

### 4.6 The "Has Single Page = the linked post" CCT pattern (redirect shim)

Using JetEngine's "Has Single Page" feature pointed at any linked post means we never need to build a separate CCT single template. The bridge's `link_via` resolution returns the linked post ID; the CCT's "single page" URL resolves to that post's permalink via a small `template_redirect` shim — regardless of what kind of post sits on the other end of the bridge.

```
add_action( 'template_redirect', function () {
    if ( ! jedb_is_jet_cct_single() ) {
        return;
    }
    $linked_post_id = jedb_get_linked_post_for_current_cct(); // walks every active bridge
    if ( ! $linked_post_id ) {
        return;
    }
    wp_safe_redirect( get_permalink( $linked_post_id ), 301 );
    exit;
});
```

(The actual implementation will use JetEngine's CCT-single detection helpers, not the placeholder function names above.)

**Target-agnostic.** The shim doesn't care whether the linked post is a `product`, a `story_bricks` CPT, a custom CPT, or anything else — it just resolves the bridge config's `link_via`, reads the linked post ID, and redirects. The "WC product" framing was historically the headline use case (Brick Builder HQ's Available Sets and Mosaics) but the engine path is identical for any source CCT → target post bridge.

**Opt-in per flatten config.** Each flatten config's `config_json` carries a `cct_single_redirect` boolean (default `false`) — exposed as a checkbox in the Flatten admin tab editor (alongside `auto_create_target_when_unlinked`). The shim only fires for bridges that opt in. Reasoning: some bridges might want the CCT to remain frontend-visible (e.g., a CCT acting as a hidden "data record" bridged to a CPT for filtering, where the CPT single is the display surface but the CCT single shouldn't redirect).

*(Earlier drafts attached this flag to a "bridge type" template layer; per D-25 / D-27 the template layer was retired and the flag now lives directly on the flatten config that already governs the bridge.)*

**Admin escape hatch.** Logged-in users with `manage_options` who pass `?jedb_no_redirect=1` skip the redirect, so the CCT single template (or "no single template" 404) is still inspectable for debugging.

**Direction guard.** The shim only redirects for bridges where `direction` includes `push` (i.e., the CCT is canonical and the linked post is the rendered face). For pull-only bridges, the CCT is the display surface and the redirect would invert the intended flow.

### 4.7 Variation bridging — the "Has Instructions PDF" pattern

> **Locked decision (L-015):** Variations represent **different purchase
> options for ONE source record**, not different bridge types. Each
> variation's data comes from the same CCT row as its parent product.
> Bridge-type disambiguation across multiple source kinds is handled by
> the conditional engine in §4.9 — typically using product category,
> NOT variations.

Variations are first-class targets in v1. The use case that drove the decision: a Mosaic CCT row has a `has_instructions_pdf` boolean and an `instructions_price` field. We want that to surface as a **variation** on the parent Mosaic product, not a separate product, so the storefront shows a single product page with a "Just the build (in-person)" / "Build + Instructions PDF" radio. This is also the standard Woo UX for any "with extras" purchase decision — and it's *that one Mosaic*'s purchase options, not a routing mechanism for "is this a Mosaic or an Available Set?".

**How it works end to end:**

1. **Bridge Type definition** in `jedb_bridge_types` JSON includes a `variations` block:
   ```json
   {
     "slug": "mosaic",
     "label": "Mosaic",
     "cct": "mosaics_data",
     "product_type": "variable",
     "variations": [
       {
         "slug": "build_only",
         "label": "Build only (no instructions)",
         "show_when": "true",
         "price_field": null,
         "downloads": []
       },
       {
         "slug": "with_instructions",
         "label": "Includes Instructions PDF",
         "show_when": "{has_instructions_pdf} == true",
         "price_field": "instructions_price",
         "downloads": ["instructions_pdf_attachment"]
       }
     ]
   }
   ```
   The `show_when` mini-DSL (parsed by a tiny expression evaluator, **not** `eval`) controls whether the variation should exist for a given CCT row.

2. **`Target_Woo_Variation` adapter** writes through `WC_Product_Variation`'s typed setters (`->set_regular_price()`, `->set_downloads()`, etc.) and `->save()`. Same HPOS-safe rules as products.

3. **Variation reconciliation on PUSH** (when CCT row changes):
   - For each variation defined in the bridge type:
     - Evaluate `show_when` against current CCT field values.
     - If true → ensure the variation exists; create if missing; PUSH mapped fields.
     - If false → soft-delete (set `status = private`) so the variation hangs around for analytics but isn't purchasable. Hard-delete only on explicit "Reconcile" button.
   - Variations the bridge type doesn't know about are **left alone** — third-party plugins or manual variations stay untouched.

4. **The "Variation Scope" radio in the meta box** (§4.5) is what tells the bridge whether you want to write *to* a specific variation manually or let the auto-reconciliation manage them. 99% of the time editors leave it on "Bridge the parent product" and let the variation engine do its job.

5. **PULL direction** (Woo → CCT): edits to a managed variation's price/downloads back-propagate to the corresponding CCT field defined in the bridge type. Edits to *unmanaged* variations are ignored (no field to map them to).

**Pitfalls specific to variations** (added to §9 risk register):
- Stock-status drift between parent and variations.
- Variation attribute taxonomy (`pa_*`) auto-creation — bridge types may need to declare attributes upfront.
- Variations ordering — Woo respects a `menu_order`; we expose it as a per-variation field in the bridge type JSON.

### 4.8 Custom Code Snippets — the user-defined transformer system

> **Locked decision (D-11):** Every field mapping carries **two**
> transformer chains: `push_transform` (source → target) and
> `pull_transform` (target → source). They are **not** assumed to be
> inverses. Built-in transformers ship as paired inverses where
> well-defined; custom snippets are direction-agnostic functions and the
> bridge config picks which snippet runs in which chain. See
> `LESSONS-LEARNED.md` L-010.

**Why this exists.** Built-in transformers (Year Expander, Name Builder, Regex Replace, Format Number, Lookup Table) handle most cases, but every site eventually needs a one-off transformation that doesn't fit. Rather than ship a code change for each site, we let admins define a transformer in PHP from the admin UI. Snippets are also how power-users customize bridge behavior without forking the plugin.

**The mental model.** A snippet is a small PHP function with a fixed signature, stored as a single file in `wp-content/uploads/jedb-snippets/{slug}.php`. The Flatten config UI lets you pick "Custom Snippet → my_strip_lego_trademark" from the same dropdown that lists built-in transformers — separately for the push chain and the pull chain.

**Bidirectional chains in a flatten config:**

```json
{
  "source_field": "display_price_publicly",
  "target_field": "featured",
  "push_transform": [
    { "type": "builtin", "name": "yes_no_to_bool" }
  ],
  "pull_transform": [
    { "type": "builtin", "name": "bool_to_yes_no" }
  ]
}
```

```json
{
  "source_field": "description",
  "target_field": "short_description",
  "push_transform": [
    { "type": "snippet", "slug": "my_strip_html_to_plain" },
    { "type": "builtin", "name": "truncate_words", "args": { "limit": 30 } }
  ],
  "pull_transform": [
    { "type": "noop", "comment": "HTML can't be re-derived from plain text; pull is intentionally no-op" }
  ]
}
```

The Bridges admin UI renders two transformer-chain pickers per mapping, side by side, labeled `→ when pushing` and `← when pulling`. A snippet appears in both pickers; the editor adds it to whichever chain (or both) makes sense.

**Snippet condition mode (per L-013):** the same snippet runtime hosts **condition snippets** for the conditional bridge engine (§4.9). The function signature is identical (`($value, array $context = [])`); only the contract differs — condition snippets MUST return `bool`. Throws are treated as "skip this bridge" by `JEDB_Condition_Evaluator` and logged with `status='skipped_error'`.

**File anatomy.** Every snippet file looks like this — the wrapper is auto-generated on save; the editor only sees the `// CODE BEGIN` / `// CODE END` body:

```php
<?php
/**
 * Snippet: my_strip_lego_trademark
 * Created by: l.gallucci97 (admin)
 * Last edited: 2026-02-28 11:42:00 UTC
 * Hash: c4f9...
 *
 * Input expected:  string (raw product description)
 * Output expected: string
 *
 * Description (admin-editable):
 *   Removes "LEGO®", "LEGO(R)", trademark symbols and trailing whitespace.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function jedb_snippet_my_strip_lego_trademark( $value, array $context = [] ) {
    // CODE BEGIN
    if ( ! is_string( $value ) ) return $value;
    $value = preg_replace( '/LEGO\s*(®|\(R\))/iu', 'LEGO', $value );
    return trim( $value );
    // CODE END
}
```

**The `$context` parameter** gives snippets access to:
- `$context['source_target']` — the `JEDB_Data_Target` instance the value came from.
- `$context['source_id']` — the source record ID.
- `$context['source_field']` — the source field key.
- `$context['target_target']` / `target_id` / `target_field` — destination equivalents.
- `$context['direction']` — `pull` or `push`.
- `$context['bridge_type']` — slug from `jedb_bridge_types`, if applicable.

**Admin UI (Snippets tab).** A list of snippets with: status (enabled / disabled / errored), label, description, last-edit info, and a "Test" button. Clicking a snippet opens a CodeMirror PHP editor (using WP's bundled `wp_enqueue_code_editor`) with:
- Input field to type a sample value.
- Run-Test button → invokes the snippet against the sample value, displays result + execution time + any caught exceptions.
- Save button → does a syntax check (`php -l` via `shell_exec` if available, else `token_get_all` validation) before writing the file. If syntax fails, the editor stays open with errors highlighted; the file on disk is *not* touched.
- "Where used" panel showing every Flatten config that currently invokes this snippet.

**Lifecycle and safety.**

| Stage | Behavior |
|---|---|
| Activation | `JEDB_Snippet_Installer` ensures `uploads/jedb-snippets/` exists with `.htaccess` (`deny from all`) and `index.php`. |
| Snippet save | Capability check (`manage_options` AND `apply_filters('jedb_can_edit_snippets', true, $user_id)`); nonce check; syntax validation; file written 0644; SHA-256 hash stored in `wp_jedb_snippets`. |
| Snippet load | `JEDB_Snippet_Loader::load($slug)` → `require_once` only when first invoked in the request, then function exists for the rest of the request. |
| Snippet run | `JEDB_Snippet_Runner::run($slug, $value, $context)` wraps the call in try/catch + `set_error_handler` so a fatal in user code can't take down a save. On error: returns the original unmodified `$value`, marks the snippet as `errored`, writes a full stack trace to debug log, surfaces an admin notice. |
| Snippet disable | Setting `enabled = false` in `wp_jedb_snippets` makes it a no-op; the file stays on disk for editing. |
| Snippet delete | Removes file + DB row + warns about every Flatten config still referencing it (does not auto-orphan configs). |
| Plugin update | Snippets survive (they live in `uploads/`, not the plugin dir). |
| Plugin uninstall | `uninstall.php` asks (via a settings flag) whether to also wipe `uploads/jedb-snippets/`. Default: keep. |

**The big safety toggle.** In Settings, a top-level checkbox **"Enable Custom PHP Snippets"** must be ON for the Snippets tab to appear at all. Default OFF. Sites that don't need this never see the surface area.

**Why not a "safe DSL"?** Considered. Rejected because (a) every safe DSL eventually grows into a janky Turing-incomplete PHP-lite; (b) the people allowed to install plugins on a WP site already have full PHP execution; this is no worse. The capability gate + opt-in toggle is the meaningful boundary.

**Distribution.** Snippets can be exported (Utilities tab) as a JSON bundle including their full source. This is how a site author ships a tested set of snippets to other sites running the plugin.

### 4.9 Conditional Sync Engine (`JEDB_Condition_Evaluator`)

> **Locked decision (D-14):** Bridge configs may carry an optional
> `condition` (declarative DSL — see §3.5) and/or `condition_snippet`
> (snippet slug). The conditional engine evaluates these before each
> bridge application. This is what allows multiple bridge configs to
> share a source target while staying 1:1 per bridge (per D-1).
> Documented in `LESSONS-LEARNED.md` L-013.

**Goal.** Allow the system as a whole to express patterns like:

- "Sync this Mosaic CCT row to Woo product **only if** the product is in the `Mosaics` category."
- "Push to the `with_instructions` variation **only when** `cct.has_instructions_pdf == 'yes'`."
- "Skip this bridge entirely **if** the product is out of stock."

…while keeping every individual bridge a clean, predictable 1:1 source-target sync.

**Trigger taxonomy** (the *when* axis — per L-018 / D-18):

A bridge config declares its trigger separately from its condition. Triggers
are *what event causes the engine to evaluate the bridge*; conditions are
*whether to apply once it's evaluating*. Both axes get DSL + snippet escape
hatches in v1, but for v1 only a small set of trigger types is supported.

| Trigger slug | Fires on | Direction | Notes |
|---|---|---|---|
| `cct_save` | `jet-engine/custom-content-types/created-item/{slug}` AND `updated-item/{slug}` at priority 20+ (per L-018 to let JE finish auto-create first) | push | Default for CCT-source bridges. |
| `cct_field_changed` | Same hooks, but only if a declared watch-list of CCT fields actually changed value | push | More efficient than `cct_save` when only specific field changes should propagate. Diff calculation via the `$prev_item` arg from the updated-item hook. |
| `post_save` | `save_post_{post_type}`, priority 20+ | pull | For non-Woo CPTs and JE-managed CPTs alike. |
| `wc_product_save` | `woocommerce_new_product` + `woocommerce_update_product`, priority 20+ | pull | WC-specific so we get the typed product object. |
| `term_assigned` | `set_object_terms` for a watched taxonomy/term combination | push or pull | E.g., "when a product gets the `Mosaics` category, ensure a Mosaic CCT row exists." Implements §4.10's auto-link pattern. |
| `manual` | Editor clicks a "Sync now" button on the bridge admin tab | push or pull | Bypasses both trigger and condition; always evaluates. |
| `bulk` | Editor runs a bulk-sync utility from the Utilities tab | push or pull | Iterates every record matching a query, applies bridge with both trigger and condition checks bypassed. |
| `cron` *(deferred — Phase 7+)* | A scheduled task | push or pull | For periodic reconciliation on sites with high churn. |

Each bridge config carries a `trigger` field (string) and an optional
`trigger_args` object (e.g., `{ "watched_fields": ["price", "stock_quantity"] }`
for `cct_field_changed`, or `{ "taxonomy": "product_cat", "terms": ["mosaics"] }`
for `term_assigned`). The conditional engine reads these to wire the right
hooks at boot.

**Engine flow** (called by the flattener / transaction processor / bulk-sync runner):

1. **Trigger fires.** A configured trigger (per the taxonomy above) hits its hook.
2. **Candidate collection.** `JEDB_Flattener::collect_bridges_for_trigger($trigger_slug, $context)` returns every bridge config whose `trigger` matches AND whose `enabled = 1`. Direction (push vs pull) is implicit from the trigger.
3. **Sort by priority.** Lower `priority` (default 100) runs first. Ties resolved by `id ASC` for determinism.
4. **For each candidate, evaluate condition** via `JEDB_Condition_Evaluator::evaluate( $condition_dsl, $condition_snippet, $context )`:
   - If both empty → return `true`.
   - If `condition_snippet` is set → run via `JEDB_Snippet_Runner` with the same `$context` shape as a transformer; cast result to bool.
   - Else parse the DSL string with the v1 grammar (§3.5) and evaluate against the source + target field values bound to the path scopes.
5. **Apply each matching bridge** through the existing flatten / transaction flow:
   - Pre-write `Sync_Guard` lock check (per §5).
   - Run `push_transform` or `pull_transform` chain per direction.
   - Write to target via the appropriate adapter.
6. **Log every candidate** to `wp_jedb_sync_log` regardless of outcome:

| `status` value | Meaning |
|---|---|
| `success` | Bridge ran end-to-end, target write returned true. |
| `partial` | Some mapped fields wrote, some failed (per-field results in `context_json`). |
| `errored` | Bridge applied but target write threw. |
| `skipped_condition` | Condition returned false; bridge intentionally skipped. |
| `skipped_error` | Condition snippet threw; bridge skipped, snippet stack trace in `context_json`. |
| `skipped_locked` | Bridge target is locked via `_jedb_bridge_locked` or sync_guard locked. |
| `noop` | All mapped fields had identical source/target values; nothing written. |

**`$context` for condition evaluation:**

```
[
    'source_target'  => JEDB_Data_Target  // the source adapter
    'source_id'      => mixed             // source record's ID
    'source_data'    => array             // source record fields (lazy-loaded)
    'target_target'  => JEDB_Data_Target  // the target adapter
    'target_id'      => mixed             // target record's ID
    'target_data'    => array             // target record fields (lazy-loaded)
    'direction'      => 'push' | 'pull'
    'bridge_type'    => string            // bridge type slug
    'trigger'        => 'auto' | 'manual' | 'bulk' | 'cron'
]
```

The DSL path resolver maps `{cct.foo}` to `$source_data['foo']` when source is a CCT, `{product.foo}` to `$target_data['foo']` when target is a Woo product, etc. Lazy loading: `source_data` and `target_data` are only fetched on first access via the adapter's `get($id)`, so a condition that doesn't reference them stays cheap.

**Failure-mode policy (per Q4 / D-2):** condition snippet throws → skip THIS bridge only, log `status='skipped_error'`, continue evaluating the next candidate. A throw never blocks other bridges from running.

**Cycle detection:** if bridge A fires bridge B which fires bridge A again, `Sync_Guard` (§5) detects via origin tagging and refuses the recursive write. Logged as `skipped_locked`.

**Editor UX (Phase 4 deliverable):** the Bridges admin tab "Add condition" UI shows a syntax cheatsheet drawer + sample value picker that pulls live values from one of the user's actual records to validate the DSL parses + returns the expected bool. Same drawer offers "Switch to snippet mode" which generates a stub snippet pre-populated with the current DSL converted to PHP for the user to extend.

### 4.10 Reverse-direction sync (post → CCT)

> **Locked decision (D-17):** JE Relations auto-creates the related post on
> CCT save in **one direction only**. The reverse — creating a CCT row when
> a post is created independently in WC or directly via WP — is entirely
> our responsibility. Documented in `LESSONS-LEARNED.md` L-016 + L-020.

**The asymmetry, made explicit:**

| Direction | What JE does | What our plugin does |
|---|---|---|
| **CCT → post** (push direction) | Auto-creates the post via `wp_insert_post()`, populates title (and optionally description) from configured CCT fields, attaches the relation row. | Hooks at priority 20+ on `created-item/{slug}` AND `updated-item/{slug}`. Pushes ADDITIONAL mapped fields onto the JE-created post. Calls `WC_Product->save()` to refresh the WC lookup table (covers L-017). |
| **post → CCT** (pull direction) | Nothing — JE does not auto-create CCT rows when a post is saved. | The entire pipeline. Hook the post-save action, find or create the related CCT row, push mapped fields onto it. |

**Reverse-direction engine flow:**

1. **Hook fires.** One of the post-save triggers from §4.9's taxonomy:
   - `wc_product_save` → fires on `woocommerce_new_product` + `woocommerce_update_product` for products.
   - `post_save` → fires on `save_post_{type}` for non-Woo CPTs.
   - `term_assigned` → fires when a watched taxonomy term is set on a post.
2. **Candidate collection.** Find every bridge config whose direction is `pull` (or `bidirectional`) and whose `source_target` matches the post's type — e.g., `posts::product`.
3. **Condition evaluation.** Per §4.9. Conditions are how the editor scopes "only sync if product has category Mosaics" or "only if SKU starts with M-".
4. **Find or create the CCT row.**
   - Query the relation table for an existing connection involving this post.
   - If found: load that CCT row, that's our target.
   - If not found AND the bridge config has `auto_create_target_when_unlinked: true`:
     - Call `JEDB_Target_CCT::create([...minimal seed fields...])` to create a new CCT row. Seed fields are typically the bridge config's mapped fields, populated from the just-saved post's values via the `pull_transform` chain.
     - Call `JEDB_Relation_Attacher::attach($relation_id, $parent_id, $child_id)` to link them.
   - If not found AND `auto_create_target_when_unlinked: false`: log as `status='skipped_no_target'` and stop. The editor must manually link via the Phase 4 Bridge meta box.
5. **Push mapped fields onto the CCT row.** Run the `pull_transform` chain per mapping, then call `JEDB_Target_CCT::update($id, $fields)`.
6. **Log to `wp_jedb_sync_log`** as `status='success'` (or per §4.9's status taxonomy).

**Why this isn't symmetric with §4.9's CCT → post path:**

- JE owns post creation in the forward direction (one-time `wp_insert_post`); we cannot intercept it without breaking JE.
- JE does NOT own CCT creation in the reverse direction; we have full control over when and how.
- The forward direction always finds the post already created by JE; the reverse direction must decide whether to auto-create or not.
- Therefore: forward bridges have NO `auto_create_target_when_unlinked` flag (always implicit-yes via JE); reverse bridges DO have it (default false; editor opts in per bridge type).

**Cycle prevention** is critical here, but the forward and reverse
sides are *not* symmetric. Per **L-022** (verified empirically on
2026-05-06):

- **Forward push → reverse pull cascade CAN form.** Forward writes
  go through `WC_Product->save()`, which fires
  `woocommerce_update_product`. The reverse pull listener wakes up
  during that nested save. Our cross-direction
  `JEDB_Sync_Guard::is_locked('push', ...)` check is what prevents
  the cycle — bails with `cascade: push_in_flight`.
- **Reverse pull → forward push cascade CANNOT form** (currently).
  Reverse writes go through `$cct->db->update()` directly. JetEngine
  does NOT fire `updated-item/{slug}` from its low-level DB methods;
  the hook fires only from JE's higher-level save flows (REST, admin
  form, picker payload). The forward push listener never wakes up
  on reverse-pull writes, so the cycle architecturally cannot form.
  The cross-direction `is_locked('pull', ...)` check at the top of
  forward push is dead code under current JE behavior — kept as
  belt-and-suspenders insurance for future JE versions, third-party
  hook re-firers, and Phase 4's manual-sync-via-REST paths.

In both cases, `JEDB_Sync_Guard` (§5) is the active protection where
needed and benign overhead where the recursion never forms. Origin
tagging via `$context['origin']` (`'reverse_pull'`, `'forward_push'`,
`'manual'`, `'wc_product_save'`, `'cct_save_updated'`, etc.) provides
the audit trail in `wp_jedb_sync_log` regardless of which protection
fired.

**Editor UX (Phase 4 / 4.5 deliverable):**

- The Bridges admin tab gains a "Direction" toggle per bridge config:
  - `Push only` (CCT → post; uses §4.9 forward path)
  - `Pull only` (post → CCT; uses §4.10 reverse path)
  - `Bidirectional` (registers both)
- For `Pull only` and `Bidirectional`, an additional "Auto-create CCT row when unlinked post is saved" checkbox surfaces (default off — opt-in to keep the surface area conservative).
- Per-direction trigger pickers (per §4.9) so the editor can configure the WHEN axis for each direction independently.

### 4.11 Taxonomy assignment per bridge — the categorization layer

> **Locked decisions (D-20, D-21, D-22, D-23, D-24):** Bridges carry a
> dedicated `taxonomies[]` array (D-20). Taxonomy assignment is
> **push-only** in v1; pull never modifies terms (D-21). Per-rule
> defaults: `merge_strategy = 'append'`, `create_if_missing = false`,
> `match_by = 'slug'` (D-22). `apply_terms_inverse` provides explicit
> term removal (D-23). UI queries live taxonomies/terms via AJAX,
> never asks editors to type slugs (D-24). See `LESSONS-LEARNED.md`
> L-023 for the architectural rationale.

#### Why taxonomies need their own concern

WordPress terms live in `wp_terms` / `wp_term_taxonomy` /
`wp_term_relationships` — a different storage system from post-meta
or post columns. They have multi-value semantics, hierarchical
relationships, merge rules (replace vs append), and creation costs
(unknown term names → silently dropped or `wp_insert_term`'d?).
Trying to express "categorize this product under X" through the
existing `mappings[]` array would require either:

- forcing the CCT to store an array of WP term IDs (awful UX), or
- overloading transformer args with taxonomy semantics every time.

Instead, taxonomies get a parallel array on the flatten config.
Cleaner schema, room to grow.

#### The three categorization layers — separate, complementary

| Layer | Solves | Lives in | Direction | Status |
|---|---|---|---|---|
| **`term_lookup` transformer** | Per-row dynamic categorization driven by CCT field values (e.g., `cct.theme = "Cityscape"` → product term `Cityscape` in `product_cat`). Composes with the existing `push_transform` / `pull_transform` chains. | `mappings[]` entry's transform chain | both | 0.5.2 |
| **`taxonomies` array** | Static-per-bridge multi-taxonomy assignment. "Every Mosaic-bridged product is in `product_cat = mosaics`, regardless of any CCT field value." | New `taxonomies[]` on flatten config | push only (per D-21) | 0.5.2 |
| **`term_assigned` trigger** | Term changes as wakeup events for the reverse engine. "When a product gets the `mosaics` category, fire the Mosaic bridge's pull, even if no other field changed." | `trigger.type = 'term_assigned'` (D-18 trigger taxonomy) | pull (event source) | Phase 4.5 |

These compose: a Mosaic bridge can use `taxonomies[]` to assert
`product_cat = mosaics` on every push, plus a `term_lookup`
transformer in mappings to route `cct.theme` into `product_tag`,
plus a future `term_assigned` trigger to wake up reverse pull when
an editor adds the `mosaics` category to a previously-unbridged
product.

#### Schema — `taxonomies[]` per flatten config

```json
"taxonomies": [
  {
    "taxonomy":            "product_cat",
    "apply_terms":         ["mosaics"],
    "apply_terms_inverse": [],
    "match_by":            "slug",
    "merge_strategy":      "append",
    "create_if_missing":   false,
    "snippet":             null
  },
  {
    "taxonomy":            "product_tag",
    "apply_terms":         ["custom-mosaic", "made-to-order"],
    "apply_terms_inverse": ["available-set"],
    "match_by":            "slug",
    "merge_strategy":      "append",
    "create_if_missing":   false,
    "snippet":             null
  }
]
```

Per-rule fields:

| Field | Default | Purpose |
|---|---|---|
| `taxonomy` | (required) | WP taxonomy slug — must be registered for the bridge's target post type. |
| `apply_terms` | `[]` | Terms to assign on push, interpreted via `match_by`. Resolved to term IDs via `get_term_by()`. |
| `apply_terms_inverse` | `[]` | Terms to ENSURE NOT present after push. Engine calls `wp_remove_object_terms()`. |
| `match_by` | `'slug'` | `'name' \| 'slug' \| 'id'` — how to interpret `apply_terms` strings. |
| `merge_strategy` | `'append'` | `'append'` (preserves editor-added terms) or `'replace'` (bridge owns the slot). |
| `create_if_missing` | `false` | When ON, unknown `apply_terms` values trigger `wp_insert_term()`. OFF by default to keep editors in control of taxonomy hygiene. |
| `snippet` | `null` | Phase 5b forward-compat. When the snippet runtime ships, a snippet slug here overrides `apply_terms` with the snippet's return value. |

#### Engine integration order on push

> **Refined in v0.5.3 per L-024:** Mappings run BEFORE taxonomies, not
> after. The earlier "taxonomies-then-mappings" ordering had a real
> bug — a mapping that targeted a taxonomy field (e.g.
> `theme_idea → category_ids` via `term_lookup`) would call
> `WC_Product::set_category_ids()` which REPLACES the entire taxonomy
> slot, clobbering anything the applier had just added.

`JEDB_Flattener::apply_bridge()` runs the following sequence after
the cross-direction cascade check and condition evaluation pass:

1. **Resolve target post** (existing flow, L-021 self-heal).
2. **Run field mappings.** For each enabled mapping, run the
   `push_transform` chain on the source value, diff against the
   target, and append to the write payload.
3. **If the payload is non-empty, write through the target adapter**
   (e.g. `WC_Product->save()` for HPOS-safe lookup-table refresh,
   per L-017). This step can call `set_category_ids()` /
   `set_tag_ids()` etc., which REPLACE the entire taxonomy slot for
   that taxonomy on the post.
4. **For each `taxonomies[]` entry**, in declared order, AFTER the
   mapping write:
   - Resolve `apply_terms` to term IDs via `get_term_by(match_by, taxonomy, value)`.
   - If `create_if_missing` AND a value didn't resolve, `wp_insert_term()`
     and use the new term ID. Log the new-term creation in sync_log
     `context_json` so editors can audit.
   - Resolve `apply_terms_inverse` similarly (no creation — only existing
     terms can be removed, by definition).
   - Call `wp_set_object_terms($post_id, $resolved_apply_ids, $taxonomy, $append=true|false)`
     — `$append=true` for `'append'`, `$append=false` for `'replace'`.
   - Call `wp_remove_object_terms($post_id, $resolved_inverse_ids, $taxonomy)`.
5. **Sync log** records mapping outcome + `taxonomies_applied` count and
   per-rule outcome (added / removed / created) in `context_json`.

This ordering means **taxonomy rules always get the final word**:
- `merge_strategy='append'` rules pile on top of whatever the mapping
  wrote (so a mapping writing `category_ids=[42]` followed by a rule
  appending `mosaics` yields a product with both).
- `merge_strategy='replace'` rules become canonical (the mapping wrote
  whatever it wrote, then the replace rule overwrites the slot to be
  exactly the rule's `apply_terms`).
- A mapping with `term_lookup` that resolves to `[]` (e.g. nothing
  matched) clears the slot, but the subsequent taxonomy rule re-applies
  the editor's intent. **No more silent category disappearances.**

#### Engine integration on pull
**Skipped entirely.** Per D-21, the reverse pull engine doesn't read
or write taxonomies. The `taxonomies[]` array on bridge configs
exists only for the forward direction. Editors who want pull-side
behavior gated on terms use the conditional DSL
(`{product.product_cat} contains "mosaics"`) or wait for Phase 4.5's
`term_assigned` trigger.

#### `term_lookup` transformer

A new built-in transformer for the `mappings[]` chain. Args:

| Arg | Default | Purpose |
|---|---|---|
| `taxonomy` | (required) | WP taxonomy to look up against. |
| `match_by` | `'name'` | Push: how to interpret incoming string. Pull: which property to output. |
| `output` | `'ids_array'` (push) / `'first_name'` (pull) | What shape to produce. |
| `create_if_missing` | `false` | Push: insert term if not found. Same behavior as the `taxonomies[]` rule's flag. |

Push direction: takes a string or array of strings, resolves to term
IDs, returns an array of IDs. Pull direction: takes an array of term
IDs (e.g., from `category_ids`), resolves to names/slugs, returns
the first or all values per `output`. Composes naturally with
`mapping.target_field = 'category_ids'` to write Woo's typed setter.

#### Admin UI (Flatten tab — 0.5.2 deliverable)

When the form's `target_target` is `posts::*`, a new collapsible
"Taxonomies" section appears between "Self-heal options" and
"Field mappings". Inside:

- "Add taxonomy rule" button.
- Per rule: a row with the taxonomy dropdown (populated from
  `get_object_taxonomies($post_type)`), the apply-terms multi-select
  (populated from `get_terms($taxonomy)`), the inverse-terms
  multi-select (same source), the merge strategy radio pair, the
  `create_if_missing` checkbox, the `match_by` radio (slug/name/id),
  and a placeholder "snippet (Phase 5b)" disabled dropdown.
- "Remove rule" button per row.

The dropdowns refresh dynamically when `target_target` changes via
the new `wp_ajax_jedb_flatten_get_post_type_taxonomies` endpoint,
which returns `{taxonomies: [{slug, label, hierarchical, terms:[{id,name,slug}...]}]}`
for the selected post type.

#### Forward-compat with Phase 5b snippets

The `snippet` slot per rule is nullable today and meaningful in
Phase 5b. When the snippet runtime ships:

- Setting `snippet = "compute_categories_from_theme"` overrides
  `apply_terms` with the snippet's return value. The snippet
  receives `$value = null, $args = $rule, $context = $bridge_context`
  and returns an array of strings (interpreted via `match_by`).
- `apply_terms` becomes a fallback when the snippet returns null
  or an empty array.
- No schema migration — existing `snippet: null` rules continue to
  use `apply_terms` directly.

This is what enables the "compute taxonomies from CCT field
values" use case the user wanted (e.g., Mosaic theme → matching
product_tag), as a more powerful alternative to the
`term_lookup` transformer when the logic is complex.

### 4.12 Field Presets — portable target-coverage knowledge

> **Locked decision (D-26 — added 2026-05-10):** field presets are
> first-class portable artifacts, separate from flatten configs. Each
> preset is target-scoped (single adapter slug like `posts::product`),
> contains a curated list of fields with mandatory flags + freeform
> group labels + hint text, and is exportable / importable as JSON
> across sites. They overlay onto flatten configs at edit time —
> they never replace the flatten config and they never modify the
> engine's behavior.

#### What a field preset IS

A field preset answers the question: *"For target adapter X, what does a complete bridge look like?"*

That knowledge is **operational** (discovered by hands-on use of the target system — e.g. "WooCommerce won't show a product on the storefront archive without `name`, `regular_price`, `category_ids`, AND `_visibility` set"), not **structural** (which is the bridge config's job — "for THIS specific Mosaic CCT row, source field X maps to target field Y").

PAC VDM hardcoded this knowledge in PHP (per role: Makes, Models, Vehicle Configs, Service Guides). We pull it OUT of code and INTO a curated, exportable artifact so it can move between sites.

#### Schema

Stored in a new site option `jedb_field_presets`. Flat indexed array of preset entries:

```
field_preset {
    slug:        string         // unique, sanitize_key()
    label:       string
    description: string         // why this preset exists, when to apply it
    target:      string         // single adapter slug — "posts::product", "cct::mosaics_data", etc.
    fields: [
        {
            name:      string   // field key matching the adapter's schema
            label:     string   // human-readable display name
            mandatory: bool     // adds to required-field check when applied; false = recommended/informational
            group:     string   // freeform — editor types whatever — used for visual grouping
            hint:      string   // tooltip / hover-help text
        },
        ...
    ],
    notes:        string        // free-form notes
    created_at:   string        // mysql datetime
    updated_at:   string
}
```

Per **D-26**: target is a single adapter slug, not multi-target. A preset that covers both a CCT and a CPT is two separate presets that share a `description` link.

Per **D-26**: `group` is freeform string. No predefined enum. Editors might use `"Pricing"` / `"Identity"` / `"Visibility"` / `"SEO"` / `"Variations"` — or whatever taxonomy makes sense to them. The Flatten admin tab and the meta box render groups as collapsible sections.

#### How presets compose with flatten configs

Three application modes (all available from the Flatten admin tab editor's "Mandatory coverage" panel):

1. **Display mode (read-only overlay)** — pick a preset → the panel adds the preset's fields to the existing list (PAC VDM-style green/red badges per field, "5 of 7 covered" summary), but does NOT modify the flatten config. Useful for inspection and gap analysis without changing anything.
2. **Apply mode (overlay persists)** — pick a preset → its mandatory fields are added to the flatten config's `required_overrides.add` array. Persists. The "Mandatory coverage" check now treats the preset's fields as required for THIS bridge. Editors can later remove any specific field via `required_overrides.remove`.
3. **Scaffold mode (auto-stub mappings)** — single button: *"Scaffold missing mappings as passthrough"*. For every preset field that has no mapping in this bridge yet, create a stub mapping with `source_field: ''` (empty — editor fills it in), `target_field: <preset.field.name>`, push/pull = passthrough, `surface_on_target: <copy from preset's natively-rendered detection>`, `group: <preset.field.group>`. Editors fill in the source side; the rest is pre-baked.

Modes 2 and 3 are **not mutually exclusive** — you can apply a preset as overlay AND click scaffold. The scaffold button is even smarter when a preset is already applied (it knows what fields the bridge is committing to cover).

#### Adapter-declared required fields vs preset-declared required fields

Both compose into the "Mandatory coverage" panel. The order is:

1. `JEDB_Data_Target::get_required_fields()` (adapter-declared, D-15) — engineering minimum (e.g. Woo product needs `name` + `status` to even save).
2. Applied presets' mandatory fields (D-26) — operational minimum for THIS site's use case (e.g. "in BBHQ, every product must also have `category_ids` + `regular_price` to be storefront-visible").
3. `required_overrides.add` from the bridge config (D-15) — bridge-specific custom additions.
4. `required_overrides.remove` from the bridge config (D-15) — escape hatch for "I know what I'm doing, stop warning me about this field."

The panel renders the union, sorted by group, with provenance per field ("required by adapter" / "required by preset: BBHQ Storefront-Visible" / "added by this bridge" / "explicitly removed by this bridge"). Provenance makes it obvious WHY a field is mandatory, which is critical for editor onboarding.

#### Export / import format

JSON export of all presets (mirroring the bridge type export shape from the retired alpha.1):

```
{
    "plugin":      "je-data-bridge-cc",
    "version":     "0.6.0",
    "exported_at": "2026-05-10 12:34:56",
    "count":       3,
    "presets": [
        { ... woocommerce-storefront-visible ... },
        { ... woocommerce-seo ... },
        { ... cct-mosaic-essentials ... }
    ]
}
```

Import accepts: the wrapper above, a bare `presets[]` array, or a single preset entry (auto-promoted to a one-element list). Imports merge by slug; an explicit `replace_all` checkbox forces destructive replacement.

This is the "I discovered the magic Woo fields list while building one site, package it, drop it on the next site" workflow that makes the plugin actually portable across BBHQ-style stores.

#### Phase 4 deliverable boundary

- **Day 1**: schema + read path. Adapter-declared required fields stay where they are; the option is created on activation; no UI yet.
- **Day 4**: Field Presets admin tab + JSON export/import + the engine integration on the Mandatory coverage panel ("Apply preset" + "Scaffold missing").

(Days 2 and 3 are the meta box and the redirect shim, which don't depend on field presets.)

---

## 5. Sync Loop Prevention (`JEDB_Sync_Guard`)

The single biggest source of bugs in any bidirectional bridge is recursive saves. Our guard provides:

- **Static per-request flag**: `JEDB_Sync_Guard::lock( "$direction:$source_id:$target_id" )` returns false if already locked — caller bails.
- **Transient lock for cross-request cases** (Ajax, REST, CLI): same key persists 30s.
- **Origin tagging**: every write carries a `$context['origin']` so debug log can read "CCT update → Woo PUSH (origin=admin) → Woo update (skipped, origin=jedb_push)".
- **Public hooks**: `jedb/sync/before`, `jedb/sync/after`, `jedb/sync/skipped`.

PAC VDM today does this with ad-hoc statics scattered across `class-data-flattener.php`. We centralize it.

---

## 6. File-Level Migration Map

The following maps every meaningful source file to its destination, the action required, and the reason. **"Adapt"** = lift logic, rename namespaces and constants, fix any single-site assumptions. **"Merge"** = combine with another file from a different source plugin. **"Extract"** = pull a sub-piece out of a larger file.

### 6.1 From Jet Engine Relation Injector

| Source path | → Destination | Action | Notes |
|---|---|---|---|
| `jet-engine-relation-injector.php` | `je-data-bridge-cc.php` | Adapt | Use as bootstrap skeleton (constants, activation hook, dependency check). Replace all `JET_INJECTOR_*` constants with `JEDB_*`. |
| `uninstall.php` | `uninstall.php` | Adapt | Drop both `wp_jedb_relation_configs` AND `wp_jedb_flatten_configs` AND `wp_jedb_sync_log` and remove `jedb_*` options. |
| `includes/class-plugin.php` | `includes/class-plugin.php` | Merge | Merge with PAC VDM's `class-plugin.php`. New singleton wires every subsystem listed in §3.3. |
| `includes/class-config-db.php` | `includes/class-config-db.php` | Adapt | Generalize: this class manages **all** custom tables (relations, flatten, sync log). Single `install()`, single `uninstall()`. Add `flatten_configs` and `sync_log` schemas. |
| `includes/class-config-manager.php` | `includes/class-config-manager.php` | Adapt | Add typed methods: `get_relation_configs()`, `get_flatten_configs()`, `get_woo_bridges()`. Becomes the single façade. |
| `includes/class-discovery.php` | `includes/class-discovery.php` | Merge | Combine with PAC VDM's discovery. Add `discover_woo_product_meta_keys()`, `discover_woo_taxonomies()`. Cache to transient with 5-min TTL + manual flush button in Utilities. |
| `includes/class-runtime-loader.php` | `includes/relations/class-runtime-loader.php` | Adapt | Move into `relations/` subfolder. Rename JS handle. Same job. |
| `includes/class-transaction-processor.php` | `includes/relations/class-transaction-processor.php` + `includes/relations/class-relation-attacher.php` | Extract | Pull the "attach a relation between two records" logic out into `class-relation-attacher.php` so it's reusable from the Woo bridge meta box ("create new product from CCT" needs the same operation). |
| `includes/class-utilities.php` | `includes/class-utilities.php` | Adapt | Add export/import for flatten configs and Woo bridges. Add "rebuild Woo lookup table for bridged products" button. |
| `includes/class-admin-page.php` | `includes/admin/class-admin-shell.php` + `includes/admin/class-tab-relations.php` | Extract | Split the admin page into a tab router (shell) and one tab class per feature. |
| `includes/helpers/debug.php` | `includes/helpers/debug.php` | Merge | Merge with PAC VDM's debug helpers and JFB-WC's `jfbwqa_write_log()` pattern. Single function `jedb_log( $msg, $level = 'info' )` writing to `wp-content/uploads/jedb-debug.log` (NOT inside the plugin dir — survives plugin updates). |
| `templates/admin/settings-page.php` | `templates/admin/shell.php` | Adapt | Becomes the outer tab shell. |
| `templates/admin/cct-config-card.php` | `templates/admin/relation-config-card.php` | Adapt | Rename only. Same purpose. |
| `templates/admin/utilities-tab.php` | `templates/admin/tab-utilities.php` | Adapt | Add new buttons mentioned above. |
| `templates/admin/debug-tab.php` | `templates/admin/tab-debug.php` | Adapt | Add log viewer (tail last 500 lines, JS auto-refresh option). |
| `assets/js/injector.js` *(implied — confirm path)* | `assets/js/relation-injector.js` | Adapt | Localize via `wp_localize_script` to receive the new ajax action names. |
| `.cursor/rules/*` (8 docs) | `docs/architecture/*` | Adapt | These are gold. Re-purpose the architecture, glossary, troubleshooting, and JE API reference into the new plugin's developer docs. Update any `Jet_Injector_*` references. |

### 6.2 From PAC Vehicle Data Manager

| Source path | → Destination | Action | Notes |
|---|---|---|---|
| `pac-vehicle-data-manager.php` | (merged into `je-data-bridge-cc.php`) | Merge | Pull the activation/deactivation hooks and constant defs into the unified bootstrap. |
| `uninstall.php` | (merged into `uninstall.php`) | Merge | |
| `includes/class-plugin.php` | (merged into `includes/class-plugin.php`) | Merge | |
| `includes/class-data-flattener.php` | `includes/flatten/class-flattener.php` | Adapt | This is the most-touched file in the merge. Changes: (a) replace direct CCT writes with `JEDB_Data_Target` calls, (b) replace ad-hoc loop guards with `JEDB_Sync_Guard`, (c) factor every value transformation through the new transformer pipeline. |
| `includes/class-field-locker.php` | `includes/flatten/class-field-locker.php` | Adapt | Generalize selectors to also match Woo product edit fields (the meta box outputs them with predictable names). |
| `includes/class-year-expander.php` | `includes/flatten/transformers/class-year-expander.php` | Adapt | Implements the new `JEDB_Transformer` interface. Domain-specific but useful as a reference transformer. |
| `includes/class-bulk-sync.php` | `includes/flatten/class-bulk-sync.php` | Adapt | Add `target` parameter so bulk sync can run against any registered target (CCT, CPT, Woo product). |
| `includes/class-discovery.php` | (merged into `includes/class-discovery.php`) | Merge | Combine its CCT-field discovery with RI's relation-graph discovery. |
| `includes/class-cct-builder.php` | `includes/builders/class-cct-builder.php` | Adapt | Generalize: builder builds CCTs **and** can register a Woo product type if needed (e.g., `mosaic_instructions_pdf` as a custom Woo product subtype). |
| `includes/class-config-name-generator.php` | `includes/flatten/transformers/class-name-builder.php` | Adapt | Generalize from "vehicle config name" to "any record's display name from a template string". |
| `includes/class-config-manager.php` | (merged into `includes/class-config-manager.php`) | Merge | |
| `includes/class-admin-page.php` | (split across `includes/admin/class-tab-flatten.php`, `class-tab-setup.php`, `class-tab-debug.php`) | Extract | |
| `includes/helpers/debug.php` | (merged into `includes/helpers/debug.php`) | Merge | |
| `templates/admin/setup-tab.php` | `templates/admin/tab-setup.php` | Adapt | One-click setup gets a "Setup Brick Builder HQ" button (preset that creates the available_sets and mosaics CCTs + relations). Other sites can drop in their own preset JSON. |
| `templates/admin/debug-tab.php` | (merged into `templates/admin/tab-debug.php`) | Merge | |
| `templates/admin/settings-page.php` | (merged into `templates/admin/shell.php`) | Merge | |
| `assets/js/field-locker.js` | `assets/js/field-locker.js` | Adapt | Add Woo edit-screen selector. |
| `assets/css/field-locker.css` | `assets/css/field-locker.css` | Adapt | Same. |
| `BUILD-PLAN.md` | (referenced in this doc) | Read-only | Already reviewed; informs the merger but is not migrated. |
| `README.md` | (informs new `readme.txt`) | Adapt | |

### 6.3 From JFB WC Quotes Advanced

JFB-WC is **not** migrated wholesale — it stays as its own quotes plugin. We extract patterns only.

| Pattern source (line in `jfb-wc-quotes-advanced.php`) | → Destination | What we lift |
|---|---|---|
| `jfbwqa_settings_init()` (~line 1026) | `includes/admin/class-settings.php` | Settings API skeleton (sections + fields + sanitize callback). |
| `jfbwqa_get_options()` / sanitize (~lines 39, 1144) | `includes/admin/class-settings.php` | Defaults pattern + boolean coercion. |
| `jfbwqa_read_mapping()` / `jfbwqa_write_mapping()` (~lines 82-125) | `includes/class-utilities.php` | JSON file-based config with upload + write-permission checks. |
| `jfbwqa_write_log()` + `debug/debug.log` (~line 127) | `includes/helpers/debug.php` | File-based debug log with toggle. We move the file out of the plugin dir into uploads. |
| `jetengine_keys` textarea (~line 1040) | `includes/admin/class-settings.php` | Per-site meta-key whitelist textarea, generalized to per-target. |
| `jfbwqa_render_field_*` callbacks (~line 1122) | `includes/admin/class-settings.php` | Reusable render callbacks for text/textarea/checkbox/wp_editor. |
| Custom WC order action button + modal (~lines 506, 1684) | `includes/admin/class-woo-product-meta-box.php` | Pattern for adding admin buttons + a footer modal on Woo edit screens. |

---

## 7. Phased Implementation Roadmap

Each phase ends with the plugin being **installable, activatable, and useful** — no big-bang merges. The user (you) reviews and tests at each phase boundary before the next phase starts.

> **Live status as of 2026-05-11:** Phases 0, 1, 2, 2.5, 3, 3.5, and 3.6 are complete and **end-to-end verified on Brick Builder HQ staging** through thirteen releases (v0.1.0 through v0.6.0-alpha.5 on `main`). **Phase 4 Day 2 + alpha.5 hardening just shipped** — meta box surface mechanics decoupled from sync mechanics (three modes: pure-surface / native-overlay / sync+surface), data-loss bug fixed (alpha.4's pull-lock hack replaced by explicit forward-push call, working around L-022 within bounded scope), "you ticked surface but nothing rendered" diagnostic added. Phase 4 Day 2 originally shipped in v0.6.0-alpha.4** — the Bridge meta box on Woo product / variation edit screens (per D-27). It reads `wp_jedb_flatten_configs` directly, walks the bridges whose `target_target` matches the current post type, runs `JEDB_Reverse_Flattener::resolve_source_id()` in read-only mode per candidate, and renders one panel per resolved bridge (linked or unlinked). The linked panel surfaces editable inputs for mappings flagged `surface_on_target=true` (grouped by freeform `group` label), shows the last 3 sync_log rows pill-coded by status, exposes per-product Lock + Direction override (writes the alpha.3 post meta the engine guards consume), and provides Sync now + Unlink action buttons. The unlinked panel includes a live CCT search (debounced 250ms, reuses the Phase 2 `wp_ajax_jedb_relation_search_items` AJAX endpoint) + Link button calling `JEDB_Relation_Attacher::attach()`. Save handler acquires a Sync_Guard `pull` lock around source-side writes so the reverse pull engine on subsequent `woocommerce_update_product` bails at its same-direction acquire (prevents stale-post-value pull-back). **Phase 4 Day 1 alpha.3** is in place underneath: bridge type template layer retired (D-25 / L-026), flatten config schema extended (`meta_box` block + per-mapping `surface_*` + `group` + `cct_single_redirect`), per-product engine guards (`_jedb_bridge_locked` / `_jedb_bridge_direction_override`), Field Presets manager skeleton. **Engine paths are byte-identical to v0.5.3** outside the alpha.3 skip-only guards. Existing 0.5.x flatten configs work unchanged. Phase 4 Day 3 (CCT-single → linked-post redirect shim per §4.6) and Day 4 (Field Presets admin tab + Mandatory coverage integration per §4.12) are next. All architectural decisions locked (D-1 → D-27, with D-5 superseded by D-25); all known platform caveats documented (L-001 → L-026). Roadmap below is the planned-from-day-zero plan; "actual" status of each phase is tracked in `README.md`'s roadmap table and per-version detail in `CHANGELOG.md`.

### Phase 0 — Skeleton (½ day) ✅
- Create `je-data-bridge-cc.php` bootstrap with constants and dependency check (JE ≥ 3.3.1, WC active warning).
- Create `class-plugin.php` singleton with empty subsystem registration.
- Create `class-config-db.php` with `install()` that creates the four custom tables.
- Activation/uninstall hooks wired.
- Empty admin shell with one "Hello" tab.
- ✅ **Exit criterion**: plugin activates clean on a fresh JE+Woo site, creates tables, shows admin menu.

### Phase 1 — Discovery + Targets (1 day) ✅
- Port and merge `class-discovery.php` from RI + PAC VDM.
- Build `interface-data-target.php` and the four target classes (`Target_CCT`, `Target_CPT`, `Target_Woo_Product`, `Target_Woo_Variation`).
- `Target_Registry` with `register()` / `get()` / `all()`.
- Targets tab shows a read-only list of all discovered targets.
- ✅ **Exit criterion**: Targets tab on a real site lists every CCT, every public CPT, every product, and every variation without errors.

### Phase 2 — Relation Injector port (1 day) ✅
- Port RI's runtime loader, transaction processor, admin tab, JS.
- Test on Brick Builder HQ's `available_sets_data` CCT.
- ✅ **Exit criterion**: editor can create a new CCT item AND attach a relation in one save, identical to RI's behavior today.

### Phase 2.5 — Bidirectional architecture lock + picker visibility fix (½ day) ✅
- Documented the JE one-directional auto-create + post-side reverse responsibility (L-016, L-020, D-17).
- Added trigger taxonomy as a separate axis from condition (D-18, §4.9).
- Locked Phase 3+ hook priority contract (D-19, L-018).
- Added §4.10 — reverse-direction sync (post → CCT) full engine flow.
- Switched `Target_Woo_Product::list_records()` from `wc_get_products()` to `WP_Query` so the picker sees JE-auto-created products (L-017).
- ✅ **Exit criterion**: picker on CCT edit screen finds every product on the site regardless of whether it was created via WC API or JE auto-create; bidirectional architecture is documented before any flatten code is written.

### Phase 3 — Flattener forward direction (2 days) ✅
- Port PAC VDM's flattener engine, generalized.
- New `JEDB_Flatten_Config_Manager` (CRUD on `wp_jedb_flatten_configs`).
- New `JEDB_Flattener` runs on JE CCT save hooks at **priority ≥ 20** per D-19 (constant `JEDB_FLATTEN_HOOK_PRIORITY` = 20), so JE's own auto-create has finished before we read the related post.
- Push-direction transformer pipeline + condition evaluator + sync log writes (per §4.9).
- Flatten admin tab with the field-mapping UI (D-12 explicit-only, D-15 mandatory coverage panel, D-16 adapter-owned `is_natively_rendered`).
- Field-existence checker pattern adapted from PAC VDM's role-mapping screen.
- Wire `Sync_Guard` so PUSH writes never recurse (§5).
- After every PUSH, call `WC_Product->save()` (already routed via `JEDB_Target_Woo_Product::update`) to refresh the WC lookup table — this is the side effect that makes JE-auto-created products visible to other Woo queries (L-017 long-term self-heal).
- Snippet-mode for `condition_snippet` is **stubbed** for v1 — bridges that set it log `skipped_error` until Phase 5b ships the snippet runtime. Declarative DSL conditions work fully.
- ✅ **Exit criterion**: when an editor saves a CCT row, mapped fields PUSH onto the related JE-auto-created post correctly. Flatten config UI lets editors add mappings, set per-direction transformer chains, and see mandatory coverage. Sync log records every push.

### Phase 3.5 — Reverse-direction flatten (1 day) ✅
- Wired `woocommerce_update_product` (+ variations) and `save_post_{type}` triggers per D-18.
- `JEDB_Reverse_Flattener` runs at `JEDB_FLATTEN_HOOK_PRIORITY` (= 20) on those hooks.
- `auto_create_target_when_unlinked` flag (default off per D-17) — when a post saves and has no matching JE relation row AND no `cct_single_post_id` link AND the flag is on, creates a fresh CCT row via `JEDB_Target_CCT::create([])` (empty seed; the user's `pull_transform` chain populates it via the normal apply pipeline). Auto-attaches the relation row when `link_via.auto_attach_relation` is also on.
- Pull-direction transformer chain + condition evaluator (same `JEDB_Condition_Evaluator` as forward, with `direction = 'pull'` in the context).
- **Cross-direction cascade prevention.** Both engines call `JEDB_Sync_Guard::is_locked()` against the OPPOSITE direction at the top of their `apply_bridge()`. Forward push checks the pull lock; reverse pull checks the push lock. When either is held for the same `(source, target)` pair, the engine bails with `skipped_locked` and a `cascade: pull_in_flight` / `push_in_flight` marker in the sync log context. The cross-check is what makes bidirectional bridges safe by default.
- Bidirectional support: `direction = bidirectional` registers BOTH engines for the same bridge.
- L-021 self-heal mirrored on the reverse side: when no relation row is found, fallback to a CCT row whose `cct_single_post_id` equals the saved post id, with the same auto-attach behavior.
- ✅ **Exit criterion**: editing a product directly in WC (not through any CCT flow) propagates mapped fields onto the linked CCT row via the per-mapping `pull_transform` chain. With `auto_create_target_when_unlinked` on, an unlinked post-save creates a fresh CCT row + relation. No infinite sync loops, ever.

### Phase 3.6 — Categorization layer (~1 day, ships as v0.5.2) ✅

The taxonomy/categorization architecture per D-20 → D-24 / L-023 / §4.11.

- New built-in transformer: `JEDB_Transformer_Term_Lookup`
  (`includes/flatten/transformers/class-transformer-term-lookup.php`) —
  resolves names/slugs/IDs against a taxonomy in both directions.
  Composes with the existing `mappings[]` push/pull transformer chains.
  Per-row dynamic categorization use case.
- New `taxonomies[]` array on `wp_jedb_flatten_configs.config_json`,
  with the schema documented in §4.11. Each rule = one taxonomy's
  push behavior. Multiple rules per bridge supported natively.
- New `JEDB_Taxonomy_Applier` class (`includes/flatten/class-taxonomy-applier.php`)
  — runs the rules against a target post during forward push, between
  the condition check and the field mappings. Calls
  `wp_set_object_terms()` and `wp_remove_object_terms()` per rule.
- Forward `JEDB_Flattener::apply_bridge()` invokes the applier; reverse
  flattener skips it entirely (per D-21 push-only semantics).
- New AJAX endpoint `wp_ajax_jedb_flatten_get_post_type_taxonomies` —
  returns `{taxonomies: [{slug, label, hierarchical, terms: [...]}]}`
  for a given post type. Used by the Flatten admin UI.
- Flatten admin tab gets a new collapsible "Taxonomies" section (visible
  only when `target_target` is `posts::*`). Per-rule UI: taxonomy
  dropdown + apply-terms multi-select + inverse-terms multi-select +
  merge_strategy radio + create_if_missing checkbox + match_by radio +
  disabled-snippet placeholder dropdown (Phase 5b).
- Sync log `context_json` records `taxonomies_applied` count + per-rule
  outcome (added / removed / created-via-create_if_missing).
- ✅ **Exit criterion**: a Mosaic CCT bridge with `taxonomies[]`
  containing `{taxonomy: 'product_cat', apply_terms: ['mosaics']}`
  results in every linked product landing in the `mosaics` category on
  push. Pull never modifies categories. The Flatten admin UI shows the
  available taxonomies + terms via the new AJAX endpoint with no typed
  slugs.

### Phase 4 — Woo bridge meta box + flatten config extensions + field presets (4 days) — *the new code* ▶ **NEXT UP**

> **Note (2026-05-10):** Phase 4 was originally scoped around a separate
> "Bridges admin tab" + bridge type template layer. That scope was retired
> per **D-25 / D-27** after staging testing surfaced L-025 (silent data loss
> from schema mismatch between bridge type and flatten config) and a
> deeper architectural review (L-026 — *premature template-layer
> abstraction*). The flatten config IS the bridge identity now. Phase 4
> reshape below; v0.6.0-alpha.1 + alpha.2 work to be reverted in the
> alpha.3 release that opens this phase.

#### Day 1 — alpha.3 revert + flatten config schema extensions + engine guards

- Revert all v0.6.0-alpha.1 / alpha.2 work: delete `JEDB_Bridge_Types_Manager`, `JEDB_Tab_Bridges`, `templates/admin/tab-bridges.php`, `assets/js/bridges-admin.js`, the bridges-tab CSS block, the `JEDB_OPTION_BRIDGE_TYPES` constant + activation default. ~1,500 lines.
- Extend `JEDB_Flatten_Config_Manager::default_config_json()`: add `meta_box: { enabled, title, position, groups[] }` block + top-level `cct_single_redirect: bool`. Deep-merge defaults handle existing rows transparently.
- Extend `JEDB_Flatten_Config_Manager::default_mapping()`: add `surface_on_source: bool`, `surface_on_target: bool`, `group: string` per mapping (D-26 — freeform group label).
- Add per-product engine guards in `JEDB_Flattener::apply_bridge()` and `JEDB_Reverse_Flattener::apply_bridge()`: respect `_jedb_bridge_locked` (skip with `skipped_locked, reason: per_product_lock`) and `_jedb_bridge_direction_override` (constrain effective direction; skip with `skipped_direction_override` if disallowed).
- Extend the Flatten admin tab (`templates/admin/tab-flatten.php` + `class-tab-flatten.php`):
  - New collapsible **"Meta box settings"** section (title, position, hint when no mappings have `surface_on_target`).
  - Per-mapping `surface_on_source` + `surface_on_target` checkboxes + `group` text input added to the mappings table.
  - New `cct_single_redirect` checkbox (next to existing `auto_create_target_when_unlinked`).
- Add a new field preset option scaffolding: `JEDB_OPTION_FIELD_PRESETS` constant, activation default `array()`, `JEDB_Field_Presets_Manager` skeleton with read-only API. (Full UI ships Day 4.)
- ✅ **Exit criterion**: Flatten admin tab editor lets editors flag mapped fields for surfacing on the Woo product edit screen. Engine guards work for `_jedb_bridge_locked` and `_jedb_bridge_direction_override` post meta. Existing 0.5.x flatten configs work unchanged (back-compat verified on staging).

#### Day 2 — Bridge meta box on Woo edit screens (reads flatten config directly)

- Implement `JEDB_Woo_Product_Meta_Box` for `product` and `product_variation` edit screens.
- **Resolution path**: walk `wp_jedb_flatten_configs` → for each row whose `target_target` matches this post's type, run the existing link-resolution logic to determine if THIS specific product has a linked source. Render one panel per resolved bridge.
- **Linked panel** renders: linked CCT row label + edit link, bridge config quick reference + deep link to Flatten admin tab, last-3 sync log rows, surfaced field inputs grouped by `group` label, per-product Lock checkbox (`_jedb_bridge_locked`), Direction override radio (`_jedb_bridge_direction_override`), Sync now button, Unlink button.
- **Unlinked panel** renders: list of available flatten configs whose `target_target` matches → editor picks one → Ajax CCT picker for source rows of that bridge → Link button (calls `JEDB_Relation_Attacher::attach()` for `je_relation` bridges, OR sets `cct_single_post_id` for Has-Single-Page bridges).
- Save handler for surfaced fields: writes through the appropriate `Target_*::update()` (typed-setter API, HPOS-safe).
- ✅ **Exit criterion**: editing a Mosaic product on the Woo admin shows the linked CCT row, surfaces `pdf_link` / `theme_idea` / etc. fields per the flatten config, lets the editor sync now / lock / unlink without leaving the screen. Reverse-pull engine writes surfaced fields back to CCT.

#### Day 3 — CCT-single → linked-post redirect shim

- Implement the §4.6 shim. New file `includes/class-cct-single-redirect.php`. Hook `template_redirect`.
- Reads opt-in from each flatten config's `cct_single_redirect` flag (per Day 1 extension). Direction guard (only redirects for bridges where direction includes push). Admin escape hatch (`?jedb_no_redirect=1` with `manage_options`).
- ✅ **Exit criterion**: visiting `/cct/mosaics_data/2/` 301-redirects to the linked product permalink for opt-in bridges. Logged-in admin with `?jedb_no_redirect=1` sees the CCT single (or 404).

#### Day 4 — Field Presets admin tab + Mandatory coverage integration

- Implement `JEDB_Field_Presets_Manager` writes (CRUD on `jedb_field_presets` option). Validation enforces unique slug, target adapter slug must be valid, fields must have `name`.
- New admin tab "Field Presets" (priority 35, after Flatten). UI for add/edit/delete + JSON export (browser download) + JSON import (paste with optional replace-all).
- Flatten admin tab "Mandatory coverage" panel gains:
  - **Apply preset** dropdown — picks a preset whose `target` matches the bridge's `target_target`; on apply, preset's mandatory fields are added to `required_overrides.add`.
  - **Display-only overlay** mode — pick a preset to see its fields layered into the panel WITHOUT modifying the bridge config (PAC VDM-style green/red badges + "X of Y covered" summary, freeform group labels per preset).
  - **Scaffold missing mappings** button — for any preset field not yet in the bridge's mappings, stub a passthrough mapping (`source_field` empty, `target_field` set, push/pull = passthrough, `group` carried over from preset). Editor fills in source side.
- Provenance display on the Mandatory coverage panel: each required field labeled with its origin (adapter / preset / bridge override).
- ✅ **Exit criterion**: editor can curate a "WooCommerce — Storefront Visible" preset with `name` / `regular_price` / `category_ids` / `_visibility` as mandatory + freeform groups, export it as JSON, drop into a fresh staging site, import, apply to a bridge, see PAC VDM-style coverage badges, click Scaffold to auto-stub missing mappings.

### Phase 4b — Variation bridging (1.5 days) — *new code, builds on Phase 4*
- Implement `Target_Woo_Variation` writes (read path was done in Phase 1).
- Add `variations[]` + `show_when` mini-DSL parser **directly on the flatten config** (per D-25 — no separate template layer). The variation reconciliation engine reads the `variations[]` array from `wp_jedb_flatten_configs.config_json`.
- Variation reconciliation engine on PUSH (create / update / soft-delete based on `show_when`).
- Variation Scope radio + variation picker added to the meta box.
- Wire downloadable-files handling (the "Has Instructions PDF" path).
- ✅ **Exit criterion**: editing a Mosaic CCT row's `has_instructions_pdf` toggle creates/removes the "Includes Instructions PDF" variation on the bridged Woo product, with the correct price and downloadable file attached.

### Phase 5 — Settings, debug log, utilities (1 day)
- Settings API setup using JFB-WC pattern (incl. the "Enable Custom PHP Snippets" toggle).
- File-based debug log + viewer.
- Export/import config as JSON (now includes bridge types).
- "Bulk re-sync all bridges" button.
- ✅ **Exit criterion**: an entire site config can be exported, the plugin uninstalled, reinstalled, and config re-imported with everything working.

### Phase 5b — Custom Code Snippets (1.5 days)
- Implement `JEDB_Snippet_Installer`, `Snippet_Manager`, `Snippet_Loader`, `Snippet_Runner`.
- Build the Snippets admin tab with WP-bundled CodeMirror editor + Test runner.
- Wire `Snippet_Transformer` into the transformer registry so snippets show up in the Flatten config dropdown.
- Add export/import of snippets in the Utilities tab.
- ✅ **Exit criterion**: an admin can write, syntax-check, save, test, and apply a snippet to flatten a field, and a deliberately-broken snippet does NOT break a CCT save (it returns the unmodified value and logs the error).

### Phase 6 — Setup tab + presets (½ day)
- Programmatic CCT/relation builder (PAC VDM's `class-cct-builder.php`).
- "Brick Builder HQ" preset JSON shipped in plugin (CCTs + relations + flatten configs + field presets + a starter snippet for LEGO trademark stripping). Two preset types per D-26: **bridge presets** (pre-cooked flatten configs to insert into `wp_jedb_flatten_configs`) and **field presets** (curated target-coverage knowledge for `jedb_field_presets`). Both export/import as JSON.
- ✅ **Exit criterion**: on a fresh Woo+JE site, clicking "Apply Brick Builder HQ preset" creates `available_sets_data` CCT, `mosaics_data` CCT, the relations, the flatten configs (including the variable-product Mosaic bridge with its variations), the field presets ("WooCommerce — Storefront Visible" etc.), and the snippet in under 10 seconds.

### Phase 7 — Hardening (1 day)
- Capability gating (`manage_jedb` + `jedb_can_edit_snippets`).
- Nonces on every admin form (especially the snippet editor).
- REST endpoint hardening (auth + caps).
- Translation strings + `.pot` regeneration.
- Codex / `readme.txt` written.
- ✅ **Exit criterion**: passes a basic security pass (no public unauthenticated writes, no SQL injection paths, all admin AJAX nonced, snippet editor blocked for non-admins).

**Total estimated build**: ~11.5 working days end-to-end (Phase 4 grew by 1 day for the alpha.3 reshape + Field Presets), of which ~6.5 are net-new code (Phases 4, 4b, 5b) and the rest are port-and-generalize.

### 7.1 Staging verification log — Phases 0 through 3.6

Each phase boundary that ships runtime behavior gets verified end-to-end on Brick Builder HQ staging (`bbhq.legworklabs.com`) before being marked complete. Every entry below cites the actual artifact (sync_log row id, debug log line, SQL inspection) that proved the assertion.

#### Phase 2 — Relation Injector port (v0.3.0)

| Assertion | Evidence |
|---|---|
| Picker UI on CCT edit screen attaches relations on save | `wp_jet_rel_8` row created with `parent=1, child=395` after first picker save (per L-014 verified contract). |
| L-001/L-007 JE field-storage resolution works on JE 3.8.5 | Discovery diagnostic (Debug tab) reports `meta_fields` source = `wp_jet_post_types` row decoded successfully. |

#### Phase 2.5 — Bidirectional architecture lock + picker visibility fix (v0.3.1)

| Assertion | Evidence |
|---|---|
| L-017 self-heal: picker sees products created by JE auto-create (not just via WC API) | Picker dropdown lists products created by raw `wp_insert_post` after switching `Target_Woo_Product::list_records()` to `WP_Query` direct. |

#### Phase 3 — Forward-direction flatten engine (v0.4.0)

| Assertion | Evidence |
|---|---|
| CCT save triggers forward push at hook priority 20 (D-19) | `[Flattener] hooks registered ... priority: 20` lines in `jedb-debug.log`. |
| Mapped fields written via HPOS-safe adapter | `wp_jedb_sync_log` row id 6 (2026-05-03 02:50:13): `direction=push, status=success, fields=["regular_price"]`. |
| Sync_Guard prevents same-direction recursion | Same-source-id consecutive saves never produce duplicate write rows. |
| Diff engine NOOPs when source/target already match | `wp_jedb_sync_log` rows 2, 3, 5 — `status=noop, message="every mapped value already matched target"`. |

#### Phase 3 hotfix — L-021 self-heal (v0.4.1)

| Assertion | Evidence |
|---|---|
| Engine falls back to `cct_single_post_id` when relation row missing | `[Flattener] auto-attached JE relation via cct_single_post_id fallback (L-021 self-heal)` line at 2026-05-05 23:28:42. |
| Fallback writes the missing relation row idempotently | `[Attacher] attach: connection inserted {"relation_id":"9", parent_id:2, child_id:403}` at the same timestamp. New row in `wp_jet_rel_9` confirmed via SQL. |
| Fast path activates after first self-heal | Subsequent sync at 2026-05-05 23:31:53 logs `resolution: "relation_row"` (not `fallback_single_page`) and `auto_attached: false`. |
| Condition DSL gates correctly in both directions of the boolean | Mosaic 2 with `display_price_publicly = "no"` → `skipped_condition` (rows at 23:28:42 and 23:32:25); switched to `"yes"` → `success` (row at 23:31:53). |

#### Phase 3.5 — Reverse-direction flatten + bidirectional + auto-create (v0.5.0)

| Assertion | Evidence |
|---|---|
| Reverse pull engine fires on `woocommerce_update_product` | `wp_jedb_sync_log` rows at 2026-05-06 00:04:11 and 00:15:27: `direction=pull, origin=wc_product_save, status=success`. |
| Reverse pull writes mapped fields back via `Target_CCT::update` | Row 27 (00:15:27): `fields=["mosaic_name"]` resolved via `relation_row`, with `auto_attached=false`. CCT mosaic_name updated as expected. |
| Auto-create CCT row works for unlinked posts (D-17 opt-in) | `[Reverse_Flattener] auto-created CCT row (D-17 opt-in)` at 2026-05-06 00:20:12 — new CCT _ID 5, post 404, with auto-attach via Attacher inserting relation row id 3. |
| Reverse-direction diff engine works | Rows 28 and 30 (immediate follow-up saves) — `status=noop`, message: `every mapped value already matched source`. |

#### Phase 3.5 hotfix — L-022 cascade asymmetry doc + noop log papercut (v0.5.1)

| Assertion | Evidence |
|---|---|
| L-022 architectural finding: JE's `$db->update()` doesn't fire `updated-item/{slug}` hook | Multiple post→CCT pull writes throughout 0.5.0 testing produced **zero** `cascade=push_in_flight` markers in `wp_jedb_sync_log`. The recursion path doesn't exist on the JE side (vs. WC side which fires its hooks). Documented as a "no fix needed, document the asymmetry" finding. |
| Forward push noop path still includes resolution / auto_attached metadata | Sync_Log rows from 0.5.1 onward show `resolution` and `auto_attached` keys on `noop` rows (papercut from 0.4.1 closed). |

#### Phase 3.6 — Categorization layer (v0.5.2)

| Assertion | Evidence |
|---|---|
| Static-per-bridge taxonomy assignment (D-20) | `wp_jedb_sync_log` row 33 (2026-05-06 03:30:34) — `direction=push, status=success, message="fields all noop, but 1 taxonomy term(s) changed"`, `taxonomies.terms_added=1`, `added_ids=[17]` (mosaics). |
| Multi-taxonomy schema works at runtime | Bridge config `taxonomies[]` array with one rule decoded and applied successfully on every push. |
| Live taxonomy / terms AJAX endpoint (D-24) | UI dropdowns populated correctly when target selected; user successfully picked `product_cat` from a live-fetched list. |
| Reverse-pull skips taxonomies entirely (D-21) | Pull rows in `wp_jedb_sync_log` carry no `taxonomies` summary key (only push rows do). |

#### Phase 3.6 hotfix — L-024 ordering bug + term_lookup zero-resolve warning (v0.5.3)

| Assertion | Evidence |
|---|---|
| Mappings now run BEFORE taxonomies | `wp_jedb_sync_log` row at 2026-05-06 03:59:29 — `status=success, message="wrote 1 field(s) + 1 taxonomy term change(s)"`. The new combined message format only appears in the v0.5.3 status-determination refactor. |
| Both `category_ids` (mapping) and `mosaics` (rule) end up applied | Same row: `fields=["category_ids"], terms_added=1 (added_ids=[17])`. Editor confirmed both terms visible on product 403 in WC admin. |
| `apply_terms_inverse` removes terms even when mapping wrote them | Editor set `theme_idea = "available-sets"` (which `term_lookup` resolves and `set_category_ids()` writes) AND `apply_terms_inverse: ["available-sets"]`. After push, `available-sets` was NOT attached — inverse rule won. |
| `term_lookup` zero-resolve warning fires | `jedb-debug.log` at 2026-05-06 04:01:39 — `[Transformer:term_lookup] resolved 0 term IDs from non-empty input ... unmatched_values:["nonexistant-cat"]` with the configured `match_by` and a hint surface. |
| Cascade prevention still works post-refactor | Pull row at 03:59:29 — `status=skipped_locked, cascade=push_in_flight`, paired with the success push at the same timestamp. Bidirectional bridge correctly prevents ping-pong. |

#### What's NOT yet verified (Phase 4 will exercise)

- Bridge meta box on the Woo product edit screen (Phase 4).
- Variation reconciliation engine + `show_when` mini-DSL (Phase 4b).
- `term_assigned` trigger as a wakeup signal for reverse pull (Phase 4.5).
- Custom Code Snippets runtime + `condition_snippet` evaluation (Phase 5b — currently logs `skipped_error`).
- Setup-tab presets (Phase 6).
- Capability hardening + REST auth + i18n (Phase 7).

---

## 8. Decisions Log (locked)

The following decisions are locked in for v1. Future enhancements go in §10 / changelog.

| # | Topic | Decision | Implementation impact |
|---|---|---|---|
| D-1 | **Bridge cardinality** | **1:1.** One Woo product (or one variation) ↔ one CCT row. | `_jedb_bridge_cct_id` is a single integer. UI shows a single-select Ajax picker, not multi-select. Validation: saving a product with a CCT ID already linked elsewhere triggers an admin warning and a "swap link" prompt. |
| D-2 | **Source of truth on conflict** | **CCT-canonical by default.** When both sides change between syncs, CCT wins. Per-bridge override (`Product is canonical`) available in the meta box. **Phase 7+** may add per-field "last write wins" via timestamps. | Default value of `_jedb_bridge_direction` is `cct_canonical`. PUSH events from CCT always overwrite Woo fields. PULL events from Woo only fire when `_jedb_bridge_direction = product_canonical` OR when the meta box "Pull from Woo now" button is clicked. |
| D-3 | **Variations** | **In scope.** Both parent products and individual variations can be bridge targets. Variation reconciliation engine ships in Phase 4b. | Adds `Target_Woo_Variation`, the `variations[]` block in bridge type JSON, the `show_when` mini-DSL evaluator, and the variation reconciliation logic. See §4.7. |
| D-4 | **"Has Instructions PDF" implementation** | **Woo product variation** of the parent Mosaic product, NOT a separate product. Reconciled automatically by the bridge type's `variations[]` block. | The Brick Builder HQ preset (Phase 6) ships with a `mosaic` bridge type whose `variations[]` includes both `build_only` and `with_instructions`. The CCT field `has_instructions_pdf` controls whether the second variation exists. |
| D-5 | ~~**Bridge type enum storage**~~ *(superseded by D-25 on 2026-05-10)* | ~~Plugin Settings JSON (`jedb_bridge_types` option), NOT JetEngine Glossary.~~ **Bridge types as a separate template layer were retired** — the flatten config IS the bridge identity. The `jedb_bridge_types` option is dropped in v0.6.0-alpha.3. JetEngine Glossaries are still used for *content* enums like `story_type` per `site-structure.md`. | Decision kept in this log for audit traceability. See L-026 for the post-mortem and D-25 for the replacement decision. |
| D-6 | **Plugin author / license** | **Legwork Media · GPL v2 or later.** Matches existing JFB-WC convention. | Plugin header in `je-data-bridge-cc.php`. `readme.txt` License field. Copyright notice in `LICENSE` file. |
| D-7 | **JFB WC Quotes Advanced consolidation** | **Stays separate.** Quoting is its own beast and out of scope. We lift *patterns* (Settings API, JSON config files, debug log) but never the quoting logic. Both plugins can co-exist on the same site. | No changes to JFB-WC repo. New plugin's debug-log helper detects if JFB-WC is active and (optionally) tails its log too in the Debug tab. |
| D-8 | **Repo strategy** | **New standalone GitHub repo** (`legworkmedia/je-data-bridge-cc`). The three reference plugins remain in their own repos and are explicitly ignored in this repo's `.gitignore`. | New repo gets its own README, LICENSE, CHANGELOG. The path `Refrence but block from git/` is already gitignored at the project root; new plugin sits in its own repo entirely separate from the Brick Builder HQ workspace. |
| D-9 | **Custom Code Snippets** *(net-new from §10 follow-up)* | **In scope, opt-in.** Per-config PHP snippets stored in `uploads/jedb-snippets/`, edited via WP-bundled CodeMirror, gated by `manage_options` + the `jedb_can_edit_snippets` filter + a global Settings toggle (default OFF). Errors caught and logged; never break a save. | Adds Phase 5b (1.5 days). New `includes/snippets/*` subsystem, new `wp_jedb_snippets` table, new Snippets admin tab, new `Snippet_Transformer`. See §4.8. |
| D-10 | **Bridge link mechanism** | **JE Relations are the primary link.** `cct_single_post_id` is a special-case alternative for CCTs with "Has Single Page" enabled. **NO `_jedb_bridge_cct_id`** post-meta on the product — the link lives where JE owns it, eliminating dual-source-of-truth. | Bridge type config declares `link_via: 'je_relation' \| 'cct_single_post_id'`. Product-side meta is reduced to `_jedb_bridge_type`, `_jedb_bridge_locked`, optional `_jedb_bridge_direction`. Native JE Smart Filters / Listings / Query Builder traverse the link automatically. See §4.5 + L-013/L-015. |
| D-11 | **Bidirectional transformer chains** | Each field mapping carries **two** transformer chains (`push_transform` and `pull_transform`), not one. They are not assumed to be inverses. Built-in transformers ship as paired inverses where well-defined; custom snippets are direction-agnostic and the bridge config picks which snippet runs in which chain. | Bridge config schema gains separate `push_transform[]` and `pull_transform[]` arrays per mapping. UI renders two transformer pickers per mapping side by side. See §4.8 + L-010. |
| D-12 | **Field mapping policy** | **Explicit only.** No silent auto-matching by name. The Bridges admin UI renders a two-column picker; the destination column is adapter-aware (Woo Product picker shows only Woo product fields, with variation-attribute fields appearing only when product type = `variable`). | No "auto-map by name" code path. Bridge config requires every mapping to be explicit. Adapter-aware picker is a Phase 4 deliverable. See L-009 / Q1. |
| D-13 | **JE Relation creation policy** | **Definition manual; rows can self-heal.** The plugin does NOT create the JE Relation *definition* (the `wp_jet_post_types` row that registers `parent_object` ↔ `child_object` and the table schema); editors author those in JetEngine → Relations. The plugin DOES write rows to `{prefix}jet_rel_{id}` to self-heal a missing link when `cct_single_post_id` resolves to a valid linked post (per L-021). Phase 6 setup-tab presets MAY also auto-create relation definitions programmatically. | Bridges admin UI = "pick from existing definitions". Self-heal flags `link_via.fallback_to_single_page` + `link_via.auto_attach_relation` (both default true) handle the missing-row case automatically. Setup-tab presets ship a relations array that gets created programmatically. See §4.5 + L-021. |
| D-14 | **Conditional bridges (M:1 / 1:N routing)** | **In scope.** Multiple bridge configs may share a `source_target`. Each carries an optional `condition` (declarative DSL) and/or `condition_snippet`. The conditional engine evaluates conditions before each bridge applies; matching bridges run in declared `priority` order. Each individual bridge is still 1:1 (D-1) — conditions just keep the matching set disjoint. | New §3.5 (DSL grammar) + §4.9 (conditional engine). New `JEDB_Condition_Evaluator` class. Adds `condition`, `condition_snippet`, `priority`, `dsl_version` columns/keys to `wp_jedb_flatten_configs`. Same snippet runtime as D-9. Sync log gains `skipped_condition` and `skipped_error` statuses. See L-013. |
| D-15 | **Mandatory-field policy** | **Adapter-owned, bridge-overridable.** Each `JEDB_Data_Target` declares `get_required_fields()`. Bridge type config can extend or relax via `required_overrides: { add: [], remove: [] }`. Bridges admin UI shows a "Mandatory coverage" panel that warns (does not block) when required target fields aren't covered by the mapping, with three remediations: add a mapping, attach a synthesizing snippet, mark intentionally-unmapped. | Adds `get_required_fields()` to the `JEDB_Data_Target` interface in Phase 4. Adapter defaults: Target_CCT `[]`, Target_CPT `['post_title']`, Target_Woo_Product `['name', 'status']`, Target_Woo_Variation `['parent_id', 'attributes']`. See L-011. |
| D-16 | **Field-render-hint ownership** | **Adapter-owned via `is_natively_rendered($field_name)`.** Bridge meta box on the WC product edit screen renders inputs ONLY for fields where the adapter returns false (i.e. fields with no native Woo input). Native fields stay in their native boxes; the sync engine talks to them via the `WC_Product` setter API. | Adds `is_natively_rendered()` to the `JEDB_Data_Target` interface in Phase 4. Target_Woo_Product returns true for typed-setter fields + standard taxonomies; false for arbitrary meta keys. Conflict detection (two configs both wanting to render the same custom field) surfaces as a warning in the Bridges admin tab BEFORE save. See L-012. |
| D-17 | **JE auto-create is one-directional; reverse direction is ours** *(refined per L-021)* | **Forward direction (CCT → post):** when "Has Single Page" is enabled on the CCT, JetEngine auto-creates the linked post (CPT or Woo product) and stores its ID in `cct_single_post_id`. JE does **NOT** automatically write a row to `{prefix}jet_rel_{id}` — that's the picker's responsibility. Our plugin self-heals this gap (per L-021). **Reverse direction (post → CCT):** not handled by JE at all. Our plugin owns it entirely. | Forward bridges have no `auto_create` flag (JE handles post creation; we self-heal the relation row via D-13). Reverse bridges DO have an opt-in `auto_create_target_when_unlinked` flag (default false). Two distinct hook sets, two distinct engine paths (§4.9 forward + §4.10 reverse). See L-016, L-020, L-021. |
| D-18 | **Trigger taxonomy** is a separate axis from condition | A bridge config carries both a `trigger` (what event causes evaluation) and an optional `condition` (whether to apply once evaluating). v1 trigger types: `cct_save`, `cct_field_changed`, `post_save`, `wc_product_save`, `term_assigned`, `manual`, `bulk`. Cron-based triggers deferred to Phase 7+. | Each bridge config has `trigger` + optional `trigger_args` (e.g., watched fields for `cct_field_changed`). Engine wires the right hooks at boot based on the registered triggers across all enabled bridges. See §4.9 + L-013/L-018. |
| D-19 | **Hook priority contract** for Phase 3+ engines | Phase 2's relation-injector transaction processor registers at priority 10 on `created-item/{slug}` (correct for picker-driven attaches). Phase 3+ flatten engine MUST register at priority **>= 20** so JE's auto-create logic finishes first. Documented as a hard contract. | Compile-time check: any code that hooks `created-item/{slug}` or `updated-item/{slug}` for sync purposes (not picker purposes) uses priority 20. New `JEDB_FLATTEN_HOOK_PRIORITY` constant (= 20) referenced by every flatten-engine `add_action` call. See L-018. |
| D-20 | **Taxonomy concerns get their own array** | Bridge configs carry a dedicated `taxonomies[]` array, **separate from `mappings[]`**, modelling each taxonomy's behavior independently. Every entry has `taxonomy`, `apply_terms`, `apply_terms_inverse`, `match_by`, `merge_strategy`, `create_if_missing`, and a forward-compat `snippet` slot. Multiple taxonomies per bridge are first-class — covers the user's "product_cat for routing + product_tag for filters + pa_* for variations + custom taxonomy for templates" pattern. | New §4.11. New `JEDB_Taxonomy_Applier` class. New AJAX endpoint listing taxonomies + terms for a post type. Flatten admin tab gets a "Taxonomies" collapsible section. See L-023. |
| D-21 | **Taxonomy assignment is push-only in v1** | The reverse pull engine NEVER modifies taxonomies on the source post. Pull only writes mapped CCT fields via `mappings[]`. Editors can hand-tag products with extra categories and pull won't strip them on next sync. Symmetric pull-side taxonomy logic requires the snippet runtime (Phase 5b) and isn't shippable cleanly without it. | Reverse flattener skips the `taxonomies[]` array entirely. Forward flattener applies it between condition check and mappings. Documented in L-023 with the failure-mode analysis of the three plausible pull behaviors. |
| D-22 | **Per-rule merge strategy + create_if_missing defaults** | `merge_strategy` defaults to `'append'` (editor-friendly: doesn't strip unrelated terms). `create_if_missing` defaults to `'false'` (the plugin doesn't silently create taxonomy nodes — editor opt-in per rule). `match_by` defaults to `'slug'` (most stable identifier for `apply_terms`). | Default config-shape factory in `JEDB_Flatten_Config_Manager::default_taxonomy_rule()`. Each rule's args are exposed in the Flatten admin tab UI as radios + checkboxes. See L-023. |
| D-23 | **`apply_terms_inverse` for explicit term removal** | Per-rule `apply_terms_inverse` array of terms that must NOT be present after push. Lets editors declare "this bridge's products are never in the `available-set` category" and have the engine call `wp_remove_object_terms()` to enforce. Empty by default. Composes cleanly with `apply_terms` (apply runs first, then inverse-remove). | New field in the taxonomy rule schema. UI exposes it as a parallel multi-select right under `apply_terms`. Engine calls `wp_remove_object_terms()` after `wp_set_object_terms()` per rule. See L-023. |
| D-24 | **Taxonomy UI queries live, doesn't take typed slugs** | The Flatten admin tab's Taxonomies section, when a post-type target is selected, queries `get_object_taxonomies()` for available taxonomies and `get_terms()` for available terms. Editors pick from dropdowns, not type taxonomy slugs by hand. Reduces typo-driven bugs and makes the UI self-documenting. | New AJAX endpoint `jedb_flatten_get_post_type_taxonomies(post_type)` returns `{taxonomies: [{slug, label, terms: [{slug, name, id}, ...]}]}`. JS in `flatten-admin.js` populates two cascading dropdowns when the target post type changes. See L-023. |
| D-25 | **No "bridge type" template layer** *(retires D-5)* | The flatten config IS the bridge identity. There's exactly ONE authoring surface for bridges — the Flatten admin tab — and exactly ONE storage row per bridge: `wp_jedb_flatten_configs`. The Phase 4 meta box is a *view* of an existing flatten config (D-27), not an authoring tool that creates one. A separate `jedb_bridge_types` option as a template-to-clone layer was added in v0.6.0-alpha.1, hotfixed in alpha.2 (L-025), and retired in alpha.3 after a hard architectural review surfaced that templates created surprise (editors expected propagation), doubled the editing surface, and added a schema-mismatch failure mode without delivering value for the only use cases on the table. | v0.6.0-alpha.3 deletes `JEDB_Bridge_Types_Manager`, `JEDB_Tab_Bridges`, the Bridges admin tab template + JS + CSS, and the `JEDB_OPTION_BRIDGE_TYPES` constant + activation default. Per-product overrides (`_jedb_bridge_locked`, `_jedb_bridge_direction_override`) are added as engine guards instead. The bridge meta box (Phase 4 Day 2) reads from `wp_jedb_flatten_configs` directly. Phase 4b variations live as a `variations[]` array directly on the flatten config. See L-026 for the full post-mortem. |
| D-26 | **Field presets are first-class portable artifacts** | Field presets are a separate, target-scoped (single adapter) library of curated "what does a complete bridge to target X look like?" knowledge. Each preset declares fields with `mandatory: bool` + freeform `group: string` + `hint: string`. They overlay onto flatten configs at edit time in three modes (display-only, apply-as-`required_overrides.add`, scaffold-as-passthrough-mappings), they're exportable / importable as JSON across sites, and they do NOT replace adapter-declared `get_required_fields()` — they compose with it. PAC VDM hardcoded this knowledge in PHP per role; we pull it OUT of code and INTO a curated artifact so it can move between sites. | New `jedb_field_presets` site option, new `JEDB_OPTION_FIELD_PRESETS` constant, new `JEDB_Field_Presets_Manager` class, new "Field Presets" admin tab (priority 35). Flatten admin tab "Mandatory coverage" panel gains "Apply preset" / "Display preset" / "Scaffold missing mappings" controls. See §4.12 for the full architecture, Phase 4 Day 4 for the deliverable. |
| D-27 | **Meta box reads flatten config directly** | The Phase 4 Bridge meta box on Woo product / variation edit screens is a *view* of the existing flatten config(s) governing the current product. No "bridge type select." No clone-from-template behavior. Resolution path: walk `wp_jedb_flatten_configs` for rows whose `target_target` matches the post type, run the existing link-resolution logic per row to determine which one(s) govern THIS specific product, render one panel per resolved bridge. Reuses the engine's existing resolver in read-only mode. | New `JEDB_Woo_Product_Meta_Box` class (Phase 4 Day 2). Per-product overrides via post meta: `_jedb_bridge_locked`, `_jedb_bridge_direction_override`, `_jedb_bridge_last_manual_sync_id`. The meta box's *killer feature* is field surfacing — for each mapping where `surface_on_target=true` AND adapter's `is_natively_rendered($field) = false` (D-16), render an editable input on the product edit screen that syncs back to the CCT via the reverse-pull engine. See §4.5 for full controls list. |

---

## 9. Risks & Pitfalls (and how we mitigate them)

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| HPOS / lookup-table desync after a write | Medium | High (broken Woo queries) | Always go through `WC_Product->save()`; never `update_post_meta()` on products. Add a "rebuild lookup table" utility button. |
| Recursive sync loop bringing site down | Medium | Critical | Centralized `Sync_Guard` with both static and transient locks; debug log shows every "skipped" event. |
| JetEngine API change between minor versions | Low–Medium | Medium | Pin to `JETENGINE_MIN_VERSION = 3.3.1`; on every bootstrap, call `jet_engine()->cct->...` defensively with `method_exists`. |
| Editor confusion: "where do I edit this — CCT or product?" | High | Medium | Bridge meta box shows direction prominently; field locker greys out the non-canonical side; admin notice on every edit screen reminds them. |
| Migration of existing CCT items into Woo bridges | Medium | High (silent data loss) | Bulk Sync supports a `--dry-run` mode that emits a report before any writes. Phase 5 deliverable. |
| Plugin too generic, becomes hard to use | Medium | Medium | Setup tab presets ship with a working Brick Builder HQ config out of the box. Other sites get a "Generic JE+Woo" preset. |
| Debug log fills disk | Low | Medium | Log rotates at 5 MB (helper handles it); off by default in production. |
| Custom DB tables conflict with someone else's plugin | Very Low | Low | Tables prefixed `wp_jedb_*` + plugin slug carries `_cc` suffix. |
| Woo plugin updates break the meta box hooks | Low | Low | Use only documented hooks (`add_meta_boxes`, `woocommerce_process_product_meta`). |
| **Variations: stock/attribute drift** between parent and managed variations | Medium | Medium | Variation reconciliation engine writes parent stock as sum of managed variation stock by default; admin can opt out per bridge. Attribute taxonomies (`pa_*`) are auto-created only when declared in the bridge type JSON; otherwise we error loudly rather than silently mis-assigning. |
| **Variations: orphaned third-party variations** | Low | Medium | Reconciliation only touches variations whose `_jedb_variation_slug` meta matches a slug in the bridge type. Unmanaged variations are never auto-deleted. |
| **Custom Snippets: malicious or buggy PHP fatal-erroring a save** | Medium | High if not gated | Triple gate: (1) capability check on edit + on run, (2) global "Enable Custom PHP Snippets" toggle default OFF, (3) `Snippet_Runner` wraps every invocation in try/catch + `set_error_handler` so a fatal returns the original value and logs the trace. |
| **Custom Snippets: snippet file deleted from disk while DB row remains** | Low | Low | `Snippet_Loader::load()` re-checks file existence + hash before include; mismatched/missing → marks snippet `errored`, logs, returns passthrough. Admin notice surfaces orphaned snippets. |
| **Custom Snippets: privilege escalation via writeable uploads dir** | Low | High (theoretical RCE) | `.htaccess` (`deny from all`) + `index.php` written on activation; files chmod 0644; folder existence + perms re-verified on every plugin load via `Snippet_Installer::verify()`. Disabling the feature in Settings stops the loader from including any snippet file. |
| **Field Presets JSON corruption from bad import** | Low | Medium | Import goes through a JSON-schema validator before being persisted; a backup of the previous `jedb_field_presets` value is kept in `jedb_field_presets__previous` for one-click rollback. (Replaces the original "Bridge Types JSON" risk row from before D-25 retired the bridge type layer.) |

---

## 10. What This Document Does NOT Cover (yet)

- Detailed REST endpoint signatures (will be designed during Phase 4).
- Internals of every built-in transformer (specs ship with each phase that introduces them).
- The full grammar of the `show_when` mini-DSL for variations (single-page spec to be written at start of Phase 4b — kept intentionally tiny: comparison ops, boolean AND/OR, field-name interpolation `{field_name}`, no function calls).
- Frontend Elementor template changes for bridged products (lives in `site-structure.md`, not here).
- Per-field "last write wins" timestamp-based conflict resolution (deferred per D-2; Phase 7+ enhancement).
- Multisite considerations (deferred — capability gate works as-is, but snippet sandboxing on multisite super-admin needs explicit thought).
- WP-CLI commands (recommended Phase 7 add-on: `wp jedb sync --bridge=mosaic`, `wp jedb snippet test --slug=...`).

---

## 11. Tooling — JetEngine MCP for live verification

The plugin's parent workspace has the JetEngine MCP wired at
`https://bbhq.legworklabs.com/wp-json/jet-engine/v1/mcp/` (config in
`.cursor/mcp.json` at the workspace root, with Basic Auth credentials).
When connected and exposed in the agent's tool list, the MCP supports
direct introspection of the live JE site without uploading the plugin
or running phpMyAdmin queries by hand.

**Use the MCP for:**

- Cross-checking discovery output against the actual JE state
  (CCTs, CPTs, Relations, Glossaries, field schemas).
- Reading `wp_jet_post_types` rows to verify CCT field config
  (the L-007 storage layer).
- Inspecting JE Relation auto-create configs per relation ID (L-016).
- Sanity-checking new plugin features against actual JE behavior
  before implementing.

**When the MCP is unavailable**, the user can still run direct SQL
via phpMyAdmin (the workflow used to crack L-007 and verify L-014).
The MCP is a convenience that speeds up verification; it doesn't
replace any architectural pattern.

**MCP discoveries should still land in `LESSONS-LEARNED.md`** if
they correct an assumption — it's the persistent memory for the
plugin, not a transient session log.

## 12. Next Action — Phase 4 readiness gate (alpha.3 reshape)

Phases 0 through 3.6 are complete and verified end-to-end on
Brick Builder HQ staging (see `CHANGELOG.md` for per-version
detail and §7.1 for the verification log). v0.6.0-alpha.1 +
alpha.2 added a Bridges admin tab + Bridge Types Manager
template layer that was retired per **D-25 / L-026** after a
deeper architectural review (the flatten config is the bridge
identity; templating it created surprise + a schema-mismatch
failure mode without paying for itself). **Phase 4 has been
reshaped** around three concepts (D-25, D-26, D-27) and runs
in four days from the alpha.3 release that opens the phase.

### What gets reverted in alpha.3 (~1,500 lines deleted)

| File | Why |
|---|---|
| `includes/admin/class-bridge-types-manager.php` | D-25: template layer retired. |
| `includes/admin/class-tab-bridges.php` | D-25: no Bridges admin tab. |
| `templates/admin/tab-bridges.php` | D-25: no Bridges admin tab. |
| `assets/js/bridges-admin.js` | D-25: no Bridges admin tab. |
| Bridges-tab CSS block in `assets/css/admin.css` | D-25: no Bridges admin tab. (The `.jedb-pill-info` rule stays — general-purpose.) |
| `JEDB_OPTION_BRIDGE_TYPES` constant + activation default in `je-data-bridge-cc.php` | D-25: option no longer used. |
| Bootstrap entries in `includes/admin/class-admin-shell.php` (4 lines) | D-25: stop loading + enqueueing Bridges tab assets. |

### What stays in place (consumed by Phase 4)

| Subsystem | Where | Phase 4 use |
|---|---|---|
| Forward + reverse flatten engines | `includes/flatten/class-flattener.php`, `class-reverse-flattener.php` | Meta box's "Sync now" button calls `apply_bridge()` directly. New per-product guards in Day 1 (see "What's NOT being rebuilt" below for the disambiguation from the existing cascade guard). |
| Sync_Guard | `includes/class-sync-guard.php` | Manual syncs from the meta box go through the same lock as auto-syncs (no special path). The existing `is_locked()` / `acquire()` check at the top of `apply_bridge()` is **kept as-is** — it handles in-flight cascade prevention, not editor-set locks. |
| Sync_Log | `includes/class-sync-log.php` | Meta box reads recent rows for "last synced X ago" display. New per-product guards reuse `STATUS_SKIPPED_LOCKED` with a `reason` field in `context_json` to disambiguate from `cascade=pull_in_flight` — **no new status constant needed**. Direction override gets a new constant `STATUS_SKIPPED_DIRECTION_OVERRIDE`. |
| Target_Registry + four adapters | `includes/targets/` | Meta box queries adapters for `is_natively_rendered($field)` (D-16) — decides which surfaced fields to render. |
| `get_required_fields()` (D-15) | All four adapters | Mandatory coverage panel in the Flatten admin tab uses this. Field Presets (D-26) overlay on top — **D-15 is preserved unchanged**. |
| `JEDB_Flatten_Config_Manager` | `includes/flatten/class-flatten-config-manager.php` | Schema extensions Day 1 (`meta_box` block, `cct_single_redirect`, per-mapping `surface_*` + `group`) added via `default_config_json()` + `default_mapping()` extension only. Existing fields untouched. |
| `JEDB_Taxonomy_Applier` (D-20–D-24) | `includes/flatten/class-taxonomy-applier.php` | Untouched. |
| `JEDB_Relation_Attacher::attach()` (D-13) | `includes/relations/class-relation-attacher.php` | Day 2 meta box's "Link this product" button calls this directly — same idempotent path the L-021 self-heal uses. **No reimplementation.** |
| `resolve_target_id()` (D-10 / L-021 self-heal) | `JEDB_Flattener::resolve_target_id()` | Day 2 meta box reuses this method in read-only mode to determine which flatten config governs the current product. **No reimplementation.** |
| L-021 self-heal + L-022 cascade asymmetry | engine paths | No Phase 4 work needed — already correct. |
| AJAX endpoints | `wp_ajax_jedb_flatten_get_target_schema`, `wp_ajax_jedb_flatten_get_post_type_taxonomies`, `wp_ajax_jedb_flatten_validate_condition`, `wp_ajax_jedb_relation_search_items` | Meta box CCT picker reuses the relation-search endpoint. New endpoints (Day 4): `wp_ajax_jedb_flatten_apply_field_preset`, `wp_ajax_jedb_flatten_scaffold_missing_mappings`. |

### What's NOT being rebuilt (audit done 2026-05-10)

Before alpha.3 work begins, this section is the explicit "we already verified X exists; we're calling it, not duplicating it" list. Future-me reading this in a hurry: don't re-grep, the audit was done, here's the result.

| Concern | Already exists | Where | alpha.3 touches it? |
|---|---|---|---|
| **Cascade prevention between forward + reverse engines** | `JEDB_Sync_Guard::is_locked() / acquire()` cross-direction check at top of `apply_bridge()` | `class-flattener.php` ~258–264 + symmetric in `class-reverse-flattener.php` | **No.** New per-product guards are a separate layer — different signal source (post meta vs in-memory lock), different lifetime (sticky vs in-flight), different intent (editor will vs cascade prevention). Reuses `STATUS_SKIPPED_LOCKED` constant with a `reason` field to disambiguate context. |
| **Bridge cardinality 1:1 (D-1)** | `default_config_json()` + adapter contracts | `class-flatten-config-manager.php` | **No.** alpha.3 doesn't touch cardinality. |
| **Default direction = CCT-canonical (D-2)** | `'direction' => 'push'` in defaults | `class-flatten-config-manager.php` | **No.** Per-product `_jedb_bridge_direction_override` is a layer on top, not a default change. |
| **Link via JE Relations primary (D-10)** | `resolve_target_id()` walks `link_via.type` then falls back per L-021 | `class-flattener.php` | **No.** Day 2 meta box reuses `resolve_target_id()` in read-only mode. |
| **Relation rows self-heal (D-13)** | `JEDB_Relation_Attacher::attach()` (idempotent), invoked by L-021 fallback | `class-relation-attacher.php` | **No.** Day 2's "Link this product" button calls `attach()` directly. |
| **Adapter-declared required fields (D-15)** | `get_required_fields()` on all four adapters | `interface-data-target.php` + `class-target-*.php` | **No.** Field Presets (D-26) compose ON TOP via `required_overrides.add` — `get_required_fields()` is unchanged. |
| **Adapter-declared native rendering (D-16)** | `is_natively_rendered($field)` on all four adapters | `interface-data-target.php` + `class-target-*.php` | **No.** Day 2 meta box CALLS this to skip Woo-native fields when rendering surfaced inputs. |
| **JE asymmetric auto-create (D-17)** | Forward engine no auto-create; reverse engine has opt-in `auto_create_target_when_unlinked` | both flatteners | **No.** Untouched. |
| **Hook priority 20 (D-19)** | `JEDB_FLATTEN_HOOK_PRIORITY` constant + `init` hook at priority 30 to register | `je-data-bridge-cc.php` + `class-flattener.php` line 57 | **No.** Untouched. |
| **Taxonomy applier (D-20–D-24)** | `JEDB_Taxonomy_Applier` shipped Phase 3.6 | `class-taxonomy-applier.php` | **No.** Untouched. |
| **L-024 mappings-then-taxonomies ordering** | Locked into `apply_bridge()` flow | `class-flattener.php` ~319–325 | **No.** Untouched. |
| **`_jedb_bridge_locked` / `_jedb_bridge_direction_override` post meta** | *Confirmed via grep: zero occurrences in active code* | (none) | **Yes — genuinely net new.** No post meta with these names exists today; alpha.3 introduces them. |

### Reusable patterns from reference plugins (cited verbatim, ported into Day 2 / Day 4)

The two reference plugins in `Refrence but block from git/` that have the most relevant patterns for Phase 4 are JFB-WC (meta box on Woo edit screens) and Jet Engine Relation Injector (JE hook timing + conditional asset enqueue). Don't re-invent these:

| Pattern | Source citation | Day 2 / Day 4 application |
|---|---|---|
| `add_meta_box()` registration with `add_action('add_meta_boxes', ...)` | `Refrence but block from git/JFB-WC-Quoites/jfb-wc-quotes-advanced/jfb-wc-quotes-advanced.php` lines ~1435–1444 | Day 2: identical call, post_type = `product` AND `product_variation`, context = `normal` (more space than `side` for surfaced fields). |
| Save handler with the canonical 4-guard preamble (nonce, perms, autosave, post-type) | JFB-WC ~1465–1484 | Day 2: identical structure, hooked on `save_post_product` + `save_post_product_variation` at default priority 10 (runs after Woo's own save handlers). |
| `wp_nonce_field()` in render + `wp_verify_nonce()` in save | JFB-WC ~1452 + ~1468 | Day 2: identical. |
| Conditional `admin_enqueue_scripts` keyed on `$hook == 'post.php' && $post_type == ...` | JFB-WC ~1658–1661 | Day 2: same shape, condition = `'post.php' == $hook && in_array( $post_type, array('product','product_variation'), true )`. |
| `admin_notices` for inline save-time feedback | JFB-WC ~795 | Day 2: use for "Sync now" success/failure surfacing instead of inventing a custom UI. |
| `add_action('init', ..., 20+)` for JE hook registration timing | `Refrence but block from git/Jet Engine Relation Injector/includes/class-transaction-processor.php` line 25 | Day 1+: already followed (`class-flattener.php` line 57 uses `init` priority 30). No change. |
| Conditional picker asset enqueue scoped to relevant edit screens | RI's `class-runtime-loader.php` + JFB-WC's enqueue gate | Day 2: meta box JS/CSS only enqueues on product / variation edit screens. Day 4: Field Presets admin tab JS only on the Field Presets tab. |

What we explicitly do NOT crib from these plugins: their inline `<style>` blocks, direct-SQL where the JE/WP API would do, commented-out legacy branches in JFB-WC. The patterns are right; the surrounding style isn't.

### What Phase 4 will deliver (4 days)

### What Phase 4 will deliver (4 days)

#### Day 1 — alpha.3 release: revert + flatten config schema + engine guards

- Delete the alpha.1/alpha.2 work listed above.
- Extend `JEDB_Flatten_Config_Manager::default_config_json()` with `meta_box: { enabled, title, position, groups[] }` block and top-level `cct_single_redirect: bool`.
- Extend `JEDB_Flatten_Config_Manager::default_mapping()` with `surface_on_source: bool`, `surface_on_target: bool`, `group: string` (D-26 freeform).
- Add 3-line guards at the top of `JEDB_Flattener::apply_bridge()` and `JEDB_Reverse_Flattener::apply_bridge()` for `_jedb_bridge_locked` post meta and `_jedb_bridge_direction_override` post meta.
- Extend the Flatten admin tab editor: new "Meta box settings" collapsible section, per-mapping `surface_*` + `group` controls in the mappings table, new `cct_single_redirect` checkbox.
- Add `JEDB_OPTION_FIELD_PRESETS` constant + activation default. `JEDB_Field_Presets_Manager` skeleton (read-only API) — full UI ships Day 4.
- ✅ Exit: editing existing 0.5.x flatten configs still works (back-compat verified). New per-mapping flags render correctly. Engine guards skip-log a locked product. Lint clean.

#### Day 2 — Bridge meta box on Woo edit screens (reads flatten config directly per D-27)

- Implement `JEDB_Woo_Product_Meta_Box` for `product` and `product_variation` edit screens.
- Resolution: walk `wp_jedb_flatten_configs` → for each row whose `target_target` matches → run existing link-resolution logic per row → render one panel per resolved bridge (or the unlinked picker if zero).
- Linked panel: linked CCT row label + edit link, last-3 sync log rows, surfaced field inputs grouped by `group` label, Lock checkbox, Direction override radio, Sync now button, Unlink button.
- Unlinked panel: pick from compatible flatten configs → Ajax CCT picker → Link button (calls `JEDB_Relation_Attacher::attach()` for `je_relation` bridges).
- Save handler routes surfaced field edits through `Target_*::update()` (HPOS-safe, typed setters).
- ✅ Exit: editing a Mosaic product on Woo admin shows linked CCT row, surfaces flagged fields, lets editor sync now / lock / unlink without leaving the screen. Reverse-pull engine writes surfaced fields back to CCT.

#### Day 3 — CCT-single → linked-post redirect shim

- New `includes/class-cct-single-redirect.php`. Hook `template_redirect`.
- Reads opt-in from each flatten config's `cct_single_redirect` flag. Direction guard (only redirects when direction includes push). Admin escape hatch (`?jedb_no_redirect=1` for `manage_options`).
- ✅ Exit: visiting a CCT single URL 301-redirects to linked product permalink for opt-in bridges. Admin escape hatch works.

#### Day 4 — Field Presets admin tab + Mandatory coverage integration (D-26)

- New `JEDB_Field_Presets_Manager` writes (CRUD on `jedb_field_presets`).
- New "Field Presets" admin tab (priority 35).
- Flatten admin tab Mandatory coverage panel gains: **Apply preset** dropdown, **Display-only overlay** mode, **Scaffold missing mappings** button, provenance display (adapter / preset / bridge override per required field).
- ✅ Exit: editor curates "WooCommerce — Storefront Visible" preset, exports as JSON, imports on a fresh staging site, applies to a bridge, sees PAC VDM-style coverage badges, clicks Scaffold to auto-stub missing mappings.

### Per-product meta added in alpha.3

```
_jedb_bridge_locked              bool   universal lock — engine skip with reason: per_product_lock
_jedb_bridge_direction_override  enum   '' | push | pull | bidirectional | none — constrains effective direction for this product
_jedb_bridge_last_manual_sync_id int    sync_log row id of the last meta box "Sync now" — drives status block
```

**No `_jedb_bridge_type`.** **No `_jedb_bridge_cct_id`.** Per D-10 / D-25 / D-27, the link lives in JE Relations / `cct_single_post_id`, the bridge identity lives in `wp_jedb_flatten_configs`.

### What stays NOT in Phase 4

- Variation reconciliation engine + `show_when` mini-DSL — Phase 4b. The `variations[]` array lives directly on the flatten config per D-25.
- `term_assigned` trigger — Phase 4.5.
- Custom Code Snippets runtime — Phase 5b.
- Setup tab presets — Phase 6 ships both bridge presets (flatten config JSON) and field presets (Day 4 artifact format).
- Capability gating beyond `manage_options`, REST auth hardening, i18n .pot — Phase 7.

All architectural decisions Phase 4 needs are locked: D-1 through D-27 (with D-5 superseded by D-25). All known JE / WC / WP caveats are documented (L-001 through L-026). The forward + reverse flatteners, sync guard, sync log, transformer registry, condition evaluator, adapter-owned `is_natively_rendered` / `get_required_fields` methods, taxonomy applier, and live-querying admin AJAX endpoints are all production-verified through Phase 3.6 and ready for the meta box to consume.

## 13. Historical reference: original "Next Action" notes from §8 lock-in

(Kept for audit purposes — the work has been done.)

1. **I scaffold Phase 0** — bootstrap, constants, empty class graph, all four custom tables (`wp_jedb_relation_configs`, `wp_jedb_flatten_configs`, `wp_jedb_sync_log`, `wp_jedb_snippets`), `Snippet_Installer` writes `uploads/jedb-snippets/.htaccess` + `index.php`, blank admin tab. Result is committable in one sitting and visible in WP admin. Lives in a new repo `legworkmedia/je-data-bridge-cc`.
2. **You install on a staging copy** of bbhq.legworklabs.com and confirm it activates clean.
3. **We move through Phases 1 → 7** in order, stopping for review at each exit criterion.

Estimated total: ~11 working days end-to-end.
