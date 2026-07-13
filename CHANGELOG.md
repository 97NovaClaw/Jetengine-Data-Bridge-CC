# Changelog

All notable changes to this plugin are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Phase 4c is COMPLETE. Next per roadmap: Phase 5 (Settings, debug log viewer, utilities, export/import) and Phase 5b (Custom Code Snippets).

## [0.6.0-alpha.27] — 2026-07-12

**"Physical or PDF" dropdown resurfacing fixed — engine gains a safe-downgrade for classification attributes + full staging conversion of all legacy mosaic products.**

Staging report: the retired "Physical or PDF" storefront dropdown reappeared. Diagnosis: the alpha.23 demotion only ran on the two products that had managed variations at that moment (Brain, Variation test 2). Every OTHER mosaic product still carried `is_variation=1` on `pa_physical-or-pdf` from the July attribute cleanup — plus their May-era unmanaged variations still keyed on it. As mosaics got saved post-4c-A, managed variations appeared ALONGSIDE the legacy ones → two dropdowns / resurrected selector.

### Fixed (engine — this release)

- **`maintain_parent_attributes()` safe-downgrade branch**: when an `attribute_terms` (classification) taxonomy is found `is_variation=1` on the parent, the engine now demotes it to 0 — but ONLY when no unmanaged variation of that product still carries a value for it (new `unmanaged_variations_use_attribute()` check preserves the D-32 contract for genuinely manual setups). Self-heals both legacy state and any future WC-admin save that re-promotes the flag.

### Fixed (staging data, via MCP)

1. **All 11 published mosaic CCT rows force-pushed through bridge 3** (`origin=legacy_conversion_4cc`) — every linked product now has its managed variations from the repeaters (previously only Brain/736/748 did).
2. **Cleanup across all mosaic products with managed variations**: legacy unmanaged variations keyed on the old attribute trashed (657: 666/667 · 403: 511/512 · 401: 509/510 · 397: 507/508), stale attribute meta stripped from managed variations, `pa_physical-or-pdf` demoted (Koala, 657, 403, 401, 397), `WC_Product_Variable::sync` + transients + post caches refreshed.
3. Products without managed variations (unlinked test artifacts 395/404) deliberately untouched.

Note: Koala's manual variations were superseded by its repeater-managed ones in the force-push and its old attribute demoted — the D-32 coexistence test case is now converted like everything else (the contract itself remains enforced by the engine for any future manual setups).

### Verification

1. Any mosaic product page: ONE "Variant" dropdown only. Hard-refresh (transients were cleared, but browsers cache).
2. Variations selectable + add-to-cart works on converted products (e.g. Toronto Skyline, Cascade test).
3. Trash contains the 8 newly-trashed legacy variations (recoverable).

## [0.6.0-alpha.26] — 2026-07-12

**Phase 4c-C executed — legacy field retirement (decisions A–D locked by user). Six columns dropped from `mosaics_data`; the repeaters are now the single source of truth for variant data. Plugin change is preset-alignment only; the heavy lifting was site config/data via MCP (documented in BUILD-PLAN §4.14.14 + DATA-MAP).**

### Plugin changes (this repo)

- `flatten-admin.js` Physical preset: `price_fallback_field` + `derived_size_field` now empty (their backing CCT columns were dropped on BBHQ). Both remain supported engine features for other configurations.

### Site execution record (staging, via MCP — not plugin code)

