<?php
/**
 * The live check (trash, empty) must cover at least every source the scan reads, with the same rules:
 * for each source a media used only there is "in use" for the live check, in a single check and in a batch,
 * and its trashing and its permanent deletion are refused. Run on a TEST site only:
 *
 *   OLIMC_TEST_OK=1 wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-live-sources.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( '1' !== getenv( 'OLIMC_TEST_OK' ) ) {
	echo "Refusing to run: this test trashes and deletes media. Set OLIMC_TEST_OK=1 to confirm this is a throwaway test site.\n";
	exit( 1 );
}
$fail = 0;
$t    = function ( $c, $m ) use ( &$fail ) {
	echo ( $c ? 'OK: ' : 'FAIL: ' ) . $m . "\n";
	if ( ! $c ) {
		++$fail;
	}
};
global $wpdb;
wp_set_current_user( 1 );
OLIMC_Results::install();
$up   = wp_upload_dir();
$base = rtrim( $up['basedir'], '/' );
$u    = substr( md5( uniqid( '', true ) ), 0, 8 );
$root_dir = sys_get_temp_dir() . "/olimc-ls-test-$u/olimc-trash";
$rootf    = function () use ( $root_dir ) {
	return $root_dir;
};
add_filter( 'olimc_quarantine_base_dir', $rootf );
if ( OLIMC_Quarantine::root_dir() !== $root_dir ) {
	echo "Refusing to run: the test quarantine folder is not in use.\n";
	exit( 1 );
}
$reset = function () {
	OLIMC_Scanner::reset_upload_path_index();
	clean_post_cache( 0 );
};
$folder = "olimc-ls-$u";
wp_mkdir_p( "$base/$folder" );
$n     = 0;
$media = [];
$mk    = function ( $name = null ) use ( &$n, $base, $folder, $u, &$media ) {
	++$n;
	$name = $name ?: "m$n-$u.jpg";
	file_put_contents( "$base/$folder/$name", "FILE$n" );
	file_put_contents( "$base/$folder/" . preg_replace( '/\.jpg$/', '-300x200.jpg', $name ), "THUMB$n" );
	$id = wp_insert_attachment( [ 'post_title' => "olimc-ls-$n", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], "$base/$folder/$name" );
	update_post_meta( $id, '_wp_attached_file', "$folder/$name" );
	update_post_meta( $id, '_wp_attachment_metadata', [ 'file' => "$folder/$name", 'sizes' => [ 'medium' => [ 'file' => preg_replace( '/\.jpg$/', '-300x200.jpg', $name ), 'width' => 3, 'height' => 2 ] ] ] );
	$media[] = $id;
	return [ $id, "$folder/$name" ];
};
$host = wp_insert_post( [ 'post_title' => "olimc-ls-host-$u", 'post_status' => 'draft', 'post_content' => 'x' ] );
$term = wp_insert_term( "olimc-ls-term-$u", 'category' );
$tid  = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
$extra_posts = [];
$cleanups    = [];

// name => [ expected scan result for the source, builder( $id, $rel, $url ) ]
$scenarios = [
	'post meta: the ID'                       => [ true, function ( $id ) use ( $host ) {
		update_post_meta( $host, 'wkls_num', $id );
	} ],
	'post meta: a list "7,ID,9"'              => [ true, function ( $id ) use ( $host ) {
		update_post_meta( $host, 'wkls_csv', "7,$id,9" );
	} ],
	'post meta: serialized, ID as a value'    => [ true, function ( $id ) use ( $host ) {
		update_post_meta( $host, 'wkls_ser', [ 'a' => [ 'b' => $id ] ] );
	} ],
	'post meta: serialized, ID as a key'      => [ true, function ( $id ) use ( $host ) {
		update_post_meta( $host, 'wkls_serkey', [ $id => 'x' ] );
	} ],
	'post meta: JSON {"id":ID}'               => [ true, function ( $id ) use ( $host ) {
		update_post_meta( $host, 'wkls_json', wp_json_encode( [ 'image' => [ 'id' => $id ] ] ) );
	} ],
	'post meta: JSON list [ID]'               => [ true, function ( $id ) use ( $host ) {
		update_post_meta( $host, 'wkls_jsonlist', '[5,' . $id . ']' );
	} ],
	'post meta: upload URL'                   => [ true, function ( $id, $rel, $url ) use ( $host ) {
		update_post_meta( $host, 'wkls_url', $url );
	} ],
	'post meta: JSON-escaped upload URL'      => [ true, function ( $id, $rel, $url ) use ( $host ) {
		update_post_meta( $host, 'wkls_urljson', wp_json_encode( [ 'u' => $url ] ) );
	} ],
	'post meta: URL of a size not in the metadata' => [ true, function ( $id, $rel, $url ) use ( $host ) {
		update_post_meta( $host, 'wkls_urlsize', preg_replace( '/\.jpg$/', '-768x512.jpg', $url ) );
	} ],
	'post meta: URL with an encoded name'     => [ true, function ( $id, $rel, $url ) use ( $host ) {
		update_post_meta( $host, 'wkls_urlenc', str_replace( ' ', '%20', $url ) );
	}, 'my file %s.jpg' ],
	'term meta: the ID'                       => [ true, function ( $id ) use ( $tid ) {
		update_term_meta( $tid, 'wkls_tnum', $id );
	} ],
	'term meta: serialized, ID as a key'      => [ true, function ( $id ) use ( $tid ) {
		update_term_meta( $tid, 'wkls_tkey', [ $id => 1 ] );
	} ],
	'term meta: upload URL'                   => [ true, function ( $id, $rel, $url ) use ( $tid ) {
		update_term_meta( $tid, 'wkls_turl', $url );
	} ],
	'user meta: the ID'                       => [ true, function ( $id ) {
		update_user_meta( 1, 'wkls_unum', $id );
	} ],
	'user meta: serialized, ID as a key'      => [ true, function ( $id ) {
		update_user_meta( 1, 'wkls_ukey', [ $id => 1 ] );
	} ],
	'user meta: upload URL'                   => [ true, function ( $id, $rel, $url ) {
		update_user_meta( 1, 'wkls_uurl', $url );
	} ],
	'option: the ID'                          => [ true, function ( $id ) {
		update_option( 'wkls_opt_num', $id, false );
	} ],
	'option: serialized, ID as a value'       => [ true, function ( $id ) {
		update_option( 'wkls_opt_ser', [ 'logo' => $id ], false );
	} ],
	'option: upload URL'                      => [ true, function ( $id, $rel, $url ) {
		update_option( 'wkls_opt_url', 'x ' . $url . ' y', false );
	} ],
	'option: JSON'                            => [ true, function ( $id ) {
		update_option( 'wkls_opt_json', wp_json_encode( [ 'image' => [ 'id' => $id ] ] ), false );
	} ],
	'option: a transient'                     => [ true, function ( $id ) {
		set_transient( 'wkls_tr', $id, 3600 );
	} ],
	'option: ID only as an array key (does not count, in the scan or in the live check)' => [ false, function ( $id ) {
		update_option( 'wkls_opt_key', [ $id => 'x' ], false );
	} ],
	'content of a pending post'               => [ true, function ( $id, $rel, $url ) use ( &$extra_posts ) {
		$extra_posts[] = wp_insert_post( [ 'post_title' => 'olimc-ls-pending', 'post_status' => 'pending', 'post_content' => '<img class="wp-image-' . $id . '" src="' . $url . '">' ] );
	} ],
	'a theme file'                            => [ true, function ( $id ) use ( $u, &$cleanups ) {
		$f = get_stylesheet_directory() . "/olimc-ls-$u.php";
		file_put_contents( $f, "<?php echo wp_get_attachment_image( $id );\n" );
		$cleanups[] = $f;
	} ],
	'a theme file citing the URL'             => [ true, function ( $id, $rel, $url ) use ( $u, &$cleanups ) {
		$f = get_stylesheet_directory() . "/olimc-ls-url-$u.css";
		file_put_contents( $f, "a{background:url($url)}\n" );
		$cleanups[] = $f;
	} ],
	'control: nothing refers to it'           => [ false, function () {
	} ],
];

// ── Phase A: refused to empty while used (the media is in the trash when the reference appears) ──
$items = [];
foreach ( $scenarios as $label => $sc ) {
	$name        = isset( $sc[2] ) ? null : null;
	list( $id, $rel ) = $mk( isset( $sc[2] ) ? sprintf( $sc[2], $n + 1 ) : null );
	$url         = $up['baseurl'] . '/' . $rel;
	$items[ $label ] = [ $id, $rel, $url, $sc[0], $sc[1] ];
}
$reset();
foreach ( $items as $label => $it ) {
	$r = OLIMC_Trash::trash( $it[0] );
	$t( $r['ok'], "A: [$label] trashed while nothing refers to it" );
}
foreach ( $items as $label => $it ) {
	call_user_func( $it[4], $it[0], $it[1], $it[2] );
}
$reset();
OLIMC_Scanner::begin_batch( array_column( $items, 0 ) );
foreach ( $items as $label => $it ) {
	$r = OLIMC_Trash::purge( $it[0] );
	if ( $it[3] ) {
		$t( ! $r['ok'] && null !== get_post( $it[0] ), "A: [$label] emptying is refused (code {$r['code']}), the media is still there" );
	} else {
		$t( $r['ok'], "A: [$label] emptying goes through (nothing counts)" );
	}
}
OLIMC_Scanner::end_batch();
$kept = [];
foreach ( $items as $label => $it ) {
	if ( null !== get_post( $it[0] ) ) {
		$r = OLIMC_Trash::restore( $it[0] );
		$t( $r['ok'], "A: [$label] restored" );
		$kept[ $label ] = $it;
	}
}

// ── Phase B: the scan and the live check agree, and trashing is refused ──
// The SQL filter for one media (what a lone check runs) is the same rule as the whole read (a batch).
$one = new ReflectionMethod( 'OLIMC_Scanner', 'meta_hits' );
$one->setAccessible( true );
foreach ( $kept as $label => $it ) {
	if ( ! preg_match( '/^(post meta|term meta|user meta|option|control)/', $label ) ) {
		continue; // Content and theme files are other sources.
	}
	$hits = $one->invoke( new OLIMC_Scanner(), [ $it[0] ], microtime( true ) + 60 );
	$t( is_array( $hits ) && $it[3] === isset( $hits[ $it[0] ] ), "B: [$label] targeted SQL filter for one media: " . ( $it[3] ? 'found' : 'not counted' ) );
}
$reset();
$scan = array_flip( array_map( 'intval', ( new OLIMC_Scanner() )->collect_used_ids() ) );
foreach ( $kept as $label => $it ) {
	$exp = $it[3];
	$t( $exp === isset( $scan[ $it[0] ] ), "B: [$label] the scan " . ( $exp ? 'finds it used' : 'does not count it' ) );
	$live = ( new OLIMC_Scanner() )->is_attachment_in_use_now( $it[0] );
	$t( $exp === $live, "B: [$label] live check (single) = " . var_export( $live, true ) );
}
$reset();
OLIMC_Scanner::begin_batch( array_column( $kept, 0 ) );
foreach ( $kept as $label => $it ) {
	$live = ( new OLIMC_Scanner() )->is_attachment_in_use_now( $it[0] );
	$t( $it[3] === $live, "B: [$label] live check (batch) = " . var_export( $live, true ) );
}
foreach ( $kept as $label => $it ) {
	$r = OLIMC_Trash::trash( $it[0] );
	if ( $it[3] ) {
		$t( ! $r['ok'] && 'used' === $r['code'] && 'inherit' === get_post_field( 'post_status', $it[0], 'raw' ), "B: [$label] trashing refused (used)" );
	} else {
		$t( $r['ok'], "B: [$label] trashing allowed" );
	}
}
OLIMC_Scanner::end_batch();
// No scan result may be "used" where the live check says "unused".
$stale = 0;
foreach ( $scan as $sid => $_ ) {
	if ( in_array( $sid, $media, true ) && false === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $sid ) ) {
		++$stale;
	}
}
$t( 0 === $stale, 'B: no media is used for the scan and unused for the live check (' . $stale . ')' );

// The setting "attached media counts as used".
list( $aid, $arel ) = $mk();
$pub = wp_insert_post( [ 'post_title' => 'olimc-ls-pub', 'post_status' => 'publish', 'post_content' => 'x' ] );
$extra_posts[] = $pub;
$wpdb->update( $wpdb->posts, [ 'post_parent' => $pub ], [ 'ID' => $aid ] );
clean_post_cache( $aid );
update_option( 'olimc_count_attached', '0' );
$t( false === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $aid ), 'attached media, setting off: not in use' );
update_option( 'olimc_count_attached', '1' );
$t( true === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $aid ), 'attached media, setting on: in use (as the scan counts it)' );
wp_trash_post( $pub );
$t( false === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $aid ), 'attached to a post that is in the WordPress trash: not in use' );
delete_option( 'olimc_count_attached' );

// A batch above the pre-filter limit reads the tables whole, once, with the same answers.
$many = $media;
while ( count( $many ) <= OLIMC_Scanner::META_PREFILTER_MAX + 1 ) {
	$many[] = 900000000 + count( $many );
}
$reset();
OLIMC_Scanner::begin_batch( $many );
$ok = true;
foreach ( $kept as $label => $it ) {
	if ( $it[3] !== ( new OLIMC_Scanner() )->is_attachment_in_use_now( $it[0] ) ) {
		$ok = false;
		echo "  differs: $label\n";
	}
}
OLIMC_Scanner::end_batch();
$t( $ok, 'a batch of more than ' . OLIMC_Scanner::META_PREFILTER_MAX . ' media (tables read whole, once) gives the same answers' );

// ── Cleanup ──
foreach ( $cleanups as $f ) {
	if ( is_file( $f ) ) {
		unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
	}
}
foreach ( [ 'wkls_num', 'wkls_csv', 'wkls_ser', 'wkls_serkey', 'wkls_json', 'wkls_jsonlist', 'wkls_url', 'wkls_urljson', 'wkls_urlsize', 'wkls_urlenc' ] as $k ) {
	delete_post_meta( $host, $k );
}
foreach ( [ 'wkls_tnum', 'wkls_tkey', 'wkls_turl' ] as $k ) {
	delete_term_meta( $tid, $k );
}
foreach ( [ 'wkls_unum', 'wkls_ukey', 'wkls_uurl' ] as $k ) {
	delete_user_meta( 1, $k );
}
foreach ( [ 'wkls_opt_num', 'wkls_opt_ser', 'wkls_opt_url', 'wkls_opt_json', 'wkls_opt_key' ] as $k ) {
	delete_option( $k );
}
delete_transient( 'wkls_tr' );
foreach ( array_merge( [ $host ], $extra_posts ) as $pid ) {
	wp_delete_post( $pid, true );
}
if ( $tid ) {
	wp_delete_term( $tid, 'category' );
}
$reset();
foreach ( $media as $mid ) {
	if ( OLIMC_Trash::is_in_trash( $mid ) ) {
		OLIMC_Trash::restore( $mid );
	}
	wp_delete_attachment( $mid, true );
}
foreach ( glob( "$base/$folder/*" ) ?: [] as $f ) {
	unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
}
rmdir( "$base/$folder" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
remove_filter( 'olimc_quarantine_base_dir', $rootf );
if ( is_dir( dirname( $root_dir ) ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( $root_dir ), FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		( $f->isDir() && ! $f->isLink() ) ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
	}
	rmdir( dirname( $root_dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
}
echo $fail ? "\n$fail live-source test(s) FAILED.\n" : "All live-source tests passed.\n";
exit( $fail ? 1 : 0 );
