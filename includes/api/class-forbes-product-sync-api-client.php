<?php
/**
 * API Client base class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_API_Client
 * Base class for API communication
 */
class Forbes_Product_Sync_API_Client {
    /**
     * API settings
     *
     * @var array
     */
    protected $settings;
    
    /**
     * API base URL
     *
     * @var string
     */
    protected $api_base_url;
    
    /**
     * Cache group name for WordPress transients
     *
     * @var string
     */
    protected $cache_group = 'forbes_product_sync';
    
    /**
     * Cache expiration time (in seconds)
     *
     * @var int
     */
    protected $cache_expiration = 3600; // 1 hour
    
    /**
     * Logger instance
     * 
     * @var Forbes_Product_Sync_Logger
     */
    protected $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('forbes_product_sync_settings', array(
            'api_url' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'sync_tag' => 'sync-this'
        ));
        
        $this->api_base_url = $this->prepare_api_url($this->settings['api_url']);
        $this->logger = Forbes_Product_Sync_Logger::instance();
    }
    
    /**
     * Make a request to the WooCommerce REST API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $args Request arguments
     * @return array|WP_Error
     */
    protected function make_request($method, $endpoint, $args = array()) {
        if (empty($this->settings['api_url'])) {
            return new WP_Error('missing_api_url', __('API URL is not configured.', 'forbes-product-sync'));
        }

        if (empty($this->settings['consumer_key']) || empty($this->settings['consumer_secret'])) {
            return new WP_Error('missing_credentials', __('API credentials are not configured.', 'forbes-product-sync'));
        }

        $url = $this->api_base_url . $endpoint;
        
        $auth = base64_encode($this->settings['consumer_key'] . ':' . $this->settings['consumer_secret']);
        
        $request_args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30,
            'sslverify' => apply_filters('forbes_product_sync_sslverify', true)
        );

        if ($method === 'GET') {
            $url = add_query_arg($args, $url);
        } else {
            $request_args['body'] = wp_json_encode($args);
        }

        // Log the API request details if debug mode is enabled
        if (apply_filters('forbes_product_sync_debug_mode', false)) {
            $this->log_debug_info('API Request', array(
                'method' => $method,
                'url' => $url,
                'args' => $args
            ));
        }

        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            $this->log_debug_info('API Error', array(
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the response details if debug mode is enabled
        if (apply_filters('forbes_product_sync_debug_mode', false)) {
            $this->log_debug_info('API Response', array(
                'code' => $response_code,
                'body' => $response_body
            ));
        }

        if ($response_code < 200 || $response_code >= 300) {
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
            
            return new WP_Error('api_error', $error_message, array(
                'status' => $response_code,
                'body' => $response_body
            ));
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
     * Log debug information
     *
     * @param string $title Log title
     * @param array $data Data to log
     */
    protected function log_debug_info($title, $data) {
        error_log(sprintf(
            'Forbes Product Sync - %s: %s',
            $title,
            wp_json_encode($data, JSON_PRETTY_PRINT)
        ));
    }
    
    /**
     * Prepare API URL
     * Ensures the URL is properly formatted for WC REST API calls
     *
     * @param string $url Raw API URL
     * @return string Formatted API URL
     */
    protected function prepare_api_url($url) {
        if (empty($url)) {
            return '';
        }
        
        // Ensure the API URL is properly formatted
        $api_url = untrailingslashit($url);
        if (strpos($api_url, 'wp-json/wc/v3') === false) {
            $api_url = trailingslashit($api_url) . 'wp-json/wc/v3/';
        } else if (substr($api_url, -1) !== '/') {
            $api_url .= '/';
        }
        
        return $api_url;
    }
    
    /**
     * Get a value from the cache
     *
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    protected function get_cache($key) {
        return get_transient($this->cache_group . '_' . $key);
    }
    
    /**
     * Set a value in the cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $expiration Expiration time (in seconds)
     * @return bool True if the value was set, false otherwise
     */
    protected function set_cache($key, $value, $expiration = null) {
        if ($expiration === null) {
            $expiration = $this->cache_expiration;
        }
        
        return set_transient($this->cache_group . '_' . $key, $value, $expiration);
    }
    
    /**
     * Delete a value from the cache
     *
     * @param string $key Cache key
     * @return bool True if the value was deleted, false otherwise
     */
    protected function delete_cache($key) {
        return delete_transient($this->cache_group . '_' . $key);
    }
    
    /**
     * Test API connection
     *
     * @return true|WP_Error True if connection successful, WP_Error on failure
     */
    public function test_connection() {
        $response = $this->make_request('GET', 'system_status');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return true;
    }
    
    /**
     * Get the sync tag setting
     *
     * @return string
     */
    public function get_sync_tag() {
        return $this->settings['sync_tag'];
    }
    
    /**
     * Get the API URL setting
     *
     * @return string
     */
    public function get_api_url() {
        return $this->settings['api_url'];
    }
    
    /**
     * Get timestamp of last cache update
     *
     * @return string|false Timestamp or false if not set
     */
    public function get_cache_timestamp() {
        return get_option($this->cache_group . '_cache_timestamp', false);
    }
    
    /**
     * Update cache timestamp
     *
     * @return bool True if option value has changed, false otherwise
     */
    protected function update_cache_timestamp() {
        return update_option($this->cache_group . '_cache_timestamp', current_time('mysql'), false);
    }
    
    /**
     * Clear all caches for this group
     */
    public function clear_all_caches() {
        global $wpdb;
        
        // Get all transients for this group
        $like = $wpdb->esc_like('_transient_' . $this->cache_group . '_') . '%';
        $transients = $wpdb->get_col(
            $wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $like)
        );
        
        // Delete each transient
        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient);
            delete_transient($key);
        }
        
        // Update cache timestamp
        $this->update_cache_timestamp();
    }
} 