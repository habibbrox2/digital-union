<?php
require_once __DIR__ . '/../config/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'tdhuedhn_lgdhaka');
if ($conn->connect_error) {
    echo "Connection error: " . $conn->connect_error . "\n";
    exit(1);
}

// Find geo-related tables - use separate queries
$patterns = ['%division%', '%district%', '%upazila%', '%geo%'];
echo "=== Geo-related tables ===\n";
foreach ($patterns as $pattern) {
    $result = $conn->query("SHOW TABLES LIKE '$pattern'");
    while ($row = $result->fetch_array()) {
        echo "Table: " . $row[0] . "\n";
    }
}

// Check users table for geo columns
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'division_id'");
echo "\n=== Users table geo columns ===\n";
while ($row = $result->fetch_assoc()) {
    echo "division_id: " . $row['Type'] . "\n";
}
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'district_id'");
while ($row = $result->fetch_assoc()) {
    echo "district_id: " . $row['Type'] . "\n";
}
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'upazila_id'");
while ($row = $result->fetch_assoc()) {
    echo "upazila_id: " . $row['Type'] . "\n";
}
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'union_id'");
while ($row = $result->fetch_assoc()) {
    echo "union_id: " . $row['Type'] . "\n";
}

// Check unions table - it might have division/district/upazila references
$result = $conn->query("SHOW COLUMNS FROM unions");
echo "\n=== Unions table columns ===\n";
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " " . $row['Type'] . "\n";
}

$conn->close();
