<?php
/**
 * Attribute synchronization handler
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Attributes_Handler
 * Handles attribute creation, synchronization, and comparison
 */
class Forbes_Product_Sync_Attributes_Handler {
    /**
     * Logger instance
     *
     * @var Forbes_Product_Sync_Logger
     */
    private $logger;
    
    /**
     * API instance
     *
     * @var Forbes_Product_Sync_API_Attributes
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = Forbes_Product_Sync_Logger::instance();
        $this->api = new Forbes_Product_Sync_API_Attributes();
    }
    
    /**
     * Get attribute by name
     *
     * @param string $name Attribute name
     * @return array|false Attribute data or false if not found
     */
    public function get_attribute_by_name($name) {
        $attributes = wc_get_attribute_taxonomies();
        foreach ($attributes as $attribute) {
            if ($attribute->attribute_name === $name) {
                return $attribute;
            }
        }
        return false;
    }

    /**
     * Get term by slug
     *
     * @param string $slug Term slug
     * @param string $taxonomy Taxonomy name
     * @return WP_Term|false Term object or false if not found
     */
    public function get_term_by_slug($slug, $taxonomy) {
        return get_term_by('slug', $slug, $taxonomy);
    }

    /**
     * Get term by name
     *
     * @param string $name Term name
     * @param string $taxonomy Taxonomy name
     * @return WP_Term|false Term object or false if not found
     */
    public function get_term_by_name($name, $taxonomy) {
        // Try exact match first
        $term = get_term_by('name', $name, $taxonomy);
        if ($term) {
            return $term;
        }
        
        // If not found, try case-insensitive match
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ]);
        
        if (is_wp_error($terms)) {
            return false;
        }
        
        foreach ($terms as $term) {
            if (strtolower($term->name) === strtolower($name)) {
                return $term;
            }
        }
        
        return false;
    }

    /**
     * Create attribute
     *
     * @param array $attribute_data Attribute data
     * @return int|WP_Error Attribute ID or error
     */
    public function create_attribute($attribute_data) {
        $args = array(
            'name' => $attribute_data['name'],
            'slug' => wc_sanitize_taxonomy_name($attribute_data['name']),
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false
        );

        $attribute_id = wc_create_attribute($args);

        if (is_wp_error($attribute_id)) {
            $this->logger->log_sync(
                'Attribute Creation',
                'error',
                sprintf('Failed to create attribute "%s": %s', $attribute_data['name'], $attribute_id->get_error_message())
            );
            return $attribute_id;
        }

        $this->logger->log_sync(
            'Attribute Creation',
            'success',
            sprintf('Created attribute "%s"', $attribute_data['name']),
            array('attribute_id' => $attribute_id)
        );

        return $attribute_id;
    }

    /**
     * Create term
     *
     * @param array $term_data Term data
     * @param string $taxonomy Taxonomy name
     * @return array|WP_Error Term data or error
     */
    public function create_term($term_data, $taxonomy) {
        $args = array(
            'slug' => $term_data['slug']
        );
        
        // Add description if present
        if (!empty($term_data['description'])) {
            $args['description'] = $term_data['description'];
        }
        
        $term = wp_insert_term($term_data['name'], $taxonomy, $args);

        if (is_wp_error($term)) {
            $this->logger->log_sync(
                'Term Creation',
                'error',
                sprintf('Failed to create term "%s": %s', $term_data['name'], $term->get_error_message())
            );
            return $term;
        }

        // Set term metadata
        if (!empty($term['term_id'])) {
            $meta = $this->api->get_term_meta_map($term_data);
            $this->update_term_metadata($term['term_id'], $meta);
        }

        $this->logger->log_sync(
            'Term Creation',
            'success',
            sprintf('Created term "%s"', $term_data['name']),
            array('term_id' => $term['term_id'])
        );

        return $term;
    }

    /**
     * Update term metadata
     *
     * @param int $term_id Term ID
     * @param array $metadata Metadata to update
     */
    public function update_term_metadata($term_id, $metadata) {
        foreach ($metadata as $key => $value) {
            update_term_meta($term_id, $key, $value);
        }
    }
    
    /**
     * Get term changes
     *
     * @param WP_Term $existing_term Existing term
     * @param array $source_term Source term data
     * @return array Changes
     */
    public function get_term_changes($existing_term, $source_term) {
        $changes = array();
        
        // Check for name changes
        if ($existing_term->name !== $source_term['name']) {
            $changes['name'] = array(
                'old' => $existing_term->name,
                'new' => $source_term['name']
            );
        }
        
        // Check for slug changes
        if ($existing_term->slug !== $source_term['slug']) {
            $changes['slug'] = array(
                'old' => $existing_term->slug,
                'new' => $source_term['slug']
            );
        }
        
        // Check for description changes
        if ($existing_term->description !== $source_term['description']) {
            $changes['description'] = array(
                'old' => $existing_term->description,
                'new' => $source_term['description']
            );
        }
        
        // Check for meta changes
        $meta = $this->api->get_term_meta_map($source_term);
        foreach ($meta as $key => $value) {
            $existing_value = get_term_meta($existing_term->term_id, $key, true);
            if ($existing_value !== $value) {
                $changes['meta_' . $key] = array(
                    'old' => $existing_value,
                    'new' => $value
                );
            }
        }
        
        return $changes;
    }

    /**
     * Sync attributes
     *
     * @param array $source_attributes Source attribute data
     * @param bool $sync_metadata Whether to sync metadata
     * @param bool $handle_conflicts Whether to handle conflicts
     * @return array Sync result stats
     */
    public function sync_attributes($source_attributes) {
        $results = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'new_attributes' => array(),
            'updated_attributes' => array(),
            'new_terms' => array(),
            'updated_terms' => array(),
            'missing_attributes' => array(),
            'missing_terms' => array(),
        );

        // Build local attribute map
        $local_attributes = array();
        foreach (wc_get_attribute_taxonomies() as $attr) {
            $local_attributes[$attr->attribute_name] = $attr;
        }

        // Track which local attributes/terms are seen in source
        $seen_local_attributes = array();
        $seen_local_terms = array();

        foreach ($source_attributes as $attribute) {
            $attr_name = wc_sanitize_taxonomy_name($attribute['name']);
            $seen_local_attributes[$attr_name] = true;
            
            // Check if attribute exists locally
            if (!isset($local_attributes[$attr_name])) {
                // Create attribute
                $attribute_id = $this->create_attribute($attribute);
                if (is_wp_error($attribute_id)) {
                    $results['errors']++;
                    $results['missing_attributes'][] = $attribute['name'];
                    continue;
                }
                
                $results['created']++;
                $results['new_attributes'][] = $attribute['name'];
                
                // Refresh taxonomy cache
                delete_transient('wc_attribute_taxonomies');
                WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
                
                // Refresh local_attributes
                $local_attributes = array();
                foreach (wc_get_attribute_taxonomies() as $attr) {
                    $local_attributes[$attr->attribute_name] = $attr;
                }
            } else {
                $results['skipped']++;
            }
            
            // Process terms for this attribute
            if (isset($attribute['terms']) && is_array($attribute['terms'])) {
                $taxonomy = wc_attribute_taxonomy_name($attr_name);
                
                foreach ($attribute['terms'] as $term) {
                    $term_slug = sanitize_title($term['slug']);
                    $seen_local_terms[$taxonomy][$term_slug] = true;
                    
                    // Check if term exists locally
                    $existing_term = $this->get_term_by_slug($term_slug, $taxonomy);
                    
                    if (!$existing_term) {
                        // Try by name as fallback
                        $existing_term = $this->get_term_by_name($term['name'], $taxonomy);
                    }
                    
                    if (!$existing_term) {
                        // Create term
                        $term_result = $this->create_term($term, $taxonomy);
                        if (is_wp_error($term_result)) {
                            $results['errors']++;
                            $results['missing_terms'][] = $term['name'];
                            continue;
                        }
                        
                        $results['created']++;
                        $results['new_terms'][] = $term['name'];
                    } else {
                        // Check for changes
                        $changes = $this->get_term_changes($existing_term, $term);
                        
                        if (!empty($changes)) {
                            // Update term
                            $update_args = array(
                                'name' => $term['name'],
                                'slug' => $term['slug']
                            );
                            
                            if (isset($term['description'])) {
                                $update_args['description'] = $term['description'];
                            }
                            
                            wp_update_term($existing_term->term_id, $taxonomy, $update_args);
                            
                            // Update meta
                            $meta = $this->api->get_term_meta_map($term);
                            $this->update_term_metadata($existing_term->term_id, $meta);
                            
                            $results['updated']++;
                            $results['updated_terms'][] = $term['name'];
                        } else {
                            $results['skipped']++;
                        }
                    }
                }
            }
        }

        return $results;
    }
} 