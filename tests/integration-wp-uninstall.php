<?php
/**
 * Uninstall test: data removed, trashed media restored, never deleted.
 * Run on a TEST site only; the plugin must be re-activated afterwards.
 *
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-uninstall.php
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
$up   = wp_upload_dir();
$file = $up['path'] . '/olimc-un-1.jpg';
imagejpeg( imagecreatetruecolor( 10, 10 ), $file );
$id = wp_insert_attachment( [ 'post_title' => 'olimc-un-1', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], $file );
OLIMC_Results::install();
OLIMC_Trash::trash( $id );
update_post_meta( $id, '_olimc_protected', 1 );
update_option( 'olimc_protected_paths', 'x/*' );
update_user_meta( 1, 'olimc_backup_dismissed', 1 );
$t( OLIMC_Trash::is_in_trash( $id ), 'fixture is in the trash' );
define( 'WP_UNINSTALL_PLUGIN', 'oli-media-cleaner/oli-media-cleaner.php' );
include OLIMC_PLUGIN_DIR . 'uninstall.php';
$t( ! OLIMC_Trash::is_in_trash( $id ) && get_post( $id ) && file_exists( $file ), 'trashed media restored, not deleted' );
$t( '' === (string) get_post_meta( $id, '_olimc_trashed', true ) && '' === (string) get_post_meta( $id, '_olimc_protected', true ), 'plugin meta removed' );
$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'olimc\\_%'" );
$t( [] === $left, 'no olimc_ option left (' . implode( ',', $left ) . ')' );
$t( null === $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}olimc_results'" ), 'results table dropped' );
$t( '' === (string) get_user_meta( 1, 'olimc_backup_dismissed', true ), 'user meta removed' );
wp_delete_attachment( $id, true );
echo $fail ? "\n$fail uninstall test(s) failed.\n" : "\nAll uninstall tests passed.\n";
exit( $fail ? 1 : 0 );
