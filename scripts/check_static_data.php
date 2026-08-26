<?php
$data = require 'D:/xampp-server/lgdhaka/storage/data/employee_data.php';
foreach ($data as $slug => $items) {
    echo $slug . ': ' . count($items) . ' items' . "\n";
}
