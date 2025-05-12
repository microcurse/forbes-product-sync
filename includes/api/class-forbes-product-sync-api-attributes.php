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
     * Cache key for attributes
     */
    const CACHE_KEY_ATTRIBUTES = 'fps_attributes';
    
    /**
     * Cache key for terms
     */
    const CACHE_KEY_TERMS = 'fps_terms';
    
    /**
     * Cache key for attributes with terms
     */
    const CACHE_KEY_ATTRIBUTES_WITH_TERMS = 'fps_attributes_with_terms';
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        
        // Increase cache time for attributes since they don't change often
        $this->cache_ttl = 24 * HOUR_IN_SECONDS; // 24 hours
    }
    
    /**
     * Get all product attributes
     *
     * @param bool $use_cache Whether to use cached data
     * @return array|WP_Error Attributes or error
     */
    public function get_attributes($use_cache = true) {
        fps_debug('Getting all product attributes', 'Attributes API');
        
        if ($use_cache) {
            $cached_data = $this->get_cache(self::CACHE_KEY_ATTRIBUTES);
            if ($cached_data !== false) {
                fps_debug('Using cached attributes data', 'Attributes API');
                return $cached_data;
            }
        }
        
        $response = $this->make_request('GET', 'wc/v3/products/attributes', [
            'per_page' => 100,
            'process_pagination' => true,
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            fps_debug('Error getting attributes: ' . $response->get_error_message(), 'Attributes API');
            return $response;
        }
        
        // Ensure consistent array format and normalize data
        $attributes = $this->normalize_attributes($response);
        
        // Cache the results
        $this->set_cache(self::CACHE_KEY_ATTRIBUTES, $attributes);
        
        fps_debug('Retrieved ' . count($attributes) . ' attributes', 'Attributes API');
        
        return $attributes;
    }
    
    /**
     * Get terms for a specific attribute
     *
     * @param int $attribute_id Attribute ID
     * @param bool $use_cache Whether to use cached data
     * @return array|WP_Error Terms or error
     */
    public function get_attribute_terms($attribute_id, $use_cache = true) {
        fps_debug("Getting terms for attribute ID: $attribute_id", 'Attributes API');
        
        $cache_key = self::CACHE_KEY_TERMS . '_' . $attribute_id;
        
        if ($use_cache) {
            $cached_data = $this->get_cache($cache_key);
            if ($cached_data !== false) {
                fps_debug('Using cached terms data for attribute ' . $attribute_id, 'Attributes API');
                return $cached_data;
            }
        }
        
        $response = $this->make_request('GET', 'wc/v3/products/attributes/' . $attribute_id . '/terms', [
            'per_page' => 100,
            'process_pagination' => true,
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            fps_debug('Error getting attribute terms: ' . $response->get_error_message(), 'Attributes API');
            return $response;
        }
        
        // Ensure consistent array format and normalize data
        $terms = $this->normalize_terms($response);
        
        // Cache the results
        $this->set_cache($cache_key, $terms);
        
        fps_debug('Retrieved ' . count($terms) . ' terms for attribute ' . $attribute_id, 'Attributes API');
        
        return $terms;
    }
    
    /**
     * Get all attributes with their terms in a single call
     *
     * @param bool $use_cache Whether to use cached data
     * @param bool $partial_data Whether to return partial data if available
     * @return array|WP_Error Attributes with terms, or error
     */
    public function get_attributes_with_terms($use_cache = true, $partial_data = false) {
        fps_debug('Getting all attributes with terms', 'Attributes API');
        
        if ($use_cache) {
            $cached_data = $this->get_cache(self::CACHE_KEY_ATTRIBUTES_WITH_TERMS);
            if ($cached_data !== false) {
                fps_debug('Using cached attributes-with-terms data', 'Attributes API');
                return $cached_data;
            }
        }
        
        // Get all attributes first
        $attributes = $this->get_attributes($use_cache);
        
        if (is_wp_error($attributes)) {
            fps_debug('Error getting attributes in get_attributes_with_terms: ' . $attributes->get_error_message(), 'Attributes API');
            return $attributes;
        }
        
        // Store partial data to help with timeout recovery
        $partial_cache_key = self::CACHE_KEY_ATTRIBUTES_WITH_TERMS . '_partial';
        
        // Structure for the combined data
        $combined_data = [
            'attributes' => $attributes,
            'terms' => [],
            'metadata' => [
                'total_attributes' => count($attributes),
                'processed_attributes' => 0,
                'total_terms' => 0,
                'timestamp' => time(),
            ]
        ];
        
        // Check for partial data if requested
        $partial_cache = null;
        if ($partial_data) {
            $partial_cache = $this->get_cache($partial_cache_key);
            if ($partial_cache !== false && isset($partial_cache['metadata'])) {
                fps_debug('Using partial cache data with ' . $partial_cache['metadata']['processed_attributes'] . ' of ' . $partial_cache['metadata']['total_attributes'] . ' attributes', 'Attributes API');
                $combined_data = $partial_cache;
            }
        }
        
        // Determine which attributes we still need to process
        $start_index = isset($combined_data['metadata']['processed_attributes']) ? $combined_data['metadata']['processed_attributes'] : 0;
        
        // Process terms for each attribute - batch into smaller chunks
        $batch_size = 10; // Process 10 attributes at a time
        $total_attributes = count($attributes);
        $max_batches = ceil(($total_attributes - $start_index) / $batch_size);
        
        fps_debug("Processing terms for $total_attributes attributes in up to $max_batches batches", 'Attributes API');
        
        for ($batch = 0; $batch < $max_batches; $batch++) {
            $batch_start = $start_index + ($batch * $batch_size);
            $batch_end = min($batch_start + $batch_size, $total_attributes);
            
            fps_debug("Processing batch $batch ($batch_start to $batch_end)", 'Attributes API');
            
            for ($i = $batch_start; $i < $batch_end; $i++) {
                $attribute = $attributes[$i];
                
                if (!isset($attribute['id'])) {
                    fps_debug('Attribute missing ID at index ' . $i, 'Attributes API Error');
                    continue;
                }
                
                $attribute_id = $attribute['id'];
                
                // Check if we already have terms for this attribute (from partial cache)
                if (isset($combined_data['terms'][$attribute_id])) {
                    fps_debug("Skipping already cached terms for attribute ID $attribute_id", 'Attributes API');
                    continue;
                }
                
                // Get terms for this attribute
                $terms = $this->get_attribute_terms($attribute_id, $use_cache);
                
                if (is_wp_error($terms)) {
                    fps_debug('Error getting terms for attribute ' . $attribute_id . ': ' . $terms->get_error_message(), 'Attributes API');
                    
                    // Store partial data before returning error
                    $combined_data['metadata']['processed_attributes'] = $i;
                    $this->set_cache($partial_cache_key, $combined_data, 2 * HOUR_IN_SECONDS); // Keep partial data for 2 hours
                    
                    return $terms;
                }
                
                // Store the terms for this attribute
                $combined_data['terms'][$attribute_id] = $terms;
                $combined_data['metadata']['total_terms'] += count($terms);
            }
            
            // Update processed count
            $combined_data['metadata']['processed_attributes'] = $batch_end;
            
            // Save partial progress after each batch
            $this->set_cache($partial_cache_key, $combined_data, 2 * HOUR_IN_SECONDS);
            
            fps_debug("Completed batch $batch, processed " . $combined_data['metadata']['processed_attributes'] . " of $total_attributes attributes", 'Attributes API');
        }
        
        // Remove metadata for final cache
        unset($combined_data['metadata']);
        
        // Cache the complete results
        $this->set_cache(self::CACHE_KEY_ATTRIBUTES_WITH_TERMS, $combined_data);
        
        // Clear partial cache since we're done
        $this->delete_cache($partial_cache_key);
        
        fps_debug('Retrieved all attributes with terms: ' . count($attributes) . ' attributes found', 'Attributes API');
        
        return $combined_data;
    }
    
    /**
     * Clear all attribute caches
     */
    public function clear_attribute_caches() {
        fps_debug('Clearing all attribute caches', 'Attributes API');
        
        // Delete main caches
        $this->delete_cache(self::CACHE_KEY_ATTRIBUTES);
        $this->delete_cache(self::CACHE_KEY_ATTRIBUTES_WITH_TERMS);
        $this->delete_cache(self::CACHE_KEY_ATTRIBUTES_WITH_TERMS . '_partial');
        
        // Get all attributes to find term caches to clear
        $attributes = $this->get_attributes(false); // Force fresh fetch
        
        if (!is_wp_error($attributes)) {
            foreach ($attributes as $attribute) {
                if (isset($attribute['id'])) {
                    $term_cache_key = self::CACHE_KEY_TERMS . '_' . $attribute['id'];
                    $this->delete_cache($term_cache_key);
                }
            }
        }
        
        fps_debug('All attribute caches cleared', 'Attributes API');
    }
    
    /**
     * Extract term metadata from term data
     * 
     * @param array $term Term data
     * @return array Term metadata
     */
    public function get_term_meta_map($term) {
        $meta = [];
        
        if (!is_array($term)) {
            fps_debug('Term metadata extracted: []', 'Term Meta');
            return $meta;
        }
        
        // Handle meta values from API response
        if (isset($term['meta_data']) && is_array($term['meta_data'])) {
            foreach ($term['meta_data'] as $meta_item) {
                if (isset($meta_item['key']) && isset($meta_item['value'])) {
                    $meta[$meta_item['key']] = $meta_item['value'];
                }
            }
        }
        
        // Handle specific properties we know might contain meta information
        if (isset($term['swatch_image']) && !empty($term['swatch_image'])) {
            $meta['swatch_image'] = $term['swatch_image'];
        }
        
        if (isset($term['term_suffix']) && !empty($term['term_suffix'])) {
            $meta['term_suffix'] = $term['term_suffix'];
        }
        
        fps_debug('Term metadata extracted: ' . print_r($meta, true), 'Term Meta');
        
        return $meta;
    }
    
    /**
     * Normalize attributes data to ensure consistent format
     *
     * @param mixed $attributes Raw attributes data
     * @return array Normalized attributes
     */
    private function normalize_attributes($attributes) {
        $normalized = [];
        
        // Ensure we're working with an array
        $attributes = $this->ensure_array($attributes);
        
        foreach ($attributes as $attribute) {
            // Convert to array if it's an object
            $attribute = $this->ensure_array($attribute);
            
            // Ensure required fields exist
            if (!isset($attribute['id'])) {
                continue;
            }
            
            // Add a safe slug if not present
            if (!isset($attribute['slug']) && isset($attribute['name'])) {
                $attribute['slug'] = sanitize_title($attribute['name']);
            }
            
            $normalized[] = $attribute;
        }
        
        return $normalized;
    }
    
    /**
     * Normalize terms data to ensure consistent format
     *
     * @param mixed $terms Raw terms data
     * @return array Normalized terms
     */
    private function normalize_terms($terms) {
        $normalized = [];
        
        // Ensure we're working with an array
        $terms = $this->ensure_array($terms);
        
        foreach ($terms as $term) {
            // Convert to array if it's an object
            $term = $this->ensure_array($term);
            
            // Ensure required fields exist
            if (!isset($term['id'])) {
                continue;
            }
            
            // Add a safe slug if not present
            if (!isset($term['slug']) && isset($term['name'])) {
                $term['slug'] = sanitize_title($term['name']);
            }
            
            // Ensure description is set
            if (!isset($term['description'])) {
                $term['description'] = '';
            }
            
            $normalized[] = $term;
        }
        
        return $normalized;
    }
} 