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
3. **Trash.** Move the files you do not need to the plugin's trash. Your site is checked again, file by file, just before each move. The files really leave your site: they are moved into a protected folder, so an image your site still needs shows up as missing and you can see it.
4. **Restore.** Changed your mind, or an image is missing on your site? Restore it from the trash in one click. The files go back to the same paths, byte for byte. You can restore at any time: nothing in the trash is ever deleted by WordPress or by this plugin on its own.
5. **Empty the trash.** Only files in the trash can be deleted permanently, and the plugin asks for confirmation first. Emptying the trash is the only step that erases files.

= How the trash works =

* **Moved, not flagged.** Trashing a media moves its original file, the "-scaled" version, the original image, every generated size, the backup sizes and the `.webp` and `.avif` copies that exist on disk to `wp-content/uploads/olimc-trash/`. The media disappears from the Media Library while it is in the trash.
* **Exact restore.** A manifest keeps the original path, size and MD5 of each moved file. Restoring checks the files, puts them back at the same paths and shows the media again. If a file with the same name was created in the meantime, nothing is overwritten and you get a clear message.
* **All or nothing for each media.** If one file cannot be moved, the files already moved for that media are put back. If the uploads folder is not writable, the media is left as it is and you are told. If a request stops in the middle (time limit, memory, crash), the files are put back, or the next try finishes the job; no file is lost.
* **Shared files stay.** A file that another media entry also uses is left in place, and the plugin never deletes it while the other one needs it.
* **Protected folder.** The trash folder has an empty `index.php`, an `.htaccess` that denies access, and a random name that cannot be guessed. Nginx ignores `.htaccess`: there, the random name is the protection. The Trash tab shows previews through an admin-only address; the real location is never written in the page.
* **Empty = permanent.** Emptying the trash deletes the media and its files for good, after a last live check of your site.
* **Multisite.** Each site has its own trash folder, inside its own uploads folder.

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
* WordPress never purges the trash of this plugin (no 30-day countdown, `EMPTY_TRASH_DAYS` has no effect on it).
* A file used by two media entries is never deleted while the other one needs it.
* Fully translated: French (Canada and France).

== Installation ==

1. Upload the `oli-media-cleaner` folder to `/wp-content/plugins/`, or install it from the **Plugins** screen.
2. Activate the plugin.
3. Open **Media > Media Cleaner** and click **Start scan**.

== Frequently Asked Questions ==

= Is it safe? =

Nothing is changed during a scan. Files you trash are moved out of their public place into a protected folder, not erased, and you can restore them at any time. Make a backup before cleaning anyway: the trash is not a backup.

= Why is an image missing on my site after I used the trash? =

Trashed files are no longer served by your site, so a file your site still used shows up as missing. This is on purpose: you see the problem. The file was used by something the scan could not read. Open the **Trash** tab and restore it, and it is back at the same path. Then tell us what plugin or theme uses it so we can read it in a future version.

= A trashed file is still reachable on my site. Why? =

The plugin moves the file away, so your server stops serving it. A CDN, a page cache or a search engine may keep a copy of the old address until it is purged or expires. If the file is sensitive, purge your CDN or cache after trashing it. A developer can automate it: the plugin runs `do_action( 'olimc_media_trashed', $attachment_id, $urls )` after the move (`$urls` lists the public addresses of every file moved) and `do_action( 'olimc_media_restored', $attachment_id, $urls )` after a restore. Hook a purge call of your CDN or cache plugin on them. The plugin never contacts your CDN itself.

= Where are the trashed files? =

In `wp-content/uploads/olimc-trash/`, in a folder with a random name, then one folder per media (its ID). Each one holds the files with their original relative path and a `manifest.json` (original path, size, MD5). Do not edit that folder by hand: use the **Trash** tab.

= What stays after an uninstall? =

