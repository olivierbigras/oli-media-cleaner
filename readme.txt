=== Oli Media Cleaner ===
Contributors: bigrat95
Donate link: https://www.paypal.com/donate/?hosted_button_id=29SAPKFAHMTNW
Tags: media, cleanup, unused images, media cleaner, disk space
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.22
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan and remove unused media files from your WordPress site to free up disk space.

== Description ==

**Oli Media Cleaner** scans your entire WordPress site to find media files that are no longer in use. It performs a deep analysis across multiple sources to accurately determine which files are safe to remove.

= What Gets Scanned =

* **Post & page content** — all post types, Gutenberg blocks, classic editor
* **Featured images** — all post types including WooCommerce product variations
* **Custom fields (post meta)** — any plugin or theme that stores attachment IDs or URLs
* **ACF (Advanced Custom Fields)** — image, file, gallery, repeater, flexible content, group, clone fields, and Options pages
* **WooCommerce** — product galleries, variation images
* **Elementor** — page builder widget data
* **Theme files** — PHP, CSS, JS templates scanned for hardcoded image references
* **CSS background images** — inline styles in post content
* **Widgets** — image, gallery, text, custom HTML widgets
* **Site identity** — site logo, site icon, theme mods
* **Serialized data** — deep scan of complex plugin data structures

= Features =

* **Batch scanning** — processes 50 attachments per batch to avoid timeouts
* **Whitelist** — protect files you want to keep even if unused
* **Bulk actions** — trash, whitelist, restore, or permanently delete multiple files at once
* **CSV export** — export the current view (respects search, type filter, and sort) for offline analysis in Excel, Numbers, or Google Sheets
* **Trash All** — one-click batch trash of all unused images with progress bar (handles thousands)
* **Scheduled auto-cleanup** — enable daily, twice daily, or weekly automatic scan and trash via WP-Cron
* **Progress bar** — real-time scanning progress
* **No external dependencies** — uses native WordPress admin styles, no Bootstrap or jQuery UI
* **Zero custom database tables** — stores data in WordPress options
* **Clean uninstall** — removes all plugin data on deletion

= How to Use =

1. Go to **Media Cleaner** under **Media** in the WordPress admin sidebar
2. Click **Scan for Unused Media**
3. Review the results in the **Unused** tab
4. **Whitelist** any files you want to keep
5. **Trash** files you don't need
6. Go to the **Trash** tab to permanently delete or restore files

== Installation ==

1. Upload the `oli-media-cleaner` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Media Cleaner** under **Media** in the admin sidebar
4. Click **Scan for Unused Media** to start

== Frequently Asked Questions ==

= Is it safe to delete unused media? =

The plugin moves files to WordPress trash first. You can review and restore them before permanently deleting. We recommend making a full backup before any bulk deletion.

= Does it work with ACF? =

Yes. The plugin uses the ACF API to discover all field groups and recursively scans image, file, gallery, repeater, flexible content, group, and clone fields — including ACF Options pages.

= Does it work with WooCommerce? =

Yes. Product featured images, gallery images, and variation images are all detected as "in use."

= Does it work with Elementor? =

Yes. The plugin scans Elementor's `_elementor_data` post meta for image references.

= Does it scan theme files? =

Yes. All PHP, CSS, and JS files in the active theme (and parent theme) are scanned for hardcoded references to `wp-content/uploads/`.

= What about images hardcoded in theme templates? =

Those are detected by the theme file scanner. It finds both URL paths and `wp_get_attachment_image()` function calls.

= Can I whitelist images? =

Yes. You can whitelist individual images or use bulk select to whitelist multiple at once. Whitelisted images are never flagged as unused.

= Which languages are supported? =

The plugin ships with complete translations for every locale listed on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/oli-media-cleaner/) (except joke locales such as Emoji and Pirate). Unique translations cover major languages including French, German, Spanish, Italian, Portuguese, Dutch, Japanese, Chinese, Korean, Russian, Polish, Arabic, Turkish, Hebrew, Hindi, Indonesian, Vietnamese, Thai, and many more, plus regional variants (Swiss German, Latin American Spanish, Brazilian vs European Portuguese, and others). Improvements can still be contributed on GlotPress.

= Does it create custom database tables? =

No. The plugin uses WordPress options only. Clean and lightweight.

== Screenshots ==

