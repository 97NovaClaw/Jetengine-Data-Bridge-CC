=== JetEngine Data Bridge CC ===
Contributors: legworkmedia
Tags: jetengine, woocommerce, cct, relations, sync, bridge, data
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridges JetEngine CCTs / CPTs / Relations and WooCommerce products with bidirectional, loop-safe sync, relation pre-attachment, field flattening, and a sandboxed custom-snippet transformer system.

== Description ==

JetEngine Data Bridge CC consolidates relation management, field flattening, and a WooCommerce product bridge into a single, portable plugin you can drop on any JetEngine + WooCommerce site and configure entirely from the admin UI.

Highlights:

* **Relation pre-attachment** on CCT edit screens — pick a related parent before the row is saved.
* **PULL / PUSH field flattening** between related records.
* **Field locker** — derived fields render greyed-out with a "source" tooltip so editors don't accidentally overwrite them.
* **WooCommerce product bridge** — link a CCT row and a Woo product 1:1, with HPOS-safe writes through `WC_Product->save()`.
* **Variation reconciliation** — bridge types can declare variations with `show_when` rules.
* **Custom Code Snippets** — sandboxed PHP transformers editable from the admin, gated by capability and an opt-in toggle.

This is Phase 0 (scaffold) of an 11-day build. See BUILD-PLAN.md in the plugin folder for the full architecture and roadmap.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/je-data-bridge-cc/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Visit **JE Data Bridge** in the admin menu and verify the Status tab shows every row green.

Requires JetEngine 3.3.1 or higher. WooCommerce is recommended but not required (the plugin runs in CCT-only mode if WooCommerce is missing).

== Frequently Asked Questions ==

= Does this plugin write directly to WooCommerce database tables? =

No. All writes go through the `WC_Product` API and `$product->save()` to keep HPOS / `wc_product_meta_lookup` in sync.

= Is this safe with HPOS-enabled stores? =

Yes. The plugin only writes to product data, not order data, and uses Woo's typed setters for every product write. HPOS detection is exposed via `jedb_is_hpos_enabled()` for any future code paths that need it.

= Can I write my own transformers? =

Yes — once Phase 5b ships, admins with `manage_options` (and the global "Enable Custom PHP Snippets" toggle ON) can write small PHP transformers from the admin UI. Errors are caught and logged; a broken snippet returns the unmodified input rather than killing a save.

== Changelog ==

= 0.1.0 =
* Phase 0 scaffold: bootstrap, dependency check, four custom tables, snippet uploads folder with .htaccess + index.php protection, admin shell + status tab, debug-log helper.

== Upgrade Notice ==

= 0.1.0 =
Initial scaffold release. No production data is written yet.
