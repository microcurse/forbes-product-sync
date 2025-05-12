<?php
/**
 * Attribute sync template
 *
 * @package Forbes_Product_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Process form submission if needed
if (isset($_POST['forbes_product_sync_action'])) {
    // Verify nonce
    $nonce_verified = false;
    
    if ($_POST['forbes_product_sync_action'] === 'sync_attributes' && 
        isset($_POST['forbes_product_sync_nonce']) && 
        wp_verify_nonce($_POST['forbes_product_sync_nonce'], 'forbes_product_sync_attributes')) {
        $nonce_verified = true;
    } elseif ($_POST['forbes_product_sync_action'] === 'refresh_attributes' && 
              isset($_POST['forbes_product_sync_refresh_nonce']) && 
              wp_verify_nonce($_POST['forbes_product_sync_refresh_nonce'], 'forbes_product_sync_refresh')) {
        $nonce_verified = true;
    } elseif ($_POST['forbes_product_sync_action'] === 'sync_selected_attributes' && 
              isset($_POST['forbes_product_sync_selected_attributes_nonce']) && 
              wp_verify_nonce($_POST['forbes_product_sync_selected_attributes_nonce'], 'forbes_product_sync_selected_attributes')) {
        $nonce_verified = true;
    }
    
    if ($nonce_verified) {
        // Process the form submission based on action
        if ($_POST['forbes_product_sync_action'] === 'sync_attributes') {
            // This would be handled by admin class
        } elseif ($_POST['forbes_product_sync_action'] === 'refresh_attributes') {
            // Force refresh attributes
            if (class_exists('Forbes_Product_Sync_API_Attributes')) {
                $api = new Forbes_Product_Sync_API_Attributes();
                $api->clear_attribute_caches();
                
                // Display success message
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Attributes cache has been refreshed successfully.', 'forbes-product-sync');
                echo '</p></div>';
            }
        } elseif ($_POST['forbes_product_sync_action'] === 'sync_selected_attributes') {
            // Process selected attributes
            if (isset($_POST['selected_terms']) && is_array($_POST['selected_terms'])) {
                // Create processor instance
                $processor = new Forbes_Product_Sync_Attributes_Processor();
                
                // Get sync options
                $sync_metadata = isset($_POST['sync_metadata']) && $_POST['sync_metadata'] == '1';
                $handle_conflicts = isset($_POST['handle_conflicts']) && $_POST['handle_conflicts'] == '1';
                
                // Process the selected terms
                $sync_stats = $processor->process_attributes($_POST['selected_terms'], $sync_metadata, $handle_conflicts);
                
                // Set success flag if any items were processed
                $sync_result = ($sync_stats['created'] > 0 || $sync_stats['updated'] > 0);
            } else {
                // No terms were selected
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('No attributes or terms were selected for synchronization.', 'forbes-product-sync');
                echo '</p></div>';
            }
        }
    } else {
        // Invalid nonce
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo esc_html__('Security verification failed. Please try again.', 'forbes-product-sync');
        echo '</p></div>';
    }
}

// Get API client for attributes
$api = new Forbes_Product_Sync_API_Attributes();
$last_update = $api->get_cache_timestamp();

// Load attributes from source for comparison
$attribute_diff = array();
$comparison_html = '';

// Only load comparison data if we're doing a comparison
if (isset($_GET['compare']) && $_GET['compare'] === '1') {
    // Ensure required classes are loaded
    if (!class_exists('Forbes_Product_Sync_Logger')) {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/logging/class-forbes-product-sync-logger.php';
    }
    
    if (!class_exists('Forbes_Product_Sync_API_Attributes')) {
        require_once FORBES_PRODUCT_SYNC_PLUGIN_DIR . 'includes/api/class-forbes-product-sync-api-attributes.php';
    }
    
    // Check if API is configured
    $settings = get_option('forbes_product_sync_settings', array());
    if (empty($settings['api_url']) || empty($settings['consumer_key']) || empty($settings['consumer_secret'])) {
        $comparison_html = '<div class="notice notice-error"><p>' . 
            sprintf(
                __('API is not properly configured. Please <a href="%s">configure the API settings</a> first.', 'forbes-product-sync'),
                admin_url('admin.php?page=forbes-product-sync-settings')
            ) . 
        '</p></div>';
    } else {
        // API is configured - The JS will handle loading the table
        // We prepare the form and the container for the JS to populate
        ob_start();
        ?>
        <form method="post" action="" id="attribute-comparison-form">
            <?php wp_nonce_field('forbes_product_sync_selected_attributes_action', 'forbes_product_sync_selected_attributes_nonce'); ?>
            <input type="hidden" name="forbes_product_sync_action" value="sync_selected_attributes">
            <input type="hidden" name="sync_metadata" value="1">
            <input type="hidden" name="handle_conflicts" value="1">
            
            <div id="attribute-comparison-results">
                <p><?php esc_html_e('Loading attributes for comparison...', 'forbes-product-sync'); ?></p>
            </div>
        </form>
        <?php
        $comparison_html = ob_get_clean();
    }
}

// After handling form submission, show appropriate feedback
if (isset($_POST['forbes_product_sync_action']) && $_POST['forbes_product_sync_action'] === 'sync_selected_attributes') {
    if (isset($sync_result) && $sync_result) {
        // Show success message if sync was successful
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html__('Attributes successfully synchronized!', 'forbes-product-sync');
        if (isset($sync_stats)) {
            echo ' ' . sprintf(
                esc_html__('Created: %d, Updated: %d, Skipped: %d', 'forbes-product-sync'),
                $sync_stats['created'],
                $sync_stats['updated'],
                $sync_stats['skipped']
            );
        }
        echo '</p></div>';
    } else {
        // Show error message if sync failed
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo esc_html__('Failed to synchronize attributes. Please check the error log for details.', 'forbes-product-sync');
        echo '</p></div>';
    }
}
?>

<div class="wrap forbes-product-sync-main">
    <h1><?php esc_html_e('Attribute Synchronization', 'forbes-product-sync'); ?></h1>
    
    <?php if (isset($_GET['compare']) && $_GET['compare'] === '1'): ?>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-attributes')); ?>" class="button button-secondary">
                <span class="dashicons dashicons-arrow-left-alt" style="vertical-align: text-top;"></span>
                <?php esc_html_e('Back to Attributes', 'forbes-product-sync'); ?>
            </a>
        </p>
        <div class="sync-content-wrapper">
            <div class="sync-main-content">
                <div class="card">
                    <h2><?php esc_html_e('Compare and Synchronize Attributes', 'forbes-product-sync'); ?></h2>
                    <p><?php esc_html_e('Select the attributes and terms you want to synchronize from the source site to this site.', 'forbes-product-sync'); ?></p>
                    
                    <!-- Sync options -->
                    <div id="attribute-loading-bar">
                        <div class="attribute-progress-bar"></div>
                    </div>
                    
                    <!-- Results area -->
                    <?php echo $comparison_html; ?>
                </div>
            </div>
            
            <!-- Sidebar for Compare View -->
            <div id="sync-sidebar">
                <div class="sidebar-inner">
                    <!-- Notification area -->
                    <div id="sidebar-notification-section">
                        <h3><?php esc_html_e('Status', 'forbes-product-sync'); ?></h3>
                        <div id="sidebar-notifications"></div>
                    </div>
                    
                    <!-- Selected terms section -->
                    <div class="sidebar-section">
                        <h3><?php esc_html_e('Selected Items', 'forbes-product-sync'); ?></h3>
                        <div class="selected-count">
                            <span id="selected-count">0</span> <?php esc_html_e('items selected', 'forbes-product-sync'); ?>
                        </div>
                        <div class="selected-terms-container">
                            <ul class="selected-terms-list" id="selected-terms-list"></ul>
                        </div>
                    </div>
                    
                    <!-- Options section -->
                    <div class="sidebar-section">
                        <h3><?php esc_html_e('Options', 'forbes-product-sync'); ?></h3>
                        <div class="sidebar-options">
                            <form method="post" action="" id="sync-attributes-form">
                                <?php wp_nonce_field('forbes_product_sync_selected_attributes', 'forbes_product_sync_selected_attributes_nonce'); ?>
                                <input type="hidden" name="forbes_product_sync_action" value="sync_selected_attributes">
                                
                                <!-- Form will be populated with selected terms via JavaScript -->
                                <div id="selected-terms-form-inputs"></div>
                                
                                <label>
                                    <input type="checkbox" name="sync_metadata" value="1" checked>
                                    <?php esc_html_e('Sync metadata', 'forbes-product-sync'); ?>
                                    <span class="tooltip" title="<?php esc_attr_e('Includes suffix, price adjustment, and swatch images', 'forbes-product-sync'); ?>">ⓘ</span>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="handle_conflicts" value="1" checked>
                                    <?php esc_html_e('Handle conflicts', 'forbes-product-sync'); ?>
                                </label>
                                
                                <div class="sidebar-actions">
                                    <button type="submit" class="button button-primary" id="apply-changes-btn">
                                        <?php esc_html_e('Sync Attributes', 'forbes-product-sync'); ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Refresh section -->
                    <div class="sidebar-section">
                        <h3><?php esc_html_e('Refresh Data', 'forbes-product-sync'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('Force a refresh of data from the source site. This clears the local cache and fetches new data.', 'forbes-product-sync'); ?>
                        </p>
                        <button type="button" class="button" id="force-refresh-attributes">
                            <?php esc_html_e('Force Refresh Cache', 'forbes-product-sync'); ?>
                        </button>
                    </div>
                    
                    <!-- Scroll to top -->
                    <div class="sidebar-scroll-top">
                        <button type="button" class="button" id="scroll-to-top" style="display: block; text-align: left; width: 100%;">
                            <span class="dashicons dashicons-arrow-up-alt"></span>
                            <?php esc_html_e('Back to top', 'forbes-product-sync'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Main Attribute Sync Page with summary dashboard -->
        <div class="sync-tabs">
            <div class="sync-tab active"><?php esc_html_e('Overview', 'forbes-product-sync'); ?></div>
        </div>
        
        <div class="sync-content-wrapper">
            <div class="sync-main-content">
                <!-- Action Buttons -->
                <div class="attribute-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=forbes-product-sync-attributes&compare=1')); ?>" class="button button-primary">
                        <?php esc_html_e('Compare & Sync Attributes', 'forbes-product-sync'); ?>
                    </a>
                </div>
                
                <!-- Summary Dashboard -->
                <div class="card" id="sync-summary-dashboard">
                    <h2><?php esc_html_e('Attribute Sync Status', 'forbes-product-sync'); ?></h2>
                    <div class="summary-loading-indicator">
                        <span class="spinner is-active"></span>
                        <span class="loading-text"><?php esc_html_e('Loading status...', 'forbes-product-sync'); ?></span>
                    </div>
                    
                    <!-- Error Message Section -->
                    <div id="sync-error-message" class="notice notice-error" style="display: none;">
                        <p><strong><?php esc_html_e('Error:', 'forbes-product-sync'); ?></strong> <span id="error-message-text"></span></p>
                        <p>
                            <?php esc_html_e('The API request for attribute data timed out or encountered an error. You may need to:', 'forbes-product-sync'); ?>
                            <ul style="list-style-type: disc; margin-left: 20px;">
                                <li><?php esc_html_e('Check your connection to the Forbes site', 'forbes-product-sync'); ?></li>
                                <li><?php esc_html_e('Verify API credentials', 'forbes-product-sync'); ?></li>
                                <li><?php esc_html_e('Try again later when the server load is lower', 'forbes-product-sync'); ?></li>
                            </ul>
                        </p>
                    </div>
                    
                    <div class="summary-content">
                        <div class="summary-cards">
                            <div class="summary-card">
                                <div class="summary-card-header"><?php esc_html_e('New Attributes', 'forbes-product-sync'); ?></div>
                                <div class="summary-card-value new-attributes-count">0</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-card-header"><?php esc_html_e('Modified Attributes', 'forbes-product-sync'); ?></div>
                                <div class="summary-card-value modified-attributes-count">0</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-card-header"><?php esc_html_e('New Terms', 'forbes-product-sync'); ?></div>
                                <div class="summary-card-value new-terms-count">0</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-card-header"><?php esc_html_e('Modified Terms', 'forbes-product-sync'); ?></div>
                                <div class="summary-card-value modified-terms-count">0</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-card-header"><?php esc_html_e('Total Differences', 'forbes-product-sync'); ?></div>
                                <div class="summary-card-value total-differences-count">0</div>
                            </div>
                        </div>
                        
                        <div class="summary-info">
                            <div class="summary-last-update">
                                <strong><?php esc_html_e('Last Sync:', 'forbes-product-sync'); ?></strong>
                                <span class="last-sync-time">
                                    <?php echo !empty($last_update) ? esc_html($last_update) : esc_html__('Never', 'forbes-product-sync'); ?>
                                </span>
                            </div>
                            
                            <div class="summary-refresh">
                                <button type="button" id="load-sync-summary" class="button button-secondary">
                                    <?php esc_html_e('Refresh Status', 'forbes-product-sync'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attributes Status Table -->
                <div class="card">
                    <h2><?php esc_html_e('Attributes Status', 'forbes-product-sync'); ?></h2>
                    <div id="sync-summary-error" class="notice notice-warning" style="display: none;">
                        <p></p>
                    </div>
                    
                    <table class="wp-list-table widefat fixed striped" id="attribute-status-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Attribute', 'forbes-product-sync'); ?></th>
                                <th><?php esc_html_e('Status', 'forbes-product-sync'); ?></th>
                                <th><?php esc_html_e('Terms', 'forbes-product-sync'); ?></th>
                                <th><?php esc_html_e('Term Status', 'forbes-product-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="4">
                                    <span class="spinner is-active"></span>
                                    <?php esc_html_e('Loading attribute status...', 'forbes-product-sync'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>