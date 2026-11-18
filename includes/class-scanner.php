<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables and core tables are read directly on purpose: scans and results are one-off, large and must not fill the object cache.
defined('ABSPATH') || exit;

class OLIMC_Scanner {

    private $used_ids = [];
    private $path_cache = [];
    /** @var array<int, true>|null */
    private $attachment_id_index = null;
    /** @var string[] Field keys (field_xxx) for image / file / gallery, filled while walking ACF definitions. */
    private $acf_media_field_key_buffer = [];
    /**
     * Media cited by post content, computed once for a batch of live checks (see begin_batch()).
     * Null: no batch. False: batch started, not computed yet.
     *
     * @var array<int,true>|false|null
     */
    private static $batch_content = null;

    /**
     * Media IDs of the batch (flipped), when the caller gave them; the rows of the meta tables and of the
     * options are read once for all of them (see meta_hits()). Null: no list.
     *
     * @var array<int,true>|null
     */
    private static $batch_set = null;

    /**
     * Media of the batch that a meta or option row refers to. False: not computed yet. Null: a query failed.
     *
     * @var array<int,true>|false|null
     */
    private static $batch_meta = false;

    /**
     * What the theme files cite, read once per request: media IDs and upload paths.
     *
     * @var array{ids: array<int,true>, paths: array<string,true>}|null
     */
    private static $theme_index = null;

    /**
     * Single checks made without a batch in this request, and when the first one was made: the second one switches to a
     * whole read of the tables for every media (valid for a short time), which a loop of single checks would otherwise repeat.
     *
     * @var int
     */
    private static $single_calls = 0;

    /** @var float */
    private static $single_since = 0.0;

    /** @var bool True when the batch was made by the second single check, not announced by a caller. */
    private static $auto_batch = false;

    /** Seconds during which the whole read made for a loop of single checks is trusted. */
    const AUTO_BATCH_TTL = 20;

    /** Up to this many media the tables are filtered in SQL for each ID; above, they are read once for the whole batch (cheaper from 2 media on). */
    const META_PREFILTER_MAX = 1;

    /** @var int|null Attachment ID being checked by is_attachment_in_use_now(). */
    private $usage_check_id = null;
    /** @var bool */
    private $usage_check_hit = false;
    /** @var bool */
    private $usage_check_failed = false;
    /** @var float */
    private $usage_check_deadline = 0.0;
    /** @var int[]|null Post ID range for a chunked auxiliary source. */
    private $aux_range = null;

