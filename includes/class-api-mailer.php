<?php
/**
 * API Mailer class
 * Hooks into WordPress mail system and redirects emails to API
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SMTP_API_Mailer {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * API Client
     */
    private $api_client;

    /**
     * Logger
     */
    private $logger;

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
        $this->api_client = new WP_SMTP_API_Client();
        $this->logger = WP_SMTP_API_Logger::instance();

        // Hook into WordPress mail system
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Use phpmailer_init to intercept emails
        add_action('phpmailer_init', array($this, 'phpmailer_init'), 999);

        // Alternative: pre_wp_mail filter (fires before wp_mail sends)
        add_filter('pre_wp_mail', array($this, 'pre_wp_mail'), 10, 2);
    }

    /**
     * Intercept wp_mail before it's sent
     * Return non-null to bypass wp_mail
     */
    public function pre_wp_mail($null, $atts) {
        // Extract email data
        $to = $atts['to'];
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = isset($atts['headers']) ? $atts['headers'] : array();
        $attachments = isset($atts['attachments']) ? $atts['attachments'] : array();

        // Validate and sanitize email data
        $email_data = $this->prepare_email_data($to, $subject, $message, $headers, $attachments);

        if (!$email_data) {
            $this->logger->error('Email validation failed');
            return false; // Let wp_mail handle it normally
        }

        // Send via API
        $result = $this->api_client->send_email($email_data);

        if ($result['success']) {
            // Return true to indicate email was sent and bypass wp_mail
            return true;
        } else {
            // Log error and let wp_mail handle as fallback
            $this->logger->warning('API send failed, falling back to wp_mail', array(
                'error' => $result['message'],
            ));
            return null; // null means continue with normal wp_mail
        }
    }

    /**
     * Configure PHPMailer (alternative hook)
     * This is a backup in case pre_wp_mail doesn't work
     */
    public function phpmailer_init($phpmailer) {
        // We're already handling this in pre_wp_mail
        // This hook is here as a backup/alternative approach
        return $phpmailer;
    }

    /**
     * Prepare and validate email data
     */
    private function prepare_email_data($to, $subject, $message, $headers, $attachments) {
        // Validate recipients
        $to = WP_SMTP_API_Validator::validate_emails((array) $to);
        if (empty($to)) {
            $this->logger->error('No valid recipients');
            return false;
        }

        // Sanitize subject
        $subject = WP_SMTP_API_Validator::sanitize_subject($subject);
        if (empty($subject)) {
            $this->logger->error('Invalid subject');
            return false;
        }

        // Parse headers to determine content type and extract metadata
        $parsed_headers = $this->parse_headers($headers);

        // Determine if content is HTML
        $is_html = $parsed_headers['content_type'] === 'text/html';

        // Sanitize content
        $message = WP_SMTP_API_Validator::sanitize_content($message, $is_html);

        // Prepare email data
        $email_data = array(
            'to' => $to,
            'subject' => $subject,
            'content' => $message,
            'is_html' => $is_html,
        );

        // Add from address if specified
        if (!empty($parsed_headers['from'])) {
            $email_data['from'] = $parsed_headers['from'];
        } else {
            // Use WordPress default
            $email_data['from'] = get_option('admin_email');
        }

        // Add from name if specified
        if (!empty($parsed_headers['from_name'])) {
            $email_data['from_name'] = $parsed_headers['from_name'];
        } else {
            // Use WordPress default
            $email_data['from_name'] = get_bloginfo('name');
        }

        // Add reply-to if specified
        if (!empty($parsed_headers['reply_to'])) {
            $email_data['reply_to'] = $parsed_headers['reply_to'];
        }

        // Add CC if specified
        if (!empty($parsed_headers['cc'])) {
            $email_data['cc'] = $parsed_headers['cc'];
        }

        // Add BCC if specified
        if (!empty($parsed_headers['bcc'])) {
            $email_data['bcc'] = $parsed_headers['bcc'];
        }

        // Validate headers and add to email data
        $validated_headers = WP_SMTP_API_Validator::validate_headers($parsed_headers['other_headers']);
        if (!empty($validated_headers)) {
            $email_data['headers'] = $validated_headers;
        }

        // Handle attachments (if API supports them)
        if (!empty($attachments)) {
            $validated_attachments = WP_SMTP_API_Validator::validate_attachments($attachments);
            if (!empty($validated_attachments)) {
                $email_data['attachments'] = $validated_attachments;
                $this->logger->warning('Attachments detected but may not be supported by API', array(
                    'count' => count($validated_attachments),
                ));
            }
        }

        return $email_data;
    }

    /**
     * Parse email headers
     */
    private function parse_headers($headers) {
        $parsed = array(
            'content_type' => 'text/plain',
            'from' => '',
            'from_name' => '',
            'reply_to' => '',
            'cc' => array(),
            'bcc' => array(),
            'other_headers' => array(),
        );

        // Normalize headers to array
        if (!is_array($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        foreach ($headers as $header) {
            if (strpos($header, ':') === false) {
                continue;
            }

            list($name, $value) = explode(':', $header, 2);
            $name = trim($name);
            $value = trim($value);

            switch (strtolower($name)) {
                case 'content-type':
                    if (strpos($value, 'text/html') !== false) {
                        $parsed['content_type'] = 'text/html';
                    }
                    break;

                case 'from':
                    // Parse "Name <email@example.com>" format
                    if (preg_match('/^(.+?)\s*<(.+?)>$/', $value, $matches)) {
                        $parsed['from_name'] = trim($matches[1], '" ');
                        $parsed['from'] = trim($matches[2]);
                    } else {
                        $parsed['from'] = $value;
                    }
                    break;

                case 'reply-to':
                    $parsed['reply_to'] = $value;
                    break;

                case 'cc':
                    $parsed['cc'] = array_map('trim', explode(',', $value));
                    break;

                case 'bcc':
                    $parsed['bcc'] = array_map('trim', explode(',', $value));
                    break;

                default:
                    $parsed['other_headers'][] = $name . ': ' . $value;
                    break;
            }
        }

        return $parsed;
    }

    /**
     * Get mailer status
     */
    public function get_status() {
        $settings = WP_SMTP_API_Settings::instance();

        return array(
            'enabled' => $settings->get('enabled', false),
            'configured' => $settings->is_configured(),
            'endpoint' => $settings->get_api_endpoint(),
            'last_error' => $this->api_client->get_last_error(),
        );
    }
}
