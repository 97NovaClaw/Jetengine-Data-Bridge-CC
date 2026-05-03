=== JetEngine Data Bridge CC ===
Contributors: legworkmedia
Tags: jetengine, woocommerce, cct, relations, sync, bridge, data
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridges JetEngine CCTs / CPTs / Relations and WooCommerce products with bidirectional, loop-safe sync, relation pre-attachment, field flattening, and a sandboxed custom-snippet transformer system.

== Description ==

JetEngine Data Bridge CC consolidates relation management, field flattening, and a WooCommerce product bridge into a single, portable plugin you can drop on any JetEngine + WooCommerce site and configure entirely from the admin UI.

End-state highlights (full plan in BUILD-PLAN.md):

* **Relation pre-attachment** on CCT edit screens — pick a related parent before the row is saved.
* **PULL / PUSH field flattening** between related records, with separate per-direction transformer chains (push and pull are not assumed to be inverses).
* **Conditional sync engine** — multiple bridge configs can share a source target with disjoint conditions (declarative DSL or snippet escape hatch).
* **WooCommerce product bridge** — link a CCT row and a Woo product 1:1 via JE Relations (no parallel meta link), HPOS-safe writes through `WC_Product->save()`.
* **Variation reconciliation** — bridge types can declare variations with `show_when` rules.
* **Custom Code Snippets** — sandboxed PHP transformers editable from the admin, gated by capability and an opt-in toggle.

This is an in-progress port consolidating three earlier private plugins. Functional capability today is documented in the readme; the BUILD-PLAN.md document in the plugin folder has the full architectural spec and decisions log.

== Current Capability (v0.4.0) ==

