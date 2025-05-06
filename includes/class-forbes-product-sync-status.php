<?php
/**
 * Sync status handler
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Status
 */
class Forbes_Product_Sync_Status {
    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     *
     * @param array $settings Plugin settings
     */
    public function __construct($settings) {
        $this->settings = $settings;
    }

    /**
     * Get count of synced products
     *
     * @return int
     */
    public function get_synced_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'forbes_product_sync_logs';
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT sku) FROM {$table_name} WHERE status = %s AND action = %s",
                'success',
                'sync'
            )
        );

        return (int) $count;
    }

    /**
     * Get count of pending products
     *
     * @return int
     */
    public function get_pending_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'forbes_product_sync_logs';
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT sku) FROM {$table_name} WHERE status = %s AND action = %s",
                'pending',
                'sync'
            )
        );

        return (int) $count;
    }

    /**
     * Get count of failed syncs
     *
     * @return int
     */
    public function get_failed_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'forbes_product_sync_logs';
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT sku) FROM {$table_name} WHERE status = %s AND action = %s",
                'error',
                'sync'
            )
        );

        return (int) $count;
    }

    /**
     * Get recent sync logs
     *
     * @param int $limit Number of logs to return.
     * @return array
     */
    public function get_recent_logs($limit = 10) {
        $logs = get_option('forbes_product_sync_logs', array());
        $logs = array_slice($logs, -$limit);
        return array_reverse($logs);
    }

    /**
     * Add sync log entry
     *
     * @param int    $product_id Product ID.
     * @param string $status Sync status.
     * @param string $message Log message.
     */
    public function add_log($product_id, $status, $message) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $logs = get_option('forbes_product_sync_logs', array());
        $logs[] = array(
            'date' => current_time('mysql'),
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'status' => $status,
            'message' => $message
        );

        // Keep only the last 100 logs
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }

        update_option('forbes_product_sync_logs', $logs);
    }

    /**
     * Clear all sync logs
     */
    public function clear_logs() {
        delete_option('forbes_product_sync_logs');
    }

    /**
     * Update product sync status
     *
     * @param int    $product_id Product ID.
     * @param string $status Sync status.
     */
    public function update_status($product_id, $status) {
        update_post_meta($product_id, '_forbes_sync_status', $status);
    }

    /**
     * Get product sync status
     *
     * @param int $product_id Product ID.
     * @return string
     */
    public function get_status($product_id) {
        return get_post_meta($product_id, '_forbes_sync_status', true);
    }
} 