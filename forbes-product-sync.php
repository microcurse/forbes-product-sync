<?php
/**
 * Plugin Name: Forbes Product Sync
 * Plugin URI: https://github.com/microcurse
 * Description: Pulls products from the Live site into the Forbes Portal using WooCommerce REST API
 * Version: 1.0.0
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

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Forbes Product Sync requires WooCommerce to be installed and activated.', 'forbes-product-sync'); ?></p>
        </div>
        <?php
    });
    return;
}

// Define plugin constants
define('FORBES_PRODUCT_SYNC_VERSION', '1.0.0');
define('FORBES_PRODUCT_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FORBES_PRODUCT_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
$required_files = array(
    'includes/class-forbes-product-sync-api.php',
    'includes/class-forbes-product-sync-product.php',
    'includes/class-forbes-product-sync-logger.php',
    'includes/class-forbes-product-sync-status.php',
    'includes/class-forbes-product-sync.php'
);

foreach ($required_files as $file) {
    if (file_exists(FORBES_PRODUCT_SYNC_PLUGIN_DIR . $file)) {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . $file;
    } else {
        add_action('admin_notices', function() use ($file) {
            ?>
            <div class="notice notice-error">
                <p><?php printf(__('Forbes Product Sync is missing required file: %s. Please reinstall the plugin.', 'forbes-product-sync'), $file); ?></p>
            </div>
            <?php
        });
        return;
    }
}

/**
 * Initialize the plugin
 */
function forbes_product_sync_init() {
    // Load text domain for internationalization
    load_plugin_textdomain('forbes-product-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Initialize the main plugin class
    new Forbes_Product_Sync();
}
add_action('plugins_loaded', 'forbes_product_sync_init', 20);

/**
 * Activation hook
 */
function forbes_product_sync_activate() {
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

    // Create necessary database tables
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'forbes_product_sync_log';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        sku varchar(100) NOT NULL,
        action varchar(20) NOT NULL,
        status varchar(20) NOT NULL,
        message text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY sku (sku)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'forbes_product_sync_activate');

/**
 * Deactivation hook
 */
function forbes_product_sync_deactivate() {
    if (!current_user_can('activate_plugins')) {
        return;
    }
}
register_deactivation_hook(__FILE__, 'forbes_product_sync_deactivate');
