<?php
/**
 * API Handler class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forbes_Product_Sync_API {
    /**
     * API settings
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('forbes_product_sync_settings', array(
            'api_url' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'sync_tag' => 'live-only'
        ));
    }

    /**
     * Get products from the live site
     *
     * @param array $args Query arguments
     * @return array|WP_Error
     */
    public function get_products($args = array()) {
        $defaults = array(
            'per_page' => 100,
            'page' => 1,
            'tag_slug' => $this->settings['sync_tag']
        );

        $args = wp_parse_args($args, $defaults);
        $endpoint = 'products';
        
        // Debug log the sync tag and arguments
        error_log('Forbes Product Sync - Sync Tag: ' . $this->settings['sync_tag']);
        error_log('Forbes Product Sync - Query Args: ' . print_r($args, true));
        
        $response = $this->make_request('GET', $endpoint, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Filter products to ensure they have the sync tag
        if (is_array($response)) {
            $filtered_products = array();
            foreach ($response as $product) {
                if (isset($product['tags']) && is_array($product['tags'])) {
                    foreach ($product['tags'] as $tag) {
                        if ($tag['slug'] === $this->settings['sync_tag']) {
                            $filtered_products[] = $product;
                            break;
                        }
                    }
                }
            }
            return $filtered_products;
        }
        
        return $response;
    }

    /**
     * Get a single product by SKU
     *
     * @param string $sku Product SKU
     * @return array|WP_Error
     */
    public function get_product_by_sku($sku) {
        $args = array(
            'sku' => $sku
        );
        
        return $this->make_request('GET', 'products', $args);
    }

    /**
     * Make a request to the WooCommerce REST API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $args Request arguments
     * @return array|WP_Error
     */
    private function make_request($method, $endpoint, $args = array()) {
        if (empty($this->settings['api_url'])) {
            return new WP_Error('missing_api_url', __('API URL is not configured.', 'forbes-product-sync'));
        }

        if (empty($this->settings['consumer_key']) || empty($this->settings['consumer_secret'])) {
            return new WP_Error('missing_credentials', __('API credentials are not configured.', 'forbes-product-sync'));
        }

        // Ensure the API URL is properly formatted
        $api_url = untrailingslashit($this->settings['api_url']);
        if (strpos($api_url, 'wp-json/wc/v3') === false) {
            $api_url = trailingslashit($api_url) . 'wp-json/wc/v3';
        }
        
        $url = trailingslashit($api_url) . $endpoint;
        
        $auth = base64_encode($this->settings['consumer_key'] . ':' . $this->settings['consumer_secret']);
        
        $request_args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30,
            'sslverify' => false // Only if you're having SSL issues
        );

        if ($method === 'GET') {
            $url = add_query_arg($args, $url);
        } else {
            $request_args['body'] = json_encode($args);
        }

        // Debug log the request URL
        error_log('Forbes Product Sync - API Request URL: ' . $url);

        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            error_log('Forbes Product Sync - API Error: ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Debug log the response
        error_log('Forbes Product Sync - API Response Code: ' . $response_code);
        error_log('Forbes Product Sync - API Response Body: ' . $response_body);

        if ($response_code !== 200) {
            $error_message = sprintf(
                __('API request failed with status code %d: %s', 'forbes-product-sync'),
                $response_code,
                wp_remote_retrieve_response_message($response)
            );
            
            // Try to get more detailed error message from response body
            $response_data = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($response_data['message'])) {
                $error_message .= ' - ' . $response_data['message'];
            }
            
            return new WP_Error('api_error', $error_message);
        }

        if (empty($response_body)) {
            return new WP_Error('empty_response', __('Empty response from API.', 'forbes-product-sync'));
        }

        $data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'json_error',
                sprintf(
                    __('Failed to parse JSON response: %s', 'forbes-product-sync'),
                    json_last_error_msg()
                )
            );
        }

        return $data;
    }

    /**
     * Get product categories
     *
     * @return array|WP_Error
     */
    public function get_categories() {
        return $this->make_request('GET', 'products/categories', array('per_page' => 100));
    }

    /**
     * Get product attributes
     *
     * @return array|WP_Error
     */
    public function get_attributes() {
        return $this->make_request('GET', 'products/attributes', array('per_page' => 100));
    }

    /**
     * Get attribute terms
     *
     * @param int $attribute_id Attribute ID
     * @return array|WP_Error
     */
    public function get_attribute_terms($attribute_id) {
        return $this->make_request('GET', "products/attributes/{$attribute_id}/terms", array('per_page' => 100));
    }

    /**
     * Get the current sync tag
     *
     * @return string
     */
    public function get_sync_tag() {
        return $this->settings['sync_tag'];
    }

    /**
     * Get the API URL
     *
     * @return string
     */
    public function get_api_url() {
        return $this->settings['api_url'];
    }

    /**
     * Get product by SKU
     *
     * @param string $sku
     * @return WC_Product|false
     */
    public function wc_get_product_by_sku($sku) {
        global $wpdb;
        
        $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value=%s LIMIT 1", $sku));
        
        if ($product_id) {
            return wc_get_product($product_id);
        }
        
        return false;
    }
} 