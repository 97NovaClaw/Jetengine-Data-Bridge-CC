# Changelog

All notable changes to this plugin are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
