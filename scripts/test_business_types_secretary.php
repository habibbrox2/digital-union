<?php
/**
 * Test: Simulate secretary user accessing /settings/business-types
 * This mimics what happens when a secretary (union_id = 4) loads the page.
 * The previous error was: "Unknown column 'bt.union_id' in 'where clause'" 
 * caused by the count query missing the `bt` alias.
 */

// Load environment
require_once __DIR__ . '/../config/config.php';

// Auto-detect actual database name (may differ from fallback, e.g. tdhuedhn_lgdhaka)
$dbName = DB_NAME;
if (!class_exists('Dotenv\\Dotenv') && file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), 'DB_NAME=')) {
            $dbName = trim(substr(trim($line), 8));
            break;
        }
    }
}

// Try auto-discovery via SHOW DATABASES
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $testConn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if (!$testConn->connect_error) {
        $dbResult = $testConn->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '%lgdhaka%'");
        if ($dbResult && $dbResult->num_rows > 0) {
            $dbName = $dbResult->fetch_assoc()['SCHEMA_NAME'];
            echo "[INFO] Auto-detected database: {$dbName}\n";
        }
        $testConn->close();
    }
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($mysqli->connect_error) {
    die("CONNECT_ERROR: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

echo "Connected to database: {$dbName}\n";

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../models/ErrorHandler.php';
ErrorHandler::init(__DIR__ . '/../storage/logs/error.log', true);
require_once __DIR__ . '/../config/error.php';
require_once __DIR__ . '/../models/BusinessOwnershipType.php';

echo "============================================\n";
echo "  Testing /settings/business-types for Secretary\n";
echo "============================================\n\n";

// Use a known union_id (secretary belongs to union_id=4)
$testUnionId = 4;

$businessOwnership = new BusinessOwnershipType($mysqli);

echo "▶ Test 1: fetchBusinessTypes() with unionId={$testUnionId} (no search)\n";
try {
    $result = $businessOwnership->fetchBusinessTypes(
        page: 1,
        limit: 10,
        search: '',
        sort: 'id',
        order: 'DESC',
        unionId: $testUnionId
    );

    $count = count($result['businessTypes']);
    echo "  ✅ SUCCESS - Returned {$count} records out of {$result['totalRecords']} total\n";
    echo "  Total pages: {$result['totalPages']}\n";
    echo "  Current page: {$result['currentPage']}\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n▶ Test 2: fetchBusinessTypes() with search term\n";
try {
    $result = $businessOwnership->fetchBusinessTypes(
        page: 1,
        limit: 10,
        search: 'ট্রেড',
        sort: 'id',
        order: 'DESC',
        unionId: $testUnionId
    );

    $count = count($result['businessTypes']);
    echo "  ✅ SUCCESS - Search returned {$count} records\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n▶ Test 3: fetchBusinessTypes() - superadmin (no union filter)\n";
try {
    $result = $businessOwnership->fetchBusinessTypes(
        page: 1,
        limit: 10,
        search: '',
        sort: 'id',
        order: 'DESC',
        unionId: null
    );

    $count = count($result['businessTypes']);
    echo "  ✅ SUCCESS - All records returned: {$result['totalRecords']} total\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n▶ Test 4: fetchBusinessTypes() with unionId=0 (orphaned)\n";
try {
    $result = $businessOwnership->fetchBusinessTypes(
        page: 1,
        limit: 10,
        search: '',
        sort: 'id',
        order: 'DESC',
        unionId: 0
    );

    $count = count($result['businessTypes']);
    echo "  ✅ SUCCESS - unionId=0 returned {$result['totalRecords']} total\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n▶ Test 5: fetchBusinessTypes() fetching ALL (limit=0)\n";
try {
    $result = $businessOwnership->fetchBusinessTypes(
        page: 1,
        limit: 0,  // fetch all
        search: '',
        sort: 'id',
        order: 'DESC',
        unionId: $testUnionId
    );

    $count = count($result['businessTypes']);
    echo "  ✅ SUCCESS - Fetch all returned {$count} records\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n============================================\n";
echo "  All tests completed!\n";
echo "============================================\n";
