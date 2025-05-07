<?php
/**
 * Template for the main product sync page
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap forbes-product-sync-main">
    <h1><?php esc_html_e('Forbes Product Sync', 'forbes-product-sync'); ?></h1>

    <div class="card">
        <h2><?php esc_html_e('Sync Products', 'forbes-product-sync'); ?></h2>
        
        <div class="sync-options">
            <p><?php esc_html_e('This will sync products from the source to your store. You can preview the changes before applying them.', 'forbes-product-sync'); ?></p>

            <form id="forbes-product-sync-form" method="post">
                <?php wp_nonce_field('forbes_product_sync_nonce', 'forbes_product_sync_nonce'); ?>
                
                <div class="form-field">
                    <label>
                        <input type="checkbox" name="dry_run" value="1" checked>
                        <?php esc_html_e('Preview changes before applying', 'forbes-product-sync'); ?>
                    </label>
                </div>

                <div class="form-field">
                    <label>
                        <input type="checkbox" name="sync_images" value="1" checked>
                        <?php esc_html_e('Sync product images', 'forbes-product-sync'); ?>
                    </label>
                </div>

                <div class="form-field">
                    <label>
                        <input type="checkbox" name="sync_attributes" value="1" checked>
                        <?php esc_html_e('Sync product attributes', 'forbes-product-sync'); ?>
                    </label>
                </div>

                <div class="form-field">
                    <label>
                        <input type="checkbox" name="handle_conflicts" value="1" checked>
                        <?php esc_html_e('Show conflicts for review', 'forbes-product-sync'); ?>
                    </label>
                </div>

                <div class="submit-button">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Start Sync', 'forbes-product-sync'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Quick Links', 'forbes-product-sync'); ?></h2>
        
        <div class="quick-links">
            <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-attributes')); ?>" class="button">
                <?php esc_html_e('Attribute Sync', 'forbes-product-sync'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-log')); ?>" class="button">
                <?php esc_html_e('View Sync Log', 'forbes-product-sync'); ?>
            </a>
        </div>
    </div>
</div> 