1. Main dashboard with stats cards and scan button
2. Unused media list with bulk actions
3. Whitelist tab
4. Scan sources information panel

== Changelog ==

= 1.5.22 =
* Maintenance: GitHub links updated to github.com/olivierbigras (account renamed from bigrat95). No functional change.

= 1.5.21 =
* NEW: Protection system for externally referenced files — prevent deletion of files still in use outside WordPress (e.g. receipts, external systems)
* NEW: Global protected paths setting — protect files by path, URL, or glob pattern (one per line in settings)
* NEW: Per-attachment protection checkbox — "Protect from deletion" in the attachment edit screen and media modal
* FIX: Protected files are never listed as unused by the scan and all deletion attempts (AJAX, bulk, cron, background) are refused server-side with clear admin messages
* CHANGE: Thumbnails and derivative files (.webp, .avif) are automatically protected when their original is protected

= 1.5.20 =
* FIX: A file that another attachment still references is kept on every deletion path. Trash, the 30-day empty, and the Media Library go through the same guard. Imagify is not allowed to delete the WebP, AVIF, or backup of a shared file
* FIX: The plugin's own options, transients, and meta are ignored while scanning, so a previous result list is not treated as usage
* FIX: Edited-image backups in _wp_attachment_backup_sizes count as shared files. The file index is read once per request

= 1.5.19 =
* FIX: Upload URLs inside HTML stop at the closing quote, tag, parenthesis, query string, or anchor, so a linked file such as a PDF in page content is kept
* FIX: An attachment that shares its file or a generated size with an attachment that is in use is marked used. Permanent delete never unlinks a file another attachment still references

= 1.5.18 =
* FIX: ACF meta keys that contain a hyphen (for example highlight-image) are included. Values are passed through $wpdb->prepare() instead of being dropped by a character whitelist
* FIX: Upload URL detection uses wp_upload_dir() (base URL, protocol-less URL, path, and directory, including multisite) so Bedrock sites under /app/uploads match the ACF URL return format
* FIX: When ACF is active, a chunked raw-meta pass still counts non-ACF references (page builders, SEO, serialized IDs, URLs in meta) as used
* CHANGE: AJAX and cron keep separate scan state, a lock, and a pending-results option so they cannot overwrite each other or rewrite the full unused list on every batch

= 1.5.17 =
* FIX: ACF image fields (including the default array return format on custom post types such as concert) are detected with indexed meta_key lookups from acf_get_field_groups() / acf_get_fields(), instead of one LIKE query per stored key
* FIX: Load ACF local JSON and PHP field groups before scanning, so cron does not miss field groups that are not registered yet
* FIX: Nested repeater, flexible content, group and clone fields (for example content_blocks_4_image_carousel_images) are resolved from the ACF reference meta, then loaded in batches
* FIX: If ACF is inactive or field groups cannot be resolved, a chunked raw-meta scan is used. An incomplete or failed scan marks nothing as unused
* CHANGE: Scans resume across AJAX and cron requests. Scheduled runs still only scan — they never trash or delete media

= 1.5.16 =
* FIX: Reliable ACF attachment detection from raw post meta (including `_field` references), even when ACF is inactive — fixes false “unused” results for CPT fields such as concert `image` (ID, array, and URL return formats)
* FIX: Gallery and repeater/flexible/clone sub-fields, options pages, term meta, and user meta are scanned with a generic fallback for any attachment ID stored in meta
* CHANGE: Scheduled auto-cleanup now runs a scan only; media are never trashed or deleted automatically (manual confirmation still required in the admin)
* CHANGE: Trash and delete confirmations show a filename preview; trash actions remind you that files go to Media Trash first

= 1.5.15 =
* NEW: CSV export includes a Status column (Unused / Whitelist / Trash) and exports all three categories in one file so you can filter/categorize in a spreadsheet. Search, type filter, and sort still apply.

= 1.5.14 =
* CHANGE: "Trash All Unused" is always visible on the Unused tab when there are unused items — no selection required

= 1.5.13 =
* CHANGE: Whitelist and Trash selection actions (Remove from Whitelist, Restore Selected, Delete Selected) only appear when items are selected
* NEW: "Remove All from Whitelist" always visible when the Whitelist tab has items
* CHANGE: "Restore All" and "Empty Trash" always visible when the Trash tab has items; "Delete Permanently" renamed to "Delete Selected"
* CHANGE: Empty Trash confirmation now warns that the action is irreversible and recommends a backup first

