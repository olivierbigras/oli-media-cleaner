<?php
/**
 * Plugin Name: Oli Media Cleaner
 * Plugin URI: https://github.com/olivierbigras/oli-media-cleaner
 * Description: Scan and remove unused media files from your WordPress site to free up disk space. Deep scans post content, ACF fields, WooCommerce, Elementor, theme files, widgets, and more.
 * Version: 1.5.22
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-media-cleaner
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('OLIMC_VERSION', '1.5.22');
define('OLIMC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OLIMC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OLIMC_BASENAME', plugin_basename(__FILE__));
define('OLIMC_DONATE_URL', 'https://www.paypal.com/donate/?hosted_button_id=29SAPKFAHMTNW');

require_once OLIMC_PLUGIN_DIR . 'includes/class-media-usage-parser.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-acf-meta-scanner.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once OLIMC_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, ['OLIMC_Admin', 'activate']);
register_deactivation_hook(__FILE__, ['OLIMC_Admin', 'deactivate']);

// Boot
OLIMC_Admin::init();
