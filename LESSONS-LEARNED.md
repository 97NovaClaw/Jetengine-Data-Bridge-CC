# Lessons Learned

> **Purpose.** This document is the long-term memory of `JetEngine Data Bridge CC`.
> Every false assumption, API surprise, and architectural mistake we make against
> JetEngine, WooCommerce, WordPress, or our own codebase gets recorded here with
> the evidence that proved it wrong and the prevention rule that follows.
>
> Future development must read this document before touching anything in the
> following areas: CCT/CPT/Woo data adapters, the JetEngine config-storage
> resolver, the relation-attachment subsystem, the bridge meta box, the sync
> direction model, the snippet runtime, or the table-prefix discipline.
>
> ## Per-entry format
>
> Each entry follows the same template so audits and grep-scans stay reliable:
>
> ```
> ## L-NNN: <one-line summary>
> Discovered:   <YYYY-MM-DD> (Phase <X> / version <0.X.Y>)
> Severity:     {Critical | High | Medium | Low}
> Category:     {API drift | Wrong assumption | Architecture | Defensive coding | Documentation}
>
> ### Context        — what we were trying to do
> ### Wrong          — what we actually did / believed
> ### Evidence       — diagnostic output, source-plugin code, SQL, screenshot
> ### Reality        — what is actually true (verified, not assumed)
> ### Affected code  — files / functions / line ranges
> ### Fix shipped in — version + commit hash
> ### Prevention     — the rule that, if followed, avoids this class going forward
> ```
>
> Add new entries by appending at the bottom; never renumber existing ones.

---

## L-001: JetEngine may not define the `JET_ENGINE_VERSION` global constant

**Discovered:** 2026-04-28 (Phase 0 / version 0.1.1)
**Severity:** High
**Category:** API drift

### Context
The Status tab read JE's version to display it and to enforce the `>= 3.3.1`
minimum.

### Wrong
We checked only `defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : null`,
so the Status tab rendered "NOT DETECTED" on a site where JetEngine was clearly
loaded (left-sidebar menu items present, plugin booted successfully).

### Evidence
Screenshot from `bbhq.legworklabs.com` showed `JetEngine NOT DETECTED` despite
the JetEngine, JetPopup, Smart Filters, and JE-managed CPT items all visible
in the WP admin sidebar.

### Reality
JetEngine 3.8.5 exposes its version through several different channels and is
not guaranteed to define `JET_ENGINE_VERSION`. Real channels in priority order:
1. `JET_ENGINE_VERSION` (older builds)
2. `Jet_Engine::VERSION` (class constant)
3. `jet_engine()->get_version()` (instance method)
4. `jet_engine()->version` (instance property)
5. Plugin file header at `wp-content/plugins/jet-engine/jet-engine.php`

### Affected code
- `je-data-bridge-cc.php` `jedb_dependencies_ok()`
- `templates/admin/tab-hello.php` (Status tab)

### Fix shipped in
v0.1.1, commit `0f6c810`. New helper file
`includes/helpers/dependencies.php` with `jedb_get_jet_engine_version()` that
tries every channel in order and caches the result per request. Plugin boots
when JE is *active* even if no channel returns a version string.

### Prevention
Never trust a single API channel to detect a third-party plugin's state. Wrap
multi-channel detection in a helper, prefer instance methods over global
constants, and treat "presence" (function/class exists) as separate from
"version readable".

---

## L-002: `JEDB_Discovery::memo_set()` had no `return` statement

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.2)
**Severity:** Critical
**Category:** Defensive coding

### Context
Discovery's `get_all_*()` methods cached results to a transient via the
`maybe_cache() → memo_set()` helper chain.

### Wrong
`memo_set()` set the in-memory and transient caches correctly but had no
`return $value;` statement. The chain `return $this->maybe_cache(...)` therefore
returned `null` for every non-empty result, even when the underlying query had
just returned 4 CCTs / 26 post types.

### Evidence
- Targets tab on first visit showed `0 / 0 / 1 / 1` (only the two manually-
  registered Woo adapters; CCT and CPT discovery returned empty).
- Debug log: `count(): Argument #1 ($value) must be of type Countable|array,
  null given at class-target-registry.php:124`.
- Diagnostic showed `Raw CCT count from manager: 4` (raw call worked) but
  `JEDB_Discovery CCTs returned: NOT-ARRAY` (wrapper layer dropped it).
- Targets tab worked on the SECOND visit because the memo-as-side-effect
  populated the transient and a fresh discovery singleton hit the transient
  cache via `memo_get()`.

### Reality
A void-returning helper at the end of a `return` chain returns `null`. PHP 8+
TypeError on subsequent `count($null)` revealed it.

### Affected code
- `includes/class-discovery.php` `memo_set()`

### Fix shipped in
v0.2.2, commit `0d1e6c2`. Added `return $value;`. Also added defensive
`is_array()` coercion in `JEDB_Target_Registry::bootstrap_defaults()` so this
class of bug can never blank the page again, and improved diagnostic to report
`gettype()` on non-array returns (e.g., `NOT-ARRAY (NULL)`).

### Prevention
Any helper that participates in a `return ...` chain MUST return its value.
Lint rule (manual until automated): when a method's only callers are
`return $this->method(...)`, ensure it returns the value it's setting.

---

## L-003: `$cct_instance->db` is a public property on the JE CCT factory, not a method

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.3)
**Severity:** Critical
**Category:** API drift / Wrong assumption

### Context
`JEDB_Target_CCT` needed to call the JetEngine CCT db API for read/write.

### Wrong
Every `db`-touching method guarded with `method_exists($inst, 'db')`. That
check returns false (because `db` is a property, not a method), so `count()`,
`get()`, `update()`, `create()`, and `list_records()` silently fell through
to slower or null-returning fallbacks. Visible symptom: every CCT showed
`0 items` on the Targets tab even when its `wp_jet_cct_{slug}` table had rows.

### Evidence
PAC VDM source at `class-data-flattener.php:434` and RI source at
`class-data-broker.php:614` both use direct property access:
`$content_type->db->get_item($id)` and `$content_type->db->query($args, ...)`.
No `method_exists` guards.

