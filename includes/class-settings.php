<?php
/**
 * Settings management class
 * Handles plugin settings with secure encryption for sensitive data
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SMTP_API_Settings {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Settings option name
     */
    private $option_name = 'wp_smtp_api_settings';

    /**
     * Encryption key option name
     */
    private $key_option_name = 'wp_smtp_api_encryption_key';

    /**
     * Cached settings
     */
    private $settings = null;

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
        $this->ensure_encryption_key();
    }

    /**
     * Ensure encryption key exists
     */
    private function ensure_encryption_key() {
        if (!get_option($this->key_option_name)) {
            // Generate a random encryption key
            $key = wp_generate_password(64, true, true);
            add_option($this->key_option_name, $key, '', false); // Not autoloaded for security
        }
    }

    /**
     * Get encryption key
     */
    private function get_encryption_key() {
        // Check if key is defined in wp-config.php (most secure)
        if (defined('WP_SMTP_API_ENCRYPTION_KEY')) {
            return WP_SMTP_API_ENCRYPTION_KEY;
        }

        // Fall back to database key
        return get_option($this->key_option_name);
    }

    /**
     * Encrypt sensitive data
     */
    private function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);

        // Combine IV and encrypted data
        return base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    private function decrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = $this->get_encryption_key();
        $decoded = base64_decode($data);

        if ($decoded === false) {
            return '';
        }

        $parts = explode('::', $decoded, 2);
        if (count($parts) !== 2) {
            return $data; // Return as-is if not encrypted (backward compatibility)
        }

        list($iv, $encrypted) = $parts;
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Get all settings
     */
    public function get_settings() {
        if (null !== $this->settings) {
            return $this->settings;
        }

        $settings = get_option($this->option_name, array());

        // Set defaults
        $defaults = array(
            'api_endpoint' => '',
            'jwt_token' => '',
            'timeout' => 30,
            'ssl_verify' => true,
            'enable_logging' => false,
            'enabled' => false,
        );

        $settings = wp_parse_args($settings, $defaults);

        // Decrypt JWT token if stored in database
        if (!empty($settings['jwt_token']) && !$this->is_using_constant_token()) {
            $settings['jwt_token'] = $this->decrypt($settings['jwt_token']);
        }

        $this->settings = $settings;
        return $settings;
    }

    /**
     * Get a specific setting
     */
    public function get($key, $default = null) {
        $settings = $this->get_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Update settings
     */
    public function update($settings) {
        // Clear cache
        $this->settings = null;

        // Validate settings
        $settings = $this->validate_settings($settings);

        // Encrypt JWT token before saving (if not using constant)
        if (!empty($settings['jwt_token']) && !$this->is_using_constant_token()) {
            $settings['jwt_token'] = $this->encrypt($settings['jwt_token']);
        } else if ($this->is_using_constant_token()) {
            // Don't save token if using constant
            unset($settings['jwt_token']);
        }

        return update_option($this->option_name, $settings);
    }

    /**
     * Validate settings
     */
    private function validate_settings($settings) {
        $validated = array();

        // API Endpoint
        if (isset($settings['api_endpoint'])) {
            $validated['api_endpoint'] = esc_url_raw($settings['api_endpoint']);
        }

        // JWT Token (validate but don't sanitize - it's binary data)
        if (isset($settings['jwt_token'])) {
            $validated['jwt_token'] = $settings['jwt_token'];
        }

        // Timeout (integer, min 5, max 120)
        $validated['timeout'] = isset($settings['timeout'])
            ? max(5, min(120, intval($settings['timeout'])))
            : 30;

        // SSL Verify (boolean)
        $validated['ssl_verify'] = isset($settings['ssl_verify'])
            ? (bool) $settings['ssl_verify']
            : true;

        // Enable Logging (boolean)
        $validated['enable_logging'] = isset($settings['enable_logging'])
            ? (bool) $settings['enable_logging']
            : false;

        // Enabled (boolean)
        $validated['enabled'] = isset($settings['enabled'])
            ? (bool) $settings['enabled']
            : false;

        return $validated;
    }

    /**
     * Check if JWT token is defined as constant
     */
    public function is_using_constant_token() {
        return defined('WP_SMTP_API_JWT_TOKEN');
    }

    /**
     * Get JWT token (from constant or database)
     */
    public function get_jwt_token() {
        // Prefer constant (most secure)
        if ($this->is_using_constant_token()) {
            return WP_SMTP_API_JWT_TOKEN;
        }

        // Fall back to database
        return $this->get('jwt_token', '');
    }

    /**
     * Check if API endpoint is defined as constant
     */
    public function is_using_constant_endpoint() {
        return defined('WP_SMTP_API_ENDPOINT');
    }

    /**
     * Get API endpoint (from constant or database)
     */
    public function get_api_endpoint() {
        // Prefer constant
        if ($this->is_using_constant_endpoint()) {
            return WP_SMTP_API_ENDPOINT;
        }

        // Fall back to database
        return $this->get('api_endpoint', '');
    }

    /**
     * Check if plugin is properly configured
     */
    public function is_configured() {
        $endpoint = $this->get_api_endpoint();
        $token = $this->get_jwt_token();
        $enabled = $this->get('enabled', false);

        return !empty($endpoint) && !empty($token) && $enabled;
    }

    /**
     * Get sanitized settings for display (masks sensitive data)
     */
    public function get_display_settings() {
        $settings = $this->get_settings();

        // Mask JWT token for display
        if (!empty($settings['jwt_token'])) {
            $token = $settings['jwt_token'];
            $settings['jwt_token_masked'] = substr($token, 0, 10) . '...' . substr($token, -10);
        }

        return $settings;
    }
}
