<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/Services/HomeService.php';
global $mysqli;

$homeService = new HomeService($mysqli);

$slugs = ['chairman', 'secretary', 'computer_operator', 'member', 'village_police', 'udc'];

foreach ($slugs as $slug) {
    $data = $homeService->getEmployeeData($slug);
    echo "$slug: " . count($data) . " unions\n";
    foreach (array_slice($data, 0, 2) as $union) {
        echo "  - " . $union['union_name'] . " (" . count($union['persons']) . " persons)\n";
    }
    echo "\n";
}
