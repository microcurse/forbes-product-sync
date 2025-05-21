<?php
/**
 * Sync Logs admin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Admin_Sync_Logs Class.
 */
class FPS_Admin_Sync_Logs {
    /**
     * Output the sync logs page.
     */
    public static function output() {
        global $wpdb;
        // Ensure logger class is available for table name and clear function
        if ( ! class_exists('FPS_Logger') ) {
            // This path assumes the logger class is in the includes directory
            $logger_path = FPS_PLUGIN_DIR . 'includes/class-fps-logger.php';
            if ( file_exists( $logger_path ) ) {
                include_once $logger_path;
            } else {
                // Handle error: Logger class file not found
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Error: Logger class file not found.', 'forbes-product-sync' ) . '</p></div>';
                return;
            }
        }
        FPS_Logger::init(); // Ensure table name is initialized in FPS_Logger

        // This should be dynamically set by FPS_Logger::init() and then accessed,
        // but for safety in this context, we define it if not already.
        // In a more robust scenario, FPS_Logger would provide a get_table_name() method.
        $log_table_name = $wpdb->prefix . 'fps_sync_logs';


        // Handle Clear Log Action
        if ( isset( $_POST['fps_action'] ) && $_POST['fps_action'] === 'clear_logs' ) {
            if ( isset( $_POST['_wpnonce_fps_clear_logs'] ) && wp_verify_nonce( sanitize_key( $_POST['_wpnonce_fps_clear_logs'] ), 'fps_clear_logs_action' ) ) {
                if ( current_user_can( 'manage_options' ) ) {
                    $cleared_rows = FPS_Logger::clear_logs();
                    if ( false !== $cleared_rows ) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( '%d log entries cleared.', 'forbes-product-sync' ), absint( $cleared_rows ) ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Error clearing logs.', 'forbes-product-sync' ) . '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'You do not have permission to clear logs.', 'forbes-product-sync' ) . '</p></div>';
                }
            } else {
                 echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Nonce verification failed.', 'forbes-product-sync' ) . '</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url('admin.php?page=forbes-product-sync-logs') ); ?>">
                <input type="hidden" name="fps_action" value="clear_logs">
                <?php wp_nonce_field( 'fps_clear_logs_action', '_wpnonce_fps_clear_logs' ); ?>
                <?php submit_button( __( 'Clear All Logs', 'forbes-product-sync' ), 'delete', 'clear_logs_button', false, array( 'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to delete all logs? This action cannot be undone.', 'forbes-product-sync' ) ) . '");' ) ); ?>
            </form>
            <hr/>

            <?php
            // Pagination
            $current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
            $logs_per_page = 30; // Increased logs per page
            $offset = ( $current_page - 1 ) * $logs_per_page;

            // Get total logs
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_logs = $wpdb->get_var( "SELECT COUNT(id) FROM {$log_table_name}" );

            // Get logs for the current page
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $logs = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$log_table_name} ORDER BY log_timestamp DESC LIMIT %d OFFSET %d",
                $logs_per_page,
                $offset
            ) );
            
            if ( empty( $logs ) && $total_logs > 0 && $current_page > 1) {
                wp_safe_redirect( admin_url('admin.php?page=forbes-product-sync-logs') );
                exit;
            }
            
            if ( empty( $logs ) ) {
                echo '<p>' . esc_html__( 'No sync logs found.', 'forbes-product-sync' ) . '</p>';
            } else {
                ?>
                <p>
                    <?php
                    printf(
                        /* translators: %1$s: current number of logs on page, %2$s: total number of logs */
                        esc_html__( 'Displaying %1$s log(s) on this page (out of %2$s total).', 'forbes-product-sync' ),
                        esc_html( count( $logs ) ),
                        esc_html( $total_logs )
                    );
                    ?>
                </p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width:150px;"><?php esc_html_e( 'Timestamp', 'forbes-product-sync' ); ?></th>
                            <th scope="col" style="width:120px;"><?php esc_html_e( 'Action', 'forbes-product-sync' ); ?></th>
                            <th scope="col" style="width:90px;"><?php esc_html_e( 'Status', 'forbes-product-sync' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Item Name', 'forbes-product-sync' ); ?></th>
                            <th scope="col" style="width:100px;"><?php esc_html_e( 'Source ID', 'forbes-product-sync' ); ?></th>
                            <th scope="col" style="width:100px;"><?php esc_html_e( 'Local ID', 'forbes-product-sync' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Message', 'forbes-product-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log_entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log_entry->log_timestamp ) ) ); ?></td>
                                <td><?php echo esc_html( $log_entry->action_type ); ?></td>
                                <td><span class="fps-log-status fps-log-status-<?php echo esc_attr( strtolower( $log_entry->status ) ); ?>"><?php echo esc_html( $log_entry->status ); ?></span></td>
                                <td><?php echo esc_html( $log_entry->item_name ); ?></td>
                                <td><?php echo esc_html( $log_entry->source_item_id ?? 'N/A' ); ?></td>
                                <td><?php echo esc_html( $log_entry->local_item_id ?? 'N/A' ); ?></td>
                                <td><?php echo wp_kses_post( $log_entry->message ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                // Pagination display
                if ( $total_logs > $logs_per_page ) {
                    $total_pages = ceil( $total_logs / $logs_per_page );
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo '<span class="displaying-num">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'forbes-product-sync' ), $current_page, $total_pages ) . '</span>';
                    
                    $page_links = paginate_links( array(
                        'base'         => add_query_arg( 'paged', '%#%' ), // %#% will be replaced with page number
                        'format'       => '', // 'format' is not needed when 'base' is used like this
                        'prev_text'    => __( '&laquo; Previous', 'forbes-product-sync' ),
                        'next_text'    => __( 'Next &raquo;', 'forbes-product-sync' ),
                        'total'        => $total_pages,
                        'current'      => $current_page,
                        'type'         => 'array',
                    ) );

                    if ( $page_links ) {
                        echo '<span class="pagination-links">' . implode( ' ', $page_links ) . '</span>';
                    }
                    echo '</div></div>';
                }
            }
            ?>
        </div>
        <?php
        // Inline styles removed as they are now in assets/css/admin.css
    }
}
?>