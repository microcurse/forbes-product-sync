<?php
/**
 * AJAX class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_AJAX Class.
 */
class FPS_AJAX {
    /**
     * Initialize AJAX handlers.
     */
    public static function init() {
        // The problematic action for define_ajax is now removed.

        // Test connection AJAX handler
        add_action( 'wp_ajax_fps_test_connection', array( __CLASS__, 'test_connection' ) );
        add_action( 'wp_ajax_fps_sync_single_attribute', array( __CLASS__, 'sync_single_attribute' ) );
        add_action( 'wp_ajax_fps_sync_single_product', array( __CLASS__, 'sync_single_product' ) );
        add_action( 'wp_ajax_fps_clear_sync_logs', array( __CLASS__, 'clear_sync_logs' ) );
    }

    /**
     * Test the connection to the remote site.
     */
    public static function test_connection() {
        check_ajax_referer( 'fps-admin-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( -1 );
        }

        $remote_url = get_option( 'fps_remote_site_url', '' );
        $username   = get_option( 'fps_api_username', '' );
        $password   = get_option( 'fps_api_password', '' );

        if ( empty( $remote_url ) || empty( $username ) || empty( $password ) ) {
            wp_send_json_error( array( 'message' => __( 'Please save your API settings first.', 'forbes-product-sync' ) ) );
        }

        // Changed to a more general WooCommerce products endpoint for testing
        $test_endpoint = trailingslashit( $remote_url ) . 'wp-json/wc/v3/products?per_page=1'; 
        
        $response = wp_remote_get(
            $test_endpoint,
            array(
                'timeout'     => 30,
                'headers'     => array(
                    'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
                ),
                'sslverify'   => apply_filters( 'fps_sslverify', true ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
            return;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = wp_remote_retrieve_body( $response );

        if ( 200 !== $response_code ) {
            $error_message = wp_remote_retrieve_response_message( $response );
            $decoded_body = json_decode( $body );
            if ( $decoded_body && ! empty( $decoded_body->message ) ) {
                $error_message = $decoded_body->message; // Use error message from WC if available
            }
            $error = sprintf(
                __( 'Error connecting to WooCommerce API: %1$s (HTTP %2$d)', 'forbes-product-sync' ),
                $error_message,
                $response_code
            );
            wp_send_json_error( array( 'message' => $error ) );
            return;
        }

        $data = json_decode( $body );
        
        // Check if the response is an array (products endpoint returns an array)
        if ( empty( $data ) || ! is_array( $data ) ) {
            // Check if the body itself was the permission error from WC
            if (is_object($data) && isset($data->code) && strpos($data->code, 'rest_cannot_view') !== false) {
                 $error_message = isset($data->message) ? $data->message : 'Permission denied for test endpoint.';
                 wp_send_json_error( array( 'message' => sprintf(__( 'Connection Test Failed: %s Please ensure the API user has permissions for /wc/v3/products.', 'forbes-product-sync'), $error_message) ) );
            } else {
                wp_send_json_error( array( 'message' => __( 'Invalid WooCommerce API response format from products endpoint.', 'forbes-product-sync' ) . ' (Raw: ' . esc_html(substr($body, 0, 200)) . ')' ) );
            }
            return;
        }

        wp_send_json_success( array( 'message' => __( 'Connection successful! The remote site\'s WooCommerce API (products endpoint) is accessible.', 'forbes-product-sync' ) ) );
    }

    /**
     * Sync a single attribute and its terms from the remote site.
     */
    public static function sync_single_attribute() {
        check_ajax_referer( 'fps-admin-nonce', 'nonce' );
        $source_attribute_id = isset($_POST['attribute_id']) ? absint( $_POST['attribute_id'] ) : 0;
        $action_item_name = 'Attribute Sync (Source ID: ' . $source_attribute_id . ')';

        // Ensure wc-attribute-functions.php is loaded, as it contains wc_create_attribute, wc_attribute_taxonomy_name, etc.
        if ( ! function_exists( 'wc_create_attribute' ) || ! function_exists( 'wc_attribute_taxonomy_name' ) || ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
            $wc_attribute_functions_path = WP_PLUGIN_DIR . '/woocommerce/includes/wc-attribute-functions.php';
            if ( file_exists( $wc_attribute_functions_path ) ) {
                include_once $wc_attribute_functions_path;
            } else {
                // This is a critical failure if the file is missing.
                $error_msg = 'CRITICAL ERROR: The file woocommerce/includes/wc-attribute-functions.php was not found. Essential attribute functions are unavailable.';
                FPS_Logger::log('attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id);
                wp_send_json_error( array( 'message' => $error_msg ) );
                return;
            }
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            $error_msg = __( 'User does not have permission to manage options.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ), 403 );
            return;
        }

        if ( $source_attribute_id <= 0 ) {
            $error_msg = __( 'Invalid or missing Attribute ID.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ), 400 );
            return;
        }

        $remote_url = get_option( 'fps_remote_site_url', '' );
        $username   = get_option( 'fps_api_username', '' );
        $password   = get_option( 'fps_api_password', '' );

        if ( empty( $remote_url ) || empty( $username ) || empty( $password ) ) {
            $error_msg = __( 'API credentials are not configured.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ), 400 );
            return;
        }

        $auth_header = 'Basic ' . base64_encode( $username . ':' . $password );
        $api_args = array(
            'headers'   => array( 'Authorization' => $auth_header ),
            'timeout'   => 45,
            'sslverify' => apply_filters( 'fps_sync_sslverify', true ),
        );

        // 1. Fetch Attribute Details from Source (to get its slug and name)
        $attr_api_url = trailingslashit( $remote_url ) . "wp-json/wc/v3/products/attributes/{$source_attribute_id}";
        $attr_response = wp_remote_get( $attr_api_url, $api_args );

        if ( is_wp_error( $attr_response ) ) {
            $error_msg = sprintf( __( 'Error fetching attribute details: %s', 'forbes-product-sync' ), $attr_response->get_error_message() );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        $attr_response_code = wp_remote_retrieve_response_code( $attr_response );
        $attr_response_body = wp_remote_retrieve_body( $attr_response );
        if ( 200 !== $attr_response_code ) {
            $error_data = json_decode( $attr_response_body );
            $error_message_detail = isset($error_data->message) ? $error_data->message : wp_remote_retrieve_response_message( $attr_response );
            $error_msg = sprintf( __( 'Error fetching attribute details (HTTP %1$d): %2$s', 'forbes-product-sync' ), $attr_response_code, $error_message_detail );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        $source_attribute_meta = json_decode( $attr_response_body );
        if ( ! $source_attribute_meta || ! isset( $source_attribute_meta->slug ) ) {
            $error_msg = __( 'Invalid attribute metadata received from source.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        $action_item_name = $source_attribute_meta->name ?? $action_item_name;
        
        // Derive the base slug (e.g., 'laminate-color') from the source slug (which might be 'pa_laminate-color' or 'laminate-color')
        $raw_source_slug = $source_attribute_meta->slug ?? '';
        $base_attribute_slug = $raw_source_slug;
        if ( strpos( $raw_source_slug, 'pa_' ) === 0 ) {
            $base_attribute_slug = substr( $raw_source_slug, 3 );
        }
        // Ensure base_attribute_slug is not empty if raw_source_slug was just 'pa_' or empty after stripping
        if ( empty($base_attribute_slug) && !empty($raw_source_slug) ) {
             $base_attribute_slug = sanitize_title($source_attribute_meta->name ?? 'temp-attr-' . time()); // Fallback slug from name
             FPS_Logger::log('attribute_sync', 'WARNING', $action_item_name, "Source slug ('{$raw_source_slug}') resulted in an empty base slug. Fallback to slug derived from name: '{$base_attribute_slug}'.", $source_attribute_id);
        } else if (empty($base_attribute_slug) && empty($raw_source_slug)) {
            $base_attribute_slug = sanitize_title($source_attribute_meta->name ?? 'temp-attr-' . time());
            FPS_Logger::log('attribute_sync', 'WARNING', $action_item_name, "Source slug was empty. Fallback to slug derived from name: '{$base_attribute_slug}'.", $source_attribute_id);
        }

        FPS_Logger::log('attribute_sync', 'INFO', $action_item_name, "Derived base_attribute_slug: '{$base_attribute_slug}' from raw_source_slug: '{$raw_source_slug}'", $source_attribute_id);

        // Construct the REST base for the /wp/v2/ terms endpoint on the source site.
        // It should be like 'pa_laminate-color'.
        $base_slug_from_source_attr = $source_attribute_meta->slug;
        if (strpos($base_slug_from_source_attr, 'pa_') === 0) {
            // The slug from /wc/v3/products/attributes/{id} already starts with 'pa_'. Use it directly.
            $source_rest_base_taxonomy_slug = $base_slug_from_source_attr;
            FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Source attribute slug '{$base_slug_from_source_attr}' already starts with 'pa_'. Using as is: '{$source_rest_base_taxonomy_slug}' for /wp/v2/ terms endpoint.", $source_attribute_id );
        } else {
            // The slug is a base slug (e.g., 'laminate-color'). Prepend 'pa_'.
            $source_rest_base_taxonomy_slug = 'pa_' . $base_slug_from_source_attr;
            FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Source attribute slug is '{$base_slug_from_source_attr}'. Prepended 'pa_' to form: '{$source_rest_base_taxonomy_slug}' for /wp/v2/ terms endpoint.", $source_attribute_id );
        }

        // 2. Fetch Attribute Terms from Source using /wp/v2/ endpoint
        $terms_api_url = trailingslashit( $remote_url ) . "wp-json/wp/v2/{$source_rest_base_taxonomy_slug}";
        $terms_api_url_with_params = add_query_arg(array(
            'per_page' => 100,
            'context'  => 'edit' 
        ), $terms_api_url);
        
        FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Fetching terms from (context=edit): {$terms_api_url_with_params}", $source_attribute_id );

        // --- IMPORTANT: Authentication Details for SOURCE SITE's /wp/v2/ Term Endpoints ---
        // Replace with your actual admin username on portal.forbesindustries.com
        $source_wp_admin_username = 'mmaninang'; 
        // Replace with the Application Password you generated and successfully used in Postman
        $source_wp_application_password = 'FJPc 9NGF 9LuG 7IRb dAvx ut1a'; 

        $terms_api_args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $source_wp_admin_username . ':' . $source_wp_application_password ),
                // It's good practice to set a User-Agent
                'User-Agent'    => 'Forbes Product Sync Plugin/' . (defined('FPS_VERSION') ? FPS_VERSION : '1.0')
            ),
            'timeout'   => 45, // Increased timeout for potentially larger responses
            'sslverify' => apply_filters( 'fps_sync_sslverify', true ), // Use existing filter, but ideally 'false' for local if issues
        );
        
        FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Request Args for terms: " . print_r(array_merge($terms_api_args, ['headers' => ['Authorization' => 'Basic [REDACTED]']]), true), $source_attribute_id );

        $terms_response = wp_remote_get( $terms_api_url_with_params, $terms_api_args );

        if ( is_wp_error( $terms_response ) ) {
            $error_msg = sprintf( __( 'Error fetching terms for %1$s: %2$s', 'forbes-product-sync' ), $source_rest_base_taxonomy_slug, $terms_response->get_error_message());
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        $terms_response_code = wp_remote_retrieve_response_code( $terms_response );
        $terms_response_body = wp_remote_retrieve_body( $terms_response );
        if ( 200 !== $terms_response_code ) {
            $error_data = json_decode( $terms_response_body );
            $error_message_detail = isset($error_data->message) ? $error_data->message : wp_remote_retrieve_response_message( $terms_response );
            $error_msg = sprintf( __( 'Error fetching terms for %1$s (HTTP %2$d): %3$s', 'forbes-product-sync' ), $source_rest_base_taxonomy_slug, $terms_response_code, $error_message_detail );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        $source_terms = json_decode( $terms_response_body );
        if ( ! is_array( $source_terms ) ) {
            $error_msg = sprintf(__( 'Invalid terms data received for %1$s. Expected array. Body: %2$s', 'forbes-product-sync' ), $source_rest_base_taxonomy_slug, esc_html(substr($terms_response_body,0, 250)));
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }

        // 3. Process Attribute on Destination Site
        $local_attribute_id_for_log = null;
        $local_attribute_slug = $source_attribute_meta->slug;
        $local_attribute_id = wc_attribute_taxonomy_id_by_name( $base_attribute_slug );
        $attribute_message = '';

        if ( ! $local_attribute_id ) {
            $args = array(
                'name'         => $source_attribute_meta->name,
                'slug'         => $base_attribute_slug,
                'type'         => $source_attribute_meta->type,
                'order_by'     => $source_attribute_meta->order_by,
                'has_archives' => $source_attribute_meta->has_archives,
            );
            $created_id = wc_create_attribute( $args );

            if ( is_wp_error( $created_id ) ) {
                $error_msg = sprintf( __( 'Error creating attribute "%s": %s', 'forbes-product-sync' ), $source_attribute_meta->name, $created_id->get_error_message() );
                FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
                wp_send_json_error( array( 'message' => $error_msg ) );
                return;
            }
            $local_attribute_id = $created_id;
            $local_attribute_id_for_log = $created_id;
            $attribute_message = sprintf( __( 'Attribute "%s" created. ', 'forbes-product-sync' ), $source_attribute_meta->name );
        } else {
            $local_attribute_id_for_log = $local_attribute_id;
            $attribute_message = sprintf( __( 'Attribute "%s" already exists. ', 'forbes-product-sync' ), $source_attribute_meta->name );
        }

        // 4. Process Terms
        $local_taxonomy_name = wc_attribute_taxonomy_name( $base_attribute_slug );

        // Check if taxonomy name generation was successful (it should be if wc_attribute_taxonomy_name is loaded)
        if ( empty( $local_taxonomy_name ) ) {
            $error_msg = "Could not determine local taxonomy name for base slug: '{$base_attribute_slug}'. wc_attribute_taxonomy_name() might have failed or returned empty.";
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id, $local_attribute_id_for_log );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }

        $terms_synced_count = 0;
        $terms_updated_count = 0;
        $terms_failed_count = 0;

        foreach ( $source_terms as $source_term ) {
            if ( ! isset( $source_term->name ) || ! isset( $source_term->slug ) ) {
                FPS_Logger::log( 'attribute_sync', 'WARNING', $action_item_name, "Skipping term with missing name or slug: " . print_r($source_term, true), $source_attribute_id );
                $terms_failed_count++;
                continue;
            }
            
            $term_data_for_log = sprintf("Source Term: Name='%s', Slug='%s', Suffix='%s', Price='%s', SwatchFullURL='%s'", 
                $source_term->name, 
                $source_term->slug,
                $source_term->term_suffix ?? 'N/A',
                $source_term->term_price ?? 'N/A',
                isset($source_term->swatch_image_details->full_url) ? $source_term->swatch_image_details->full_url : 'N/A'
            );
            FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Processing term data: {$term_data_for_log}", $source_attribute_id, $local_attribute_id_for_log );

            $term_exists_check = term_exists( $source_term->slug, $local_taxonomy_name );
            $local_term_id = 0;

            if ( ! $term_exists_check ) {
                $new_term_args = array( 
                    'slug' => $source_term->slug,
                    'description' => $source_term->description ?? ''
                );
                $term_result = wp_insert_term( $source_term->name, $local_taxonomy_name, $new_term_args );
                if ( is_wp_error( $term_result ) ) {
                    $term_error_msg = sprintf(__( 'Error creating term "%1$s": %2$s', 'forbes-product-sync' ), $source_term->name, $term_result->get_error_message());
                    FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $term_error_msg, $source_attribute_id, $local_attribute_id_for_log );
                    $terms_failed_count++;
                    continue;
                } 
                $local_term_id = $term_result['term_id'];
                $terms_synced_count++;
                FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Created new term '{$source_term->name}' (ID: {$local_term_id}) for taxonomy '{$local_taxonomy_name}'.", $source_attribute_id, $local_attribute_id_for_log );
            } else {
                $local_term_id = $term_exists_check['term_id'];
                $current_local_term = get_term($local_term_id, $local_taxonomy_name);
                $update_args = array();
                if ($current_local_term && $current_local_term->name !== $source_term->name) $update_args['name'] = $source_term->name;
                if (isset($source_term->description) && $current_local_term && $current_local_term->description !== $source_term->description) $update_args['description'] = $source_term->description;
                if (!empty($update_args) && $current_local_term) {
                    wp_update_term($local_term_id, $local_taxonomy_name, $update_args);
                }
                $terms_updated_count++;
                 FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Found existing term '{$source_term->name}' (ID: {$local_term_id}) for taxonomy '{$local_taxonomy_name}'. Will update meta.", $source_attribute_id, $local_attribute_id_for_log );
            }

            if ( $local_term_id > 0 ) {
                if ( isset( $source_term->term_price ) ) {
                    update_term_meta( $local_term_id, 'term_price', sanitize_text_field( $source_term->term_price ) );
                }
                if ( isset( $source_term->term_suffix ) ) {
                    update_term_meta( $local_term_id, '_term_suffix', sanitize_text_field( $source_term->term_suffix ) );
                }
                if ( isset( $source_term->swatch_image_details ) && isset($source_term->swatch_image_details->full_url) && !empty( $source_term->swatch_image_details->full_url ) ) {
                    $image_url = $source_term->swatch_image_details->full_url;
                    $image_desc = $source_term->name . ' Swatch';
                    $attachment_id = self::_sideload_image( $image_url, 0, $image_desc );
                    if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
                        update_term_meta( $local_term_id, 'thumbnail_id', $attachment_id );
                        FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Updated swatch image (thumbnail_id: {$attachment_id}) for term '{$source_term->name}'.", $source_attribute_id, $local_attribute_id_for_log );
                    } else {
                        $image_error_msg = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'Unknown error';
                        FPS_Logger::log( 'attribute_sync', 'WARNING', $action_item_name, sprintf(__( 'Failed to sideload swatch image for term "%1$s" from %2$s: %3$s', 'forbes-product-sync' ), $source_term->name, $image_url, $image_error_msg), $source_attribute_id, $local_attribute_id_for_log );
                        $terms_failed_count++;
                    }
                } else if (isset( $source_term->swatch_image_details ) && ( !isset($source_term->swatch_image_details->full_url) || empty( $source_term->swatch_image_details->full_url ) ) ) {
                    delete_term_meta( $local_term_id, 'thumbnail_id' );
                    FPS_Logger::log( 'attribute_sync', 'INFO', $action_item_name, "Removed swatch image (thumbnail_id) for term '{$source_term->name}' as source has no image URL or it is empty.", $source_attribute_id, $local_attribute_id_for_log );
                }
            }
        }
        
        $final_message = $attribute_message;
        if ( $terms_synced_count > 0 ) $final_message .= sprintf( _n( '%d term created. ', '%d terms created. ', $terms_synced_count, 'forbes-product-sync' ), $terms_synced_count );
        if ( $terms_updated_count > 0 ) $final_message .= sprintf( _n( '%d term updated. ', '%d terms updated. ', $terms_updated_count, 'forbes-product-sync' ), $terms_updated_count );
        if ( $terms_failed_count > 0 ) $final_message .= sprintf( _n( '%d term failed to sync completely. ', '%d terms failed to sync completely. ', $terms_failed_count, 'forbes-product-sync' ), $terms_failed_count );
        
        if ( $terms_synced_count === 0 && $terms_updated_count === 0 && $terms_failed_count === 0 && count($source_terms) > 0) {
            $final_message .= __( 'All terms already up-to-date.', 'forbes-product-sync' );
        } elseif (count($source_terms) === 0) {
            $final_message .= __( 'Attribute has no terms on source to sync.', 'forbes-product-sync' );
        }

        FPS_Logger::log( 'attribute_sync', 'SUCCESS', $action_item_name, trim($final_message), $source_attribute_id, $local_attribute_id_for_log );

        wp_send_json_success( array( 'message' => trim($final_message) /* 'diagnostic_info' => $diagnostic_info */ ) ); // diagnostic_info removed
    }

    /**
     * Sync a single product and its variations from the remote site.
     */
    public static function sync_single_product() {
        check_ajax_referer( 'fps-admin-nonce', 'nonce' );
        $action_item_name = 'Product ID: ' . (isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : 'N/A');
        $source_product_id_for_log = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : null;
        $product_name_for_log = isset($_POST['product_name']) ? sanitize_text_field($_POST['product_name']) : 'Product';

        if ( ! current_user_can( 'manage_options' ) ) {
            $error_msg = __( 'User does not have permission to perform this action.', 'forbes-product-sync' );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_log, $error_msg, $source_product_id_for_log);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'PERMISSION_DENIED' ), 403 );
            return;
        }

        if ( ! isset( $_POST['product_id'] ) ) {
            $error_msg = __( 'Product ID not provided.', 'forbes-product-sync' );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_log, $error_msg, $source_product_id_for_log);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'MISSING_PARAM_PRODUCT_ID' ), 400 );
            return;
        }
        $source_product_id = absint( $_POST['product_id'] );
        $product_name_for_error = isset($_POST['product_name']) ? sanitize_text_field($_POST['product_name']) : 'Product (ID: ' . $source_product_id . ')';

        if ( $source_product_id <= 0 ) {
            $error_msg = __( 'Invalid Product ID provided.', 'forbes-product-sync' );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_error, $error_msg, $source_product_id_for_log);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'INVALID_PRODUCT_ID' ), 400 );
            return;
        }

        $remote_url = get_option( 'fps_remote_site_url', '' );
        $username   = get_option( 'fps_api_username', '' );
        $password   = get_option( 'fps_api_password', '' );

        if ( empty( $remote_url ) || empty( $username ) || empty( $password ) ) {
            $error_msg = __( 'API credentials are not configured. Please configure them in settings.', 'forbes-product-sync' );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_log, $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_CREDENTIALS_MISSING' ), 400 );
            return;
        }

        $auth_header = 'Basic ' . base64_encode( $username . ':' . $password );
        $api_args = array(
            'headers'   => array( 'Authorization' => $auth_header ),
            'timeout'   => 60,
            'sslverify' => apply_filters( 'fps_product_sync_sslverify', true ),
        );

        // 1. Fetch Full Product Data from Source
        $product_api_url = trailingslashit( $remote_url ) . "wp-json/wc/v3/products/{$source_product_id}";
        $product_response = wp_remote_get( $product_api_url, $api_args );

        if ( is_wp_error( $product_response ) ) {
            $error_msg = sprintf( __( 'Error fetching product data for "%1$s" (ID: %2$d): %3$s', 'forbes-product-sync' ), $product_name_for_error, $source_product_id, $product_response->get_error_message() );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_error, $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_FETCH_ERROR_PRODUCT' ) );
            return;
        }

        $product_response_code = wp_remote_retrieve_response_code( $product_response );
        $product_response_body = wp_remote_retrieve_body( $product_response );

        if ( 200 !== $product_response_code ) {
            $error_data = json_decode( $product_response_body );
            $error_message_detail = isset($error_data->message) ? $error_data->message : wp_remote_retrieve_response_message( $product_response );
            $error_msg = sprintf( __( 'Error fetching product data for "%1$s" (ID: %2$d) (HTTP %3$d): %4$s. URL: %5$s', 'forbes-product-sync' ), $product_name_for_error, $source_product_id, $product_response_code, $error_message_detail, esc_url($product_api_url) );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_error, $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_FETCH_ERROR_PRODUCT_HTTP', 'http_code' => $product_response_code ) );
            return;
        }

        $source_product_data = json_decode( $product_response_body );
        if ( ! $source_product_data || ! isset( $source_product_data->id ) ) {
            $error_msg = sprintf( __( 'Invalid product data received from source for "%1$s" (ID: %2$d). JSON Error: %3$s', 'forbes-product-sync' ), $product_name_for_error, $source_product_id, json_last_error_msg() );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_error, $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_INVALID_JSON_PRODUCT' ) );
            return;
        }
        $product_name_for_log = $source_product_data->name ?? $product_name_for_error;

        if ( empty( $source_product_data->sku ) ) {
            $error_msg = sprintf( __( 'Product "%1$s" (ID: %2$d) from source has no SKU. Cannot sync without an SKU.', 'forbes-product-sync' ), $product_name_for_log, $source_product_id );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_log, $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'MISSING_SKU' ) );
            return;
        }

        $existing_product_id = wc_get_product_id_by_sku( $source_product_data->sku );
        if ( $existing_product_id ) {
            $skip_msg = sprintf( __( 'Product "%1$s" (SKU: %2$s) already exists locally (ID: %3$d). Skipping.', 'forbes-product-sync' ), $product_name_for_log, $source_product_data->sku, $existing_product_id );
            FPS_Logger::log('product_sync', 'SKIPPED', $product_name_for_log, $skip_msg, $source_product_id, $existing_product_id);
            wp_send_json_error( array( 'message' => $skip_msg, 'status_code' => 'SKU_EXISTS' ) );
            return;
        }
        
        $product_type = $source_product_data->type ?? 'simple';
        $classname    = WC_Product_Factory::get_product_classname( 0, $product_type );
        $product      = new $classname();

        if ( ! $product ) {
            $error_msg = sprintf( __( 'Failed to instantiate product object for "%s".', 'forbes-product-sync' ), $product_name_for_log );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_log, $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'PRODUCT_INSTANTIATION_FAILED' ) );
            return;
        }

        $product->set_name( sanitize_text_field( $source_product_data->name ) );
        if ( isset( $source_product_data->slug ) ) $product->set_slug( sanitize_title( $source_product_data->slug ) );
        if ( isset( $source_product_data->description ) ) $product->set_description( wp_kses_post( $source_product_data->description ) );
        if ( isset( $source_product_data->short_description ) ) $product->set_short_description( wp_kses_post( $source_product_data->short_description ) );
        $product->set_sku( sanitize_text_field( $source_product_data->sku ) );
        $product->set_status( isset( $source_product_data->status ) ? sanitize_key( $source_product_data->status ) : 'publish' );
        $product->set_catalog_visibility( isset( $source_product_data->catalog_visibility ) ? sanitize_key( $source_product_data->catalog_visibility ) : 'visible' );

        if ( isset( $source_product_data->regular_price ) ) $product->set_regular_price( $source_product_data->regular_price === "" ? '' : wc_format_decimal( $source_product_data->regular_price ) );
        if ( isset( $source_product_data->sale_price ) ) $product->set_sale_price( $source_product_data->sale_price === "" ? '' : wc_format_decimal( $source_product_data->sale_price ) );
        if ( isset( $source_product_data->date_on_sale_from_gmt ) && !empty($source_product_data->date_on_sale_from_gmt) ) $product->set_date_on_sale_from( strtotime( $source_product_data->date_on_sale_from_gmt ) );
        if ( isset( $source_product_data->date_on_sale_to_gmt ) && !empty($source_product_data->date_on_sale_to_gmt) ) $product->set_date_on_sale_to( strtotime( $source_product_data->date_on_sale_to_gmt ) );
        
        $product->set_manage_stock( isset( $source_product_data->manage_stock ) ? (bool) $source_product_data->manage_stock : false );
        if ( $product->get_manage_stock() && isset( $source_product_data->stock_quantity ) ) $product->set_stock_quantity( intval( $source_product_data->stock_quantity ) );
        $product->set_stock_status( isset( $source_product_data->stock_status ) ? sanitize_key( $source_product_data->stock_status ) : 'instock' );
        
        $product->set_virtual( isset( $source_product_data->virtual ) ? (bool) $source_product_data->virtual : false );
        $product->set_downloadable( isset( $source_product_data->downloadable ) ? (bool) $source_product_data->downloadable : false );

        if (isset($source_product_data->weight) && $source_product_data->weight !== "") $product->set_weight(wc_format_decimal($source_product_data->weight));
        if (isset($source_product_data->dimensions)) {
            if (isset($source_product_data->dimensions->length) && $source_product_data->dimensions->length !== "") $product->set_length(wc_format_decimal($source_product_data->dimensions->length));
            if (isset($source_product_data->dimensions->width) && $source_product_data->dimensions->width !== "") $product->set_width(wc_format_decimal($source_product_data->dimensions->width));
            if (isset($source_product_data->dimensions->height) && $source_product_data->dimensions->height !== "") $product->set_height(wc_format_decimal($source_product_data->dimensions->height));
        }

        if ( ! empty( $source_product_data->categories ) ) {
            $category_ids = self::_map_and_create_terms( $source_product_data->categories, 'product_cat' );
            if ( ! empty( $category_ids ) ) $product->set_category_ids( $category_ids );
        }

        if ( ! empty( $source_product_data->tags ) ) {
            $tag_ids = self::_map_and_create_terms( $source_product_data->tags, 'product_tag' );
            if ( ! empty( $tag_ids ) ) $product->set_tag_ids( $tag_ids );
        }
        
        $attributes_array = array();
        if ( ! empty( $source_product_data->attributes ) && is_array( $source_product_data->attributes ) ) {
            foreach ( $source_product_data->attributes as $source_attr ) {
                if ( ! isset( $source_attr->name ) ) continue;
                $attribute_slug = isset($source_attr->slug) && !empty($source_attr->slug) ? sanitize_title($source_attr->slug) : sanitize_title($source_attr->name);
                $local_attr_taxonomy_id = wc_attribute_taxonomy_id_by_name( $attribute_slug );
                $wc_product_attribute = new WC_Product_Attribute();
                if ( $local_attr_taxonomy_id ) {
                    $taxonomy_name = '';
                    if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
                        $taxonomy_name = wc_attribute_taxonomy_name( $attribute_slug );
                    } else {
                        FPS_Logger::log('product_sync', 'WARNING', $product_name_for_log, 'wc_attribute_taxonomy_name function not found. Attempting fallback for attribute taxonomy name for ' . $attribute_slug, $source_product_id);
                        $taxonomy_name = 'pa_' . $attribute_slug;
                    }
                    $wc_product_attribute->set_id( $local_attr_taxonomy_id );
                    $wc_product_attribute->set_name( $taxonomy_name );
                    $term_options_ids = array();
                    if ( ! empty( $source_attr->options ) && is_array( $source_attr->options ) ) {
                        foreach ( $source_attr->options as $option_name ) {
                            $term = get_term_by( 'name', $option_name, $taxonomy_name );
                            if ( $term && ! is_wp_error( $term ) ) $term_options_ids[] = $term->term_id;
                        }
                    }
                    $wc_product_attribute->set_options( $term_options_ids );
                } else { 
                    $wc_product_attribute->set_name( $source_attr->name );
                    if ( ! empty( $source_attr->options ) && is_array( $source_attr->options ) ) $wc_product_attribute->set_options( $source_attr->options );
                }
                $wc_product_attribute->set_visible( isset( $source_attr->visible ) ? (bool) $source_attr->visible : false );
                $wc_product_attribute->set_variation( isset( $source_attr->variation ) ? (bool) $source_attr->variation : false );
                $attributes_array[] = $wc_product_attribute;
            }
            if ( ! empty( $attributes_array ) ) $product->set_attributes( $attributes_array );
        }

        $image_sync_messages = array();
        $image_errors_count = 0;
        if ( ! empty( $source_product_data->images ) && is_array( $source_product_data->images ) ) {
            $gallery_ids = array();
            foreach ( $source_product_data->images as $index => $image_data ) {
                if ( empty( $image_data->src ) ) continue;
                $attachment_id = self::_sideload_image( $image_data->src, 0, $image_data->name ?? $product->get_name() );
                if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
                    if ( $index === 0 ) {
                        $product->set_image_id( $attachment_id );
                        $image_sync_messages[] = __( 'Featured image synced.', 'forbes-product-sync');
                    } else {
                        $gallery_ids[] = $attachment_id;
                    }
                } else {
                    $image_errors_count++;
                    $error_string = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : __( 'Unknown error', 'forbes-product-sync' );
                    $image_sync_messages[] = sprintf( __( 'Failed to sync image "%s": %s', 'forbes-product-sync' ), basename($image_data->src), $error_string );
                }
            }
            if ( ! empty( $gallery_ids ) ) {
                $product->set_gallery_image_ids( $gallery_ids );
                 $image_sync_messages[] = sprintf( _n( '%d gallery image synced.', '%d gallery images synced.', count($gallery_ids), 'forbes-product-sync' ), count($gallery_ids) );
            }
        }

        $local_product_id = 0;
        try {
            $local_product_id = $product->save();
            if ( ! $local_product_id || is_wp_error( $local_product_id ) ) {
                $error_message_detail = is_wp_error( $local_product_id ) ? $local_product_id->get_error_message() : __( 'Unknown error during product save.', 'forbes-product-sync' );
                $error_msg = sprintf( __( 'Failed to save product "%s": %s', 'forbes-product-sync' ), $product->get_name(), $error_message_detail );
                FPS_Logger::log('product_sync', 'ERROR', $product->get_name(), $error_msg, $source_product_id);
                wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'PRODUCT_SAVE_ERROR' ) );
                return;
            }
        } catch ( WC_Data_Exception $e ) {
            $error_msg = sprintf( __( 'Error saving product data for "%s": %s', 'forbes-product-sync' ), $product->get_name(), $e->getMessage() );
            FPS_Logger::log('product_sync', 'ERROR', $product->get_name(), $error_msg, $source_product_id);
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'PRODUCT_SAVE_EXCEPTION' ) );
            return;
        }

        $main_product_message = sprintf( __( 'Product "%s" (%s) created with ID %d. ', 'forbes-product-sync' ), $product->get_name(), $product_type, $local_product_id );
        
        $variation_sync_summary = '';
        $variation_error_details = array();

        if ( 'variable' === $product_type && $product instanceof WC_Product_Variable ) {
            $variations_synced_count = 0;
            $variations_failed_count = 0;
            
            if ( ! empty( $source_product_data->variations ) && is_array( $source_product_data->variations ) ) {
                foreach ( $source_product_data->variations as $source_variation_id ) {
                    $variation_api_url = trailingslashit( $remote_url ) . "wp-json/wc/v3/products/{$source_product_id}/variations/{$source_variation_id}";
                    $variation_response = wp_remote_get( $variation_api_url, $api_args );
                    if ( is_wp_error( $variation_response ) ) {
                        $variations_failed_count++;
                        $variation_error_details[] = sprintf( __( 'Variation ID %d: API Error - %s', 'forbes-product-sync' ), $source_variation_id, $variation_response->get_error_message() );
                        continue;
                    }
                    $var_response_code = wp_remote_retrieve_response_code( $variation_response );
                    $var_response_body = wp_remote_retrieve_body( $variation_response );
                    if ( 200 !== $var_response_code ) {
                        $variations_failed_count++;
                        $var_error_data = json_decode( $var_response_body );
                        $var_error_message_detail = isset($var_error_data->message) ? $var_error_data->message : wp_remote_retrieve_response_message( $variation_response );
                        $variation_error_details[] = sprintf( __( 'Variation ID %s: Fetch error (HTTP %d) from %s - %s', 'forbes-product-sync' ), $source_variation_id, $var_response_code, esc_url($variation_api_url), $var_error_message_detail );
                        continue;
                    }
                    $source_variation_data = json_decode( $var_response_body );
                    if ( ! $source_variation_data || ! isset( $source_variation_data->id ) ) {
                        $variations_failed_count++;
                        $variation_error_details[] = sprintf( __( 'Variation ID %s: Invalid data received (JSON Error: %s) from %s', 'forbes-product-sync' ), $source_variation_id, json_last_error_msg(), esc_url($variation_api_url) );
                        continue;
                    }
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id( $local_product_id );
                    $mapped_variation_attributes = array();
                    if ( ! empty( $source_variation_data->attributes ) && is_array( $source_variation_data->attributes ) ) {
                        foreach ( $source_variation_data->attributes as $var_attr ) {
                            if ( isset( $var_attr->slug ) && isset( $var_attr->option ) ) {
                                $var_attribute_slug = $var_attr->slug;
                                $var_attribute_option_slug = $var_attr->option; // This is usually the term slug

                                // If $var_attr->slug is not prefixed with 'pa_', it might be a global attribute slug
                                // or a local attribute name. We need the taxonomy name for set_attributes.
                                $var_taxonomy_name = '';
                                if (strpos($var_attribute_slug, 'pa_') === 0) {
                                    $var_taxonomy_name = $var_attribute_slug;
                                } else {
                                    // It's a base slug like 'color', get the full taxonomy name
                                    if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
                                        $var_taxonomy_name = wc_attribute_taxonomy_name( $var_attribute_slug );
                                    } else {
                                        $var_taxonomy_name = 'pa_' . $var_attribute_slug;
                                        FPS_Logger::log('product_sync', 'WARNING', $product_name_for_log, 'wc_attribute_taxonomy_name function not found. Attempting fallback for variation attribute taxonomy name for ' . $var_attribute_slug, $source_product_id);
                                    }
                                }
                                $mapped_variation_attributes[ $var_taxonomy_name ] = $var_attribute_option_slug;
                            }
                        }
                    }
                    $variation->set_attributes( $mapped_variation_attributes );
                    if (isset($source_variation_data->sku) && !empty($source_variation_data->sku)) $variation->set_sku(sanitize_text_field($source_variation_data->sku));
                    if (isset($source_variation_data->regular_price)) $variation->set_regular_price($source_variation_data->regular_price === "" ? '' : wc_format_decimal($source_variation_data->regular_price));
                    if (isset($source_variation_data->sale_price)) $variation->set_sale_price($source_variation_data->sale_price === "" ? '' : wc_format_decimal($source_variation_data->sale_price));
                    if (isset($source_variation_data->date_on_sale_from_gmt) && !empty($source_variation_data->date_on_sale_from_gmt)) $variation->set_date_on_sale_from(strtotime($source_variation_data->date_on_sale_from_gmt));
                    if (isset($source_variation_data->date_on_sale_to_gmt) && !empty($source_variation_data->date_on_sale_to_gmt)) $variation->set_date_on_sale_to(strtotime($source_variation_data->date_on_sale_to_gmt));
                    $variation->set_manage_stock(isset($source_variation_data->manage_stock) ? (bool)$source_variation_data->manage_stock : false);
                    if ($variation->get_manage_stock() && isset($source_variation_data->stock_quantity)) $variation->set_stock_quantity(intval($source_variation_data->stock_quantity));
                    $variation->set_stock_status(isset($source_variation_data->stock_status) ? sanitize_key($source_variation_data->stock_status) : 'instock');
                    if (isset($source_variation_data->weight) && $source_variation_data->weight !== "") $variation->set_weight(wc_format_decimal($source_variation_data->weight));
                    if (isset($source_variation_data->dimensions)) {
                         if (isset($source_variation_data->dimensions->length) && $source_variation_data->dimensions->length !== "") $variation->set_length(wc_format_decimal($source_variation_data->dimensions->length));
                         if (isset($source_variation_data->dimensions->width) && $source_variation_data->dimensions->width !== "") $variation->set_width(wc_format_decimal($source_variation_data->dimensions->width));
                         if (isset($source_variation_data->dimensions->height) && $source_variation_data->dimensions->height !== "") $variation->set_height(wc_format_decimal($source_variation_data->dimensions->height));
                    }
                    $variation->set_description(isset($source_variation_data->description) ? wp_kses_post($source_variation_data->description) : '');
                    if ( ! empty( $source_variation_data->image ) && ! empty( $source_variation_data->image->src ) ) {
                        $var_image_id = self::_sideload_image( $source_variation_data->image->src, $local_product_id, $source_variation_data->image->name ?? $product->get_name() . ' variation' );
                        if ( ! is_wp_error( $var_image_id ) && $var_image_id > 0 ) {
                            $variation->set_image_id( $var_image_id );
                        } else {
                             $image_errors_count++;
                             $var_error_msg_detail = is_wp_error( $var_image_id ) ? $var_image_id->get_error_message() : 'Unknown error during sideload';
                             $variation_error_details[] = sprintf(__( 'Variation ID %1$s, Image "%2$s": Sideload failed - %3$s', 'forbes-product-sync'), $source_variation_id, basename($source_variation_data->image->src), $var_error_msg_detail);
                        }
                    }
                    try {
                        $variation_save_result = $variation->save();
                        if ( $variation_save_result > 0 ) {
                             $variations_synced_count++;
                        } else {
                             $variations_failed_count++;
                             $variation_error_details[] = sprintf(__( 'Variation ID %s: Failed to save (save() returned 0 or error).', 'forbes-product-sync'), $source_variation_id);
                        }
                    } catch (WC_Data_Exception $e) {
                        $variations_failed_count++;
                        $variation_error_details[] = sprintf(__( 'Variation ID %s: Save Exception - %s', 'forbes-product-sync'), $source_variation_id, $e->getMessage());
                    }
                }
            }
            if ($variations_synced_count > 0) $variation_sync_summary .= sprintf( _n( '%d variation synced. ', '%d variations synced. ', $variations_synced_count, 'forbes-product-sync' ), $variations_synced_count );
            if ($variations_failed_count > 0) $variation_sync_summary .= sprintf( _n( '%d variation failed. ', '%d variations failed. ', $variations_failed_count, 'forbes-product-sync' ), $variations_failed_count );
            if (empty($source_product_data->variations)) $variation_sync_summary .= __( 'No variations found on source to sync.', 'forbes-product-sync' );
            
            // Sync prices for variable products
            if ( $local_product_id > 0 && $product instanceof WC_Product_Variable ) {
                // Ensure product is not null and is the correct type
                $product_to_sync = wc_get_product($local_product_id);
                if ($product_to_sync && $product_to_sync->is_type('variable')) {
                    if ( method_exists( $product_to_sync, 'sync_prices' ) ) {
                        $product_to_sync->sync_prices();
                        FPS_Logger::log('product_sync', 'INFO', $product_to_sync->get_name(), 'Called $product->sync_prices()', $source_product_id, $local_product_id);
                    } elseif ( function_exists( 'wc_product_variable_sync' ) ) {
                        wc_product_variable_sync( $local_product_id );
                        FPS_Logger::log('product_sync', 'INFO', $product_to_sync->get_name(), 'Called wc_product_variable_sync()', $source_product_id, $local_product_id);
                    } else {
                        FPS_Logger::log('product_sync', 'WARNING', $product_to_sync->get_name(), 'Could not find a standard method/function to sync variable product prices (e.g., $product->sync_prices(), wc_product_variable_sync()). Price sync might be incomplete.', $source_product_id, $local_product_id);
                    }
                } else {
                     FPS_Logger::log('product_sync', 'WARNING', 'Product (ID: ' . $local_product_id . ')', 'Could not sync prices: Product not found or not a variable product after save.', $source_product_id, $local_product_id);
                }
            }
        }

        $final_message = $main_product_message . (!empty($image_sync_messages) ? implode(' ', $image_sync_messages) . ' ' : '') . $variation_sync_summary;
        $log_status = ($image_errors_count > 0 || ($product_type === 'variable' && $variations_failed_count > 0)) ? 'PARTIAL_SUCCESS' : 'SUCCESS';
        
        FPS_Logger::log('product_sync', $log_status, $product->get_name(), trim($final_message . (!empty($variation_error_details) ? " Details: " . implode("; ", $variation_error_details) : "")), $source_product_id, $local_product_id);
        
        $response_data = array(
            'message' => trim($final_message),
            'local_product_id' => $local_product_id,
            'status_code' => $log_status
        );
        if (!empty($variation_error_details)) {
            $response_data['variation_error_details'] = $variation_error_details;
        }
        if ($image_errors_count > 0) {
             $response_data['image_error_count'] = $image_errors_count;
        }

        wp_send_json_success( $response_data );

    } // End sync_single_product

    /**
     * Helper function to sideload an image.
     *
     * @param string $image_url The URL of the image to sideload.
     * @param int    $post_id   The post ID the media is to be attached to.
     * @param string $desc      Description for the image.
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    private static function _sideload_image( $image_url, $post_id = 0, $desc = null ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        FPS_Logger::log('image_sideload', 'INFO', 'Attempting Sideload', 'Sideload attempt for URL: ' . $image_url . ' | Post ID: ' . $post_id . ' | Desc: ' . $desc);

        // Check if image from this URL already exists
        $existing_attachment_id = self::_find_existing_attachment_by_source_url( $image_url );
        if ( $existing_attachment_id ) {
            FPS_Logger::log('image_sideload', 'INFO', 'Existing Found', 'Found existing attachment ID: ' . $existing_attachment_id . ' for URL: ' . $image_url);
            return $existing_attachment_id;
        }
        
        FPS_Logger::log('image_sideload', 'INFO', 'No Existing', 'No existing attachment found for URL: ' . $image_url . '. Proceeding with new sideload.');

        // Temporarily increase timeout for image sideloading
        $original_timeout = ini_get('default_socket_timeout'); // Store original timeout
        add_filter( 'http_request_timeout', function() { return 60; } );
        
        $attachment_id = media_sideload_image( $image_url, $post_id, $desc, 'id' );
        
        // Restore original timeout
        remove_all_filters( 'http_request_timeout' ); // Remove our filter
        if ($original_timeout) {
            // If there was an original timeout, try to set it back via a new filter if needed, 
            // or ensure it's reset if http_request_args filter is used by WP core.
            // For simplicity, we assume removing our filter is enough for most cases.
            // If specific reset is needed: add_filter( 'http_request_timeout', function() use ($original_timeout) { return $original_timeout; } );
        }

        if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
            update_post_meta( $attachment_id, '_source_image_url', esc_url_raw( $image_url ) );
            FPS_Logger::log('image_sideload', 'INFO', 'Meta Saved', 'Saved _source_image_url for new attachment ID: ' . $attachment_id . ' with URL: ' . esc_url_raw($image_url));
        } else if (is_wp_error($attachment_id)) {
            FPS_Logger::log('image_sideload', 'ERROR', 'Sideload Failed', 'media_sideload_image returned WP_Error: ' . $attachment_id->get_error_message() . ' for URL: ' . $image_url);
        } else {
            FPS_Logger::log('image_sideload', 'WARNING', 'Sideload Failed No Error', 'media_sideload_image did not return WP_Error but attachment_id is not > 0. Value: ' . print_r($attachment_id, true) . ' for URL: ' . $image_url);
        }

        return $attachment_id;
    }

    /**
     * Helper function to find an existing attachment ID by its source URL meta.
     *
     * @param string $image_url The source URL of the image.
     * @return int Attachment ID if found, 0 otherwise.
     */
    private static function _find_existing_attachment_by_source_url( $image_url ) {
        global $wpdb;
        $escaped_url = esc_url_raw( $image_url );
        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_source_image_url' AND meta_value = %s LIMIT 1",
            $escaped_url
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $attachment_id = $wpdb->get_var( $sql );
        return $attachment_id ? (int) $attachment_id : 0;
    }

    /**
     * Helper function to map term names to local term IDs for a given taxonomy, creating terms if they don't exist.
     *
     * @param array  $term_data_array Array of term objects from source (expected to have name, slug, description).
     * @param string $taxonomy        Taxonomy slug.
     * @return array Array of local term IDs.
     */
    private static function _map_and_create_terms( $term_data_array, $taxonomy ) {
        $term_ids = array();
        if ( empty( $term_data_array ) || !is_array( $term_data_array ) ) {
            return $term_ids;
        }

        foreach ( $term_data_array as $term_obj ) {
            if ( !is_object($term_obj) || !isset($term_obj->name) ) continue;
            
            $term_name = $term_obj->name;
            $term_slug = $term_obj->slug ?? sanitize_title($term_name);

            $existing_term = get_term_by( 'slug', $term_slug, $taxonomy );
            if (!$existing_term) {
                 $existing_term = get_term_by( 'name', $term_name, $taxonomy );
            }

            if ( $existing_term ) {
                $term_ids[] = $existing_term->term_id;
                if (isset($term_obj->description) && $term_obj->description !== $existing_term->description) {
                    wp_update_term($existing_term->term_id, $taxonomy, array('description' => $term_obj->description));
                }
            } else {
                $args = array(
                    'slug' => $term_slug,
                    'description' => $term_obj->description ?? '',
                );
                $new_term = wp_insert_term( $term_name, $taxonomy, $args );
                if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
                    $term_ids[] = $new_term['term_id'];
                } else {
                    FPS_Logger::log('terms_mapping', 'ERROR', "Failed to create term '{$term_name}' (slug: {$term_slug}) for taxonomy '{$taxonomy}'", is_wp_error($new_term) ? $new_term->get_error_message() : 'Unknown error');
                }
            }
        }
        return array_unique( $term_ids );
    }

} // End FPS_AJAX class 