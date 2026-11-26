<?php
/**
 * Plugin Name: Oli Media Cleaner
 * Plugin URI: https://github.com/olivierbigras/oli-media-cleaner
 * Description: Find unused media, move them to a safe trash, restore anytime. Local only: no ads, no tracking, nothing sent out.
 * Version: 2.0.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-media-cleaner
 * Domain Path: /languages
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

define( 'OLIMC_VERSION', '2.0.1' );
define( 'OLIMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLIMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OLIMC_BASENAME', plugin_basename( __FILE__ ) );
define( 'OLIMC_DONATE_URL', 'https://www.paypal.com/donate/?hosted_button_id=29SAPKFAHMTNW' );

require_once OLIMC_PLUGIN_DIR . 'includes/functions.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-media-usage-parser.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-acf-meta-scanner.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-results.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-trash.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-quarantine.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-library.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-settings.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-scan.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-admin.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-screen.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-ajax.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OLIMC_PLUGIN_DIR . 'includes/class-cli.php';
}

register_activation_hook( __FILE__, [ 'OLIMC_Admin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'OLIMC_Admin', 'deactivate' ] );

OLIMC_Admin::init();
