<?php
/**
 * ACF API path, raw-meta fallback, clone-in-flexible regression, and a 500k-row benchmark.
 *
 * Usage: php tests/run-acf-scan-tests.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['olimc_mock_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		global $olimc_mock_options;
		if ( is_array( $olimc_mock_options ) && array_key_exists( $option, $olimc_mock_options ) ) {
			return $olimc_mock_options[ $option ];
		}
		return $default;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key, $single = false ) {
		return $single ? '' : [];
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		unset( $hook, $args );
		return $value;
	}
}
if ( ! function_exists( 'get_theme_mods' ) ) {
	/**
	 * @return array
	 */
	function get_theme_mods() {
		return [];
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * @return array
	 */
	function wp_upload_dir( $time = null, $create_dir = true ) {
		unset( $time, $create_dir );
		return [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		];
	}
}
if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single Single.
	 * @param string $plural Plural.
	 * @param int    $number Count.
	 * @param string $domain Domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = '' ) {
		unset( $domain );
		return ( (int) $number === 1 ) ? $single : $plural;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-media-usage-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-acf-meta-scanner.php';

$failures = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Message.
 */
function olimc_scan_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$failures++;
		return;
	}
	echo "OK: {$message}\n";
}

/**
 * Minimal wpdb over SQLite. Counts queries so the benchmark can reject per-key LIKE scans.
 */
class OLIMC_Fake_Wpdb {
	public $posts    = 'posts';
	public $postmeta = 'postmeta';
	public $termmeta = 'termmeta';
	public $term_taxonomy = 'term_taxonomy';
	public $usermeta = 'usermeta';
	public $options  = 'options';
	public $prefix   = 'wp_';
	public $last_error = '';
	public $queries  = [];
	public $fail_reads = false;

	/** @var PDO */
	private $pdo;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $sql, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[dfs]/',
			function ( $match ) use ( &$args, &$index ) {
				$value = $args[ $index ] ?? '';
				$index++;
				if ( $match[0] === '%d' ) {
					return (string) (int) $value;
				}
				if ( $match[0] === '%f' ) {
					return (string) (float) $value;
				}
				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$sql
		);
	}

	public function get_var( $sql ) {
		$rows = $this->run( $sql, 'var' );
		return $rows;
	}

	public function get_col( $sql ) {
		return $this->run( $sql, 'col' );
	}

	public function get_results( $sql ) {
		return $this->run( $sql, 'results' );
	}

	private function run( $sql, $mode ) {
		$this->queries[] = $sql;
		if ( $this->fail_reads ) {
			$this->last_error = 'forced failure';
			return $mode === 'var' ? null : [];
		}
		$this->last_error = '';
		try {
			$statement = $this->pdo->query( $sql );
			if ( $mode === 'var' ) {
				$value = $statement->fetchColumn();
				return $value === false ? null : $value;
			}
			if ( $mode === 'col' ) {
				return $statement->fetchAll( PDO::FETCH_COLUMN );
			}
			return $statement->fetchAll( PDO::FETCH_OBJ );
		} catch ( Exception $e ) {
			$this->last_error = $e->getMessage();
			return $mode === 'var' ? null : [];
		}
	}
}

$GLOBALS['olimc_acf_enabled'] = false;

/**
 * @return array
 */
function acf_get_field_groups() {
	if ( empty( $GLOBALS['olimc_acf_enabled'] ) ) {
		return [];
	}
	return [
		[ 'key' => 'group_concert' ],
		[ 'key' => 'group_blocks' ],
		[ 'key' => 'group_options' ],
	];
}

/**
 * @param string $key Group or field key.
 * @return array|null
 */
