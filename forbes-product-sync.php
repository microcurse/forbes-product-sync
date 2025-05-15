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

// Include the main Forbes_Product_Sync class
if ( ! class_exists( 'Forbes_Product_Sync' ) ) {
    include_once dirname( __FILE__ ) . '/includes/class-forbes-product-sync.php';
}

// Include admin classes
if ( is_admin() ) {
    include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin.php';
    include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-settings.php';
    include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-product-sync.php';
    include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-attribute-sync.php';
    include_once dirname( __FILE__ ) . '/includes/admin/class-fps-admin-sync-logs.php';
    include_once dirname( __FILE__ ) . '/includes/class-fps-ajax.php';
    
    // Initialize admin classes directly
    add_action( 'plugins_loaded', function() {
        FPS_Admin::instance();
    });
}

/**
 * Main instance of Forbes_Product_Sync.
 *
 * Returns the main instance of FPS to prevent the need to use globals.
 *
 * @return Forbes_Product_Sync
 */
function FPS() {
    return Forbes_Product_Sync::instance();
}

// Global for backwards compatibility
$GLOBALS['forbes_product_sync'] = FPS();
