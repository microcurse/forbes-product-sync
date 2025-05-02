<?php
/**
 * Sync Status Template
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Product Sync Status', 'forbes-product-sync'); ?></h1>

    <div class="forbes-sync-status">
        <div class="sync-stats">
            <h2><?php esc_html_e('Sync Statistics', 'forbes-product-sync'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Count', 'forbes-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e('Synced Products', 'forbes-product-sync'); ?></td>
                        <td><?php echo esc_html($this->sync_status->get_synced_count()); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Pending Sync', 'forbes-product-sync'); ?></td>
                        <td><?php echo esc_html($this->sync_status->get_pending_count()); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Failed Syncs', 'forbes-product-sync'); ?></td>
                        <td><?php echo esc_html($this->sync_status->get_failed_count()); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="sync-logs">
            <h2><?php esc_html_e('Recent Sync Logs', 'forbes-product-sync'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Product', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                        <th><?php esc_html_e('Message', 'forbes-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $logs = $this->sync_status->get_recent_logs();
                    foreach ($logs as $log) :
                    ?>
                        <tr>
                            <td><?php echo esc_html($log['date']); ?></td>
                            <td><?php echo esc_html($log['product_name']); ?></td>
                            <td><?php echo esc_html($log['status']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="sync-actions">
            <h2><?php esc_html_e('Sync Actions', 'forbes-product-sync'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('forbes_product_sync_action', 'forbes_product_sync_nonce'); ?>
                <p>
                    <input type="submit" name="run_sync" class="button button-primary" value="<?php esc_attr_e('Run Manual Sync', 'forbes-product-sync'); ?>">
                    <input type="submit" name="clear_logs" class="button" value="<?php esc_attr_e('Clear Logs', 'forbes-product-sync'); ?>">
                </p>
            </form>
        </div>
    </div>
</div>

<style>
.forbes-sync-status {
    margin-top: 20px;
}

.sync-stats,
.sync-logs,
.sync-actions {
    margin-bottom: 30px;
}

.widefat {
    width: 100%;
    border-spacing: 0;
    border-collapse: collapse;
}

.widefat th,
.widefat td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid #e5e5e5;
}

.widefat th {
    background: #f1f1f1;
    font-weight: 600;
}

.sync-actions {
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #e5e5e5;
    border-radius: 3px;
}
</style> 