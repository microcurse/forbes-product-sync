<?php
/**
 * Admin page template
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

settings_errors('forbes_product_sync_messages');
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="card">
        <h2><?php esc_html_e('Sync Products', 'forbes-product-sync'); ?></h2>
        <p><?php esc_html_e('Click the button below to sync products from the live site.', 'forbes-product-sync'); ?></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('forbes_product_sync_action', 'forbes_product_sync_nonce'); ?>
            <p>
                <input type="submit" name="run_sync" class="button button-primary" value="<?php esc_attr_e('Pull from Live Site', 'forbes-product-sync'); ?>">
            </p>
        </form>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Settings', 'forbes-product-sync'); ?></h2>
        <p><?php esc_html_e('Configure your API settings in wp-config.php:', 'forbes-product-sync'); ?></p>
        <pre>
define('FORBES_PRODUCT_SYNC_API_URL', 'https://your-live-site.com/graphql');
define('FORBES_PRODUCT_SYNC_API_CREDENTIALS', 'username:password');
        </pre>
    </div>
</div> 