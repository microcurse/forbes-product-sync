<?php
/**
 * Template for attribute sync form
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap forbes-product-sync-form">
    <div class="sync-content-wrapper">
        <!-- Main Content Area -->
        <div class="sync-main-content">
            <h1><?php esc_html_e('Attribute Sync', 'forbes-product-sync'); ?></h1>

            <div class="card">
                <h2><?php esc_html_e('Sync Attributes', 'forbes-product-sync'); ?></h2>
                
                <div class="sync-options">
                    <p><?php esc_html_e('This will sync attributes and their terms from the source to your store. Click "Get Attributes" to compare and review differences before syncing.', 'forbes-product-sync'); ?></p>

                    <button type="button" class="button button-primary" id="get-attributes-btn">
                        <?php esc_html_e('Get Attributes', 'forbes-product-sync'); ?>
                    </button>
                    <button type="button" class="button" id="refresh-cache-btn" title="<?php esc_attr_e('Clear cache and fetch fresh data', 'forbes-product-sync'); ?>">
                        <?php esc_html_e('Refresh Cache', 'forbes-product-sync'); ?>
                    </button>
                    <span id="get-attributes-status" style="margin-left: 16px;"></span>

                    <div id="attribute-comparison-results" style="margin-top: 32px;"></div>

                    <form id="forbes-product-sync-form" method="post" style="display:none;">
                        <?php wp_nonce_field('forbes_product_sync_nonce', 'forbes_product_sync_nonce'); ?>
                        <div class="form-field">
                            <label>
                                <input type="checkbox" name="sync_metadata" value="1" checked>
                                <?php esc_html_e('Sync term metadata (swatches, suffixes, price adjustments)', 'forbes-product-sync'); ?>
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
        </div>
        
        <!-- Persistent Sidebar -->
        <div id="sync-sidebar">
            <div class="sidebar-inner">
                <div class="sidebar-section">
                    <h3><?php esc_html_e('Selected Terms', 'forbes-product-sync'); ?></h3>
                    <div class="selected-count">
                        <span id="selected-terms-count">0</span> <?php esc_html_e('terms selected', 'forbes-product-sync'); ?>
                    </div>
                    <div id="selected-terms-list" class="selected-terms-container">
                        <p class="no-terms-message"><?php esc_html_e('No terms selected', 'forbes-product-sync'); ?></p>
                        <ul class="selected-terms-list"></ul>
                    </div>
                </div>
                
                <div class="sidebar-section">
                    <h3><?php esc_html_e('Sync Options', 'forbes-product-sync'); ?></h3>
                    <div class="sidebar-options">
                        <label>
                            <input type="checkbox" id="sidebar-sync-metadata" checked>
                            <?php esc_html_e('Sync metadata', 'forbes-product-sync'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="sidebar-handle-conflicts" checked>
                            <?php esc_html_e('Show conflicts', 'forbes-product-sync'); ?>
                        </label>
                    </div>
                </div>
                
                <div class="sidebar-section sidebar-actions">
                    <button type="button" id="sidebar-apply-btn" class="button button-primary button-large" disabled>
                        <?php esc_html_e('Apply Selected', 'forbes-product-sync'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div> 