<?php
/**
 * Plugin Name: Forbes Product Sync
 * Plugin URI: https://github.com/microcurse
 * Description: Pulls products from the Live site into the Forbes Portal using WooCommerce REST API
 * Version: 1.1.0
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
define('FORBES_PRODUCT_SYNC_VERSION', '1.1.0');
define('FORBES_PRODUCT_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FORBES_PRODUCT_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Define database table name constant globally for activation hook compatibility
global $wpdb;
if (!defined('FORBES_PRODUCT_SYNC_LOG_TABLE')) {
    define('FORBES_PRODUCT_SYNC_LOG_TABLE', $wpdb->prefix . 'forbes_product_sync_log');
}

/**
 * Main plugin initialization class
 */
final class Forbes_Product_Sync_Plugin {
    /**
     * Singleton instance
     *
     * @var self
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Define additional constants
     */
    private function define_constants() {
        // Database table name is defined globally now
        // define('FORBES_PRODUCT_SYNC_LOG_TABLE', $GLOBALS['wpdb']->prefix . 'forbes_product_sync_log');
    }
    
    /**
     * Include required files
     */
    private function includes() {
        // Core classes - Load these first
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/class-forbes-product-sync-autoloader.php';
        Forbes_Product_Sync_Autoloader::register();
        
        // Manually load Logger class as it's needed early by other classes
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/logging/class-forbes-product-sync-logger.php';
        
        // Load core functionality class definition (constructor runs later during plugins_loaded)
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/class-forbes-product-sync.php';
        
        // Load admin UI only when in admin
        if (is_admin()) {
            require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-admin.php';
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize the plugin after WordPress is fully loaded
        add_action('plugins_loaded', array($this, 'init'), 20);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
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
        $this->create_tables();
        
        // Set initial options
        $this->set_default_options();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_name = FORBES_PRODUCT_SYNC_LOG_TABLE;
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
    
    /**
     * Set default options
     */
    private function set_default_options() {
        // Set default settings if they don't exist
        if (!get_option('forbes_product_sync_settings')) {
            update_option('forbes_product_sync_settings', array(
                'api_url' => '',
                'consumer_key' => '',
                'consumer_secret' => '',
                'sync_tag' => 'live-only'
            ));
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Do not delete any data on deactivation
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load text domain for internationalization
        load_plugin_textdomain('forbes-product-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize the main plugin class
        new Forbes_Product_Sync();
        
        // Initialize the admin class if in admin
        if (is_admin()) {
            new Forbes_Product_Sync_Admin();
        }
    }
}

// Initialize the plugin
Forbes_Product_Sync_Plugin::instance();
