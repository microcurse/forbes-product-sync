<?php
/**
 * Logger class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forbes_Product_Sync_Logger {
    /**
     * Log table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'forbes_product_sync_log';
    }

    /**
     * Log a sync action
     *
     * @param string $sku Product SKU
     * @param string $action Action performed (create, update)
     * @param string $status Status (success, error)
     * @param string $message Log message
     * @return int|false The number of rows inserted, or false on error
     */
    public function log($sku, $action, $status, $message) {
        global $wpdb;

        $product_id = wc_get_product_id_by_sku($sku);

        return $wpdb->insert(
            $this->table_name,
            array(
                'product_id' => $product_id ? $product_id : 0,
                'sku' => $sku,
                'action' => $action,
                'status' => $status,
                'message' => $message,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get logs for a product
     *
     * @param string $sku Product SKU
     * @param int $limit Number of logs to return
     * @return array Log entries
     */
    public function get_logs($sku, $limit = 10) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE sku = %s ORDER BY created_at DESC LIMIT %d",
                $sku,
                $limit
            )
        );
    }

    /**
     * Get recent logs
     *
     * @param int $limit Number of logs to return
     * @return array Log entries
     */
    public function get_recent_logs($limit = 50) {
        $logs = get_option('forbes_product_sync_logs', array());
        return array_slice($logs, 0, $limit);
    }

    /**
     * Clear old logs
     *
     * @param int $days Number of days to keep logs
     * @return int|false Number of rows deleted, or false on error
     */
    public function clear_old_logs($days = 30) {
        global $wpdb;

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Log a sync operation
     *
     * @param string $product_name
     * @param string $status
     * @param string $message
     * @param array $changes
     */
    public function log_sync($product_name, $status, $message, $changes = array()) {
        $logs = $this->get_recent_logs();
        
        // Format changes for display
        $changes_text = '';
        if (!empty($changes)) {
            $change_parts = array();
            foreach ($changes as $field => $value) {
                $change_parts[] = sprintf('%s: %s', $field, $value);
            }
            $changes_text = implode(', ', $change_parts);
        }

        array_unshift($logs, array(
            'date' => current_time('mysql'),
            'product' => $product_name,
            'status' => $status,
            'message' => $message,
            'changes' => $changes_text
        ));

        // Keep only the last 100 logs
        $logs = array_slice($logs, 0, 100);

        update_option('forbes_product_sync_logs', $logs);
    }
} 