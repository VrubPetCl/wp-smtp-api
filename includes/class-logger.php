<?php
/**
 * Secure logging class
 * Logs API requests and responses without exposing sensitive data
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SMTP_API_Logger {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Log file path
     */
    private $log_file;

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
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-smtp-api-logs';

        // Ensure log directory exists
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Protect directory with .htaccess
            file_put_contents($log_dir . '/.htaccess', 'Deny from all');
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
            // Set secure permissions
            @chmod($log_dir, 0755);
        }

        // Add random suffix to log file name for additional security
        $random_suffix = get_option('wp_smtp_api_log_suffix');
        if (!$random_suffix) {
            $random_suffix = wp_generate_password(8, false);
            add_option('wp_smtp_api_log_suffix', $random_suffix, '', false);
        }

        $this->log_file = $log_dir . '/smtp-api-' . date('Y-m-d') . '-' . $random_suffix . '.log';
    }

    /**
     * Check if logging is enabled
     */
    private function is_enabled() {
        $settings = WP_SMTP_API_Settings::instance();
        return $settings->get('enable_logging', false);
    }

    /**
     * Log a message
     */
    public function log($level, $message, $context = array()) {
        if (!$this->is_enabled()) {
            return;
        }

        // Sanitize context to remove sensitive data
        $context = $this->sanitize_context($context);

        // Format log entry
        $timestamp = current_time('mysql');
        $log_entry = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );

        // Write to file with proper error handling
        $this->write_to_file($log_entry);
    }

    /**
     * Write to log file
     */
    private function write_to_file($entry) {
        // Use WordPress filesystem API
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Try direct write first (faster)
        $written = @file_put_contents($this->log_file, $entry, FILE_APPEND | LOCK_EX);

        if (false === $written) {
            // Fall back to error_log if file write fails
            error_log('WP SMTP API: ' . trim($entry));
        }
    }

    /**
     * Sanitize context to remove sensitive data
     */
    private function sanitize_context($context) {
        if (!is_array($context)) {
            return array();
        }

        $sensitive_keys = array('jwt_token', 'token', 'password', 'Authorization', 'auth');

        foreach ($context as $key => $value) {
            // Mask sensitive keys
            if (in_array($key, $sensitive_keys, true)) {
                $context[$key] = $this->mask_sensitive_value($value);
            }

            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $context[$key] = $this->sanitize_context($value);
            }
        }

        return $context;
    }

    /**
     * Mask sensitive value
     */
    private function mask_sensitive_value($value) {
        if (empty($value)) {
            return '[empty]';
        }

        if (is_string($value) && strlen($value) > 20) {
            return substr($value, 0, 8) . '...' . substr($value, -8);
        }

        return '[REDACTED]';
    }

    /**
     * Log info message
     */
    public function info($message, $context = array()) {
        $this->log('info', $message, $context);
    }

    /**
     * Log error message
     */
    public function error($message, $context = array()) {
        $this->log('error', $message, $context);
    }

    /**
     * Log warning message
     */
    public function warning($message, $context = array()) {
        $this->log('warning', $message, $context);
    }

    /**
     * Log debug message
     */
    public function debug($message, $context = array()) {
        $this->log('debug', $message, $context);
    }

    /**
     * Get log file path
     */
    public function get_log_file() {
        return $this->log_file;
    }

    /**
     * Get log files list
     */
    public function get_log_files() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-smtp-api-logs';

        if (!file_exists($log_dir)) {
            return array();
        }

        $files = glob($log_dir . '/smtp-api-*.log');
        if (!$files) {
            return array();
        }

        // Sort by date (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    /**
     * Read log file
     */
    public function read_log($file = null, $lines = 100) {
        if (!current_user_can('manage_options')) {
            return '';
        }

        $file = $file ?: $this->log_file;

        if (!file_exists($file)) {
            return '';
        }

        // Read last N lines
        $output = array();
        $handle = @fopen($file, 'r');

        if ($handle) {
            // For large files, read from end
            fseek($handle, -1, SEEK_END);
            $lines_read = 0;

            // Read backwards
            for ($pos = ftell($handle); $pos > 0 && $lines_read < $lines; $pos--) {
                fseek($handle, $pos, SEEK_SET);
                $char = fgetc($handle);

                if ($char === "\n" && $pos !== ftell($handle) - 1) {
                    $lines_read++;
                }
            }

            // Read remaining content
            $content = '';
            while (!feof($handle)) {
                $content .= fgets($handle);
            }

            fclose($handle);
            return $content;
        }

        return '';
    }

    /**
     * Clear old logs (older than 30 days)
     */
    public function clear_old_logs() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-smtp-api-logs';

        if (!file_exists($log_dir)) {
            return false;
        }

        $files = glob($log_dir . '/smtp-api-*.log');
        $cutoff_time = strtotime('-30 days');
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete all logs
     */
    public function delete_all_logs() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $files = $this->get_log_files();
        $deleted = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
