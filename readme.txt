=== JetEngine Data Bridge CC ===
Contributors: legworkmedia
Tags: jetengine, woocommerce, cct, relations, sync, bridge, data
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.6.0-alpha.8
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

== Current Capability (v0.6.0-alpha.8) ==

* Plugin tables created on activation.
* Discovery layer covering CCTs, public CPTs, JE Relations, JE Glossaries, Woo products and variations.
* Four target adapters (CCT, CPT, Woo Product, Woo Variation) — HPOS-safe — with required-fields and native-rendering hints (D-15 / D-16).
* Targets admin tab — read-only inventory.
* Relations admin tab — configure which JE Relations the picker exposes per CCT (relations themselves are still authored in JetEngine → Relations).
* Picker UI on CCT edit screens with modal-based search via WP_Query (sees all products, including those auto-created by JE Relations).
* Direct-SQL relation writes per a verified contract (idempotent duplicate-check, type-aware clearing for 1:1 / 1:M).
* **Forward-direction flatten engine** (Phase 3, v0.4.0) — editing a CCT row pushes mapped fields onto its linked Woo / CPT record. Hooks at priority 20 so JE's own auto-create finishes first.
* **JE Relation row self-heal** (v0.4.1) — when a CCT row's relation row is missing but `cct_single_post_id` resolves to a valid linked post, the engine auto-attaches the relation row so JE Smart Filters / Listings work natively from the first sync. Per L-021.
* **Reverse-direction flatten engine** (Phase 3.5, v0.5.0) — editing a Woo product / CPT propagates mapped fields back to the linked CCT row via the per-mapping `pull_transform` chain. Hooks at `woocommerce_update_product` (+ variations) and `save_post_{type}`.
* **Bidirectional bridges** (v0.5.0) — `direction = bidirectional` registers both engines for one bridge with mutual cascade prevention.
* **Auto-create CCT row** (v0.5.0, D-17 opt-in) — when a post saves with no linked CCT row, the reverse engine can optionally create a fresh CCT row in the bridge's source target and auto-attach the relation. Default OFF; opt-in per bridge.
* **Categorization layer** (Phase 3.6, v0.5.2) — bridges can categorize posts on push via two complementary mechanisms: a new `term_lookup` transformer for per-row dynamic categorization (composes with the existing per-mapping transformer chain), and a new `taxonomies[]` array on flatten configs for static-per-bridge multi-taxonomy assignment with per-rule merge strategy (append/replace), explicit term removal via `apply_terms_inverse`, optional `create_if_missing`, and forward-compat with Phase 5b snippets. Push-only semantics in v1 (D-21).
* **Live taxonomy UI** (Phase 3.6, v0.5.2) — Flatten admin tab gains a Taxonomies section visible only when `target_target` is `posts::*`. Editors pick from registered taxonomies + existing terms via dropdowns instead of typing slugs.
* **Phase 4 Day 1 alpha.3 reshape** (v0.6.0-alpha.3) — the alpha.1/alpha.2 Bridges admin tab + Bridge Types Manager template layer was retired (~1,500 lines deleted) per D-25 / L-026. Flatten config schema extended with `meta_box` block + per-mapping `surface_on_target` / `surface_on_source` flags + freeform `group` label + top-level `cct_single_redirect` opt-in. Per-product engine guards added in both flatteners for `_jedb_bridge_locked` (lock sync per product without touching bridge config) and `_jedb_bridge_direction_override` (constrain direction per product). New STATUS_SKIPPED_DIRECTION_OVERRIDE sync_log status. Field Presets manager skeleton in place (full CRUD + admin tab Day 4). Existing 0.5.x flatten configs work unchanged (back-compat in merge_with_defaults).
* **Phase 4 Day 2 — Bridge meta box** (v0.6.0-alpha.4) — new `JEDB_Woo_Product_Meta_Box` class renders a meta box on `product` and `product_variation` edit screens. Reads `wp_jedb_flatten_configs` directly per D-27 — no template layer. Walks bridges where `target_target` matches the current post type, resolves via the existing `JEDB_Reverse_Flattener::resolve_source_id()` in read-only mode per candidate, renders one panel per resolved bridge (linked or unlinked). Linked panel: surfaced field inputs grouped by freeform group label, per-product Lock + Direction override controls (writes the alpha.3 post meta), last 3 sync_log rows pill-coded, Sync now + Unlink action buttons. Unlinked panel: live CCT search (reuses Phase 2 relation-search AJAX) + Link button. Save handler acquires Sync_Guard pull lock around source-side writes to prevent the reverse pull engine from clobbering fresh CCT writes on the same request. All engine code untouched.
* **Sync Guard** — per-request + transient locks with origin tagging prevent recursive saves.
* **Sync Log** — every bridge invocation writes a row with status from the `success / partial / errored / skipped_condition / skipped_error / skipped_locked / skipped_no_target / noop` taxonomy.
* **Transformer registry** — 9 built-ins (passthrough, yes_no_to_bool, regex_replace, format_number, lookup_table, name_builder, truncate_words, strip_html, year_expander). Each defines push and pull explicitly.
* **Condition Evaluator** — v1 declarative DSL parser supporting `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `not_contains`, `starts_with`, `ends_with`, `in`, `not_in`, combined with `AND`, `OR`, `NOT`, with `{source.field}` / `{target.field}` path references.
* **Flatten admin tab** — bridge editor with explicit two-column field-mapping picker, per-direction transformer chains (D-11), mandatory-coverage panel (D-15), native-rendered hint (D-16), live condition validator, and manual "Sync now" button.
* Debug tab with log viewer and discovery diagnostics.

== Not Yet Shipped ==

* Snippet-mode `condition_snippet` evaluation — Phase 5b. Declarative DSL conditions work fully today.
* CCT-single → linked-post redirect shim — Phase 4 Day 3.
* Field Presets admin tab + Apply / Scaffold integration on the Mandatory coverage panel — Phase 4 Day 4.
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

= 0.6.0-alpha.8 =
* Stale-data hotfix (L-030) - Bridge meta box surfaced previews were reading via JE's `$db->get_item()`, which can return cached pre-save rows on the request immediately after a write (especially Redis / Memcached setups). Forward push didn't have this problem because it runs in JE's save request where cache layers happen to be hot with the new value. New `Target_CCT::get_fresh()` method goes direct-SQL, bypassing every JE cache layer. Wired into meta box `resolve_for_post`, forward push `apply_bridge`, and reverse pull source-side reads. Non-CCT adapters unchanged (WP's standard post-meta cache invalidates properly). Engine semantics unchanged - purely a read-freshness fix.

= 0.6.0-alpha.7 =
* Bridge meta box modal flow fixes (L-029) - three user-reported bugs squashed: (1) "Save & edit" confirm-dialog loop on post-save reload (caused by an over-eager dirty-check vs WP autosave / 3rd-party plugin DOM mutations - dropped the check entirely, always save-first); (2) "Done" button didn't save (Done now programmatically clicks JE's submit button so JE's full save flow fires); (3) JE's native Save button left the editor on a chromed page inside the iframe (JE's post-save redirect strips our chrome-strip query param; fixed via a two-tier injection where the close-on-save handler is always-injected on jet-cct-* pages and uses sessionStorage to survive the redirect, hiding html immediately to prevent WP-chrome flash). Polish: "Saving..." overlay on the parent modal during the save round-trip. No engine code touched.

= 0.6.0-alpha.6.1 =
* Critical hotfix (L-028) - the Bridge meta box was emitting `<form>` tags inside WP's main `#post` form, which HTML5 forbids. Browser parsers were closing `#post` prematurely on the inner `</form>`, pushing the WP Update button outside any form, and causing every product save to redirect to `wp-admin/edit.php`. Bug existed since alpha.4 but only surfaced now because alpha.6's modal launcher made the symptom unmissable. Fixed by converting the Sync now / Unlink / Link buttons to `<button type="button">` elements with data attributes; the JS click handler builds the real `<form>` off-DOM (appended to `<body>`, outside `#post`) and submits programmatically. Same admin-post.php endpoints, same handlers, same flow - just no invalid nested-form HTML. NO engine code touched.