    /**
     * Run a full scan. Returns array of unused attachment IDs.
     * Works in batches via AJAX to avoid timeouts.
     */
    public function get_all_attachment_ids() {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are validated by olimc_prepare(); fixed SQL fragments only.
        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE " . self::scannable_attachment_sql() . '
             ORDER BY ID ASC'
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * SQL condition (on the posts table) for media that are in the library:
     * not in the WordPress trash and not in the trash of this plugin.
     *
     * @param string $alias Optional table alias, with trailing dot.
     * @return string Static SQL, no user input.
     */
    public static function scannable_attachment_sql( $alias = '' ) {
        global $wpdb;
        return "{$alias}post_type = 'attachment' AND {$alias}post_status != 'trash'"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} olimc_t WHERE olimc_t.post_id = {$alias}ID AND olimc_t.meta_key = '_olimc_trashed')";
    }

    /**
     * Get total attachment count.
     */
    public function get_total_attachment_count() {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are validated by olimc_prepare(); fixed SQL fragments only.
        return (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE " . self::scannable_attachment_sql()
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
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
        while ( ! in_array($state['phase'], [ 'done', 'failed' ], true) && $guard < 1000000) {
            if (microtime(true) >= $deadline) {
                $state['phase'] = 'failed';
                $state['error'] = 'Scan stopped before the PHP time limit.';
                break;
            }
            $remaining_ms = (int) max(200, ( $deadline - microtime(true) ) * 1000);
            $state        = $engine->step($state, min(2000, $remaining_ms));
            $guard++;
        }

        if ( ! OLIMC_Acf_Meta_Scanner::may_mark_unused($state)) {
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

        if ( ! empty($protected_ids)) {
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
    private function normalize_protected_pattern( $pattern, $base_url ) {
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
     * Auxiliary sources, in scan order: slug => collector method.
     *
     * The scan engine runs one source per step so the progress bar moves
     * regularly and no single request reads every source at once.
     *
     * @return array<string,string>
     */
    public static function auxiliary_source_map() {
        return [
            'featured'        => 'collect_featured_images',
            'content'         => 'collect_post_content_images',
            'options'         => 'collect_option_images',
            'woo_gallery'     => 'collect_woocommerce_gallery',
            'woo_variations'  => 'collect_woocommerce_variations',
            'identity'        => 'collect_site_identity',
            'widgets'         => 'collect_widget_images',
            'elementor'       => 'collect_elementor_images',
            'taxonomy'        => 'collect_taxonomy_thumbnails',
            'terms'           => 'collect_term_description_images',
            'theme'           => 'collect_theme_files',
            'css'             => 'collect_css_background_images',
        ];
    }

    /**
     * Run one auxiliary source. Post content is read by ID range (see $range).
     *
     * @param string     $slug  Source slug from auxiliary_source_map().
     * @param int[]|null $range Optional [low, high] post ID range (content and css only).
     * @return bool False when the slug is unknown.
     */
    public function collect_auxiliary_source( $slug, $range = null ) {
        $map = self::auxiliary_source_map();
        if ( ! isset($map[ $slug ])) {
            return false;
        }
        $this->aux_range = ( is_array($range) && count($range) === 2 ) ? [ (int) $range[0], (int) $range[1] ] : null;
        $method = $map[ $slug ];
        try {
            $this->$method();
        } finally {
            $this->aux_range = null;
        }
        return true;
    }

    /**
     * Sources that are not the ACF meta walk (content, WooCommerce, theme files, …).
     */
    public function collect_auxiliary_sources() {
        foreach (array_keys(self::auxiliary_source_map()) as $slug) {
            $this->collect_auxiliary_source($slug);
        }
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
        if ( ! $this->ensure_acf_loaded()) {
            return null;
        }

        $groups = acf_get_field_groups();
        if ( ! is_array($groups) || $groups === []) {
            return null;
        }

        $image_keys   = [];
        $file_keys    = [];
        $gallery_keys = [];
        $all_keys     = [];
        $resolved     = false;

        foreach ($groups as $group) {
            if ( ! is_array($group) || empty($group['key'])) {
                continue;
            }
            $fields = function_exists('acf_get_fields') ? acf_get_fields($group['key']) : null;
            if ( ! is_array($fields)) {
                continue;
            }
            $resolved = true;
            $this->collect_acf_field_keys_recursive($fields, $image_keys, $file_keys, $gallery_keys, $all_keys);
        }

        if ( ! $resolved) {
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
        if ( ! function_exists('acf_get_field_groups')) {
            return false;
        }
        $init_done       = function_exists('did_action') && did_action('init');
        $acf_init_done   = function_exists('did_action') && did_action('acf/init');
        $fields_included = function_exists('did_action') && did_action('acf/include_fields');

        // Local JSON is loaded on acf/include_fields. After WordPress init, do not
        // call acf_init() again (that re-registers hooks). Call include_fields
        // directly when it has not run yet. acf_init() is only for a scan that
        // really is running before init.
        if ( ! $fields_included && function_exists('acf_include_fields')) {
            if ( ! $init_done && ! $acf_init_done && function_exists('acf_init')) {
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
    private function collect_acf_field_keys_recursive( $fields, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys, &$visited_clones = null ) {
        if ($visited_clones === null) {
            $visited_clones = [];
        }

        foreach ($fields as $field) {
            if ( ! is_array($field)) {
                continue;
            }

            if ( ! empty($field['name'])) {
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

            if (in_array($type, [ 'image', 'file', 'gallery' ], true) && ! empty($field['key']) && is_string($field['key'])) {
                $this->acf_media_field_key_buffer[] = $field['key'];
            }

            if ( ! empty($field['sub_fields'])) {
                $this->collect_acf_field_keys_recursive($field['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
            if ( ! empty($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if ( ! empty($layout['sub_fields'])) {
                        $this->collect_acf_field_keys_recursive($layout['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
                    }
                }
            }

            if ($type === 'clone' && ! empty($field['clone']) && is_array($field['clone'])) {
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
    private function collect_acf_cloned_fields( $clone_selectors, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys, &$visited_clones ) {
        foreach ($clone_selectors as $clone_selector) {
            if ( ! is_string($clone_selector) || $clone_selector === '') {
                continue;
            }
            if (isset($visited_clones[ $clone_selector ])) {
                continue;
            }
            $visited_clones[ $clone_selector ] = true;

            $cloned_fields = function_exists('acf_get_fields') ? acf_get_fields($clone_selector) : null;
            if ( ! empty($cloned_fields) && is_array($cloned_fields)) {
                $this->collect_acf_field_keys_recursive($cloned_fields, $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
                continue;
            }

            if ( ! function_exists('acf_get_field')) {
                continue;
            }
            $cloned_field = acf_get_field($clone_selector);
            if ( ! empty($cloned_field) && is_array($cloned_field)) {
                $this->collect_acf_field_keys_recursive([ $cloned_field ], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
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
    public function acf_meta_key_matches_field( $meta_key, $field_name ) {
        if ($meta_key === $field_name) {
            return true;
        }
        $suffix = '_' . $field_name;
        return substr($meta_key, -strlen($suffix)) === $suffix;
    }

    /**
     * Check if a specific attachment ID is used.
     */
    public function is_used( $attachment_id ) {
        return in_array( (int) $attachment_id, $this->used_ids, true);
    }

    /**
     * Start a batch of live checks (one request that checks many media): the post content is read once
     * for the whole batch instead of once per media. Call end_batch() afterwards.
     */
    public static function begin_batch( array $ids = [] ) {
        self::$batch_content = false;
        $ids                 = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        self::$batch_set     = [] === $ids ? null : array_fill_keys( $ids, true );
        self::$batch_meta    = false;
        self::$auto_batch    = false;
        self::$single_calls  = 0;
    }

    public static function end_batch() {
        self::$batch_content = null;
        self::$batch_set     = null;
        self::$batch_meta    = false;
        self::$auto_batch    = false;
        self::$single_calls  = 0;
    }

    /**
     * IDs of the media cited by post content and excerpts, with the same readers as the scan.
     *
     * @return array<int,true>|null Null when a query failed (callers treat that as "in use").
     */
    private function batch_content_ids() {
        global $wpdb;
        $where_sql = $this->prepared_like_or([ 'post_content', 'post_excerpt' ], $this->content_prefilter_needles());
        if ($where_sql === null) {
            return [];
        }
        $reader = new OLIMC_Scanner();
        $used   = [];
        $last   = 0;
        do {
            $rows = $wpdb->get_results(
                "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ({$where_sql}) AND post_status != 'trash' AND post_type NOT IN ('revision', 'attachment') AND ID > " . (int) $last . ' ORDER BY ID ASC LIMIT 500' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments; the bound is an integer.
            );
            if ( ! empty($wpdb->last_error) || ! is_array($rows)) {
                return null;
            }
            $parents = [];
            $paths   = [];
            foreach ($rows as $row) {
                $paths = array_merge($paths, OLIMC_Media_Usage_Parser::relative_upload_paths($row->post_content), OLIMC_Media_Usage_Parser::relative_upload_paths($row->post_excerpt));
            }
            $reader->prefetch_paths($paths); // One lookup for the whole chunk, as the scan does.
            foreach ($rows as $row) {
                $last = (int) $row->ID;
                foreach ([ $row->post_content, $row->post_excerpt ] as $text) {
                    foreach ($reader->attachment_ids_from_html($text) as $found) {
                        $used[ (int) $found ] = true;
                    }
                    $parents = array_merge($parents, $reader->gallery_default_parents($text, (int) $row->ID));
                }
            }
            foreach ($reader->attachments_of_posts($parents) as $found) {
                $used[ (int) $found ] = true;
            }
        } while (count($rows) === 500);
        return $used;
    }

    /**
     * Live check: is this attachment referenced right now?
     *
     * Targeted prepared SQL, no full scan. Collectors run in single-ID mode.
     *
     * @param int $id Attachment ID.
     * @return bool|null true if used, false if unused, null on SQL error or timeout.
     */
    public function is_attachment_in_use_now( $id ) {
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
            $this->collect_meta_sources();
            $this->collect_theme_files_now();
            $this->collect_attached_now();
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
     * Every row the scan reads in the meta tables (posts, terms, users) and in the options, with the rules the scan applies
     * to it: the value is the ID, a list of IDs, a serialized or JSON structure holding it (numeric array keys count in
     * custom fields, not in other options), or an upload URL that leads to the media. The rows are filtered in SQL for the
     * media being checked, and the rows that remain go through the very parser the scan uses. In a batch the tables are
     * read once for all the media of the batch.
     */
    private function collect_meta_sources() {
        if ($this->usage_check_id === null || ! $this->begin_usage_query()) {
            return;
        }
        $id = $this->usage_check_id;
        if (microtime(true) - self::$single_since > self::AUTO_BATCH_TTL) {
            // Too old to be trusted: forget the whole read made for a loop of single checks, and start counting again.
            self::$single_calls = 0;
            self::$single_since = microtime(true);
            if (self::$auto_batch) {
                self::$batch_set  = null;
                self::$batch_meta = false;
                self::$auto_batch = false;
            }
        }
        if (self::$batch_set !== null && isset(self::$batch_set[ $id ])) {
            $this->collect_meta_sources_from_batch($id);
            return;
        }
        // A loop of single checks (a bulk delete, a script): from the second on, read the tables once for every media.
        if (self::$batch_set === null && ++self::$single_calls > self::META_PREFILTER_MAX) {
            global $wpdb;
            $all = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'");
            if (is_array($all) && $all !== [] && empty($wpdb->last_error)) {
                self::$batch_set  = array_fill_keys(array_map('intval', $all), true);
                self::$batch_meta = false;
                self::$auto_batch = true;
                $this->collect_meta_sources_from_batch($id);
                return;
            }
        }
        $hits = $this->meta_hits([ $id ], $this->usage_check_deadline);
        if ($hits === null) {
            $this->usage_check_failed = true;
            return;
        }
        if (isset($hits[ $id ])) {
            $this->usage_check_hit = true;
        }
    }

    /**
     * The answer for one media from the whole read made for its batch (made now if it was not yet).
     *
     * @param int $id Attachment ID.
     */
    private function collect_meta_sources_from_batch( $id ) {
        if (self::$batch_meta === false) {
            $budget = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;
            self::$batch_meta = $this->meta_hits(array_keys(self::$batch_set), microtime(true) + ( $budget > 0 ? max(5, $budget - 5) : 120 ));
        }
        if (self::$batch_meta === null) {
            $this->usage_check_failed = true;
            return;
        }
        if (isset(self::$batch_meta[ $id ])) {
            $this->usage_check_hit = true;
        }
    }

    /**
     * @param int[] $ids Attachment IDs.
     * @return array<int,string>|null Attached file (relative path) by ID; null on a database error.
     */
    private function attached_files_of( array $ids ) {
        global $wpdb;
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ({$marks})", $chunk)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the list only holds %d placeholders.
            if ( ! is_array($rows) || ! empty($wpdb->last_error)) {
                return null;
            }
            foreach ($rows as $row) {
                $file = (string) $row->meta_value;
                if ($file !== '') {
                    $out[ (int) $row->post_id ] = $file;
                }
            }
        }
        return $out;
    }

    /**
     * Which of these media do the meta tables or the options refer to?
     *
     * @param int[]      $ids      Attachment IDs.
     * @param float|null $deadline Wall clock after which the answer is "unknown" (null).
     * @return array<int,true>|null The media found; null on a database error or when time ran out (treated as "in use").
     */
    private function meta_hits( array $ids, $deadline = null, $force_full = false ) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $files = $this->attached_files_of($ids);
        if ($files === null) {
            return null;
        }
        $set     = array_fill_keys($ids, true);
        $hits    = [];
        $by_file = [];
        foreach ($files as $post_id => $file) {
            $by_file[ strtolower($file) ][] = $post_id;
        }
        // Same resolution as the scan: the path without its "-WxH" size suffix, else the path itself, is the attached file.
        $resolve = static function ( $path ) use ( &$by_file, &$hits ) {
            $path  = trim( (string) $path, '"\'\\/ ');
            $clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $path);
            foreach ([ $clean, $path ] as $candidate) {
                $key = strtolower( (string) $candidate );
                if (isset($by_file[ $key ])) {
                    foreach ($by_file[ $key ] as $post_id) {
                        $hits[ $post_id ] = true;
                    }
                    return $by_file[ $key ][0];
                }
            }
            return 0;
        };
        $known = static function ( $found ) use ( $set ) {
            return isset($set[ (int) $found ]);
        };

        // SQL pre-filter: the number written as a whole run of digits (the LIKE keeps the regular expression for the few rows
        // that hold the digits), or the path of the file without its extension and size suffix, in its plain, URL-encoded
        // and JSON-escaped forms.
        $full    = $force_full || count($ids) > self::META_PREFILTER_MAX;
        $regexp  = '';
        $stems   = [];
        $gate    = '';
        if ($full) {
            // Whole read: only the rows the parser can get anything from (a number or a list of numbers, a serialized or JSON
            // value, an upload URL); plain text is skipped in SQL.
            $fragment = OLIMC_Media_Usage_Parser::upload_search_fragment();
            $gate     = '(`__V__` REGEXP \'^[[:space:]]*[-+.0-9]\' OR LEFT(`__V__`, 2) IN (\'a:\', \'O:\') OR LEFT(`__V__`, 1) IN (\'[\', \'{\')';
            if ($fragment !== '') {
                $gate .= ' OR `__V__` LIKE ' . $wpdb->prepare('%s', '%' . $wpdb->esc_like($fragment) . '%') . ' OR `__V__` LIKE ' . $wpdb->prepare('%s', '%' . $wpdb->esc_like(str_replace('/', '\\/', $fragment)) . '%');
            }
            $gate .= ')';
        } else {
            $regexp = '(^|[^0-9])0*(' . implode('|', $ids) . ')([^0-9]|$)';
            foreach ($files as $file) {
                $stem = preg_replace('/\.[^.\/]+$/', '', $file);
                if ( ! is_string($stem) || $stem === '') {
                    continue;
                }
                $encoded = implode('/', array_map('rawurlencode', explode('/', $stem)));
                foreach (array_unique([ $stem, $encoded, str_replace('/', '\\/', $stem) ]) as $form) {
                    $stems[ '/' . $form ] = true;
                }
            }
        }
        $sources = [
            [ $wpdb->postmeta, 'meta_id', 'meta_key', 'meta_value', true ],
            [ $wpdb->usermeta, 'umeta_id', 'meta_key', 'meta_value', true ],
            [ $wpdb->options, 'option_id', 'option_name', 'option_value', false ],
        ];
        if (isset($wpdb->termmeta)) {
            array_splice($sources, 1, 0, [ [ $wpdb->termmeta, 'meta_id', 'meta_key', 'meta_value', true ] ]);
        }
        $page = $full ? 500 : 100;
        foreach ($sources as $src) {
            list($table, $id_col, $key_col, $val_col, $count_keys) = $src;
            $where = [];
            foreach (OLIMC_Acf_Meta_Scanner::plugin_key_prefixes() as $prefix) {
                $where[] = $wpdb->prepare("`{$key_col}` NOT LIKE %s", $wpdb->esc_like($prefix) . '%'); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is chosen by this class.
            }
            if ($full) {
                $where[] = str_replace('`__V__`', '`' . $val_col . '`', $gate);
            } else {
                $or = [];
                $number_likes = [];
                foreach ($ids as $one) {
                    $number_likes[] = $wpdb->prepare("`{$val_col}` LIKE %s", '%' . $wpdb->esc_like( (string) $one) . '%'); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is chosen by this class.
                }
                $or[] = '((' . implode(' OR ', $number_likes) . ') AND ' . $wpdb->prepare("`{$val_col}` REGEXP %s", $regexp) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is chosen by this class.
                foreach (array_keys($stems) as $needle) {
                    $or[] = $wpdb->prepare("`{$val_col}` LIKE %s", '%' . $wpdb->esc_like($needle) . '%'); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is chosen by this class.
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
            $last = 0;
            do {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return null;
                }
                $wpdb->last_error = '';
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- every value of $where is bound by prepare(); the identifiers are chosen by this class.
                $rows = $wpdb->get_results(
                    "SELECT `{$id_col}` AS rid, `{$key_col}` AS rkey, `{$val_col}` AS rval FROM `{$table}` WHERE `{$id_col}` > " . (int) $last . ' AND ' . implode(' AND ', $where) . " ORDER BY `{$id_col}` ASC LIMIT " . (int) $page // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are chosen by this class; every value is bound by prepare().
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
                if ( ! is_array($rows) || ! empty($wpdb->last_error)) {
                    // The filter could not run on this database: read the tables whole instead.
                    return $full ? null : $this->meta_hits($ids, $deadline, true);
                }
                foreach ($rows as $row) {
                    $last = (int) $row->rid;
                    if (OLIMC_Acf_Meta_Scanner::is_plugin_key( (string) $row->rkey) || ! isset($row->rval) || $row->rval === '') {
                        continue;
                    }
                    foreach (OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value($row->rval, $resolve, $known, $count_keys) as $found) {
                        if (isset($set[ (int) $found ])) {
                            $hits[ (int) $found ] = true;
                        }
                    }
                }
                if (count($hits) >= count($ids)) {
                    return $hits;
                }
            } while (count($rows) === $page);
        }
        return $hits;
    }

    /**
     * Files of the active theme and its parent that cite this media (an upload URL, or wp_get_attachment_*(ID)), read once per request.
     */
    private function collect_theme_files_now() {
        if ($this->usage_check_id === null || ! $this->begin_usage_query()) {
            return;
        }
        if (self::$theme_index === null) {
            $index = [
                'ids'   => [],
                'paths' => [],
            ];
            $dirs  = [ get_stylesheet_directory() ];
            if (get_template_directory() !== $dirs[0]) {
                $dirs[] = get_template_directory();
            }
            foreach ($dirs as $dir) {
                $this->index_theme_directory($dir, $index);
            }
            self::$theme_index = $index;
        }
        $id = $this->usage_check_id;
        if (isset(self::$theme_index['ids'][ $id ])) {
            $this->usage_check_hit = true;
            return;
        }
        $files = $this->attached_files_of([ $id ]);
        if ($files === null) {
            $this->usage_check_failed = true;
            return;
        }
        if (isset($files[ $id ]) && isset(self::$theme_index['paths'][ strtolower($files[ $id ]) ])) {
            $this->usage_check_hit = true;
        }
    }

    /**
     * @param string $dir   Theme directory.
     * @param array  $index Index being built (by reference).
     */
    private function index_theme_directory( $dir, array &$index ) {
        if ( ! is_dir($dir)) {
            return;
        }
        $skip = [ 'node_modules', '.git', 'vendor', 'dist', 'build' ];
        $it   = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            function ( $current ) use ( $skip ) {
                return $current->isDir() ? ! in_array($current->getFilename(), $skip, true) : true;
            }
        ));
        foreach ($it as $file) {
            if ( ! $file->isFile() || ! in_array(strtolower($file->getExtension()), [ 'php', 'css', 'js', 'html', 'htm', 'json' ], true) || $file->getSize() > 1048576) {
                continue;
            }
            $content = file_get_contents($file->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
            if ( ! $content) {
                continue;
            }
            foreach (OLIMC_Media_Usage_Parser::relative_upload_paths($content) as $path) {
                $path = trim($path, '"\'\\/ ');
                $index['paths'][ strtolower($path) ] = true;
                $index['paths'][ strtolower( (string) preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $path)) ] = true;
            }
            if (strtolower($file->getExtension()) === 'php' && preg_match_all('/wp_get_attachment_(?:image|url|image_url|image_src)\s*\(\s*(\d+)/', $content, $matches)) {
                foreach ($matches[1] as $found) {
                    $index['ids'][ (int) $found ] = true;
                }
            }
        }
    }

    /**
     * The setting "count a file attached to a content item as used": the media is attached to an item that still exists.
     */
    private function collect_attached_now() {
        if ($this->usage_check_id === null || get_option('olimc_count_attached', '0') !== '1' || ! $this->begin_usage_query()) {
            return;
        }
        global $wpdb;
        $parent = (int) $wpdb->get_var($wpdb->prepare("SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $this->usage_check_id));
        if ($parent <= 0) {
            return;
        }
        $exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_status != 'trash'", $parent));
        if ( ! empty($wpdb->last_error)) {
            $this->usage_check_failed = true;
            return;
        }
        if ($exists) {
            $this->usage_check_hit = true;
        }
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
    private function finish_usage_query( $ids_or_hit ) {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && ! empty($wpdb->last_error)) {
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
    private function value_is_usage_hit( $value ) {
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
    private function attachment_relative_paths( $id ) {
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
        if ( ! empty($wpdb->last_error)) {
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
    private function like_needles_for_attachment( $id ) {
        $id = (int) $id;
        $needles = [
            'wp-image-' . $id . '"',
            'wp-image-' . $id . ' ',
            'wp-image-' . $id . "'",
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
    private function prepared_like_or( array $columns, array $needles ) {
        global $wpdb;
        $parts = [];
        foreach ($columns as $column) {
            if ( ! $this->is_safe_identifier($column)) {
                continue;
            }
            foreach ($needles as $needle) {
                if ($needle === '') {
                    continue;
                }
                $parts[] = olimc_prepare(
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
    private function is_safe_identifier( $key ) {
        return is_string($key) && $key !== '' && (bool) preg_match('/^[A-Za-z0-9_]+$/', $key);
    }

    /**
     * Posts whose attached media a [gallery] shortcode shows without listing IDs
     * (no ids or include attribute: WordPress then uses the media attached to the post, or to the post named by id).
     *
     * @param string $content Post content or excerpt.
     * @param int    $post_id Post holding the content (0 when unknown).
     * @return int[]
     */
    private function gallery_default_parents( $content, $post_id ) {
        $parents = [];
        if ( ! is_string($content) || stripos($content, '[gallery') === false) {
            return $parents;
        }
        if (preg_match_all('/\[gallery\b([^\]]*)\]/i', $content, $m)) {
            foreach ($m[1] as $attrs) {
                if (preg_match('/\b(?:ids|include)\s*=/i', $attrs)) {
                    continue;
                }
                if (preg_match('/\bid\s*=\s*["\']?(\d+)/i', $attrs, $idm)) {
                    $parents[] = (int) $idm[1];
                } elseif ($post_id > 0) {
                    $parents[] = $post_id;
                }
            }
        }
        return $parents;
    }

    /**
     * IDs of the media attached to the given posts.
     *
     * @param int[] $post_ids Parent post IDs.
     * @return int[]
     */
    private function attachments_of_posts( array $post_ids ) {
        global $wpdb;
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        $found    = [];
        foreach (array_chunk($post_ids, 500) as $chunk) {
            $list  = implode(',', $chunk); // Integers only.
            $rows  = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent IN ({$list})" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list is made of integers.
            );
            $found = array_merge($found, is_array($rows) ? $rows : []);
        }
        return $found;
    }

    /**
     * Attachment IDs mentioned in HTML / block markup / gallery shortcodes.
     *
     * @param string $content Post content, excerpt, or term description.
     * @return array<int|string>
     */
    private function attachment_ids_from_html( $content ) {
        $ids = [];
        if ( ! is_string($content) || $content === '') {
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
        if (preg_match_all('/\[(?:gallery|playlist|audio|video)[^\]]*?\b(?:ids|include)=(?:["\']([\d\s,]+)["\']|([\d,]+))/', $content, $matches)) {
            foreach (array_merge($matches[1], $matches[2]) as $list) {
                $ids = array_merge($ids, preg_split('/\s*,\s*/', trim($list)) ?: []);
            }
        }
        if (preg_match_all('/"ids"\s*:\s*\[([\d\s,]+)\]/', $content, $matches)) {
            foreach ($matches[1] as $list) {
                $ids = array_merge($ids, preg_split('/\s*,\s*/', trim($list)) ?: []);
            }
        }
        $paths = OLIMC_Media_Usage_Parser::relative_upload_paths($content);
        $this->prefetch_paths($paths);
        foreach ($paths as $path) {
            $found = $this->find_attachment_by_path($path);
            if ($found) {
                $ids[] = $found;
            }
        }
        return $ids;
    }

    /**
     * Integers of a serialized value, array keys included ("i:N;").
     *
     * @param string $serialized Serialized PHP.
     * @return int[]
     */
    private function serialized_integers( $serialized ) {
        if ( ! is_string($serialized) || $serialized === '' || ! preg_match_all('/i:(\d+);/', $serialized, $matches)) {
            return [];
        }
        return array_map('intval', array_unique($matches[1]));
    }

    /**
     * Attachment IDs stored in serialized widget / theme_mod payloads.
     *
     * @param string $serialized Serialized PHP.
     * @return array<int|string>
     */
    private function attachment_ids_from_serialized( $serialized ) {
        $ids = [];
        if ( ! is_string($serialized) || $serialized === '') {
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
    private function meta_value_references_attachment( $value, $id ) {
        $id = (int) $id;
        if (is_array($value) || is_object($value)) {
            $value = function_exists('maybe_serialize') ? maybe_serialize($value) : serialize($value);
        }
        if ( ! is_string($value) && ! is_numeric($value)) {
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
        if ( ! is_array($paths)) {
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
        if ( ! $this->begin_usage_query()) {
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
            if ( ! is_string($name) || $name === '') {
                continue;
            }
            $key_parts[] = $wpdb->prepare('meta_key = %s', $name);
            $key_parts[] = $wpdb->prepare('meta_key LIKE %s', '%_' . $wpdb->esc_like($name));
        }
        if ($key_parts === []) {
            return;
        }

        $id_len = (string) strlen( (string) $id);
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
            $wpdb->esc_like( (string) $id) . ',%',
            '%,' . $wpdb->esc_like( (string) $id) . ',%',
            '%,' . $wpdb->esc_like( (string) $id)
        );
        $where_sql = '(' . implode(' OR ', $key_parts) . ') AND ' . $value_sql;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $row = $wpdb->get_results(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE {$where_sql} LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        if ( ! empty($wpdb->last_error)) {
            $this->usage_check_failed = true;
            return;
        }
        if ( ! empty($row)) {
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

        if ( ! $this->begin_usage_query()) {
            return;
        }
        $opt_parts = [];
        foreach ($names as $name) {
            if ( ! is_string($name) || $name === '') {
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
        if ( ! empty($wpdb->last_error)) {
            $this->usage_check_failed = true;
            return;
        }
        if ( ! empty($opt_row)) {
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
        if ( ! $this->begin_usage_query()) {
            return;
        }
        global $wpdb;
        $paths = $this->attachment_relative_paths($this->usage_check_id);
        if ($paths === null) {
            $this->usage_check_failed = true;
            return;
        }
        $paths = array_values(array_filter($paths, function ( $path ) {
            return is_string($path) && $path !== '';
        }));
        if ($paths === []) {
            return;
        }
        $like_sql = $this->prepared_like_or([ 'option_value' ], $paths);
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
    public function get_attachment_info( $attachment_id ) {
        $post = get_post($attachment_id);
        if ( ! $post) {
			return null;
        }

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
     * Compact row for the results table (no URLs, they are built when a page is shown).
     *
     * @param int $attachment_id Attachment ID.
     * @return array|null
     */
    public function get_result_row( $attachment_id ) {
        $post = get_post($attachment_id);
        if ( ! $post) {
            return null;
        }
        $mime = (string) get_post_mime_type($attachment_id);
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        return [
            'id'       => (int) $attachment_id,
            'title'    => (string) $post->post_title,
            'file'     => $file,
            'mime'     => $mime,
            'size'     => (int) $this->calculate_total_size($attachment_id, $mime),
            'uploaded' => (string) $post->post_date,
        ];
    }

    /**
     * Calculate total disk size of an attachment (original + all thumbnails).
     */
    public function calculate_total_size( $attachment_id, $mime = '' ) {
        // In the quarantine the files are no longer at their public path: use the manifest.
        if (class_exists('OLIMC_Quarantine')) {
            $quarantined = OLIMC_Quarantine::total_bytes($attachment_id);
            if (null !== $quarantined) {
                return $quarantined;
            }
        }
        if ( ! $mime) {
            $mime = get_post_mime_type($attachment_id);
        }

        $total = 0;

        if (strpos($mime, 'image') !== false) {
            $meta = wp_get_attachment_metadata($attachment_id);
            if ( ! is_array($meta)) {
                $meta = [];
            }
            $upload_dir = wp_upload_dir();
            $basedir = $upload_dir['basedir'];

            // Main file
            if ( ! empty($meta['file'])) {
                $main_path = $basedir . '/' . $meta['file'];
                $total += olimc_filesize($main_path);
                $dir = dirname($main_path);
            } else {
                // No metadata: the file itself still has a size.
                $attached = get_attached_file($attachment_id);
                if ($attached) {
                    $total += olimc_filesize($attached);
                    $dir = dirname($attached);
                }
            }

            // Thumbnails
            if ( ! empty($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if ( ! empty($size['file']) && isset($dir)) {
                        $total += olimc_filesize($dir . '/' . $size['file']);
                    }
                }
            }

            // Original image (if scaled)
            if ( ! empty($meta['original_image']) && isset($dir)) {
                $total += olimc_filesize($dir . '/' . $meta['original_image']);
            }
        } else {
            $file = get_attached_file($attachment_id);
            if ($file) {
                $total = olimc_filesize($file);
            }
        }

        return $total;
    }

    // ─── Collection methods ───────────────────────────────────────────

    private function collect_featured_images() {
        global $wpdb;
        if ( ! $this->begin_usage_query()) {
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
        if ( ! $this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $this->collect_post_content_images_for_id($this->usage_check_id);
            return;
        }
        $where_sql = $this->prepared_like_or([ 'post_content', 'post_excerpt' ], $this->content_prefilter_needles());
        if ($where_sql === null) {
            return;
        }
        $range = $this->aux_range === null ? [ 0, PHP_INT_MAX ] : $this->aux_range;
        $rows  = $wpdb->get_results(
            "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ({$where_sql}) AND post_status != 'trash' AND post_type NOT IN ('revision', 'attachment') AND ID BETWEEN " . (int) $range[0] . ' AND ' . (int) $range[1] // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments; the bounds are integers.
        );

        $attachment_ids = [];
        if (is_array($rows)) {
            $all_paths = [];
            foreach ($rows as $row) {
                $all_paths = array_merge(
                    $all_paths,
                    OLIMC_Media_Usage_Parser::relative_upload_paths(isset($row->post_content) ? $row->post_content : ''),
                    OLIMC_Media_Usage_Parser::relative_upload_paths(isset($row->post_excerpt) ? $row->post_excerpt : '')
                );
            }
            $this->prefetch_paths($all_paths);
            $gallery_parents = [];
            foreach ($rows as $row) {
                $attachment_ids = array_merge(
                    $attachment_ids,
                    $this->attachment_ids_from_html(isset($row->post_content) ? $row->post_content : ''),
                    $this->attachment_ids_from_html(isset($row->post_excerpt) ? $row->post_excerpt : '')
                );
                $row_id = isset($row->ID) ? (int) $row->ID : 0;
                $gallery_parents = array_merge(
                    $gallery_parents,
                    $this->gallery_default_parents(isset($row->post_content) ? $row->post_content : '', $row_id),
                    $this->gallery_default_parents(isset($row->post_excerpt) ? $row->post_excerpt : '', $row_id)
                );
            }
            $attachment_ids = array_merge($attachment_ids, $this->attachments_of_posts($gallery_parents));
        }

        $this->finish_usage_query($attachment_ids);
    }

    /**
     * One LIKE query (OR of precise needles) against published / draft / private / future content.
     *
     * @param int $id Attachment ID.
     */
    private function collect_post_content_images_for_id( $id ) {
        global $wpdb;
        if (self::$batch_content !== null) {
            if (self::$batch_content === false) {
                $computed = $this->batch_content_ids();
                if ($computed === null) {
                    $this->usage_check_failed = true;
                    return;
                }
                self::$batch_content = $computed;
            }
            $this->finish_usage_query(isset(self::$batch_content[ (int) $id ]));
            return;
        }
        $needles = $this->like_needles_for_attachment($id);
        if ($needles === null) {
            $this->usage_check_failed = true;
            return;
        }
        $where_sql = $this->prepared_like_or([ 'post_content', 'post_excerpt' ], $needles);
        if ($where_sql === null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
        $hit = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_type NOT IN ('revision', 'attachment') AND ({$where_sql}) LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is $wpdb->prepare() fragments.
        );
        $this->finish_usage_query($hit);
        $this->collect_gallery_parent_usage_for_id($id);
    }

    /**
     * A [gallery] without ids shows the media attached to a post: is this media attached to a post that shows it?
     *
     * @param int $id Attachment ID.
     */
    private function collect_gallery_parent_usage_for_id( $id ) {
        global $wpdb;
        $parent = (int) $wpdb->get_var($wpdb->prepare("SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", (int) $id));
        if ($parent <= 0) {
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')
             AND post_content LIKE %s
             AND (ID = %d OR post_content LIKE %s)",
            '%' . $wpdb->esc_like('[gallery') . '%',
            $parent,
            '%' . $wpdb->esc_like( (string) $parent ) . '%'
        ));
        if ( ! is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (in_array($parent, $this->gallery_default_parents($row->post_content, (int) $row->ID), true)) {
                $this->finish_usage_query( (int) $row->ID );
                return;
            }
        }
        $this->finish_usage_query(null);
    }

    private function collect_option_images() {
        if ( ! $this->begin_usage_query()) {
            return;
        }
        $ids = [];
        $theme_mods = function_exists('get_theme_mods') ? get_theme_mods() : [];
        if ( ! is_array($theme_mods)) {
            $theme_mods = [];
        }
        if ( ! empty($theme_mods['custom_logo'])) {
            $ids[] = (int) $theme_mods['custom_logo'];
        }
        if ( ! empty($theme_mods['header_image_data']) && ! empty($theme_mods['header_image_data']->attachment_id)) {
            $ids[] = (int) $theme_mods['header_image_data']->attachment_id;
        }
        if ( ! empty($theme_mods['background_image']) && function_exists('attachment_url_to_postid')) {
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
        $ids = array_merge($ids, $this->theme_mods_option_ids());
        $this->finish_usage_query($ids);
    }

    /**
     * Scan mode: IDs and upload paths held by every theme_mods_* option (any theme), keys included,
     * as the live check reads them.
     *
     * @return int[]
     */
    private function theme_mods_option_ids() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('theme_mods_') . '%'
        ));
        if ( ! is_array($rows) || ! empty($wpdb->last_error)) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $raw = (string) $row->option_value;
            $ids = array_merge($ids, $this->serialized_integers($raw));
            if (preg_match_all('/s:\d+:"(\d+)"/', $raw, $m)) {
                $ids = array_merge($ids, array_map('intval', $m[1]));
            }
            $this->merge_upload_paths_from_text($raw);
        }
        return $ids;
    }

    /**
     * @param int $id Attachment ID.
     */
    private function collect_theme_mods_option_for_id( $id ) {
        global $wpdb;
        if ( ! $this->begin_usage_query()) {
            return;
        }
        $needles = [
            'custom_logo";i:' . $id . ';',
            's:' . strlen( (string) $id) . ':"' . $id . '"',
            'i:' . $id . ';',
        ];
        $paths = $this->attachment_relative_paths($id);
        if ($paths === null) {
            $this->usage_check_failed = true;
            return;
        }
        $needles = array_merge($needles, $paths);
        $like_sql = $this->prepared_like_or([ 'option_value' ], $needles);
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
        if ($this->usage_check_id === null && ! class_exists('WooCommerce')) {
            return;
        }
        global $wpdb;
        if ( ! $this->begin_usage_query()) {
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
        if ($this->usage_check_id === null && ! class_exists('WooCommerce')) {
            return;
        }
        global $wpdb;
        if ( ! $this->begin_usage_query()) {
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
        if ( ! $this->begin_usage_query()) {
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
        global $wpdb;
        if ( ! $this->begin_usage_query()) {
            return;
        }
        // Every widget option (widget_text, widget_block, widget_media_*, custom HTML, plugin widgets).
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are validated by olimc_prepare(); fixed SQL fragments only.
        $rows = $wpdb->get_results(
            olimc_prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('widget_') . '%')
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        if ( ! is_array($rows)) {
            $this->finish_usage_query(null);
            return;
        }
        foreach ($rows as $row) {
            $widgets = OLIMC_Media_Usage_Parser::safe_unserialize($row->option_value);
            if ( ! is_array($widgets)) {
                continue;
            }
            $serialized = serialize($widgets);
            $strings    = [];
            $ids        = $this->attachment_ids_from_serialized($serialized);
            // Every integer, key or value ("i:N;"): the same rule as the live check.
            $ids = array_merge($ids, $this->serialized_integers($serialized));
            $this->widget_values($widgets, $strings, $ids);
            // The same reader as for post content: ids=, include=, wp-image-N, block "id", data-id, upload paths.
            $ids = array_merge($ids, $this->attachment_ids_from_html(implode("\n", $strings)));
            if ($this->usage_check_id !== null) {
                if (in_array($this->usage_check_id, array_map('intval', $ids), true)) {
                    $this->finish_usage_query(true);
                    return;
                }
                $this->merge_upload_paths_from_text($serialized);
                if ($this->usage_check_hit || $this->usage_check_failed) {
                    return;
                }
                continue;
            }
            $this->used_ids = array_merge($this->used_ids, $ids);
            $this->merge_upload_paths_from_text($serialized);
        }
        if ($this->usage_check_id !== null) {
            $this->collect_widget_options_for_id($this->usage_check_id);
        }
    }

    /**
     * Strings and media IDs held by a widget instance (any depth).
     *
     * @param mixed $value   Widget option value.
     * @param array $strings Collected strings (by reference).
     * @param array $ids     Collected media IDs (by reference).
     */
    private function widget_values( $value, array &$strings, array &$ids ) {
        if (is_string($value)) {
            $strings[] = $value;
            return;
        }
        if ( ! is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if ($key === 'ids' && is_array($item)) {
                foreach ($item as $one) {
                    $ids[] = (int) $one;
                }
                continue;
            }
            if ('attachment_id' === $key && ( is_int( $item ) || ctype_digit( (string) $item ) )) {
                $ids[] = (int) $item;
                continue;
            }
            $this->widget_values($item, $strings, $ids);
        }
    }

    /**
     * @param int $id Attachment ID.
     */
    private function collect_widget_options_for_id( $id ) {
        global $wpdb;
        if ( ! $this->begin_usage_query()) {
            return;
        }
        $needles = $this->like_needles_for_attachment($id);
        if ($needles === null) {
            $this->usage_check_failed = true;
            return;
        }
        $needles[] = '"attachment_id";i:' . $id . ';';
        $needles[] = 'i:' . $id . ';';
        $like_sql = $this->prepared_like_or([ 'option_value' ], $needles);
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
        if ( ! $this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $needles = $this->like_needles_for_attachment($this->usage_check_id);
            if ($needles === null) {
                $this->usage_check_failed = true;
                return;
            }
            $where_sql = $this->prepared_like_or([ 'meta_value' ], $needles);
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
                    if ( (int) $id > 0 && (int) $id < 999999999) {
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

        $dirs = [ $theme_dir ];
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
    private function scan_directory_for_uploads( $dir ) {
        if ( ! is_dir($dir)) {
			return;
        }

        $extensions = [ 'php', 'css', 'js', 'html', 'htm', 'json' ];
        $skip_dirs = [ 'node_modules', '.git', 'vendor', 'dist', 'build' ];

        $iterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($iterator, function ( $current, $key, $iterator ) use ( $skip_dirs ) {
            if ($current->isDir()) {
                return ! in_array($current->getFilename(), $skip_dirs);
            }
            return true;
        });
        $files = new RecursiveIteratorIterator($filter);

        foreach ($files as $file) {
            if ( ! $file->isFile()) {
				continue;
            }

            $ext = strtolower($file->getExtension());
            if ( ! in_array($ext, $extensions)) {
				continue;
            }

            // Skip very large files (> 1MB)
            if ($file->getSize() > 1048576) {
				continue;
            }

            $content = @file_get_contents($file->getPathname());
            if ( ! $content) {
				continue;
            }

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
        $range = $this->aux_range === null ? [ 0, PHP_INT_MAX ] : $this->aux_range;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_content LIKE %s
             AND post_content LIKE %s
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')
             AND ID BETWEEN %d AND %d",
            '%' . $wpdb->esc_like('background') . '%',
            $like,
            $range[0],
            $range[1]
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
        if ( ! $this->begin_usage_query()) {
            return;
        }
        if ($this->usage_check_id !== null) {
            $termmeta = isset($wpdb->termmeta) ? $wpdb->termmeta : $wpdb->prefix . 'termmeta';
            if ( ! $this->is_safe_identifier($termmeta)) {
                $this->usage_check_failed = true;
                return;
            }
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are validated by olimc_prepare(); fixed SQL fragments only.
            $hit = $wpdb->get_var(olimc_prepare(
                'SELECT meta_value FROM %i
                 WHERE meta_key = %s
                 AND meta_value = %d
                 LIMIT 1',
                $termmeta,
                'thumbnail_id',
                $this->usage_check_id
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
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
        if ( ! $this->begin_usage_query()) {
            return;
        }
        $term_taxonomy = isset($wpdb->term_taxonomy) ? $wpdb->term_taxonomy : $wpdb->prefix . 'term_taxonomy';
        if ( ! $this->is_safe_identifier($term_taxonomy)) {
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
            $like_sql = $this->prepared_like_or([ 'description' ], $needles);
            if ($like_sql === null) {
                return;
            }
            $from_sql = olimc_prepare('SELECT term_taxonomy_id FROM %i WHERE (', $term_taxonomy);
            $where_sql = $from_sql . $like_sql . ') LIMIT 1';
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is assembled from $wpdb->prepare() fragments.
            $hit = $wpdb->get_var($where_sql);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->finish_usage_query($hit);
            return;
        }
        $like = $this->upload_content_like();
        $rows = [];
        if ($like !== null) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are validated by olimc_prepare(); fixed SQL fragments only.
            $rows = $wpdb->get_col(olimc_prepare(
                'SELECT description FROM %i
                 WHERE description LIKE %s',
                $term_taxonomy,
                $like
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
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
    public function ids_sharing_files_with_used( array $used ) {
        $normalized = [];
        foreach ($used as $id => $_unused) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[ $id ] = 1;
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
                    if (isset($normalized[ $id ]) || isset($added[ $id ])) {
                        $hit = true;
                        break;
                    }
                }
                if ( ! $hit) {
                    continue;
                }
                foreach ($ids as $id) {
                    if ( ! isset($normalized[ $id ]) && ! isset($added[ $id ])) {
                        $added[ $id ] = 1;
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
    public function shared_relative_upload_paths( $attachment_id ) {
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
    public static function absolute_path_matches_relative( $absolute, array $relatives ) {
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
    public function absolute_file_is_shared( $absolute ) {
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $candidates = [ $absolute ];
        foreach ([ '.webp', '.avif' ] as $extension) {
            if (is_string($absolute) && strlen($absolute) > strlen($extension) && substr($absolute, -strlen($extension)) === $extension) {
                $candidates[] = substr($absolute, 0, -strlen($extension));
            }
        }
        foreach ($candidates as $candidate) {
            foreach ($index as $path => $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                if (self::absolute_path_matches_relative($candidate, [ $path ])) {
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
        self::$theme_index = null;
        if (self::$auto_batch) {
            self::$batch_set  = null;
            self::$batch_meta = false;
        }
        self::$auto_batch   = false;
        self::$single_calls = 0;
        self::$upload_path_index_cache = null;
        self::$upload_path_index_ready = false;
        self::$stem_index_cache = null;
    }

    /**
     * @var array<string, int[]>|null
     */
    private static $stem_index_cache = null;

    /**
     * Every path of one attachment, relative to the uploads folder: original,
     * -scaled, original_image, generated sizes and backup sizes.
     *
     * @param int $id Attachment ID.
     * @return string[]|null Null when the lookup failed (callers must not move or delete anything).
     */
    public function relative_upload_paths( $id ) {
        return $this->attachment_relative_paths( (int) $id );
    }

    /**
     * Whether another attachment owns a file with the same path without its extension
     * (photo.jpg and photo.png both own photo.webp). Null when the index is unreadable.
     *
     * @param int    $id  Attachment ID.
     * @param string $rel Relative upload path of a derived copy (any extension).
     * @return bool|null
     */
    public function stem_used_by_others( $id, $rel ) {
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $stem = preg_replace('/\.[^.\/]+$/', '', (string) $rel);
        if ( ! isset(self::$stem_index_cache[ $stem ])) {
            return false;
        }
        foreach ( (array) self::$stem_index_cache[ $stem ] as $owner ) {
            if ( (int) $owner !== (int) $id ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a relative upload path is referenced by more than one attachment.
     * Null when the index is unreadable.
     *
     * @param string $rel Relative upload path.
     * @return bool|null
     */
    public function relative_path_is_shared( $rel ) {
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        return isset($index[ $rel ]) && count($index[ $rel ]) > 1;
    }

    /**
     * @var array<string, int[]>|null Shared paths only.
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
        $failed = static function () use ( $wpdb ) {
            return ! empty($wpdb->last_error);
        };
        // Small values first (file names, backups), then the large serialized metadata in pages,
        // so the raw rows never sit in memory all at once.
        $files   = [];
        $backups = [];
        $small   = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value != ''",
            '_wp_attached_file',
            '_wp_attachment_backup_sizes'
        ));
        if ($failed() || ! is_array($small)) {
            self::$upload_path_index_cache = null;
            self::$upload_path_index_ready = true;
            return null;
        }
        foreach ($small as $row) {
            if ($row->meta_key === '_wp_attached_file') {
                $files[ (int) $row->post_id ] = (string) $row->meta_value;
            } else {
                $backups[ (int) $row->post_id ] = $row->meta_value;
            }
        }
        unset($small);
        $all    = [];
        $stems  = [];
        $seen   = [];
        $last   = 0;
        $handle = function ( $id, $file, $meta, $backup ) use ( &$all, &$stems ) {
            foreach ($this->relative_paths_for_attachment_row($file, $meta, $backup) as $path) {
                self::add_owner($all, $path, $id);
                self::add_owner($stems, preg_replace('/\.[^.\/]+$/', '', (string) $path), $id);
            }
        };
        do {
            $page = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' AND meta_id > %d ORDER BY meta_id ASC LIMIT 2000",
                '_wp_attachment_metadata',
                $last
            ));
            if ($failed() || ! is_array($page)) {
                self::$upload_path_index_cache = null;
                self::$upload_path_index_ready = true;
                return null;
            }
            foreach ($page as $row) {
                $last = (int) $row->meta_id;
                $id   = (int) $row->post_id;
                if (isset($seen[ $id ])) {
                    continue;
                }
                $seen[ $id ] = true;
                $handle($id, isset($files[ $id ]) ? $files[ $id ] : '', $row->meta_value, isset($backups[ $id ]) ? $backups[ $id ] : '');
            }
            $count = count($page);
            unset($page);
        } while ($count === 2000);
        foreach ($files as $id => $file) {
            if ( ! isset($seen[ $id ])) {
                $handle($id, $file, '', isset($backups[ $id ]) ? $backups[ $id ] : '');
                $seen[ $id ] = true;
            }
        }
        foreach ($backups as $id => $backup) {
            if ( ! isset($seen[ $id ])) {
                $handle($id, '', '', $backup);
            }
        }
        unset($files, $backups, $seen);
        $index = [];
        foreach ($all as $path => $owners) {
            if (is_array($owners)) {
                $index[ $path ] = $owners;
            }
        }
        unset($all);
        self::$stem_index_cache = $stems;
        self::$upload_path_index_cache = $index;
        self::$upload_path_index_ready = true;
        return $index;
    }

    /**
     * @param array<string, int|int[]> $map   Key => owner ID, or list of owner IDs.
     * @param string                   $key   Key.
     * @param int                      $owner Attachment ID.
     */
    private static function add_owner( array &$map, $key, $owner ) {
        if ( ! isset($map[ $key ])) {
            $map[ $key ] = $owner;
        } elseif (is_int($map[ $key ])) {
            if ($map[ $key ] !== $owner) {
                $map[ $key ] = [ $map[ $key ], $owner ];
            }
        } elseif ( ! in_array($owner, $map[ $key ], true)) {
            $map[ $key ][] = $owner;
        }
    }

    /**
     * @return string Uploads basedir, or an empty string when WordPress is not loaded.
     */
    private static function upload_basedir() {
        if ( ! function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir(null, false);
        if ( ! is_array($uploads) || empty($uploads['basedir']) || ! is_string($uploads['basedir'])) {
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
    private function relative_paths_for_attachment_row( $attached_file, $meta_value, $backup_value = '' ) {
        $paths = [];
        $attached_file = $this->normalize_relative_upload_path($attached_file);
        if ($attached_file !== '') {
            $paths[] = $attached_file;
        }
        $meta = $this->decode_attachment_meta($meta_value);
        $base = $attached_file;
        if (is_array($meta) && ! empty($meta['file']) && is_string($meta['file'])) {
            $file = $this->normalize_relative_upload_path($meta['file']);
            if ($file !== '') {
                $paths[] = $file;
                $base = $file;
            }
        }
        $dir = ( $base !== '' && strpos($base, '/') !== false ) ? dirname($base) : '';
        if ($dir === '.') {
            $dir = '';
        }
        if (is_array($meta) && ! empty($meta['original_image']) && is_string($meta['original_image'])) {
            $paths[] = $this->join_upload_dir($dir, $meta['original_image']);
        }
        if (is_array($meta) && ! empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (is_array($size) && ! empty($size['file']) && is_string($size['file'])) {
                    $paths[] = $this->join_upload_dir($dir, $size['file']);
                }
            }
        }
        $backup = $this->decode_attachment_meta($backup_value);
        if (is_array($backup)) {
            foreach ($backup as $size) {
                if (is_array($size) && ! empty($size['file']) && is_string($size['file'])) {
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
    private function decode_attachment_meta( $value ) {
        if (is_array($value)) {
            return $value;
        }
        if ( ! is_string($value) || $value === '') {
            return null;
        }
        $data = OLIMC_Media_Usage_Parser::safe_unserialize($value);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $dir  Directory relative to uploads.
     * @param string $file File name or relative path.
     * @return string
     */
    private function join_upload_dir( $dir, $file ) {
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
    private function normalize_relative_upload_path( $path ) {
        if ( ! is_string($path)) {
            return '';
        }
        $path = str_replace('\\', '/', trim($path));
        return ltrim($path, '/');
    }

    // ─── Utility ──────────────────────────────────────────────────────

    /**
     * Resolve many relative upload paths with a few queries instead of one per path.
     *
     * Looking a path up costs a scan of the attached-file rows, so thousands of
     * single lookups would not fit in one scan step on a large library. The
     * result lands in the same cache as find_attachment_by_path().
     *
     * @param string[] $paths Relative upload paths (as found in content).
     */
    private function prefetch_paths( array $paths ) {
        global $wpdb;
        $wanted = [];
        foreach ( $paths as $path ) {
            $path = trim( (string) $path, '"\'\\/ ' );
            if ( '' === $path ) {
                continue;
            }
            $clean = preg_replace( '/-\d+x\d+(\.\w+)$/', '$1', $path );
            if ( isset( $this->path_cache[ $clean ] ) ) {
                continue;
            }
            $wanted[ $clean ] = $path;
        }
        if ( count( $wanted ) < 2 ) {
            return;
        }
        $lookups = [];
        foreach ( $wanted as $clean => $path ) {
            $lookups[ $clean ] = true;
            $lookups[ $path ]  = true;
        }
        $found = [];
        foreach ( array_chunk( array_keys( $lookups ), 250 ) as $chunk ) {
            $marks = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the list only holds %s placeholders.
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$marks})", $chunk ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            if ( ! is_array( $rows ) ) {
                return; // The single lookups will run and report the failure themselves.
            }
            foreach ( $rows as $row ) {
                if ( ! isset( $found[ $row->meta_value ] ) ) {
                    $found[ $row->meta_value ] = (int) $row->post_id;
                }
            }
        }
        foreach ( $wanted as $clean => $path ) {
            $id = isset( $found[ $clean ] ) ? $found[ $clean ] : ( isset( $found[ $path ] ) ? $found[ $path ] : 0 );
            $this->path_cache[ $clean ] = $id;
        }
    }

    /**
     * Find attachment ID from a partial upload path (e.g. "2025/06/photo.jpg").
     * Caches lookups for performance.
     */
    private function find_attachment_by_path( $path ) {
        global $wpdb;
        // Clean path
        $path = trim($path, '"\'\\/ ');
        // Remove thumbnail size suffix for lookup (e.g. photo-300x200.jpg -> photo.jpg)
        $clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $path);

        if (isset($this->path_cache[ $clean ])) {
            return $this->path_cache[ $clean ];
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value = %s
             LIMIT 1",
            $clean
        ));

        // If not found, try with the original (might be a thumbnail filename)
        if ( ! $id && $clean !== $path) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'
                 AND meta_value = %s
                 LIMIT 1",
                $path
            ));
        }

        $this->path_cache[ $clean ] = $id ? (int) $id : 0;
        return $this->path_cache[ $clean ];
    }

    /**
     * LIKE patterns that pre-select the content worth reading: an upload URL, or any way
     * WordPress and page builders cite a media by ID (classic and block galleries, wp-image-N, block "id").
     *
     * @return string[] Raw substrings.
     */
    private function content_prefilter_needles() {
        $needles  = [ OLIMC_Media_Usage_Parser::upload_search_fragment() ];
        $needles  = array_merge($needles, [ 'wp-image-', '[gallery', '[playlist', 'wp:gallery', '"id":', '"ids":', 'data-id=', 'attachment_id=' ]);
        return $needles;
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
    private function merge_upload_paths_from_text( $text ) {
        if ($this->usage_check_id !== null) {
            $paths = $this->attachment_relative_paths($this->usage_check_id);
            if ($paths === null) {
                $this->usage_check_failed = true;
                return;
            }
            if ( ! is_string($text) || $text === '') {
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
        $paths = OLIMC_Media_Usage_Parser::relative_upload_paths($text);
        $this->prefetch_paths($paths);
        foreach ($paths as $path) {
            $id = $this->find_attachment_by_path($path);
            if ($id) {
                $this->used_ids[] = $id;
            }
        }
    }
}
