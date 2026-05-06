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

## L-021: JetEngine "auto-create on CCT save" creates the linked POST (via Has-Single-Page), NOT the relation row

**Discovered:** 2026-05-03 (Phase 3 / version 0.4.1)
**Severity:** Critical
**Category:** Wrong assumption / Architecture

### Context
End-to-end test on Brick Builder HQ staging: user created a Mosaic
CCT, set `display_price_publicly = "no"`, saved. Expected behavior:
forward-flatten engine resolves the linked Woo product, evaluates the
condition (`{cct.display_price_publicly} == "yes"`), logs
`skipped_condition`, and stops. Actual behavior: every recent
mosaic save logged `skipped_no_target` instead — the engine never
even reached the condition check.

### Wrong
L-016 and D-17 stated, in part, that "JE Relations auto-creates the
related post on CCT save in one direction only". That phrasing was
true *in spirit* but conflated two distinct JetEngine mechanisms,
which led to a real bug in the flattener's link resolution.

### Evidence

**`wp_jet_rel_9` (Mosaic → Product relation table)** — exactly ONE row:

| _ID | rel_id | parent_object_id | child_object_id |
|---|---|---|---|
| 1 | 9 | 1 (mosaic) | 395 (product) |

Mosaic CCT rows `_ID = 2, 3, 4` have **no relation row at all** — yet
their linked products clearly exist (the user confirmed "Single Page
button works and the fields are synced", implying `cct_single_post_id`
on each CCT row points to a real product post).

**Sync log for source `cct::mosaics_data`** — every recent row:
```
status: skipped_no_target
message: "no linked target — Phase 3.5 will optionally auto-create"
context: {"link_via":{"type":"je_relation","relation_id":"9","side":"auto"}, ...}
```

The user's saved bridge config is correct (`condition` is set,
`link_via.relation_id = "9"`), but the engine bailed at step 1
because the relation table lookup returned 0.

### Reality
JetEngine has **two distinct auto-creation features** and they fire
on different triggers:

| Feature | What it auto-creates on CCT save | When |
|---|---|---|
| **"Has Single Page"** on the CCT | A linked post (CPT or Woo product, configurable) whose ID is stored in the CCT row's `cct_single_post_id` column | Always, when Has-Single-Page is enabled |
| **JE Relation row** in `{prefix}jet_rel_{id}` | Nothing automatic | Only written when the user explicitly attaches via the picker UI (`Allow to create new children from parent` button), or when `JEDB_Relation_Attacher::attach()` is called from our own code |

Earlier docs implied JE wrote the relation row "for free" when
Has-Single-Page is on. **It does not.** The two systems are
independent — Has-Single-Page populates `cct_single_post_id`; the
relation table stays empty unless something explicitly inserts.

This is why the user's mosaics 2/3/4 had products linked via
`cct_single_post_id` (so the field-sync looked like it worked when
testing earlier) but had no rows in `wp_jet_rel_9` (so JE Smart
Filters / Listing Grids that traverse the relation see nothing). And
in this 0.4.0 test, the engine — which tries the relation table
first — found no row and gave up.

### Fix shipped in
v0.4.1 (commit pending, this release).

`JEDB_Flattener::resolve_target_id()` reworked to a 3-step resolution
chain:

1. **JE Relation row lookup** (fast path, when present).
2. **Fallback to `cct_single_post_id`** when the relation row is
   missing AND `link_via.fallback_to_single_page` is true (default).
   The fallback verifies the linked post's type matches the relation's
   other endpoint, so we don't accidentally bridge a `story_bricks`
   post into a `mosaics_data → product` relation.
3. **Auto-attach** the missing relation row via the existing
   `JEDB_Relation_Attacher::attach()` (idempotent) when
   `link_via.auto_attach_relation` is true (default). After the first
   sync, the relation table has the row, JE Smart Filters work, and
   future syncs use the fast path. Self-heal.

Sync log `context_json` now records `resolution: 'relation_row' |
'fallback_single_page' | 'cct_single_post_id' | 'none'` and
`auto_attached: true|false` so the user can see at a glance whether a
particular sync used the fallback.

