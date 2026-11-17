<?php
defined('ABSPATH') || exit;

/**
 * Hooks, "Keep" rules (meta flag and path patterns), the guards that stop any
 * deletion path from erasing used or shared files, and the 1.x to 2.0 migration.
 * Screens live in OLIMC_Screen, routes in OLIMC_Ajax.
 */
class OLIMC_Admin {

    /**
     * Screen capability. Filter `olimc_capability` changes it.
     *
     * @return string
     */
    public static function capability() {
        $cap = apply_filters('olimc_capability', 'manage_options');
        return ( is_string($cap) && $cap !== '' ) ? $cap : 'manage_options';
    }

    public static function init() {
        add_action('init', [ __CLASS__, 'maybe_upgrade' ], 1);
        add_action('init', [ __CLASS__, 'load_textdomain' ]);
        add_filter('plugin_row_meta', [ __CLASS__, 'plugin_row_meta' ], 10, 2);

        // Keep checkbox on the media edit screen.
        add_filter('attachment_fields_to_edit', [ __CLASS__, 'add_protection_checkbox' ], 10, 2);
        add_filter('attachment_fields_to_save', [ __CLASS__, 'save_protection_checkbox' ], 10, 2);

        // Scheduled scan and optional automatic emptying of the trash.
        add_action('olimc_scheduled_cleanup', [ 'OLIMC_Scan', 'run_scheduled' ]);
        add_action('olimc_auto_empty_trash', [ 'OLIMC_Trash', 'run_auto_empty' ]);

        // Any deletion path (Media Library, wp_scheduled_delete, this plugin).
        add_filter('pre_delete_attachment', [ __CLASS__, 'pre_delete_attachment' ], 0, 3);
        add_filter('pre_trash_post', [ __CLASS__, 'pre_trash_post' ], 0, 3);
        add_filter('wp_delete_file', [ __CLASS__, 'preserve_shared_upload_file' ], 100);
        add_action('delete_attachment', [ __CLASS__, 'on_delete_attachment' ], 0);

        if (is_admin()) {
            OLIMC_Screen::init();
            OLIMC_Ajax::init();
        }
    }

    /**
     * Load bundled translations as a fallback until WordPress.org language packs ship.
     */
    public static function load_textdomain() {
        load_plugin_textdomain(
            'oli-media-cleaner',
            false,
            dirname(OLIMC_BASENAME) . '/languages'
        );
    }