= 0.6.0-alpha.6 =
* Phase 4 Day 2 architectural pivot (L-027) — CCT editing on the Woo product edit screen is now delegated to JE's own CCT edit page via a chrome-stripped modal iframe. Surfaced fields render as type-aware READ-ONLY previews (text, boolean pills, media thumbnails, gallery grids, select labels, date formatting, etc.); a "Save & edit \"{label}\" in JetEngine" button per linked bridge launches the JE edit page in an iframe overlay with WP chrome hidden. Editor saves in JE's native UI (every field type works because JE renders them), clicks "Done · Return to product" in our top bar, parent product page reloads and shows the updated previews. The alpha.5 explicit-`apply_bridge` workaround for L-022 is retired — JE's natural save flow fires its hooks normally because JE itself is doing the save, not our adapter. Net result: zero per-type-renderer code, zero L-022 friction, every JE field type works correctly with no maintenance burden as JE adds field types in future releases. No schema migration; existing flatten configs work unchanged.

= 0.6.0-alpha.5 =
* Phase 4 Day 2 hardening — Bridge meta box surface mechanics decoupled from sync mechanics. A mapping flagged `surface_on_target` now renders in the meta box regardless of whether `target_field` is set (pure-surface mode) or whether the target is Woo-native (native overlay — editor opted in by ticking, CCT-canonical D-2 wins on conflict). Mode pill on each field tells editors what the input actually does. Data-loss bug fixed: meta box save now explicitly invokes `JEDB_Flattener::apply_bridge()` after the source write to keep target in sync (working around L-022 within bounded scope). When surface flags are ticked but no fields render, the panel shows per-mapping skip reasons instead of a misleading blank-state message. Engine code unchanged from alpha.4.

