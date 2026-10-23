<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove all plugin options
delete_option( 'olimc_version' );
delete_option( 'olimc_scan_results' );
delete_option( 'olimc_scan_used_ids' );
delete_option( 'olimc_scan_state' );
delete_option( 'olimc_scan_state_ajax' );
delete_option( 'olimc_scan_state_cron' );
delete_option( 'olimc_scan_pending_ajax' );
delete_option( 'olimc_scan_pending_cron' );
delete_option( 'olimc_scan_lock' );
delete_option( 'olimc_scan_date' );
delete_option( 'olimc_whitelist' );
delete_option( 'olimc_protected_paths' );
delete_option( 'olimc_cron_enabled' );
delete_option( 'olimc_cron_frequency' );

// Clear scheduled events
wp_clear_scheduled_hook( 'olimc_scheduled_cleanup' );
