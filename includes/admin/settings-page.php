<?php
/**
 * Direct settings page render.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render settings page directly without using classes
 */
function fps_render_settings_page() {
    // Get saved values
    $remote_url = get_option( 'fps_remote_site_url', '' );
    $api_username = get_option( 'fps_api_username', '' );
    $api_password = get_option( 'fps_api_password', '' );
    
    // Settings ID
    $settings_id = 'fps_settings';
    
    ?>
    <div class="wrap fps-settings-container">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Settings saved successfully.', 'forbes-product-sync'); ?></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="options.php">
            <?php settings_fields($settings_id); ?>
            
            <h2><?php esc_html_e('API Connection Settings', 'forbes-product-sync'); ?></h2>
            <p><?php esc_html_e('Configure the connection to the remote WordPress site. These settings are required for syncing products and attributes.', 'forbes-product-sync'); ?></p>
            
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="fps_remote_site_url"><?php esc_html_e('Remote Site URL', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="fps_remote_site_url" name="fps_remote_site_url" value="<?php echo esc_attr($remote_url); ?>" class="regular-text" placeholder="<?php esc_attr_e('https://example.com', 'forbes-product-sync'); ?>" required />
                            <p class="description"><?php esc_html_e('The URL of the remote WordPress site (including https://)', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fps_api_username"><?php esc_html_e('API Username/Key', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="fps_api_username" name="fps_api_username" value="<?php echo esc_attr($api_username); ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e('The API consumer key or username', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fps_api_password"><?php esc_html_e('API Password/Token', 'forbes-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="fps_api_password" name="fps_api_password" value="<?php echo esc_attr($api_password); ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e('The API consumer secret or password', 'forbes-product-sync'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <?php submit_button(); ?>
            
            <div class="fps-test-connection-container">
                <button type="button" id="fps-test-connection" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'forbes-product-sync'); ?>
                </button>
                <span id="fps-test-connection-result" class="fps-test-result"></span>
            </div>
        </form>
        
        <!-- Debug Information -->
        <?php if (defined('WP_DEBUG') && WP_DEBUG) : ?>
            <div class="notice notice-info">
                <h3><?php esc_html_e('Debug Information', 'forbes-product-sync'); ?></h3>
                <ul>
                    <li><?php echo 'FPS_PLUGIN_FILE: ' . (defined('FPS_PLUGIN_FILE') ? esc_html(FPS_PLUGIN_FILE) : 'Not defined'); ?></li>
                    <li><?php echo 'FPS_PLUGIN_DIR: ' . (defined('FPS_PLUGIN_DIR') ? esc_html(FPS_PLUGIN_DIR) : 'Not defined'); ?></li>
                    <li><?php echo 'FPS_PLUGIN_URL: ' . (defined('FPS_PLUGIN_URL') ? esc_html(FPS_PLUGIN_URL) : 'Not defined'); ?></li>
                    <li><?php echo 'FPS_VERSION: ' . (defined('FPS_VERSION') ? esc_html(FPS_VERSION) : 'Not defined'); ?></li>
                    <li><?php echo 'Settings class exists: ' . (class_exists('FPS_Admin_Settings') ? 'Yes' : 'No'); ?></li>
                    <li><?php echo 'Admin class exists: ' . (class_exists('FPS_Admin') ? 'Yes' : 'No'); ?></li>
                    <li><?php echo 'AJAX class exists: ' . (class_exists('FPS_AJAX') ? 'Yes' : 'No'); ?></li>
                    <li><?php echo 'get_current_screen()->id: ' . esc_html(get_current_screen()->id); ?></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Register the settings
function fps_register_direct_settings() {
    // Register the remote URL setting
    register_setting(
        'fps_settings',
        'fps_remote_site_url',
        array(
            'type'              => 'string',
            'description'       => 'Remote site URL',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
    
    // Register the API username setting
    register_setting(
        'fps_settings',
        'fps_api_username',
        array(
            'type'              => 'string',
            'description'       => 'API Username/Key',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
    
    // Register the API password setting
    register_setting(
        'fps_settings',
        'fps_api_password',
        array(
            'type'              => 'string',
            'description'       => 'API Password/Token',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
}

// Initialize the settings
add_action('admin_init', 'fps_register_direct_settings'); 