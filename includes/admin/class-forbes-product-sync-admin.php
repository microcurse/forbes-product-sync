<?php
/**
 * Admin interface for product sync
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Admin
 * Handles admin interface, menus, and settings
 */
class Forbes_Product_Sync_Admin {
    /**
     * The plugin version
     *
     * @var string
     */
    private $version;
    
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
        // Admin menu
        add_action('admin_menu', array($this, 'add_menu_pages'));
        
        // Admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX handlers
        add_action('wp_ajax_forbes_product_sync_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_forbes_product_sync_get_attribute_differences', array($this, 'ajax_get_attribute_differences'));
        add_action('wp_ajax_forbes_product_sync_process_attributes', array($this, 'ajax_process_attributes'));
        add_action('wp_ajax_forbes_product_sync_process_batch', array($this, 'ajax_process_batch'));
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        // Add main menu item
        add_menu_page(
            __('Forbes Product Sync', 'forbes-product-sync'),
            __('Product Sync', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync',
            array($this, 'render_main_page'),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Dashboard', 'forbes-product-sync'),
            __('Dashboard', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync',
            array($this, 'render_main_page')
        );
        
        add_submenu_page(
            'forbes-product-sync',
            __('Attribute Sync', 'forbes-product-sync'),
            __('Attribute Sync', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync-attributes',
            array($this, 'render_attribute_sync_page')
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Sync Log', 'forbes-product-sync'),
            __('Sync Log', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync-log',
            array($this, 'render_log_page')
        );
        
        add_submenu_page(
            'forbes-product-sync',
            __('Settings', 'forbes-product-sync'),
            __('Settings', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page
     */
    public function enqueue_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'forbes-product-sync') === false) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'forbes-product-sync-admin',
            FORBES_PRODUCT_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $this->version
        );
        
        // Scripts
        wp_enqueue_script(
            'forbes-product-sync-admin',
            FORBES_PRODUCT_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Localize script with data
        wp_localize_script(
            'forbes-product-sync-admin',
            'forbesProductSync',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('forbes_product_sync_nonce'),
                'i18n' => array(
                    'confirmApply' => __('Are you sure you want to apply these changes?', 'forbes-product-sync'),
                    'processing' => __('Processing', 'forbes-product-sync'),
                    'error' => __('An error occurred. Please try again.', 'forbes-product-sync'),
                    'noChangesSelected' => __('Please select at least one change to apply.', 'forbes-product-sync'),
                    'refreshingCache' => __('Refreshing attribute cache...', 'forbes-product-sync'),
                    'syncing' => __('Syncing...', 'forbes-product-sync'),
                    'synced' => __('Synced', 'forbes-product-sync'),
                    'syncingMetadata' => __('Syncing metadata in the background...', 'forbes-product-sync'),
                    'startSync' => __('Apply Changes', 'forbes-product-sync'),
                    'syncProducts' => __('Sync Products', 'forbes-product-sync'),
                    'testConnection' => __('Test Connection', 'forbes-product-sync'),
                    'confirmSync' => __('Are you sure you want to start this sync operation?', 'forbes-product-sync'),
                    'cancel' => __('Cancel', 'forbes-product-sync'),
                    'cancelling' => __('Cancelling...', 'forbes-product-sync'),
                    'canceled' => __('Sync cancelled.', 'forbes-product-sync'),
                    'completed' => __('Completed!', 'forbes-product-sync'),
                    'downloadingProducts' => __('Downloading products...', 'forbes-product-sync'),
                    'confirmCancel' => __('Are you sure you want to cancel the sync operation?', 'forbes-product-sync')
                )
            )
        );
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
            'forbes-product-sync-settings'
        );

        add_settings_field(
            'api_url',
            __('API URL', 'forbes-product-sync'),
            array($this, 'render_api_url_field'),
            'forbes-product-sync-settings',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'consumer_key',
            __('Consumer Key', 'forbes-product-sync'),
            array($this, 'render_consumer_key_field'),
            'forbes-product-sync-settings',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'consumer_secret',
            __('Consumer Secret', 'forbes-product-sync'),
            array($this, 'render_consumer_secret_field'),
            'forbes-product-sync-settings',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'sync_tag',
            __('Sync Tag', 'forbes-product-sync'),
            array($this, 'render_sync_tag_field'),
            'forbes-product-sync-settings',
            'forbes_product_sync_api_settings'
        );
    }
    
    /**
     * Sanitize settings
     *
     * @param array $input The value being saved
     * @return array The sanitized value
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw(trim($input['api_url']));
        }
        
        if (isset($input['consumer_key'])) {
            $sanitized['consumer_key'] = sanitize_text_field($input['consumer_key']);
        }
        
        if (isset($input['consumer_secret'])) {
            $sanitized['consumer_secret'] = sanitize_text_field($input['consumer_secret']);
        }
        
        if (isset($input['sync_tag'])) {
            $sanitized['sync_tag'] = sanitize_text_field($input['sync_tag']);
        }
        
        return $sanitized;
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
        $options = get_option('forbes_product_sync_settings');
        ?>
        <input type="url" name="forbes_product_sync_settings[api_url]" value="<?php echo esc_attr($options['api_url']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter the WooCommerce REST API URL of your live site.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render consumer key field
     */
    public function render_consumer_key_field() {
        $options = get_option('forbes_product_sync_settings');
        ?>
        <input type="text" name="forbes_product_sync_settings[consumer_key]" value="<?php echo esc_attr($options['consumer_key']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter your WooCommerce REST API Consumer Key.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render consumer secret field
     */
    public function render_consumer_secret_field() {
        $options = get_option('forbes_product_sync_settings');
        $value = isset($options['consumer_secret']) ? $options['consumer_secret'] : '';
        ?>
        <input type="password" name="forbes_product_sync_settings[consumer_secret]" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter your WooCommerce REST API Consumer Secret.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render sync tag field
     */
    public function render_sync_tag_field() {
        $options = get_option('forbes_product_sync_settings');
        ?>
        <input type="text" name="forbes_product_sync_settings[sync_tag]" value="<?php echo esc_attr($options['sync_tag']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter the tag name used to identify products for syncing.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render main page
     */
    public function render_main_page() {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/dashboard.php';
    }

    /**
     * Render attribute sync page
     */
    public function render_attribute_sync_page() {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/attribute-sync.php';
    }

    /**
     * Render log page
     */
    public function render_log_page() {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/log.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/settings.php';
    }
    
    /**
     * AJAX handler: Test API connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        $api = new Forbes_Product_Sync_API_Client();
        $result = $api->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Connection successful!', 'forbes-product-sync')));
        }
    }
    
    /**
     * AJAX handler: Get attribute differences
     */
    public function ajax_get_attribute_differences() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        // Check if this is a cache refresh request
        $refresh_cache = isset($_POST['refresh_cache']) && $_POST['refresh_cache'];
        
        $api = new Forbes_Product_Sync_API_Attributes();
        
        if ($refresh_cache) {
            $api->clear_attribute_caches();
        }
        
        // Get all attributes and their terms
        $source_data = $api->get_attributes_with_terms();
        
        if (is_wp_error($source_data) || !isset($source_data['attributes']) || !is_array($source_data['attributes'])) {
            wp_send_json_error(array('message' => __('Failed to fetch source attributes.', 'forbes-product-sync')));
        }
        
        // Load the attributes comparison class
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-attributes-comparison.php';
        $comparison = new Forbes_Product_Sync_Attributes_Comparison();
        $result = $comparison->compare_attributes($source_data);
        
        wp_send_json_success(array(
            'html' => $comparison->render_comparison_table($result),
            'count' => $comparison->get_stats($result),
            'timestamp' => $api->get_cache_timestamp()
        ));
    }
    
    /**
     * AJAX handler: Process attributes
     */
    public function ajax_process_attributes() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        if (!isset($_POST['terms']) || !is_array($_POST['terms'])) {
            wp_send_json_error(array('message' => __('No terms selected.', 'forbes-product-sync')));
        }
        
        $terms = $_POST['terms'];
        $sync_metadata = isset($_POST['sync_metadata']) && $_POST['sync_metadata'] ? true : false;
        $handle_conflicts = isset($_POST['handle_conflicts']) && $_POST['handle_conflicts'] ? true : false;
        
        // Load the attribute processor
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-attributes-processor.php';
        $processor = new Forbes_Product_Sync_Attributes_Processor();
        $result = $processor->process_attributes($terms, $sync_metadata, $handle_conflicts);
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Processed %d terms with %d created, %d updated, and %d errors.', 'forbes-product-sync'),
                count($terms),
                $result['created'],
                $result['updated'],
                $result['errors']
            ),
            'stats' => $result
        ));
    }
    
    /**
     * AJAX handler: Process batch
     */
    public function ajax_process_batch() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
        }
        
        $batch_processor = new Forbes_Product_Sync_Batch_Processor();
        $result = $batch_processor->process_next_batch();
        
        if ($result === false) {
            $final_status = $batch_processor->get_status();
            
            wp_send_json_success(array(
                'complete' => true,
                'message' => __('Processing complete!', 'forbes-product-sync'),
                'status' => $final_status
            ));
        } else {
            $progress = $batch_processor->get_progress();
            
            wp_send_json_success(array(
                'complete' => false,
                'progress' => $progress,
                'message' => sprintf(
                    __('Processed %d of %d items (%d%%)...', 'forbes-product-sync'),
                    $progress['processed'],
                    $progress['total'],
                    $progress['percent']
                )
            ));
        }
    }
} 