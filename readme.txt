=== Oli Media Cleaner ===
Contributors: bigrat95
Donate link: https://www.paypal.com/donate/?hosted_button_id=29SAPKFAHMTNW
Tags: unused media, media library, cleanup, trash, images
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find unused media, move them to a safe trash, restore anytime. Local only: no ads, no tracking, nothing sent out.

== Description ==

**Oli Media Cleaner** finds the files of your media library that your site no longer uses, and lets you clean them up in five calm steps:

1. **Scan.** Nothing is changed during a scan. It runs in small steps, shows a progress bar, and can be paused and resumed.
2. **Review.** Look at the list (search, filters, sort, CSV export). Keep any file you want to protect.
3. **Trash.** Move the files you do not need to the plugin's trash. Your site is checked again, file by file, just before each move.
4. **Restore.** Changed your mind, or an image is missing on your site? Restore it from the trash in one click.
5. **Empty the trash.** Only files in the trash can be deleted permanently, and the plugin asks for confirmation first.

= What is read during a scan =

* Post and page content (all post types, blocks and classic editor), excerpts
* Featured images, site logo and site icon
* Custom fields: ACF (image, file, gallery, repeater, flexible content, group, clone, options pages) and other post, term, user and option metadata
* WooCommerce product galleries and variations, Elementor data
* Widgets, term thumbnails and term descriptions
* Theme files and CSS (hard-coded image references)

= What is not read =

* Files used only by a plugin that stores its data outside the WordPress database, in a format the scan cannot understand. When the plugin detects such a plugin, it tells you on the screen.
* Anything on another site, a CDN or a remote storage.

Back up your site before cleaning. The trash keeps your files, but it is not a backup.

= Built to be quiet and safe =

* No ads, no upsell, no rating request, no tracking, no remote call: nothing leaves your site.
* Native WordPress screens (list table, tabs, notices), keyboard friendly, readable with a screen reader, usable on a phone.
* The scheduled scan only refreshes the list. It never moves or deletes anything.
* A file used by two media entries is never deleted while the other one needs it.
* Fully translated: French (Canada and France).

== Installation ==

1. Upload the `oli-media-cleaner` folder to `/wp-content/plugins/`, or install it from the **Plugins** screen.
2. Activate the plugin.
3. Open **Media > Media Cleaner** and click **Start scan**.

== Frequently Asked Questions ==

= Is it safe? =

Nothing is changed during a scan. Files you trash are set aside, not erased, and you can restore them. Make a backup before cleaning anyway: the trash is not a backup.

= Why is an image missing on my site after I used the trash? =

The file was used by something the scan could not read. Open the **Trash** tab and restore it. Then tell us what plugin or theme uses it so we can read it in a future version.

= Which sources does the scan read? =

Content, featured images, custom fields (including ACF), WooCommerce, Elementor, widgets, term data, theme files and CSS. The list is on the screen, under the **Start scan** button.

= What happens when I uninstall the plugin? =

Media in the trash are restored to the media library first, then the plugin data is removed. No media file is ever deleted by the uninstall. You can keep the data by unchecking the option in **Settings**.

= Does the plugin send my data anywhere? =

No. It makes no remote request and loads no external resource.

= Does the scheduled scan delete anything? =

No. It only refreshes the list. Moving files to the trash is always your action.

= Does it create a database table? =

Yes, one: `{prefix}olimc_results`, which holds the list of the last scan so that large libraries stay fast. It is removed on uninstall.

== Screenshots ==

1. The list of unused media, with filters and bulk actions
2. A scan in progress, with its five steps
3. The confirmation dialog before moving files to the trash
4. The trash tab: restore, or delete permanently
5. Settings: kept files, automatic emptying, scheduled scan
6. The same list on a phone

== Changelog ==

