<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Only runs when "Delete the plugin data when it is uninstalled" is checked
 * (the default). Media in the trash are restored first. No media file is ever
 * deleted here.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'OLIMC_PLUGIN_DIR' ) ) {
	define( 'OLIMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'OLIMC_VERSION' ) ) {
	define( 'OLIMC_VERSION', '2.0.0' );
}
if ( ! defined( 'OLIMC_BASENAME' ) ) {
	define( 'OLIMC_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Clean one site.
 */
function olimc_uninstall_site() {
	global $wpdb;

	if ( '0' === (string) get_option( 'olimc_delete_data', '1' ) ) {
		return;
	}

	// 1. Put every media of the trash back (no file is deleted).
	if ( ! class_exists( 'OLIMC_Trash' ) ) {
		require_once OLIMC_PLUGIN_DIR . 'includes/functions.php';
		require_once OLIMC_PLUGIN_DIR . 'includes/class-results.php';
		require_once OLIMC_PLUGIN_DIR . 'includes/class-trash.php';
	}
	OLIMC_Trash::restore_all();

	// 2. Options, post meta, user meta, transients.
	$like = $wpdb->esc_like( 'olimc_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, '_transient_' . $like, '_transient_timeout_' . $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_olimc_protected','_olimc_trashed')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s,%s,%s)", 'olimc_backup_dismissed', 'olimc_per_page', $wpdb->get_blog_prefix() . 'olimc_per_page' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// 3. Results table and scheduled events.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'olimc_results' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	wp_clear_scheduled_hook( 'olimc_scheduled_cleanup' );
	wp_clear_scheduled_hook( 'olimc_auto_empty_trash' );

	// 4. The quarantine folder, only when it is empty.
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['basedir'] ) ) {
		$dir = trailingslashit( $uploads['basedir'] ) . 'olimc-trash';
		if ( is_dir( $dir ) ) {
			$left = array_diff( (array) scandir( $dir ), [ '.', '..', 'index.php' ] );
			if ( [] === $left ) {
				wp_delete_file( $dir . '/index.php' );
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty folder created by this plugin.
			}
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( [
		'fields' => 'ids',
		'number' => 0,
	] ) as $olimc_site_id ) {
		switch_to_blog( (int) $olimc_site_id );
		olimc_uninstall_site();
		restore_current_blog();
	}
} else {
	olimc_uninstall_site();
}
