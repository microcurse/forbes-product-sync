<?php
/**
 * Admin Page Template
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Product Sync Settings', 'forbes-product-sync'); ?></h1>

    <form method="post" action="options.php">
        <?php
        settings_fields('forbes_product_sync_settings');
        do_settings_sections('forbes_product_sync_settings');
        ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="api_url"><?php esc_html_e('API URL', 'forbes-product-sync'); ?></label>
                </th>
                <td>
                    <input type="url" name="forbes_product_sync_settings[api_url]" id="api_url" 
                           value="<?php echo esc_attr($this->settings['api_url']); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Enter the GraphQL API endpoint URL of the source site.', 'forbes-product-sync'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="api_username"><?php esc_html_e('API Username', 'forbes-product-sync'); ?></label>
                </th>
                <td>
                    <input type="text" name="forbes_product_sync_settings[api_username]" id="api_username" 
                           value="<?php echo esc_attr($this->settings['api_username']); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Enter the API username for authentication.', 'forbes-product-sync'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="api_password"><?php esc_html_e('API Password', 'forbes-product-sync'); ?></label>
                </th>
                <td>
                    <input type="password" name="forbes_product_sync_settings[api_password]" id="api_password" 
                           value="<?php echo esc_attr($this->settings['api_password']); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Enter the API password for authentication.', 'forbes-product-sync'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sync_tag"><?php esc_html_e('Sync Tag', 'forbes-product-sync'); ?></label>
                </th>
                <td>
                    <input type="text" name="forbes_product_sync_settings[sync_tag]" id="sync_tag" 
                           value="<?php echo esc_attr($this->settings['sync_tag']); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Enter the tag that identifies products to be synced.', 'forbes-product-sync'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <p><em><?php esc_html_e('To run a manual sync or view sync status, visit the Sync Status page.', 'forbes-product-sync'); ?></em></p>
</div>

<style>
.sync-actions {
    margin-top: 30px;
    padding: 20px;
    background: #f9f9f9;
    border: 1px solid #e5e5e5;
    border-radius: 3px;
}

.sync-actions h2 {
    margin-top: 0;
}
</style> 