    /**
     * Row meta on the Plugins screen: the only place besides the readme that mentions the donation link.
     *
     * @param string[] $links Existing links.
     * @param string   $file  Plugin file.
     * @return string[]
     */
    public static function plugin_row_meta( $links, $file ) {
        if ($file === OLIMC_BASENAME) {
            $links[] = '<a href="' . esc_url(OLIMC_DONATE_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Donate', 'oli-media-cleaner') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'oli-media-cleaner') . '</span></a>';
        }
        return $links;
    }

    // ─── Protection system ────────────────────────────────────────────

    /**
     * Add 'Protéger' checkbox to attachment edit screen.
     *
     * @param array   $form_fields Fields to display.
     * @param WP_Post $post        Attachment post.
     * @return array
     */
    public static function add_protection_checkbox( $form_fields, $post ) {
        if ($post->post_type !== 'attachment') {
            return $form_fields;
        }
        $protected = (bool) get_post_meta($post->ID, '_olimc_protected', true);
        $form_fields['olimc_protected'] = [
            'label' => __('Keep', 'oli-media-cleaner'),
            'input' => 'html',
            'html'  => '<input type="hidden" name="attachments[' . (int) $post->ID . '][olimc_protected_present]" value="1"><label><input type="checkbox" name="attachments[' . (int) $post->ID . '][olimc_protected]" value="1" ' . checked($protected, true, false) . '> ' . esc_html__('Keep this media (never report it as unused)', 'oli-media-cleaner') . '</label>',
        ];
        return $form_fields;
    }

    /**
     * Save the protection checkbox value.
     *
     * @param array $post       Attachment post data.
     * @param array $attachment Attachment fields.
     * @return array
     */
    public static function save_protection_checkbox( $post, $attachment ) {
        if ( ! isset($post['ID']) || ! current_user_can('edit_post', $post['ID'])) {
            return $post;
        }
        // Only a form that rendered the checkbox may change the flag (a partial save must not clear it).
        if (empty($attachment['olimc_protected_present'])) {
            return $post;
        }
        $post_id = (int) $post['ID'];
        $protected = ! empty($attachment['olimc_protected']);

        if ($protected) {
            update_post_meta($post_id, '_olimc_protected', 1);
            OLIMC_Results::remove($post_id);
        } else {
            delete_post_meta($post_id, '_olimc_protected');
        }

        return $post;
    }

    /**
     * Check if an attachment is protected via meta or path matching.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function is_attachment_protected( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }

        // Check per-attachment meta flag
        if (get_post_meta($attachment_id, '_olimc_protected', true)) {
            return true;
        }

        $protected_paths = self::get_protected_path_patterns();
        if ($protected_paths === []) {
            return false;
        }

        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($file)) {
            return false;
        }

        $upload_dir = wp_upload_dir(null, false);
        $base_url = isset($upload_dir['baseurl']) ? $upload_dir['baseurl'] : '';

        foreach ($protected_paths as $pattern) {
            $pattern = self::normalize_protected_pattern($pattern, $base_url);
            if ($pattern === '') {
                continue;
            }

            if (self::path_matches_pattern($file, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the stored protected-paths option into a list of patterns.
     *
     * @param mixed $raw Option value (multiline string).
     * @return string[]
     */
    public static function parse_protected_path_option( $raw ) {
        if ( ! is_string($raw) || $raw === '') {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return array_values(array_unique($lines));
    }

    /**
     * Protected paths and glob patterns for this site.
     *
     * Empty by default. Values come from the `olimc_protected_paths` option
     * (one path, URL, or glob per line) and the `olimc_protected_paths` filter.
     *
     * @return string[]
     */
    public static function get_protected_path_patterns() {
        $patterns = self::parse_protected_path_option(get_option('olimc_protected_paths', ''));
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('olimc_protected_paths', $patterns);
            if (is_array($filtered)) {
                $patterns = [];
                foreach ($filtered as $pattern) {
                    if ( ! is_string($pattern)) {
                        continue;
                    }
                    $pattern = trim($pattern);
                    if ($pattern !== '') {
                        $patterns[] = $pattern;
                    }
                }
                $patterns = array_values(array_unique($patterns));
            }
        }
        return $patterns;
    }

    /**
     * Persist previously applied protected paths into the site option.
     *
     * Runtime defaults stay empty. Existing option values are kept. A one-time
     * `olimc_legacy_protected_paths` filter may supply the list a site already
     * relied on so this plugin never ships client-specific paths.
     */
    public static function migrate_protected_paths_from_legacy() {
        $existing = self::parse_protected_path_option(get_option('olimc_protected_paths', ''));
        $legacy = [];
        if (function_exists('apply_filters')) {
            $legacy = apply_filters('olimc_legacy_protected_paths', []);
        }
        if ( ! is_array($legacy)) {
            $legacy = [];
        }
        $from_legacy = [];
        foreach ($legacy as $pattern) {
            if ( ! is_string($pattern)) {
                continue;
            }
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $from_legacy[] = $pattern;
            }
        }
        $merged = array_values(array_unique(array_merge($existing, $from_legacy)));
        update_option('olimc_protected_paths', implode("\n", $merged), false);
    }

    /**
     * Normalize a protected pattern to uploads-relative path.
     *
     * @param string $pattern  Raw pattern (path, URL, or glob).
     * @param string $base_url Upload base URL.
     * @return string Normalized pattern.
     */
    private static function normalize_protected_pattern( $pattern, $base_url ) {
        $pattern = trim($pattern);
        if (empty($pattern)) {
            return '';
        }

        // Convert URL to path
        if (preg_match('#^https?://#i', $pattern)) {
            if ( ! empty($base_url) && strpos($pattern, $base_url) === 0) {
                $pattern = substr($pattern, strlen($base_url));
            } else {
                // Try to extract path from URL
                if (preg_match('#/wp-content/uploads/(.+)$#', $pattern, $m)) {
                    $pattern = $m[1];
                }
            }
        }

        // Remove leading slash for consistency
        return ltrim($pattern, '/');
    }

