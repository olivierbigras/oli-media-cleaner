<?php
/**
 * Quarantine on a multisite network: each site has its own trash folder inside
 * its own uploads folder, its own token, and the uninstall restores every site.
 * Run on a TEST network only (the plugin data is removed at the end):
 *
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-quarantine-multisite.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( ! is_multisite() ) {
	echo "SKIP: not a multisite network\n";
	return;
}
if ( '1' !== getenv( 'OLIMC_TEST_OK' ) ) {
	echo "Refusing to run: this test erases uploads/olimc-trash. Set OLIMC_TEST_OK=1 to confirm this is a throwaway test site.\n";
	exit( 1 );
}
$fail = 0;
$t    = function ( $c, $m ) use ( &$fail ) {
	echo ( $c ? 'OK: ' : 'FAIL: ' ) . $m . "\n";
	if ( ! $c ) {
		++$fail;
	}
};
wp_set_current_user( 1 );
$sites = get_sites( [ 'fields' => 'ids', 'number' => 5 ] );
$t( count( $sites ) >= 2, 'the network has at least two sites' );
$state = [];
foreach ( $sites as $sid ) {
	switch_to_blog( (int) $sid );
	OLIMC_Results::install();
	$up   = wp_upload_dir();
	$sub  = ltrim( $up['subdir'], '/' );
	wp_mkdir_p( $up['path'] );
	$name = 'olimc-ms-' . $sid . '-' . wp_rand( 1000, 9999 );
	$file = $up['path'] . "/$name.jpg";
	imagejpeg( imagecreatetruecolor( 20, 20 ), $file );
	$id = wp_insert_attachment( [ 'post_title' => $name, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], $file );
	update_post_meta( $id, '_wp_attached_file', "$sub/$name.jpg" );
	$md5 = md5_file( $file );
	OLIMC_Scanner::reset_upload_path_index();
	$r   = OLIMC_Trash::trash( $id );
	$map = OLIMC_Quarantine::map( $id );
	$t( $r['ok'] && ! file_exists( $file ), "site $sid: trashed, file gone from its uploads folder" );
	$qdir = $up['basedir'] . '/' . $map['dir'];
	$t( is_file( $qdir . '/files/' . "$sub/$name.jpg" ), "site $sid: file is in this site's own uploads folder ($qdir)" );
	$t( 0 === strpos( $qdir, rtrim( $up['basedir'], '/' ) . '/olimc-trash/' ), "site $sid: quarantine inside the uploads folder of the site" );
	$state[ $sid ] = [ $id, $file, $md5, OLIMC_Quarantine::token(), $up['basedir'] ];
	restore_current_blog();
}
$tokens = array_map( function ( $s ) { return $s[3]; }, $state );
$t( count( array_unique( $tokens ) ) === count( $tokens ), 'each site has its own random token' );
$bases = array_map( function ( $s ) { return $s[4]; }, $state );
$t( count( array_unique( $bases ) ) === count( $bases ), 'each site has its own uploads folder' );
// Restore on one site does not touch the others.
$first = (int) array_key_first( $state );
switch_to_blog( $first );
OLIMC_Scanner::reset_upload_path_index();
$t( OLIMC_Trash::restore( $state[ $first ][0] )['ok'] && md5_file( $state[ $first ][1] ) === $state[ $first ][2], 'site ' . $first . ': restore is exact' );
OLIMC_Trash::trash( $state[ $first ][0] );
restore_current_blog();

// Network uninstall: every site restored, nothing deleted.
define( 'WP_UNINSTALL_PLUGIN', 'oli-media-cleaner/oli-media-cleaner.php' );
include OLIMC_PLUGIN_DIR . 'uninstall.php';
foreach ( $state as $sid => $s ) {
	switch_to_blog( (int) $sid );
	$t( is_file( $s[1] ) && md5_file( $s[1] ) === $s[2] && get_post( $s[0] ), "uninstall: site $sid restored byte for byte, media still there" );
	$t( ! is_dir( $s[4] . '/olimc-trash' ), "uninstall: site $sid quarantine folder removed" );
	wp_delete_attachment( $s[0], true );
	restore_current_blog();
}
echo $fail ? "\n$fail FAILED\n" : "\nAll multisite quarantine tests passed.\n";
exit( $fail ? 1 : 0 );
