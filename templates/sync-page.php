<?php
/**
 * Sync page template
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get API instance
$api = Forbes_Product_Sync::get_instance()->get_api();

// Get current page
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20; // Number of products per page

// Handle sync action
if (isset($_POST['start_sync']) && check_admin_referer('forbes_product_sync_start')) {
    $products = $api->get_products();
    if (!is_wp_error($products)) {
        $success_count = 0;
        $error_count = 0;
        
        foreach ($products as $product) {
            $result = Forbes_Product_Sync::get_instance()->get_product()->sync_product($product);
            if (is_wp_error($result)) {
                $error_count++;
            } else {
                $success_count++;
            }
        }
        
        if ($success_count > 0 || $error_count > 0) {
            add_settings_error(
                'forbes_product_sync',
                'sync_complete',
                sprintf(
                    __('Sync completed. %d products synced successfully, %d failed.', 'forbes-product-sync'),
                    $success_count,
                    $error_count
                ),
                $error_count === 0 ? 'success' : 'warning'
            );
        }
    }
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('forbes_product_sync'); ?>

    <div class="sync-info">
        <p>Products will be synced based on the tag: <code><?php echo esc_html($api->get_sync_tag()); ?></code></p>
        <p>You can change this tag in the <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-settings')); ?>">Settings</a> page.</p>
    </div>

    <?php
    // Handle preview request
    if (isset($_POST['preview_sync']) && check_admin_referer('forbes_product_sync_preview')) {
        $products = $api->get_products(array(
            'per_page' => $per_page,
            'page' => $current_page
        ));

        if (is_wp_error($products)) {
            ?>
            <div class="notice notice-error">
                <p><?php echo esc_html($products->get_error_message()); ?></p>
                <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                    <p><strong>Debug Info:</strong></p>
                    <pre><?php echo esc_html(print_r($products->get_error_data(), true)); ?></pre>
                <?php endif; ?>
            </div>
            <?php
        } elseif (empty($products)) {
            ?>
            <div class="notice notice-warning">
                <p>No products found with sync tag: <code><?php echo esc_html($api->get_sync_tag()); ?></code></p>
                <p>Please check your API settings and make sure the sync tag is correct.</p>
            </div>
            <?php
        } else {
            // Get total products count for pagination
            $total_products = $api->get_products(array('per_page' => 1));
            $total_pages = ceil(count($total_products) / $per_page);
            ?>
            <div class="sync-preview">
                <h2>Sync Preview</h2>
                <p>Found <?php echo count($products); ?> products with sync tag: <code><?php echo esc_html($api->get_sync_tag()); ?></code></p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo esc_html($product['sku']); ?></td>
                                <td><?php echo esc_html($product['price']); ?></td>
                                <td><?php echo esc_html($product['stock_quantity']); ?></td>
                                <td>
                                    <?php
                                    $local_product = $api->wc_get_product_by_sku($product['sku']);
                                    if ($local_product) {
                                        echo '<span class="sync-status synced">Synced</span>';
                                    } else {
                                        echo '<span class="sync-status not-synced">Not Synced</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo count($total_products); ?> items</span>
                            <span class="pagination-links">
                                <?php
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%'),
                                    'format' => '',
                                    'prev_text' => __('&laquo;'),
                                    'next_text' => __('&raquo;'),
                                    'total' => $total_pages,
                                    'current' => $current_page
                                ));
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <?php wp_nonce_field('forbes_product_sync_start'); ?>
                    <input type="hidden" name="start_sync" value="1">
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Start Sync">
                    </p>
                </form>
            </div>
            <?php
        }
    }
    ?>

    <form method="post" action="">
        <?php wp_nonce_field('forbes_product_sync_preview'); ?>
        <input type="hidden" name="preview_sync" value="1">
        <p class="submit">
            <input type="submit" class="button button-secondary" value="Preview Sync">
        </p>
    </form>

    <div class="sync-status-section">
        <h2>Sync Status</h2>
        <?php
        $stats = Forbes_Product_Sync::get_instance()->get_sync_stats();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Total Products</th>
                    <th>Synced in Last 24 Hours</th>
                    <th>Products Needing Sync</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo esc_html($stats['total']); ?></td>
                    <td><?php echo esc_html($stats['last_synced']); ?></td>
                    <td><?php echo esc_html($stats['needs_sync']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="sync-logs-section">
        <h2>Recent Sync Logs</h2>
        <?php
        $logs = Forbes_Product_Sync::get_instance()->get_logger()->get_recent_logs();
        if (!empty($logs)) {
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Changes</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['date']); ?></td>
                            <td><?php echo esc_html($log['product']); ?></td>
                            <td><?php echo esc_html($log['changes']); ?></td>
                            <td>
                                <span class="sync-status <?php echo esc_attr($log['status']); ?>">
                                    <?php echo esc_html($log['status']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>No sync logs found.</p>';
        }
        ?>
    </div>

    <div class="help-section">
        <h2>Help</h2>
        <p>Use the preview button to see which products will be synced before starting the sync process.</p>
        <p>The sync status shows the current state of all products in your store.</p>
        <p>Recent sync logs show the last 10 sync operations performed.</p>
    </div>
</div> 