<?php

/**

 * Secure Server Configuration File

 * Author: Hr Habib

 * Last Updated: 2025-07-25

 * Updated: 2024 - Environment-based configuration

 */



declare(strict_types=1);

date_default_timezone_set('Asia/Dhaka');



// Prevent direct access

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {

    renderError(403, 'Access Denied');

}



// Load environment variables from .env file

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');

if (file_exists(__DIR__ . '/../.env')) {

    $dotenv->load();

}



// Database Configuration (from .env)

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');

define('DB_PORT', $_ENV['DB_PORT'] ?? 3306);

define('DB_USER', $_ENV['DB_USER'] ?? 'root');

define('DB_PASS', $_ENV['DB_PASS'] ?? '');

define('DB_NAME', $_ENV['DB_NAME'] ?? 'lgdhaka');

define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');



/**
 * Safely create a directory and all its parent directories.
 *
 * @param string $dirPath Path of the directory to create
 * @param int $permissions Directory permissions (default 0775)
 * @throws RuntimeException if creation fails
 */
function ensureDirectory(string $dirPath, int $permissions = 0775): void {
    if (!is_dir($dirPath)) {
        if (!mkdir($dirPath, $permissions, true) && !is_dir($dirPath)) {
            throw new RuntimeException("Failed to create directory: $dirPath");
        }
    }
}

// Define base paths
define('BASE_PATH', dirname(__DIR__));
define('STORAGE_DIR', BASE_PATH . DIRECTORY_SEPARATOR . 'storage'); 
define('CACHE_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'cache');  
define('TEMP_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'tmp'); 

// Ensure directories exist
foreach ([STORAGE_DIR, CACHE_DIR, TEMP_DIR] as $dir) {
    ensureDirectory($dir);
}





// Detect protocol & host automatically

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

define('SITE_URL', $protocol . '://' . $host);



// Start secure session

if (session_status() === PHP_SESSION_NONE) {

    session_start([

        'cookie_httponly' => true,

        'cookie_secure'   => isset($_SERVER['HTTPS']),

        'use_strict_mode' => true,

    ]);

}



// ============================

// Encryption Configuration 🔐

// ============================



define('ENCRYPTION_KEY', '@#+AriyanAhmedAlif_2025SecureKey!%');

define('ENCRYPTION_METHOD', 'AES-256-CBC');