= 1.5.12 =
* NEW: Settings and Donate links on the Plugins screen (Settings opens Media > Media Cleaner)
* NEW: Optional support notice on the Media Cleaner screen (dismissible per user) and a small footer donation link
* NEW: Donate link on the WordPress.org plugin page (PayPal)

= 1.5.11 =
* CHANGE: The "per page" selection (20 / 50 / 100) is now remembered across page reloads and tab navigation, so the list and pagination keep showing your chosen page size instead of resetting to 20

= 1.5.10 =
* CHANGE: Removed the duplicate "Select All" checkbox from the toolbar — the standard checkbox in the table header column now handles select all

= 1.5.9 =
* NEW: Export to CSV — an "Export CSV" button exports the current list for analysis in a spreadsheet. The export honors the active tab, search, file-type filter, and sort order, and includes every matching row (not just the current page). Columns: ID, title, filename, URL, type, MIME type, size in bytes, human-readable size, and date

= 1.5.8 =
* CHANGE: The "Trash Selected" and "Whitelist Selected" buttons on the Unused tab now appear only when at least one item is selected, keeping the toolbar clean until there is something to act on

= 1.5.7 =
* NEW: "Restore All" button on the Trash tab — batch-restores every trashed file with a progress bar, no need to select items first (mirrors "Empty Trash")

= 1.5.6 =
* CHANGE: The "Trash All Unused" button now appears only when more than one item is selected, so it stays out of the way until you are working with a batch

= 1.5.5 =
* FIX: The file type filter is now populated immediately after a scan completes — no page refresh needed. The dropdown stays in sync with the latest scan results via AJAX
* NEW: Clearer scan feedback — the Scan button shows a spinner and the progress bar animates with an "Analyzing your site…" message during the initial analysis step, so it is obvious the scan is working before the percentage starts counting

= 1.5.4 =
* Confirmed compatibility with WordPress 7.1
* Updated "Tested up to" to 7.1 so the plugin no longer shows an "untested with your version of WordPress" warning

= 1.5.3 =
* Bundled translations for every WordPress.org locale (200+), including French, German, Spanish, Italian, Portuguese, Dutch, Japanese, Chinese, Korean, Russian, Arabic, and many more
* Regional variants (e.g. Swiss German, Latin American Spanish, Brazilian and European Portuguese)
* English (Canada) and English (UK) locale support
* All remaining admin JavaScript strings are now translatable
* Translation template (POT) for WordPress.org GlotPress

= 1.5.2 =
* FIX: Bulk action toolbar now updates immediately when switching tabs
* Restore Selected, Delete Permanently, and Empty Trash appear on the Trash tab without a page refresh
* Trash All Unused is hidden when viewing Whitelist or Trash

= 1.5.1 =
* FIX: Detect ACF gallery/image/file fields nested inside clone fields (e.g. flexible content → clone → gallery)
* Clone field groups and individual cloned fields are now resolved via the ACF API
* Nested clone-prefixed meta keys (e.g. content_blocks_4_image_carousel_images) are treated as used

= 1.5.0 =
* Renamed plugin from "Delete Unused Images" to "Oli Media Cleaner"
* New slug: oli-media-cleaner, new prefix: olimc_
* Added "Empty Trash" button — batch-deletes all trashed attachments with progress bar
* Added taxonomy image scanning: WooCommerce category thumbnails, term descriptions, attribute descriptions
* Added termmeta deep scan for images stored by third-party plugins
* Live tab count updates during Trash All and Empty Trash batch operations
* Fixed trash tab count not updating after single/bulk trash, delete, or restore actions

= 1.4.0 =
* Fixed all WordPress Plugin Check errors
* Proper output escaping (esc_html_e, esc_html__, esc_html)
* Translators comments for all placeholder strings
* Ordered placeholders (%1$d, %2$d) for multi-placeholder strings
* Fixed SQL preparation: inline $wpdb->prepare(), esc_like() for LIKE queries
* Dynamic file type filter (only shows detected extensions)
* Per-page selector (20, 50, 100)

= 1.3.0 =
* Clickable column headers to sort by Name, Size, Type, or Date (asc/desc)
* File type filter dropdown (JPG, PNG, GIF, WebP, SVG, PDF, MP4)
* Sort indicators (arrows) on active column
* Filters, sort, and search all reset when switching tabs

