<?php
/**
 * Autoload all classes and helpers
 * Production Ready Configuration
 */

// Autoload all classes from classes directory
$classPath = __DIR__ . '/../classes';
if (is_dir($classPath)) {
    foreach (glob($classPath . '/*.php') as $file) {
        if (basename($file) !== '.htaccess' && is_file($file)) {
            require_once $file;
        }
    }
}

// Autoload all helpers from helpers directory
// Priority order: v2 versions first, then legacy
$helperPath = __DIR__ . '/../helpers';
if (is_dir($helperPath)) {
    // Load v2 (new production versions) first
    $v2Files = [
        'api_response_v2.php',
        'security_v2.php',
        'validator.php'
    ];
    
    foreach ($v2Files as $file) {
        $filepath = $helperPath . '/' . $file;
        if (file_exists($filepath)) {
            require_once $filepath;
        }
    }
    
    // Load legacy helpers
    foreach (glob($helperPath . '/*.php') as $file) {
        if (basename($file) !== '.htaccess' && is_file($file) && !in_array(basename($file), $v2Files)) {
            require_once $file;
        }
    }
}
