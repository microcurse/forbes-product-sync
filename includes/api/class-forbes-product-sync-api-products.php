<?php
/**
 * Products API Client
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_API_Products
 * Handles product API operations
 */
class Forbes_Product_Sync_API_Products extends Forbes_Product_Sync_API_Client {
    /**
     * Product endpoint
     */
    const ENDPOINT = 'products';
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
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
            'tag_slug' => $this->get_sync_tag()
        );

        $args = wp_parse_args($args, $defaults);
        $cache_key = 'products_' . md5(wp_json_encode($args));
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false && !isset($args['skip_cache'])) {
            return $cached_data;
        }
        
        $response = $this->make_request('GET', self::ENDPOINT, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Filter products to ensure they have the sync tag
        if (is_array($response)) {
            $filtered_products = array();
            foreach ($response as $product) {
                if (isset($product['tags']) && is_array($product['tags'])) {
                    foreach ($product['tags'] as $tag) {
                        if ($tag['slug'] === $this->get_sync_tag()) {
                            $filtered_products[] = $product;
                            break;
                        }
                    }
                }
            }
            
            // Cache the filtered response
            $this->set_cache($cache_key, $filtered_products);
            
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
        $cache_key = 'product_sku_' . sanitize_title($sku);
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $args = array(
            'sku' => $sku
        );
        
        $response = $this->make_request('GET', self::ENDPOINT, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Cache the response
        if (!empty($response)) {
            $this->set_cache($cache_key, $response);
        }
        
        return $response;
    }
    
    /**
     * Get a single product by ID
     *
     * @param int $product_id Product ID
     * @return array|WP_Error
     */
    public function get_product_by_id($product_id) {
        $cache_key = 'product_id_' . $product_id;
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $response = $this->make_request('GET', self::ENDPOINT . '/' . $product_id);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Cache the response
        $this->set_cache($cache_key, $response);
        
        return $response;
    }
    
    /**
     * Get product categories
     *
     * @param array $args Query arguments
     * @return array|WP_Error
     */
    public function get_categories($args = array()) {
        $defaults = array(
            'per_page' => 100,
            'page' => 1
        );
        
        $args = wp_parse_args($args, $defaults);
        $cache_key = 'product_categories_' . md5(wp_json_encode($args));
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false && !isset($args['skip_cache'])) {
            return $cached_data;
        }
        
        $response = $this->make_request('GET', self::ENDPOINT . '/categories', $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Cache the response
        $this->set_cache($cache_key, $response);
        
        return $response;
    }
    
    /**
     * Get all products with the sync tag
     *
     * @return array|WP_Error All products to sync
     */
    public function get_all_sync_products() {
        $products = array();
        $page = 1;
        $per_page = 100;
        
        do {
            $args = array(
                'page' => $page,
                'per_page' => $per_page,
                'tag_slug' => $this->get_sync_tag()
            );
            
            $response = $this->get_products($args);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $products = array_merge($products, $response);
            $page++;
            
            // Stop if we received fewer products than the per_page limit
            if (count($response) < $per_page) {
                break;
            }
        } while (true);
        
        return $products;
    }
    
    /**
     * Find a WooCommerce product by SKU
     *
     * @param string $sku Product SKU
     * @return WC_Product|null Product object or null if not found
     */
    public function wc_get_product_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return null;
        }
        
        return wc_get_product($product_id);
    }
} 