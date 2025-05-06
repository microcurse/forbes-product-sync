<?php
/**
 * Settings page template
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('forbes_product_sync'); ?>

    <div class="forbes-product-sync-admin">
        <div class="forbes-product-sync-settings">
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" id="forbes-settings-form">
                <?php
                settings_fields('forbes_product_sync_settings');
                do_settings_sections('forbes-product-sync');
                ?>
                <p class="submit">
                    <?php submit_button(__('Save API Settings', 'forbes-product-sync'), 'primary', 'submit', false, array('id' => 'save-settings')); ?>
                </p>
            </form>

            <form method="post" action="" id="forbes-test-form" style="margin-top: 20px;">
                <?php wp_nonce_field('forbes_product_sync_test', 'forbes_test_nonce'); ?>
                <input type="hidden" name="test_connection" value="1">
                <?php submit_button(__('Test Connection', 'forbes-product-sync'), 'secondary', 'submit', false, array('id' => 'test-connection')); ?>
            </form>
        </div>

        <div class="forbes-product-sync-help">
            <h2><?php esc_html_e('Help', 'forbes-product-sync'); ?></h2>
            <div class="forbes-product-sync-help-content">
                <h3><?php esc_html_e('How to use this plugin', 'forbes-product-sync'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Configure your WooCommerce REST API settings above.', 'forbes-product-sync'); ?></li>
                    <li><?php esc_html_e('Click "Test Connection" to verify your settings.', 'forbes-product-sync'); ?></li>
                    <li><?php esc_html_e('Click "Save API Settings" to save your configuration.', 'forbes-product-sync'); ?></li>
                    <li><?php esc_html_e('Go to the Sync Products page to start syncing products.', 'forbes-product-sync'); ?></li>
                </ol>

                <h3><?php esc_html_e('API Settings', 'forbes-product-sync'); ?></h3>
                <p><?php esc_html_e('You can find your API credentials in WooCommerce > Settings > Advanced > REST API.', 'forbes-product-sync'); ?></p>
            </div>
        </div>
    </div>
</div> 