function acf_get_fields( $key ) {
	if ( empty( $GLOBALS['olimc_acf_enabled'] ) ) {
		return [];
	}
	if ( $key === 'group_concert' ) {
		return [
			[ 'key' => 'field_concert_image', 'name' => 'image', 'type' => 'image' ],
			[ 'key' => 'field_brochure', 'name' => 'brochure', 'type' => 'file' ],
			[ 'key' => 'field_portrait', 'name' => 'portrait', 'type' => 'image' ],
			[
				'key'   => 'field_flexible_components_highlight-image_image',
				'name'  => 'highlight-image',
				'type'  => 'image',
			],
			[
				'key'        => 'field_hero',
				'name'       => 'hero',
				'type'       => 'group',
				'sub_fields' => [
					[ 'key' => 'field_shot', 'name' => 'shot', 'type' => 'image' ],
				],
			],
			[
				'key'        => 'field_shows',
				'name'       => 'shows',
				'type'       => 'repeater',
				'sub_fields' => [
					[ 'key' => 'field_photo', 'name' => 'photo', 'type' => 'image' ],
				],
			],
		];
	}
	if ( $key === 'group_blocks' ) {
		return [
			[
				'key'     => 'field_blocks',
				'name'    => 'content_blocks',
				'type'    => 'flexible_content',
				'layouts' => [
					[
						'name'       => 'carousel',
						'sub_fields' => [
							[
								'key'   => 'field_clone_carousel',
								'name'  => 'image_carousel',
								'type'  => 'clone',
								'clone' => [ 'group_carousel' ],
							],
						],
					],
				],
			],
		];
	}
	if ( $key === 'group_carousel' ) {
		return [
			[ 'key' => 'field_carousel_images', 'name' => 'images', 'type' => 'gallery' ],
		];
	}
	if ( $key === 'group_options' ) {
		return [
			[ 'key' => 'field_banner', 'name' => 'banner', 'type' => 'image' ],
		];
	}
	return null;
}

/**
 * @param string $key Field key.
 * @return array|null
 */
function acf_get_field( $key ) {
	if ( $key === 'field_carousel_images' ) {
		return [ 'key' => 'field_carousel_images', 'name' => 'images', 'type' => 'gallery' ];
	}
	return null;
}

$GLOBALS['olimc_acf_enabled'] = true;
$scanner                      = new OLIMC_Scanner();
$targets                      = $scanner->get_acf_media_field_targets();
olimc_scan_assert( is_array( $targets ), 'ACF API resolves field groups' );
olimc_scan_assert( in_array( 'image', $targets['names'], true ), 'concert image field name collected' );
olimc_scan_assert( in_array( 'images', $targets['names'], true ), 'cloned gallery field name collected' );
olimc_scan_assert( in_array( 'photo', $targets['names'], true ), 'repeater image sub-field collected' );
olimc_scan_assert( in_array( 'shot', $targets['names'], true ), 'group image sub-field collected' );
olimc_scan_assert( in_array( 'field_concert_image', $targets['keys'], true ), 'concert image field key collected' );
olimc_scan_assert( in_array( 'field_carousel_images', $targets['keys'], true ), 'clone gallery field key collected' );
olimc_scan_assert( in_array( 'highlight-image', $targets['names'], true ), 'hyphenated ACF field name collected' );
olimc_scan_assert(
	in_array( 'field_flexible_components_highlight-image_image', $targets['keys'], true ),
	'hyphenated ACF field key collected'
);
olimc_scan_assert(
	$scanner->acf_meta_key_matches_field( 'content_blocks_4_image_carousel_images', 'images' ),
	'clone inside flexible content matches suffixed meta key'
);

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT)' );
$pdo->exec( 'CREATE TABLE postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE termmeta (meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)' );

$insert_post = $pdo->prepare( 'INSERT INTO posts (ID, post_type, post_status) VALUES (?, ?, ?)' );
for ( $id = 1; $id <= 10000; $id++ ) {
	$insert_post->execute( [ $id, 'attachment', 'inherit' ] );
}
// Real-world false positives: hyphenated ACF keys, outside the 1..10000 filler set.
$insert_post->execute( [ 37455, 'attachment', 'inherit' ] );
$insert_post->execute( [ 37681, 'attachment', 'inherit' ] );

$pdo->beginTransaction();
for ( $base = 1; $base <= 500000; $base += 500 ) {
	$tuples = [];
	for ( $offset = 0; $offset < 500; $offset++ ) {
		$meta_id = $base + $offset;
		$tuples[] = "({$meta_id},1,'noise','n')";
	}
	$pdo->exec( 'INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES ' . implode( ',', $tuples ) );
}
$pdo->commit();

$array_value = serialize(
	[
		'ID'  => 101,
		'id'  => 101,
		'url' => 'https://example.com/wp-content/uploads/2026/02/not-a-file.jpg',
	]
);
$gallery_value = serialize( [ 103, 104 ] );
$clone_value   = serialize( [ 105, 106 ] );
$builder_value = serialize( [ 'id' => 221 ] );

