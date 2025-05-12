<?php
/**
 * Dashboard template
 *
 * @package Forbes_Product_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get logger stats
$logger = Forbes_Product_Sync_Logger::instance();

// Check if log table exists
global $wpdb;
$table_name = FORBES_PRODUCT_SYNC_LOG_TABLE;
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

// Get stats only if table exists
if ($table_exists) {
    $stats = $logger->get_sync_stats();
    $recent_logs = $logger->get_recent_logs(10);
} else {
    // Default values if table doesn't exist
    $stats = array(
        'success' => 0,
        'error' => 0,
        'created' => 0,
        'updated' => 0
    );
    $recent_logs = array();
}

// Get batch processor
require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/batch/class-forbes-product-sync-batch-processor.php';

// Get batch processor status
$batch_processor = new Forbes_Product_Sync_Batch_Processor();
$queue_status = $batch_processor->get_status();
$is_processing = $batch_processor->is_processing();
?>

<div class="wrap forbes-product-sync-main">
    <h1><?php esc_html_e('Forbes Product Sync Dashboard', 'forbes-product-sync'); ?></h1>
    
    <?php if (!$table_exists): ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Database tables are missing. Please repair the database to continue.', 'forbes-product-sync'); ?></p>
        <p><button id="repair-database" class="button button-primary"><?php esc_html_e('Repair Database Tables', 'forbes-product-sync'); ?></button></p>
    </div>
    <?php endif; ?>
    
    <div class="dashboard-grid">
        <div class="card">
            <h2><?php esc_html_e('Sync Status', 'forbes-product-sync'); ?></h2>
            
            <div class="summary">
                <div class="summary-item">
                    <h3><?php esc_html_e('Products', 'forbes-product-sync'); ?></h3>
                    <div class="count"><?php echo esc_html($stats['created'] + $stats['updated']); ?></div>
                    <div class="details">
                        <?php echo esc_html(sprintf(
                            __('Created: %d, Updated: %d', 'forbes-product-sync'),
                            $stats['created'],
                            $stats['updated']
                        )); ?>
                    </div>
                </div>
                
                <div class="summary-item">
                    <h3><?php esc_html_e('Success', 'forbes-product-sync'); ?></h3>
                    <div class="count"><?php echo esc_html($stats['success']); ?></div>
                </div>
                
                <div class="summary-item">
                    <h3><?php esc_html_e('Errors', 'forbes-product-sync'); ?></h3>
                    <div class="count"><?php echo esc_html($stats['error']); ?></div>
                </div>
            </div>
            
            <?php if ($is_processing && $table_exists): ?>
            <div class="sync-progress-container">
                <h3><?php esc_html_e('Current Sync Progress', 'forbes-product-sync'); ?></h3>
                <?php 
                $progress = $batch_processor->get_progress();
                $percent = $progress['percent'];
                ?>
                <div class="sync-progress">
                    <div class="sync-progress-bar" style="width: <?php echo esc_attr($percent); ?>%"></div>
                </div>
                <div class="sync-progress-text">
                    <?php echo esc_html(sprintf(
                        __('Processing %d of %d items (%d%%)', 'forbes-product-sync'),
                        $progress['processed'],
                        $progress['total'],
                        $progress['percent']
                    )); ?>
                </div>
                
                <div class="sync-actions">
                    <button id="cancel-sync" class="button button-secondary">
                        <?php esc_html_e('Cancel Sync', 'forbes-product-sync'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="sync-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-attributes')); ?>" class="button button-primary">
                    <?php esc_html_e('Sync Attributes', 'forbes-product-sync'); ?>
                </a>
                
                <button id="sync-products" class="button button-primary">
                    <?php esc_html_e('Sync Products', 'forbes-product-sync'); ?>
                </button>
                
                <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-settings')); ?>" class="button button-secondary">
                    <?php esc_html_e('Settings', 'forbes-product-sync'); ?>
                </a>
            </div>
        </div>
        
        <div class="card">
            <h2><?php esc_html_e('Recent Sync Logs', 'forbes-product-sync'); ?></h2>
            
            <?php if (!$table_exists): ?>
            <p><?php esc_html_e('Log table not available. Please repair the database.', 'forbes-product-sync'); ?></p>
            <?php elseif (empty($recent_logs)): ?>
            <p><?php esc_html_e('No recent logs found.', 'forbes-product-sync'); ?></p>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Product', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Message', 'forbes-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['date']))); ?></td>
                        <td><?php echo esc_html($log['product']); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr($log['status']); ?>">
                                <?php echo esc_html(ucfirst($log['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log['message']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="sync-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-log')); ?>" class="button button-secondary">
                    <?php esc_html_e('View All Logs', 'forbes-product-sync'); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Repair database button
    $('#repair-database').on('click', function() {
        $(this).prop('disabled', true).text('<?php esc_html_e('Repairing...', 'forbes-product-sync'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'forbes_product_sync_repair_db',
                nonce: '<?php echo wp_create_nonce('forbes_product_sync_repair_db'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('<?php esc_html_e('Database repair failed. Please contact support.', 'forbes-product-sync'); ?>');
                    $('#repair-database').prop('disabled', false).text('<?php esc_html_e('Repair Database Tables', 'forbes-product-sync'); ?>');
                }
            },
            error: function() {
                alert('<?php esc_html_e('Database repair failed. Please contact support.', 'forbes-product-sync'); ?>');
                $('#repair-database').prop('disabled', false).text('<?php esc_html_e('Repair Database Tables', 'forbes-product-sync'); ?>');
            }
        });
    });
});
</script> 