= 0.6.0-alpha.4 =
* Phase 4 Day 2 — Bridge meta box on Woo product / variation edit screens (D-27). New JEDB_Woo_Product_Meta_Box class hooked on add_meta_boxes for product + product_variation post types. Reads wp_jedb_flatten_configs directly per D-27 — no template layer. Resolves which bridge(s) govern THIS specific product via the existing JEDB_Reverse_Flattener::resolve_source_id() in read-only mode. Linked panel surfaces editable inputs for mappings flagged surface_on_target=true (grouped by freeform group label), shows last 3 sync_log rows pill-coded, exposes per-product Lock + Direction override (writes the alpha.3 post meta the engine guards consume), provides Sync now + Unlink action buttons. Unlinked panel includes a live CCT search (debounced 250ms, reuses Phase 2 wp_ajax_jedb_relation_search_items) + Link button calling JEDB_Relation_Attacher::attach(). Save handler acquires Sync_Guard pull lock around source-side writes so the reverse pull engine on the subsequent woocommerce_update_product bails at its same-direction acquire (prevents stale-post-value pull-back). All engine code untouched. WC-active gate: only loaded when class_exists('WooCommerce'). Known minor limitation: editing a surfaced field AND a Woo-native field in the same save defers the native-field reverse pull by one save cycle.

= 0.6.0-alpha.3 =
* Phase 4 Day 1 reshape per D-25 / D-26 / D-27 / L-026. Alpha.1/alpha.2 Bridges admin tab + Bridge Types Manager template layer retired (~1,500 lines deleted) — the flatten config IS the bridge identity. Flatten config schema extended: meta_box block (title, position, groups), per-mapping surface_on_target / surface_on_source flags + freeform group label, top-level cct_single_redirect opt-in. Per-product engine guards added: _jedb_bridge_locked freezes sync without touching the bridge config; _jedb_bridge_direction_override constrains direction per product. New STATUS_SKIPPED_DIRECTION_OVERRIDE sync_log constant. New jedb_field_presets option + JEDB_Field_Presets_Manager skeleton (read-only API; full CRUD + admin tab UI ship Day 4). Engine paths byte-identical to v0.5.3 outside the new skip-only guards. Existing 0.5.x flatten configs work unchanged (back-compat in merge_with_defaults).

= 0.6.0-alpha.2 =
* Phase 4 / Day 1 hotfix — bridge type schema realigned with flatten config (L-025). Pasting a working flatten config's "Advanced JSON" into the Bridges admin tab "Defaults JSON" textarea now Just Works — the textarea accepts a raw flatten config payload verbatim. Cause: alpha.1 used `default_field_mappings`, `default_taxonomies`, `default_condition`, `default_priority` keys at the top level. Flatten configs use `mappings`, `taxonomies`, `condition`, `priority`. The schema mismatch silently dropped the user's pasted values. Fix: bridge types now wrap the flatten config payload in a `flatten_defaults` sub-object whose keys match `JEDB_Flatten_Config_Manager::default_config_json()` exactly. Top-level metadata (slug, label, description, source/target/direction, enabled, cct_single_redirect, variations) stays at the top of the bridge type entry. NO engine code touched. Silent on-read migration for any alpha.1 entries — no editor action required.

