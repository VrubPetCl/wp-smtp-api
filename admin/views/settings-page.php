<?php
/**
 * Admin Settings Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = WP_SMTP_API_Settings::instance();
$is_configured = $settings->is_configured();
?>

<div class="wrap wp-smtp-api-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('wp_smtp_api_messages'); ?>

    <div class="wp-smtp-api-container">
        <!-- Main Settings Form -->
        <div class="wp-smtp-api-main">
            <form action="options.php" method="post">
                <?php
                settings_fields('wp_smtp_api_settings_group');
                do_settings_sections($this->page_slug);
                submit_button(__('Save Settings', 'wp-smtp-api'));
                ?>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="wp-smtp-api-sidebar">
            <!-- Status Card -->
            <div class="wp-smtp-api-card">
                <h2><?php esc_html_e('Status', 'wp-smtp-api'); ?></h2>
                <div class="wp-smtp-api-status">
                    <?php if ($is_configured): ?>
                        <p class="status-item status-enabled">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e('Plugin Configured', 'wp-smtp-api'); ?>
                        </p>
                    <?php else: ?>
                        <p class="status-item status-disabled">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e('Plugin Not Configured', 'wp-smtp-api'); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($settings->get('enabled')): ?>
                        <p class="status-item status-enabled">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e('Plugin Enabled', 'wp-smtp-api'); ?>
                        </p>
                    <?php else: ?>
                        <p class="status-item status-disabled">
                            <span class="dashicons dashicons-minus"></span>
                            <?php esc_html_e('Plugin Disabled', 'wp-smtp-api'); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($settings->is_using_constant_endpoint()): ?>
                        <p class="status-item status-info">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php esc_html_e('Using wp-config endpoint', 'wp-smtp-api'); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($settings->is_using_constant_token()): ?>
                        <p class="status-item status-info">
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e('Using wp-config token', 'wp-smtp-api'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Test Email Card -->
            <div class="wp-smtp-api-card">
                <h2><?php esc_html_e('Test Email', 'wp-smtp-api'); ?></h2>
                <p><?php esc_html_e('Send a test email to verify your configuration.', 'wp-smtp-api'); ?></p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <?php wp_nonce_field('wp_smtp_api_test_email'); ?>
                    <input type="hidden" name="action" value="wp_smtp_api_test_email">
                    <p>
                        <strong><?php esc_html_e('Recipient:', 'wp-smtp-api'); ?></strong><br>
                        <?php echo esc_html(get_option('admin_email')); ?>
                    </p>
                    <button type="submit" class="button button-secondary button-large" <?php disabled(!$is_configured); ?>>
                        <span class="dashicons dashicons-email-alt"></span>
                        <?php esc_html_e('Send Test Email', 'wp-smtp-api'); ?>
                    </button>
                </form>
            </div>

            <!-- Logging Card -->
            <?php if ($settings->get('enable_logging')): ?>
            <div class="wp-smtp-api-card">
                <h2><?php esc_html_e('Debug Logs', 'wp-smtp-api'); ?></h2>
                <p><?php esc_html_e('View or clear debug logs.', 'wp-smtp-api'); ?></p>
                <div class="button-group">
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;">
                        <?php wp_nonce_field('wp_smtp_api_view_logs'); ?>
                        <input type="hidden" name="action" value="wp_smtp_api_view_logs">
                        <button type="submit" class="button button-secondary">
                            <span class="dashicons dashicons-text-page"></span>
                            <?php esc_html_e('View Logs', 'wp-smtp-api'); ?>
                        </button>
                    </form>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;">
                        <?php wp_nonce_field('wp_smtp_api_clear_logs'); ?>
                        <input type="hidden" name="action" value="wp_smtp_api_clear_logs">
                        <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete all logs?', 'wp-smtp-api'); ?>');">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear Logs', 'wp-smtp-api'); ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Security Info Card -->
            <div class="wp-smtp-api-card">
                <h2><?php esc_html_e('Security Best Practices', 'wp-smtp-api'); ?></h2>
                <ul class="security-tips">
                    <li>
                        <span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e('Define JWT token in wp-config.php for maximum security', 'wp-smtp-api'); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e('Always use HTTPS endpoints', 'wp-smtp-api'); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-lock"></span>
                        <?php esc_html_e('Keep SSL verification enabled', 'wp-smtp-api'); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e('Enable logging only for debugging', 'wp-smtp-api'); ?>
                    </li>
                </ul>
            </div>

            <!-- Documentation Card -->
            <div class="wp-smtp-api-card">
                <h2><?php esc_html_e('Documentation', 'wp-smtp-api'); ?></h2>
                <h3><?php esc_html_e('wp-config.php Constants', 'wp-smtp-api'); ?></h3>
                <pre><code>// API Endpoint
define('WP_SMTP_API_ENDPOINT', 'https://api.example.com/send');

// JWT Token (most secure)
define('WP_SMTP_API_JWT_TOKEN', 'your-token-here');

// Encryption Key (optional)
define('WP_SMTP_API_ENCRYPTION_KEY', 'your-key-here');</code></pre>

                <h3><?php esc_html_e('API Request Format', 'wp-smtp-api'); ?></h3>
                <pre><code>{
  "to": ["recipient@example.com"],
  "subject": "Email Subject",
  "content": "Email body",
  "from": "sender@example.com",
  "from_name": "Sender Name",
  "timestamp": 1234567890,
  "content_type": "text/html"
}</code></pre>

                <h3><?php esc_html_e('API Response', 'wp-smtp-api'); ?></h3>
                <p><?php esc_html_e('The API should return:', 'wp-smtp-api'); ?></p>
                <ul>
                    <li><?php esc_html_e('2XX status code for success', 'wp-smtp-api'); ?></li>
                    <li><?php esc_html_e('4XX/5XX status code for errors', 'wp-smtp-api'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>
