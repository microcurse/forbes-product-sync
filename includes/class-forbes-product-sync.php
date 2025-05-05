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
     * Sync status instance
     *
     * @var Forbes_Product_Sync_Status
     */
    private $sync_status;

    /**
     * Remote product instance
     *
     * @var Forbes_Product_Sync_Remote
     */
    private $remote;

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = FORBES_PRODUCT_SYNC_VERSION;
        $this->settings = get_option('forbes_product_sync_settings', array(
            'api_url' => '',
            'api_username' => '',
            'api_password' => '',
            'sync_tag' => 'sync-this'
        ));
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
        register_setting('forbes_product_sync_settings', 'forbes_product_sync_settings');

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
            'api_username',
            __('API Username', 'forbes-product-sync'),
            array($this, 'render_api_username_field'),
            'forbes-product-sync',
            'forbes_product_sync_api_settings'
        );

        add_settings_field(
            'api_password',
            __('API Password', 'forbes-product-sync'),
            array($this, 'render_api_password_field'),
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
        echo '<p>' . esc_html__('Configure your API settings below.', 'forbes-product-sync') . '</p>';
    }

    /**
     * Render API URL field
     */
    public function render_api_url_field() {
        ?>
        <input type="url" name="forbes_product_sync_settings[api_url]" value="<?php echo esc_attr($this->settings['api_url']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Enter the GraphQL API URL of your live site.', 'forbes-product-sync'); ?></p>
        <?php
    }

    /**
     * Render API username field
     */
    public function render_api_username_field() {
        ?>
        <input type="text" name="forbes_product_sync_settings[api_username]" value="<?php echo esc_attr($this->settings['api_username']); ?>" class="regular-text">
        <?php
    }

    /**
     * Render API password field
     */
    public function render_api_password_field() {
        ?>
        <input type="password" name="forbes_product_sync_settings[api_password]" value="<?php echo esc_attr($this->settings['api_password']); ?>" class="regular-text">
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
     * Add product sync field
     */
    public function add_product_sync_field() {
        global $post;
        $sync_enabled = get_post_meta($post->ID, $this->settings['sync_meta_key'], true);
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="forbes_sync_enabled"><?php esc_html_e('Enable Sync', 'forbes-product-sync'); ?></label>
                <input type="checkbox" id="forbes_sync_enabled" name="forbes_sync_enabled" <?php checked($sync_enabled, 'yes'); ?>>
                <span class="description"><?php esc_html_e('Enable this product to be synced to other sites.', 'forbes-product-sync'); ?></span>
            </p>
        </div>
        <?php
    }

    /**
     * Save product sync field
     */
    public function save_product_sync_field($post_id) {
        $sync_enabled = isset($_POST['forbes_sync_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, $this->settings['sync_meta_key'], $sync_enabled);
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Add any initialization code here
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
            array($this, 'render_admin_page'),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Sync Status', 'forbes-product-sync'),
            __('Sync Status', 'forbes-product-sync'),
            'manage_options',
            'forbes-product-sync-status',
            array($this, 'render_sync_status_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_forbes-product-sync' !== $hook) {
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
                'nonce' => wp_create_nonce('forbes_product_sync_nonce'),
            )
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'forbes-product-sync'));
        }

        if (isset($_POST['run_sync']) && check_admin_referer('forbes_product_sync_action', 'forbes_product_sync_nonce')) {
            $this->run_product_sync();
        }

        include FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Render sync status page
     */
    public function render_sync_status_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'forbes-product-sync'));
        }

        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/class-forbes-product-sync-status.php';
        $this->sync_status = new Forbes_Product_Sync_Status($this->settings);
        
        include FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'templates/sync-status.php';
    }

    /**
     * Run product sync
     */
    private function run_product_sync() {
        error_log('Starting product sync...');
        try {
            $query = $this->get_graphql_query();
            error_log('GraphQL Query: ' . $query);
            $response = $this->make_api_request($query);
            error_log('API Response: ' . print_r($response, true));
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            
            if (empty($data['data']['products']['nodes'])) {
                throw new Exception(__('No products found to sync.', 'forbes-product-sync'));
            }

            $this->process_products($data['data']['products']['nodes']);
            
            add_settings_error(
                'forbes_product_sync_messages',
                'forbes_product_sync_message',
                __('Product sync completed successfully.', 'forbes-product-sync'),
                'success'
            );
        } catch (Exception $e) {
            error_log('Sync Exception: ' . $e->getMessage());
            add_settings_error(
                'forbes_product_sync_messages',
                'forbes_product_sync_message',
                sprintf(__('Error during sync: %s', 'forbes-product-sync'), $e->getMessage()),
                'error'
            );
        }
    }

    /**
     * Get GraphQL query
     */
    private function get_graphql_query() {
        return <<<GRAPHQL
            query GetProductsFromLive {
                products(where: { tag: "{$this->settings['sync_tag']}" }, first: 10) {
                    nodes {
                        name
                        slug
                        sku
                        description
                        shortDescription
                        productCategories { nodes { slug } }
                        productTags { nodes { slug } }
                        image { sourceUrl }
                        galleryImages { nodes { sourceUrl } }
                        attributes { nodes { name options } }
                        ... on SimpleProduct {
                            price
                            regularPrice
                            salePrice
                            stockStatus
                        }
                        ... on VariableProduct {
                            price
                            regularPrice
                            salePrice
                            stockStatus
                            variations(first: 50) {
                                nodes {
                                    sku
                                    price
                                    regularPrice
                                    salePrice
                                    stockStatus
                                    attributes {
                                        nodes {
                                            name
                                            value
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        GRAPHQL;
    }

    /**
     * Make API request
     */
    private function make_api_request($query) {
        if (empty($this->settings['api_url']) || empty($this->settings['api_username']) || empty($this->settings['api_password'])) {
            throw new Exception(__('API credentials are not configured.', 'forbes-product-sync'));
        }

        return wp_remote_post($this->settings['api_url'], array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->settings['api_username'] . ':' . $this->settings['api_password']),
            ),
            'body' => json_encode(array('query' => $query)),
            'timeout' => 30,
        ));
    }

    /**
     * Process products
     */
    private function process_products($products) {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $existing_id = wc_get_product_id_by_sku($product['sku']);
            
            if ($existing_id) {
                // Update existing product
                $this->update_product($existing_id, $product);
                $updated++;
            } else {
                // Create new product
                $post_id = $this->create_product($product);
                if (!is_wp_error($post_id)) {
                    $this->update_product_meta($post_id, $product);
                    $this->set_product_terms($post_id, $product);
                    $this->handle_product_images($post_id, $product);
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        // Add success message with statistics
        add_settings_error(
            'forbes_product_sync_messages',
            'forbes_product_sync_message',
            sprintf(
                __('Sync completed: %d new products created, %d products updated, %d products skipped.', 'forbes-product-sync'),
                $created,
                $updated,
                $skipped
            ),
            'success'
        );
    }

    /**
     * Get remote product
     *
     * @param string $sku Product SKU.
     * @return array|false
     */
    private function get_remote_product($sku) {
        if (!$this->remote) {
            require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/class-forbes-product-sync-remote.php';
            $this->remote = new Forbes_Product_Sync_Remote($this->settings);
        }
        return $this->remote->get_product($sku);
    }

    /**
     * Create product
     *
     * @param array $product Product data.
     * @return int|WP_Error
     */
    private function create_product($product) {
        $post_data = array(
            'post_title' => $product['name'],
            'post_content' => $product['description'],
            'post_status' => 'publish',
            'post_type' => 'product'
        );

        $post_id = wp_insert_post($post_data);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $wc_product = wc_get_product($post_id);
        $wc_product->set_sku($product['sku']);
        $wc_product->set_price($product['price']);
        $wc_product->set_regular_price($product['regularPrice']);
        $wc_product->set_sale_price($product['salePrice']);
        $wc_product->set_stock_status($product['stockStatus']);
        $wc_product->set_stock_quantity($product['stockQuantity']);
        $wc_product->set_weight($product['weight']);
        $wc_product->set_length($product['length']);
        $wc_product->set_width($product['width']);
        $wc_product->set_height($product['height']);
        $wc_product->save();

        return $post_id;
    }

    /**
     * Update product
     *
     * @param int   $product_id Product ID.
     * @param array $product Product data.
     */
    private function update_product($product_id, $product) {
        $post_data = array(
            'ID' => $product_id,
            'post_title' => $product['name'],
            'post_content' => $product['description']
        );

        wp_update_post($post_data);

        $wc_product = wc_get_product($product_id);
        $wc_product->set_price($product['price']);
        $wc_product->set_regular_price($product['regularPrice']);
        $wc_product->set_sale_price($product['salePrice']);
        $wc_product->set_stock_status($product['stockStatus']);
        $wc_product->set_stock_quantity($product['stockQuantity']);
        $wc_product->set_weight($product['weight']);
        $wc_product->set_length($product['length']);
        $wc_product->set_width($product['width']);
        $wc_product->set_height($product['height']);
        $wc_product->save();
    }

    /**
     * Update product meta
     *
     * @param int   $product_id Product ID.
     * @param array $product Product data.
     */
    private function update_product_meta($product_id, $product) {
        update_post_meta($product_id, '_forbes_sync_status', 'synced');
        update_post_meta($product_id, '_forbes_sync_last_updated', current_time('mysql'));
    }

    /**
     * Set product terms
     *
     * @param int   $product_id Product ID.
     * @param array $product Product data.
     */
    private function set_product_terms($product_id, $product) {
        // Set categories
        $category_ids = array();
        foreach ($product['categories'] as $category) {
            $term = get_term_by('slug', $category['slug'], 'product_cat');
            if (!$term) {
                $term = wp_insert_term($category['name'], 'product_cat', array('slug' => $category['slug']));
                if (!is_wp_error($term)) {
                    $category_ids[] = $term['term_id'];
                }
            } else {
                $category_ids[] = $term->term_id;
            }
        }
        wp_set_object_terms($product_id, $category_ids, 'product_cat');

        // Set tags
        $tag_ids = array();
        foreach ($product['tags'] as $tag) {
            $term = get_term_by('slug', $tag['slug'], 'product_tag');
            if (!$term) {
                $term = wp_insert_term($tag['name'], 'product_tag', array('slug' => $tag['slug']));
                if (!is_wp_error($term)) {
                    $tag_ids[] = $term['term_id'];
                }
            } else {
                $tag_ids[] = $term->term_id;
            }
        }
        wp_set_object_terms($product_id, $tag_ids, 'product_tag');

        // Set attributes
        $attributes = array();
        foreach ($product['attributes'] as $attribute) {
            $taxonomy = 'pa_' . sanitize_title($attribute['name']);
            $attribute_ids = array();
            
            foreach ($attribute['options'] as $option) {
                $term = get_term_by('name', $option, $taxonomy);
                if (!$term) {
                    $term = wp_insert_term($option, $taxonomy);
                    if (!is_wp_error($term)) {
                        $attribute_ids[] = $term['term_id'];
                    }
                } else {
                    $attribute_ids[] = $term->term_id;
                }
            }
            
            if (!empty($attribute_ids)) {
                wp_set_object_terms($product_id, $attribute_ids, $taxonomy);
                $attributes[] = array(
                    'name' => $taxonomy,
                    'value' => $attribute['options'],
                    'is_visible' => true,
                    'is_variation' => false
                );
            }
        }
        
        update_post_meta($product_id, '_product_attributes', $attributes);
    }

    /**
     * Handle product images
     *
     * @param int   $product_id Product ID.
     * @param array $product Product data.
     */
    private function handle_product_images($product_id, $product) {
        $image_ids = array();
        foreach ($product['images'] as $image) {
            $image_id = $this->upload_image($image['sourceUrl'], $image['altText']);
            if ($image_id) {
                $image_ids[] = $image_id;
            }
        }

        if (!empty($image_ids)) {
            set_post_thumbnail($product_id, $image_ids[0]);
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($image_ids, 1)));
        }
    }

    /**
     * Upload image from URL
     *
     * @param string $url Image URL.
     * @param string $alt Alt text.
     * @return int|false
     */
    private function upload_image($url, $alt) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );

        $id = media_handle_sideload($file_array, 0);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }

        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        return $id;
    }

    /**
     * Add bulk actions
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['enable_sync'] = __('Enable Sync', 'forbes-product-sync');
        $bulk_actions['disable_sync'] = __('Disable Sync', 'forbes-product-sync');
        return $bulk_actions;
    }

    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'enable_sync' && $doaction !== 'disable_sync') {
            return $redirect_to;
        }

        foreach ($post_ids as $post_id) {
            $terms = wp_get_object_terms($post_id, 'product_tag', array('fields' => 'names'));
            if ($doaction === 'enable_sync') {
                if (!in_array($this->settings['sync_tag'], $terms)) {
                    $terms[] = $this->settings['sync_tag'];
                }
            } else {
                $terms = array_diff($terms, array($this->settings['sync_tag']));
            }
            wp_set_object_terms($post_id, $terms, 'product_tag');
        }

        $redirect_to = add_query_arg(
            array(
                'bulk_sync_updated' => count($post_ids),
                'sync_action' => $doaction
            ),
            $redirect_to
        );

        return $redirect_to;
    }

    /**
     * Show bulk action notices
     */
    public function bulk_action_admin_notice() {
        if (!empty($_REQUEST['bulk_sync_updated'])) {
            $count = intval($_REQUEST['bulk_sync_updated']);
            $action = sanitize_text_field($_REQUEST['sync_action']);
            $message = sprintf(
                _n(
                    '%s product has been %s for syncing.',
                    '%s products have been %s for syncing.',
                    $count,
                    'forbes-product-sync'
                ),
                $count,
                $action === 'enable_sync' ? 'enabled' : 'disabled'
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Add sync column to products list
     */
    public function add_sync_column($columns) {
        $columns['forbes_sync'] = __('Sync Status', 'forbes-product-sync');
        return $columns;
    }

    /**
     * Render sync column
     */
    public function render_sync_column($column, $post_id) {
        if ($column === 'forbes_sync') {
            $terms = wp_get_object_terms($post_id, 'product_tag', array('fields' => 'names'));
            $is_enabled = in_array($this->settings['sync_tag'], $terms);
            echo '<span class="dashicons ' . ($is_enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt') . '"></span>';
        }
    }

    /**
     * Make sync column sortable
     */
    public function make_sync_column_sortable($columns) {
        $columns['forbes_sync'] = 'forbes_sync';
        return $columns;
    }

    /**
     * Handle create product AJAX
     */
    public function handle_create_product() {
        check_ajax_referer('forbes_product_sync_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions.', 'forbes-product-sync'));
        }

        $sku = sanitize_text_field($_POST['sku']);
        if (empty($sku)) {
            wp_send_json_error(__('Invalid SKU.', 'forbes-product-sync'));
        }

        // Get product data from remote
        $product = $this->get_remote_product($sku);
        if (!$product) {
            wp_send_json_error(__('Product not found on remote site.', 'forbes-product-sync'));
        }

        // Create product
        $post_id = $this->create_product($product);
        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id->get_error_message());
        }

        $this->update_product_meta($post_id, $product);
        $this->set_product_terms($post_id, $product);
        $this->handle_product_images($post_id, $product);

        wp_send_json_success(__('Product created successfully.', 'forbes-product-sync'));
    }

    /**
     * Handle update product AJAX
     */
    public function handle_update_product() {
        check_ajax_referer('forbes_product_sync_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions.', 'forbes-product-sync'));
        }

        $sku = sanitize_text_field($_POST['sku']);
        if (empty($sku)) {
            wp_send_json_error(__('Invalid SKU.', 'forbes-product-sync'));
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            wp_send_json_error(__('Product not found.', 'forbes-product-sync'));
        }

        // Get product data from remote
        $product = $this->get_remote_product($sku);
        if (!$product) {
            wp_send_json_error(__('Product not found on remote site.', 'forbes-product-sync'));
        }

        // Update product
        $this->update_product($product_id, $product);

        wp_send_json_success(__('Product updated successfully.', 'forbes-product-sync'));
    }
} 