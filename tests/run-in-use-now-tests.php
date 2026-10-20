<?php
/**
 * Live usage check: is_attachment_in_use_now() and must_keep_attachment_files().
 *
 * Usage: php tests/run-in-use-now-tests.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['olimc_mock_options'] = [
	'olimc_scan_date' => '2026-10-20 12:00:00',
];
$GLOBALS['olimc_acf_enabled']  = true;
$GLOBALS['olimc_theme_mods']   = [];

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
		if ( $hook === 'olimc_must_keep_attachment' && isset( $GLOBALS['olimc_must_keep_filter'] ) ) {
			return (bool) call_user_func( $GLOBALS['olimc_must_keep_filter'], $value, $args[0] ?? 0 );
		}
		return $value;
	}
}
if ( ! function_exists( 'get_theme_mods' ) ) {
	/**
	 * @return array
	 */
	function get_theme_mods() {
		return isset( $GLOBALS['olimc_theme_mods'] ) && is_array( $GLOBALS['olimc_theme_mods'] )
			? $GLOBALS['olimc_theme_mods']
			: [];
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
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = '' ) {
		unset( $domain );
		return ( (int) $number === 1 ) ? $single : $plural;
	}
}
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text, $domain = '' ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'acf_get_field_groups' ) ) {
	/**
	 * @return array
	 */
	function acf_get_field_groups() {
		if ( empty( $GLOBALS['olimc_acf_enabled'] ) ) {
			return [];
		}
		return [ [ 'key' => 'group_concert' ] ];
	}
}
if ( ! function_exists( 'acf_get_fields' ) ) {
	/**
	 * @param string $key Group key.
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
				[ 'key' => 'field_gallery', 'name' => 'gallery', 'type' => 'gallery' ],
			];
		}
		return null;
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * Present so WooCommerce collectors run when the plugin is not loaded.
	 */
	class WooCommerce {
	}
}

require_once dirname( __DIR__ ) . '/includes/class-media-usage-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

$failures = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Message.
 */
function olimc_inuse_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$failures++;
		return;
	}
	echo "OK: {$message}\n";
}

/**
 * Minimal wpdb over SQLite.
 */
class OLIMC_Inuse_Fake_Wpdb {
	public $posts         = 'posts';
	public $postmeta      = 'postmeta';
	public $termmeta      = 'termmeta';
	public $term_taxonomy = 'term_taxonomy';
	public $usermeta      = 'usermeta';
	public $options       = 'options';
	public $prefix        = 'wp_';
	public $last_error    = '';
	public $queries       = [];
	public $fail_reads    = false;

	/** @var PDO */
	private $pdo;

	/**
	 * @param PDO $pdo SQLite connection.
	 */
	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * @param string $sql SQL.
	 * @return string
	 */
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

	/**
	 * @param string $sql SQL.
	 * @return mixed
	 */
	public function get_var( $sql ) {
		return $this->run( $sql, 'var' );
	}

	/**
	 * @param string $sql SQL.
	 * @return array
	 */
	public function get_col( $sql ) {
		return $this->run( $sql, 'col' );
	}

	/**
	 * @param string $sql SQL.
	 * @return array
	 */
	public function get_results( $sql ) {
		return $this->run( $sql, 'results' );
	}

