<?php
/**
 * Main plugin class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync
 * Main plugin class
 */
class Forbes_Product_Sync {
    /**
     * Plugin version
     *
     * @var string
     */
    public $version;
    
    /**
     * Logger instance
     *
     * @var Forbes_Product_Sync_Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->version = FORBES_PRODUCT_SYNC_VERSION;
        $this->logger = Forbes_Product_Sync_Logger::instance();
        
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom post types if needed
        add_action('init', array($this, 'register_post_types'));
        
        // Register REST API endpoints if needed
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Schedule sync events
        add_action('init', array($this, 'schedule_events'));
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Add product admin column
        add_filter('manage_product_posts_columns', array($this, 'add_product_sync_column'));
        add_action('manage_product_posts_custom_column', array($this, 'render_product_sync_column'), 10, 2);
    }
    
    /**
     * Register custom post types
     */
    public function register_post_types() {
        // No custom post types needed
    }
    
    /**
     * Register REST API endpoints
     */
    public function register_rest_routes() {
        // Register custom REST API endpoints if needed
    }
    
    /**
     * Schedule sync events
     */
    public function schedule_events() {
        if (!wp_next_scheduled('forbes_product_sync_daily')) {
            wp_schedule_event(time(), 'daily', 'forbes_product_sync_daily');
        }
        
        add_action('forbes_product_sync_daily', array($this, 'run_daily_sync'));
    }
    
    /**
     * Run daily sync
     */
    public function run_daily_sync() {
        $this->logger->log_sync(
            'Daily Sync',
            'info',
            'Starting daily sync process'
        );
        
        // Check if there's an active batch process
        $batch_processor = new Forbes_Product_Sync_Batch_Processor();
        if ($batch_processor->is_processing()) {
            $this->logger->log_sync(
                'Daily Sync',
                'warning',
                'A batch process is already running. Skipping daily sync.'
            );
            return;
        }
        
        // Create a new sync job - we'll add functionality for this later
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Product sync initialization
        add_action('wp_ajax_forbes_product_sync_init_products', array($this, 'ajax_init_products'));
        
        // Check sync status
        add_action('wp_ajax_forbes_product_sync_check_status', array($this, 'ajax_check_status'));
        
        // Cancel sync
        add_action('wp_ajax_forbes_product_sync_cancel', array($this, 'ajax_cancel_sync'));
    }
    
    /**
     * AJAX: Initialize product sync
     */
    public function ajax_init_products() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        // Get products from API
        $api = new Forbes_Product_Sync_API_Products();
        $products = $api->get_all_sync_products();
        
        if (is_wp_error($products)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Failed to get products: %s', 'forbes-product-sync'),
                    $products->get_error_message()
                )
            ));
        }
        
        if (empty($products)) {
            wp_send_json_error(array('message' => __('No products found to sync.', 'forbes-product-sync')));
        }
        
        // Initialize queue for processing
        $batch_processor = new Forbes_Product_Sync_Batch_Processor();
        $batch_processor->initialize_products_queue($products);
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Initialized sync for %d products.', 'forbes-product-sync'),
                count($products)
            ),
            'redirect' => admin_url('admin.php?page=forbes-product-sync')
        ));
    }
    
    /**
     * AJAX: Check sync status
     */
    public function ajax_check_status() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        $batch_processor = new Forbes_Product_Sync_Batch_Processor();
        $is_processing = $batch_processor->is_processing();
        $progress = $batch_processor->get_progress();
        
        wp_send_json_success(array(
            'is_processing' => $is_processing,
            'progress' => $progress
        ));
    }
    
    /**
     * AJAX: Cancel sync
     */
    public function ajax_cancel_sync() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        $batch_processor = new Forbes_Product_Sync_Batch_Processor();
        $batch_processor->cancel_queue();
        
        wp_send_json_success(array(
            'message' => __('Sync operation cancelled.', 'forbes-product-sync')
        ));
    }
    
    /**
     * Add product sync column
     *
     * @param array $columns Product columns
     * @return array Modified columns
     */
    public function add_product_sync_column($columns) {
        $columns['forbes_sync'] = __('Forbes Sync', 'forbes-product-sync');
        return $columns;
    }
    
    /**
     * Render product sync column
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function render_product_sync_column($column, $post_id) {
        if ($column !== 'forbes_sync') {
            return;
        }
        
        $last_sync = get_post_meta($post_id, '_forbes_last_sync_time', true);
        $product = wc_get_product($post_id);
        
        if (!$product) {
            return;
        }
        
        $sku = $product->get_sku();
        $logs = $this->logger->get_logs_by_sku($sku, 1);
        $status = empty($logs) ? 'none' : $logs[0]['status'];
        
        if ($last_sync) {
            echo '<span class="status-badge status-' . esc_attr($status) . '">';
            echo esc_html(human_time_diff($last_sync, time()) . ' ' . __('ago', 'forbes-product-sync'));
            echo '</span>';
        } else {
            echo '<span class="status-badge status-none">';
            echo esc_html__('Not synced', 'forbes-product-sync');
            echo '</span>';
        }
    }
    
    /**
     * Get plugin setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public function get_setting($key, $default = '') {
        $settings = get_option('forbes_product_sync_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
} 