The two new flags are exposed in the Flatten admin tab's "Self-heal
options" fieldset under the link-via picker.

### Affected code
- `includes/flatten/class-flattener.php`
  - `resolve_target_id()` — full rewrite, returns `[id, method, attached]` tuple
  - new private `verify_single_post_matches_relation()`
  - `apply_bridge()` — destructures the tuple, threads metadata into sync log context
- `includes/flatten/class-flatten-config-manager.php`
  - `default_config_json()` adds the two new flags
  - `merge_with_defaults()` deep-merges `link_via` so existing bridge configs get the new keys on read
- `templates/admin/tab-flatten.php` — new fieldset with two checkboxes
- `assets/js/flatten-admin.js` — wires the checkboxes into config_json
- `BUILD-PLAN.md` §4.5 + decisions log — updated wording
- This file (L-021)

### Prevention
1. **Verify auto-create behavior empirically per JE feature, never
   conflate them in docs.** Has-Single-Page and Relations are
   different subsystems; treat them as such.
2. **Always cross-reference the relation table directly when
   debugging "no target" errors.** A missing relation row is much
   more common than we assumed and won't show up in any JE admin
   screen.
3. **The plugin must self-heal whenever a verifiable link exists.**
   Editors should never need to click a picker button to make a
   bridge work; that defeats the purpose of having a bridge engine.
4. **Document JE auto-create features as a TABLE, not prose.** Prose
   conflates; tables make the distinction unambiguous.
5. **When a sync logs `skipped_no_target`, the context_json must
   carry enough info to diagnose without re-running.** v0.4.1 adds
   `has_single_page` and `resolution` to the no-target log entry —
   so anyone reading the sync log can immediately see "the link
   exists via single-page but was rejected for X reason."

---

## L-022: JetEngine `$cct->db->update()` and `->insert()` do NOT fire the `updated-item` / `created-item` hooks — cycles between forward push and reverse pull don't form on the JE side

**Discovered:** 2026-05-06 (Phase 3.5 / version 0.5.1)
**Severity:** Medium (architectural finding, not a bug)
**Category:** API surprise / Architecture

