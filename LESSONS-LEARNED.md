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
- **Preferred write API.** Direct SQL works but bypasses JE caches and
  hooks. Need to confirm the exact JE method to call programmatically —
  candidates include `jet_engine()->relations->process_relation_data()`
  (used by RI's transaction processor) and the relation object's own
  `update()` / `insert()` methods. **TODO before Phase 2 starts:** read
  RI's `class-transaction-processor.php` end-to-end and document the
  exact method signature here.
- **Cascade behavior on parent/child deletion.** Not yet verified. JE
  may or may not auto-clean orphaned relation rows.
- **"Make this relation a CCT" toggle.** Possibly changes the table
  structure or moves rows into a CCT table. Out of v1 scope but worth
  knowing. Captured for future investigation.

### Prevention
Verify table structure with `DESCRIBE` before writing to any third-party
table. Capture the structure here in lessons-learned with the exact
DESCRIBE output so the contract is auditable across versions.

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
