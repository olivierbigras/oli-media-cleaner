# Oli Media Cleaner

[![Version](https://img.shields.io/badge/version-2.0.0-blue)](https://github.com/olivierbigras/oli-media-cleaner/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-green)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-orange)](https://www.gnu.org/licenses/gpl-2.0.html)

Find unused media, move them to a safe trash, restore anytime. Local only: no ads, no tracking, nothing sent out.

## How it works

1. **Scan** (Media > Media Cleaner). Five visible steps, resumable, nothing is changed.
2. **Review** the list: search, filters, sort, CSV export, "Keep" for the files to protect.
3. **Trash** the files you do not need. Each file is re-checked live just before it moves, then its files are moved out of the public uploads path into a quarantine (see below).
4. **Restore** from the Trash tab at any time: the files go back to the same paths, byte for byte.
5. **Empty the trash** (or delete one file permanently) after a confirmation. Only files in the trash can be deleted permanently; the server refuses anything else.

## The trash is a real quarantine

- **Location.** `wp-content/uploads/olimc-trash/<random token>/<attachment ID>/files/<original relative path>`, plus `manifest.json` in the media folder. One trash per site on multisite (each site's own uploads folder).
- **What moves.** Original, `-scaled`, `original_image`, every generated size, `_wp_attachment_backup_sizes`, the `sources` files WordPress 6.x lists in the metadata, and the `.webp`/`.avif` copies found next to them. A file that another media entry references stays where it is (`stay` in the manifest).
- **Manifest.** Original relative path, size and MD5 of each moved file, the previous post status, kept in the `_olimc_trash_map` post meta and in `manifest.json`.
- **Hidden.** While trashed the attachment has the internal post status `olimc-trash`: no Media Library, front-end or REST list query for `inherit` returns it (`/wp/v2/media` does not list it; `GET /wp/v2/media/<ID>` still answers a user who can edit that media, with its title and original URL, never the folder token or a quarantine path), and WordPress never purges it (`EMPTY_TRASH_DAYS` and `wp_scheduled_delete` do not apply). `_olimc_trashed` holds the timestamp.
- **Protection.** Empty `index.php` in every folder, `.htaccess` that denies access (Apache), random 128-bit folder name stored in the `olimc_trash_token` option (nginx ignores `.htaccess`). The Trash tab previews images through `admin-post.php?action=olimc_thumb` (capability + nonce, real `Content-Type`, `nosniff`); no quarantine URL is ever written in a page.
- **Recommended server rule.** Nginx ignores `.htaccess`, so block the folder in the server configuration: `location ^~ /wp-content/uploads/olimc-trash/ { deny all; return 403; }`. On a multisite (each site has its own uploads folder) use `location ~ ^/wp-content/uploads/(sites/[0-9]+/)?olimc-trash/ { deny all; return 403; }`. On Apache the plugin already writes an `.htaccess` with `Require all denied` in `wp-content/uploads/olimc-trash/`; in the server configuration the same directive is only valid inside a container (or in a `.htaccess` placed in that folder):

  ```apache
  <Directory "/path/to/wp-content/uploads/olimc-trash">
      Require all denied
  </Directory>
  ```

- **Restore.** Checks first (file present, size and MD5 equal, nothing at the destination), then moves with a no-clobber link; any failure puts the already moved files back. A file at the destination gives the code `exists` and nothing is overwritten.
- **Trash.** All or nothing per media: if one move fails (or the `olimc_quarantine_allow_move` filter refuses it), the files already moved go back and the media keeps its status. An unwritable uploads folder is refused before anything moves.
- **Empty.** `wp_delete_attachment()` with the existing guards (kept, shared, live usage re-check); the quarantine folder of the media is then erased. A file found at an original path is never unlinked.
- **Security (2.0.0).** Executable names (`.php`, `.phtml`, `.phar`, `.pht`, `.php5`, `.shtml`, `.cgi`, `.htaccess`, `.user.ini`, dotfiles…) are stored as `<name with dots replaced>.olimc` (manifest field `q`), restored under their exact name. `realpath` confinement: a move or a restore never leaves uploads (nor the quarantine folder); symlinks stay where they are. Rollbacks never overwrite (a file that cannot go back stays, with its manifest). Move, restore and purge share one per-media lock; a stale lock (300 s) is taken with a compare-and-swap. Auto-empty skips a media after 3 failures (`_olimc_autofail`). One nonce per AJAX action. Journal in the `olimc_audit_log` option (user ID, action, up to 25 IDs, time; 200 lines). Stored values are read with `unserialize( …, [ 'allowed_classes' => false ] )`. `Tools > Export` skips `_olimc_trash_map`, `_olimc_trashed`, `_olimc_prev_status`, `_olimc_trashing` and `_olimc_autofail`.
- **Other options.** Other options count by value only, not by array key (lists and transients); the live check does not read them. Widgets, theme settings and custom fields also count by numeric array key, in the scan and in the live check.
- **Folder outside the web root.** Filter `olimc_quarantine_base_dir` (absolute path, folder named `olimc-trash`, no `..`; otherwise ignored). Change it while the trash is empty.
- **Token rotation.** `wp olimc rotate-token` (`OLIMC_Quarantine::rotate_token()`): renames the token folder, rewrites each map and `manifest.json`, resumable; refused while a media is being processed. No screen. While an interrupted rotation is pending (option `olimc_trash_rotating`), trashing, restoring and deleting are refused (`olimc_rotating`) and the plugin screen shows a notice; running the command again merges the old folder into the new one without overwriting anything (a conflict is reported and left in place).
- **Uninstall.** Restores every trashed media first. One that cannot be restored is shown again; its files stay in `uploads/olimc-trash/` with the manifest and a README. Unchecking "Delete the plugin data" keeps your settings and your list of kept files; the trash is restored either way. A media that could not be restored keeps its files in `uploads/olimc-trash/` with its manifest and a `README.txt`; the plugin no longer manages that folder: on a server that ignores `.htaccess` it stays reachable by URL to anyone who knows the folder name, so block it in the server configuration or delete it by SFTP once you have recovered what you need.

## Hooks and cron

Actions: `olimc_media_trashed( $id, $urls )` and `olimc_media_restored( $id, $urls )`, fired after a media is moved out of or back to its public place; `$urls` are the public URLs of the files that moved (purge a CDN or cache there; a CDN may keep serving a copy until it is purged).

Filters: `olimc_capability`, `olimc_protected_paths`, `olimc_must_keep_attachment`, `olimc_known_gaps`, `olimc_scan_step_budget`, `olimc_trash_backend` (replace the backend), `olimc_quarantine_allow_move`. WP-Cron events: `olimc_scheduled_cleanup` (optional scan, analysis only) and `olimc_auto_empty_trash` (daily, only when automatic emptying is on).

## What the scan reads

Post content and excerpts, featured images, site logo and icon, custom fields (ACF including repeaters, flexible content, groups, clones and options pages, plus any post, term, user or option metadata), WooCommerce galleries and variations, Elementor data, widgets, term thumbnails and descriptions, theme files and CSS.

A scan is complete or it is nothing: until the last step finishes, no file is marked as unused. The scheduled scan (optional) only refreshes the list.

## Requirements

WordPress 5.8+ and PHP 7.4+ (tested up to WordPress 7.1 and PHP 8.4).

## Install

Download the ZIP from the [releases](https://github.com/olivierbigras/oli-media-cleaner/releases), or copy the folder to `wp-content/plugins/oli-media-cleaner/`, then activate it.

## Privacy

The plugin makes no remote request and loads no external resource. It stores its data in your database: a few options (including the random name of the trash folder), post meta keys (`_olimc_protected`, `_olimc_trashed`, `_olimc_trash_map`, `_olimc_prev_status`) and one table (`{prefix}olimc_results`). The media in the trash are always restored on uninstall (never deleted; one that cannot be restored keeps its files in the trash folder). The rest is removed unless you turn the "Delete the plugin data" option off.

## Development

```
plugin/
  oli-media-cleaner.php      bootstrap
  uninstall.php
  includes/
    class-scanner.php        collects used media (content, theme, widgets, meta)
    class-acf-meta-scanner.php  resumable scan engine (phases, sub-steps, progress)
    class-scan.php           scan lifecycle: start, step, cancel, stale detection, schedule
    class-results.php        results table (published only when a scan completes)
    class-trash.php          trash rules + backend interface
    class-quarantine.php     real quarantine: move, manifest, exact restore, guards, preview
    class-library.php        kept / trash queries, path patterns
    class-list-table.php     WP_List_Table for the three lists
    class-screen.php         screen, tabs, settings form, CSV export, diagnostics
    class-ajax.php           routes (capability + nonce on every route)
    class-settings.php       Settings API
    class-admin.php          hooks, protection, guards, upgrade
  assets/css/admin.css       under 6 KB
  assets/js/admin.js         plain JavaScript (wp.i18n, wp.a11y), no build step
  languages/                 .pot, fr_CA, fr_FR (+ JSON for the script)
  tests/
```

### Tests

```
bash tests/run-all.sh                       # syntax, unit tests, source rules
CLIENT_NAMES_FILE=/path/to/list bash tests/run-all.sh   # also checks a private list of words that must not appear
wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-2.0.php    # on a test site
```

The integration tests use a real database. Run them on a throw-away site only. The uninstall test (`integration-wp-uninstall.php`) removes the plugin data.

### Coding standard

`phpcs.xml.dist` (WordPress rules, WordPress 5.8 minimum, PHP 7.4+). Run `phpcs` from the plugin folder.

### Translations

`languages/oli-media-cleaner.pot` is generated with `wp i18n make-pot`. French (Canada) and French (France) are shipped. Script strings use `wp.i18n` and the JSON file named after the script path.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. Author: [Olivier Bigras](https://olivierbigras.com). Source: [github.com/olivierbigras/oli-media-cleaner](https://github.com/olivierbigras/oli-media-cleaner).
