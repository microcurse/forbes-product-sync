<?php
/**
 * Product Handler class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Product_Handler
 * Handles product creation, updating, and synchronization
 */
class Forbes_Product_Sync_Product_Handler {
    /**
     * API instance
     *
     * @var Forbes_Product_Sync_API_Products
     */
    private $api;

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
        $this->api = new Forbes_Product_Sync_API_Products();
        $this->logger = Forbes_Product_Sync_Logger::instance();
    }
    
    /**
     * Get product by SKU
     *
     * @param string $sku Product SKU
     * @return WC_Product|false
     */
    public function get_product_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        return $product_id ? wc_get_product($product_id) : false;
    }

    /**
     * Create or update a product
     *
     * @param array $product_data Product data from API
     * @return int|WP_Error Product ID on success, WP_Error on failure
     */
    public function sync_product($product_data) {
        if (empty($product_data['sku'])) {
            return new WP_Error('missing_sku', __('Product SKU is required', 'forbes-product-sync'));
        }

        // Check if product exists
        $existing_product_id = wc_get_product_id_by_sku($product_data['sku']);

        if ($existing_product_id) {
            return $this->update_product($existing_product_id, $product_data);
        } else {
            return $this->create_product($product_data);
        }
    }

    /**
     * Create a new product
     *
     * @param array $product_data Product data from API
     * @return int|WP_Error Product ID on success, WP_Error on failure
     */
    private function create_product($product_data) {
        $product_type = !empty($product_data['type']) ? $product_data['type'] : 'simple';
        
        switch ($product_type) {
            case 'variable':
                $product = new WC_Product_Variable();
                break;
            case 'grouped':
                $product = new WC_Product_Grouped();
                break;
            case 'external':
                $product = new WC_Product_External();
                break;
            default:
                $product = new WC_Product_Simple();
        }
        
        $changes = array('action' => 'created');
        
        // Set basic product data
        $product->set_name($product_data['name']);
        $product->set_sku($product_data['sku']);
        
        if (isset($product_data['regular_price'])) {
            $product->set_regular_price($product_data['regular_price']);
        }
        
        if (isset($product_data['sale_price'])) {
            $product->set_sale_price($product_data['sale_price']);
        }
        
        if (isset($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        
        if (isset($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
        
        if (isset($product_data['status'])) {
            $product->set_status($product_data['status']);
        }
        
        if (isset($product_data['catalog_visibility'])) {
            $product->set_catalog_visibility($product_data['catalog_visibility']);
        }
        
        if (isset($product_data['featured'])) {
            $product->set_featured($product_data['featured']);
        }
        
        if (isset($product_data['weight'])) {
            $product->set_weight($product_data['weight']);
        }
        
        if (isset($product_data['dimensions'])) {
            if (isset($product_data['dimensions']['length'])) {
                $product->set_length($product_data['dimensions']['length']);
            }
            
            if (isset($product_data['dimensions']['width'])) {
                $product->set_width($product_data['dimensions']['width']);
            }
            
            if (isset($product_data['dimensions']['height'])) {
                $product->set_height($product_data['dimensions']['height']);
            }
        }

        // Set categories
        if (!empty($product_data['categories'])) {
            $category_ids = $this->get_or_create_categories($product_data['categories']);
            $product->set_category_ids($category_ids);
            $changes['categories'] = 'Added ' . count($category_ids) . ' categories';
        }

        // Set attributes
        if (!empty($product_data['attributes'])) {
            $attributes = $this->prepare_attributes($product_data['attributes']);
            $product->set_attributes($attributes);
            $changes['attributes'] = 'Added ' . count($attributes) . ' attributes';
        }

        // Save the product
        $product_save_result = $product->save();

        if (is_wp_error($product_save_result)) {
            $this->logger->log_sync(
                $product_data['name'],
                'error',
                $product_save_result->get_error_message(),
                array('sku' => $product_data['sku'])
            );
            return $product_save_result;
        } else if (is_int($product_save_result) && $product_save_result > 0) {
             $product_id = $product_save_result;
        } else {
             // Handle unexpected return value from save()
             $error_msg = 'Unexpected return value from product save: ' . print_r($product_save_result, true);
             $this->logger->log_sync($product_data['name'], 'error', $error_msg, array('sku' => $product_data['sku']));
             return new WP_Error('product_save_failed', $error_msg);
        }
        
        // Handle images
        if (!empty($product_data['images'])) {
            $this->handle_product_images($product_id, $product_data['images']);
            $changes['images'] = 'Added ' . count($product_data['images']) . ' images';
        }

        // Update last sync time
        update_post_meta($product_id, '_forbes_last_sync_time', time());

        // Log successful creation
        $this->logger->log_sync(
            $product_data['name'],
            'success',
            'Product created successfully',
            $changes
        );

        return $product_id;
    }

    /**
     * Update an existing product
     *
     * @param int $product_id Product ID
     * @param array $product_data Product data from API
     * @return int|WP_Error Product ID on success, WP_Error on failure
     */
    private function update_product($product_id, $product_data) {
        $product = wc_get_product($product_id);
        $changes = array('action' => 'updated');
        
        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found', 'forbes-product-sync'));
        }

        // Track changes
        if ($product->get_name() !== $product_data['name']) {
            $changes['name'] = sprintf('"%s" → "%s"', $product->get_name(), $product_data['name']);
        }
        
        if (isset($product_data['regular_price']) && $product->get_regular_price() !== $product_data['regular_price']) {
            $changes['price'] = sprintf('$%s → $%s', $product->get_regular_price(), $product_data['regular_price']);
        }
        
        if (isset($product_data['sale_price']) && $product->get_sale_price() !== $product_data['sale_price']) {
            $changes['sale_price'] = sprintf('$%s → $%s', $product->get_sale_price(), $product_data['sale_price']);
        }
        
        if (isset($product_data['description']) && $product->get_description() !== $product_data['description']) {
            $changes['description'] = 'Updated';
        }
        
        if (isset($product_data['short_description']) && $product->get_short_description() !== $product_data['short_description']) {
            $changes['short_description'] = 'Updated';
        }

        // Update basic product data
        $product->set_name($product_data['name']);
        
        if (isset($product_data['regular_price'])) {
            $product->set_regular_price($product_data['regular_price']);
        }
        
        if (isset($product_data['sale_price'])) {
            $product->set_sale_price($product_data['sale_price']);
        }
        
        if (isset($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        
        if (isset($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
        
        if (isset($product_data['status'])) {
            $product->set_status($product_data['status']);
        }
        
        if (isset($product_data['catalog_visibility'])) {
            $product->set_catalog_visibility($product_data['catalog_visibility']);
        }
        
        if (isset($product_data['featured'])) {
            $product->set_featured($product_data['featured']);
        }
        
        if (isset($product_data['weight'])) {
            $product->set_weight($product_data['weight']);
        }
        
        if (isset($product_data['dimensions'])) {
            if (isset($product_data['dimensions']['length'])) {
                $product->set_length($product_data['dimensions']['length']);
            }
            
            if (isset($product_data['dimensions']['width'])) {
                $product->set_width($product_data['dimensions']['width']);
            }
            
            if (isset($product_data['dimensions']['height'])) {
                $product->set_height($product_data['dimensions']['height']);
            }
        }

        // Update categories
        if (!empty($product_data['categories'])) {
            $category_ids = $this->get_or_create_categories($product_data['categories']);
            $product->set_category_ids($category_ids);
            $changes['categories'] = 'Updated categories';
        }

        // Update attributes
        if (!empty($product_data['attributes'])) {
            $attributes = $this->prepare_attributes($product_data['attributes']);
            $product->set_attributes($attributes);
            $changes['attributes'] = 'Updated attributes';
        }

        // Save the product
        $product_update_result = $product->save();

        if (is_wp_error($product_update_result)) {
            $this->logger->log_sync(
                $product_data['name'],
                'error',
                $product_update_result->get_error_message(),
                array('sku' => $product_data['sku'])
            );
            return $product_update_result;
        } else if (!(is_int($product_update_result) && $product_update_result > 0)) {
            // Handle unexpected return value from save()
             $error_msg = 'Unexpected return value from product update: ' . print_r($product_update_result, true);
             $this->logger->log_sync($product_data['name'], 'error', $error_msg, array('sku' => $product_data['sku']));
             return new WP_Error('product_update_failed', $error_msg);
        }
        // $product_id remains the same on update, $product_update_result is the id too

        // Handle product images
        if (!empty($product_data['images'])) {
            $image_count = $this->handle_product_images($product_id, $product_data['images']);
            if ($image_count > 0) {
                $changes['images'] = 'Updated ' . $image_count . ' images';
            }
        }

        // Update last sync time
        update_post_meta($product_id, '_forbes_last_sync_time', time());

        // Log successful update
        $this->logger->log_sync(
            $product_data['name'],
            'success',
            'Product updated successfully',
            $changes
        );

        return $product_id;
    }

    /**
     * Get or create product categories
     *
     * @param array $categories Categories from API
     * @return array Category IDs
     */
    private function get_or_create_categories($categories) {
        $category_ids = array();

        foreach ($categories as $category) {
            $term = get_term_by('slug', $category['slug'], 'product_cat');

            if (!$term) {
                // Try to find by name
                $term = get_term_by('name', $category['name'], 'product_cat');
            }

            if (!$term) {
                // Create the category
                $term_data = wp_insert_term($category['name'], 'product_cat', array(
                    'slug' => $category['slug'],
                    'description' => isset($category['description']) ? $category['description'] : ''
                ));

                if (!is_wp_error($term_data)) {
                    $category_ids[] = $term_data['term_id'];
                }
            } else {
                $category_ids[] = $term->term_id;
            }
        }

        return $category_ids;
    }

    /**
     * Prepare product attributes
     *
     * @param array $attributes Attributes from API
     * @return array WC_Product_Attribute objects
     */
    private function prepare_attributes($attributes) {
        $wc_attributes = array();

        foreach ($attributes as $attribute) {
            if (empty($attribute['name'])) {
                continue;
            }

            $is_taxonomy = false;
            $taxonomy = '';
            $attribute_terms = array();

            // Check if this is a taxonomy attribute
            if (!empty($attribute['id'])) {
                $attr_name = wc_sanitize_taxonomy_name($attribute['name']);
                $taxonomy = wc_attribute_taxonomy_name($attr_name);
                
                // Check if taxonomy exists
                if (taxonomy_exists($taxonomy)) {
                    $is_taxonomy = true;
                    
                    // Add attribute terms if provided
                    if (!empty($attribute['options']) && is_array($attribute['options'])) {
                        foreach ($attribute['options'] as $option) {
                            $term = get_term_by('name', $option, $taxonomy);
                            
                            if (!$term) {
                                // Try to find by slug
                                $term = get_term_by('slug', sanitize_title($option), $taxonomy);
                            }
                            
                            if (!$term) {
                                // Create the term
                                $term_data = wp_insert_term($option, $taxonomy);
                                if (!is_wp_error($term_data)) {
                                    $attribute_terms[] = $term_data['term_id'];
                                }
                            } else {
                                $attribute_terms[] = $term->term_id;
                            }
                        }
                    }
                }
            }

            $wc_attribute = new WC_Product_Attribute();
            $wc_attribute->set_name($is_taxonomy ? $taxonomy : sanitize_title($attribute['name']));
            $wc_attribute->set_options($is_taxonomy ? $attribute_terms : (isset($attribute['options']) ? $attribute['options'] : array()));
            $wc_attribute->set_position(isset($attribute['position']) ? $attribute['position'] : 0);
            $wc_attribute->set_visible(isset($attribute['visible']) ? (bool) $attribute['visible'] : true);
            $wc_attribute->set_variation(isset($attribute['variation']) ? (bool) $attribute['variation'] : false);
            $wc_attributes[] = $wc_attribute;
        }

        return $wc_attributes;
    }

    /**
     * Handle product images
     *
     * @param int $product_id Product ID
     * @param array $images Images from API
     * @return int Number of images processed
     */
    private function handle_product_images($product_id, $images) {
        $image_count = 0;
        $gallery_image_ids = array();
        
        // Process each image
        foreach ($images as $image) {
            if (empty($image['src'])) {
                continue;
            }
            
            // Try to find existing attachment by source URL
            $attachment_id = $this->get_attachment_by_url($image['src']);
            
            if (!$attachment_id) {
                // Download and create new attachment
                $attachment_id = $this->upload_image($image['src'], isset($image['alt']) ? $image['alt'] : '');
                
                if (!$attachment_id) {
                    continue;
                }
            }
            
            // Set as featured image if it's the first one
            if ($image_count === 0) {
                set_post_thumbnail($product_id, $attachment_id);
            } else {
                $gallery_image_ids[] = $attachment_id;
            }
            
            $image_count++;
        }
        
        // Update product gallery
        if (!empty($gallery_image_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_image_ids));
        }
        
        return $image_count;
    }
    
    /**
     * Get attachment by URL
     *
     * @param string $url Image URL
     * @return int|false Attachment ID or false
     */
    private function get_attachment_by_url($url) {
        global $wpdb;
        
        // Clean the URL
        $url = preg_replace('/([?&])ver=[^&]+/', '', $url);
        
        // Query for attachments with matching URL
        $attachment = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE guid LIKE %s AND post_type = 'attachment'",
            '%' . $wpdb->esc_like(basename($url)) . '%'
        ));
        
        if (!empty($attachment[0])) {
            return (int) $attachment[0];
        }
        
        return false;
    }

    /**
     * Upload and create image attachment
     *
     * @param string $url Image URL
     * @param string $alt Image alt text
     * @return int|false Attachment ID or false on failure
     */
    private function upload_image($url, $alt = '') {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Download image from URL
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            $this->logger->log_sync(
                'Image Download',
                'error',
                sprintf('Failed to download image from %s: %s', $url, $tmp->get_error_message())
            );
            return false;
        }
        
        // Prepare file data for wp_handle_sideload
        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );
        
        // Handle sideload
        $result = wp_handle_sideload($file_array, array('test_form' => false));
        
        if (isset($result['error'])) {
            @unlink($tmp);
            $this->logger->log_sync(
                'Image Upload',
                'error',
                sprintf('Failed to upload image: %s', $result['error'])
            );
            return false;
        }
        
        // Create attachment from uploaded file
        $attachment = array(
            'post_mime_type' => $result['type'],
            'post_title' => sanitize_file_name(basename($result['file'])),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        // Insert attachment
        $attachment_result = wp_insert_attachment($attachment, $result['file']);
        
        if (is_wp_error($attachment_result)) {
            $this->logger->log_sync(
                'Image Attachment',
                'error',
                sprintf('Failed to create attachment: %s', $attachment_result->get_error_message())
            );
            return false;
        } else if (!(is_int($attachment_result) && $attachment_result > 0)) {
             $this->logger->log_sync(
                'Image Attachment',
                'error',
                'Failed to create attachment: wp_insert_attachment returned non-ID value ' . print_r($attachment_result, true)
            );
            return false;
        }
        
        $attachment_id = $attachment_result;
        
        // Generate metadata and update attachment
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $result['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        // Set alt text if provided
        if (!empty($alt)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }
        
        return $attachment_id;
    }
} 