### Reality
On the JE CCT factory class, `db` is a public property whose value is a
`Custom_Content_Types\DB` instance. Access pattern is
`isset( $inst->db ) && is_object( $inst->db ) && method_exists( $inst->db, '...' )`.

### Affected code
- `includes/targets/class-target-cct.php` (5 methods)

### Fix shipped in
v0.2.3, commit `c8533a4`. Rewrote all five methods with the correct guard
pattern, added direct-SQL fallback on `wp_jet_cct_{slug}` so a future JE API
change doesn't take counting/listing offline.

### Prevention
Distinguish properties from methods in API checks. When porting code from a
working source plugin, copy its access pattern verbatim — don't "improve" it
with extra guards that change semantics.

---

## L-004: `$cct->db->count()` does not exist — use direct SQL

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.3)
**Severity:** Medium
**Category:** Wrong assumption

### Context
Counting CCT items for the Targets-tab inventory.

### Wrong
Tried `$inst->db->count()`. This method doesn't exist on the JE CCT db class.
Even if L-003 hadn't been blocking, this call would have failed.

### Evidence
RI source uses `$content_type->db->query([], 0, 0)` to fetch all items, then
`count()`s the array. PAC VDM bulk-sync uses raw SQL on `wp_jet_cct_{slug}`.
Neither references a `count()` method on the db handle.

### Reality
The cheapest correct count is `SELECT COUNT(*) FROM \`wp_jet_cct_{slug}\``
via `$wpdb`. Always preceded by `SHOW TABLES LIKE` so a missing table returns
0 instead of a fatal.

### Affected code
- `includes/targets/class-target-cct.php` `count()`

### Fix shipped in
v0.2.3, commit `c8533a4`. `count()` now uses direct SQL with a table-existence
guard.

### Prevention
Verify a method exists in the source plugin's actual usage before assuming
it exists on a third-party API. When in doubt, write a SQL query against
the table — it's the most version-resilient path.

---

## L-005: JE non-data field types must be filtered from data schemas

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.3)
**Severity:** Medium
**Category:** Wrong assumption

### Context
`JEDB_Target_CCT::get_field_schema()` listed every field returned by JE.

### Wrong
JE's field-type universe includes visual organizers (`tab`, `section`,
`section_separator`, `heading`, `group_separator`, `group_break`,
`wysiwyg_separator`) that have no DB column and no value. Including them
inflated field counts on the Targets tab vs the JE UI.

### Evidence
Mosaic Data CCT showed 19 fields in the schema vs 14 user fields visible in
the JE editor. The diff matched: 4 JE internal columns + 1 unknown.

### Reality
These types are layout markers in the JE field config, not data fields. The
schema must skip them.

### Affected code
- `includes/targets/class-target-cct.php` `NON_DATA_FIELD_TYPES` constant +
  `get_field_schema()` filter

### Fix shipped in
v0.2.3, commit `c8533a4`. Added the constant and filter, plus dedup-by-name.

### Prevention
When iterating a third-party "fields" config, check the `type` field against
a known-good filter list. Don't assume every entry is a storable data field.

---

## L-006: JE system columns are valuable — surface them as readonly system fields

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.5)
**Severity:** Medium
**Category:** Architecture / Documentation

### Context
Earlier versions hard-filtered the JE system columns (`cct_status`,
`cct_author_id`, `cct_created`, `cct_modified`, `cct_single_post_id`) from
the schema entirely.

### Wrong
Hiding these columns blocks future use cases that depend on them:
- `cct_modified` is the source of truth for the Phase 7+ last-write-wins
  conflict resolution per BUILD-PLAN D-2.
- `cct_single_post_id` is JE's "Has Single Page" link — directly relevant
  to the Phase 4 Bridge meta box pattern (BUILD-PLAN §4.6).

### Evidence
User question on 2026-04-29 asking whether the filtered-out columns would
be useful later. Answer: yes, several of them.

### Reality
These columns must be visible to bridge / flatten configs for read access
(PULL, conditionals, display), but blocked from PUSH so editors don't
accidentally clobber JE-managed timestamps.

### Affected code
- `includes/class-discovery.php` `CCT_SYSTEM_COLUMN_NAMES`
- `includes/targets/class-target-cct.php` `get_field_schema()`,
  `update()`, `create()`

### Fix shipped in
v0.2.5, commit `79c14a9`. Schema lays out: `_ID` (system, readonly) → JE
system columns (each with `readonly: true`, `group: 'system'`, friendly
labels, `jedb_role` markers) → user fields. `update()` and `create()`
strip readonly fields with a warning log.

### Prevention
"Hide" is the wrong default for system data. Default to "expose as readonly"
so callers can read but not corrupt. Mark with role tags so future code
can pattern-match without name-based string checks.

---

## L-007: JetEngine 3.8+ stores ALL object configs in `{prefix}jet_post_types`

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.7 in flight)
**Severity:** Critical
**Category:** API drift

### Context
Resolving CCT field schemas (with proper types) so flatten configs and the
Phase 4 bridge meta box can populate field-picker dropdowns.

### Wrong
Resolver tried five channels in order: `get_arg('fields')`,
`get_arg('meta_fields')`, `$instance->args['fields']`,
`$instance->args['meta_fields']`, `get_option('jet_engine_active_content_types')`.
All five returned empty on JE 3.8.5. Schema fell back to `get_fields_list()`
(names only, no types). Every user field rendered as `[text]`.

### Evidence
- Diagnostic showed every channel returning 0 entries.
- "All `instance->args` keys" dump revealed JE 3.8.5's CCT factory `$args`
  has no `fields` or `meta_fields` key at all — only CCT-level settings
  (single-page support, REST permissions, admin column config).
- SQL probe `SELECT * FROM wp_jet_post_types` returned the four CCTs
  with `status='content-type'` and `meta_fields = a:14:{...}` (rich
  serialized array of field configs with `name`, `type`, `title`,
  `options`).