* Plugin tables created on activation.
* Discovery layer covering CCTs, public CPTs, JE Relations, JE Glossaries, Woo products and variations.
* Four target adapters (CCT, CPT, Woo Product, Woo Variation) — HPOS-safe — with required-fields and native-rendering hints (D-15 / D-16).
* Targets admin tab — read-only inventory.
* Relations admin tab — configure which JE Relations the picker exposes per CCT (relations themselves are still authored in JetEngine → Relations).
* Picker UI on CCT edit screens with modal-based search via WP_Query (sees all products, including those auto-created by JE Relations).
* Direct-SQL relation writes per a verified contract (idempotent duplicate-check, type-aware clearing for 1:1 / 1:M).
* **Forward-direction flatten engine** (Phase 3, v0.4.0) — editing a CCT row pushes mapped fields onto its linked Woo / CPT record. Hooks at priority 20 so JE's own auto-create finishes first.
* **Sync Guard** — per-request + transient locks with origin tagging prevent recursive saves.
* **Sync Log** — every bridge invocation writes a row with status from the `success / partial / errored / skipped_condition / skipped_error / skipped_locked / skipped_no_target / noop` taxonomy.
* **Transformer registry** — 9 built-ins (passthrough, yes_no_to_bool, regex_replace, format_number, lookup_table, name_builder, truncate_words, strip_html, year_expander). Each defines push and pull explicitly.
* **Condition Evaluator** — v1 declarative DSL parser supporting `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `not_contains`, `starts_with`, `ends_with`, `in`, `not_in`, combined with `AND`, `OR`, `NOT`, with `{source.field}` / `{target.field}` path references.
* **Flatten admin tab** — bridge editor with explicit two-column field-mapping picker, per-direction transformer chains (D-11), mandatory-coverage panel (D-15), native-rendered hint (D-16), live condition validator, and manual "Sync now" button.
* Debug tab with log viewer and discovery diagnostics.

== Not Yet Shipped ==

* Reverse-direction sync (post → CCT) — Phase 3.5.
* Snippet-mode `condition_snippet` evaluation — Phase 5b. Declarative DSL conditions work fully today.
* Bridge meta box on Woo product edit screen — Phase 4.
* Variation reconciliation engine — Phase 4b.
* Custom Code Snippets runtime — Phase 5b.
* Setup-tab presets — Phase 6.
* Capability gating beyond `manage_options`, REST auth, i18n .pot — Phase 7.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/je-data-bridge-cc/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Visit **JE Data Bridge → Status** in the admin menu and verify every row is green.
4. Visit **Targets** for a read-only inventory of every record store the plugin can see.
5. Visit **Relations** to configure which JE Relations the picker UI exposes per CCT.

Requires JetEngine 3.3.1 or higher. WooCommerce is recommended but not required (the plugin runs in CCT-only mode if WooCommerce is missing).

== Frequently Asked Questions ==

= Does this plugin write directly to WooCommerce database tables? =

Reads use direct WP_Query for picker discovery (WC's `wc_get_products()` filters out products that haven't been saved through the WC API once — a real issue on JE-auto-created products). Writes always go through `WC_Product->save()` to keep HPOS / `wc_product_meta_lookup` in sync.

= Is this safe with HPOS-enabled stores? =

Yes. The plugin only writes to product data, not order data, and uses Woo's typed setters for every product write. HPOS detection is exposed via `jedb_is_hpos_enabled()` for any future code paths that need it.

= Does this plugin create JetEngine Relations? =

No. JE Relations are still created and edited in JetEngine → Relations. This plugin only configures which existing relations the picker UI exposes per CCT, and writes to the JE relation tables when a relation is attached. Relation creation stays in JE's own admin so JE owns version compatibility.

= Why doesn't auto-creating a related post work in both directions? =

JetEngine Relations supports auto-creating the related record on CCT save (e.g., create a Woo product when a Mosaic CCT row is saved). It does NOT support the reverse direction: creating a CCT row when a product is saved. Our plugin's reverse-direction sync (Phase 3.5+) handles that case explicitly.

= Can I write my own transformers? =

Yes — once Phase 5b ships, admins with `manage_options` (and the global "Enable Custom PHP Snippets" toggle ON) can write small PHP transformers in a CodeMirror editor from the admin UI. Errors are caught and logged; a broken snippet returns the unmodified input rather than killing a save.

== Changelog ==

= 0.4.0 =
* Phase 3 — forward-direction flatten engine. Editing a CCT row now pushes mapped fields onto the linked Woo / CPT record automatically, gated by per-bridge conditions and serialized through a per-direction transformer chain. Adds: Sync Guard, Sync Log, Transformer Registry (9 built-ins), Condition Evaluator (v1 DSL), Flatten Config Manager, Flattener engine, and the Flatten admin tab UI. New `JEDB_FLATTEN_HOOK_PRIORITY` constant (= 20). New `get_required_fields()` / `is_natively_rendered()` methods on the data-target interface (D-15 / D-16) implemented across all four adapters.

= 0.3.1 =
* Fix: Picker on CCT edit screen now sees JetEngine-auto-created products (switched from `wc_get_products()` to `WP_Query` — `wc_get_products()` filters by `_visibility` meta and the lookup table, both populated only by `WC_Product->save()`).
* Architecture: Locked the bidirectional sync model (forward direction = JE handles auto-create + we extend; reverse direction = ours entirely). Added L-016 through L-020 to LESSONS-LEARNED, D-17 through D-19 to BUILD-PLAN's Decisions Log. Added §4.10 (reverse-direction sync) and §4.9 trigger taxonomy.

= 0.3.0 =
* Phase 2 — Relation Injector port. Picker UI on CCT edit screens with modal-based search; direct-SQL relation writes per a verified contract; relation config admin tab with per-CCT cards.

= 0.2.x =
* Phase 1 — Discovery layer + four target adapters (CCT, CPT, Woo Product, Woo Variation) + Targets admin inventory tab. Multiple iterative fixes for JE 3.8+ field-schema resolution (canonical home is `wp_jet_post_types`), JE system-column handling, prefix discipline, and the Discovery resolver returning null for non-empty results.

= 0.1.x =
* Phase 0 scaffold — bootstrap, dependency check, four custom tables, snippet uploads folder, admin shell + status tab, debug-log helper. Hotfix for JetEngine version detection across multiple JE channels.

== Upgrade Notice ==

= 0.4.0 =
First release that actually moves data between sources and targets. Phase 3 flatten engine plus admin tab. No schema migration required.

= 0.3.1 =
Picker visibility fix for JE-auto-created products + bidirectional architecture documentation locked. No schema changes.