Trashed media are always restored first. One that cannot be restored (a file with the same name exists, folder not writable) is shown again in the Media Library and its files stay in `wp-content/uploads/olimc-trash/` with their manifest and a `README.txt`. The plugin no longer manages that folder. On a server that ignores `.htaccess` it stays reachable by URL to anyone who knows the secret folder name (the plugin forgets it when you delete its data): block the folder in the server configuration, or delete it by SFTP once you have recovered what you need.

= How do I block direct access to the trash folder? =

The plugin writes an empty `index.php` in every folder, an `.htaccess` that denies access, and uses a random folder name. Apache reads the `.htaccess`. Nginx does not, so on nginx (and on any server that ignores `.htaccess`) add a rule yourself, in the server configuration, then reload it:

`location ^~ /wp-content/uploads/olimc-trash/ { deny all; return 403; }`

On a multisite, each site has its own uploads folder (`wp-content/uploads/sites/<id>/`), so use this rule instead, which covers the main site and the others:

`location ~ ^/wp-content/uploads/(sites/[0-9]+/)?olimc-trash/ { deny all; return 403; }`

For a custom uploads location, use the path of your uploads folder followed by `/olimc-trash/`. On Apache the plugin already creates a `.htaccess` in `wp-content/uploads/olimc-trash/`. To set the rule in the server configuration instead, `Require all denied` is only valid inside a container, for example `<Directory "/path/to/wp-content/uploads/olimc-trash">`, or inside a `.htaccess` placed in that folder:

    <Directory "/path/to/wp-content/uploads/olimc-trash">
        Require all denied
    </Directory>

Hosts that manage the server for you can usually add the rule on request.

= How do the scan and the live check count numeric keys and other options? =

Other options count by value only, not by array key (lists and transients), in the scan and in the live check. Widgets, theme settings and custom fields also count by numeric array key, in the scan and in the live check.

= Can I keep the trash outside the web root? =

Yes. A developer can return an absolute path from the filter `olimc_quarantine_base_dir`, for example `add_filter( 'olimc_quarantine_base_dir', fn() => '/home/site/private/olimc-trash' );`. The folder must be named `olimc-trash`, must not contain `..`, and must be writable by the web server. Nothing then sits under `wp-content/uploads`, so no server rule is needed. Change it while the trash is empty: a media is looked up in the folder that is current when you restore it.

= How do I change the secret folder name of the trash? =

The random folder name is the protection on a server that ignores `.htaccess`. If a copy of your database, an export or a backup may have leaked, replace it: `wp olimc rotate-token` (add `--url=<site>` on a multisite). The folder is renamed and every trashed media is updated; if the command is interrupted, the trash stays closed (trash, restore and delete are refused, and the plugin screen says so) until you run it again; it then merges the old folder into the new one without overwriting anything, and removes from the old folder only the files the plugin wrote itself (any other file is reported and left in place). The automatic emptying waits meanwhile. There is no screen for it. The names of the trashed files are kept out of the WordPress export (Tools > Export).

= What security checks does the trash do? =

* A file whose name a server could run (`.php`, `.phtml`, `.phar`, `.pht`, `.php5`, `.shtml`, `.cgi`, `.htaccess`, `.user.ini`, any name starting with a dot, and the like) is stored under a neutral name (dots replaced, suffix `.olimc`). The manifest keeps the real name, and the restore puts back the exact name and bytes.
* A file is only moved or restored inside the uploads folder (symbolic links that lead out of it are refused) and inside the trash folder.
* A failed move puts the files back without ever overwriting a file that appeared in the meantime; if one cannot go back, it stays in the trash folder with its manifest.
* A permanent deletion, a restore and a move of the same media cannot run at the same time.
* The automatic emptying skips a media that failed three times, so it does not block the others.
* Every action has its own security token. A short journal (user ID, action, media IDs, date; the last 200 lines, in the `olimc_audit_log` option) records who trashed, restored or deleted; it is removed with the plugin data.
* Values read from your database are unserialized without instantiating any class.

