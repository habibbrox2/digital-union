<?php
require __DIR__ . '/../config/db.php';
global $mysqli;

echo "--- Roles ---\n";
$result = $mysqli->query("SELECT * FROM roles");
while ($row = $result->fetch_assoc()) {
    echo $row['role_id'] . " | " . $row['name_en'] . " | " . ($row['name_bn'] ?? '') . "\n";
}

echo "\n--- User role counts ---\n";
$result = $mysqli->query("SELECT role_id, COUNT(*) as cnt FROM users GROUP BY role_id ORDER BY cnt DESC");
while ($row = $result->fetch_assoc()) {
    echo $row['role_id'] . " | " . $row['cnt'] . "\n";
}

echo "\n--- Sample users with role_id=6 or similar ---\n";
$result = $mysqli->query("SELECT user_id, name_bn, designation, union_id, role_id FROM users WHERE role_id = 5 OR role_id = 6 OR role_id = 7 LIMIT 10");
while ($row = $result->fetch_assoc()) {
    echo $row['user_id'] . " | " . $row['name_bn'] . " | " . $row['designation'] . " | " . $row['union_id'] . " | " . $row['role_id'] . "\n";
}
