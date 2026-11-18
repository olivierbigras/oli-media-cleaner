# Changelog

All notable changes to Oli Media Cleaner will be documented in this file.

## [2.0.0] - 2026-11-17

### Added
- New screen on native WordPress components: tabs To review / Kept / Trash / Settings, `WP_List_Table` (screen options, sortable columns, mobile view), inline notices.
- Five-step scan (preparing, ACF, other metadata, content/theme/widgets, sorting) with progress bar, counter, elapsed time, pause/resume/cancel. The former "auxiliary" phase is split into one step per source so the bar never stalls.
- Results stored in `{prefix}olimc_results` (keyed by scan), published only when the scan is complete.
- Stale list detection (plugin set or versions, theme, media count/latest change, or older than 14 days) and a live re-check of each file before it is moved (unknown counts as used).
- Real trash (quarantine): trashing moves the original, `-scaled`, original image, every size, backup sizes and WebP/AVIF copies to `uploads/olimc-trash/<random token>/<ID>/files/…` with a manifest (original path, size, MD5), hides the media from the Media Library (internal status `olimc-trash`, never purged by WordPress), and restoring puts the exact bytes back at the exact paths. Nothing is overwritten (a file at the old path stops that restore), a failed move puts the media's files back (also when a request is cut short; an interrupted move or restore is finished by the next try), an unwritable uploads folder is refused, files shared with another media stay in place, each site of a multisite has its own folder. The folder is protected by an empty `index.php`, an `.htaccess` and a random name; previews in the Trash tab go through an admin-only endpoint.
- Trash backend interface (`OLIMC_Trash_Backend`), default backend `OLIMC_Trash_Quarantine_Backend`; the former WordPress trash is still read and restored. Filters `olimc_trash_backend` and `olimc_quarantine_allow_move`.
- Dialogs with `<dialog>`/`showModal()`, required checkbox for large selections ("all results", 100+ files or 30%+ of the library) and for emptying the trash.
- "Select all results" across pages, CSV export with formula-injection protection and UTF-8 BOM, "Test patterns", diagnostics report, per-user screen option for the page size.
- `wp.i18n` + `wp_set_script_translations`, `wp.a11y.speak` announcements, ARIA roles and labels, 44 px targets, reduced-motion.
- Tests: WordPress integration tests (`tests/integration-wp-2.0.php`, `tests/integration-wp-uninstall.php`).

### Changed
- "Whitelist" is now "Keep": one notion (media meta `_olimc_protected` and path patterns). The `olimc_whitelist` option is migrated, then deleted.
- Permanent deletion only from the trash, checked on the server (capability per media, nonce, media must be in the trash; otherwise HTTP 400 and nothing is deleted).
- Requires WordPress 5.8 (was 6.2): `%i` and `wp_filesize()` are provided by small compatibility helpers.
- The scheduled scan only analyses. It never moves or deletes anything.
- The scan now reads the same sources as the live check: a media whose ID is only an array key in a widget option, a theme mod (any theme) or a custom field counts as used and is no longer listed in "To review" (it was refused at trash time before). Scan time is unchanged on a 20,000-media library.
- A failed restore says why (for example the uploads folder is not writable). The "Empty the trash" dialog marks a kept media as "kept: restored, not deleted". The message "This media is being processed." is translatable.
- Honest offline texts: the files are no longer served by the site, but a CDN or cache may keep a copy until it is purged (trash notice, confirmation dialog, readme). New actions `olimc_media_trashed( $id, $urls )` and `olimc_media_restored( $id, $urls )` to purge a CDN or cache; the readme explains the purge after a sensitive trash.
- Security review of the trash: executable file names are stored under a neutral name and restored exactly; confinement to the uploads and trash folders with `realpath`; a rollback never overwrites a file; one lock for move, restore and permanent deletion, taken atomically when stale; the automatic emptying skips a media that keeps failing; one nonce per action and a bounded journal (`olimc_audit_log`); stored values are unserialized without classes; the trash state is left out of Tools > Export; `wp olimc rotate-token` replaces the secret folder name; filter `olimc_quarantine_base_dir` to keep the trash outside the web root; the note left after an uninstall explains how to protect or remove what remains.
- An interrupted token rotation closes the trash (trash, restore, delete, empty) with a notice on the plugin screen until `wp olimc rotate-token` is run again, which merges the two folders without overwriting. The folder given to `olimc_quarantine_base_dir` must be absolute for the system (a `C:/…` path is ignored on Linux). The quarantine tests refuse to run without `OLIMC_TEST_OK=1`.
- Other options count by value only, not by array key (lists and transients); the live check does not read them. Widgets, theme settings and custom fields also count by numeric array key.
- A trash that is refused because another action is working on the same media says so ("This media is being processed.").
- Documentation: recommended server rule (with the multisite nginx example and the Apache container) (nginx `location` block, Apache `Require all denied`) to block direct access to `uploads/olimc-trash/`.
- Uninstall always restores trashed media (their files go back to their paths), whether or not "Delete the plugin data" is checked, then (if checked) removes options, post/user meta, the results table and scheduled events. A media that cannot be restored is shown again and keeps its files in `uploads/olimc-trash/` with its manifest and a README.

