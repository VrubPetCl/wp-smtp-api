<?php
/**
 * Input validation utilities
 * Prevents injection attacks and validates email data
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SMTP_API_Validator {

    /**
     * Validate email address
     * Prevents header injection attacks
     */
    public static function validate_email($email) {
        if (empty($email)) {
            return false;
        }

        // Remove whitespace
        $email = trim($email);

        // Check for header injection attempts
        if (self::contains_header_injection($email)) {
            return false;
        }

        // Use WordPress validation
        return is_email($email);
    }

    /**
     * Validate multiple email addresses
     */
    public static function validate_emails($emails) {
        if (!is_array($emails)) {
            return array();
        }

        $valid_emails = array();
        foreach ($emails as $email) {
            $validated = self::validate_email($email);
            if ($validated) {
                $valid_emails[] = $validated;
            }
        }

        return $valid_emails;
    }

    /**
     * Sanitize email subject
     * Prevents header injection
     */
    public static function sanitize_subject($subject) {
        if (empty($subject)) {
            return '';
        }

        // Remove any newlines or carriage returns (header injection prevention)
        $subject = str_replace(array("\r", "\n", "%0a", "%0d"), '', $subject);

        // Remove null bytes
        $subject = str_replace(chr(0), '', $subject);

        // Trim and return
        return trim($subject);
    }

    /**
     * Sanitize email content
     */
    public static function sanitize_content($content, $is_html = true) {
        if (empty($content)) {
            return '';
        }

        // Remove null bytes
        $content = str_replace(chr(0), '', $content);

        if ($is_html) {
            // For HTML content, use wp_kses_post to allow safe HTML
            return wp_kses_post($content);
        } else {
            // For plain text, just sanitize
            return sanitize_textarea_field($content);
        }
    }

    /**
     * Validate URL (for API endpoint)
     */
    public static function validate_url($url) {
        if (empty($url)) {
            return false;
        }

        // Must be HTTPS
        if (strpos($url, 'https://') !== 0) {
            return false;
        }

        // Validate URL format
        $validated = esc_url_raw($url, array('https'));

        return filter_var($validated, FILTER_VALIDATE_URL) ? $validated : false;
    }

    /**
     * Check for header injection attempts
     */
    private static function contains_header_injection($string) {
        $patterns = array(
            '/[\r\n]/',           // Line breaks
            '/%0[ad]/i',          // URL encoded line breaks
            '/content-type:/i',   // Content-Type header
            '/bcc:/i',            // BCC header
            '/cc:/i',             // CC header
            '/to:/i',             // To header
            '/from:/i',           // From header
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $string)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate email headers
     */
    public static function validate_headers($headers) {
        if (empty($headers)) {
            return array();
        }

        if (!is_array($headers)) {
            $headers = array($headers);
        }

        $valid_headers = array();
        $allowed_headers = array('Content-Type', 'Reply-To', 'X-Mailer', 'X-Priority');

        foreach ($headers as $header) {
            // Check for injection
            if (self::contains_header_injection($header)) {
                continue;
            }

            // Parse header
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $header_name = trim($parts[0]);
            $header_value = trim($parts[1]);

            // Only allow specific headers
            if (in_array($header_name, $allowed_headers)) {
                // Special validation for Reply-To
                if ($header_name === 'Reply-To') {
                    if (self::validate_email($header_value)) {
                        $valid_headers[] = $header;
                    }
                } else {
                    $valid_headers[] = $header_name . ': ' . $header_value;
                }
            }
        }

        return $valid_headers;
    }

    /**
     * Validate attachments data
     */
    public static function validate_attachments($attachments) {
        if (empty($attachments)) {
            return array();
        }

        if (!is_array($attachments)) {
            return array();
        }

        $valid_attachments = array();

        foreach ($attachments as $attachment) {
            // Must be a file path string
            if (!is_string($attachment)) {
                continue;
            }

            // Check if file exists and is readable
            if (file_exists($attachment) && is_readable($attachment)) {
                // Prevent directory traversal
                $real_path = realpath($attachment);
                if ($real_path && strpos($real_path, ABSPATH) === 0) {
                    $valid_attachments[] = $real_path;
                }
            }
        }

        return $valid_attachments;
    }

    /**
     * Sanitize from name
     */
    public static function sanitize_from_name($name) {
        if (empty($name)) {
            return '';
        }

        // Remove potential injection characters
        $name = str_replace(array("\r", "\n", "%0a", "%0d"), '', $name);
        $name = str_replace(chr(0), '', $name);

        return sanitize_text_field($name);
    }

    /**
     * Validate JWT token format
     */
    public static function validate_jwt_token($token) {
        if (empty($token)) {
            return false;
        }

        // JWT should have 3 parts separated by dots
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        // Each part should be base64 encoded
        foreach ($parts as $part) {
            if (empty($part) || !preg_match('/^[a-zA-Z0-9_-]+$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize settings input from admin form
     */
    public static function sanitize_settings($input) {
        $sanitized = array();

        // API Endpoint
        if (isset($input['api_endpoint'])) {
            $url = self::validate_url($input['api_endpoint']);
            $sanitized['api_endpoint'] = $url ? $url : '';
        }

        // JWT Token
        if (isset($input['jwt_token'])) {
            $sanitized['jwt_token'] = sanitize_text_field($input['jwt_token']);
        }

        // Timeout
        if (isset($input['timeout'])) {
            $sanitized['timeout'] = max(5, min(120, intval($input['timeout'])));
        }

        // Boolean settings
        $sanitized['ssl_verify'] = isset($input['ssl_verify']) ? true : false;
        $sanitized['enable_logging'] = isset($input['enable_logging']) ? true : false;
        $sanitized['enabled'] = isset($input['enabled']) ? true : false;

        return $sanitized;
    }
}
