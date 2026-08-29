<?php
/**
 * scripts/migrate_fcm_tokens.php
 * 
 * Creates the fcm_tokens table for Firebase Cloud Messaging.
 * Run once: php scripts/migrate_fcm_tokens.php
 */

// Load environment variables
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->load();

// Database connection using env vars directly
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'lgdhaka';
$dbCharset = getenv('DB_CHARSET') ?: 'utf8mb4';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    echo "Error: Database connection failed: " . $mysqli->connect_error . "\n";
    echo "Trying to create database '$dbName'...\n";
    
    // Try without database name to create it
    $mysqliNoDb = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqliNoDb->connect_error) {
        echo "Error: Cannot connect to MySQL: " . $mysqliNoDb->connect_error . "\n";
        exit(1);
    }
    
    $mysqliNoDb->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysqliNoDb->close();
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        echo "Error: Still cannot connect: " . $mysqli->connect_error . "\n";
        exit(1);
    }
}

$mysqli->set_charset($dbCharset);
echo "Connected to database: $dbName\n";

function migrateFcmTokens($mysqli) {
    $sql = "
    CREATE TABLE IF NOT EXISTS fcm_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(128) NOT NULL,
        fcm_token VARCHAR(512) NOT NULL,
        session_sig VARCHAR(128) DEFAULT NULL,
        user_type ENUM('visitor', 'admin') DEFAULT 'visitor',
        user_id INT DEFAULT NULL,
        device_info TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_fcm_token (fcm_token(255)),
        KEY idx_session_id (session_id),
        KEY idx_user_type_id (user_type, user_id),
        KEY idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($mysqli->query($sql)) {
        echo "✓ fcm_tokens table created successfully\n";
        return true;
    } else {
        echo "✗ Error creating table: " . $mysqli->error . "\n";
        return false;
    }
}

// Run migration
migrateFcmTokens($mysqli);
$mysqli->close();
echo "Migration complete.\n";
