<?php
/**
 * Logger class
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Logger
 * Centralized logging system for Forbes Product Sync
 */
class Forbes_Product_Sync_Logger {
    /**
     * Log levels
     */
    const LEVEL_ERROR   = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_INFO    = 'info';
    const LEVEL_SUCCESS = 'success';
    
    /**
     * Log actions
     */
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_SYNC   = 'sync';
    const ACTION_DELETE = 'delete';
    
    /**
     * Log table name
     *
     * @var string
     */
    private $table_name;
    
    /**
     * Maximum number of logs to keep in memory
     *
     * @var int
     */
    private $max_logs = 100;

    /**
     * Singleton instance
     *
     * @var self
     */
    private static $instance = null;
    
    /**
     * Get instance
     *
     * @return self
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = FORBES_PRODUCT_SYNC_LOG_TABLE;
    }

    /**
     * Log a sync action
     *
     * @param string $sku Product SKU
     * @param string $action Action performed (create, update)
     * @param string $status Status (success, error)
     * @param string $message Log message
     * @param array  $meta Additional metadata
     * @return int|false The number of rows inserted, or false on error
     */
    public function log($sku, $action, $status, $message, $meta = array()) {
        global $wpdb;

        $product_id = wc_get_product_id_by_sku($sku);
        
        // Convert metadata to JSON
        $metadata = !empty($meta) ? wp_json_encode($meta) : null;

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
    public function get_logs_by_sku($sku, $limit = 10) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE sku = %s ORDER BY created_at DESC LIMIT %d",
                $sku,
                $limit
            ),
            ARRAY_A
        );
    }
    
    /**
     * Get logs by product ID
     *
     * @param int $product_id Product ID
     * @param int $limit Number of logs to return
     * @return array Log entries
     */
    public function get_logs_by_product_id($product_id, $limit = 10) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE product_id = %d ORDER BY created_at DESC LIMIT %d",
                $product_id,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Get recent logs
     *
     * @param string $type Optional log type filter (e.g., 'attribute', 'product')
     * @param int $limit Number of logs to return
     * @return array Log entries
     */
    public function get_recent_logs($type = '', $limit = 50) {
        $logs = get_option('forbes_product_sync_logs', array());
        
        // Filter by type if specified
        if (!empty($type)) {
            $filtered_logs = array();
            foreach ($logs as $log) {
                if (isset($log['type']) && $log['type'] === $type) {
                    $filtered_logs[] = $log;
                }
            }
            $logs = $filtered_logs;
        }
        
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
     * @param string $product_name Product name or identifier
     * @param string $status Status (success, error, warning, info)
     * @param string $message Message
     * @param array $changes Array of changes for detailed logging
     * @param string $type Type of log entry (e.g., 'attribute', 'product')
     */
    public function log_sync($product_name, $status, $message, $changes = array(), $type = '') {
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
            'changes' => $changes_text,
            'type' => $type
        ));

        // Keep only the last X logs
        $logs = array_slice($logs, 0, $this->max_logs);

        update_option('forbes_product_sync_logs', $logs);
    }
    
    /**
     * Get sync stats
     *
     * @return array Statistics about sync operations
     */
    public function get_sync_stats() {
        global $wpdb;
        
        $stats = array(
            'total' => 0,
            'success' => 0,
            'error' => 0,
            'warning' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0
        );
        
        // Get total counts
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status",
            ARRAY_A
        );
        
        if ($counts) {
            foreach ($counts as $count) {
                if (isset($stats[$count['status']])) {
                    $stats[$count['status']] = (int) $count['count'];
                }
                $stats['total'] += (int) $count['count'];
            }
        }
        
        // Get action counts
        $action_counts = $wpdb->get_results(
            "SELECT action, COUNT(*) as count FROM {$this->table_name} WHERE status = 'success' GROUP BY action",
            ARRAY_A
        );
        
        if ($action_counts) {
            foreach ($action_counts as $count) {
                switch ($count['action']) {
                    case self::ACTION_CREATE:
                        $stats['created'] = (int) $count['count'];
                        break;
                    case self::ACTION_UPDATE:
                        $stats['updated'] = (int) $count['count'];
                        break;
                    case self::ACTION_DELETE:
                        $stats['deleted'] = (int) $count['count'];
                        break;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Clear all logs
     */
    public function clear_all_logs() {
        global $wpdb;
        
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        delete_option('forbes_product_sync_logs');
    }
} 