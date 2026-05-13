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

## L-025: Bridge type and flatten config inner shapes MUST mirror each other — gratuitous key renames between a template and its instance cause silent data loss

**Discovered:** 2026-05-10 (Phase 4 / Day 1, version 0.6.0-alpha.1 → alpha.2)
**Severity:** High (silent data loss, save reports success)
**Category:** Architecture / Schema design

### Context
Phase 4 Day 1 introduced the Bridges admin tab and `JEDB_Bridge_Types_Manager`,
which manages a list of *bridge type templates* in the `jedb_bridge_types`
site option. Each bridge type is meant to be a template that the Phase 4
Day 2 Bridge meta box clones into a concrete `wp_jedb_flatten_configs`
row when an editor wires up an individual product.

Editors had a perfectly natural workflow expectation:
1. Build a working bridge in the Flatten admin tab manually (mappings,
   taxonomies, condition, trigger, etc.).
2. Click "Show advanced JSON" on that flatten config to see the raw
   payload.
3. Copy it.
4. Paste it into a bridge type's "Defaults JSON" textarea.
5. Save. Future products linked to this bridge type now start from
   that proven baseline.

### Wrong
The alpha.1 bridge type schema gave the inner config keys a
`default_` prefix to signal "these are templates that get cloned":

```
flatten config (wp_jedb_flatten_configs.config_json):
  mappings, taxonomies, condition, condition_snippet, priority,
  trigger, link_via, auto_create_target_when_unlinked,
  required_overrides, origin_tag

bridge type (alpha.1):
  default_field_mappings  ←  not "mappings"
  default_taxonomies      ←  not "taxonomies"
  default_condition       ←  not "condition"
  default_priority        ←  not "priority"
  default_direction       ←  (top-level rename)
  link_via                ←  same name (good)
  auto_create_target_when_unlinked  ←  same name (good)
  (no trigger, condition_snippet, required_overrides, origin_tag)
```

Every key the user paste-tested with had a different name in the
bridge type schema. `wp_parse_args( $input, $defaults )` saw a payload
of `mappings` / `taxonomies` / `condition` / `priority` and the
defaults of `default_field_mappings` / `default_taxonomies` etc.,
silently kept the user's keys as "extra fields" but ALSO filled in
the empty defaults for the prefixed keys. Then the per-key
sanitizers ran on the prefixed keys (which were empty) and stored
empty arrays. The user's pasted values were never written to any
field the manager would later read.

### Evidence
The user's report:
> "I tried copying the raw config (advanced) JSON from flatten tab and
> pasting it into a bridges defaults json and when i clicked save, it
> didn't actually save it."

The bridge type save flow:
1. JSON textarea → `$decoded` array with keys `mappings`, `taxonomies`, …
2. Form fields override `slug`, `label`, `source_target`, `target_target`,
   `default_direction`, `default_priority`, `default_condition`, etc.
   (form ALWAYS writes to the prefixed keys).
3. `prepare_for_storage()` calls `wp_parse_args()` which keeps
   `mappings` / `taxonomies` as extra keys but also adds empty
   `default_field_mappings: []` / `default_taxonomies: []` from defaults.
4. `sanitize_mappings()` is called on `$bt['default_field_mappings']`
   → empty in, empty out. **The user's `mappings` array is now an
   orphan with no sanitizer touching it.**
5. The manager-level `merge_with_defaults()` is the read path —
   it only knows `default_field_mappings`. The orphan `mappings`
   key is never read back.
6. Reload form → the `default_field_mappings` slot is empty, which
   the template renders as an empty array. **User sees: "the JSON
   I pasted didn't save."**

### Reality
The save did persist the row. It even persisted the user's pasted
keys (under their original names — `mappings`, `taxonomies`). But
**nothing in the manager's read path or render path knew to look
for those names.** The data was orphaned the moment it hit storage.

This is the same shape of bug as L-024 (silent data loss disguised
as success), but with a different root cause: instead of "ordering
clobbers data," it's "schema mismatch silently drops data."

### Fix shipped in
v0.6.0-alpha.2 (this release).

**Schema realignment:** the bridge type's inner config block was
restructured to match the flatten config's `config_json` shape
EXACTLY. New shape:

```
bridge_type (alpha.2):
  slug, label, description,                     (admin metadata)
  source_target, target_target, direction,      (relationship metadata)
  enabled, cct_single_redirect, variations,     (toggles + Phase 4b)
  flatten_defaults: {
    mappings, taxonomies, condition, condition_snippet,
    priority, trigger, link_via,
    auto_create_target_when_unlinked,
    required_overrides, origin_tag,             (← matches flatten config)
  },
  created_at, updated_at,
```

The Bridge meta box's clone operation (Day 2) becomes a one-liner:
`$flatten_config['config'] = $bridge_type['flatten_defaults']`.
No translation table, no key remapping.

**Back-compat:** `JEDB_Bridge_Types_Manager::upgrade_alpha1_shape()`
runs on every read. If an entry has alpha.1 top-level keys
(`default_field_mappings`, `default_taxonomies`, etc.), they're
silently lifted into `flatten_defaults` and the prefixed keys are
removed. Idempotent. Persists in alpha.2 shape on the next save.
No editor action required.

