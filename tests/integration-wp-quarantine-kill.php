<?php
/**
 * A real SIGKILL in the middle of a trashing: the media stays in the library (status "inherit") with part of its files in the
 * quarantine and a manifest in the state "moving". The restore (and the plugin screen) put the files back; the lock of a dead
 * process does not hold this up for 300 seconds, the lock of a live one does. Run on a TEST site only:
 *
 *   OLIMC_TEST_OK=1 wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-quarantine-kill.php
 *
 * The test starts a second PHP process (the same WP-CLI) that kills itself: it needs proc_open and the POSIX extension.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( '1' !== getenv( 'OLIMC_TEST_OK' ) ) {
	echo "Refusing to run: this test trashes media. Set OLIMC_TEST_OK=1 to confirm this is a throwaway test site.\n";
	exit( 1 );
}
$argv0 = isset( $_SERVER['argv'][0] ) ? (string) $_SERVER['argv'][0] : '';
if ( ! function_exists( 'proc_open' ) || ! function_exists( 'posix_kill' ) || '' === $argv0 || ! is_file( $argv0 ) ) {
	echo "SKIP: no way to start a child process that kills itself (proc_open, posix, WP-CLI).\n";
	exit( 0 );
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
$up       = wp_upload_dir();
$base     = rtrim( $up['basedir'], '/' );
$u        = substr( md5( uniqid( '', true ) ), 0, 8 );
$root_dir = sys_get_temp_dir() . "/olimc-kill-test-$u/olimc-trash";
$rootf    = function () use ( $root_dir ) {
	return $root_dir;
};
add_filter( 'olimc_quarantine_base_dir', $rootf );
if ( OLIMC_Quarantine::root_dir() !== $root_dir ) {
	echo "Refusing to run: the test quarantine folder is not in use.\n";
	exit( 1 );
}
$folder = "olimc-kill-$u";
$dir    = "$base/$folder";
wp_mkdir_p( $dir );
$files = [ 'big.jpg' => str_repeat( 'B', 4096 ) ];
for ( $i = 1; $i <= 11; $i++ ) {
	$files[ "big-{$i}x{$i}.jpg" ] = "S$i" . str_repeat( 'x', 50 * $i );
}
foreach ( $files as $name => $c ) {
	file_put_contents( "$dir/$name", $c );
}
$id    = wp_insert_attachment( [ 'post_title' => "olimc-kill-$u", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], "$dir/big.jpg" );
$sizes = [];
foreach ( array_slice( array_keys( $files ), 1 ) as $k => $name ) {
	$sizes[ 's' . $k ] = [ 'file' => $name, 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg' ];
}
update_post_meta( $id, '_wp_attached_file', "$folder/big.jpg" );
update_post_meta( $id, '_wp_attachment_metadata', [ 'file' => "$folder/big.jpg", 'width' => 1, 'height' => 1, 'sizes' => $sizes ] );
$before = [];
foreach ( array_keys( $files ) as $name ) {
	$before[ $name ] = md5_file( "$dir/$name" );
}
$here = function () use ( $dir, $files ) {
	$o = [];
	foreach ( array_keys( $files ) as $name ) {
		$o[ $name ] = is_file( "$dir/$name" ) ? md5_file( "$dir/$name" ) : null;
	}
	return $o;
};
$st    = function () use ( $id ) {
	clean_post_cache( $id );
	return (string) get_post_field( 'post_status', $id, 'raw' );
};
$lockv = function () use ( $wpdb, $id ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, OLIMC_Quarantine::META_LOCK ) );
};
$qdir  = function () use ( $root_dir, $id ) {
	foreach ( glob( "$root_dir/*/$id" ) ?: [] as $d ) {
		return $d;
	}
	return '';
};
$child = dirname( $root_dir ) . "/child-$u.php";
wp_mkdir_p( dirname( $root_dir ) );
file_put_contents(
	$child,
	'<?php
$id = (int) getenv( "OLIMC_KILL_ID" );
$root = getenv( "OLIMC_KILL_ROOT" );
add_filter( "olimc_quarantine_base_dir", function () use ( $root ) { return $root; } );
wp_set_current_user( 1 );
$n = 0;
add_filter( "olimc_quarantine_allow_move", function ( $ok ) use ( &$n ) {
	if ( ++$n === 6 ) {
		posix_kill( posix_getpid(), 9 );
		sleep( 5 );
	}
	return $ok;
}, 10, 3 );
OLIMC_Trash::trash( $id );
echo "SURVIVED\n";
'
);
$kill = function () use ( $argv0, $child, $id, $root_dir, $t ) {
	$cmd  = [ PHP_BINARY, '-d', 'memory_limit=512M', $argv0, '--path=' . rtrim( ABSPATH, '/' ), 'eval-file', $child ];
	$proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, array_merge( getenv(), [ 'OLIMC_KILL_ID' => (string) $id, 'OLIMC_KILL_ROOT' => $root_dir ] ) );
	if ( ! is_resource( $proc ) ) {
		$t( false, 'the child process started' );
		return false;
	}
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	$code = proc_close( $proc ); // 9 (or 137 through a shell): killed by SIGKILL.
	if ( ! in_array( $code, [ 9, 137 ], true ) ) {
		echo '  child exit code ' . $code . ': ' . trim( substr( $out . ' ' . $err, 0, 400 ) ) . "\n";
	}
	return in_array( $code, [ 9, 137 ], true ) && false === strpos( (string) $out, 'SURVIVED' );
};
$reset = function () use ( $id ) {
	OLIMC_Scanner::reset_upload_path_index();
	clean_post_cache( $id );
	wp_cache_delete( $id, 'post_meta' );
};
$host = substr( md5( function_exists( 'gethostname' ) ? (string) gethostname() : 'host' ), 0, 8 );
$msg  = __( 'This media is being processed.', 'oli-media-cleaner' );

