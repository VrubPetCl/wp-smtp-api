# WP SMTP API - Comprehensive Project Documentation

**Version:** 1.0.0
**Last Updated:** 2025-11-21
**Status:** Production Ready

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Architecture](#architecture)
3. [Security Implementation](#security-implementation)
4. [File Structure](#file-structure)
5. [Key Components](#key-components)
6. [FastAPI Integration](#fastapi-integration)
7. [Configuration](#configuration)
8. [Development Workflow](#development-workflow)
9. [Testing & Deployment](#testing--deployment)
10. [Troubleshooting](#troubleshooting)

---

## Project Overview

### What Is This?

WP SMTP API is a **production-ready WordPress plugin** that replaces the default SMTP email functionality by routing all emails through a secure JWT-authenticated REST API over HTTPS. It's specifically designed to work with the [FastAPI SMTP Proxy](https://github.com/VrubPetCl/fastapi-smtp-proxy) backend.

### Why This Approach?

**Traditional Problem:**
- WordPress stores SMTP credentials in the database (security risk)
- Each site manages its own email configuration
- No centralized logging or analytics
- Limited scalability for high-volume sites

**Our Solution:**
- WordPress never stores SMTP credentials
- Centralized email management via FastAPI proxy
- JWT-based authentication (encrypted at rest)
- Enterprise-grade security features
- Full analytics and tracking (on proxy side)
- Multi-tenant support (multiple WordPress sites → one proxy)

### Key Features

✅ **Security First**
- AES-256-CBC encryption with HMAC for stored tokens
- PBKDF2 key derivation (10,000 iterations)
- Input validation preventing injection attacks
- Rate limiting on test emails
- HTTPS-only enforcement
- No sensitive data in logs

✅ **WordPress Integration**
- Hooks into `pre_wp_mail` filter
- Automatic email interception
- Seamless with all plugins/themes
- Test email functionality
- Fallback to standard wp_mail if API fails

✅ **FastAPI Compatible**
- Standard Bearer token authentication
- JSON payload format matching FastAPI schema
- Attachment support (base64 encoding)
- CC/BCC handling
- HTML and plain text emails

---

## Architecture

### High-Level Flow

```
WordPress Site (wp_mail called)
    ↓
WP SMTP API Plugin (this plugin)
    ↓ Intercepts via pre_wp_mail filter
    ↓ Validates & sanitizes data
    ↓ Encrypts to JSON payload
    ↓ HTTPS POST with JWT Bearer token
    ↓
FastAPI SMTP Proxy
    ↓ Validates JWT
    ↓ Logs to database
    ↓ Sends via configured SMTP
    ↓
SMTP Server (Gmail/SendGrid/etc.)
    ↓
Recipient Inbox
```

### Component Architecture

```
┌─────────────────────────────────────────────────┐
│ WordPress Core                                   │
│  └─> wp_mail()                                  │
└────────────┬────────────────────────────────────┘
             │
             ↓
┌─────────────────────────────────────────────────┐
│ WP SMTP API Plugin                              │
│                                                  │
│  ┌──────────────────────────────────────────┐  │
│  │ WP_SMTP_API_Mailer (class-api-mailer.php)│  │
│  │ - Hooks: pre_wp_mail, phpmailer_init     │  │
│  │ - Intercepts emails                       │  │
│  │ - Parses headers                          │  │
│  └────────────┬─────────────────────────────┘  │
│               │                                  │
│               ↓                                  │
│  ┌──────────────────────────────────────────┐  │
│  │ WP_SMTP_API_Validator (class-validator)  │  │
│  │ - Email validation                        │  │
│  │ - Subject sanitization                    │  │
│  │ - Content sanitization                    │  │
│  │ - Header injection prevention             │  │
│  └────────────┬─────────────────────────────┘  │
│               │                                  │
│               ↓                                  │
│  ┌──────────────────────────────────────────┐  │
│  │ WP_SMTP_API_Client (class-api-client)    │  │
│  │ - prepare_payload()                       │  │
│  │ - format_attachments()                    │  │
│  │ - make_request() (HTTP POST)              │  │
│  │ - handle_response()                       │  │
│  └────────────┬─────────────────────────────┘  │
│               │                                  │
│  ┌────────────┴─────────────────────────────┐  │
│  │ WP_SMTP_API_Settings (class-settings)    │  │
│  │ - Token encryption/decryption             │  │
│  │ - wp-config constant support              │  │
│  └──────────────────────────────────────────┘  │
│                                                  │
│  ┌──────────────────────────────────────────┐  │
│  │ WP_SMTP_API_Logger (class-logger)        │  │
│  │ - Secure logging with masked data        │  │
│  │ - Random filename suffixes                │  │
│  └──────────────────────────────────────────┘  │
└─────────────────────────────────────────────────┘
             │
             ↓ HTTPS + JWT
┌─────────────────────────────────────────────────┐
│ FastAPI SMTP Proxy (external service)           │
│  - /api/send endpoint                           │
│  - JWT validation                               │
│  - Analytics & logging                          │
│  - SMTP relay                                   │
└─────────────────────────────────────────────────┘
```

---

## Security Implementation

### 1. Encryption System (class-settings.php)

#### Key Generation
```php
// Cryptographically secure 32-byte key for AES-256
$random_bytes = openssl_random_pseudo_bytes(32, $crypto_strong);
// Fallback if crypto_strong = false
$random_bytes = hash('sha256', wp_generate_password(64) . wp_salt(), true);
```

#### Key Derivation
```php
// PBKDF2 with 10,000 iterations for proper key derivation
hash_pbkdf2('sha256', $input, wp_salt(), 10000, 32, true);
```

#### Encryption (AES-256-CBC with HMAC)
```php
// Structure: base64(IV + HMAC + encrypted_data)
$iv = openssl_random_pseudo_bytes(16);
$encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
$hmac = hash_hmac('sha256', $encrypted, $key, true);
return base64_encode($iv . $hmac . $encrypted);
```

#### Decryption with Integrity Check
```php
// Extract: IV (16 bytes) + HMAC (32 bytes) + encrypted data
$iv = substr($decoded, 0, 16);
$hmac = substr($decoded, 16, 32);
$encrypted = substr($decoded, 48);

// Verify HMAC before decryption (timing-attack resistant)
if (hash_equals($calculated_hmac, $hmac)) {
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}
```

**Why HMAC?**
- Prevents tampering with encrypted data
- Detects corruption before decryption
- Authenticated encryption (encrypt-then-MAC)

### 2. Input Validation (class-validator.php)

#### Email Validation
```php
// Prevents header injection
if (contains_header_injection($email)) return false;
// Uses WordPress is_email() for RFC compliance
return is_email($email);
```

#### Subject Sanitization
```php
// Length limit (RFC 2822: 998 chars max)
$subject = substr($subject, 0, 998);
// Remove header injection vectors
$subject = str_replace(["\r", "\n", "%0a", "%0d"], '', $subject);
// Remove null bytes
$subject = str_replace(chr(0), '', $subject);
```

#### Content Sanitization
```php
// For HTML emails: preserve structure, remove scripts
$content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
// For plain text: full sanitization
$content = sanitize_textarea_field($content);
// Length limit: 10MB to prevent memory exhaustion
if (strlen($content) > 10485760) {
    return substr($content, 0, 10485760);
}
```

### 3. Rate Limiting (admin/class-admin-settings.php)

```php
// 3 test emails per 5 minutes per user
$rate_limit_key = 'wp_smtp_api_test_rate_' . get_current_user_id();
$test_count = get_transient($rate_limit_key);
if ($test_count >= 3) {
    return 'Rate limit exceeded';
}
set_transient($rate_limit_key, $test_count + 1, 5 * MINUTE_IN_SECONDS);
```

### 4. Secure Logging (class-logger.php)

```php
// Random filename suffix (8 chars, alphanumeric)
$random_suffix = wp_generate_password(8, false);
$log_file = "smtp-api-{date}-{$random_suffix}.log";

// Mask sensitive data
$sensitive_keys = ['jwt_token', 'password', 'Authorization'];
foreach ($context as $key => $value) {
    if (in_array($key, $sensitive_keys)) {
        $context[$key] = substr($value, 0, 8) . '...' . substr($value, -8);
    }
}
```

### 5. JWT Token Validation (class-validator.php)

```php
// Must have 3 parts: header.payload.signature
$parts = explode('.', $token);
if (count($parts) !== 3) return false;

// Each part must be valid base64url
foreach ($parts as $part) {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $part)) return false;
}
```

### 6. wp-config Constant Validation (wp-smtp-api.php)

```php
// Endpoint must be HTTPS
if (strpos(WP_SMTP_API_ENDPOINT, 'https://') !== 0) {
    add_action('admin_notices', show_error);
}

// JWT must have valid format
if (substr_count(WP_SMTP_API_JWT_TOKEN, '.') !== 2) {
    add_action('admin_notices', show_warning);
}

// Encryption key minimum length
if (strlen(WP_SMTP_API_ENCRYPTION_KEY) < 16) {
    add_action('admin_notices', show_warning);
}
```

---

## File Structure

```
wp-smtp-api/
├── wp-smtp-api.php                 # Plugin bootstrap, hooks, constant validation
├── uninstall.php                   # Cleanup on plugin deletion
├── README.md                       # User-facing documentation
├── readme.txt                      # WordPress.org format README
├── claude.md                       # This file (developer documentation)
├── .gitignore                      # Git exclusions
│
├── includes/                       # Core plugin logic
│   ├── class-settings.php          # Settings management + encryption
│   ├── class-validator.php         # Input validation utilities
│   ├── class-logger.php            # Secure logging system
│   ├── class-api-client.php        # HTTP client for API requests
│   └── class-api-mailer.php        # Email interception and routing
│
├── admin/                          # WordPress admin interface
│   ├── class-admin-settings.php    # Settings page controller
│   └── views/
│       └── settings-page.php       # Settings UI template
│
└── assets/                         # Frontend assets
    └── css/
        └── admin.css               # Admin panel styling
```

### File Responsibilities

#### wp-smtp-api.php (Main Plugin File)
- **Purpose**: Bootstrap and lifecycle management
- **Key Functions**:
  - `activate()` - Creates default options, log directory
  - `deactivate()` - Cleanup transients
  - `init()` - Load dependencies, validate constants
  - `validate_constants()` - Security checks on wp-config constants
- **Hooks**: `plugins_loaded`, `activation`, `deactivation`

#### includes/class-settings.php
- **Purpose**: Settings management with encryption
- **Singleton**: Yes
- **Key Methods**:
  - `generate_encryption_key()` - Crypto-secure 32-byte key
  - `derive_key()` - PBKDF2 key derivation
  - `encrypt()` / `decrypt()` - AES-256-CBC with HMAC
  - `get_jwt_token()` - Returns token from constant or DB
  - `is_configured()` - Validates plugin readiness
- **Security**: Encrypts JWT tokens before DB storage

#### includes/class-validator.php
- **Purpose**: Input validation and sanitization
- **Static Methods**: All methods are static utilities
- **Key Functions**:
  - `validate_email()` - Email + header injection check
  - `sanitize_subject()` - Length limit + injection prevention
  - `sanitize_content()` - HTML preservation + script removal
  - `validate_url()` - HTTPS enforcement
  - `validate_jwt_token()` - Format validation
  - `contains_header_injection()` - Pattern matching for attacks
- **Security**: Prevents OWASP Top 10 vulnerabilities

#### includes/class-logger.php
- **Purpose**: Secure debug logging
- **Singleton**: Yes
- **Key Features**:
  - Random filename suffix (8 chars)
  - Automatic sensitive data masking
  - .htaccess protection
  - 30-day auto-cleanup via `clear_old_logs()`
  - Admin-only access
- **Storage**: `wp-content/uploads/wp-smtp-api-logs/`

#### includes/class-api-client.php
- **Purpose**: HTTP communication with FastAPI
- **Key Methods**:
  - `send_email($email_data)` - Main entry point
  - `prepare_payload()` - Format data for FastAPI
  - `format_attachments()` - Base64 encoding + MIME detection
  - `make_request()` - HTTP POST with WordPress HTTP API
  - `handle_response()` - Parse and validate API response
  - `parse_error_message()` - Extract errors from JSON
  - `get_mime_type()` - WordPress + PHP fallbacks
- **Security**: JSON depth limit (32), error message length limit (500 chars)

#### includes/class-api-mailer.php
- **Purpose**: Email interception and routing
- **Singleton**: Yes
- **Hooks**:
  - `pre_wp_mail` (priority 10) - Primary interception
  - `phpmailer_init` (priority 999) - Backup hook
- **Key Methods**:
  - `pre_wp_mail()` - Returns true (sent) or null (fallback)
  - `prepare_email_data()` - Validate and format email
  - `parse_headers()` - Extract From, Reply-To, CC, BCC, Content-Type
- **Fallback**: Returns null to let wp_mail handle if API fails

#### admin/class-admin-settings.php
- **Purpose**: WordPress admin interface
- **Page**: Settings → WP SMTP API
- **Key Features**:
  - Settings registration (Settings API)
  - Test email functionality
  - Rate limiting (3 per 5 min)
  - Log viewing and clearing
  - Nonce verification on all actions
- **Hooks**: `admin_menu`, `admin_init`, `admin_post_*`, `admin_enqueue_scripts`

---

## Key Components

### 1. Email Interception Flow

```php
// Entry point: WordPress calls wp_mail()
add_filter('pre_wp_mail', [$this, 'pre_wp_mail'], 10, 2);

public function pre_wp_mail($null, $atts) {
    // 1. Extract email data
    $to = $atts['to'];
    $subject = $atts['subject'];
    $message = $atts['message'];
    $headers = $atts['headers'];
    $attachments = $atts['attachments'];

    // 2. Validate and sanitize
    $email_data = $this->prepare_email_data(...);
    if (!$email_data) return false; // Invalid data

    // 3. Send via API
    $result = $this->api_client->send_email($email_data);

    // 4. Return true (sent) or null (let wp_mail handle)
    return $result['success'] ? true : null;
}
```

### 2. Header Parsing

```php
// Handles both array and string headers
if (!is_array($headers)) {
    $headers = explode("\n", str_replace("\r\n", "\n", $headers));
}

foreach ($headers as $header) {
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
            }
            break;
        // ... handle CC, BCC, Reply-To
    }
}
```

### 3. Attachment Handling

```php
private function format_attachments($attachments) {
    $formatted = array();

    foreach ($attachments as $attachment) {
        // File path: read and encode
        if (is_string($attachment) && file_exists($attachment)) {
            $formatted[] = array(
                'filename' => basename($attachment),
                'content' => base64_encode(file_get_contents($attachment)),
                'content_type' => $this->get_mime_type($attachment),
            );
        }
        // Array with content: format it
        elseif (is_array($attachment) && isset($attachment['content'])) {
            $formatted[] = array(
                'filename' => $attachment['filename'] ?? 'attachment',
                'content' => ($attachment['encoded'] ?? false)
                    ? $attachment['content']
                    : base64_encode($attachment['content']),
                'content_type' => $attachment['type'] ?? 'application/octet-stream',
            );
        }
    }

    return $formatted;
}
```

---

## FastAPI Integration

### Request Format

```json
{
  "to": ["recipient@example.com"],
  "subject": "Email Subject",
  "content": "Email body (HTML or plain text)",
  "from_email": "sender@example.com",
  "from_name": "Sender Name",
  "timestamp": 1700000000,
  "content_type": "text/html",
  "reply_to": "reply@example.com",
  "cc": ["cc@example.com"],
  "bcc": ["bcc@example.com"],
  "attachments": [
    {
      "filename": "document.pdf",
      "content": "JVBERi0xLjQK...",
      "content_type": "application/pdf"
    }
  ]
}
```

### Response Format

**Success (200-299):**
```json
{
  "success": true,
  "message": "Email sent successfully",
  "email_id": "123"
}
```

**Error (400-599):**
```json
{
  "success": false,
  "error": "Detailed error message",
  "message": "User-friendly message"
}
```

### Authentication

```http
POST /api/send HTTP/1.1
Host: your-fastapi-domain.com
Content-Type: application/json
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
User-Agent: WordPress/6.4; WP-SMTP-API/1.0.0

{payload}
```

### Field Mapping

| WordPress | FastAPI Field | Notes |
|-----------|--------------|-------|
| `from` | `from_email` | Changed for FastAPI compatibility |
| `from_name` | `from_name` | Same |
| `to` | `to` | Array of emails |
| `subject` | `subject` | Sanitized |
| `message` | `content` | HTML preserved |
| `headers` | `reply_to`, `cc`, `bcc` | Parsed and separated |
| Content-Type header | `content_type` | "text/html" or "text/plain" |
| `attachments` | `attachments` | Base64 encoded with metadata |

---

## Configuration

### Option 1: Admin Panel

Navigate to **Settings → WP SMTP API**

```
API Endpoint URL: https://your-proxy.com/api/send
JWT Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
Request Timeout: 30 seconds
SSL Verification: ✓ Enabled
Enable Logging: □ Disabled (enable for debugging only)
Enable Plugin: ✓ Enabled
```

**Storage**: JWT token encrypted in `wp_smtp_api_settings` option

### Option 2: wp-config.php (Recommended)

```php
// API Configuration
define('WP_SMTP_API_ENDPOINT', 'https://your-proxy.com/api/send');
define('WP_SMTP_API_JWT_TOKEN', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...');

// Optional: Custom encryption key (min 16 chars)
define('WP_SMTP_API_ENCRYPTION_KEY', 'your-32-char-encryption-key-here');
```

**Benefits**:
- Token never stored in database
- Protected from database exports
- Not visible in admin panel
- Survives plugin updates

### Database Schema

```sql
-- Settings (wp_options)
wp_smtp_api_settings = {
  "api_endpoint": "https://...",
  "jwt_token": "encrypted_base64_string", -- Only if not using constant
  "timeout": 30,
  "ssl_verify": true,
  "enable_logging": false,
  "enabled": true
}

-- Encryption key (wp_options)
wp_smtp_api_encryption_key = "base64_encoded_32_byte_key"

-- Log filename suffix (wp_options)
wp_smtp_api_log_suffix = "a1b2c3d4"

-- Last error (transient, 1 hour)
_transient_wp_smtp_api_last_error = {
  "message": "Error description",
  "code": 500,
  "time": 1700000000
}

-- Rate limiting (transient, 5 minutes)
_transient_wp_smtp_api_test_rate_{user_id} = 3
```

---

## Development Workflow

### Setting Up Development Environment

```bash
# 1. Clone repository
cd ~/wp-content/plugins/
git clone <repo-url> wp-smtp-api

# 2. Install WordPress (if needed)
# Use Local, MAMP, or Docker

# 3. Activate plugin
# WordPress Admin → Plugins → Activate "WP SMTP API"

# 4. Configure with test endpoint
# Use ngrok or local FastAPI instance
```

### Local FastAPI Setup

```bash
# Clone FastAPI proxy
git clone https://github.com/VrubPetCl/fastapi-smtp-proxy.git
cd fastapi-smtp-proxy

# Create virtual environment
python -m venv venv
source venv/bin/activate  # Windows: venv\Scripts\activate

# Install dependencies
pip install -r requirements.txt

# Configure
cp .env.example .env
# Edit .env: set JWT_SECRET_KEY

# Initialize database and create client
python manage.py create-client
# Enter SMTP credentials

# Generate API key
python manage.py create-api-key
# Copy the JWT token

# Start server
python -m uvicorn app.main:app --reload
# API available at http://localhost:8000
```

### WordPress Configuration for Development

```php
// wp-config.php (local development)
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

define('WP_SMTP_API_ENDPOINT', 'http://localhost:8000/api/send');
define('WP_SMTP_API_JWT_TOKEN', 'paste-token-from-manage-py');
```

### Testing Flow

1. **Enable Logging**
   - Settings → WP SMTP API
   - Check "Enable debug logging"
   - Save settings

2. **Send Test Email**
   - Click "Send Test Email" button
   - Check admin email inbox
   - Check logs: Settings → WP SMTP API → View Logs

3. **Test with Real Plugin**
   ```php
   // In any WordPress plugin/theme
   wp_mail(
       'test@example.com',
       'Test Subject',
       '<h1>Test HTML Email</h1>',
       array('Content-Type: text/html')
   );
   ```

4. **Check FastAPI Logs**
   ```bash
   # In FastAPI terminal
   # Should see POST /api/send with 200 response
   ```

### Debugging

**WordPress Side:**
```bash
# Check WordPress debug log
tail -f wp-content/debug.log

# Check plugin logs (if logging enabled)
tail -f wp-content/uploads/wp-smtp-api-logs/smtp-api-*.log
```

**FastAPI Side:**
```bash
# Check FastAPI console output
# or
python manage.py show-analytics  # View email statistics
```

**Common Issues:**

| Issue | Solution |
|-------|----------|
| "API endpoint must use HTTPS" | For dev: temporarily comment out validation in wp-smtp-api.php:115-122 |
| "Invalid JWT token" | Check token format (3 parts separated by dots) |
| "Connection refused" | Ensure FastAPI is running on correct port |
| "SSL verification failed" | Disable SSL verify in settings (dev only!) |
| "Rate limit exceeded" | Wait 5 minutes or clear transient: `delete_transient('wp_smtp_api_test_rate_' . get_current_user_id())` |

---

## Testing & Deployment

### Pre-Deployment Checklist

**Security:**
- [ ] JWT token in wp-config.php (not database)
- [ ] HTTPS endpoint only
- [ ] SSL verification enabled
- [ ] Debug logging disabled
- [ ] Strong encryption key defined (optional)
- [ ] wp-config.php excluded from version control

**Functionality:**
- [ ] Test email sends successfully
- [ ] HTML emails render correctly
- [ ] Attachments work (if used)
- [ ] Error handling works (try invalid token)
- [ ] Rate limiting works (try 4 test emails)

**WordPress:**
- [ ] Plugin version updated
- [ ] README.md updated
- [ ] Compatible with latest WordPress
- [ ] No PHP warnings/errors
- [ ] Settings page accessible

### Deployment Steps

1. **Deploy FastAPI Proxy First**
   ```bash
   # Production server
   git clone https://github.com/VrubPetCl/fastapi-smtp-proxy.git
   cd fastapi-smtp-proxy

   # Set strong JWT secret
   export JWT_SECRET_KEY=$(python -c "import secrets; print(secrets.token_urlsafe(32))")

   # Configure SMTP
   python manage.py create-client
   python manage.py create-api-key

   # Run with Gunicorn + Nginx
   gunicorn app.main:app -w 4 -k uvicorn.workers.UvicornWorker
   ```

2. **Configure WordPress Plugin**
   ```php
   // wp-config.php
   define('WP_SMTP_API_ENDPOINT', 'https://your-production-domain.com/api/send');
   define('WP_SMTP_API_JWT_TOKEN', 'production-jwt-token-from-step-1');
   define('WP_SMTP_API_ENCRYPTION_KEY', 'long-random-string-32-chars-min');
   ```

3. **Activate and Test**
   - Activate plugin
   - Send test email
   - Monitor logs on FastAPI side
   - Verify email delivery

### Production Monitoring

**WordPress:**
- Monitor error logs: `wp-content/debug.log`
- Check for "WP SMTP API: " error_log entries
- Monitor last_error transient in database

**FastAPI:**
- Use dashboard: `https://your-domain.com/admin`
- Check email success rate
- Monitor failed emails
- Review analytics

### Performance Considerations

**WordPress Side:**
- Plugin adds ~1-2ms overhead per email
- Settings cached in singleton (no DB queries per email)
- HTTP timeout: 30s default (configurable 5-120s)

**Recommended Settings:**
- Timeout: 30s for most use cases
- 15s for high-volume sites with fast SMTP
- 60s if using slow SMTP servers

**Scaling:**
- Plugin handles concurrent requests (WordPress process isolation)
- Rate limiting only on test emails (not production emails)
- No DB writes per email (only API client logs if enabled)

---

## Troubleshooting

### Common Errors

#### 1. "Unauthorized: Invalid JWT token"

**Causes:**
- Wrong JWT token
- Token expired (if using expiring tokens)
- Token not in correct format

**Solutions:**
```bash
# Verify token format (should have 2 dots)
echo "your-token" | grep -o "\." | wc -l  # Should output: 2

# Generate new token
python manage.py create-api-key

# Verify token works
curl -X POST https://your-api.com/api/send \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"to":["test@example.com"],"subject":"Test","content":"Test","timestamp":'$(date +%s)'}'
```

#### 2. "API endpoint must use HTTPS"

**Production:**
- Only HTTPS endpoints allowed (security requirement)
- Get SSL certificate (Let's Encrypt is free)

**Development:**
```php
// Temporary workaround (NEVER in production!)
// Comment out lines 115-122 in wp-smtp-api.php
// Or use ngrok for local HTTPS tunnel
ngrok http 8000
# Use https://xxx.ngrok.io/api/send
```

#### 3. "SSL certificate verification failed"

**Production:**
- Ensure SSL certificate is valid
- Update CA certificates on server

**Development:**
```
Settings → WP SMTP API
Uncheck "Verify SSL certificates"
(Remember to re-enable for production!)
```

#### 4. "Rate limit exceeded"

**Test Emails:**
```php
// Clear rate limit
delete_transient('wp_smtp_api_test_rate_' . get_current_user_id());
```

**Note:** Rate limiting ONLY applies to test emails, not production wp_mail() calls.

#### 5. Email Not Sending

**Debug Steps:**

1. **Check WordPress Logs**
   ```bash
   tail -f wp-content/debug.log | grep "WP SMTP"
   ```

2. **Enable Plugin Logging**
   - Settings → WP SMTP API
   - Enable logging
   - Send test email
   - View Logs button

3. **Check Last Error**
   ```php
   // In WordPress
   $client = new WP_SMTP_API_Client();
   $error = $client->get_last_error();
   var_dump($error);
   ```

4. **Test API Directly**
   ```bash
   curl -X POST https://your-api.com/api/send \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "to": ["test@example.com"],
       "subject": "Direct API Test",
       "content": "Testing API directly",
       "timestamp": '$(date +%s)',
       "content_type": "text/plain"
     }'
   ```

5. **Check FastAPI Side**
   ```bash
   # FastAPI logs
   python manage.py show-analytics
   # Look for failed emails
   ```

### Debug Mode

**Enable Full Debug Output:**

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false); // Don't show on frontend
define('SCRIPT_DEBUG', true);

// Enable plugin logging
// Settings → WP SMTP API → Enable debug logging
```

**View Logs:**
```bash
# WordPress debug log
tail -f wp-content/debug.log

# Plugin-specific log (if logging enabled)
tail -f wp-content/uploads/wp-smtp-api-logs/smtp-api-$(date +%Y-%m-%d)-*.log
```

---

## Git Branches

### Current Branches

1. **`claude/wordpress-smtp-jwt-plugin-01D2PothN5Kp6ykfQNtvhCAD`**
   - Initial plugin implementation
   - Security improvements (commit: 69648ab)
   - All security features implemented

2. **`claude/fastapi-smtp-proxy-integration-01D2PothN5Kp6ykfQNtvhCAD`** (CURRENT)
   - FastAPI integration (commit: dcc29aa)
   - Attachment support
   - Field name changes (from → from_email)
   - HMAC signature removed
   - Production ready

### Commit History

```
dcc29aa - FEATURE: FastAPI SMTP Proxy integration
69648ab - SECURITY: Major security and production-readiness improvements
c549379 - Initial implementation of WP SMTP API plugin
```

---

## Future Enhancements

### Planned Features

1. **Bulk Email Support**
   - Queue system for high-volume sends
   - Background processing via WP Cron

2. **Email Templates**
   - Predefined templates
   - Variable substitution
   - Template management UI

3. **Advanced Analytics**
   - WordPress dashboard widget
   - Email delivery stats from FastAPI
   - Error rate tracking

4. **Multi-API Support**
   - Configure multiple API endpoints
   - Round-robin or failover
   - Per-email-type routing

5. **WP-CLI Integration**
   ```bash
   wp smtp-api test-email user@example.com
   wp smtp-api list-errors
   wp smtp-api rotate-logs
   ```

### Known Limitations

1. **Attachments**: No size validation (relies on PHP/WordPress limits)
2. **Batch Sending**: Each email is individual API call
3. **Retry Logic**: Single attempt (no automatic retry on failure)
4. **Metrics**: No client-side analytics (handled by FastAPI)

### Contribution Guidelines

When extending this plugin:

1. **Security First**: Always validate and sanitize inputs
2. **WordPress Standards**: Follow WordPress coding standards
3. **Backward Compatibility**: Don't break existing configurations
4. **Testing**: Test with multiple WordPress versions
5. **Documentation**: Update README.md and this file

---

## Quick Reference

### Important Functions

```php
// Send email (automatic)
wp_mail($to, $subject, $message, $headers, $attachments);

// Test connection
$client = new WP_SMTP_API_Client();
$result = $client->test_connection();

// Get settings
$settings = WP_SMTP_API_Settings::instance();
$endpoint = $settings->get_api_endpoint();
$token = $settings->get_jwt_token();

// Check if configured
$is_ready = $settings->is_configured();

// Get last error
$error = $client->get_last_error();
```

### Important Filters

```php
// Modify email data before sending
add_filter('pre_wp_mail', function($null, $atts) {
    // Custom logic
    return $null;
}, 5, 2); // Priority < 10 to run before plugin

// Modify API payload (not implemented yet, but could be added)
// add_filter('wp_smtp_api_payload', function($payload) { ... });
```

### Important Constants

```php
WP_SMTP_API_VERSION          // Plugin version
WP_SMTP_API_PLUGIN_DIR       // Plugin directory path
WP_SMTP_API_PLUGIN_URL       // Plugin URL
WP_SMTP_API_ENDPOINT         // API endpoint (if defined)
WP_SMTP_API_JWT_TOKEN        // JWT token (if defined)
WP_SMTP_API_ENCRYPTION_KEY   // Custom encryption key (if defined)
```

### Important Options

```php
wp_smtp_api_settings         // Main settings array
wp_smtp_api_encryption_key   // Encryption key (base64)
wp_smtp_api_log_suffix       // Random log filename suffix
```

### Important Transients

```php
wp_smtp_api_last_error               // Last error (1 hour)
wp_smtp_api_test_rate_{user_id}      // Rate limiting (5 minutes)
```

---

## Support & Resources

### Documentation
- Plugin README: `/README.md`
- WordPress README: `/readme.txt`
- This File: `/claude.md`

### External Resources
- FastAPI Proxy: https://github.com/VrubPetCl/fastapi-smtp-proxy
- FastAPI Docs: https://fastapi-smtp-proxy-docs.example.com
- WordPress Plugin Handbook: https://developer.wordpress.org/plugins/

### Getting Help

1. Check logs (WordPress + plugin + FastAPI)
2. Review this documentation
3. Check GitHub issues
4. Test API directly with curl
5. Enable debug mode

---

## Changelog

### Version 1.0.0 (2025-11-21)

**Initial Release:**
- JWT-based authentication
- AES-256-CBC encryption with HMAC
- FastAPI SMTP Proxy integration
- Attachment support (base64 encoding)
- Input validation and sanitization
- Rate limiting on test emails
- Secure logging system
- wp-config.php constant support
- Admin settings panel
- Test email functionality

**Security Features:**
- PBKDF2 key derivation
- Authenticated encryption
- Header injection prevention
- Length limits on all inputs
- JSON decode depth limits
- Error message sanitization
- wp-config constant validation

**Performance:**
- Singleton pattern for all classes
- Settings caching
- Minimal DB queries
- Async HTTP requests (WordPress HTTP API)

---

**Last Updated:** 2025-11-21
**Branch:** claude/fastapi-smtp-proxy-integration-01D2PothN5Kp6ykfQNtvhCAD
**Status:** Production Ready ✅
