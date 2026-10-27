<?php
require __DIR__ . '/../config/db.php';
global $mysqli;

$result = $mysqli->query("SELECT user_id, name_bn, designation, phone_number, email, union_id, profile_picture_url FROM users WHERE role_id = 4 AND is_deleted = 0 LIMIT 5");
while ($row = $result->fetch_assoc()) {
    print_r($row);
    echo "\n";
}

echo "\n--- Count ---\n";
$result = $mysqli->query("SELECT COUNT(*) as cnt FROM users WHERE role_id = 4 AND is_deleted = 0");
$row = $result->fetch_assoc();
echo "Ward members: " . $row['cnt'] . "\n";
