<?php
require __DIR__ . '/../config/db.php';
global $mysqli;

$result = $mysqli->query("DESCRIBE users");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}

echo "\n--- Role counts ---\n";
$result = $mysqli->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role ORDER BY cnt DESC");
while ($row = $result->fetch_assoc()) {
    echo $row['role'] . " | " . $row['cnt'] . "\n";
}
