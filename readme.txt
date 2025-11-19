=== WP SMTP API ===
Contributors: yourname
Tags: smtp, email, api, jwt, security
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure SMTP replacement that sends emails via JWT-authenticated API over HTTPS.

== Description ==

WP SMTP API is a secure WordPress plugin that replaces the default SMTP functionality by sending emails via a JWT-authenticated REST API over HTTPS. Perfect for modern cloud-based email services and microservices architectures.

= Key Features =

* **Secure API Communication** - All emails sent over HTTPS with JWT token authentication
* **Encrypted Storage** - JWT tokens stored encrypted in database or via wp-config.php constants
* **Input Validation** - Comprehensive validation to prevent injection attacks
* **Security Hardening** - Protection against header injection, XSS, and OWASP top 10 vulnerabilities
* **SSL Verification** - Built-in SSL certificate validation (configurable)
* **Debug Logging** - Optional secure logging with automatic cleanup
* **Test Email** - Built-in test functionality to verify configuration
* **WordPress Standards** - Follows WordPress coding standards and best practices

= Security Features =

* JWT tokens encrypted using AES-256-CBC
* Sensitive data masked in logs
* Secure log storage with .htaccess protection
* Support for wp-config.php constants (most secure)
* Email address validation (prevents injection)
* Subject line sanitization (prevents header injection)
* Content sanitization (HTML and plain text)
* HTTPS-only endpoints
* Request timestamps to prevent replay attacks

= Use Cases =

* Send emails through custom API endpoints
* Integrate with cloud email services (SendGrid, Mailgun, etc.)
* Centralized email management across multiple WordPress sites
* Enhanced email delivery tracking and analytics
* Compliance with corporate email policies

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-smtp-api/` directory, or install through WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to Settings → WP SMTP API to configure the plugin
4. Enter your API endpoint URL (must be HTTPS)
5. Enter your JWT token
6. Enable the plugin and send a test email

= Configuration via wp-config.php (Most Secure) =

Add these constants to your `wp-config.php` file:

`
define('WP_SMTP_API_ENDPOINT', 'https://api.example.com/send-email');
define('WP_SMTP_API_JWT_TOKEN', 'your-long-lived-jwt-token-here');
`

== Frequently Asked Questions ==

= What API endpoint format is required? =

Your API endpoint must:
- Accept HTTPS POST requests (HTTP not supported)
- Verify JWT token from `Authorization: Bearer {token}` header
- Return 2XX status codes for success, 4XX/5XX for errors
- Accept JSON payload with email data

See the full API specification in the README.md file.

= Is my JWT token secure? =

Yes! The plugin stores JWT tokens encrypted using AES-256-CBC encryption. For maximum security, you can define the token in `wp-config.php` instead of the database.

= What happens if the API fails? =

The plugin logs the error and can fall back to the standard WordPress `wp_mail()` function, ensuring emails are still delivered.

= Can I test my configuration? =

Yes! The settings page includes a "Send Test Email" button that sends a test message to your admin email address.

= Does this work with all WordPress plugins? =

Yes! Any plugin or theme that uses the standard WordPress `wp_mail()` function will automatically use this plugin.

= How do I view debug logs? =

Enable logging in the plugin settings, then click the "View Logs" button. Logs are stored securely and automatically cleaned up after 30 days.

= What if I get SSL certificate errors? =

Ensure your API uses a valid SSL certificate. For development environments with self-signed certificates, you can temporarily disable SSL verification in the advanced settings (not recommended for production).

== Screenshots ==

1. Main settings page with API configuration
2. Status dashboard showing plugin health
3. Test email functionality
4. Debug logs viewer
5. Security best practices guide

== Changelog ==

= 1.0.0 =
* Initial release
* JWT authentication support
* Encrypted token storage
* Comprehensive input validation
* Secure logging system
* Admin settings panel
* Test email functionality
* wp-config.php constants support

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP SMTP API.

== API Requirements ==

Your receiving API endpoint must accept POST requests with the following JSON structure:

`
{
  "to": ["recipient@example.com"],
  "subject": "Email Subject",
  "content": "Email body content",
  "from": "sender@example.com",
  "from_name": "Sender Name",
  "timestamp": 1234567890,
  "content_type": "text/html"
}
`

And return appropriate HTTP status codes:
- 2XX (200-299) for success
- 4XX (400-499) for client errors
- 5XX (500-599) for server errors

== Security Best Practices ==

1. Use wp-config.php for tokens (most secure)
2. Enable SSL verification in production
3. Use HTTPS-only endpoints
4. Rotate JWT tokens regularly
5. Enable logging only for debugging
6. Monitor failed authentication attempts
7. Restrict admin access to authorized users

== Support ==

For bug reports and feature requests, please visit the GitHub repository or WordPress support forums.
