<?php
/**
 * Plugin Name: WP SMTP API
 * Plugin URI: https://github.com/VrubPetCl/wp-smtp-api
 * Description: Secure SMTP replacement that sends emails via JWT-authenticated API over HTTPS
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-smtp-api
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WP_SMTP_API_VERSION', '1.0.0');
define('WP_SMTP_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_SMTP_API_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_SMTP_API_PLUGIN_FILE', __FILE__);

/**
 * Main plugin class
 */
class WP_SMTP_API {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
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
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once WP_SMTP_API_PLUGIN_DIR . 'includes/class-settings.php';
        require_once WP_SMTP_API_PLUGIN_DIR . 'includes/class-validator.php';
        require_once WP_SMTP_API_PLUGIN_DIR . 'includes/class-logger.php';
        require_once WP_SMTP_API_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once WP_SMTP_API_PLUGIN_DIR . 'includes/class-api-mailer.php';

        // Admin classes
        if (is_admin()) {
            require_once WP_SMTP_API_PLUGIN_DIR . 'admin/class-admin-settings.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize components
        add_action('plugins_loaded', array($this, 'init'));

        // Admin initialization
        if (is_admin()) {
            add_action('plugins_loaded', array('WP_SMTP_API_Admin_Settings', 'instance'));
        }
    }

    /**
     * Initialize plugin components
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('wp-smtp-api', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize the API mailer if settings are configured
        $settings = WP_SMTP_API_Settings::instance();
        if ($settings->is_configured()) {
            WP_SMTP_API_Mailer::instance();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'api_endpoint' => '',
            'jwt_token' => '',
            'timeout' => 30,
            'ssl_verify' => true,
            'enable_logging' => false,
            'enabled' => false,
        );

        // Only set if not exists
        if (!get_option('wp_smtp_api_settings')) {
            add_option('wp_smtp_api_settings', $default_options);
        }

        // Create logs directory if logging is enabled
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/wp-smtp-api-logs';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
            // Protect logs directory
            file_put_contents($logs_dir . '/.htaccess', 'Deny from all');
            file_put_contents($logs_dir . '/index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up transients
        delete_transient('wp_smtp_api_last_error');
    }
}

// Initialize plugin
function wp_smtp_api() {
    return WP_SMTP_API::instance();
}

// Start the plugin
wp_smtp_api();