### Removed
- Donation notice and its inline script, "Donate" action link, footer line (the donation link stays in the readme and the plugin meta line).
- `window.confirm()` prompts (kept only as a fallback for browsers without `<dialog>`), toasts, home-made pagination.
- Translations other than French (Canada) and French (France); both rewritten.
- Screenshots and banners from the repository (they belong to the WordPress.org assets).
- Development dependency on jsdom.

## [1.5.27]
Prepared on a branch (never released); folded into 2.0.0.
- Translation files regenerated to fix double-encoded UTF-8 (superseded: 2.0.0 ships only French, rebuilt).
- `phpcs:ignore` annotations for Plugin Check on direct queries (the 2.0.0 queries carry their own).
- Removed the `load_plugin_textdomain()` call (WordPress loads translations itself); kept removed in 2.0.0.

## [1.5.26] - 2026-10-23

### Fixed
- Removed `esc_sql()` from SQL fragments and ID lists that are then passed to `$wpdb->prepare()`. After WordPress 4.8.3, `esc_sql()` escapes `%` placeholders and breaks those queries
- Empty Trash exclusion list uses `%d` placeholders instead of interpolating `esc_sql()` output

### Changed
- Screenshots recaptured on a local demo WordPress site with generic `sample-photo-*.jpg` files (no real media library data)

## [1.5.25] - 2026-10-21

### Fixed
- Plugin Check 2.1.0 errors: prepared SQL identifiers (`%i` / `esc_sql` whitelist), missing translators comments, `parse_url()` → `wp_parse_url()`, and CSV export via `WP_Filesystem`

### Changed
- Requires at least WordPress 6.2 (`wp_filesize`, `$wpdb->prepare()` `%i` placeholders)
- Admin AJAX reads go through a visible nonce check plus `wp_unslash()` / sanitization

### Added
- `.distignore` and `.gitattributes` `export-ignore` so the distribution ZIP excludes tests, GitHub workflows, PHPCS/PHPUnit/Composer/npm files, and internal markdown

## [1.5.24] - 2026-10-21

### Changed
- Protected paths and glob patterns are site-configurable and empty by default (`olimc_protected_paths` option, `olimc_protected_paths` filter)
- Client-specific sample paths, domains, and filenames were removed from source, tests, and translation examples

### Added
- On update, previously applied protected paths are copied into the site option so existing sites keep the same protection. This plugin does not ship a built-in client list
- One-time filter `olimc_legacy_protected_paths` can supply that previous list during the upgrade

## [1.5.23] - 2026-10-20

### Fixed
- Deleting or trashing an attachment that is still in use is refused on every path (Media Library, WP-CLI, plugin single/bulk/Trash All/empty trash)
- Live usage is checked with targeted SQL (`OLIMC_Scanner::is_attachment_in_use_now()`), not the last scan's `used_ids`
- Plugin actions report how many used media items were kept (EN/FR). Whitelisted files stay protected

### Added
- Filter `olimc_must_keep_attachment`
- When no scan exists (`olimc_scan_date`) and the live check errors or times out, the file is kept

## [1.5.22] - 2026-10-20

### Changed
- GitHub links updated to github.com/olivierbigras (account renamed from bigrat95). No functional change.

## [1.5.21] - 2026-10-14

### Added
- Protection system for externally referenced files to prevent deletion of files still in use outside WordPress (e.g. receipts, external systems, direct URLs)
- Global protected paths setting in the settings screen — protect files by path, URL, or glob pattern (one per line, normalized to uploads-relative paths)
- Per-attachment "Protect from deletion" checkbox in the attachment edit screen and media modal (stored as `_olimc_protected` meta)
- Server-side protection enforcement for all deletion paths (AJAX single/bulk, cron, background, Media Library)
- Admin messages when protection prevents deletion
- Full internationalization support (French and English strings)

### Changed
- Protected files are never listed as unused by the scan
- Thumbnails and derivative files (.webp, .avif) are automatically protected when their original is protected

### Technical
- New option `olimc_protected_paths` (empty by default, multiline textarea)
- New meta key `_olimc_protected` (boolean flag per attachment)
- Protection checks integrated into `must_keep_attachment_files()` and scan logic
- Glob pattern matching with * wildcards for flexible path protection
- All sanitization, escaping, nonces, and capabilities follow WordPress.org guidelines
- No inline scripts in the UI

## [1.5.20] - Previous release
- FIX: A file that another attachment still references is kept on every deletion path
- FIX: The plugin's own options, transients, and meta are ignored while scanning
- FIX: Edited-image backups in _wp_attachment_backup_sizes count as shared files

## Earlier versions
See readme.txt for full history.