### Reality
JE 3.8+ stores configs for every JE-managed object — CCTs, JE-registered
CPTs, JE Relations, JE Queries, JE Glossaries — as rows in the
`{prefix}jet_post_types` table, differentiated by the `status` column:
- `status='content-type'` → CCT, with `meta_fields` populated
- `status='publish'` → JE-registered CPT (meta_fields empty; field configs
  for those live in JE's separate meta-box system)
- `status='relation'` → JE Relation
- `status='query'` → JE Query Builder query
- `status='glossary'` → JE Glossary

This is a generalization of CCT-only storage: JE consolidated all object
configs into one master table some time during the 3.x line.

### Affected code
- `includes/class-discovery.php` `get_cct_fields_from_instance()`,
  `lookup_cct_fields_in_option()`, `deep_probe_je_field_storage()`

### Fix shipped in
v0.2.7 (in flight). New `get_cct_fields_from_jet_post_types_table()`
becomes channel #1 in the resolver. Older channels remain as fallbacks
for older JE versions. Diagnostic deep probe gains a `wp_jet_post_types`
row + `meta_fields` preview per CCT.

### Prevention
1. Treat `{prefix}jet_post_types` as the canonical storage for all
   JetEngine-managed object configs (CCTs, CPTs registered by JE,
   Relations, Queries, Glossaries) — discover from there first.
2. When introspecting a third-party API and finding nothing, ALSO query
   the database directly for unique strings from your data (e.g., a
   field name you authored). Direct-SQL probes find truth that API
   probes can't.
3. Diagnostic surfaces are first-class. Build them before you guess.

---

## L-008: NEVER hardcode the WordPress table prefix

**Discovered:** 2026-04-29 (Phase 1 / version 0.2.7 in flight)
**Severity:** Medium
**Category:** Defensive coding

### Context
Every plugin table reference and every reference to a JE-managed table
must work on sites where `$table_prefix` is not the default `wp_`.

### Wrong
`includes/class-discovery.php` `get_all_relations()` produced a
display-only `'table_name' => 'wp_jet_rel_' . $relation_id`. The rendered
value would be wrong on any site with a non-default prefix.

### Evidence
Audit grep `['"]wp_` against the codebase on 2026-04-29 surfaced exactly
one violation in production code (`class-discovery.php:649`); all other
matches were post-type slugs (`wp_block`, `wp_template`, …) or function
names in log strings, neither of which represent table prefixes.

### Reality
The WP table prefix is per-install and configurable. Always
`$wpdb->prefix . 'table_name'`. Even display-only strings — they end up
in screenshots, support docs, and debug logs that get shared across
sites.

### Affected code
- `includes/class-discovery.php` `get_all_relations()` (display string only;
  the actual SQL query for table-existence checks already used `$wpdb->prefix`)

### Fix shipped in
v0.2.7 (in flight).

### Prevention
- Lint rule (manual): no string literal containing `'wp_'` or `"wp_"` in
  PHP code may resolve to a table name. Audit before each release.
- Display strings derived from table names go through a `prefixed()`
  helper or use `$wpdb->prefix . 'rest'` inline.
- Unit-of-work for any table reference: prefix → schema → query. Never
  skip the prefix step even when it "feels right" because the value is
  cosmetic.

---

## L-009: Two-CCT-to-one-Woo-product (M:1) is out of v1 scope per D-1

**Discovered:** 2026-04-29 (Phase 1 / decision-log)
**Severity:** Low (scope clarification, not a bug)
**Category:** Architecture / Documentation

### Context
The user noted that an earlier project tried bridging two CCTs (e.g.,
`mosaics_data` and `available_sets_data`) to one shared Woo product,
disambiguating by product category. They surfaced this as a real risk:
"how would the product page know which CCT to update?"

### Wrong
None — no code was written for this. The risk was raised in design
discussion before implementation.

### Reality
Decision D-1 in BUILD-PLAN locks bridge cardinality at 1:1. M:1 is
explicitly unsupported in v1. The product's `_jedb_bridge_type` post-meta
identifies which bridge config governs that product, and the link to a
single CCT row is unambiguous via either a JE Relation row or
`cct_single_post_id` (per the Q3 resolution under L-010 below).

If a future need for M:1 emerges, it will be tracked as a Phase 7+
enhancement requiring additional design (likely an ordered list of
bridge types per product with conflict resolution rules).

### Affected code
None.

### Prevention
Lock bridge cardinality early in the decisions log. When edge cases come
up, check the decisions log before implementing — don't build out M:1
mechanics speculatively.

---

## L-010: A→B and B→A field transformations are not necessarily inverses — bridge mappings need separate push/pull chains

**Discovered:** 2026-04-29 (Phase 1 / decision-log)
**Severity:** Medium
**Category:** Architecture / Documentation

### Context
Designing how field values translate between source and target adapters in a
bridge config (Phase 3 flattener / Phase 4 bridge meta box). User raised
the point during Q2 of the design discussion.

### Wrong
The original BUILD-PLAN §4.8 implied a single transformer chain per field
mapping. That model breaks for asymmetric coercions.

### Reality
Many transformations are not symmetric:
- `"yes"` / `"no"` (CCT switcher) → `bool` (Woo `featured`) — the inverse
  must convert back to `"yes"` / `"no"`, but a different snippet might
  decide to emit `"on"` / `"off"` instead.
- HTML stripping (CCT WYSIWYG → Woo short_description) — the inverse can't
  meaningfully re-add HTML.
- Currency formatting — `"850"` → `"$850.00"` is fine for display, but
  pulling that back into a numeric CCT field requires stripping.

Each field mapping must carry **two transformer chains** ordered separately:
- `push_transform`: source → target (runs when source is canonical and
  we are PUSHing).
- `pull_transform`: target → source (runs when target is canonical and
  we are PULLing).

Built-in transformers ship as paired inverses where well-defined
(`yes_no_to_bool` ↔ `bool_to_yes_no`, `csv_to_array` ↔ `array_to_csv`).
Custom snippets are direction-agnostic functions; the bridge config
decides which snippet goes in which chain.

### Affected code
None yet (design phase). BUILD-PLAN §4.8 needs updating before Phase 3
or 4 implementation.

### Prevention
Always model bidirectional sync as two distinct chains, even when a single
chain would have worked. Same instinct as RESTful design: don't conflate
read and write paths just because they happen to mirror today.

---

## L-011: Required-field declarations belong on adapters, not hardcoded in bridge configs

**Discovered:** 2026-04-29 (Phase 1 / decision-log)
**Severity:** Low (capability, not a bug)
**Category:** Architecture

### Context
WooCommerce products have fields that MUST be set for the product to be
valid (e.g., `name` / `post_title`, `status`). Variations have additional
required fields (parent_id, attribute selections). The bridge config UI
should warn editors when required target fields aren't covered by the
mapping.

### Reality
PAC VDM does this for its specific use case but hardcodes the required
fields. The user explicitly does NOT want that — required-field policy
must vary per install (e.g., some sites treat `regular_price` as required
while others use a price range or a custom-snippet-supplied default).

### Resolution
The `JEDB_Data_Target` interface gains a new method
`get_required_fields()` that returns an array of field names the target
treats as required for `create()` (and optionally for `update()`). Each
adapter declares its own. Bridge UI shows a "Mandatory coverage" panel
per bridge type:
- For each required target field, check whether any source-side mapping
  resolves to it.
- Unmapped required fields render as warnings (not errors) with three
  remediation options: add a mapping, attach a custom snippet that
  synthesizes the value, or mark as "intentionally unmapped" (suppresses
  the warning).

Required-field policy can be overridden at the bridge-type level via a
`required_overrides` array in the bridge config JSON, so different
bridges of the same target type can have different policies.

### Affected code
None yet (design phase). `JEDB_Data_Target` interface, `Target_Woo_Product`,
`Target_Woo_Variation`, `Target_CPT`, `Target_CCT` will each gain
`get_required_fields()` in the appropriate phase.

### Prevention
Capabilities live on adapters, not in cross-cutting configs. If a target
has a constraint, the adapter declares it; if a config wants to override
it, it does so explicitly.

---

<!-- Append new entries below this line. Never renumber existing entries. -->

## L-012: WC product-edit meta-box injection has rough edges; field-render-hint must be adapter-owned

**Discovered:** 2026-04-29 (Phase 1 / decision-log for Phase 4)
**Severity:** Medium
**Category:** Architecture / Documentation

### Context
Designing the Phase 4 Bridge meta box that lives on the WC product edit
screen. We need to decide which fields the box renders inputs for vs which
fields it leaves to WooCommerce's native UI.

### Wrong
Initial framing assumed the Bridge meta box would render every bridged field
in its own UI. That double-renders core Woo fields (name, sku, price, stock,
categories, image) — those already have native inputs and our box would
fight with WC's positioning, validation, and styling.

### Reality
- WooCommerce already renders inputs for every typed setter on `WC_Product`
  (name, sku, prices, stock, dimensions, taxonomies, image/gallery,
  downloads, etc.). Our Bridge meta box should NEVER render duplicate
  inputs for those.
- Custom meta keys, plugin-added fields, and JE-derived display-only data
  have no native input — those are exactly what our box exists to render.
- JFB-WC-Quotes' implementation is the closest precedent on this repo.
  Worth re-reading `jfbwqa_add_prepared_quote_metabox_revised()` (~line
  1496) and `jfbwqa_render_prepared_quote_metabox_content()` (~line 1520)
  in `jfb-wc-quotes-advanced.php` before Phase 4 starts. HPOS changes the
  hooks for the orders screen but not for the products screen — products
  stay on `add_meta_boxes_product`.

### Resolution
- New `JEDB_Data_Target` interface method
  `is_natively_rendered( string $field_name ): bool` per Q-render-hint
  decision (Option B — adapter-owned).
- `Target_Woo_Product` returns `true` for every typed-setter field plus
  category/tag taxonomies, image, gallery. Returns `false` for arbitrary
  meta keys (per-target whitelist + sampled keys).
- `Target_Woo_Variation` returns `true` for typed-setter fields, false for
  custom meta.
- Bridge meta box queries `is_natively_rendered()` for every mapped field
  and renders an input only when it returns false. The sync engine still
  runs against ALL mapped fields regardless of where they're rendered.
- Field-render-hint conflicts (two bridge configs both wanting to render the
  same custom field in our box) surface as a warning in the Bridges admin
  tab BEFORE save.

### Affected code
None yet (design phase). Planned for Phase 4. Interface change captured here
so the contract is locked.

### Prevention
Adapter-owned capabilities are the rule, bridge-config-owned overrides are
the exception. When a default decision can be made by examining the target
type, make it there. Cross-cutting bridge configs should declare exceptions,
not enumerate defaults.

---

## L-013: Conditional bridge configs allow 1:N source→targets via 1:1 individual bridges + per-bridge conditions

**Discovered:** 2026-04-29 (Phase 1 / decision-log)
**Severity:** High
**Category:** Architecture

### Context
The user identified that "two CCTs share one Woo product" (M:1) and "one
CCT syncs to multiple targets based on conditions" (1:N) are real
requirements that the cardinality decision (D-1: 1:1) appeared to block.

### Wrong
Initial framing punted on M:1 and 1:N entirely. That left a known-needed
pattern unsupported.

### Reality
The cardinality decision (D-1) is about *individual bridges*, not about
*sources*. Each bridge can stay 1:1 between exactly one source record and
exactly one target record. We allow multiple bridge configs to share the
same source target as long as each config carries a `condition` that
makes the matching set disjoint.

When a sync event fires:
1. Sync engine finds every bridge config whose `source_target` matches.
2. For each, evaluates the `condition` (no condition = always apply).
3. Applies all matching bridges in declared `priority` order.
4. Each bridge application is still 1:1 and atomic.
5. Aggregate behavior: 1 source → N matched targets, each via a 1:1 bridge.

This eliminates the disambiguation problem because two bridges that would
have collided on the same target field never both apply to the same target
simultaneously — the conditions make them mutually exclusive.

### Resolution per Q-cond
Per Q-cond decision (Option B — DSL + snippet fallback):
- `condition` is a tiny declarative DSL string for simple cases:
  `{product.product_cat} contains "Mosaics"`,
  `{cct.has_instructions_pdf} == "yes"`,
  `{product.status} == "publish" AND {cct.featured} == "yes"`.
- Snippet escape hatch: `condition_snippet: my_complex_condition_slug`.
  Snippet returns bool; the runtime treats throws as "skip this bridge"
  and logs the failure to `wp_jedb_sync_log` with `status='skipped_error'`.
- Bridge config gains `priority: int` (default 100). Lower numbers run
  first. Useful for deterministic chaining.
- Built-in DSL operators (versioned: `dsl_version: 1` in bridge config so
  we can extend without breaking old configs):
  - Comparison: `==`, `!=`, `>`, `<`, `>=`, `<=`
  - String: `contains`, `not_contains`, `starts_with`, `ends_with`
  - Membership: `in`, `not_in` (against literal arrays)
  - Logical: `AND`, `OR`, `NOT`, parentheses
  - Path access: `{source.field_name}`, `{target.field_name}`,
    `{cct.field_name}` (alias for source when source is a CCT),
    `{product.field_name}` (alias for target when target is a Woo product)

### Affected code
None yet (design phase). Planned for Phase 4.9 (Conditional Sync Engine)
and integrated with Phase 5b (Snippet runtime).

### Prevention
When a cardinality decision blocks a real-world need, look for an
orthogonal axis (here, conditions-on-bridges) before relaxing the
cardinality. Conditions keep individual bridges simple and predictable
while letting the system as a whole express complex routing.

---

## L-014: Verified `{prefix}jet_rel_{id}` table structure and write semantics

**Discovered:** 2026-04-29 (Phase 1 / pre-Phase-2 verification)
**Severity:** Low (informational; prevents future guessing)
**Category:** Documentation

### Context
Phase 2 (Relation Injector port) is the first phase that writes to JE
relation tables. Writing to a third-party table without confirmed schema
knowledge would be reckless.

### Evidence
User-supplied `DESCRIBE wp_jet_rel_9;` and dummy-data dump on
`bbhq.legworklabs.com` (JE 3.8.5).

### Reality — verified column structure for any `{prefix}jet_rel_{id}`

| Column            | Type             | Constraints                  | Purpose                                                                 |
|-------------------|------------------|------------------------------|-------------------------------------------------------------------------|
| `_ID`             | bigint(20)       | NOT NULL, PK, AUTO_INCREMENT | Row identity                                                            |
| `created`         | timestamp        | NULL DEFAULT CURRENT_TIMESTAMP | Insertion time                                                        |
| `rel_id`          | varchar(40)      | NULL                         | The JE relation ID, stored as a string                                  |
| `parent_rel`      | int(11)          | NULL                         | Parent relation ID for hierarchical chains; 0 for non-hierarchical      |
| `parent_object_id`| bigint(20)       | NULL, INDEX (MUL)            | Parent record's primary key (CCT `_ID` for cct::*, post ID for posts::*) |
| `child_object_id` | bigint(20)       | NULL, INDEX (MUL)            | Child record's primary key (same scheme)                                |

Verified write pattern (one connection between CCT row 1 and post 395 via
relation 9):

```sql
INSERT INTO wp_jet_rel_9
  (rel_id, parent_rel, parent_object_id, child_object_id, created)
VALUES
  ('9', 0, 1, 395, NOW());
```

### Caveats / outstanding verification before Phase 2 ships
- **Cascade behavior on parent/child deletion.** Not yet verified. JE
  may or may not auto-clean orphaned relation rows.
- **"Make this relation a CCT" toggle.** Possibly changes the table
  structure or moves rows into a CCT table. Out of v1 scope but worth
  knowing. Captured for future investigation.
- **JE-managed caches.** RI uses direct SQL for inserts (verified
  below). Whether JE listing-grid result caches or other transient
  caches need post-insert invalidation is not verified — see the
  "Open items still to verify" section below.

### Update — 2026-04-29: full read of RI's `class-transaction-processor.php`

End-to-end re-read of
`Refrence but block from git/Jet Engine Relation Injector/includes/class-transaction-processor.php`
(version present in this workspace; ~358 lines). Verified facts below
are direct quotes / paraphrases of code RI ships in production. Anything
not in this update remains uncertain — never assume.

#### Write API actually used by RI
**Direct `$wpdb->insert()` on `{prefix}jet_rel_{id}`.** RI does NOT use
any `jet_engine()->relations->...` write method. The relevant code is
in `Jet_Injector_Transaction_Processor::create_relation()` (lines
240-316):

```php
$result = $wpdb->insert(
    $table,
    [
        'rel_id'           => $relation_id,  // Required by JetEngine!
        'parent_rel'       => $parent_rel,   // For grandparent relations
        'parent_object_id' => $parent_id,
        'child_object_id'  => $child_id,
    ],
    ['%s', '%d', '%d', '%d']  // rel_id is text type
);
```

**Critical contract details:**
- `rel_id` MUST be included in every insert. The inline comment says
  *"Required by JetEngine!"* — without it, JE won't recognize the row.
- `rel_id` format string is `'%s'` (string) even though it looks like
  an int (varchar(40) in the schema confirms — see DESCRIBE above).
- `parent_rel` is `null` for non-hierarchical relations, the parent
  relation's ID for hierarchical chains.
- `created` is omitted — DB default `CURRENT_TIMESTAMP` handles it.

#### Read API used by RI (for context, since we'll need this in Phase 2 too)
- `jet_engine()->relations->get_active_relations()` → returns array
  `[ relation_id => Relation_Object ]`. Verified line 151.
- `$relation->get_id()` → returns the relation ID. Line 243.
- `$relation->get_args()` → returns `['parent_object', 'child_object',
  'type', 'parent_rel', ...]`. Line 159, 284.

#### Pre-insert duplicate check (idempotency)
RI checks for an existing connection before inserting (lines 267-281):

```php
$exists = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT rel_id FROM {$table} WHERE parent_object_id = %d AND child_object_id = %d",
        $parent_id,
        $child_id
    )
);

if ( $exists ) {
    // Already connected — return true without re-inserting.
    return true;
}
```

This makes the operation idempotent. Important for our flatten engine
since the same bridge can fire multiple times (CCT save → flatten →
target write → if target is a CCT and bridges loop back, sync_guard
intercepts but the relation insert path itself stays safe).

#### Clearing for 1:1 / 1:M relation types
RI clears existing relations on the appropriate side BEFORE inserting
the new one (lines 192-194 + `clear_existing_relations()` at lines
325-355):

```php
if ( $args['type'] === 'one_to_one' || $args['type'] === 'one_to_many' ) {
    $this->clear_existing_relations( $item_id, $relation_id, $is_parent );
}
```

`clear_existing_relations()` does `$wpdb->delete( $table, [ $column => $item_id ], ['%d'] )`
where `$column` is `parent_object_id` or `child_object_id` depending
on whether the current item is the parent or child side. **Note: RI
does NOT clear for `many_to_many` — it just appends.** Bridge code in
Phase 2 should follow the same convention.

#### Side determination ("am I parent or child?")
RI parses the relation's `parent_object` / `child_object` strings via
the discovery's `parse_relation_object()` (the `cct::slug` /
`posts::slug` / `terms::slug` parser we already ported), then compares
against the CCT slug from the hook closure (line 182):

