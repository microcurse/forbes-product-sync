<?php
/**
 * Settings template
 *
 * @package Forbes_Product_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get settings
$options = get_option('forbes_product_sync_settings');

// Get API client to test connection
$api = new Forbes_Product_Sync_API_Client();
?>

<div class="wrap forbes-product-sync-main">
    <h1><?php esc_html_e('Forbes Product Sync Settings', 'forbes-product-sync'); ?></h1>
    
    <div class="card">
        <form method="post" action="options.php" id="forbes-product-sync-settings-form">
            <?php settings_fields('forbes_product_sync_settings'); ?>
            
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="api_url"><?php esc_html_e('API URL', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="api_url" name="forbes_product_sync_settings[api_url]" value="<?php echo esc_attr($options['api_url']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Enter the WooCommerce REST API URL of your live site. Example: https://example.com/wp-json/wc/v3/', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="consumer_key"><?php esc_html_e('Consumer Key', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="consumer_key" name="forbes_product_sync_settings[consumer_key]" value="<?php echo esc_attr($options['consumer_key']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Enter your WooCommerce REST API Consumer Key.', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="consumer_secret"><?php esc_html_e('Consumer Secret', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="consumer_secret" name="forbes_product_sync_settings[consumer_secret]" value="<?php echo esc_attr($options['consumer_secret']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Enter your WooCommerce REST API Consumer Secret.', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="sync_tag"><?php esc_html_e('Sync Tag', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="sync_tag" name="forbes_product_sync_settings[sync_tag]" value="<?php echo esc_attr($options['sync_tag']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Enter the tag name used to identify products for syncing.', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div class="submit-container">
                <?php submit_button(); ?>
                
                <button type="button" id="test-connection" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'forbes-product-sync'); ?>
                </button>
                
                <span id="connection-result"></span>
            </div>
        </form>
    </div>
</div> 