**Paste-shape tolerance:** the save handler's
`unwrap_flatten_payload()` accepts three textarea shapes:
1. Raw flatten config inner block (most common — copy from
   Flatten admin tab's Advanced JSON).
2. Wrapper `{ "flatten_defaults": { ... } }` (from a bridge type
   export).
3. Full bridge type entry (the inner `flatten_defaults` is auto-unwrapped).

All three round-trip cleanly. Pasting raw flatten "Advanced JSON"
verbatim now Just Works.

### Affected code
- `includes/admin/class-bridge-types-manager.php` —
  `default_bridge_type()`, `default_flatten_defaults()`,
  `prepare_for_storage()`, `sanitize_flatten_defaults()`,
  `upgrade_alpha1_shape()`, `merge_with_defaults()`. Schema
  refactor + back-compat migration.
- `includes/admin/class-tab-bridges.php` — `handle_save()` rewritten
  around the new shape; new `unwrap_flatten_payload()` helper.
- `templates/admin/tab-bridges.php` — form field renames
  (`default_direction` → `direction`, `default_priority` → `priority`,
  `default_condition` → `condition`), Defaults JSON textarea shows
  the `flatten_defaults` block directly with the new "this IS a
  flatten config payload" framing, list-table column derivations
  read from `$bt['flatten_defaults']` instead of top-level keys.
- `LESSONS-LEARNED.md` (this entry).

### Prevention
1. **When System A is a template for System B, A's inner shape MUST
   mirror B's.** Don't gratuitously rename keys to "signal that A
   is a template" — every rename is a paper cut every time someone
   copy-pastes between the two surfaces, and silent renames cause
   silent data loss. If you need to flag "this is a template,"
   wrap the payload in a sub-object (`flatten_defaults`) or add
   metadata at a higher level — don't rename the keys themselves.
2. **Make the copy-paste workflow a first-class design criterion.**
   "Can I paste a raw flatten payload into the bridge type editor
   and have it Just Work?" was a workflow the user discovered on
   their own. The schema should welcome it, not punish it.
3. **`wp_parse_args` is permissive — sanitizers must be too.**
   `wp_parse_args` doesn't drop unknown keys (it merges them in).
   So if the sanitizer only touches known keys, unknown keys
   silently survive. Either:
   - Whitelist the keys the sanitizer cares about and explicitly
     drop everything else (so unknown input fails loudly), OR
   - Match the schema such that the keys the user is likely to
     send ARE the keys the sanitizer expects (this lesson's fix).
4. **Save → reload → re-edit is the integration test for any
   config UI.** When a user reports "I saved this but it didn't
   stick," it's almost always either:
   - A schema mismatch between the form and storage (this lesson), or
   - A sanitizer that strips the value too aggressively, or
   - An asymmetry between write and read paths.
   Test the full round-trip on every config form, with realistic
   editor-pasted payloads, before considering a feature shipped.
5. **Phase 4 `flatten_defaults` block is now load-bearing — keep it
   in sync with `JEDB_Flatten_Config_Manager::default_config_json()`.**
   `JEDB_Bridge_Types_Manager::default_flatten_defaults()` delegates
   to that method when the class is loaded; otherwise it returns
   a hard-coded mirror. If the flatten config schema ever grows
   a new key, both surfaces must be updated together — and the
   alpha.1 → alpha.2 migration template (`upgrade_alpha1_shape()`)
   becomes the prototype for any future schema migrations.

### Postscript (added 2026-05-10 alongside L-026)

This lesson stayed valuable but its framing changed. The schema-mirror
rule above was the *symptom-level* fix. The deeper, *root-cause* lesson
was: *the template layer that needed mirroring shouldn't have existed
in the first place.* See **L-026 — Premature template-layer abstraction**
for the architectural review that retired bridge types entirely (D-25),
moved per-product overrides to engine-level guards, and recast the meta
box as a view of the flatten config (D-27). L-025's prevention rules
remain useful any time you DO have two systems that must mirror each
other (e.g. bridge presets and flatten configs in the Phase 6 setup
preset format), but the meta-rule from L-026 — "don't add a template
layer until you have ≥2 real consumers driving the design" — applies
first.

---

## L-026: Premature template-layer abstraction — retiring the bridge type concept

**Discovered:** 2026-05-10 (Phase 4 / Day 1 architectural review, between v0.6.0-alpha.2 and the alpha.3 release that opens Phase 4 reshape)
**Severity:** High (over-engineering manifested as silent data loss in L-025; deeper review found the layer didn't deliver value)
**Category:** Architecture / Premature abstraction

### Context
v0.6.0-alpha.1 introduced a Bridges admin tab plus a `JEDB_Bridge_Types_Manager` class managing a `jedb_bridge_types` site option. The premise: bridge types would be **templates** — declared once per "kind of bridge" (Mosaic, Available Set, etc.) — that the eventual Phase 4 Day 2 Bridge meta box would clone into concrete `wp_jedb_flatten_configs` rows when an editor wired up an individual product. This was carried forward from BUILD-PLAN §3.1 (D-5 in the original decisions log).

The build of alpha.1 went smoothly. Schema, CRUD wrapper, admin tab UI, JSON export/import, live JE Relation picker — all shipped. Lint clean. Pushed.

### Wrong
The template layer was an answer to a workflow problem that didn't actually exist on the operating site (Brick Builder HQ):

1. **The anticipated "many products of the same kind being onboarded by editors" workflow was hypothetical.** BBHQ has TWO long-lived bridges (`cct::mosaics_data` ↔ `posts::product` and `cct::available_sets_data` ↔ `posts::product`). Both already exist as flatten configs. They work. New products are auto-created from the CCT side via JE Has-Single-Page; editors don't reach for "make this a Mosaic" from the Woo side.
2. **"Templates not bindings" creates surprise.** Editing a bridge type after products are linked doesn't propagate to the existing flatten configs — only future clones get the changes. This subtle semantic gap kept showing up as a UI warning callout. That's a smell — the system was fighting itself.
3. **Templates double the editing surface.** Every concept now needs to be authored twice (once in the bridge type, once per flatten config that diverges from it). Mappings, taxonomies, conditions, link_via — all of it lived in both places, joined by clone semantics.
4. **The schema mismatch surfaced in L-025 was the smoking gun.** When a user pasted a working flatten config's "Advanced JSON" into the bridge type editor, it silently dropped every mapping. The cause was renamed keys. The deeper cause was that the two systems shouldn't have had different shapes at all — and one of the two systems shouldn't have existed.

### Evidence
The user's verbatim feedback after the alpha.2 hotfix landed:

> *"Honestly, the whole bridge thing feels redundant when we have the flatten thats proven and working… I'd almost prefer we don't use the bridge tab and then just append. The biggest part of meta box is to show fields that are important on woocommerce from the CCT, because the product post is not easy to add custom fields to. so having extra fields available on this post that sync back to the CCT is important."*

That feedback identified three concepts that had been getting mashed together inside the bridge type abstraction:

1. **The meta box's killer feature** is field surfacing on the Woo product edit screen, not template selection. WC products are notoriously hard to extend with custom fields; the CCT carries operational fields (`pdf_link`, `theme_idea`, `internal_notes`); editors want to see and edit those without context-switching.
2. **The "configurable mandatory fields" story** is a separate, portable artifact — a curated "what does a complete bridge to target X look like?" list — that should travel between sites as JSON. PAC VDM hardcoded this knowledge in PHP per role; we can pull it out of code.
3. **Per-product overrides** (lock, direction override) are post meta + engine guards, not "instance overrides on a template's defaults."

When those three concepts are recognized as separate, the bridge type abstraction collapses — there's nothing left for it to hold.

### Reality
The architecture that actually fits the requirements (per D-25 / D-26 / D-27 added 2026-05-10):

1. **One authoring surface for bridges:** the Flatten admin tab. The flatten config IS the bridge identity. No separate template layer.
2. **One storage table for bridges:** `wp_jedb_flatten_configs`. No parallel option.
3. **Meta box reads flatten configs directly** (D-27). Walks the table at render time, runs the existing link-resolution logic to determine which bridge governs THIS product, renders one panel per resolved bridge.
4. **Field surfacing** lives on the flatten config — per-mapping `surface_on_source` / `surface_on_target` flags + freeform `group` label. The meta box renders an input for each mapping flagged for the target side where the adapter's `is_natively_rendered()` returns false (D-16 composes naturally).
5. **Per-product overrides** are post meta — `_jedb_bridge_locked`, `_jedb_bridge_direction_override` — checked by engine guards at the top of `apply_bridge()` calls. Three lines of new logic per engine, no new tables, no new options.
6. **Field Presets** (D-26) become a separate first-class concept: target-scoped, exportable JSON, three application modes (display-only overlay, apply-as-`required_overrides.add`, scaffold-as-passthrough-mappings). Solves the "I discovered the right Woo storefront-visibility field list, package it, drop it on the next site" workflow that PAC VDM hardcoded per role.

The bridge type as an abstraction had been doing 3 unrelated jobs poorly. Splitting them into 3 proper homes does each one well, with less code overall.

### Fix shipped in
v0.6.0-alpha.3 (Phase 4 Day 1).

The alpha.3 release deletes the entire alpha.1/alpha.2 footprint:

- `includes/admin/class-bridge-types-manager.php` (~620 lines)
- `includes/admin/class-tab-bridges.php` (~330 lines)
- `templates/admin/tab-bridges.php` (~280 lines)
- `assets/js/bridges-admin.js` (~220 lines)
- The Bridges-tab CSS block (~55 lines; `.jedb-pill-info` stays as general-purpose)
- `JEDB_OPTION_BRIDGE_TYPES` constant + activation default
- 4-line bootstrap in `class-admin-shell.php`

Net deletion: ~1,500 lines.

In its place:

- Flatten config schema gets a `meta_box: { enabled, title, position, groups[] }` block + top-level `cct_single_redirect: bool`.
- Each mapping gets `surface_on_source`, `surface_on_target`, `group` fields (freeform group per D-26).
- Engine guards add `_jedb_bridge_locked` + `_jedb_bridge_direction_override` checks with new sync_log statuses (`skipped_locked` with `reason: per_product_lock`, `skipped_direction_override`).
- New `jedb_field_presets` site option + `JEDB_Field_Presets_Manager` (skeleton in Day 1, full UI in Day 4).

### Affected code
- Deletion list above.
- `includes/flatten/class-flatten-config-manager.php` — schema extensions (Day 1).
- `includes/flatten/class-flattener.php` + `class-reverse-flattener.php` — engine guards (Day 1).
- `includes/admin/class-tab-flatten.php` + `templates/admin/tab-flatten.php` + `assets/js/flatten-admin.js` — UI extensions (Day 1).
- `includes/admin/class-woo-product-meta-box.php` — new in Day 2.
- `includes/class-cct-single-redirect.php` — new in Day 3.
- `includes/admin/class-field-presets-manager.php` + `class-tab-field-presets.php` + templates / JS — new in Day 4.
- `BUILD-PLAN.md` §4.5 / §4.5.1 / §4.6 / §4.12 / §7 / §8 / §9 / §12 — updated 2026-05-10 with the reshape.

### Prevention
1. **Don't add a template-and-instance layer until you have at least TWO real consumers driving the design.** Bridge types as a separate layer would have made sense if BBHQ had 20+ products of varying shapes that editors needed to onboard quickly via "pick a kind." With 2-3 long-lived bridges authored once, the layer is pure indirection.
2. **The "what does this layer let me do that the existing layer doesn't?" test.** Before adding a template layer, list the user actions that become possible (or significantly faster) AFTER the layer exists vs BEFORE. If the list is short or hypothetical, don't add the layer. For bridge types, the actions were: (a) "name a kind of bridge for editor recognition" — but flatten config `label` already does this; (b) "share defaults across instances" — but BBHQ has one instance per kind; (c) "package presets for setup" — but Phase 6 setup presets ship flatten configs directly.
3. **Surface vs identity.** A meta box on the Woo product edit screen is a *surface* (where editors interact) — it doesn't need to own the *identity* (what bridge governs this product). Trying to make the meta box's authoring surface own the identity created the template-layer pressure. Recognize when a UI surface is ASKING for an identity layer because the existing identity layer (flatten config) wasn't surfaced there yet — the answer is to surface the existing identity, not to invent a new one.
4. **Operational knowledge belongs in portable artifacts, not hardcoded PHP and not the bridge config.** PAC VDM hardcoded "Vehicle Configs need year + make + model" — an operational truth — into PHP per role. We tried to bury it in a bridge type's `default_field_mappings`. Both are wrong. The right home is a third concept (Field Presets) that's target-scoped and exportable across sites because the knowledge IS site-portable in the way the bridge config isn't.
5. **An architectural reframe in alpha is cheap; rolling it out in beta is expensive.** alpha.1 → alpha.3 cost ~2 days of work + a clean revert. The same reshape after beta would have meant deprecation cycles, migration scripts, and editor retraining. Use the alpha label literally — push back hard on architecture during alpha, accept that "maybe we shouldn't have built this" is a perfectly valid alpha-phase outcome.
6. **L-025's lesson stays valid for any future case where two systems DO need to mirror each other.** Bridge presets vs flatten configs in the Phase 6 setup preset format is one such case. The lesson there is enforceable AT design time by adopting the same `default_config_json()` shape verbatim, not by inventing a parallel schema with similar-but-renamed keys.

---

## L-027: Don't rebuild every JE field type. Delegate editing to JE itself via a chrome-stripped modal iframe.

**Discovered:** 2026-05-15 (Phase 4 Day 2 hardening / version 0.6.0-alpha.6)
**Severity:** High
**Category:** Architecture / Defensive coding

### Context
After alpha.5 shipped the inline-editable surfaced fields on the Woo product meta box, the editor pointed out that **field types weren't honored**: a CCT field of type `select` rendered as a plain text input, a media field rendered as a text input, WYSIWYG rendered as a textarea, gallery as a textarea, etc. Editors expected the same UI affordances JE provides on its own CCT edit page. The natural next step looked like: "OK, render each field type properly in our meta box — `<select>` for selects, the WP media picker for media, `wp_editor()` for WYSIWYG, etc."

### Wrong (the alpha.6-as-originally-planned path)
The initial plan for alpha.6 was a `JEDB_Field_Renderer` class with type-specific renderers — render select boxes with options, integrate the WP media library JS for media fields, mount `wp_editor()` for WYSIWYG, etc. Plus a `Target_CCT::get_field_schema()` extension to surface enough metadata (option lists, glossary IDs, allowed mime types) for our renderers to do something equivalent to JE's.

This was wrong for three reasons that compounded:

1. **Ongoing maintenance burden.** Every new JE field type would have meant a new renderer in our plugin. JE's surface is large and grows over releases (e.g. JE 3.x added new field types). We'd be perpetually behind.
2. **Subtle behavioral drift.** Our select would not match JE's select. Our media picker would not match JE's media picker. Our gallery picker would diverge from JE's. Editors would get two slightly-different UIs for the same data and have to track which surface they were on.
3. **Architectural coupling we couldn't avoid.** For glossary-backed selects we'd have to call `jet_engine()->glossaries->...`. For media we'd duplicate JE's attachment handling. For conditional-field-visibility we'd need to re-implement JE's expression evaluator. Each of these is a small leak; together they make our plugin a partial reimplementation of JE itself.

### Evidence
- alpha.5 staging test: editor opens product edit screen, sees `<input type="text">` for `theme_idea` (a CCT select field), enters a value that isn't in the option list, save succeeds, JE later rejects the value silently because it didn't pass JE's own select validation.
- alpha.5 staging test: media field rendered as text input expecting an attachment ID; pasting a URL "worked" in our text input but produced an unusable target value.
- User feedback: "the only other issue I have now is that field type is not considered. So for example, if we are using a drop down with a select box or a media field or WYSIWYG or a gallery field… it doesn't carry over."
- User-proposed alternative architecture (which won): "What if we showed a non editable 'Surfaced' fields in this section... But created a button that says save current progress and edit extra fields. Maybe even make it a pop up with only that CCT's edit page (so wordpress side bars don't load or anything distracting) and then if that pop up gets saved it closes and the page reloads."

### Reality
JE already has a CCT edit page that renders every field type correctly (because JE wrote it, it gets updated when JE updates, glossaries integrate natively, conditional visibility works, media library works). The CCT edit page URL is `admin.php?page=jet-cct-{slug}&cct_action=edit&item_id={id}` (verified against the RI repo's JE API reference doc and our own existing `class-target-cct.php` line 443 comment). The save form is a standard HTML form POST to `?cct_action=save-item` — not AJAX, just a regular POST that causes a page reload (verified in the RI repo's JE API reference, "Form Selector" section).

The right architecture is to **delegate editing to JE entirely** and provide a one-click bridge from the product edit screen into JE's editor:

1. The Bridge meta box on the product edit screen renders each surfaced mapping as a **read-only, type-aware preview** (text → escaped text, boolean → ✓/✗ pill, media → thumbnail, gallery → thumbnail grid, select → option label, etc.). This is much easier than editable rendering because we're not handling input/save semantics, just display.
2. Below the previews, a **"Save & edit CCT row"** button per bridge. Clicking it:
   - If the product form is dirty, asks the editor to save first. On confirm, stamps a `_jedb_reopen_cct_bridge` hidden marker and submits the WP form. After save, `handle_save()` writes a 60-second transient `jedb_reopen_cct_{user}_{post}` keyed to this user+post.
   - On the reloaded page, the JS bootstrap reads the transient (passed via `wp_localize_script`) and auto-launches the modal for that bridge.
3. The modal contains an iframe whose `src` is the JE CCT edit URL plus `?jedb_chrome=stripped&jedb_return={post_id}`.
4. A new method `JEDB_Woo_Product_Meta_Box::maybe_inject_cct_chrome_strip()` hooks `admin_head`. When it sees `page=jet-cct-*` AND `jedb_chrome=stripped`, it injects CSS that hides `#wpadminbar` / `#adminmenu*` / `#wpfooter` / `#screen-meta*` AND a top bar with two buttons:
   - **Done · Return to product** → `parent.postMessage({type:'jedb:cct-modal-close', reload:true}, origin)`.
   - **Cancel** → `parent.postMessage({type:'jedb:cct-modal-close', reload:false}, origin)`.
5. The parent product edit page listens for `message` events with `origin === window.location.origin` and acts on `jedb:cct-modal-close`: close the modal, reload the parent (if `reload:true`).
6. The editor saves in JE's native UI using JE's native Save button. JE's normal save flow fires `updated-item/{slug}`. Our forward-push engine subscribes to that hook (Phase 3). Push lock prevents the reverse-pull cascade. Target gets updated values. The parent page reloads and shows the new values in its read-only previews.

### Bonus payoff
The alpha.5 explicit-`apply_bridge` workaround (the "double work" the user flagged) is no longer needed. L-022's "adapter writes don't fire JE hooks" is a real architectural quirk but it ONLY bit us in alpha.5 because the meta box was bypassing JE and writing to source directly via the adapter. In the alpha.6 model, the meta box never writes to source — the source write happens inside the modal iframe via JE's own form POST, which fires every hook JE intends to fire. The natural Phase 3 pathway works perfectly.

In other words: the L-022 quirk only matters for writes that ORIGINATE from our adapter. Writes that originate from JE's own UI behave normally. By routing editing through JE's UI, we sidestep L-022 entirely.

### Affected code
- `includes/admin/class-woo-product-meta-box.php` — slim `handle_save()` (drop `apply_surfaced_edits_for_bridge()` entirely, drop the explicit `apply_bridge()` call from alpha.5, drop `jedb_surfaced[][]` form handling, drop the `meta_box_inline_save` sync_log row, drop the `meta_box_post_save_push` origin tag). Add `maybe_inject_cct_chrome_strip()` for the iframe chrome strip. Extend `maybe_enqueue_assets()` to read the 60-second `jedb_reopen_cct_{user}_{post}` transient and pass it to the JS bootstrap via `wp_localize_script`. Require `includes/helpers/field-preview.php` in `hooks()`.
- `templates/admin/meta-box-bridge.php` — replace editable input rendering with read-only previews via `jedb_render_field_preview()`. Add the "Save & edit CCT row" button per bridge.
- `includes/helpers/field-preview.php` — NEW. `jedb_render_field_preview()` handles ~15 JE-style field types with sane fallbacks.
- `assets/js/bridge-meta-box.js` — modal creation, postMessage listener, dirty-form detection for the save-first flow, auto-reopen on `jedbMetaBoxBootstrap.reopenBridgeId`.
- `assets/css/bridge-meta-box.css` — read-only preview styles per field type, modal overlay styles, "Save & edit CCT row" launch button styles. The alpha.5 `.jedb-surfaced-mode-pill` block is removed (no more modes — everything is read-only).

### Fix shipped in
v0.6.0-alpha.6.

### Prevention
1. **Before reimplementing a host plugin's UI, look for a way to delegate to its existing UI in-context.** WP admin pages can be embedded in iframes from the same origin. Chrome-stripping via a query-gated `admin_head` CSS injection is a 30-line trick that lets you reuse the host's entire renderer surface. This is dramatically cheaper than mirroring the renderer.
2. **A meta box on screen X doesn't have to BE the editor for data Y — it can be a status display + launcher TO the editor for data Y.** Status display is much easier than editor (escape, format, dump). Launcher is one button. Together they often satisfy what looked like "we need an editor here."
3. **If a host plugin's adapter is missing hook firings (L-022), don't bypass the adapter — route around the adapter back through the host's own UI.** This pattern preserves every hook the host intends to fire, including hooks downstream plugins may depend on. Our explicit-`apply_bridge` workaround in alpha.5 was a defensible bounded workaround; routing through JE's UI in alpha.6 is the correct long-term answer.
4. **postMessage between same-origin iframe and parent is the right primitive for "child page wants to control parent."** Use `event.origin === window.location.origin` to authenticate. Don't store window references or use globals across frames — they survive in unexpected ways across page reloads.
5. **Watch for "we need to do double work" smells.** When the design forces you to manually orchestrate what a host plugin would do for you if it just fired its own hooks, ask whether the design is forcing you to bypass the host. Find the path that lets the host work normally instead of working around it.
6. **The "what does this layer let me do that the existing layer doesn't?" test (from L-026) applies here too.** A custom field-type renderer per JE type would give us… what? Theoretical control we never use in practice. Delegating to JE's renderer gives us correct rendering of every type forever. The test screams "delegate."

---

## L-028: Never nest `<form>` tags inside a WordPress meta box — meta boxes already live inside `#post`.

**Discovered:** 2026-05-15 (Phase 4 Day 2 hotfix / version 0.6.0-alpha.6.1)
**Severity:** Critical
**Category:** Wrong assumption / API knowledge

### Context
Phase 4 Day 2 (alpha.4) introduced the Bridge meta box on Woo product / variation edit screens. The meta box needs three distinct action endpoints (Sync now, Unlink, Link) that hit `admin-post.php`. The natural pattern — and the one used everywhere else in the plugin's standalone admin tabs — is `<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">` with hidden inputs and a submit button.

We applied that pattern verbatim inside the meta box templates. It looked correct in source.

### Wrong
We had three `<form>` blocks inside `templates/admin/meta-box-bridge.php` and `templates/admin/meta-box-bridge-unlinked.php`:

- Sync now (`<form action="admin-post.php">`)
- Unlink (`<form action="admin-post.php" onsubmit="confirm(…)">`)
- Link (`<form action="admin-post.php">` wrapping the CCT picker)

Forgot that WordPress meta boxes are **rendered inside the main `#post` form** that the post edit screen builds. That makes our `<form>` tags **nested**, which HTML5 explicitly forbids.

### Evidence
End-to-end staging tests from 2026-05-12 showed: **every product save** (regular WP Update click AND clicks on our action buttons AND the alpha.6 "Save & edit CCT row" launcher) redirected to `wp-admin/edit.php` instead of returning to the product edit page. The `jedb-debug.log` showed nothing wrong server-side — hooks registered normally, no error rows in `wp_jedb_sync_log`. Pure client/browser-layer bug, invisible from PHP.

The user described: *"anytime i save a product (regular save or from the JE Data bridge), it is just taking me to wp-admin/edit.php this was happening much earlier in the changes we were making i just didn't surface this issue until now because im not seeing a modal its just sending me to the page i just mentioned"*.

This wasn't an alpha.6 regression — alpha.4 introduced it the moment the meta box first shipped. Three releases (alpha.4, alpha.5, alpha.6) all silently carried the bug. The user only surfaced it now because alpha.6's "Save & edit CCT row" flow made the symptom unmissable (no modal, just a redirect).

### Reality
HTML5 spec, §4.10.3: *"A form element cannot be nested in another form element."*

Browser parsers handle this by:
1. **Ignoring the inner `<form>` opening tag** — the parser knows it's invalid, drops it.
2. **Treating the inner `</form>` closing tag as closing the OUTER form** — the parser has to balance something, and since the inner opening tag was dropped, the closing tag closes the only open form (the outer `#post`).

Net DOM after parsing:

```html
<form id="post" action="post.php" method="post">
  ... product fields ...
  <div id="jedb_bridge_meta_box">
    <!-- our inner <form> tag dropped -->
    <input type="hidden" name="action" value="jedb_sync_now" />
    <input type="hidden" name="bridge_id" value="1" />
    <button type="submit">Sync now</button>
  </form>  <!-- this CLOSES #post! -->
  ... rest of product page ...
  <button id="publish">Update</button>   <!-- now OUTSIDE any form -->
</form> <!-- ignored, nothing to close -->
```

The WP Update button is now outside `#post`. Click behavior depends on browser:
- **Submits nothing** — and falls through to the document's default action handler, which on many setups is `wp-admin/edit.php` via the URL bar's referrer.
- **Submits to `admin-post.php`** — because that was the LAST `<form action="…">` the parser saw before the orphaned `<button type="submit">`. With no `action=…` POST param, `admin-post.php` does nothing useful and WordPress's fallback redirects to admin home / list.

Either way, the user lands on `wp-admin/edit.php` and any pending product save is lost. Compounded silently because the field changes go to the right `<input>` elements but never get POSTed.

### Affected code
- `templates/admin/meta-box-bridge.php` lines 251-265 (alpha.4 onward) — Sync now + Unlink forms.
- `templates/admin/meta-box-bridge-unlinked.php` lines 59-96 (alpha.4 onward) — Link form.

### Fix shipped in
v0.6.0-alpha.6.1.

**Pattern: render plain `<div>`s with data attributes; let JavaScript build the real `<form>` off-DOM (appended to `<body>`) at click time and submit it programmatically.**

Template side:
```php
<div
    class="jedb-bridge-actions"
    data-jedb-form-action="<?php echo esc_url( $ajax_url ); ?>"
    data-jedb-nonce-field="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::NONCE_SAVE_FIELD ); ?>"
    data-jedb-nonce-value="<?php echo esc_attr( wp_create_nonce( JEDB_Woo_Product_Meta_Box::NONCE_SAVE ) ); ?>"
    data-jedb-post-id="<?php echo (int) $post->ID; ?>"
    data-jedb-bridge-id="<?php echo (int) $bridge_id; ?>"
>
    <button
        type="button"
        class="button button-primary jedb-bridge-action-btn"
        data-jedb-action="<?php echo esc_attr( JEDB_Woo_Product_Meta_Box::ACTION_SYNC_NOW ); ?>"
    >Sync now</button>
</div>
```

JS side:
```js
function buildAndSubmitForm( config ) {
    var $form = $( '<form>', { method:'post', action:config.action, style:'display:none;' } );
    function addHidden( name, value ) {
        $form.append( $( '<input>', { type:'hidden', name:name, value:value } ) );
    }
    addHidden( 'action',           config.wpAction );
    addHidden( config.nonceField,  config.nonceValue );
    addHidden( '_wp_http_referer', window.location.href );
    addHidden( 'post_id',          config.postId );
    addHidden( 'bridge_id',        config.bridgeId );
    if ( config.extras ) {
        $.each( config.extras, function ( k, v ) { addHidden( k, v ); } );
    }
    $( 'body' ).append( $form );
    $form.trigger( 'submit' );
}
```

Note: `type="button"` on the in-template `<button>` is critical — `type="submit"` (the HTML default!) would attempt to submit the OUTER `#post` form when clicked, which is exactly what we're trying to avoid for actions that aren't "save the product."

### Prevention
1. **Treat the meta box's outer container as a transparent rendering surface — assume everything you emit will be wrapped by WP in the post edit `#post` form. Never emit `<form>` tags. Never emit `<button type="submit">` for non-save actions.** Use `type="button"` and a JS handler.
2. **Anything that needs to POST to admin-post.php from a meta box has to be built and submitted at the JS layer.** Render plain `<div>`s with data attributes; build the form in JS appended to `<body>` (or some other container guaranteed-outside `#post`) at click time.
3. **Even `<input type="hidden" name="…">` outside any form is fine** — they just don't submit. But they DO submit if you place them inside `#post` (which is what meta box contents are). Use this carefully: for example, the meta box DOES use `<input type="hidden" name="jedb_meta_box_present" value="1">` and lock checkbox + direction radios INTENTIONALLY because we WANT those to ride along with the regular WP product save (handle_save() reads them). The distinction is: post-meta-side state goes inside #post intentionally; admin-post.php-side actions go through the JS-built off-DOM form.
4. **When you write a meta box, view it in browser devtools' Elements panel and inspect the actual DOM tree, not just the source HTML.** The browser-corrected DOM tree will show nested-form mangling clearly: the outer `<form id="post">` will close on an unexpected line, your meta box will appear to have leaked children outside the form, the Update button will be parentless or in the wrong parent.
5. **Lint rule for the future:** any PHP template at `templates/admin/meta-box-*.php` SHOULD NOT contain a literal `<form` token. Could be enforced by a CI check (`rg --quiet '<form\\b' templates/admin/meta-box-*.php && exit 1`).
6. **L-022, L-027, and L-028 share a theme: assumptions about API surfaces that turn out to be invalid against the host plugin / host system.** L-022: adapter writes don't fire JE hooks. L-027: don't reimplement what JE already does. L-028: meta boxes are inside the post form. The general rule is: when working with a host system (WP, JE, Woo), verify the rendering / hook / state model with the actual host, not your mental model.
7. **Standalone admin tab forms remain fine** — `templates/admin/tab-flatten.php`, `tab-relations.php`, `tab-debug.php`, `tab-targets.php` all use `<form>` directly. They render on their own admin pages, not inside `#post`. The bug was strictly the meta box (the only UI surface that renders inside a parent form).

---

## L-029: JE's post-save redirect strips custom query params — use sessionStorage + always-injected handler to communicate cross-frame state.

**Discovered:** 2026-05-16 (Phase 4 Day 2 hotfix / version 0.6.0-alpha.7)
**Severity:** High
**Category:** API knowledge / Cross-frame architecture

### Context
The L-027 modal-iframe pattern uses `?jedb_chrome=stripped` as the trigger query param: when JE's CCT edit page is loaded with that param, our `admin_head` hook injects CSS to hide WP admin chrome and JS for a Done/Cancel top bar. Works perfectly on initial iframe load.

But when the editor saves inside the iframe (either by clicking JE's native Save button OR by clicking our Done button which programmatically clicks JE's submit), JE's form POSTs to `?cct_action=save-item`, the server saves the CCT row, and JE constructs a redirect URL — typically back to the edit page. **That redirect URL is constructed fresh from JE's own state; it does NOT preserve our `?jedb_chrome=stripped` extra param.**

### Wrong (alpha.6 modal flow)
Three derived bugs from assuming the chrome-strip param would survive the redirect:

1. **WP chrome reappears after JE save.** Post-save iframe URL is `admin.php?page=jet-cct-{slug}&cct_action=edit&item_id={id}` — no jedb_chrome param. Our `admin_head` hook bails on the `if ( 'stripped' !== $chrome ) return;` check. No chrome-strip CSS injected. Editor sees the full WP admin chrome (admin bar, sidebar, footer) inside the iframe.
2. **Modal doesn't auto-close after save.** Our alpha.6 Done button just postMessaged the parent to close — it didn't actually submit JE's form. If the editor clicked Done, the modal closed but their edits were lost.
3. **Save & edit confirm dialog looped on the post-save reload.** Independent bug but compounding: the alpha.6 click handler had an `isProductFormDirty()` check that compared `value` to `defaultValue` on every `#post :input`. WP's autosave/heartbeat (`Restore the backup` browser notice) and various third-party plugins (`Deployer for Git`, `Jet Woo Builder Data Update`) leave inputs differing from `defaultValue` even immediately post-save, so the check returned dirty=true → confirm dialog fired again on the auto-launched page → user clicked OK → save fired → reload → loop.

### Evidence
- User staging report 2026-05-15: *"after I clicked save & Edit i got the pop up question asking me to save. If i clicked 'Ok' i would get a loop... Eventually i clicked cancle and the intended pop up showed up then. I decided to change the mosaic name from the modal and clicked the Done - return to product button (instead of the 'save' button inside the modal's cct edit section) this closed it but nothing saved."*
- *"if i click the save button inside the CCT, it refreshes the page and loads standard wordpress sidebars and headers. So if we click save inside, it should also close the modal."*
- Screenshot showed multiple unrelated admin notices on the product edit screen (autosave restore, Deployer for Git ad, Jet Woo Builder data update) — exactly the kind of DOM mutations that defeat any `defaultValue`-based dirty check.

### Reality
JE's redirect after save is a `wp_redirect()` call to a freshly-constructed URL. It would take JE plugin patching to make it preserve arbitrary query params from the original request. We can't depend on that.

Instead, **communicate cross-frame state through `sessionStorage`** — which DOES survive same-origin redirects (it's keyed to the origin + tab, not the URL). The pattern that works:

1. Before submitting JE's form, set `sessionStorage.jedb_close_modal_on_load = '1'`. Do this via an in-form-submit-listener (fires for native Save button) AND directly in the Done click handler (belt-and-suspenders for `form.submit()` fallback).
2. JE saves, redirects, iframe reloads to the chrome-LESS URL.
3. Our `admin_head` hook still fires (we're still on a `jet-cct-*` page) — restructured into **two tiers**:
   - **Tier 1 (always-injected on jet-cct-* pages):** read sessionStorage, if flag set AND in an iframe, hide the page (`html.style.visibility='hidden'`) immediately, then on DOMContentLoaded check for `.notice-error` validation failures. If clean, postMessage parent to close; if error, un-hide and postMessage `jedb:cct-save-error` so the parent un-shows its "Saving…" overlay.
   - **Tier 2 (only when ?jedb_chrome=stripped):** the chrome-strip CSS + Done/Cancel top bar + form-submit interceptor. Sets the sessionStorage flag and postMessages parent `jedb:cct-save-starting`.
4. On the parent (product edit page), listen for `jedb:cct-save-starting` → show overlay; `jedb:cct-save-error` → hide overlay; `jedb:cct-modal-close` → close modal + reload.

The page-flash mitigation matters: when our Tier 1 script runs in `<head>`, `document.documentElement` exists but body isn't yet parsed. Setting `documentElement.style.visibility = 'hidden'` before body paints means no flash of WP chrome between iframe nav-start and our postMessage. The parent receives the message ~20-50ms later, closes the iframe (`src='about:blank'`), the user never sees the chromed JE page.

### Affected code
- `assets/js/bridge-meta-box.js`:
  - Removed `isProductFormDirty()` (was triggering false positives on autosave-mutated forms).
  - Removed the confirm dialog branch.
  - Click handler always stamps `_jedb_reopen_cct_bridge` + clicks `#publish`.
  - Auto-launch path on `jedbMetaBoxBootstrap.reopenBridgeId` opens modal directly via `openModal()` instead of re-triggering the click handler.
  - Added `showSavingOverlay()` / `hideSavingOverlay()` helpers + `.jedb-cct-modal-saving` overlay element with a WP-native `.spinner.is-active`.
  - postMessage listener extended with three message types: `jedb:cct-save-starting`, `jedb:cct-save-error`, `jedb:cct-modal-close`.
- `includes/admin/class-woo-product-meta-box.php`:
  - `maybe_inject_cct_chrome_strip()` restructured into Tier 1 / Tier 2. Tier 1 runs always on jet-cct-* pages (subject to capability check), Tier 2 only when `jedb_chrome=stripped`.
  - Tier 2's form-submit interceptor (attached to `form[action*="jet-cct-save-item"], form[action*="cct_action=save-item"]`) sets the sessionStorage flag and postMessages parent `jedb:cct-save-starting`.
  - Done button calls `submitBtn.click()` (which fires submit events) after setting the flag + notifying parent.
- `assets/css/bridge-meta-box.css`:
  - `.jedb-cct-modal-saving` overlay styles (white 85%-opaque background, centered card with spinner, z-index above iframe).

### Fix shipped in
v0.6.0-alpha.7.

### Prevention
1. **For any sequence "iframe loads page X → user action → page reloads to Y", you cannot assume query params from page X survive to Y — the host plugin / WP / browser controls the redirect URL construction.** Test the actual redirect destination by clicking through the flow once and looking at the network panel.
2. **`sessionStorage` is the right cross-page-load state channel for same-origin iframe contexts.** It survives redirects within the origin, is keyed per tab (so multiple modal instances don't collide), and is automatically scoped to the iframe's origin.
3. **`postMessage` is the right cross-frame channel for same-origin parent ↔ iframe communication.** Always check `event.origin === window.location.origin` to authenticate. Define a small message vocabulary (`type` + optional payload) and document it.
4. **Hide the iframe body BEFORE body paint when about to close.** Set `document.documentElement.style.visibility = 'hidden'` in the early `<head>`-time script (sessionStorage is available immediately, before DOM). Don't wait for `DOMContentLoaded` — the body paints before that fires. The visibility-hide is what makes the modal-close feel instant; without it, there's a 50-200ms flash of the post-save page.
5. **Dirty-checking forms by comparing `value` to `defaultValue` is unreliable in real WordPress environments.** WP autosave, heartbeat, 3rd-party plugins all mutate inputs post-load. If you need a dirty check, use the official `wp.autosave` API (the block editor uses this) — but in many cases, the right answer is to skip the dirty check entirely and just always save.
6. **When auto-launching a UI flow after a save, open it DIRECTLY — don't re-trigger the click handler.** The click handler is for user-initiated flows that may need confirmations / save-first / etc. The auto-launch path is "we already did the save, just open the thing." Use a separate code path that bypasses prerequisites.
7. **L-027 was about delegating editing to JE's UI via iframe; L-029 is about making that iframe actually work end-to-end through JE's save lifecycle.** The pair forms the full architecture: L-027 says "delegate the rendering," L-029 says "and here's how to recover state and close cleanly when the host redirects."

---

## L-030: JE's `$db->get_item()` returns stale rows on the next request after a write — use direct SQL when freshness matters.

**Discovered:** 2026-05-16 (Phase 4 Day 2 follow-up / version 0.6.0-alpha.8)
**Severity:** Medium
**Category:** API drift / Defensive coding

### Context
After L-029 fixed the modal close flow, the user reported one remaining issue: *"the surfaced fields don't update on the product page, but the standard push/pulled fields (rendered in woocommerce product page) do change."*

The flow in question:

1. Editor opens the modal, edits `mosaic_name` to "New Whale", clicks Done.
2. JE form submits → JE server saves the CCT row.
3. `updated-item/{slug}` hook fires → our forward push engine reads `$source_adapter->get($source_id)` (which calls `$db->get_item($source_id)`), applies mappings, writes "New Whale" to the linked product's post meta / post title.
4. JE redirects iframe, Tier 1 postMessages parent, parent reloads.
5. New GET request — meta box renders → calls `$source_adapter->get($source_id)` → builds previews.

**Observed:** product fields (WC native) show "New Whale" after the reload. Bridge meta box surfaced previews still show "Old Whale".

### Wrong
Initially assumed forward push and meta box render would both see the same persisted CCT row, since they both call `$source_adapter->get($source_id)` against the same DB and the save committed in step 2 before either read.

### Evidence
- User staging: WC product title updates correctly; surfaced preview of `mosaic_name` does NOT update on the same page reload.
- Both reads go through identical adapter code (`Target_CCT::get()` which calls `$db->get_item()`).
- The difference: forward push runs in the SAME PHP request as the JE save (step 3); meta box render runs in a SEPARATE later request (step 5).

### Reality
JE's `$db->get_item()` consults a per-class instance cache AND in some persistent-cache configurations also `wp_cache_get()` for the row. When `$db->update()` runs in step 2, **JE doesn't guarantee `wp_cache_delete()` for the row's cache entry** — same asymmetric-API surface that L-022 documents for hooks not firing on adapter writes. Net effect:

- **Step 3 (same request as save):** `$db->get_item()` is called by our forward push. The save in step 2 may have populated or refreshed JE's per-class instance cache as a side effect of its internal flow, so step 3 happens to read the FRESH row. Target gets the new value. User sees the update on the WC product fields.
- **Step 5 (separate request, separate PHP process):** `$db->get_item()` is called by our meta box. The per-class instance cache is empty (new request, new instance), so it falls through to underlying storage. If a persistent object cache (Redis / Memcached) is enabled AND was populated by a prior read with the pre-save row AND wasn't invalidated by step 2's write, this returns the STALE row. Surfaced preview shows old value.

In other words: the freshness of `$db->get_item()` depends on which cache layers are hot, whether the prior request invalidated them, and the exact cache backend. Not predictable, not reliable.

### Affected code
- `includes/targets/class-target-cct.php` — `get()` method (calls `$db->get_item()`).
- `includes/admin/class-woo-product-meta-box.php` — `resolve_for_post()` (uses adapter's `get()` for the source read that feeds surfaced previews).
- `includes/flatten/class-flattener.php` — `apply_bridge()` source read (mostly fine because the typical caller is the same-request save hook, but admin-triggered "Sync now" syncs and bulk syncs are separate requests and could be affected).
- `includes/flatten/class-reverse-flattener.php` — `apply_bridge()` source read (CCT row read during pull diff calculation; staleness here could cause unnecessary double-writes).

### Fix shipped in
v0.6.0-alpha.8.

Added `Target_CCT::get_fresh( $id )` method that goes directly to the underlying `wp_jet_cct_{slug}` table via `$wpdb->get_row()`, skipping `$db->get_item()`, `$db->query()`, and every layer of caching they touch. Wired four call sites to prefer `get_fresh()` when the adapter exposes it:

1. **`JEDB_Woo_Product_Meta_Box::resolve_for_post()`** — the highest-priority user-visible path. Surfaced previews now always show the freshest CCT row after a modal save.
2. **`JEDB_Flattener::apply_bridge()`** — source read used by the forward push. Hook-triggered case is already fresh in practice; this hardens against admin-triggered syncs hitting stale persistent caches.
3. **`JEDB_Reverse_Flattener::apply_bridge()`** — source-side (CCT) read used during pull diff. Prevents unnecessary CCT writes when target post values match the freshly-saved CCT row but a cached read would have reported divergence.
4. Non-CCT adapters (CPT, Woo product, Woo variation) don't expose `get_fresh()` — they fall through to the standard `get()` because their post-meta-based reads use WP's standard object cache which DOES invalidate properly on `update_post_meta()` / `wp_update_post()`.

### Prevention
1. **When reading a host plugin's data after a write the host plugin performed, don't assume the host's read API invalidates its own caches.** Verify the host's behavior by writing a tracing test (one request writes, the next reads and logs both the API-via value and a direct-SQL value side-by-side; compare). Or just always use direct SQL for the use cases where freshness is critical.
2. **Per-class instance caches + persistent object caches stack.** Same code path can hit different layers in different requests depending on cache state. Don't reason about cache freshness without identifying every layer involved.
3. **Provide adapter-level escape hatches.** Each `JEDB_Data_Target` implementation should know whether its underlying storage has freshness gotchas and expose a `get_fresh()` accordingly. Callers that care should `method_exists()`-check and prefer the fresh path.
4. **L-022 and L-030 are siblings.** L-022 is "host writes don't fire host hooks consistently"; L-030 is "host reads don't see host writes consistently." Both come from the same root: host plugins built around their own internal APIs as the primary interface, with low-level `$wpdb` operations as the actual storage, and gaps between the two when developers assume parity. The mitigation pattern in both cases is the same: when symmetry matters, go direct to the underlying storage and bypass the host's wrapper.
5. **For our adapter API: any method whose name implies a value read (`get`, `exists`, `count`, `list_ids`) should be EITHER guaranteed-fresh or have a companion `*_fresh()` variant.** Going forward, all new adapters get audited for this asymmetry at design time, not in production.

---

## L-031: WP meta box label is set at `add_meta_box()` registration — register one box per bridge, not one umbrella box that loops bridges internally.

**Discovered:** 2026-05-16 (Phase 4 Day 2 final form / version 0.6.0-alpha.9)
**Severity:** Medium
**Category:** API knowledge / Information architecture

### Context
The alpha.4 → alpha.8 Bridge meta box registered a single WP meta box (`add_meta_box('jedb_bridge_meta_box', 'JE Data Bridge', ...)`) on `product` and `product_variation` post types. The render callback then looped ALL bridges whose `target_target` matched the post type and rendered one inner panel per resolved bridge, with custom-CSS chrome (custom `<h3>` panel title, pills, borders, sub-headers, etc.) to visually separate them.

This was acceptable when there was only one bridge in practice, but the BBHQ install has two bridges targeting `posts::product` (Mosaics→Product and Available Sets→Product), and the umbrella architecture produced UX friction.

### Wrong (alpha.4-8)
Three compounding issues:

1. **One outer gray WP header for all bridges.** The WP `add_meta_box()` second arg ("JE Data Bridge") was hardcoded. Editors who renamed a bridge's `label` or set `meta_box.title` saw the inner `<h3>` change, but the outer WP gray bar stayed "JE Data Bridge" — they reported this as a bug (correctly: the surface they intended to customize was unreachable).
2. **Per-bridge collapse / position not possible.** WP's screen-options + drag/drop machinery operates at the `add_meta_box()` ID granularity. With one umbrella box, editors can't collapse a single bridge's panel, can't drag one to the side column while keeping another in the main column, can't selectively hide via screen options.
3. **Custom panel chrome to compensate.** The inner-loop architecture forced us to invent visual separation between bridges using custom CSS (`.jedb-bridge-panel` with borders + `.jedb-bridge-panel-title` `<h3>` headers + status pills). This drifted from native WP styling and ended up looking foreign on the product edit screen next to WC's own meta boxes.

### Evidence
- User staging report 2026-05-16: *"I have changed the name of the box on in the flatten tab, but its not changing on the box header in the product page."* — they had set `meta_box.title = "Moasics Data surface"` and expected the WP gray bar to update.
- Same report: *"theres alot of admin data in this box on the product page, but i want the option to hide everything but the button and the surfaced fields. OH and it should have more of a native wordpress look than what we designed."* — the custom chrome compensating for the umbrella architecture had become visual noise.
- Future-proofing concern from the same report about multi-CCT-per-product: when two CCTs are linked to the same product, the umbrella box stacks two custom-styled panels with arbitrary visual separation. Two clearly-separate native WP boxes would be far more legible.

### Reality
`add_meta_box()` is meant to be called multiple times — once per logical container that should appear as a distinct collapsible / movable WP meta box. The right granularity for our use case is **one WP meta box per bridge**:

```php
foreach ( $bridges as $bridge ) {
    $meta_box_cfg = $bridge['config']['meta_box'] ?? array();
    if ( isset( $meta_box_cfg['enabled'] ) && ! $meta_box_cfg['enabled'] ) {
        continue;
    }
    $title    = ! empty( $meta_box_cfg['title'] ) ? $meta_box_cfg['title'] : $this->bridge_display_label( $bridge );
    $position = $meta_box_cfg['position'] ?? 'normal';
    add_meta_box(
        'jedb_bridge_meta_box_' . (int) $bridge['id'],
        $title,
        function ( $post ) use ( $bridge ) { $this->render_meta_box_for_bridge( $post, $bridge ); },
        $pt,
        $position,
        'default'
    );
}
```

Benefits:

1. **Native WP header from `meta_box.title` / `label`.** Editor changes the title in the Flatten tab → updates immediately on next render. No more "title bug."
2. **Native WP collapse / drag / screen-options.** Each bridge box gets its own UI state. Editors can collapse Mosaics, expand Available Sets, drag one to the side column, hide one via Screen Options.
3. **Custom chrome becomes unnecessary.** The WP meta box itself IS the container with title, border, collapsible chevron, and screen-options entry. Inner template can drop the `<h3>` + pills + custom panel chrome and use a native `<table class="form-table">` for content — exactly like the WC "Categories" or "Tags" boxes look.
4. **Future-compatible with the `meta_box.position` field already in schema** (since alpha.3). User sets `"position": "side"` → bridge renders in the sidebar. `"normal"` → main column. `"advanced"` → below main. Honored at registration time per bridge.

The "umbrella with inner loop" model was a legacy of the early Phase 4 design that assumed one bridge per post type. The moment a second bridge exists, the model frays. Better to assume N bridges from day one and use WP's primitive (one `add_meta_box()` call per logical unit) for the granularity that matches.

### Bonus: opt-in compact mode (`meta_box.show_advanced`)
A new boolean flag on the meta_box block (default `false`) controls whether the panel renders only the surfaced field previews + the "Save & edit" button (clean native look — the "tags/categories meta box" feel the user asked for) OR also surfaces per-product overrides + recent sync log + Sync now / Unlink action buttons inside a collapsed `<details>` "Advanced Details" section at the bottom.

Default `false` = minimal. Editors who want the admin diagnostics flip a checkbox in the Flatten admin tab to opt in. Existing alpha.4-8 bridges automatically default to `false` via `wp_parse_args()` in `merge_with_defaults()` — they "lose" the verbose surface but gain a cleaner look until the editor decides they want the diagnostics back.

### Affected code
- `includes/admin/class-woo-product-meta-box.php` — `register_meta_boxes()` rewritten to loop bridges per post type, one `add_meta_box()` per enabled bridge. `render_meta_box()` deleted; replaced by `render_meta_box_for_bridge( $post, $bridge )` for the per-bridge render. `render_linked_panel()` now passes `$show_advanced` into template scope.
- `includes/flatten/class-flatten-config-manager.php` — `default_meta_box()` extended with `show_advanced => false`. `default_config_json()` `meta_box` block extended likewise. `merge_with_defaults()` already deep-merges via `wp_parse_args()` so existing configs inherit the new default automatically.
- `templates/admin/meta-box-bridge.php` — rewritten. Drops `<h3>` panel title, drops status pill, drops `.jedb-bridge-panel-meta` block. Uses `<table class="form-table">` for surfaced field rows. Wraps the alpha.4-8 diagnostics + override controls + actions in a `<details>` collapsible gated on `$show_advanced`.
- `templates/admin/meta-box-bridge-unlinked.php` — rewritten. Drops `<h3>` panel title and status pill. Plain `<p class="description">` for the "not linked" message + CCT search picker.
- `assets/css/bridge-meta-box.css` — reduced from ~500 lines to ~270. Drops `.jedb-bridge-panel-title`, `.jedb-bridge-panel-meta`, `.jedb-bridge-panel-status`, `.jedb-pill-*`, `.jedb-surfaced-row` chrome, `.jedb-surfaced-group` `<fieldset>` border. Keeps the read-only preview helpers + modal overlay + Advanced Details section tweaks.
- `includes/helpers/field-preview.php` — non-image attachments collapse to a plain "Has attachment" label (image previews still render thumbnails per user preference).
- `templates/admin/tab-flatten.php` — new "Advanced Details" checkbox row in the "Meta box settings" section.
- `assets/js/flatten-admin.js` — `buildConfig()` writes `meta_box.show_advanced` from the checkbox; the `change` listener includes the new input name.

### Fix shipped in
v0.6.0-alpha.9.

### Prevention
1. **`add_meta_box()` registration is the right granularity for "logical unit the editor interacts with separately."** If you're tempted to render N bridges/panels/items inside ONE meta box with custom visual separation, ask: would the editor benefit from independent collapse / drag / screen-options control per item? Almost always yes — register N meta boxes.
2. **Hardcoded labels in `add_meta_box()` are a UX trap.** The label appears in the gray bar, screen options menu, dashboard widget list, and admin search results. If your data has a label, USE that label. Pull from the source data at registration time; the WP filter pipeline expects this.
3. **Custom chrome that compensates for the wrong WP primitive is a code smell.** When we ended up writing `.jedb-bridge-panel { background; border; border-radius; padding; }` to make N panels inside one meta box look like separate WP boxes, that was a sign the WP primitive (one meta box per logical unit) was being misused. Native WP meta boxes already provide the chrome — register more of them instead of styling more.
4. **`wp_parse_args()` on read is the cleanest forward-compat strategy for adding new config keys.** Existing configs that lack the new key receive the default automatically; no migration code, no version-gated branches, no schema version bumps for minor additions.
5. **Default new visibility flags to the more conservative value (typically `false`).** Editors who used the previous behavior and miss the verbose surface flip ONE checkbox per bridge to bring it back. Editors who never knew the verbose surface existed get a clean default they likely prefer. This is much better than defaulting to `true` and forcing every editor to discover + flip a "hide stuff" checkbox.
6. **L-031 and L-029 / L-027 / L-028 form the Phase 4 Day 2 lesson cluster.** Each addresses a different layer of the same workflow (modal flow, nested-form HTML, WP-chrome flash, cache freshness, meta-box granularity). The collective lesson: when wrapping a host plugin's UI in your own, every WP/host primitive that touches your wrapper needs verification against actual behavior — not against assumed behavior. The hosts are bigger than they look.

---

## L-032: When the host plugin has a rich native UI for a complex feature, iframe-bridge to it. Don't reimplement declaratively. L-027 applies bidirectionally.

**Discovered:** 2026-05-17 (Phase 4b retirement / version 0.6.0-alpha.14 — pre-implementation review)
**Severity:** High
**Category:** Architecture / Premature declarative abstraction

### Context
Phase 4b (alpha.13) shipped a `JEDB_Variation_Reconciler` engine that consumed a declarative `variations[]` block on the flatten config schema: each entry described one WooCommerce variation to manage with a `show_when` DSL deciding when it should exist, plus `price_field` / `downloads[]` / `attributes` declaring how to populate it from CCT data. The reconciler walked these entries on every push, created / updated / soft-deleted variations to match. The BBHQ "Has Instructions PDF" use case worked end-to-end. Staging tests passed.

### Wrong
The mental model assumed declarative reconciliation was clearer + more powerful than delegating to WC's native variations UI. In practice the configuration surface for `variations[]` was significantly more convoluted than the actual editorial decision being modeled, AND covered only a small subset of what WC's variations UI exposes natively.

User flagged this within hours of staging alpha.13: *"Im realising this work is kind of redundant right... because if theres multiple variations the sync becomes quite convoluted. Im almost wondering if we do the same thing for woo commerce where added an Iframe into the metabox... but this time we do it backwards into jet engine CCT's where in the bridge we add an option like has variables which can be clicked after initial save and loads an Iframe of the product data and auto selects the product type to variable — this way the complexity of variables gets handled by woocommerce itself."*

The user's instinct was right. The bridge type (alpha.1/alpha.2 / L-026) reckoning had the same shape: a config-driven layer we built to model editor intent turned out to be both more complex AND less expressive than just delegating to the host plugin's UI.

### Evidence
The pain points scale steeply with variation complexity:

| Bridge has... | alpha.13 effort (declarative) | Iframe-flip effort (delegate) |
|---|---|---|
| 1 simple variation | 1 form row × 7 fields | 1 button click → WC's native UI |
| 3-5 variations with different fields | 5 form rows × 7 fields = 35 fields to manage | Same: 1 button click |
| Per-variation custom image | Not supported — would need `image_field` in schema | WC's native UI handles natively |
| Per-variation stock management | Not supported — would need 4 stock-related fields | WC handles natively |
| Per-variation shipping class | Not supported — would need `shipping_class_field` | WC handles natively |
| Per-variation menu_order | Not supported — would need `menu_order` field | WC handles natively |
| Variation attribute taxonomy auto-creation | Required pre-configuration in WC anyway | WC's UI walks editors through it |

The gap between "what `variations[]` exposes" and "what WC supports per variation" is large and grows over time as WC adds features. Closing it means schema bloat. Not closing it means alpha.13 is forever an awkward subset of WC variation capabilities.

### Reality
The L-027 pattern works in both directions:

| Direction | Pattern | Outcome |
|---|---|---|
| Edit CCT data from WC product page (L-027) | iframe to JE's CCT edit page, chrome-strip the admin | Every JE field type renders correctly because JE renders them. Zero per-type renderer code in our plugin. |
| Edit WC variations from CCT edit screen (L-032) | iframe to WC's product edit page, chrome-strip the admin | Every WC variation field works correctly because WC renders them. Zero `variations[]` reconciliation code in our plugin. |

The architectural pattern is symmetric. Once L-027 was in place, the cost of NOT mirroring it for WC variations was a 480-line reconciler + a declarative schema block + a Flatten admin tab section + JS row builder + a meta box diagnostic — all of which becomes obsolete the moment the iframe-flip lands.

### Affected code (alpha.14 will remove)
- `includes/flatten/class-variation-reconciler.php` (~480 lines).
- `JEDB_Variation_Reconciler::instance()` registration in `JEDB_Plugin::load_core()`.
- The reconciler invocation in `JEDB_Flattener::apply_bridge()` + the `variations_changed` / `variations_only` / Path 3 success branch logic added in alpha.13.
- `default_variation()` factory + `variations[]` key on `default_config_json()` in `JEDB_Flatten_Config_Manager`.
- `merge_with_defaults()` `variations[]` deep-merge code.
- Variations section in `templates/admin/tab-flatten.php`.
- Variation row builder (`makeVariationRow`, `readVariationsFromDom`, `renderVariations`, etc.) in `assets/js/flatten-admin.js`.
- "Variations managed by this bridge" subsection in `templates/admin/meta-box-bridge.php` + the `$variations_status` data plumbing in `JEDB_Woo_Product_Meta_Box::render_linked_panel()`.
- Variation status pill CSS in `assets/css/bridge-meta-box.css`.

### Affected code (alpha.14 will retain, marked deprecated in docblocks)
- `JEDB_Target_Woo_Variation::find_managed_variation()` (~50 lines)
- `JEDB_Target_Woo_Variation::create_for_bridge()` (~30 lines)
- `JEDB_Target_Woo_Variation::META_VARIATION_SLUG` / `META_VARIATION_BRIDGE` constants

These are general-purpose Woo-variation utilities. They don't reference reconciler-specific logic. Defensive surface for any future automation hook (e.g. an optional "fix orphaned variations" admin button) that wants to find/create bridge-managed variations. Docblocks are updated to make their deprecated status explicit and reference BUILD-PLAN §4.7 + this lesson.

### Replacement (alpha.14 will ship)
**Per-bridge `cct_screen.wc_variations` panel** on the JE CCT edit screen:

```json
"cct_screen": {
  "wc_variations": {
    "enabled": false,
    "title": "WooCommerce Variations",
    "auto_force_variable_type": false
  }
}
```

When `enabled=true` AND the current CCT row has a linked WC product, a new panel renders below the JE save button on the CCT edit page. The panel contains:
- The configured `title` as a heading
- A short helper text: "After initial save you can add variations to this post."
- A button **"Open variations editor →"** that opens the linked WC product's edit page in a chrome-stripped modal iframe (Phase A ships without the chrome strip; Phase B adds it)

Modal mechanics REUSE the L-027/L-029 infrastructure entirely — same overlay, same Done/Cancel top bar, same sessionStorage close-on-save, same postMessage protocol. The only difference is the iframe URL (WC product edit instead of JE CCT edit).

When `auto_force_variable_type=true` (admin-opt-in per D3): the iframe's chrome-strip script auto-triggers `jQuery('#product-type').val('variable').trigger('change')` on load so the editor doesn't have to manually flip the product type dropdown. Off by default — admin enables per bridge.

The Flatten admin tab's "Enable WooCommerce Variations" section is hidden when `target_target !== 'posts::product'` (per D6 — irrelevant for non-product targets).

Decisions locked before implementation (D1–D6 / 2026-05-17 discussion):
- **D1: R3 — contextual replacement of `jedb-relations-block`.** The existing relation picker on CCT edit screens stays when the CCT row is unlinked (one-step workflow for editors creating rows from the CCT side preserved); the new variations panel takes its space once a link exists.
- **D2: `cct_screen.wc_variations` namespace** for the new config block. Forward-extensible for future CCT-screen panels.
- **D3: per-bridge admin opt-in for "auto-force variable product type"** instead of unconditionally auto-forcing. The code path is built but only fires when the admin explicitly enables it per bridge.
- **D4: do NOT auto-jump to the Variations sub-tab inside Product Data.** Editor may need to configure attributes first; forcing the tab steals control.
- **D5: silently hide the variations panel button when the CCT edit page is loaded inside an iframe context** (e.g. when accessed through the L-027 CCT-edit modal from a WC product page). Prevents nested-iframe chaos.
- **D6: hide the "Enable WooCommerce Variations" checkbox in the Flatten admin tab when `target_target !== 'posts::product'`** since the feature only applies to Woo products.

### Prevention
1. **Ask "could we iframe to the host's UI for this?" BEFORE building a declarative reconciliation engine.** If the host plugin (WC, JE, Yoast, etc.) has a complete, polished UI for the feature, that's usually the answer. Declarative reconciliation is appropriate when (a) the data shape is small and stable, (b) host UI is poor or inaccessible, (c) automation has clear behavioral semantics with low policy ambiguity. None of these held for WC variations.
2. **Bidirectional symmetry is a design smell in the GOOD direction.** Once L-027 worked for editing CCT from the WC side, the symmetric question "what about editing WC from the CCT side?" should have surfaced before alpha.13 instead of after. Going forward: when introducing an iframe-bridge pattern, immediately ask whether the symmetric counterpart belongs in the same release.
3. **Declarative DSLs for "should this exist?" decisions are a slippery slope.** The `show_when` mini-DSL handled the BBHQ case, but extending it for new patterns (stock-dependent variations, time-windowed variations, etc.) means more DSL operators. Eventually the DSL becomes a programming language. The escape hatch — Phase 5b custom snippets — exists for a reason; declarative DSLs in config should stay simple and let snippets handle complex policy.
4. **Premature reconciliation engines compound L-026.** L-026 documented retiring the bridge type template layer (alpha.1-alpha.2) for the same reason: a layer that modeled editor intent but turned out to be more complex than the underlying primitive. alpha.13's reconciler is its sibling. Whenever a feature requires "configure declaratively in our UI, we'll translate to the host's API," step back and ask whether the host's UI can be exposed directly instead.
5. **Code retained as deprecated should reference both BUILD-PLAN and the lesson.** When code stays in the repo as defensive surface (like `find_managed_variation`), the docblock must reference the architecture doc that explains current usage (BUILD-PLAN §4.7) AND the lesson that explains why it's no longer wired (this lesson). Future AI / human readers grepping for "find_managed_variation" should immediately understand it's intentionally orphaned, not forgotten.

### Cross-references
- **L-026** ("Premature template-layer abstraction") — same architectural pattern, same fix family (delete the abstraction, expose the primitive directly). The bridge type layer was retired in alpha.3 for the same reasons alpha.13's `variations[]` is being retired in alpha.14.
- **L-027** ("Don't rebuild every JE field type — delegate editing to JE itself via a chrome-stripped modal iframe") — the direct architectural parent of L-032. L-032 is the symmetric mirror: don't rebuild WC variation management — delegate to WC itself via the same chrome-stripped modal pattern.
- **L-031** ("WP meta box label is set at `add_meta_box()` registration — register one box per bridge, not one umbrella box") — different specific lesson but same underlying principle: use the host platform's primitives at the right granularity, don't paper over them with abstractions.

---