```php
$is_parent = ( $parent_parsed['type'] === 'cct'
            && $parent_parsed['slug'] === $cct_slug );
```

For our Phase 4 Bridge meta box on the WC product side, the same
pattern applies: the bridge type config tells us the source kind, the
relation's parent/child strings tell us which side the source is on,
and the insert direction follows.

#### CCT save hook signatures (different for created vs updated)
This is the part that bit RI badly enough to warrant inline comments
(lines 42-62). The hook names and signatures:

| Hook | Signature | Notes |
|---|---|---|
| `jet-engine/custom-content-types/created-item/{slug}` | `($item, $item_id, $handler)` | New CCT row created. `$item_id` is the new `_ID`. |
| `jet-engine/custom-content-types/updated-item/{slug}` | `($item, $prev_item, $handler)` | Existing row updated. **No `$item_id` parameter** — extract from `$item['_ID']`. |

Both fire at priority 10 with 3 args. RI registers a closure per CCT
(line 33's `Jet_Injector_Config_DB::get_enabled('cct')` enumerates
which CCTs to hook). For the bridge engine, we'll register hooks for
EVERY CCT that has at least one bridge config pointing at it as source.

#### Trojan Horse data wire format
RI's hidden inputs carry JSON-encoded relation data (line 106):

```php
$relations_data = json_decode( stripslashes( $_POST['jet_injector_relations'] ), true );
```

