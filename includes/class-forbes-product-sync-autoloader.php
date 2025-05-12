<?php
/**
 * Autoloader for Forbes Product Sync
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Autoloader
 */
class Forbes_Product_Sync_Autoloader {
    /**
     * Path to the includes directory
     *
     * @var string
     */
    private static $includes_path = '';

    /**
     * Register the autoloader
     */
    public static function register() {
        self::$includes_path = FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/';
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    /**
     * Autoload class files
     *
     * @param string $class Class name.
     */
    public static function autoload($class) {
        // Only handle our plugin's classes
        if (false === strpos($class, 'Forbes_Product_Sync')) {
            return;
        }

        // Convert to filename format
        $file = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';

        // Create path based on class name
        $path = self::$includes_path;
        
        // Check for admin classes
        if (false !== strpos($class, 'Admin')) {
            $path .= 'admin/';
        } else if (false !== strpos($class, 'API')) {
            $path .= 'api/';
        } else if (false !== strpos($class, 'Product')) {
            $path .= 'product/';
        } else if (false !== strpos($class, 'Attribute')) {
            $path .= 'attributes/';
        } else if (false !== strpos($class, 'Logger') || false !== strpos($class, 'Status')) {
            $path .= 'logging/';
        } else if (false !== strpos($class, 'Queue')) {
            $path .= 'batch/';
        } else if (false !== strpos($class, 'Batch')) {
            $path .= 'batch/';
        }
        
        // Full path to file
        $filepath = $path . $file;
        
        // Include the file if it exists
        if (file_exists($filepath)) {
            require_once $filepath;
        }
    }
} 