= 2.0.0 =
* NEW: Redesigned screen on native WordPress components: tabs "To review", "Kept", "Trash" and "Settings", WordPress list table with screen options, notices instead of pop-ups.
* NEW: The scan runs in five visible steps with a progress bar, a counter and an elapsed time. It can be paused, resumed or cancelled. Nothing is marked as unused until it is complete.
* NEW: Single trash with "Undo". Grouped actions and permanent deletion ask for confirmation in an accessible dialog. A checkbox is required for large selections and for emptying the trash.
* NEW: "Select all results" across pages, and results stored in a dedicated table for large libraries.
* NEW: The list is flagged as out of date after a change of plugin, theme or media, or after 14 days; every file is checked again live before it is moved.
* NEW: Settings: kept paths with a "Test patterns" button, automatic emptying (never, 30 or 90 days), scheduled scan (analysis only), careful mode, data removal on uninstall, diagnostics report.
* NEW: Keyboard and screen reader support throughout, 44 px targets, mobile layout, reduced motion.
* CHANGE: "Whitelist" becomes "Keep" (one concept). Your previous list is migrated. Existing results are dropped: run a new scan.
* CHANGE: Permanent deletion is only possible from the trash and is checked on the server (capability, nonce, and the media must be in the trash).
* CHANGE: Requires WordPress 5.8 (was 6.2).
* REMOVED: Donation notice and its inline script, "Donate" action link, footer line, browser pop-ups (confirm) and toasts, home-made pagination.
* REMOVED: All translations except French (Canada and France), which are rewritten for the new screen.

= 1.5.27 =
* Folded into 2.0.0 (not released separately): translation encoding fix, Plugin Check annotations, removal of load_plugin_textdomain().

= 1.5.26 =
* FIX: Stop wrapping SQL fragments or ID lists with esc_sql() before $wpdb->prepare() (WP 4.8.3+ placeholder escaping broke those queries)
* FIX: Empty Trash binds excluded IDs with %d placeholders
* CHANGE: Screenshots recaptured on a neutral demo site with generic sample-photo filenames

= 1.5.25 =
* FIX: Plugin Check 2.1.0 reports 0 errors (prepared SQL identifiers, translators comments, wp_parse_url, WP_Filesystem CSV export)
* CHANGE: Requires at least WordPress 6.2 so $wpdb->prepare() can use %i for table and column names
* NEW: .distignore and git archive export-ignore exclude development files from the distribution ZIP

= 1.5.24 =
* CHANGE: Protected paths and glob patterns are empty by default and configured per site (settings field or `olimc_protected_paths` filter)
* NEW: Upgrade copies a site's previously applied protected paths into the `olimc_protected_paths` option so existing protection is kept. No built-in client list is shipped
* CHANGE: Sample paths and filenames in settings and tests no longer refer to a specific site

= 1.5.23 =
* FIX: Refuse to trash or permanently delete media that is still in use (featured image, post content, WooCommerce gallery, ACF fields, and other live references), including Media Library, WP-CLI, and plugin actions
* FIX: Plugin trash/delete actions count refusals and show how many used media items were kept
* NEW: `olimc_must_keep_attachment` filter and targeted `OLIMC_Scanner::is_attachment_in_use_now()` live check (no full scan)

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
* FIX: ACF image fields (including the default array return format on custom post types such as event) are detected with indexed meta_key lookups from acf_get_field_groups() / acf_get_fields(), instead of one LIKE query per stored key
* FIX: Load ACF local JSON and PHP field groups before scanning, so cron does not miss field groups that are not registered yet
* FIX: Nested repeater, flexible content, group and clone fields (for example content_blocks_4_image_carousel_images) are resolved from the ACF reference meta, then loaded in batches
* FIX: If ACF is inactive or field groups cannot be resolved, a chunked raw-meta scan is used. An incomplete or failed scan marks nothing as unused
* CHANGE: Scans resume across AJAX and cron requests. Scheduled runs still only scan — they never trash or delete media

= 1.5.16 =
* FIX: Reliable ACF attachment detection from raw post meta (including `_field` references), even when ACF is inactive — fixes false “unused” results for CPT fields such as event `image` (ID, array, and URL return formats)
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

= 2.0.0 =
New interface and a new trash. Your list of kept media is carried over. Run a new scan after updating: the previous results are dropped. Requires WordPress 5.8+.

= 1.5.26 =
Fixes SQL queries that combined esc_sql() with $wpdb->prepare().