$seed = [
	[ 500001, 'image', '100' ],
	[ 500002, 'image', $array_value ],
	[ 500003, 'image', 'https://example.com/wp-content/uploads/2026/02/url-pastille.jpg' ],
	[ 500004, '_wp_attached_file', '2026/02/url-pastille.jpg' ],
	[ 500005, 'images', $gallery_value ],
	[ 500006, '_content_blocks_4_image_carousel_images', 'field_carousel_images' ],
	[ 500007, 'content_blocks_4_image_carousel_images', $clone_value ],
	[ 500008, 'brochure', '112' ],
	[ 500009, '_shows_2_photo', 'field_photo' ],
	[ 500010, 'shows_2_photo', '109' ],
	[ 500011, '_hero_shot', 'field_shot' ],
	[ 500012, 'hero_shot', '111' ],
	[ 500013, 'highlight-image', '37455' ],
	[ 500014, '_highlight-image', 'field_flexible_components_highlight-image_image' ],
	[ 500015, '_flexible_components_highlight-image_image', 'field_flexible_components_highlight-image_image' ],
	[ 500016, 'flexible_components_highlight-image_image', '37681' ],
	[ 500017, 'image', 'https://example.com/app/uploads/2024/06/tour-url.jpg' ],
	[ 500018, '_wp_attached_file', '2024/06/tour-url.jpg' ],
	[ 500019, 'rank_math_facebook_image', '220' ],
	[ 500020, 'page_builder', $builder_value ],
	[ 500021, 'og_image_url', 'https://example.com/app/uploads/2024/06/tour-og.jpg' ],
	[ 500022, '_wp_attached_file', '2024/06/tour-og.jpg' ],
];
$insert_meta = $pdo->prepare( 'INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES (?, 2, ?, ?)' );
foreach ( $seed as $row ) {
	$insert_meta->execute( $row );
}
$pdo->prepare( 'INSERT INTO termmeta (meta_id, term_id, meta_key, meta_value) VALUES (1, 9, ?, ?)' )->execute( [ 'image', '108' ] );
$pdo->prepare( 'INSERT INTO usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (1, 4, ?, ?)' )->execute( [ 'portrait', '110' ] );
$pdo->prepare( 'INSERT INTO options (option_id, option_name, option_value) VALUES (1, ?, ?)' )->execute( [ '_options_banner', 'field_banner' ] );
$pdo->prepare( 'INSERT INTO options (option_id, option_name, option_value) VALUES (2, ?, ?)' )->execute( [ 'options_banner', '107' ] );
$plugin_results = serialize(
	[
		[
			'id'  => 50,
			'url' => 'https://example.com/wp-content/uploads/2026/02/not-real.jpg',
		],
	]
);
$pdo->prepare( 'INSERT INTO options (option_id, option_name, option_value) VALUES (3, ?, ?)' )->execute( [ 'olimc_scan_results', $plugin_results ] );
$pdo->prepare( 'INSERT INTO options (option_id, option_name, option_value) VALUES (4, ?, ?)' )->execute( [ '_transient_olimc_scan', '51' ] );
$pdo->prepare( 'INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES (500023, 2, ?, ?)' )->execute( [ 'olimc_internal', '52' ] );

// The attached file row is stored on the attachment post, but the lookup only needs the meta value.
$pdo->exec( "UPDATE postmeta SET post_id = 102 WHERE meta_id = 500004" );
$pdo->exec( "UPDATE postmeta SET post_id = 69161 WHERE meta_id = 500018" );
$pdo->exec( "UPDATE postmeta SET post_id = 222 WHERE meta_id = 500022" );

OLIMC_Media_Usage_Parser::set_upload_markers(
	[
		'https://example.com/wp-content/uploads',
		'https://example.com/app/uploads',
		'//example.com/app/uploads',
		'/app/uploads',
	]
);

/**
 * @param bool $acf_enabled Whether the ACF API path is used.
 * @return array{state: array, queries: int, likes: int, seconds: float}
 */
