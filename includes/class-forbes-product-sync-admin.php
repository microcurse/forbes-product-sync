<?php
/**
 * Admin interface for product sync
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forbes_Product_Sync_Admin {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_forbes_product_sync_get_attribute_differences', array($this, 'ajax_get_attribute_differences'));
        add_action('wp_ajax_forbes_product_sync_process_attributes', array($this, 'ajax_process_attributes'));
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        // Add main menu item
        add_menu_page(
            __('Forbes Product Sync', 'forbes-product-sync'),
            __('Product Sync', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync',
            array($this, 'render_main_page'),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Attribute Sync', 'forbes-product-sync'),
            __('Attribute Sync', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync-attributes',
            array($this, 'render_attribute_sync_page')
        );

        add_submenu_page(
            'forbes-product-sync',
            __('Sync Log', 'forbes-product-sync'),
            __('Sync Log', 'forbes-product-sync'),
            'manage_woocommerce',
            'forbes-product-sync-log',
            array($this, 'render_log_page')
        );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'forbes-product-sync') === false) {
            return;
        }

        wp_enqueue_style(
            'forbes-product-sync-admin',
            plugins_url('assets/css/admin.css', dirname(__FILE__)),
            array(),
            FORBES_PRODUCT_SYNC_VERSION
        );

        wp_enqueue_script(
            'forbes-product-sync-admin',
            plugins_url('assets/js/admin.js', dirname(__FILE__)),
            array('jquery'),
            FORBES_PRODUCT_SYNC_VERSION,
            true
        );

        wp_localize_script('forbes-product-sync-admin', 'forbesProductSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('forbes_product_sync_nonce'),
            'i18n' => array(
                'confirmApply' => __('Are you sure you want to apply these changes?', 'forbes-product-sync'),
                'processing' => __('Processing', 'forbes-product-sync'),
                'error' => __('An error occurred. Please try again.', 'forbes-product-sync'),
                'noChangesSelected' => __('Please select at least one change to apply.', 'forbes-product-sync'),
                'refreshingCache' => __('Refreshing attribute cache...', 'forbes-product-sync'),
                'syncing' => __('Syncing...', 'forbes-product-sync'),
                'synced' => __('Synced', 'forbes-product-sync'),
                'syncingMetadata' => __('Syncing metadata in the background...', 'forbes-product-sync'),
                'startSync' => __('Start Sync', 'forbes-product-sync'),
            )
        ));
    }

    /**
     * Render main page
     */
    public function render_main_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'templates/main-page.php';
    }

    /**
     * Render attribute sync page
     */
    public function render_attribute_sync_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'templates/attribute-sync-form.php';
    }

    /**
     * Render log page
     */
    public function render_log_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'templates/log-page.php';
    }

    /**
     * AJAX handler: Get and compare attributes/terms, return HTML table of differences
     */
    public function ajax_get_attribute_differences() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'forbes-product-sync')]);
        }
        
        // Check if this is a cache refresh request
        $refresh_cache = isset($_POST['refresh_cache']) && $_POST['refresh_cache'];
        
        $api = new Forbes_Product_Sync_API();
        
        if ($refresh_cache) {
            $api->clear_attribute_caches();
        }
        
        // Get all attributes and their terms in a single method call
        $source_data = $api->get_attributes_with_terms();
        
        if (is_wp_error($source_data) || !isset($source_data['attributes']) || !is_array($source_data['attributes'])) {
            wp_send_json_error(['message' => __('Failed to fetch source attributes.', 'forbes-product-sync')]);
        }
        
        $source_attributes = $source_data['attributes'];
        $source_terms_map = $source_data['terms'];
        
        $local_attributes = wc_get_attribute_taxonomies();
        $local_map = [];
        foreach ($local_attributes as $attr) {
            $local_map[$attr->attribute_name] = $attr;
        }
        
        // Pre-fetch all local term metadata to avoid multiple DB queries
        $local_term_meta = array();
        $taxonomies = array();
        
        foreach ($source_attributes as $src_attr) {
            $src_slug = wc_sanitize_taxonomy_name($src_attr['name']);
            $taxonomies[] = wc_attribute_taxonomy_name($src_slug);
        }
        
        // Get all terms for the relevant taxonomies at once
        $all_local_terms = array();
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (!is_wp_error($terms) && !empty($terms)) {
                $all_local_terms = array_merge($all_local_terms, $terms);
            }
        }
        
        // Get all term IDs for meta query
        $term_ids = array();
        foreach ($all_local_terms as $term) {
            $term_ids[] = $term->term_id;
        }
        
        // Fetch meta values in bulk if there are terms
        if (!empty($term_ids)) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
            $meta_keys = array('suffix', 'term_suffix', '_term_suffix', 'swatch_image', 'price_adjustment');
            
            $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            
            $query = $wpdb->prepare(
                "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} 
                 WHERE term_id IN ($placeholders) AND meta_key IN ($meta_placeholders)",
                array_merge($term_ids, $meta_keys)
            );
            
            $results = $wpdb->get_results($query);
            
            foreach ($results as $row) {
                if (!isset($local_term_meta[$row->term_id])) {
                    $local_term_meta[$row->term_id] = array();
                }
                $local_term_meta[$row->term_id][$row->meta_key] = $row->meta_value;
            }
        }
        
        // Helper function to get term suffix using the prefetched meta
        $get_suffix = function($term) use ($local_term_meta) {
            if (!$term) return '';
            
            $term_id = $term->term_id;
            if (isset($local_term_meta[$term_id]['suffix'])) {
                return $local_term_meta[$term_id]['suffix'];
            }
            if (isset($local_term_meta[$term_id]['term_suffix'])) {
                return $local_term_meta[$term_id]['term_suffix'];
            }
            if (isset($local_term_meta[$term_id]['_term_suffix'])) {
                return $local_term_meta[$term_id]['_term_suffix'];
            }
            return '';
        };
        
        // Helper function to get swatch image using the prefetched meta
        $get_swatch = function($term) use ($local_term_meta) {
            if (!$term) return '';
            
            $term_id = $term->term_id;
            if (isset($local_term_meta[$term_id]['swatch_image'])) {
                $swatch = $local_term_meta[$term_id]['swatch_image'];
                if ($swatch && is_numeric($swatch)) {
                    $url = wp_get_attachment_image_url($swatch, 'thumbnail');
                    return $url ? $url : $swatch;
                }
                return $swatch;
            }
            return '';
        };
        
        // Helper function to get price adjustment using the prefetched meta
        $get_price = function($term) use ($local_term_meta) {
            if (!$term) return '';
            
            $term_id = $term->term_id;
            if (isset($local_term_meta[$term_id]['price_adjustment'])) {
                $price = $local_term_meta[$term_id]['price_adjustment'];
                if ($price !== '') return wc_price(floatval($price));
            }
            return '';
        };
        
        $comparison = [];
        foreach ($source_attributes as $src_attr) {
            $src_slug = wc_sanitize_taxonomy_name($src_attr['name']);
            $local = isset($local_map[$src_slug]) ? $local_map[$src_slug] : null;
            
            // Get source terms from the already fetched map
            $src_terms = isset($source_terms_map[$src_attr['id']]) ? $source_terms_map[$src_attr['id']] : array();
            
            $taxonomy = wc_attribute_taxonomy_name($src_slug);
            $local_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            $local_terms_map = [];
            $local_terms_by_name = [];
            foreach ($local_terms as $term) {
                $local_terms_map[$term->slug] = $term;
                $local_terms_by_name[strtolower($term->name)] = $term;
            }
            
            // Create a list of all term names from both source and destination
            $all_names = array();
            foreach ($src_terms as $term) {
                $all_names[strtolower($term['name'])] = $term['name'];
            }
            foreach ($local_terms as $term) {
                $all_names[strtolower($term->name)] = $term->name;
            }
            
            $terms = [];
            // Process each unique term name
            foreach ($all_names as $lowercase_name => $display_name) {
                // Find the source term by name
                $src_term = null;
                foreach ($src_terms as $t) {
                    if (strtolower($t['name']) === $lowercase_name) {
                        $src_term = $t;
                        break;
                    }
                }
                
                // Find the destination term by name
                $dst_term = isset($local_terms_by_name[$lowercase_name]) ? $local_terms_by_name[$lowercase_name] : null;
                
                $meta_keys = [
                    'slug' => 'slug',
                    'suffix' => 'suffix',
                    'price_adjustment' => 'price_adjustment',
                    'swatch_image' => 'swatch_image',
                ];
                $source_meta = [
                    'exists' => !!$src_term,
                    'slug' => $src_term['slug'] ?? '',
                    'suffix' => $src_term['suffix'] ?? '',
                    'price_adjustment' => isset($src_term['price_adjustment']) && $src_term['price_adjustment'] !== '' ? wc_price(floatval($src_term['price_adjustment'])) : '',
                    'swatch_image' => isset($src_term['swatch_image']) && is_numeric($src_term['swatch_image']) ? (wp_get_attachment_image_url($src_term['swatch_image'], 'thumbnail') ?: $src_term['swatch_image']) : ($src_term['swatch_image'] ?? ''),
                ];
                $destination_meta = [
                    'exists' => !!$dst_term,
                    'slug' => $dst_term ? $dst_term->slug : '',
                    'suffix' => $get_suffix($dst_term),
                    'price_adjustment' => $get_price($dst_term),
                    'swatch_image' => $get_swatch($dst_term),
                ];
                // Determine status
                if ($src_term && $dst_term) {
                    $all_match = true;
                    foreach ($meta_keys as $k => $meta_key) {
                        if ($source_meta[$k] !== $destination_meta[$k]) {
                            $all_match = false;
                            break;
                        }
                    }
                    $status = $all_match ? 'match' : 'mismatch';
                } elseif ($src_term && !$dst_term) {
                    $status = 'new';
                } elseif (!$src_term && $dst_term) {
                    $status = 'missing';
                } else {
                    $status = 'unknown';
                }
                $terms[] = [
                    'name' => $src_term ? $src_term['name'] : ($dst_term ? $dst_term->name : $display_name),
                    'status' => $status,
                    'source' => $source_meta,
                    'destination' => $destination_meta,
                ];
            }
            $comparison[] = [
                'attribute' => $src_attr['name'],
                'status' => '',
                'terms' => $terms
            ];
        }
        $html = self::render_comparison_table($comparison);
        $hasDifferences = !empty($comparison);
        
        // Get cache timestamp to show when data was last fetched
        $cache_timestamp = $api->get_cache_timestamp();
        $cache_info = $cache_timestamp ? sprintf(
            __('Data cached %s ago. Click "Refresh Cache" for fresh data.', 'forbes-product-sync'),
            human_time_diff($cache_timestamp, time())
        ) : '';
        
        wp_send_json_success([
            'html' => $html, 
            'hasDifferences' => $hasDifferences,
            'cache_info' => $cache_info
        ]);
    }

    /**
     * AJAX handler: Process attribute sync
     */
    public function ajax_process_attributes() {
        check_ajax_referer('forbes_product_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'forbes-product-sync')]);
        }

        $sync_metadata = isset($_POST['sync_metadata']) ? (bool)$_POST['sync_metadata'] : true;
        $handle_conflicts = isset($_POST['handle_conflicts']) ? (bool)$_POST['handle_conflicts'] : true;
        $selected_terms = isset($_POST['terms']) ? $_POST['terms'] : [];

        try {
            $api = new Forbes_Product_Sync_API();
            
            // Use cached attribute data if available
            $source_data = $api->get_attributes_with_terms();
            
            if (is_wp_error($source_data) || !isset($source_data['attributes'])) {
                // Fallback to regular attribute fetching
                $source_attributes = $api->get_attributes();
                
                if (is_wp_error($source_attributes)) {
                    throw new Exception($source_attributes->get_error_message());
                }
            } else {
                $source_attributes = $source_data['attributes'];
            }

            // If we are processing only specific terms
            if (!empty($selected_terms)) {
                $attributes_sync = new Forbes_Product_Sync_Attributes();
                $processed_terms = 0;
                $errors = 0;
                
                foreach ($selected_terms as $selected_term) {
                    $attr_name = $selected_term['attribute'];
                    $term_name = isset($selected_term['term_name']) ? $selected_term['term_name'] : $selected_term['term'];
                    
                    // Find the attribute in source data
                    $src_attr = null;
                    foreach ($source_attributes as $attr) {
                        if ($attr['name'] === $attr_name) {
                            $src_attr = $attr;
                            break;
                        }
                    }
                    
                    if (!$src_attr) {
                        error_log('Forbes Product Sync - Cannot find attribute in source: ' . $attr_name);
                        $errors++;
                        continue;
                    }
                    
                    // Find the term in the source attribute terms
                    $src_term = null;
                    $attr_id = $src_attr['id'];
                    
                    if (isset($source_data['terms'][$attr_id])) {
                        // Try exact match first
                        foreach ($source_data['terms'][$attr_id] as $term) {
                            if ($term['name'] === $term_name) {
                                $src_term = $term;
                                break;
                            }
                        }
                        
                        // If not found, try case-insensitive match
                        if (!$src_term) {
                            foreach ($source_data['terms'][$attr_id] as $term) {
                                // Check both name and slug for a match
                                if (strtolower($term['name']) === strtolower($term_name) || 
                                    strtolower($term['slug']) === strtolower(sanitize_title($term_name))) {
                                    $src_term = $term;
                                    break;
                                }
                            }
                        }
                        
                        // As a last resort, try to find a partial match
                        if (!$src_term && strlen($term_name) > 3) {
                            foreach ($source_data['terms'][$attr_id] as $term) {
                                if (stripos($term['name'], $term_name) !== false || 
                                    stripos($term_name, $term['name']) !== false) {
                                    $src_term = $term;
                                    error_log('Forbes Product Sync - Found partial match for "' . $term_name . '": "' . $term['name'] . '"');
                                    break;
                                }
                            }
                        }
                    }
                    
                    if (!$src_term) {
                        error_log('Forbes Product Sync - Cannot find term "' . $term_name . '" for attribute "' . $attr_name . '" (ID: ' . $attr_id . ')');
                        // Debug log the available terms
                        if (isset($source_data['terms'][$attr_id])) {
                            error_log('Forbes Product Sync - Available terms for "' . $attr_name . '": ' . 
                                implode(', ', array_map(function($t) { 
                                    return '"' . $t['name'] . '" (slug: ' . $t['slug'] . ')'; 
                                }, $source_data['terms'][$attr_id])));
                            error_log('Forbes Product Sync - Term name received: "' . $term_name . '" (data type: ' . gettype($term_name) . ', length: ' . strlen($term_name) . ')');
                            
                            // Try cleaning the term name and searching again
                            $clean_term = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $term_name);
                            // Also remove common UI indicators and text that might have been accidentally included
                            $clean_term = preg_replace('/[➕➖✓❌☑️⬜️⚠️✅❎]/', '', $clean_term);
                            $clean_term = trim(preg_replace('/Source slug:.*|Destination slug:.*/i', '', $clean_term));
                            
                            if ($clean_term !== $term_name) {
                                error_log('Forbes Product Sync - Term cleaned: "' . $clean_term . '"');
                                // Try one more time with the cleaned term
                                foreach ($source_data['terms'][$attr_id] as $term) {
                                    if (strtolower($term['name']) === strtolower($clean_term)) {
                                        $src_term = $term;
                                        error_log('Forbes Product Sync - Found match with cleaned term: "' . $clean_term . '" -> "' . $term['name'] . '"');
                                        break;
                                    }
                                }
                            }
                            
                            // If still not found, try direct lookup by name or slug
                            if (!$src_term && (isset($source_data['available_terms'][$attr_id]) || count($source_data['terms'][$attr_id]) <= 5)) {
                                error_log('Forbes Product Sync - Attempting match based on available terms for "' . $attr_name . '"');
                                
                                // For common boolean attributes like "Yes"/"No", try to directly match
                                $possible_matches = [
                                    'yes' => ['yes', 'y', 'true', '1'],
                                    'no' => ['no', 'n', 'false', '0'],
                                ];
                                
                                $clean_term_lower = strtolower(trim($clean_term));
                                foreach ($possible_matches as $key => $variations) {
                                    if (in_array($clean_term_lower, $variations)) {
                                        // Look for a term that matches this key
                                        foreach ($source_data['terms'][$attr_id] as $term) {
                                            if (strtolower($term['name']) === $key || strtolower($term['slug']) === $key) {
                                                $src_term = $term;
                                                error_log('Forbes Product Sync - Found common term match: "' . $clean_term . '" -> "' . $term['name'] . '"');
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            error_log('Forbes Product Sync - No terms available for attribute ID ' . $attr_id);
                        }
                        
                        if (!$src_term) {
                            // Still not found after all attempts
                            $errors++;
                            continue;
                        }
                    }
                    
                    // Process this specific term
                    $attr_slug = wc_sanitize_taxonomy_name($attr_name);
                    $taxonomy = wc_attribute_taxonomy_name($attr_slug);
                    
                    // Check if the term exists in the destination by name first
                    $dest_term = get_term_by('name', $term_name, $taxonomy);
                    if (!$dest_term) {
                        // Try case-insensitive comparison
                        $terms = get_terms([
                            'taxonomy' => $taxonomy,
                            'hide_empty' => false
                        ]);
                        
                        foreach ($terms as $term) {
                            if (strtolower($term->name) === strtolower($term_name)) {
                                $dest_term = $term;
                                break;
                            }
                        }
                    }
                    
                    if (!$dest_term) {
                        // Create the term
                        $term_insert = wp_insert_term($src_term['name'], $taxonomy, array(
                            'slug' => $src_term['slug']
                        ));
                        
                        if (is_wp_error($term_insert)) {
                            $errors++;
                            continue;
                        }
                        
                        // Add term metadata
                        update_term_meta($term_insert['term_id'], 'swatch_image', $src_term['swatch_image'] ?? '');
                        update_term_meta($term_insert['term_id'], 'term_suffix', $src_term['suffix'] ?? '');
                        update_term_meta($term_insert['term_id'], 'price_adjustment', $src_term['price_adjustment'] ?? '');
                        
                        $processed_terms++;
                    } else {
                        // Update term meta if needed
                        $updated = false;
                        
                        // Check for metadata differences and update if needed
                        $current_suffix = get_term_meta($dest_term->term_id, 'term_suffix', true);
                        $source_suffix = $src_term['suffix'] ?? '';
                        if ($current_suffix !== $source_suffix) {
                            update_term_meta($dest_term->term_id, 'term_suffix', $source_suffix);
                            $updated = true;
                        }
                        
                        $current_price = get_term_meta($dest_term->term_id, 'price_adjustment', true);
                        $source_price = $src_term['price_adjustment'] ?? '';
                        if ($current_price !== $source_price) {
                            update_term_meta($dest_term->term_id, 'price_adjustment', $source_price);
                            $updated = true;
                        }
                        
                        $current_swatch = get_term_meta($dest_term->term_id, 'swatch_image', true);
                        $source_swatch = $src_term['swatch_image'] ?? '';
                        if ($current_swatch !== $source_swatch) {
                            update_term_meta($dest_term->term_id, 'swatch_image', $source_swatch);
                            $updated = true;
                        }
                        
                        if ($updated) {
                            $processed_terms++;
                        }
                    }
                }
                
                if ($errors > 0) {
                    wp_send_json_success([
                        'message' => sprintf(
                            __('Processed %d terms with %d errors.', 'forbes-product-sync'),
                            $processed_terms,
                            $errors
                        )
                    ]);
                } else {
                    wp_send_json_success([
                        'message' => sprintf(
                            __('Successfully processed %d terms.', 'forbes-product-sync'),
                            $processed_terms
                        )
                    ]);
                }
                return;
            }

            // Full attribute sync
            $sync = new Forbes_Product_Sync();
            $result = $sync->sync_attributes($source_attributes, $sync_metadata, $handle_conflicts);

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            wp_send_json_success([
                'message' => __('Attributes synchronized successfully.', 'forbes-product-sync')
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private static function render_comparison_table($comparison) {
        if (empty($comparison)) {
            return '<div class="notice notice-info">' . esc_html__('No attribute or term differences found.', 'forbes-product-sync') . '</div>';
        }
        $icon_map = [
            'match' => '<span class="status-icon status-ok" title="Match">&#x2705;</span>',
            'mismatch' => '<span class="status-icon status-warning" title="Metadata mismatch">&#x26A0;&#xFE0F;</span>',
            'new' => '<span class="status-icon status-new" title="New in source">&#x2795;</span>',
            'missing' => '<span class="status-icon status-missing" title="Not found in source">&#x274C;</span>',
            'unknown' => '<span class="status-icon status-missing" title="Unknown">&#x2753;</span>',
        ];
        
        // Helper function to highlight differences
        $highlight_diff = function($source_value, $dest_value, $type = 'text') {
            if ($source_value === $dest_value) {
                return $dest_value;
            }
            
            if (empty($source_value) || empty($dest_value)) {
                return $dest_value;
            }
            
            // For simple text values, wrap in a span with a class
            if ($type === 'text') {
                return '<span class="highlight-diff" style="background-color:#fff9c4;padding:1px 3px;border-radius:2px;">' . $dest_value . '</span>';
            }
            
            return $dest_value;
        };
        
        $html = '';
        $attrIndex = 0;
        foreach ($comparison as $attr) {
            $collapsed = $attrIndex > 0 ? 'collapsed' : '';
            $toggleIcon = $collapsed ? '&#x25B6;' : '&#x25BC;';
            $html .= '<div class="attribute-comparison-block" style="margin-bottom:18px;">';
            $html .= '<div class="attribute-header" style="display:flex;align-items:center;cursor:pointer;user-select:none;" data-attr-index="' . $attrIndex . '">' .
                '<span class="toggle-arrow" style="margin-right:8px;font-size:1.2em;">' . $toggleIcon . '</span>' .
                '<span class="attribute-title attr-name">' . 
                    esc_html($attr['attribute']) . 
                    '<span class="attribute-slug">(' . esc_html(wc_sanitize_taxonomy_name($attr['attribute'])) . ')</span>' .
                '</span>' .
            '</div>';
            $html .= '<div class="attribute-terms-table ' . $collapsed . '" data-attr-index="' . $attrIndex . '" style="' . ($collapsed ? 'display:none;' : '') . 'margin-bottom:0;">';
            $html .= '<table class="attribute-comparison-table widefat fixed striped" style="margin-bottom:0;"><thead>';
            $html .= '<tr>';
            $html .= '<th class="check-column"><input type="checkbox" class="select-all" title="' . esc_attr__('Select All', 'forbes-product-sync') . '"></th>';
            $html .= '<th>' . esc_html__('Term Name', 'forbes-product-sync') . '</th>';
            $html .= '<th>' . esc_html__('Source', 'forbes-product-sync') . '</th>';
            $html .= '<th>' . esc_html__('Destination', 'forbes-product-sync') . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($attr['terms'] as $term) {
                $status = $term['status'];
                $icon = isset($icon_map[$status]) ? $icon_map[$status] : $icon_map['unknown'];
                $can_sync = in_array($status, ['new', 'mismatch']);
                $html .= '<tr>';
                $html .= '<td class="check-column">' . ($can_sync ? '<input type="checkbox" class="sync-term-checkbox" data-attr="' . esc_attr($attr['attribute']) . '" data-term="' . esc_attr($term['name']) . '">' : '') . '</td>';
                $html .= '<td style="font-weight:500;" class="term-name">' . 
                    $icon . ' ' . esc_html($term['name']) . 
                    '<div class="term-slug-info">' . 
                    ($term['source']['exists'] ? 'Source slug: ' . esc_html($term['source']['slug']) : '') . 
                    ($term['destination']['exists'] ? ($term['source']['exists'] ? ' | ' : '') . 'Destination slug: ' . esc_html($term['destination']['slug']) : '') . 
                    '</div>' . 
                '</td>';
                // Source
                $html .= '<td style="font-size:0.97em;line-height:1.5;">';
                if ($term['source']['exists']) {
                    $html .= '<span class="meta-details">';
                    $html .= '<span style="color:#888;">slug:</span> <code>' . esc_html($term['source']['slug']) . '</code> ';
                    $html .= '<span style="color:#888;">suffix:</span> <code>' . esc_html($term['source']['suffix']) . '</code> ';
                    $html .= '<span style="color:#888;">price:</span> <code>' . esc_html($term['source']['price_adjustment']) . '</code> ';
                    if ($term['source']['swatch_image']) {
                        $html .= '<span style="color:#888;">swatch:</span> <img src="' . esc_url($term['source']['swatch_image']) . '" style="height:18px;vertical-align:middle;" alt="swatch"> ';
                    } else {
                        $html .= '<span style="color:#888;">swatch:</span> <code></code> ';
                    }
                    $html .= '</span>';
                } else {
                    $html .= '<span class="meta-details" style="color:#d63638;">' . esc_html__('Not found in source', 'forbes-product-sync') . '</span>';
                }
                $html .= '</td>';
                // Destination
                $html .= '<td style="font-size:0.97em;line-height:1.5;">';
                if ($term['destination']['exists']) {
                    $html .= '<span class="meta-details">';
                    
                    // Highlight different slug
                    $slug_html = $highlight_diff($term['source']['slug'], $term['destination']['slug'], 'text');
                    $html .= '<span style="color:#888;">slug:</span> <code>' . $slug_html . '</code> ';
                    
                    // Highlight different suffix
                    $suffix_html = $highlight_diff($term['source']['suffix'], $term['destination']['suffix'], 'text');
                    $html .= '<span style="color:#888;">suffix:</span> <code>' . $suffix_html . '</code> ';
                    
                    // Highlight different price
                    $price_html = $highlight_diff($term['source']['price_adjustment'], $term['destination']['price_adjustment'], 'text');
                    $html .= '<span style="color:#888;">price:</span> <code>' . $price_html . '</code> ';
                    
                    if ($term['destination']['swatch_image']) {
                        $html .= '<span style="color:#888;">swatch:</span> <img src="' . esc_url($term['destination']['swatch_image']) . '" style="height:18px;vertical-align:middle;" alt="swatch"> ';
                    } else {
                        $html .= '<span style="color:#888;">swatch:</span> <code></code> ';
                    }
                    $html .= '</span>';
                } else {
                    $html .= '<span class="meta-details" style="color:#d63638;">' . esc_html__('Not found in destination', 'forbes-product-sync') . '</span>';
                }
                $html .= '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '</div>';
            $html .= '</div>';
            $attrIndex++;
        }
        $html .= '<div style="text-align:center;margin-top:18px;"><button type="button" class="button button-primary" id="apply-attribute-sync" disabled>' . esc_html__('Apply Selected to Destination', 'forbes-product-sync') . '</button></div>';
        $html .= '<script>jQuery(function($){
            $(".attribute-header").on("click", function(){
                var idx = $(this).data("attr-index");
                var $table = $(".attribute-terms-table[data-attr-index=\"" + idx + "\"]");
                $table.toggle();
                var $arrow = $(this).find(".toggle-arrow");
                $arrow.html($table.is(":visible") ? "&#x25BC;" : "&#x25B6;");
            });
        });</script>';
        return $html;
    }

    private static function get_status_icon($status) {
        if ($status === 'ok') return '<span class="dashicons dashicons-yes"></span>';
        if ($status === 'missing') return '<span class="dashicons dashicons-no"></span>';
        if ($status === 'warning') return '<span class="dashicons dashicons-warning"></span>';
        return '';
    }
} 