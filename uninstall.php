<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Media in the trash are ALWAYS restored first, whether or not "Delete the
 * plugin data when it is uninstalled" is checked: their files go back to
 * their original paths. No media file is ever deleted here. A media that
 * cannot be restored (a file with the same name exists, folder not writable)
 * is shown again in the Media Library and its files stay in
 * uploads/olimc-trash with their manifest and a README. The checkbox only
 * decides whether the options, post meta, results table and folder token
 * are removed.
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

	// 1. Put every media of the trash back (no file is deleted), whatever the "delete data" setting.
	if ( ! class_exists( 'OLIMC_Trash' ) ) {
		require_once OLIMC_PLUGIN_DIR . 'includes/functions.php';
		require_once OLIMC_PLUGIN_DIR . 'includes/class-results.php';
		require_once OLIMC_PLUGIN_DIR . 'includes/class-trash.php';
	}
	if ( ! class_exists( 'OLIMC_Quarantine' ) ) {
		require_once OLIMC_PLUGIN_DIR . 'includes/class-quarantine.php';
	}
	OLIMC_Trash::restore_all();
	// Whatever could not be put back is shown again and keeps its files in the quarantine folder.
	foreach ( OLIMC_Trash::all_ids() as $olimc_left_id ) {
		if ( OLIMC_Quarantine::is_quarantined( $olimc_left_id ) ) {
			OLIMC_Quarantine::release_status( $olimc_left_id );
		}
	}

	// The folder goes only when no media folder is left in it; otherwise a README explains what is there.
	$olimc_empty = OLIMC_Quarantine::remove_if_empty();
	if ( ! $olimc_empty ) {
		OLIMC_Quarantine::write_leftover_note();
	}

	// The setting only governs the removal of the plugin data (steps 2 to 3).
	if ( '0' === (string) get_option( 'olimc_delete_data', '1' ) ) {
		return;
	}

	// 2. Options, post meta, user meta, transients.
	$like = $wpdb->esc_like( 'olimc_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, '_transient_' . $like, '_transient_timeout_' . $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_olimc_protected','_olimc_trashed','_olimc_trash_map','_olimc_prev_status','_olimc_trashing','_olimc_autofail')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s,%s,%s)", 'olimc_backup_dismissed', 'olimc_per_page', $wpdb->get_blog_prefix() . 'olimc_per_page' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// 3. Results table and scheduled events.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'olimc_results' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	wp_clear_scheduled_hook( 'olimc_scheduled_cleanup' );
	wp_clear_scheduled_hook( 'olimc_auto_empty_trash' );
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