function olimc_run_scan( OLIMC_Fake_Wpdb $db, $acf_enabled ) {
	global $wpdb;
	$wpdb                         = $db;
	$db->queries                  = [];
	$db->fail_reads               = false;
	$GLOBALS['olimc_acf_enabled'] = $acf_enabled;
	$scanner                      = new OLIMC_Scanner();
	$engine                       = new OLIMC_Acf_Meta_Scanner( $scanner );
	$state                        = $engine->initial_state();
	$state['skip_auxiliary']      = true;
	$state['skip_classify']       = true;
	$started                      = microtime( true );
	$guard                        = 0;
	while ( ! in_array( $state['phase'], [ 'done', 'failed' ], true ) && $guard < 100000 ) {
		$state = $engine->step( $state, 60000 );
		$guard++;
	}
	$likes = 0;
	foreach ( $db->queries as $sql ) {
		if ( preg_match( "/LIKE\\s+'%_/", $sql ) ) {
			$likes++;
		}
	}
	return [
		'state'   => $state,
		'queries' => count( $db->queries ),
		'likes'   => $likes,
		'seconds' => microtime( true ) - $started,
		'sql'     => $db->queries,
	];
}

/**
 * @param array $used Used map.
 * @param int[] $ids  Expected IDs.
 * @param string $label Label.
 */
function olimc_assert_used( array $used, array $ids, $label ) {
	foreach ( $ids as $id ) {
		olimc_scan_assert( isset( $used[ $id ] ), "{$label} keeps attachment {$id}" );
	}
	olimc_scan_assert( ! isset( $used[50] ), "{$label} does not flag an unreferenced attachment" );
}

$expected = [ 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 37455, 37681, 69161, 220, 221, 222 ];

global $wpdb;
$wpdb = new OLIMC_Fake_Wpdb( $pdo );

$acf = olimc_run_scan( $wpdb, true );
olimc_scan_assert( $acf['state']['phase'] === 'done', 'ACF API scan finishes' );
olimc_scan_assert( $acf['state']['mode'] === 'acf', 'primary path uses the ACF API' );
olimc_assert_used( $acf['state']['used'], $expected, 'ACF path' );
olimc_scan_assert( $acf['likes'] === 0, 'ACF path issues no per-key LIKE scans (' . $acf['likes'] . ')' );
olimc_scan_assert( $acf['queries'] < 500, 'ACF path query count stays batched (' . $acf['queries'] . ' queries)' );
olimc_scan_assert( $acf['seconds'] < 45, 'ACF path finishes in reasonable time (' . round( $acf['seconds'], 2 ) . 's)' );
$saw_name      = false;
$saw_nested    = false;
$saw_field_key = false;
foreach ( $acf['sql'] as $sql ) {
	if ( strpos( $sql, "'highlight-image'" ) !== false ) {
		$saw_name = true;
	}
	if ( strpos( $sql, "'flexible_components_highlight-image_image'" ) !== false ) {
		$saw_nested = true;
	}
	if ( strpos( $sql, "'field_flexible_components_highlight-image_image'" ) !== false ) {
		$saw_field_key = true;
	}
}
olimc_scan_assert( $saw_name && $saw_nested && $saw_field_key, 'hyphenated ACF keys are bound in SQL instead of being skipped' );
olimc_scan_assert( isset( $acf['state']['used'][37455] ), 'ACF path keeps hyphenated highlight-image attachment 37455' );
olimc_scan_assert( isset( $acf['state']['used'][37681] ), 'ACF path keeps nested hyphenated key attachment 37681' );
olimc_scan_assert( isset( $acf['state']['used'][69161] ), 'ACF path keeps Bedrock URL return format attachment 69161' );
olimc_scan_assert( isset( $acf['state']['used'][220] ), 'ACF path keeps non-ACF numeric meta attachment 220' );
olimc_scan_assert( isset( $acf['state']['used'][221] ), 'ACF path keeps non-ACF serialized meta attachment 221' );
olimc_scan_assert( isset( $acf['state']['used'][222] ), 'ACF path keeps non-ACF upload URL in meta attachment 222' );
olimc_scan_assert( ! isset( $acf['state']['used'][51] ), 'plugin transient is not treated as usage' );
olimc_scan_assert( ! isset( $acf['state']['used'][52] ), 'plugin meta is not treated as usage' );
echo 'ACF path: ' . $acf['queries'] . ' queries in ' . round( $acf['seconds'], 2 ) . "s\n";

