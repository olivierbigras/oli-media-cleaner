<?php
/**
 * Security tests of the quarantine (review of 18 Nov 2026): executable names, rollback without
 * overwrite, confinement to the uploads folder, locks, auto-empty failures, journal, token rotation,
 * folder outside the web root, export, safe unserialize. Run on a TEST site only:
 *
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-quarantine-security.php
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
$u    = substr( md5( uniqid( '', true ) ), 0, 8 );
// This test writes and deletes under its own quarantine folder (a filter), never under uploads/olimc-trash.
if ( '1' !== getenv( 'OLIMC_TEST_OK' ) ) {
	echo "Refusing to run: set OLIMC_TEST_OK=1 to confirm this is a throwaway test site.\n";
	exit( 1 );
}
$root_dir = sys_get_temp_dir() . "/olimc-sec-test-$u/olimc-trash";
$global_f = function () use ( $root_dir ) {
	return $root_dir;
};
add_filter( 'olimc_quarantine_base_dir', $global_f );
$root = OLIMC_Quarantine::root_dir();
if ( $root !== $root_dir ) {
	echo "Refusing to run: the test quarantine folder is not in use.\n";
	exit( 1 );
}
$reset = function () {
	OLIMC_Scanner::reset_upload_path_index();
	clean_post_cache( 0 );
};
$st = function ( $x ) {
	return (string) get_post_field( 'post_status', $x, 'raw' );
};
$rm = function ( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		if ( $f->isLink() || ! $f->isDir() ) {
			unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
		} else {
			rmdir( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
};
$created = [];
/**
 * A media whose files are given as relative name => content, the first one being the attached file.
 */
