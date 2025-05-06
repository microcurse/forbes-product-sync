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
 * Main plugin class
 */
class Forbes_Product_Sync {
    /**
     * Plugin version
     *
     * @var string
     */
    private $version;

    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings;

    /**
     * API instance
     *
     * @var Forbes_Product_Sync_API
     */
    private $api;

    /**
     * Product handler instance
     *
     * @var Forbes_Product_Sync_Product
     */
    private $product;

    /**
     * Logger instance
     *
     * @var Forbes_Product_Sync_Logger
     */
    private $logger;

    /**
     * Singleton instance
     *
     * @var Forbes_Product_Sync
     */
    private static $instance;

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = FORBES_PRODUCT_SYNC_VERSION;
        $this->settings = get_option('forbes_product_sync_settings', array(
            'api_url' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'sync_tag' => 'live-only'
        ));

        $this->api = new Forbes_Product_Sync_API();
        $this->product = new Forbes_Product_Sync_Product();
        $this->logger = new Forbes_Product_Sync_Logger();

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add bulk actions
        add_filter('bulk_actions-edit-product', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
        
        // Add column to products list
        add_filter('manage_edit-product_columns', array($this, 'add_sync_column'), 20);
        add_action('manage_product_posts_custom_column', array($this, 'render_sync_column'), 20, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'make_sync_column_sortable'));