    /**
     * Check if a file path matches a glob pattern or literal path.
     *
     * Shared helper used by both Admin and Scanner classes.
     *
     * Glob syntax:
     * - * matches within one directory segment (excludes /)
     * - ** matches across directory segments (includes /)
     * - Exact paths are case-sensitive
     * - Glob patterns are case-insensitive
     *
     * @param string $file    Uploads-relative file path.
     * @param string $pattern Pattern (may contain * or ** wildcards).
     * @return bool
     */
    public static function path_matches_pattern( $file, $pattern ) {
        $file = ltrim($file, '/');
        $pattern = ltrim($pattern, '/');

        // Check if pattern contains wildcards
        $has_wildcards = strpos($pattern, '*') !== false;

        if ( ! $has_wildcards) {
            // Exact match is case-sensitive
            return $file === $pattern;
        }

        // Glob match: case-insensitive
        // ** matches across segments (including /), * matches within a segment (excluding /)
        // First, escape the pattern and protect ** markers
        $regex_pattern = preg_quote($pattern, '#');

        // Replace \*\* with a placeholder that won't conflict
        $regex_pattern = str_replace('\\*\\*', '__DOUBLESTAR__', $regex_pattern);

        // Replace remaining \* (single stars) with [^/]* (matches within segment)
        $regex_pattern = str_replace('\\*', '[^/]*', $regex_pattern);

        // Replace __DOUBLESTAR__ with .* (matches across segments)
        $regex_pattern = str_replace('__DOUBLESTAR__', '.*', $regex_pattern);

        $regex = '#^' . $regex_pattern . '$#i';
        return (bool) preg_match($regex, $file);
    }

    // ─── End protection system ────────────────────────────────────────
    // ─── Lifecycle and migration ─────────────────────────────────────

    public static function activate() {
        self::maybe_upgrade();
        OLIMC_Results::install();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('olimc_scheduled_cleanup');
        wp_clear_scheduled_hook('olimc_auto_empty_trash');
        OLIMC_Scan::clear_runtime();
    }

    /**
     * Run versioned upgrades. Safe to call on init and on activation: each step
     * is idempotent and a step runs once per site.
     */
    public static function maybe_upgrade() {
        $stored = get_option('olimc_version', '0');
        if ( ! is_string($stored) && ! is_numeric($stored)) {
            $stored = '0';
        }
        $stored = (string) $stored;
        if ($stored === OLIMC_VERSION) {
            return;
        }
        if (version_compare($stored, '1.5.24', '<')) {
            self::migrate_protected_paths_from_legacy();
        }
        OLIMC_Results::install();
        if (version_compare($stored, '2.0.0', '<')) {
            self::migrate_to_2_0($stored !== '0');
        }
        update_option('olimc_version', OLIMC_VERSION);
    }

    /**
     * 1.x to 2.0: the whitelist becomes "kept", old results are dropped (a new
     * scan is required), and the scheduled events are re-created.
     *
     * @param bool $upgrade True when a 1.x version was installed (not a new install).
     */
    public static function migrate_to_2_0( $upgrade ) {
        $whitelist = get_option('olimc_whitelist', []);
        if (is_array($whitelist)) {
            foreach ($whitelist as $id) {
                $id = (int) $id;
                if ($id > 0 && get_post_type($id) === 'attachment') {
                    update_post_meta($id, '_olimc_protected', 1);
                }
            }
        }
        delete_option('olimc_whitelist');
        OLIMC_Results::invalidate();
        delete_option('olimc_scan_results');
        delete_option('olimc_scan_used_ids');
        delete_option('olimc_scan_state');
        delete_option('olimc_scan_pending_ajax');
        delete_option('olimc_scan_pending_cron');
        delete_option('olimc_scan_state_ajax');
        delete_option('olimc_scan_state_cron');
        delete_option(OLIMC_Acf_Meta_Scanner::LOCK_OPTION);
        delete_option('olimc_donate_dismissed');
        // 1.x options carried over: protected paths stay as they are; the schedule keeps its values.
        if (get_option('olimc_cron_enabled', null) !== null) {
            update_option('olimc_schedule_enabled', get_option('olimc_cron_enabled') ? '1' : '0', false);
            update_option('olimc_schedule_frequency', self::frequency_from_legacy(get_option('olimc_cron_frequency', 'daily')), false);
        }
        delete_option('olimc_cron_enabled');
        delete_option('olimc_cron_frequency');
        wp_clear_scheduled_hook('olimc_scheduled_cleanup');
        OLIMC_Settings::sync_schedule();
        if ($upgrade) {
            update_option('olimc_migr_notice', '1', false);
        }
    }

