<?php
defined('ABSPATH') || exit;

class OLIMC_Admin {

    public static function init() {
        add_action('init', [__CLASS__, 'load_textdomain']);
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('plugin_action_links_' . OLIMC_BASENAME, [__CLASS__, 'plugin_action_links']);
        add_filter('plugin_row_meta', [__CLASS__, 'plugin_row_meta'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'donate_notice']);
        add_action('wp_ajax_olimc_dismiss_donate', [__CLASS__, 'ajax_dismiss_donate']);

        // Protection hooks
        add_filter('attachment_fields_to_edit', [__CLASS__, 'add_protection_checkbox'], 10, 2);
        add_filter('attachment_fields_to_save', [__CLASS__, 'save_protection_checkbox'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_olimc_start_scan', [__CLASS__, 'ajax_start_scan']);
        add_action('wp_ajax_olimc_scan_batch', [__CLASS__, 'ajax_scan_batch']);
        add_action('wp_ajax_olimc_get_results', [__CLASS__, 'ajax_get_results']);
        add_action('wp_ajax_olimc_export_csv', [__CLASS__, 'ajax_export_csv']);
        add_action('wp_ajax_olimc_trash_single', [__CLASS__, 'ajax_trash_single']);
        add_action('wp_ajax_olimc_trash_bulk', [__CLASS__, 'ajax_trash_bulk']);
        add_action('wp_ajax_olimc_trash_all_batch', [__CLASS__, 'ajax_trash_all_batch']);
        add_action('wp_ajax_olimc_delete_single', [__CLASS__, 'ajax_delete_single']);
        add_action('wp_ajax_olimc_delete_bulk', [__CLASS__, 'ajax_delete_bulk']);
        add_action('wp_ajax_olimc_whitelist_single', [__CLASS__, 'ajax_whitelist_single']);
        add_action('wp_ajax_olimc_whitelist_bulk', [__CLASS__, 'ajax_whitelist_bulk']);
        add_action('wp_ajax_olimc_remove_whitelist', [__CLASS__, 'ajax_remove_whitelist']);
        add_action('wp_ajax_olimc_remove_whitelist_bulk', [__CLASS__, 'ajax_remove_whitelist_bulk']);
        add_action('wp_ajax_olimc_remove_whitelist_all', [__CLASS__, 'ajax_remove_whitelist_all']);
        add_action('wp_ajax_olimc_restore_single', [__CLASS__, 'ajax_restore_single']);
        add_action('wp_ajax_olimc_restore_bulk', [__CLASS__, 'ajax_restore_bulk']);
        add_action('wp_ajax_olimc_restore_all_batch', [__CLASS__, 'ajax_restore_all_batch']);
        add_action('wp_ajax_olimc_save_cron_settings', [__CLASS__, 'ajax_save_cron_settings']);
        add_action('wp_ajax_olimc_save_protection_settings', [__CLASS__, 'ajax_save_protection_settings']);
        add_action('wp_ajax_olimc_empty_trash_batch', [__CLASS__, 'ajax_empty_trash_batch']);

        // Cron hook
        add_action('olimc_scheduled_cleanup', [__CLASS__, 'run_scheduled_cleanup']);

        // Any deletion path (Media Library, wp_scheduled_delete, this plugin).
        add_filter('pre_delete_attachment', [__CLASS__, 'pre_delete_attachment'], 0, 3);
        add_filter('pre_trash_post', [__CLASS__, 'pre_trash_post'], 0, 3);
        add_filter('wp_delete_file', [__CLASS__, 'preserve_shared_upload_file'], 100);
        add_action('delete_attachment', [__CLASS__, 'on_delete_attachment'], 0);
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

    // ─── Protection system ────────────────────────────────────────────

    /**
     * Add 'Protéger' checkbox to attachment edit screen.
     *
     * @param array   $form_fields Fields to display.
     * @param WP_Post $post        Attachment post.
     * @return array
     */
    public static function add_protection_checkbox($form_fields, $post) {
        if ($post->post_type !== 'attachment') {
            return $form_fields;
        }
        $protected = (bool) get_post_meta($post->ID, '_olimc_protected', true);
        $form_fields['olimc_protected'] = [
            'label' => __('Protect from deletion', 'oli-media-cleaner'),
            'input' => 'html',
            'html'  => '<label><input type="checkbox" name="attachments[' . (int) $post->ID . '][olimc_protected]" value="1" ' . checked($protected, true, false) . '> ' . esc_html__('Protect this file from being listed as unused or deleted by Media Cleaner', 'oli-media-cleaner') . '</label>',
            'helps' => __('When checked, Media Cleaner will never flag this file as unused and will refuse any deletion attempts.', 'oli-media-cleaner'),
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
    public static function save_protection_checkbox($post, $attachment) {
        if (!isset($post['ID']) || !current_user_can('edit_post', $post['ID'])) {
            return $post;
        }
        $post_id = (int) $post['ID'];
        $protected = !empty($attachment['olimc_protected']);
        
        if ($protected) {
            update_post_meta($post_id, '_olimc_protected', 1);
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
    public static function is_attachment_protected($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }

        // Check per-attachment meta flag
        if (get_post_meta($attachment_id, '_olimc_protected', true)) {
            return true;
        }

        // Check global protected paths
        $protected_paths = get_option('olimc_protected_paths', '');
        if (empty($protected_paths) || !is_string($protected_paths)) {
            return false;
        }

        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($file)) {
            return false;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return false;
        }

        $upload_dir = wp_upload_dir(null, false);
        $base_url = isset($upload_dir['baseurl']) ? $upload_dir['baseurl'] : '';

        $lines = array_filter(array_map('trim', explode("\n", $protected_paths)));
        foreach ($lines as $pattern) {
            if (empty($pattern)) {
                continue;
            }

            // Normalize to uploads-relative path
            $pattern = self::normalize_protected_pattern($pattern, $base_url);
            if (empty($pattern)) {
                continue;
            }

            // Check if file matches pattern (glob or literal)
            if (self::path_matches_pattern($file, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a protected pattern to uploads-relative path.
     *
     * @param string $pattern  Raw pattern (path, URL, or glob).
     * @param string $base_url Upload base URL.
     * @return string Normalized pattern.
     */
    private static function normalize_protected_pattern($pattern, $base_url) {
        $pattern = trim($pattern);
        if (empty($pattern)) {
            return '';
        }

        // Convert URL to path
        if (preg_match('#^https?://#i', $pattern)) {
            if (!empty($base_url) && strpos($pattern, $base_url) === 0) {
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
    public static function path_matches_pattern($file, $pattern) {
        $file = ltrim($file, '/');
        $pattern = ltrim($pattern, '/');

        // Check if pattern contains wildcards
        $has_wildcards = strpos($pattern, '*') !== false;

        if (!$has_wildcards) {
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

    public static function activate() {
        update_option('olimc_version', OLIMC_VERSION);
        // Initialize whitelist as empty
        if (false === get_option('olimc_whitelist')) {
            update_option('olimc_whitelist', [], false);
        }
        // Initialize protected paths as empty
        if (false === get_option('olimc_protected_paths')) {
            update_option('olimc_protected_paths', '', false);
        }
    }

    public static function deactivate() {
        delete_option('olimc_scan_results');
        delete_option('olimc_scan_used_ids');
        delete_option('olimc_scan_date');
        self::delete_scan_runtime_options();
        wp_clear_scheduled_hook('olimc_scheduled_cleanup');
    }

    public static function add_menu() {
        add_submenu_page(
            'upload.php',
            __('Media Cleaner', 'oli-media-cleaner'),
            __('Media Cleaner', 'oli-media-cleaner'),
            'manage_options',
            'oli-media-cleaner',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Settings + Donate links next to Deactivate on the Plugins screen.
     *
     * @param string[] $links Existing action links.
     * @return string[]
     */
    public static function plugin_action_links($links) {
        $settings = '<a href="' . esc_url(admin_url('upload.php?page=oli-media-cleaner')) . '">' . esc_html__('Settings', 'oli-media-cleaner') . '</a>';
        $donate   = '<a href="' . esc_url(OLIMC_DONATE_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Donate', 'oli-media-cleaner') . '</a>';
        array_unshift($links, $settings, $donate);
        return $links;
    }

    /**
     * Donate link in the plugin meta row (Version | By | View details | Donate).
     *
     * @param string[] $links Plugin meta links.
     * @param string   $file  Plugin basename.
     * @return string[]
     */
    public static function plugin_row_meta($links, $file) {
        if ($file !== OLIMC_BASENAME) {
            return $links;
        }
        $links[] = '<a href="' . esc_url(OLIMC_DONATE_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Donate', 'oli-media-cleaner') . '</a>';
        return $links;
    }

    /**
     * Dismissible support notice on the Media Cleaner screen only.
     */
    public static function donate_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'media_page_oli-media-cleaner') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_user_meta(get_current_user_id(), 'olimc_donate_dismissed', true)) {
            return;
        }
        ?>
        <div class="notice notice-info is-dismissible olimc-donate-notice">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: donate URL */
                        __('Enjoying Oli Media Cleaner? <a href="%s" target="_blank" rel="noopener noreferrer">Buy me a coffee</a> — your support keeps the plugin free and maintained.', 'oli-media-cleaner'),
                        esc_url(OLIMC_DONATE_URL)
                    ),
                    [
                        'a' => [
                            'href'   => true,
                            'target' => true,
                            'rel'    => true,
                        ],
                    ]
                );
                ?>
            </p>
        </div>
        <script>
        (function () {
            var notice = document.querySelector('.olimc-donate-notice');
            if (!notice) return;
            notice.addEventListener('click', function (e) {
                if (!e.target.classList.contains('notice-dismiss')) return;
                if (typeof olimcObj === 'undefined') return;
                var body = new FormData();
                body.append('action', 'olimc_dismiss_donate');
                body.append('nonce', olimcObj.nonce);
                if (window.fetch) {
                    fetch(olimcObj.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' });
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Persist dismissal of the donate notice for the current user.
     */
    public static function ajax_dismiss_donate() {
        check_ajax_referer('olimc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }
        update_user_meta(get_current_user_id(), 'olimc_donate_dismissed', 1);
        wp_send_json_success();
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'media_page_oli-media-cleaner') return;

        wp_enqueue_style(
            'olimc-admin-css',
            OLIMC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OLIMC_VERSION
        );

        wp_enqueue_script(
            'olimc-admin-js',
            OLIMC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            OLIMC_VERSION,
            true
        );

        wp_localize_script('olimc-admin-js', 'olimcObj', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('olimc_nonce'),
            'strings' => [
                'scanning'               => __('Scanning...', 'oli-media-cleaner'),
                'preparing'              => __('Analyzing your site… this can take a moment.', 'oli-media-cleaner'),
                'scan_complete'          => __('Scan complete!', 'oli-media-cleaner'),
                'confirm_trash'          => __('Move this file to the Media Trash? You can restore it from the Trash tab before permanently deleting.', 'oli-media-cleaner'),
                'confirm_delete'         => __('Permanently delete this file? This cannot be undone. Consider a backup first.', 'oli-media-cleaner'),
                'confirm_bulk_trash'     => __('Move the selected files to the Media Trash? You can restore them from the Trash tab before permanently deleting.', 'oli-media-cleaner'),
                'confirm_bulk_delete'    => __('Permanently delete the selected files? This cannot be undone. Consider a backup first.', 'oli-media-cleaner'),
                'confirm_preview_intro'  => __('You are about to affect:', 'oli-media-cleaner'),
                'no_selection'           => __('No files selected.', 'oli-media-cleaner'),
                'confirm_trash_all'      => __('Move ALL unused media to the Media Trash in batches? Review the list first. You can restore from the Trash tab before permanently deleting.', 'oli-media-cleaner'),
                'confirm_empty_trash'    => __('WARNING: This permanently deletes ALL files in the trash. This cannot be undone. Back up your site (or at least wp-content/uploads) before continuing. Empty the trash now?', 'oli-media-cleaner'),
                'confirm_remove_whitelist' => __('Remove selected items from whitelist?', 'oli-media-cleaner'),
                'confirm_remove_whitelist_all' => __('Remove ALL items from the whitelist? They will appear again as unused on the next scan.', 'oli-media-cleaner'),
                'remove_all_whitelist'   => __('Remove All from Whitelist', 'oli-media-cleaner'),
                'scan_failed'            => __('Scan failed', 'oli-media-cleaner'),
                'network_error'          => __('Network error', 'oli-media-cleaner'),
                'batch_scan_error'       => __('Batch scan error', 'oli-media-cleaner'),
                'network_error_scan'     => __('Network error during scan', 'oli-media-cleaner'),
                'rescan'                 => __('Re-Scan', 'oli-media-cleaner'),
                'scan_button'            => __('Scan for Unused Media', 'oli-media-cleaner'),
                /* translators: %1$d: progress percent, %2$d: unused file count */
                'unused_found_progress'  => __('%1$d%% — %2$d unused found', 'oli-media-cleaner'),
                /* translators: %d: unused file count */
                'unused_files_count'     => __('%d unused files.', 'oli-media-cleaner'),
                /* translators: %d: unused file count */
                'found_unused'           => __('Found %d unused files.', 'oli-media-cleaner'),
                'trashing'               => __('Trashing...', 'oli-media-cleaner'),
                'deleting'               => __('Deleting...', 'oli-media-cleaner'),
                'restoring'              => __('Restoring...', 'oli-media-cleaner'),
                'starting'               => __('Starting...', 'oli-media-cleaner'),
                'error_trashing'         => __('Error trashing files', 'oli-media-cleaner'),
                'error_deleting'         => __('Error deleting files', 'oli-media-cleaner'),
                'error_restoring'        => __('Error restoring files', 'oli-media-cleaner'),
                'confirm_restore_all'    => __('Restore ALL trashed files? This will process all pages in batches.', 'oli-media-cleaner'),
                'restore_all'            => __('Restore All', 'oli-media-cleaner'),
                /* translators: %1$d: progress percent, %2$d: processed count, %3$d: total count */
                'restored_progress'      => __('%1$d%% — %2$d / %3$d restored', 'oli-media-cleaner'),
                /* translators: %d: number of files */
                'done_restored'          => __('Done! %d files restored.', 'oli-media-cleaner'),
                /* translators: %1$d: progress percent, %2$d: processed count, %3$d: total count */
                'trashed_progress'       => __('%1$d%% — %2$d / %3$d trashed', 'oli-media-cleaner'),
                /* translators: %d: number of files */
                'done_trashed'           => __('Done! %d files moved to trash.', 'oli-media-cleaner'),
                /* translators: %1$d: progress percent, %2$d: processed count, %3$d: total count */
                'deleted_progress'       => __('%1$d%% — %2$d / %3$d permanently deleted', 'oli-media-cleaner'),
                /* translators: %d: number of files */
                'done_deleted'           => __('Done! %d files permanently deleted.', 'oli-media-cleaner'),
                'error_saving'           => __('Error saving settings', 'oli-media-cleaner'),
                /* translators: %1$d: selected count, %2$s: total size */
                'selected_info'          => __('%1$d selected (%2$s)', 'oli-media-cleaner'),
                /* translators: %d: number of items */
                'items_count'            => __('%d items', 'oli-media-cleaner'),
                'trash_all_unused'       => __('Trash All Unused', 'oli-media-cleaner'),
                'empty_trash'            => __('Empty Trash', 'oli-media-cleaner'),
                'pattern_match_counts'   => __('Pattern Match Counts:', 'oli-media-cleaner'),
                /* translators: %d: number of files */
                'pattern_matches_file'   => __('%d file', 'oli-media-cleaner'),
                /* translators: %d: number of files */
                'pattern_matches_files'  => __('%d files', 'oli-media-cleaner'),
                'pattern_no_matches'     => __('No matches (possible typo?)', 'oli-media-cleaner'),
            ],
        ]);
    }

    // ─── Page render ──────────────────────────────────────────────────

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        $scan_date    = get_option( 'olimc_scan_date', '' );
        $tab          = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'unused';
        $whitelist    = get_option( 'olimc_whitelist', [] );
        $unused_count = count( get_option( 'olimc_scan_results', [] ) );
        $base_url     = admin_url( 'upload.php?page=oli-media-cleaner' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Oli Media Cleaner', 'oli-media-cleaner'); ?></h1>

            <div class="postbox" style="margin-top:20px;">
                <div class="inside" id="olimc-stats">
                    <?php self::render_stats(); ?>
                </div>
            </div>

            <p>
                <button type="button" id="olimc-scan-btn" class="button button-primary">
                    <?php esc_html_e('Scan for Unused Media', 'oli-media-cleaner'); ?>
                </button>
                <?php if ($scan_date): ?>
                    <span class="description" style="margin-left:10px;">
                        <?php
                        /* translators: %s: date and time of last scan */
                        printf( esc_html__( 'Last scan: %s', 'oli-media-cleaner' ), esc_html( date_i18n( 'M j, Y g:i a', strtotime( $scan_date ) ) ) );
                        ?>
                    </span>
                <?php endif; ?>
            </p>

            <div id="olimc-progress-wrap" style="display:none;margin-bottom:15px;">
                <div style="background:#e0e0e0;height:20px;border-radius:3px;overflow:hidden;max-width:500px;">
                    <div id="olimc-progress-fill" style="background:#2271b1;height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <p class="description" id="olimc-progress-text">0%</p>
            </div>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base_url . '&tab=unused'); ?>"
                   class="nav-tab <?php echo $tab === 'unused' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Unused', 'oli-media-cleaner'); ?>
                    <span class="count" id="olimc-unused-count">(<?php echo esc_html( (string) $unused_count ); ?>)</span>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=whitelist'); ?>"
                   class="nav-tab <?php echo $tab === 'whitelist' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Whitelist', 'oli-media-cleaner'); ?>
                    <span class="count" id="olimc-whitelist-count">(<?php echo count($whitelist); ?>)</span>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=trash'); ?>"
                   class="nav-tab <?php echo $tab === 'trash' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Trash', 'oli-media-cleaner'); ?>
                    <span class="count" id="olimc-trash-count">(<?php echo esc_html( wp_count_posts('attachment')->trash ); ?>)</span>
                </a>
            </nav>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <?php
                    // Every tab's actions stay in the DOM; JS shows/hides them on tab switch and selection.
                    $whitelist_count    = count( $whitelist );
                    $trash_count        = (int) wp_count_posts( 'attachment' )->trash;
                    $show_unused_all    = ( $tab === 'unused' && $unused_count > 0 );
                    $show_whitelist_all = ( $tab === 'whitelist' && $whitelist_count > 0 );
                    $show_trash_all     = ( $tab === 'trash' && $trash_count > 0 );
                    ?>
                    <button type="button" class="button olimc-tab-unused olimc-needs-selection" id="olimc-bulk-trash-btn" style="display:none;"><?php esc_html_e('Trash Selected', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-unused olimc-needs-selection" id="olimc-bulk-whitelist-btn" style="display:none;"><?php esc_html_e('Whitelist Selected', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-unused olimc-when-has-items" id="olimc-trash-all-btn"<?php echo $show_unused_all ? '' : ' style="display:none;"'; ?>><?php esc_html_e('Trash All Unused', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-whitelist olimc-needs-selection" id="olimc-bulk-remove-whitelist-btn" style="display:none;"><?php esc_html_e('Remove from Whitelist', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-whitelist olimc-when-has-items" id="olimc-remove-all-whitelist-btn"<?php echo $show_whitelist_all ? '' : ' style="display:none;"'; ?>><?php esc_html_e('Remove All from Whitelist', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-trash olimc-needs-selection" id="olimc-bulk-restore-btn" style="display:none;"><?php esc_html_e('Restore Selected', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-trash olimc-needs-selection" id="olimc-bulk-delete-btn" style="display:none;"><?php esc_html_e('Delete Selected', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-trash olimc-when-has-items" id="olimc-restore-all-btn"<?php echo $show_trash_all ? '' : ' style="display:none;"'; ?>><?php esc_html_e('Restore All', 'oli-media-cleaner'); ?></button>
                    <button type="button" class="button olimc-tab-trash olimc-when-has-items" id="olimc-empty-trash-btn"<?php echo $show_trash_all ? '' : ' style="display:none;"'; ?>><?php esc_html_e('Empty Trash', 'oli-media-cleaner'); ?></button>
                    <span id="olimc-selected-info" class="description" style="margin-left:8px;"></span>
                </div>
                <div class="alignright">
                    <select id="olimc-filter-type" style="vertical-align:middle;">
                        <?php echo self::render_filter_type_options(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </select>
                    <input type="search" id="olimc-search" placeholder="<?php esc_attr_e('Search files...', 'oli-media-cleaner'); ?>" style="vertical-align:middle;">
                    <button type="button" id="olimc-search-btn" class="button"><?php esc_html_e('Search', 'oli-media-cleaner'); ?></button>
                    <button type="button" id="olimc-export-csv-btn" class="button" style="vertical-align:middle;">
                        <span class="dashicons dashicons-download" style="vertical-align:text-top;font-size:16px;height:16px;width:16px;"></span>
                        <?php esc_html_e('Export CSV', 'oli-media-cleaner'); ?>
                    </button>
                </div>
            </div>

            <div id="olimc-results">
                <?php self::render_results_table($tab); ?>
            </div>

            <div class="tablenav bottom">
                <div class="alignleft actions">
                    <label><?php esc_html_e('Show', 'oli-media-cleaner'); ?>
                        <select id="olimc-per-page" style="vertical-align:middle;">
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <?php esc_html_e('per page', 'oli-media-cleaner'); ?>
                    </label>
                </div>
                <div class="alignright" id="olimc-pagination"></div>
                <br class="clear">
            </div>

            <div class="postbox" style="margin-top:30px;">
                <div class="postbox-header"><h2 style="padding:8px 12px;margin:0;"><?php esc_html_e('Protection Settings', 'oli-media-cleaner'); ?></h2></div>
                <div class="inside">
                    <p class="description">
                        <?php esc_html_e('Protect specific files from being listed as unused or deleted. Enter one path, URL, or glob pattern per line.', 'oli-media-cleaner'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Protected Paths', 'oli-media-cleaner'); ?></th>
                            <td>
                                <textarea
                                    id="olimc-protected-paths"
                                    rows="8"
                                    style="width:100%;max-width:600px;font-family:monospace;"
                                    placeholder="<?php esc_attr_e('Examples:
2024/03/logo_osm_2019.png
2023/*/header-*.jpg
2024/**/receipts/*.pdf
https://example.com/wp-content/uploads/2024/03/logo.png', 'oli-media-cleaner'); ?>"
                                ><?php echo esc_textarea(get_option('olimc_protected_paths', '')); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Use * wildcards for glob patterns: * matches within one directory segment, ** matches across segments. Paths are relative to the uploads directory. Derivatives (thumbnails, WebP) are automatically protected when the original is protected.', 'oli-media-cleaner'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Per-File Protection', 'oli-media-cleaner'); ?></th>
                            <td>
                                <p class="description">
                                    <?php esc_html_e('Individual files can also be protected using the "Protect from deletion" checkbox in the attachment edit screen or media modal.', 'oli-media-cleaner'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="olimc-save-protection-btn" class="button button-primary"><?php esc_html_e('Save Protection Settings', 'oli-media-cleaner'); ?></button>
                    </p>
                </div>
            </div>

            <div class="postbox" style="margin-top:30px;">
                <div class="postbox-header"><h2 style="padding:8px 12px;margin:0;"><?php esc_html_e('Scheduled Scan', 'oli-media-cleaner'); ?></h2></div>
                <div class="inside">
                    <?php
                    $cron_enabled = get_option('olimc_cron_enabled', false);
                    $cron_frequency = get_option('olimc_cron_frequency', 'daily');
                    $next_run = wp_next_scheduled('olimc_scheduled_cleanup');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Enable Scheduled Scan', 'oli-media-cleaner'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="olimc-cron-enabled" <?php checked($cron_enabled); ?>>
                                    <?php esc_html_e('Automatically scan for unused images on a schedule (results only — nothing is deleted without your confirmation in the admin)', 'oli-media-cleaner'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Frequency', 'oli-media-cleaner'); ?></th>
                            <td>
                                <select id="olimc-cron-frequency">
                                    <option value="daily" <?php selected($cron_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'oli-media-cleaner'); ?></option>
                                    <option value="twicedaily" <?php selected($cron_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'oli-media-cleaner'); ?></option>
                                    <option value="weekly" <?php selected($cron_frequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'oli-media-cleaner'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Next Scheduled Run', 'oli-media-cleaner'); ?></th>
                            <td>
                                <span id="olimc-next-run">
                                    <?php echo $next_run ? esc_html( date_i18n('M j, Y g:i a', $next_run) ) : esc_html__('Not scheduled', 'oli-media-cleaner'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="olimc-save-cron-btn" class="button button-primary"><?php esc_html_e('Save Settings', 'oli-media-cleaner'); ?></button>
                    </p>
                </div>
            </div>

            <p class="description olimc-support-footer" style="margin-top:20px;">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: donate URL */
                        __('If this plugin helps you, please consider a <a href="%s" target="_blank" rel="noopener noreferrer">donation</a>. Thank you!', 'oli-media-cleaner'),
                        esc_url(OLIMC_DONATE_URL)
                    ),
                    [
                        'a' => [
                            'href'   => true,
                            'target' => true,
                            'rel'    => true,
                        ],
                    ]
                );
                ?>
            </p>
        </div>
        <?php
    }

    private static function render_stats() {
        $scan_results = get_option('olimc_scan_results', []);
        $total = (new OLIMC_Scanner())->get_total_attachment_count();
        $unused_count = count($scan_results);
        $used_count = $total - $unused_count;
        $whitelist = get_option('olimc_whitelist', []);

        $unused_size = 0;
        if (!empty($scan_results)) {
            foreach ($scan_results as $item) {
                $unused_size += (int)($item['file_size'] ?? 0);
            }
        }
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Total Media', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n($total) ); ?></strong></td>
                <th><?php esc_html_e('In Use', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n($used_count) ); ?></strong></td>
                <th><?php esc_html_e('Unused', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n($unused_count) ); ?></strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Space to Free', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( size_format($unused_size) ); ?></strong></td>
                <th><?php esc_html_e('Whitelisted', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n(count($whitelist)) ); ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Build the <option>/<optgroup> markup for the file-type filter from the
     * current scan results. Returned (not echoed) so it can be reused both on
     * initial page render and in the AJAX results refresh, keeping the dropdown
     * in sync after a scan without a full page reload.
     *
     * @param string $selected Currently selected extension to preserve.
     * @return string Escaped HTML for the select's inner options.
     */
    private static function render_filter_type_options($selected = '') {
        $scan_results = get_option('olimc_scan_results', []);
        $found_exts = [];
        foreach ($scan_results as $item) {
            $ext = strtolower($item['ext'] ?? '');
            if ($ext && !isset($found_exts[$ext])) $found_exts[$ext] = strtoupper($ext);
        }
        ksort($found_exts);

        $groups = [
            __('Images', 'oli-media-cleaner')    => ['jpg','jpeg','png','gif','webp','svg','ico','bmp','tiff','heic'],
            __('Documents', 'oli-media-cleaner') => ['pdf','doc','docx','xls','xlsx','csv','ppt','pptx','txt','zip','rar'],
            __('Video', 'oli-media-cleaner')     => ['mp4','mov','avi','webm','wmv','mkv'],
            __('Audio', 'oli-media-cleaner')     => ['mp3','wav','ogg','flac','aac'],
        ];

        $selected = strtolower((string) $selected);

        $render_option = function ($ext, $display) use ($selected) {
            return '<option value="' . esc_attr($ext) . '"' . selected($selected, $ext, false) . '>' . esc_html($display) . '</option>';
        };

        $html  = '<option value="">' . esc_html__('All Types', 'oli-media-cleaner') . '</option>';

        foreach ($groups as $label => $exts) {
            $group_items = array_intersect_key($found_exts, array_flip($exts));
            if (empty($group_items)) continue;
            $html .= '<optgroup label="' . esc_attr($label) . '">';
            foreach ($group_items as $ext => $display) {
                $html .= $render_option($ext, $display);
            }
            $html .= '</optgroup>';
        }

        // Any extensions not in a known group.
        $known = array_merge(...array_values($groups));
        $other = array_diff_key($found_exts, array_flip($known));
        if (!empty($other)) {
            $html .= '<optgroup label="' . esc_attr__('Other', 'oli-media-cleaner') . '">';
            foreach ($other as $ext => $display) {
                $html .= $render_option($ext, $display);
            }
            $html .= '</optgroup>';
        }

        return $html;
    }

    /**
     * Collect, filter and sort the items for a tab. Shared by the table renderer
     * and the CSV export so both reflect exactly the same filtered dataset.
     *
     * @return array List of attachment info arrays.
     */
    private static function get_filtered_items($tab, $search = '', $orderby = 'date', $order = 'desc', $filter_type = '') {
        $scanner = new OLIMC_Scanner();
        $all_items = [];

        if ($tab === 'unused') {
            $scan_results = get_option('olimc_scan_results', []);
            $whitelist = get_option('olimc_whitelist', []);
            $scan_results = array_filter($scan_results, function($item) use ($whitelist) {
                return !in_array((int)$item['id'], $whitelist, true);
            });
            $all_items = array_values($scan_results);

        } elseif ($tab === 'whitelist') {
            $whitelist = get_option('olimc_whitelist', []);
            foreach ($whitelist as $id) {
                $info = $scanner->get_attachment_info($id);
                if ($info) $all_items[] = $info;
            }

        } elseif ($tab === 'trash') {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'trash' ORDER BY ID ASC"
            );
            foreach ($rows as $row) {
                $info = $scanner->get_attachment_info($row->ID);
                if ($info) $all_items[] = $info;
            }
        }

        // Filter by search
        if ($search) {
            $search_lower = strtolower($search);
            $all_items = array_filter($all_items, function($item) use ($search_lower) {
                return strpos(strtolower($item['title'] ?? ''), $search_lower) !== false
                    || strpos(strtolower($item['url'] ?? ''), $search_lower) !== false
                    || strpos(strtolower($item['ext'] ?? ''), $search_lower) !== false
                    || strpos((string)($item['id'] ?? ''), $search_lower) !== false;
            });
        }

        // Filter by file type
        if ($filter_type) {
            $filter_lower = strtolower($filter_type);
            $all_items = array_filter($all_items, function($item) use ($filter_lower) {
                return strtolower($item['ext'] ?? '') === $filter_lower;
            });
        }

        $all_items = array_values($all_items);

        // Sort
        if ($orderby && !empty($all_items)) {
            usort($all_items, function($a, $b) use ($orderby, $order) {
                switch ($orderby) {
                    case 'name':
                        $cmp = strcasecmp($a['title'] ?? '', $b['title'] ?? '');
                        break;
                    case 'size':
                        $cmp = ((int)($a['file_size'] ?? 0)) - ((int)($b['file_size'] ?? 0));
                        break;
                    case 'type':
                        $cmp = strcasecmp($a['ext'] ?? '', $b['ext'] ?? '');
                        break;
                    case 'date':
                    default:
                        $cmp = strcmp($a['date'] ?? '', $b['date'] ?? '');
                        break;
                }
                return $order === 'asc' ? $cmp : -$cmp;
            });
        }

        return $all_items;
    }

    private static function render_results_table($tab, $page = 1, $per_page = 20, $search = '', $orderby = 'date', $order = 'desc', $filter_type = '') {
        $all_items = self::get_filtered_items($tab, $search, $orderby, $order, $filter_type);

        $total_items = count($all_items);
        $items = array_slice($all_items, ($page - 1) * $per_page, $per_page);
        $total_pages = ceil($total_items / $per_page);

        if (empty($items)) {
            echo '<p class="description">';
            if ($tab === 'unused') {
                esc_html_e('No unused media found. Run a scan to detect unused files.', 'oli-media-cleaner');
            } elseif ($tab === 'whitelist') {
                esc_html_e('No whitelisted items.', 'oli-media-cleaner');
            } else {
                esc_html_e('Trash is empty.', 'oli-media-cleaner');
            }
            echo '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th class="check-column"><input type="checkbox" class="olimc-select-all-header"></th>';
        echo '<th>' . esc_html__('File', 'oli-media-cleaner') . '</th>';
        echo '<th class="olimc-sortable" data-sort="name" style="cursor:pointer;">' . esc_html__('Name', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('name', $orderby, $order) ) . '</th>';
        echo '<th class="olimc-sortable" data-sort="size" style="cursor:pointer;">' . esc_html__('Size', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('size', $orderby, $order) ) . '</th>';
        echo '<th class="olimc-sortable" data-sort="type" style="cursor:pointer;">' . esc_html__('Type', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('type', $orderby, $order) ) . '</th>';
        echo '<th class="olimc-sortable" data-sort="date" style="cursor:pointer;">' . esc_html__('Date', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('date', $orderby, $order) ) . '</th>';
        echo '<th>' . esc_html__('Actions', 'oli-media-cleaner') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $id = (int) $item['id'];
            $size_formatted = size_format($item['file_size']);
            $date_formatted = date_i18n('Y/m/d', strtotime($item['date']));
            $thumb = self::get_thumb_html($item);
            $edit_url = admin_url("post.php?post={$id}&action=edit");

            $basename = wp_basename( $item['url'] ?? '' );
            echo '<tr data-id="' . esc_attr( $id ) . '" data-size="' . esc_attr( $item['file_size'] ) . '" data-filename="' . esc_attr( $basename ) . '">';
            echo '<th class="check-column"><input type="checkbox" class="olimc-item-cb" value="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '"></th>';
            echo '<td>' . wp_kses_post( $thumb ) . '</td>';
            echo '<td><strong>' . esc_html($item['title']) . '</strong><br><span class="description">' . esc_html(wp_basename($item['url'] ?? '')) . '</span></td>';
            echo '<td>' . esc_html($size_formatted) . '</td>';
            echo '<td><code>' . esc_html(strtoupper($item['ext'])) . '</code></td>';
            echo '<td>' . esc_html($date_formatted) . '</td>';
            echo '<td>';

            if ($tab === 'unused') {
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" class="button button-small">' . esc_html__('View', 'oli-media-cleaner') . '</a> ';
                echo '<a href="' . esc_url($edit_url) . '" target="_blank" class="button button-small">' . esc_html__('Edit', 'oli-media-cleaner') . '</a> ';
                echo '<button type="button" class="button button-small olimc-whitelist-btn" data-id="' . esc_attr($id) . '">' . esc_html__('Whitelist', 'oli-media-cleaner') . '</button> ';
                echo '<button type="button" class="button button-small olimc-trash-btn" data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '">' . esc_html__('Trash', 'oli-media-cleaner') . '</button>';
            } elseif ($tab === 'whitelist') {
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" class="button button-small">' . esc_html__('View', 'oli-media-cleaner') . '</a> ';
                echo '<button type="button" class="button button-small olimc-remove-whitelist-btn" data-id="' . esc_attr($id) . '">' . esc_html__('Remove', 'oli-media-cleaner') . '</button>';
            } elseif ($tab === 'trash') {
                echo '<button type="button" class="button button-small olimc-restore-btn" data-id="' . esc_attr($id) . '">' . esc_html__('Restore', 'oli-media-cleaner') . '</button> ';
                echo '<button type="button" class="button button-small olimc-delete-btn" data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '" style="color:#b32d2e;">' . esc_html__('Delete', 'oli-media-cleaner') . '</button>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div id="olimc-pag-data" data-total-pages="' . (int) $total_pages . '" data-current-page="' . (int) $page . '" data-total-items="' . (int) $total_items . '" style="display:none;"></div>';
    }

    private static function sort_indicator($col, $orderby, $order) {
        if ($col !== $orderby) {
            return ' <span style="color:#c3c4c7;">&#x25B5;&#x25BF;</span>';
        }
        $arrow = $order === 'asc' ? '&#x25B4;' : '&#x25BE;';
        return ' <span>' . $arrow . '</span>';
    }

    private static function get_thumb_html($item) {
        $mime = $item['mime'] ?? '';
        if (strpos($mime, 'image') !== false && !empty($item['thumb_url'])) {
            return '<img src="' . esc_url($item['thumb_url']) . '" alt="" width="40" height="40" style="object-fit:cover;">';
        }
        return wp_get_attachment_image($item['id'], [40, 40]) ?: '<span class="dashicons dashicons-media-default" style="font-size:20px;color:#8c8f94;"></span>';
    }

    // ─── AJAX handlers ────────────────────────────────────────────────

    private static function verify_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'oli-media-cleaner'));
        }
        if (!check_ajax_referer('olimc_nonce', 'nonce', false)) {
            wp_send_json_error(__('Invalid nonce.', 'oli-media-cleaner'));
        }
    }

    /**
     * Step 1: Start a resumable scan. Does not decide unused media yet.
     */
    public static function ajax_start_scan() {
        self::verify_request();

        if (!OLIMC_Acf_Meta_Scanner::acquire_lock('ajax')) {
            wp_send_json_error(__('Another scan is already running. No media was marked unused.', 'oli-media-cleaner'));
        }

        // Drop the legacy shared blob. Leave the cron owner's state alone.
        delete_option('olimc_scan_state');
        self::clear_owner_scan('ajax');

        $scanner = new OLIMC_Scanner();
        $engine  = new OLIMC_Acf_Meta_Scanner($scanner);
        $state   = $engine->initial_state();

        // Until the scan finishes cleanly, nothing is unused.
        update_option('olimc_scan_results', [], false);
        delete_option('olimc_scan_used_ids');
        self::persist_scan_progress('ajax', $state);

        if ($state['phase'] === 'failed') {
            self::abort_owner_scan('ajax');
            wp_send_json_error(__('Scan could not start. No media was marked unused.', 'oli-media-cleaner'));
        }

        wp_send_json_success([
            'total'   => $scanner->get_total_attachment_count(),
            'percent' => 1,
            'phase'   => $state['phase'],
            'message' => __('Analyzing media usage…', 'oli-media-cleaner'),
        ]);
    }

    /**
     * Step 2: Continue the resumable scan for a short time budget.
     */
    public static function ajax_scan_batch() {
        self::verify_request();

        if (!OLIMC_Acf_Meta_Scanner::acquire_lock('ajax')) {
            wp_send_json_error(__('Another scan is already running. No media was marked unused.', 'oli-media-cleaner'));
        }

        $state = get_option(OLIMC_Acf_Meta_Scanner::state_option_key('ajax'), []);
        if (!is_array($state) || empty($state['phase'])) {
            self::abort_owner_scan('ajax');
            wp_send_json_error(__('Scan state was lost. No media was marked unused.', 'oli-media-cleaner'));
        }

        $scanner = new OLIMC_Scanner();
        $engine  = new OLIMC_Acf_Meta_Scanner($scanner);
        $state   = $engine->step($state, 1500);
        $total   = $scanner->get_total_attachment_count();

        if ($state['phase'] === 'failed' || !empty($state['error'])) {
            self::abort_owner_scan('ajax');
            wp_send_json_error(__('Scan failed before it finished. No media was marked unused.', 'oli-media-cleaner'));
        }

        self::persist_scan_progress('ajax', $state);
        $done = OLIMC_Acf_Meta_Scanner::may_mark_unused($state);
        if ($done) {
            $pending = get_option(OLIMC_Acf_Meta_Scanner::pending_option_key('ajax'), []);
            if (!is_array($pending)) {
                $pending = [];
            }
            update_option('olimc_scan_results', $pending, false);
            update_option('olimc_scan_date', current_time('mysql'), false);
            self::clear_owner_scan('ajax');
            OLIMC_Acf_Meta_Scanner::release_lock('ajax');
            $unused_found = count($pending);
        } else {
            // Keep the public unused list empty while the scan is still running.
            update_option('olimc_scan_results', [], false);
            $unused_found = 0;
        }

        wp_send_json_success([
            'processed'    => (int) $state['cursor'],
            'total'        => $total,
            'unused_found' => $unused_found,
            'done'         => $done,
            'percent'      => $done ? 100 : $engine->percent($state),
            'phase'        => $state['phase'],
            'message'      => $done
                ? __('Scan complete.', 'oli-media-cleaner')
                : __('Scanning media usage…', 'oli-media-cleaner'),
        ]);
    }

    /**
     * Get paginated results (refreshes table via AJAX).
     */
    public static function ajax_get_results() {
        self::verify_request();

        $tab = sanitize_text_field($_POST['tab'] ?? 'unused');
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $orderby = sanitize_text_field($_POST['orderby'] ?? 'date');
        $order = sanitize_text_field($_POST['order'] ?? 'desc');
        $filter_type = sanitize_text_field($_POST['filter_type'] ?? '');
        $per_page = (int) ($_POST['per_page'] ?? 20);

        if (!in_array($orderby, ['name', 'size', 'type', 'date'], true)) $orderby = 'date';
        if (!in_array($order, ['asc', 'desc'], true)) $order = 'desc';
        if (!in_array($per_page, [20, 50, 100], true)) $per_page = 20;

        ob_start();
        self::render_results_table($tab, $page, $per_page, $search, $orderby, $order, $filter_type);
        $html = ob_get_clean();

        // Extract pagination data from hidden div
        $total_pages = 0;
        $total_items = 0;
        if (preg_match('/data-total-pages="(\d+)"/', $html, $m)) $total_pages = (int) $m[1];
        if (preg_match('/data-total-items="(\d+)"/', $html, $m)) $total_items = (int) $m[1];

        ob_start();
        self::render_stats();
        $stats_html = ob_get_clean();

        wp_send_json_success([
            'html'           => $html,
            'stats'          => $stats_html,
            'filter_options' => self::render_filter_type_options($filter_type),
            'total_pages'    => $total_pages,
            'total_items'    => $total_items,
            'page'           => $page,
            'trash_count'    => (int) wp_count_posts('attachment')->trash,
        ]);
    }

    /**
     * Export filtered items as a downloadable CSV file.
     *
     * Includes a Status column (Unused / Whitelist / Trash). By default exports
     * all three categories so the file can be filtered/categorized in a spreadsheet.
     * Pass tab=unused|whitelist|trash to limit to one category. Search, type filter
     * and sort still apply (all matching rows, not only the current page).
     */
    public static function ajax_export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'oli-media-cleaner'), '', ['response' => 403]);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'olimc_nonce')) {
            wp_die(esc_html__('Invalid nonce.', 'oli-media-cleaner'), '', ['response' => 403]);
        }

        $tab         = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'all';
        $search      = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $orderby     = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'date';
        $order       = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc';
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field(wp_unslash($_GET['filter_type'])) : '';

        if (!in_array($tab, ['all', 'unused', 'whitelist', 'trash'], true)) {
            $tab = 'all';
        }
        if (!in_array($orderby, ['name', 'size', 'type', 'date'], true)) {
            $orderby = 'date';
        }
        if (!in_array($order, ['asc', 'desc'], true)) {
            $order = 'desc';
        }

        $status_labels = [
            'unused'    => __('Unused', 'oli-media-cleaner'),
            'whitelist' => __('Whitelist', 'oli-media-cleaner'),
            'trash'     => __('Trash', 'oli-media-cleaner'),
        ];

        $tabs_to_export = ( 'all' === $tab ) ? [ 'unused', 'whitelist', 'trash' ] : [ $tab ];

        $rows = [];
        foreach ( $tabs_to_export as $export_tab ) {
            // Skip per-tab sort; we re-sort the combined set below.
            $items = self::get_filtered_items( $export_tab, $search, $orderby, $order, $filter_type );
            foreach ( $items as $item ) {
                $item['status'] = $status_labels[ $export_tab ];
                $rows[]         = $item;
            }
        }

        // Re-apply sort across the combined categories.
        if ( $orderby && count( $rows ) > 1 ) {
            usort(
                $rows,
                static function ( $a, $b ) use ( $orderby, $order ) {
                    switch ( $orderby ) {
                        case 'name':
                            $cmp = strcasecmp( $a['title'] ?? '', $b['title'] ?? '' );
                            break;
                        case 'size':
                            $cmp = ( (int) ( $a['file_size'] ?? 0 ) ) - ( (int) ( $b['file_size'] ?? 0 ) );
                            break;
                        case 'type':
                            $cmp = strcasecmp( $a['ext'] ?? '', $b['ext'] ?? '' );
                            break;
                        case 'date':
                        default:
                            $cmp = strcmp( $a['date'] ?? '', $b['date'] ?? '' );
                            break;
                    }
                    return ( 'asc' === $order ) ? $cmp : -$cmp;
                }
            );
        }

        $filename = 'oli-media-cleaner-' . $tab . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );

        // UTF-8 BOM so Excel opens accented characters correctly.
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv(
            $out,
            [
                __( 'ID', 'oli-media-cleaner' ),
                __( 'Status', 'oli-media-cleaner' ),
                __( 'Title', 'oli-media-cleaner' ),
                __( 'Filename', 'oli-media-cleaner' ),
                __( 'URL', 'oli-media-cleaner' ),
                __( 'Type', 'oli-media-cleaner' ),
                __( 'MIME type', 'oli-media-cleaner' ),
                __( 'Size (bytes)', 'oli-media-cleaner' ),
                __( 'Size', 'oli-media-cleaner' ),
                __( 'Date', 'oli-media-cleaner' ),
            ]
        );

        foreach ( $rows as $item ) {
            $bytes = (int) ( $item['file_size'] ?? 0 );
            fputcsv(
                $out,
                [
                    $item['id'] ?? '',
                    $item['status'] ?? '',
                    $item['title'] ?? '',
                    wp_basename( $item['url'] ?? '' ),
                    $item['url'] ?? '',
                    strtoupper( $item['ext'] ?? '' ),
                    $item['mime'] ?? '',
                    $bytes,
                    size_format( $bytes ),
                    $item['date'] ?? '',
                ]
            );
        }

        fclose( $out );
        exit;
    }

    /**
     * Trash a single unused file.
     */
    public static function ajax_trash_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(__('Invalid ID.', 'oli-media-cleaner'));

        if (self::is_attachment_protected($post_id) || self::is_attachment_whitelisted($post_id)) {
            wp_send_json_error(__('This file is protected and cannot be trashed.', 'oli-media-cleaner'));
        }

        if (self::must_keep_attachment_files($post_id)) {
            wp_send_json_error(self::used_media_kept_message(1));
        }

        $result = wp_trash_post($post_id);
        if ($result) {
            self::remove_from_scan_results($post_id);
            wp_send_json_success(['message' => __('File moved to trash.', 'oli-media-cleaner')]);
        }
        wp_send_json_error(__('Could not trash file.', 'oli-media-cleaner'));
    }

    /**
     * Trash multiple files.
     */
    public static function ajax_trash_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $trashed = 0;
        $protected = 0;
        $used_kept = 0;
        foreach ($ids as $id) {
            if (self::is_attachment_protected($id) || self::is_attachment_whitelisted($id)) {
                $protected++;
                continue;
            }
            if (self::must_keep_attachment_files($id)) {
                $used_kept++;
                continue;
            }
            if (wp_trash_post($id)) {
                self::remove_from_scan_results($id);
                $trashed++;
            }
        }

        $message = '';
        if ($trashed > 0) {
            /* translators: %d: number of files */
            $message = sprintf(_n('%d file moved to trash.', '%d files moved to trash.', $trashed, 'oli-media-cleaner'), $trashed);
        }
        if ($protected > 0) {
            /* translators: %d: number of files */
            $msg = sprintf(_n('%d protected file skipped.', '%d protected files skipped.', $protected, 'oli-media-cleaner'), $protected);
            $message = $message ? $message . ' ' . $msg : $msg;
        }
        $message = self::append_used_media_kept_message($message, $used_kept);

        wp_send_json_success([
            'message' => $message ?: __('No files were trashed.', 'oli-media-cleaner'),
            'count'   => $trashed,
        ]);
    }

    /**
     * Whether this attachment is on the plugin whitelist.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function is_attachment_whitelisted($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }
        $whitelist = get_option('olimc_whitelist', []);
        if (!is_array($whitelist)) {
            return false;
        }
        foreach ($whitelist as $id) {
            if ((int) $id === $attachment_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Admin notice when used media were refused for trash or delete.
     *
     * @param int $count Number of attachments kept.
     * @return string
     */
    public static function used_media_kept_message($count) {
        $count = (int) $count;
        if ($count <= 0) {
            return '';
        }
        /* translators: %d: number of media items that are still in use */
        return sprintf(
            _n('%d used media item was kept.', '%d used media items were kept.', $count, 'oli-media-cleaner'),
            $count
        );
    }

    /**
     * Append a used-media refusal notice to an existing admin message.
     *
     * @param string $message Current message.
     * @param int    $count   Number of attachments kept.
     * @return string
     */
    private static function append_used_media_kept_message($message, $count) {
        $extra = self::used_media_kept_message($count);
        if ($extra === '') {
            return $message;
        }
        return $message !== '' ? $message . ' ' . $extra : $extra;
    }

    /**
     * Shared files, unknown index, protected files, or in-use media must not be deleted or trashed.
     *
     * @param int $post_id Attachment ID.
     * @return bool
     */
    public static function must_keep_attachment_files($post_id) {
        $post_id = (int) $post_id;
        $keep = false;

        if ($post_id > 0) {
            // Check protection first (cheapest check)
            if (self::is_attachment_protected($post_id) || self::is_attachment_whitelisted($post_id)) {
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
                    if (!$keep && empty($scan_date) && $in_use !== false) {
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
    public static function pre_delete_attachment($check, $post, $force_delete = false) {
        unset($force_delete);
        if (null !== $check) {
            return $check;
        }
        if (!is_object($post) || (isset($post->post_type) && $post->post_type !== 'attachment')) {
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
    public static function pre_trash_post($check, $post, $previous_status = '') {
        unset($previous_status);
        if (null !== $check) {
            return $check;
        }
        if (!is_object($post) || (isset($post->post_type) && $post->post_type !== 'attachment')) {
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
    public static function preserve_shared_upload_file($file) {
        if (!is_string($file) || $file === '') {
            return $file;
        }
        $verdict = (new OLIMC_Scanner())->absolute_file_is_shared($file);
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
    public static function on_delete_attachment($post_id) {
        if (!self::must_keep_attachment_files($post_id)) {
            return;
        }
        if (!isset($GLOBALS['wp_filter']['delete_attachment'])) {
            return;
        }
        $hook = $GLOBALS['wp_filter']['delete_attachment'];
        $callbacks = (is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)) ? $hook->callbacks : null;
        if (!is_array($callbacks)) {
            return;
        }
        foreach ($callbacks as $priority => $group) {
            if ((int) $priority <= 0 || !is_array($group)) {
                continue;
            }
            foreach ($group as $callback) {
                if (is_array($callback) && isset($callback['function'])) {
                    remove_action('delete_attachment', $callback['function'], (int) $priority);
                }
            }
        }
    }

    /**
     * Permanently delete one attachment. Shared files are refused by pre_delete_attachment.
     *
     * @param int $post_id Attachment ID.
     * @return mixed Result of wp_delete_attachment().
     */
    private static function delete_attachment_preserve_shared_files($post_id) {
        return wp_delete_attachment((int) $post_id, true);
    }

    /**
     * Permanently delete a single file.
     */
    public static function ajax_delete_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(__('Invalid ID.', 'oli-media-cleaner'));

        if (self::is_attachment_protected($post_id) || self::is_attachment_whitelisted($post_id)) {
            wp_send_json_error(__('This file is protected and cannot be deleted.', 'oli-media-cleaner'));
        }

        if (self::must_keep_attachment_files($post_id)) {
            wp_send_json_error(self::used_media_kept_message(1));
        }

        $result = self::delete_attachment_preserve_shared_files($post_id);
        if ($result) {
            self::remove_from_scan_results($post_id);
            wp_send_json_success(['message' => __('File permanently deleted.', 'oli-media-cleaner')]);
        }
        wp_send_json_error(__('Could not delete file.', 'oli-media-cleaner'));
    }

    /**
     * Permanently delete multiple files.
     */
    public static function ajax_delete_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $deleted = 0;
        $protected = 0;
        $used_kept = 0;
        foreach ($ids as $id) {
            if (self::is_attachment_protected($id) || self::is_attachment_whitelisted($id)) {
                $protected++;
                continue;
            }
            if (self::must_keep_attachment_files($id)) {
                $used_kept++;
                continue;
            }
            if (self::delete_attachment_preserve_shared_files($id)) {
                self::remove_from_scan_results($id);
                $deleted++;
            }
        }

        $message = '';
        if ($deleted > 0) {
            /* translators: %d: number of files */
            $message = sprintf(_n('%d file permanently deleted.', '%d files permanently deleted.', $deleted, 'oli-media-cleaner'), $deleted);
        }
        if ($protected > 0) {
            /* translators: %d: number of files */
            $msg = sprintf(_n('%d protected file skipped.', '%d protected files skipped.', $protected, 'oli-media-cleaner'), $protected);
            $message = $message ? $message . ' ' . $msg : $msg;
        }
        $message = self::append_used_media_kept_message($message, $used_kept);

        wp_send_json_success([
            'message' => $message ?: __('No files were deleted.', 'oli-media-cleaner'),
            'count'   => $deleted,
        ]);
    }

    /**
     * Add to whitelist.
     */
    public static function ajax_whitelist_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(__('Invalid ID.', 'oli-media-cleaner'));

        $whitelist = get_option('olimc_whitelist', []);
        if (!in_array($post_id, $whitelist, true)) {
            $whitelist[] = $post_id;
            update_option('olimc_whitelist', $whitelist, false);
        }

        wp_send_json_success(['message' => __('Added to whitelist.', 'oli-media-cleaner')]);
    }

    /**
     * Add multiple to whitelist.
     */
    public static function ajax_whitelist_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $whitelist = get_option('olimc_whitelist', []);
        $added = 0;

        foreach ($ids as $id) {
            if ($id > 0 && !in_array($id, $whitelist, true)) {
                $whitelist[] = $id;
                $added++;
            }
        }
        update_option('olimc_whitelist', $whitelist, false);

        wp_send_json_success([
            /* translators: %d: number of items added */
            /* translators: %d: number of items */
            'message' => sprintf(_n('%d item added to whitelist.', '%d items added to whitelist.', $added, 'oli-media-cleaner'), $added),
            'count'   => $added,
        ]);
    }

    /**
     * Remove from whitelist.
     */
    public static function ajax_remove_whitelist() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(__('Invalid ID.', 'oli-media-cleaner'));

        $whitelist = get_option('olimc_whitelist', []);
        $whitelist = array_values(array_diff($whitelist, [$post_id]));
        update_option('olimc_whitelist', $whitelist, false);

        wp_send_json_success(['message' => __('Removed from whitelist.', 'oli-media-cleaner')]);
    }

    /**
     * Remove multiple from whitelist.
     */
    public static function ajax_remove_whitelist_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $whitelist = get_option('olimc_whitelist', []);
        $whitelist = array_values(array_diff($whitelist, $ids));
        update_option('olimc_whitelist', $whitelist, false);

        wp_send_json_success([
            /* translators: %d: number of items removed */
            /* translators: %d: number of items */
            'message' => sprintf(_n('%d item removed from whitelist.', '%d items removed from whitelist.', count($ids), 'oli-media-cleaner'), count($ids)),
            'count'   => count($ids),
        ]);
    }

    /**
     * Remove every item from the whitelist.
     */
    public static function ajax_remove_whitelist_all() {
        self::verify_request();

        $whitelist = get_option('olimc_whitelist', []);
        $count     = is_array($whitelist) ? count($whitelist) : 0;
        update_option('olimc_whitelist', [], false);

        wp_send_json_success([
            /* translators: %d: number of items removed */
            'message' => sprintf(_n('%d item removed from whitelist.', '%d items removed from whitelist.', $count, 'oli-media-cleaner'), $count),
            'count'   => $count,
        ]);
    }

    /**
     * Restore from trash.
     */
    public static function ajax_restore_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(__('Invalid ID.', 'oli-media-cleaner'));

        $result = wp_untrash_post($post_id);
        if ($result) {
            wp_send_json_success(['message' => __('File restored.', 'oli-media-cleaner')]);
        }
        wp_send_json_error(__('Could not restore file.', 'oli-media-cleaner'));
    }

    /**
     * Restore multiple from trash.
     */
    public static function ajax_restore_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $restored = 0;
        foreach ($ids as $id) {
            if (wp_untrash_post($id)) {
                $restored++;
            }
        }

        wp_send_json_success([
            /* translators: %d: number of files restored */
            /* translators: %d: number of files */
            'message' => sprintf(_n('%d file restored.', '%d files restored.', $restored, 'oli-media-cleaner'), $restored),
            'count'   => $restored,
        ]);
    }

    /**
     * Trash all unused in batches (AJAX).
     */
    public static function ajax_trash_all_batch() {
        self::verify_request();

        $batch_size = 50;
        $scan_results = get_option('olimc_scan_results', []);
        $whitelist = get_option('olimc_whitelist', []);

        // Filter out whitelisted
        $scan_results = array_filter($scan_results, function($item) use ($whitelist) {
            return !in_array((int)$item['id'], $whitelist, true);
        });
        $scan_results = array_values($scan_results);

        $total = count($scan_results);
        $batch = array_slice($scan_results, 0, $batch_size);
        $trashed = 0;
        $protected = 0;
        $used_kept = 0;

        foreach ($batch as $item) {
            $id = (int)$item['id'];
            if (self::is_attachment_protected($id) || self::is_attachment_whitelisted($id)) {
                // Skip protected, but still remove from results so we don't try again
                self::remove_from_scan_results($id);
                $protected++;
                continue;
            }
            if (self::must_keep_attachment_files($id)) {
                self::remove_from_scan_results($id);
                $used_kept++;
                continue;
            }
            if (wp_trash_post($id)) {
                self::remove_from_scan_results($id);
                $trashed++;
            }
        }

        // Remaining count excludes protected / kept items we just removed
        $remaining = $total - $trashed - $protected - $used_kept;

        $message = '';
        if ($protected > 0) {
            /* translators: %d: number of files */
            $message = sprintf(_n('%d protected file skipped.', '%d protected files skipped.', $protected, 'oli-media-cleaner'), $protected);
        }
        $message = self::append_used_media_kept_message($message, $used_kept);

        wp_send_json_success([
            'trashed'   => $trashed,
            'remaining' => max(0, $remaining),
            'total'     => $total,
            'done'      => $remaining <= 0,
            'message'   => $message,
        ]);
    }

    /**
     * Empty trash: permanently delete trashed attachments in batches.
     * 
     * Excludes protected items in the query itself and paginates by ID to ensure progress.
     */
    public static function ajax_empty_trash_batch() {
        self::verify_request();

        global $wpdb;
        $batch_size = 50;
        $last_id = isset($_POST['last_id']) ? (int) $_POST['last_id'] : 0;

        // Get protected IDs (meta-based protection) - ONLY from trash
        $protected_meta_ids = $wpdb->get_col(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_olimc_protected'
             AND pm.meta_value = '1'
             AND p.post_type = 'attachment'
             AND p.post_status = 'trash'"
        );

        // Get protected IDs (path-based protection) - ONLY from trash
        $protected_path_ids = [];
        $protected_paths = get_option('olimc_protected_paths', '');
        if (!empty($protected_paths) && is_string($protected_paths)) {
            $upload_dir = wp_upload_dir(null, false);
            $base_url = isset($upload_dir['baseurl']) ? $upload_dir['baseurl'] : '';
            
            // Get all trashed attachment files (already filtered to trash status)
            $trashed_files = $wpdb->get_results(
                "SELECT p.ID as post_id, pm.meta_value as file_path
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'attachment'
                 AND p.post_status = 'trash'
                 AND pm.meta_key = '_wp_attached_file'"
            );
            
            $lines = array_filter(array_map('trim', explode("\n", $protected_paths)));
            $patterns = [];
            foreach ($lines as $pattern) {
                if (!empty($pattern)) {
                    $patterns[] = self::normalize_protected_pattern($pattern, $base_url);
                }
            }
            
            foreach ($trashed_files as $row) {
                foreach ($patterns as $pattern) {
                    if (self::path_matches_pattern($row->file_path, $pattern)) {
                        $protected_path_ids[] = (int) $row->post_id;
                        break;
                    }
                }
            }
        }

        // Combine all protected IDs
        $all_protected_ids = array_unique(array_merge($protected_meta_ids, $protected_path_ids));
        
        // Build exclusion clause
        $exclude_clause = '';
        if (!empty($all_protected_ids)) {
            $protected_ids_str = implode(',', array_map('intval', $all_protected_ids));
            $exclude_clause = " AND ID NOT IN ({$protected_ids_str})";
        }

        // Paginate by ID to ensure progress
        $id_clause = $last_id > 0 ? " AND ID > {$last_id}" : '';

        // Fetch next batch of deletable items
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $trashed_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status = 'trash'
             {$exclude_clause}
             {$id_clause}
             ORDER BY ID ASC
             LIMIT %d",
            $batch_size
        ) );

        // Count only deletable items (excluding all protected) - from trash only
        $total_trashed = (int) wp_count_posts('attachment')->trash;
        $protected_count = count($all_protected_ids);
        $deletable_count = $total_trashed - $protected_count;
        
        $deleted = 0;
        $used_kept = 0;
        $new_last_id = $last_id;

        foreach ($trashed_ids as $id) {
            $id = (int) $id;
            if (self::must_keep_attachment_files($id)) {
                $used_kept++;
            } elseif (self::delete_attachment_preserve_shared_files($id)) {
                $deleted++;
            }
            $new_last_id = max($new_last_id, $id);
        }

        // Remaining counts only deletable items
        $remaining = max(0, $deletable_count - $deleted);

        // Done when query returns no IDs (we've paginated past everything)
        $done = empty($trashed_ids);
        
        $message = '';
        if ($done && $protected_count > 0) {
            /* translators: %d: number of files */
            $message = sprintf(
                _n('%d protected file in trash was not deleted.', '%d protected files in trash were not deleted.', $protected_count, 'oli-media-cleaner'),
                $protected_count
            );
        }
        $message = self::append_used_media_kept_message($message, $used_kept);

        wp_send_json_success([
            'deleted'   => $deleted,
            'remaining' => $remaining,
            'total'     => $deletable_count,
            'done'      => $done,
            'message'   => $message,
            'last_id'   => $new_last_id,
        ]);
    }

    /**
     * Restore all trashed attachments in batches (AJAX).
     */
    public static function ajax_restore_all_batch() {
        self::verify_request();

        global $wpdb;
        $batch_size = 50;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $trashed_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status = 'trash'
             ORDER BY ID ASC
             LIMIT %d",
            $batch_size
        ) );

        $total_trashed = (int) wp_count_posts('attachment')->trash;
        $restored = 0;

        foreach ($trashed_ids as $id) {
            if (wp_untrash_post((int) $id)) {
                $restored++;
            }
        }

        $remaining = max(0, $total_trashed - $restored);

        // Guard against an infinite loop if an item refuses to leave the trash.
        $done = $remaining <= 0 || ($restored === 0 && !empty($trashed_ids));

        wp_send_json_success([
            'restored'  => $restored,
            'remaining' => $remaining,
            'total'     => $total_trashed,
            'done'      => $done,
        ]);
    }

    /**
     * Save cron settings.
     */
    public static function ajax_save_cron_settings() {
        self::verify_request();

        $enabled = !empty($_POST['enabled']);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');

        if (!in_array($frequency, ['daily', 'twicedaily', 'weekly'], true)) {
            $frequency = 'daily';
        }

        update_option('olimc_cron_enabled', $enabled, false);
        update_option('olimc_cron_frequency', $frequency, false);

        // Clear existing schedule
        wp_clear_scheduled_hook('olimc_scheduled_cleanup');

        $next_run = '';
        if ($enabled) {
            wp_schedule_event(time() + 60, $frequency, 'olimc_scheduled_cleanup');
            $next_run = date_i18n('M j, Y g:i a', wp_next_scheduled('olimc_scheduled_cleanup'));
        }

        $frequency_labels = [
            'daily'      => __('Daily', 'oli-media-cleaner'),
            'twicedaily' => __('Twice Daily', 'oli-media-cleaner'),
            'weekly'     => __('Weekly', 'oli-media-cleaner'),
        ];
        $frequency_label = $frequency_labels[$frequency] ?? $frequency;

        wp_send_json_success([
            'message'  => $enabled
                /* translators: %1$s: frequency label, %2$s: next run date */
                ? sprintf(__('Scheduled scan enabled (%1$s). Next run: %2$s', 'oli-media-cleaner'), $frequency_label, $next_run)
                : __('Scheduled scan disabled.', 'oli-media-cleaner'),
            'next_run' => $next_run ?: __('Not scheduled', 'oli-media-cleaner'),
        ]);
    }

    /**
     * Save protection settings and return match counts with warnings.
     */
    public static function ajax_save_protection_settings() {
        self::verify_request();

        $protected_paths = isset($_POST['protected_paths']) ? sanitize_textarea_field(wp_unslash($_POST['protected_paths'])) : '';
        update_option('olimc_protected_paths', $protected_paths, false);

        // Calculate match counts for feedback
        $warnings = [];
        if (!empty($protected_paths)) {
            global $wpdb;
            $upload_dir = wp_upload_dir(null, false);
            $base_url = isset($upload_dir['baseurl']) ? $upload_dir['baseurl'] : '';

            // Get all attachment files
            $files = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'"
            );

            $lines = array_filter(array_map('trim', explode("\n", $protected_paths)));
            foreach ($lines as $pattern) {
                if (empty($pattern)) {
                    continue;
                }
                
                $normalized = self::normalize_protected_pattern($pattern, $base_url);
                if (empty($normalized)) {
                    continue;
                }

                $match_count = 0;
                foreach ($files as $row) {
                    if (self::path_matches_pattern($row->meta_value, $normalized)) {
                        $match_count++;
                    }
                }

                // Always include patterns, even with 0 matches (possible typo)
                $warning_level = '';
                if ($match_count === 0) {
                    $warning_level = 'no-match';
                } elseif ($match_count > 100) {
                    $warning_level = 'very-broad';
                } elseif ($match_count > 20) {
                    $warning_level = 'broad';
                }

                $warnings[] = [
                    'pattern' => $pattern,
                    'count' => $match_count,
                    'level' => $warning_level,
                ];
            }
        }

        $message = __('Protection settings saved.', 'oli-media-cleaner');
        
        wp_send_json_success([
            'message' => $message,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Cron callback: resume the scan only. Never trashes or deletes media.
     */
    public static function run_scheduled_cleanup() {
        if (!OLIMC_Acf_Meta_Scanner::acquire_lock('cron')) {
            self::reschedule_scan_soon();
            return;
        }

        $state_key = OLIMC_Acf_Meta_Scanner::state_option_key('cron');
        $state     = get_option($state_key, []);
        $scanner   = new OLIMC_Scanner();
        $engine    = new OLIMC_Acf_Meta_Scanner($scanner);

        if (!is_array($state) || empty($state['phase']) || $state['phase'] === 'done' || $state['phase'] === 'failed') {
            delete_option('olimc_scan_state');
            self::clear_owner_scan('cron');
            $state = $engine->initial_state();
            update_option('olimc_scan_results', [], false);
        }

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && !in_array($state['phase'], ['done', 'failed'], true)) {
            $state = $engine->step($state, 2000);
            self::persist_scan_progress('cron', $state);
        }

        if ($state['phase'] === 'failed' || !empty($state['error'])) {
            self::abort_owner_scan('cron');
            return;
        }

        if (!OLIMC_Acf_Meta_Scanner::may_mark_unused($state)) {
            self::persist_scan_progress('cron', $state);
            self::reschedule_scan_soon();
            return;
        }

        $pending = get_option(OLIMC_Acf_Meta_Scanner::pending_option_key('cron'), []);
        if (!is_array($pending)) {
            $pending = [];
        }
        update_option('olimc_scan_results', $pending, false);
        update_option('olimc_scan_date', current_time('mysql'), false);
        self::clear_owner_scan('cron');
        OLIMC_Acf_Meta_Scanner::release_lock('cron');
    }

    /**
     * Merge this step's new unused rows into the pending option, then save progress without them.
     *
     * @param string $owner ajax|cron.
     * @param array  $state Scan state. pending_delta is removed before the option is written.
     */
    private static function persist_scan_progress($owner, array &$state) {
        $delta = isset($state['pending_delta']) && is_array($state['pending_delta']) ? $state['pending_delta'] : [];
        unset($state['pending_delta'], $state['pending_unused']);
        if ($delta !== []) {
            $pending_key = OLIMC_Acf_Meta_Scanner::pending_option_key($owner);
            $pending     = get_option($pending_key, []);
            if (!is_array($pending)) {
                $pending = [];
            }
            update_option($pending_key, array_merge($pending, $delta), false);
        }
        update_option(OLIMC_Acf_Meta_Scanner::state_option_key($owner), $state, false);
        OLIMC_Acf_Meta_Scanner::touch_lock($owner);
    }

    /**
     * @param string $owner ajax|cron.
     */
    private static function clear_owner_scan($owner) {
        delete_option(OLIMC_Acf_Meta_Scanner::state_option_key($owner));
        delete_option(OLIMC_Acf_Meta_Scanner::pending_option_key($owner));
    }

    /**
     * Failed or abandoned scan: publish nothing, and only clear this owner's options.
     *
     * @param string $owner ajax|cron.
     */
    private static function abort_owner_scan($owner) {
        update_option('olimc_scan_results', [], false);
        self::clear_owner_scan($owner);
        OLIMC_Acf_Meta_Scanner::release_lock($owner);
    }

    /**
     * Ask cron to continue soon, without replacing a run that is already due shortly.
     */
    private static function reschedule_scan_soon() {
        $next = wp_next_scheduled('olimc_scheduled_cleanup');
        if (!$next || $next > time() + 120) {
            wp_schedule_single_event(time() + 30, 'olimc_scheduled_cleanup');
        }
    }

    /**
     * Legacy shared state plus the per-owner progress, pending list, and lock.
     */
    private static function delete_scan_runtime_options() {
        delete_option('olimc_scan_state');
        delete_option(OLIMC_Acf_Meta_Scanner::state_option_key('ajax'));
        delete_option(OLIMC_Acf_Meta_Scanner::state_option_key('cron'));
        delete_option(OLIMC_Acf_Meta_Scanner::pending_option_key('ajax'));
        delete_option(OLIMC_Acf_Meta_Scanner::pending_option_key('cron'));
        delete_option(OLIMC_Acf_Meta_Scanner::LOCK_OPTION);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private static function remove_from_scan_results($post_id) {
        $results = get_option('olimc_scan_results', []);
        $results = array_filter($results, function($item) use ($post_id) {
            return (int) $item['id'] !== (int) $post_id;
        });
        update_option('olimc_scan_results', array_values($results), false);
    }
}
