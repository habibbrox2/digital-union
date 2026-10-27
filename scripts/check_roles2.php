<?php
require __DIR__ . '/../config/db.php';
global $mysqli;

$result = $mysqli->query("DESCRIBE roles");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}

echo "\n--- All roles ---\n";
$result = $mysqli->query("SELECT * FROM roles");
while ($row = $result->fetch_assoc()) {
    print_r($row);
    echo "\n";
}
