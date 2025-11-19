<?php
/**
 * Uninstall script for WP SMTP API
 * Runs when the plugin is uninstalled (deleted) from WordPress
 */

// Exit if accessed directly or not in uninstall context
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wp_smtp_api_settings');
delete_option('wp_smtp_api_encryption_key');
delete_option('wp_smtp_api_log_suffix');

// Delete transients
delete_transient('wp_smtp_api_last_error');

// Delete all rate limit transients (this is a best-effort cleanup)
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wp_smtp_api_test_rate_%'
     OR option_name LIKE '_transient_timeout_wp_smtp_api_test_rate_%'"
);

// Optionally delete logs directory
// Uncomment the following lines if you want to delete logs on uninstall
/*
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/wp-smtp-api-logs';

if (file_exists($log_dir)) {
    // Delete all log files
    $files = glob($log_dir . '/*');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // Remove directory
    @rmdir($log_dir);
}
*/

// Note: We intentionally don't delete logs by default for data retention compliance
// Users can manually delete the logs directory if needed
