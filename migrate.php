<?php

/**
 * Database Migration Runner - Simple Safe Version
 * 
 * Usage: php migrate.php
 */

declare(strict_types=1);

// Load environment and config
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    echo "❌ Database connection failed!\n";
    exit(1);
}

echo "
╔════════════════════════════════════════════════╗
║     DATABASE MIGRATION - LGDHAKA               ║
╚════════════════════════════════════════════════╝

📊 Database: " . DB_NAME . " @ " . DB_HOST . "
";

// Check current database status
$tablesResult = $mysqli->query("SHOW TABLES");
$tableCount = $tablesResult ? $tablesResult->num_rows : 0;

$applicationsResult = $mysqli->query("SELECT COUNT(*) as count FROM applications");
$appCount = $applicationsResult ? $applicationsResult->fetch_assoc()['count'] : 0;

echo "
📈 Current Status:
   Tables: $tableCount
   Application Records: $appCount
";

// Run only safe UPDATE queries from database_migration_safe.sql
$migrations = [
    "UPDATE `applications` SET `applicant_phone` = NULL WHERE `applicant_phone` IN ('', '0', '11111111111111', '00000000000')",
    "UPDATE `applications` SET `birth_date` = NULL WHERE `birth_date` = '0000-00-00'",
];

echo "
🔧 Running Migration Queries...
";

$successCount = 0;
$errorCount = 0;

foreach ($migrations as $index => $query) {
    if ($mysqli->query($query)) {
        $successCount++;
        $affectedRows = $mysqli->affected_rows;
        echo "   ✅ Migration " . ($index + 1) . ": $affectedRows row(s) updated\n";
    } else {
        $errorCount++;
        echo "   ❌ Migration " . ($index + 1) . ": " . $mysqli->error . "\n";
    }
}

// Display final status
$applicationsResult2 = $mysqli->query("SELECT COUNT(*) as count FROM applications");
$appCount2 = $applicationsResult2 ? $applicationsResult2->fetch_assoc()['count'] : 0;

echo "
╔════════════════════════════════════════════════╗
✅ Migration Complete!
   Successful: $successCount
   Failed: $errorCount
   Total Records: $appCount2
╚════════════════════════════════════════════════╝
";

$mysqli->close();