### Context
Phase 3.5's reverse flattener writes back to a CCT row via
`JEDB_Target_CCT::update($id, $payload)`, which delegates to
`$cct_factory->db->update($fields, ['_ID' => $id])` on JetEngine's CCT
DB handle. The expectation, when designing the cross-direction
cascade prevention (BUILD-PLAN §4.10's cycle prevention notes), was
that this CCT write would fire JE's `updated-item/{slug}` hook and
the forward push engine — listening on the same hook for
bidirectional bridges — would wake up, see the pull lock held by the
reverse engine, and bail with `cascade: pull_in_flight`. That was
the symmetric counterpart to the `cascade: push_in_flight` case
(forward push → `WC_Product->save()` → reverse pull wakes up → sees
push lock → bails).

### Wrong
We assumed JE's CCT save events fire on every CCT row write,
including writes that originate inside our own code via the public
JE DB handle.

### Evidence
End-to-end test on 2026-05-06 with bridge id 3 set to
`direction = bidirectional`, `auto_create_target_when_unlinked = true`:

| event | timestamp | direction | result |
|---|---|---|---|
| User edits product 403 | 00:15:27 | reverse pull writes `mosaic_name` to CCT 2 | success — wrote 1 field |
| `[Reverse_Flattener]` write completes | 00:15:27 | — | no `updated-item/mosaics_data` hook fires |
| Forward push listener (waiting on that hook) | 00:15:27 | — | **never wakes** — no companion sync_log row, no cascade marker |
| Auto-create CCT 5 from product 404 | 00:20:12 | reverse pull creates+writes | success |
| Forward push listener (waiting on the new CCT save) | 00:20:12 | — | **never wakes** — same null result |

`SELECT COUNT(*) FROM wp_jedb_sync_log WHERE direction='push' AND
JSON_EXTRACT(context_json,'$.cascade')='push_in_flight'` returns 0
across every test run. Five reverse-pull writes that wrote real CCT
data, zero forward-push cascade events.

The user even attempted a forced-write test (rows 31, 32) — edited
the CCT mosaic_name AFTER a reverse pull had brought it into sync,
expected forward push to write, expected reverse pull to bail with
the cascade marker. Both rows logged `noop` because the diff engine
correctly short-circuited (CCT and product values matched), and the
test never produced an actual cross-direction event to observe.

### Reality
The `jet-engine/custom-content-types/created-item/{slug}` and
`updated-item/{slug}` hooks are fired by JetEngine's **higher-level
CCT save handlers** — REST API endpoints (`POST /jet-cct/{slug}`),
the JE CCT admin form submit handler, and the Phase 2 picker payload
processor. They are NOT fired by JE's low-level `$db->update()` /
`$db->insert()` methods.

This means:

| Direction | Recursion possibility |
|---|---|
| **Forward push (CCT → post)** writes through `$product->save()` → WC fires `woocommerce_update_product` (and `save_post_product`) → reverse pull listener wakes → cross-direction lock check fires → bails with `cascade: push_in_flight`. **Cycle is possible; cascade check is the active protection.** |
| **Reverse pull (post → CCT)** writes through `$cct->db->update()` → JE does NOT fire `updated-item/{slug}` → forward push listener never wakes → no cascade event → **cycle architecturally cannot form.** |

The plugin's cross-direction `is_locked()` check still runs in both
engines and is correct, but on the reverse side it is *unreachable
defensive code* under current JE behavior — a belt to the
suspenders of "the hook just doesn't fire on the path that would
recurse."

### Why this is actually a positive finding
- Bidirectional bridges are recursion-free even if a future regression
  weakens our cross-direction lock check on the reverse side.
- Editorial mental model is simpler: "post saves can cascade; CCT
  saves done by us don't."
- We don't have to introduce hook-suppression flags or instance
  re-entry counters for the reverse path.

### Why we still want the lock check
- A future JE version may start firing `updated-item` from
  `$db->update()` (it's a reasonable behavior change). If they do,
  our cross-direction check kicks in automatically — no plugin update
  needed.
- Third-party plugins that re-fire `updated-item` after our writes
  (some sync-tracking plugins do this) would otherwise create the
  cycle JE itself doesn't.
- Phase 4 (Bridge meta box on the product edit screen) might trigger
  manual sync via JE REST APIs that DO fire the hook. The lock check
  still guards that path.

### Affected code
- `includes/flatten/class-flattener.php` — cross-direction lock check
  at the top of `apply_bridge()` (kept as-is; correct)
- `includes/flatten/class-reverse-flattener.php` — cross-direction
  lock check at the top of `apply_bridge()` (kept as-is; correct)
- `includes/targets/class-target-cct.php` `update()` / `create()` —
  call `$db->update()` / `$db->insert()` directly; this is the
  intentional bypass that makes the reverse path non-recursive.
- `BUILD-PLAN.md` §4.10 cycle-prevention notes — needs a footnote
  about the asymmetry (added in 0.5.1)
- `CHANGELOG.md` 0.5.1 entry

### Fix shipped in
v0.5.1 — documentation-only release. No code change; the existing
defensive code is correct. This entry exists to lock the architectural
understanding so the next person reading the cross-direction lock
check doesn't think it's load-bearing for the pull→push direction
(it isn't, today, but it's correct insurance).

### Prevention
1. **Don't assume save events fire from low-level WP/JE/WC API
   methods.** Verify empirically before relying on cascade behavior.
   WC fires hooks at the public typed-setter API layer (`save()`); JE
   does not at the equivalent DB-handle layer (`$db->update`).
2. **When a recursion path "doesn't fire" in testing, that's a fact
   to capture, not a bug to fix.** The right response is to document
   the asymmetry, keep the defensive check (cheap insurance), and
   move on. Don't introduce hook-firing forcing functions just to
   make symmetry feel complete.
3. **The sync log's `cascade` field will be NULL on every reverse-pull
   row in v0.5.x.** If a future test ever shows `cascade=push_in_flight`,
   that means the JE→cascade path has activated for some reason
   (3rd-party hook, JE behavior change, manual REST sync from Phase
   4's meta box, etc.) — investigate, don't celebrate.
4. **Trust the diff engine.** Many of our "expected to write but
   logged noop" surprises trace back to forgetting that a previous
   reverse-pull or self-heal already brought both sides into sync.
   The diff is doing its job; the cascade marker test failing isn't
   the test failing — it's the diff working.

---

## L-023: Taxonomies are a separate concern from field mappings — model them as a parallel `taxonomies` array, push-only in v1

**Discovered:** 2026-05-06 (Phase 3.5 / pre-0.5.2 design)
**Severity:** High (architectural lock-in for the categorization layer)
**Category:** Architecture / Schema design

### Context
Phase 3.5 testing surfaced a real editorial concern: when a Mosaic
CCT row pushes to its bridged Woo product, the product doesn't end
up in the `mosaics` category. WordPress taxonomies (`product_cat`,
`product_tag`, `pa_*` attributes, custom taxonomies) live in a
different storage system from post-meta and post-columns, so a CCT
field → product field "mapping" can't naturally express
"categorize this product under X."

### Wrong (the temptation, not yet shipped)
The first-instinct fix would be to add a one-off `default_taxonomies`
flat dictionary to bridge configs:
```json
"default_taxonomies": { "product_cat": ["mosaics"] }
```

That works for the single-taxonomy case but doesn't handle the
multi-taxonomy reality the user surfaced — a Mosaic bridge might
need to set `product_cat` (for routing/templating), `product_tag`
(for storefront filters), `pa_has_pdf` (for variation-attribute
selection in Phase 4b), AND a custom taxonomy for theme grouping.
Each of those needs its own merge strategy, its own create-if-missing
policy, and (in Phase 5b) its own snippet override.

A flat dict can't grow that far without becoming a parallel schema
overgrown into a footgun.

### Reality (what we're shipping in 0.5.2)
**Three parallel concerns, three layers of architecture, each with
clear ownership.**

| Layer | Solves | Ships in |
|---|---|---|
| **`term_lookup` transformer** | Per-row dynamic categorization driven by CCT field values (CCT `theme = "Cityscape"` → product term `Cityscape` in `product_cat`). Composes with the existing `mappings[]` and `push_transform[]` / `pull_transform[]` chains. | 0.5.2 |
| **`taxonomies` array on flatten config** | Static-per-bridge multi-taxonomy assignment. Each entry describes ONE taxonomy's behavior. Push action only — pull never modifies taxonomies. | 0.5.2 |
| **`term_assigned` trigger** | Term changes as wakeup events for the reverse engine. "When a product gets the `mosaics` category, fire the Mosaic bridge's pull." Already in BUILD-PLAN D-18's trigger taxonomy as a v1 trigger type, deferred to Phase 4.5. | Phase 4.5 |

**The `taxonomies` array shape (per bridge config):**

```json
"taxonomies": [
  {
    "taxonomy":           "product_cat",
    "apply_terms":        ["mosaics"],
    "apply_terms_inverse":[],
    "match_by":           "slug",
    "merge_strategy":     "append",
    "create_if_missing":  false,
    "snippet":            null
  },
  {
    "taxonomy":           "product_tag",
    "apply_terms":        ["custom-mosaic", "made-to-order"],
    "apply_terms_inverse":["available-set"],
    "match_by":           "slug",
    "merge_strategy":     "append",
    "create_if_missing":  false,
    "snippet":            null
  }
]
```

Per-rule fields:
- `taxonomy` — the WP taxonomy slug.
- `apply_terms` — terms to assign on push (interpreted via `match_by`).
- `apply_terms_inverse` — terms to ENSURE NOT present on push. Lets editors
  declare "this bridge's products must never be in `available-set`." The
  engine calls `wp_remove_object_terms()` for any of these that are
  currently attached.
- `match_by` — `'name' | 'slug' | 'id'`. How to interpret `apply_terms` /
  `apply_terms_inverse` strings.
- `merge_strategy` — `'append'` (default, editor-friendly: doesn't strip
  unrelated terms) or `'replace'` (canonical: bridge owns the entire
  taxonomy slot).
- `create_if_missing` — default `false`. When ON, an `apply_terms` value
  that doesn't match any existing term in `taxonomy` triggers
  `wp_insert_term()` instead of being silently dropped.
- `snippet` — placeholder for Phase 5b. When the snippet runtime ships,
  setting this to a snippet slug overrides `apply_terms` with the
  snippet's return value (an array of term references). Forward-compatible.

### Why push-only in v1
The bidirectional question — "what should pull do with taxonomies?"
— has three plausible answers, and each has a real downside:

| Pull behavior | Issue |
|---|---|
| Pull also writes terms back | Symmetric but ill-defined: which taxonomy on the post drives which CCT field? Requires snippet logic to be useful. Phase 5b territory. |
| Pull strips terms not in `apply_terms` | Destructive — would silently delete editor-added categories on every product save. Anti-feature. |
| **Pull ignores `taxonomies` entirely** | **What we're shipping.** Pull only modifies CCT fields via `mappings[]`. Taxonomies are a push-only assertion. Editors can hand-tag products with extra categories and the bridge won't strip them on next pull. |

The "pull-as-trigger" use case ("when a product gets the mosaics
category, fire the Mosaic bridge's pull") is real but architecturally
distinct — that's Play 3 (`term_assigned` trigger) and it goes in
Phase 4.5, not the categorization layer.

### Engine integration order (push)
1. Resolve target post (existing flow with L-021 self-heal).
2. Cross-direction cascade check (existing flow per L-022).
3. Condition evaluation (existing flow).
4. **NEW: Taxonomy assertions.** For each entry in `taxonomies[]`:
   - Resolve `apply_terms` to term IDs (via `get_term_by(match_by)` or
     `wp_insert_term()` if `create_if_missing` AND the term doesn't exist).
   - Resolve `apply_terms_inverse` similarly.
   - Call `wp_set_object_terms($post_id, $resolved_ids, $taxonomy, $append)`
     where `$append` is `true` for `'append'` strategy, `false` for `'replace'`.
   - Call `wp_remove_object_terms($post_id, $inverse_ids, $taxonomy)` for the
     inverse terms.
5. Field mappings (existing flow with `term_lookup` available as a transformer).
6. Sync log records taxonomies applied alongside fields written.

### Why `term_lookup` AND `taxonomies` array (not one or the other)
They solve different problems and are forward-compatible:
- `term_lookup` is a **transformer** — operates on a single value, fits in
  the existing `push_transform[]` / `pull_transform[]` chain, writes to a
  field like `category_ids`. Per-row dynamic.
- `taxonomies[]` is an **action** — operates on the post, runs separately
  from mappings, bridge-level static. Per-bridge static.

Editors can use either or both. Multi-taxonomy bridges typically use
`taxonomies[]` for static categorization + `term_lookup` for one or two
fields where the CCT value drives the taxonomy.

### Affected code (planned, 0.5.2)
- New: `includes/flatten/transformers/class-transformer-term-lookup.php`
  — implements `JEDB_Transformer` interface; registered in `bootstrap_defaults()`.
- New: `includes/flatten/class-taxonomy-applier.php` — runs the taxonomies
  array against a post; called from both forward and reverse flatteners
  (forward: writes; reverse: skipped per "push-only in v1").
- Modified: `includes/flatten/class-flatten-config-manager.php` — adds
  `taxonomies` (default `[]`) to the canonical config_json shape; adds
  default-shape merging for new entries on read.
- Modified: `includes/flatten/class-flattener.php` — calls
  `JEDB_Taxonomy_Applier` between condition check and mappings.
- Modified: `templates/admin/tab-flatten.php` — new "Taxonomies"
  collapsible section visible when target_target is `posts::*`.
- Modified: `assets/js/flatten-admin.js` — wires the new section, queries
  taxonomies + terms via WP REST or AJAX endpoints.
- New AJAX endpoint: `jedb_flatten_get_post_type_taxonomies` returns the
  list of taxonomies registered for the chosen post type, plus first 100
  terms in each.

### Prevention
1. **Treat taxonomies as a separate concern from field mappings, always.**
   They have different storage, different cardinality semantics, different
   merge rules, and different creation costs.
2. **Don't try to "unify" everything into the `mappings[]` array.** Each
   thing the bridge can do (mappings, taxonomies, future: variations,
   downloads, attributes) gets its own array if it has its own semantics.
   The flatten config is a CONFIGURATION, not a programming language —
   verbosity is a feature when it makes intent obvious.
3. **Push-only is a valid v1 stance** when the symmetric bidirectional
   semantics aren't well-defined. Better to ship a clear unidirectional
   feature and add the reverse side under a separate trigger later than
   to ship something half-baked that loses data on every sync.
4. **Forward-compat with the snippet runtime** by adding a `snippet`
   slot per rule that's nullable today and meaningful in Phase 5b. No
   schema migration required when snippets ship.
5. **The UI should query the live system** — `get_object_taxonomies()`
   for available taxonomies, `get_terms()` for available terms. Don't
   ask editors to type taxonomy slugs; surface what's actually
   registered.

---

## L-024: Apply taxonomy rules AFTER field mappings, not before — typed setters that target taxonomy fields will clobber pre-applied terms

**Discovered:** 2026-05-06 (Phase 3.6 / version 0.5.3)
**Severity:** High (silent data loss disguised as success)
**Category:** Architecture / Engine ordering

### Context
The user configured a Phase 3.6 bridge with both layers of the
categorization architecture engaged simultaneously on the same
taxonomy:

- A `taxonomies[]` rule: `{ taxonomy: 'product_cat', apply_terms: ['mosaics'], merge_strategy: 'append' }`
- A field mapping: `theme_idea -> category_ids` with a `term_lookup`
  transformer set to `{ taxonomy: 'product_cat', match_by: 'name' }`

The CCT field `theme_idea` held a slug-style value `"available-sets"`
that did NOT match any term name in `product_cat` (the actual term
NAME would be "Available Sets" — `available-sets` is its slug).

Expected behavior: product ends up in `mosaics` (from the rule),
because the rule's append semantics should preserve whatever else
got set.

Observed behavior: product had NO categories at all. Sync log
nevertheless reported success with `terms_added: 1` (mosaics).

### Wrong
The original §4.11 design ran the taxonomy applier BEFORE the field
mappings. The rationale was editorial intent: "categorization is
upstream of field copy-paste." That rationale ignored the mechanical
reality of what mappings can do to taxonomies.

### Evidence
Real sync log row from the user's staging environment:

| field | value |
|---|---|
| direction | push |
| status | success |
| message | "wrote 1 field(s)" |
| fields | `["category_ids"]` |
| per_field | `{"name":"noop","category_ids":"will_write"}` |
| taxonomies.terms_added | 1 |
| taxonomies.rules[0].added_ids | `[17]` |

Yet the product had zero categories visible in WC admin.

### Reality
Trace of what actually happened, in the original (broken) order:

1. **Taxonomy applier (first):** called
   `wp_set_object_terms(403, [17], 'product_cat', append=true)`.
   Product now correctly has `mosaics` (term 17) attached. Sync log
   accurately records `added_ids: [17]`.
2. **Mappings loop (second):** the `term_lookup` transformer with
   `match_by='name'` scanned `product_cat` for a term NAMED
   `"available-sets"` and found none (only a term *slugged*
   `available-sets` exists, named "Available Sets" or similar).
   Returned `[]`. Mapping payload became `{ category_ids: [] }`.
3. **Adapter write:** `WC_Product::set_category_ids([])` REPLACED
   the entire `product_cat` slot with empty. The `mosaics` term we
   just added was wiped out.
4. **Final state:** product has zero categories. Sync log lies
   about success because each step *did* what it claimed; the
   second step just clobbered the first.

This is silent data loss disguised as success — the worst kind of
bug because the audit trail says "everything worked."

### Fix shipped in
v0.5.3 (this release).

`JEDB_Flattener::apply_bridge()` now runs in this order:

1. Resolve target post.
2. Cross-direction cascade check.
3. Condition evaluation.
4. **Mappings loop** — build payload, diff, write through target
   adapter.
5. **Taxonomy applier** — runs AFTER the adapter write so its
   `wp_set_object_terms()` calls operate on the post-mapping state.

This means taxonomy rules ALWAYS get the final word:

- `merge_strategy='append'` rules pile on top of whatever the
  mapping wrote (mapping's `[42]` + rule's `[mosaics]` → both attached).
- `merge_strategy='replace'` rules become canonical (mapping write
  doesn't survive a replace rule).
- A mapping that resolved to `[]` (e.g. `term_lookup` found nothing)
  clears the slot, but the subsequent taxonomy rule restores
  whatever the editor configured. **No more silent category
  disappearances.**

### Companion fix — `term_lookup` zero-resolve warning
In addition to the ordering fix, `JEDB_Transformer_Term_Lookup::apply_push()`
now logs a warning to `jedb-debug.log` when the input had non-empty
candidate values but ALL of them failed to resolve. Most common cause
is the `match_by` / value-shape mismatch the user hit. The log
includes the unmatched values and a hint:

```
[Transformer:term_lookup] resolved 0 term IDs from non-empty input —
likely a match_by / value-shape mismatch
{
  "taxonomy":         "product_cat",
  "match_by":         "name",
  "unmatched_values": ["available-sets"],
  "hint":             "try match_by=\"slug\" if your CCT field stores
                       slug-style values, or match_by=\"name\" if it
                       stores display names"
}
```

This makes the user-config gotcha self-diagnosing.

### Affected code
- `includes/flatten/class-flattener.php` `apply_bridge()` — full
  mapping/taxonomy ordering refactor with explicit four-path
  status determination (errored / mappings-wrote / taxonomies-only /
  noop).
- `includes/flatten/transformers/class-transformer-term-lookup.php`
  — new zero-resolve warning logic in `apply_push()`.
- `BUILD-PLAN.md` §4.11 "Engine integration order on push" —
  rewritten with the new ordering and the rationale callout.
- `LESSONS-LEARNED.md` (this entry).

### Prevention
1. **Verify ordering against EVERY downstream side-effect, not just
   the immediate one.** It wasn't enough to think "taxonomies should
   conceptually run first." The question that should have been asked
   in design: "what happens if a mapping's adapter write touches
   the same WP API surface the taxonomy applier just wrote to?"
   Anything that uses typed setters to write to a slot will REPLACE
   that slot — so anything earlier targeting the same slot loses.
2. **A "success" sync log row that doesn't match observable state on
   the target is a critical bug, not a documentation issue.** The
   sync log's job is to be the audit-trail truth. When it says
   "added [17]" but the post has no terms, the engine has a
   defect, not the log format.
3. **Run rules AFTER mappings for ALL bridge-level concerns going
   forward.** If a future Phase X adds, e.g., variation reconciliation
   or downloads management, those should also run after mappings
   for the same reason — they're "rules to enforce on top of whatever
   field-level changes happened."
4. **Default to ordering that's robust against config mistakes.**
   Editors will misconfigure things — wrong `match_by`, typos in
   slugs, dropdown drift after term renames. The engine's ordering
   should make their misconfigurations fail-safe (rules win) rather
   than fail-silent (mapping clobbers rule with no warning).
5. **Surface zero-resolve cases in transformers.** Silent zero
   results from a transformer chain that received non-empty input is
   the editor's #1 "why isn't my config working?" debugging blocker.
   Always log a warning with enough context to fix it.

---
