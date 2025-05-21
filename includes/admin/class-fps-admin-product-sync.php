<?php
/**
 * Product Sync admin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Admin_Product_Sync Class.
 */
class FPS_Admin_Product_Sync {
    /**
     * Output the product sync page.
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

            if ( empty( $remote_site_url ) || empty( $api_username ) || empty( $api_password ) ) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: URL to settings page */
                                __( 'API credentials are not configured. Please <a href="%s">configure them here</a> to fetch products.', 'forbes-product-sync' ),
                                esc_url( admin_url( 'admin.php?page=fps-settings' ) )
                            )
                        );
                        ?>
                    </p>
                </div>
                <?php
                echo '</div>'; // Close wrap
                return;
            }

            // Pagination
            $current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
            $per_page = 20; // Products per page

            // Construct the API endpoint URL
            $api_url_base = rtrim( $remote_site_url, '/' ) . '/wp-json/wc/v3/products';
            $api_url = add_query_arg(
                array(
                    'per_page' => $per_page,
                    'page'     => $current_page,
                    'orderby'  => 'date',
                    'order'    => 'desc',
                    // '_embed'   => 'true', // Not strictly needed for variation attribute names, can add if full term details are required later
                ),
                $api_url_base
            );

            // Prepare arguments for wp_remote_get
            $api_args = array(
                'headers'   => array(
                    'Authorization' => 'Basic ' . base64_encode( $api_username . ':' . $api_password ),
                ),
                'timeout'   => 45, 
                'sslverify' => apply_filters( 'fps_fetch_products_sslverify', true ),
            );

            $response = wp_remote_get( $api_url, $api_args );

            if ( is_wp_error( $response ) ) {
                ?>
                <div class="notice notice-error">
                    <p><?php printf( esc_html__( 'Error fetching products: %s', 'forbes-product-sync' ), esc_html( $response->get_error_message() ) ); ?></p>
                </div>
                <?php
            } else {
                $response_code    = wp_remote_retrieve_response_code( $response );
                $response_body    = wp_remote_retrieve_body( $response );
                $response_headers = wp_remote_retrieve_headers( $response );

                if ( 200 !== $response_code ) {
                    $message = wp_remote_retrieve_response_message( $response );
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <?php
                            printf(
                                /* translators: %1$d: HTTP response code, %2$s: HTTP response message */
                                esc_html__( 'Error fetching products: Received HTTP response code %1$d - %2$s', 'forbes-product-sync' ),
                                absint( $response_code ),
                                esc_html( $message )
                            );
                            $error_data = json_decode( $response_body );
                            if ( $error_data && isset( $error_data->message ) ) {
                                echo '<br/>' . esc_html__( 'Details:', 'forbes-product-sync' ) . ' ' . esc_html( $error_data->message );
                            }
                            ?>
                        </p>
                    </div>
                    <?php
                } else {
                    $products = json_decode( $response_body );

                    if ( empty( $products ) || ! is_array( $products ) ) {
                         if ( json_last_error() !== JSON_ERROR_NONE && !empty($response_body) ) {
                             ?>
                            <div class="notice notice-error">
                                <p><?php esc_html_e( 'Error: Could not decode the JSON response for products from the remote site.', 'forbes-product-sync' ); ?></p>
                                <p><?php printf( esc_html__('JSON Error: %s', 'forbes-product-sync'), esc_html(json_last_error_msg())); ?></p>
                            </div>
                            <?php
                        } else {
                        ?>
                        <div class="notice notice-info">
                            <p><?php esc_html_e( 'No products found on the source site for the current page.', 'forbes-product-sync' ); ?></p>
                        </div>
                        <?php
                        }
                    } else {
                        $total_products = isset( $response_headers['x-wp-total'] ) ? (int) $response_headers['x-wp-total'] : 0;
                        $total_pages    = isset( $response_headers['x-wp-totalpages'] ) ? (int) $response_headers['x-wp-totalpages'] : 0;
                        
                        if ($total_products > 0) {
                             echo '<p>' . sprintf(
                                /* translators: %1$d is the number of products on the current page, %2$d is the total number of products. */
                                esc_html__( 'Displaying %1$d product(s) on this page (out of %2$d total).', 'forbes-product-sync' ),
                                count($products),
                                $total_products
                            ) . '</p>';
                        }
                        ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Name', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'SKU', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Type', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Price', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Stock Status', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Variation Attributes', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Local Status', 'forbes-product-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Actions', 'forbes-product-sync' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $products as $product ) : ?>
                                    <?php
                                    $local_status = __( 'New', 'forbes-product-sync' );
                                    $local_status_class = 'fps-text-success'; // Green for New
                                    $sync_button_text = __( 'Sync', 'forbes-product-sync' );
                                    $sync_button_disabled = false;
                                    $existing_local_id = null;

                                    if ( ! empty( $product->sku ) ) {
                                        $existing_local_id = wc_get_product_id_by_sku( $product->sku );
                                        if ( $existing_local_id ) {
                                            $local_status = __( 'Exists Locally', 'forbes-product-sync' );
                                            $local_status_class = 'fps-text-warning'; // Orange for Exists
                                            $sync_button_text = __( 'Skipped (Exists)', 'forbes-product-sync' );
                                            $sync_button_disabled = true;
                                        }
                                    } else {
                                        $local_status = __( 'No SKU', 'forbes-product-sync' );
                                        $local_status_class = 'fps-text-error'; // Red for No SKU
                                        $sync_button_text = __( 'Cannot Sync', 'forbes-product-sync' );
                                        $sync_button_disabled = true;
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $product->name ?? '' ); ?></td>
                                        <td><?php echo esc_html( $product->sku ?? 'N/A' ); ?></td>
                                        <td><?php echo esc_html( $product->type ?? '' ); ?></td>
                                        <td>
                                            <?php
                                            // Use wc_price if available, otherwise fallback to raw price.
                                            if ( function_exists('wc_price') && isset($product->price) && $product->price !== '' ) {
                                                echo wp_kses_post( wc_price( $product->price ) );
                                            } elseif (isset($product->price)) {
                                                echo esc_html( $product->price );
                                            } else {
                                                esc_html_e( 'N/A', 'forbes-product-sync' );
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo esc_html( $product->stock_status ?? 'N/A' ); ?></td>
                                        <td>
                                            <?php
                                            if ( 'variable' === ( $product->type ?? '' ) && ! empty( $product->attributes ) ) {
                                                $variation_attributes = array();
                                                foreach ( $product->attributes as $attribute ) {
                                                    if ( isset($attribute->variation) && $attribute->variation && isset($attribute->name) ) {
                                                        $variation_attributes[] = esc_html( $attribute->name );
                                                    }
                                                }
                                                if (!empty($variation_attributes)) {
                                                    echo esc_html(implode( ', ', $variation_attributes ));
                                                } else {
                                                    esc_html_e( 'N/A', 'forbes-product-sync' );
                                                }
                                            } else {
                                                esc_html_e( 'N/A', 'forbes-product-sync' );
                                            }
                                            ?>
                                        </td>
                                        <td><span class="<?php echo esc_attr($local_status_class); ?>"><?php echo esc_html( $local_status ); ?></span></td>
                                        <td>
                                            <button type="button" class="button button-primary fps-sync-product-button" 
                                                    data-product-id="<?php echo esc_attr( $product->id ?? '' ); ?>" 
                                                    data-product-name="<?php echo esc_attr( $product->name ?? '' ); ?>"
                                                    <?php if ($sync_button_disabled) echo 'disabled="disabled"'; ?>>
                                                <?php echo esc_html( $sync_button_text ); ?>
                                            </button>
                                            <span class="fps-sync-status" style="margin-left: 5px;"></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                        // Pagination display
                        if ( $total_pages > 1 ) {
                            echo '<div class="tablenav"><div class="tablenav-pages">';
                            echo '<span class="displaying-num">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'forbes-product-sync' ), $current_page, $total_pages ) . '</span>';
                            
                            // Generate base URL for pagination links
                            $base_url = admin_url('admin.php?page=fps-product-sync');
                            
                            $page_links = paginate_links( array(
                                'base'      => $base_url . '%_%', // Use %_% to allow add_query_arg to replace it.
                                'format'    => '&paged=%#%', // %#% is replaced by the page number.
                                'prev_text' => __( '&laquo; Previous', 'forbes-product-sync' ),
                                'next_text' => __( 'Next &raquo;', 'forbes-product-sync' ),
                                'total'     => $total_pages,
                                'current'   => $current_page,
                                'type'      => 'array', 
                            ) );

                            if ( $page_links ) {
                                echo '<span class="pagination-links">' . implode( ' ', $page_links ) . '</span>';
                            }
                            echo '</div></div>';
                        }
                    }
                }
            }
            ?>
        </div>
        <?php
    }
}
?>