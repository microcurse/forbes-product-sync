<?php
/**
 * Plugin Name: Forbes Product Sync
 * Plugin URI: https://github.com/microcurse
 * Description: Pulls products from the Live site into the Forbes Portal and creates them, including variations, attributes, and images.
 * Version: 0.1.0
 * Author: Marc Maninang
 * Author URI: https://github.com/microcurse
 * Text Domain: forbes-product-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FORBES_PRODUCT_SYNC_VERSION', '0.1.0');
define('FORBES_PRODUCT_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FORBES_PRODUCT_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/class-forbes-product-sync.php';

/**
 * Initialize the plugin
 */
function forbes_product_sync_init() {
    // Load text domain for internationalization
    load_plugin_textdomain('forbes-product-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Initialize the main plugin class
    $plugin = new Forbes_Product_Sync();
    $plugin->init();
}
add_action('plugins_loaded', 'forbes_product_sync_init');

/**
 * Activation hook
 */
function forbes_product_sync_activate() {
    // Add any activation tasks here
    if (!current_user_can('activate_plugins')) {
        return;
    }
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('This plugin requires WooCommerce to be installed and activated.', 'forbes-product-sync'),
            'Plugin dependency check',
            array('back_link' => true)
        );
    }
}
register_activation_hook(__FILE__, 'forbes_product_sync_activate');

/**
 * Deactivation hook
 */
function forbes_product_sync_deactivate() {
    // Add any deactivation tasks here
    if (!current_user_can('activate_plugins')) {
        return;
    }
}
register_deactivation_hook(__FILE__, 'forbes_product_sync_deactivate');
