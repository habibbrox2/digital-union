<?php
require __DIR__ . '/../config/db/db.php';
global $mysqli;

$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    echo $row[0] . "\n";
}
