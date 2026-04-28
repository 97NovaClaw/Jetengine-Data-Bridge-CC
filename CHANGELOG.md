# Changelog

All notable changes to this plugin are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