        // Add AJAX handlers
        add_action('wp_ajax_forbes_product_sync_create', array($this, 'handle_create_product'));
        add_action('wp_ajax_forbes_product_sync_update', array($this, 'handle_update_product'));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'forbes_product_sync_settings', // Option group
            'forbes_product_sync_settings', // Option name
            array(
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'api_url' => '',
                    'consumer_key' => '',
                    'consumer_secret' => '',
                    'sync_tag' => 'live-only'
                ),
                'show_in_rest' => false,
                'type' => 'object'
            )
        );

        add_settings_section(
            'forbes_product_sync_api_settings',
            __('API Settings', 'forbes-product-sync'),
            array($this, 'render_api_settings_section'),
            'forbes-product-sync'
        );

        add_settings_field(
            'api_url',
            __('API URL', 'forbes-product-sync'),
            array($this, 'render_api_url_field'),
            'forbes-product-sync',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'consumer_key',
            __('Consumer Key', 'forbes-product-sync'),
            array($this, 'render_consumer_key_field'),
            'forbes-product-sync',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'consumer_secret',
            __('Consumer Secret', 'forbes-product-sync'),
            array($this, 'render_consumer_secret_field'),
            'forbes-product-sync',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'sync_tag',
            __('Sync Tag', 'forbes-product-sync'),
            array($this, 'render_sync_tag_field'),
            'forbes-product-sync',
            'forbes_product_sync_api_settings'
        );
    }

    /**
     * Render API settings section
     */
    public function render_api_settings_section() {
        echo '<p>' . esc_html__('Configure your WooCommerce REST API settings below.', 'forbes-product-sync') . '</p>';
    }

    /**
     * Render API URL field
     */
    public function render_api_url_field() {
        ?>
        <input type="url" name="forbes_product_sync_settings[api_url]" value="<?php echo esc_attr($this->settings['api_url']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter the WooCommerce REST API URL of your live site.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render consumer key field
     */
    public function render_consumer_key_field() {
        ?>
        <input type="text" name="forbes_product_sync_settings[consumer_key]" value="<?php echo esc_attr($this->settings['consumer_key']); ?>" class="regular-text">
        <?php
    }

    /**
     * Render consumer secret field
     */
    public function render_consumer_secret_field() {
        $value = isset($this->settings['consumer_secret']) ? $this->settings['consumer_secret'] : '';
        ?>
        <input type="password" name="forbes_product_sync_settings[consumer_secret]" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter your WooCommerce REST API Consumer Secret.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render sync tag field
     */
    public function render_sync_tag_field() {
        ?>
        <input type="text" name="forbes_product_sync_settings[sync_tag]" value="<?php echo esc_attr($this->settings['sync_tag']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter the tag name used to identify products for syncing.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Product Sync', 'forbes-product-sync'),
            __('Product Sync', 'forbes-product-sync'),
            'manage_options',
            'forbes-product-sync',
            array($this, 'render_sync_page'),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Sync Products', 'forbes-product-sync'),
            __('Sync Products', 'forbes-product-sync'),
            'manage_options',
            'forbes-product-sync',
            array($this, 'render_sync_page')
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Settings', 'forbes-product-sync'),
            __('Settings', 'forbes-product-sync'),
            'manage_options',
            'forbes-product-sync-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, array(
            'toplevel_page_forbes-product-sync',
            'product-sync_page_forbes-product-sync-settings'
        ))) {
            return;
        }

        wp_enqueue_style(
            'forbes-product-sync-admin',
            FORBES_PRODUCT_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'forbes-product-sync-admin',
            FORBES_PRODUCT_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $this->version,
            true
        );

        wp_localize_script(
            'forbes-product-sync-admin',
            'forbesProductSync',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('forbes_product_sync_nonce')
            )
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle test connection
        if (isset($_POST['test_connection'])) {
            check_admin_referer('forbes_product_sync_test', 'forbes_test_nonce');
            $result = $this->test_api_connection();
            
            if (is_wp_error($result)) {
                add_settings_error(
                    'forbes_product_sync',
                    'api_test_error',
                    $result->get_error_message(),
                    'error'
                );
            } else {
                add_settings_error(
                    'forbes_product_sync',
                    'api_test_success',
                    __('API connection successful!', 'forbes-product-sync'),
                    'success'
                );
            }
        }

        include FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/settings-page.php';
    }

    /**
     * Render sync page
     */
    public function render_sync_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle sync action
        if (isset($_POST['forbes_product_sync_action']) && $_POST['forbes_product_sync_action'] === 'sync') {
            check_admin_referer('forbes_product_sync_action');
            $this->run_product_sync();
        }

        // Get sync statistics
        $sync_status = new Forbes_Product_Sync_Status($this->settings);
        $synced_count = $sync_status->get_synced_count();
        $pending_count = $sync_status->get_pending_count();
        $failed_count = $sync_status->get_failed_count();

        // Get recent logs
        $logs = $this->logger->get_recent_logs(100);

        // Display any settings errors
        settings_errors('forbes_product_sync');

        include FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/sync-page.php';
    }

    /**
     * Run product sync
     */
    private function run_product_sync() {
        $products = $this->api->get_products();

        if (is_wp_error($products)) {
            add_settings_error(
                'forbes_product_sync',
                'sync_error',
                $products->get_error_message(),
                'error'
            );
            return;
        }

        $success_count = 0;
        $error_count = 0;

        foreach ($products as $product_data) {
            $result = $this->product->sync_product($product_data);

            if (is_wp_error($result)) {
                $error_count++;
            } else {
                $success_count++;
            }
        }

        add_settings_error(
            'forbes_product_sync',
            'sync_complete',
            sprintf(
                __('Sync completed. %d products synced successfully, %d failed.', 'forbes-product-sync'),
                $success_count,
                $error_count
            ),
            $error_count === 0 ? 'success' : 'warning'
        );
    }

    /**
     * Add bulk actions
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['forbes_sync_products'] = __('Sync with Live Site', 'forbes-product-sync');
        return $bulk_actions;
    }

    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'forbes_sync_products') {
            return $redirect_to;
        }

        $success_count = 0;
        $error_count = 0;

        foreach ($post_ids as $post_id) {
            $product = wc_get_product($post_id);
            if (!$product) {
                continue;
            }

            $sku = $product->get_sku();
            if (empty($sku)) {
                continue;
            }

            $product_data = $this->api->get_product_by_sku($sku);
            if (is_wp_error($product_data)) {
                $error_count++;
                continue;
            }

            $result = $this->product->sync_product($product_data);
            if (is_wp_error($result)) {
                $error_count++;
            } else {
                $success_count++;
            }
        }

        $redirect_to = add_query_arg(
            array(
                'forbes_sync_completed' => 1,
                'success_count' => $success_count,
                'error_count' => $error_count
            ),
            $redirect_to
        );

        return $redirect_to;
    }

    /**
     * Bulk action admin notice
     */
    public function bulk_action_admin_notice() {
        if (!empty($_REQUEST['forbes_sync_completed'])) {
            $success_count = intval($_REQUEST['success_count']);
            $error_count = intval($_REQUEST['error_count']);

            printf(
                '<div class="notice notice-success"><p>' . esc_html__('Sync completed. %d products synced successfully, %d failed.', 'forbes-product-sync') . '</p></div>',
                $success_count,
                $error_count
            );
        }
    }

    /**
     * Add sync column
     */
    public function add_sync_column($columns) {
        $columns['forbes_sync'] = __('Sync Status', 'forbes-product-sync');
        return $columns;
    }

    /**
     * Render sync column
     */
    public function render_sync_column($column, $post_id) {
        if ($column !== 'forbes_sync') {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }

        $sku = $product->get_sku();
        if (empty($sku)) {
            echo '<span class="forbes-sync-status missing-sku">' . esc_html__('No SKU', 'forbes-product-sync') . '</span>';
            return;
        }

        $logs = $this->logger->get_logs($sku, 1);
        if (empty($logs)) {
            echo '<span class="forbes-sync-status not-synced">' . esc_html__('Not Synced', 'forbes-product-sync') . '</span>';
            return;
        }

        $latest_log = $logs[0];
        $status_class = $latest_log->status === 'success' ? 'synced' : 'error';
        $status_text = $latest_log->status === 'success' ? __('Synced', 'forbes-product-sync') : __('Error', 'forbes-product-sync');

        echo '<span class="forbes-sync-status ' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span>';
    }

    /**
     * Make sync column sortable
     */
    public function make_sync_column_sortable($columns) {
        $columns['forbes_sync'] = 'forbes_sync';
        return $columns;
    }

    /**
     * Handle create product AJAX action
     */
    public function handle_create_product() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'forbes-product-sync'));
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        if (empty($sku)) {
            wp_send_json_error(__('SKU is required.', 'forbes-product-sync'));
        }

        $product_data = $this->api->get_product_by_sku($sku);
        if (is_wp_error($product_data)) {
            wp_send_json_error($product_data->get_error_message());
        }

        $result = $this->product->sync_product($product_data);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(__('Product created successfully.', 'forbes-product-sync'));
    }

    /**
     * Handle update product AJAX action
     */
    public function handle_update_product() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'forbes-product-sync'));
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        if (empty($sku)) {
            wp_send_json_error(__('SKU is required.', 'forbes-product-sync'));
        }

        $product_data = $this->api->get_product_by_sku($sku);
        if (is_wp_error($product_data)) {
            wp_send_json_error($product_data->get_error_message());
        }

        $result = $this->product->sync_product($product_data);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(__('Product updated successfully.', 'forbes-product-sync'));
    }

    /**
     * Test API connection
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function test_api_connection() {
        if (empty($this->settings['api_url']) || empty($this->settings['consumer_key']) || empty($this->settings['consumer_secret'])) {
            return new WP_Error('missing_settings', __('Please fill in all API settings.', 'forbes-product-sync'));
        }

        $response = $this->api->get_products(array('per_page' => 1));
        
        if (is_wp_error($response)) {
            return $response;
        }

        if (!is_array($response)) {
            return new WP_Error('invalid_response', __('Invalid API response format.', 'forbes-product-sync'));
        }
        
        return true;
    }

    /**
     * Sanitize settings
     *
     * @param array $input Settings input
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Get existing settings
        $existing_settings = get_option('forbes_product_sync_settings', array());
        
        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw(untrailingslashit($input['api_url']));
        }
        
        if (isset($input['consumer_key'])) {
            $sanitized['consumer_key'] = sanitize_text_field($input['consumer_key']);
        }
        
        // Handle consumer secret specially - only update if provided
        if (!empty($input['consumer_secret'])) {
            $sanitized['consumer_secret'] = sanitize_text_field($input['consumer_secret']);
        } else {
            $sanitized['consumer_secret'] = isset($existing_settings['consumer_secret']) ? $existing_settings['consumer_secret'] : '';
        }
        
        if (isset($input['sync_tag'])) {
            $sanitized['sync_tag'] = sanitize_text_field($input['sync_tag']);
        }
        
        return $sanitized;
    }

    /**
     * Get plugin instance
     *
     * @return Forbes_Product_Sync
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get API instance
     *
     * @return Forbes_Product_Sync_API
     */
    public function get_api() {
        return $this->api;
    }

    /**
     * Get logger instance
     *
     * @return Forbes_Product_Sync_Logger
     */
    public function get_logger() {
        return $this->logger;
    }

    /**
     * Get product handler instance
     *
     * @return Forbes_Product_Sync_Product
     */
    public function get_product() {
        return $this->product;
    }

    /**
     * Get sync statistics
     *
     * @return array
     */
    public function get_sync_stats() {
        $total = wc_get_products(array('limit' => -1, 'return' => 'ids'));
        $synced = 0;
        $pending = 0;
        $failed = 0;

        foreach ($total as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $sync_status = get_post_meta($product_id, '_forbes_sync_status', true);
            switch ($sync_status) {
                case 'synced':
                    $synced++;
                    break;
                case 'pending':
                    $pending++;
                    break;
                case 'failed':
                    $failed++;
                    break;
            }
        }

        return array(
            'total' => count($total),
            'synced' => $synced,
            'pending' => $pending,
            'failed' => $failed
        );
    }
} 