	/**
	 * @param string $sql  SQL.
	 * @param string $mode var|col|results.
	 * @return mixed
	 */
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

/**
 * @return PDO
 */
function olimc_inuse_schema() {
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_content TEXT, post_excerpt TEXT)' );
	$pdo->exec( 'CREATE TABLE postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	$pdo->exec( 'CREATE TABLE termmeta (meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	$pdo->exec( 'CREATE TABLE term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, description TEXT)' );
	$pdo->exec( 'CREATE TABLE options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)' );
	return $pdo;
}

/**
 * @param PDO    $pdo  Connection.
 * @param int    $id   Attachment ID.
 * @param string $file Relative upload path.
 */
function olimc_inuse_seed_attachment( PDO $pdo, $id, $file ) {
	$pdo->prepare( 'INSERT INTO posts (ID, post_type, post_status, post_content, post_excerpt) VALUES (?, ?, ?, ?, ?)' )
		->execute( [ $id, 'attachment', 'inherit', '', '' ] );
	$meta = serialize(
		[
			'file'  => $file,
			'sizes' => [
				'thumbnail' => [ 'file' => basename( $file, '.jpg' ) . '-150x150.jpg' ],
			],
		]
	);
	$ins = $pdo->prepare( 'INSERT INTO postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' );
	$ins->execute( [ $id, '_wp_attached_file', $file ] );
	$ins->execute( [ $id, '_wp_attachment_metadata', $meta ] );
}

OLIMC_Media_Usage_Parser::set_upload_markers(
	[
		'https://example.com/wp-content/uploads',
		'//example.com/wp-content/uploads',
		'/wp-content/uploads',
	]
);

global $wpdb;
$pdo = olimc_inuse_schema();
olimc_inuse_seed_attachment( $pdo, 101, '2026/02/content.jpg' );
olimc_inuse_seed_attachment( $pdo, 102, '2026/02/featured.jpg' );
olimc_inuse_seed_attachment( $pdo, 103, '2026/02/woo-gallery.jpg' );
olimc_inuse_seed_attachment( $pdo, 104, '2026/02/acf-image.jpg' );
olimc_inuse_seed_attachment( $pdo, 105, '2026/02/lonely.jpg' );

$pdo->prepare( 'INSERT INTO posts (ID, post_type, post_status, post_content, post_excerpt) VALUES (?, ?, ?, ?, ?)' )
	->execute(
		[
			10,
			'post',
			'publish',
			'<img class="wp-image-101" src="https://example.com/wp-content/uploads/2026/02/content.jpg" />',
			'',
		]
	);
$pdo->prepare( 'INSERT INTO postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' )
	->execute( [ 20, '_thumbnail_id', '102' ] );
$pdo->prepare( 'INSERT INTO postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' )
	->execute( [ 30, '_product_image_gallery', '99,103,200' ] );
$pdo->prepare( 'INSERT INTO postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' )
	->execute( [ 40, 'image', '104' ] );
$pdo->prepare( 'INSERT INTO postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' )
	->execute( [ 40, '_image', 'field_concert_image' ] );

$wpdb = new OLIMC_Inuse_Fake_Wpdb( $pdo );
OLIMC_Scanner::reset_upload_path_index();

$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 101 ) === true, 'media used in post_content (wp-image + URL) is in use' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 101 ) === true, 'must_keep refuses a content-used attachment' );

$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 102 ) === true, 'featured image (_thumbnail_id) is in use' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 102 ) === true, 'must_keep refuses a featured image' );

$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 103 ) === true, 'WooCommerce gallery CSV ID is in use' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 103 ) === true, 'must_keep refuses a WooCommerce gallery image' );

$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 104 ) === true, 'ACF image field ID is in use' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 104 ) === true, 'must_keep refuses an ACF image' );

$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 105 ) === false, 'unreferenced attachment is not in use' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 105 ) === false, 'unused media may be deleted when a scan exists' );

$pdo->prepare( 'INSERT INTO posts (ID, post_type, post_status, post_content, post_excerpt) VALUES (?, ?, ?, ?, ?)' )
	->execute( [ 11, 'page', 'draft', '', 'See ids="106" in [gallery ids="106"]' ] );
olimc_inuse_seed_attachment( $pdo, 106, '2026/02/gallery.jpg' );
OLIMC_Scanner::reset_upload_path_index();
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 106 ) === true, 'gallery shortcode in post_excerpt is in use' );

$pdo->prepare( 'INSERT INTO posts (ID, post_type, post_status, post_content, post_excerpt) VALUES (?, ?, ?, ?, ?)' )
	->execute( [ 12, 'post', 'private', '<!-- wp:image {"id":107} --><figure></figure><!-- /wp:image -->', '' ] );
olimc_inuse_seed_attachment( $pdo, 107, '2026/02/block.jpg' );
OLIMC_Scanner::reset_upload_path_index();
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 107 ) === true, 'Gutenberg "id":ID block in private post is in use' );

$GLOBALS['olimc_theme_mods'] = [ 'custom_logo' => 108 ];
olimc_inuse_seed_attachment( $pdo, 108, '2026/02/logo.jpg' );
OLIMC_Scanner::reset_upload_path_index();
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 108 ) === true, 'custom_logo theme mod is in use' );
$GLOBALS['olimc_theme_mods'] = [];

