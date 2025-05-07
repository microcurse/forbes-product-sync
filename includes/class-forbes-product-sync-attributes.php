<?php
/**
 * Attribute synchronization handler
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forbes_Product_Sync_Attributes {
    /**
     * Logger instance
     *
     * @var Forbes_Product_Sync_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Forbes_Product_Sync_Logger();
    }

    /**
     * Get attribute by name
     *
     * @param string $name
     * @return array|false
     */
    private function get_attribute_by_name($name) {
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
     * @param string $slug
     * @param string $taxonomy
     * @return WP_Term|false
     */
    private function get_term_by_slug($slug, $taxonomy) {
        return get_term_by('slug', $slug, $taxonomy);
    }

    /**
     * Get term by name
     *
     * @param string $name
     * @param string $taxonomy
     * @return WP_Term|false
     */
    private function get_term_by_name($name, $taxonomy) {
        // Try to find a term with an exact name match first
        $term = get_term_by('name', $name, $taxonomy);
        if ($term) {
            return $term;
        }
        
        // If not found by exact name, try case-insensitive comparison
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ]);
        
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
     * @param array $attribute_data
     * @return int|WP_Error
     */
    private function create_attribute($attribute_data) {
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
     * @param array $term_data
     * @param string $taxonomy
     * @return array|WP_Error
     */
    private function create_term($term_data, $taxonomy) {
        $term = wp_insert_term($term_data['name'], $taxonomy, array(
            'slug' => $term_data['slug']
        ));

        if (is_wp_error($term)) {
            $this->logger->log_sync(
                'Term Creation',
                'error',
                sprintf('Failed to create term "%s": %s', $term_data['name'], $term->get_error_message())
            );
            return $term;
        }

        // Set term metadata
        $this->update_term_metadata($term['term_id'], array(
            'swatch_image' => $term_data['swatch_image'] ?? '',
            'term_suffix' => $term_data['suffix'] ?? '',
            'price_adjustment' => $term_data['price_adjustment'] ?? ''
        ));

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
     * @param int $term_id
     * @param array $metadata
     */
    private function update_term_metadata($term_id, $metadata) {
        foreach ($metadata as $key => $value) {
            update_term_meta($term_id, $key, $value);
        }
    }

    /**
     * Sync attributes (global attribute taxonomy and terms)
     *
     * @param array $source_attributes
     * @return array
     */
    public function sync_attributes($source_attributes) {
        $results = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'new_attributes' => [],
            'updated_attributes' => [],
            'new_terms' => [],
            'updated_terms' => [],
            'missing_attributes' => [],
            'missing_terms' => [],
        );

        // Build local attribute map
        $local_attributes = [];
        foreach (wc_get_attribute_taxonomies() as $attr) {
            $local_attributes[$attr->attribute_name] = $attr;
        }

        // Track which local attributes/terms are seen in source
        $seen_local_attributes = [];
        $seen_local_terms = [];

        foreach ($source_attributes as $attribute) {
            $attr_name = $attribute['name'];
            $attr_slug = wc_sanitize_taxonomy_name($attr_name);
            $existing_attribute = isset($local_attributes[$attr_slug]) ? $local_attributes[$attr_slug] : false;

            if (!$existing_attribute) {
                $results['created']++;
                $results['new_attributes'][] = $attr_name;
                $this->logger->log_sync('Attribute Sync', 'info', sprintf('Would create new attribute: %s', $attr_name));
                $this->create_attribute($attribute);
            } else {
                $seen_local_attributes[$attr_slug] = true;
                // Compare attribute properties (type, order_by, etc.)
                $attr_changes = [];
                if ($existing_attribute->attribute_type !== $attribute['type']) {
                    $attr_changes['type'] = [$existing_attribute->attribute_type, $attribute['type']];
                }
                if ($existing_attribute->attribute_orderby !== $attribute['order_by']) {
                    $attr_changes['order_by'] = [$existing_attribute->attribute_orderby, $attribute['order_by']];
                }
                if (!empty($attr_changes)) {
                    $results['updated']++;
                    $results['updated_attributes'][] = ['name' => $attr_name, 'changes' => $attr_changes];
                    $this->logger->log_sync('Attribute Sync', 'info', sprintf('Would update attribute: %s', $attr_name), $attr_changes);
                }
            }

            // Terms
            $taxonomy = wc_attribute_taxonomy_name($attr_slug);
            $local_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            $local_terms_map = [];
            $local_terms_by_name = [];
            
            foreach ($local_terms as $term) {
                $local_terms_map[$term->slug] = $term;
                $local_terms_by_name[strtolower($term->name)] = $term;
            }
            
            foreach ($attribute['terms'] as $term) {
                // Try to find the term by name first, then fall back to slug
                $term_name = $term['name'];
                $term_slug = $term['slug'];
                $existing_term = isset($local_terms_by_name[strtolower($term_name)]) 
                    ? $local_terms_by_name[strtolower($term_name)] 
                    : (isset($local_terms_map[$term_slug]) ? $local_terms_map[$term_slug] : false);
                
                if (!$existing_term) {
                    $results['created']++;
                    $results['new_terms'][] = ['attribute' => $attr_name, 'term' => $term['name']];
                    $this->logger->log_sync('Term Sync', 'info', sprintf('Would create new term: %s (%s)', $term['name'], $attr_name));
                    $this->create_term($term, $taxonomy);
                } else {
                    $seen_local_terms[$taxonomy][$existing_term->slug] = true;
                    // Compare term meta (suffix, swatch_image, price_adjustment)
                    $term_changes = $this->get_term_changes($existing_term, $term);
                    if (!empty($term_changes)) {
                        $results['updated']++;
                        $results['updated_terms'][] = ['attribute' => $attr_name, 'term' => $term['name'], 'changes' => $term_changes];
                        $this->logger->log_sync('Term Sync', 'info', sprintf('Would update term: %s (%s)', $term['name'], $attr_name), $term_changes);
                        $this->update_term_metadata($existing_term->term_id, $term_changes);
                    }
                }
            }
            // Optionally: find missing terms (local but not in source)
            foreach ($local_terms_map as $slug => $term_obj) {
                $found = false;
                foreach ($attribute['terms'] as $term) {
                    if (strtolower($term['name']) === strtolower($term_obj->name) || $term['slug'] === $slug) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $results['missing_terms'][] = ['attribute' => $attr_name, 'term' => $term_obj->name];
                }
            }
        }
        // Optionally: find missing attributes (local but not in source)
        foreach ($local_attributes as $attr_slug => $attr_obj) {
            if (empty($seen_local_attributes[$attr_slug])) {
                $results['missing_attributes'][] = $attr_obj->attribute_label;
            }
        }
        return $results;
    }

    /**
     * Get term changes
     *
     * @param WP_Term $existing_term
     * @param array $source_term
     * @return array
     */
    private function get_term_changes($existing_term, $source_term) {
        $changes = array();

        // Check image swatch
        $current_swatch = get_term_meta($existing_term->term_id, 'swatch_image', true);
        if ($current_swatch !== ($source_term['swatch_image'] ?? '')) {
            $changes['swatch_image'] = $source_term['swatch_image'] ?? '';
        }

        // Check suffix
        $current_suffix = get_term_meta($existing_term->term_id, 'term_suffix', true);
        if ($current_suffix !== ($source_term['suffix'] ?? '')) {
            $changes['term_suffix'] = $source_term['suffix'] ?? '';
        }

        // Check price adjustment
        $current_price = get_term_meta($existing_term->term_id, 'price_adjustment', true);
        if ($current_price !== ($source_term['price_adjustment'] ?? '')) {
            $changes['price_adjustment'] = $source_term['price_adjustment'] ?? '';
        }

        return $changes;
    }
} 