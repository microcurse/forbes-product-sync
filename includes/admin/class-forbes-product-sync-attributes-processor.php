<?php
/**
 * Attributes Processor Class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include required files
require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/attributes/class-forbes-product-sync-attributes-handler.php';

/**
 * Class Forbes_Product_Sync_Attributes_Processor
 * Handles processing selected attribute/term changes based on AJAX request.
 */
class Forbes_Product_Sync_Attributes_Processor {

    private $logger;
    private $api_attributes;
    private $attributes_handler;

    public function __construct() {
        $this->logger = Forbes_Product_Sync_Logger::instance();
        $this->api_attributes = new Forbes_Product_Sync_API_Attributes();
        $this->attributes_handler = new Forbes_Product_Sync_Attributes_Handler();
    }

    /**
     * Process selected attributes/terms from the comparison page.
     *
     * @param array $selected_terms Array of selected terms from $_POST (each item has 'attribute', 'term', 'term_name').
     * @param bool $sync_metadata Whether to sync metadata (e.g., image, suffix).
     * @param bool $handle_conflicts Currently unused, potential future feature.
     * @return array Processing results (created, updated, errors).
     */
    public function process_attributes($selected_terms, $sync_metadata = true, $handle_conflicts = true) {
        error_log('Starting process_attributes method');
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'processed_list' => [] // Keep track of what was actually processed
        ];

        if (empty($selected_terms)) {
            error_log('No terms selected, returning empty results');
            return $results; // Nothing selected
        }

