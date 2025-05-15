<?php
/**
 * Attribute Sync admin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Admin_Attribute_Sync Class.
 */
class FPS_Admin_Attribute_Sync {
    /**
     * Output the attribute sync page.
     */
    public static function output() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <div class="fps-notice notice notice-info">
                <p><?php esc_html_e( 'This is a placeholder for the Attribute Sync page. Implementation will be added in future updates.', 'forbes-product-sync' ); ?></p>
            </div>
        </div>
        <?php
    }
} 