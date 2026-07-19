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

if (!function_exists('validateURL')) {
    function validateURL($url) {
        if (empty($url)) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

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
        if ($key === null) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'default-key-change-me';
        }
        
        $method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
}

if (!function_exists('decryptData')) {
    function decryptData($data, $key = null) {
        if ($key === null) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'default-key-change-me';
        }
        
        $method = 'AES-256-CBC';
        $data = base64_decode($data);
        $iv = substr($data, 0, openssl_cipher_iv_length($method));
        $encrypted = substr($data, openssl_cipher_iv_length($method));
        
        return openssl_decrypt($encrypted, $method, $key, 0, $iv);
    }
}

// ==================== SECURITY HEADERS ====================

if (!function_exists('setSecurityHeaders')) {
    function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
}

// ==================== LEGACY COMPATIBILITY ====================
// @codebuff-processForm: processForm() was removed. It was never loaded in production
// (helpers/security.php is not required anywhere). The /csrf route in config/routes.php
// now uses sanitizeRequest() from config/csrf.php directly.