1. **Snapshot** of the six doomed columns → `uploads/jedb-4cc-retirement-snapshot-20260713-002101.json`.
2. **Repeater schema**: `physical_variations` + `stud_count` (number, NOT WC-synced per user decision) + `hide_price` (yes/no select); `pdf_variations` + `hide_price`.
3. **Data migration**: parent `stud_count` copied into every physical row (all 9 legacy mosaics); per-row `regular_price` backfilled from parent `price` where empty and parent price > 0 (mosaics 11 & 15 → $1500); `hide_price` defaulted `no` everywhere.
4. **NEW Snippet 18** "Mosaic variant helpers": `[bbhq_mosaic_size]` + `[bbhq_mosaic_studs]` (first-enabled-row derivation, listing-loop aware, per-request cached) + `bbhq_variation_cct_row()` (variation → repeater row via `_jedb_variation_slug` UUID). Priority 5 so helpers precede Snippet 9.
5. **Snippet 9 reworked**: (a) Decision-D price display — `hide_price=yes` → "Quote on request", else stock ≤ 0/outofstock → "Request a Commission", else price; applied via `woocommerce_get_price_html` (variations) AND `woocommerce_available_variation.price_html` (the JS-driven display after selecting a variation). The old zero-price convention is retired — price value no longer drives display. (b) Additional-Info tab now derives Approximate Size / Stud Count / "PDF Instructions: Available" from the repeaters. Sets behavior unchanged.
6. **Listing 600** (home card): size element rebound from the dead `jet-object-property: approximate_size` dynamic tag to the `[bbhq_mosaic_size]` shortcode tag; Elementor CSS cache cleared.
7. **Queries 23 + 28**: `cct.approximate_size`, `cct.stud_count`, `cct.price` removed from SELECT lists (verified error-free post-drop).
8. **Bridge 3 config**: `price_fallback_field` + `derived_size_field` cleared.
9. **THE DROP** (JE Data API, `before_item_update` → `update_item_in_db` → `after_item_update` so column removal ran through JE's schema differ): `is_there_only_1_product_size`, `has_instructions_pdf`, `instructions_pdf`, `approximate_size`, `stud_count`, `price`. Verified: columns gone, repeater data intact (17-key physical rows), queries run clean.

`display_price_publicly` KEPT — card-only gate via Snippet 6, per user decision D.

### Verification (user)

1. Home page + archive cards render size strings (now via `[bbhq_mosaic_size]`).
2. Product page Additional Information tab: Theme / Approximate Size / Stud Count / PDF Instructions rows present.
3. Variation price display: set `hide_price=yes` on a row + save → storefront shows "Quote on request" for that variation; set a variation's stock to 0 → "Request a Commission".
4. Card gate: `display_price_publicly=no` mosaic still shows "Quote on request" on the archive card.
5. CCT edit screen: the six legacy fields are gone; repeater rows show Stud Count + Hide Price.

## [0.6.0-alpha.25] — 2026-07-12

**Phase 4c UI alignment — every admin surface now reflects the repeater-variation model (D-32 coexistence notice + managed-variation awareness + stale copy fixes).**

UI audit after 4c-A/B shipped, closing the gaps between what the engine does and what editors see:

### Added

- **D-32 coexistence notice on the CCT-screen variations panel** (the iframe launcher): when the bridge also has `variation_mappings`, the panel shows an amber notice — *"variations created from this item's variation fields are overwritten on every save here (stock changes sync back automatically). Use the editor below for variation images, shipping class, or extra manual variations."* Wired via a per-panel `has_managed_mappings` flag from `build_panels_for()` + new i18n string + `.jedb-cctv-managed-notice` styling. This was the scoped 4c-C notice, delivered early.
- **Managed-variations line in the product meta box** (Advanced Details, `show_advanced=true` bridges): *"N variations on this product are synced from the linked CCT's variation fields. Manual edits to those variations (except stock) are overwritten on the next CCT save. Variations created manually are never touched."* Count computed from `META_VARIATION_BRIDGE` meta scoped to this bridge + parent. Answers the inevitable "why did my variation change by itself?" admin question at the exact place they'd look.

### Changed

- **Flatten tab "Enable WooCommerce Variations" description de-staled** — it still claimed variation management needs "no declarative configuration in this plugin" (true in alpha.15, wrong since 4c-A). Now describes the split: repeater-synced variations vs. iframe-editor surface, plus the coexistence policy.

### Verification

1. Open mosaic 16's CCT edit page → the "Variations Management" panel shows the amber coexistence notice under the helper text.
2. Set `meta_box.show_advanced=true` on bridge 3, open product 736 → Advanced Details shows "3 variations on this product are synced…".
3. Flatten tab → Enable WooCommerce Variations section → new coexistence paragraph present.

## [0.6.0-alpha.24] — 2026-07-12

**Phase 4c-B shipped — reverse stock sync. Variation stock changes in WooCommerce (admin edits AND customer purchases) now flow back into the owning CCT repeater row. Stock is fully two-way.**

### Added

- **`JEDB_Variation_Sync::on_variation_stock_change()`** hooked on `woocommerce_variation_set_stock` (priority 20). WC fires this hook only when a variation's `stock_quantity` actually changed, and for BOTH paths that matter: an admin editing stock on the product page, and a customer purchase decrementing it (`wc_maybe_reduce_stock_levels` → `wc_update_product_stock` → data-store updated-props). One hook covers both reported scenarios.
- **Flow:** variation → `META_VARIATION_SLUG` (row UUID) + `META_VARIATION_BRIDGE` meta → unmanaged variations return immediately (D-32) → bridge loaded + direction contract respected (pull requires `pull`/`bidirectional`) → owning CCT row located by UUID probe against the mapped repeater columns (UUIDs are globally unique, so a LIKE probe is exact) → the `subfield_map` entry with `target=stock_quantity` AND `pull:true` names the subfield → surgical in-place update of that row's subfield → re-serialize → direct SQL write (L-030; no JE hooks fire per L-022, so no forward-push echo).
- **Cascade safety:** when the forward reconcile itself sets stock, `woocommerce_variation_set_stock` fires inside the push lock — the handler's `is_locked('push')` check suppresses the echo. The pull write itself is wrapped in a `pull` guard acquire/release.
- **Observability:** every stock pull records a sync_log row (`direction=pull`, `origin=wc_variation_stock`, success/noop/errored, old→new in context) + `jedb_log` info line.

### Scope note

4c-B is deliberately stock-only (per §4.14.8): the `pull` flag exists on every `subfield_map` entry, but only `stock_quantity` has a WC-side change hook wired. Broader pull fields (sale price / dims edited WC-side) are a 4c-C question — if editors author exclusively CCT-side, they may never be needed.

### Verification

1. **Admin edit:** change a managed variation's stock on the WC product page → save → the mosaic's repeater row shows the new Stock Quantity (reload the CCT edit page).
2. **Purchase path:** place a test order for a managed variation (stock 1 → 0) → complete/processing → repeater row shows 0.
3. **Echo test:** save the mosaic CCT (push sets stock) → sync_log shows the forward push but NO `wc_variation_stock` pull rows (suppressed by the push-lock check) or at most a `noop`.
4. **Unmanaged safety:** change stock on one of Koala's manual variations → no CCT write, no sync_log rows.

## [0.6.0-alpha.23] — 2026-07-12

**Phase 4c-A staging fixes — variation updates were silently failing (adapter type-check bug) + single-selector attribute model (removes the redundant "Physical or PDF" storefront dropdown).**

First staging round on alpha.22 (Variation test 2): initial save created all 3 variations correctly, but subsequent saves did nothing — sale-date changes didn't propagate and disabling a row didn't hide its variation. sync_log's `variations.errors` had the smoking gun: `update of variation #745 failed` for every row.

### Fixed — the adapter type-check bug (root cause of "nothing happened")

`JEDB_Target_Woo_Variation::exists()/get()/update()` compared `$variation->get_type()` against `self::POST_TYPE` (`'product_variation'`). But `WC_Product::get_type()` returns the PRODUCT type — `'variation'` — not the post type. Every `update()` call bailed at "variation not found" and returned false. Creates worked (no type check on the create path), which is why the initial save looked perfect and every re-save silently failed. All three methods now use the canonical `$variation->is_type( 'variation' )`.

(This bug predates 4c-A — the adapter shipped in alpha.13 whose reconciler was retired before update paths got real staging traffic. First real exercise found it.)

### Changed — single-selector attribute model (staging UX feedback)

alpha.22 put BOTH attributes on each variation (`pa_physical-or-pdf` + `pa_variant`), producing two storefront dropdowns — and the "Physical or PDF" one felt redundant/dead since the variant term alone already uniquely identifies every variation. Amended model:

- **`variant_attribute` (pa_variant) is THE storefront selector** — the only attribute on managed variations, `is_variation=1`. PDF mappings now declare it too (the PDF row's `variant_label` becomes a term, e.g. "Instructions PDF"), so physicals + PDF are all picked from ONE dropdown.
- **`attribute_terms` (pa_physical-or-pdf) becomes parent-level classification** — terms assigned to the product for filtering, `_product_attributes` entry created with `is_variation=0`, never placed on variations.
- **Existing entries' `is_variation` is never DOWNGRADED** by the engine (products with manual variations keyed on `pa_physical-or-pdf` — e.g. Koala — keep working; D-32 spirit). Upgrade to 1 still happens for the variant selector taxonomy. Staging test products created by alpha.22 get their parent meta corrected by a one-time data fix instead.
- `set_attributes()` replaces the whole attribute set on update, so stale `attribute_pa_physical-or-pdf` values on alpha.22-created variations self-heal on the next save (now that updates work).
- JS PDF preset + factory docs updated to match; bridge 3's seeded config updated on staging (PDF mapping gains `variant_attribute`).

### Verification (redo of the alpha.22 recipe, steps that failed)

1. Change a sale date on the 10″ FRAME row + save → variation #746's sale schedule updates in WC.
2. Disable the 18″ row + save → variation #745 goes `private` (hidden storefront-side).
3. Re-enable + save → back to `publish`.
4. Storefront single-product page shows ONE dropdown ("Variant": 18″ frame / 10″ frame / PDF Instruction set) — no "Physical or PDF" selector.
5. Parent product still carries `pa_physical-or-pdf` terms (visible in Additional Information / usable by filters) but not as a variation selector.

## [0.6.0-alpha.22] — 2026-07-12

**Phase 4c-A shipped — data-driven variation sync: CCT repeater rows ↔ managed WC variations (push direction).**

The spec'd-earlier-today §4.14 architecture, implemented. A bridge with `variation_mappings[]` configured now reconciles its source CCT's repeater rows into managed WooCommerce variations on every push, as phase 3 of the forward engine (mappings → taxonomies → variations).

### Added

- **`includes/flatten/class-variation-sync.php`** — new `JEDB_Variation_Sync` engine (~450 lines). Six-pass reconcile:
  1. **Row identity:** parses each mapped repeater (PHP-serialized, per DATA-MAP ground truth), fills empty `_jedb_row_id` subfields with UUIDs, persists immediately via direct SQL (L-030 pattern — no JE hooks fire per L-022).
  2. **D-30 always-variable** + **D-31 parent attribute maintenance:** forces `product_type=variable` when enabled rows exist; ensures attribute terms exist (creates `pa_variant` terms from `variant_label` per `create_if_missing`), assigns them to the parent (append-only), and maintains `_product_attributes` with `is_variation=1` while preserving unrelated editor-configured attributes.
  3. **Row reconcile:** finds managed variations via `find_managed_variation()` (row UUID ↔ `META_VARIATION_SLUG`), creates via `create_for_bridge()`, updates via the variation adapter. Full field build with cast discipline (strings → int/bool/float per target), price fallback to the source scalar (`price_fallback_field`), sale gate (`on_sale != yes` CLEARS the variation's sale fields so switching a sale off ends it storefront-side), downloads (attachment ID → WC downloads array with URL + title), per-row `photo` → variation `image_id`, per-row attribute combination (fixed `attribute_terms` + `pa_variant` term), status publish/private from the `enabled` switch. Un-trashes managed variations whose row came back.
  4. **Orphan handling:** managed variations (this bridge + parent only — unmanaged NEVER touched, D-32) whose row id vanished from the repeater get `delete_policy` treatment (trash default / private).
  5. **Parent rollups:** `WC_Product_Variable::sync()` + product transient clear when anything changed.
  6. **Derived size (§4.14.11):** when a mapping sets `derived_size_field`, the first enabled row's L/W/H become the `18″ × 18″` display string written to that source column (direct SQL, column-existence-guarded).
- **`variation_mappings[]` schema** in `JEDB_Flatten_Config_Manager`: `default_variation_mapping()` factory (documented per-key) + `merge_with_defaults()` back-compat. Generic by design — `derived_size_field`, subfield names, attribute taxonomies are all config, not hardcoded BBHQ knowledge.
- **Flattener integration:** reconcile invoked after the taxonomy applier inside the push lock (cascade events from variation/parent saves suppressed by the reverse engine's cross-direction check). `variations` summary added to all four status-path `context_json` payloads; Path 2/3 messages + conditions extended (`+ N variation change(s)` / `fields all noop, but N variation(s) changed`).
- **Flatten admin tab:** new "Variation Mappings (repeater → WC variations)" section, gated to `posts::product` targets (D6 rule). v1 surface = validated JSON editor (invalid JSON keeps the last valid state and shows an inline error — never poisons the saved config) + one-click **Physical** / **PDF repeater presets** carrying the §4.14.3 canonical BBHQ blocks (duplicate-guarded).
- **`_jedb_row_id` hidden on CCT edit screens:** `JEDB_Variation_Sync::maybe_hide_row_id_field()` on `admin_head` for `jet-cct-*` pages — MutationObserver-based (JE's Vue form mounts asynchronously and repeater rows grow), matches by input name/id or the "Row ID (system)" component label.
- **`Target_Woo_Variation` helpers un-deprecated:** `find_managed_variation()` / `create_for_bridge()` / `META_VARIATION_*` docblocks rewritten — production callers again as of 4c-A, exactly the "defensive surface for future automation" the alpha.14 retention decision anticipated. Tracking contract documented (SLUG = row UUID, BRIDGE = config row id).

### Verification (on staging, after deploy + bridge config)

1. Bridge 3 needs the two preset mappings added (Flatten tab → Variation Mappings → both preset buttons → Save) — or via the seeded config if pre-provisioned.
2. The `pa_variant` global attribute must exist in WC (Products → Attributes).
3. Open a migrated mosaic (e.g. Brain — 1 physical row from migration), save it. Expect: product flips to variable, one managed variation created with `physical-art` + variant term, price fallback from the parent `price` (empty on Brain — variation gets no price until set), stock 1, dims 64×80, `approximate_size` recomputed.
4. Open "Variation test 2" (item 16 — 2 physical rows incl. one on-sale + 1 PDF row with file 407): expect 3 managed variations — the 10″ frame with sale price 500 + schedule, the PDF variation virtual+downloadable with the file attached.
5. Toggle a row's Enabled off + save → variation goes private. Delete a row + save → variation trashed. Re-add with same content → NEW variation (new UUID — row identity is the hidden field, not the content).
6. Koala's manually-created variations (from the July attribute cleanup): the reconciler must NOT touch them until its repeater rows sync — and when they do, Koala will have BOTH managed and unmanaged variations coexisting (D-32 proof).

## [Docs] Phase 4c spec (2026-07-12) — repeater-driven variation data sync. BUILD-PLAN gained §4.13 (applicability gate reference section, filling D-28's dangling pointer), §4.14 (full Phase 4c spec), decision rows D-29 → D-32, and a Phase 4c roadmap entry. Design: CCT repeater fields (`physical_variations`, `pdf_variations`) carry per-row variation content — inventory, dimensions (L/W/H + weight), sale price with schedule, downloadable PDF — mapped to WC variations via a new `variation_mappings[]` bridge config block. Data-driven (CCT rows carry content), NOT config-driven like the retired alpha.13 reconciler (L-032 framing in §4.14.1). Row identity via hidden `_jedb_row_id` UUID ↔ the retained `META_VARIATION_SLUG` meta. Key locked decisions: always-variable product type once repeater rows exist (D-30), two-attribute strategy `pa_physical-or-pdf` + new `pa_variant` to satisfy WC's unique-combination rule for multiple physical variants (D-31), reconciler owns managed variations / iframe owns the rest (D-32). Phasing: 4c-A push, 4c-B stock-quantity pull (the purchase-decrement two-way piece), 4c-C polish.

**New `DATA-MAP.md`** — visual per-bridge field-mapping reference (card per bridge: mappings, transforms, taxonomy rules, applicability, flags) snapshotted from live staging config, plus the planned Phase 4c repeater mappings and a maintenance checklist. Update it whenever a bridge changes.

**Staging maintenance (2026-07-12, data-only, no release):** WC attribute cleanup — all 8 variable products migrated to the single global `pa_physical-or-pdf` attribute. Fixed dead-taxonomy references (`pa_physical-or-pdf-instructions`) on products 657/662, replaced per-product custom text attributes on 395/397/401/403, appended the missing term on 736, remapped all 17 variation attribute metas (validated against `_downloadable` flags), regenerated variation titles, ran `WC_Product_Variable::sync`, cleared product transients + post caches, regenerated the attributes lookup table, recounted terms, flushed the attribute-taxonomies cache. Incidental validation: the alpha.21 applicability gate behaved correctly under the resave load (mosaics bridge pulled, available_sets bridge skipped `not_applicable`, zero orphan rows).

Next implementation work: Phase 4c-A. Then Phase 5 (Settings, debug log viewer, utilities, export/import) and Phase 5b (Custom Code Snippets).

## [0.6.0-alpha.21] — 2026-05-24

**Cross-bridge applicability gate (post-L-033) — addresses the staging report where saving a Mosaic CCT row spawned an orphan Available Sets CCT row on the cascade.**

Live staging diagnostics on Brick Builders HQ revealed that when bridges share a `target_target` (e.g. multiple CCTs all bridging to `posts::product`), the reverse-flatten fan-out fires ALL of them on any save of that target. Combined with the overloaded `auto_create_target_when_unlinked` flag (which the reverse direction reused as "auto-create the SOURCE if missing"), saving a Mosaic CCT row would:

1. Forward-push create the WC product 662 (correct)
2. Product save fires `woocommerce_update_product`
3. Reverse_Flattener fans out to BOTH bridges with `target_target=posts::product`
4. Bridge 1 (`available_sets_data ← product`) finds no linked CCT → `auto_create_target_when_unlinked=true` → **CREATES orphan available_sets row 8 + auto-attaches jet_rel_8** (wrong)
5. The product is now in BOTH categorical webs forever

L-033 documents the full retrospective. The fix splits a single overloaded flag into direction-specific flags AND adds a first-class applicability gate that scopes bridges to specific taxonomy terms on the target.

### Added

- **`applies_when_target_in_terms` config block** in `JEDB_Flatten_Config_Manager::default_config_json()`:
  ```json
  "applies_when_target_in_terms": {
    "taxonomy":   "",
    "terms":      [],
    "match_by":   "slug",
    "match_mode": "any",     // any | all | none
    "applies_to": "pull"      // pull | push | both
  }
  ```
  Empty taxonomy OR empty terms = no gate (back-compat). New factory `JEDB_Flatten_Config_Manager::default_applies_when_target_in_terms()`. `match_mode` semantics: `any` = target has at least one of `terms` (default); `all` = target has every term; `none` = target has none (exclusion gate, useful for "this bridge handles everything EXCEPT mosaics").
- **`auto_create_source_when_unlinked` flag** in `default_config_json()` (default **false**). Replaces the reverse-direction semantics that were overloaded onto `auto_create_target_when_unlinked`. Reverse_Flattener::resolve_source_id() now reads this flag instead of the old one. Editors who genuinely want product-driven CCT creation (rare — typically only when the WC product is the canonical source and the CCT is a derived snapshot) opt in explicitly per bridge.
- **`JEDB_Sync_Log::STATUS_SKIPPED_NOT_APPLICABLE`** constant. Emitted when the applicability gate evaluates false against the saving target. `context_json` includes the bridge's expected `terms` + the target's actual terms for diagnostic clarity. **Healthy signal, not noise** — confirms the categorical scope is being respected.
- **`JEDB_Reverse_Flattener::evaluate_applicability_gate()`** — public static helper. Fetches the target's terms via `wp_get_post_terms()`, evaluates them against the bridge's gate per `match_mode`, returns `{decision: 'apply'|'skip', gate, actual_terms, match_mode}`. Called by both flatteners so the logic isn't duplicated.
- **Applicability check in `JEDB_Reverse_Flattener::apply_bridge()`** — runs BEFORE `resolve_source_id()`. On `skip`, logs `STATUS_SKIPPED_NOT_APPLICABLE` and returns. **No source resolution, no auto-create, no relation attach, no cascade noise.**
- **Applicability check in `JEDB_Flattener::apply_bridge()`** — runs AFTER `resolve_target_id()` (so a target_id exists to check terms on) but BEFORE field writes. Only fires when `applies_to ∈ {push, both}`. Default `pull`-only so existing bridges' forward-push behavior is unchanged.
- **Flatten admin tab UI** in `templates/admin/tab-flatten.php`:
  - Renamed the existing "Reverse-direction options" row's checkbox to "Auto-create on push" (forward) and fixed its label/description (it was previously labeled "creates the source CCT" — wrong direction).
  - New "Auto-create on pull" row with the new `auto_create_source_when_unlinked` checkbox (visible only when direction includes pull, like the old reverse-direction row).
  - New "Applicability" row exposing all five fields (taxonomy, terms CSV, match_by select, match_mode select, applies_to select) plus an inline warning banner when the gate was auto-derived from the `taxonomies[]` block on read.
- **`assets/js/flatten-admin.js`** round-trips all the new fields in `buildConfig` + change listeners.

### Changed

- **`JEDB_Reverse_Flattener::resolve_source_id()`** now reads `$config['auto_create_source_when_unlinked']` (default false) instead of `$config['auto_create_target_when_unlinked']`. Inline doc explains the change + references L-033.
- **`JEDB_Reverse_Flattener::apply_bridge()`** STATUS_SKIPPED_NO_TARGET message updated: `"no linked source CCT row — set auto_create_source_when_unlinked to opt in"` (was `"set link_via.auto_create_target_when_unlinked to opt in"` — pointed at the wrong flag after the split).
- **`JEDB_Flatten_Config_Manager::merge_with_defaults()`** — new back-compat block for `applies_when_target_in_terms` and `auto_create_source_when_unlinked`:
  - Adds defaults for `applies_when_target_in_terms` on read for bridges saved before alpha.21.
  - **Auto-derives the gate** from `taxonomies[0]` (first rule with non-empty `apply_terms`) when the explicit gate is empty. Sets `taxonomy = taxonomies[0].taxonomy`, `terms = taxonomies[0].apply_terms`, `match_by = taxonomies[0].match_by`, `match_mode = 'any'`, `applies_to = 'pull'`. Also stamps `_derived_from_taxonomies: true` for the UI to surface a "this was auto-derived" banner.
  - Initializes `auto_create_source_when_unlinked = false` for bridges that don't have the key — **DOES NOT inherit from `auto_create_target_when_unlinked`**. This closes the cascade footgun by default. Editors who relied on the old overloaded behavior have to explicitly re-enable.

### Affected by this fix (production bridges on BBHQ)

Both `cct::available_sets_data ↔ posts::product` (bridge id 1) and `cct::mosaics_data ↔ posts::product` (bridge id 3) have `taxonomies[]` rules with non-empty `apply_terms`:

- Bridge 1: `taxonomy=product_cat, apply_terms=[available-sets]`
- Bridge 3: `taxonomy=product_cat, apply_terms=[mosaics]`

On first read after alpha.21 deploys, `merge_with_defaults()` auto-derives the applicability gate for each — Bridge 1 will skip reverse-pulls on non-`available-sets` products, Bridge 3 will skip on non-`mosaics`. The orphan-row cascade scenario can't recur even without editor action.

### L-033 — new lesson learned

**Bridges targeting the same post type are NOT independent — reverse-pull cascades them all unless explicit applicability scope is declared.** Full retrospective in `LESSONS-LEARNED.md` covering: the cascade chronology from sync_log row 307→312, JE relations being passive (no auto-related on JE's side — confirmed via direct DB inspection of `wp_jet_post_types` ids 8 and 9), the failure mechanism, the architectural fix (applicability gate + split flags), prevention rules for future bridge design, and cross-references to L-020 / L-021 / L-023 / L-026.

### Migration

Zero editor action required. Bridges that already had `taxonomies[]` rules with `apply_terms` automatically get the right applicability gate on read. The split auto-create flags default the new reverse flag to OFF, which is the safe default. Existing bridges that genuinely depended on reverse auto-create (rare — almost no one does) need to explicitly enable `auto_create_source_when_unlinked` in the Flatten admin tab.

The orphan rows + relations from the BBHQ staging incident (available_sets row 8 + jet_rel_8 entry parent=8, child=662) are NOT auto-cleaned by this release. BBHQ is the test site so the user explicitly elected to clean up manually; production installs that hit the cascade before alpha.21 will need either manual DB cleanup or a future Phase 5 Utilities-tab "find orphan source rows" tool.

### Verification

1. **Existing bridges get auto-derived gates**: open Bridge 1 (or any bridge with `taxonomies[]` rules) in the Flatten admin tab. The "Applicability" row should show the orange "Auto-derived from taxonomies block" banner with `taxonomy=product_cat`, `terms=available-sets` (or whatever the bridge's apply_terms are), `match_by=slug`, `match_mode=any`, `applies_to=pull`. Save without changes — banner disappears next visit, gate persists explicitly.
2. **Cascade is blocked**: create a fresh Mosaic CCT row. Product gets created. Check `wp_jet_cct_available_sets_data` — no new row. Check `wp_jet_rel_8` — no new attachment. Check `wp_jedb_sync_log` — there should be a `STATUS_SKIPPED_NOT_APPLICABLE` row for Bridge 1's reverse-pull attempt with `context.gate.terms=["available-sets"]` and `context.target_terms=["mosaics"]`.
3. **Reverse-pull still works for IN-SCOPE products**: edit Product 662 (mosaics-category) via the iframe modal. Bridge 3's reverse-pull should fire successfully (applies because product is in mosaics). sync_log row direction=pull, status=success.
4. **Exclusion mode**: set a bridge's `match_mode=none` with terms `["mosaics"]`. Now that bridge pulls for products NOT in mosaics. Useful for "everything except" workflows.
5. **Forward gating opt-in**: set `applies_to=both` on Bridge 3. Manually re-categorize product 662 to `available-sets` only. Save the linked Mosaic — forward push should now skip with `STATUS_SKIPPED_NOT_APPLICABLE` (was previously allowed to force-recategorize via taxonomies rule).

## [0.6.0-alpha.20] — 2026-05-21

**Phase 4b modal-close fix follow-up — strip out the DOM-based WC error detection that was producing false positives and blocking the alpha.19 close-on-save path.**

Live staging diagnostics after alpha.19 ship revealed:

```
[JEDB parent received] jedb:wc-save-starting    (x3 — Done click + submit-button click + form submit)
[JEDB parent received] jedb:wc-save-error       ← KILLER
```

So Tier 1's `<script id="jedb-wc-iframe-close-handler">` did fire on the post-save page (meaning sessionStorage DID work this time — but unreliably, hence alpha.19's parent-side architecture is still correct), AND Tier 1 inspected the post-save DOM for `.notice-error`, AND it FOUND a match — but the match was a false positive.

The WC product edit page contains hidden template / React-scaffolding elements with the `.notice-error` class on it (probably from WC Admin's block-editor bits or a plugin's notice container that's empty but has the class). Same false positive would hit the parent's own `.notice-error` check in the load handler that alpha.19 added.

The cascade:
1. Tier 1 finds a `.notice-error` in DOM → posts `jedb:wc-save-error` to parent
2. Parent's alpha.19 listener sets `iframePendingSave = false` + hides saving overlay
3. iframe `load` event fires on parent
4. Parent's load handler sees `iframePendingSave === false` → returns without closing
5. Modal stays open. Editor is confused. Reverse-pull worked perfectly server-side but the visual feedback is broken.

### Fixed

- **Drop the parent's DOM-based `.notice-error` detection** in the iframe `load` handler. Always close the modal when `iframePendingSave === true` on post-save iframe load.
- **Ignore `jedb:wc-save-error`** in the parent's message listener. Tier 1's premature DOM scan can't be trusted; let the parent's `load` handler be the sole close authority.

### Tradeoff (documented)

In exchange for reliable close-on-save, we lose the validation-error-keep-modal-open feature. If WC genuinely rejects a product save (e.g., duplicate SKU error), the modal will still close, the parent CCT page will reload, and the editor will see the CCT row didn't update (because the WC save was rejected). They'd re-open the iframe and retry.

This is acceptable because:
- The variation-management workflow rarely triggers validation errors (the editor is mostly toggling variation existence + setting prices + uploading downloadable files — none of which have hard-to-meet validation rules).
- WC's redirect-after-save URL doesn't include a reliable "save failed" signal we can check JS-side.
- Server-side error detection via `wc_get_notices('error')` would work but requires a custom AJAX endpoint or transient bridge — defer to a future release if needed.

### Retained (still defensive backup)

- `JEDB_CCT_Screen_Variations_Panel::maybe_inject_wc_chrome_strip()` still emits Tier 1's inline `<script id="jedb-wc-iframe-close-handler">`. Its sessionStorage-bridged behavior is unchanged. When it DOES manage to fire successfully (which proved variable on staging), it sends `jedb:wc-modal-close` to the parent — the parent's listener still respects that as a defensive close request. No conflict with the new primary close path.
- The `jedb:wc-save-error` message is still emitted by Tier 1 (harmless — parent now ignores it).

### Migration

Zero. JS-only change.

### Verification

The save & auto-close happy path should now work reliably:
1. Open a CCT row → click "Open variations editor →"
2. Edit the product title to a marker
3. Click "Done · Save & return to CCT"
4. Within ~1 second of the post-save iframe redirect, the modal auto-closes + the parent CCT page reloads
5. The `Mosaic Name` field on the CCT now shows the new value (reverse-pull worked)

All four points 2-5 are now consistent with server-side state (which alpha.18 already confirmed working).

## [0.6.0-alpha.19] — 2026-05-21

**Phase 4b modal-close fix — move close-on-save state from iframe sessionStorage to the parent window. Resolves the staging report that the modal didn't auto-close after a successful product save.**

Staging diagnostics on Brick Builders HQ proved the alpha.18 reverse-pull engine works end-to-end: editing a mapped product field in the iframe modal, clicking Done, and saving DOES fire `woocommerce_process_product_meta` + `woocommerce_update_product` server-side, and Reverse_Flattener DOES write the changes back to the linked CCT row (verified with 4 new sync_log entries on the live test). The only failure mode left was visual — the modal kept showing the post-save WC product edit page (with full WP chrome, since the `?jedb_chrome=light` query param was stripped by WC's redirect — same L-029 quirk we hit with JE).

Browser-console inspection in the iframe context after the save revealed:

```
location.href:                                          'https://.../post.php?post=398&action=edit'
sessionStorage.getItem('jedb_close_wc_modal_on_load'):  null            ← THE BUG
!!document.querySelector('#jedb-wc-iframe-close-handler'): true         ← Tier 1 script IS in DOM
document.documentElement.style.visibility:              ''              ← Tier 1 bailed
```

Tier 1's close-on-save handler IS injected on the post-save iframe page (we confirmed the `<script id="jedb-wc-iframe-close-handler">` is in the DOM). But it reads `sessionStorage.getItem(FLAG_KEY) === '1'` and gets `null`, so it bails out. Something in WC's save flow (heartbeat? autosave? a plugin?) is wiping sessionStorage between the moment Tier 2's submit listener sets the flag (immediately before form POST) and the moment the post-save page reloads. The matching CCT-edit modal (L-027/L-029) doesn't hit this because JE's save flow doesn't touch sessionStorage.

### Fixed

- **Move close-on-save state to the parent window.** Don't bridge state through sessionStorage at all — the parent CCT page doesn't navigate during the save, so its in-memory state survives the round-trip cleanly.
- **Use the iframe's native `load` event as the close trigger.** Every iframe navigation fires `load` on the `<iframe>` element. The parent JS listens for it and closes the modal when `iframePendingSave === true`.
- **Validation-error detection** in the parent: when the iframe `load` fires after a save, inspect `iframe.contentDocument` for `.notice-error` / `.notice.notice-error` / `#message.error`. If found, WC rejected the save (invalid SKU, etc.) — keep the modal open + hide the saving overlay so the editor can fix the error.

### Added

In `assets/js/cct-screen-variations-panel.js`:

- Two new module-scope state variables — `iframePendingSave` (boolean) and `iframeLoadCount` (number for diagnostic).
- New `$modalIframe.on('load', …)` handler in `ensureModal()`. First load (count=1) is the initial open — skipped. Subsequent loads with `iframePendingSave === true` are post-save redirects — close the modal + reload the parent CCT page (unless `.notice-error` is found inside the iframe doc).
- `openModal()` resets `iframePendingSave = false` and `iframeLoadCount = 0` on every fresh open so old state doesn't leak between modal sessions.
- Message listener now arms `iframePendingSave = true` on `jedb:wc-save-starting` and resets it on `jedb:wc-save-error`. The existing `jedb:wc-modal-close` path is kept as a defensive fallback (in case Tier 1 ever does work in some browser).

### Retained as fallback (alpha.15+)

- `JEDB_CCT_Screen_Variations_Panel::maybe_inject_wc_chrome_strip()` still emits Tier 1's `<script id="jedb-wc-iframe-close-handler">` on every product edit page. Its behavior is unchanged. When sessionStorage IS reliable, Tier 1 fires and sends `jedb:wc-modal-close` to the parent — the new listener treats this as a defensive close request. Closing an already-closed modal is a no-op, so no conflict with the new primary close path.
- Tier 2's `setCloseFlag()` / `notifyParent()` still set sessionStorage + postMessage on submit. The sessionStorage write is now harmless extra work (the parent doesn't rely on it).

### Migration

Zero. JS-only change. The new `load` event handler runs alongside the existing message listener — both can fire safely.

### Verification

1. **Save & auto-close**: open a CCT row → click "Open variations editor →" → edit the product title to a marker → click "Done · Save & return to CCT". Within ~1 second of the post-save iframe redirect, the modal should auto-close + the CCT page should reload. The Mosaic Name field on the CCT should show the new value.
2. **Validation-error path**: open the modal → set an invalid value (e.g., a duplicate SKU on a product where another product already has that SKU) → click Done. WC's save rejects the request, the iframe reloads to the same page WITH a `.notice-error`. The parent detects the error, hides the saving overlay, keeps the modal open so the editor can fix the error.
3. **Cancel still works**: open the modal → make a change → click Cancel. Modal closes without saving; CCT does NOT reload.
4. **Non-save iframe navigation doesn't close**: open the modal → click a link inside the iframe that navigates elsewhere (e.g. a "View Product" link that opens within the iframe). Modal should NOT auto-close — `iframePendingSave` stays false unless the editor armed it via Done click or a submit button.
5. **Backward compatibility**: if Tier 1's sessionStorage handler ever DOES work (sometimes it might, depending on the WP/WC version + plugin set), it sends `jedb:wc-modal-close` which the parent handles as a defensive close — no double-close glitch.

## [0.6.0-alpha.18] — 2026-05-17

**Phase 4b iframe-save defensive guard + diagnostic logging — addresses staging report that WC product fields edited inside the iframe modal aren't reverse-flattening back to the CCT on bidirectional bridges.**

The staging report described editing a non-variation field on the linked WC product from inside the new CCT-screen variations panel iframe and finding that the change didn't sync back to the linked CCT row, even though the bridge has both `push_transform` and `pull_transform` configured on the affected mapping.

The reverse-pull (`product → CCT`) hangs on three independent gates, any of which can silently block: (a) the bridge ROW-level `direction` column must be `pull` or `bidirectional` for `Reverse_Flattener` to even register the `woocommerce_update_product` hook for it; (b) the post-meta `_jedb_bridge_direction_override` must not be `push` or `none`; (c) the post-meta `_jedb_bridge_locked` must not be set. All three of these are visible-by-design in the admin UI, but invisible in the modal context — the iframe's WC product edit page in `stripped` mode hides the JEDB meta box via CSS, and even in `light` mode the per-product override controls are only rendered when `meta_box.show_advanced=true`. The actual root cause needs sync_log inspection to confirm, but the defensive guard below eliminates one entire class of potential blockers preventatively, AND the new debug logging makes the next staging cycle self-diagnostic.

### Added

- **`JEDB_Woo_Product_Meta_Box::is_save_from_jedb_iframe()`** — detects whether the current WC product save POST originates from inside our CCT-screen variations iframe modal. Detection: HTTP `Referer` header carries `jedb_chrome=stripped` or `jedb_chrome=light` query parameter. Same-origin iframe so Referer is reliably present.
- **iframe-aware skip in `handle_save()`** — when `is_save_from_jedb_iframe()` returns true, SKIP the per-product `_jedb_bridge_locked` + `_jedb_bridge_direction_override` writes. Rationale: those overrides are authored on a direct (non-iframe) product edit visit and should never be touched by an iframe-context save that the editor opened to manage variations. Today this is a defensive no-op (the meta box's lock + direction radios only render when `meta_box.show_advanced=true`, otherwise they aren't in the DOM and aren't in `$_POST`) — but if the staging report's bridge ever flips show_advanced on AND has a stale radio selection, the iframe save would silently stomp the override. This guard makes that impossible.
- **`Reverse_Flattener::run_for_post_event()`** now emits an `info`-level `jedb_log` call on every hook firing, with post_id + bridge_count + bridge_ids in context. Lets editors confirm "did the reverse-flatten hook fire at all?" from `jedb-debug.log` alone, without DB access.
- **`Reverse_Flattener::apply_bridge()`** now emits an `info`-level `jedb_log` call at entry showing bridge_id + source_target + target_target + post_id + origin + direction. The `direction` field exposes the bridge ROW's direction column — invaluable for diagnosing "is this bridge actually bidirectional or did it default to push?"
- **`Reverse_Flattener::log_status()`** now ALSO emits to `jedb_log` (in addition to the existing sync_log DB write). Level scales with status — `error` for `STATUS_ERRORED`, `info` for `STATUS_SUCCESS` and `STATUS_NOOP`, `warn` for every `STATUS_SKIPPED_*`. Means the file log carries the entire reverse-flatten lifecycle (hook fired → bridge attempted → final status + message) for every save, eliminating the previous gap where only the DB had outcome info.

### Diagnostic procedure (use when sync isn't behaving)

The most likely cause of "edit in iframe doesn't sync back" is the bridge's `direction` column being `push` instead of `bidirectional`. The mapping-level `pull_transform` is configured separately from the bridge-level `direction` field — having `pull_transform` on every mapping does NOT make the bridge bidirectional. Verify with this SQL:

```sql
SELECT id, label, source_target, target_target, direction, enabled
FROM wp_jedb_flatten_configs
WHERE target_target = 'posts::product';
```

If the row's `direction` column is `push`, the reverse-pull is NOT registered for that bridge — `Reverse_Flattener::register_post_save_hooks()` filters out `push`-only bridges at hook registration time. Fix: open the bridge in the Flatten admin tab, change the Direction radio to "Bidirectional", save.

Second most likely cause: per-product overrides on the test product blocking the pull. Check post meta:

```sql
SELECT post_id, meta_key, meta_value
FROM wp_postmeta
WHERE post_id = {your_product_id}
  AND meta_key IN ( '_jedb_bridge_locked', '_jedb_bridge_direction_override' );
```

If `_jedb_bridge_direction_override` is `push` or `none`, OR `_jedb_bridge_locked` is `1`, the reverse-pull is blocked for THAT specific product. Fix: open the product in a direct (non-iframe) admin visit, expand the JEDB meta box's Advanced Details, set the radio to "None (use bridge default)" and uncheck the lock. (Or `DELETE FROM wp_postmeta WHERE post_id = X AND meta_key IN (…)`.)

Third: actual sync_log inspection. After saving the iframe modal, run:

```sql
SELECT id, created_at, direction, source_id, target_id, origin, status, message
FROM wp_jedb_sync_log
WHERE target_id = '{your_product_id}'
ORDER BY id DESC
LIMIT 10;
```

The most recent rows should include a `pull` direction entry. Status tells you exactly what happened: `success` (wrote N fields), `noop` (no diff), `skipped_locked` (per_product_lock OR cascade), `skipped_direction_override` (override blocked it), `skipped_no_target` (couldn't resolve source CCT row), `errored` (adapter / source-update failure with message). For pure file-log diagnosis post-alpha.18, the same info lands in `jedb-debug.log` with the `[Reverse_Flattener] *` prefix.

### Migration

Zero. The defensive guard in `handle_save()` is a skip-on-iframe-only branch; non-iframe direct admin visits to product edit pages behave exactly as before. The diagnostic logging is additive — no behavior change, just new lines in `jedb-debug.log` whenever a reverse-flatten hook fires.

### Verification

1. **Confirm the hook fires**: open a CCT row, click "Open variations editor →", edit a mapped product field (e.g., the product title bound to `mosaic_name`), click Done. Check `jedb-debug.log` for `[Reverse_Flattener] hook fired` followed by `[Reverse_Flattener] apply_bridge entry` for your bridge. If those entries DON'T appear, the hook isn't firing — verify the bridge's `direction` column is `bidirectional` via the SQL query above.
2. **Confirm the outcome**: same log should contain `[Reverse_Flattener] success` (or `noop` if values already matched, or `skipped_*` indicating a guard rejected). The `message` field tells you exactly what happened.
3. **Direct (non-iframe) edits unaffected**: open the same product in a direct admin visit (no iframe), expand Advanced Details on the JEDB meta box (set `meta_box.show_advanced=true` on the bridge first), set direction override to "Push only", save. Verify `_jedb_bridge_direction_override` post meta = `push` via the post meta query.
4. **Iframe save no longer touches override**: open the same product via the iframe, edit a mapped field, click Done. Verify `_jedb_bridge_direction_override` is STILL `push` (unchanged by the iframe save). Then change it back to bidirectional via direct admin visit and confirm subsequent iframe saves leave it alone.

## [0.6.0-alpha.17] — 2026-05-17

**Phase 4b CSS patch — kill WC admin layout's right-edge activity panel + Freemius SDK marketing notices inside the chrome-stripped modal. Affects both `stripped` and `light` modes.**

The alpha.16 notice-killer caught most admin notices via `.notice` family classes, but two specific cases survived and created the right-edge grey gutter clipping the Publish meta box that the staging report flagged (with the WC variations editor open via the modal):

1. **`.woocommerce-layout__activity-panel-wrapper`** — WC's new wc-admin React layer injects this element + a couple of siblings even on legacy product edit pages, and the layout JS reserves a right gutter on `#wpcontent` for the activity panel. Hiding the wrapper alone doesn't close the gutter — we also need to reset `#wpcontent { margin-right: 0 !important; }`.
2. **`.fs-notice`** — Freemius SDK plugin marketing notices (the staging "Deployer for Git — 14-day free trial" promotion is the canonical example). The element carries `.updated`, which alpha.16's CSS already targets, but Freemius's promotional notices use inline styles + late injection that win against generic `.updated { display: none !important; }`. Target the `.fs-*` family directly.

### Added

WC admin layout selectors in the Tier 2 base layer (applies in both `stripped` and `light` modes):

- `.woocommerce-layout__activity-panel-wrapper`
- `.woocommerce-layout__activity-panel`
- `.woocommerce-layout__header`
- `.woocommerce-layout__notice-list`
- `[class*="woocommerce-layout__activity"]`
- `[class*="woocommerce-layout__header"]`

Freemius selectors (catches any Freemius-monetized plugin's marketing notices, not just Deployer for Git):

- `.fs-notice`
- `.fs-sticky`
- `[class^="fs-notice"]`
- `[class*=" fs-notice"]`

Layout reset:

- `#wpcontent, #wpbody-content { margin-right: 0 !important; }` — closes the right gutter the WC layout JS reserves for the activity panel.

### Migration

Zero. CSS-only patch — nothing changes in saved config, behavior, or admin UI surfaces. The selectors only activate inside the iframe modal when `?jedb_chrome=stripped` or `?jedb_chrome=light` is in the URL.

### Verification

1. Open a CCT row with the variations panel enabled. Click "Open variations editor →".
2. Inside the modal, you should see NO right-edge grey gutter and NO floating "Activity" widget. The Publish meta box (in `stripped` mode) should fully reach the right edge of the modal frame with the Update button completely visible.
3. The Freemius "Deployer for Git" promotional notice should be hidden (along with similar notices from any other Freemius-monetized plugin you have installed).
4. Same result in `show_full_page=true` (`light`) mode — the WC layout chrome + Freemius notices are hidden but everything else stays.
5. Direct (non-iframe) admin visits to product edit pages: the WC activity panel + Freemius notices are STILL visible (these selectors only activate on the chrome-stripped iframe URL).

## [0.6.0-alpha.16] — 2026-05-17

**Phase 4b polish — three staging-report fixes on top of alpha.15's chrome strip + new per-bridge "Show full product editor" mode for admin UX discretion.**

Staging the alpha.15 chrome strip surfaced three issues:

1. **Right-side overlay clipping Publish meta box.** WP admin notices, page title (`Edit Product`), and plugin-injected floating elements (Deployer for Git badge, Jet Woo Builder data-update notice, Yoast SEO Activity panel, generic `.notice-dismiss` floaters) were leaking through the chrome strip and overlapping the Publish meta box's Update button on the right edge of the iframe.
2. **Modal didn't auto-close after Done button save.** Clicking the Done button in the top bar would submit WC's product form, but on the post-save reload the modal stayed open instead of auto-closing — leaving a WP-chromed product edit page inside the iframe that the editor had to manually close.
3. **Admin discretion needed.** alpha.15 always stripped down to Product Data + Submit. Some bridges may want editors to access the full WC product editor (title, content, all meta boxes — SEO, attributes, featured image) inside the modal, not just the variations work.

This release fixes all three.

### Added

- **`cct_screen.wc_variations.show_full_page` flag** on the flatten config schema. Default `false` (current behavior — strip to Product Data + Submit only). When `true`, the iframe URL gets `?jedb_chrome=light` instead of `?jedb_chrome=stripped`, and Tier 2 skips the strip-to-Product-Data CSS while keeping everything else (WP chrome hidden, notices killed, page title hidden, Done/Cancel bar, form-submit interceptor, close-on-save).
- **New "Show full product editor" checkbox** in the Flatten admin tab's "Enable WooCommerce Variations" section. Sits below the auto-force-variable-type checkbox. Helper text explains the two modes — focused (default) vs. full editor — and notes that WP admin chrome is hidden in both.
- **`flatten-admin.js`** rounds-trips the new field via `cfg.cct_screen.wc_variations.show_full_page` in `buildConfig` + change listener.

### Fixed (Issue 1: right-side overlay)

- **Aggressive notice-killer CSS** in Tier 2 base layer (applies to both `stripped` and `light` modes): hides `.notice`, `.notice-info`, `.notice-success`, `.notice-warning`, `.notice-error`, `.updated`, `.update-nag`, `.error`, `div.notice`, `div[class*="-notice"]`, `.notice-dismiss`, plus `.wrap > .notice`, `.wrap > .updated`, `.wrap > .error`, `.wrap > .update-nag`, and the same set under `#wpbody-content >`. Kills the Deployer for Git badge + Jet Woo Builder Data Update banner + any other plugin-injected admin notices that leak into the modal.
- **Stronger page-title hide**: `.wrap h1`, `.wrap > h1.wp-heading-inline`, `.wrap > .page-title-action`, `.wrap > .wp-header-end`, `.wrap > hr.wp-header-end` (was only the `.wrap > h1.wp-heading-inline` variant in alpha.15 — some themes nest the title differently).
- Editors can still dismiss legitimate admin notices on a direct (non-iframe) admin visit — the kill only applies inside the focused modal context (gated on `?jedb_chrome=stripped` or `light`).

### Fixed (Issue 2: modal didn't auto-close after Done save)

Belt-and-suspenders coverage on the close-on-save flag — three independent triggers:

- **`submit` event on `form#post`** with capture phase (`addEventListener('submit', ..., true)`) so we fire even if WC's own submit handlers call `stopPropagation()` on the bubbled event.
- **`click` event on each submit button** (`#publish`, `#save-post`, `input[name="save"]`, `input[type="submit"][name="save"]`) — fires BEFORE the form submit event, catching cases where WP/WC's onclick handlers call `form.submit()` (method, doesn't fire submit event) instead of letting the click bubble normally.
- **`beforeunload` on the window** — last-ditch fallback to catch programmatic form submissions that don't fire either submit OR click events. Guarded by a `saveArmed` boolean flag — only sets the close flag if the editor has actually intent-signalled a save (clicked Done, or clicked a submit button, or triggered a submit event). Otherwise navigation from the iframe to a non-product page (clicking a "Linked Products" link, etc.) would incorrectly auto-close the modal.

This trio guarantees the close flag is set in sessionStorage before WC's redirect-after-save, which is what Tier 1's close-on-save handler reads on the post-save page reload to trigger the modal close + parent reload.

- **Done button fallback chain** now also includes `wcForm.requestSubmit()` (modern, fires submit event) before falling back to `wcForm.submit()` (legacy, does NOT fire submit event). The button-click handlers above catch the legacy fallback path too.

### Fixed (Issue 1 follow-on, bonus)

- The user-visible "Activity / Dismiss" floating widget on the right edge was a `.notice-dismiss` button rendered inside a plugin's `.notice` — killed by the new notice-killer CSS.

### Changed

- **PHP iframe URL builder** (`build_panels_for()`) now picks `?jedb_chrome=light` or `?jedb_chrome=stripped` based on the per-bridge `show_full_page` flag instead of always `stripped`.
- **`maybe_inject_wc_chrome_strip()`** Tier 2 gate broadened from `'stripped' !== $chrome` to `! in_array( $chrome, ['stripped', 'light'], true )`. Tier 2 CSS now split into a base layer (always applied in any chrome mode — WP chrome hide + notice killer + page title hide + top bar reservation + bar styling) and a strict layer (only applied in `stripped` mode — `#post-body-content` hide + `.postbox:not(...)` filter).
- The Tier 2 method docblock now documents the two-mode design + the notice-killer rationale + the alpha.16 staging report context.

### Migration

Zero. Existing alpha.15 bridges that have `cct_screen.wc_variations.enabled = true` continue to use the focused stripped mode (`show_full_page` defaults to false via `merge_with_defaults()`). Admins who want the full-editor mode tick the new checkbox on their bridge and re-save.

### Verification

1. **Notice killer**: open a CCT row with the variations panel enabled. Click "Open variations editor →". Inside the modal, you should see NO admin notices regardless of how many your install has (Deployer for Git, Jet Woo Builder Data Update, Yoast SEO Activity, etc. — all hidden). The Publish meta box should be fully visible on the right with no clipping.
2. **Page title gone**: the `Edit Product` title heading should NOT appear above the Product Data box.
3. **Done button auto-close**: edit a variation, click the top-bar Done button. The Saving overlay should appear, then the modal should auto-close + the CCT page should reload. No more manual "click × to close" required.
4. **Save via WC's Update button**: same outcome as #3 — Done is just a shortcut for clicking WC's Update.
5. **Cancel still works**: click Cancel. Modal closes without saving. CCT page does NOT reload.
6. **Non-save navigation doesn't close**: open the modal, click a link inside the iframe that navigates somewhere (e.g., "Linked Products" cross-sell picker if applicable). The modal should NOT auto-close — the `saveArmed` flag prevents that.
7. **`show_full_page=false` (default)**: stripped mode renders only Product Data + Submit, same as alpha.15 — confirm no regression.
8. **`show_full_page=true`**: tick the checkbox, save the bridge, reopen the modal. You should see the FULL WC product editor (title editor, content editor, ALL meta boxes including SEO, Attributes, Featured Image, Linked Products, etc.) inside the modal — only WP admin chrome (admin bar, sidebar, footer, notices, page title) hidden. The Done/Cancel top bar still shows. Save/close behavior identical to stripped mode.

## [0.6.0-alpha.15] — 2026-05-17

**Phase 4b Phase B shipped — chrome-strip the WC product edit iframe to Product Data + Submit only; auto-close on save; D3 auto-force variable product type. Phase 4b is now complete.**

This release lands the visual + behavioral half of the iframe-flip pattern that Phase A scaffolded. The iframe modal launched from the CCT-edit-screen variations panel now renders only the meta boxes editors actually need (`#woocommerce-product-data` for variations management and `#submitdiv` for save controls), with everything else from WP/WC's product edit chrome hidden. A dark top bar with Done + Cancel buttons overlays. The form-submit interceptor + sessionStorage close flag close the modal automatically after WC's post-save redirect — same L-027/L-029 mechanism used in reverse for the CCT-edit iframe modal, now mirrored for the WC-product-edit iframe modal.

### Added (Phase B chrome strip)

- **`JEDB_CCT_Screen_Variations_Panel::maybe_inject_wc_chrome_strip()`** hooked on `admin_head` for `post.php?post=*` where `post_type=product`. Symmetric mirror of `JEDB_Woo_Product_Meta_Box::maybe_inject_cct_chrome_strip()` (alpha.6 / L-027). Same two-tier structure:
  - **Tier 1 (always on product edit pages)**: iframe-aware close-on-save handler. Reads sessionStorage `jedb_close_wc_modal_on_load` flag (deliberately distinct from the CCT-side `jedb_close_modal_on_load` key to prevent cross-contamination if both modal subsystems are ever used in sequence). Hides the page immediately on flag-hit to avoid flash of WP chrome, then on DOMContentLoaded either postMessages parent `jedb:wc-modal-close` (clean save → modal closes + CCT page reloads) or postMessages parent `jedb:wc-save-error` and un-hides (validation failure → editor sees error, modal stays open).
  - **Tier 2 (only when `?jedb_chrome=stripped`)**: the visual chrome strip — hides `#wpadminbar` / `#adminmenu*` / `#wpfooter` / `#screen-meta*` / `.wrap > h1.wp-heading-inline` / `.wrap > .page-title-action` / `.wrap > .wp-header-end` / `#post-body-content` (title + permalink + editor) + ALL `.postbox` except `#woocommerce-product-data` and `#submitdiv`. Forces the surviving boxes open + visible even if user_meta had them collapsed. Removes drag handles on surviving boxes so editors can't fold them down to nothing. Reserves 56px at the top for the fixed Done bar. Adds the `.jedb-wc-frame-bar` dark top bar with title text + Cancel + Done buttons (mirrors `.jedb-cct-frame-bar` visual design). Adds the WC `form#post` submit interceptor + Done button click handler that prefer-clicks WC's `#publish` / `#save-post` button so all WC submit handlers fire (variation save, attribute serialization, downloadable file processing, etc.) before form submission.
- **D3 auto-force variable product type**: when the bridge has `cct_screen.wc_variations.auto_force_variable_type=true`, the iframe URL includes `&jedb_force_variable=1`. The chrome-strip script reads the URL param via PHP server-side gate and auto-triggers `jQuery('#product-type').val('variable').trigger('change')` on DOMContentLoaded. Skipped silently if jQuery isn't loaded or the product type is already `variable`. Off by default; admin opts in per bridge in the Flatten admin tab.
- **D4 explicit non-action**: NOT auto-jumping to the Variations sub-tab inside `#woocommerce-product-data`. Editors may need to configure attributes first (General → Attributes tab) before they can add variations, so we land on whatever WC's default Product Data tab is. Documented in the method docblock so future maintainers understand the deliberate choice.

### Changed (Phase A forward-compat → Phase B production)

- **`assets/js/cct-screen-variations-panel.js`** postMessage listener renamed from `jedb:cct-*` to `jedb:wc-*`. The Phase A listener was forward-wired for these messages; Phase B's chrome-strip script now emits them. Renaming clarifies that these messages flow from the WC iframe to the CCT page (the opposite direction from the existing `jedb:cct-*` traffic that flows from the CCT iframe to the WC page per L-027).
- **`includes/admin/class-cct-screen-variations-panel.php` `hooks()`** now registers `add_action( 'admin_head', 'maybe_inject_wc_chrome_strip' )` in addition to the existing `admin_enqueue_scripts` hook.
- **`build_panels_for()`** appends `&jedb_force_variable=1` to the iframe URL when the bridge has `auto_force_variable_type=true`. The chrome-strip script reads this param.

### Phase 4b completion summary

With Phase B shipped, Phase 4b is complete:
- Phase A (alpha.14) shipped the iframe-flip panel + modal + form-poll injection + D1/R3/D5/D6 plumbing.
- Phase B (alpha.15) shipped the chrome strip + Done/Cancel top bar + form-submit interceptor + auto-close on save + D3 auto-force variable product type + D4 explicit non-action.

The entire alpha.13 declarative variations[] reconciler has been retired, with WC variation management fully delegated to WC's native UI accessed via a focused iframe modal from the CCT edit screen. Editors get all WC variation features (per-variation images, stock, shipping class, menu_order, attributes, downloads) for free — zero schema bloat in our plugin, zero per-variation behavior to maintain.

### Verification

The Phase A 8-step recipe still applies for the underlying behavior. Phase B adds these checks on top:

1. **Chrome strip applies**: open a Mosaic CCT row with the new panel enabled; click "Open variations editor →". Inside the modal iframe, you should see ONLY the dark Done/Cancel bar at the top and the Product Data meta box + Update meta box. No admin bar, no left sidebar, no page title, no permalink editor, no other meta boxes (SEO plugin, etc.).
2. **D3 auto-force**: tick "Auto-force variable type" in the Flatten admin tab on the bridge. Save. Open a Mosaic CCT row whose linked product is a Simple product (not yet variable). Click "Open variations editor →". On iframe load, the Product Data dropdown should auto-flip to "Variable product" and the WC tabs should re-render with the Variations sub-tab visible.
3. **Save & close round-trip**: inside the iframe, add a variation using WC's UI. Click WC's blue "Update" button (or our top-bar Done button — both should do the same thing). The "Saving variations…" overlay appears on the parent CCT page (Phase A's postMessage listener finally has a sender). After WC's redirect, the iframe page reloads, Tier 1 detects the sessionStorage flag, postMessages parent, modal auto-closes, CCT edit page reloads. Editor sees the variations editor close cleanly without manual intervention.
4. **Cancel discards**: open the modal, make a change (e.g. add a variation), click Cancel. Modal closes WITHOUT WC saving. Open it again — your changes should be gone (because they never POSTed). The CCT page does NOT reload (no parent-side reload on cancel).
5. **Validation error keeps modal open**: inside the iframe, set an invalid SKU (e.g. a duplicate). Click Update. The iframe reloads to the same page WITH a `.notice-error`. Tier 1 detects the error, un-hides the page (so editor can see what failed), postMessages `jedb:wc-save-error` → parent hides the saving overlay. Modal stays open so editor can fix and retry.
6. **Direct (non-iframe) visits unaffected**: navigate directly to a product edit page outside the modal. The chrome strip should NOT apply (you should see the full normal WC product edit UI with admin bar, sidebar, everything). Tier 1's `window.top === window.self` guard prevents close-on-save misfires.
7. **D5 nested-iframe prevention still works**: from a WC product edit page, open the Bridge meta box's modal (the L-027 CCT-edit modal). Inside that modal, the CCT edit page renders in an iframe. The variations panel button on the CCT edit page should remain hidden (D5).
8. **Flag isolation**: confirm the two modal subsystems use distinct sessionStorage keys (`jedb_close_modal_on_load` for CCT-side, `jedb_close_wc_modal_on_load` for WC-side) by inspecting browser sessionStorage during use of each. They should never appear together.

## [0.6.0-alpha.14] — 2026-05-17

**Phase 4b reset shipped — alpha.13 declarative variations[] reconciler retired; new iframe-flip panel on the JE CCT edit screen launches WC's native product edit page in a modal (Phase A — no chrome strip yet). L-032.**

After staging alpha.13's `variations[]` reconciler end-to-end, the user identified that the configuration surface scaled poorly with variation complexity AND covered only a small subset of WC's per-variation feature set (no per-variation images, no stock management, no shipping class, no menu_order, no per-variation custom meta beyond the bridge's hardcoded fields). The symmetric application of the L-027 iframe-flip pattern — already proven for CCT editing from the WC product side — delegates 100% of variation UI to WC's native admin and gets all WC features for free with no schema bloat. L-032 documents the full architectural retrospective; BUILD-PLAN §4.7 was rewritten in the preceding docs-only commit; this release lands the code changes.

### Removed (alpha.13 reconciler retirement)

- **`includes/flatten/class-variation-reconciler.php`** deleted entirely (~480 lines).
- **`JEDB_Variation_Reconciler::instance()` registration + require_once** from `JEDB_Plugin::load_core()`.
- **Reconciler invocation + Path 3 `variations_changed` / `variations_only` success branch** from `JEDB_Flattener::apply_bridge()`. Sync log `context_json.variations` field no longer emitted.
- **`default_variation()` factory** + **`variations[]` key on `default_config_json()`** + **`merge_with_defaults()` variations handling** from `JEDB_Flatten_Config_Manager`. Note: existing saved bridges that have a `variations[]` array in their config_json keep that field as inert data (we don't strip it on read so future custom-automation hooks via the retained Target_Woo_Variation helpers can still find it).
- **Variations section** (Phase A admin UI for the alpha.13 reconciler) from `templates/admin/tab-flatten.php`.
- **`makeVariationRow()` / `readVariationsFromDom()` / `renderVariations()` + add-row handler + `cfg.variations = readVariationsFromDom()` in `buildConfig`** from `assets/js/flatten-admin.js`. Plus `initial_variations` + `variation_default` from the bootstrap.
- **"Variations managed by this bridge" subsection** from `templates/admin/meta-box-bridge.php` + the `$variations_status` data plumbing in `JEDB_Woo_Product_Meta_Box::render_linked_panel()`.
- **`.jedb-bridge-variations-status` + per-state border-left CSS** from `assets/css/bridge-meta-box.css`.

### Retained as deprecated defensive surface

Docblocks updated in the previous docs-only commit:
- `JEDB_Target_Woo_Variation::find_managed_variation()`
- `JEDB_Target_Woo_Variation::create_for_bridge()`
- `JEDB_Target_Woo_Variation::META_VARIATION_SLUG` / `META_VARIATION_BRIDGE` constants

These methods have ZERO production callers as of alpha.14. They're kept in the codebase for any future automation hook (e.g. an admin "fix orphaned managed variations" tool). New code should NOT wire through them without revisiting L-032.

### Added (alpha.14 iframe-flip — Phase A)

- **`cct_screen.wc_variations` block on the flatten config schema** with `enabled` / `title` / `auto_force_variable_type` (D3 admin opt-in). Default factories `default_cct_screen()` + `default_wc_variations_panel()`. `merge_with_defaults()` back-compat handling so existing alpha.13 bridges read the new defaults transparently.
- **New "Enable WooCommerce Variations" section in the Flatten admin tab** (`templates/admin/tab-flatten.php`). Hidden via D6 when `target_target !== 'posts::product'`. Three controls: enabled checkbox, panel title text input, auto-force-variable-type checkbox. JS round-trip in `buildConfig` via `cfg.cct_screen.wc_variations = ...`.
- **New `JEDB_CCT_Screen_Variations_Panel` class** in `includes/admin/class-cct-screen-variations-panel.php`. Hooks `admin_enqueue_scripts` on JE CCT edit pages (`?page=jet-cct-{slug}&cct_action=edit&item_id={id}`). Walks enabled bridges where `source_target = cct::{current_slug}` AND `cct_screen.wc_variations.enabled = true` AND `target_target = posts::product`. For each match, resolves the linked WC product post ID via `JEDB_Flattener::resolve_target_id()` (re-using engine path), packages config (`bridge_id` / `title` / `auto_force_variable_type` / `target_post_id` / `edit_url` / `return_url`), and `wp_localize_script`s the JS bootstrap. Source data read via `Target_CCT::get_fresh()` (L-030 freshness pattern). Registered in `JEDB_Plugin::load_admin()` alongside the Bridge meta box, gated on `class_exists('WooCommerce')`.
- **New `assets/js/cct-screen-variations-panel.js`** — waits for JE's CCT save form (same form-polling pattern as the relation injector), then injects one panel per matching bridge as a sibling of the submit button. Each panel: configured title, "After initial save you can add variations to this post." helper text, "Open variations editor →" button. Button click opens a modal iframe pointed at the linked WC product's edit URL with `?jedb_chrome=stripped&jedb_return=...`. Modal mechanics mirror `bridge-meta-box.js`'s L-027/L-029 implementation: overlay, close button, ESC key, saving overlay, postMessage listener for `jedb:cct-save-starting` / `jedb:cct-save-error` / `jedb:cct-modal-close`. D1/R3: when any panel has a linked product, `.jedb-relations-block` is hidden (the relations picker is only useful for unlinked rows). D5: when this script runs inside an iframe context (`window.top !== window.self`), the panel buttons are hidden silently to prevent nested-iframe chaos.
- **New `assets/css/cct-screen-variations-panel.css`** — panel chrome (`.jedb-cct-screen-variations-panel` with subtle blue left-border, similar visual weight to WC's own meta boxes) + modal overlay styles (`.jedb-cctv-modal-overlay` / `-frame` / `-iframe` / `-close` / `-saving` — mirrors the `.jedb-cct-modal-*` styles in bridge-meta-box.css since the two modal subsystems live on different admin pages and never collide in the DOM).
- **Iframe URL includes `?jedb_chrome=stripped`** for forward-compat with the alpha.15 chrome-strip CSS + Done bar. In Phase A no chrome strip code intercepts the param, so the iframe just renders the full WC product edit page. The Phase A workflow still works — editor uses WC's UI inside the iframe, clicks Update (WC's native save button), iframe reloads to the post-save page, the existing L-027 Tier 1 close-on-save handler (which already watches `jet-cct-*` page renders) does NOT fire because this is a `post.php?post_type=product` page — so the editor needs to click the modal's close button (× or Esc) when done. Phase B alpha.15 will add the chrome-strip script + Tier 1 close-on-save handler for `post.php?post_type=product` so the modal auto-closes on save just like the CCT modal does.

### Behavior change

- Bridges with `cct_screen.wc_variations.enabled=true` now see a new panel on their source CCT's edit screen. Bridges that haven't enabled the new flag see no behavior change (the panel is opt-in per bridge).
- Bridges that previously had `variations[]` rules in their config_json no longer have those rules applied on push (the reconciler is gone). The saved data stays in the config but is inert. To re-engage variation management, enable the new panel and use WC's native UI.
- Sync log `context_json` no longer carries a `variations` block. Sync logs for previous pushes that recorded the block are unaffected; new pushes simply don't add the field.

### Migration

Zero migration. Existing bridges read cleanly through `merge_with_defaults()` (the new `cct_screen.wc_variations` block fills in with `enabled=false` so nothing happens until the editor opts in per bridge). Existing saved `variations[]` arrays in bridge config_json stay in storage but aren't read by the engine.

### Verification

1. **Saved bridge data intact**: open an alpha.13-era bridge in the Flatten tab. Confirm all the non-variation config (mappings, taxonomies, link_via, etc.) saves and loads as before. The old Variations section is gone; the new "Enable WooCommerce Variations" section appears for Woo-product-target bridges.
2. **Enable the new panel**: tick "Enabled" in the new section on a Mosaics → Product bridge. Save the bridge.
3. **Open a Mosaic CCT row** (`wp-admin/admin.php?page=jet-cct-mosaics_data&cct_action=edit&item_id={id}`). Beneath the JE save button, a new panel should appear with the configured title (or "WooCommerce Variations" if blank) and an "Open variations editor →" button.
4. **Click the button**: modal opens with the linked WC product's edit page rendered fully (Phase A — no chrome strip yet). Navigate to the Variations tab in WC's native UI. Add / edit / remove variations using WC's UX. Click WC's Update button to save.
5. **Close the modal** via × or Esc (Phase A — no auto-close on save yet; that lands in alpha.15). The CCT edit page is still visible underneath.
6. **D1 / R3 check**: on a CCT row that IS linked, the `.jedb-relations-block` picker is hidden. On a CCT row that ISN'T linked, the relations picker is visible AND the variations panel button is disabled (showing "No linked product found.") because there's nothing to iframe into.
7. **D5 check**: open the same CCT row from the WC product's Bridge meta box modal (the L-027 modal). Inside that modal, the CCT edit page is rendered in an iframe. The variations panel should appear but the "Open variations editor →" button should be hidden (D5 — prevents nested-iframe).
8. **D6 check**: on a bridge with `target_target = posts::page` (or any non-product target), the Flatten admin tab's "Enable WooCommerce Variations" section should be hidden entirely.

### What ships in alpha.15 (Phase B)

- CSS that hides everything except `#woocommerce-product-data` + `#submitdiv` when the iframe loads with `?jedb_chrome=stripped`.
- Chrome-strip script + Done/Cancel top bar mirroring the L-027 CCT-edit chrome strip, just targeting the WC product edit page (`post.php?post_type=product`).
- Form-submit interceptor on WC's `form#post` that sets the sessionStorage close flag → existing Tier 1 handler on the post-save page reload → postMessage parent → modal auto-closes + CCT page reloads.
- D3 implementation: when `cct_screen.wc_variations.auto_force_variable_type=true` on the bridge, auto-trigger `jQuery('#product-type').val('variable').trigger('change')` on DOMContentLoaded.
- D4 confirmed: NOT auto-jumping to the Variations sub-tab inside Product Data — editor may need to configure attributes first.

## [0.6.0-alpha.13] — 2026-05-17 (RETIRED in alpha.14 per L-032)

> **Heads-up:** the declarative variations[] reconciler described in this entry was retired in alpha.14. The schema field, the reconciler class, the Flatten admin tab section, and the supporting JS / CSS / meta box UI are all GONE in alpha.14+. Editors looking for variation management should refer to the alpha.14 entry above — variations are now managed via WC's native UI launched from a `cct_screen.wc_variations` panel on the CCT edit screen. The entry below is preserved as archaeology; the implementation no longer exists but the lessons learned (L-032 + the original L-015 reasoning) do.

**Phase 4b — Variation reconciliation engine shipped (§4.7 / L-015).**

A bridge can now manage WooCommerce product variations on its linked parent product from a `variations[]` array on the flatten config. On every push, the reconciler walks each variation entry, evaluates its `show_when` against the source CCT row, and creates / updates / soft-deletes the corresponding variation. The BBHQ "Has Instructions PDF" use case is the canonical driver — set `has_instructions_pdf=true` on a Mosaic CCT row and the bridge spawns the "Includes Instructions PDF" variation on the linked product, populates its price + downloadable file from configured CCT fields, and tears it back down when toggled off. Push-only for this release; PULL deferred to a follow-up.

### Added

- **Flatten config schema — `variations[]` block.** New `default_variation()` factory returns the canonical entry shape: `slug` (required, used as the `_jedb_variation_slug` post meta key on managed variations), `label`, `show_when` (DSL expression, same syntax as the bridge condition field), `price_field` (source CCT field name whose value populates the variation's `regular_price`), `downloads[]` (source CCT field names whose values populate WC downloadable files — accepts attachment IDs or URLs), `attributes[]` (map of `attribute_slug => value` for WC variation identification; falls back to a plugin-managed `pa_jedb_variant` slot when empty), `enabled`, `note`. `merge_with_defaults()` deep-merges each rule against the default shape — existing alpha.3–alpha.12 bridges read cleanly with an empty `variations[]` array (no migration needed).
- **`Target_Woo_Variation` extensions:**
  - `find_managed_variation($parent_post_id, $bridge_id, $variation_slug)` — reverse-lookup via direct SQL on `wp_postmeta` + `wp_posts`. Returns the variation post ID or 0. Cheap; no `WC_Product_Variation` instantiation just to read meta.
  - `create_for_bridge($parent_post_id, $bridge_id, $variation_slug, $fields)` — wrapper around `create()` that forces `parent_id`, stamps `_jedb_variation_slug` + `_jedb_variation_bridge` post meta (the management tracking keys), and defaults `status=publish` if the caller didn't specify.
- **`JEDB_Variation_Reconciler` class** in `includes/flatten/class-variation-reconciler.php`. Singleton, registered in `JEDB_Plugin::load_core()`. The `reconcile()` method:
  - Bails fast on non-Woo targets (`target_target !== 'posts::product'`), bridges with empty `variations[]`, and environments where `WC_Product_Variation` isn't loaded.
  - Walks each variation rule. Sanitizes the slug; skips entries with empty slugs or `enabled=false`. Evaluates `show_when` via the existing `JEDB_Condition_Evaluator` with the same `$context` shape (`source`, `target`, `direction`, etc.) the engine uses elsewhere — DSL errors fail closed (variation is treated as `should_show=false`, matches the condition evaluator's existing semantics).
  - For `should_show=true`: if the variation exists (managed lookup), updates it with the rule's computed fields + `status=publish` (recovers from prior soft-deletes). If it doesn't exist, calls `create_for_bridge()` with the rule's attributes + computed fields.
  - For `should_show=false`: if the variation exists, soft-deletes via `status=private`. If it doesn't exist, noop.
  - `compute_variation_fields()` translates the rule + source row into a fields payload: `description` from label, `regular_price` from `price_field`, `downloads` from each `downloads[]` source field (handles both attachment IDs and URLs via `build_download_entry()`), `downloadable=true` + `virtual=true` when downloads are present.
  - Returns a summary `{ran, examined, created, updated, hidden, skipped, errors, per_variation}` that the caller includes in `sync_log.context_json`.
- **`JEDB_Flattener::apply_bridge()` integration.** Reconciler invoked after mappings + taxonomies, before status determination. The four result paths updated:
  - Path 1 (mapping error) → includes `variations` summary in context.
  - Path 2 (mappings wrote) → message string includes variation reconciliation count when non-zero.
  - Path 3 (mappings noop, taxonomies OR variations changed) → status `success` instead of `noop`. New `variations_only` marker mirrors the existing `taxonomies_only` marker.
  - Path 4 (everything noop) → noop status with `variations` summary in context.
- **Flatten admin tab Variations section** in `templates/admin/tab-flatten.php`. Collapsible `<details>` visible only when `target_target = posts::product`. Per-variation row: slug, label, show_when textarea, price_field, downloads (CSV), attributes (CSV of `key=value` pairs), enabled checkbox, Remove button. Add row button + status pill. Detailed help paragraph explains the DSL syntax + attribute pre-configuration recommendation + push-only-for-now caveat.
- **`assets/js/flatten-admin.js` extensions:** `$variationsSection` / `$variationsTbody` DOM hooks. `initialVariations` + `variationDefault` from bootstrap. `makeVariationRow()` builds rows from rule objects (handles CSV serialization for downloads + attributes). `readVariationsFromDom()` parses rows back to JSON; drops rows with empty slugs to avoid poisoning saved config. `renderVariations()` populates the tbody from bootstrap on initial render. `refreshTaxonomySectionVisibility()` extended to also hide/show the variations section based on the target dropdown (Woo product only). `cfg.variations = readVariationsFromDom()` in `buildConfig()` so the round-trip persists on every form sync.
- **Bridge meta box Advanced Details — "Variations managed by this bridge" subsection** in `templates/admin/meta-box-bridge.php`. Visible only when `meta_box.show_advanced=true` AND bridge has non-empty `variations[]` AND target is `posts::product`. Per-variation pill: `active` (green) / `will create on next push` (amber) / `hidden (will soft-delete on next push)` (pink) / `rule disabled` (gray) / `inactive` (gray). Read-only diagnostic — authoring lives in the Flatten tab. The `class-woo-product-meta-box.php` `render_linked_panel()` computes the snapshot via the same `JEDB_Condition_Evaluator` the reconciler uses, so the displayed state matches what the next push would do.
- **CSS for variation status pills** in `bridge-meta-box.css` (`.jedb-bridge-variations-status` + per-state border-left colors).

### Engine code unchanged outside the new reconciler hook

The new variation reconciliation runs as a clearly-bounded step inserted after taxonomies in `apply_bridge()`. Mappings, taxonomies, transformers, condition evaluator, target adapters (other than the Woo Variation adapter's new helpers), sync guard, sync log are unchanged. Bridges without variations and bridges targeting non-Woo post types see ZERO behavior change — the reconciler bails before doing any work.

### Migration

Zero migration. The `variations[]` array is a new optional block on the flatten config; existing alpha.12 bridges automatically read it as an empty array via `wp_parse_args()` in `merge_with_defaults()`. To enable variation reconciliation on a bridge, edit the bridge in the Flatten admin tab, scroll to the Variations section (only shown when target=posts::product), add one or more variation rules, save. Subsequent CCT saves trigger the reconciler.

### Verification

1. **Setup.** Edit a Mosaic→Product bridge in the Flatten tab. Confirm a Variations section appears (target=posts::product). Click "+ Add variation rule." Fill in: slug=`with-instructions`, label=`Includes Instructions PDF`, show_when=`{source.has_instructions_pdf} == true`, price_field=`instructions_price`, downloads=`instructions_pdf_attachment`, attributes=`pa_format=digital` (assuming you've pre-configured `pa_format` on the parent product). Save bridge.
2. **Create path.** On a Mosaic CCT row that has `has_instructions_pdf=true` and a non-empty `instructions_price` + `instructions_pdf_attachment`, save (or click Sync now). Confirm a new variation appears under the linked product in WC > Products > {product} > Variations panel. SKU/name should reflect the configured attribute. Regular price should equal `instructions_price`. Downloadable files should include the configured attachment.
3. **Update path.** Change the CCT row's `instructions_price`. Save. Confirm the variation's regular price updates to match.
4. **Hide path (soft-delete).** Toggle `has_instructions_pdf` to false on the CCT row. Save. Confirm the variation still exists but its post_status is now `private` (visible in WC's variations panel marked as Private, not visible on the storefront).
5. **Restore path.** Toggle `has_instructions_pdf` back to true. Save. Confirm the variation's status returns to `publish` and the variation is visible again on the storefront.
6. **Diagnostic surface.** Enable `meta_box.show_advanced=true` on the bridge. Open the linked product. Expand Advanced Details. The "Variations managed by this bridge" list should show the variation's current state (active / will create / hidden / etc.) matching the CCT row's current `has_instructions_pdf` value.
7. **Sync log.** Inspect the latest `wp_jedb_sync_log` row for the bridge. `context_json` should contain a `variations` block with `{ran:true, examined:1, created/updated/hidden: 1, errors:0, per_variation: [{slug:"with-instructions", outcome:"created"|"updated"|"hidden", variation_id:N}]}`.
8. **Non-Woo target sanity.** A bridge targeting a non-product CPT (e.g. `posts::event`) should NOT see the Variations section in the Flatten admin tab. If you manually paste a `variations[]` array via the raw JSON editor and save, the engine logs an info-level `[Variation_Reconciler] non-product target — variations[] block ignored` and reconciliation no-ops.

### Known limitations (Tier 2, deferred)

1. **PULL direction not implemented.** When the editor changes a managed variation's price / downloads / etc. directly via WC's variations panel, the change persists in WC but doesn't back-propagate to the source CCT field. Push-only suffices for the BBHQ Mosaic use case. PULL would require hooking `woocommerce_update_product_variation` + walking each bridge's `variations[]` to find the matching slug + writing back to source; not blocking, can ship in a follow-up.
2. **Variation attribute taxonomy auto-creation.** Editors must pre-configure attribute taxonomies (`pa_format`, `pa_size`, etc.) in WooCommerce Products > Attributes BEFORE declaring them in a bridge's variation rules. When `attributes` is empty in a variation rule, the reconciler falls back to a plugin-managed `pa_jedb_variant` slot with the variation's slug as the value — usable but not editor-friendly (no taxonomy term row, no admin UI for the attribute). Auto-creation of `pa_jedb_variant` as a proper attribute taxonomy on first use can ship later.
3. **`menu_order` field not exposed.** Variations get the WC default ordering. A future enhancement could add `menu_order` to the variation rule shape and the row UI.
4. **No DSL validate button on the show_when textarea yet.** Editors who want to validate a `show_when` expression before save can copy/paste it into the bridge's main Condition field, click Validate, then move it back. Adding a per-row Validate button is straightforward but wasn't in scope for alpha.13.

## [0.6.0-alpha.12] — 2026-05-17

**Phase 4 / Day 4 — Field Presets admin tab + Mandatory coverage integration shipped (§4.12).**

Field Presets are portable, target-scoped knowledge artifacts that answer "for adapter X, what does a complete bridge look like?". Each preset binds to one target adapter (e.g. `posts::product`, `cct::mosaics_data`) and carries a list of fields with `mandatory` flags, freeform `group` labels, and optional `hint` text. The Flatten admin tab's Mandatory coverage panel now lets editors apply a preset's mandatory fields onto a bridge in one click, scaffold passthrough mappings for any missing required fields, and see at-a-glance green/red coverage badges. The meta box's Advanced Details adds the same coverage breakdown when the bridge has `show_advanced=true`.

### Added

- **`JEDB_Field_Presets_Manager` writes** (alpha.3 shipped reads only):
  - `upsert($entry)` — create or update by slug, stamps `created_at` / `updated_at`, returns canonical slug or `WP_Error`.
  - `delete($slug)` — idempotent removal.
  - `replace_all($entries)` — destructive import (overwrites everything), returns `{accepted, dropped}` with per-entry rejection reasons.
  - `merge_import($entries)` — non-destructive import (existing presets with same slug get overwritten, others kept).
  - `prepare_for_storage($entry)` — validation + sanitization. Enforces non-empty slug + label + target. Target must resolve through `JEDB_Target_Registry` (falls back to a `cct::*` / `posts::*` regex match if registry isn't loaded yet). Field entries with empty `name` are silently dropped; duplicates within the same preset are de-duped by name.
  - `compute_effective_required_fields($bridge_config, $adapter_required)` — static helper that combines adapter-required ∪ overrides.add ∖ overrides.remove and tags each result with provenance (`adapter` / `override`). Used by both the Flatten tab's coverage panel AND the meta box's Advanced Details so behavior is identical across surfaces.
- **`includes/admin/class-tab-field-presets.php`** — new `JEDB_Tab_Field_Presets` class. Registers tab at priority 35 (after Flatten). admin-post handlers for save / delete / import / export. JSON export streams `attachment; filename="jedb-field-presets-{ts}.json"` with envelope `{ jedb_field_presets_version: 1, exported_at, site_url, presets: [...] }`. JSON import accepts either the envelope or a bare top-level array. Notice round-trip via query args (`jedb_notice` + counts).
- **`templates/admin/tab-field-presets.php`** — list of saved presets in a `widefat striped` table + add/edit form (slug, label, target, description, notes) + dynamic per-field rows table (name, label, mandatory, group, hint) + Export button + Import textarea with replace-all checkbox.
- **`assets/js/field-presets-admin.js`** — dynamic add/remove of field rows. Seeds an empty row when editing a preset with no fields. Pure client-side; the form is a regular POST.
- **Mandatory coverage panel rewrite in `templates/admin/tab-flatten.php`**:
  - Coverage summary header ("Coverage: X of Y required fields mapped") + missing-count pill.
  - Per-required-field list with green ✓ / red ⚠ badges and provenance labels ("required by adapter" / "required by override / preset").
  - Apply preset dropdown — populated only with presets whose `target` matches the bridge's `target_target`. Selecting a preset and clicking Apply pushes the preset's mandatory field names into `required_overrides.add` (snapshot model — preset edits later don't auto-propagate to applied bridges; editor re-applies if needed).
  - Scaffold missing mappings button — appears when `missing_count > 0`. Appends one passthrough mapping row to the mappings table per missing field.
  - Empty-state placeholder gets a class hook so the JS coverage re-render cleans it up after Apply seeds the first overrides.
- **`assets/js/flatten-admin.js`** extension:
  - `liveRequiredOverrides` state variable seeded from bootstrap; `buildConfig()` reads from it on every form-state sync so Apply changes flow into the saved `config_json`.
  - `effectiveRequiredFields()` mirrors the PHP `compute_effective_required_fields()` so coverage badges re-render client-side after each Apply / Scaffold action without a page reload.
  - `mappedTargetFields()` walks the mappings table for "which target fields are already mapped?".
  - `renderCoverage()` rebuilds the coverage list + summary + missing pill from current form state. Called after each Apply / Scaffold.
  - `#jedb_flatten_apply_preset` click handler — looks up the selected preset in `matchingPresets`, dedups against existing overrides, pushes mandatory field names into `liveRequiredOverrides.add`, syncs JSON, re-renders coverage, shows a status message.
  - `#jedb_flatten_scaffold_missing` click handler — for every missing required field, appends a `passthrough` push/pull mapping row via the existing `makeMappingRow()`. Syncs JSON, re-renders coverage, shows count.
- **Bootstrap extensions in tab-flatten.php** — `matching_presets` (presets whose target matches the bridge's target_target — empty when no target selected) + `required_overrides` (initial state for the live mutable variable).
- **Bridge meta box Advanced Details coverage subsection** (`templates/admin/meta-box-bridge.php`) — rendered ONLY when `show_advanced=true`. Shows "X of Y required fields mapped" + a `<ul>` of missing fields with provenance labels + a "Flatten admin tab" deep link. The compact surface stays uncluttered for editors who don't opt in.
- **CSS** for `.jedb-coverage-summary` / `.jedb-coverage-missing-pill` / `.jedb-coverage-row` (green / red variants) / `.jedb-coverage-badge` / `.jedb-coverage-origin` / `.jedb-coverage-actions` in `admin.css`, plus the meta box's `.jedb-bridge-coverage-missing` in `bridge-meta-box.css`.

### Changed

- **`JEDB_Admin_Shell::enqueue_assets()`** enqueues `assets/js/field-presets-admin.js` when `?tab=field-presets`.
- **`JEDB_Admin_Shell::load_admin()`** requires + instantiates `JEDB_Tab_Field_Presets` alongside the other tabs.
- **`JEDB_Woo_Product_Meta_Box::render_linked_panel()`** computes coverage data only when `show_advanced=true`, passes `$coverage_required` + `$coverage_missing` into template scope.
- **`buildConfig()` in flatten-admin.js** now writes `required_overrides` from `liveRequiredOverrides` instead of relying on whatever was in the initial config_json.

### Engine code unchanged

No changes to any flattener, transformer, condition evaluator, target adapter, taxonomy applier, sync log path, or the meta box modal flow. Field Presets are purely an authoring-time convenience — they seed `required_overrides.add` and stub passthrough mappings, but the engine reads bridges identically to before.

### Migration

Zero migration. The `jedb_field_presets` site option has existed (empty default) since alpha.3. The `required_overrides` block on flatten configs has existed since alpha.3 too. This release just exposes both with full UIs.

### Verification

1. **Create a preset.** Go to JE Data Bridge → Field Presets → Add new preset. Set slug `woocommerce-storefront-visible`, label `WooCommerce — Storefront Visible`, target `posts::product`, then add fields: `name` (mandatory), `regular_price` (mandatory), `category_ids` (mandatory), `_visibility` (mandatory). Save.
2. **Apply to a bridge.** Open an existing Mosaics→Product bridge in the Flatten tab. Scroll to Mandatory coverage. The Apply preset dropdown should list the preset you just created. Pick it, click Apply. The required_overrides.add should grow; the coverage summary should reflect "4 of N required fields mapped" with green / red badges per field. Save the bridge.
3. **Scaffold missing mappings.** If after Apply some fields are still missing mappings, click "Scaffold missing mappings". The mappings table should gain one passthrough row per missing field with target_field pre-filled. Save the bridge.
4. **Coverage in meta box Advanced Details.** Enable `meta_box.show_advanced=true` on the bridge. Open a linked product. Open the Bridge meta box's "Advanced Details" `<details>`. You should see the same coverage breakdown — "X of Y required fields mapped" + missing field list + link back to the Flatten admin tab.
5. **Export / Import.** From Field Presets tab, click "Export presets as JSON" — should download `jedb-field-presets-{timestamp}.json`. Open the file, confirm envelope shape. Paste back into the Import textarea with replace-all OFF; submit. Notice should report "X accepted, 0 dropped". Try with replace-all ON; same result. Try pasting malformed JSON; notice should report `preset_import_invalid_json`.
6. **Validation errors.** Try saving a preset with no slug or no label or an unregistered target slug — save handler should reject with a redirect-back notice carrying the error message.

### Known limitation

"Display-only overlay" mode (preview a preset's fields layered onto the coverage panel WITHOUT writing to `required_overrides`) was scoped out of alpha.12. The Apply-then-Save workflow is the supported authoring path; overlay-without-apply would add a second display state and modest JS complexity for marginal gain. Can be added later if the editor experience needs it.

## [0.6.0-alpha.11] — 2026-05-17

**Phase 4 / Day 3 — CCT-single → linked-post redirect shim shipped (§4.6).**

The `cct_single_redirect` schema flag has been in place since alpha.3; this release wires the runtime behavior. When the editor toggles `cct_single_redirect=true` on an enabled push-direction bridge, visiting the source CCT's "Has Single Page" URL on the frontend now 301-redirects to the bridge's resolved linked-post permalink. Default OFF — opt in per bridge in the Flatten admin tab.

### Added

- **`includes/class-cct-single-redirect.php`** — new `JEDB_CCT_Single_Redirect` class. Hooks `template_redirect` at priority 5 (runs before most theme handlers so we don't waste cycles loading a template we're about to redirect away from). Singleton; registers via `JEDB_CCT_Single_Redirect::instance()` in `JEDB_Plugin::load_core()`.
- **Detection model — reverse-lookup via `cct_single_post_id`.** At `template_redirect`, after pre-flight bail-outs (admin, AJAX, cron, REST, CLI, non-singular), the shim takes the queried post ID, walks every enabled bridge whose `cct_single_redirect=true` AND direction includes push, and queries each bridge's source CCT table for a row whose `cct_single_post_id` matches the queried post ID. First match wins. This approach is JE-version-agnostic — it works whenever JE has populated `cct_single_post_id` via its standard "Has Single Page" mechanism.
- **Loop guard for BBHQ Pattern X.** When `cct_single_post_id` IS the bridge target (the standard pattern where JE's "Has Single Page" was configured to point at the linked product), the resolved target post ID equals the queried post ID — redirecting would loop. The shim silently no-ops in this case. Intended behavior: BBHQ-style setups can safely enable the flag without breakage; the redirect just isn't needed there.
- **Direction guard** — only redirects for bridges where `direction` is `push` or `bidirectional`. Pull-only bridges treat the CCT as the canonical display surface; redirecting them would invert the intended flow.
- **Admin escape hatch** — logged-in users with the `JEDB_CAPABILITY` capability can pass `?jedb_no_redirect=1` to bypass the shim and inspect the underlying CCT-single page. The capability check blocks anonymous bypass.
- **Fresh-read source data** — the shim calls `Target_CCT::get_fresh()` (L-030) when resolving target post IDs so it doesn't act on a stale cached CCT row. Frontend visits are rare enough that the direct-SQL cost is irrelevant.
- **Shared link resolution** — re-uses `JEDB_Flattener::resolve_target_id()` so the shim and the engine stay in lock-step. Any improvement to the resolution logic (e.g. L-021 self-heal: relation row → cct_single_post_id fallback → auto-attach) automatically benefits the shim.
- **Debug log entry** — successful redirects emit a `jedb_log` debug entry tagged `[CCT_Single_Redirect]` with bridge_id / source_id / queried_id / target_post_id for traceability.

### Engine code unchanged

No changes to any flattener, transformer, condition evaluator, target adapter, taxonomy applier, sync log, or meta box render path. The shim is a new frontend-only subsystem; it consumes existing engine APIs (`JEDB_Flattener::resolve_target_id`, `Target_CCT::get_fresh`) but doesn't modify them.

### Modal-iframe interaction (post L-027)

The shim hooks `template_redirect`, which fires only on frontend requests. The alpha.6 modal iframe loads JE's admin CCT edit URL (`wp-admin/admin.php?page=jet-cct-{slug}&cct_action=edit`), so the modal flow is completely unaffected. The shim's main consumer is public-storefront visitors who somehow land on the public CCT single URL; editors using the alpha.9 meta box never see a frontend CCT page in their daily workflow.

### Migration

Zero migration. The `cct_single_redirect` flag has been in the schema since alpha.3 (default `false`). Existing bridges read it as `false` automatically via `wp_parse_args()` in `merge_with_defaults()`. To activate the new shim for a bridge, tick the "CCT-single redirect" checkbox in the Flatten admin tab and save the bridge. Toggle off to revert.

### Verification

1. **BBHQ Pattern X (cct_single_post_id IS the bridge target)**: enable `cct_single_redirect` on the Mosaics→Product bridge. Visit the CCT single URL. Should render the product page as before, NO redirect (loop guard kicks in). Confirm via `jedb-debug.log` — no `[CCT_Single_Redirect]` entry.
2. **Pattern Y (cct_single_post_id is a placeholder, bridge target via JE relation to a DIFFERENT post)**: set up a bridge where the CCT row's `cct_single_post_id` points at a "placeholder" post different from the bridge's linked target. Enable `cct_single_redirect`. Visit the placeholder's permalink. Should 301-redirect to the linked target's permalink. Confirm via `jedb-debug.log` `[CCT_Single_Redirect]` entry showing both IDs.
3. **Pull-only bridge**: same as #2 but with direction=pull. Should NOT redirect (direction guard).
4. **Admin escape hatch**: visit the placeholder permalink while logged in as a user with the JEDB capability, with `?jedb_no_redirect=1` appended. Should render the placeholder page WITHOUT redirecting.
5. **Anonymous escape attempt**: visit the placeholder permalink while logged OUT, with `?jedb_no_redirect=1` appended. Should still redirect (anonymous bypass blocked).
6. **No-op on unrelated singular pages**: visit any regular product / post / CPT single that isn't a CCT-backed page. Should render normally. Performance impact: one bridge-list query + one SQL per opt-in CCT-targeting bridge per singular page render. Cached column-existence checks within the request.

### Future enhancement (not blocking)

For JE installs where CCT singles render WITHOUT a backing WP post (some JE versions / configurations don't populate `cct_single_post_id`), the reverse-lookup approach is a no-op. Adding a secondary JE-native detection path (e.g. via `jet_engine()->listings->data->get_current_object()` returning a CCT item) would extend coverage. Deferred until a real install requires it — the reverse-lookup model covers the standard JE "Has Single Page" pattern that BBHQ uses.

## [0.6.0-alpha.10] — 2026-05-16

**Flatten admin tab UI sweep + forward-looking phase documentation. No engine code touched.**

Post-alpha.9 audit identified a pile of out-of-date copy in the Flatten admin tab — references to phases that are now shipped, behavior descriptions that no longer match the modal-iframe model (L-027), and forward-looking phrases that became stale ("Phase 4 Day 2 builds..."). Cleaned up in one pass. Also tightened up BUILD-PLAN forward-looking notes so future phases know how to interact with the alpha.9 meta box reshape.

### Changed (admin UI copy)

- **Meta box settings intro paragraph** rewritten. Old copy described "editable inputs" + "two-way-syncing inputs" + the D-16 native-rendering skip — all wrong after L-027 / alpha.6. New copy describes the actual flow: read-only previews, "Save & edit" button, JE CCT edit page in a modal, sync engine handles the rest.
- **Section header** dropped `(Phase 4 / Day 2)` suffix. Phase tags age poorly; belong in BUILD-PLAN, not editor-facing UI.
- **"Surfacing fields:" closing paragraph** dropped "Phase 4 Day 2 builds the actual meta box" forward-looking phrase (Day 2 shipped multiple releases ago).
- **CCT-single redirect description** now leads with `**Not yet active —**` since the runtime shim is still a future-release item. Editor understands the toggle persists but doesn't fire today.
- **Source target dropdown description** dropped "Phase 3 supports CCT sources only" sentence — long since superseded by Phase 3.5.
- **Mandatory coverage paragraph** rephrased by feature ("A future Field Presets feature will...") instead of by phase number.
- **Taxonomies section intro** rephrased: dropped `Per BUILD-PLAN §4.11 (D-20, D-21):` lead-in, dropped Phase 3.6 reference. Added explicit reminder that the taxonomy applier works for "any post-type target (products, CPTs, etc.) with any associated taxonomy" — the engine architecture has been post-type-agnostic since 3.6 and the UI was hiding that fact.
- **Snippet support paragraph** under Taxonomies dropped `(Phase 5b)` parenthetical, rephrased as "the snippet runtime ships in a future release."
- **Field mappings intro paragraph** dropped `per D-11` decision reference; rephrased as "Push and pull chains are stored independently — they don't have to be inverses" so editors understand the implication, not the decision number.
- **Condition DSL description** dropped `v1 DSL — see BUILD-PLAN §3.5` lead-in. Operators list is enough.
- **Group-order description** dropped `(D-26)` parenthetical.
- **Label field** gained a one-line description: "Identifies the bridge in admin lists and is used as the WP meta box header on the linked product edit screen (unless 'Meta box title' below is set)." Removes the alpha.9 surprise where editors expected `label` changes to update the WP meta box header (which they do — but the connection wasn't documented).

### Changed (behavior)

- **Reverse-direction options row** now hides when `direction = push` is selected. The `auto_create_target_when_unlinked` flag is only meaningful for pull / bidirectional bridges; showing it on a push-only bridge invited confusion (your config had `auto_create_target_when_unlinked: true` on a push-only bridge — a silent no-op). New JS handler `toggleReverseRow()` watches the direction radios and hides/shows the row. Persisted config_json still saves the flag value correctly on submit; we just don't render the control when it would be a no-op. Server-side initial visibility computed from the persisted direction.
- **Auto-create flag description** dropped `(per D-17)` parenthetical and rephrased to make the trade-off explicit: "Default OFF because the action creates data — turn it on only when you want post saves to spawn source CCT rows automatically."

### Documentation (BUILD-PLAN forward-look notes)

- **Phase 4 Day 3 (redirect shim)** gained a "Modal-iframe interaction" note documenting that the shim hooks `template_redirect` (frontend only) while the modal iframe loads JE's admin URL, so the modal flow is unaffected. The shim's main consumer is public-storefront visitors, not editors.
- **Phase 4 Day 4 (Field Presets + Mandatory coverage)** gained a "Meta box presentation" decision: mandatory-coverage warnings on the linked product Bridge meta box appear ONLY when that bridge's `meta_box.show_advanced=true`. Compact meta box stays surface-fields-and-button only; warnings live with the rest of the diagnostic surface inside Advanced Details. Editors who want at-a-glance coverage flip `show_advanced` on; otherwise the Flatten admin tab's Mandatory coverage panel is always visible there.
- **Phase 4b (Variation reconciliation)** gained a "Scope reminder" — variations are Woo-specific (`Target_Woo_Variation`, `variations[]`, reconciliation engine), but the broader architecture (taxonomies, field mappings, push/pull engines, conditional sync) is post-type-agnostic and supports any CPT target. Don't conflate "variations are Woo-only" with "the whole bridge is Woo-only." Also gained a "Meta box presentation" note — when implemented, the Variation Scope radio belongs inside Advanced Details, not on the compact surface; pure-surface fields driving variation creation (like `has_instructions_pdf`) continue to render as ordinary read-only previews.
- **Phase 5 (Settings, debug, utilities)** gained a "L-022 / L-030 caveat for admin-triggered bulk operations" note. The currently-listed "Bulk re-sync all bridges" item is READ-side (re-pushes existing values via `apply_bridge()`) so it doesn't hit the L-022 hook-asymmetry. BUT any future bulk WRITE tool that writes via `$source_adapter->update()` directly will hit it (JE doesn't fire `updated-item/{slug}` for adapter writes). Documented three handling options: (a) loud documentation, (b) hand-fire JE's hook after each write, (c) route through a higher-level JE API if one becomes available.

### Engine code unchanged

No changes to any flattener, transformer, condition evaluator, target adapter, taxonomy applier, sync log, or meta box render path. Pure UI copy + one JS visibility toggle + four BUILD-PLAN documentation paragraphs.

### Migration

Zero migration. All changes are display-only or admin-UI-visibility tweaks. Existing flatten configs work identically. `surface_on_source` checkbox stays in the per-mapping table for forward-flexibility (per user feedback — was a candidate for removal but kept since the schema field is harmless).

## [0.6.0-alpha.9] — 2026-05-16

**Bridge meta box reshape — one box per bridge, native WP look, opt-in Advanced Details (L-031).**

User feedback after alpha.8: three architectural friction points with the umbrella-meta-box model that had been in place since alpha.4:

1. Renaming a bridge in the Flatten tab didn't update the WP meta box gray header (which was hardcoded to "JE Data Bridge" because one umbrella box served all bridges).
2. Too much admin chrome in the box on day-to-day editor workflows — overrides, sync log, action buttons cluttered the surface that should mostly be "see the surfaced fields, click to edit in JE."
3. Custom panel chrome (pills, panel borders, custom h3 headers) looked foreign next to WC's native meta boxes on the product edit screen.

### Changed

- **`JEDB_Woo_Product_Meta_Box::register_meta_boxes()`** rewritten to loop `find_bridges_for_target()` per post type and call `add_meta_box()` once per enabled bridge. Each box ID is `jedb_bridge_meta_box_{bridge_id}`. Each box uses the bridge's `meta_box.title` (fallback to `bridge_display_label()`) as its WP gray header — so editor edits in the Flatten tab now propagate to the WP meta box header natively on next render. Each box uses the bridge's `meta_box.position` (`normal` | `side` | `advanced`), enabling per-bridge placement in main column / sidebar / below-main. Two CCTs targeting one product = two stacked collapsible WP meta boxes, each clearly named and independently controllable via WP's native screen options + drag-drop UI.
- **`render_meta_box()` → `render_meta_box_for_bridge( $post, $bridge )`** — the umbrella looping render method retired. Per-bridge render method called by each registered meta box's callback closure. Resolves linked-vs-unlinked state for that specific bridge × post pair, then delegates to the linked or unlinked template.
- **`templates/admin/meta-box-bridge.php`** (linked panel) rewritten with minimal native WP look. Uses `<table class="form-table">` for the surfaced field previews (label in `<th>`, preview in `<td>`). Drops the alpha.4-8 panel `<h3>` title, "Linked" status pill, `.jedb-bridge-panel-meta` diagnostic block, `.jedb-bridge-cct-edit-launch` blue background panel — the WP meta box header serves as the panel title, and the "Save & edit" button stands on its own in a `<p>`. When multiple groups exist, they're separated by a single `<tr>` group header row inside the form-table (no `<fieldset>` chrome).
- **`templates/admin/meta-box-bridge-unlinked.php`** rewritten with the same minimal native look. Plain `<p class="description">` for the "not linked" message, regular `regular-text` input + `<select size="6">` for the CCT picker, no panel chrome.
- **`assets/css/bridge-meta-box.css`** slimmed from ~500 lines to ~270. Drops `.jedb-bridge-panel-title`, `.jedb-bridge-panel-meta`, `.jedb-bridge-panel-status`, `.jedb-pill-ok` / `.jedb-pill-warn` / `.jedb-pill-info`, `.jedb-surfaced-row` chrome, `.jedb-surfaced-group` fieldset chrome, `.jedb-bridge-cct-edit-launch` blue panel chrome, `.jedb-bridge-recent-log` custom pill stylings. Keeps the read-only field preview helpers, the modal overlay (L-027), the saving overlay (L-029), and the Advanced Details section (L-031) tweaks.
- **`includes/helpers/field-preview.php`** — non-image attachments now collapse to a plain `<span class="jedb-preview-attachment">` "Has attachment #{id}" label with a default media dashicon. Image attachments still render rich thumbnails (per user preference). Gallery thumbnails unchanged.

### Added

- **`meta_box.show_advanced`** boolean flag on the flatten config schema (default `false`). When `true`, a collapsed `<details>` "Advanced Details" section appears at the bottom of the linked panel containing: linked source diagnostic line, bridge config link, per-product overrides (Freeze / Direction override), recent syncs (last 3 sync_log rows), Sync now button, Unlink button. When `false`, the panel renders ONLY the surfaced field previews + the "Save & edit" button. Existing alpha.4-8 bridges automatically inherit `false` via `wp_parse_args()` in `merge_with_defaults()` — no migration code needed.
- **Flatten admin tab UI** — new checkbox row "Show 'Advanced Details' collapsible on this bridge's meta box" in the Meta box settings section. Wired through `flatten-admin.js` `buildConfig()` so the value round-trips correctly.
- **L-031 in `LESSONS-LEARNED.md`** — "WP meta box label is set at `add_meta_box()` registration — register one box per bridge, not one umbrella box that loops bridges internally." Documents the umbrella architecture trap + the WP-primitive-granularity rule + the wp_parse_args back-compat pattern.

### Engine code unchanged

No changes to `handle_save()`, any flattener / reverse flattener, taxonomy applier, transformer, condition evaluator, target adapter, or sync log path. This is purely a meta box reshape — registration model + template + CSS + one schema flag.

### Multi-CCT and variation gameplan clarified

With one-box-per-bridge, two CCTs linked to one product = two stacked collapsible WP meta boxes (each named, each independently controllable). The resolution path is fenced per-bridge (each bridge's `link_via.relation_id` only matches its own source CCT), so the Mosaics box can only ever surface mosaics fields and the Available Sets box can only ever surface available-sets fields. No cross-contamination possible by design.

For variations (the `has_instructions_pdf → product variation` use case in BUILD-PLAN §4.7), the locked decision (L-015) is that variations come from the SAME CCT row as their parent product, NOT from a separate bridge. The current alpha.9 architecture is fully compatible: when Phase 4b ships the `variations[]` block + reconciliation engine, the same Mosaics→Product bridge gains variation-management behavior without needing a new bridge or a new meta box. Pure-surface fields like the user's current `has_instructions_pdf` (target_field='' + surface_on_target=true) are the correct interim shape — when Phase 4b lands, those fields gain reconciliation side effects automatically with no schema migration.

### Verification

1. **WP header reflects bridge name**: open a product linked through the Mosaics bridge. The WP meta box gray header should display `meta_box.title` (e.g. "Moasics Data surface" if you typed that) or fall back to the bridge `label`. NOT "JE Data Bridge."
2. **Two CCTs → two boxes**: if a product is linked via two bridges, two separate WP meta boxes appear, each named with its own bridge's label. Each can be collapsed / dragged independently.
3. **Native look**: the linked panel renders a `<table class="form-table">` with key/value rows for surfaced fields, followed by a single `.button.button-primary` for the modal launcher. No custom pills, no custom borders, no inner `<h3>` title.
4. **Compact default**: a fresh bridge with `show_advanced` unticked shows only surfaced fields + Save & edit button.
5. **Advanced opt-in**: tick "Show 'Advanced Details' collapsible" in the Flatten admin tab Meta box settings, save the bridge. On next product edit screen render, a collapsed `<details>` "Advanced Details" element appears at the bottom of the meta box. Click to expand → per-product overrides, recent syncs, Sync now / Unlink buttons.
6. **Image previews still rich**: a media field pointing at an image still renders a thumbnail. A media field pointing at a PDF or other non-image collapses to "Has attachment #{id}".
7. **Position field honored**: change a bridge's `meta_box.position` to `side`, save, reload the product edit page. The bridge's meta box should now appear in the right sidebar instead of the main column.
8. **Existing configs**: alpha.8 bridges work unchanged on first render after upgrade — they automatically inherit `show_advanced=false` and the new registration model.

## [0.6.0-alpha.8] — 2026-05-16

**Stale-data hotfix — Bridge meta box surfaced previews now refresh correctly after a modal save (L-030).**

After alpha.7's modal flow fixes landed, the user reported one final issue: *"the surfaced fields don't update on the product page, but the standard push/pulled fields (rendered in woocommerce product page) do change."*

Root cause: JE's `$db->get_item()` can return STALE rows on the request immediately after a write, especially in setups with a persistent object cache (Redis / Memcached). Forward push reads via the same call but happens to get fresh data because it runs in the same PHP request as JE's save (cache layers happen to be hot with the new value, or empty and forced to underlying storage). The meta box render is a separate request — different cache state — and can read the cached pre-save row. Same asymmetric-API gotcha L-022 documents for hooks; L-030 documents it for reads.

### Added

- **`Target_CCT::get_fresh( $id )`** — new method on the CCT adapter that goes directly to the underlying `wp_jet_cct_{slug}` table via `$wpdb->get_row()`, bypassing `$db->get_item()`, `$db->query()`, and every cache layer underneath. Use when freshness matters more than a tiny perf cost (one cache-bypass DB read).

### Changed

- **`JEDB_Woo_Product_Meta_Box::resolve_for_post()`** — source-data read now `method_exists()`-checks for `get_fresh()` and prefers it. Surfaced field previews in the Bridge meta box now always reflect the latest persisted CCT row, even immediately after a modal save on Redis-cached setups.
- **`JEDB_Flattener::apply_bridge()`** — source read also prefers `get_fresh()`. Hook-triggered pushes (the common case) already saw fresh data because they run in JE's save request, but admin-triggered Sync now / bulk-sync paths are separate requests and could hit stale cached rows. This hardens those paths too.
- **`JEDB_Reverse_Flattener::apply_bridge()`** — source-side (CCT) read during pull diff also prefers `get_fresh()`. Prevents unnecessary CCT double-writes when target post values match the freshly-saved CCT row but a cached read would report spurious divergence.
- **L-030 added to LESSONS-LEARNED** — captures the JE asymmetric-read-cache behavior + the get_fresh pattern + the "host read API doesn't see host write API" prevention rules.

### Engine semantics unchanged

This is purely a read-path freshness fix. No mapping logic changed. No new sync_log rows. No condition evaluation differences. No taxonomy applier changes. If a setup has no persistent object cache (typical small WP installs, file-based transient caching), this is a near-no-op — `Target_CCT::get_fresh()` just adds one direct-SQL read instead of routing through JE's wrapper, same network of DB queries either way. On Redis-cached setups, this is the difference between the meta box working correctly and not.

### Verification

1. After running alpha.7's verification checklist (no loop, Done saves, JE Save also closes, Cancel discards), open the modal, edit `mosaic_name` to a new value (e.g. "Mosaic Whale 2"), click Done.
2. Wait for the modal to close + parent to reload.
3. Inspect the Bridge meta box on the product edit page — the read-only preview for `mosaic_name` should show "Mosaic Whale 2".
4. Inspect WC native product fields (e.g. product title if `mosaic_name` maps to `post_title`) — should ALSO show "Mosaic Whale 2".
5. Both freshness paths now line up. If the surfaced preview still shows the old value, there's an additional caching layer we haven't accounted for — please report with `wp_jedb_sync_log` rows, a SELECT * from `wp_jet_cct_mosaics_data` showing the actual DB state, and any active object-cache plugin name.

### Non-CCT adapter notes

`Target_CPT`, `Target_Woo_Product`, and `Target_Woo_Variation` don't expose `get_fresh()` — they fall through to standard `get()`. This is intentional: their reads go through WP's standard `get_post()` / `get_post_meta()` which use the standard object cache, and WP's standard cache DOES invalidate properly on `update_post_meta()` / `wp_update_post()`. The freshness gotcha is specifically a JE-CCT thing.

## [0.6.0-alpha.7] — 2026-05-16

**Bridge meta box modal flow fixes — Save & edit no longer loops; Done button now actually saves; JE's post-save redirect no longer leaves the editor on a chromed page (L-029).**

Three user-reported bugs from alpha.6.1 staging:

1. **Click "Save & edit \"X\" in JetEngine" → confirm dialog asks to save → click OK → save fires → page reloads → dialog appears AGAIN → loop.** Eventually clicking Cancel breaks out, but the auto-launched modal then opens on top of an unsaved-state product page.
2. **Click "Done · Return to product" in the modal top bar → modal closes → CCT changes are NOT saved.** The Done button was a misnomer; it just postMessaged the parent to close without telling JE to save.
3. **Click JE's native Save button inside the modal → page refreshes inside the iframe → WP admin chrome (sidebar, admin bar, screen options) reappears.** JE's post-save redirect URL doesn't preserve our `?jedb_chrome=stripped` query param, so the chrome-strip code stops applying. Also: clicking JE's Save doesn't close the modal automatically — editor is stuck on a chromed JE page inside the iframe.

All three fixed in alpha.7. See L-029 for the parser / cross-frame interaction details.

### Changed

- **`assets/js/bridge-meta-box.js`** — `.jedb-open-cct-modal` click handler simplified: **always** save the product form first, no dirty-check, no confirm dialog. Saving a clean form is a harmless WP no-op; saving a dirty form preserves the editor's work. The alpha.6 dirty-check (comparing `value` to `defaultValue` on every input in `#post`) was triggering false positives because WP's autosave/heartbeat and various third-party plugins (visible in user's staging: "Restore the backup", "Deployer for Git", "Jet Woo Builder Data Update" notices) mutate form inputs post-load. Auto-launch path on the post-save reload now opens the modal **directly** via `openModal()` instead of re-triggering the click handler, eliminating the loop entirely.
- **`JEDB_Woo_Product_Meta_Box::maybe_inject_cct_chrome_strip()`** — restructured into a two-tier injection model:
  - **Tier 1** (always injected on `jet-cct-*` pages, regardless of `?jedb_chrome=stripped`): an iframe-aware close-on-save handler that runs only when (a) the page is loaded inside an iframe (`window.top !== window.self`), AND (b) `sessionStorage.jedb_close_modal_on_load === '1'`. When both conditions hold, hides the document with `html.style.visibility = 'hidden'` immediately (prevents WP chrome flash), then on `DOMContentLoaded` checks for validation error notices (`.notice-error, .notice.notice-error`). If clean, postMessages the parent to close + reload. If error, un-hides and postMessages `jedb:cct-save-error` so the parent can hide its saving overlay. The flag is cleared in either case to prevent loops.
  - **Tier 2** (only when `?jedb_chrome=stripped`): the chrome-strip CSS + Done/Cancel top bar + JE form submit interceptor. The submit interceptor sets the sessionStorage flag on every JE form submit (whether triggered by the user clicking JE's native Save button OR by our Done button programmatically clicking it), AND postMessages the parent with `jedb:cct-save-starting` so the parent can show a "Saving…" overlay.
- **"Done" button behavior** — was `postMessage parent to close`. Now: sets the sessionStorage close flag, postMessages parent with `jedb:cct-save-starting`, then programmatically clicks JE's submit button so JE's native validation / save flow fires normally. Renamed from "Done · Return to product" to "Done · Save & return to product" for clarity.
- **"Cancel" button** — unchanged behavior (closes without saving, postMessages parent with `jedb:cct-modal-close, reload:false`). Renamed to "Cancel · Discard changes" for clarity.

### Added

- **"Saving…" overlay** in the parent modal. Shown when the iframe postMessages `jedb:cct-save-starting`. Hidden when the iframe either closes (success) or postMessages `jedb:cct-save-error` (validation failure). Gives the editor visual feedback during the ~200-800ms server round-trip while JE saves + redirects + the iframe reloads.
- **L-029 in LESSONS-LEARNED** — "JE's post-save redirect strips custom query params; use `sessionStorage` + an always-injected handler to communicate cross-frame state." Captures the redirect-strips-params problem, the two-tier injection pattern, the chrome-flash mitigation, and prevention rules.

### Engine code unchanged

No changes to `handle_save()`, any flattener / reverse flattener, taxonomy applier, or any engine path. The bug surface was entirely in client-side mechanics (form interception, sessionStorage, postMessage protocol, page visibility timing). The CCT save itself goes through JE's normal form POST → `?cct_action=save-item` → JE's internal handler → JE's `updated-item/{slug}` hook → our forward push subscriber, all as designed since Phase 3.

### Verification

1. **No more loop**: open any linked product, edit a product field (creates "dirty" state in WP's eyes), click "Save & edit \"X\" in JetEngine". Should: see WP save the product, page reloads, modal opens automatically. NO confirm dialog should appear at any point.
2. **Done saves**: open the modal, edit a CCT field (e.g. mosaic_name), click "Done · Save & return to product". Should: see "Saving…" overlay appear briefly, modal closes, parent reloads, the preview in the meta box shows the new value.
3. **JE's Save also closes**: open the modal, edit a CCT field, click JE's native Save button (inside the JE form, not our top bar). Should: see "Saving…" overlay appear briefly, modal closes (no WP chrome flash beyond the unavoidable inter-page transition), parent reloads with the new preview value.
4. **Cancel doesn't save**: open the modal, edit a CCT field, click "Cancel · Discard changes". Should: modal closes immediately, NO reload, the preview values are UNCHANGED.
5. **Sync log**: a successful Done or JE-Save should produce a forward-push row in `wp_jedb_sync_log` from JE's natural `updated-item/{slug}` hook. NO `meta_box_post_save_push` rows (that origin tag was retired in alpha.6).

### Known limitation

If JE's CCT save fails validation, the editor sees the iframe re-render with the error message — but the iframe will show with normal WP chrome (admin bar, sidebar) because JE's post-save redirect drops `?jedb_chrome=stripped` from the URL. This is acceptable as a fallback: validation errors are recoverable (fix input, click Done again). A future release can preserve the chrome-strip param across JE's redirect by either modifying JE's redirect URL or detecting the post-error state and self-redirecting to the chrome-stripped URL.

## [0.6.0-alpha.6.1] — 2026-05-15

**Critical hotfix — Bridge meta box was emitting `<form>` tags inside the WP `#post` form (since alpha.4); fixed product saves redirecting to `wp-admin/edit.php` and the modal launcher not working (L-028).**

The Bridge meta box on the Woo product / variation edit screen has been silently breaking product saves since alpha.4 (the version that introduced it). Every "Update" click on a product redirected to `wp-admin/edit.php` instead of returning to the product edit page. Field changes weren't lost server-side, but the editor's visible flow broke completely. The alpha.6 "Save & edit CCT row" modal flow also relied on a working product-save → page-reload cycle, so it appeared broken too even though the modal code itself was correct.

Cause: `templates/admin/meta-box-bridge.php` and `templates/admin/meta-box-bridge-unlinked.php` rendered three `<form action="admin-post.php">` blocks inline for Sync now / Unlink / Link actions. Meta boxes are rendered INSIDE WordPress's main `#post` form, and HTML5 forbids nested forms. Browsers parse this by **ignoring the inner `<form>` opening tag but treating the inner `</form>` closing tag as closing the OUTER `#post` form**. The WP Update button then ended up outside any form and either submitted nothing or fell through to admin-post.php with no `action`, which redirects to the admin list page. See L-028 for the full mechanism + prevention rules.

### Changed

- **`templates/admin/meta-box-bridge.php`** — Sync now and Unlink buttons no longer wrapped in `<form>`. They are now `<button type="button" class="jedb-bridge-action-btn" data-jedb-action="…">` elements inside a plain `<div data-jedb-form-action="…" data-jedb-nonce-field="…" data-jedb-nonce-value="…" data-jedb-post-id="…" data-jedb-bridge-id="…">` wrapper. The nonce is freshly generated per render via `wp_create_nonce()` and carried in a data attribute instead of as an inline `wp_nonce_field()` call.
- **`templates/admin/meta-box-bridge-unlinked.php`** — Link form converted the same way. The picker `<select>` no longer carries `name="source_id"` (which would otherwise have submitted with `#post` since the inputs are now naked inside the meta box); it carries `data-jedb-field-name="source_id"` instead, and the JS handler reads that value off the picker when building the off-DOM form.
- **`assets/js/bridge-meta-box.js`** — new `buildAndSubmitForm()` helper. Click handlers on `.jedb-bridge-action-btn` (Sync now / Unlink) and `.jedb-bridge-link-btn` (Link) build a real `<form>` element appended to `<body>` (well outside `#post`), populate it with hidden inputs from the wrapper's data attributes plus an optional `extras` map for action-specific values (e.g. `source_id` from the picker), and submit it programmatically. Same admin-post.php endpoints, same handlers, same flow — just no invalid nested-form HTML.
- **L-028 in `LESSONS-LEARNED.md`** — "Never nest `<form>` tags inside a WordPress meta box." Full breakdown of the bug + parser behavior + prevention rules including a possible CI lint check (`rg --quiet '<form\b' templates/admin/meta-box-*.php`).

### Engine code unchanged

No changes to `handle_save()`, `handle_sync_now()`, `handle_unlink()`, `handle_link()`, any flattener / reverse flattener, or any other engine file. The bug was purely in the meta box's HTML emission. The Sync now / Unlink / Link admin-post.php endpoints validate their nonces and POST data exactly as before — the wire format is identical.

### Migration notes

Pure browser-rendering fix. No schema migration, no config migration, no engine behavior change. Existing flatten configs work unchanged. The bug was invisible to PHP — no error rows in `wp_jedb_sync_log`, no entries in `jedb-debug.log`. Only end-to-end user testing surfaced it.

### Why this slipped through alpha.4 → alpha.5 → alpha.6

The meta box was tested in alpha.4 staging primarily via the action buttons (Sync now / Unlink) — those buttons WORKED because clicking them submitted what the browser thought was the correct (mangled) form to admin-post.php with the right action params. The regression was visible only when the user clicked WP's main "Update" button on the product, which most staging tests didn't focus on. alpha.5's data-loss fix re-tested the meta box flow but again focused on the surfaced-field write semantics rather than the product save outcome. alpha.6's modal launcher relied on the product save → page reload cycle, which made the bug finally unmissable: the editor saved the product, expected the modal to auto-open on reload, and got dumped on the post list instead.

### Verification

1. Open any Woo product. Verify the meta box renders normally.
2. Make a change to a product-level field (title, price, etc.). Click "Update."
3. Page should return to the product edit screen (with the WP "Post updated" notice), NOT redirect to `wp-admin/edit.php`.
4. View page source / DOM inspector. The `<div id="jedb_bridge_meta_box">` block should contain NO `<form>` tags. Outer `<form id="post">` should remain open through the entire meta box block.
5. Click "Sync now" or "Unlink" buttons. They should POST to `admin-post.php` and redirect back to the product edit page (with our admin notice on success).
6. Click "Save & edit \"{label}\" in JetEngine". If form was dirty, the save-first flow should now actually save the product and reload to the same product page. The modal should auto-open after reload.

## [0.6.0-alpha.6] — 2026-05-15

**Phase 4 / Day 2 architectural pivot — delegate CCT editing to JE itself via a chrome-stripped modal iframe; field-type-aware read-only previews on the product edit screen; alpha.5 explicit-`apply_bridge` workaround retired (L-027).**

Staging testing of alpha.5 surfaced one final issue: the inline editable inputs in the meta box didn't honor JE field types. A CCT `select` rendered as a plain text input. Media fields rendered as text. WYSIWYG rendered as plain textarea. Editors expected the same UI affordances JE gives them on its CCT edit page. The natural next step looked like "build a renderer for each JE field type" — but the user proposed (and we agreed) a much cleaner architecture: **render type-aware read-only previews on the product edit screen, then launch JE's actual CCT edit page in a chrome-stripped modal iframe when the editor wants to edit**. Every field type renders correctly because JE itself is rendering it. Zero reimplementation burden, no ongoing maintenance cost as JE adds field types.

Bonus payoff: the alpha.5 explicit-`apply_bridge` workaround for L-022 disappears. The meta box no longer writes to source — JE's own save form does — so the natural `updated-item/{slug}` → forward push pathway runs without our intervention.

### Added

- **`includes/helpers/field-preview.php`** — new helper `jedb_render_field_preview( $value, $field_type, $field )` returning HTML-safe read-only previews for ~15 JE-style field types: text, textarea, wysiwyg, checkbox, boolean, switch, select, radio, media, gallery, date, time, datetime, number, repeater, posts, and url/email links. Each branch escapes per-context (the helper is the trust boundary).
- **`JEDB_Woo_Product_Meta_Box::maybe_inject_cct_chrome_strip()`** — hooks `admin_head`. When the current page is `?page=jet-cct-*&jedb_chrome=stripped` AND the user can manage JEDB, emits:
  - CSS hiding `#wpadminbar`, `#adminmenu*`, `#wpfooter`, `#screen-meta*`, plus `body.wp-admin` background adjustments.
  - A 48-px floating top bar with two buttons: **Done · Return to product** and **Cancel**. Both `postMessage` the parent window with `{type:'jedb:cct-modal-close', reload:true|false}` on click.
  - The bar's JS aborts if not actually in an iframe (`window.top === window.self` guard) so direct visits with the query param don't break.
- **`JEDB_Woo_Product_Meta_Box::maybe_enqueue_assets()`** extension — reads the 60-second transient `jedb_reopen_cct_{user}_{post}` and passes it to JS as `jedbMetaBoxBootstrap.reopenBridgeId`. Used by the JS to auto-launch the modal after a "Save & edit" submission completes.
- **Meta box template** — a "Save & edit \"{label}\" in JetEngine" button per linked bridge. Constructs the CCT edit URL with `?jedb_chrome=stripped&jedb_return={post_id}` and a data attribute for the JS click handler to consume.
- **`assets/js/bridge-meta-box.js`** — modal subsystem:
  - `ensureModal()` lazy-creates the overlay + iframe + close-button structure on first use.
  - `openModal(url)` / `closeModal(shouldReload, confirmDirty)` orchestrate visibility and source URL.
  - `window.addEventListener('message')` handler accepts `jedb:cct-modal-close` events from same-origin only, closes modal, optionally reloads the parent product edit page.
  - `.jedb-open-cct-modal` click handler with dirty-form detection: if the product form is dirty, asks the editor to save first; on confirm, stamps `_jedb_reopen_cct_bridge` hidden marker and triggers `#publish`.
  - Auto-launch logic: if `jedbMetaBoxBootstrap.reopenBridgeId > 0`, finds the matching button and triggers click after a 250 ms paint settle.
  - Escape key handler closes the modal.
- **`assets/css/bridge-meta-box.css`** — modal overlay (z-index 160001 above WP admin bar 99999), 92vh × min(1400px, 95vw) frame, close button styling, body.jedb-cct-modal-open scroll lock. Plus a complete suite of read-only preview styles per field type (`.jedb-preview-bool-on/off`, `.jedb-preview-wysiwyg`, `.jedb-preview-media-thumb`, `.jedb-preview-gallery`, `.jedb-preview-select`, `.jedb-preview-multi`, `.jedb-preview-date`, `.jedb-preview-link`, etc.). New `.jedb-bridge-cct-edit-launch` panel for the "Save & edit" button group.
- **L-027 in LESSONS-LEARNED.md** — "Don't rebuild every JE field type. Delegate editing to JE itself via a chrome-stripped modal iframe." Captures the false start (per-type renderers), the evidence, the reality (JE's own CCT edit page is the right surface), the bonus payoff (L-022 stops mattering), and six prevention rules.

### Removed

- **`JEDB_Woo_Product_Meta_Box::apply_surfaced_edits_for_bridge()`** — the alpha.5 method that wrote surfaced-field edits back to source and then explicitly invoked `apply_bridge()`. Replaced by JE's native save flow inside the modal iframe.
- **`jedb_surfaced[<bridge_id>][<source_field>]` form posting** — the meta box no longer submits any field values from the inline editor (there's no inline editor anymore). The `handle_save()` body shrunk from ~50 lines of surfaced-field iteration to ~10 lines of pure post-meta handling.
- **The `meta_box_inline_save` sync_log origin tag** — no inline saves happen here anymore. (The constant in `JEDB_Sync_Log` stays for historical sync_log row readability; no new rows get written with it.)
- **The `meta_box_post_save_push` origin tag in active use** — the explicit `apply_bridge()` call that emitted this tag is gone. JE's natural save now uses whatever origin the existing Phase 3 hook subscribers tag with (typically `cct_updated_item_hook` or similar).
- **Mode pills in the meta box** — `pure_surface` / `native_overlay` / `sync_and_surface` mode pills are no longer rendered because there's no rendering distinction anymore (everything is read-only preview). The `.jedb-surfaced-mode-pill` CSS class is removed.

### Changed

- **`templates/admin/meta-box-bridge.php`** — rewritten surfaced-fields rendering. Each field becomes a `.jedb-surfaced-readonly` row containing label + `jedb_render_field_preview()` output + source/target field meta. No `<input>` / `<textarea>` / `<select>` elements anywhere in the linked panel.
- **`JEDB_Woo_Product_Meta_Box::hooks()`** — requires `includes/helpers/field-preview.php` and registers the new `admin_head` chrome-strip hook.
- **`JEDB_Woo_Product_Meta_Box::handle_save()`** — slimmed dramatically. Now writes only `_jedb_bridge_locked` + `_jedb_bridge_direction_override` (unchanged) and optionally writes the `jedb_reopen_cct_{user}_{post}` transient if `_jedb_reopen_cct_bridge` was posted.

### Migration notes

No schema changes. No flatten config migration needed. Existing bridge configs continue to work — the `surface_on_target` flag now controls "show this field's READ-ONLY PREVIEW on the product edit screen" instead of "render an editable input." Mappings with `target_field` empty still render a preview (showing the source value); previously these were the "pure-surface" inline editors.

### Verification checklist

1. Open a Woo product that's linked to a CCT row via a bridge.
2. Meta box renders surfaced field previews (text, boolean pill, media thumb, etc.) instead of editable inputs.
3. Click "Save & edit \"{label}\" in JetEngine" — if the form is dirty, confirm dialog appears; on confirm, page saves and reloads with the modal auto-opened.
4. Modal contains the JE CCT edit page with no WP admin bar / sidebar / footer visible — just the JE form and our top bar.
5. Edit a CCT field of any type (select, media, WYSIWYG, gallery, etc.) — all render natively in JE.
6. Click Save in JE's form. Page inside iframe reloads (or shows JE's "Saved" notice).
7. Click "Done · Return to product" in our top bar. Modal closes, parent page reloads.
8. Updated preview values visible on the product edit screen.
9. `wp_jedb_sync_log` shows a forward push row from the JE-natural-hook source (NOT `meta_box_post_save_push` anymore — that origin should not appear in any new rows).
10. Per-product lock + direction override post meta still respected by both engines (unchanged from alpha.3 guards).

## [0.6.0-alpha.5] — 2026-05-11

**Phase 4 / Day 2 follow-up — Bridge meta box surface mechanics decoupled from sync mechanics + data-loss bug fix.**

Staging testing of alpha.4's Bridge meta box surfaced two real issues the user identified:

1. **Surfacing required a `target_field`** even when the editor's intent was purely "edit this CCT field from the product page" (no sync side effect needed). Adding a fake target like `_jedb_demo_field` was the awkward workaround.
2. **Data-loss bug**: after the meta box save wrote to source, the next product save's reverse-pull engine would diff against stale target values and clobber the source. The alpha.4 pull-lock hack only protected within the current request; subsequent requests would still desync.

alpha.5 fixes both. The mapping schema now supports three modes (pure-surface / native-overlay / sync-and-surface) that decouple "where to render the editor UI" from "what data must live where."

### Changed

- **`JEDB_Woo_Product_Meta_Box::build_surfaced_groups()`** restructured.
  - **Dropped the D-16 native-rendering skip for surface.** Editor's `surface_on_target` tick is now authoritative. If they want to render an editable `mosaic_name` input alongside Woo's native title input, they can — CCT-canonical (D-2) handles conflicts.
  - **Dropped the empty-`target_field` skip.** A mapping with `source_field` set, `target_field=''`, `surface_on_target=true` now renders as a "pure-surface" editor — saves write back to source, no target shadow data created, no push/pull on this mapping.
  - **Tracks skipped mappings** with reasons (disabled, no source_field, etc.) so the template can show useful "you ticked this box, here's why it didn't render" diagnostics instead of a blank-state message that wrongly implies you forgot to tick.
  - **Returns `{groups, skipped}`** instead of just the groups array.
  - Each rendered field gets a `mode` annotation: `pure_surface` / `native_overlay` / `sync_and_surface`. The template renders a small uppercase pill next to each field so editors see at a glance what the field does.
- **`JEDB_Woo_Product_Meta_Box::apply_surfaced_edits_for_bridge()`** — replaced the alpha.4 pull-lock hack with an explicit forward-push call after the source write. Now:
  - Source adapter write.
  - Sync log row 1: meta_box_inline_save.
  - **`JEDB_Flattener::apply_bridge()` invoked synchronously** with origin tag `meta_box_post_save_push`. This acquires the push lock, runs all mappings (propagating new source values to target), runs taxonomy rules. The reverse pull that fires later in the same request on `woocommerce_update_product` sees the push lock at its cascade check and bails with `skipped_locked, cascade=push_in_flight`. Clean.
  - Sync log row 2: meta_box_post_save_push (logged by apply_bridge itself).
  - If the push status is not success/noop, the engine logs a warning to `jedb-debug.log` flagging that the next product save's reverse pull may clobber source — gives editors a diagnostic trail.
- **`templates/admin/meta-box-bridge.php`** — three new presentation states:
  - **Mode pills** next to each rendered field: `surface only` (info), `native overlay` (warn), or no pill for the default sync+surface mode. Hovering shows a tooltip explaining the semantics.
  - **Skipped-mapping diagnostic** when `$surfaced_groups` is empty but `$surface_skipped` has entries — explicitly lists each `source_field → target_field` that was skipped + reason.
  - **Pure-surface label fallback** — uses source schema label when no target schema is resolvable; falls back to the field name if both schemas are unavailable.
- **`assets/css/bridge-meta-box.css`** — added styles for `.jedb-surfaced-mode-pill`, `.jedb-surfaced-fields-empty`, `.jedb-surface-skipped-list`, `.jedb-surface-skipped-reason`.

### Why "double work" in the meta box save is bounded but unavoidable for now

L-022 documents that `Target_CCT::update()` writes via `$db->update()` directly, which does NOT fire JE's `updated-item/{slug}` hook. So the natural engine pathway ("CCT save → forward push fires → target stays in sync") does NOT activate from our adapter writes. To prevent the data-loss bug, the meta box must explicitly invoke `apply_bridge()` after the source write.

The architecturally correct fix (make `Target_CCT::update()` fire JE's hooks so the engine pathway works naturally) is bigger than alpha.5 scope. L-022's "no fix needed" disposition was based on the broken-hooks side-effect preventing some cascade scenarios; fixing it requires rebalancing the cascade-prevention story across every CCT writer. Deferred to Phase 5+.

The explicit `apply_bridge()` call is bounded to one code path (the meta box save handler), annotated with an L-022 reference so future readers know why it's there.

### Engine behavior unchanged

- `JEDB_Flattener::apply_bridge()` already skips mappings with empty `source_field` OR empty `target_field` (lines 375). Pure-surface mappings are silently ignored by forward push — no engine code change needed.
- `JEDB_Reverse_Flattener::apply_bridge()` already skips mappings with empty `source_field` OR empty `target_field` (line 365). Same — pure-surface mappings are silently ignored by reverse pull. No engine code change needed.
- All other engine paths byte-identical to alpha.4.

### Migration / upgrade notes

- **Automatic.** No schema migration. Existing alpha.4 flatten configs work unchanged. The new `mode` field is derived at render time, not stored.
- **Test recipe simplifies dramatically.** No more `_jedb_demo_field` fake mapping needed. Just tick "Target" on your real `mosaic_name → name` mapping in the Flatten tab — the input now renders correctly with a "native overlay" pill explaining the dual-input UX.
- **For pure-surface use**: add a mapping with `source_field` set, leave `target_field` empty, tick "Target" surface. The input renders in the meta box, edits write to source CCT only, no product shadow data is created.

### Day 2 limitations carried forward

- Editor edits surfaced field AND Woo-native field in same save: the forward-push-after-meta-box-save still runs, but Woo-native field changes that aren't in bridge mappings still pull on the next save normally. No change from alpha.4.
- Multi-bridge conflict warnings still deferred to Day 4 Field Presets.

---

## [0.6.0-alpha.4] — 2026-05-10

**Phase 4 / Day 2 — Bridge meta box on Woo product / variation edit screens (D-27).**

The meta box reads flatten configs directly (no template layer per
D-25/D-27). Resolves at render time which bridge(s) govern THIS
specific product by walking `wp_jedb_flatten_configs` for rows whose
`target_target` matches the post type, then running
`JEDB_Reverse_Flattener::resolve_source_id()` in read-only mode per
candidate. One panel rendered per resolved bridge (linked or
unlinked).

This is the killer-feature deliverable for Phase 4 — surfaces
CCT-managed fields on the Woo product edit screen so editors don't
have to context-switch to JE's CCT admin for fields that are
operationally meaningful while looking at a product.

### Added

- **`includes/admin/class-woo-product-meta-box.php`** — new class
  `JEDB_Woo_Product_Meta_Box`. Singleton. Hooks on `add_meta_boxes`
  for `product` and `product_variation` post types (`normal`
  context, `default` priority). Save handler hooks on
  `save_post_product` and `save_post_product_variation` at priority
  20. Three action handlers on `admin_post_*` for Sync now, Unlink,
  Link. Admin notices renderer for post-action feedback.
- **`templates/admin/meta-box-bridge.php`** — linked-state template.
  Renders: linked source row label + via-method, bridge config slug
  + deep link to Flatten admin tab, surfaced field inputs grouped by
  freeform `group` label (D-26), per-product Lock checkbox +
  Direction override radios (writing to the `_jedb_bridge_locked` /
  `_jedb_bridge_direction_override` post meta the alpha.3 engine
  guards consume), top-3 sync_log rows pill-coded by status, Sync
  now button (forward push trigger), Unlink button.
- **`templates/admin/meta-box-bridge-unlinked.php`** — picker
  template. Search input + results select, Link button. Reuses the
  existing `wp_ajax_jedb_relation_search_items` endpoint (Phase 2)
  so the AJAX surface is shared with the CCT picker UI.
- **`assets/js/bridge-meta-box.js`** — frontend behavior. Debounced
  live CCT search (250ms) using the existing relation-search AJAX
  endpoint, populates the results select, lock checkbox confirm
  dialog with clear explanation of effect, escapeAttr/escapeText
  helpers. Initial search-all fires on first focus (saves a typing
  step for small CCT lists).
- **`assets/css/bridge-meta-box.css`** — scoped styles (only inside
  `.jedb-meta-box-wrap`) — bridge panel layout, surfaced field
  groups, override fieldset with yellow accent, recent log pills,
  unlinked picker styling.

### Reused (no duplication — verified by the §12 audit)

- `JEDB_Reverse_Flattener::resolve_source_id()` for the resolution
  path. Called in read-only mode (explicitly clones the config with
  `auto_create_target_when_unlinked = false` so a render never
  creates a CCT row as a side effect).
- `JEDB_Relation_Attacher::attach()` + `detach()` for Link / Unlink.
  Same idempotent path L-021 self-heal uses.
- `JEDB_Flattener::apply_bridge()` for the Sync now button. Same
  engine path as automatic CCT saves.
- `JEDB_Sync_Guard::acquire('pull', ...)` in `handle_save()` to
  prevent the reverse pull engine from running redundantly on the
  same request after a surfaced-field write (which IS a
  pull-direction effect). Reverse engine bails at its
  same-direction acquire with `STATUS_SKIPPED_LOCKED, "sync_guard
  already locked — same-direction cycle detected"`.
- `JEDB_Sync_Log::record()` for surfaced-field save audit trail.
  Origin tag `meta_box_inline_save` distinguishes from automatic
  reverse pulls.
- JFB-WC meta box patterns: `add_meta_box()` registration (lines
  ~1437–1444), 4-guard save preamble (nonce/perms/autosave/post-type,
  lines ~1465–1481), conditional `admin_enqueue_scripts` (~1658–1661),
  `admin_notices` for feedback (~795).

### Wired

- **`includes/class-plugin.php`** — `load_admin()` instantiates
  `JEDB_Woo_Product_Meta_Box` when `class_exists( 'WooCommerce' )`.
  Skipped on WC-less installs (the meta box has no target post
  type to attach to without WC).

### Behavior unchanged

- **Engine paths byte-identical to alpha.3.** `JEDB_Flattener`,
  `JEDB_Reverse_Flattener`, `JEDB_Sync_Guard`, `JEDB_Taxonomy_Applier`,
  every transformer, condition evaluator, all four target adapters:
  untouched.
- **Existing flatten configs work unchanged.** The meta box renders
  for any product whose post type matches an existing bridge's
  `target_target`. Configs created in 0.5.x / alpha.3 work today.
- **Per-product post meta** (`_jedb_bridge_locked`,
  `_jedb_bridge_direction_override`) — read by the alpha.3 engine
  guards, NOW WRITTEN BY THE META BOX UI. The pieces connect.

### Known Day 2 limitations

- **Surfaced field + Woo-native field on the same save** — if the
  editor edits both a surfaced field (e.g. `mosaic_name`) AND a
  Woo-native field that's in the bridge's mappings (e.g.
  `regular_price`), the meta box's pull lock prevents the reverse
  pull from running on the same request. The Woo-native edit
  propagates on the NEXT product save instead. Eventually consistent
  with one-save delay. Acceptable for Phase 4; could be tightened in
  Phase 5+ with per-field cascade tracking.
- **No live "this product matches multiple bridges" warning yet** —
  if a product happens to match multiple `target_target = posts::product`
  bridges with overlapping mappings, all panels render side by side.
  Day 4 Field Presets work may surface a "conflict detected" warning
  on the Mandatory coverage panel.
- **`save_post_product_variation`** is hooked but only fires when
  WC manages variations through the standard product editor path.
  Bulk variation operations may not trigger the meta box save.
  Acceptable — variations are a Phase 4b deliverable.

### Migration / upgrade notes

- **Automatic.** Install over alpha.3: the meta box appears on
  product / variation edit screens for any post type that has an
  enabled flatten config targeting it. Nothing else changes. No
  database migrations.

### Phase 4 Day 2 exit criterion (BUILD-PLAN §7) — met

> *"Editing a Mosaic product on the Woo admin shows the linked CCT
> row, surfaces the flagged fields per the flatten config, lets the
> editor sync now / lock / unlink without leaving the screen. Reverse-pull
> engine writes surfaced fields back to CCT."*

Verified by inspection of:
- `JEDB_Woo_Product_Meta_Box::render_meta_box()` (resolution path
  reuses `resolve_source_id()`)
- `templates/admin/meta-box-bridge.php` (renders surfaced fields,
  status block, override controls, action buttons)
- `apply_surfaced_edits_for_bridge()` (writes through
  `JEDB_Target_*::update()`, acquires pull lock, records sync_log)
- `handle_sync_now()` (calls `JEDB_Flattener::apply_bridge()`
  directly)
- `handle_unlink()` / `handle_link()` (call `JEDB_Relation_Attacher`
  directly)

Staging verification + §7.1 evidence block ship when the user
finishes Day 2 testing.

---

## [0.6.0-alpha.3] — 2026-05-10

**Phase 4 / Day 1 — alpha.3 reshape: revert template layer + flatten config schema extensions + per-product engine guards (D-25 / D-26 / D-27 / L-026).**

The alpha.1/alpha.2 Bridges admin tab and `JEDB_Bridge_Types_Manager`
were a premature template-layer abstraction. Architectural review
(L-026) found the layer didn't deliver value for the actual editing
workflow on Brick Builder HQ — the flatten config IS the bridge
identity. This release retires the layer and reshapes Phase 4 around
three new locked decisions:

- **D-25** — drop bridge types template layer.
- **D-26** — field presets are first-class portable artifacts (target-scoped, freeform groups, three application modes — display/apply/scaffold). Skeleton lands here; full UI ships Phase 4 Day 4.
- **D-27** — meta box reads flatten config directly. The Phase 4 Day 2 Bridge meta box on Woo product / variation edit screens will be a *view* of an existing flatten config, not an authoring surface for a separate concept.

This release ships the foundation: revert + flatten config schema
extensions + engine guards. Days 2-4 follow on subsequent commits.

### Removed

- **`includes/admin/class-bridge-types-manager.php`** (~620 lines) — entire template-layer CRUD wrapper retired per D-25.
- **`includes/admin/class-tab-bridges.php`** (~330 lines) — Bridges admin tab.
- **`templates/admin/tab-bridges.php`** (~280 lines) — admin tab template.
- **`assets/js/bridges-admin.js`** (~220 lines) — admin tab JS.
- **Bridges-tab CSS block** in `assets/css/admin.css` (~55 lines). The `.jedb-pill-info` rule is kept as a general-purpose info badge.
- **`JEDB_OPTION_BRIDGE_TYPES` constant + activation default** in `je-data-bridge-cc.php`. `delete_option('jedb_bridge_types')` runs on activation (best-effort cleanup for any install that ran alpha.1/alpha.2; no-op otherwise). The legacy option key + `jedb_bridge_types__previous` are also listed in `uninstall.php` for full cleanup.
- **4 lines of bootstrap wiring** for the Bridges tab in `includes/admin/class-admin-shell.php`.

Net deletion: ~1,500 lines.

### Added

- **`JEDB_OPTION_FIELD_PRESETS` constant** + activation default `array()` in `je-data-bridge-cc.php`. Stores the new portable field-preset library (D-26 / BUILD-PLAN §4.12).
- **`JEDB_Field_Presets_Manager`** in `includes/admin/class-field-presets-manager.php` — Day 1 ships the read-only API: `get_all()` / `get_for_target()` / `get_by_slug()` / `count_all()` + `default_preset()` / `default_field()` shape factories. Full CRUD + admin tab UI ships in Phase 4 Day 4.
- **`STATUS_SKIPPED_DIRECTION_OVERRIDE`** constant in `JEDB_Sync_Log` for the new direction-override engine guard. Distinct from `STATUS_SKIPPED_LOCKED` so editors can filter the sync log by per-product direction overrides specifically. The lock guard (per-product `_jedb_bridge_locked`) reuses `STATUS_SKIPPED_LOCKED` with a `reason: per_product_lock` field in `context_json` to disambiguate from the in-flight cascade lock.
- **Per-product engine guards** in `JEDB_Flattener::apply_bridge()` and `JEDB_Reverse_Flattener::apply_bridge()`. Inserted right after the existing cascade-prevention check, before condition DSL evaluation. Read `_jedb_bridge_locked` and `_jedb_bridge_direction_override` post meta off the target post (forward push) or post-that-just-saved (reverse pull). Different signal source / lifetime / intent from the existing `JEDB_Sync_Guard` cascade lock — see L-026 audit table for the full disambiguation. Forward push respects `pull` and `none` overrides; reverse pull respects `push` and `none` overrides.

### Changed

- **`JEDB_Flatten_Config_Manager::default_config_json()`** — extended:
  - `meta_box: { enabled, title, position, groups[] }` block (D-27 / §4.5). Day 2 Bridge meta box reads it.
  - `cct_single_redirect: bool` top-level flag (D-27 / §4.6). Day 3 redirect shim reads it. Default OFF.
- **`JEDB_Flatten_Config_Manager::default_mapping()`** — extended with three new per-mapping fields:
  - `surface_on_target: bool` — Day 2 meta box renders an editable input for this mapping when true AND the target adapter's `is_natively_rendered()` returns false (D-16 composes naturally).
  - `surface_on_source: bool` — forward-compat for an eventual CCT-side meta box. Stored but no consumer in Phase 4.
  - `group: string` — freeform per-mapping label used by the meta box for visual grouping ("Pricing", "Identity", etc. — admin types whatever, no enum). Per D-26.
- **`JEDB_Flatten_Config_Manager::merge_with_defaults()`** — back-compat: deep-merges the new `meta_box` block, defaults `cct_single_redirect` to false, and casts the new per-mapping fields. Existing 0.5.x flatten configs read with the new keys filled in transparently.
- **`JEDB_Flatten_Config_Manager::default_meta_box()`** — new public static factory for the meta_box block defaults (used by the merge path + the Flatten admin tab template).
- **Flatten admin tab editor (`templates/admin/tab-flatten.php`)** — three new UI elements:
  - **CCT-single redirect checkbox** (between Reverse-direction options and Enabled). Stores into `cct_single_redirect`. Description references BUILD-PLAN §4.6 + the Day 3 deliverable.
  - **"Meta box settings" collapsible section** (between the form table and Mandatory coverage). Controls: enabled checkbox, title text input, position radio (normal/side/advanced), groups CSV text input. Open by default.
  - **"Meta box" column** added to the field mappings table. Per-row: Target checkbox, Source checkbox, Group text input. Stacked compact layout.
- **`assets/js/flatten-admin.js`** — `makeMappingRow()` + `readMappingsFromDom()` extended to render and read the new column. `buildConfig()` extended to capture `meta_box.*` + `cct_single_redirect`. New change/input listeners wire the new controls into `syncJSON()`.
- **`assets/css/admin.css`** — added `.jedb-flatten-meta-box-section` collapsible styles + `.jedb-mapping-meta-cell` for the per-mapping meta column.
- **`uninstall.php`** — added `jedb_field_presets` to the cleanup list. Legacy `jedb_bridge_types` + `jedb_bridge_types__previous` retained for installs that ran alpha.1/alpha.2.

### Behavior unchanged

- **Engine paths byte-identical to v0.5.3** outside of the new per-product guards (which are skip-only — they never modify data, just early-return with a sync_log row). Forward push, reverse pull, Sync_Guard cascade prevention, condition DSL, mappings loop, taxonomy applier, sync log, all four target adapters: untouched.
- **Existing flatten configs work unchanged.** Back-compat handled in `merge_with_defaults()`. The new schema fields default-fill on read; no migration runs.
- **No new tables.** No new admin tabs in this release (the Field Presets tab ships in Day 4).

### Migration / upgrade notes

- **Automatic.** Install over an alpha.1 / alpha.2 install: the Bridges tab disappears, the `jedb_bridge_types` option is dropped on next activation hook, the new flatten config schema fields default-fill on read for any existing rows, the new constants exist, the new engine guards are no-ops until editors set the post meta. No manual action required.
- **`_jedb_bridge_locked` / `_jedb_bridge_direction_override` post meta** can be set programmatically TODAY for any product/variation. The Day 2 meta box will provide a UI for these; this release just wires the engine to respect them.

### Architectural notes locked (added to `BUILD-PLAN.md` §8)

- **D-25** — bridge type template layer retired. `jedb_bridge_types` option dropped. The flatten config IS the bridge identity. (Supersedes D-5.)
- **D-26** — field presets are first-class portable artifacts: target-scoped (single adapter), freeform groups, three application modes (display / apply / scaffold).
- **D-27** — Phase 4 Bridge meta box reads flatten config directly. No bridge type select, no clone-from-template, no parallel storage.

### Lessons learned (added to `LESSONS-LEARNED.md`)

- **L-025 postscript** — the schema-mirror rule remains valid for any future case where two systems must mirror each other (e.g. Phase 6 setup preset format), but the deeper meta-rule from L-026 — "don't add a template layer until you have ≥2 real consumers driving the design" — applies first.
- **L-026** — premature template-layer abstraction. Six prevention rules including: "When System A is a template for System B, A's inner shape MUST mirror B's. Don't gratuitously rename keys" (carried over from L-025) and "Make the copy-paste workflow a first-class design criterion."

---

## [0.6.0-alpha.2] — 2026-05-10

**Phase 4 / Day 1 hotfix — bridge type schema realigned with flatten config (L-025).**

Staging testing surfaced a UX trap in alpha.1: pasting a working flatten
config's "Advanced JSON" into the Bridges admin tab "Defaults JSON"
textarea would save successfully but silently drop every mapping and
taxonomy rule. Cause: alpha.1 used `default_field_mappings`,
`default_taxonomies`, `default_condition`, `default_priority` keys at
the top level of a bridge type. Flatten configs use `mappings`,
`taxonomies`, `condition`, `priority` (no prefix). When the user
pasted, `wp_parse_args` sanitization was looking for the prefixed keys,
didn't find them, and silently fell back to the empty defaults.

The fix locks in the architectural principle: **a bridge type IS a
flatten config template; their inner shapes must mirror each other.**
Documented as L-025.

### Changed (schema)

- **`JEDB_Bridge_Types_Manager::default_bridge_type()`** restructured.
  The bridge type now has top-level metadata (`slug`, `label`,
  `description`, `source_target`, `target_target`, `direction`,
  `enabled`, `cct_single_redirect`, `variations`, timestamps) plus a
  single `flatten_defaults` sub-object that mirrors
  `JEDB_Flatten_Config_Manager::default_config_json()` EXACTLY —
  same keys, same shapes (`mappings`, `taxonomies`, `condition`,
  `condition_snippet`, `priority`, `trigger`, `link_via`,
  `auto_create_target_when_unlinked`, `required_overrides`,
  `origin_tag`).
- **Top-level field renames:**
  `default_direction` → `direction`.
- **Moved into `flatten_defaults` sub-object:**
  `default_field_mappings` → `flatten_defaults.mappings`,
  `default_taxonomies` → `flatten_defaults.taxonomies`,
  `default_condition` → `flatten_defaults.condition`,
  `default_priority` → `flatten_defaults.priority`,
  `link_via` → `flatten_defaults.link_via`,
  `auto_create_target_when_unlinked` →
  `flatten_defaults.auto_create_target_when_unlinked`.

### Added

- **`JEDB_Bridge_Types_Manager::default_flatten_defaults()`** — public
  static helper returning the inner block defaults. Delegates to
  `JEDB_Flatten_Config_Manager::default_config_json()` when that class
  is loaded; otherwise returns a hard-coded mirror. Single source of
  truth for the inner shape.
- **`JEDB_Bridge_Types_Manager::upgrade_alpha1_shape()`** — silent on-read
  back-compat migration. Detects alpha.1-shaped entries (top-level
  `default_*` keys) and lifts them into `flatten_defaults` without
  any user action. Idempotent. Persists in alpha.2 shape on the
  next save. No data loss for editors who already created bridge
  types under alpha.1.
- **`JEDB_Tab_Bridges::unwrap_flatten_payload()`** — accepts three
  paste shapes for the JSON textarea:
  (1) raw flatten config inner block (most common — copy from the
  Flatten admin tab's Advanced JSON),
  (2) `{ "flatten_defaults": { ... } }` wrapper from a bridge type
  export,
  (3) full bridge type entry (the inner `flatten_defaults` is
  unwrapped automatically).
  All three round-trip cleanly.
- **Form fields for `priority` and `condition`** added to the bridge
  type editor. Both write into `flatten_defaults.*` and override
  what's in the pasted JSON for those specific keys (form is the
  source of truth for keys it manages).

### Fixed

- **L-025: silent data loss** when pasting raw flatten "Advanced JSON"
  into the Bridges admin tab. Root cause was the alpha.1
  `default_*` key naming. After this release the textarea accepts
  raw flatten payloads verbatim.

### Behavior unchanged

- **Still no engine code touched.** `JEDB_Flattener`,
  `JEDB_Reverse_Flattener`, `JEDB_Sync_Guard`,
  `JEDB_Taxonomy_Applier`, every transformer, condition evaluator,
  and all four target adapters are byte-identical to v0.5.3 / alpha.1.
- **Existing flatten configs unchanged.** `wp_jedb_flatten_configs`
  rows from v0.4.0 → v0.5.3 keep working through the Flatten admin
  tab as before.

### Migration / upgrade notes

- **Automatic migration on read.** If you saved any bridge types in
  alpha.1, their alpha.1-shaped entries silently migrate to alpha.2
  shape on the next read. The next save persists the new shape. No
  manual action required. No data loss.
- **No schema migration** for the option itself — it's still a flat
  indexed array in `jedb_bridge_types`.

### Architectural lesson locked

- **L-025 (`LESSONS-LEARNED.md`):** "When system A is a template
  for system B, A's inner shape MUST mirror B's. Don't gratuitously
  rename keys — every rename is a paper cut every time the user
  copy-pastes between the two surfaces, and silent renames cause
  silent data loss."

---

## [0.6.0-alpha.1] — 2026-05-06

**Phase 4 / Day 1 — Bridges admin tab + `JEDB_Bridge_Types_Manager`.**

First slice of Phase 4. The long-reserved `jedb_bridge_types` site
option (`JEDB_OPTION_BRIDGE_TYPES`, scaffolded since v0.1.0) finally
gets a UI. Bridge types are *templates* — each declares a
`source_target`, `target_target`, `default_direction`, `link_via`,
default field mappings, default taxonomy rules, and the §4.6 redirect
opt-in. The Phase 4 Day 2 Bridge meta box on the Woo product edit
screen will clone a bridge type into a concrete `wp_jedb_flatten_configs`
row when an editor wires up an individual product.

This release is `alpha.1` because Phase 4 isn't feature-complete —
Day 1 ships the definitions layer; Day 2 ships the meta box (consumer);
Day 3 ships the redirect shim. No staging-test gate yet.

### Added

- **`JEDB_Bridge_Types_Manager`** —
  `includes/admin/class-bridge-types-manager.php`. CRUD wrapper around
  the `jedb_bridge_types` option (a flat indexed array, sanitized +
  validated on every write). Public API: `get_all()`, `get_enabled()`,
  `get_by_slug()`, `get_for_post_type()`, `count_all()`, `count_enabled()`,
  `upsert()`, `set_enabled()`, `delete()`, `replace_all()`,
  `prepare_for_storage()`, `default_bridge_type()`. Validation enforces:
  unique slug, both source and target required, source ≠ target, JE
  Relation must have a relation_id selected, `cct_single_post_id` link
  type only valid when source is a CCT. Fires `jedb/bridge_types/changed`
  action on every successful write.
- **`JEDB_Tab_Bridges`** — `includes/admin/class-tab-bridges.php`.
  Admin tab between Relations and Flatten (priority 25, label
  "Bridges"). Form actions: `jedb_bridges_save` (upsert),
  `jedb_bridges_toggle` (enable/disable), `jedb_bridges_delete`,
  `jedb_bridges_import`. AJAX endpoints: `jedb_bridges_export`
  (download all as JSON), `jedb_bridges_get_relations_for_pair`
  (live JE Relation picker scoped to the chosen source/target).
  Relation lookup delegates to the existing `JEDB_Tab_Flatten::
  get_relations_between()` helper — no duplication.
- **`templates/admin/tab-bridges.php`** — list of existing bridge
  types as a sortable table + add/edit form. Includes the standard
  link-via picker with self-heal options, a "Defaults JSON" textarea
  (mappings + taxonomies + variations) for hand editing, the
  `cct_single_redirect` opt-in for the §4.6 redirect shim (Day 3),
  the `auto_create_target_when_unlinked` default that gets cloned to
  reverse bridges, and inline JSON import dialog with a
  destructive-replace-all toggle.
- **`assets/js/bridges-admin.js`** — vanilla DOM + jQuery (matches
  flatten-admin.js style, no build step). Behavior: live relation
  picker refresh on source/target change (debounced 250ms), JSON
  export download, import dialog open/close, slug auto-fill from
  label on add (stops auto-filling once user types in slug), JSON
  textarea live border-color validation (green/red).
- **CSS** — `.jedb-bridges-tab`, `.jedb-bridges-list`,
  `.jedb-bridges-import-dialog`, `.jedb-pill-info` blue variant.
  Appended to `assets/css/admin.css`.
- **Asset enqueue** — `JEDB_Admin_Shell::enqueue_assets()` now
  enqueues `bridges-admin.js` when the Bridges tab is active
  (matching the Flatten tab pattern).

### Behavior unchanged

- **No engine code touched.** The flattener, reverse flattener,
  taxonomy applier, sync guard, sync log, and every transformer
  are byte-identical to v0.5.3. Bridge types are a configuration
  template layer that doesn't run at sync time — Day 2's meta box
  is the consumer.
- **Existing flatten configs unchanged.** `wp_jedb_flatten_configs`
  rows created in 0.4.0 → 0.5.3 keep working through the Flatten
  admin tab exactly as before. Day 2 will add the meta box as a
  *second* path to creating flatten configs.

### Migration / upgrade notes

- No schema migration. `jedb_bridge_types` was already created with
  an empty array as default during Phase 0 activation.
- No breaking changes. Existing 0.5.x sites can install 0.6.0-alpha.1
  with no functional changes until they actually create a bridge
  type AND wire it through the (Day 2) meta box.

### Known limitations of Day 1

- The "Defaults JSON" textarea is the only way to set up
  `default_field_mappings[]` and `default_taxonomies[]` — there's
  no per-mapping picker UI in the bridge type editor. Editors are
  expected to clone the JSON from a working flatten config that
  was set up the regular way. Phase 4 Day 2's meta box will provide
  a friendlier surface; the Day 1 form is a config-as-data backbone.
- Bridge type changes do NOT propagate to existing flatten configs
  (templates not bindings — surfaced in the UI).
- The variation reconciliation block in the JSON is stored but not
  consumed yet (Phase 4b).

### Architectural notes locked

- **Storage:** flat indexed array in a site option, not a custom
  table. Bridge types are a small list per site; total payload is
  tiny; change frequency is low. Custom table would be overkill.
- **Templates not bindings:** bridge type defaults are cloned at
  link-time. Editing a bridge type doesn't retroactively change
  products already linked to it. Surfaced in the UI with an explicit
  warning callout.
- **Target-agnostic:** the bridge type editor lists every adapter
  kind (CCT, CPT, Woo Product, Woo Variation) — Phase 4's Bridge
  meta box is the only Woo-specific surface (per BUILD-PLAN §4.5
  scope note).

---

## [0.5.3] — 2026-05-06

**Phase 3.6 hotfix — engine ordering bug + term_lookup zero-resolve warning.**

End-to-end testing on Brick Builder HQ staging surfaced a real
silent-data-loss bug in the v0.5.2 engine ordering. Bridges with
both a `taxonomies[]` rule AND a field mapping that targets the
SAME taxonomy slot (e.g. mapping `theme_idea → category_ids` via
`term_lookup` plus a static rule applying `mosaics`) ended up with
zero categories on the product even though the sync log reported
success.

### Fixed

- **`JEDB_Flattener::apply_bridge()` now runs mappings BEFORE
  taxonomies** (was: taxonomies before mappings). Per L-024:
  field mappings that target taxonomy fields like `category_ids`
  call `WC_Product::set_category_ids()`, a typed setter that
  REPLACES the entire taxonomy slot. Running the taxonomy applier
  before that meant the applier's `wp_set_object_terms()` calls
  got clobbered. New ordering means rules ALWAYS get the final
  word — append rules pile on top of whatever the mapping wrote;
  replace rules become canonical. **No more silent category
  disappearances.**

- **`JEDB_Transformer_Term_Lookup::apply_push()` now logs a warning**
  when input had non-empty candidate values but ALL of them failed
  to resolve to term IDs. Most common cause is a `match_by` /
  value-shape mismatch (e.g. `match_by='name'` but the CCT field
  stores slug-style values like `"available-sets"`). Log line
  includes the unmatched values + a hint suggesting the editor try
  the other `match_by` variant.

### Engine status determination — refactored

The success/error/noop branching at the end of `apply_bridge()` is
now structured into four explicit paths that read top-to-bottom:

1. Mappings produced a payload AND adapter write failed → `errored`.
2. Mappings produced a payload AND adapter write succeeded
   (regardless of taxonomies) → `success`. Message includes both
   field count and any taxonomy term changes.
3. Mappings noop'd but taxonomies changed terms → `success` with
   `taxonomies_only: true` marker.
4. Nothing changed anywhere → `noop`.

This is cleaner than the v0.5.2 nested-conditional version and makes
the audit trail in `wp_jedb_sync_log` more predictable.

### Documentation

- **L-024** added to `LESSONS-LEARNED.md` — full root-cause analysis
  with the user's actual sync log row showing the lying-success
  output, the trace of what happened in the broken order, the new
  ordering, and five prevention rules.
- **BUILD-PLAN.md §4.11 "Engine integration order on push"**
  rewritten with the new ordering and a callout explaining why
  taxonomies-after-mappings is correct despite the original design's
  intuition.

### Notes for upgraders

- **No schema migration.** Behavior fix only. Existing 0.5.2 bridges
  that were affected by the bug will start working correctly on the
  next save.
- **No config changes required.** Bridges with both `taxonomies[]`
  rules AND `category_ids` mappings will now both take effect.
  Mappings write first, taxonomies enforce final state.
- **If you saw "ghost successes" in your sync log on 0.5.2** (rows
  with `terms_added: N` but the product showed no terms), those
  were instances of L-024. The data hasn't been retroactively fixed
  — re-save the affected CCT rows on 0.5.3 and the engine will do
  the right thing this time.

## [0.5.2] — 2026-05-06

**Phase 3.6 — categorization layer.**

End-to-end taxonomy support per D-20 → D-24 / L-023 / BUILD-PLAN
§4.11. Bridges can now categorize posts on push via two complementary
mechanisms: a new `term_lookup` transformer for per-row dynamic
categorization, and a new `taxonomies[]` array on flatten configs
for static-per-bridge multi-taxonomy assignment. Push-only semantics
in v1 (D-21) — pull never modifies taxonomies.

### Added — runtime engine

- **`JEDB_Transformer_Term_Lookup`** (`includes/flatten/transformers/class-transformer-term-lookup.php`)
  — new built-in transformer registered alongside the existing nine.
  Push: names/slugs/IDs → term IDs (array). Pull: term IDs →
  names/slugs (string or array, configurable via `output` arg).
  Composes naturally with `mappings[]` entries that target Woo's
  `category_ids` / `tag_ids` typed-setter fields.

- **`taxonomies[]` array** on `wp_jedb_flatten_configs.config_json`.
  Each entry is one taxonomy rule with `taxonomy`, `apply_terms`,
  `apply_terms_inverse`, `match_by`, `merge_strategy`,
  `create_if_missing`, and a forward-compat `snippet` slot
  (Phase 5b). Defaults per `JEDB_Flatten_Config_Manager::default_taxonomy_rule()`:
  `merge_strategy='append'`, `create_if_missing=false`, `match_by='slug'`
  per D-22.

- **`JEDB_Taxonomy_Applier`** (`includes/flatten/class-taxonomy-applier.php`)
  — applies the rules during forward push between the condition check
  and field mappings. Per rule: validates the taxonomy is registered
  + applicable to the target post type, resolves apply/inverse term
  refs (with optional `wp_insert_term()` for `create_if_missing`),
  calls `wp_set_object_terms()` and `wp_remove_object_terms()`,
  returns a structured per-rule outcome.

- **Forward `JEDB_Flattener` integration** — calls the applier between
  condition check and mappings. Sync log `context_json` now carries a
  `taxonomies` summary with `rules_processed`, `rules_applied`,
  `terms_added`, `terms_removed`, `terms_created`, plus per-rule
  outcome arrays. **A bridge with no mappings but with taxonomy
  rules is now a valid bridge** — the engine no longer short-circuits
  on empty `mappings`. When mappings are all-noop but taxonomies
  changed terms, the row logs `success` with a `taxonomies_only`
  marker.

- **Reverse `JEDB_Reverse_Flattener` skips taxonomies entirely**
  (D-21 push-only semantics). Pull only writes mapped CCT fields.

### Added — admin UI

- **Flatten admin tab gains a "Taxonomies (push only)" collapsible
  section** between the Mandatory Coverage panel and the Field
  Mappings table. Visible only when `target_target` is `posts::*`.
  Per rule: taxonomy dropdown (live-queried), apply-terms multi-
  select, inverse-terms multi-select, match-by select, strategy
  select, `create_if_missing` checkbox, remove button. "Add taxonomy
  rule" + "Refresh from site" buttons in the table footer.
  Status pill in the section header shows "no rules" / "N rule(s)".

- **AJAX endpoint `wp_ajax_jedb_flatten_get_post_type_taxonomies`** —
  returns `{post_type, taxonomies: [{slug, label, hierarchical,
  public, terms_count, truncated, terms: [...]}]}` for a given post
  type. Powers the dropdowns. Truncates to `JEDB_TAX_TERMS_LIMIT`
  (default 100, override via `define`) — taxonomies with more terms
  show the editor a "showing first 100 of N" notice.

- **JS rebuilds the apply/inverse multi-selects whenever the
  taxonomy or `match_by` value changes**, preserving the previous
  selection where possible. The multi-select stores values per the
  current `match_by` (slugs by default, names or IDs as configured).

### Added — bootstrap

- **`JEDB_TAX_TERMS_LIMIT` constant** (default 100) — configurable
  ceiling on the AJAX endpoint's per-taxonomy term return count.

### Plumbing

- `class-plugin.php` `load_core()` requires + instantiates
  `JEDB_Taxonomy_Applier::instance()` between Sync_Log and
  Flattener.
- `class-flatten-config-manager.php` `default_config_json()` adds
  `taxonomies` (default `[]`); new `default_taxonomy_rule()` factory;
  `merge_with_defaults()` deep-merges `taxonomies[]` so existing
  0.5.x bridges get well-formed defaults on read.

### Notes for upgraders

- **No schema migration needed.** `taxonomies[]` is a new top-level
  key on the JSON-encoded `config_json` column; bridges saved before
  0.5.2 get an empty array filled in on read.
- **Existing 0.5.x push and pull bridges are unaffected** — they
  simply have an empty `taxonomies[]` and the engine skips that
  step. Add rules from the Flatten admin tab when ready.
- **Reverse pull bridges ignore `taxonomies[]` entirely** — push-only
  per D-21. Editors who want post categorization to gate or trigger
  reverse syncs should wait for Phase 4.5's `term_assigned` trigger
  per D-18.

## [0.5.1] — 2026-05-06

**Documentation + small cleanups; no behavior change.**

End-to-end testing of v0.5.0 on Brick Builder HQ staging surfaced an
architectural asymmetry between the forward and reverse cycle-
prevention paths. The cross-direction `Sync_Guard::is_locked()`
checks at the top of each engine's `apply_bridge()` are correct, but
on the reverse-pull side they're effectively dead code under
current JetEngine behavior — because JE's `$cct->db->update()`
doesn't fire the `updated-item/{slug}` hook that would have
triggered the forward push to wake up. The cycle architecturally
cannot form on that side; on the forward push side it can (WC's
`WC_Product->save()` does fire its hooks). Both sides keep the
defensive check; this release just documents what each does.

### Documentation

- **`LESSONS-LEARNED.md` L-022** added — full root-cause analysis
  with five sync-log rows of evidence + the cross-direction
  asymmetry table. Locks in the understanding that the reverse
  pull → forward push cascade is non-recurring by JE design, not
  by our defensive code; the defensive code stays as insurance for
  future JE behavior changes / third-party hook re-firers / Phase
  4 meta-box manual-sync paths that DO fire the hook.

- **`BUILD-PLAN.md` §4.10** gets a small footnote referencing
  L-022's asymmetry finding so future readers don't expect
  cascade markers on every reverse-pull row.

### Improved (papercut closure)

- Forward and reverse `noop` / `skipped_no_target` / `skipped_locked`
  log rows now include `resolution` and (where applicable)
  `cascade` keys in their `context_json`. Symmetric with the
  `success` / `errored` rows that already had them. Closes the
  v0.4.1 / v0.5.0 inconsistency the user flagged in test session
  rounds 2 + 3.

### Notes for upgraders

- **No schema change, no behavior change, no migration needed.**
  This is purely a documentation release plus a few extra context
  fields in sync_log rows that were already being written.
- **Existing bridges are unaffected.** The defensive cross-direction
  lock check has been in place since v0.5.0; this release only
  documents that the pull → push side of the cascade doesn't
  currently fire because JE's API doesn't surface the trigger event.

## [0.5.0] — 2026-05-06

**Phase 3.5 — reverse-direction (post → CCT) flatten engine + bidirectional bridges.**

End-to-end bidirectional sync now works on a real site. Editing a Woo
product (or any bridged CPT) directly propagates back to the linked
CCT row, gated by per-bridge conditions and a dedicated
`pull_transform` chain. Bridges can declare `direction = pull` for
reverse-only, or `direction = bidirectional` for both directions in
one bridge with mutual cascade prevention.

### Added — runtime engine

- **`JEDB_Reverse_Flattener`** (`includes/flatten/class-reverse-flattener.php`)
  Mirror of `JEDB_Flattener`. Hooks at priority
  `JEDB_FLATTEN_HOOK_PRIORITY` (= 20):
  - `woocommerce_update_product` + `woocommerce_new_product` for
    `posts::product` targets.
  - `woocommerce_update_product_variation` + `woocommerce_new_product_variation`
    for `posts::product_variation` targets.
  - `save_post_{post_type}` for any other CPT target, with explicit
    auto-save / revision filtering so we don't fire on every minor
    draft churn.

  For each registered bridge, on hook fire:
  1. Resolves the source CCT row's `_ID` given the post id (see
     `resolve_source_id()` below).
  2. Cross-direction cascade check: if the forward push lock is held
     for these exact coords, this is a forward-engine side-effect —
     bail with `skipped_locked`.
  3. Evaluates the bridge's condition (DSL or snippet) against the
     same `$context` shape as the forward engine, just with
     `direction = 'pull'`.
  4. For each enabled mapping, reads the target (post) field, runs
     it through `pull_transform`, diffs against current source field,
     writes only the differences.
  5. Acquires its own pull-direction Sync_Guard lock for the duration
     of the write. The CCT save it triggers fires JE's
     `updated-item/{slug}` hook — the forward engine's
     cross-direction check sees our pull lock and bails. **No infinite
     loop.**
  6. Records every outcome to `wp_jedb_sync_log` with `resolution`,
     `auto_attached`, `auto_created` flags so the user can see
     exactly how the link was found.

- **`resolve_source_id()`** — the reverse-direction analog of L-021's
  forward resolver:
  - Path A (`link_via.type = 'cct_single_post_id'`) — direct lookup
    of the CCT row whose `cct_single_post_id` column equals the post
    id, with auto-create fallback.
  - Path B (`link_via.type = 'je_relation'`) — relation-table lookup
    first (post on the side opposite the source CCT), fallback to
    `cct_single_post_id`-by-post lookup with optional relation
    auto-attach (mirrors L-021), and finally optional auto-create of
    a fresh CCT row when nothing else resolves.

- **Auto-create CCT row** (D-17 opt-in, default OFF) — when a bridge
  has `auto_create_target_when_unlinked = true`, saving an unlinked
  post creates an empty CCT row in the bridge's source target via
  `JEDB_Target_CCT::create([])`, optionally auto-attaches the
  relation row, then lets the normal apply pipeline populate the new
  row through the `pull_transform` chain. **The user's transformer
  config is the single source of truth for what gets written** — the
  resolver doesn't seed any fields itself.

### Added — direction model expansion

- Bridge config `direction` field now accepts three values:
  - `push` — forward only (Phase 3 default)
  - `pull` — reverse only
  - `bidirectional` — both engines register hooks; mutual cascade
    prevention via cross-direction `Sync_Guard::is_locked()` checks
    at the top of each engine's `apply_bridge()`.

- **Forward `JEDB_Flattener` updated:**
  - Registration filter now matches `direction in (push, bidirectional)`.
  - New cross-direction cascade check at the top of `apply_bridge()`:
    if the reverse-pull lock is currently held for these coords, the
    forward push is a side-effect of a reverse pull — bail with
    `skipped_locked` and `cascade: pull_in_flight`.

### Added — admin UI

- **Direction radio** (`tab-flatten.php`):
  - "Push (source → target) — fires on CCT save"
  - "Pull (target → source) — fires on post save" *(was disabled in 0.4.x)*
  - "Bidirectional — registers both hooks, mutual cascade prevention"

- **Reverse-direction options** fieldset:
  - "Auto-create the source CCT row when an unlinked post saves"
    checkbox — wired to `auto_create_target_when_unlinked` config flag.

- JS (`flatten-admin.js`) wires the new radios + checkbox into the
  hidden `config_json` payload so the form survives roundtrips.

### Improved (was the v0.4.1 papercut)

- Forward flattener's `skipped_condition` log row now includes
  `resolution` and `auto_attached` in `context_json`. The 0.4.1
  test session noted these were missing; closed in this release. The
  `skipped_error`, `noop`, and `skipped_locked` rows also gain
  `resolution` for symmetric debuggability.

### Plumbing

- `class-plugin.php` `load_core()` requires + instantiates
  `JEDB_Reverse_Flattener::instance()`.
- `class-flatten-config-manager.php` `default_config_json()` adds
  the `auto_create_target_when_unlinked` key (default `false`).
  `wp_parse_args` top-level merge already backfills this on existing
  bridges saved before 0.5.0.

### Notes for upgraders

- **No schema migration needed.** The `direction` column already
  accepts varchar(20); 'pull' and 'bidirectional' fit. Existing
  push-only bridges work unchanged.
- **Existing 0.4.x bridges are upgraded transparently** — the
  flatten config manager's deep-merge fills in the new
  `auto_create_target_when_unlinked: false` default the first time
  the config is read.
- **Bidirectional bridges are safe by default.** The cross-direction
  cascade check is automatic; no opt-in flag needed. The only way to
  trigger an actual loop would be to write transformer chains that
  intentionally produce different values on each pass — and even then
  the `Sync_Guard` per-request statics catch the second iteration.

## [0.4.1] — 2026-05-03

**Phase 3 hotfix — JE Relation row self-heal.**

End-to-end testing on Brick Builder HQ staging surfaced a critical
gap in the original L-016 / D-17 documentation: I had stated JE
"auto-creates the related post on CCT save" without distinguishing
between **two separate** JE auto-create features. The reality, now
locked as L-021:

| JE feature | What it auto-creates on CCT save |
|---|---|
| Has-Single-Page | A linked post (CPT or Woo product), ID stored in `cct_single_post_id` |
| JE Relation row in `{prefix}jet_rel_{id}` | **Nothing** — only writes when the user attaches via picker |

So a fresh CCT row with Has-Single-Page enabled gets a real linked
post BUT no relation row, and the v0.4.0 flattener (which only
checked the relation table) logged `skipped_no_target` even though
the link was discoverable via `cct_single_post_id`.

### Fixed

- **`JEDB_Flattener::resolve_target_id()` self-heals missing relation rows.**
  Resolution now follows a 3-step chain when `link_via.type = 'je_relation'`:
  1. JE Relation row lookup (fast path).
  2. Fallback to `cct_single_post_id` when no relation row exists,
     `link_via.fallback_to_single_page` is on (default), and the
     linked post's type matches the relation's other endpoint.
  3. Auto-attach the missing relation row via the existing idempotent
     `JEDB_Relation_Attacher::attach()` when `link_via.auto_attach_relation`
     is on (default). After the first sync, JE Smart Filters /
     Listing Grids / Query Builder traversals work natively, and
     subsequent syncs use the fast path. **Self-heal.**

  Verified: the fallback rejects type-mismatched candidates (e.g.
  won't bridge a `story_bricks` post into a `mosaics_data → product`
  relation) via a new private `verify_single_post_matches_relation()`
  helper.

### Added

- **Two new bridge-config flags** (both default true to make the
  sensible behavior the default):
  - `link_via.fallback_to_single_page`
  - `link_via.auto_attach_relation`

  Exposed in the Flatten admin tab's "Self-heal options" fieldset
  with explanatory help text. The Flatten config manager's
  `merge_with_defaults()` now deep-merges the `link_via` subtree so
  bridges saved before 0.4.1 get the new keys filled in on read
  (no migration needed).

- **Sync log context now records resolution metadata.** Every
  `success` / `partial` / `errored` row's `context_json` carries:
  - `resolution`: `'relation_row'` | `'fallback_single_page'` |
    `'cct_single_post_id'` | `'none'`
  - `auto_attached`: `bool` — true when the fallback wrote a relation row

  And every `skipped_no_target` row carries:
  - `has_single_page`: `bool` — whether the source had a non-zero
    `cct_single_post_id`
  - `resolution`: which step the resolver gave up at

  This makes diagnosing future link-resolution bugs trivial without
  re-running the sync.

### Documentation

- **`LESSONS-LEARNED.md` L-021** added — the most architecturally
  important entry to date. Distinguishes JE's two auto-create
  features in a verifiable table, captures the user's empirical
  evidence (relation table inspection + sync log SQL), and links
  to every code change.
- **`BUILD-PLAN.md` §4.5** rewritten — adds the "Self-heal:
  auto-attach when JE Relation row is missing" subsection.
- **D-13 / D-17 refined** in the Decisions Log to reflect the
  separation between relation *definitions* (manual / preset-only)
  and relation *rows* (self-heal automatic).

### Notes for upgraders

- **No schema migration needed.** Bridges saved in 0.4.0 work as-is
  with the self-heal behavior turned ON by default (the new flags
  default to true when missing from saved JSON).
- **If you want strict explicit-attach behavior**, edit each bridge
  in the Flatten tab and uncheck "Fall back to cct_single_post_id"
  and/or "Auto-attach the missing relation row".
- **Existing relation rows are never overwritten.** The auto-attach
  uses the existing idempotent `attach()` method which checks for
  duplicates first.

## [0.4.0] — 2026-05-02

**Phase 3 — forward-direction (CCT → post) flatten engine + admin tab.**

This is the first release that actually moves data between sources and
targets. Editing a CCT row now pushes mapped values onto its bridged
WooCommerce / CPT record automatically, gated by per-bridge conditions
and serialized through a per-direction transformer chain.

### Added — runtime engine

- **`JEDB_Sync_Guard`** (`includes/class-sync-guard.php`)
  Per-request static-lock + transient-backed cross-request lock keyed on
  `(direction, source, source_id, target, target_id)` with origin tagging.
  Catches recursive saves before they happen. Phase 3.5's reverse
  engine reuses this verbatim. Public hooks: `jedb/sync/lock_acquired`,
  `jedb/sync/lock_released`.

- **`JEDB_Sync_Log`** (`includes/class-sync-log.php`)
  Append-only writer for `wp_jedb_sync_log`. One row per bridge
  invocation regardless of outcome. Status taxonomy from BUILD-PLAN
  §4.9: `success`, `partial`, `errored`, `skipped_condition`,
  `skipped_error`, `skipped_locked`, `skipped_no_target`, `noop`.
  Includes `recent()` reader for the Phase 5 viewer + `purge_older_than()`
  for the Phase 5 retention cron.

- **`JEDB_Transformer_Registry` + `JEDB_Transformer` interface**
  (`includes/flatten/transformers/`). Per D-11 / L-010 every transformer
  defines push and pull as separate methods; built-ins ship as paired
  inverses where well-defined. Snippet-backed transformers (Phase 5b)
  register themselves via `jedb/transformer/register` action.

  Built-ins shipped this release:
  - `passthrough` — return value unchanged (default for new mappings)
  - `yes_no_to_bool` — bidirectional inverse pair
  - `regex_replace` — independent push/pull patterns
  - `format_number` — round / cast / decimal-cap, both directions
  - `lookup_table` — JSON dictionary; push key→value, pull value→key
  - `name_builder` — template like `{set_name} ({set_number})`; push only
  - `truncate_words` — word cap; push only (no inverse)
  - `strip_html` — push HTML→plain; pull no-op (cannot recover)
  - `year_expander` — PAC VDM port; "2018-2022" ↔ [2018,2019,…,2022]

- **`JEDB_Condition_Evaluator`**
  (`includes/flatten/class-condition-evaluator.php`)
  Hand-rolled tokenizer + recursive-descent parser + evaluator for the
  v1 declarative DSL from BUILD-PLAN §3.5. Operators: `==`, `!=`, `>`,
  `<`, `>=`, `<=`, `contains`, `not_contains`, `starts_with`,
  `ends_with`, `in`, `not_in`. Logical connectives: `AND`, `OR`, `NOT`.
  Path scopes: `source` / `cct` resolve to source data; `target` /
  `product` / `variation` resolve to target data. Validate-only mode
  (`validate()`) used by the admin tab's "Validate" button. Failure
  mode per D-2 / Q4: any parse or eval error returns `false` with a
  warning log so the bridge is skipped, not wrongly applied.

- **`JEDB_Flatten_Config_Manager`**
  (`includes/flatten/class-flatten-config-manager.php`)
  CRUD wrapper for `wp_jedb_flatten_configs`. One row per bridge.
  Column-level fields stay in sync with the matching keys in
  `config_json` so simple WHERE filters still work without decoding
  every row. `config_slug` auto-derived + uniqueness-suffixed (lets
  conditional bridges per D-14 share the same source/target/direction
  triple). Defaults factory + mapping defaults documented inline.

- **`JEDB_Flattener`** (`includes/flatten/class-flattener.php`)
  The forward-push engine. Wires hooks at `JEDB_FLATTEN_HOOK_PRIORITY`
  (= 20, per D-19 / L-018) on `created-item/{slug}` and
  `updated-item/{slug}` for every CCT that has at least one enabled
  push bridge. Per bridge:
  1. Resolves the linked target via `link_via.type` (JE relation
     lookup or `cct_single_post_id`).
  2. Builds the `$context` shape from BUILD-PLAN §4.9.
  3. Evaluates the condition (DSL or snippet — snippet path stubs to
     a "skipped_error" log entry until Phase 5b ships the runtime).
  4. For each enabled mapping: reads source value → runs
     `push_transform` chain → diffs against current target value →
     writes through the target adapter (which goes through
     `WC_Product->save()` for HPOS-safe lookup-table refresh, per
     L-017).
  5. Acquires `Sync_Guard` for the duration of the write so the
     resulting target-side save event can't recurse back into us.
  6. Records every outcome to `wp_jedb_sync_log`.

  Bridges sort by `priority` (default 100; lower runs first), tie-broken
  by id ASC for deterministic ordering.

### Added — admin UI

- **Flatten tab** (`includes/admin/class-tab-flatten.php` +
  `templates/admin/tab-flatten.php` + `assets/js/flatten-admin.js`).
  - Lists every flatten config with status pills + edit/enable/delete
    actions.
  - Add/edit form with source-target picker (CCT in v1 — CPT/Woo
    sources land in Phase 3.5 reverse direction), target-target picker
    (CPT / Woo Product / Woo Variation), link-via picker (JE Relation or
    `cct_single_post_id`), enabled toggle, condition DSL textarea +
    "Validate" button (calls AJAX into `JEDB_Condition_Evaluator::validate`),
    priority field.
  - **Mandatory coverage panel** (D-15) — surfaces every required field
    declared by the chosen target adapter so the editor knows what
    they're obliged to map.
  - **Field-mapping table** (D-12 explicit-only). Two-column source /
    target picker per row. Each row has TWO transformer chains side by
    side (`→ Push transformer`, `← Pull transformer`) per D-11. Each
    chain step has a transformer dropdown + dynamic args form
    (text/number/checkbox/select/textarea inputs) reflected from the
    transformer's `get_args_schema()`. Add / remove / chain-step
    buttons live in the table.
  - **Native-rendered hint** (D-16) appears in target-field labels:
    fields where `is_natively_rendered()` returns true display a
    "· native" suffix so editors know Phase 4's bridge meta box won't
    duplicate them. Required fields show a `★` prefix.
  - **Manual sync** form on the edit page lets the editor punch a
    source `_ID` and run the bridge once on demand. Result lands in
    `wp_jedb_sync_log`; URL surfaces the status code.
  - Raw-JSON `<details>` editor under the table for advanced edits.

- **AJAX endpoints**:
  - `jedb_flatten_get_target_schema` — returns target field schema +
    required-fields + per-field `natively_rendered` flag.
  - `jedb_flatten_validate_condition` — DSL syntax check.

### Added — data-target adapter contract

Per D-15 / D-16, two new methods on the `JEDB_Data_Target` interface
(implemented on the abstract base with safe defaults; overridden per
adapter):

| Method | Defaults |
|---|---|
| `get_required_fields()` | Abstract returns `[]`. CCT `[]`, CPT `['post_title']`, Woo Product `['name','status']`, Woo Variation `['parent_id','attributes']`. |
| `is_natively_rendered($field)` | Abstract returns `false`. CCT returns `true` for everything (JE renders all fields). CPT returns `true` for standard post columns. Woo Product / Variation return `true` for every typed-setter field. |

These ship now (Phase 3) rather than being deferred to Phase 4 because
the Flatten admin UI's mandatory-coverage panel and native-rendered hint
both need them.

### Added — bootstrap constants

- `JEDB_FLATTEN_HOOK_PRIORITY` (= 20) — single source of truth for the
  hook-priority contract (per D-19). Every flatten / reverse-flatten
  engine references this constant.

### Plumbing

- `class-plugin.php` `load_core()` now also loads + instantiates
  `JEDB_Sync_Guard`, `JEDB_Sync_Log`, and `JEDB_Flattener`. Transformer
  registry + Flatten config manager + Condition evaluator are loaded
  but lazy-instantiated on first call (no boot cost when no bridges
  exist).
- `class-admin-shell.php` registers the Flatten tab and conditionally
  enqueues `flatten-admin.js` only on its own screen.

### Notes for upgraders

- **No schema migration needed.** `wp_jedb_flatten_configs` and
  `wp_jedb_sync_log` were already created in Phase 0; this release
  starts using them.
- **Existing relation configs are untouched.** Phase 2's relation-
  picker behavior is unchanged.
- **Forward direction only.** Reverse-direction (post → CCT) is Phase
  3.5. Editing a Woo product directly does not yet propagate back to
  the CCT.

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