// ── 1. The kill, and what it leaves ──
$t( $kill(), 'the trashing process was killed by SIGKILL in the middle of the move' );
$reset();
$left = $here();
$moved = count( array_filter( $left, 'is_null' ) );
$t( 'inherit' === $st() && null === OLIMC_Quarantine::map( $id ), 'after the kill: status "inherit", no trash map (the media still shows in the library)' );
$t( $moved > 0 && $moved < count( $files ), "after the kill: part of the files moved ($moved of " . count( $files ) . ' are in the quarantine)' );
$mdir = $qdir();
$man  = '' !== $mdir ? json_decode( (string) file_get_contents( "$mdir/manifest.json" ), true ) : null;
$t( is_array( $man ) && 'moving' === ( $man['state'] ?? '' ), 'after the kill: the manifest says "moving"' );
$lv = (string) $lockv();
$t( preg_match( '/^\d+:\d+:' . $host . '$/', $lv ) === 1, 'the lock names the process and the server (' . $lv . ')' );
$t( in_array( $id, OLIMC_Quarantine::interrupted_ids(), true ), 'the media is listed as interrupted' );

// ── 2. Restore: finishes the rollback at once, the dead process does not hold it for 300 s ──
$r = OLIMC_Trash::restore( $id );
$reset();
$t( $r['ok'], 'restore answers ok (not "not_trashed"), the lock of a dead process does not block it' );
$t( $here() === $before, 'every file is back at its place, same MD5' );
$t( '' === $qdir() && null === $lockv() && 'inherit' === $st(), 'the quarantine folder is gone, the lock released, the media unchanged' );
$t( [] === OLIMC_Quarantine::interrupted_ids(), 'nothing is listed as interrupted any more' );
$r = OLIMC_Trash::restore( $id );
$t( ! $r['ok'] && 'not_trashed' === $r['code'], 'restoring a media that is neither in the trash nor interrupted still answers not_trashed' );