$fallback = olimc_run_scan( $wpdb, false );
olimc_scan_assert( $fallback['state']['phase'] === 'done', 'fallback scan finishes' );
olimc_scan_assert( $fallback['state']['mode'] === 'fallback', 'inactive ACF uses the raw-meta fallback' );
olimc_assert_used( $fallback['state']['used'], $expected, 'fallback' );
olimc_scan_assert( $fallback['likes'] === 0, 'fallback issues no per-key LIKE scans' );
olimc_scan_assert( $fallback['queries'] < 400, 'fallback query count stays batched (' . $fallback['queries'] . ' queries)' );
olimc_scan_assert( $fallback['seconds'] < 60, 'fallback finishes in reasonable time (' . round( $fallback['seconds'], 2 ) . 's)' );
echo 'Fallback: ' . $fallback['queries'] . ' queries in ' . round( $fallback['seconds'], 2 ) . "s\n";
olimc_scan_assert( ! isset( $fallback['state']['used'][51] ), 'fallback ignores the plugin transient' );
olimc_scan_assert( ! isset( $fallback['state']['used'][52] ), 'fallback ignores plugin meta' );

$wpdb->fail_reads = true;
$engine           = new OLIMC_Acf_Meta_Scanner( new OLIMC_Scanner() );
$failed           = $engine->initial_state();
olimc_scan_assert( $failed['phase'] === 'failed', 'database error fails the scan' );
olimc_scan_assert( ! OLIMC_Acf_Meta_Scanner::may_mark_unused( $failed ), 'a failed scan marks nothing unused' );
olimc_scan_assert( $failed['pending_unused'] === [], 'a failed scan has no unused list' );
olimc_scan_assert( $failed['pending_delta'] === [], 'a failed scan has no pending delta' );

$admin_source  = file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
$cron_start    = strpos( $admin_source, 'function run_scheduled_cleanup' );
$cron_end      = strpos( $admin_source, 'function persist_scan_progress' );
$cron_body     = substr( $admin_source, $cron_start, $cron_end - $cron_start );
olimc_scan_assert( strpos( $cron_body, 'wp_trash_post' ) === false, 'cron never trashes posts' );
olimc_scan_assert( strpos( $cron_body, 'wp_delete_attachment' ) === false, 'cron never deletes attachments' );
olimc_scan_assert( strpos( $cron_body, 'reschedule_scan_soon' ) !== false, 'incomplete cron reschedules itself' );
olimc_scan_assert( strpos( $cron_body, "state_option_key('cron')" ) !== false, 'cron uses its own state option' );
olimc_scan_assert( strpos( $admin_source, "state_option_key('ajax')" ) !== false, 'AJAX uses its own state option' );
olimc_scan_assert( strpos( $admin_source, 'acquire_lock' ) !== false, 'AJAX and cron take a scan lock' );
olimc_scan_assert( strpos( $admin_source, "unset(\$state['pending_delta'], \$state['pending_unused'])" ) !== false, 'progress option does not keep the unused list' );
olimc_scan_assert( strpos( $admin_source, "current_user_can('manage_options')" ) !== false, 'AJAX requires manage_options' );
olimc_scan_assert( strpos( $admin_source, "check_ajax_referer('olimc_nonce'" ) !== false, 'AJAX checks the nonce' );

$scanner_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scanner.php' );
olimc_scan_assert( strpos( $scanner_source, 'acf_include_fields' ) !== false, 'local ACF JSON is loaded via acf_include_fields' );
olimc_scan_assert( strpos( $scanner_source, '!$init_done && !$acf_init_done && function_exists(\'acf_init\')' ) !== false, 'acf_init() runs only before init' );
olimc_scan_assert( strpos( $scanner_source, 'max(5, $max - 5)' ) !== false, 'synchronous scans stop before max_execution_time' );
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );
olimc_scan_assert( strpos( $js, 'confirmWithPreview' ) !== false, 'delete and trash still preview the filename' );

$engine_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-acf-meta-scanner.php' );
olimc_scan_assert( strpos( $engine_source, 'function is_safe_meta_key' ) === false, 'meta keys are no longer filtered by a character whitelist' );
olimc_scan_assert( strpos( $engine_source, 'generic_post' ) !== false, 'ACF mode still walks non-ACF meta' );