Shape: `{ relation_id: [related_item_ids] }`. Stripslashes handles
WP's magic-quotes-on-POST behavior. Nonce input field name is
`jet_injector_nonce`, action `jet_injector_nonce`.

For our Phase 4 product-side trojan horse, we'll mirror this:
`_jedb_bridge_trojan` (JSON), `_jedb_bridge_trojan_nonce` (action
`jedb_bridge_save`).

#### Open items still to verify (Phase 2 punch list)
1. **JE cache invalidation post-insert.** RI's direct SQL works but
   does it leave stale data in JE listing caches, smart-filter
   query caches, or transient cache? Plan: write a test bridge,
   create a relation row via direct SQL, then load a listing on the
   front-end before clicking any "refresh cache" button. If the
   listing is stale, JE has caches we need to invalidate. Possible
   invalidation methods: `wp_cache_flush()`,
   `do_action( 'jet-engine/relations/items-changed', $relation_id )`
   if such a hook exists, or a `delete_transient` sweep.
2. **`many_to_many` semantics.** RI doesn't clear before append —
   verify that's actually correct (no UNIQUE constraint conflicts on
   re-inserts of the same pair) AND that we want our bridge engine to
   follow the same "append, don't replace" semantics for M:M.
3. **Relation rows' `created` column on update.** RI inserts but
   never updates rows. We may want to bump `created` (or add an
   `updated_at` if JE later adds one) when bridge syncs touch a
   relation. Probably not — relation rows are connection records,
   not data records, and don't logically have an "updated" event.