// ── 3. The plugin screen detects the state and finishes the rollback ──
$t( $kill(), 'second kill' );
$reset();
$t( $here() !== $before && 'inherit' === $st(), 'second kill: the media is broken in the same way' );
ob_start();
( new ReflectionMethod( 'OLIMC_Screen', 'render_standing_notices' ) )->invoke( null, 'review' );
$html = (string) ob_get_clean();
$reset();
$t( false !== strpos( $html, 'olimc-recovered' ), 'the plugin screen says the files were put back' );
$t( $here() === $before && '' === $qdir() && [] === OLIMC_Quarantine::interrupted_ids(), 'the screen put every file back (same MD5)' );
ob_start();
( new ReflectionMethod( 'OLIMC_Screen', 'render_standing_notices' ) )->invoke( null, 'review' );
$t( false === strpos( (string) ob_get_clean(), 'olimc-recovered' ), 'and the notice does not come back' );

// ── 4. The trashing can be tried again at once ──
$t( $kill(), 'third kill' );
$reset();
$t0 = microtime( true );
$r  = OLIMC_Trash::trash( $id );
$t( $r['ok'] && microtime( true ) - $t0 < 10 && OLIMC_Trash::is_in_trash( $id ), 'the same media can be put in the trash again right away (no 300 s wait)' );
$r = OLIMC_Trash::restore( $id );
$reset();
$t( $r['ok'] && $here() === $before, 'and restored, same MD5' );

// ── 5. A lock that may be alive blocks, and says so ──
$t( $kill(), 'fourth kill' );
$reset();
$wpdb->update( $wpdb->postmeta, [ 'meta_value' => time() . ':1:' . $host ], [ 'post_id' => $id, 'meta_key' => OLIMC_Quarantine::META_LOCK ] );
wp_cache_delete( $id, 'post_meta' );
$r = OLIMC_Trash::restore( $id );
$t( ! $r['ok'] && $here() !== $before, 'a lock held by a live process (pid 1): nothing is touched' );
$t( OLIMC_Quarantine::had_busy() && $msg === ( new ReflectionMethod( 'OLIMC_Ajax', 'reason' ) )->invoke( null ), 'the answer is "' . $msg . '"' );
ob_start();
( new ReflectionMethod( 'OLIMC_Screen', 'render_standing_notices' ) )->invoke( null, 'review' );
$html = (string) ob_get_clean();
$t( false !== strpos( $html, 'olimc-interrupted' ) && false === strpos( $html, 'olimc-recovered' ), 'the plugin screen warns that a media is waiting (nothing deleted)' );
$wpdb->update( $wpdb->postmeta, [ 'meta_value' => ( time() - 1000 ) . ':1:' . $host ], [ 'post_id' => $id, 'meta_key' => OLIMC_Quarantine::META_LOCK ] );
wp_cache_delete( $id, 'post_meta' );
$r = OLIMC_Trash::restore( $id );
$reset();
$t( $r['ok'] && $here() === $before, 'the same lock, older than 300 s: it is taken and the rollback ends' );
// An old-format lock (a bare time) cannot be told from a live one.
$t( $kill(), 'fifth kill' );
$reset();
$wpdb->update( $wpdb->postmeta, [ 'meta_value' => (string) time() ], [ 'post_id' => $id, 'meta_key' => OLIMC_Quarantine::META_LOCK ] );
wp_cache_delete( $id, 'post_meta' );
$r = OLIMC_Trash::restore( $id );
$t( ! $r['ok'] && $here() !== $before, 'a lock without process information is not guessed: it blocks until it is 300 s old' );
delete_post_meta( $id, OLIMC_Quarantine::META_LOCK );
$r = OLIMC_Trash::restore( $id );
$reset();
$t( $r['ok'] && $here() === $before, 'once released, the rollback ends and nothing was lost' );

// A file created at the original path in the meantime is never overwritten.
$t( $kill(), 'sixth kill' );
$reset();
$gone = array_keys( array_filter( $here(), 'is_null' ) );
$name = (string) reset( $gone );
file_put_contents( "$dir/$name", 'NEW CONTENT' );
$r = OLIMC_Trash::restore( $id );
$reset();
$t( ! $r['ok'] && 'NEW CONTENT' === file_get_contents( "$dir/$name" ), 'a file that appeared at the original path is not overwritten, and the answer is a failure' );
$t( '' !== $qdir() && is_file( $qdir() . '/manifest.json' ), 'the quarantine copy stays (nothing is deleted)' );