    /**
     * @param mixed $value Value of the 1.x frequency option.
     * @return string daily|twicedaily|weekly
     */
    private static function frequency_from_legacy( $value ) {
        return in_array($value, [ 'daily', 'twicedaily', 'weekly' ], true) ? $value : 'daily';
    }


    /**
     * Shared files, unknown index, protected files, or in-use media must not be deleted or trashed.
     *
     * @param int $post_id Attachment ID.
     * @return bool
     */
    public static function must_keep_attachment_files( $post_id ) {
        $post_id = (int) $post_id;
        $keep = false;

        if ($post_id > 0) {
            // Check protection first (cheapest check)
            if (self::is_attachment_protected($post_id)) {
                $keep = true;
            } else {
                $scanner = new OLIMC_Scanner();
                $shared = $scanner->shared_relative_upload_paths($post_id);
                if ($shared === null || $shared !== []) {
                    $keep = true;
                } else {
                    $in_use = $scanner->is_attachment_in_use_now($post_id);
                    if ($in_use === true || $in_use === null) {
                        $keep = true;
                    }
                    $scan_date = get_option('olimc_scan_date', '');
                    if ( ! $keep && empty($scan_date) && $in_use !== false) {
                        $keep = true;
                    }
                }
            }
        }

        return (bool) apply_filters('olimc_must_keep_attachment', $keep, $post_id);
    }

    /**
     * Stop wp_delete_attachment before delete_attachment (Imagify) and file removal.
     *
     * @param mixed $check        Previous decision.
     * @param mixed $post         Post object.
     * @param bool  $force_delete Unused. Present so the filter receives the core arguments.
     * @return mixed
     */
    public static function pre_delete_attachment( $check, $post, $force_delete = false ) {
        unset($force_delete);
        if (null !== $check) {
            return $check;
        }
        if ( ! is_object($post) || ( isset($post->post_type) && $post->post_type !== 'attachment' )) {
            return null;
        }
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if (self::must_keep_attachment_files($post_id)) {
            return false;
        }
        return null;
    }

    /**
     * Refuse to trash an attachment whose files are still used elsewhere.
     *
     * With EMPTY_TRASH_DAYS at 0, trashing deletes immediately. Otherwise WordPress
     * permanently deletes the trash after 30 days, outside this plugin.
     *
     * @param mixed $check Previous decision.
     * @param mixed $post  Post object.
     * @param mixed $previous_status Unused. Present so the filter receives the core arguments.
     * @return mixed
     */
    public static function pre_trash_post( $check, $post, $previous_status = '' ) {
        unset($previous_status);
        if (null !== $check) {
            return $check;
        }
        if ( ! is_object($post) || ( isset($post->post_type) && $post->post_type !== 'attachment' )) {
            return null;
        }
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if (self::must_keep_attachment_files($post_id)) {
            return false;
        }
        return null;
    }

    /**
     * Keep a file on disk when another attachment still references it.
     *
     * Also keeps a .webp or .avif sibling of a shared file. A failed lookup
     * keeps the file. Returning an empty string makes wp_delete_file skip unlink.
     *
     * @param string $file Absolute path.
     * @return string
     */
    public static function preserve_shared_upload_file( $file ) {
        if ( ! is_string($file) || $file === '') {
            return $file;
        }
        $verdict = ( new OLIMC_Scanner() )->absolute_file_is_shared($file);
        if ($verdict === false) {
            return $file;
        }
        return '';
    }

    /**
     * If deletion still reaches delete_attachment, drop later callbacks.
     *
     * Imagify removes WebP, AVIF, and its backup with WP_Filesystem, which
     * does not call wp_delete_file. Priority 0 runs before those callbacks.
     *
     * @param int $post_id Attachment ID.
     */
    public static function on_delete_attachment( $post_id ) {
        if ( ! self::must_keep_attachment_files($post_id)) {
            return;
        }
        if ( ! isset($GLOBALS['wp_filter']['delete_attachment'])) {
            return;
        }
        $hook = $GLOBALS['wp_filter']['delete_attachment'];
        $callbacks = ( is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks) ) ? $hook->callbacks : null;
        if ( ! is_array($callbacks)) {
            return;
        }
        foreach ($callbacks as $priority => $group) {
            if ( (int) $priority <= 0 || ! is_array($group)) {
                continue;
            }
            foreach ($group as $callback) {
                if (is_array($callback) && isset($callback['function'])) {
                    remove_action('delete_attachment', $callback['function'], (int) $priority);
                }
            }
        }
    }
}
