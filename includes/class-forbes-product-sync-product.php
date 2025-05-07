<?php
/**
 * Product Handler class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forbes_Product_Sync_Product {
    /**
     * API instance
     *
     * @var Forbes_Product_Sync_API
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
        $this->api = new Forbes_Product_Sync_API();
        $this->logger = new Forbes_Product_Sync_Logger();
    }

    /**
     * Get product by SKU
     *
     * @param string $sku
     * @return WC_Product|false
     */
    private function get_product_by_sku($sku) {
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
            return new WP_Error('missing_sku', 'Product SKU is required');
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
        $product = new WC_Product_Simple();
        $changes = array('action' => 'created');
        
        // Set basic product data
        $product->set_name($product_data['name']);
        $product->set_sku($product_data['sku']);
        $product->set_regular_price($product_data['regular_price']);
        $product->set_sale_price($product_data['sale_price']);
        $product->set_description($product_data['description']);
        $product->set_short_description($product_data['short_description']);
        $product->set_status($product_data['status']);
        $product->set_catalog_visibility($product_data['catalog_visibility']);
        $product->set_featured($product_data['featured']);
        $product->set_weight($product_data['weight']);
        $product->set_length($product_data['dimensions']['length']);
        $product->set_width($product_data['dimensions']['width']);
        $product->set_height($product_data['dimensions']['height']);

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
        $product_id = $product->save();

        if (is_wp_error($product_id)) {
            $this->logger->log_sync(
                $product_data['name'],
                'error',
                $product_id->get_error_message()
            );
            return $product_id;
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
            return new WP_Error('product_not_found', 'Product not found');
        }

        // Track changes
        if ($product->get_name() !== $product_data['name']) {
            $changes['name'] = sprintf('"%s" → "%s"', $product->get_name(), $product_data['name']);
        }
        if ($product->get_regular_price() !== $product_data['regular_price']) {
            $changes['price'] = sprintf('$%s → $%s', $product->get_regular_price(), $product_data['regular_price']);
        }
        if ($product->get_sale_price() !== $product_data['sale_price']) {
            $changes['sale_price'] = sprintf('$%s → $%s', $product->get_sale_price(), $product_data['sale_price']);
        }
        if ($product->get_description() !== $product_data['description']) {
            $changes['description'] = 'Updated';
        }
        if ($product->get_short_description() !== $product_data['short_description']) {
            $changes['short_description'] = 'Updated';
        }

        // Update basic product data
        $product->set_name($product_data['name']);
        $product->set_regular_price($product_data['regular_price']);
        $product->set_sale_price($product_data['sale_price']);
        $product->set_description($product_data['description']);
        $product->set_short_description($product_data['short_description']);
        $product->set_status($product_data['status']);
        $product->set_catalog_visibility($product_data['catalog_visibility']);
        $product->set_featured($product_data['featured']);
        $product->set_weight($product_data['weight']);
        $product->set_length($product_data['dimensions']['length']);
        $product->set_width($product_data['dimensions']['width']);
        $product->set_height($product_data['dimensions']['height']);

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
        $result = $product->save();

        if (is_wp_error($result)) {
            $this->logger->log_sync(
                $product_data['name'],
                'error',
                $result->get_error_message()
            );
            return $result;
        }

        // Handle images
        if (!empty($product_data['images'])) {
            $this->handle_product_images($product_id, $product_data['images']);
            $changes['images'] = 'Updated images';
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
     * Get or create categories
     *
     * @param array $categories Category data from API
     * @return array Category IDs
     */
    private function get_or_create_categories($categories) {
        $category_ids = array();

        foreach ($categories as $category) {
            $term = get_term_by('slug', $category['slug'], 'product_cat');

            if (!$term) {
                $term = wp_insert_term($category['name'], 'product_cat', array(
                    'slug' => $category['slug']
                ));

                if (!is_wp_error($term)) {
                    $category_ids[] = $term['term_id'];
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
     * @param array $attributes Attribute data from API
     * @return array Prepared attributes
     */
    private function prepare_attributes($attributes) {
        $prepared_attributes = array();

        foreach ($attributes as $attribute) {
            $attribute_name = $attribute['name'];
            $attribute_slug = wc_sanitize_taxonomy_name($attribute_name);
            $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);

            if (!$attribute_id) {
                // Create attribute if it doesn't exist
                $attribute_id = wc_create_attribute(array(
                    'name' => $attribute_name,
                    'slug' => $attribute_slug,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));
            }

            $taxonomy = wc_attribute_taxonomy_name($attribute_slug);
            $terms = array();

            foreach ($attribute['options'] as $option) {
                $term = get_term_by('slug', $option, $taxonomy);

                if (!$term) {
                    $term = wp_insert_term($option, $taxonomy);
                    if (!is_wp_error($term)) {
                        $terms[] = $term['term_id'];
                    }
                } else {
                    $terms[] = $term->term_id;
                }
            }

            $prepared_attributes[] = array(
                'id' => $attribute_id,
                'name' => $attribute_name,
                'position' => 0,
                'visible' => true,
                'variation' => false,
                'options' => $terms
            );
        }

        return $prepared_attributes;
    }

    /**
     * Handle product images
     *
     * @param int $product_id Product ID
     * @param array $images Image data from API
     */
    private function handle_product_images($product_id, $images) {
        $attachment_ids = array();

        foreach ($images as $index => $image) {
            $attachment_id = $this->upload_image($image['src'], $image['alt']);

            if (!is_wp_error($attachment_id)) {
                $attachment_ids[] = $attachment_id;

                if ($index === 0) {
                    set_post_thumbnail($product_id, $attachment_id);
                }
            }
        }

        if (!empty($attachment_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $attachment_ids));
        }
    }

    /**
     * Upload an image from URL
     *
     * @param string $url Image URL
     * @param string $alt Image alt text
     * @return int|WP_Error Attachment ID on success, WP_Error on failure
     */
    private function upload_image($url, $alt) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $temp_file = download_url($url);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $temp_file
        );

        $attachment_id = media_handle_sideload($file_array, 0, $alt);

        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return $attachment_id;
        }

        return $attachment_id;
    }
} 