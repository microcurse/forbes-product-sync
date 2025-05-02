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
     * Constructor
     */
    public function __construct() {
        $this->version = FORBES_PRODUCT_SYNC_VERSION;
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
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
     * Run product sync
     */
    private function run_product_sync() {
        try {
            $query = $this->get_graphql_query();
            $response = $this->make_api_request($query);
            
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
                products(where: { tag: "live-only" }, first: 10) {
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
        $api_url = defined('FORBES_PRODUCT_SYNC_API_URL') ? FORBES_PRODUCT_SYNC_API_URL : 'https://LIVE-SITE-URL/graphql';
        $api_credentials = defined('FORBES_PRODUCT_SYNC_API_CREDENTIALS') ? FORBES_PRODUCT_SYNC_API_CREDENTIALS : 'USERNAME:PASSWORD';

        return wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($api_credentials),
            ),
            'body' => json_encode(array('query' => $query)),
            'timeout' => 30,
        ));
    }

    /**
     * Process products
     */
    private function process_products($products) {
        foreach ($products as $product) {
            if (wc_get_product_id_by_sku($product['sku'])) {
                continue;
            }

            $post_id = $this->create_product($product);
            if (is_wp_error($post_id)) {
                continue;
            }

            $this->update_product_meta($post_id, $product);
            $this->set_product_terms($post_id, $product);
            $this->handle_product_images($post_id, $product);
        }
    }

    /**
     * Create product
     */
    private function create_product($product) {
        return wp_insert_post(array(
            'post_title' => sanitize_text_field($product['name']),
            'post_name' => sanitize_title($product['slug']),
            'post_content' => wp_kses_post($product['description']),
            'post_excerpt' => wp_kses_post($product['shortDescription']),
            'post_status' => 'publish',
            'post_type' => 'product',
        ));
    }

    /**
     * Update product meta
     */
    private function update_product_meta($post_id, $product) {
        update_post_meta($post_id, '_sku', sanitize_text_field($product['sku']));
        update_post_meta($post_id, '_regular_price', sanitize_text_field($product['regularPrice']));
        update_post_meta($post_id, '_price', sanitize_text_field($product['price']));
        update_post_meta($post_id, '_sale_price', sanitize_text_field($product['salePrice']));
        update_post_meta($post_id, '_stock_status', sanitize_text_field($product['stockStatus']));
    }

    /**
     * Set product terms
     */
    private function set_product_terms($post_id, $product) {
        wp_set_object_terms(
            $post_id,
            wp_list_pluck($product['productCategories']['nodes'], 'slug'),
            'product_cat'
        );
        wp_set_object_terms(
            $post_id,
            wp_list_pluck($product['productTags']['nodes'], 'slug'),
            'product_tag'
        );
    }

    /**
     * Handle product images
     */
    private function handle_product_images($post_id, $product) {
        if (!empty($product['image']['sourceUrl'])) {
            $image_id = media_sideload_image($product['image']['sourceUrl'], $post_id, null, 'id');
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id);
            }
        }

        // TODO: Handle gallery images
    }
} 