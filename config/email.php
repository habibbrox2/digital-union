<?php
/**
 * Email Configuration
 * PHPMailer SMTP Configuration
 * Author: Hr Habib
 * Last Updated: 2025-12-28
 */

declare(strict_types=1);

// Ensure BASE_PATH is defined (project root)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Try to load .env variables if available (prefer vlucas/phpdotenv)
$rootPath = BASE_PATH;
$dotenvPath = $rootPath . '/.env';
if (file_exists($rootPath . '/vendor/autoload.php')) {
    @require_once $rootPath . '/vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        try {
            $dot = Dotenv\Dotenv::createImmutable($rootPath);
            $dot->safeLoad();
        } catch (Throwable $e) {
            // ignore dotenv load errors; fall back to existing env
        }
    }
}

// If .env exists but phpdotenv not available, parse basic key=val pairs
if (file_exists($dotenvPath) && !isset($_ENV['DOTENV_LOADED'])) {
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($k, $_ENV)) {
            $_ENV[$k] = $v;
        }
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
    $_ENV['DOTENV_LOADED'] = '1';
}

// ============================
// EMAIL CONFIGURATION
// ============================

// Detect environment
$environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
$isProduction = $environment === 'production';

// ============================
// SMTP Configuration
// ============================

if (!defined('MAIL_DRIVER')) {
    // Email driver: 'smtp', 'gmail', or 'sendmail'
    define('MAIL_DRIVER', $_ENV['MAIL_DRIVER'] ?? getenv('MAIL_DRIVER') ?? 'smtp');
}

// SMTP Host
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');

if (!defined('SMTP_PORT')) {
    // SMTP Port: 587 (TLS) or 465 (SSL)
    define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? 587));
}

if (!defined('SMTP_ENCRYPTION')) {
    // Encryption: 'tls', 'ssl', or '' (none)
    define('SMTP_ENCRYPTION', $_ENV['SMTP_ENCRYPTION'] ?? 'tls');
}

if (!defined('SMTP_USERNAME')) {
    // SMTP Username (usually email address)
    define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? 'your-email@gmail.com');
}

if (!defined('SMTP_PASSWORD')) {
    // SMTP Password (use app-specific password for Gmail)
    define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? 'your-app-password');
}


if (!defined('MAIL_FROM_ADDRESS')) {
    // From Email Address
    define('MAIL_FROM_ADDRESS', $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?? SMTP_USERNAME);
}

if (!defined('MAIL_FROM_NAME')) {
    // From Name
    define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?? 'লগ ধাকা');
}

// ============================
// EMAIL FEATURES CONFIGURATION
// ============================

