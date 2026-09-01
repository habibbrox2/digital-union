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
$helperPath = __DIR__ . '/../helpers';
if (is_dir($helperPath)) {
    foreach (glob($helperPath . '/*.php') as $file) {
        if (basename($file) !== '.htaccess' && is_file($file)) {
            require_once $file;
        }
    }
}
