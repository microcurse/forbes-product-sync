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
        // Define constants safely in proper order
        $this->define( 'FPS_VERSION', $this->version );
        $this->define( 'FPS_PLUGIN_BASENAME', plugin_basename( FPS_PLUGIN_FILE ) );
        $this->define( 'FPS_ABSPATH', dirname( FPS_PLUGIN_FILE ) . '/' );
        $this->define( 'FPS_TEMPLATE_PATH', dirname( FPS_PLUGIN_FILE ) . '/templates/' );
        $this->define( 'FPS_ASSETS_PATH', dirname( FPS_PLUGIN_FILE ) . '/assets/' );
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
        // Admin includes.
        if ( $this->is_request( 'admin' ) ) {
            include_once dirname( FPS_PLUGIN_FILE ) . '/includes/admin/class-fps-admin.php';
            include_once dirname( FPS_PLUGIN_FILE ) . '/includes/admin/class-fps-admin-settings.php';
            include_once dirname( FPS_PLUGIN_FILE ) . '/includes/admin/class-fps-admin-product-sync.php';
            include_once dirname( FPS_PLUGIN_FILE ) . '/includes/admin/class-fps-admin-attribute-sync.php';
            include_once dirname( FPS_PLUGIN_FILE ) . '/includes/admin/class-fps-admin-sync-logs.php';
        }

        // Core includes.
        include_once dirname( FPS_PLUGIN_FILE ) . '/includes/class-fps-ajax.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        register_activation_hook( FPS_PLUGIN_FILE, array( $this, 'on_activation' ) );
        add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
    }

    /**
     * Hook that runs on plugin activation.
     */
    public function on_activation() {
        // Create tables, set default options, etc.
    }

    /**
     * Hook that runs when plugins are loaded.
     */
    public function on_plugins_loaded() {
        // Initialize plugin components.
        if ( $this->is_request( 'admin' ) ) {
            FPS_Admin::instance();
        }
    }

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
                return defined( 'DOING_AJAX' );
            case 'cron':
                return defined( 'DOING_CRON' );
            case 'frontend':
                return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
        }
    }
} 