if (!defined('SEND_WELCOME_EMAIL')) {
    define('SEND_WELCOME_EMAIL', filter_var($_ENV['SEND_WELCOME_EMAIL'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('SEND_EMAIL_VERIFICATION')) {
    define('SEND_EMAIL_VERIFICATION', filter_var($_ENV['SEND_EMAIL_VERIFICATION'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('SEND_LOGIN_ALERT')) {
    define('SEND_LOGIN_ALERT', filter_var($_ENV['SEND_LOGIN_ALERT'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('SEND_PASSWORD_CHANGE_ALERT')) {
    define('SEND_PASSWORD_CHANGE_ALERT', filter_var($_ENV['SEND_PASSWORD_CHANGE_ALERT'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('SEND_FAILED_LOGIN_ALERT')) {
    define('SEND_FAILED_LOGIN_ALERT', filter_var($_ENV['SEND_FAILED_LOGIN_ALERT'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('FAILED_LOGIN_THRESHOLD')) {
    define('FAILED_LOGIN_THRESHOLD', (int)($_ENV['FAILED_LOGIN_THRESHOLD'] ?? 3));
}

if (!defined('PASSWORD_RESET_EXPIRY')) {
    define('PASSWORD_RESET_EXPIRY', (int)($_ENV['PASSWORD_RESET_EXPIRY'] ?? 3600));
}

if (!defined('EMAIL_VERIFICATION_EXPIRY')) {
    define('EMAIL_VERIFICATION_EXPIRY', (int)($_ENV['EMAIL_VERIFICATION_EXPIRY'] ?? 86400));
}

// ============================
// DEBUG & LOGGING
// ============================

if (!defined('EMAIL_DEBUG')) {
    define('EMAIL_DEBUG', filter_var($_ENV['EMAIL_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('EMAIL_LOG_ENABLED')) {
    define('EMAIL_LOG_ENABLED', filter_var($_ENV['EMAIL_LOG_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('EMAIL_LOG_DIR')) {
    $logDir = $_ENV['EMAIL_LOG_DIR'] ?? 'storage/logs/email';
    // Make path absolute if it's relative
    if (!str_starts_with($logDir, '/')) {
        $logDir = BASE_PATH . '/' . $logDir;
    }
    define('EMAIL_LOG_DIR', $logDir);
}

// Create log directory if doesn't exist
if (EMAIL_LOG_ENABLED && !is_dir(EMAIL_LOG_DIR)) {
    @mkdir(EMAIL_LOG_DIR, 0775, true);
}

if (!defined('EMAIL_LOG_FILE')) {
    define('EMAIL_LOG_FILE', EMAIL_LOG_DIR . '/email_' . date('Y-m-d') . '.log');
}

// ============================
// EMAIL RETRY CONFIGURATION
// ============================

if (!defined('EMAIL_MAX_RETRIES')) {
    define('EMAIL_MAX_RETRIES', (int)($_ENV['EMAIL_MAX_RETRIES'] ?? 3));
}

if (!defined('EMAIL_RETRY_DELAY')) {
    define('EMAIL_RETRY_DELAY', (int)($_ENV['EMAIL_RETRY_DELAY'] ?? 300));
}

// ============================
// TEMPLATE CONFIGURATION
// ============================

if (!defined('EMAIL_TEMPLATE_DIR')) {
    define('EMAIL_TEMPLATE_DIR', BASE_PATH . '/templates/emails');
}

// Create email template directory if doesn't exist
if (!is_dir(EMAIL_TEMPLATE_DIR)) {
    @mkdir(EMAIL_TEMPLATE_DIR, 0775, true);
}

// ============================
// Google Gmail SMTP Example
// ============================
/*
 * For Gmail SMTP:
 * 1. Enable 2-Step Verification on Gmail account
 * 2. Generate App Password (https://myaccount.google.com/apppasswords)
 * 3. Use App Password as MAIL_PASSWORD
 * 
 * .env example:
 * MAIL_DRIVER=gmail
 * MAIL_HOST=smtp.gmail.com
 * MAIL_PORT=587
 * MAIL_ENCRYPTION=tls
 * MAIL_USERNAME=your-email@gmail.com
 * MAIL_PASSWORD=your-app-password (NOT your actual Gmail password)
 * MAIL_FROM_ADDRESS=your-email@gmail.com
 * MAIL_FROM_NAME=Your App Name
 */

// ============================
// Custom SMTP Server Example
// ============================
/*
 * For Custom SMTP (e.g., SendGrid, AWS SES):
 * 
 * .env example:
 * MAIL_DRIVER=smtp
 * MAIL_HOST=smtp.sendgrid.net
 * MAIL_PORT=587
 * MAIL_ENCRYPTION=tls
 * MAIL_USERNAME=apikey
 * MAIL_PASSWORD=SG.xxxxxxxxxxxxxxxxxxx (SendGrid API key)
 * MAIL_FROM_ADDRESS=noreply@yourdomain.com
 * MAIL_FROM_NAME=Your App Name
 */

return [
    'driver' => MAIL_DRIVER,
    'host' => SMTP_HOST,
    'port' => SMTP_PORT,
    'encryption' => SMTP_ENCRYPTION,
    'username' => SMTP_USERNAME,
    'password' => SMTP_PASSWORD,
    'from' => [
        'address' => MAIL_FROM_ADDRESS,
        'name' => MAIL_FROM_NAME,
    ],
    'debug' => EMAIL_DEBUG,
    'log_dir' => EMAIL_LOG_DIR,
];