= 0.6.0-alpha.1 =
* Phase 4 / Day 1 — Bridges admin tab + JEDB_Bridge_Types_Manager. The long-reserved `jedb_bridge_types` site option finally gets a UI. Bridge types are templates (source/target/direction/link_via/default mappings/default taxonomies/cct_single_redirect opt-in) that the Phase 4 Day 2 Bridge meta box on Woo product edit screens will clone into concrete flatten configs when an editor wires up an individual product. New AJAX endpoints `jedb_bridges_export` and `jedb_bridges_get_relations_for_pair`. New form actions `jedb_bridges_save`/`toggle`/`delete`/`import` with optional replace-all on import. NO engine code touched — flattener / reverse flattener / sync guard / taxonomy applier are byte-identical to v0.5.3. This is alpha.1 because Phase 4 isn't feature-complete; Day 2 (meta box) and Day 3 (redirect shim) follow.

= 0.5.3 =
* Phase 3.6 hotfix — engine ordering bug + term_lookup zero-resolve warning. Per L-024: field mappings now run BEFORE taxonomies in JEDB_Flattener::apply_bridge(), so taxonomy rules always get the final word. Was causing silent data loss when a `taxonomies[]` rule and a `term_lookup` mapping both targeted the same taxonomy slot — sync log reported success but the product had zero categories because the mapping's typed setter cleared the slot AFTER the applier added terms. New sync log status determination has four explicit paths (errored / mappings-wrote / taxonomies-only / noop). The `term_lookup` transformer now logs a warning when non-empty input resolved to zero term IDs, with a hint about the match_by / value-shape mismatch.

= 0.5.2 =
* Phase 3.6 — categorization layer. New `term_lookup` transformer (push: names/slugs/IDs → term IDs; pull: term IDs → names/slugs) for per-row dynamic categorization via the existing per-mapping chain. New `taxonomies[]` array on bridge configs for static-per-bridge multi-taxonomy assignment with per-rule merge strategy, explicit term removal, optional create_if_missing, and forward-compat with Phase 5b snippets. New `JEDB_Taxonomy_Applier` engine class. Forward flattener invokes the applier between condition check and field mappings; reverse flattener skips taxonomies entirely (D-21 push-only semantics). New Flatten admin tab Taxonomies section with live-queried dropdowns via the new `wp_ajax_jedb_flatten_get_post_type_taxonomies` endpoint. Per D-20 → D-24 / L-023 / BUILD-PLAN §4.11.

= 0.5.1 =
* Documentation + small cleanups; no behavior change. Adds L-022 to LESSONS-LEARNED capturing the architectural finding that JetEngine's `$db->update()` doesn't fire the `updated-item/{slug}` hook — meaning the reverse pull → forward push cascade can't form on the JE side, and our defensive `is_locked()` check on that path is insurance for future JE versions / 3rd-party hook re-firers / Phase 4 manual-sync paths. BUILD-PLAN §4.10 cycle-prevention prose updated to reflect the asymmetry. Forward + reverse `noop` log rows now include `resolution`, `auto_attached`, and `auto_created` fields for symmetric debuggability with success/errored rows.

= 0.5.0 =
* Phase 3.5 — reverse-direction (post → CCT) flatten engine. Editing a Woo product or any bridged CPT now propagates mapped fields back to the linked CCT row via the per-mapping `pull_transform` chain. Adds: `JEDB_Reverse_Flattener` engine, `direction = pull` and `direction = bidirectional` bridge support, mutual cascade prevention via cross-direction Sync_Guard checks, optional `auto_create_target_when_unlinked` flag (D-17 opt-in). Forward engine's `skipped_condition` log now includes resolution metadata (the v0.4.1 papercut).

= 0.4.1 =
* Phase 3 hotfix — JE Relation row self-heal. End-to-end testing surfaced that JetEngine's "Has-Single-Page" creates the linked post (`cct_single_post_id`) but does NOT write a row to `{prefix}jet_rel_{id}`. The flattener now falls back to `cct_single_post_id` when the relation table is empty and auto-attaches the missing relation row (idempotent), so JE Smart Filters / Listings work from the first sync without a manual picker click. Both behaviors are opt-out flags. Sync log gains `resolution` and `auto_attached` context. Documented as L-021.

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

= 0.6.0-alpha.8 =
Stale-data hotfix - Bridge meta box surfaced previews now refresh correctly after a modal save, even on setups with persistent object cache (Redis / Memcached). Direct-SQL get_fresh() bypass for CCT reads. No engine semantics change, no schema change.