// A kill after the last file moved and before the media was hidden: the manifest is already final.
unlink( "$dir/$name" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
OLIMC_Trash::restore( $id );
$t( $kill(), 'seventh kill' );
$reset();
$mdir = $qdir();
$man  = json_decode( (string) file_get_contents( "$mdir/manifest.json" ), true );
unset( $man['state'] );
file_put_contents( "$mdir/manifest.json", wp_json_encode( $man ) );
$r = OLIMC_Trash::restore( $id );
$reset();
$t( $r['ok'] && $here() === $before && '' === $qdir(), 'a manifest that is already final (state removed) is rolled back too' );

// ── 8. wp olimc rotate-token takes the lock of a dead process at once, like restore() and the screen ──
if ( 0 !== OLIMC_Trash::count() ) {
	echo "SKIP: rotate-token checks (other media are in the trash of this site).\n";
} else {
	$tok0 = get_option( OLIMC_Quarantine::OPT_TOKEN, '' );
	$t( $kill(), 'eighth kill' );
	$reset();
	$wpdb->update( $wpdb->postmeta, [ 'meta_value' => time() . ':1:' . $host ], [ 'post_id' => $id, 'meta_key' => OLIMC_Quarantine::META_LOCK ] );
	wp_cache_delete( $id, 'post_meta' );
	$res = OLIMC_Quarantine::rotate_token();
	$t( is_wp_error( $res ) && 'olimc_busy' === $res->get_error_code() && $here() !== $before, 'rotate-token: a lock held by a live process still makes it wait (nothing touched)' );
	$t( $tok0 === get_option( OLIMC_Quarantine::OPT_TOKEN, '' ) && '' === (string) get_option( OLIMC_Quarantine::OPT_ROTATE, '' ), 'rotate-token: and no rotation was started' );
	// The same media, the lock of a pid that is certainly dead (the child's own lock): the rollback ends, then the rotation runs.
	delete_post_meta( $id, OLIMC_Quarantine::META_LOCK );
	OLIMC_Trash::restore( $id );
	$t( $kill(), 'ninth kill' );
	$reset();
	$t( preg_match( '/^\d+:\d+:' . $host . '$/', (string) $lockv() ) === 1, 'ninth kill: a fresh lock of a dead process' );
	$t0  = microtime( true );
	$res = OLIMC_Quarantine::rotate_token();
	$reset();
	$t( ! is_wp_error( $res ), 'rotate-token: a fresh lock of a dead process does not make it wait 300 s' );
	$t( microtime( true ) - $t0 < 20 && $here() === $before && '' === $qdir() && null === $lockv(), 'rotate-token: the rollback was finished first (files back, same MD5, lock gone)' );
	$t( $tok0 !== get_option( OLIMC_Quarantine::OPT_TOKEN, '' ) && '' === (string) get_option( OLIMC_Quarantine::OPT_ROTATE, '' ), 'rotate-token: the token was replaced, no rotation left pending' );
	update_option( OLIMC_Quarantine::OPT_TOKEN, $tok0, false );
	delete_option( OLIMC_Quarantine::OPT_ROTATE );
}

// ── Cleanup ──
OLIMC_Trash::restore( $id );
delete_post_meta( $id, OLIMC_Quarantine::META_LOCK );
wp_delete_attachment( $id, true );
foreach ( glob( "$dir/*" ) ?: [] as $f ) {
	unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
}
rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
unlink( $child ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
remove_filter( 'olimc_quarantine_base_dir', $rootf );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( $root_dir ), FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) {
	( $f->isDir() && ! $f->isLink() ) ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
}
rmdir( dirname( $root_dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
echo $fail ? "\n$fail kill test(s) FAILED.\n" : "All kill tests passed.\n";
exit( $fail ? 1 : 0 );
