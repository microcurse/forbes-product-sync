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

// Get API client for attributes
$api = new Forbes_Product_Sync_API_Attributes();
$last_update = $api->get_cache_timestamp();
?>

<div class="wrap forbes-product-sync-main">
    <h1><?php esc_html_e('Attribute Synchronization', 'forbes-product-sync'); ?></h1>
    
    <div class="sync-content-wrapper">
        <div class="sync-main-content">
            <div class="card">
                <div class="sync-controls">
                    <button id="load-attributes" class="button button-primary">
                        <?php esc_html_e('Load Attribute Differences', 'forbes-product-sync'); ?>
                    </button>
                    
                    <button id="refresh-cache-btn" class="button button-secondary">
                        <?php esc_html_e('Refresh Cache', 'forbes-product-sync'); ?>
                    </button>
                    
                    <?php if ($last_update): ?>
                        <div class="last-updated">
                            <?php
                            printf(
                                /* translators: %s: Date/time of last update */
                                esc_html__('Last cache update: %s', 'forbes-product-sync'),
                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_update))
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div id="attribute-loading-bar" class="hidden">
                    <div class="attribute-progress-bar"></div>
                    <div class="attribute-loading-text">
                        <?php esc_html_e('Loading attribute data...', 'forbes-product-sync'); ?>
                    </div>
                </div>
                
                <div id="attribute-comparison-results"></div>
            </div>
        </div>
        
        <!-- Sidebar for attribute selection -->
        <div id="sync-sidebar" class="hidden">
            <div class="sidebar-inner">
                <div class="sidebar-section">
                    <h3><?php esc_html_e('Selected Terms', 'forbes-product-sync'); ?></h3>
                    <div class="selected-count">
                        <span id="selected-terms-count">0</span> <?php esc_html_e('terms selected', 'forbes-product-sync'); ?>
                    </div>
                    
                    <div class="selected-terms-container">
                        <div class="no-terms-message">
                            <?php esc_html_e('No terms selected yet. Check the boxes next to terms you want to sync.', 'forbes-product-sync'); ?>
                        </div>
                        <ul class="selected-terms-list"></ul>
                    </div>
                </div>
                
                <div class="sidebar-section">
                    <h3><?php esc_html_e('Sync Options', 'forbes-product-sync'); ?></h3>
                    
                    <div class="sidebar-options">
                        <label>
                            <input type="checkbox" id="sidebar-sync-metadata" checked>
                            <?php esc_html_e('Sync term metadata', 'forbes-product-sync'); ?>
                        </label>
                        
                        <label>
                            <input type="checkbox" id="sidebar-handle-conflicts" checked>
                            <?php esc_html_e('Handle naming conflicts', 'forbes-product-sync'); ?>
                        </label>
                    </div>
                </div>
                
                <div class="sidebar-actions">
                    <button id="sidebar-apply-btn" class="button button-primary" disabled>
                        <?php esc_html_e('Apply Changes', 'forbes-product-sync'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Template for form submission -->
<form id="forbes-product-sync-form" method="post" class="hidden">
    <input type="hidden" name="action" value="forbes_product_sync_process_attributes">
    <input type="hidden" name="sync_metadata" value="1">
    <input type="hidden" name="handle_conflicts" value="1">
    <button type="submit"><?php esc_html_e('Apply Changes', 'forbes-product-sync'); ?></button>
</form>