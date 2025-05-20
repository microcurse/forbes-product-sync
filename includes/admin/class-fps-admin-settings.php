<?php
/**
 * Settings admin class.
 *
 * @package Forbes_Product_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * FPS_Admin_Settings Class.
 */
class FPS_Admin_Settings {
    /**
     * Setting page id.
     *
     * @var string
     */
    private static $settings_id = 'fps_settings';
    
    /**
     * Constructor.
     */
    public static function init() {
        // Register settings
        self::register_settings();
    }
    
    /**
     * Register settings.
     */
    public static function register_settings() {
        register_setting(
            self::$settings_id,
            'fps_remote_site_url',
            array(
                'type'              => 'string',
                'description'       => __( 'Remote site URL', 'forbes-product-sync' ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_url' ),
                'show_in_rest'      => false,
                'default'           => '',
            )
        );
        
        register_setting(
            self::$settings_id,
            'fps_api_username',
            array(
                'type'              => 'string',
                'description'       => __( 'API Username/Key', 'forbes-product-sync' ),
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest'      => false,
                'default'           => '',
            )
        );
        
        register_setting(
            self::$settings_id,
            'fps_api_password',
            array(
                'type'              => 'string',
                'description'       => __( 'API Password/Token', 'forbes-product-sync' ),
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest'      => false,
                'default'           => '',
            )
        );
        
        // Register settings section
        add_settings_section(
            'fps_api_settings_section',
            __( 'API Connection Settings', 'forbes-product-sync' ),
            array( __CLASS__, 'render_section_description' ),
            self::$settings_id
        );
        
        // Register settings fields
        add_settings_field(
            'fps_remote_site_url',
            __( 'Remote Site URL', 'forbes-product-sync' ),
            array( __CLASS__, 'render_url_field' ),
            self::$settings_id,
            'fps_api_settings_section'
        );
        
        add_settings_field(
            'fps_api_username',
            __( 'API Username/Key', 'forbes-product-sync' ),
            array( __CLASS__, 'render_username_field' ),
            self::$settings_id,
            'fps_api_settings_section'
        );
        
        add_settings_field(
            'fps_api_password',
            __( 'API Password/Token', 'forbes-product-sync' ),
            array( __CLASS__, 'render_password_field' ),
            self::$settings_id,
            'fps_api_settings_section'
        );
    }
    
    /**
     * Sanitize URL.
     *
     * @param string $url URL to sanitize.
     * @return string
     */
    public static function sanitize_url( $url ) {
        $url = sanitize_text_field( $url );
        return rtrim( $url, '/' );
    }
    
    /**
     * Render section description.
     */
    public static function render_section_description() {
        echo '<p>' . esc_html__( 'Configure the connection to the remote WordPress site. These settings are required for syncing products and attributes.', 'forbes-product-sync' ) . '</p>';
    }
    
    /**
     * Render URL field.
     */
    public static function render_url_field() {
        $value = get_option( 'fps_remote_site_url', '' );
        ?>
        <input type="url" id="fps_remote_site_url" name="fps_remote_site_url" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'https://example.com', 'forbes-product-sync' ); ?>" required />
        <p class="description"><?php esc_html_e( 'The URL of the remote WordPress site (including https://)', 'forbes-product-sync' ); ?></p>
        <?php
    }
    
    /**
     * Render username field.
     */
    public static function render_username_field() {
        $value = get_option( 'fps_api_username', '' );
        ?>
        <input type="text" id="fps_api_username" name="fps_api_username" value="<?php echo esc_attr( $value ); ?>" class="regular-text" required />
        <p class="description"><?php esc_html_e( 'The API consumer key or username', 'forbes-product-sync' ); ?></p>
        <?php
    }
    
    /**
     * Render password field.
     */
    public static function render_password_field() {
        $value = get_option( 'fps_api_password', '' );
        ?>
        <input type="password" id="fps_api_password" name="fps_api_password" value="<?php echo esc_attr( $value ); ?>" class="regular-text" required />
        <p class="description"><?php esc_html_e( 'The API consumer secret or password', 'forbes-product-sync' ); ?></p>
        <?php
    }
    
    /**
     * Output the settings page.
     */
    public static function output() {
        // self::init(); // Removed this call as settings are registered on admin_init
        ?>
        <div class="wrap fps-settings-container">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved successfully.', 'forbes-product-sync'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                // Add the nonce and action fields
                settings_fields( self::$settings_id );
                
                // Manually create the settings table instead of using do_settings_sections
                ?>
                <h2><?php esc_html_e('API Connection Settings', 'forbes-product-sync'); ?></h2>
                <p><?php esc_html_e('Configure the connection to the remote WordPress site. These settings are required for syncing products and attributes.', 'forbes-product-sync'); ?></p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="fps_remote_site_url"><?php esc_html_e('Remote Site URL', 'forbes-product-sync'); ?></label>
                            </th>
                            <td>
                                <?php self::render_url_field(); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fps_api_username"><?php esc_html_e('API Username/Key', 'forbes-product-sync'); ?></label>
                            </th>
                            <td>
                                <?php self::render_username_field(); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fps_api_password"><?php esc_html_e('API Password/Token', 'forbes-product-sync'); ?></label>
                            </th>
                            <td>
                                <?php self::render_password_field(); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php
                submit_button();
                ?>
                <div class="fps-test-connection-container">
                    <button type="button" id="fps-test-connection" class="button button-secondary">
                        <?php esc_html_e( 'Test Connection', 'forbes-product-sync' ); ?>
                    </button>
                    <span id="fps-test-connection-result" class="fps-test-result"></span>
                </div>
            </form>
        </div>
        <?php
    }
}

// Initialize settings
add_action( 'admin_init', array( 'FPS_Admin_Settings', 'init' ) ); 