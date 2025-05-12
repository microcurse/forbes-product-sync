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
            if (strtolower(trim($term->name)) === strtolower(trim($name))) {
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
        error_log('Creating attribute: ' . json_encode($attribute_data));
        
        if (empty($attribute_data['name'])) {
            error_log('Attribute name is empty, cannot create attribute');
            return new WP_Error('invalid_attribute', 'Attribute name is required');
        }
        
        // Check if the attribute already exists
        $existing = $this->get_attribute_by_name(wc_sanitize_taxonomy_name($attribute_data['name']));
        if ($existing) {
            error_log('Attribute already exists with ID: ' . $existing->attribute_id);
            return $existing->attribute_id;
        }
        
        $args = array(
            'name' => $attribute_data['name'],
            'slug' => wc_sanitize_taxonomy_name($attribute_data['name']),
            'type' => 'select', // Default to select type
            'order_by' => 'menu_order',
            'has_archives' => false
        );
        
        error_log('Creating attribute with args: ' . json_encode($args));
        
        $attribute_id = wc_create_attribute($args);

        if (is_wp_error($attribute_id)) {
            error_log('Error creating attribute: ' . $attribute_id->get_error_message());
            $this->logger->log_sync(
                'Attribute Creation',
                'error',
                sprintf('Failed to create attribute "%s": %s', $attribute_data['name'], $attribute_id->get_error_message())
            );
            return $attribute_id;
        }

        error_log('Successfully created attribute with ID: ' . $attribute_id);
        
        // Force refresh of attribute cache
        $this->refresh_attribute_cache();
        
        $this->logger->log_sync(
            'Attribute Creation',
            'success',
            sprintf('Created attribute "%s" with ID %d', $attribute_data['name'], $attribute_id),
            array('attribute_id' => $attribute_id)
        );

        return $attribute_id;
    }
    
    /**
     * Force refresh of WooCommerce attribute cache
     */
    public function refresh_attribute_cache() {
        error_log('Refreshing attribute taxonomy cache');
        
        // Delete the transient cache
        delete_transient('wc_attribute_taxonomies');
        
        // If WC_Cache_Helper exists, use it to invalidate the cache group
        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
            error_log('Invalidated WooCommerce attributes cache group');
        }
        
        // Direct database refresh as a fallback
        global $wpdb;
        $attributes = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "woocommerce_attribute_taxonomies");
        error_log('Directly loaded ' . count($attributes) . ' attributes from database');
        
        // Register any new attribute taxonomies
        foreach ($attributes as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
            error_log('Registering taxonomy: ' . $taxonomy);
            
            // Check if the taxonomy is already registered
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy(
                    $taxonomy,
                    apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, array('product')),
                    apply_filters('woocommerce_taxonomy_args_' . $taxonomy, array(
                        'labels' => array(
                            'name' => $attribute->attribute_label,
                        ),
                        'hierarchical' => true,
                        'show_ui' => false,
                        'query_var' => true,
                        'rewrite' => false,
                    ))
                );
                error_log('Registered new taxonomy: ' . $taxonomy);
            } else {
                error_log('Taxonomy already exists: ' . $taxonomy);
            }
        }
        
        // Return the fresh attributes
        return $attributes;
    }

    /**
     * Create term
     *
     * @param array $term_data Term data
     * @param string $taxonomy Taxonomy name
     * @return array|WP_Error Term data or error
     */
    public function create_term($term_data, $taxonomy) {
        // Make sure we have valid term data
        if (empty($term_data['name'])) {
            $this->logger->log_sync(
                'Term Creation',
                'error',
                'Cannot create term: name is required'
            );
            return new WP_Error('invalid_term_data', 'Term name is required');
        }

        // Make sure the taxonomy exists before creating a term
        if (!taxonomy_exists($taxonomy)) {
            $this->logger->log_sync(
                'Term Creation',
                'error',
                sprintf('Cannot create term "%s": taxonomy %s does not exist', $term_data['name'], $taxonomy)
            );
            return new WP_Error('invalid_taxonomy', sprintf('Taxonomy %s does not exist', $taxonomy));
        }

        // Prepare the term slug - ensure it's valid
        $slug = '';
        if (isset($term_data['slug']) && !empty($term_data['slug'])) {
            $slug = sanitize_title($term_data['slug']);
            
            // Make sure slug isn't too long (DB typically has limits around 200 chars)
            if (strlen($slug) > 190) {
                $slug = substr($slug, 0, 190);
                $this->logger->log_sync(
                    'Term Creation',
                    'warning',
                    sprintf('Term slug for "%s" was truncated to %d characters', $term_data['name'], 190)
                );
            }
        }
        
        // Check if the term already exists (by exact slug or exact name only)
        $existing_term = null;
        if (!empty($slug)) {
            // Force exact slug match only
            $existing_term = get_term_by('slug', $slug, $taxonomy);
        }
        
        if (!$existing_term) {
            // Force exact name match only
            $existing_term = get_term_by('name', $term_data['name'], $taxonomy);
        }

        if ($existing_term) {
            $this->logger->log_sync(
                'Term Creation',
                'info',
                sprintf('Term "%s" already exists in taxonomy %s with ID %d', $term_data['name'], $taxonomy, $existing_term->term_id)
            );
            
            // Update the term metadata if it exists to ensure it's up to date
            if (!empty($existing_term->term_id)) {
                $meta = $this->api->get_term_meta_map($term_data);
                $this->update_term_metadata($existing_term->term_id, $meta);
                $this->logger->log_sync(
                    'Term Metadata',
                    'info',
                    sprintf('Updated metadata for existing term "%s"', $term_data['name'])
                );
            }
            
            return array(
                'term_id' => $existing_term->term_id,
                'term_taxonomy_id' => $existing_term->term_taxonomy_id
            );
        }
        
        // Prepare arguments for term creation
        $args = array();
        if (!empty($slug)) {
            $args['slug'] = $slug;
        }

        // Add description if present
        if (!empty($term_data['description'])) {
            $args['description'] = sanitize_text_field($term_data['description']);
        }

        // Create the term
        try {
            // Ensure the name isn't too long either
            $term_name = $term_data['name'];
            if (strlen($term_name) > 190) {
                $term_name = substr($term_name, 0, 190);
                $this->logger->log_sync(
                    'Term Creation',
                    'warning',
                    sprintf('Term name "%s" was truncated to %d characters', $term_data['name'], 190)
                );
            }
        
            $term = wp_insert_term(sanitize_text_field($term_name), $taxonomy, $args);

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
        } catch (Exception $e) {
            $this->logger->log_sync(
                'Term Creation',
                'error',
                sprintf('Exception creating term "%s": %s', $term_data['name'], $e->getMessage())
            );
            return new WP_Error('term_creation_exception', $e->getMessage());
        }
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
        error_log('Starting attribute sync with ' . count($source_attributes) . ' attributes');
        
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
        
        error_log('Found ' . count($local_attributes) . ' existing local attributes');

        // Process each source attribute
        foreach ($source_attributes as $attribute) {
            $attr_name = wc_sanitize_taxonomy_name($attribute['name']);
            error_log("Processing attribute: $attr_name");
            
            // Check if attribute exists locally
            if (!isset($local_attributes[$attr_name])) {
                // Create attribute using WooCommerce function
                $args = array(
                    'name'         => $attribute['name'],
                    'slug'         => $attr_name,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false
                );
                
                error_log("Creating attribute with args: " . json_encode($args));
                $attribute_id = wc_create_attribute($args);
                
                if (is_wp_error($attribute_id)) {
                    error_log("Error creating attribute: " . $attribute_id->get_error_message());
                    $results['errors']++;
                    $results['missing_attributes'][] = $attribute['name'];
                    continue;
                }
                
                error_log("Successfully created attribute with ID: $attribute_id");
                $results['created']++;
                $results['new_attributes'][] = $attribute['name'];
                
                // Force refresh WooCommerce attribute cache
                $this->refresh_attribute_cache();
                
                // Wait briefly to allow WooCommerce to register the new attribute
                error_log("Pausing to allow WooCommerce to register the attribute");
                sleep(1);
                
                // Get updated attributes list
                $updated_attributes = wc_get_attribute_taxonomies();
                $local_attributes = array();
                foreach ($updated_attributes as $attr) {
                    $local_attributes[$attr->attribute_name] = $attr;
                }
                
                error_log("Attributes after creation: " . count($local_attributes));
            } else {
                error_log("Attribute already exists locally: $attr_name");
                $results['skipped']++;
            }
            
            // Get the taxonomy name for this attribute
            $taxonomy = wc_attribute_taxonomy_name($attr_name);
            error_log("Taxonomy name: $taxonomy");
            
            // Ensure the taxonomy is registered
            if (!taxonomy_exists($taxonomy)) {
                error_log("Taxonomy doesn't exist yet, registering it");
                
                register_taxonomy(
                    $taxonomy,
                    apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, array('product')),
                    apply_filters('woocommerce_taxonomy_args_' . $taxonomy, array(
                        'labels' => array(
                            'name' => $attribute['name'],
                        ),
                        'hierarchical' => true,
                        'show_ui' => false,
                        'query_var' => true,
                        'rewrite' => false,
                    ))
                );
                
                error_log("Taxonomy registered: $taxonomy");
            }
            
            // Process terms for this attribute
            if (isset($attribute['terms']) && is_array($attribute['terms'])) {
                error_log("Processing " . count($attribute['terms']) . " terms for attribute: $attr_name");
                
                foreach ($attribute['terms'] as $term) {
                    $term_slug = sanitize_title($term['slug']);
                    error_log("Processing term: " . $term['name'] . " (slug: $term_slug)");
                    
                    // Check if term exists locally
                    $existing_term = $this->get_term_by_slug($term_slug, $taxonomy);
                    
                    if (!$existing_term) {
                        // Try by name as fallback
                        $existing_term = $this->get_term_by_name($term['name'], $taxonomy);
                    }
                    
                    if (!$existing_term) {
                        // Create term
                        error_log("Term doesn't exist, creating it");
                        
                        // Prepare args
                        $term_args = array();
                        if (!empty($term_slug)) {
                            $term_args['slug'] = $term_slug;
                        }
                        
                        if (!empty($term['description'])) {
                            $term_args['description'] = sanitize_text_field($term['description']);
                        }
                        
                        $term_result = wp_insert_term(
                            sanitize_text_field($term['name']),
                            $taxonomy,
                            $term_args
                        );
                        
                        if (is_wp_error($term_result)) {
                            error_log("Error creating term: " . $term_result->get_error_message());
                            $results['errors']++;
                            $results['missing_terms'][] = $term['name'];
                            continue;
                        }
                        
                        error_log("Term created successfully: " . json_encode($term_result));
                        $results['created']++;
                        $results['new_terms'][] = $term['name'];
                        
                        // Set term metadata
                        if (!empty($term_result['term_id'])) {
                            $meta = $this->api->get_term_meta_map($term);
                            $this->update_term_metadata($term_result['term_id'], $meta);
                            error_log("Term metadata updated");
                        }
                    } else {
                        // Check for changes
                        $changes = $this->get_term_changes($existing_term, $term);
                        
                        if (!empty($changes)) {
                            // Update term
                            error_log("Term exists with changes, updating it");
                            $update_args = array(
                                'name' => $term['name']
                            );
                            
                            if (isset($term['slug']) && !empty($term['slug'])) {
                                $update_args['slug'] = sanitize_title($term['slug']);
                            }
                            
                            if (isset($term['description'])) {
                                $update_args['description'] = sanitize_text_field($term['description']);
                            }
                            
                            $update_result = wp_update_term($existing_term->term_id, $taxonomy, $update_args);
                            
                            if (is_wp_error($update_result)) {
                                error_log("Error updating term: " . $update_result->get_error_message());
                                $results['errors']++;
                                continue;
                            }
                            
                            // Update meta
                            $meta = $this->api->get_term_meta_map($term);
                            $this->update_term_metadata($existing_term->term_id, $meta);
                            
                            $results['updated']++;
                            $results['updated_terms'][] = $term['name'];
                            error_log("Term updated successfully");
                        } else {
                            error_log("Term exists with no changes, skipping");
                            $results['skipped']++;
                        }
                    }
                }
            }
        }

        error_log("Attribute sync completed. Results: " . json_encode($results));
        return $results;
    }
} 