<?php
/**
 * Plugin Name: Forbes Product Sync
 * Plugin URI: https://github.com/microcurse
 * Description: Compares products and attributes from source and destination sites. Allows user to sync products from source to destination after comparison. Manually select which attributes to sync.
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

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'FPS_PLUGIN_FILE', __FILE__ );
define( 'FPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FPS_VERSION', '1.0.0' );

/**
 * Main function to initialize the Forbes Product Sync plugin.
 *
 * Loads the main plugin class and gets its instance on the 'plugins_loaded' hook.
 */
function fps_run_forbes_product_sync() {
    // Ensure the main plugin class is loaded.
    if ( ! class_exists( 'Forbes_Product_Sync' ) ) {
        require_once FPS_PLUGIN_DIR . 'includes/class-forbes-product-sync.php';
    }
    // Get the instance of the main plugin class (this will run the constructor once).
    Forbes_Product_Sync::instance();
}
add_action( 'plugins_loaded', 'fps_run_forbes_product_sync', 10 );

/**
 * Activation hook callback.
 *
 * Ensures the logger class is available and creates the log table.
 */
function fps_plugin_activation() {
    if ( ! class_exists( 'FPS_Logger' ) ) {
        require_once FPS_PLUGIN_DIR . 'includes/class-fps-logger.php';
    }
    // The FPS_Logger::create_table method also calls FPS_Logger::init()
    FPS_Logger::create_table();
}
register_activation_hook( FPS_PLUGIN_FILE, 'fps_plugin_activation' );

// The FPS() function and global $forbes_product_sync variable are removed
// as direct global instantiation is no longer used.
// If access to the main plugin instance is needed, Forbes_Product_Sync::instance() can be used
// after the 'plugins_loaded' hook.
// // Include the main Forbes_Product_Sync class
// if ( ! class_exists( 'Forbes_Product_Sync' ) ) {
//     include_once dirname( __FILE__ ) . '/includes/class-forbes-product-sync.php';
// }

// // Include admin classes
// if ( is_admin() ) {
//     include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin.php';
//     include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-settings.php';
//     include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-product-sync.php';
//     include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-attribute-sync.php';
//     include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-sync-logs.php';
//     include_once dirname( __FILE__ ) . '/includes/class-fps-ajax.php';
//     include_once dirname( __FILE__ ) . '/includes/class-fps-logger.php'; // Added Logger
    
//     // Initialize admin classes directly
//     add_action( 'plugins_loaded', function() {
//         FPS_Admin::instance();
//     });
// }

// /**
//  * Main instance of Forbes_Product_Sync.
//  *
//  * Returns the main instance of FPS to prevent the need to use globals.
//  *
//  * @return Forbes_Product_Sync
//  */
// function FPS() {
//     return Forbes_Product_Sync::instance();
// }

// // Global for backwards compatibility
// $GLOBALS['forbes_product_sync'] = FPS();

// // Activation hook for creating tables
// register_activation_hook( __FILE__, array( 'FPS_Logger', 'create_table' ) );
