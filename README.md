# JetEngine Data Bridge CC

> A WordPress plugin that bridges JetEngine CCTs / CPTs / Relations and WooCommerce products with bidirectional, loop-safe sync, relation pre-attachment, field flattening, and a sandboxed custom-snippet transformer system.

**Status:** Phase 0 (scaffold) — installable, activates clean, status screen confirms tables and folders. No sync logic shipped yet.

**Author:** Legwork Media · GPL v2 or later
**Min versions:** WordPress 6.0 · PHP 7.4 · JetEngine 3.3.1

---

## What this plugin does (or will, by end of roadmap)

This plugin consolidates three earlier bespoke plugins (Jet Engine Relation Injector, PAC Vehicle Data Manager, and patterns from JFB WC Quotes Advanced) into a single portable codebase. End state:

- **Relation pre-attachment** on CCT edit screens (you can pick a related parent before the CCT row is saved).
- **PULL/PUSH field flattening** between related records, so derived fields stay in sync without editor effort.
- **Field locker** — fields whose value is sourced from another record render greyed-out with a "source" tooltip.
- **WooCommerce product bridge** — a CCT row and a Woo product (or a specific variation) can be linked 1:1, edited from either side, and kept in sync via HPOS-safe writes (`WC_Product->save()`).
- **Variation reconciliation** — a bridge type can declare variations with `show_when` rules so toggles like "Has Instructions PDF" automatically materialize the right Woo variation.
- **Custom Code Snippets** — admins with the right capability can write small PHP transformers in a CodeMirror editor; snippets live in `uploads/jedb-snippets/` (protected by `.htaccess`), are syntax-checked on save, and are wrapped in a try/catch sandbox so a fatal in user code can't kill a save.

See [`BUILD-PLAN.md`](./BUILD-PLAN.md) for the full architecture, file-level migration map, locked decisions log, and phased roadmap (~11 working days end-to-end).

---

## Phase 0 — what's here right now

```
je-data-bridge-cc/
├── je-data-bridge-cc.php             ← plugin bootstrap, constants, dependency check
├── uninstall.php                     ← drops tables, removes options
├── includes/
│   ├── class-plugin.php              ← singleton, schema upgrade dispatcher
│   ├── class-config-db.php           ← creates the 4 custom tables via dbDelta
│   ├── snippets/
│   │   └── class-snippet-installer.php  ← creates uploads/jedb-snippets/ + .htaccess + index.php
│   ├── admin/
│   │   └── class-admin-shell.php     ← top-level menu + tab router
│   └── helpers/
│       └── debug.php                 ← jedb_log() with file rotation
├── templates/admin/
│   ├── shell.php                     ← outer page with tabs nav
│   └── tab-hello.php                 ← Phase 0 status screen
├── assets/css/admin.css
├── BUILD-PLAN.md
├── README.md
├── readme.txt
├── CHANGELOG.md
└── LICENSE
```

### Custom tables created on activation

| Table | Purpose |
|---|---|
| `wp_jedb_relation_configs` | Per-CCT relation pre-attachment rules (Phase 2). |
| `wp_jedb_flatten_configs`  | PULL/PUSH flatten rules (Phase 3). |
| `wp_jedb_sync_log`         | Append-only audit trail of every PUSH/PULL (used from Phase 3 onward, viewable in Debug tab). |
| `wp_jedb_snippets`         | Registry of Custom Code Snippets (Phase 5b). |

### Options created

- `jedb_settings` — global toggles (debug log, custom snippets, default sync direction).
- `jedb_bridge_types` — JSON array of bridge-type definitions (the source of truth for the Bridge Type select on Woo product edit screens).
- `jedb_meta_whitelist` — per-target meta-key allowlists.
- `jedb_db_version` — schema version, drives in-place upgrades.

---

## Installation (development)

1. Copy the entire plugin folder to your WP install at `wp-content/plugins/je-data-bridge-cc/` (rename the dev folder to remove spaces).
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Visit **JE Data Bridge** in the admin sidebar. The Status tab should show every row green.
4. If anything is red, deactivate and reactivate to re-run the installer; if still red, check the Debug tab once it ships.

### Building a release zip (later)

For now this is dev-only. When the plugin reaches a release-worthy phase a `bin/build.sh` will produce a clean zip.

---

## Roadmap

See [`BUILD-PLAN.md`](./BUILD-PLAN.md) §7 for the eight-phase plan and exit criteria.

| Phase | Scope | Status |
|---|---|---|
| 0  | Skeleton, tables, snippet folder, status screen | **✅ Complete** |
| 1  | Discovery + Targets (CCT, CPT, Woo Product, Woo Variation) | Pending |
| 2  | Relation Injector port | Pending |
| 3  | Flattener port (PULL/PUSH + Field Locker + Bulk Sync + transformers) | Pending |
| 4  | Woo product target + Bridge meta box + Bridges admin tab | Pending |
| 4b | Variation bridging + reconciliation engine + `show_when` mini-DSL | Pending |
| 5  | Settings API + debug log viewer + utilities export/import | Pending |
| 5b | Custom Code Snippets subsystem | Pending |
| 6  | Setup tab + presets (Brick Builder HQ preset) | Pending |
| 7  | Hardening (caps, nonces, REST auth, i18n, security pass) | Pending |

---

## License

GPL v2 or later. See [`LICENSE`](./LICENSE).

## Author

Legwork Media · [legworkmedia.ca](https://legworkmedia.ca)