### Affected code (now)
- `LESSONS-LEARNED.md` — this update.

### Affected code (Phase 2 will write)
- `includes/relations/class-runtime-loader.php` — port RI's hidden-
  input injection.
- `includes/relations/class-transaction-processor.php` — port the
  trojan-horse handler with the verified hook signatures and the
  direct-SQL insert pattern.
- `includes/relations/class-relation-attacher.php` — extracted helper
  for "create relation row between A and B" usable from both Phase 2
  (CCT save) and Phase 4 (product save).

### Prevention
- Direct SQL on third-party tables is acceptable when the third-party
  API doesn't expose the operation cleanly — but it MUST be paired
  with: (a) the duplicate-check we observed in RI, (b) the type-aware
  clear-before-insert for 1:1/1:M, (c) explicit cache-invalidation
  research before shipping.
- When using closures to register WP hooks per-iteration, capture
  loop-scoped variables explicitly via `use ( $var )` to avoid
  closure-binding bugs. RI's lines 40, 45, 55 show the pattern.
- WP's two CCT save hooks have different signatures even though
  they're conceptually paired. ALWAYS register both, ALWAYS test
  both code paths.

---

## L-015: Woo product variations are for purchase options, NOT for bridge-type disambiguation

**Discovered:** 2026-04-29 (Phase 1 / decision-log for Phase 4b)
**Severity:** Medium
**Category:** Architecture / Documentation

