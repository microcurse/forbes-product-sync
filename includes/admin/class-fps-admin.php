<?php
/**
 * Admin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Admin Class.
 */
class FPS_Admin {
    /**
     * The single instance of the class.
     *
     * @var FPS_Admin
     */
    protected static $_instance = null;

    /**
     * Main FPS_Admin Instance.
     *
     * @return FPS_Admin
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Add menu items
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        
        // Enqueue admin scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
    }

    /**
     * Add menu items.
     */
    public function admin_menu() {
        $menu_icon = 'dashicons-update-alt';
        
        // Add top level menu
        add_menu_page(
            __( 'Forbes Product Sync', 'forbes-product-sync' ),
            __( 'Sync Plugin', 'forbes-product-sync' ),
            'manage_options',
            'forbes-product-sync',
            array( $this, 'settings_page' ),
            $menu_icon,
            58 // Position
        );
        
        // Add submenus
        add_submenu_page(
            'forbes-product-sync',
            __( 'Settings', 'forbes-product-sync' ),
            __( 'Settings', 'forbes-product-sync' ),
            'manage_options',
            'forbes-product-sync',
            array( $this, 'settings_page' )
        );
        
        add_submenu_page(
            'forbes-product-sync',
            __( 'Product Sync', 'forbes-product-sync' ),
            __( 'Product Sync', 'forbes-product-sync' ),
            'manage_options',
            'forbes-product-sync-products',
            array( $this, 'product_sync_page' )
        );
        
        add_submenu_page(
            'forbes-product-sync',
            __( 'Attribute Sync', 'forbes-product-sync' ),
            __( 'Attribute Sync', 'forbes-product-sync' ),
            'manage_options',
            'forbes-product-sync-attributes',
            array( $this, 'attribute_sync_page' )
        );
        
        add_submenu_page(
            'forbes-product-sync',
            __( 'Sync Logs', 'forbes-product-sync' ),
            __( 'Sync Logs', 'forbes-product-sync' ),
            'manage_options',
            'forbes-product-sync-logs',
            array( $this, 'sync_logs_page' )
        );
    }

    /**
     * Enqueue scripts and styles for admin pages.
     */
    public function admin_scripts() {
        $screen = get_current_screen();
        
        // Only load on plugin pages
        if ( strpos( $screen->id, 'forbes-product-sync' ) === false ) {
            return;
        }
        
        // Get plugin version
        $version = defined( 'FPS_VERSION' ) ? FPS_VERSION : '1.0.0';
        
        // Register and enqueue styles
        wp_register_style(
            'fps-admin-styles',
            FPS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $version
        );
        wp_enqueue_style( 'fps-admin-styles' );
        
        // Register and enqueue scripts
        wp_register_script(
            'fps-admin-scripts',
            FPS_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            $version,
            true
        );
        
        wp_localize_script(
            'fps-admin-scripts',
            'fps_params',
            array(
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'fps-admin-nonce' ),
                'test_connection_prompt' => __( 'Testing connection...', 'forbes-product-sync' ),
                'test_success' => __( 'Connection successful!', 'forbes-product-sync' ),
                'test_error'   => __( 'Connection failed: ', 'forbes-product-sync' ),
            )
        );
        
        wp_enqueue_script( 'fps-admin-scripts' );
    }

    /**
     * Settings page.
     */
    public function settings_page() {
        // Debug info to display on the page
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->debug_info();
        }
        
        // Try to use the class-based approach first
        if ( class_exists( 'FPS_Admin_Settings' ) ) {
            FPS_Admin_Settings::output();
            return;
        }
        
        // If class doesn't exist, try to include the direct settings page
        $direct_settings_file = FPS_PLUGIN_DIR . 'includes/admin/settings-page.php';
        if ( file_exists( $direct_settings_file ) ) {
            include_once $direct_settings_file;
            if ( function_exists( 'fps_render_settings_page' ) ) {
                fps_render_settings_page();
                return;
            }
        }
        
        // Fallback if nothing else works
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Forbes Product Sync Settings', 'forbes-product-sync' ); ?></h1>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Error: Settings class not found. Please deactivate and reactivate the plugin.', 'forbes-product-sync' ); ?></p>
            </div>
            
            <!-- Manual Settings Form as Last Resort -->
            <form method="post" action="options.php">
                <?php settings_fields( 'fps_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fps_remote_site_url">Remote Site URL</label></th>
                        <td>
                            <input name="fps_remote_site_url" type="url" id="fps_remote_site_url" value="<?php echo esc_attr( get_option( 'fps_remote_site_url', '' ) ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fps_api_username">API Username/Key</label></th>
                        <td>
                            <input name="fps_api_username" type="text" id="fps_api_username" value="<?php echo esc_attr( get_option( 'fps_api_username', '' ) ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fps_api_password">API Password/Token</label></th>
                        <td>
                            <input name="fps_api_password" type="password" id="fps_api_password" value="<?php echo esc_attr( get_option( 'fps_api_password', '' ) ); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Product sync page.
     */
    public function product_sync_page() {
        FPS_Admin_Product_Sync::output();
    }
    
    /**
     * Attribute sync page.
     */
    public function attribute_sync_page() {
        FPS_Admin_Attribute_Sync::output();
    }
    
    /**
     * Sync logs page.
     */
    public function sync_logs_page() {
        FPS_Admin_Sync_Logs::output();
    }
    
    /**
     * Output debug information.
     */
    private function debug_info() {
        ?>
        <div class="notice notice-info">
            <h3><?php esc_html_e( 'Debug Information', 'forbes-product-sync' ); ?></h3>
            <ul>
                <li><?php echo 'FPS_PLUGIN_FILE: ' . (defined('FPS_PLUGIN_FILE') ? esc_html(FPS_PLUGIN_FILE) : 'Not defined'); ?></li>
                <li><?php echo 'FPS_PLUGIN_DIR: ' . (defined('FPS_PLUGIN_DIR') ? esc_html(FPS_PLUGIN_DIR) : 'Not defined'); ?></li>
                <li><?php echo 'FPS_PLUGIN_URL: ' . (defined('FPS_PLUGIN_URL') ? esc_html(FPS_PLUGIN_URL) : 'Not defined'); ?></li>
                <li><?php echo 'FPS_VERSION: ' . (defined('FPS_VERSION') ? esc_html(FPS_VERSION) : 'Not defined'); ?></li>
                <li><?php echo 'Settings class exists: ' . (class_exists('FPS_Admin_Settings') ? 'Yes' : 'No'); ?></li>
                <li><?php echo 'Admin class exists: ' . (class_exists('FPS_Admin') ? 'Yes' : 'No'); ?></li>
                <li><?php echo 'AJAX class exists: ' . (class_exists('FPS_AJAX') ? 'Yes' : 'No'); ?></li>
                <li><?php echo 'get_current_screen()->id: ' . esc_html(get_current_screen()->id); ?></li>
            </ul>
        </div>
        <?php
    }
} 