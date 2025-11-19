# WP SMTP API

A secure WordPress plugin that replaces the default SMTP functionality by sending emails via a JWT-authenticated API over HTTPS.

## Features

- **Secure API Communication**: All emails sent over HTTPS with JWT token authentication
- **Encrypted Storage**: JWT tokens stored encrypted in database or via wp-config.php constants
- **Input Validation**: Comprehensive validation to prevent injection attacks
- **Security Hardening**: Protection against header injection, XSS, and other OWASP top 10 vulnerabilities
- **SSL Verification**: Built-in SSL certificate validation (configurable)
- **Debug Logging**: Optional secure logging with automatic cleanup
- **Test Email**: Built-in test functionality to verify configuration
- **Graceful Fallback**: Falls back to standard wp_mail if API fails
- **WordPress Standards**: Follows WordPress coding standards and best practices

## Security Features

### 🔒 Data Protection
- JWT tokens encrypted using AES-256-CBC
- Sensitive data masked in logs
- Secure log storage with .htaccess protection
- Support for wp-config.php constants (most secure)

### 🛡️ Input Validation
- Email address validation (prevents injection)
- Subject line sanitization (prevents header injection)
- Content sanitization (HTML and plain text)
- URL validation (HTTPS only)
- JWT token format validation

### 🔐 Authentication
- Long-lived JWT token support
- Bearer token authentication
- Request timestamps to prevent replay attacks

### ✅ Security Best Practices
- HTTPS-only endpoints
- SSL certificate verification
- No sensitive data in error messages
- Nonce verification for admin actions
- Capability checks (manage_options)
- Escaped output to prevent XSS

## Installation

1. **Upload Plugin**
   ```bash
   cd wp-content/plugins/
   git clone <repository-url> wp-smtp-api
   ```

2. **Activate Plugin**
   - Go to WordPress Admin → Plugins
   - Activate "WP SMTP API"

3. **Configure Settings**
   - Go to Settings → WP SMTP API
   - Enter your API endpoint URL (must be HTTPS)
   - Enter your JWT token
   - Enable the plugin

## Configuration

### Method 1: Admin Panel (Standard)

1. Navigate to **Settings → WP SMTP API**
2. Configure the following:
   - **API Endpoint URL**: Your HTTPS API endpoint (e.g., `https://api.example.com/send-email`)
   - **JWT Token**: Your long-lived JWT authentication token
   - **Request Timeout**: Maximum wait time for API response (5-120 seconds, default: 30)
   - **SSL Verification**: Enable SSL certificate verification (recommended)
   - **Enable Logging**: Turn on debug logging (for troubleshooting only)
   - **Enable Plugin**: Activate email routing through API

### Method 2: wp-config.php (Most Secure)

Add these constants to your `wp-config.php` file:

```php
// API Endpoint (required)
define('WP_SMTP_API_ENDPOINT', 'https://api.example.com/send-email');

// JWT Token (required) - Most secure method
define('WP_SMTP_API_JWT_TOKEN', 'your-long-lived-jwt-token-here');

// Optional: Custom encryption key for database-stored tokens
define('WP_SMTP_API_ENCRYPTION_KEY', 'your-custom-encryption-key-here');
```

**Benefits of wp-config.php method:**
- Tokens not stored in database
- Protected from database exports/backups
- Version control exclusion (via .gitignore)
- Maximum security

## API Requirements

### Endpoint Specifications

Your receiving API endpoint must:

1. **Accept HTTPS POST requests** (HTTP not supported)
2. **Verify JWT token** from `Authorization: Bearer {token}` header
3. **Return appropriate HTTP status codes**:
   - `2XX` (200-299) for successful email processing
   - `4XX` (400-499) for client errors (bad request, auth failure, etc.)
   - `5XX` (500-599) for server errors

### Request Format

The plugin sends JSON POST requests with the following structure:

```json
{
  "to": ["recipient@example.com"],
  "subject": "Email Subject",
  "content": "Email body content (HTML or plain text)",
  "from": "sender@example.com",
  "from_name": "Sender Name",
  "timestamp": 1234567890,
  "content_type": "text/html",
  "headers": ["Reply-To: reply@example.com"],
  "reply_to": "reply@example.com"
}
```

#### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `to` | array | Yes | Array of recipient email addresses |
| `subject` | string | Yes | Email subject line (sanitized) |
| `content` | string | Yes | Email body content |
| `from` | string | No | Sender email address |
| `from_name` | string | No | Sender display name |
| `timestamp` | integer | Yes | Unix timestamp (for replay attack prevention) |
| `content_type` | string | Yes | Either "text/html" or "text/plain" |
| `headers` | array | No | Additional email headers (validated) |
| `reply_to` | string | No | Reply-To email address |
| `cc` | array | No | CC recipients |
| `bcc` | array | No | BCC recipients |

### Response Format

**Success Response (2XX):**
```json
{
  "success": true,
  "message": "Email sent successfully"
}
```

**Error Response (4XX/5XX):**
```json
{
  "success": false,
  "error": "Error description",
  "message": "User-friendly error message"
}
```

## Usage

