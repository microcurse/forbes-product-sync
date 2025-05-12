<?php
/**
 * Attributes Comparison Class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Attributes_Comparison
 * Handles comparing local and remote attributes and rendering the comparison table.
 */
class Forbes_Product_Sync_Attributes_Comparison {

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
        if (class_exists('Forbes_Product_Sync_API_Attributes')) {
            $this->api = new Forbes_Product_Sync_API_Attributes();
        }
    }

    /**
     * Compare source (API) attributes with local attributes.
     *
     * @param array $source_data Data from API (containing 'attributes' and 'terms' keys).
     * @return array Comparison results.
     */
    public function compare_attributes($source_data) {
        $results = [
            'attributes' => [],
            'stats' => [
                'attributes' => [
                    'new' => 0,
                    'modified' => 0
                ],
                'terms' => [
                    'new' => 0,
                    'modified' => 0
                ],
                'total_differences' => 0,
            ],
        ];

        if (!isset($source_data['attributes']) || !is_array($source_data['attributes'])) {
            fps_debug('Invalid source data structure in compare_attributes', 'Error');
            return $results;
        }

        $source_attributes = $source_data['attributes'];
        $source_terms_map = isset($source_data['terms']) ? $source_data['terms'] : [];
        $local_attributes = $this->get_local_attributes_map();
        $seen_local_attributes = [];
        $seen_local_terms = [];

        // Process attributes in smaller batches to prevent timeouts with large datasets
        $batch_size = 10;
        $attribute_batches = array_chunk($source_attributes, $batch_size);
        
        fps_debug('Processing comparison in ' . count($attribute_batches) . ' batches', 'Info');
        
        foreach ($attribute_batches as $batch_index => $attribute_batch) {
            fps_debug('Processing batch ' . ($batch_index + 1) . ' of ' . count($attribute_batches), 'Info');
            
            foreach ($attribute_batch as $source_attr) {
                $attr_slug = $this->sanitize_attr_slug($source_attr['name']);
                $taxonomy_name = wc_attribute_taxonomy_name($attr_slug);
                $comparison_data = [
                    'source' => $source_attr,
                    'local' => null,
                    'terms' => [],
                    'status' => 'ok',
                    'taxonomy' => $taxonomy_name
                ];

                if (isset($local_attributes[$attr_slug])) {
                    $comparison_data['local'] = $local_attributes[$attr_slug];
                    $seen_local_attributes[$attr_slug] = true;
                    
                    // Check if attribute properties have changed
                    $local_attr = $local_attributes[$attr_slug];
                    if ($local_attr->attribute_label !== $source_attr['name'] ||
                        $local_attr->attribute_type !== $source_attr['type']) {
                        $comparison_data['status'] = 'modified';
                        $results['stats']['attributes']['modified']++;
                        $results['stats']['total_differences']++;
                    }
                } else {
                    $comparison_data['status'] = 'new';
                    $results['stats']['attributes']['new']++;
                    $results['stats']['total_differences']++;
                }

                // Compare terms for this attribute
                $source_terms = isset($source_terms_map[$source_attr['id']]) ? $source_terms_map[$source_attr['id']] : [];
                $local_terms = isset($local_attributes[$attr_slug]) ? $this->get_local_terms_map($taxonomy_name) : [];

                foreach ($source_terms as $source_term) {
                    $term_comp = $this->compare_term($source_term, $local_terms, $taxonomy_name);
                    
                    if ($term_comp['status'] !== 'ok') {
                        $results['stats']['total_differences']++;
                        if ($term_comp['status'] === 'new') {
                            $results['stats']['terms']['new']++;
                        } elseif ($term_comp['status'] === 'updated') {
                            $results['stats']['terms']['modified']++;
                        }
                    }

                    // Track seen terms
                    if (isset($term_comp['local']) && !empty($term_comp['local']->slug)) {
                        $seen_local_terms[$taxonomy_name][$term_comp['local']->slug] = true;
                    }

                    $comparison_data['terms'][] = $term_comp;
                }
                
                // Check for missing terms - limit to 20 per attribute for performance
                if (!empty($local_terms)) {
                    $missing_terms_count = 0;
                    $missing_terms_limit = 20;
                    
                    foreach ($local_terms as $local_term_slug => $local_term) {
                        if (!isset($seen_local_terms[$taxonomy_name][$local_term_slug])) {
                            if ($missing_terms_count < $missing_terms_limit) {
                                $comparison_data['terms'][] = [
                                    'source' => null,
                                    'local' => $local_term,
                                    'status' => 'missing_source',
                                    'changes' => [],
                                    'meta_changes' => []
                                ];
                            }
                            $missing_terms_count++;
                            $results['stats']['total_differences']++;
                        }
                    }
                    
                    // If we hit the limit, add a placeholder entry
                    if ($missing_terms_count > $missing_terms_limit) {
                        $comparison_data['terms'][] = [
                            'source' => null,
                            'local' => (object)['name' => sprintf('... and %d more missing terms (not shown)', $missing_terms_count - $missing_terms_limit)],
                            'status' => 'missing_source',
                            'changes' => [],
                            'meta_changes' => []
                        ];
                    }
                }
                
                // Limit number of terms displayed for very large attributes to improve performance
                if (count($comparison_data['terms']) > 50) {
                    fps_debug('Limiting terms displayed for large attribute: ' . $source_attr['name'], 'Info');
                    $comparison_data['terms'] = $this->limit_terms_for_display($comparison_data['terms']);
                }

                $results['attributes'][] = $comparison_data;
            }
        }
        
        // Add missing local attributes
        $results = $this->add_missing_local_attributes($results, $local_attributes, $seen_local_attributes);
        
        // Sort attributes by status and name
        $results['attributes'] = $this->sort_attributes($results['attributes']);

        return $results;
    }

    /**
     * Sanitize attribute slug
     *
     * @param string $name Attribute name
     * @return string Sanitized slug
     */
    private function sanitize_attr_slug($name) {
        return wc_sanitize_taxonomy_name($name);
    }

    /**
     * Compare a single term between source and local
     *
     * @param array $source_term Source term data
     * @param array $local_terms Local terms map
     * @param string $taxonomy Taxonomy name
     * @return array Term comparison data
     */
    private function compare_term($source_term, $local_terms, $taxonomy) {
        $term_slug = sanitize_title($source_term['slug']);
        $term_comparison = [
            'source' => $source_term,
            'local' => null,
            'status' => 'ok',
            'changes' => [],
            'meta_changes' => []
        ];

        if (isset($local_terms[$term_slug])) {
            $local_term = $local_terms[$term_slug];
            $term_comparison['local'] = $local_term;

            // Check for differences in name, slug, description
            if (strtolower($local_term->name) !== strtolower($source_term['name'])) {
                $term_comparison['status'] = 'updated';
                $term_comparison['changes']['name'] = ['old' => $local_term->name, 'new' => $source_term['name']];
            }
            
            if ($local_term->slug !== $source_term['slug']) {
                if ($term_comparison['status'] === 'ok') $term_comparison['status'] = 'updated';
                $term_comparison['changes']['slug'] = ['old' => $local_term->slug, 'new' => $source_term['slug']];
            }
            
            if ($local_term->description !== $source_term['description']) {
                if ($term_comparison['status'] === 'ok') $term_comparison['status'] = 'updated';
                $term_comparison['changes']['description'] = ['old' => $local_term->description, 'new' => $source_term['description']];
            }
            
            // Check for meta differences - only if we already have differences or for a sample of terms
            // This helps improve performance by not checking metadata for every term
            if ($term_comparison['status'] !== 'ok' || mt_rand(1, 10) === 1) {
                if ($this->api) {
                    $source_meta = $this->api->get_term_meta_map($source_term);
                    foreach ($source_meta as $key => $value) {
                        $local_value = get_term_meta($local_term->term_id, $key, true);
                        if ($local_value != $value) { // Use loose comparison for flexibility
                            if ($term_comparison['status'] === 'ok') $term_comparison['status'] = 'updated';
                            $term_comparison['meta_changes'][$key] = ['old' => $local_value, 'new' => $value];
                        }
                    }
                }
            }
        } else {
            $term_comparison['status'] = 'new';
        }

        return $term_comparison;
    }

    /**
     * Sort and limit terms for display
     *
     * @param array $terms Terms to process
     * @return array Processed terms
     */
    private function limit_terms_for_display($terms) {
        usort($terms, function($a, $b) {
            // Sort by status - new and updated first
            $statusOrder = [
                'new' => 0,
                'updated' => 1, 
                'missing_source' => 2,
                'ok' => 3
            ];
            
            $statusA = isset($statusOrder[$a['status']]) ? $statusOrder[$a['status']] : 4;
            $statusB = isset($statusOrder[$b['status']]) ? $statusOrder[$b['status']] : 4;
            
            if ($statusA !== $statusB) {
                return $statusA - $statusB;
            }
            
            // Then sort by name
            $nameA = isset($a['source']['name']) ? strtolower($a['source']['name']) : (isset($a['local']->name) ? strtolower($a['local']->name) : 'zzz');
            $nameB = isset($b['source']['name']) ? strtolower($b['source']['name']) : (isset($b['local']->name) ? strtolower($b['local']->name) : 'zzz');
            return strcmp($nameA, $nameB);
        });
        
        // Keep count of total terms for reference
        $total_terms = count($terms);
        
        // Limit to 50 terms
        $limited_terms = array_slice($terms, 0, 50);
        
        // Add a note if terms were trimmed
        if ($total_terms > 50) {
            $limited_terms[] = [
                'source' => (object)['name' => sprintf('... plus %d more terms (not shown)', $total_terms - 50)],
                'local' => null,
                'status' => 'note',
                'changes' => [],
                'meta_changes' => []
            ];
        }

        return $limited_terms;
    }

    /**
     * Add missing local attributes to results
     *
     * @param array $results Current results
     * @param array $local_attributes Local attributes map
     * @param array $seen_local_attributes Seen local attributes
     * @return array Updated results
     */
    private function add_missing_local_attributes($results, $local_attributes, $seen_local_attributes) {
        // Check for local attributes missing in source - limit to 20 for performance
        $missing_attr_count = 0;
        $missing_attr_limit = 20;
        
        foreach ($local_attributes as $local_attr_slug => $local_attr) {
            if (!isset($seen_local_attributes[$local_attr_slug])) {
                if ($missing_attr_count < $missing_attr_limit) {
                    $taxonomy_name = wc_attribute_taxonomy_name($local_attr_slug);
                    $results['attributes'][] = [
                        'source' => null,
                        'local' => $local_attr,
                        'terms' => [],
                        'status' => 'missing_source',
                        'taxonomy' => $taxonomy_name
                    ];
                }
                $missing_attr_count++;
                $results['stats']['total_differences']++;
            }
        }
        
        // If we hit the limit, add a note
        if ($missing_attr_count > $missing_attr_limit) {
            $results['attributes'][] = [
                'source' => null,
                'local' => (object)['attribute_label' => sprintf('... and %d more missing attributes (not shown)', $missing_attr_count - $missing_attr_limit)],
                'terms' => [],
                'status' => 'missing_source',
                'taxonomy' => ''
            ];
        }

        return $results;
    }

    /**
     * Sort attributes by status and name
     *
     * @param array $attributes Attributes to sort
     * @return array Sorted attributes
     */
    private function sort_attributes($attributes) {
        usort($attributes, function($a, $b) {
            // Sort by status first - new and modified attributes first
            $statusOrder = [
                'new' => 0,
                'modified' => 1,
                'missing_source' => 2,
                'ok' => 3
            ];
            
            $statusA = isset($statusOrder[$a['status']]) ? $statusOrder[$a['status']] : 4;
            $statusB = isset($statusOrder[$b['status']]) ? $statusOrder[$b['status']] : 4;
            
            if ($statusA !== $statusB) {
                return $statusA - $statusB;
            }
            
            // Then sort by name
            $nameA = isset($a['source']['name']) ? strtolower($a['source']['name']) : (isset($a['local']->attribute_label) ? strtolower($a['local']->attribute_label) : 'zzz');
            $nameB = isset($b['source']['name']) ? strtolower($b['source']['name']) : (isset($b['local']->attribute_label) ? strtolower($b['local']->attribute_label) : 'zzz');
            return strcmp($nameA, $nameB);
        });

        return $attributes;
    }

    /**
     * Get local attributes mapped by slug.
     *
     * @return array [slug => taxonomy_object]
     */
    private function get_local_attributes_map() {
        $map = [];
        $attributes = wc_get_attribute_taxonomies();
        if ($attributes) {
            foreach ($attributes as $attribute) {
                if (isset($attribute->attribute_name)) {
                    $map[wc_sanitize_taxonomy_name($attribute->attribute_name)] = $attribute;
                }
            }
        }
        return $map;
    }

    /**
     * Get local terms for a taxonomy mapped by slug.
     *
     * @param string $taxonomy Taxonomy name.
     * @return array [slug => term_object]
     */
    private function get_local_terms_map($taxonomy) {
        $map = [];
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ]);
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $map[$term->slug] = $term;
            }
        }
        return $map;
    }

    /**
     * Render comparison table for attributes.
     *
     * @param array $comparison_results Results from compare_attributes().
     * @return string HTML output.
     */
    public function render_comparison_table($comparison_results) {
        if (!isset($comparison_results['attributes']) || !is_array($comparison_results['attributes'])) {
            return '<div class="notice notice-error"><p>' . __('Invalid comparison data.', 'forbes-product-sync') . '</p></div>';
        }

        if (!$this->api) {
            $this->api = new Forbes_Product_Sync_API_Attributes();
        }

        $attributes_data = $comparison_results['attributes'];
        
        ob_start();
        ?>
        <table class="widefat striped attribute-comparison-table">
            <thead>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" id="select-all-attributes" title="<?php esc_attr_e('Select All', 'forbes-product-sync'); ?>" />
                    </th>
                    <th><?php esc_html_e('Attribute', 'forbes-product-sync'); ?></th>
                    <th><?php esc_html_e('Term', 'forbes-product-sync'); ?></th>
                    <th><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                    <th><?php esc_html_e('Changes', 'forbes-product-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attributes_data)): ?>
                    <tr><td colspan="5"><?php esc_html_e('No attributes found in source or locally.', 'forbes-product-sync'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($attributes_data as $attr_index => $attr_comp): ?>
                        <?php 
                        // Safely get attribute properties
                        $attr_name = $this->get_attribute_name($attr_comp);
                        $attr_slug = $this->get_attribute_slug($attr_comp);
                        
                        $is_new_attr = $attr_comp['status'] === 'new';
                        $is_missing_source_attr = $attr_comp['status'] === 'missing_source';
                        $has_terms = !empty($attr_comp['terms']);
                        
                        // Determine if this attribute has selectable terms
                        $selectable_terms = array_filter($attr_comp['terms'] ?? [], function($term) {
                            return isset($term['status']) && ($term['status'] === 'new' || $term['status'] === 'updated');
                        });
                        $has_selectable_terms = !empty($selectable_terms);
                        ?>
                        
                        <!-- Attribute header -->
                        <tr class="attribute-group-header" data-attr-id="<?php echo esc_attr($attr_index); ?>" data-attr-slug="<?php echo esc_attr($attr_slug); ?>">
                            <td class="check-column">
                                <?php if ($has_terms): ?>
                                    <span class="attribute-toggle dashicons dashicons-arrow-down-alt2"></span>
                                    <?php if ($has_selectable_terms): ?>
                                        <input type="checkbox" class="select-attribute-terms" 
                                               data-attr-id="<?php echo esc_attr($attr_index); ?>" 
                                               data-attr-slug="<?php echo esc_attr($attr_slug); ?>" 
                                               title="<?php esc_attr_e('Select all terms in this attribute', 'forbes-product-sync'); ?>" />
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($attr_name); ?></strong>
                                <div class="attribute-slug">Attribute Slug: <?php echo esc_html($attr_slug); ?></div>
                                <?php if ($is_new_attr): ?>
                                    <div class="item-status-notice status-new"><?php esc_html_e('New Attribute', 'forbes-product-sync'); ?></div>
                                <?php elseif ($is_missing_source_attr): ?>
                                    <div class="item-status-notice status-local-only"><?php esc_html_e('Local Attribute Only', 'forbes-product-sync'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td colspan="3">
                                <?php 
                                if ($has_terms) {
                                    printf(esc_html__('%d terms found', 'forbes-product-sync'), count($attr_comp['terms']));
                                    
                                    if ($has_selectable_terms) {
                                        echo ' <span class="term-selection-hint">' . esc_html__('(Use checkbox to select all terms)', 'forbes-product-sync') . '</span>';
                                    }
                                } else {
                                    echo esc_html__('No terms', 'forbes-product-sync');
                                }
                                ?>
                            </td>
                        </tr>
                        
                        <!-- Terms rows -->
                        <?php if ($has_terms): ?>
                            <?php foreach ($attr_comp['terms'] as $term_index => $term_comp): ?>
                                <?php 
                                $term_name = $this->get_term_name($term_comp);
                                $term_slug = $this->get_term_slug($term_comp);
                                $can_select = (isset($term_comp['status']) && ($term_comp['status'] === 'new' || $term_comp['status'] === 'updated'));
                                ?>
                                <tr class="attribute-group-term" data-attr-id="<?php echo esc_attr($attr_index); ?>" data-term="<?php echo esc_attr($term_slug); ?>">
                                    <td class="check-column">
                                        <?php if ($can_select): ?>
                                        <input type="checkbox" class="sync-term-checkbox" 
                                               data-attr="<?php echo esc_attr($attr_slug); ?>" 
                                               data-term="<?php echo esc_attr($term_slug); ?>"
                                               data-term-name="<?php echo esc_attr($term_name); ?>"
                                               data-attr-name="<?php echo esc_attr($attr_name); ?>"
                                               data-attr-id="<?php echo esc_attr($attr_index); ?>" />
                                        <?php endif; ?>
                                    </td>
                                    <td class="term-name-cell">&nbsp;</td>
                                    <td class="term-name">
                                        <?php echo esc_html($term_name); ?>
                                        <div class="term-slug-info"><strong>Slug:</strong> <?php echo esc_html($term_slug); ?></div>
                                    </td>
                                    <td class="status">
                                        <?php echo $this->get_term_status_badge($term_comp); ?>
                                    </td>
                                    <td class="changes">
                                        <?php echo $this->get_term_changes_html($term_comp); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Get attribute name safely
     *
     * @param array $attr_comp Attribute comparison data
     * @return string Attribute name
     */
    private function get_attribute_name($attr_comp) {
        if (isset($attr_comp['source']['name'])) {
            return $attr_comp['source']['name'];
        } elseif (isset($attr_comp['local']) && is_object($attr_comp['local']) && isset($attr_comp['local']->attribute_label)) {
            return $attr_comp['local']->attribute_label;
        }
        return __('Unknown Attribute', 'forbes-product-sync');
    }

    /**
     * Get attribute slug safely
     *
     * @param array $attr_comp Attribute comparison data
     * @return string Attribute slug
     */
    private function get_attribute_slug($attr_comp) {
        if (isset($attr_comp['source']['slug'])) {
            return $attr_comp['source']['slug'];
        } elseif (isset($attr_comp['local']) && is_object($attr_comp['local']) && isset($attr_comp['local']->attribute_name)) {
            return $attr_comp['local']->attribute_name;
        }
        return 'unknown';
    }

    /**
     * Get term name safely
     *
     * @param array $term_comp Term comparison data
     * @return string Term name
     */
    private function get_term_name($term_comp) {
        if (isset($term_comp['source']['name'])) {
            return $term_comp['source']['name'];
        } elseif (isset($term_comp['local']) && is_object($term_comp['local']) && isset($term_comp['local']->name)) {
            return $term_comp['local']->name;
        }
        return __('Unknown Term', 'forbes-product-sync');
    }

    /**
     * Get term slug safely
     *
     * @param array $term_comp Term comparison data
     * @return string Term slug
     */
    private function get_term_slug($term_comp) {
        if (isset($term_comp['source']['slug'])) {
            return $term_comp['source']['slug'];
        } elseif (isset($term_comp['local']) && is_object($term_comp['local']) && isset($term_comp['local']->slug)) {
            return $term_comp['local']->slug;
        }
        return 'unknown';
    }

    /**
     * Get term status badge HTML
     *
     * @param array $term_comp Term comparison data
     * @return string HTML badge
     */
    private function get_term_status_badge($term_comp) {
        if (!isset($term_comp['status'])) {
            return '<span class="status-badge status-none">' . esc_html__('Unknown', 'forbes-product-sync') . '</span>';
        }
        
        switch ($term_comp['status']) {
            case 'new':
                return '<span class="status-badge status-success">' . esc_html__('New Term', 'forbes-product-sync') . '</span>';
            case 'updated':
                return '<span class="status-badge status-warning">' . esc_html__('Term Updated', 'forbes-product-sync') . '</span>';
            case 'missing_source':
                return '<span class="status-badge status-info">' . esc_html__('Local Term Only', 'forbes-product-sync') . '</span>';
            case 'note':
                return '<span class="status-badge status-none">' . esc_html__('Note', 'forbes-product-sync') . '</span>';
            default:
                return '<span class="status-badge status-ok">' . esc_html__('Term OK', 'forbes-product-sync') . '</span>';
        }
    }

    /**
     * Get term changes HTML
     *
     * @param array $term_comp Term comparison data
     * @return string HTML for changes
     */
    private function get_term_changes_html($term_comp) {
        $change_details = [];
        
        // Process source meta if we have source data
        $source_meta = [];
        if (isset($term_comp['source']) && is_array($term_comp['source']) && $this->api) {
            $source_meta = $this->api->get_term_meta_map($term_comp['source']);
        }
        
        // Process local meta if we have local data
        $local_meta = [
            'term_suffix' => '',
            'swatch_image' => ''
        ];
        
        if (isset($term_comp['local']) && is_object($term_comp['local']) && isset($term_comp['local']->term_id)) {
            $local_meta['term_suffix'] = get_term_meta($term_comp['local']->term_id, 'term_suffix', true);
            $local_meta['swatch_image'] = get_term_meta($term_comp['local']->term_id, 'swatch_image', true);
        }
        
        if (isset($term_comp['status']) && $term_comp['status'] === 'new') {
            $change_details[] = '<strong>' . esc_html__('Term will be created', 'forbes-product-sync') . '</strong>';
            
            // For new terms, show the source metadata that will be created
            if (!empty($source_meta['term_suffix'])) {
                $change_details[] = sprintf('<strong>Suffix:</strong> "%s"', esc_html($source_meta['term_suffix']));
            }
            
            if (!empty($source_meta['swatch_image'])) {
                $change_details[] = sprintf(
                    '<strong>Swatch:</strong> <img src="%1$s" alt="swatch" class="swatch-image"/> <a href="%1$s" target="_blank">View</a>', 
                    esc_url($source_meta['swatch_image'])
                );
            }
            
        } elseif (isset($term_comp['status']) && $term_comp['status'] === 'missing_source') {
            $change_details[] = '<strong>' . esc_html__('Term only exists locally', 'forbes-product-sync') . '</strong>';
            
            // For local-only terms, show the current local metadata
            if (!empty($local_meta['term_suffix'])) {
                $change_details[] = sprintf('<strong>Current Suffix:</strong> "%s"', esc_html($local_meta['term_suffix']));
            }
            
            if (!empty($local_meta['swatch_image'])) {
                $change_details[] = sprintf(
                    '<strong>Current Swatch:</strong> <img src="%1$s" alt="swatch" class="swatch-image"/> <a href="%1$s" target="_blank">View</a>', 
                    esc_url($local_meta['swatch_image'])
                );
            }
        } elseif (isset($term_comp['status']) && $term_comp['status'] === 'note') {
            // For note type entries, just use the name as the message
            if (isset($term_comp['source']) && isset($term_comp['source']->name)) {
                $change_details[] = esc_html($term_comp['source']->name);
            }
        } else { // For 'updated' or 'ok' status
            $meta_fields_displayed = [];
            
            // For updated terms, first show the field changes
            if (!empty($term_comp['changes'])) {
                $change_details[] = '<strong>' . esc_html__('Field Changes:', 'forbes-product-sync') . '</strong>';
                $field_changes = [];
                
                foreach ($term_comp['changes'] as $field => $diff) {
                    if (isset($diff['old']) && isset($diff['new'])) {
                        $field_changes[] = sprintf(
                            '<strong>%s:</strong> "%s" &rarr; "%s"', 
                            ucfirst($field),
                            esc_html($diff['old']), 
                            esc_html($diff['new'])
                        );
                    }
                }
                
                if (!empty($field_changes)) {
                    $change_details[] = '<ul class="term-meta-list"><li>' . implode('</li><li>', $field_changes) . '</li></ul>';
                }
            }
            
            // Then show metadata changes
            if (!empty($term_comp['meta_changes'])) {
                $change_details[] = '<strong>' . esc_html__('Metadata Changes:', 'forbes-product-sync') . '</strong>';
                $meta_changes = [];
                
                foreach ($term_comp['meta_changes'] as $key => $diff) {
                    if (isset($diff['old']) && isset($diff['new'])) {
                        $meta_fields_displayed[] = $key;
                        $display_key = str_replace('_', ' ', ucfirst($key));
                        
                        if ($key === 'swatch_image') {
                            $old_val_display = !empty($diff['old']) 
                                ? sprintf('<img src="%1$s" alt="old swatch" class="swatch-image"/> <a href="%1$s" target="_blank">View</a>', esc_url($diff['old']))
                                : '<em>none</em>';
                            
                            $new_val_display = !empty($diff['new']) 
                                ? sprintf('<img src="%1$s" alt="new swatch" class="swatch-image"/> <a href="%1$s" target="_blank">View</a>', esc_url($diff['new']))
                                : '<em>none</em>';
                            
                            $meta_changes[] = sprintf('<strong>%s:</strong> %s &rarr; %s', esc_html($display_key), $old_val_display, $new_val_display);
                        } else {
                            $meta_changes[] = sprintf('<strong>%s:</strong> "%s" &rarr; "%s"', esc_html($display_key), esc_html($diff['old']), esc_html($diff['new']));
                        }
                    }
                }
                
                if (!empty($meta_changes)) {
                    $change_details[] = '<ul class="term-meta-list"><li>' . implode('</li><li>', $meta_changes) . '</li></ul>';
                }
            }
        }
        
        if (empty($change_details)) {
            return '-';
        } else {
            return implode('<br>', $change_details);
        }
    }

    /**
     * Calculate statistics from comparison results.
     *
     * @param array $comparison_results Results from compare_attributes().
     * @return array Statistics.
     */
    public function get_stats($comparison_results) {
        // Prepare stats structure
        $stats = [
            'attributes' => [
                'new' => 0,
                'modified' => 0
            ],
            'terms' => [
                'new' => 0,
                'modified' => 0
            ],
            'total_differences' => 0
        ];
        
        // If the comparison results already include calculated stats, return those
        if (isset($comparison_results['stats'])) {
            return $comparison_results['stats'];
        }
        
        // Otherwise calculate stats from the attribute data
        if (!empty($comparison_results['attributes']) && is_array($comparison_results['attributes'])) {
            foreach ($comparison_results['attributes'] as $attr_comp) {
                if (isset($attr_comp['status'])) {
                    if ($attr_comp['status'] === 'new') {
                        $stats['attributes']['new']++;
                        $stats['total_differences']++;
                    } elseif ($attr_comp['status'] === 'modified') {
                        $stats['attributes']['modified']++;
                        $stats['total_differences']++;
                    }
                }
                
                if (!empty($attr_comp['terms']) && is_array($attr_comp['terms'])) {
                    foreach ($attr_comp['terms'] as $term_comp) {
                        if (isset($term_comp['status'])) {
                            if ($term_comp['status'] === 'new') {
                                $stats['terms']['new']++;
                                $stats['total_differences']++;
                            } elseif ($term_comp['status'] === 'updated') {
                                $stats['terms']['modified']++;
                                $stats['total_differences']++;
                            }
                        }
                    }
                }
            }
        }
        
        return $stats;
    }
} 