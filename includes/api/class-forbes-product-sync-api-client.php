<?php
/**
 * API Client
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_API_Client
 * Base class for API interactions
 */
class Forbes_Product_Sync_API_Client {

    /**
     * API base URL
     *
     * @var string
     */
    protected $api_base_url;

    /**
     * Plugin settings
     *
     * @var array
     */
    protected $settings;

    /**
     * Cache TTL in seconds
     *
     * @var int
     */
    protected $cache_ttl = 3600; // 1 hour default

    /**
     * Default request timeout in seconds
     *
     * @var int
     */
    protected $default_timeout = 60;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('forbes_product_sync_settings', []);
        $this->api_base_url = $this->prepare_api_url(isset($this->settings['api_url']) ? $this->settings['api_url'] : '');
    }

    /**
     * Make a request to the WooCommerce REST API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $args Request arguments
     * @return array|WP_Error
     */
    protected function make_request($method, $endpoint, $args = []) {
        if (empty($this->settings['api_url'])) {
            fps_debug('API URL is not configured', 'API Error');
            return new WP_Error('missing_api_url', __('API URL is not configured.', 'forbes-product-sync'));
        }

        if (empty($this->settings['consumer_key']) || empty($this->settings['consumer_secret'])) {
            fps_debug('API credentials are not configured', 'API Error');
            return new WP_Error('missing_credentials', __('API credentials are not configured.', 'forbes-product-sync'));
        }

        $url = $this->api_base_url . $endpoint;
        
        $auth = base64_encode($this->settings['consumer_key'] . ':' . $this->settings['consumer_secret']);
        
        // Set higher timeout for large requests
        $timeout = isset($args['timeout']) ? $args['timeout'] : $this->default_timeout;
        
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        
        // Build request args for wp_remote_request
        $request_args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'sslverify' => false,
        ];
        
        // Add body for POST/PUT requests
        if (!empty($args['body']) && in_array($method, ['POST', 'PUT'])) {
            $request_args['body'] = json_encode($args['body']);
        }
        
        // Log request time
        $start_time = microtime(true);
        
        fps_debug("Making $method request to: $url", 'API Request');
        
        // Make the request
        $response = wp_remote_request($url, $request_args);
        
        $end_time = microtime(true);
        $time_taken = round($end_time - $start_time, 2);
        
        fps_debug("API request took $time_taken seconds", 'API Response Time');
        
        // Check for errors
        if (is_wp_error($response)) {
            fps_debug('API request error: ' . $response->get_error_message(), 'API Error');
            return new WP_Error('api_request_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log response code
        fps_debug("API response code: $response_code", 'API Response');
        
        // Handle non-200 responses
        if ($response_code < 200 || $response_code >= 300) {
            $error_message = "API returned error code: $response_code";
            if (!empty($response_body)) {
                $error_details = json_decode($response_body, true);
                if (!empty($error_details['message'])) {
                    $error_message .= ' - ' . $error_details['message'];
                }
            }
            fps_debug($error_message, 'API Error');
            return new WP_Error('api_error', $error_message);
        }
        
        // Parse response
        $data = json_decode($response_body, true);
        
        // Check for JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            fps_debug('JSON parsing error: ' . json_last_error_msg(), 'API Error');
            return new WP_Error('json_parse_error', __('Failed to parse API response.', 'forbes-product-sync'));
        }
        
        // Process pagination if available
        if (!empty($args['process_pagination']) && isset($response['headers']['x-wp-totalpages'])) {
            $total_pages = (int) $response['headers']['x-wp-totalpages'];
            
            if ($total_pages > 1) {
                fps_debug("Processing pagination - total pages: $total_pages", 'API Pagination');
                
                // Process only up to 10 pages to avoid excessive requests
                $max_pages = min($total_pages, 10);
                
                for ($page = 2; $page <= $max_pages; $page++) {
                    $page_url = add_query_arg('page', $page, $url);
                    fps_debug("Fetching page $page of $total_pages", 'API Pagination');
                    
                    $page_response = wp_remote_request($page_url, $request_args);
                    
                    if (is_wp_error($page_response)) {
                        fps_debug('Pagination request error: ' . $page_response->get_error_message(), 'API Error');
                        continue;
                    }
                    
                    $page_code = wp_remote_retrieve_response_code($page_response);
                    
                    if ($page_code >= 200 && $page_code < 300) {
                        $page_body = wp_remote_retrieve_body($page_response);
                        $page_data = json_decode($page_body, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && is_array($page_data)) {
                            // Merge results
                            $data = array_merge($data, $page_data);
                        }
                    }
                }
            }
        }
        
        // Log data for debugging (limit to summary for large responses)
        $this->log_debug_info('API Response Data', $data);
        
        return $data;
    }

    /**
     * Log debug info for large data structures
     *
     * @param string $title Log title
     * @param mixed $data Data to log
     */
    protected function log_debug_info($title, $data) {
        if (!is_array($data)) {
            fps_debug("$title: " . print_r($data, true), 'API Debug');
            return;
        }
        
        // Only log summary for large data structures
        $count = count($data);
        if ($count > 20) {
            $sample = array_slice($data, 0, 3);
            fps_debug("$title: $count items, first 3: " . print_r($sample, true), 'API Debug');
        } else {
            fps_debug("$title: " . print_r($data, true), 'API Debug');
        }
    }

    /**
     * Prepare API URL by ensuring it ends with a trailing slash
     *
     * @param string $url The API URL
     * @return string Prepared URL
     */
    protected function prepare_api_url($url) {
        if (empty($url)) {
            return '';
        }
        
        // Ensure URL starts with http
        if (strpos($url, 'http') !== 0) {
            $url = 'https://' . $url;
        }
        
        // Ensure URL ends with a slash
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }
        
        // Ensure URL includes wp-json if needed
        if (strpos($url, 'wp-json') === false) {
            $url .= 'wp-json/';
        }
        
        return $url;
    }

    /**
     * Get cache value
     *
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    protected function get_cache($key) {
        $cache = get_transient($key);
        if ($cache !== false) {
            fps_debug("Cache hit for key: $key", 'Cache');
            return $cache;
        }
        fps_debug("Cache miss for key: $key", 'Cache');
        return false;
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $expiration Expiration time in seconds. Defaults to $this->cache_ttl.
     * @return bool True on success, false on failure
     */
    protected function set_cache($key, $value, $expiration = null) {
        if ($expiration === null) {
            $expiration = $this->cache_ttl;
        }
        
        // Update cache timestamp when setting new values
        $this->update_cache_timestamp();
        
        fps_debug("Setting cache for key: $key with expiration: $expiration seconds", 'Cache');
        return set_transient($key, $value, $expiration);
    }

    /**
     * Delete cache value
     *
     * @param string $key Cache key
     * @return bool True on success, false on failure
     */
    protected function delete_cache($key) {
        fps_debug("Deleting cache for key: $key", 'Cache');
        return delete_transient($key);
    }

    /**
     * Test API connection
     *
     * @return array|WP_Error Connection test results
     */
    public function test_connection() {
        fps_debug('Testing API connection', 'API');
        
        // Use a short timeout for quick feedback
        $response = $this->make_request('GET', 'wc/v3/system_status', ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return [
            'success' => true,
            'message' => __('Connection successful!', 'forbes-product-sync')
        ];
    }

    /**
     * Get the configured sync tag from settings
     *
     * @return string Sync tag or empty string if not configured
     */
    public function get_sync_tag() {
        return isset($this->settings['sync_tag']) ? $this->settings['sync_tag'] : '';
    }

    /**
     * Get the API URL from settings
     *
     * @return string API URL or empty string if not configured
     */
    public function get_api_url() {
        return isset($this->settings['api_url']) ? $this->settings['api_url'] : '';
    }

    /**
     * Get cache timestamp
     *
     * @return int|false Timestamp or false if not set
     */
    public function get_cache_timestamp() {
        return get_option('forbes_product_sync_cache_timestamp', false);
    }

    /**
     * Update cache timestamp
     */
    protected function update_cache_timestamp() {
        update_option('forbes_product_sync_cache_timestamp', time());
    }

    /**
     * Clear all caches
     */
    public function clear_all_caches() {
        global $wpdb;
        
        fps_debug('Clearing all caches', 'Cache');
        
        // Get all transients that start with our prefix
        $transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_fps_%',
                '_transient_timeout_fps_%'
            )
        );
        
        foreach ($transients as $transient) {
            if (strpos($transient, '_transient_timeout_') === 0) {
                // Skip timeout entries, they'll be deleted when we delete the transient
                continue;
            }
            
            // Extract actual transient name
            $name = str_replace('_transient_', '', $transient);
            fps_debug("Deleting transient: $name", 'Cache');
            delete_transient($name);
        }
        
        // Update cache timestamp
        $this->update_cache_timestamp();
    }
    
    /**
     * Ensure API response is consistently formatted as array
     * 
     * @param mixed $data API response data
     * @return array Normalized data
     */
    protected function ensure_array($data) {
        if (is_array($data)) {
            return $data;
        }
        
        if (is_object($data)) {
            return json_decode(json_encode($data), true);
        }
        
        return [];
    }
} 