= 0.6.0-alpha.7 =
Bridge meta box modal flow fixes - Save & edit no longer loops, Done now actually saves, JE's native Save also closes the modal. "Saving..." overlay added. No schema migration, no engine behavior change.

= 0.6.0-alpha.6.1 =
Critical hotfix - product saves were redirecting to `wp-admin/edit.php` because the Bridge meta box emitted `<form>` tags inside WP's `#post` form (invalid HTML, since alpha.4). Update IMMEDIATELY if you're on alpha.4 / alpha.5 / alpha.6. No engine behavior change, no schema migration.

= 0.6.0-alpha.6 =
Phase 4 Day 2 architectural pivot — CCT editing now happens in JE's own UI via a chrome-stripped modal iframe launched from the Woo product edit screen. The meta box renders type-aware read-only previews and a "Save & edit" button per linked bridge. Every JE field type works correctly (because JE renders them). The alpha.5 explicit-apply_bridge workaround for L-022 is gone. No schema migration; existing configs work unchanged.

= 0.6.0-alpha.5 =
Phase 4 Day 2 hardening — fixes a data-loss bug in alpha.4 where surfaced-field edits could be clobbered by the reverse pull on the next product save. Also decouples surface from sync — mappings can now surface in the meta box without requiring a target_field. No schema migration. Test recipe simplified: just tick "Target" on your real mappings instead of inventing fake target meta keys.

= 0.6.0-alpha.4 =
Phase 4 Day 2 — Bridge meta box on Woo product / variation edit screens. The meta box appears automatically for any product whose post type has an enabled flatten config targeting it. Surfaces flagged CCT fields on the product edit screen with two-way sync. No schema migration; no engine code change. WC-active gate (only loaded when WooCommerce is present).

= 0.6.0-alpha.3 =
Phase 4 reshape — alpha.1/alpha.2 Bridges tab retired, flatten config schema extended with Meta box settings + per-mapping surface flags + cct_single_redirect, per-product engine guards added. No engine behavior change for existing setups; the new guards are skip-only and only fire when editors set `_jedb_bridge_locked` or `_jedb_bridge_direction_override` post meta. Existing 0.5.x flatten configs work unchanged. The `jedb_bridge_types` option is dropped on activation (no-op for installs that didn't run alpha.1/alpha.2).

= 0.6.0-alpha.2 =
Schema realignment for bridge types — alpha.1 entries silently migrate on read. No editor action required. Pasting raw flatten config "Advanced JSON" into the Bridges admin tab now works verbatim (L-025). NO engine code touched.

= 0.6.0-alpha.1 =
Phase 4 / Day 1 — Bridges admin tab + Bridge Types Manager. No schema migration; no behavior change for existing 0.5.x sites. Engine code is byte-identical to v0.5.3 — this release adds the configuration template layer; the Phase 4 Day 2 Bridge meta box (next release) is the consumer.

= 0.5.3 =
Hotfix for L-024 ordering bug. If you saw "ghost successes" in your sync log on 0.5.2 (rows with `terms_added: N` but the product showed no terms), upgrade and re-save the affected CCT rows. No schema change; no config change required.

= 0.5.2 =
Phase 3.6 — taxonomy support shipped. Bridges can now categorize posts on push via the new `term_lookup` transformer (per-row dynamic) and the new `taxonomies[]` array (static-per-bridge). Push-only semantics in v1 — pull never modifies taxonomies. No schema migration; existing 0.5.x bridges work unchanged with an empty `taxonomies[]` filled in on read.

= 0.5.1 =
Documentation + small cleanups; no behavior change. Locks in the L-022 architectural finding from staging testing. Existing 0.5.0 bridges work unchanged.

= 0.5.0 =
Phase 3.5 — reverse direction works. Editing a Woo product propagates back to the linked CCT row. Bidirectional bridges supported with automatic cascade prevention. No schema migration; existing 0.4.x bridges work unchanged. The `direction` field on the form now accepts pull and bidirectional.

= 0.4.1 =
Fixes a Phase 3 link-resolution bug discovered in staging. Bridges using `link_via.type = je_relation` now self-heal missing relation rows via `cct_single_post_id` fallback + idempotent auto-attach. No schema change. Backwards-compatible — existing 0.4.0 bridges get the new behavior on by default.

= 0.4.0 =
First release that actually moves data between sources and targets. Phase 3 flatten engine plus admin tab. No schema migration required.

= 0.3.1 =
Picker visibility fix for JE-auto-created products + bidirectional architecture documentation locked. No schema changes.