        try {
            error_log('Fetching source data from API');
            // Fetch fresh source data to ensure we process the correct items
            $source_data = $this->api_attributes->get_attributes_with_terms();
            
            if (is_wp_error($source_data)) {
                error_log('API Error: ' . $source_data->get_error_message());
                $this->logger->log_sync('Attribute Processing', 'error', 'Could not fetch source attribute data for processing: ' . $source_data->get_error_message());
                $results['errors'] = count($selected_terms); // Mark all as errors
                return $results;
            }
            
            if (!isset($source_data['attributes']) || !is_array($source_data['attributes'])) {
                error_log('Invalid source data: attributes missing or not an array');
                $this->logger->log_sync('Attribute Processing', 'error', 'Invalid source attribute data: attributes missing or not an array');
                $results['errors'] = count($selected_terms);
                return $results;
            }

            // Create maps for easier lookup
            error_log('Creating attribute maps for lookup');
            $source_attrs_map = [];
            foreach ($source_data['attributes'] as $attr) {
                $source_attrs_map[wc_sanitize_taxonomy_name($attr['name'])] = $attr;
            }
            
            if (!isset($source_data['terms']) || !is_array($source_data['terms'])) {
                error_log('Invalid source data: terms missing or not an array');
                $this->logger->log_sync('Attribute Processing', 'error', 'Invalid source attribute data: terms missing or not an array');
                $results['errors'] = count($selected_terms);
                return $results;
            }
            
            $source_terms_map = $source_data['terms']; // Already mapped by attribute ID

            foreach ($selected_terms as $selection) {
                try {
                    error_log('Processing term: ' . json_encode($selection));
                    
                    if (!isset($selection['attribute']) || !isset($selection['term'])) {
                        error_log('Invalid selection, missing attribute or term: ' . json_encode($selection));
                        $results['skipped']++;
                        continue;
                    }
                    
                    $attr_slug = wc_sanitize_taxonomy_name($selection['attribute']);
                    $term_slug = sanitize_title($selection['term']);
                    $taxonomy_name = wc_attribute_taxonomy_name($attr_slug);
                    
                    error_log("Processing attribute: $attr_slug, term: $term_slug, taxonomy: $taxonomy_name");

                    // Find the corresponding source attribute and term
                    if (!isset($source_attrs_map[$attr_slug])) {
                        error_log("Source attribute not found for: $attr_slug");
                        $this->logger->log_sync('Attribute Processing', 'warning', "Could not find source data for attribute: $attr_slug. Skipping.");
                        $results['skipped']++;
                        continue;
                    }
                    
                    $source_attr = $source_attrs_map[$attr_slug];
                    $source_term = null;
                    
                    if (!isset($source_terms_map[$source_attr['id']]) || !is_array($source_terms_map[$source_attr['id']])) {
                        error_log("No terms found for attribute ID: " . $source_attr['id']);
                        $this->logger->log_sync('Attribute Processing', 'warning', "No terms found for attribute: $attr_slug. Skipping.");
                        $results['skipped']++;
                        continue;
                    }
                    
                    foreach ($source_terms_map[$source_attr['id']] as $st) {
                        // Match either by slug (preferred) or by name
                        if (sanitize_title($st['slug']) === $term_slug || 
                            (isset($selection['term_name']) && trim($st['name']) === trim($selection['term_name']))) {
                            $source_term = $st;
                            break;
                        }
                    }
                    
                    if (!$source_term) {
                        error_log("Source term not found for: $term_slug in attribute: $attr_slug");
                        $this->logger->log_sync('Attribute Processing', 'warning', "Could not find source data for term: $term_slug in attribute: $attr_slug. Skipping.");
                        $results['skipped']++;
                        continue;
                    }

                    // --- Process Attribute (Create if missing) ---
                    error_log("Checking if local attribute exists: $attr_slug");
                    $local_attribute = $this->attributes_handler->get_attribute_by_name($attr_slug);
                    
                    if (!$local_attribute) {
                        error_log("Creating new attribute: $attr_slug");
                        $attr_result = $this->attributes_handler->create_attribute($source_attr);
                        
                        if (is_wp_error($attr_result)) {
                            error_log("Error creating attribute: " . $attr_result->get_error_message());
                            $results['errors']++;
                            continue; // Skip term if attribute creation failed
                        } else {
                            error_log("Attribute created successfully with ID: $attr_result");
                            
                            // Force refresh WC data
                            $this->attributes_handler->refresh_attribute_cache();
                            
                            // Wait briefly to allow WooCommerce to register the new attribute
                            usleep(500000); // 0.5 seconds
                            
                            // Re-fetch to make sure we have the latest attribute data
                            $local_attribute = $this->attributes_handler->get_attribute_by_name($attr_slug);
                            
                            if (!$local_attribute) {
                                error_log("CRITICAL: Still can't find attribute $attr_slug after creation - this will cause issues");
                                $results['errors']++;
                                continue;
                            } else {
                                error_log("Successfully retrieved newly created attribute with ID: " . $local_attribute->attribute_id);
                            }
                        }
                    } else {
                        error_log("Local attribute found with ID: " . $local_attribute->attribute_id);
                    }

                    // --- Process Term (Create or Update) ---
                    error_log("Processing term: " . $source_term['name'] . " for taxonomy: $taxonomy_name");
                    $term_result = $this->attributes_handler->create_term($source_term, $taxonomy_name);
                    
                    if (is_wp_error($term_result)) {
                        error_log("Error processing term: " . $term_result->get_error_message());
                        $results['errors']++;
                        $this->logger->log_sync(
                            'Term Processing',
                            'error',
                            sprintf('Failed to process term "%s": %s', $source_term['name'], $term_result->get_error_message())
                        );
                    } else {
                        // Check if the term was created or already existed
                        $term_id = $term_result['term_id'];
                        $term_taxonomy_id = $term_result['term_taxonomy_id'];
                        
                        // Get the term to check if it was just created
                        $term = get_term($term_id, $taxonomy_name);
                        if ($term && isset($term->count) && $term->count == 0) {
                            // This is likely a newly created term
                            error_log("Term was likely created: " . json_encode($term_result));
                            $results['created']++;
                            $this->logger->log_sync(
                                'Term Creation',
                                'success',
                                sprintf('Created term "%s" in taxonomy "%s"', $source_term['name'], $taxonomy_name)
                            );
                        } else {
                            // Term likely already existed and was updated
                            error_log("Term already existed and was updated: " . json_encode($term_result));
                            $results['updated']++;
                            $this->logger->log_sync(
                                'Term Update',
                                'success',
                                sprintf('Updated term "%s" in taxonomy "%s"', $source_term['name'], $taxonomy_name)
                            );
                        }
                        
                        // Apply metadata if requested
                        if ($sync_metadata) {
                            $meta = $this->api_attributes->get_term_meta_map($source_term);
                            $this->attributes_handler->update_term_metadata($term_id, $meta);
                            error_log("Applied metadata to term: " . json_encode($meta));
                        }
                        
                        $results['processed_list'][] = $selection;
                    }
                } catch (Exception $e) {
                    error_log("Exception processing term: " . $e->getMessage());
                    $results['errors']++;
                    continue;
                }
            }
            
            return $results;
        } catch (Exception $e) {
            error_log("Exception in process_attributes: " . $e->getMessage());
            $this->logger->log_sync('Attribute Processing', 'error', 'Exception during attribute processing: ' . $e->getMessage());
            $results['errors'] = count($selected_terms); // Mark all as errors
            return $results;
        }
    }
} 