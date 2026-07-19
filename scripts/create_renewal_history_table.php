<?php
/**
 * Script to ensure license_renewal_history table exists with the correct schema.
 * Expected columns (from ApplicationManager::trackRenewalHistory):
 *   application_id, old_sonod_number, old_fiscal_year, old_expiry_date,
 *   renewal_date, renewed_by, renewal_notes, created_at
 *
 * Run: php scripts/create_renewal_history_table.php
 */

require_once __DIR__ . '/../config/config.php';

// Try DB_NAME first, then scan for alternatives if it fails
$dbName = DB_NAME;
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($conn->connect_error) {
    // Fallback: scan all databases for a name containing 'lgdhaka'
    $tmpConn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $result = $tmpConn->query("SHOW DATABASES LIKE '%lgdhaka%'");
    $found = [];
    while ($row = $result->fetch_array()) {
        $found[] = $row[0];
    }
    if (!empty($found)) {
        $dbName = $found[0];
        echo "ℹ️  Using database: '$dbName' (auto-detected)\n";
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    } else {
        echo "❌ Could not connect to any database. Error: " . $conn->connect_error . "\n";
        exit(1);
    }
    $tmpConn->close();
}
if ($conn->connect_error) {
    echo "❌ Connection failed: " . $conn->connect_error . "\n";
    exit(1);
}
echo "✅ Connected to database '" . DB_NAME . "'\n";

// Check if table exists
$result = $conn->query("SHOW TABLES LIKE 'license_renewal_history'");
$tableExists = $result && $result->num_rows > 0;

$expectedCreateSQL = "CREATE TABLE IF NOT EXISTS `license_renewal_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `application_id` VARCHAR(100) NOT NULL,
    `old_sonod_number` VARCHAR(100) DEFAULT NULL,
    `old_fiscal_year` VARCHAR(20) DEFAULT NULL,
    `old_expiry_date` DATE DEFAULT NULL,
    `renewal_date` DATE DEFAULT NULL,
    `renewed_by` VARCHAR(100) DEFAULT NULL,
    `renewal_notes` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_application_id` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$tableExists) {
    echo "ℹ️  Table 'license_renewal_history' does not exist. Creating...\n";
    if ($conn->query($expectedCreateSQL)) {
        echo "✅ Table created successfully.\n";
    } else {
        echo "❌ Failed to create table: " . $conn->error . "\n";
        exit(1);
    }
} else {
    echo "ℹ️  Table 'license_renewal_history' exists. Checking columns...\n";
    
    // Get existing columns
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `license_renewal_history`");
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = $row;
    }
    
    $expectedColumns = [
        'id', 'application_id', 'old_sonod_number', 'old_fiscal_year',
        'old_expiry_date', 'renewal_date', 'renewed_by', 'renewal_notes', 'created_at'
    ];
    
    $missingColumns = array_diff($expectedColumns, array_keys($columns));
    $extraColumns = array_diff(array_keys($columns), $expectedColumns);
    
    if (empty($missingColumns)) {
        echo "✅ All expected columns are present.\n";
    } else {
        echo "⚠️  Missing columns: " . implode(', ', $missingColumns) . "\n";
        
        // Safely add missing columns with ALTER (preserves existing data)
        $columnDefs = [
            'application_id' => 'VARCHAR(100) NOT NULL',
            'old_sonod_number' => 'VARCHAR(100) DEFAULT NULL',
            'old_fiscal_year' => 'VARCHAR(20) DEFAULT NULL',
            'old_expiry_date' => 'DATE DEFAULT NULL',
            'renewal_date' => 'DATE DEFAULT NULL',
            'renewed_by' => 'VARCHAR(100) DEFAULT NULL',
            'renewal_notes' => 'TEXT DEFAULT NULL',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        ];
        
        $addedAny = false;
        foreach ($missingColumns as $col) {
            if ($col === 'id') {
                echo "⚠️  Skipping 'id' (PK) — cannot add via ALTER. Consider recreating manually.\n";
                continue;
            }
            $def = $columnDefs[$col] ?? 'VARCHAR(255) DEFAULT NULL';
            $sql = "ALTER TABLE `license_renewal_history` ADD COLUMN `$col` $def";
            if ($conn->query($sql)) {
                echo "✅ Added column: $col\n";
                $addedAny = true;
            } else {
                echo "❌ Failed to add column $col: " . $conn->error . "\n";
            }
        }
        
        if (!$addedAny) {
            echo "⚠️  Could not add missing columns. Data preserved.\n";
        }
    }
    
    if (!empty($extraColumns)) {
        echo "ℹ️  Extra columns (ignored by code): " . implode(', ', $extraColumns) . "\n";
    }
}

// Verify the table works — try a select only (insert may have FK constraints)
echo "\n🔍 Verifying table is usable...\n";

$result = $conn->query("SELECT COUNT(*) as cnt FROM license_renewal_history");
if ($result) {
    $row = $result->fetch_assoc();
    echo "✅ Table is readable. Current rows: " . $row['cnt'] . "\n";
    
    // Show table structure
    echo "\n📋 Table structure:\n";
    $columns = $conn->query("SHOW COLUMNS FROM license_renewal_history");
    echo str_pad('Field', 22) . str_pad('Type', 20) . str_pad('Null', 8) . "Key\n";
    echo str_repeat('-', 55) . "\n";
    while ($col = $columns->fetch_assoc()) {
        echo str_pad($col['Field'], 22) . str_pad($col['Type'], 20) . str_pad($col['Null'] === 'NO' ? 'NO' : 'YES', 8) . ($col['Key'] ?: '') . "\n";
    }
    
    // Show foreign keys
    $fks = $conn->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                          FROM information_schema.KEY_COLUMN_USAGE 
                          WHERE TABLE_SCHEMA = DATABASE() 
                          AND TABLE_NAME = 'license_renewal_history' 
                          AND REFERENCED_TABLE_NAME IS NOT NULL");
    if ($fks && $fks->num_rows > 0) {
        echo "\n🔗 Foreign keys:\n";
        while ($fk = $fks->fetch_assoc()) {
            echo "  • {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} → {$fk['REFERENCED_TABLE_NAME']}({$fk['REFERENCED_COLUMN_NAME']})\n";
        }
    }
} else {
    echo "❌ Table verification failed: " . $conn->error . "\n";
    exit(1);
}

$conn->close();
echo "\n🎉 Table 'license_renewal_history' is ready for use.\n";
