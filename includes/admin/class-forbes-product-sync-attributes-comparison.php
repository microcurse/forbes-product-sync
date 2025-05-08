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
     * Compare source (API) attributes with local attributes.
     *
     * @param array $source_data Data from API (containing 'attributes' and 'terms' keys).
     * @return array Comparison results.
     */
    public function compare_attributes($source_data) {
        $results = [
            'attributes' => [],
            'stats' => [
                'new_attributes' => 0,
                'missing_attributes' => 0, // Attributes present locally but not in source
                'new_terms' => 0,
                'updated_terms' => 0,
                'missing_terms' => 0, // Terms present locally but not in source
                'conflicts' => 0,
                'total_differences' => 0,
            ],
        ];

        $source_attributes = isset($source_data['attributes']) ? $source_data['attributes'] : [];
        $source_terms_map = isset($source_data['terms']) ? $source_data['terms'] : [];
        $local_attributes = $this->get_local_attributes_map();
        $seen_local_attributes = [];
        $seen_local_terms = [];

        $api = new Forbes_Product_Sync_API_Attributes(); // Needed for get_term_meta_map

        foreach ($source_attributes as $source_attr) {
            $attr_slug = wc_sanitize_taxonomy_name($source_attr['name']);
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
            } else {
                $comparison_data['status'] = 'new';
                $results['stats']['new_attributes']++;
                $results['stats']['total_differences']++;
            }

            // Compare terms for this attribute
            $source_terms = isset($source_terms_map[$source_attr['id']]) ? $source_terms_map[$source_attr['id']] : [];
            $local_terms = isset($local_attributes[$attr_slug]) ? $this->get_local_terms_map($taxonomy_name) : [];

            foreach ($source_terms as $source_term) {
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
                    $seen_local_terms[$taxonomy_name][$term_slug] = true;

                    // Check for differences (name, slug, description)
                    if (strtolower($local_term->name) !== strtolower($source_term['name'])) {
                        $term_comparison['status'] = 'updated';
                        $term_comparison['changes']['name'] = ['old' => $local_term->name, 'new' => $source_term['name']];
                    }
                    // Note: We generally shouldn't update slugs if they exist, but we can flag it.
                    if ($local_term->slug !== $source_term['slug']) {
                         if ($term_comparison['status'] === 'ok') $term_comparison['status'] = 'updated'; // Mark as updated if not already
                         $term_comparison['changes']['slug'] = ['old' => $local_term->slug, 'new' => $source_term['slug']];
                    }
                     if ($local_term->description !== $source_term['description']) {
                         if ($term_comparison['status'] === 'ok') $term_comparison['status'] = 'updated';
                         $term_comparison['changes']['description'] = ['old' => $local_term->description, 'new' => $source_term['description']];
                    }
                    
                    // Check for meta differences
                    $source_meta = $api->get_term_meta_map($source_term);
                    foreach ($source_meta as $key => $value) {
                        $local_value = get_term_meta($local_term->term_id, $key, true);
                        if ($local_value != $value) { // Use loose comparison for flexibility
                             if ($term_comparison['status'] === 'ok') $term_comparison['status'] = 'updated';
                             $term_comparison['meta_changes'][$key] = ['old' => $local_value, 'new' => $value];
                        }
                    }

                } else {
                    $term_comparison['status'] = 'new';
                    $results['stats']['new_terms']++;
                }
                
                if($term_comparison['status'] !== 'ok') {
                    $results['stats']['total_differences']++;
                    if($term_comparison['status'] === 'updated') {
                         $results['stats']['updated_terms']++;
                    }
                }

                $comparison_data['terms'][] = $term_comparison;
            }
            
            // Check for local terms missing in source
            foreach ($local_terms as $local_term_slug => $local_term) {
                if (!isset($seen_local_terms[$taxonomy_name][$local_term_slug])) {
                    $comparison_data['terms'][] = [
                        'source' => null,
                        'local' => $local_term,
                        'status' => 'missing_source', // Local term exists, but not in source data
                        'changes' => [],
                        'meta_changes' => []
                    ];
                    $results['stats']['missing_terms']++;
                    $results['stats']['total_differences']++;
                }
            }
            
            // Sort terms alphabetically by name for consistent display
            usort($comparison_data['terms'], function ($a, $b) {
                $nameA = isset($a['source']['name']) ? strtolower($a['source']['name']) : (isset($a['local']->name) ? strtolower($a['local']->name) : 'zzz');
                $nameB = isset($b['source']['name']) ? strtolower($b['source']['name']) : (isset($b['local']->name) ? strtolower($b['local']->name) : 'zzz');
                return strcmp($nameA, $nameB);
            });

            $results['attributes'][] = $comparison_data;
        }
        
         // Check for local attributes missing in source
        foreach ($local_attributes as $local_attr_slug => $local_attr) {
             if (!isset($seen_local_attributes[$local_attr_slug])) {
                 $taxonomy_name = wc_attribute_taxonomy_name($local_attr_slug);
                 $results['attributes'][] = [
                     'source' => null,
                     'local' => $local_attr,
                     'terms' => [], // Or fetch local terms if needed
                     'status' => 'missing_source',
                     'taxonomy' => $taxonomy_name
                 ];
                 $results['stats']['missing_attributes']++;
                 $results['stats']['total_differences']++;
             }
         }
         
         // Sort attributes alphabetically
         usort($results['attributes'], function($a, $b) {
             $nameA = isset($a['source']['name']) ? strtolower($a['source']['name']) : (isset($a['local']->attribute_label) ? strtolower($a['local']->attribute_label) : 'zzz');
             $nameB = isset($b['source']['name']) ? strtolower($b['source']['name']) : (isset($b['local']->attribute_label) ? strtolower($b['local']->attribute_label) : 'zzz');
             return strcmp($nameA, $nameB);
         });

        return $results;
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
                $map[wc_sanitize_taxonomy_name($attribute->attribute_name)] = $attribute;
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
            'hide_empty' => false,
        ]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $map[$term->slug] = $term;
            }
        }
        return $map;
    }

    /**
     * Render the comparison results as an HTML table.
     *
     * @param array $comparison_results Results from compare_attributes().
     * @return string HTML table.
     */
    public function render_comparison_table($comparison_results) {
        $attributes_data = $comparison_results['attributes'];
        $stats = $this->get_stats($comparison_results); // Use get_stats to ensure consistency
        
        // Initialize API instance once
        $api = new Forbes_Product_Sync_API_Attributes();

        if ($stats['total_differences'] === 0) {
            return '<div class="notice notice-success inline"><p>' . esc_html__('Local attributes match the source. No synchronization needed.', 'forbes-product-sync') . '</p></div>';
        }

        ob_start();
        ?>
        <p><?php printf(esc_html__('Found %d differences. Review the changes below and select terms to sync.', 'forbes-product-sync'), $stats['total_differences']); ?></p>
        <table class="wp-list-table widefat fixed striped attribute-comparison-table">
            <thead>
                <tr>
                    <th class="check-column"></th>
                    <th><?php esc_html_e('Attribute', 'forbes-product-sync'); ?></th>
                    <th><?php esc_html_e('Term', 'forbes-product-sync'); ?></th>
                    <th><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                    <th><?php esc_html_e('Details & Changes', 'forbes-product-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attributes_data)): ?>
                    <tr><td colspan="5"><?php esc_html_e('No attributes found in source or locally.', 'forbes-product-sync'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($attributes_data as $attr_index => $attr_comp): ?>
                        <?php 
                        $attr_name = isset($attr_comp['source']['name']) ? $attr_comp['source']['name'] : ($attr_comp['local'] ? $attr_comp['local']->attribute_label : 'N/A');
                        $attr_slug = isset($attr_comp['source']['slug']) ? $attr_comp['source']['slug'] : ($attr_comp['local'] ? $attr_comp['local']->attribute_name : 'N/A');
                        $rowspan = max(1, count($attr_comp['terms']));
                        $is_new_attr = $attr_comp['status'] === 'new';
                        $is_missing_source_attr = $attr_comp['status'] === 'missing_source';
                        $first_term = true;
                        ?>
                        <?php if (empty($attr_comp['terms']) && ($is_new_attr || $is_missing_source_attr)): // Show attribute row even if no terms ?>
                             <tr class="<?php echo esc_attr($attr_comp['status']); ?>-attribute attribute-group-header" data-attr-id="<?php echo esc_attr($attr_index); ?>">
                                <td class="check-column">
                                    <span class="attribute-toggle dashicons dashicons-arrow-down-alt2"></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($attr_name); ?></strong>
                                    <div class="attribute-slug">Slug: <?php echo esc_html($attr_slug); ?></div>
                                </td>
                                <td>-</td>
                                <td>
                                     <?php if ($is_new_attr): ?>
                                         <span class="status-badge status-success"><?php esc_html_e('New Attribute', 'forbes-product-sync'); ?></span>
                                     <?php elseif ($is_missing_source_attr): ?>
                                         <span class="status-badge status-warning"><?php esc_html_e('Local Attribute Only', 'forbes-product-sync'); ?></span>
                                     <?php endif; ?>
                                 </td>
                                 <td><?php echo $is_new_attr ? esc_html__('Attribute will be created', 'forbes-product-sync') : esc_html__('Attribute not present in source data', 'forbes-product-sync'); ?></td>
                             </tr>
                        <?php else: ?>
                             <?php 
                             $attr_has_terms = !empty($attr_comp['terms']);
                             
                             // More efficient determination of selectable terms
                             $selectable_terms = array_filter($attr_comp['terms'], function($term) {
                                 return $term['status'] === 'new' || $term['status'] === 'updated';
                             });
                             $has_selectable_terms = !empty($selectable_terms);
                             ?>
                             <tr class="attribute-group-header" data-attr-id="<?php echo esc_attr($attr_index); ?>" data-attr-slug="<?php echo esc_attr($attr_slug); ?>">
                                 <td class="check-column">
                                     <?php if ($attr_has_terms): ?>
                                         <span class="attribute-toggle dashicons dashicons-arrow-down-alt2"></span>
                                         <?php if ($has_selectable_terms): ?>
                                             <input type="checkbox" class="select-attribute-terms" data-attr-id="<?php echo esc_attr($attr_index); ?>" data-attr-slug="<?php echo esc_attr($attr_slug); ?>" title="<?php esc_attr_e('Select all terms in this attribute', 'forbes-product-sync'); ?>" />
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
                                     <?php printf(esc_html__('%d terms found', 'forbes-product-sync'), count($attr_comp['terms'])); ?>
                                     <?php if ($has_selectable_terms): ?>
                                         <span class="term-selection-hint"><?php esc_html_e('(Use checkbox to select all terms)', 'forbes-product-sync'); ?></span>
                                     <?php endif; ?>
                                 </td>
                             </tr>
                             
                             <?php foreach ($attr_comp['terms'] as $term_index => $term_comp): ?>
                                <?php 
                                $term_name = isset($term_comp['source']['name']) ? $term_comp['source']['name'] : ($term_comp['local'] ? $term_comp['local']->name : 'N/A');
                                $source_term_slug = isset($term_comp['source']['slug']) ? $term_comp['source']['slug'] : null;
                                $local_term_slug = isset($term_comp['local']->slug) ? $term_comp['local']->slug : null;
                                $display_slug = $source_term_slug ?: $local_term_slug ?: 'N/A';
                                $can_select = ($term_comp['status'] === 'new' || $term_comp['status'] === 'updated');
                                ?>
                                <tr class="attribute-group-term" data-attr-id="<?php echo esc_attr($attr_index); ?>" data-term="<?php echo esc_attr($display_slug); ?>">
                                    <td class="check-column">
                                        <?php if ($can_select): ?>
                                        <input type="checkbox" class="sync-term-checkbox" 
                                               data-attr="<?php echo esc_attr($attr_slug); ?>" 
                                               data-term="<?php echo esc_attr($display_slug); ?>"
                                               data-term-name="<?php echo esc_attr($term_name); ?>"
                                               data-attr-name="<?php echo esc_attr($attr_name); ?>"
                                               data-attr-id="<?php echo esc_attr($attr_index); ?>" />
                                        <?php endif; ?>
                                    </td>
                                    <td class="term-name-cell">&nbsp;</td>
                                    <td class="term-name">
                                        <?php echo esc_html($term_name); ?>
                                        <div class="term-slug-info"><strong>Slug:</strong> <?php echo esc_html($display_slug); ?></div>
                                    </td>
                                    <td class="status">
                                        <?php 
                                        switch ($term_comp['status']) {
                                            case 'new':
                                                echo '<span class="status-badge status-success">' . esc_html__('New Term', 'forbes-product-sync') . '</span>';
                                                break;
                                            case 'updated':
                                                echo '<span class="status-badge status-warning">' . esc_html__('Term Updated', 'forbes-product-sync') . '</span>';
                                                break;
                                            case 'missing_source':
                                                echo '<span class="status-badge status-info">' . esc_html__('Local Term Only', 'forbes-product-sync') . '</span>';
                                                break;
                                            default:
                                                 echo '<span class="status-badge status-ok">' . esc_html__('Term OK', 'forbes-product-sync') . '</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="changes">
                                        <?php 
                                        $change_details = [];
                                        
                                        // Process source meta if we have source data
                                        $source_meta = [];
                                        if (isset($term_comp['source'])) {
                                            $source_meta = $api->get_term_meta_map($term_comp['source']);
                                        }
                                        
                                        // Process local meta if we have local data
                                        $local_meta = [
                                            'term_suffix' => '',
                                            'swatch_image' => ''
                                        ];
                                        if (isset($term_comp['local']) && !empty($term_comp['local']->term_id)) {
                                            $local_meta['term_suffix'] = get_term_meta($term_comp['local']->term_id, 'term_suffix', true);
                                            $local_meta['swatch_image'] = get_term_meta($term_comp['local']->term_id, 'swatch_image', true);
                                        }
                                        
                                        if ($term_comp['status'] === 'new') {
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
                                            
                                        } elseif ($term_comp['status'] === 'missing_source') {
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
                                            
                                        } else { // For 'updated' or 'ok' status
                                            $meta_fields_displayed = [];
                                            
                                            // For updated terms, first show the field changes
                                            if (!empty($term_comp['changes'])) {
                                                 $change_details[] = '<strong>' . esc_html__('Field Changes:', 'forbes-product-sync') . '</strong>';
                                                 $field_changes = [];
                                                 foreach ($term_comp['changes'] as $field => $diff) {
                                                     $field_changes[] = sprintf('<strong>%s:</strong> "%s" &rarr; "%s"', ucfirst($field), esc_html($diff['old']), esc_html($diff['new']));
                                                 }
                                                 $change_details[] = '<ul class="term-meta-list"><li>' . implode('</li><li>', $field_changes) . '</li></ul>';
                                            }
                                            
                                            // Then show metadata changes
                                            if (!empty($term_comp['meta_changes'])) {
                                                $change_details[] = '<strong>' . esc_html__('Metadata Changes:', 'forbes-product-sync') . '</strong>';
                                                $meta_changes = [];
                                                
                                                foreach ($term_comp['meta_changes'] as $key => $diff) {
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
                                                
                                                $change_details[] = '<ul class="term-meta-list"><li>' . implode('</li><li>', $meta_changes) . '</li></ul>';
                                            }
                                            
                                            // For all terms with local data, show current metadata that wasn't already displayed
                                            $current_meta = [];
                                            
                                            if (!in_array('term_suffix', $meta_fields_displayed) && !empty($local_meta['term_suffix'])) {
                                                $current_meta[] = sprintf('<strong>Current Suffix:</strong> "%s"', esc_html($local_meta['term_suffix']));
                                            }
                                            
                                            if (!in_array('swatch_image', $meta_fields_displayed) && !empty($local_meta['swatch_image'])) {
                                                $current_meta[] = sprintf(
                                                    '<strong>Current Swatch:</strong> <img src="%1$s" alt="swatch" class="swatch-image"/> <a href="%1$s" target="_blank">View</a>', 
                                                    esc_url($local_meta['swatch_image'])
                                                );
                                            }
                                            
                                            if (!empty($current_meta)) {
                                                $change_details[] = '<strong>' . esc_html__('Current Metadata:', 'forbes-product-sync') . '</strong>';
                                                $change_details[] = '<ul class="term-meta-list"><li>' . implode('</li><li>', $current_meta) . '</li></ul>';
                                            }
                                            
                                            // If no detailed changes were listed but status is updated, show generic message
                                            if ($term_comp['status'] === 'updated' && empty($term_comp['changes']) && empty($term_comp['meta_changes'])) {
                                                $change_details[] = esc_html__('Term has updates but specific changes could not be determined.', 'forbes-product-sync');
                                            }
                                        }
                                        
                                        if (empty($change_details)) {
                                            echo '-';
                                        } else {
                                            echo implode('<br>', $change_details);
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; // Terms loop ?>
                        <?php endif; // End if/else for terms check ?>
                    <?php endforeach; // Attributes loop ?>
                <?php endif; // End if empty attributes_data ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Calculate statistics from comparison results.
     *
     * @param array $comparison_results Results from compare_attributes().
     * @return array Statistics.
     */
    public function get_stats($comparison_results) {
         // Recalculate stats to be sure, as the initial calculation might miss some edge cases
         $stats = [
             'new_attributes' => 0,
             'missing_attributes' => 0, 
             'new_terms' => 0,
             'updated_terms' => 0,
             'missing_terms' => 0,
             'total_differences' => 0,
         ];
         
         foreach ($comparison_results['attributes'] as $attr_comp) {
             if ($attr_comp['status'] === 'new') {
                 $stats['new_attributes']++;
                 $stats['total_differences']++;
             } elseif ($attr_comp['status'] === 'missing_source') {
                 $stats['missing_attributes']++;
                 $stats['total_differences']++;
             }
             
             foreach ($attr_comp['terms'] as $term_comp) {
                  if ($term_comp['status'] === 'new') {
                     $stats['new_terms']++;
                     $stats['total_differences']++;
                 } elseif ($term_comp['status'] === 'updated') {
                     $stats['updated_terms']++;
                      $stats['total_differences']++;
                 } elseif ($term_comp['status'] === 'missing_source') {
                      $stats['missing_terms']++;
                      $stats['total_differences']++;
                 }
             }
         }
         
         return $stats;
    }
} 