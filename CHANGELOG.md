# Changelog

All notable changes to Oli Media Cleaner will be documented in this file.

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