Once configured and enabled, the plugin automatically intercepts all WordPress emails sent via `wp_mail()` function and routes them through your API.

### Testing Configuration

1. Navigate to **Settings → WP SMTP API**
2. Click **Send Test Email** button
3. Check your admin email inbox for the test message
4. Review logs (if enabled) for detailed request/response information

### Programmatic Usage

The plugin works automatically with any WordPress function that uses `wp_mail()`:

```php
// Standard WordPress email
wp_mail(
    'recipient@example.com',
    'Test Subject',
    'Email content',
    ['Content-Type: text/html']
);

// This will automatically be sent via your API
```

## Logging

### Enable Debug Logging

1. Go to **Settings → WP SMTP API**
2. Check "Enable debug logging"
3. Save settings

### View Logs

- Click **View Logs** button in admin panel
- Logs stored in: `wp-content/uploads/wp-smtp-api-logs/`
- Automatic rotation: New log file daily
- Auto-cleanup: Logs older than 30 days deleted automatically

### Log Security

- Logs directory protected with `.htaccess`
- Sensitive data (tokens, passwords) automatically masked
- Admin-only access (`manage_options` capability required)

## Error Handling

The plugin includes robust error handling:

1. **Validation Errors**: Invalid email data is logged and rejected
2. **API Errors**: Failed API requests fall back to standard `wp_mail()` (optional)
3. **Network Errors**: Timeout and connection errors logged with details
4. **Authentication Errors**: 401/403 responses logged for troubleshooting

### Common Error Codes

| Code | Meaning | Solution |
|------|---------|----------|
| 400 | Bad Request | Check email data format |
| 401 | Unauthorized | Verify JWT token is correct |
| 403 | Forbidden | Check API permissions |
| 404 | Not Found | Verify API endpoint URL |
| 429 | Rate Limited | Reduce email frequency |
| 500 | Server Error | Check API server logs |
| 503 | Unavailable | API temporarily down, retry later |

## Security Recommendations

1. **Use wp-config.php for tokens**: Most secure storage method
2. **Enable SSL verification**: Always verify certificates in production
3. **Use HTTPS only**: Never use HTTP endpoints
4. **Rotate tokens regularly**: Update JWT tokens periodically
5. **Limit logging**: Enable only for debugging, disable in production
6. **Monitor failed requests**: Check logs for authentication failures
7. **Use strong encryption key**: Define custom `WP_SMTP_API_ENCRYPTION_KEY`
8. **Restrict admin access**: Limit who has `manage_options` capability

## File Structure

```
wp-smtp-api/
├── wp-smtp-api.php                 # Main plugin file
├── README.md                       # This file
├── includes/
│   ├── class-settings.php          # Settings management with encryption
│   ├── class-validator.php         # Input validation utilities
│   ├── class-logger.php            # Secure logging system
│   ├── class-api-client.php        # HTTP client for API requests
│   └── class-api-mailer.php        # Core mailer (wp_mail hook)
├── admin/
│   ├── class-admin-settings.php    # Admin UI controller
│   └── views/
│       └── settings-page.php       # Settings page template
└── assets/
    └── css/
        └── admin.css               # Admin panel styles
```

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **HTTPS**: API endpoint must use HTTPS
- **PHP Extensions**: openssl (for encryption)

## Filters & Hooks

### Available Filters

```php
// Modify email data before sending to API
add_filter('wp_smtp_api_email_data', function($email_data) {
    // Modify $email_data array
    return $email_data;
});

// Modify API request arguments
add_filter('wp_smtp_api_request_args', function($args) {
    // Modify $args array (headers, timeout, etc.)
    return $args;
});
```

### Available Actions

```php
// Fires after email sent successfully
add_action('wp_smtp_api_email_sent', function($email_data, $response) {
    // Custom logic after successful send
}, 10, 2);

// Fires after email send failure
add_action('wp_smtp_api_email_failed', function($email_data, $error) {
    // Custom logic after failed send
}, 10, 2);
```

## Troubleshooting

### Emails not sending

1. Check plugin is **enabled** in settings
2. Verify API endpoint URL is correct (HTTPS only)
3. Verify JWT token is valid
4. Send test email and check response
5. Enable logging to see detailed error messages
6. Check API server logs for errors

### SSL Certificate Errors

If you get SSL verification errors:

1. Ensure your API uses a valid SSL certificate
2. Temporarily disable SSL verification for testing (not recommended for production)
3. Update your server's CA certificates bundle

### Authentication Failures (401/403)

1. Verify JWT token is correct
2. Check token hasn't expired (if using expiring tokens)
3. Verify API is receiving `Authorization` header
4. Check API CORS settings if applicable

### Debug Mode

Enable WordPress debug mode for detailed error messages:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## Support & Contributing

- **Issues**: Report bugs via GitHub Issues
- **Security**: Report security vulnerabilities privately
- **Contributing**: Pull requests welcome

## License

GPL v2 or later

## Changelog

### 1.0.0 (2024-01-XX)
- Initial release
- JWT authentication support
- Encrypted token storage
- Comprehensive input validation
- Secure logging system
- Admin settings panel
- Test email functionality
- wp-config.php constants support
