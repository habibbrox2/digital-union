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

if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        if (empty($password)) {
            return false;
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        if (empty($password) || empty($hash)) {
            return false;
        }
        return password_verify($password, $hash);
    }
}

if (!function_exists('hash_password')) {
    function hash_password($password) {
        return hashPassword($password);
    }
}

if (!function_exists('verify_password')) {
    function verify_password($password, $hash) {
        return verifyPassword($password, $hash);
    }
}

// ==================== SANITIZATION ====================

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map('sanitizeInput', $input);
        }
        
        if (!is_string($input)) {
            return $input;
        }
        
        // Remove HTML/script tags
        $input = strip_tags($input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Convert special characters to HTML entities
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        return $input;
    }
}

if (!function_exists('sanitize_input')) {
    function sanitize_input($data = null) {
        if ($data === null) {
            $data = $_POST;
        }
        
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = sanitizeInput($value);
        }
        
        return $sanitized;
    }
}

if (!function_exists('si')) {
    function si($str) {
        return sanitizeInput($str);
    }
}

// ==================== VALIDATION ====================

if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        if (empty($email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validatePhoneNumber')) {
    function validatePhoneNumber($phone) {
        if (empty($phone)) {
            return false;
        }
        
        // Remove spaces and dashes
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        
        // Bangladesh phone number pattern
        if (preg_match('/^(01|88?01)[0-9]{8}$/', $phone)) {
            return true;
        }
        
        return false;
    }
}

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

// ==================== CLIENT IP ====================

if (!function_exists('getClientIP')) {
    function getClientIP() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
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

if (!function_exists('processForm')) {
    function processForm(array $postData) {
        $csrfToken = $postData['csrf_token'] ?? null;
        if (!verifyCSRFToken($csrfToken)) {
            return false;
        }
        
        unset($postData['csrf_token']);
        return array_map('sanitizeInput', $postData);
    }
}
