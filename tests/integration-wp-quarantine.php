<?php
/**
 * Quarantine tests: files really leave their public place, come back byte for
 * byte, are erased for good on empty, never overwrite, roll back on failure, and
 * survive an uninstall. Run on a TEST site only:
 *
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-quarantine.php
 *
 * The plugin must be active. The test creates and deletes its own media.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
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
$uniq = substr( md5( uniqid( '', true ) ), 0, 8 );

/**
 * A media with real files: original, -scaled, original_image, sizes, a backup size and WebP/AVIF copies.
 */
$make = function ( $name, $with_copies = true ) use ( $up, $base ) {
	$sub  = ltrim( $up['subdir'], '/' );
	$dir  = $base . '/' . $sub;
	wp_mkdir_p( $dir );
	$files = [
		"$name.jpg"              => 'original_image',
		"$name-scaled.jpg"       => 'file',
		"$name-150x150.jpg"      => 'thumbnail',
		"$name-300x200.jpg"      => 'medium',
		"$name-orig.jpg"         => 'backup',
	];
	foreach ( array_keys( $files ) as $f ) {
		$im = imagecreatetruecolor( 30, 20 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
		imagejpeg( $im, "$dir/$f" );
	}
	if ( $with_copies ) {
		file_put_contents( "$dir/$name-scaled.jpg.webp", 'webp-' . $name . '-1' );
		file_put_contents( "$dir/$name-150x150.webp", 'webp-' . $name . '-2' );
		file_put_contents( "$dir/$name-150x150.avif", 'avif-' . $name . '-3' );
	}
	$id = wp_insert_attachment( [ 'post_title' => $name, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], "$dir/$name-scaled.jpg" );
	update_post_meta( $id, '_wp_attached_file', "$sub/$name-scaled.jpg" );
	update_post_meta(
		$id,
		'_wp_attachment_metadata',
		[
			'width'          => 30,
			'height'         => 20,
			'file'           => "$sub/$name-scaled.jpg",
			'original_image' => "$name.jpg",
			'sizes'          => [
				'thumbnail' => [ 'file' => "$name-150x150.jpg", 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ],
				'medium'    => [ 'file' => "$name-300x200.jpg", 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg' ],
			],
		]
	);
	update_post_meta( $id, '_wp_attachment_backup_sizes', [ 'full-orig' => [ 'file' => "$name-orig.jpg", 'width' => 30, 'height' => 20 ] ] );
	return [ $id, $dir, array_merge( array_keys( $files ), $with_copies ? [ "$name-scaled.jpg.webp", "$name-150x150.webp", "$name-150x150.avif" ] : [] ), $sub ];
};
$snap = function ( $dir, $names ) {
	$out = [];
	foreach ( $names as $n ) {
		$out[ $n ] = is_file( "$dir/$n" ) ? md5_file( "$dir/$n" ) : null;
	}
	return $out;
};
// The bench answers every unknown URL with a WordPress page, so "gone" means: no image is served.
$code = function ( $url ) {
	$r = wp_remote_get( $url, [ 'timeout' => 20, 'redirection' => 5 ] );
	if ( is_wp_error( $r ) ) {
		return 0;
	}
	$c = (int) wp_remote_retrieve_response_code( $r );
	if ( 200 === $c && 0 !== strpos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'image/' ) && false === strpos( $url, 'admin-post.php' ) ) {
		return 404; // Not the image: the web server (or WordPress) did not find the file.
	}
	return $c;
};
$st = function ( $x ) {
	return (string) get_post_field( 'post_status', $x, 'raw' );
};
$reset = function () {
	OLIMC_Scanner::reset_upload_path_index();
	clean_post_cache( 0 );
};

// ── 1. Trash: files leave their public place ─────────────────────────
list( $id, $dir, $names, $sub ) = $make( "olimc-q1-$uniq" );
$before = $snap( $dir, $names );
$url    = $up['baseurl'] . "/$sub/" . "olimc-q1-$uniq-scaled.jpg";
$t( 200 === $code( $url ), 'before: the public URL answers 200' );
$r = OLIMC_Trash::trash( $id );
$t( $r['ok'], 'trash() succeeds (' . $r['code'] . ')' );
$gone = true;
foreach ( $names as $n ) {
	$gone = $gone && ! file_exists( "$dir/$n" );
}
$t( $gone, 'original, -scaled, original_image, sizes, backup size and WebP/AVIF copies are gone from the public place' );
$t( 404 === $code( $url ), 'after: the public URL of the original answers 404' );
$t( 404 === $code( $up['baseurl'] . "/$sub/olimc-q1-$uniq-150x150.jpg" ), 'after: the public URL of a size answers 404' );
$map = OLIMC_Quarantine::map( $id );
$t( is_array( $map ) && 8 === count( $map['files'] ), 'the manifest lists the 8 moved files (' . ( is_array( $map ) ? count( $map['files'] ) : 0 ) . ')' );
$qdir = $base . '/' . $map['dir'];
$t( (bool) preg_match( '#^olimc-trash/[a-f0-9]{32}/' . $id . '$#', $map['dir'] ), 'folder: olimc-trash/<32 hex>/<id>' );
$inq = true;
foreach ( $map['files'] as $f ) {
	$p   = $qdir . '/files/' . $f['p'];
	$inq = $inq && is_file( $p ) && md5_file( $p ) === $f['m'] && filesize( $p ) === $f['s'] && $before[ basename( $f['p'] ) ] === $f['m'];
}
$t( $inq, 'every moved file is in the quarantine with the size and MD5 of the manifest' );
$t( is_file( $qdir . '/manifest.json' ), 'manifest.json is next to the files' );
$t( is_file( $base . '/olimc-trash/index.php' ) && is_file( $base . '/olimc-trash/.htaccess' ) && false !== strpos( (string) file_get_contents( $base . '/olimc-trash/.htaccess' ), 'Deny from all' ), 'root has an empty index.php and an .htaccess that denies' );
$t( is_file( $base . '/' . dirname( $map['dir'] ) . '/index.php' ) && is_file( $qdir . '/index.php' ), 'token and media folders have an index.php' );
$t( 0 === filesize( $base . '/olimc-trash/index.php' ) || false === strpos( (string) file_get_contents( $base . '/olimc-trash/index.php' ), 'echo' ), 'index.php outputs nothing' );
$t( OLIMC_Quarantine::STATUS === $st( $id ), 'the media has the hidden status' );
$t( OLIMC_Trash::is_in_trash( $id ) && ! in_array( $id, ( new WP_Query( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ] ) )->posts, true ), 'hidden from the Media Library query (inherit)' );
$t( ! in_array( $id, ( new OLIMC_Scanner() )->get_all_attachment_ids(), false ), 'not in the scannable library' );
$t( OLIMC_Quarantine::total_bytes( $id ) > 0, 'size of the trashed media comes from the manifest' );
$t( 1 === count( array_filter( $wpdb->get_col( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = $id" ), function ( $k ) { return '_olimc_trash_map' === $k; } ) ), 'the map is also stored in post meta' );

// Never purged by WordPress.
$t( ! $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = $id AND meta_key = '_wp_trash_meta_time'" ) && 'trash' !== $st( $id ), 'WordPress trash meta/status are not used (no wp_scheduled_delete)' );

// ── 2. Restore: exact bytes, exact paths ─────────────────────────────
$reset();
$r = OLIMC_Trash::restore( $id );
$t( $r['ok'], 'restore() succeeds (' . $r['code'] . ')' );
$after = $snap( $dir, $names );
$t( $after === $before, 'all 8 files are back at the same paths with the same MD5' );
$t( 'inherit' === $st( $id ) && ! OLIMC_Trash::is_in_trash( $id ) && null === OLIMC_Quarantine::map( $id ), 'status inherit, trash state and map removed' );
$t( ! is_dir( $qdir ), 'the media quarantine folder is gone' );
$t( 200 === $code( $url ), 'the public URL answers 200 again' );
$t( in_array( $id, ( new OLIMC_Scanner() )->get_all_attachment_ids(), false ), 'back in the scannable library' );

// ── 3. Empty: permanent deletion ─────────────────────────────────────
$reset();
$r = OLIMC_Trash::trash( $id );
$map  = OLIMC_Quarantine::map( $id );
$qdir = $base . '/' . $map['dir'];
// A file that is not ours appears at an original path while the media is in the trash.
file_put_contents( "$dir/olimc-q1-$uniq-150x150.jpg", 'foreign' );
$reset();
$r = OLIMC_Trash::purge( $id );
$t( $r['ok'], 'purge() succeeds (' . $r['code'] . ')' );
$t( ! get_post( $id ), 'the attachment is deleted' );
$t( ! is_dir( $qdir ), 'the quarantine folder and its files are erased' );
$t( 'foreign' === (string) @file_get_contents( "$dir/olimc-q1-$uniq-150x150.jpg" ), 'a file found at an original path (not ours) is never deleted' );
wp_delete_file( "$dir/olimc-q1-$uniq-150x150.jpg" );
$t( ! OLIMC_Trash::purge( $id, false )['ok'], 'purge() of a deleted media is refused' );
// A media that is not in the trash cannot be purged.
list( $idn, $dirn, $namesn ) = $make( "olimc-q3-$uniq" );
$t( 'not_trashed' === OLIMC_Trash::purge( $idn )['code'] && is_file( "$dirn/olimc-q3-$uniq-scaled.jpg" ), 'purge() refuses a media outside the trash and keeps its files' );

// ── 4. Live re-check: used media is not trashed, nor emptied ─────────
$post = wp_insert_post( [ 'post_title' => "olimc-q4-$uniq", 'post_status' => 'publish', 'post_content' => '<img src="' . $up['baseurl'] . "/$sub/olimc-q3-$uniq-scaled.jpg" . '" class="wp-image-' . $idn . '">' ] );
$reset();
$r = OLIMC_Trash::trash( $idn );
$t( ! $r['ok'] && 'used' === $r['code'] && is_file( "$dirn/olimc-q3-$uniq-scaled.jpg" ) && ! OLIMC_Quarantine::is_quarantined( $idn ), 'a media used by a post is not moved (live re-check)' );
wp_delete_post( $post, true );
$reset();
$r = OLIMC_Trash::trash( $idn );
$t( $r['ok'], 'once unused, it is moved' );
// It becomes used while in the trash: emptying refuses and keeps everything.
$post = wp_insert_post( [ 'post_title' => "olimc-q4b-$uniq", 'post_status' => 'publish', 'post_content' => '<!-- wp:image {"id":' . $idn . '} --><figure class="wp-block-image"><img class="wp-image-' . $idn . '"/></figure><!-- /wp:image -->' ] );
$reset();
$r = OLIMC_Trash::purge( $idn );
$t( ! $r['ok'] && 'used' === $r['code'] && OLIMC_Quarantine::is_quarantined( $idn ) && is_dir( $base . '/' . OLIMC_Quarantine::map( $idn )['dir'] ), 'emptying refuses a media that became used; files stay in the quarantine' );
wp_delete_post( $post, true );
$reset();
$t( OLIMC_Trash::restore( $idn )['ok'] && is_file( "$dirn/olimc-q3-$uniq-scaled.jpg" ), 'and it can be restored' );
OLIMC_Trash::trash( $idn );
$reset();
OLIMC_Trash::purge( $idn );

// ── 5. Collision on return: nothing is overwritten ───────────────────
list( $id, $dir, $names, $sub ) = $make( "olimc-q5-$uniq" );
$before = $snap( $dir, $names );
$reset();
OLIMC_Trash::trash( $id );
file_put_contents( "$dir/olimc-q5-$uniq-150x150.jpg", 'recreated meanwhile' );
$reset();
$r = OLIMC_Trash::restore( $id );
$t( ! $r['ok'] && 'exists' === $r['code'], 'restore() with a file at the destination answers "exists"' );
$t( 'recreated meanwhile' === file_get_contents( "$dir/olimc-q5-$uniq-150x150.jpg" ), 'the recreated file is untouched' );
$map = OLIMC_Quarantine::map( $id );
$okq = true;
foreach ( $map['files'] as $f ) {
	$okq = $okq && is_file( $base . '/' . $map['dir'] . '/files/' . $f['p'] ) && ! file_exists( $base . '/' . $f['p'] ) || basename( $f['p'] ) === "olimc-q5-$uniq-150x150.jpg";
}
$t( $okq && OLIMC_Trash::is_in_trash( $id ), 'nothing was moved back: all files are still in the quarantine, the media is still in the trash' );
wp_delete_file( "$dir/olimc-q5-$uniq-150x150.jpg" );
$reset();
$t( OLIMC_Trash::restore( $id )['ok'] && $snap( $dir, $names ) === $before, 'after removing the intruder, restore is exact' );

// ── 6. Partial failure: rollback of the batch of this media ──────────
$n = 0;
$fail_second = function ( $allow, $from, $to ) use ( &$n ) {
	++$n;
	return 3 === $n ? false : $allow;
};
$reset();
add_filter( 'olimc_quarantine_allow_move', $fail_second, 10, 3 );
$r = OLIMC_Trash::trash( $id );
remove_filter( 'olimc_quarantine_allow_move', $fail_second, 10 );
$t( ! $r['ok'] && 'fail' === $r['code'], 'trash() with a failing move reports a failure' );
$t( $snap( $dir, $names ) === $before, 'the 2 files already moved were put back: same paths, same MD5' );
$t( ! OLIMC_Trash::is_in_trash( $id ) && 'inherit' === $st( $id ) && null === OLIMC_Quarantine::map( $id ), 'the media is not in the trash and keeps its status' );
$t( ! is_dir( $base . '/olimc-trash/' . OLIMC_Quarantine::token() . '/' . $id ), 'the half-built quarantine folder is removed' );
// Failure while restoring: the files moved back so far return to the quarantine.
$reset();
OLIMC_Trash::trash( $id );
$n = 0;
$reset();
add_filter( 'olimc_quarantine_allow_move', $fail_second, 10, 3 );
$r = OLIMC_Trash::restore( $id );
remove_filter( 'olimc_quarantine_allow_move', $fail_second, 10 );
$t( ! $r['ok'] && 'fail' === $r['code'] && OLIMC_Trash::is_in_trash( $id ), 'restore() with a failing move reports a failure and the media stays in the trash' );
$map = OLIMC_Quarantine::map( $id );
$allq = true;
foreach ( $map['files'] as $f ) {
	$allq = $allq && is_file( $base . '/' . $map['dir'] . '/files/' . $f['p'] ) && ! file_exists( $base . '/' . $f['p'] );
}
$t( $allq, 'every file is back in the quarantine, none left half way' );
$reset();
$t( OLIMC_Trash::restore( $id )['ok'] && $snap( $dir, $names ) === $before, 'a later restore is exact' );

// A changed or missing file in the quarantine is detected before anything is moved.
$reset();
OLIMC_Trash::trash( $id );
$map = OLIMC_Quarantine::map( $id );
file_put_contents( $base . '/' . $map['dir'] . '/files/' . $map['files'][0]['p'], 'changed' );
$reset();
$r = OLIMC_Trash::restore( $id );
$t( ! $r['ok'] && 'fail' === $r['code'] && ! file_exists( $base . '/' . $map['files'][1]['p'] ), 'a file changed in the quarantine stops the restore before anything moves' );
$reset();
OLIMC_Trash::purge( $id );
$t( ! get_post( $id ), 'such a media can still be deleted' );

// ── 7. No write permission: refused, nothing changes ─────────────────
list( $id, $dir, $names, $sub ) = $make( "olimc-q7-$uniq" );
$before = $snap( $dir, $names );
chmod( $dir, 0555 );
clearstatcache();
$reset();
$ro = ! is_writable( $dir );
$r  = OLIMC_Trash::trash( $id );
chmod( $dir, 0755 );
clearstatcache();
if ( $ro ) {
	$t( ! $r['ok'] && 'fail' === $r['code'], 'folder not writable: trash() is refused' );
	$t( OLIMC_Quarantine::had_permission_error(), 'the permission error is reported (message "The uploads folder is not writable.")' );
	$t( $snap( $dir, $names ) === $before && ! OLIMC_Trash::is_in_trash( $id ) && 'inherit' === $st( $id ), 'nothing moved, status unchanged' );
} else {
	echo "SKIP: cannot make a folder read-only for this user\n";
}
// Quarantine root not writable.
$root = $base . '/olimc-trash';
wp_mkdir_p( $root );
chmod( $root, 0555 );
clearstatcache();
$reset();
if ( ! is_writable( $root ) ) {
	$r = OLIMC_Trash::trash( $id );
	$t( ! $r['ok'] && $snap( $dir, $names ) === $before, 'quarantine folder not writable: trash() is refused, nothing moved' );
	$t( false === OLIMC_Quarantine::is_writable(), 'is_writable() reports it (used for the message)' );
}
chmod( $root, 0755 );
clearstatcache();

// ── 8. Files shared with another media stay in place ─────────────────
$reset();
list( $ida, $dira, $namesa, $suba ) = $make( "olimc-q8-$uniq", false );
$idb = wp_insert_attachment( [ 'post_title' => "olimc-q8b-$uniq", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], "$dira/olimc-q8-$uniq-150x150.jpg" );
update_post_meta( $idb, '_wp_attached_file', "$suba/olimc-q8-$uniq-150x150.jpg" );
$reset();
$beforea = $snap( $dira, $namesa );
$r = OLIMC_Trash::trash( $ida );
$t( $r['ok'] && is_file( "$dira/olimc-q8-$uniq-150x150.jpg" ) && ! file_exists( "$dira/olimc-q8-$uniq-scaled.jpg" ), 'a file shared with another media stays in place, the rest moves' );
$t( in_array( "$suba/olimc-q8-$uniq-150x150.jpg", OLIMC_Quarantine::map( $ida )['stay'], true ), 'the manifest records the file left in place' );
$reset();
$t( OLIMC_Trash::restore( $ida )['ok'] && $snap( $dira, $namesa ) === $beforea, 'restore is exact with a shared file' );
$reset();
OLIMC_Trash::trash( $ida );
$reset();
$t( ! OLIMC_Trash::purge( $ida )['ok'] && is_file( "$dira/olimc-q8-$uniq-150x150.jpg" ), 'emptying keeps the shared file (existing guard), the media stays in the trash' );
$reset();
OLIMC_Trash::restore( $ida );
wp_delete_attachment( $idb, true );
$reset();
wp_delete_attachment( $ida, true );

// ── 9. Media without a file on disk: only hidden, restorable ─────────
$idz = wp_insert_attachment( [ 'post_title' => "olimc-q9-$uniq", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], '' );
update_post_meta( $idz, '_wp_attached_file', "$sub/olimc-q9-$uniq-missing.jpg" );
$reset();
$t( OLIMC_Trash::trash( $idz )['ok'] && OLIMC_Trash::is_in_trash( $idz ) && 'inherit' !== $st( $idz ), 'a media with no file on disk is hidden and counted in the trash' );
$reset();
$t( OLIMC_Trash::restore( $idz )['ok'] && 'inherit' === $st( $idz ), 'and restored' );
OLIMC_Trash::trash( $idz );
$reset();
$t( OLIMC_Trash::purge( $idz )['ok'] && ! get_post( $idz ), 'and deleted' );

// ── 10. Preview in the Trash tab: no public URL ──────────────────────
list( $id, $dir, $names, $sub ) = $make( "olimc-q10-$uniq" );
$reset();
OLIMC_Trash::trash( $id );
$turl = OLIMC_Quarantine::thumb_url( $id );
$t( false !== strpos( $turl, 'admin-post.php' ) && false !== strpos( $turl, 'olimc_thumb' ) && false === strpos( $turl, 'olimc-trash' ), 'thumb_url() is an admin-post URL, never a quarantine URL' );
$t( in_array( $code( $turl ), [ 400, 401, 403 ], true ), 'the preview endpoint refuses a visitor who is not signed in (' . $code( $turl ) . ')' );

// ── 11. Uninstall: restore first, never delete ───────────────────────
list( $idu, $diru, $namesu, $subu ) = $make( "olimc-q11-$uniq" );
list( $idv, $dirv, $namesv, $subv ) = $make( "olimc-q12-$uniq" );
$beforeu = $snap( $diru, $namesu );
$beforev = $snap( $dirv, $namesv );
$reset();
OLIMC_Trash::trash( $idu );
OLIMC_Trash::trash( $idv );
// One of them cannot be restored: a file was created at an original path.
file_put_contents( "$dirv/olimc-q12-$uniq-300x200.jpg", 'mine' );
$reset();
update_option( 'olimc_protected_paths', 'x/*' );
define( 'WP_UNINSTALL_PLUGIN', 'oli-media-cleaner/oli-media-cleaner.php' );
include OLIMC_PLUGIN_DIR . 'uninstall.php';
$t( $snap( $diru, $namesu ) === $beforeu && 'inherit' === $st( $idu ), 'uninstall: the media of the trash are restored byte for byte' );
$t( 'inherit' === $st( $idv ) && 'mine' === file_get_contents( "$dirv/olimc-q12-$uniq-300x200.jpg" ), 'uninstall: a media that cannot be restored is shown again and nothing is overwritten' );
$left = glob( $base . '/olimc-trash/*/' . $idv . '/files/*/*/*' ) ?: [];
$t( count( $left ) >= 1 && is_file( $base . '/olimc-trash/README.txt' ), 'uninstall: its files stay in the quarantine folder, with a README' );
$mf = glob( $base . '/olimc-trash/*/' . $idv . '/manifest.json' );
$t( 1 === count( $mf ), 'uninstall: its manifest is kept' );
$t( [] === $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'olimc\\_%'" ), 'uninstall: no olimc_ option left (token included)' );
$t( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_olimc_trashed','_olimc_trash_map','_olimc_prev_status','_olimc_trashing')" ), 'uninstall: no trash post meta left' );
// Clean the leftover by hand: the plugin is re-activated by the caller.
foreach ( glob( $base . '/olimc-trash/*' ) ?: [] as $p ) {
	if ( is_file( $p ) ) {
		wp_delete_file( $p );
	}
}
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . '/olimc-trash', FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) {
	$f->isDir() ? rmdir( $f->getPathname() ) : wp_delete_file( $f->getPathname() );
}
rmdir( $base . '/olimc-trash' );
// Delete the fixtures that were left.
foreach ( [ $id, $idu, $idv ] as $x ) {
	if ( get_post( $x ) ) {
		wp_delete_attachment( $x, true );
	}
}

// ── 12. Uninstall with an empty trash removes the folder ─────────────
list( $idw, $dirw, $namesw ) = $make( "olimc-q13-$uniq" );
$beforew = $snap( $dirw, $namesw );
$reset();
OLIMC_Quarantine::init();
$t( OLIMC_Trash::trash( $idw )['ok'], 'second site state: media trashed again' );
$reset();
olimc_uninstall_site();
$t( $snap( $dirw, $namesw ) === $beforew && ! is_dir( $base . '/olimc-trash' ), 'uninstall with everything restorable: files back, quarantine folder removed' );
wp_delete_attachment( $idw, true );

echo $fail ? "\n$fail FAILED\n" : "\nAll quarantine tests passed.\n";
exit( $fail ? 1 : 0 );
