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
            // Generate a cryptographically secure random encryption key (32 bytes for AES-256)
            $key = $this->generate_encryption_key();
            add_option($this->key_option_name, $key, '', false); // Not autoloaded for security
        }
    }

    /**
     * Generate a cryptographically secure encryption key
     */
    private function generate_encryption_key() {
        $crypto_strong = false;
        $random_bytes = openssl_random_pseudo_bytes(32, $crypto_strong);

        if (!$crypto_strong) {
            // Fallback to wp_generate_password if openssl fails
            $random_bytes = hash('sha256', wp_generate_password(64, true, true) . wp_salt(), true);
        }

        // Return base64 encoded for storage
        return base64_encode($random_bytes);
    }

    /**
     * Get encryption key
     */
    private function get_encryption_key() {
        // Check if key is defined in wp-config.php (most secure)
        if (defined('WP_SMTP_API_ENCRYPTION_KEY')) {
            return $this->derive_key(WP_SMTP_API_ENCRYPTION_KEY);
        }

        // Fall back to database key
        $stored_key = get_option($this->key_option_name);
        if ($stored_key) {
            return base64_decode($stored_key);
        }

        // This should never happen, but fallback
        return $this->derive_key(wp_salt());
    }

    /**
     * Derive a proper 32-byte key for AES-256
     */
    private function derive_key($input) {
        // Use PBKDF2 to derive a proper 32-byte key
        return hash_pbkdf2('sha256', $input, wp_salt(), 10000, 32, true);
    }

    /**
     * Encrypt sensitive data
     */
    private function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = $this->get_encryption_key();

        // Generate IV with crypto strength check
        $crypto_strong = false;
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'), $crypto_strong);

        if (!$crypto_strong) {
            // Log warning if IV generation is not cryptographically strong
            error_log('WP SMTP API: Warning - IV generation not cryptographically strong');
        }

        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            error_log('WP SMTP API: Encryption failed');
            return '';
        }

        // Combine IV and encrypted data with HMAC for integrity
        $hmac = hash_hmac('sha256', $encrypted, $key, true);
        return base64_encode($iv . $hmac . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    private function decrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = $this->get_encryption_key();
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            return '';
        }

        // New format with HMAC (IV + HMAC + encrypted data)
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $hmac_length = 32; // SHA-256 produces 32 bytes

        if (strlen($decoded) > $iv_length + $hmac_length) {
            $iv = substr($decoded, 0, $iv_length);
            $hmac = substr($decoded, $iv_length, $hmac_length);
            $encrypted = substr($decoded, $iv_length + $hmac_length);

            // Verify HMAC for integrity
            $calculated_hmac = hash_hmac('sha256', $encrypted, $key, true);
            if (hash_equals($calculated_hmac, $hmac)) {
                $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
        }

        // Legacy format support (old ::  delimiter format)
        $parts = explode('::', $decoded, 2);
        if (count($parts) === 2) {
            list($iv, $encrypted) = $parts;
            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
            if ($decrypted !== false) {
                return $decrypted;
            }
        }

        // If all decryption attempts fail, return empty string
        error_log('WP SMTP API: Decryption failed for stored data');
        return '';
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
