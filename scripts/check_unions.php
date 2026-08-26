<?php
require __DIR__ . '/../config/db.php';
global $mysqli;

$result = $mysqli->query("DESCRIBE unions");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}

echo "\n--- Sample unions ---\n";
$result = $mysqli->query("SELECT union_id, union_name_bn FROM unions LIMIT 5");
while ($row = $result->fetch_assoc()) {
    echo $row['union_id'] . " | " . $row['union_name_bn'] . "\n";
}
