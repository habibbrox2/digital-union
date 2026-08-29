<?php
$_SERVER['HTTPS'] = '';
$_SERVER['HTTP_HOST'] = 'lgdhaka.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/../public';
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env manually (dotENV may fail on some configs)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\x00\x0B\"");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

echo "=== 1. Find Trade Applications ===\n";
$res = $mysqli->query("SELECT application_id, certificate_type, sonod_number, status FROM applications WHERE certificate_type='trade' ORDER BY id DESC LIMIT 5");
$tradeApps = [];
while ($row = $res->fetch_assoc()) {
    $tradeApps[] = $row;
    echo "  ID: {$row['application_id']} | Sonod: {$row['sonod_number']} | Status: {$row['status']}\n";
}

if (empty($tradeApps)) {
    echo "No trade applications found. Creating test data...\n";
    $testId = 'TEST_TRADE_' . time();
    $mysqli->query("INSERT INTO applications (application_id, certificate_type, status, union_id, name_bn, name_en) VALUES ('$testId', 'trade', 'pending', 1, 'টেস্ট', 'Test')");
    $tradeApps[] = ['application_id' => $testId, 'certificate_type' => 'trade', 'sonod_number' => '', 'status' => 'pending'];
    echo "  Created: $testId\n";
}

$testApp = $tradeApps[0];
$appId = $testApp['application_id'];

echo "\n=== 2. Check Current Business Meta ===\n";
$stmt = $mysqli->prepare("SELECT license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge, total_fee, business_type_id, fiscal_year FROM business_meta WHERE application_id = ?");
$stmt->bind_param('s', $appId);
$stmt->execute();
$meta = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($meta) {
    echo "  License Fee: {$meta['license_fee']}\n";
    echo "  VAT: {$meta['vat_amount']}\n";
    echo "  Occupation Tax: {$meta['occupation_tax']}\n";
    echo "  Business Type ID: {$meta['business_type_id']}\n";
    echo "  Fiscal Year: {$meta['fiscal_year']}\n";
} else {
    echo "  No business meta found. Creating...\n";
    $mysqli->query("INSERT INTO business_meta (application_id, license_fee, vat_amount, occupation_tax, business_type_id) VALUES ('$appId', 100, 15, 50, 1)");
    echo "  Created with license_fee=100\n";
}

echo "\n=== 3. Test Fee Update Endpoint ===\n";
$_POST = [
    'license_fee' => '250',
    'vat_amount' => '37.50',
    'occupation_tax' => '75',
    'income_tax' => '0',
    'signboard_tax' => '0',
    'surcharge' => '0',
    'total_fee' => '362.50',
    'fiscal_year' => '2025-2026',
    'ownership_type_id' => '1',
    'business_type_id' => '1',
];

// Simulate the updateTradeFees method
require_once __DIR__ . '/../modules/Services/ApplicationService.php';
require_once __DIR__ . '/../models/ApplicationManager.php';
require_once __DIR__ . '/../models/BusinessOwnershipType.php';

require_once __DIR__ . '/../config/functions.php';

$appManager = new ApplicationManager($mysqli);
$service = new ApplicationService($mysqli, $appManager);

$result = $service->updateTradeFees($appId, $_POST, 1);
echo "  Result: " . json_encode($result) . "\n";

echo "\n=== 4. Verify Updated Business Meta ===\n";
$stmt = $mysqli->prepare("SELECT license_fee, vat_amount, occupation_tax, total_fee, fiscal_year FROM business_meta WHERE application_id = ?");
$stmt->bind_param('s', $appId);
$stmt->execute();
$updated = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($updated) {
    echo "  License Fee: {$updated['license_fee']} (expected: 250)\n";
    echo "  VAT: {$updated['vat_amount']} (expected: 37.50)\n";
    echo "  Occupation Tax: {$updated['occupation_tax']} (expected: 75)\n";
    echo "  Total Fee: {$updated['total_fee']} (expected: 362.50)\n";
    echo "  Fiscal Year: {$updated['fiscal_year']} (expected: 2025-2026)\n";
    
    $pass = ($updated['license_fee'] == 250 && $updated['occupation_tax'] == 75);
    echo "\n  " . ($pass ? "✅ business_meta UPDATE PASSED" : "❌ business_meta UPDATE FAILED") . "\n";
}

echo "\n=== 5. Verify Business Type Table Synced ===\n";
$btRes = $mysqli->query("SELECT license_fee, vat_amount, occupation_tax FROM business_type WHERE id = 1");
$bt = $btRes->fetch_assoc();
if ($bt) {
    echo "  License Fee: {$bt['license_fee']} (expected: 250)\n";
    echo "  VAT: {$bt['vat_amount']} (expected: 37.50)\n";
    echo "  Occupation Tax: {$bt['occupation_tax']} (expected: 75)\n";
    
    $pass2 = ($bt['license_fee'] == 250 && $bt['occupation_tax'] == 75);
    echo "\n  " . ($pass2 ? "✅ business_type SYNC PASSED" : "❌ business_type SYNC FAILED") . "\n";
} else {
    echo "  No business_type with id=1 found\n";
}

echo "\n=== 6. Test Endpoint via HTTP ===\n";
// Simulate POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

$ch = curl_init('http://lgdhaka.local/applications/trade/update-fees/' . $appId);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($_POST));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "  HTTP Status: $httpCode\n";
echo "  Response: $response\n";

$result2 = json_decode($response, true);
if ($result2 && $result2['status'] === 'success') {
    echo "\n  ✅ HTTP ENDPOINT PASSED\n";
} else {
    echo "\n  ❌ HTTP ENDPOINT FAILED\n";
}

echo "\n=== DONE ===\n";
