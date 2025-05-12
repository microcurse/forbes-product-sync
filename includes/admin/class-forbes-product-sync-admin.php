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
        // Add admin menu items
        add_action('admin_menu', [$this, 'add_menu_pages']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Register AJAX handlers
        add_action('init', [$this, 'register_ajax_handlers']);
        
        // Add custom attribute meta
        add_action('woocommerce_attribute_added', [$this, 'on_attribute_added'], 10, 2);
        
        // Add admin notices
        add_action('admin_notices', [$this, 'display_admin_notices']);
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
        
        // Register and enqueue styles
        wp_register_style(
            'forbes-product-sync-admin',
            FORBES_PRODUCT_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $this->version
        );
        wp_enqueue_style('forbes-product-sync-admin');
        
        // Register and enqueue scripts
        wp_register_script(
            'forbes-product-sync-admin',
            FORBES_PRODUCT_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            $this->version,
            true
        );
        
        // Localize script with translations and settings
        wp_localize_script('forbes-product-sync-admin', 'forbesProductSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('forbes_product_sync_nonce'),
            'compareUrl' => admin_url('admin.php?page=forbes-product-sync-attribute-sync&view=compare'),
            'i18n' => [
                'error' => __('An error occurred.', 'forbes-product-sync'),
                'loading_error' => __('Error loading data.', 'forbes-product-sync'),
                'retry_attempt' => __('Retry attempt %d...', 'forbes-product-sync'),
                'failed_after_retries' => __('Failed after multiple attempts. Please try refreshing the page.', 'forbes-product-sync'),
                'load_attributes_error' => __('Error loading attributes.', 'forbes-product-sync'),
                'refresh_error' => __('Error refreshing attributes.', 'forbes-product-sync'),
                'refreshing' => __('Refreshing...', 'forbes-product-sync'),
                'refresh' => __('Refresh', 'forbes-product-sync'),
                'no_terms_selected' => __('No terms selected.', 'forbes-product-sync'),
                'sync_error' => __('Error syncing terms.', 'forbes-product-sync'),
                'sync_complete' => __('Sync completed successfully.', 'forbes-product-sync'),
                'testConnection' => __('Test Connection', 'forbes-product-sync'),
                'processing' => __('Processing...', 'forbes-product-sync'),
                'syncProducts' => __('Sync Products', 'forbes-product-sync'),
                'downloadingProducts' => __('Downloading Products...', 'forbes-product-sync'),
                'confirmSync' => __('Are you sure you want to start the sync? This may take a few minutes.', 'forbes-product-sync'),
                'confirmCancel' => __('Are you sure you want to cancel the sync?', 'forbes-product-sync'),
                'cancelling' => __('Cancelling...', 'forbes-product-sync'),
                'cancel' => __('Cancel', 'forbes-product-sync'),
                'total_attributes' => __('Total Attributes', 'forbes-product-sync'),
                'total_terms' => __('Total Terms', 'forbes-product-sync'),
                'total_differences' => __('Total Differences', 'forbes-product-sync'),
                'new_attributes' => __('New Attributes', 'forbes-product-sync'),
                'modified_attributes' => __('Modified Attributes', 'forbes-product-sync'),
                'new_terms' => __('New Terms', 'forbes-product-sync'),
                'modified_terms' => __('Modified Terms', 'forbes-product-sync'),
                'view_differences' => __('View Differences', 'forbes-product-sync'),
                'no_differences' => __('No differences found. Attributes are in sync.', 'forbes-product-sync'),
                'last_updated' => __('Last Updated', 'forbes-product-sync'),
                'terms' => __('terms', 'forbes-product-sync'),
                'more' => __('more', 'forbes-product-sync'),
            ]
        ]);
        
        wp_enqueue_script('forbes-product-sync-admin');
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
        // Initialize variables that will be used in the template
        $sync_result = false;
        $sync_stats = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0
        );
        
        // Process form submission if sync_selected_attributes action is sent
        if (isset($_POST['forbes_product_sync_action']) && 
            $_POST['forbes_product_sync_action'] === 'sync_selected_attributes' && 
            isset($_POST['forbes_product_sync_selected_attributes_nonce']) && 
            wp_verify_nonce($_POST['forbes_product_sync_selected_attributes_nonce'], 'forbes_product_sync_selected_attributes')) {
            
            // Check if selected terms are set
            if (isset($_POST['selected_terms']) && is_array($_POST['selected_terms'])) {
                fps_debug('Processing selected terms form submission', 'Info');
                
                // Load required classes
                if (!class_exists('Forbes_Product_Sync_API_Attributes')) {
                    require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/api/class-forbes-product-sync-api-attributes.php';
                }
                
                if (!class_exists('Forbes_Product_Sync_Attributes_Handler')) {
                    require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/attributes/class-forbes-product-sync-attributes-handler.php';
                }
                
                if (!class_exists('Forbes_Product_Sync_Attributes_Processor')) {
                    require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-attributes-processor.php';
                }
                
                try {
                    // Get selected terms data from the form
                    $selected_terms = $_POST['selected_terms'];
                    fps_debug('Selected terms: ' . count($selected_terms) . ' attribute(s)', 'Info');
                    
                    // Get sync options
                    $sync_metadata = isset($_POST['sync_metadata']) && $_POST['sync_metadata'] === '1';
                    $handle_conflicts = isset($_POST['handle_conflicts']) && $_POST['handle_conflicts'] === '1';
                    
                    // Prepare terms array for processing
                    $terms_to_process = array();
                    foreach ($selected_terms as $attr_slug => $terms) {
                        foreach ($terms as $term_slug => $value) {
                            $terms_to_process[] = array(
                                'attr' => $attr_slug,
                                'term' => $term_slug
                            );
                        }
                    }
                    
                    // Process the terms if there are any
                    if (!empty($terms_to_process)) {
                        fps_debug('Processing ' . count($terms_to_process) . ' terms', 'Info');
                        
                        // Create the processor
                        $processor = new Forbes_Product_Sync_Attributes_Processor();
                        
                        // Process the terms
                        $result = $processor->process_attributes($terms_to_process, $sync_metadata, $handle_conflicts);
                        
                        // Store results to show on the page
                        $sync_result = true;
                        $sync_stats = $result;
                        
                        // Log the sync operation
                        $logger = Forbes_Product_Sync_Logger::instance();
                        $logger->log_sync(
                            'Attribute Synchronization',
                            ($result['errors'] > 0) ? 'warning' : 'success',
                            sprintf(
                                'Processed %d terms with %d created, %d updated, %d skipped, and %d errors.',
                                count($terms_to_process),
                                $result['created'],
                                $result['updated'],
                                $result['skipped'],
                                $result['errors']
                            ),
                            array(),
                            'attribute'
                        );
                        
                        fps_debug('Terms processing complete. Created: ' . $result['created'] . ', Updated: ' . $result['updated'] . ', Errors: ' . $result['errors'], 'Info');
                        
                        // Force refresh attribute cache
                        $handler = new Forbes_Product_Sync_Attributes_Handler();
                        $handler->refresh_attribute_cache();
                    } else {
                        fps_debug('No terms selected for processing', 'Warning');
                    }
                } catch (Exception $e) {
                    fps_debug('Error processing selected terms: ' . $e->getMessage(), 'Error');
                    // Let the template show the error message
                }
            }
        }
        
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
        try {
            // Check nonce and permissions
            check_ajax_referer('forbes_product_sync_nonce', 'nonce');
            
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
                return;
            }
            
            fps_debug('Starting attribute differences fetch', 'AJAX');
            
            // Get the refresh cache parameter
            $refresh_cache = isset($_REQUEST['refresh_cache']) && $_REQUEST['refresh_cache'] == '1';
            
            // Initialize API classes
            if (!class_exists('Forbes_Product_Sync_API_Attributes')) {
                require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/api/class-forbes-product-sync-api-attributes.php';
            }
            
            if (!class_exists('Forbes_Product_Sync_Attributes_Comparison')) {
                require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-attributes-comparison.php';
            }
            
            // Increase time limit for large attribute sets
            @set_time_limit(120);
            
            $api = new Forbes_Product_Sync_API_Attributes();
            $comparison = new Forbes_Product_Sync_Attributes_Comparison();
            
            // Clear cache if requested
            if ($refresh_cache) {
                fps_debug('Refreshing API cache before comparison', 'AJAX');
                $api->clear_attribute_caches();
            }
            
            // Get all attributes with terms
            $source_data = $api->get_attributes_with_terms(true, true); // use_cache=true, allow_partial=true
            
            if (is_wp_error($source_data)) {
                $error_message = $source_data->get_error_message();
                fps_debug('Error getting source data: ' . $error_message, 'AJAX Error');
                wp_send_json_error(array(
                    'message' => $error_message,
                    'error_code' => $source_data->get_error_code()
                ));
                return;
            }
            
            // Ensure we have valid data structure
            if (!is_array($source_data) || !isset($source_data['attributes']) || !is_array($source_data['attributes'])) {
                fps_debug('Invalid source data structure', 'AJAX Error');
                wp_send_json_error(array(
                    'message' => __('Invalid API response format.', 'forbes-product-sync'),
                    'error_code' => 'invalid_format'
                ));
                return;
            }
            
            fps_debug('Comparing attributes', 'AJAX');
            $comparison_results = $comparison->compare_attributes($source_data);
            
            // Get stats for the comparison
            $stats = $comparison->get_stats($comparison_results);
            
            // Pre-process and simplify the comparison data before sending to the client
            $simplified_comparison = $this->simplify_comparison_data($comparison_results);
            
            // Render the comparison table
            fps_debug('Rendering comparison table', 'AJAX');
            $html = $comparison->render_comparison_table($comparison_results);
            
            fps_debug('Sending response with comparison data', 'AJAX');
            
            wp_send_json_success(array(
                'html' => $html,
                'count' => $stats,
                'comparison' => $simplified_comparison,
                'attributes_count' => count($source_data['attributes']),
                'terms_count' => isset($source_data['terms']) ? $this->count_total_terms($source_data['terms']) : 0
            ));
        } catch (Exception $e) {
            fps_debug('Exception in ajax_get_attribute_differences: ' . $e->getMessage(), 'AJAX Error');
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'error_code' => 'exception'
            ));
        }
    }
    
    /**
     * Count total terms in the terms map
     * 
     * @param array $terms_map Terms map from API
     * @return int Total number of terms
     */
    private function count_total_terms($terms_map) {
        $count = 0;
        if (is_array($terms_map)) {
            foreach ($terms_map as $attr_terms) {
                if (is_array($attr_terms)) {
                    $count += count($attr_terms);
                }
            }
        }
        return $count;
    }
    
    /**
     * Simplify comparison data for client
     * 
     * @param array $comparison_results Comparison results
     * @return array Simplified data
     */
    private function simplify_comparison_data($comparison_results) {
        $simplified = [];
        
        if (!is_array($comparison_results) || !isset($comparison_results['attributes']) || !is_array($comparison_results['attributes'])) {
            return $simplified;
        }
        
        foreach ($comparison_results['attributes'] as $attr_index => $attr_comp) {
            $attr_data = [
                'status' => $attr_comp['status'],
                'terms' => []
            ];
            
            if (isset($attr_comp['source']) && is_array($attr_comp['source'])) {
                $attr_data['id'] = $attr_comp['source']['id'] ?? '';
                $attr_data['name'] = $attr_comp['source']['name'] ?? '';
                $attr_data['slug'] = $attr_comp['source']['slug'] ?? '';
            } elseif (isset($attr_comp['local']) && is_object($attr_comp['local'])) {
                $attr_data['id'] = $attr_comp['local']->attribute_id ?? '';
                $attr_data['name'] = $attr_comp['local']->attribute_label ?? '';
                $attr_data['slug'] = $attr_comp['local']->attribute_name ?? '';
            }
            
            // Include simplified term data
            if (isset($attr_comp['terms']) && is_array($attr_comp['terms'])) {
                foreach ($attr_comp['terms'] as $term_comp) {
                    $term_data = [
                        'status' => $term_comp['status'] ?? 'unknown'
                    ];
                    
                    if (isset($term_comp['source']) && is_array($term_comp['source'])) {
                        $term_data['id'] = $term_comp['source']['id'] ?? '';
                        $term_data['name'] = $term_comp['source']['name'] ?? '';
                        $term_data['slug'] = $term_comp['source']['slug'] ?? '';
                    } elseif (isset($term_comp['local']) && is_object($term_comp['local'])) {
                        $term_data['id'] = $term_comp['local']->term_id ?? '';
                        $term_data['name'] = $term_comp['local']->name ?? '';
                        $term_data['slug'] = $term_comp['local']->slug ?? '';
                    }
                    
                    $attr_data['terms'][] = $term_data;
                }
            }
            
            $simplified[] = $attr_data;
        }
        
        return $simplified;
    }
    
    /**
     * AJAX handler: Process attributes
     */
    public function ajax_process_attributes() {
        // Enable error logging
        ini_set('display_errors', 1);
        error_log('Starting attribute processing AJAX request');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'forbes_product_sync_nonce')) {
                error_log('Nonce verification failed');
                wp_send_json_error(array('message' => __('Security verification failed.', 'forbes-product-sync')));
                return;
            }
            
            // Check permissions
            if (!current_user_can('manage_woocommerce')) {
                error_log('Permission check failed');
                wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
                return;
            }
            
            // Check for terms data
            if (!isset($_POST['terms']) || !is_array($_POST['terms'])) {
                error_log('No terms data provided in POST request');
                wp_send_json_error(array('message' => __('No terms selected.', 'forbes-product-sync')));
                return;
            }
            
            $terms = $_POST['terms'];
            error_log('Processing ' . count($terms) . ' terms');
            
            $sync_metadata = isset($_POST['sync_metadata']) && $_POST['sync_metadata'] ? true : false;
            $handle_conflicts = isset($_POST['handle_conflicts']) && $_POST['handle_conflicts'] ? true : false;
            
            // Load all required classes
            error_log('Loading required classes');
            require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/api/class-forbes-product-sync-api-attributes.php';
            require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/attributes/class-forbes-product-sync-attributes-handler.php';
            require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-attributes-processor.php';
            
            error_log('Creating attribute processor instance');
            $processor = new Forbes_Product_Sync_Attributes_Processor();
            error_log('Attribute processor initialized');
            
            // Process the attributes
            error_log('Starting attribute processor execution');
            $result = $processor->process_attributes($terms, $sync_metadata, $handle_conflicts);
            error_log('Attribute processor execution completed');
            
            // Log the overall process
            $logger = Forbes_Product_Sync_Logger::instance();
            $logger->log_sync(
                'Attribute Processing',
                ($result['errors'] > 0) ? 'warning' : 'success',
                sprintf(
                    __('Processed %d terms with %d created, %d updated, %d skipped, and %d errors.', 'forbes-product-sync'),
                    count($terms),
                    $result['created'],
                    $result['updated'],
                    $result['skipped'],
                    $result['errors']
                )
            );
            
            error_log('Sending success response');
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
            
        } catch (Exception $e) {
            error_log('Exception caught in AJAX request: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            
            $logger = Forbes_Product_Sync_Logger::instance();
            $logger->log_sync(
                'Attribute Processing',
                'error',
                sprintf(
                    __('Error processing attributes: %s', 'forbes-product-sync'),
                    $e->getMessage()
                )
            );
            
            wp_send_json_error(array(
                'message' => sprintf(
                    __('An error occurred while processing attributes: %s', 'forbes-product-sync'),
                    $e->getMessage()
                )
            ));
        }
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

    /**
     * Called when a WooCommerce attribute is added
     */
    public function on_attribute_added($attribute_id, $attribute_data) {
        error_log('WooCommerce attribute added - ID: ' . $attribute_id . ', Data: ' . json_encode($attribute_data));
        
        // Force refresh attribute cache
        delete_transient('wc_attribute_taxonomies');
        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        }
    }
    
    /**
     * AJAX handler: Force refresh attributes
     */
    public function ajax_force_refresh_attributes() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'forbes-product-sync')));
            return;
        }
        
        error_log('Force refreshing WooCommerce attributes');
        
        // Load the attribute handler
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/attributes/class-forbes-product-sync-attributes-handler.php';
        $attributes_handler = new Forbes_Product_Sync_Attributes_Handler();
        
        // Refresh the attribute cache
        $attributes = $attributes_handler->refresh_attribute_cache();
        
        // Reload the page to show the refreshed attributes
        wp_send_json_success(array(
            'message' => __('Attributes refreshed. Reloading page...', 'forbes-product-sync'),
            'count' => count($attributes)
        ));
    }

    /**
     * AJAX handler: Get sync summary for the main dashboard
     */
    public function ajax_get_sync_summary() {
        try {
            // Check nonce and permissions
            check_ajax_referer('forbes_product_sync_nonce', 'nonce');
            
            if (!current_user_can('manage_woocommerce')) {
                fps_debug('Permission denied in ajax_get_sync_summary', 'Error');
                wp_send_json_error(['message' => __('Permission denied.', 'forbes-product-sync')]);
                return;
            }
            
            fps_debug('Starting ajax_get_sync_summary', 'Info');
            
            // Make sure all required classes are loaded
            if (!class_exists('Forbes_Product_Sync_API_Attributes')) {
                require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/api/class-forbes-product-sync-api-attributes.php';
            }
            
            if (!class_exists('Forbes_Product_Sync_Attributes_Comparison')) {
                require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/admin/class-forbes-product-sync-attributes-comparison.php';
            }
            
            // Set longer time limit for large attribute sets
            @set_time_limit(120);
            
            // Get attributes from API
            $api = new Forbes_Product_Sync_API_Attributes();
            $comparison = new Forbes_Product_Sync_Attributes_Comparison();
            
            // Try to get the attribute data
            $source_data = $api->get_attributes_with_terms(true, true); // use_cache=true, partial_data=true
            
            if (is_wp_error($source_data)) {
                $error_message = $source_data->get_error_message();
                fps_debug('Error getting attributes: ' . $error_message, 'AJAX Error');
                
                // Try to get a subset of data for display
                $attributes = $api->get_attributes();
                if (!is_wp_error($attributes)) {
                    $attribute_count = count($attributes);
                    $cache_time = $api->get_cache_timestamp();
                    
                    if ($cache_time) {
                        $cache_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cache_time);
                    } else {
                        $cache_time = __('Never', 'forbes-product-sync');
                    }
                    
                    // Get some basic stats even if we couldn't get terms
                    wp_send_json_error([
                        'message' => $error_message,
                        'error_code' => $source_data->get_error_code(),
                        'partial_data' => true,
                        'attributes_count' => $attribute_count,
                        'last_update' => $cache_time,
                        'stats' => [
                            'total_differences' => 0,
                            'attributes' => ['new' => 0, 'modified' => 0],
                            'terms' => ['new' => 0, 'modified' => 0]
                        ]
                    ]);
                } else {
                    // Could not get any data
                    wp_send_json_error([
                        'message' => $error_message,
                        'error_code' => $source_data->get_error_code()
                    ]);
                }
                return;
            }
            
            // Ensure we have attributes in the response
            if (!isset($source_data['attributes']) || !is_array($source_data['attributes'])) {
                fps_debug('Invalid API response format in ajax_get_sync_summary', 'Error');
                wp_send_json_error([
                    'message' => __('Invalid API response format.', 'forbes-product-sync'),
                    'error_code' => 'invalid_format'
                ]);
                return;
            }
            
            // Get total counts
            $attributes_count = count($source_data['attributes']);
            $terms_count = 0;
            
            if (isset($source_data['terms']) && is_array($source_data['terms'])) {
                foreach ($source_data['terms'] as $attr_terms) {
                    if (is_array($attr_terms)) {
                        $terms_count += count($attr_terms);
                    }
                }
            }
            
            // Get comparison results
            $comparison_results = $comparison->compare_attributes($source_data);
            $stats = $comparison->get_stats($comparison_results);
            
            // Get cache timestamp
            $cache_time = $api->get_cache_timestamp();
            if ($cache_time) {
                $cache_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cache_time);
            } else {
                $cache_time = __('Never', 'forbes-product-sync');
            }
            
            // Get attribute status for detailed view
            $attributes_status = $this->get_attributes_status($source_data['attributes']);
            
            // Send response
            wp_send_json_success([
                'total_attributes' => $attributes_count,
                'total_terms' => $terms_count,
                'differences' => $stats,
                'stats' => $stats,
                'last_update' => $cache_time,
                'attributes_status' => $attributes_status,
                'cache_time' => $cache_time
            ]);
            
        } catch (Exception $e) {
            fps_debug('Exception in ajax_get_sync_summary: ' . $e->getMessage(), 'Error');
            wp_send_json_error([
                'message' => $e->getMessage(),
                'error_code' => 'exception'
            ]);
        }
    }
    
    /**
     * Get attributes status for summary display
     *
     * @param array $attributes Attributes from API
     * @return array Attributes status data
     */
    private function get_attributes_status($attributes) {
        if (!is_array($attributes)) {
            return [];
        }
        
        $status_data = [];
        $api_attributes = new Forbes_Product_Sync_API_Attributes();
        $local_attributes = wc_get_attribute_taxonomies();
        
        // Create a map of local attributes by name for faster lookup
        $local_map = [];
        foreach ($local_attributes as $attr) {
            if (isset($attr->attribute_name)) {
                $local_map[wc_sanitize_taxonomy_name($attr->attribute_name)] = $attr;
            }
        }
        
        // Process each attribute
        foreach ($attributes as $attr) {
            // Ensure we're working with array data
            if (!is_array($attr)) {
                if (is_object($attr)) {
                    $attr = json_decode(json_encode($attr), true);
                } else {
                    continue;
                }
            }
            
            // Skip if no name
            if (!isset($attr['name'])) {
                continue;
            }
            
            $attr_slug = wc_sanitize_taxonomy_name($attr['name']);
            $attr_data = [
                'name' => $attr['name'],
                'slug' => $attr_slug,
                'id' => isset($attr['id']) ? $attr['id'] : 0,
                'terms_count' => 0,
                'terms_new' => 0,
                'terms_modified' => 0,
                'terms_ok' => 0,
                'status' => 'ok' // Default status
            ];
            
            // Check if attribute exists locally
            if (!isset($local_map[$attr_slug])) {
                $attr_data['status'] = 'new';
            }
            
            // Try to get terms count
            try {
                $terms = $api_attributes->get_attribute_terms($attr['id']);
                if (!is_wp_error($terms) && is_array($terms)) {
                    $attr_data['terms_count'] = count($terms);
                    
                    // Check term status
                    $taxonomy = wc_attribute_taxonomy_name($attr_slug);
                    if (taxonomy_exists($taxonomy)) {
                        $local_terms = get_terms([
                            'taxonomy' => $taxonomy,
                            'hide_empty' => false
                        ]);
                        
                        if (!is_wp_error($local_terms)) {
                            // Create a map of local terms by slug
                            $local_terms_map = [];
                            foreach ($local_terms as $local_term) {
                                $local_terms_map[$local_term->slug] = $local_term;
                            }
                            
                            // Check each term
                            foreach ($terms as $term) {
                                if (!is_array($term)) {
                                    if (is_object($term)) {
                                        $term = json_decode(json_encode($term), true);
                                    } else {
                                        continue;
                                    }
                                }
                                
                                $term_slug = isset($term['slug']) ? sanitize_title($term['slug']) : '';
                                
                                if (empty($term_slug)) {
                                    continue;
                                }
                                
                                if (!isset($local_terms_map[$term_slug])) {
                                    $attr_data['terms_new']++;
                                } else {
                                    // Check if term needs update
                                    $local_term = $local_terms_map[$term_slug];
                                    if (isset($term['name']) && $term['name'] !== $local_term['name']) {
                                        $attr_data['terms_modified']++;
                                    } else {
                                        $attr_data['terms_ok']++;
                                    }
                                }
                            }
                        }
                    } else {
                        // All terms would be new since taxonomy doesn't exist
                        $attr_data['terms_new'] = $attr_data['terms_count'];
                    }
                }
            } catch (Exception $e) {
                fps_debug('Error checking terms for attribute ' . $attr['name'] . ': ' . $e->getMessage(), 'Error');
            }
            
            // Set attribute status based on term status
            if ($attr_data['status'] === 'ok' && ($attr_data['terms_new'] > 0 || $attr_data['terms_modified'] > 0)) {
                $attr_data['status'] = 'updated';
            }
            
            $status_data[] = $attr_data;
        }
        
        // Sort by name
        usort($status_data, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $status_data;
    }

    /**
     * Register scripts and styles
     */
    public function register_assets() {
        // Do not register here, move to the enqueue_scripts method
    }
    
    /**
     * Register AJAX handlers
     */
    public function register_ajax_handlers() {
        // Test API connection
        add_action('wp_ajax_forbes_product_sync_test_connection', [$this, 'ajax_test_connection']);
        
        // Get attribute differences
        add_action('wp_ajax_forbes_product_sync_get_attribute_differences', [$this, 'ajax_get_attribute_differences']);
        
        // Process selected attributes
        add_action('wp_ajax_forbes_product_sync_process_attributes', [$this, 'ajax_process_attributes']);
        
        // Process batch of terms
        add_action('wp_ajax_forbes_product_sync_process_batch', [$this, 'ajax_process_batch']);
        
        // Force refresh attributes
        add_action('wp_ajax_forbes_product_sync_force_refresh_attributes', [$this, 'ajax_force_refresh_attributes']);
        
        // Get sync summary
        add_action('wp_ajax_forbes_product_sync_get_sync_summary', [$this, 'ajax_get_sync_summary']);
        
        // Handle DB repair
        add_action('wp_ajax_forbes_product_sync_repair_db', [$this, 'handle_db_repair']);
    }
    
    /**
     * Handle database repair AJAX request
     */
    public function handle_db_repair() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'forbes_product_sync_repair_db')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Get the plugin instance
        $plugin = Forbes_Product_Sync_Plugin::instance();
        
        // Create tables
        $plugin->create_tables();
        
        // Check if the tables were created
        global $wpdb;
        $table_name = FORBES_PRODUCT_SYNC_LOG_TABLE;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if ($table_exists) {
            wp_send_json_success('Database tables repaired successfully');
        } else {
            wp_send_json_error('Failed to create database tables');
        }
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        // Only show on our plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'forbes-product-sync') === false) {
            return;
        }
        
        // Check for transient notices
        $notices = get_transient('forbes_product_sync_admin_notices');
        if (!empty($notices) && is_array($notices)) {
            foreach ($notices as $notice) {
                if (isset($notice['type']) && isset($notice['message'])) {
                    $class = 'notice notice-' . esc_attr($notice['type']);
                    $message = wp_kses_post($notice['message']);
                    
                    printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
                }
            }
            
            // Clear the notices after displaying
            delete_transient('forbes_product_sync_admin_notices');
        }
    }

    /**
     * Add admin notice
     *
     * @param string $message The notice message
     * @param string $type The notice type (success, error, warning, info)
     * @param bool $is_dismissible Whether the notice is dismissible
     */
    public function add_admin_notice($message, $type = 'info', $is_dismissible = false) {
        $notices = get_transient('forbes_product_sync_admin_notices') ?: [];
        
        $notices[] = [
            'message' => $message,
            'type' => $type,
            'dismissible' => $is_dismissible
        ];
        
        set_transient('forbes_product_sync_admin_notices', $notices, 60 * 5); // Store for 5 minutes
    }
} 