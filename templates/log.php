<?php
/**
 * Log template
 *
 * @package Forbes_Product_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get logs
$logger = Forbes_Product_Sync_Logger::instance();
$logs = $logger->get_recent_logs(50); // Get more logs for the log page
$stats = $logger->get_sync_stats();

// Handle log clearing
if (isset($_POST['action']) && $_POST['action'] === 'clear_logs' && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'forbes_product_sync_clear_logs')) {
    $logger->clear_all_logs();
    ?>
    <div class="notice notice-success">
        <p><?php esc_html_e('Logs cleared successfully.', 'forbes-product-sync'); ?></p>
    </div>
    <?php
    $logs = array(); // Empty logs after clearing
}

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$total_logs = count($logs);
$total_pages = ceil($total_logs / $per_page);

// Slice logs for current page
$logs = array_slice($logs, ($current_page - 1) * $per_page, $per_page);
?>

<div class="wrap forbes-product-sync-main">
    <h1><?php esc_html_e('Forbes Product Sync Log', 'forbes-product-sync'); ?></h1>
    
    <div class="card">
        <div class="log-controls">
            <div class="log-stats">
                <div class="log-stat">
                    <span class="label"><?php esc_html_e('Total Logs:', 'forbes-product-sync'); ?></span>
                    <span class="value"><?php echo esc_html($total_logs); ?></span>
                </div>
                
                <div class="log-stat">
                    <span class="label"><?php esc_html_e('Success:', 'forbes-product-sync'); ?></span>
                    <span class="value"><?php echo esc_html($stats['success']); ?></span>
                </div>
                
                <div class="log-stat">
                    <span class="label"><?php esc_html_e('Errors:', 'forbes-product-sync'); ?></span>
                    <span class="value"><?php echo esc_html($stats['error']); ?></span>
                </div>
                
                <div class="log-stat">
                    <span class="label"><?php esc_html_e('Products Created:', 'forbes-product-sync'); ?></span>
                    <span class="value"><?php echo esc_html($stats['created']); ?></span>
                </div>
                
                <div class="log-stat">
                    <span class="label"><?php esc_html_e('Products Updated:', 'forbes-product-sync'); ?></span>
                    <span class="value"><?php echo esc_html($stats['updated']); ?></span>
                </div>
            </div>
            
            <div class="log-actions">
                <form method="post" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs?', 'forbes-product-sync'); ?>');">
                    <?php wp_nonce_field('forbes_product_sync_clear_logs'); ?>
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Clear Logs', 'forbes-product-sync'); ?></button>
                </form>
            </div>
        </div>
        
        <?php if (empty($logs)): ?>
        <p><?php esc_html_e('No logs found.', 'forbes-product-sync'); ?></p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="15%"><?php esc_html_e('Time', 'forbes-product-sync'); ?></th>
                    <th width="20%"><?php esc_html_e('Product', 'forbes-product-sync'); ?></th>
                    <th width="10%"><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                    <th width="25%"><?php esc_html_e('Message', 'forbes-product-sync'); ?></th>
                    <th width="30%"><?php esc_html_e('Changes', 'forbes-product-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['date']))); ?></td>
                    <td><?php echo esc_html($log['product']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr($log['status']); ?>">
                            <?php echo esc_html(ucfirst($log['status'])); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($log['message']); ?></td>
                    <td><?php echo esc_html($log['changes']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    printf(
                        /* translators: %s: Number of items */
                        esc_html(_n('%s item', '%s items', $total_logs, 'forbes-product-sync')),
                        number_format_i18n($total_logs)
                    );
                    ?>
                </span>
                
                <span class="pagination-links">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $current_page
                    ));
                    ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
</div> 