$GLOBALS['olimc_mock_options']['site_icon'] = 109;
olimc_inuse_seed_attachment( $pdo, 109, '2026/02/icon.jpg' );
OLIMC_Scanner::reset_upload_path_index();
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 109 ) === true, 'site_icon option is in use' );
unset( $GLOBALS['olimc_mock_options']['site_icon'] );

$pdo->prepare( 'INSERT INTO termmeta (term_id, meta_key, meta_value) VALUES (?, ?, ?)' )
	->execute( [ 7, 'thumbnail_id', '110' ] );
olimc_inuse_seed_attachment( $pdo, 110, '2026/02/term.jpg' );
OLIMC_Scanner::reset_upload_path_index();
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 110 ) === true, 'term thumbnail_id is in use' );

$pdo->prepare( 'INSERT INTO options (option_name, option_value) VALUES (?, ?)' )
	->execute( [ 'some_plugin_banner', 'https://example.com/wp-content/uploads/2026/02/content.jpg' ] );
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 101 ) === true, 'option that contains the upload URL is in use' );

$likes_with_limit = 0;
$wpdb->queries    = [];
$scanner          = new OLIMC_Scanner();
$scanner->is_attachment_in_use_now( 105 );
foreach ( $wpdb->queries as $sql ) {
	if ( stripos( $sql, 'LIKE' ) !== false && preg_match( '/LIMIT\s+1/i', $sql ) ) {
		$likes_with_limit++;
	}
}
olimc_inuse_assert( $likes_with_limit > 0, 'single-ID LIKE queries use LIMIT 1 (' . $likes_with_limit . ')' );

$GLOBALS['olimc_mock_options']['olimc_whitelist'] = [ 105 ];
olimc_inuse_assert( OLIMC_Admin::is_attachment_whitelisted( 105 ) === true, 'whitelist helper detects the ID' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 105 ) === true, 'whitelisted media stays protected' );
unset( $GLOBALS['olimc_mock_options']['olimc_whitelist'] );

olimc_inuse_assert(
	OLIMC_Admin::used_media_kept_message( 1 ) === '1 used media item was kept.',
	'singular used-media message'
);
olimc_inuse_assert(
	OLIMC_Admin::used_media_kept_message( 3 ) === '3 used media items were kept.',
	'plural used-media message'
);

$GLOBALS['olimc_must_keep_filter'] = function ( $keep, $post_id ) {
	return (int) $post_id === 105 ? true : $keep;
};
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 105 ) === true, 'olimc_must_keep_attachment can force a keep' );
unset( $GLOBALS['olimc_must_keep_filter'] );

unset( $GLOBALS['olimc_mock_options']['olimc_scan_date'] );
$wpdb->fail_reads = true;
OLIMC_Scanner::reset_upload_path_index();
$scanner = new OLIMC_Scanner();
olimc_inuse_assert( $scanner->is_attachment_in_use_now( 105 ) === null, 'query error returns null' );
olimc_inuse_assert( OLIMC_Admin::must_keep_attachment_files( 105 ) === true, 'no scan + query error refuses deletion' );
$wpdb->fail_reads = false;
$GLOBALS['olimc_mock_options']['olimc_scan_date'] = '2026-10-20 12:00:00';
OLIMC_Scanner::reset_upload_path_index();

$admin_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
olimc_inuse_assert( strpos( $admin_source, "apply_filters('olimc_must_keep_attachment'" ) !== false, 'must_keep exposes olimc_must_keep_attachment' );
olimc_inuse_assert( strpos( $admin_source, 'used_media_kept_message' ) !== false, 'plugin actions surface the kept-media message' );
olimc_inuse_assert( strpos( $admin_source, "add_action('delete_attachment', [__CLASS__, 'on_delete_attachment'], 0)" ) !== false, 'delete_attachment still runs at priority 0' );
olimc_inuse_assert( strpos( $admin_source, "add_filter('pre_delete_attachment'" ) !== false, 'pre_delete_attachment guard is unchanged' );

$scanner_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scanner.php' );
olimc_inuse_assert( strpos( $scanner_source, 'function is_attachment_in_use_now' ) !== false, 'scanner exposes is_attachment_in_use_now' );
olimc_inuse_assert( strpos( $scanner_source, 'function collect_post_content_images_for_id' ) !== false, 'collectors have a single-ID variant' );

if ( $failures > 0 ) {
	echo "\n{$failures} test(s) failed.\n";
	exit( 1 );
}

echo "\nAll in-use-now tests passed.\n";
exit( 0 );
