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

echo "\n=== 2. Snapshot Current Business Meta (before) ===\n";
$stmt = $mysqli->prepare("SELECT * FROM business_meta WHERE application_id = ?");
$stmt->bind_param('s', $appId);
$stmt->execute();
$metaBefore = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($metaBefore) {
    echo "  License Fee: {$metaBefore['license_fee']}\n";
    echo "  Business Name BN: {$metaBefore['business_name_bn']}\n";
    echo "  Business Type ID: {$metaBefore['business_type_id']}\n";
    echo "  Fiscal Year: {$metaBefore['fiscal_year']}\n";
} else {
    echo "  No business meta row for this application (that's fine — business_meta must stay absent).\n";
}

// Business type to test with: the application's own type, else the first row
$btId = (int)($metaBefore['business_type_id'] ?? 0);
if (!$btId) {
    $btRes = $mysqli->query("SELECT id FROM business_type ORDER BY id ASC LIMIT 1");
    $btId = (int)($btRes->fetch_assoc()['id'] ?? 0);
}
if (!$btId) {
    echo "  No business_type rows exist — cannot run test.\n";
    exit(1);
}

$stmt = $mysqli->prepare("SELECT business_name_bn, business_name_en, license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge FROM business_type WHERE id = ?");
$stmt->bind_param('i', $btId);
$stmt->execute();
$btBefore = $stmt->get_result()->fetch_assoc();
$stmt->close();
echo "  Testing with business_type id={$btId} ({$btBefore['business_name_bn']})\n";
echo "  Before fees: license={$btBefore['license_fee']}, vat={$btBefore['vat_amount']}, occ_tax={$btBefore['occupation_tax']}\n";

echo "\n=== 3. Test Fee Update Endpoint (fees + business_type_id ONLY) ===\n";
$_POST = [
    'license_fee' => '250',
    'vat_amount' => '37.50',
    'occupation_tax' => '75',
    'income_tax' => '0',
    'signboard_tax' => '0',
    'surcharge' => '0',
    'business_type_id' => (string)$btId,
];

// Simulate the updateTradeFees method
require_once __DIR__ . '/../modules/Services/ApplicationService.php';
require_once __DIR__ . '/../models/ApplicationManager.php';
require_once __DIR__ . '/../models/BusinessOwnershipType.php';

require_once __DIR__ . '/../config/functions.php';

$appManager = new ApplicationManager($mysqli);
$service = new ApplicationService($mysqli, $appManager);

$result = $service->updateTradeFees($appId, $_POST, 1);
echo "  Result: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 4. Verify business_meta is UNCHANGED ===\n";
$stmt = $mysqli->prepare("SELECT * FROM business_meta WHERE application_id = ?");
$stmt->bind_param('s', $appId);
$stmt->execute();
$metaAfter = $stmt->get_result()->fetch_assoc();
$stmt->close();

$metaUnchanged = ($metaBefore === $metaAfter);
if ($metaBefore === null && $metaAfter === null) {
    $metaUnchanged = true; // absent before and after — correct
}
echo ($metaUnchanged ? "  ✅ business_meta row unchanged (business name intact)" : "  ❌ business_meta row CHANGED — regression!") . "\n";
if (!$metaUnchanged) {
    foreach ($metaBefore as $k => $v) {
        if (($metaAfter[$k] ?? null) !== $v) {
            echo "    Diff '{$k}': '{$v}' -> '" . ($metaAfter[$k] ?? '(null)') . "'\n";
        }
    }
}

echo "\n=== 5. Verify business_type fee columns updated, name untouched ===\n";
$stmt = $mysqli->prepare("SELECT business_name_bn, business_name_en, license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge FROM business_type WHERE id = ?");
$stmt->bind_param('i', $btId);
$stmt->execute();
$btAfter = $stmt->get_result()->fetch_assoc();
$stmt->close();

$pass2 = ($btAfter['license_fee'] == 250 && $btAfter['occupation_tax'] == 75);
$nameUnchanged = ($btAfter['business_name_bn'] === $btBefore['business_name_bn'] && $btAfter['business_name_en'] === $btBefore['business_name_en']);
echo "  License Fee: {$btAfter['license_fee']} (expected: 250)\n";
echo "  VAT: {$btAfter['vat_amount']} (expected: 37.50)\n";
echo "  Occupation Tax: {$btAfter['occupation_tax']} (expected: 75)\n";
echo ($pass2 ? "  ✅ business_type FEES updated" : "  ❌ business_type fees NOT updated") . "\n";
echo ($nameUnchanged ? "  ✅ business_type names untouched" : "  ❌ business_type names changed — regression!") . "\n";

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
