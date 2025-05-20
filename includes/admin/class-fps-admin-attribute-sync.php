<?php
/**
 * Attribute Sync admin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Admin_Attribute_Sync Class.
 */
class FPS_Admin_Attribute_Sync {
    /**
     * Output the attribute sync page.
     */
    public static function output() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php
            // Retrieve API credentials
            $remote_site_url = get_option( 'fps_remote_site_url' );
            $api_username    = get_option( 'fps_api_username' );
            $api_password    = get_option( 'fps_api_password' );

            // Check if credentials are set
            if ( empty( $remote_site_url ) || empty( $api_username ) || empty( $api_password ) ) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: URL to settings page */
                                __( 'API credentials are not configured. Please <a href="%s">configure them here</a> to fetch attributes.', 'forbes-product-sync' ),
                                esc_url( admin_url( 'admin.php?page=fps-settings' ) )
                            )
                        );
                        ?>
                    </p>
                </div>
                <?php
                echo '</div>'; // Close wrap
                return; // Stop further processing
            }

            // Construct the API endpoint URL
            $api_url = rtrim( $remote_site_url, '/' ) . '/wp-json/wc/v3/products/attributes';

            // Prepare arguments for wp_remote_get
            $api_args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $api_username . ':' . $api_password ),
                ),
                'timeout'   => 30,
                'sslverify' => apply_filters( 'fps_fetch_attributes_sslverify', true ),
            );

            // Make the API request
            $response = wp_remote_get( $api_url, $api_args );

            // Error handling for API Request
            if ( is_wp_error( $response ) ) {
                ?>
                <div class="notice notice-error">
                    <p><?php printf( esc_html__( 'Error fetching attributes: %s', 'forbes-product-sync' ), esc_html( $response->get_error_message() ) ); ?></p>
                </div>
                <?php
            } else {
                $response_code = wp_remote_retrieve_response_code( $response );
                $response_body = wp_remote_retrieve_body( $response );

                if ( 200 !== $response_code ) {
                    $message = wp_remote_retrieve_response_message( $response );
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <?php
                            printf(
                                /* translators: %1$d: HTTP response code, %2$s: HTTP response message */
                                esc_html__( 'Error fetching attributes: Received HTTP response code %1$d - %2$s', 'forbes-product-sync' ),
                                absint( $response_code ),
                                esc_html( $message )
                            );
                            // Attempt to get more details from body if it's JSON
                            $error_data = json_decode( $response_body );
                            if ( $error_data && isset( $error_data->message ) ) {
                                echo '<br/>' . esc_html__( 'Details:', 'forbes-product-sync' ) . ' ' . esc_html( $error_data->message );
                            }
                            ?>
                        </p>
                    </div>
                    <?php
                } else {
                    $attributes = json_decode( $response_body );

                    if ( empty( $attributes ) || ! is_array( $attributes ) ) {
                        if ( json_last_error() !== JSON_ERROR_NONE && !empty($response_body) ) {
                            ?>
                            <div class="notice notice-error">
                                <p><?php esc_html_e( 'Error: Could not decode the JSON response from the remote site.', 'forbes-product-sync' ); ?></p>
                                <p><?php printf( esc_html__('JSON Error: %s', 'forbes-product-sync'), esc_html(json_last_error_msg())); ?></p>
                            </div>
                            <?php
                        } else {
                        ?>
                        <div class="notice notice-info">
                            <p><?php esc_html_e( 'No product attributes found on the source site.', 'forbes-product-sync' ); ?></p>
                        </div>
                        <?php
                        }
                    } else {
                        // Display attributes in a table
                        ?>
                        <p><?php printf( esc_html__( 'Found %d attribute(s) on the remote site.', 'forbes-product-sync' ), count( $attributes ) ); ?></p>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Name', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Slug', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Type', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Order by', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Has Archives?', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Actions', 'forbes-product-sync' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $attributes as $attribute ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $attribute->name ?? '' ); ?></td>
                                        <td><?php echo esc_html( $attribute->slug ?? '' ); ?></td>
                                        <td><?php echo esc_html( $attribute->type ?? '' ); ?></td>
                                        <td><?php echo esc_html( $attribute->order_by ?? '' ); ?></td>
                                        <td><?php echo ( $attribute->has_archives ?? false ) ? esc_html__( 'Yes', 'forbes-product-sync' ) : esc_html__( 'No', 'forbes-product-sync' ); ?></td>
                                        <td>
                                            <button type="button" class="button button-primary fps-sync-attribute-button" data-attribute-id="<?php echo esc_attr( $attribute->id ?? '' ); ?>" data-attribute-name="<?php echo esc_attr( $attribute->name ?? '' ); ?>">
                                                <?php esc_html_e( 'Sync', 'forbes-product-sync' ); ?>
                                            </button>
                                            <span class="fps-sync-status" style="margin-left: 5px;"></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                    }
                }
            }
            ?>
        </div>
        <?php
    }
}
?>