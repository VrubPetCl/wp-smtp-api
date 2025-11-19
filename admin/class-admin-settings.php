<?php
/**
 * Admin Settings Controller
 * Manages the admin settings page and handles form submissions
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SMTP_API_Admin_Settings {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Settings page slug
     */
    private $page_slug = 'wp-smtp-api-settings';

    /**
     * Get instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_wp_smtp_api_test_email', array($this, 'handle_test_email'));
        add_action('admin_post_wp_smtp_api_view_logs', array($this, 'handle_view_logs'));
        add_action('admin_post_wp_smtp_api_clear_logs', array($this, 'handle_clear_logs'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('WP SMTP API Settings', 'wp-smtp-api'),
            __('WP SMTP API', 'wp-smtp-api'),
            'manage_options',
            $this->page_slug,
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'wp_smtp_api_settings_group',
            'wp_smtp_api_settings',
            array(
                'sanitize_callback' => array($this, 'sanitize_settings'),
            )
        );

        // General Settings Section
        add_settings_section(
            'wp_smtp_api_general_section',
            __('API Configuration', 'wp-smtp-api'),
            array($this, 'render_general_section'),
            $this->page_slug
        );

        // API Endpoint
        add_settings_field(
            'api_endpoint',
            __('API Endpoint URL', 'wp-smtp-api'),
            array($this, 'render_api_endpoint_field'),
            $this->page_slug,
            'wp_smtp_api_general_section'
        );

        // JWT Token
        add_settings_field(
            'jwt_token',
            __('JWT Token', 'wp-smtp-api'),
            array($this, 'render_jwt_token_field'),
            $this->page_slug,
            'wp_smtp_api_general_section'
        );

        // Advanced Settings Section
        add_settings_section(
            'wp_smtp_api_advanced_section',
            __('Advanced Settings', 'wp-smtp-api'),
            array($this, 'render_advanced_section'),
            $this->page_slug
        );

        // Timeout
        add_settings_field(
            'timeout',
            __('Request Timeout', 'wp-smtp-api'),
            array($this, 'render_timeout_field'),
            $this->page_slug,
            'wp_smtp_api_advanced_section'
        );

        // SSL Verification
        add_settings_field(
            'ssl_verify',
            __('SSL Verification', 'wp-smtp-api'),
            array($this, 'render_ssl_verify_field'),
            $this->page_slug,
            'wp_smtp_api_advanced_section'
        );

        // Enable Logging
        add_settings_field(
            'enable_logging',
            __('Enable Logging', 'wp-smtp-api'),
            array($this, 'render_logging_field'),
            $this->page_slug,
            'wp_smtp_api_advanced_section'
        );

        // Enable Plugin
        add_settings_field(
            'enabled',
            __('Enable Plugin', 'wp-smtp-api'),
            array($this, 'render_enabled_field'),
            $this->page_slug,
            'wp_smtp_api_advanced_section'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        return WP_SMTP_API_Validator::sanitize_settings($input);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if using constants
        $settings = WP_SMTP_API_Settings::instance();
        $using_constant_endpoint = $settings->is_using_constant_endpoint();
        $using_constant_token = $settings->is_using_constant_token();

        include WP_SMTP_API_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Render general section
     */
    public function render_general_section() {
        echo '<p>' . esc_html__('Configure the API endpoint and authentication for sending emails.', 'wp-smtp-api') . '</p>';
    }

    /**
     * Render advanced section
     */
    public function render_advanced_section() {
        echo '<p>' . esc_html__('Advanced configuration options.', 'wp-smtp-api') . '</p>';
    }

    /**
     * Render API endpoint field
     */
    public function render_api_endpoint_field() {
        $settings = WP_SMTP_API_Settings::instance();
        $value = $settings->get_api_endpoint();
        $disabled = $settings->is_using_constant_endpoint();

        if ($disabled) {
            echo '<p class="description">' . esc_html__('API endpoint is defined in wp-config.php', 'wp-smtp-api') . '</p>';
            echo '<code>define(\'WP_SMTP_API_ENDPOINT\', \'' . esc_attr($value) . '\');</code>';
        } else {
            ?>
            <input type="url"
                   name="wp_smtp_api_settings[api_endpoint]"
                   id="api_endpoint"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text"
                   placeholder="https://api.example.com/send-email"
                   required>
            <p class="description">
                <?php esc_html_e('HTTPS endpoint URL for sending emails. Must use HTTPS.', 'wp-smtp-api'); ?>
            </p>
            <?php
        }
    }

    /**
     * Render JWT token field
     */
    public function render_jwt_token_field() {
        $settings = WP_SMTP_API_Settings::instance();
        $disabled = $settings->is_using_constant_token();

        if ($disabled) {
            echo '<p class="description">' . esc_html__('JWT token is defined in wp-config.php (most secure)', 'wp-smtp-api') . '</p>';
            echo '<code>define(\'WP_SMTP_API_JWT_TOKEN\', \'your-token-here\');</code>';
        } else {
            $value = $settings->get('jwt_token', '');
            ?>
            <input type="password"
                   name="wp_smtp_api_settings[jwt_token]"
                   id="jwt_token"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text"
                   placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
                   required>
            <button type="button" class="button" onclick="togglePasswordVisibility('jwt_token')">
                <?php esc_html_e('Show/Hide', 'wp-smtp-api'); ?>
            </button>
            <p class="description">
                <?php esc_html_e('Long-lived JWT token for API authentication. Stored encrypted in database.', 'wp-smtp-api'); ?>
                <br>
                <strong><?php esc_html_e('For maximum security, define this in wp-config.php instead.', 'wp-smtp-api'); ?></strong>
            </p>
            <?php
        }
    }

    /**
     * Render timeout field
     */
    public function render_timeout_field() {
        $settings = WP_SMTP_API_Settings::instance();
        $value = $settings->get('timeout', 30);
        ?>
        <input type="number"
               name="wp_smtp_api_settings[timeout]"
               id="timeout"
               value="<?php echo esc_attr($value); ?>"
               min="5"
               max="120"
               class="small-text">
        <span><?php esc_html_e('seconds', 'wp-smtp-api'); ?></span>
        <p class="description">
            <?php esc_html_e('Maximum time to wait for API response (5-120 seconds).', 'wp-smtp-api'); ?>
        </p>
        <?php
    }

    /**
     * Render SSL verification field
     */
    public function render_ssl_verify_field() {
        $settings = WP_SMTP_API_Settings::instance();
        $value = $settings->get('ssl_verify', true);
        ?>
        <label>
            <input type="checkbox"
                   name="wp_smtp_api_settings[ssl_verify]"
                   id="ssl_verify"
                   value="1"
                   <?php checked($value, true); ?>>
            <?php esc_html_e('Verify SSL certificates', 'wp-smtp-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Recommended for production. Disable only for development with self-signed certificates.', 'wp-smtp-api'); ?>
        </p>
        <?php
    }

    /**
     * Render logging field
     */
    public function render_logging_field() {
        $settings = WP_SMTP_API_Settings::instance();
        $value = $settings->get('enable_logging', false);
        ?>
        <label>
            <input type="checkbox"
                   name="wp_smtp_api_settings[enable_logging]"
                   id="enable_logging"
                   value="1"
                   <?php checked($value, true); ?>>
            <?php esc_html_e('Enable debug logging', 'wp-smtp-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Log API requests and responses for debugging. Logs are stored securely and auto-cleaned after 30 days.', 'wp-smtp-api'); ?>
        </p>
        <?php
    }

    /**
     * Render enabled field
     */
    public function render_enabled_field() {
        $settings = WP_SMTP_API_Settings::instance();
        $value = $settings->get('enabled', false);
        ?>
        <label>
            <input type="checkbox"
                   name="wp_smtp_api_settings[enabled]"
                   id="enabled"
                   value="1"
                   <?php checked($value, true); ?>>
            <?php esc_html_e('Enable WP SMTP API', 'wp-smtp-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Turn on the plugin to route emails through the API.', 'wp-smtp-api'); ?>
        </p>
        <?php
    }

    /**
     * Handle test email
     */
    public function handle_test_email() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'wp-smtp-api'));
        }

        check_admin_referer('wp_smtp_api_test_email');

        // Rate limiting: Max 3 test emails per 5 minutes per user
        $rate_limit_key = 'wp_smtp_api_test_rate_' . get_current_user_id();
        $test_count = get_transient($rate_limit_key);

        if ($test_count && $test_count >= 3) {
            add_settings_error(
                'wp_smtp_api_messages',
                'wp_smtp_api_rate_limit',
                __('Rate limit exceeded. Please wait 5 minutes before sending another test email.', 'wp-smtp-api'),
                'error'
            );
            set_transient('settings_errors', get_settings_errors(), 30);
            wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
            exit;
        }

        // Increment test count
        set_transient($rate_limit_key, ($test_count ? $test_count + 1 : 1), 5 * MINUTE_IN_SECONDS);

        $client = new WP_SMTP_API_Client();
        $result = $client->test_connection();

        if ($result['success']) {
            add_settings_error(
                'wp_smtp_api_messages',
                'wp_smtp_api_test_success',
                __('Test email sent successfully! Check your inbox.', 'wp-smtp-api'),
                'success'
            );
        } else {
            add_settings_error(
                'wp_smtp_api_messages',
                'wp_smtp_api_test_error',
                sprintf(__('Test email failed: %s', 'wp-smtp-api'), $result['message']),
                'error'
            );
        }

        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    /**
     * Handle view logs
     */
    public function handle_view_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'wp-smtp-api'));
        }

        check_admin_referer('wp_smtp_api_view_logs');

        $logger = WP_SMTP_API_Logger::instance();
        $logs = $logger->read_log(null, 500);

        header('Content-Type: text/plain');
        echo $logs;
        exit;
    }

    /**
     * Handle clear logs
     */
    public function handle_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'wp-smtp-api'));
        }

        check_admin_referer('wp_smtp_api_clear_logs');

        $logger = WP_SMTP_API_Logger::instance();
        $deleted = $logger->delete_all_logs();

        add_settings_error(
            'wp_smtp_api_messages',
            'wp_smtp_api_logs_cleared',
            sprintf(__('%d log file(s) deleted successfully.', 'wp-smtp-api'), $deleted),
            'success'
        );

        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ('settings_page_' . $this->page_slug !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wp-smtp-api-admin',
            WP_SMTP_API_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_SMTP_API_VERSION
        );

        wp_add_inline_script('jquery', "
            function togglePasswordVisibility(fieldId) {
                var field = document.getElementById(fieldId);
                field.type = field.type === 'password' ? 'text' : 'password';
            }
        ");
    }
}
