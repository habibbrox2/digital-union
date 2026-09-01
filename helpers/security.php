<?php

/**
 * Security Helper - Production Ready
 * CSRF, Password, Sanitization, and Validation Functions
 * function_exists() guards included
 */

// ==================== SESSION & CSRF ====================
// Using csrf.php functions: generateCsrfToken() and verifyCsrfToken()
// These are globally available from config/csrf.php



// ==================== PASSWORD SECURITY ====================

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        if (empty($password) || empty($hash)) {
            return false;
        }
        return password_verify($password, $hash);
    }
}

if (!function_exists('verify_password')) {
    function verify_password($password, $hash) {
        return verifyPassword($password, $hash);
    }
}

// ==================== SANITIZATION ====================

// @codebuff-sanitize: sanitize_input, sanitizeInput, and si have been moved to
// modules/Services/SanitizationService.php (invoked via config/functions.php thin wrapper).
// The functions below were never loaded in production (helpers/security.php is not required anywhere).
// See SanitizationService for the consolidated single source of truth.

// ==================== VALIDATION ====================

// NOTE: validateURL() is defined in helpers/validator.php with the data-array
// signature used by the Validator class and validateBatch().
// For direct single-URL validation, use filter_var($url, FILTER_VALIDATE_URL).

if (!function_exists('validatePassword')) {
    function validatePassword($password) {
        if (empty($password)) {
            return ['valid' => false, 'error' => 'Password is required'];
        }
        
        if (strlen($password) < 8) {
            return ['valid' => false, 'error' => 'Password must be at least 8 characters'];
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'error' => 'Password must contain uppercase letter'];
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'error' => 'Password must contain lowercase letter'];
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'error' => 'Password must contain number'];
        }
        
        return ['valid' => true];
    }
}

// ==================== ENCRYPTION/DECRYPTION ====================
if (!function_exists('encryptData')) {
    function encryptData($data, $key = null) {
        // 🔒 Delegate to CryptManager for HMAC-authenticated encryption.
        // Falls back to unauthenticated AES-256-CBC only if CryptManager is unavailable.
        if (class_exists('CryptManager')) {
            $crypt = new CryptManager($key ?? (defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : random_bytes(32)));
            return $crypt->encrypt($data);
        }

        // Fallback: AES-256-CBC WITHOUT authentication (legacy — migrate away)
        if ($key === null) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : random_bytes(32);
        }
        $method = 'AES-256-CBC';
        $iv = random_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
}


if (!function_exists('decryptData')) {
    function decryptData($data, $key = null) {
        // 🔒 Delegate to CryptManager for HMAC-verified decryption.
        if (class_exists('CryptManager')) {
            $crypt = new CryptManager($key ?? (defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : ''));
            return $crypt->decrypt($data);
        }

        // Fallback: AES-256-CBC WITHOUT authentication (legacy — migrate away)
        if ($key === null) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
        }
        $method = 'AES-256-CBC';
        $decoded = base64_decode($data, true);
        if ($decoded === false) return false;
        $ivLength = openssl_cipher_iv_length($method);
        if (strlen($decoded) < $ivLength) return false;
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);
        return openssl_decrypt($encrypted, $method, $key, OPENSSL_RAW_DATA, $iv);
    }
}

// ==================== SECURITY HEADERS ====================
if (!function_exists('setSecurityHeaders')) {
    function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        // X-XSS-Protection removed — deprecated in modern browsers, replaced by CSP
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // 🔒 Content Security Policy — restrict resource loading to same origin
        // Adjust 'script-src' and 'style-src' as needed for external CDNs
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self';");
    }
}

// ==================== LEGACY COMPATIBILITY ====================
// @codebuff-processForm: processForm() was removed. It was never loaded in production
// (helpers/security.php is not required anywhere). The /csrf route in config/routes.php
// now uses sanitizeRequest() from config/csrf.php directly.