= Can a restore overwrite something? =

No. If a file already exists at the old path (for example you uploaded a new image with the same name), the restore of that media stops, nothing is moved and nothing is overwritten. Rename or remove the new file, then restore again.

= What if the uploads folder is not writable? =

Trashing is refused with a message and nothing is changed. Fix the permissions of `wp-content/uploads`, then try again.

= Does WordPress delete the trash after 30 days? =

No. The plugin uses its own folder and its own hidden status, so WordPress never purges it. Files leave the trash only when you restore them or empty the trash. You can also choose to empty it automatically after 30 or 90 days in **Settings**.

= What happens if I deactivate the plugin? =

Trashed files stay in the trash folder and the media stay hidden. Activate the plugin again to restore them. If you delete the plugin, they are restored first (see below).

= What scheduled events does it use? =

Two, both optional and visible in WP-Cron: `olimc_scheduled_cleanup` runs a scan and only refreshes the list (nothing is moved or deleted), and `olimc_auto_empty_trash` runs once a day, only if you choose to empty the trash automatically, and permanently deletes media that have been in the trash longer than 30 or 90 days (kept media are restored instead). Both are removed when you deactivate or uninstall the plugin. If `DISABLE_WP_CRON` is set, trigger WP-Cron from your server.

= Which sources does the scan read? =

Content, featured images, custom fields (including ACF), WooCommerce, Elementor, widgets, term data, theme files and CSS. The list is on the screen, under the **Start scan** button.

= What happens when I uninstall the plugin? =

Media in the trash are always restored first (their files go back to their original paths), whether or not the option below is checked, on every site of a multisite. Then the plugin data is removed if the option is checked. No media file is ever deleted by the uninstall. A media that cannot be restored (a file with the same name exists, or the folder is not writable) is shown again in the Media Library and its files stay in `uploads/olimc-trash/` with their manifest and a README, so nothing is lost silently. Unchecking **Delete the plugin data when it is uninstalled** in **Settings** keeps your settings and your list of kept files; the trash is restored either way.

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
* NEW: Real trash. Files are moved out of their public place into `uploads/olimc-trash/` (original, scaled, original image, every size, backup sizes, WebP/AVIF copies), with a manifest for an exact restore. The media is hidden from the Media Library while it is in the trash, and WordPress never purges it. Nothing is overwritten on restore; a failed move puts the media's files back; shared files stay in place.
* NEW: Single trash with "Undo". Grouped actions and permanent deletion ask for confirmation in an accessible dialog. A checkbox is required for large selections and for emptying the trash.
* NEW: "Select all results" across pages, and results stored in a dedicated table for large libraries.
* NEW: The list is flagged as out of date after a change of plugin, theme or media, or after 14 days; every file is checked again live before it is moved.
* NEW: Settings: kept paths with a "Test patterns" button, automatic emptying (never, 30 or 90 days), scheduled scan (analysis only), careful mode, data removal on uninstall, diagnostics report.
* NEW: Keyboard and screen reader support throughout, 44 px targets, mobile layout, reduced motion.
* CHANGE: "Whitelist" becomes "Keep" (one concept). Your previous list is migrated. Existing results are dropped: run a new scan.
* FIX: The live check (before trashing, before emptying) now reads every source of the scan: custom fields, term meta, user meta and other options, with the scan's own rules. A stale list can no longer trash or delete a media that is used there. One pass per batch.
* CHANGE: A media referenced only by its numeric ID used as an array key (widgets, theme settings, custom fields) counts as used, in the scan as in the live check.
* CHANGE: Security hardening of the trash (executable names neutralised, confinement to uploads, no overwrite on rollback, one lock per media, one security token per action, a short journal, no class instantiated when reading stored data, trash state excluded from the export). New: `wp olimc rotate-token`, filter `olimc_quarantine_base_dir`.
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
