<?php
/**
 * Attributes API Client
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_API_Attributes
 * Handles attribute API operations
 */
class Forbes_Product_Sync_API_Attributes extends Forbes_Product_Sync_API_Client {
    /**
     * Attribute endpoint
     */
    const ENDPOINT = 'products/attributes';
    
    /**
     * Cache key for all attributes
     */
    const CACHE_KEY_ATTRIBUTES = 'attributes';
    
    /**
     * Cache key for attributes with terms
     */
    const CACHE_KEY_ATTRIBUTES_WITH_TERMS = 'attributes_with_terms';
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Get product attributes
     *
     * @return array|WP_Error
     */
    public function get_attributes() {
        $cache_key = self::CACHE_KEY_ATTRIBUTES;
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $response = $this->make_request('GET', self::ENDPOINT, array('per_page' => 100));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Cache the response
        $this->set_cache($cache_key, $response);
        $this->update_cache_timestamp();
        
        return $response;
    }
    
    /**
     * Get attribute terms
     *
     * @param int $attribute_id Attribute ID
     * @return array|WP_Error
     */
    public function get_attribute_terms($attribute_id) {
        $cache_key = 'attribute_terms_' . $attribute_id;
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $response = $this->make_request('GET', self::ENDPOINT . '/' . $attribute_id . '/terms', array('per_page' => 100));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Cache the response
        $this->set_cache($cache_key, $response);
        
        return $response;
    }
    
    /**
     * Get all attributes with their terms in a single call
     *
     * @return array|WP_Error
     */
    public function get_attributes_with_terms() {
        $cache_key = self::CACHE_KEY_ATTRIBUTES_WITH_TERMS;
        
        // Check cache first
        $cached_data = $this->get_cache($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        // Get all attributes
        $attributes = $this->get_attributes();
        if (is_wp_error($attributes)) {
            return $attributes;
        }
        
        // Get terms for each attribute
        $terms_map = array();
        foreach ($attributes as $attribute) {
            $terms = $this->get_attribute_terms($attribute['id']);
            if (is_wp_error($terms)) {
                // Log the error but continue to get other attribute terms
                error_log(sprintf(
                    'Error getting terms for attribute %s (ID: %d): %s',
                    $attribute['name'],
                    $attribute['id'],
                    $terms->get_error_message()
                ));
                continue;
            }
            
            $terms_map[$attribute['id']] = $terms;
        }
        
        $result = array(
            'attributes' => $attributes,
            'terms' => $terms_map
        );
        
        // Cache the combined result
        $this->set_cache($cache_key, $result);
        $this->update_cache_timestamp();
        
        return $result;
    }
    
    /**
     * Clear attribute caches
     */
    public function clear_attribute_caches() {
        $this->delete_cache(self::CACHE_KEY_ATTRIBUTES);
        $this->delete_cache(self::CACHE_KEY_ATTRIBUTES_WITH_TERMS);
        
        // Also clear any attribute terms caches
        global $wpdb;
        $like = $wpdb->esc_like('_transient_' . $this->cache_group . '_attribute_terms_') . '%';
        $transients = $wpdb->get_col(
            $wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $like)
        );
        
        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient);
            delete_transient($key);
        }
        
        $this->update_cache_timestamp();
    }
    
    /**
     * Get attribute term metadata map
     * 
     * This function will parse the attribute term data from the API and return a map
     * of term metadata that can be used to set local term meta.
     *
     * @param array $term Term data from API
     * @return array Metadata map
     */
    public function get_term_meta_map($term) {
        $meta = array();
        
        // Get term suffix, if any
        if (!empty($term['description'])) {
            // Check if the description contains a suffix indicator
            if (preg_match('/suffix:\s*(.*?)(?:\n|$)/i', $term['description'], $matches)) {
                $meta['term_suffix'] = trim($matches[1]);
            }
        }
        
        // Get swatch image, if any
        if (!empty($term['image'])) {
            if (!empty($term['image']['src'])) {
                $meta['swatch_image'] = esc_url_raw($term['image']['src']);
            }
        }
        
        // Add any additional meta processing here
        
        return $meta;
    }
} 