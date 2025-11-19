<?php
/**
 * API Client class
 * Handles secure HTTP requests to the remote SMTP API
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SMTP_API_Client {

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = WP_SMTP_API_Settings::instance();
        $this->logger = WP_SMTP_API_Logger::instance();
    }

    /**
     * Send email via API
     *
     * @param array $email_data Email data (to, subject, content, etc.)
     * @return array Response with 'success' boolean and 'message'
     */
    public function send_email($email_data) {
        // Validate required fields
        if (empty($email_data['to']) || empty($email_data['subject'])) {
            $this->logger->error('Missing required email fields', array(
                'has_to' => !empty($email_data['to']),
                'has_subject' => !empty($email_data['subject']),
            ));

            return array(
                'success' => false,
                'message' => 'Missing required email fields (to, subject)',
            );
        }

        // Get API configuration
        $api_endpoint = $this->settings->get_api_endpoint();
        $jwt_token = $this->settings->get_jwt_token();

        if (empty($api_endpoint) || empty($jwt_token)) {
            $this->logger->error('API not configured', array(
                'has_endpoint' => !empty($api_endpoint),
                'has_token' => !empty($jwt_token),
            ));

            return array(
                'success' => false,
                'message' => 'API endpoint or JWT token not configured',
            );
        }

        // Prepare request payload
        $payload = $this->prepare_payload($email_data);

        // Log request (without sensitive data)
        $this->logger->info('Sending email via API', array(
            'to' => is_array($email_data['to']) ? implode(', ', $email_data['to']) : $email_data['to'],
            'subject' => $email_data['subject'],
            'endpoint' => $api_endpoint,
        ));

        // Make API request
        $response = $this->make_request($api_endpoint, $jwt_token, $payload);

        return $response;
    }

    /**
     * Prepare request payload
     */
    private function prepare_payload($email_data) {
        $payload = array(
            'to' => $this->normalize_recipients($email_data['to']),
            'subject' => $email_data['subject'],
            'content' => $email_data['content'],
            'timestamp' => time(),
        );

        // Optional fields
        if (!empty($email_data['from'])) {
            $payload['from'] = $email_data['from'];
        }

        if (!empty($email_data['from_name'])) {
            $payload['from_name'] = $email_data['from_name'];
        }

        if (!empty($email_data['headers'])) {
            $payload['headers'] = $email_data['headers'];
        }

        if (!empty($email_data['reply_to'])) {
            $payload['reply_to'] = $email_data['reply_to'];
        }

        // Add content type indicator
        $payload['content_type'] = !empty($email_data['is_html']) ? 'text/html' : 'text/plain';

        return $payload;
    }

    /**
     * Normalize recipients to array
     */
    private function normalize_recipients($recipients) {
        if (is_string($recipients)) {
            return array($recipients);
        }

        if (is_array($recipients)) {
            return $recipients;
        }

        return array();
    }

    /**
     * Make HTTP request to API
     */
    private function make_request($endpoint, $token, $payload) {
        // Encode payload
        $json_payload = wp_json_encode($payload);

        // Generate HMAC signature for payload integrity
        $signature = hash_hmac('sha256', $json_payload, $token);

        // Prepare request arguments
        $args = array(
            'method' => 'POST',
            'timeout' => $this->settings->get('timeout', 30),
            'sslverify' => $this->settings->get('ssl_verify', true),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'X-WP-SMTP-Signature' => $signature,
                'X-WP-SMTP-Timestamp' => (string) $payload['timestamp'],
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; WP-SMTP-API/' . WP_SMTP_API_VERSION,
            ),
            'body' => $json_payload,
        );

        // Make request using WordPress HTTP API
        $response = wp_remote_post($endpoint, $args);

        // Handle response
        return $this->handle_response($response);
    }

    /**
     * Handle API response
     */
    private function handle_response($response) {
        // Check for WP_Error
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();

            $this->logger->error('API request failed', array(
                'error' => $error_message,
            ));

            // Store last error
            $this->set_last_error(array(
                'message' => 'API request failed: ' . $error_message,
                'code' => $response->get_error_code(),
                'time' => time(),
            ));

            return array(
                'success' => false,
                'message' => 'API request failed: ' . $error_message,
                'error_code' => $response->get_error_code(),
            );
        }

        // Get response code and body
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Log response
        $this->logger->debug('API response received', array(
            'status_code' => $response_code,
            'body_length' => strlen($response_body),
        ));

        // Check if successful (2xx status codes)
        if ($response_code >= 200 && $response_code < 300) {
            $this->logger->info('Email sent successfully', array(
                'status_code' => $response_code,
            ));

            return array(
                'success' => true,
                'message' => 'Email sent successfully',
                'status_code' => $response_code,
                'response_body' => $response_body,
            );
        }

        // Handle error responses
        $error_message = $this->parse_error_message($response_body, $response_code);

        $this->logger->error('API returned error', array(
            'status_code' => $response_code,
            'error_message' => $error_message,
        ));

        // Store last error for admin display
        $this->set_last_error(array(
            'message' => $error_message,
            'code' => $response_code,
            'time' => time(),
        ));

        return array(
            'success' => false,
            'message' => $error_message,
            'status_code' => $response_code,
        );
    }

    /**
     * Parse error message from response
     */
    private function parse_error_message($body, $status_code) {
        // Try to decode JSON response (with depth limit for security)
        $decoded = json_decode($body, true, 32);

        // Sanitize and limit error message length
        if ($decoded && isset($decoded['message'])) {
            $message = sanitize_text_field($decoded['message']);
            $message = substr($message, 0, 500); // Limit to 500 chars
            return 'API Error (' . $status_code . '): ' . $message;
        }

        if ($decoded && isset($decoded['error'])) {
            $error = sanitize_text_field($decoded['error']);
            $error = substr($error, 0, 500); // Limit to 500 chars
            return 'API Error (' . $status_code . '): ' . $error;
        }

        // Generic error messages based on status code
        switch ($status_code) {
            case 400:
                return 'Bad Request: Invalid email data';
            case 401:
                return 'Unauthorized: Invalid JWT token';
            case 403:
                return 'Forbidden: Access denied';
            case 404:
                return 'Not Found: API endpoint not found';
            case 429:
                return 'Too Many Requests: Rate limit exceeded';
            case 500:
                return 'Internal Server Error: API server error';
            case 503:
                return 'Service Unavailable: API temporarily unavailable';
            default:
                return 'API Error: HTTP ' . $status_code;
        }
    }

    /**
     * Test API connection
     *
     * @param string $endpoint Optional endpoint to test (defaults to settings)
     * @param string $token Optional token to test (defaults to settings)
     * @return array Response with 'success' boolean and 'message'
     */
    public function test_connection($endpoint = null, $token = null) {
        $endpoint = $endpoint ?: $this->settings->get_api_endpoint();
        $token = $token ?: $this->settings->get_jwt_token();

        if (empty($endpoint) || empty($token)) {
            return array(
                'success' => false,
                'message' => 'API endpoint or JWT token not provided',
            );
        }

        // Validate endpoint is HTTPS
        if (strpos($endpoint, 'https://') !== 0) {
            return array(
                'success' => false,
                'message' => 'API endpoint must use HTTPS',
            );
        }

        $this->logger->info('Testing API connection', array(
            'endpoint' => $endpoint,
        ));

        // Send test email
        $test_data = array(
            'to' => get_option('admin_email'),
            'subject' => 'WP SMTP API - Test Email',
            'content' => 'This is a test email from WP SMTP API plugin. If you receive this, the connection is working properly.',
            'from' => get_option('admin_email'),
            'from_name' => get_bloginfo('name'),
            'is_html' => false,
        );

        return $this->send_email($test_data);
    }

    /**
     * Get last error from transient
     */
    public function get_last_error() {
        return get_transient('wp_smtp_api_last_error');
    }

    /**
     * Set last error in transient
     */
    private function set_last_error($error) {
        set_transient('wp_smtp_api_last_error', $error, HOUR_IN_SECONDS);
    }
}
