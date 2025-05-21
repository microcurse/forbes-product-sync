<?php
/**
 * Logger class for Forbes Product Sync.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Logger Class.
 */
class FPS_Logger {

    /**
     * Log table name.
     *
     * @var string
     */
    private static $table_name = '';

    /**
     * Initialize logger, set table name.
     */
    public static function init() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'fps_sync_logs';
    }

    /**
     * Create the custom log table.
     */
    public static function create_table() {
        global $wpdb;
        self::init(); // Ensure table name is set

        $charset_collate = $wpdb->get_charset_collate();
        
        // Ensure dbDelta function is available
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $sql = "CREATE TABLE " . self::$table_name . " (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            log_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            action_type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            item_name VARCHAR(255) DEFAULT NULL,
            source_item_id VARCHAR(255) DEFAULT NULL,
            local_item_id BIGINT(20) UNSIGNED DEFAULT NULL,
            message TEXT,
            PRIMARY KEY  (id),
            KEY action_type (action_type),
            KEY status (status),
            KEY log_timestamp (log_timestamp)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    /**
     * Log an action.
     *
     * @param string $action_type Type of action (e.g., 'attribute_sync', 'product_sync').
     * @param string $status Status (e.g., 'SUCCESS', 'ERROR', 'INFO', 'SKIPPED').
     * @param string $item_name Name of the item being processed.
     * @param string $message Log message.
     * @param string|null $source_item_id ID/SKU from the source site.
     * @param int|null $local_item_id ID on the destination site.
     * @return bool|int False on error, number of rows inserted on success.
     */
    public static function log( $action_type, $status, $item_name, $message = '', $source_item_id = null, $local_item_id = null ) {
        global $wpdb;
        self::init(); // Ensure table name is set

        if ( empty( $action_type ) || empty( $status ) ) {
            return false;
        }

        $data = array(
            'action_type'    => sanitize_text_field( $action_type ),
            'status'         => sanitize_text_field( $status ),
            'item_name'      => sanitize_text_field( $item_name ),
            'message'        => wp_kses_post( $message ), // Allow some HTML for basic formatting if needed later
            'source_item_id' => $source_item_id ? sanitize_text_field( $source_item_id ) : null,
            'local_item_id'  => $local_item_id ? absint( $local_item_id ) : null,
            // log_timestamp is defaulted to CURRENT_TIMESTAMP by MySQL
        );
        
        $format = array(
            '%s', // action_type
            '%s', // status
            '%s', // item_name
            '%s', // message
            '%s', // source_item_id
            '%d', // local_item_id
        );

        $result = $wpdb->insert( self::$table_name, $data, $format );
        
        return $result;
    }

    /**
     * Clear all logs from the table.
     * @return bool|int False on error, number of rows deleted on success.
     */
    public static function clear_logs() {
        global $wpdb;
        self::init();
        // Use TRUNCATE for efficiency if possible, otherwise DELETE
        // $wpdb->query( "TRUNCATE TABLE " . self::$table_name ); 
        // For broader compatibility and to return affected rows, DELETE is fine.
        return $wpdb->query( "DELETE FROM " . self::$table_name );
    }
}

// Initialize the logger to set the table name early, though create_table also calls init.

?>