### Context
Designing how multiple bridge types could share a Woo product. Initial
proposal was to use variations to disambiguate bridge types ("Mosaic
goes to one variation, Available Set goes to another, both under the
same parent product").

### Wrong
That model hijacks Woo's native variation semantics. Variations are a
purchase-option mechanic that customers see on the storefront — they
choose between variations like "Build only" vs "Includes Instructions
PDF". Using variations for invisible bridge-type bookkeeping would
confuse the storefront UX and force the storefront to either expose or
hide bridge-internal information.

### Reality
- Each variation belongs to ONE source record (one CCT row), not to a
  different bridge type. The variation represents a different purchase
  option for that one record.
- Bridge-type disambiguation, when needed, happens via the conditional
  engine (per L-013) — typically using product category as the
  discriminator. Different bridge types target different categories;
  conditions ensure the right bridge fires for each product.
- Phase 4b (Variation bridging) stays as originally designed:
  variation reconciliation creates child variations for a given CCT row
  based on `show_when` rules. The variations and their parent product
  all map to the same CCT row.

### Resolution
Phase 4b spec retains its variation-reconciliation engine but is
explicitly NOT used for cross-bridge-type concerns. BUILD-PLAN §4.7
wording tightened to make this distinction clear.

### Affected code
None (design correction before code). Phase 4b implementation will
respect this constraint.

### Prevention
When repurposing a third-party UI primitive for an internal concern,
ask first: "would a storefront customer understand this?" If the
answer is no, find another mechanism. Storefront-visible features
have semantics customers learn — don't subvert them for engineering
convenience.

---

## L-016: JetEngine auto-creates the related post on CCT save, but ONLY in one direction

**Discovered:** 2026-05-01 (Phase 2 / version 0.3.1)
**Severity:** High
**Category:** API drift / Wrong assumption

### Context
Phase 2's transaction processor was designed assuming the plugin would
either create related records itself (per the original Phase 4 design)
or attach editor-picked records via the picker. We hadn't accounted
for JetEngine's own auto-create behavior.

### Wrong
We treated JE Relations as a passive storage primitive — JE owns the
table, we write to it via the verified L-014 contract. We didn't know
JE actively *creates* the related post on CCT save when the relation
is configured for it.

### Reality (verified by user testing 2026-05-01)
JetEngine Relations supports an "auto-create related item" toggle in
the relation's settings. When enabled:

1. CCT row is saved (created or updated).
2. JE checks if a related post already exists via the relation table.
3. If not, JE calls `wp_insert_post()` to create one. Title is
   populated from a configured CCT field (typically the title field);
   description optional.
4. JE writes the relation row in `{prefix}jet_rel_{id}`.
5. The `created-item/{slug}` action fires.

**Critical caveat: this works in ONE direction only.** Configuring
the CCT → product auto-create does NOT enable the reverse. When a
product is created directly in WooCommerce (not through JE's CCT
flow), JE does NOT auto-create a corresponding CCT row. The user
verified this on 2026-05-01: products created via WC's admin appear
in `wp_posts` but no row appears in `wp_jet_rel_{id}` and no CCT
row materializes.

### Architectural implication
Cooperate with JE for the CCT → post direction:
- JE handles the create + relation insert.
- Our Phase 3 flatten engine hooks at priority 20+ on the same
  `created-item/{slug}` and pushes ADDITIONAL mapped fields onto the
  JE-created post.
- Then calls `WC_Product->save()` to refresh the WC lookup table
  (covers L-017).

For the post → CCT direction, JE provides nothing. Our plugin's
reverse-sync layer (BUILD-PLAN §4.10, added in 0.3.1) is the only
place this can happen. Hooks: `save_post_{type}`, optionally
`woocommerce_new_product` / `woocommerce_update_product` for Woo.

### Affected code
- `includes/relations/class-transaction-processor.php` — Phase 2's
  picker-driven attach path is unaffected; it still works at priority
  10 because explicit picker selections don't conflict with JE's
  auto-create (auto-create only fires when no relation row exists,
  the picker creates one).
- Phase 3 flatten engine (planned): MUST register at priority 20+ to
  guarantee JE's auto-create has finished.

### Prevention
When porting code that touches a third-party relation system, **test
both directions of every supposed-bidirectional behavior** before
assuming they're symmetric. JE Relations look bidirectional in
queries but auto-create is a one-way switch.

---

## L-017: WooCommerce product visibility — `wc_get_products()` is unreliable for picker / discovery use cases

**Discovered:** 2026-05-01 (Phase 2 / version 0.3.1)
**Severity:** High
**Category:** API drift

### Context
The Phase 2 picker on the CCT edit screen calls
`Target_Woo_Product::list_records()` which uses `wc_get_products()` to
find candidate Woo products to relate. User tested: created a CCT
row that triggered JE's auto-create of a Woo product (verified by
direct SQL — products 397, 398, 399 all `post_status='publish'`),
then opened our picker and the new products didn't appear.

### Wrong
Two false hypotheses ruled out by the user's SQL:
1. **Status filter mismatch.** I assumed JE creates with `auto-draft`
   and our `array('publish', 'private', 'draft')` filter excluded it.
   FALSE. Verified: JE creates with `publish`.
2. **`wc_product_meta_lookup` row missing → not visible.** Was unable
   to verify directly because MariaDB rejected the LIMIT-in-subquery
   syntax, but circumstantially this is still the most likely
   underlying cause.

### Reality
`wc_get_products()` is a high-level WC wrapper that filters by
several internal criteria beyond just `post_status`:
- The `_visibility` meta key (set by `WC_Product->save()`)
- The `wc_product_meta_lookup` table (populated by
  `WC_Product->save()`)
- Product visibility taxonomy

A post created via raw `wp_insert_post( ['post_type' => 'product'] )`
— which is what JE's auto-create does — is a "skeleton" product. It
exists in `wp_posts` with the right `post_type`, but lacks every WC
meta convention until `WC_Product->save()` is called on it once.
Until then, it's invisible to `wc_get_products()` even though
`post_status='publish'` and `post_type='product'`.

### Resolution
Switch `Target_Woo_Product::list_records()` to use **`WP_Query` with
`post_type='product'`** directly. WP_Query doesn't care about WC's
visibility meta or lookup table. We lose nothing useful for picker
purposes — the picker is showing every product as a candidate, not
filtering by purchasability.

For PUSH writes through `update()` we still go through
`WC_Product->save()` (HPOS-safe per L-014 / D-10), which has the
side effect of populating the lookup table the first time it runs
on a JE-created product. So the bug self-heals after the first PUSH.

For products that JE creates and the editor never pushes anything to,
the lookup table stays stale. A "Reconcile WC lookup" Utilities-tab
button (deferred to Phase 5) is the long-term fix.

### Affected code
- `includes/targets/class-target-woo-product.php` — `list_records()`

### Fix shipped in
v0.3.1.

### Prevention
For discovery use cases (pickers, search, inventory), prefer raw
`WP_Query` over `wc_get_products()` when the target is a post type.
Reserve `WC_Product` API for read/write operations on records you've
already identified.

---

## L-018: Phase 3 flatten engine MUST register at priority >= 20 on JE CCT save hooks

