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
     * Hook in ajax handlers.
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'define_ajax' ) );
        add_action( 'wp_ajax_fps_test_connection', array( __CLASS__, 'test_connection' ) );
        add_action( 'wp_ajax_fps_sync_single_attribute', array( __CLASS__, 'sync_single_attribute' ) );
        add_action( 'wp_ajax_fps_sync_single_product', array( __CLASS__, 'sync_single_product' ) ); // Removed duplicate
    }

    /**
     * Set DOING_AJAX if wp_doing_ajax() is not available (before WP 4.7).
     */
    public static function define_ajax() {
        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }
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
        $action_item_name = 'Attribute ID: ' . (isset($_POST['attribute_id']) ? sanitize_text_field($_POST['attribute_id']) : 'N/A');
        $source_attribute_id_for_log = isset($_POST['attribute_id']) ? sanitize_text_field($_POST['attribute_id']) : null;


        if ( ! current_user_can( 'manage_options' ) ) {
            $error_msg = __( 'User does not have permission to manage options.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id_for_log );
            wp_send_json_error( array( 'message' => $error_msg ), 403 );
            return;
        }

        if ( ! isset( $_POST['attribute_id'] ) ) {
            $error_msg = __( 'Attribute ID not provided.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id_for_log );
            wp_send_json_error( array( 'message' => $error_msg ), 400 );
            return;
        }
        $source_attribute_id = absint( $_POST['attribute_id'] );
        $action_item_name = 'Attribute ID: ' . $source_attribute_id; // Update with actual ID

        if ( $source_attribute_id <= 0 ) {
            $error_msg = __( 'Invalid Attribute ID.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id_for_log );
            wp_send_json_error( array( 'message' => $error_msg ), 400 );
            return;
        }

        $remote_url = get_option( 'fps_remote_site_url', '' );
        $username   = get_option( 'fps_api_username', '' );
        $password   = get_option( 'fps_api_password', '' );

        if ( empty( $remote_url ) || empty( $username ) || empty( $password ) ) {
            $error_msg = __( 'API credentials are not configured.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id_for_log );
            wp_send_json_error( array( 'message' => $error_msg ), 400 );
            return;
        }

        $auth_header = 'Basic ' . base64_encode( $username . ':' . $password );
        $api_args = array(
            'headers'   => array( 'Authorization' => $auth_header ),
            'timeout'   => 30,
            'sslverify' => apply_filters( 'fps_sync_sslverify', true ),
        );

        // 1. Fetch Attribute Details from Source
        $attr_api_url = trailingslashit( $remote_url ) . "wp-json/wc/v3/products/attributes/{$source_attribute_id}";
        $attr_response = wp_remote_get( $attr_api_url, $api_args );

        if ( is_wp_error( $attr_response ) ) {
            $error_msg = sprintf( __( 'Error fetching attribute details from %1$s: %2$s', 'forbes-product-sync' ), esc_url($attr_api_url), $attr_response->get_error_message() );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_FETCH_ERROR_ATTRIBUTE_DETAILS' ) );
            return;
        }
        $attr_response_code = wp_remote_retrieve_response_code( $attr_response );
        $attr_response_body = wp_remote_retrieve_body( $attr_response );
        if ( 200 !== $attr_response_code ) {
            $error_data = json_decode( $attr_response_body );
            $error_message_detail = isset($error_data->message) ? $error_data->message : wp_remote_retrieve_response_message( $attr_response );
            $error_msg = sprintf( __( 'Error fetching attribute details from %1$s (HTTP %2$d): %3$s', 'forbes-product-sync' ), esc_url($attr_api_url), $attr_response_code, $error_message_detail );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_FETCH_ERROR_ATTRIBUTE_DETAILS_HTTP', 'http_code' => $attr_response_code ) );
            return;
        }
        $source_attribute = json_decode( $attr_response_body );
        if ( ! $source_attribute || ! isset( $source_attribute->slug ) ) {
            $error_msg = __( 'Invalid attribute data received from source.', 'forbes-product-sync' );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }
        $action_item_name = $source_attribute->name ?? $action_item_name; // Update item name with actual name

        // 2. Fetch Attribute Terms from Source
        $terms_api_url = trailingslashit( $remote_url ) . "wp-json/wc/v3/products/attributes/{$source_attribute_id}/terms?per_page=100";
        $terms_response = wp_remote_get( $terms_api_url, $api_args );

        if ( is_wp_error( $terms_response ) ) {
            $error_msg = sprintf( __( 'Error fetching attribute terms for "%1$s" from %2$s: %3$s', 'forbes-product-sync' ), $action_item_name, esc_url($terms_api_url), $terms_response->get_error_message() );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_FETCH_ERROR_ATTRIBUTE_TERMS' ) );
            return;
        }
        $terms_response_code = wp_remote_retrieve_response_code( $terms_response );
        $terms_response_body = wp_remote_retrieve_body( $terms_response );
        if ( 200 !== $terms_response_code ) {
            $error_data = json_decode( $terms_response_body );
            $error_message_detail = isset($error_data->message) ? $error_data->message : wp_remote_retrieve_response_message( $terms_response );
            $error_msg = sprintf( __( 'Error fetching attribute terms for "%1$s" from %2$s (HTTP %3$d): %4$s', 'forbes-product-sync' ), $action_item_name, esc_url($terms_api_url), $terms_response_code, $error_message_detail );
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'API_FETCH_ERROR_ATTRIBUTE_TERMS_HTTP', 'http_code' => $terms_response_code ) );
            return;
        }
        $source_terms = json_decode( $terms_response_body );
        if ( ! is_array( $source_terms ) ) {
            $error_msg = sprintf(__( 'Invalid terms data received from source for attribute "%s".', 'forbes-product-sync' ), $action_item_name);
            FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
            wp_send_json_error( array( 'message' => $error_msg ) );
            return;
        }


        // 3. Process Attribute on Destination Site
        $local_attribute_id_for_log = null;
        $local_attribute_id = wc_attribute_taxonomy_id_by_name( $source_attribute->slug );
        $attribute_message = '';

        if ( ! $local_attribute_id ) {
            $args = array(
                'name'         => $source_attribute->name,
                'slug'         => $source_attribute->slug, 
                'type'         => $source_attribute->type,
                'order_by'     => $source_attribute->order_by,
                'has_archives' => $source_attribute->has_archives,
            );
            $created_id = wc_create_attribute( $args );

            if ( is_wp_error( $created_id ) ) {
                $error_msg = sprintf( __( 'Error creating attribute "%s": %s', 'forbes-product-sync' ), $source_attribute->name, $created_id->get_error_message() );
                FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
                wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'ATTRIBUTE_CREATION_ERROR' ) );
                return;
            }
            if ( $created_id === 0 ) { // Should ideally not happen if not WP_Error, but as a safeguard.
                $error_msg = sprintf( __( 'Failed to create attribute "%s". wc_create_attribute returned 0.', 'forbes-product-sync' ), $source_attribute->name );
                FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $error_msg, $source_attribute_id );
                wp_send_json_error( array( 'message' => $error_msg, 'status_code' => 'ATTRIBUTE_CREATION_FAILED' ) );
                return;
            }
            $local_attribute_id_for_log = $created_id;
            $attribute_message = sprintf( __( 'Attribute "%s" created. ', 'forbes-product-sync' ), $source_attribute->name );
        } else {
            $local_attribute_id_for_log = $local_attribute_id;
            $attribute_message = sprintf( __( 'Attribute "%s" already exists. ', 'forbes-product-sync' ), $source_attribute->name );
        }

        // 4. Process Terms
        $local_taxonomy_name = wc_attribute_taxonomy_name_by_slug( $source_attribute->slug ); // e.g. pa_color
        $terms_synced_count = 0;
        $terms_existing_count = 0;

        foreach ( $source_terms as $source_term ) {
            if ( ! isset( $source_term->name ) || ! isset( $source_term->slug ) ) {
                // Skip invalid term data
                continue;
            }
            $term_exists_check = term_exists( $source_term->name, $local_taxonomy_name );

            if ( ! $term_exists_check ) {
                $new_term_args = array( 'slug' => $source_term->slug );
                // Add description if available
                if (isset($source_term->description) && !empty($source_term->description)) {
                    $new_term_args['description'] = $source_term->description;
                }

                $term_result = wp_insert_term( $source_term->name, $local_taxonomy_name, $new_term_args );
                if ( is_wp_error( $term_result ) ) {
                    $term_error_msg = sprintf(__( 'Error creating term "%1$s" for attribute "%2$s": %3$s', 'forbes-product-sync' ), $source_term->name, $action_item_name, $term_result->get_error_message());
                    FPS_Logger::log( 'attribute_sync', 'ERROR', $action_item_name, $term_error_msg, $source_attribute_id, $local_attribute_id_for_log );
                    // Continue to next term, but log this error.
                } elseif ( $term_result && isset( $term_result['term_id'] ) ) {
                    $terms_synced_count++;
                }
            } else {
                $terms_existing_count++;
            }
        }
        
        $final_message = $attribute_message;
        if ( $terms_synced_count > 0 ) {
            $final_message .= sprintf( _n( '%d term synced. ', '%d terms synced. ', $terms_synced_count, 'forbes-product-sync' ), $terms_synced_count );
        }
        if ( $terms_existing_count > 0 ) {
             $final_message .= sprintf( _n( '%d term already existed.', '%d terms already existed.', $terms_existing_count, 'forbes-product-sync' ), $terms_existing_count );
        }
        if ( $terms_synced_count === 0 && $terms_existing_count === 0 && count($source_terms) > 0) {
            $final_message .= __( 'No new terms to sync.', 'forbes-product-sync' );
        } elseif (count($source_terms) === 0) {
            $final_message .= __( 'Attribute has no terms to sync.', 'forbes-product-sync' );
        }


        FPS_Logger::log( 'attribute_sync', 'SUCCESS', $action_item_name, trim($final_message), $source_attribute_id, $local_attribute_id_for_log );
        wp_send_json_success( array( 'message' => trim($final_message), 'status_code' => 'SUCCESS' ) ); // Added status_code
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
        // $action_item_name is defined above, but use $product_name_for_log for item name in logs before full data is fetched.
        $product_name_for_error = isset($_POST['product_name']) ? sanitize_text_field($_POST['product_name']) : 'Product (ID: ' . $source_product_id . ')';


        if ( $source_product_id <= 0 ) {
            $error_msg = __( 'Invalid Product ID provided.', 'forbes-product-sync' );
            FPS_Logger::log('product_sync', 'ERROR', $product_name_for_error, $error_msg, $source_product_id_for_log); // Use $product_name_for_error
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
        $product_name_for_log = $source_product_data->name ?? $product_name_for_error; // Update for subsequent logs

        if ( empty( $source_product_data->sku ) ) {
            $error_msg = sprintf( __( 'Product "%1$s" (ID: %2$d) from source has no SKU. Cannot sync without an SKU.', 'forbes-product-sync' ), $product_name_for_log, $source_product_id ); // Use updated $product_name_for_log
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
                    $taxonomy_name = wc_attribute_taxonomy_name_by_slug( $attribute_slug ); 
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
                                $local_attr_slug = $var_attr->slug; 
                                if (strpos($local_attr_slug, 'pa_') !== 0) { 
                                     $check_if_global_exists = wc_attribute_taxonomy_id_by_name($local_attr_slug);
                                     if ($check_if_global_exists) $local_attr_slug = wc_attribute_taxonomy_name_by_slug($local_attr_slug); 
                                }
                                $mapped_variation_attributes[ $local_attr_slug ] = $var_attr->option; 
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
                             $image_errors_count++; // Also count variation image errors
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
            $product->sync_prices();
        } 

        $final_message = $main_product_message . (!empty($image_sync_messages) ? implode(' ', $image_sync_messages) . ' ' : '') . $variation_sync_summary;
        $log_status = ($image_errors_count > 0 || ($product_type === 'variable' && $variations_failed_count > 0)) ? 'PARTIAL_SUCCESS' : 'SUCCESS';
        
        FPS_Logger::log('product_sync', $log_status, $product->get_name(), trim($final_message . (!empty($variation_error_details) ? " Details: " . implode("; ", $variation_error_details) : "")), $source_product_id, $local_product_id);
        
        $response_data = array( 
            'message' => trim($final_message), 
            'local_product_id' => $local_product_id,
            'status_code' => $log_status // To be used by JS for more specific feedback
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

        // Set timeout for the sideloading request
        add_filter( 'http_request_timeout', function() { return 60; } ); // 60 seconds

        $attachment_id = media_sideload_image( $image_url, $post_id, $desc, 'id' );
        
        // Reset timeout to default
        add_filter( 'http_request_timeout', function() { return 5; } );


        if ( is_wp_error( $attachment_id ) ) {
            // Log error or handle as needed
            // error_log("Image sideloading failed for URL $image_url: " . $attachment_id->get_error_message());
        }
        return $attachment_id;
    }

    /**
     * Helper function to map term names to local term IDs for a given taxonomy, creating terms if they don't exist.
     *
     * @param array  $term_names Array of term names.
     * @param string $taxonomy   Taxonomy slug.
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
            $term_slug = $term_obj->slug ?? ''; // Use slug from source if available

            $existing_term = get_term_by( 'name', $term_name, $taxonomy );

            if ( $existing_term ) {
                $term_ids[] = $existing_term->term_id;
            } else {
                // Term does not exist, create it
                $args = array();
                if (!empty($term_slug)) {
                    $args['slug'] = $term_slug;
                }
                if (isset($term_obj->description)) {
                     $args['description'] = $term_obj->description;
                }
                // Parent term handling (if 'parent' is provided and is an ID)
                // This assumes parent terms are already synced or IDs are directly usable.
                // For simplicity, direct parent ID mapping is not implemented here,
                // but one could fetch parent term by source ID and map to local parent ID.
                // if (isset($term_obj->parent) && is_numeric($term_obj->parent) && $term_obj->parent > 0) {
                //     // Potentially map $term_obj->parent (source parent term ID) to a local parent term ID
                //     // $args['parent'] = map_source_term_id_to_local_id($term_obj->parent, $taxonomy);
                // }

                $new_term = wp_insert_term( $term_name, $taxonomy, $args );
                if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
                    $term_ids[] = $new_term['term_id'];
                } else {
                    // Log error: Failed to create term $term_name for taxonomy $taxonomy
                    // error_log("Failed to create term '$term_name' for taxonomy '$taxonomy': " . (is_wp_error($new_term) ? $new_term->get_error_message() : 'Unknown error'));
                }
            }
        }
        return array_unique( $term_ids );
    }

} // End FPS_AJAX class

FPS_AJAX::init(); 