$pastille = '2026/02/concert-badge-1080-en.jpg';
$thumb    = 'concert-badge-1080-en-150x150.jpg';
$thumb_rel = '2026/02/' . $thumb;
$used_meta = serialize(
	[
		'file'  => $pastille,
		'sizes' => [
			'thumbnail' => [ 'file' => $thumb ],
		],
	]
);
$share_pdo = new PDO( 'sqlite::memory:' );
$share_pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$share_pdo->exec( 'CREATE TABLE postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$share_pdo->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_content TEXT, post_excerpt TEXT)' );
$share_pdo->exec( 'CREATE TABLE termmeta (meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$share_pdo->exec( 'CREATE TABLE term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, description TEXT)' );
$share_pdo->exec( 'CREATE TABLE options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)' );
$share_insert = $share_pdo->prepare( 'INSERT INTO postmeta (meta_id, post_id, meta_key, meta_value) VALUES (?, ?, ?, ?)' );
$backup_name = 'concert-badge-backup.jpg';
$backup_rel  = '2026/02/' . $backup_name;
$backup_meta = serialize(
	[
		'full-orig' => [ 'file' => $backup_name, 'width' => 10, 'height' => 10 ],
	]
);
$share_rows   = [
	[ 1, 61216, '_wp_attached_file', $pastille ],
	[ 2, 61216, '_wp_attachment_metadata', $used_meta ],
	[ 3, 59384, '_wp_attached_file', $pastille ],
	[ 4, 70001, '_wp_attached_file', $thumb_rel ],
	[ 5, 80001, '_wp_attached_file', '2024/01/unique-file.jpg' ],
	[ 6, 61216, '_wp_attachment_backup_sizes', $backup_meta ],
	[ 7, 70002, '_wp_attached_file', $backup_rel ],
];
foreach ( $share_rows as $row ) {
	$share_insert->execute( $row );
}
$share_db = new OLIMC_Fake_Wpdb( $share_pdo );
$wpdb     = $share_db;
OLIMC_Scanner::reset_upload_path_index();
$share_scanner = new OLIMC_Scanner();
$share_added   = $share_scanner->ids_sharing_files_with_used( [ 61216 => 1 ] );
olimc_scan_assert( is_array( $share_added ), 'shared-file lookup succeeds' );
olimc_scan_assert( isset( $share_added[59384] ), 'duplicate attachment 59384 is used because 61216 uses the same file' );
olimc_scan_assert( isset( $share_added[70001] ), 'attachment whose file is a size of a used attachment is used' );
olimc_scan_assert( isset( $share_added[70002] ), 'attachment whose file is a backup size of a used attachment is used' );
olimc_scan_assert( ! isset( $share_added[80001] ), 'an attachment with its own file stays unused' );
olimc_scan_assert( ! isset( $share_added[61216] ), 'the already-used attachment is not reported again' );
$share_likes = 0;
foreach ( $share_db->queries as $sql ) {
	if ( stripos( $sql, 'LIKE' ) !== false ) {
		$share_likes++;
	}
}
olimc_scan_assert( $share_likes === 0, 'shared-file lookup uses no LIKE (' . count( $share_db->queries ) . ' queries)' );
olimc_scan_assert( count( $share_db->queries ) === 1, 'shared-file lookup is one indexed meta_key query' );
$shared_paths = $share_scanner->shared_relative_upload_paths( 59384 );
olimc_scan_assert( $shared_paths === [ $pastille ], 'delete keeps the file still referenced by attachment 61216' );
olimc_scan_assert( $share_scanner->shared_relative_upload_paths( 80001 ) === [], 'a unique file may be deleted' );
$absolute = '/var/www/html/wp-content/uploads/' . $pastille;
olimc_scan_assert(
	OLIMC_Scanner::absolute_path_matches_relative( $absolute, $shared_paths ),
	'wp_delete_file path matches the shared relative upload'
);
olimc_scan_assert(
	! OLIMC_Scanner::absolute_path_matches_relative( '/var/www/html/wp-content/uploads/2024/01/unique-file.jpg', $shared_paths ),
	'a different file is not treated as shared'
);
$share_db->fail_reads = true;
OLIMC_Scanner::reset_upload_path_index();
olimc_scan_assert( $share_scanner->shared_relative_upload_paths( 59384 ) === null, 'a failed shared-file lookup blocks physical deletion' );
$share_db->fail_reads = false;
OLIMC_Scanner::reset_upload_path_index();