= 1.2.0 =
* Added search box to filter images by name, filename, type, or ID
* Search works across all tabs (Unused, Whitelist, Trash)
* Search clears automatically when switching tabs

= 1.1.0 =
* Added "Trash All Unused" button — batch-processes all unused images with progress bar
* Added scheduled auto-cleanup via WP-Cron (daily, twice daily, or weekly)
* Settings panel for enabling/disabling auto-cleanup and choosing frequency
* Native WordPress admin UI refinements (postbox, form-table, nav-tab-wrapper, widefat)
* Reduced custom CSS from 500+ lines to ~20 lines

= 1.0.0 =
* Initial release
* Deep scanning: post content, featured images, post meta, ACF fields, WooCommerce, Elementor, theme files, widgets, site identity
* Whitelist feature with bulk actions
* Trash and permanent delete with bulk actions
* Progress bar during scan
* "What was scanned?" info panel
* Native WordPress admin UI — no external dependencies

== Upgrade Notice ==

= 1.5.22 =
Maintenance release: GitHub links updated. No functional change.

= 1.5.21 =
Protection system for files referenced outside WordPress. Add paths/URLs/globs to settings or use per-file checkboxes to prevent deletion.

= 1.5.20 =
Shared files stay on disk even if an attachment is trashed or deleted outside this plugin, including by Imagify. A previous scan result is no longer treated as proof that a file is in use.

= 1.5.19 =
Fixes linked files missed inside HTML, and stops permanent delete from removing a file that another attachment still uses. Update before deleting anything the 1.5.18 scan flagged.

= 1.5.18 =
Fixes missed ACF fields whose names contain a hyphen, and upload URLs on Bedrock (/app/uploads). Non-ACF meta is scanned again. Update before deleting anything the 1.5.17 scan flagged.

= 1.5.17 =
Fixes ACF media detection on large sites (no per-key LIKE scans). Failed scans no longer flag media as unused.

= 1.5.16 =
Critical fix for ACF image/file usage detection; scheduled tasks no longer trash media automatically.

= 1.5.15 =
CSV export now includes a Status column and covers Unused, Whitelist, and Trash in one file.

= 1.5.14 =
"Trash All Unused" is now always available on the Unused tab when there are unused items.

= 1.5.13 =
Clearer bulk actions: selection-only buttons on Whitelist/Trash, Remove All from Whitelist, stronger Empty Trash warning.

= 1.5.12 =
Adds Settings and Donate links on the Plugins screen, plus an optional support notice in the Media Cleaner UI.

= 1.5.11 =
Your "per page" choice is now remembered, so pagination stays consistent after reloads instead of resetting to 20.

= 1.5.10 =
Removes the redundant second "Select All" checkbox; the table header checkbox selects all.

= 1.5.9 =
Adds a CSV export of the current (filtered) list for offline analysis in a spreadsheet.

= 1.5.8 =
"Trash Selected" and "Whitelist Selected" now appear only when at least one item is selected.

= 1.5.7 =
Adds a "Restore All" button to the Trash tab for one-click bulk restore of every trashed file.

= 1.5.6 =
The "Trash All Unused" button now only shows when more than one item is selected.

= 1.5.5 =
Fixes the file type filter being empty until a page refresh after scanning, and adds clearer "working" feedback while a scan starts up.

= 1.5.4 =
Confirms compatibility with WordPress 7.1 and removes the "untested with your version of WordPress" warning.

= 1.5.3 =
Adds bundled translations for 200+ WordPress.org locales, English (Canada/UK) support, and a complete translation template for GlotPress.

= 1.5.2 =
Fixes bulk action buttons not appearing when switching to the Trash or Whitelist tab.

= 1.5.1 =
Fixes false unused-media positives for ACF galleries stored inside clone fields.

= 1.5.0 =
Adds Empty Trash, taxonomy image scanning, and live tab count updates.

= 1.4.0 =
Fixes all WordPress Plugin Check errors for WP.org compliance.

= 1.3.0 =
Adds column sorting and file type filter.

= 1.2.0 =
Adds search functionality to filter images.

= 1.1.0 =
Adds Trash All button and scheduled auto-cleanup.

= 1.0.0 =
Initial release.
