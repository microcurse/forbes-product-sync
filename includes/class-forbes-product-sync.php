<?php
/**
 * Main plugin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forbes Product Sync main class.
 */
class Forbes_Product_Sync {
    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = '1.0.0';

    /**
     * The single instance of the class.
     *
     * @var Forbes_Product_Sync
     */
    protected static $_instance = null;

    /**
     * Main Forbes_Product_Sync Instance.
     *
     * Ensures only one instance of Forbes_Product_Sync is loaded or can be loaded.
     *
     * @return Forbes_Product_Sync - Main instance.
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Forbes_Product_Sync Constructor.
     */
    public function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define constants.
     */
    private function define_constants() {
        // FPS_PLUGIN_FILE, FPS_PLUGIN_DIR, FPS_PLUGIN_URL, FPS_VERSION are defined in the main plugin file.
        $this->define( 'FPS_ABSPATH', FPS_PLUGIN_DIR ); // Redundant with FPS_PLUGIN_DIR but kept for compatibility if used elsewhere.
        $this->define( 'FPS_TEMPLATE_PATH', FPS_PLUGIN_DIR . 'templates/' );
        $this->define( 'FPS_ASSETS_PATH', FPS_PLUGIN_DIR . 'assets/' );
    }

    /**
     * Define constant if not already set.
     *
     * @param string      $name  Constant name.
     * @param string|bool $value Constant value.
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /**
     * Include required core files.
     */
    private function includes() {
        // Core includes - always loaded.
        require_once FPS_PLUGIN_DIR . 'includes/class-fps-logger.php';
        require_once FPS_PLUGIN_DIR . 'includes/class-fps-ajax.php';

        // Admin includes - only loaded if is_admin().
        if ( $this->is_request( 'admin' ) || $this->is_request( 'ajax' ) ) { // AJAX handlers might need admin classes
            require_once FPS_PLUGIN_DIR . 'includes/admin/class-fps-admin.php';
            require_once FPS_PLUGIN_DIR . 'includes/admin/class-fps-admin-settings.php';
            require_once FPS_PLUGIN_DIR . 'includes/admin/class-fps-admin-product-sync.php';
            require_once FPS_PLUGIN_DIR . 'includes/admin/class-fps-admin-attribute-sync.php';
            require_once FPS_PLUGIN_DIR . 'includes/admin/class-fps-admin-sync-logs.php';
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // The activation hook is now in the main plugin file.
        // add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) ); // This class is already instantiated on plugins_loaded.

        // Initialize components that need to be hooked after includes.
        if ( class_exists( 'FPS_AJAX' ) ) {
            FPS_AJAX::init();
        }
        if ( class_exists( 'FPS_Logger' ) ) {
            FPS_Logger::init(); // Ensure logger is initialized for table name etc.
        }

        if ( $this->is_request( 'admin' ) ) {
            if ( class_exists( 'FPS_Admin' ) ) {
                FPS_Admin::instance(); // Re-enable FPS_Admin initialization
            }
            if ( class_exists( 'FPS_Admin_Settings' ) ) {
                 // FPS_Admin_Settings::init(); // This is called via add_action( 'admin_init') in its own file.
            }
        }
    }

    /**
     * Hook that runs on plugin activation.
     * This is now handled by fps_plugin_activation in the main plugin file.
     */
    // public function on_activation() {
        // FPS_Logger::create_table(); // Example, actual call is now in main plugin file.
    // }

    /**
     * Hook that runs when plugins are loaded.
     * This method is effectively replaced by the constructor logic as this class is now instantiated on plugins_loaded.
     */
    // public function on_plugins_loaded() {
        // // Initialize plugin components.
        // if ( $this->is_request( 'admin' ) ) {
        //     FPS_Admin::instance();
        // }
    // }

    /**
     * What type of request is this?
     *
     * @param string $type admin, ajax, cron or frontend.
     * @return bool
     */
    private function is_request( $type ) {
        switch ( $type ) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined( 'DOING_AJAX' ) && DOING_AJAX;
            case 'cron':
                return defined( 'DOING_CRON' ) && DOING_CRON;
            case 'frontend':
                return ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) && ! ( defined( 'DOING_CRON' ) && DOING_CRON );
        }
        return false; // Default case
    }
} 