<?php
/**
 * scripts/fix_image_urls.php
 * 
 * Scans the applications table for malformed applicant_photo URLs
 * and fixes them to use the correct relative path format.
 * 
 * Malformed patterns:
 *   - http://uploads/application/...  (missing domain, has protocol)
 *   - https://uploads/application/... (missing domain, has protocol)
 *   - uploads/application/...         (missing leading slash)
 * 
 * Correct format:
 *   - /uploads/application/filename.jpg
 * 
 * Usage: php scripts/fix_image_urls.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env using getenv (phpdotenv v5 default)
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'lgdhaka';
$port = getenv('DB_PORT') ?: '3306';

$mysqli = new mysqli($host, $user, $pass, $name, (int)$port);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$isDryRun = in_array('--dry-run', $argv);

echo "========================================\n";
echo "  Fix Malformed Image URLs\n";
echo ($isDryRun ? "  (DRY RUN - no changes will be made)\n" : "");
echo "========================================\n\n";

// 1. Find all rows with malformed photo URLs
$patterns = [
    ['pattern' => 'http://uploads/%',   'label' => 'http:// protocol without domain'],
    ['pattern' => 'https://uploads/%',  'label' => 'https:// protocol without domain'],
];

// Also check for paths starting with uploads/ (without leading slash)
// but we need to be careful not to match /uploads/ (correct format)
$likePatterns = [
    ['pattern' => 'http://uploads/%',   'label' => 'http:// protocol without domain'],
    ['pattern' => 'https://uploads/%',  'label' => 'https:// protocol without domain'],
];

$totalFixed = 0;
$totalSkipped = 0;

foreach ($likePatterns as $p) {
    $stmt = $mysqli->prepare("SELECT application_id, applicant_photo FROM applications WHERE applicant_photo LIKE ?");
    $stmt->bind_param('s', $p['pattern']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    
    if (empty($rows)) {
        echo "✅ No {$p['label']} URLs found\n";
        continue;
    }
    
    echo "\n🔍 Found " . count($rows) . " rows with {$p['label']}:\n";
    
    foreach ($rows as $row) {
        $old = $row['applicant_photo'];
        $new = $old;
        
        // Strip protocol + domain prefix if present
        if (preg_match('#^https?://#i', $old)) {
            // Remove protocol + anything before /uploads/
            $new = preg_replace('#^https?://[^/]*#i', '', $old);
        }
        
        // Ensure leading slash
        if (!str_starts_with($new, '/')) {
            $new = '/' . $new;
        }
        
        if ($old === $new) {
            echo "  ⏭️  {$row['application_id']}: No change needed: {$old}\n";
            $totalSkipped++;
            continue;
        }
        
        echo "  🔄 {$row['application_id']}: {$old}\n";
        echo "     → {$new}\n";
        
        if (!$isDryRun) {
            $updateStmt = $mysqli->prepare("UPDATE applications SET applicant_photo = ? WHERE application_id = ?");
            $updateStmt->bind_param('ss', $new, $row['application_id']);
            $updateStmt->execute();
            $updateStmt->close();
            echo "     ✅ Updated\n";
        } else {
            echo "     (dry run — not updated)\n";
        }
        $totalFixed++;
    }
}

// Also check for relative paths without leading slash: 'uploads/...' (not '/uploads/...')
$stmt = $mysqli->prepare("SELECT application_id, applicant_photo FROM applications WHERE applicant_photo REGEXP '^uploads/'");
$stmt->execute();
$result = $stmt->get_result();
$slashFixed = 0;

$slashRows = [];
while ($row = $result->fetch_assoc()) {
    $slashRows[] = $row;
}
$stmt->close();

if (!empty($slashRows)) {
    echo "\n🔍 Found " . count($slashRows) . " rows with missing leading slash:\n";
    foreach ($slashRows as $row) {
        $old = $row['applicant_photo'];
        $new = '/' . $old;
        echo "  🔄 {$row['application_id']}: {$old}\n";
        echo "     → {$new}\n";
        
        if (!$isDryRun) {
            $updateStmt = $mysqli->prepare("UPDATE applications SET applicant_photo = ? WHERE application_id = ?");
            $updateStmt->bind_param('ss', $new, $row['application_id']);
            $updateStmt->execute();
            $updateStmt->close();
            echo "     ✅ Updated\n";
        } else {
            echo "     (dry run — not updated)\n";
        }
        $slashFixed++;
        $totalFixed++;
    }
}

// 2. Also check the `documents` column for malformed URLs
echo "\n\n--- Checking documents column ---\n";
$stmt = $mysqli->prepare("SELECT application_id, documents FROM applications WHERE documents IS NOT NULL AND documents != '' AND documents != '[]'");
$stmt->execute();
$result = $stmt->get_result();
$docFixed = 0;

while ($row = $result->fetch_assoc()) {
    $docs = json_decode($row['documents'], true);
    if (!is_array($docs)) continue;
    
    $changed = false;
    foreach ($docs as &$doc) {
        $old = $doc;
        
        // Strip protocol + domain prefix if present
        if (preg_match('#^https?://#i', $doc)) {
            $doc = preg_replace('#^https?://[^/]*#i', '', $doc);
        }
        
        // Ensure leading slash
        if (str_starts_with($doc, 'uploads/') || str_starts_with($doc, 'documents/')) {
            $doc = '/' . $doc;
        }
        
        if ($doc !== $old) {
            $changed = true;
            echo "  🔄 {$row['application_id']}: {$old}\n";
            echo "     → {$doc}\n";
        }
    }
    unset($doc);
    
    if ($changed) {
        $newDocs = json_encode($docs, JSON_UNESCAPED_SLASHES);
        if (!$isDryRun) {
            $updateStmt = $mysqli->prepare("UPDATE applications SET documents = ? WHERE application_id = ?");
            $updateStmt->bind_param('ss', $newDocs, $row['application_id']);
            $updateStmt->execute();
            $updateStmt->close();
            echo "     ✅ Updated\n";
        } else {
            echo "     (dry run — not updated)\n";
        }
        $docFixed++;
    }
}
$stmt->close();

echo "\n========================================\n";
echo "  Summary\n";
echo "========================================\n";
echo "Photo URLs fixed: {$totalFixed}\n";
echo "  - Protocol without domain: " . ($totalFixed - $slashFixed) . "\n";
echo "  - Missing leading slash: {$slashFixed}\n";
echo "Photo URLs skipped: {$totalSkipped}\n";
echo "Document URLs fixed: {$docFixed}\n";
echo "Mode: " . ($isDryRun ? 'DRY RUN' : 'LIVE') . "\n";
echo "========================================\n";

$mysqli->close();