$make = function ( $folder, array $files, array $extra_meta = [] ) use ( $base, &$created ) {
	$dir = $base . '/' . $folder;
	wp_mkdir_p( $dir );
	foreach ( $files as $n => $c ) {
		file_put_contents( "$dir/$n", $c );
	}
	$first = (string) array_key_first( $files );
	$id    = wp_insert_attachment( [ 'post_title' => 'olimc-sec-' . basename( $folder ), 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], "$dir/$first" );
	update_post_meta( $id, '_wp_attached_file', "$folder/$first" );
	$sizes = [];
	$k     = 0;
	foreach ( array_slice( array_keys( $files ), 1 ) as $n ) {
		$sizes[ 's' . ( $k++ ) ] = [ 'file' => $n, 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg' ];
	}
	update_post_meta( $id, '_wp_attachment_metadata', array_merge( [ 'file' => "$folder/$first", 'width' => 1, 'height' => 1, 'sizes' => $sizes ], $extra_meta ) );
	$created[] = $id;
	return [ $id, $dir ];
};
$md5s = function ( $dir, $names ) {
	$o = [];
	foreach ( $names as $n ) {
		$o[ $n ] = is_file( "$dir/$n" ) ? md5_file( "$dir/$n" ) : null;
	}
	return $o;
};

// ── F3. Executable names are neutralised in the quarantine and restored exactly ──
$f3 = "olimc-sec-f3-$u";
list( $id3, $dir3 ) = $make(
	$f3,
	[
		'logo.jpg'           => 'jpeg-bytes',
		'shell.php'          => "<?php // harmless test\n",
		'old.PHP5'           => "<?php // harmless test 2\n",
		'double.php.jpg'     => 'jpeg-bytes-2',
		'.htaccess'          => "Options +ExecCGI\n",
		'page.phtml'         => "<?php // harmless 3\n",
	]
);
$names3  = [ 'logo.jpg', 'shell.php', 'old.PHP5', 'double.php.jpg', '.htaccess', 'page.phtml' ];
$before3 = $md5s( $dir3, $names3 );
$reset();
$r = OLIMC_Trash::trash( $id3 );
$t( $r['ok'], 'F3: a media with executable file names can be trashed (' . $r['code'] . ')' );
$map3 = OLIMC_Quarantine::map( $id3 );
$qdir = $root . '/' . ( $map3 ? substr( $map3['dir'], strlen( 'olimc-trash/' ) ) : 'x' );
$bad  = [];
$all  = [];
if ( is_dir( $qdir . '/files' ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $qdir . '/files', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( 'index.php' === $f->getFilename() ) {
			continue;
		}
		$all[] = $f->getFilename();
		if ( preg_match( '/^\.|\.(php[0-9]*|phtml|phar|pht|shtml?|htaccess)(\.|$)/i', $f->getFilename() ) ) {
			$bad[] = $f->getFilename();
		}
	}
}
$t( 6 === count( $all ) && [] === $bad, 'F3: no file of the quarantine keeps an executable name (stored: ' . implode( ', ', $all ) . ')' );
$q_names = array_filter( array_map( function ( $f ) { return isset( $f['q'] ) ? $f['p'] . ' -> ' . $f['q'] : null; }, $map3['files'] ) );
$t( 5 === count( $q_names ), 'F3: the manifest keeps the real name and the stored one for the 5 neutralised files' );
$t( ! is_file( "$dir3/shell.php" ) && ! is_file( "$dir3/.htaccess" ), 'F3: nothing is left at the public place' );
$reset();
$r = OLIMC_Trash::restore( $id3 );
$t( $r['ok'], 'F3: restore succeeds (' . $r['code'] . ')' );
$t( $md5s( $dir3, $names3 ) === $before3, 'F3: every file is back with its exact name and MD5 (.php, .PHP5, .php.jpg, .htaccess, .phtml)' );
$t( ! is_dir( $qdir ), 'F3: the media folder of the quarantine is gone' );

// ── F4. A rollback never overwrites a file that appeared at the original path ──
$f4 = "olimc-sec-f4-$u";
list( $id4, $dir4 ) = $make( $f4, [ 'a.jpg' => 'AAAA', 'b-150x150.jpg' => 'BBBB', 'c-300x200.jpg' => 'CCCC' ] );
$names4 = [ 'a.jpg', 'b-150x150.jpg', 'c-300x200.jpg' ];
$before4 = $md5s( $dir4, $names4 );
$calls   = 0;
$hook4   = function ( $allow, $from ) use ( &$calls, $dir4 ) {
	++$calls;
	if ( 2 === $calls ) {
		file_put_contents( "$dir4/a.jpg", 'NEWCOMER' ); // A new file takes the place of the first moved one.
		return false;
	}
	return $allow;
};
add_filter( 'olimc_quarantine_allow_move', $hook4, 10, 2 );
$reset();
$r = OLIMC_Trash::trash( $id4 );
remove_filter( 'olimc_quarantine_allow_move', $hook4, 10 );
$t( ! $r['ok'] && 'inherit' === $st( $id4 ), 'F4: the move failed, the media keeps its status' );
$t( 'NEWCOMER' === file_get_contents( "$dir4/a.jpg" ), 'F4: the file that appeared at the original path was NOT overwritten by the rollback' );
$left4 = glob( $root . '/*/' . $id4 . '/files/' . $f4 . '/a.jpg' );
$t( 1 === count( $left4 ) && md5_file( $left4[0] ) === $before4['a.jpg'], 'F4: the quarantined copy is kept (nothing lost)' );
unlink( "$dir4/a.jpg" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
$reset();
$r = OLIMC_Trash::trash( $id4 );
$t( $r['ok'], 'F4: once the path is free, the next try finishes the job (' . $r['code'] . ')' );
$reset();
OLIMC_Trash::restore( $id4 );
$t( $md5s( $dir4, $names4 ) === $before4, 'F4: restore gives back the exact bytes' );

// ── F5. Confinement to the uploads folder (symlinked folder) ──
$out = sys_get_temp_dir() . "/olimc-sec-out-$u";
wp_mkdir_p( $out );
file_put_contents( "$out/leak.jpg", 'OUTSIDE' );
symlink( $out, "$base/olimc-sec-link-$u" );
list( $id5 ) = $make( "olimc-sec-link-$u", [ 'leak.jpg' => 'OUTSIDE' ] );
$reset();
$r = OLIMC_Trash::trash( $id5 );
$t( is_file( "$out/leak.jpg" ) && 'OUTSIDE' === file_get_contents( "$out/leak.jpg" ), 'F5: a file reached through a symlinked folder that leaves uploads is never moved' );
$map5 = OLIMC_Quarantine::map( $id5 );
$t( null === $map5 || [] === $map5['files'], 'F5: nothing from outside uploads is in the manifest' );
if ( OLIMC_Trash::is_in_trash( $id5 ) ) {
	OLIMC_Trash::restore( $id5 );
}
// A restore must not write through a folder that became a symlink to somewhere else.
$f5 = "olimc-sec-f5-$u";
list( $id6, $dir6 ) = $make( $f5, [ 'x.jpg' => 'INSIDE' ] );
$reset();
OLIMC_Trash::trash( $id6 );
@rmdir( $dir6 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir
$out2 = sys_get_temp_dir() . "/olimc-sec-out2-$u";
wp_mkdir_p( $out2 );
symlink( $out2, $dir6 );
$reset();
$r = OLIMC_Trash::restore( $id6 );
$t( ! $r['ok'] && ! file_exists( "$out2/x.jpg" ), 'F5: a restore does not write through a folder that now leads out of uploads (' . $r['code'] . ')' );
unlink( $dir6 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
mkdir( $dir6 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
$reset();
$r = OLIMC_Trash::restore( $id6 );
$t( $r['ok'] && 'INSIDE' === file_get_contents( "$dir6/x.jpg" ), 'F5: with a real folder again, the restore works' );

// ── F6. Locks: purge takes the lock, a stale lock is taken once ──
$f6 = "olimc-sec-f6-$u";
list( $id7, $dir7 ) = $make( $f6, [ 'p.jpg' => 'PPPP' ] );
$reset();
OLIMC_Trash::trash( $id7 );
add_post_meta( $id7, OLIMC_Quarantine::META_LOCK, time(), true );
$r = OLIMC_Trash::purge( $id7 );
$t( ! $r['ok'] && 'fail' === $r['code'] && null !== get_post( $id7 ), 'F6: a permanent deletion is refused while the media is being processed' );
$r = OLIMC_Trash::restore( $id7 );
$t( ! $r['ok'], 'F6: and so is a restore' );
delete_post_meta( $id7, OLIMC_Quarantine::META_LOCK );
update_post_meta( $id7, OLIMC_Quarantine::META_LOCK, time() - 1000 );
$t( true === OLIMC_Quarantine::acquire( $id7 ), 'F6: a stale lock (older than 300 s) is taken' );
$t( false === OLIMC_Quarantine::acquire( $id7 ), 'F6: and only once: the next request does not get it (compare-and-swap)' );
OLIMC_Quarantine::release( $id7 );
$r = OLIMC_Trash::purge( $id7 );
$t( $r['ok'] && null === get_post( $id7 ), 'F6: without a lock the deletion goes through' );

// ── F7. Automatic emptying skips a media that keeps failing ──
$f7 = "olimc-sec-f7-$u";
list( $id8, $dir8 ) = $make( $f7, [ 'a.jpg' => 'AAAA' ] );
list( $id9, $dir9 ) = $make( $f7, [ 'b.jpg' => 'BBBB' ] );
$reset();
OLIMC_Trash::trash( $id8 );
OLIMC_Trash::trash( $id9 );
update_post_meta( $id8, '_olimc_trashed', time() - 40 * DAY_IN_SECONDS );
update_post_meta( $id9, '_olimc_trashed', time() - 40 * DAY_IN_SECONDS );
update_option( 'olimc_auto_empty', 30 );
$deny = function ( $check, $post ) use ( $id8 ) {
	return (int) $post->ID === (int) $id8 ? false : $check;
};
add_filter( 'pre_delete_attachment', $deny, 5, 2 );
for ( $i = 1; $i <= 5; $i++ ) {
	OLIMC_Trash::run_auto_empty();
	if ( 1 === $i ) {
		$t( null === get_post( $id9 ), 'F7: the other media is emptied at the first run' );
	}
}
remove_filter( 'pre_delete_attachment', $deny, 5 );
$t( OLIMC_Trash::AUTOFAIL_MAX === (int) get_post_meta( $id8, OLIMC_Trash::META_AUTOFAIL, true ), 'F7: a media that fails is tried ' . OLIMC_Trash::AUTOFAIL_MAX . ' times, then skipped (counter = ' . (int) get_post_meta( $id8, OLIMC_Trash::META_AUTOFAIL, true ) . ' after 5 runs)' );
delete_option( 'olimc_auto_empty' );
$reset();
OLIMC_Trash::restore( $id8 );
$t( '' === (string) get_post_meta( $id8, OLIMC_Trash::META_AUTOFAIL, true ), 'F7: the counter is cleared when the media leaves the trash' );

// ── F8. Journal: bounded, minimal ──
delete_option( OLIMC_Trash::OPT_AUDIT );
for ( $i = 0; $i < 230; $i++ ) {
	OLIMC_Trash::log( 'trash', range( 1, 40 ) );
}
$log = get_option( OLIMC_Trash::OPT_AUDIT );
$t( is_array( $log ) && OLIMC_Trash::AUDIT_MAX === count( $log ), 'F8: the journal keeps the last ' . OLIMC_Trash::AUDIT_MAX . ' lines' );
$t( 1 === get_current_user_id() && 1 === $log[0]['u'] && 'trash' === $log[0]['a'] && 40 === $log[0]['n'] && OLIMC_Trash::AUDIT_IDS === count( $log[0]['i'] ) && $log[0]['t'] > 0 && [ 'u', 'a', 'n', 'i', 't' ] === array_keys( $log[0] ), 'F8: each line holds user, action, number and IDs (bounded), time, nothing else' );
$t( 'no' === ( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", OLIMC_Trash::OPT_AUDIT ) ) ?: 'no' ) || 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", OLIMC_Trash::OPT_AUDIT ) ) || 'auto-off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", OLIMC_Trash::OPT_AUDIT ) ), 'F8: the journal is not autoloaded' );
$t( 0 === strpos( OLIMC_Trash::OPT_AUDIT, 'olimc_' ), 'F8: its name starts with olimc_, so the uninstall removes it with the plugin data' );
delete_option( OLIMC_Trash::OPT_AUDIT );
$t( wp_create_nonce( 'olimc_trash' ) !== wp_create_nonce( 'olimc_delete' ) && ! wp_verify_nonce( wp_create_nonce( 'olimc_trash' ), 'olimc_delete' ), 'F8: one nonce per action: the nonce of "trash" does not open "delete"' );

// ── R2. Export, folder outside the web root, token rotation ──
$f10 = "olimc-sec-r2-$u";
list( $id10, $dir10 ) = $make( $f10, [ 'k.jpg' => 'KKKK', 'k-150x150.jpg' => 'KKK2' ] );
list( $id11, $dir11 ) = $make( $f10, [ 'm.jpg' => 'MMMM' ] );
$names10 = [ 'k.jpg', 'k-150x150.jpg' ];
$before10 = $md5s( $dir10, $names10 );
$before11 = $md5s( $dir11, [ 'm.jpg' ] );
$reset();
OLIMC_Trash::trash( $id10 );
OLIMC_Trash::trash( $id11 );
foreach ( [ '_olimc_trash_map', '_olimc_trashed', '_olimc_prev_status', '_olimc_trashing', '_olimc_autofail' ] as $k ) {
	$t( true === apply_filters( 'wxr_export_skip_postmeta', false, $k, (object) [] ), "R2: $k is left out of the WXR export" );
}
$t( false === apply_filters( 'wxr_export_skip_postmeta', false, '_olimc_protected', (object) [] ) && false === apply_filters( 'wxr_export_skip_postmeta', false, 'some_field', (object) [] ), 'R2: the "kept" flag and other meta are exported as usual' );
$tok_old = OLIMC_Quarantine::token();
$map10   = OLIMC_Quarantine::map( $id10 );
// Rotation.
$res = OLIMC_Quarantine::rotate_token();
$tok_new = OLIMC_Quarantine::token();
$t( is_array( $res ) && 2 === $res['media'] && $tok_new !== $tok_old && preg_match( '/^[a-f0-9]{32}$/', $tok_new ), 'R2: the token is replaced and 2 trashed media are updated' );
$t( ! is_dir( "$root/$tok_old" ) && is_dir( "$root/$tok_new/$id10/files" ), 'R2: the folder was renamed' );
$m2 = OLIMC_Quarantine::map( $id10 );
$mf = json_decode( (string) file_get_contents( "$root/$tok_new/$id10/manifest.json" ), true );
$t( $m2['dir'] === "olimc-trash/$tok_new/$id10" && is_array( $mf ) && $mf['dir'] === $m2['dir'], 'R2: the map and manifest.json point to the new folder' );
$t( false === strpos( (string) get_post_meta( $id10, '_olimc_trash_map', true ), $tok_old ), 'R2: the old token is nowhere in the map' );
$t( '' === (string) get_option( OLIMC_Quarantine::OPT_ROTATE, '' ), 'R2: no rotation state left' );
// Interrupted rotation: folder renamed, option not updated yet. Running it again finishes.
$tok_c   = bin2hex( random_bytes( 16 ) );
$tok_b   = OLIMC_Quarantine::token();
update_option( OLIMC_Quarantine::OPT_ROTATE, $tok_b . ':' . $tok_c, false );
rename( "$root/$tok_b", "$root/$tok_c" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- simulates a crash.
$res2 = OLIMC_Quarantine::rotate_token();
$t( is_array( $res2 ) && $res2['new'] === $tok_c && OLIMC_Quarantine::token() === $tok_c && 2 === $res2['media'], 'R2: an interrupted rotation is resumed by running it again' );
add_post_meta( $id10, OLIMC_Quarantine::META_LOCK, time(), true );
$t( is_wp_error( OLIMC_Quarantine::rotate_token() ), 'R2: no rotation while a media is being processed' );
delete_post_meta( $id10, OLIMC_Quarantine::META_LOCK );
$reset();
$t( OLIMC_Trash::restore( $id10 )['ok'] && $md5s( $dir10, $names10 ) === $before10, 'R2: after a rotation the restore gives back the exact bytes' );
// Folder outside the web root (a filter).
$custom = sys_get_temp_dir() . "/olimc-sec-root-$u/olimc-trash";
$cf     = function () use ( $custom ) {
	return $custom;
};
foreach ( [ 'relative/olimc-trash', sys_get_temp_dir() . "/olimc-sec-x-$u", sys_get_temp_dir() . '/../olimc-trash', '' ] as $bad_path ) {
	$bf = function () use ( $bad_path ) {
		return $bad_path;
	};
	add_filter( 'olimc_quarantine_base_dir', $bf );
	$t( OLIMC_Quarantine::root_dir() === $base . '/olimc-trash', 'R2: an invalid folder from the filter is ignored (' . ( '' === $bad_path ? 'empty' : $bad_path ) . ')' );
	remove_filter( 'olimc_quarantine_base_dir', $bf );
}
OLIMC_Trash::restore( $id11 );
add_filter( 'olimc_quarantine_base_dir', $cf );
$t( OLIMC_Quarantine::root_dir() === $custom, 'R2: the filter olimc_quarantine_base_dir moves the quarantine' );
$reset();
$r = OLIMC_Trash::trash( $id11 );
$map11 = OLIMC_Quarantine::map( $id11 );
$t( $r['ok'] && is_file( "$custom/" . $tok_c . "/$id11/files/$f10/m.jpg" ) && ! is_file( "$dir11/m.jpg" ), 'R2: the files go to the other folder' );
$t( is_file( "$custom/index.php" ) && is_file( "$custom/.htaccess" ), 'R2: it gets its index.php and .htaccess' );
$t( [] === glob( "$base/olimc-trash/*/$id11" ), 'R2: nothing is written under uploads/olimc-trash for this media' );
$reset();
$r = OLIMC_Trash::restore( $id11 );
$t( $r['ok'] && $md5s( $dir11, [ 'm.jpg' ] ) === $before11, 'R2: and the restore gives back the exact bytes from there' );
remove_filter( 'olimc_quarantine_base_dir', $cf );
$rm( dirname( $custom ) );


// ── N1. An interrupted rotation closes the trash until it is run again ──
$f12 = "olimc-sec-n1-$u";
list( $id12, $dir12 ) = $make( $f12, [ 'n.jpg' => 'NNNN', 'n-150x150.jpg' => 'NNN2' ] );
list( $id13, $dir13 ) = $make( $f12, [ 'o.jpg' => 'OOOO' ] );
$names12  = [ 'n.jpg', 'n-150x150.jpg' ];
$before12 = $md5s( $dir12, $names12 );
$reset();
OLIMC_Trash::trash( $id12 );
$tok_o = OLIMC_Quarantine::token();
$tok_n = bin2hex( random_bytes( 16 ) );
update_option( OLIMC_Quarantine::OPT_ROTATE, $tok_o . ':' . $tok_n, false ); // The state a crash leaves...
rename( "$root/$tok_o", "$root/$tok_n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- ...folder renamed, token not updated.
$msg = 'The trash folder rotation was interrupted. Run wp olimc rotate-token again.';
$r1  = OLIMC_Trash::trash( $id13 );
$t( ! $r1['ok'] && 'inherit' === $st( $id13 ) && is_file( "$dir13/o.jpg" ) && $msg === $r1['detail'], 'N1: trashing is refused during an interrupted rotation, with the message, nothing moved' );
$r2 = OLIMC_Trash::restore( $id12 );
$t( ! $r2['ok'] && $msg === $r2['detail'] && OLIMC_Trash::is_in_trash( $id12 ), 'N1: restoring is refused too' );
$r3 = OLIMC_Trash::purge( $id12 );
$t( ! $r3['ok'] && null !== get_post( $id12 ), 'N1: so is a permanent deletion (and the emptying)' );
$t( [] === glob( "$root/$tok_o/*" ), 'N1: nothing was written under the old (leaked) token' );
ob_start();
( new ReflectionMethod( 'OLIMC_Screen', 'render_standing_notices' ) )->invoke( null, 'review' );
$html = (string) ob_get_clean();
$t( false !== strpos( $html, 'olimc-rotation' ) && false !== strpos( $html, esc_html( $msg ) ), 'N1: the plugin screen shows an admin notice' );
// Both folders exist (something was written under the old token after the crash): merged, nothing overwritten.
wp_mkdir_p( "$root/$tok_o/990001" );
file_put_contents( "$root/$tok_o/990001/stray.txt", 'stray' );
file_put_contents( "$root/$tok_o/index.php", "<?php\n" );
wp_mkdir_p( "$root/$tok_o/$id12" );
file_put_contents( "$root/$tok_o/$id12/other.txt", 'conflict' );
$res = OLIMC_Quarantine::rotate_token();
$t( is_wp_error( $res ) && 'olimc_conflict' === $res->get_error_code() && is_file( "$root/$tok_o/$id12/other.txt" ) && is_file( "$root/$tok_n/$id12/manifest.json" ) && is_file( "$root/$tok_n/990001/stray.txt" ), 'N1: a conflict is reported and left in place; the other folders are merged' );
$t( OLIMC_Quarantine::rotation_pending() && ! OLIMC_Trash::restore( $id12 )['ok'], 'N1: the trash stays closed while a conflict remains' );
unlink( "$root/$tok_o/$id12/other.txt" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
rmdir( "$root/$tok_o/$id12" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- test fixture.
$res = OLIMC_Quarantine::rotate_token();
$t( is_array( $res ) && ! is_dir( "$root/$tok_o" ) && $res['new'] === $tok_n && OLIMC_Quarantine::token() === $tok_n && ! OLIMC_Quarantine::rotation_pending(), 'N1: run again, the merge ends and the trash opens' );
$reset();
$r4 = OLIMC_Trash::restore( $id12 );
$t( $r4['ok'] && $md5s( $dir12, $names12 ) === $before12, 'N1: then the restore gives back the same bytes (MD5)' );
$t( OLIMC_Trash::trash( $id13 )['ok'] && OLIMC_Trash::restore( $id13 )['ok'], 'N1: and trashing works again' );
ob_start();
( new ReflectionMethod( 'OLIMC_Screen', 'render_standing_notices' ) )->invoke( null, 'review' );
$t( false === strpos( (string) ob_get_clean(), 'olimc-rotation' ), 'N1: the notice is gone' );

// ── N2. A Windows-style path is not an absolute path on Linux ──
if ( '/' === DIRECTORY_SEPARATOR ) {
	foreach ( [ 'C:/x/olimc-trash', 'C:\\x\\olimc-trash', 'c:/olimc-trash' ] as $win ) {
		$wf = function () use ( $win ) {
			return $win;
		};
		add_filter( 'olimc_quarantine_base_dir', $wf, 20 );
		$t( OLIMC_Quarantine::root_dir() === $base . '/olimc-trash', "N2: \"$win\" is ignored on Linux" );
		remove_filter( 'olimc_quarantine_base_dir', $wf, 20 );
	}
}
$t( OLIMC_Quarantine::root_dir() === $root_dir, 'N2: a real absolute path still works' );

// ── F9. Unserialize without classes ──
class OLIMC_Test_Gadget {
	public static $woke = false;
	public function __wakeup() {
		self::$woke = true;
	}
	public function __destruct() {
		self::$woke = true;
	}
}
$evil = serialize( [ 'a' => new OLIMC_Test_Gadget(), 'id' => $id10, 5 => [ 'x' => 'y' ] ] );
OLIMC_Test_Gadget::$woke = false;
$res9 = OLIMC_Media_Usage_Parser::safe_unserialize( $evil );
$t( is_array( $res9 ) && ! OLIMC_Test_Gadget::$woke && $res9['a'] instanceof __PHP_Incomplete_Class, 'F9: an injected object is not instantiated (no __wakeup, no __destruct)' );
$ids = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $evil, function () { return 0; }, function ( $i ) use ( $id10 ) { return $i === $id10; } );
$t( [ $id10 ] === $ids && ! OLIMC_Test_Gadget::$woke, 'F9: detection still reads the IDs around an inert object' );
$t( false === OLIMC_Media_Usage_Parser::safe_unserialize( 'b:0;' ) && 'plain text' === OLIMC_Media_Usage_Parser::safe_unserialize( 'plain text' ) && 'a:1:{' === OLIMC_Media_Usage_Parser::safe_unserialize( 'a:1:{' ), 'F9: false, plain text and broken data are returned as they are' );
$t( [ 1, 2 ] === OLIMC_Media_Usage_Parser::safe_unserialize( serialize( [ 1, 2 ] ) ), 'F9: an ordinary array is read as before' );

// ── Clean-up ──
foreach ( $created as $x ) {
	if ( get_post( $x ) ) {
		if ( OLIMC_Trash::is_in_trash( $x ) ) {
			OLIMC_Trash::restore( $x, false );
		}
		delete_post_meta( $x, '_wp_attached_file' );
		$reset();
		wp_delete_attachment( $x, true );
	}
}
foreach ( [ $f3, $f4, $f5, $f6, $f7, $f10, $f12 ] as $f ) {
	$rm( "$base/$f" );
}
if ( is_link( "$base/olimc-sec-link-$u" ) ) {
	unlink( "$base/olimc-sec-link-$u" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
}
$rm( $out );
$rm( $out2 );
remove_filter( 'olimc_quarantine_base_dir', $global_f );
$rm( dirname( $root_dir ) );
$reset();
echo $fail ? "\n$fail FAILED\n" : "\nAll quarantine security tests passed.\n";
exit( $fail ? 1 : 0 );