require_once dirname( __DIR__ ) . '/includes/class-admin.php';

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	function remove_action( $tag, $callback, $priority = 10 ) {
		$GLOBALS['olimc_removed_actions'][] = [ $tag, $callback, (int) $priority ];
		if ( isset( $GLOBALS['wp_filter'][ $tag ] ) && is_object( $GLOBALS['wp_filter'][ $tag ] ) ) {
			unset( $GLOBALS['wp_filter'][ $tag ]->callbacks[ (int) $priority ] );
		}
	}
}

$imagify_ran = [];
$imagify     = function ( $post_id ) use ( &$imagify_ran ) {
	$imagify_ran[] = (int) $post_id;
};
$simulate_delete = function ( $post_id ) use ( $imagify ) {
	$post = (object) [
		'ID'        => (int) $post_id,
		'post_type' => 'attachment',
	];
	$decision = OLIMC_Admin::pre_delete_attachment( null, $post, true );
	if ( false === $decision ) {
		return false;
	}
	$imagify( $post_id );
	return true;
};
olimc_scan_assert( $simulate_delete( 59384 ) === false, 'shared attachment deletion is refused before Imagify' );
olimc_scan_assert( $imagify_ran === [], 'Imagify delete_attachment hook does not run for a shared file' );
olimc_scan_assert( OLIMC_Admin::pre_trash_post( null, (object) [ 'ID' => 59384, 'post_type' => 'attachment' ], 'inherit' ) === false, 'a shared attachment cannot be trashed' );
$unique_abs = '/var/www/html/wp-content/uploads/2024/01/unique-file.jpg';
olimc_scan_assert( $simulate_delete( 80001 ) === true, 'a unique attachment can still be deleted' );
olimc_scan_assert( $imagify_ran === [ 80001 ], 'Imagify still runs for an attachment with its own file' );
olimc_scan_assert( OLIMC_Admin::pre_trash_post( null, (object) [ 'ID' => 80001, 'post_type' => 'attachment' ], 'inherit' ) === null, 'a unique attachment can be trashed' );

OLIMC_Scanner::reset_upload_path_index();
$share_db->fail_reads = false;
olimc_scan_assert( OLIMC_Admin::preserve_shared_upload_file( $absolute ) === '', 'wp_delete_file keeps a shared original from any caller' );
olimc_scan_assert( OLIMC_Admin::preserve_shared_upload_file( $absolute . '.webp' ) === '', 'wp_delete_file keeps the WebP sibling of a shared file' );
olimc_scan_assert( OLIMC_Admin::preserve_shared_upload_file( $absolute . '.avif' ) === '', 'wp_delete_file keeps the AVIF sibling of a shared file' );
olimc_scan_assert( OLIMC_Admin::preserve_shared_upload_file( $unique_abs ) === $unique_abs, 'wp_delete_file still removes a file that only one attachment uses' );

$hook = new stdClass();
$hook->callbacks = [
	10 => [
		'imagify_delete' => [ 'function' => $imagify ],
	],
];
$GLOBALS['wp_filter']['delete_attachment'] = $hook;
$GLOBALS['olimc_removed_actions']          = [];
$before_strip                              = count( $imagify_ran );
OLIMC_Admin::on_delete_attachment( 59384 );
olimc_scan_assert( isset( $GLOBALS['olimc_removed_actions'][0][0] ) && $GLOBALS['olimc_removed_actions'][0][0] === 'delete_attachment', 'Imagify callback is removed when a shared attachment is deleted' );
olimc_scan_assert( ! isset( $hook->callbacks[10] ), 'the Imagify callback is gone before it can delete WebP files' );
olimc_scan_assert( count( $imagify_ran ) === $before_strip, 'removing the Imagify hook does not run it' );

$admin_init = substr( $admin_source, (int) strpos( $admin_source, 'function init' ), 4000 );
olimc_scan_assert( strpos( $admin_init, "add_filter('pre_delete_attachment'" ) !== false, 'deletion is guarded for every caller' );
olimc_scan_assert( strpos( $admin_init, "add_filter('pre_trash_post'" ) !== false, 'trash is guarded for every caller' );
olimc_scan_assert( strpos( $admin_init, "add_filter('wp_delete_file'" ) !== false, 'wp_delete_file is guarded globally' );

if ( $failures > 0 ) {
	echo "\n{$failures} test(s) failed.\n";
	exit( 1 );
}

echo "\nAll ACF scan tests passed.\n";
exit( 0 );
