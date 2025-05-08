<?php
/**
 * Attributes Processor Class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

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
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'processed_list' => [] // Keep track of what was actually processed
        ];

        if (empty($selected_terms)) {
            return $results; // Nothing selected
        }

        // Fetch fresh source data to ensure we process the correct items
        $source_data = $this->api_attributes->get_attributes_with_terms();
        if (is_wp_error($source_data)) {
            $this->logger->log_sync('Attribute Processing', 'error', 'Could not fetch source attribute data for processing.');
            $results['errors'] = count($selected_terms); // Mark all as errors
            return $results;
        }

        // Create maps for easier lookup
        $source_attrs_map = [];
        foreach ($source_data['attributes'] as $attr) {
             $source_attrs_map[wc_sanitize_taxonomy_name($attr['name'])] = $attr;
        }
        $source_terms_map = $source_data['terms']; // Already mapped by attribute ID

        foreach ($selected_terms as $selection) {
            $attr_slug = wc_sanitize_taxonomy_name($selection['attribute']);
            $term_slug = sanitize_title($selection['term']);
            $taxonomy_name = wc_attribute_taxonomy_name($attr_slug);

            // Find the corresponding source attribute and term
            $source_attr = $source_attrs_map[$attr_slug] ?? null;
            $source_term = null;
            if ($source_attr && isset($source_terms_map[$source_attr['id']])) {
                foreach ($source_terms_map[$source_attr['id']] as $st) {
                    if (sanitize_title($st['slug']) === $term_slug) {
                        $source_term = $st;
                        break;
                    }
                }
            }

            if (!$source_attr || !$source_term) {
                $this->logger->log_sync('Attribute Processing', 'warning', sprintf('Could not find source data for selected term: %s -> %s. Skipping.', $attr_slug, $term_slug));
                $results['skipped']++;
                continue;
            }

            // --- Process Attribute (Create if missing) ---
            $local_attribute = $this->attributes_handler->get_attribute_by_name($attr_slug);
            if (!$local_attribute) {
                $attr_result = $this->attributes_handler->create_attribute($source_attr);
                if (is_wp_error($attr_result)) {
                    $results['errors']++;
                    continue; // Skip term if attribute creation failed
                } else {
                    // Attribute created, no need to increment term count yet
                }
                 // Refresh taxonomy cache after creating attribute
                 delete_transient('wc_attribute_taxonomies');
                 WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
            }

            // --- Process Term (Create or Update) ---
            $local_term = $this->attributes_handler->get_term_by_slug($term_slug, $taxonomy_name);
             if (!$local_term) {
                 // Try finding by name as a fallback (less reliable)
                 $local_term = $this->attributes_handler->get_term_by_name($source_term['name'], $taxonomy_name);
             }

            if (!$local_term) {
                // Create term
                $term_result = $this->attributes_handler->create_term($source_term, $taxonomy_name);
                if (is_wp_error($term_result)) {
                    $results['errors']++;
                } else {
                    $results['created']++;
                     $results['processed_list'][] = $selection; 
                }
            } else {
                 // Check for changes before updating
                 $changes = $this->attributes_handler->get_term_changes($local_term, $source_term);
                 if (!empty($changes)) {
                     // Update term
                     $update_args = [
                         'name' => $source_term['name'],
                         'slug' => $source_term['slug'] // Use source slug
                     ];
                     if (isset($source_term['description'])) {
                         $update_args['description'] = $source_term['description'];
                     }
                     $update_result = wp_update_term($local_term->term_id, $taxonomy_name, $update_args);
 
                     if (is_wp_error($update_result)) {
                         $this->logger->log_sync('Attribute Processing', 'error', sprintf('Failed to update term "%s": %s', $source_term['name'], $update_result->get_error_message()));
                         $results['errors']++;
                     } else {
                         // Update meta if requested
                         if ($sync_metadata) {
                             $meta = $this->api_attributes->get_term_meta_map($source_term);
                             $this->attributes_handler->update_term_metadata($local_term->term_id, $meta);
                         }
                         $results['updated']++;
                          $results['processed_list'][] = $selection; 
                     }
                 } else {
                     $results['skipped']++; // No changes detected
                 }
            }
        }

        return $results;
    }
} 