**Discovered:** 2026-05-01 (Phase 2 / version 0.3.1)
**Severity:** Medium (forward-looking; Phase 3 hasn't shipped)
**Category:** Architecture / Defensive coding

### Context
Direct consequence of L-016. Phase 2's transaction processor
registers at priority 10 on `created-item/{slug}` and that's fine
for picker-driven explicit attaches. Phase 3's flatten engine MUST
fire AFTER JE has finished its own auto-create logic.

### Reality
WordPress action priorities run in ascending numeric order. JE's
own `created-item/{slug}` consumers (including the auto-create
related-post code path) run at priority 10. Anything that needs to
observe a fully-constructed JE state must register at >= 20.

### Resolution
Phase 3 Flatten engine hooks register at priority 20. Documented
here as the contract; Phase 3 implementation is required to honor
it. Same applies to any future code that needs to read the related
post that JE just created.

### Affected code
None yet. Phase 3 implementation will reference this entry.

### Prevention
WP action priorities are the only contract for "what runs when"
within a single request. Document priority requirements explicitly
when an action chain has ordering constraints.

---

## L-019: RI's primary historical purpose was taxonomy attachment, not relation attachment

**Discovered:** 2026-05-01 (Phase 2 / decision-log)
**Severity:** Low (historical note; informs Phase 3+ scope)
**Category:** Documentation

### Context
User clarified during Phase 2.5 design discussion (2026-05-01):
> "the whole point of RI was more about showing avialble taxonommys
> to a post before the CCT is saved. this way taxonomys could be
> established on first save."

This recontextualizes the entire RI port. RI was named "Relation
Injector" but its dominant use case was attaching **taxonomies** to
new CCT items in a single save — a parallel problem to relations
since both require the CCT row to exist before the attachment can
happen.

### Reality
RI's data broker (`includes/class-data-broker.php`) explicitly
supports `terms::*` object slugs alongside `cct::*` and `posts::*`.
The taxonomy support is built into:
- `search_taxonomy_terms()` (line ~458)
- `create_taxonomy_term()` (line ~149)
- `get_taxonomy_term()` (line ~281)

Phase 2 of this plugin shipped with `cct::*` and `posts::*` target
adapters but NO `terms::*` adapter. So a CCT that has a relation to
a taxonomy term cannot use our picker for that side. The picker
silently skips relations whose `other_object` slug doesn't resolve
to a registered target — the user wouldn't see them as an option.

### Resolution
Adding a `JEDB_Target_Term` adapter (or generalizing the registry
to handle the `terms::` kind) is a deferred capability. Could land
in Phase 2.5 as a small additive improvement, OR in Phase 3
alongside the field-mapping UI (taxonomy assignments from CCT
fields are a common flatten config pattern).

For now, taxonomy-side relations are an acknowledged gap. The
Phase 2 `Tab_Relations` admin filters out invalid relations
silently; we should add a "skipped because no adapter" pill in the
admin tab so the user knows when this happens.

### Affected code
- `includes/targets/class-target-registry.php` — needs a
  `JEDB_Target_Term` adapter and registration
- `includes/admin/class-tab-relations.php`
  `get_relations_per_cct()` — should mark unsupported sides

### Fix planned for
Phase 2.5 hotfix (admin marker only) + Phase 3 (full term adapter).
NOT shipped in 0.3.1 to keep that release tightly focused on the
picker bug fix and architecture documentation.

### Prevention
"Plugin name" is a marketing concept; "plugin actual capability" is
a code concept. When porting from a plugin called "X Injector",
read the actual feature surface, don't assume the name describes
it accurately.

---

## L-020: Bidirectional sync requires explicit reverse-direction handling — JE Relations doesn't help on the post side

**Discovered:** 2026-05-01 (Phase 2 / decision-log)
**Severity:** High
**Category:** Architecture

### Context
User test on 2026-05-01: created a Woo product directly via the WC
admin (no CCT flow involved). Result: the product exists in
`wp_posts`, but no row appears in any `wp_jet_rel_*` table and no
CCT row is created. JE Relations does NOT auto-create a CCT row when
a product is created on its own (per L-016).

### Wrong
The original BUILD-PLAN §4.5 implied that JE Relations + the bridge
meta box would handle bidirectional create. JE handles only one
direction; the reverse is entirely our responsibility.

### Reality
Two genuinely separate code paths are needed:

**Direction A: CCT → post (JE handles auto-create; we extend)**
- Our Phase 3 flatten engine listens at priority 20+ on
  `created-item/{slug}` / `updated-item/{slug}`.
- JE has already created the related post and attached the relation
  by the time we run.
- We push additional mapped fields onto the JE-created post via
  `WC_Product->save()`.

**Direction B: post → CCT (we handle entirely)**
- Hooks: `save_post_{type}`, plus optionally
  `woocommerce_new_product` / `woocommerce_update_product` for Woo.
- For each registered bridge config, evaluate the trigger + condition
  (per the conditional engine §4.9).
- If the post should bridge to a CCT row but no relation exists yet,
  optionally auto-create the CCT row (via
  `JEDB_Target_CCT::create()`) and attach the relation via
  `JEDB_Relation_Attacher::attach()`.
- If the relation exists, just push mapped fields onto the existing
  CCT row.

The user explicitly endorsed this asymmetry (2026-05-01):
> "...support both the case where we deffer to JE to make a relation
> one way (and follow through with our own syncing of fields) and
> then if the admin chooses, to manually sync another way, it will
> do so."

### Resolution
- BUILD-PLAN gains §4.10 "Reverse-direction sync (post → CCT)".
- Trigger configuration becomes a per-direction concept (push trigger
  for direction A, pull trigger for direction B).
- D-17 / D-18 / D-19 added to the Decisions Log to lock the
  asymmetry, the trigger taxonomy, and the hook priority contract.

### Affected code
None yet. Phase 3 will implement direction A; direction B is Phase
3.5 or Phase 4 depending on scope decisions.

### Prevention
"Bidirectional sync" needs to be designed as two separate uni-
directional flows from day one. The two flows are NOT inverses, do
not run on symmetric hooks, and have different failure modes. Always
spec them separately and only tie them together at the conceptual
"bridge" level.

---
