<?php
defined('ABSPATH') || exit;

class OLIMC_Scanner {

    private $used_ids = [];
    private $path_cache = [];
    /** @var array<int, true>|null */
    private $attachment_id_index = null;
    /** @var string[] Field keys (field_xxx) for image / file / gallery, filled while walking ACF definitions. */
    private $acf_media_field_key_buffer = [];
    /** @var int|null Attachment ID being checked by is_attachment_in_use_now(). */
    private $usage_check_id = null;
    /** @var bool */
    private $usage_check_hit = false;
    /** @var bool */
    private $usage_check_failed = false;
    /** @var float */
    private $usage_check_deadline = 0.0;

    /**
     * Run a full scan. Returns array of unused attachment IDs.
     * Works in batches via AJAX to avoid timeouts.
     */
    public function get_all_attachment_ids() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status != 'trash'
             ORDER BY ID ASC"
        );
    }

    /**
     * Get total attachment count.
     */
    public function get_total_attachment_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status != 'trash'"
        );
    }

    /**
     * Collect all used attachment IDs from every source we know about.
     */
    public function collect_used_ids() {
        $this->used_ids = [];
        $this->ensure_attachment_lookup();

        // Mark protected items as used so they're never flagged as unused
        $this->collect_protected_attachments();

        // AJAX and cron must call OLIMC_Acf_Meta_Scanner::step() instead. This
        // synchronous helper stops before max_execution_time on web requests.
        $engine   = new OLIMC_Acf_Meta_Scanner($this);
        $state    = $engine->initial_state();
        $deadline = $this->synchronous_scan_deadline();
        $guard    = 0;
        while (!in_array($state['phase'], ['done', 'failed'], true) && $guard < 1000000) {
            if (microtime(true) >= $deadline) {
                $state['phase'] = 'failed';
                $state['error'] = 'Scan stopped before the PHP time limit.';
                break;
            }
            $remaining_ms = (int) max(200, ($deadline - microtime(true)) * 1000);
            $state        = $engine->step($state, min(2000, $remaining_ms));
            $guard++;
        }

        if (!OLIMC_Acf_Meta_Scanner::may_mark_unused($state)) {
            // Incomplete or failed scan: every known attachment stays "used".
            $ids = $this->get_all_attachment_ids();
            if (empty($ids) && is_array($this->attachment_id_index)) {
                $ids = array_keys($this->attachment_id_index);
            }
            $this->used_ids = array_map('intval', $ids);
            return $this->used_ids;
        }

        $this->used_ids = array_map('intval', array_keys($state['used']));
        return $this->used_ids;
    }

    /**
     * Wall clock after which a synchronous scan must stop and fail safe.
     *
     * Web SAPIs honour max_execution_time (minus a few seconds). CLI has no
     * request limit; the cap only prevents a runaway loop.
     *
     * @return float
     */
    private function synchronous_scan_deadline() {
        $max = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;
        $cli = function_exists('php_sapi_name') && php_sapi_name() === 'cli';
        if ($cli || $max <= 0) {
            return microtime(true) + 3600;
        }
        return microtime(true) + max(5, $max - 5);
    }

    /**
     * Mark all protected attachments as used.
     */
    private function collect_protected_attachments() {
        foreach ($this->get_protected_attachment_ids() as $id) {
            $this->used_ids[] = $id;
        }
    }

    /**
     * Get all protected attachment IDs (from meta and paths).
     *
     * @return int[]
     */
    public function get_protected_attachment_ids() {
        global $wpdb;
        $protected = [];

        // Collect IDs with _olimc_protected meta
        $protected_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_olimc_protected'
             AND meta_value = '1'"
        );

        if (!empty($protected_ids)) {
            $protected = array_merge($protected, array_map('intval', $protected_ids));
        }

        // Collect IDs matching protected paths (option + olimc_protected_paths filter)
        $protected_paths = class_exists('OLIMC_Admin')
            ? OLIMC_Admin::get_protected_path_patterns()
            : [];
        if ($protected_paths === []) {
            return $protected;
        }

        $upload_dir = wp_upload_dir(null, false);
        $base_url = isset($upload_dir['baseurl']) ? $upload_dir['baseurl'] : '';

        // Get all attachment files
        $files = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'"
        );

        $patterns = [];
        foreach ($protected_paths as $pattern) {
            $normalized = $this->normalize_protected_pattern($pattern, $base_url);
            if ($normalized !== '') {
                $patterns[] = $normalized;
            }
        }

        foreach ($files as $row) {
            $file = $row->meta_value;
            foreach ($patterns as $pattern) {
                if (OLIMC_Admin::path_matches_pattern($file, $pattern)) {
                    $protected[] = (int) $row->post_id;
                    break;
                }
            }
        }

        return array_unique($protected);
    }

    /**
     * Normalize a protected pattern to uploads-relative path.
     *
     * @param string $pattern  Raw pattern (path, URL, or glob).
     * @param string $base_url Upload base URL.
     * @return string Normalized pattern.
     */
    private function normalize_protected_pattern($pattern, $base_url) {
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
     * Sources that are not the ACF meta walk (content, WooCommerce, theme files, …).
     */
    public function collect_auxiliary_sources() {
        $this->collect_featured_images();
        $this->collect_post_content_images();
        $this->collect_option_images();
        $this->collect_woocommerce_gallery();
        $this->collect_woocommerce_variations();
        $this->collect_site_identity();
        $this->collect_widget_images();
        $this->collect_elementor_images();
        $this->collect_taxonomy_thumbnails();
        $this->collect_term_description_images();
        $this->collect_theme_files();
        $this->collect_css_background_images();
    }

    /**
     * Load ACF, including local JSON and PHP field groups, then list media fields.
     *
     * Returns null when ACF is inactive or field groups could not be resolved
     * (caller must use the raw-meta fallback). An empty list means ACF is active
     * and simply has no image/file/gallery fields.
     *
     * @return array{names: string[], keys: string[], gallery_names: string[]}|null
     */
    public function get_acf_media_field_targets() {
        $this->acf_media_field_key_buffer = [];
        if (!$this->ensure_acf_loaded()) {
            return null;
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups) || $groups === []) {
            return null;
        }

        $image_keys   = [];
        $file_keys    = [];
        $gallery_keys = [];
        $all_keys     = [];
        $resolved     = false;

        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key'])) {
                continue;
            }
            $fields = function_exists('acf_get_fields') ? acf_get_fields($group['key']) : null;
            if (!is_array($fields)) {
                continue;
            }
            $resolved = true;
            $this->collect_acf_field_keys_recursive($fields, $image_keys, $file_keys, $gallery_keys, $all_keys);
        }

        if (!$resolved) {
            return null;
        }

        return [
            'names'         => array_values(array_unique(array_merge($image_keys, $file_keys, $gallery_keys))),
            'keys'          => array_values(array_unique($this->acf_media_field_key_buffer)),
            'gallery_names' => array_values(array_unique($gallery_keys)),
        ];
    }

    /**
     * Make sure ACF has booted and local JSON / PHP groups are included.
     *
     * Cron and early AJAX used to call acf_get_field_groups() before acf/init,
     * which skips theme JSON (local field groups registered by the active theme).
     *
     * @return bool Whether the ACF API is available.
     */
    private function ensure_acf_loaded() {
        if (!function_exists('acf_get_field_groups')) {
            return false;
        }
        $init_done       = function_exists('did_action') && did_action('init');
        $acf_init_done   = function_exists('did_action') && did_action('acf/init');
        $fields_included = function_exists('did_action') && did_action('acf/include_fields');

        // Local JSON is loaded on acf/include_fields. After WordPress init, do not
        // call acf_init() again (that re-registers hooks). Call include_fields
        // directly when it has not run yet. acf_init() is only for a scan that
        // really is running before init.
        if (!$fields_included && function_exists('acf_include_fields')) {
            if (!$init_done && !$acf_init_done && function_exists('acf_init')) {
                acf_init();
            } else {
                acf_include_fields();
            }
        }
        return true;
    }

    /**
     * IDs recorded by auxiliary collectors since the last drain.
     *
     * @return int[]
     */
    public function drain_used_ids() {
        $ids = $this->used_ids;
        $this->used_ids = [];
        return $ids;
    }

    /**
     * Recursively collect ACF field names by type.
     *
     * Clone fields do not expose the referenced group's fields as sub_fields, so
     * we resolve $field['clone'] (group keys and/or field keys) via the ACF API.
     *
     * @param array      $fields        Field definitions.
     * @param array      $image_keys    Image field names.
     * @param array      $file_keys     File field names.
     * @param array      $gallery_keys  Gallery field names.
     * @param array      $all_keys      Every field name seen.
     * @param array|null $visited_clones Clone selectors already followed.
     */
    private function collect_acf_field_keys_recursive($fields, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys, &$visited_clones = null) {
        if ($visited_clones === null) {
            $visited_clones = [];
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (!empty($field['name'])) {
                $all_keys[] = $field['name'];
            }

            $type = isset($field['type']) ? $field['type'] : '';

            switch ($type) {
                case 'image':
                    $image_keys[] = $field['name'];
                    break;
                case 'file':
                    $file_keys[] = $field['name'];
                    break;
                case 'gallery':
                    $gallery_keys[] = $field['name'];
                    break;
            }

            if (in_array($type, ['image', 'file', 'gallery'], true) && !empty($field['key']) && is_string($field['key'])) {
                $this->acf_media_field_key_buffer[] = $field['key'];
            }

            if (!empty($field['sub_fields'])) {
                $this->collect_acf_field_keys_recursive($field['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
            if (!empty($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (!empty($layout['sub_fields'])) {
                        $this->collect_acf_field_keys_recursive($layout['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
                    }
                }
            }

            if ($type === 'clone' && !empty($field['clone']) && is_array($field['clone'])) {
                $this->collect_acf_cloned_fields($field['clone'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
        }
    }

    /**
     * Follow ACF clone selectors to the referenced field group or field definitions.
     *
     * @param array $clone_selectors Group keys (group_xxx) and/or field keys (field_xxx).
     * @param array $image_keys      Image field names.
     * @param array $file_keys       File field names.
     * @param array $gallery_keys    Gallery field names.
     * @param array $all_keys        Every field name seen.
     * @param array $visited_clones  Clone selectors already followed.
     */
    private function collect_acf_cloned_fields($clone_selectors, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys, &$visited_clones) {
        foreach ($clone_selectors as $clone_selector) {
            if (!is_string($clone_selector) || $clone_selector === '') {
                continue;
            }
            if (isset($visited_clones[$clone_selector])) {
                continue;
            }
            $visited_clones[$clone_selector] = true;

            $cloned_fields = function_exists('acf_get_fields') ? acf_get_fields($clone_selector) : null;
            if (!empty($cloned_fields) && is_array($cloned_fields)) {
                $this->collect_acf_field_keys_recursive($cloned_fields, $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
                continue;
            }

            if (!function_exists('acf_get_field')) {
                continue;
            }
            $cloned_field = acf_get_field($clone_selector);
            if (!empty($cloned_field) && is_array($cloned_field)) {
                $this->collect_acf_field_keys_recursive([$cloned_field], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
        }
    }

    /**
     * Whether a meta key is this ACF field, including repeater / flex / clone prefixes.
     *
     * Matches `images`, `content_blocks_4_images`, and
     * `content_blocks_4_image_carousel_images` (clone with prefix_name).
     *
     * @param string $meta_key   Stored meta key.
     * @param string $field_name ACF field name.
     * @return bool
     */
    public function acf_meta_key_matches_field($meta_key, $field_name) {
        if ($meta_key === $field_name) {
            return true;
        }
        $suffix = '_' . $field_name;
        return substr($meta_key, -strlen($suffix)) === $suffix;
    }

    /**
     * Check if a specific attachment ID is used.
     */
    public function is_used($attachment_id) {
        return in_array((int) $attachment_id, $this->used_ids, true);
    }

    /**
     * Live check: is this attachment referenced right now?
     *
     * Targeted prepared SQL, no full scan. Collectors run in single-ID mode.
     *
     * @param int $id Attachment ID.
     * @return bool|null true if used, false if unused, null on SQL error or timeout.
     */
    public function is_attachment_in_use_now($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $previous_used = $this->used_ids;
        $this->used_ids = [];
        $this->usage_check_id = $id;
        $this->usage_check_hit = false;
        $this->usage_check_failed = false;
        $this->usage_check_deadline = $this->single_id_check_deadline();

        try {
            $this->collect_featured_images();
            $this->collect_post_content_images();
            $this->collect_option_images();
            $this->collect_woocommerce_gallery();
            $this->collect_woocommerce_variations();
            $this->collect_site_identity();
            $this->collect_widget_images();
            $this->collect_elementor_images();
            $this->collect_acf_media_fields();
            $this->collect_taxonomy_thumbnails();
            $this->collect_term_description_images();
            $this->collect_option_url_references();
        } finally {
            $this->usage_check_id = null;
            $this->used_ids = $previous_used;
        }

        if ($this->usage_check_failed) {
            return null;
        }
        return $this->usage_check_hit;
    }

    /**
     * @return float
     */
    private function single_id_check_deadline() {
        $max = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;
        if ($max <= 0) {
            return microtime(true) + 15;
        }
        return microtime(true) + min(15, max(3, $max - 2));
    }

    /**
     * @return bool False when the single-ID check should skip this source.
     */
    private function begin_usage_query() {
        if ($this->usage_check_id !== null) {
            if ($this->usage_check_hit || $this->usage_check_failed) {
                return false;
            }
            if ($this->usage_check_deadline > 0 && microtime(true) >= $this->usage_check_deadline) {
                $this->usage_check_failed = true;
                return false;
            }
        }
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $wpdb->last_error = '';
        }
        return true;
    }

    /**
     * Record a collector result: merge IDs, or mark the single-ID check as a hit / error.
     *
     * @param mixed $ids_or_hit Column of IDs, a single value, or a boolean.
     */
    private function finish_usage_query($ids_or_hit) {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && !empty($wpdb->last_error)) {
            if ($this->usage_check_id !== null) {
                $this->usage_check_failed = true;
            }
            return;
        }
        if ($this->usage_check_id !== null) {
            if ($this->value_is_usage_hit($ids_or_hit)) {
                $this->usage_check_hit = true;
            }
            return;
        }
        if (is_array($ids_or_hit)) {
            $this->used_ids = array_merge($this->used_ids, $ids_or_hit);
            return;
        }
        if ($ids_or_hit !== null && $ids_or_hit !== false && $ids_or_hit !== '') {
            $this->used_ids[] = $ids_or_hit;
        }
    }

    /**
     * @param mixed $value Query result.
     * @return bool
     */
    private function value_is_usage_hit($value) {
        if ($value === true) {
            return true;
        }
        if ($value === false || $value === null || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }

    /**
     * Relative upload paths for one attachment (original + sizes + backups).
     *
     * @param int $id Attachment ID.
     * @return string[]|null Null on database error.
     */
    private function attachment_relative_paths($id) {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key IN (%s, %s, %s)",
            (int) $id,
            '_wp_attached_file',
            '_wp_attachment_metadata',
            '_wp_attachment_backup_sizes'
        ));
        if (!empty($wpdb->last_error)) {
            return null;
        }
        $file = '';
        $meta = '';
        $backup = '';
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $key = isset($row->meta_key) ? (string) $row->meta_key : '';
                if ($key === '_wp_attached_file') {
                    $file = (string) $row->meta_value;
                } elseif ($key === '_wp_attachment_metadata') {
                    $meta = $row->meta_value;
                } elseif ($key === '_wp_attachment_backup_sizes') {
                    $backup = $row->meta_value;
                }
            }
        }
        return $this->relative_paths_for_attachment_row($file, $meta, $backup);
    }

    /**
     * Precise LIKE needles for one attachment (class, block id, gallery, file paths).
     *
     * @param int $id Attachment ID.
     * @return string[]|null
     */
    private function like_needles_for_attachment($id) {
        $id = (int) $id;
        $needles = [
            'wp-image-' . $id . '"',
            'wp-image-' . $id . ' ',
            "wp-image-" . $id . "'",
            '"id":' . $id . ',',
            '"id":' . $id . '}',
            '"id": ' . $id . ',',
            '"id": ' . $id . '}',
            'ids="' . $id . '"',
            'ids="' . $id . ',',
            'ids="' . $id . ' ',
            ',' . $id . '"',
            ',"' . $id . '"',
            'ids":[' . $id . ']',
            'ids":[' . $id . ',',
            ',' . $id . ']',
        ];
        $paths = $this->attachment_relative_paths($id);
        if ($paths === null) {
            return null;
        }
        foreach ($paths as $path) {
            if ($path !== '') {
                $needles[] = $path;
            }
        }
        return array_values(array_unique($needles));
    }

    /**
     * OR of LIKE clauses with values already bound by $wpdb->prepare().
     *
     * @param string[] $columns Column names (already safe identifiers).
     * @param string[] $needles Raw substrings to wrap with %.
     * @return string|null Prepared SQL fragment, or null if nothing to match.
     */
    private function prepared_like_or(array $columns, array $needles) {
        global $wpdb;
        $parts = [];
        foreach ($columns as $column) {
            if (!$this->is_safe_identifier($column)) {
                continue;
            }
            foreach ($needles as $needle) {
                if ($needle === '') {
                    continue;
                }
                $parts[] = $wpdb->prepare(
                    '%i LIKE %s',
                    $column,
                    '%' . $wpdb->esc_like($needle) . '%'
                );
            }
        }
        if ($parts === []) {
            return null;
        }
        return implode(' OR ', $parts);
    }

    /**
     * Column and table names interpolated into SQL. Never accept meta values here.
     *
     * @param string $key Identifier.
     * @return bool
     */
    private function is_safe_identifier($key) {
        return is_string($key) && $key !== '' && (bool) preg_match('/^[A-Za-z0-9_]+$/', $key);
    }

    /**
     * Attachment IDs mentioned in HTML / block markup / gallery shortcodes.
     *
     * @param string $content Post content, excerpt, or term description.
     * @return array<int|string>
     */
    private function attachment_ids_from_html($content) {
        $ids = [];
        if (!is_string($content) || $content === '') {
            return $ids;
        }
        if (preg_match_all('/wp-image-(\d+)/', $content, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }
        if (preg_match_all('/\?attachment_id=(\d+)/', $content, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }
        if (preg_match_all('/data-id=["\'](\d+)["\']/', $content, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }
        if (preg_match_all('/"id"\s*:\s*(\d+)/', $content, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }
        if (preg_match_all('/\[gallery[^\]]*ids=["\']([\d\s,]+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $list) {
                $ids = array_merge($ids, preg_split('/\s*,\s*/', $list) ?: []);
            }
        }
        foreach (OLIMC_Media_Usage_Parser::relative_upload_paths($content) as $path) {
            $found = $this->find_attachment_by_path($path);
            if ($found) {
                $ids[] = $found;
            }
        }
        return $ids;
    }

    /**
     * Attachment IDs stored in serialized widget / theme_mod payloads.
     *
     * @param string $serialized Serialized PHP.
     * @return array<int|string>
     */
    private function attachment_ids_from_serialized($serialized) {
        $ids = [];
        if (!is_string($serialized) || $serialized === '') {
            return $ids;
        }
        if (preg_match_all('/"attachment_id";i:(\d+)/', $serialized, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }
        if (preg_match_all('/"ids";s:\d+:"([\d,]+)"/', $serialized, $matches)) {
            foreach ($matches[1] as $ids_str) {
                $ids = array_merge($ids, explode(',', $ids_str));
            }
        }
        return $ids;
    }

    /**
     * Whether a stored meta / option value references this attachment ID.
     *
     * @param mixed $value Meta or option value.
     * @param int   $id    Attachment ID.
     * @return bool
     */
    private function meta_value_references_attachment($value, $id) {
        $id = (int) $id;
        if (is_array($value) || is_object($value)) {
            $value = function_exists('maybe_serialize') ? maybe_serialize($value) : serialize($value);
        }
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }
        $value = (string) $value;
        if ($value === (string) $id || (int) $value === $id) {
            return true;
        }
        if (preg_match('/(?:^|,)' . $id . '(?:,|$)/', $value)) {
            return true;
        }
        if (preg_match('/i:' . $id . ';/', $value)) {
            return true;
        }
        if (preg_match('/s:\d+:"' . $id . '"/', $value)) {
            return true;
        }
        if (preg_match('/"id"\s*:\s*' . $id . '(?!\d)/', $value)) {
            return true;
        }
        $paths = $this->attachment_relative_paths($id);
        if (!is_array($paths)) {
            return false;
        }
        foreach ($paths as $path) {
            if ($path !== '' && strpos($value, $path) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * ACF image / file / gallery fields for one attachment (single-ID mode only).
     */
    private function collect_acf_media_fields() {
        if ($this->usage_check_id === null) {
            return;
        }
        if (!$this->begin_usage_query()) {
            return;
        }

        $id = $this->usage_check_id;
        $targets = $this->get_acf_media_field_targets();
        if ($targets === null) {
            return;
        }
        $names = isset($targets['names']) && is_array($targets['names']) ? $targets['names'] : [];
        if ($names === []) {
            return;
        }

        global $wpdb;
        $key_parts = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $key_parts[] = $wpdb->prepare('meta_key = %s', $name);
            $key_parts[] = $wpdb->prepare('meta_key LIKE %s', '%_' . $wpdb->esc_like($name));
        }
        if ($key_parts === []) {
            return;
        }

        $id_len = (string) strlen((string) $id);
        $value_sql = $wpdb->prepare(
            '(
                meta_value = %s
                OR meta_value LIKE %s
                OR meta_value LIKE %s
                OR meta_value LIKE %s
                OR meta_value LIKE %s
                OR meta_value LIKE %s
             )',
            (string) $id,
            '%' . $wpdb->esc_like('i:' . $id . ';') . '%',
            '%' . $wpdb->esc_like('s:' . $id_len . ':"' . $id . '"') . '%',
            $wpdb->esc_like((string) $id) . ',%',
            '%,' . $wpdb->esc_like((string) $id) . ',%',
            '%,' . $wpdb->esc_like((string) $id)
        );
        $where_sql = '(' . implode(' OR ', $key_parts) . ') AND ' . $value_sql;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $row = $wpdb->get_results(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE {$where_sql} LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        if (!empty($wpdb->last_error)) {
            $this->usage_check_failed = true;
            return;
        }
        if (!empty($row)) {
            foreach ($row as $candidate) {
                $key = isset($candidate->meta_key) ? (string) $candidate->meta_key : '';
                foreach ($names as $name) {
                    if ($this->acf_meta_key_matches_field($key, $name)
                        && $this->meta_value_references_attachment($candidate->meta_value, $id)
                    ) {
                        $this->usage_check_hit = true;
                        return;
                    }
                }
            }
        }

        if (!$this->begin_usage_query()) {
            return;
        }
        $opt_parts = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $opt_parts[] = $wpdb->prepare('option_name = %s', $name);
            $opt_parts[] = $wpdb->prepare('option_name LIKE %s', '%_' . $wpdb->esc_like($name));
        }
        $opt_value_sql = $wpdb->prepare(
            '(
                option_value = %s
                OR option_value LIKE %s
                OR option_value LIKE %s
             )',
            (string) $id,
            '%' . $wpdb->esc_like('i:' . $id . ';') . '%',
            '%' . $wpdb->esc_like('s:' . $id_len . ':"' . $id . '"') . '%'
        );
        $where_sql = '(' . implode(' OR ', $opt_parts) . ') AND ' . $opt_value_sql;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $opt_row = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where_sql} LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        if (!empty($wpdb->last_error)) {
            $this->usage_check_failed = true;
            return;
        }
        if (!empty($opt_row)) {
            foreach ($opt_row as $candidate) {
                if ($this->meta_value_references_attachment($candidate->option_value, $id)) {
                    $this->usage_check_hit = true;
                    return;
                }
            }
        }
    }

    /**
     * Options that store this attachment's upload URL (single-ID mode).
     */
    private function collect_option_url_references() {
        if ($this->usage_check_id === null) {
            return;
        }
        if (!$this->begin_usage_query()) {
            return;
        }
        global $wpdb;
        $paths = $this->attachment_relative_paths($this->usage_check_id);
        if ($paths === null) {
            $this->usage_check_failed = true;
            return;
        }
        $paths = array_values(array_filter($paths, function ($path) {
            return is_string($path) && $path !== '';
        }));
        if ($paths === []) {
            return;
        }
        $like_sql = $this->prepared_like_or(['option_value'], $paths);
        if ($like_sql === null) {
            return;
        }
        $prefix_sql = $wpdb->prepare(
            'option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
            $wpdb->esc_like('olimc_') . '%',
            $wpdb->esc_like('_transient') . '%',
            $wpdb->esc_like('_site_transient') . '%'
        );
        $where_sql = $prefix_sql . ' AND (' . $like_sql . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $hit = $wpdb->get_var(
            "SELECT option_id FROM {$wpdb->options} WHERE {$where_sql} LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        $this->finish_usage_query($hit);
    }

    /**
     * Merge attachment IDs discovered by meta parsers.
     *
     * @param int[]|int|string $ids Attachment ID or list of IDs.
     */
    public function merge_used_attachment_ids( $ids ) {
        if ( ! is_array( $ids ) ) {
            $ids = [ $ids ];
        }
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) {
                $this->used_ids[] = $id;
            }
        }
    }

    /**
     * Build a lookup of valid attachment post IDs (for safe generic meta matching).
     */
    public function ensure_attachment_lookup() {
        if ( null !== $this->attachment_id_index ) {
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
        $this->attachment_id_index = array_fill_keys( array_map( 'intval', $ids ), true );
    }

    /**
     * @param int $id Attachment ID.
     * @return bool
     */
    public function is_known_attachment_id( $id ) {
        $this->ensure_attachment_lookup();
        return isset( $this->attachment_id_index[ (int) $id ] );
    }

    /**
     * Resolve a relative uploads path to an attachment ID (for parsers).
     *
     * @param string $path Upload path relative to the site uploads directory.
     * @return int
     */
    public function resolve_upload_path_to_id( $path ) {
        return (int) $this->find_attachment_by_path( $path );
    }

    /**
     * Get attachment info for display.
     */
    public function get_attachment_info($attachment_id) {
        $post = get_post($attachment_id);
        if (!$post) return null;

        $mime = get_post_mime_type($attachment_id);
        $is_image = strpos($mime, 'image') !== false;

        if ($is_image) {
            $url = wp_get_original_image_url($attachment_id);
            $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        } else {
            $url = wp_get_attachment_url($attachment_id);
            $thumb_url = '';
        }

        $file_size = $this->calculate_total_size($attachment_id, $mime);

        $ext_info = wp_check_filetype($url ?: '');

        return [
            'id'         => $attachment_id,
            'title'      => $post->post_title,
            'url'        => $url,
            'thumb_url'  => $thumb_url,
            'mime'       => $mime,
            'ext'        => $ext_info['ext'] ?? '',
            'file_size'  => $file_size,
            'date'       => $post->post_date,
        ];
    }

    /**
     * Calculate total disk size of an attachment (original + all thumbnails).
     */
    public function calculate_total_size($attachment_id, $mime = '') {
        if (!$mime) {
            $mime = get_post_mime_type($attachment_id);
        }

        $total = 0;

        if (strpos($mime, 'image') !== false) {
            $meta = wp_get_attachment_metadata($attachment_id);
            $upload_dir = wp_upload_dir();
            $basedir = $upload_dir['basedir'];

            // Main file
            if (!empty($meta['file'])) {
                $main_path = $basedir . '/' . $meta['file'];
                $total += wp_filesize($main_path);
                $dir = dirname($main_path);
            }

            // Thumbnails
            if (!empty($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['file']) && isset($dir)) {
                        $total += wp_filesize($dir . '/' . $size['file']);
                    }
                }
            }

            // Original image (if scaled)
            if (!empty($meta['original_image']) && isset($dir)) {
                $total += wp_filesize($dir . '/' . $meta['original_image']);
            }
        } else {
            $file = get_attached_file($attachment_id);
            if ($file) {
                $total = wp_filesize($file);
            }
        }

        return $total;
    }

    // ─── Collection methods ───────────────────────────────────────────

    private function collect_featured_images() {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_thumbnail_id'
                 AND meta_value = %d
                 LIMIT 1",
                $this->usage_check_id
            ));
            $this->finish_usage_query($hit);
            return;
        }
        $ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'
             AND meta_value > 0"
        );
        $this->finish_usage_query($ids);
    }

    private function collect_post_content_images() {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $this->collect_post_content_images_for_id($this->usage_check_id);
            return;
        }
        $like = $this->upload_content_like();
        if ($like === null) {
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_content, post_excerpt FROM {$wpdb->posts}
             WHERE (post_content LIKE %s OR post_excerpt LIKE %s)
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')",
            $like,
            $like
        ));

        $attachment_ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $attachment_ids = array_merge(
                    $attachment_ids,
                    $this->attachment_ids_from_html(isset($row->post_content) ? $row->post_content : ''),
                    $this->attachment_ids_from_html(isset($row->post_excerpt) ? $row->post_excerpt : '')
                );
            }
        }

        $this->finish_usage_query($attachment_ids);
    }

    /**
     * One LIKE query (OR of precise needles) against published / draft / private / future content.
     *
     * @param int $id Attachment ID.
     */
    private function collect_post_content_images_for_id($id) {
        global $wpdb;
        $needles = $this->like_needles_for_attachment($id);
        if ($needles === null) {
            $this->usage_check_failed = true;
            return;
        }
        $where_sql = $this->prepared_like_or(['post_content', 'post_excerpt'], $needles);
        if ($where_sql === null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $hit = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'private', 'future') AND post_type NOT IN ('revision', 'attachment') AND ({$where_sql}) LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        $this->finish_usage_query($hit);
    }

    private function collect_option_images() {
        if (!$this->begin_usage_query()) {
            return;
        }
        $ids = [];
        $theme_mods = function_exists('get_theme_mods') ? get_theme_mods() : [];
        if (!is_array($theme_mods)) {
            $theme_mods = [];
        }
        if (!empty($theme_mods['custom_logo'])) {
            $ids[] = (int) $theme_mods['custom_logo'];
        }
        if (!empty($theme_mods['header_image_data']) && !empty($theme_mods['header_image_data']->attachment_id)) {
            $ids[] = (int) $theme_mods['header_image_data']->attachment_id;
        }
        if (!empty($theme_mods['background_image']) && function_exists('attachment_url_to_postid')) {
            $found = attachment_url_to_postid($theme_mods['background_image']);
            if ($found) {
                $ids[] = (int) $found;
            }
        }

        if ($this->usage_check_id !== null) {
            if (in_array($this->usage_check_id, $ids, true)) {
                $this->finish_usage_query(true);
                return;
            }
            $this->collect_theme_mods_option_for_id($this->usage_check_id);
            return;
        }
        $this->finish_usage_query($ids);
    }

    /**
     * @param int $id Attachment ID.
     */
    private function collect_theme_mods_option_for_id($id) {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        $needles = [
            'custom_logo";i:' . $id . ';',
            's:' . strlen((string) $id) . ':"' . $id . '"',
            'i:' . $id . ';',
        ];
        $paths = $this->attachment_relative_paths($id);
        if ($paths === null) {
            $this->usage_check_failed = true;
            return;
        }
        $needles = array_merge($needles, $paths);
        $like_sql = $this->prepared_like_or(['option_value'], $needles);
        if ($like_sql === null) {
            return;
        }
        $name_sql = $wpdb->prepare('option_name LIKE %s', $wpdb->esc_like('theme_mods_') . '%');
        $where_sql = $name_sql . ' AND (' . $like_sql . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $hit = $wpdb->get_var(
            "SELECT option_id FROM {$wpdb->options} WHERE {$where_sql} LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        $this->finish_usage_query($hit);
    }

    private function collect_woocommerce_gallery() {
        if ($this->usage_check_id === null && !class_exists('WooCommerce')) {
            return;
        }
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }

        if ($this->usage_check_id !== null) {
            $id = (string) $this->usage_check_id;
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_product_image_gallery'
                 AND meta_value != ''
                 AND (
                    meta_value = %s
                    OR meta_value LIKE %s
                    OR meta_value LIKE %s
                    OR meta_value LIKE %s
                 )
                 LIMIT 1",
                $id,
                $wpdb->esc_like($id) . ',%',
                '%,' . $wpdb->esc_like($id) . ',%',
                '%,' . $wpdb->esc_like($id)
            ));
            $this->finish_usage_query($hit);
            return;
        }

        $galleries = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND meta_value != ''"
        );

        $ids = [];
        foreach ($galleries as $gallery) {
            $ids = array_merge($ids, array_filter(array_map('intval', explode(',', $gallery))));
        }
        $this->finish_usage_query($ids);
    }

    private function collect_woocommerce_variations() {
        if ($this->usage_check_id === null && !class_exists('WooCommerce')) {
            return;
        }
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }

        if ($this->usage_check_id !== null) {
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.post_type = 'product_variation'
                 AND pm.meta_key = '_thumbnail_id'
                 AND pm.meta_value = %d
                 LIMIT 1",
                $this->usage_check_id
            ));
            $this->finish_usage_query($hit);
            return;
        }

        $variation_images = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_type = 'product_variation'
             AND pm.meta_key = '_thumbnail_id'
             AND pm.meta_value > 0"
        );
        $this->finish_usage_query($variation_images);
    }

    private function collect_site_identity() {
        if (!$this->begin_usage_query()) {
            return;
        }
        $ids = [];
        $site_logo = function_exists('get_option') ? get_option('site_logo') : 0;
        if ($site_logo) {
            $ids[] = (int) $site_logo;
        }
        $site_icon = function_exists('get_option') ? get_option('site_icon') : 0;
        if ($site_icon) {
            $ids[] = (int) $site_icon;
        }
        if ($this->usage_check_id !== null) {
            $this->finish_usage_query(in_array($this->usage_check_id, $ids, true));
            return;
        }
        $this->finish_usage_query($ids);
    }

    private function collect_widget_images() {
        if (!$this->begin_usage_query()) {
            return;
        }
        $widget_types = ['widget_media_image', 'widget_media_gallery', 'widget_media_video', 'widget_media_audio', 'widget_custom_html', 'widget_text'];

        if ($this->usage_check_id !== null) {
            foreach ($widget_types as $type) {
                $widgets = function_exists('get_option') ? get_option($type) : null;
                if (!is_array($widgets)) {
                    continue;
                }
                $serialized = serialize($widgets);
                $ids = array_map('intval', $this->attachment_ids_from_serialized($serialized));
                if (in_array($this->usage_check_id, $ids, true)) {
                    $this->finish_usage_query(true);
                    return;
                }
                $this->merge_upload_paths_from_text($serialized);
                if ($this->usage_check_hit || $this->usage_check_failed) {
                    return;
                }
            }
            $this->collect_widget_options_for_id($this->usage_check_id);
            return;
        }

        foreach ($widget_types as $type) {
            $widgets = get_option($type);
            if (!is_array($widgets)) {
                continue;
            }

            $serialized = serialize($widgets);
            $this->used_ids = array_merge($this->used_ids, $this->attachment_ids_from_serialized($serialized));
            $this->merge_upload_paths_from_text($serialized);
        }
    }

    /**
     * @param int $id Attachment ID.
     */
    private function collect_widget_options_for_id($id) {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        $needles = $this->like_needles_for_attachment($id);
        if ($needles === null) {
            $this->usage_check_failed = true;
            return;
        }
        $needles[] = '"attachment_id";i:' . $id . ';';
        $needles[] = 'i:' . $id . ';';
        $like_sql = $this->prepared_like_or(['option_value'], $needles);
        if ($like_sql === null) {
            return;
        }
        $name_sql = $wpdb->prepare('option_name LIKE %s', $wpdb->esc_like('widget_') . '%');
        $where_sql = $name_sql . ' AND (' . $like_sql . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $hit = $wpdb->get_var(
            "SELECT option_id FROM {$wpdb->options} WHERE {$where_sql} LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        $this->finish_usage_query($hit);
    }

    private function collect_elementor_images() {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $needles = $this->like_needles_for_attachment($this->usage_check_id);
            if ($needles === null) {
                $this->usage_check_failed = true;
                return;
            }
            $where_sql = $this->prepared_like_or(['meta_value'], $needles);
            if ($where_sql === null) {
                return;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
            $hit = $wpdb->get_var(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value != '' AND ({$where_sql}) LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
            );
            $this->finish_usage_query($hit);
            return;
        }

        $rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data'
             AND meta_value != ''"
        );

        foreach ($rows as $json) {
            if (preg_match_all('/"id"\s*:\s*(\d+)/', $json, $matches)) {
                foreach ($matches[1] as $id) {
                    if ((int) $id > 0 && (int) $id < 999999999) {
                        $this->used_ids[] = $id;
                    }
                }
            }
            $this->merge_upload_paths_from_text($json);
        }

        $elementor_global = get_option('elementor_custom_icon_sets_config');
        if (is_array($elementor_global)) {
            $this->merge_upload_paths_from_text(serialize($elementor_global));
        }
    }

    /**
     * Scan ALL files in the active theme for hardcoded image references.
     * Checks PHP, CSS, and JS files for the current upload location.
     */
    private function collect_theme_files() {
        $theme_dir = get_stylesheet_directory();
        $parent_theme_dir = get_template_directory();

        $dirs = [$theme_dir];
        if ($parent_theme_dir !== $theme_dir) {
            $dirs[] = $parent_theme_dir;
        }

        foreach ($dirs as $dir) {
            $this->scan_directory_for_uploads($dir);
        }
    }

    /**
     * Recursively scan a directory for files referencing the current upload location.
     */
    private function scan_directory_for_uploads($dir) {
        if (!is_dir($dir)) return;

        $extensions = ['php', 'css', 'js', 'html', 'htm', 'json'];
        $skip_dirs = ['node_modules', '.git', 'vendor', 'dist', 'build'];

        $iterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($iterator, function($current, $key, $iterator) use ($skip_dirs) {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), $skip_dirs);
            }
            return true;
        });
        $files = new RecursiveIteratorIterator($filter);

        foreach ($files as $file) {
            if (!$file->isFile()) continue;

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $extensions)) continue;

            // Skip very large files (> 1MB)
            if ($file->getSize() > 1048576) continue;

            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;

            $this->merge_upload_paths_from_text($content);

            // Find attachment IDs in PHP: wp_get_attachment_image(123), wp_get_attachment_url(456)
            if ($ext === 'php') {
                if (preg_match_all('/wp_get_attachment_(?:image|url|image_url|image_src)\s*\(\s*(\d+)/', $content, $matches)) {
                    $this->used_ids = array_merge($this->used_ids, $matches[1]);
                }
            }
        }
    }

    /**
     * Find images referenced in CSS background-image in post_content and postmeta.
     */
    private function collect_css_background_images() {
        global $wpdb;
        $like = $this->upload_content_like();
        if ($like === null) {
            return;
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_content LIKE %s
             AND post_content LIKE %s
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')",
            '%' . $wpdb->esc_like('background') . '%',
            $like
        ));

        foreach ($rows as $content) {
            $this->merge_upload_paths_from_text($content);
        }
    }

    /**
     * Collect taxonomy term thumbnail IDs (WooCommerce category images, etc.).
     * These are stored as 'thumbnail_id' in wp_termmeta.
     */
    private function collect_taxonomy_thumbnails() {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $termmeta = isset($wpdb->termmeta) ? $wpdb->termmeta : $wpdb->prefix . 'termmeta';
            if (!$this->is_safe_identifier($termmeta)) {
                $this->usage_check_failed = true;
                return;
            }
            $hit = $wpdb->get_var($wpdb->prepare(
                'SELECT meta_value FROM %i
                 WHERE meta_key = %s
                 AND meta_value = %d
                 LIMIT 1',
                $termmeta,
                'thumbnail_id',
                $this->usage_check_id
            ));
            $this->finish_usage_query($hit);
            return;
        }
        $ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_key = 'thumbnail_id'
             AND meta_value > 0"
        );
        $this->finish_usage_query($ids);
    }

    /**
     * Collect images referenced in taxonomy term descriptions.
     * Covers product categories, product attributes (pa_finish, pa_color, etc.),
     * post categories, tags, and any custom taxonomy.
     */
    private function collect_term_description_images() {
        global $wpdb;
        if (!$this->begin_usage_query()) {
            return;
        }
        $term_taxonomy = isset($wpdb->term_taxonomy) ? $wpdb->term_taxonomy : $wpdb->prefix . 'term_taxonomy';
        if (!$this->is_safe_identifier($term_taxonomy)) {
            if ($this->usage_check_id !== null) {
                $this->usage_check_failed = true;
            }
            return;
        }
        if ($this->usage_check_id !== null) {
            $needles = $this->like_needles_for_attachment($this->usage_check_id);
            if ($needles === null) {
                $this->usage_check_failed = true;
                return;
            }
            $like_sql = $this->prepared_like_or(['description'], $needles);
            if ($like_sql === null) {
                return;
            }
            $from_sql = $wpdb->prepare('SELECT term_taxonomy_id FROM %i WHERE (', $term_taxonomy);
            $where_sql = $from_sql . $like_sql . ') LIMIT 1';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
            $hit = $wpdb->get_var($where_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
            $this->finish_usage_query($hit);
            return;
        }
        $like = $this->upload_content_like();
        $rows = [];
        if ($like !== null) {
            $rows = $wpdb->get_col($wpdb->prepare(
                'SELECT description FROM %i
                 WHERE description LIKE %s',
                $term_taxonomy,
                $like
            ));
        }

        foreach ($rows as $description) {
            $this->used_ids = array_merge($this->used_ids, $this->attachment_ids_from_html($description));
        }

        // Also check termmeta for image IDs/URLs (some plugins store images in term meta)
        $meta_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_value REGEXP '^[0-9]+$'
             AND meta_value > 0
             AND meta_key NOT IN ('thumbnail_id', 'order', 'product_count_product_cat')
             AND CAST(meta_value AS UNSIGNED) IN (
                SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'
             )"
        );
        $this->used_ids = array_merge($this->used_ids, $meta_ids);

        if ($like !== null) {
            $meta_urls = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
                 WHERE meta_value LIKE %s",
                $like
            ));
            foreach ($meta_urls as $val) {
                $this->merge_upload_paths_from_text($val);
            }
        }
    }

    /**
     * Attachment IDs that are not in $used but share an upload path with one that is.
     *
     * Paths are the attached file and generated sizes (including original_image).
     * Null means the lookup failed; the caller must not mark anything unused.
     *
     * @param array<int, mixed> $used Used attachment IDs.
     * @return array<int, int>|null Map of attachment ID => 1, or null on database error.
     */
    public function ids_sharing_files_with_used(array $used) {
        $normalized = [];
        foreach ($used as $id => $_unused) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = 1;
            }
        }
        if ($normalized === []) {
            return [];
        }
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $added = [];
        $guard = 0;
        do {
            $changed = false;
            $guard++;
            foreach ($index as $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                $hit = false;
                foreach ($ids as $id) {
                    if (isset($normalized[$id]) || isset($added[$id])) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (!isset($normalized[$id]) && !isset($added[$id])) {
                        $added[$id] = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed && $guard < 10);
        return $added;
    }

    /**
     * Relative upload paths of this attachment that another attachment also references.
     *
     * Null means the lookup failed. Callers must not unlink files in that case.
     *
     * @param int $attachment_id Attachment ID.
     * @return string[]|null
     */
    public function shared_relative_upload_paths($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $shared = [];
        foreach ($index as $path => $ids) {
            if (count($ids) > 1 && in_array($attachment_id, $ids, true)) {
                $shared[] = $path;
            }
        }
        return $shared;
    }

    /**
     * Whether an absolute filesystem path is one of the relative upload paths.
     *
     * When wp_upload_dir() is available, the file must sit in that basedir and
     * the remainder must equal the relative path. That keeps a main-site file
     * from matching a subsite path that merely ends with the same suffix.
     *
     * @param string   $absolute  Absolute path passed to wp_delete_file.
     * @param string[] $relatives Paths relative to the uploads directory.
     * @return bool
     */
    public static function absolute_path_matches_relative($absolute, array $relatives) {
        $absolute = str_replace('\\', '/', (string) $absolute);
        $basedir = self::upload_basedir();
        if ($basedir !== '') {
            $prefix = rtrim($basedir, '/') . '/';
            if (strpos($absolute, $prefix) !== 0) {
                return false;
            }
            $relative = substr($absolute, strlen($prefix));
            foreach ($relatives as $candidate) {
                $candidate = ltrim(str_replace('\\', '/', (string) $candidate), '/');
                if ($candidate !== '' && $candidate === $relative) {
                    return true;
                }
            }
            return false;
        }
        foreach ($relatives as $relative) {
            $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
            if ($relative === '') {
                continue;
            }
            $suffix = '/' . $relative;
            if (substr($absolute, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when this absolute file, or the image it was derived from, is shared.
     *
     * Null means the index could not be read. Callers must keep the file.
     * A trailing .webp or .avif is compared against the underlying image path.
     *
     * @param string $absolute Absolute path.
     * @return bool|null
     */
    public function absolute_file_is_shared($absolute) {
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $candidates = [$absolute];
        foreach (['.webp', '.avif'] as $extension) {
            if (is_string($absolute) && strlen($absolute) > strlen($extension) && substr($absolute, -strlen($extension)) === $extension) {
                $candidates[] = substr($absolute, 0, -strlen($extension));
            }
        }
        foreach ($candidates as $candidate) {
            foreach ($index as $path => $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                if (self::absolute_path_matches_relative($candidate, [$path])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Drop the cached file index. Tests use this when the database changes.
     */
    public static function reset_upload_path_index() {
        self::$upload_path_index_cache = null;
        self::$upload_path_index_ready = false;
    }

    /**
     * @var array<string, int[]>|null
     */
    private static $upload_path_index_cache = null;

    /**
     * @var bool
     */
    private static $upload_path_index_ready = false;

    /**
     * path => attachment IDs that reference it (original file, size, or backup).
     *
     * Cached for the request so a bulk delete does not rescan postmeta per file.
     *
     * @return array<string, int[]>|null
     */
    private function upload_path_index() {
        if (self::$upload_path_index_ready) {
            return self::$upload_path_index_cache;
        }
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s) AND meta_value != ''",
            '_wp_attached_file',
            '_wp_attachment_metadata',
            '_wp_attachment_backup_sizes'
        ));
        if (!empty($wpdb->last_error) || !is_array($rows)) {
            self::$upload_path_index_cache = null;
            self::$upload_path_index_ready = true;
            return null;
        }

        $by_id = [];
        foreach ($rows as $row) {
            $id = (int) $row->post_id;
            if (!isset($by_id[$id])) {
                $by_id[$id] = [
                    'file' => '',
                    'meta' => '',
                    'backup' => '',
                ];
            }
            $key = isset($row->meta_key) ? (string) $row->meta_key : '';
            if ($key === '_wp_attached_file') {
                $by_id[$id]['file'] = (string) $row->meta_value;
            } elseif ($key === '_wp_attachment_metadata') {
                $by_id[$id]['meta'] = $row->meta_value;
            } elseif ($key === '_wp_attachment_backup_sizes') {
                $by_id[$id]['backup'] = $row->meta_value;
            }
        }
        $index = [];
        foreach ($by_id as $id => $parts) {
            $paths = $this->relative_paths_for_attachment_row($parts['file'], $parts['meta'], $parts['backup']);
            foreach ($paths as $path) {
                $index[$path][] = (int) $id;
            }
        }
        foreach ($index as $path => $list) {
            $index[$path] = array_values(array_unique($list));
        }
        self::$upload_path_index_cache = $index;
        self::$upload_path_index_ready = true;
        return $index;
    }

    /**
     * @return string Uploads basedir, or an empty string when WordPress is not loaded.
     */
    private static function upload_basedir() {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || empty($uploads['basedir']) || !is_string($uploads['basedir'])) {
            return '';
        }
        return rtrim(str_replace('\\', '/', $uploads['basedir']), '/');
    }

    /**
     * @param string $attached_file _wp_attached_file value.
     * @param mixed  $meta_value    Raw _wp_attachment_metadata value.
     * @param mixed  $backup_value  Raw _wp_attachment_backup_sizes value.
     * @return string[]
     */
    private function relative_paths_for_attachment_row($attached_file, $meta_value, $backup_value = '') {
        $paths = [];
        $attached_file = $this->normalize_relative_upload_path($attached_file);
        if ($attached_file !== '') {
            $paths[] = $attached_file;
        }
        $meta = $this->decode_attachment_meta($meta_value);
        $base = $attached_file;
        if (is_array($meta) && !empty($meta['file']) && is_string($meta['file'])) {
            $file = $this->normalize_relative_upload_path($meta['file']);
            if ($file !== '') {
                $paths[] = $file;
                $base = $file;
            }
        }
        $dir = ($base !== '' && strpos($base, '/') !== false) ? dirname($base) : '';
        if ($dir === '.') {
            $dir = '';
        }
        if (is_array($meta) && !empty($meta['original_image']) && is_string($meta['original_image'])) {
            $paths[] = $this->join_upload_dir($dir, $meta['original_image']);
        }
        if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
                    $paths[] = $this->join_upload_dir($dir, $size['file']);
                }
            }
        }
        $backup = $this->decode_attachment_meta($backup_value);
        if (is_array($backup)) {
            foreach ($backup as $size) {
                if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
                    $paths[] = $this->join_upload_dir($dir, $size['file']);
                }
            }
        }
        $out = [];
        foreach ($paths as $path) {
            $path = $this->normalize_relative_upload_path($path);
            if ($path !== '') {
                $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value Meta value.
     * @return array|null
     */
    private function decode_attachment_meta($value) {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $data = function_exists('maybe_unserialize') ? maybe_unserialize($value) : @unserialize($value);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $dir  Directory relative to uploads.
     * @param string $file File name or relative path.
     * @return string
     */
    private function join_upload_dir($dir, $file) {
        $file = $this->normalize_relative_upload_path($file);
        if ($file === '') {
            return '';
        }
        if ($dir === '' || strpos($file, '/') !== false) {
            return $file;
        }
        return $dir . '/' . $file;
    }

    /**
     * @param string $path Upload path.
     * @return string
     */
    private function normalize_relative_upload_path($path) {
        if (!is_string($path)) {
            return '';
        }
        $path = str_replace('\\', '/', trim($path));
        return ltrim($path, '/');
    }

    // ─── Utility ──────────────────────────────────────────────────────

    /**
     * Find attachment ID from a partial upload path (e.g. "2025/06/photo.jpg").
     * Caches lookups for performance.
     */
    private function find_attachment_by_path($path) {
        global $wpdb;
        // Clean path
        $path = trim($path, '"\'\\/ ');
        // Remove thumbnail size suffix for lookup (e.g. photo-300x200.jpg -> photo.jpg)
        $clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $path);

        if (isset($this->path_cache[$clean])) {
            return $this->path_cache[$clean];
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value = %s
             LIMIT 1",
            $clean
        ));

        // If not found, try with the original (might be a thumbnail filename)
        if (!$id && $clean !== $path) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'
                 AND meta_value = %s
                 LIMIT 1",
                $path
            ));
        }

        $this->path_cache[$clean] = $id ? (int) $id : 0;
        return $this->path_cache[$clean];
    }

    /**
     * LIKE pattern for the current upload location, or null when it is unknown.
     *
     * @return string|null
     */
    private function upload_content_like() {
        global $wpdb;
        $fragment = OLIMC_Media_Usage_Parser::upload_search_fragment();
        if ($fragment === '') {
            return null;
        }
        return '%' . $wpdb->esc_like($fragment) . '%';
    }

    /**
     * @param string $text Text that may cite an upload URL or path.
     */
    private function merge_upload_paths_from_text($text) {
        if ($this->usage_check_id !== null) {
            $paths = $this->attachment_relative_paths($this->usage_check_id);
            if ($paths === null) {
                $this->usage_check_failed = true;
                return;
            }
            if (!is_string($text) || $text === '') {
                return;
            }
            foreach ($paths as $path) {
                if ($path !== '' && strpos($text, $path) !== false) {
                    $this->usage_check_hit = true;
                    return;
                }
            }
            return;
        }
        foreach (OLIMC_Media_Usage_Parser::relative_upload_paths($text) as $path) {
            $id = $this->find_attachment_by_path($path);
            if ($id) {
                $this->used_ids[] = $id;
            }
        }
    }
}
