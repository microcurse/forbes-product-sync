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
            wp_send_json_error( __( 'Please save your API settings first.', 'forbes-product-sync' ) );
        }

        $test_endpoint = trailingslashit( $remote_url ) . 'wp-json/wp/v2/';
        
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
            wp_send_json_error( $response->get_error_message() );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( 200 !== $response_code ) {
            $error = sprintf(
                __( 'Error code: %1$s. Response: %2$s', 'forbes-product-sync' ),
                $response_code,
                wp_remote_retrieve_response_message( $response )
            );
            wp_send_json_error( $error );
        }

        $data = json_decode( $body );
        
        if ( empty( $data ) || ! is_object( $data ) ) {
            wp_send_json_error( __( 'Invalid API response format.', 'forbes-product-sync' ) );
        }

        wp_send_json_success( __( 'Connection successful! The remote site is accessible.', 'forbes-product-sync' ) );
    }
}

FPS_AJAX::init(); 