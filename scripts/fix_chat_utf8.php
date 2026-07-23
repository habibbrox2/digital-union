<?php
/**
 * scripts/fix_chat_utf8.php
 * 
 * Fixes double-encoded UTF-8 Bengali text in the system_settings table.
 * 
 * The issue: When the SQL migration (insert_chat_settings.sql) was run via
 * mysql.exe on Windows, the client used cp850 encoding. The UTF-8 Bengali bytes
 * were misinterpreted as cp850 characters and then double-encoded to UTF-8.
 * 
 * For example:
 *   Correct Bengali "লাইভ" → UTF-8 bytes: E0 A6 B2 E0 A6 BE E0 A6 87 E0 A6 AD
 *   Interpreted as cp850 → converted to UTF-8 → stored as "Óª▓Óª¥ÓªçÓªí"
 * 
 * Fix: Read the garbled UTF-8, convert back through cp850 to recover the
 * original UTF-8 Bengali bytes.
 */

// --- Configuration ---
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'tdhuedhn_lgdhaka';

// Correct Bengali values to re-insert (as a fallback if iconv fix doesn't work)
$correctSettings = [
    'chat_title'             => "লাইভ চ্যাট সহায়তা",
    'chat_subtitle'          => "স্মার্ট ইউনিয়ন পরিষদ",
    'chat_welcome_message'   => "আপনার প্রশ্ন লিখুন, আমরা সহায়তা করব।",
    'chat_welcome_title'     => "সাহায্য প্রয়োজন?",
    'chat_agent_name'        => "সহায়ক",
    'chat_offline_message'   => "আমরা বর্তমানে অফলাইনে আছি। আপনার বার্তা ছেড়ে দিন, আমরা পরে উত্তর দেব।",
    'chat_placeholder'       => "বার্তা লিখুন...",
    'chat_name_placeholder'  => "আপনার নাম (ঐচ্ছিক)",
];

echo "=== Chat UTF-8 Fix Script ===\n\n";

// 1. Connect to MySQL with UTF-8
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
echo "✅ Connected to database with utf8mb4 charset\n\n";

// 2. Try iconv-based fix first
echo "--- Attempt 1: iconv fix (UTF-8 → CP850 → recover original bytes) ---\n";
$fixed = 0;
$failed = [];

$result = $mysqli->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name LIKE 'chat_%' AND setting_name NOT IN ('chat_enabled', 'chat_offline_enabled', 'chat_offline_start', 'chat_offline_end', 'chat_primary_color')");

while ($row = $result->fetch_assoc()) {
    $name = $row['setting_name'];
    $value = $row['setting_value'];
    
    // Check if the value is garbled (contains mojibake pattern)
    $isGarbled = false;
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $byte = ord($value[$i]);
        // Garbled UTF-8 typically has bytes in the C2-C3 range (from double encoding)
        if ($byte === 0xC3 || $byte === 0xC2) {
            $isGarbled = true;
            break;
        }
    }
    
    if (!$isGarbled) {
        echo "  ✓ '{$name}' — already looks correct (UTF-8), skipping\n";
        continue;
    }
    
    echo "  → '{$name}' is garbled (has C2/C3 double-encode bytes)\n";
    echo "    Current HEX: " . bin2hex($value) . "\n";
    
    // Try iconv fix: UTF-8 → CP850 → recover original bytes
    if (function_exists('iconv')) {
        $fixedValue = @iconv('UTF-8', 'CP850//TRANSLIT//IGNORE', $value);
        if ($fixedValue !== false && strlen($fixedValue) > 0) {
            // Verify the result looks like proper UTF-8 Bengali
            $looksValid = false;
            $fixedLen = strlen($fixedValue);
            for ($i = 0; $i < $fixedLen; $i++) {
                $byte = ord($fixedValue[$i]);
                // Proper Bengali UTF-8 bytes start with E0 (for U+0980-U+09FF)
                if ($byte === 0xE0) {
                    $looksValid = true;
                    break;
                }
            }
            
            if ($looksValid) {
                $stmt = $mysqli->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = ?");
                $stmt->bind_param("ss", $fixedValue, $name);
                if ($stmt->execute()) {
                    echo "    ✅ FIXED via iconv! New HEX: " . bin2hex($fixedValue) . "\n";
                    echo "    New value: {$fixedValue}\n";
                    $fixed++;
                    $stmt->close();
                    continue;
                }
                $stmt->close();
            }
        }
    }
    
    $failed[] = $name;
}

$result->free();

if (count($failed) > 0) {
    echo "\n--- Attempt 2: Re-inserting correct values for " . count($failed) . " settings ---\n";
    foreach ($failed as $name) {
        if (isset($correctSettings[$name])) {
            $correctValue = $correctSettings[$name];
            $stmt = $mysqli->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = ?");
            $stmt->bind_param("ss", $correctValue, $name);
            if ($stmt->execute()) {
                echo "  ✅ '{$name}' — re-inserted with correct value\n";
                echo "    HEX: " . bin2hex($correctValue) . "\n";
                $fixed++;
            } else {
                echo "  ❌ '{$name}' — FAILED to update: " . $stmt->error . "\n";
            }
            $stmt->close();
        }
    }
}

echo "\n=== Summary ===\n";
echo "Fixed: {$fixed} settings\n";

// 3. Verify the API response
echo "\n--- Verification: Checking /api/chat/settings ---\n";
$apiResponse = file_get_contents('http://lgdhaka.local/api/chat/settings');
if ($apiResponse) {
    $data = json_decode($apiResponse, true);
    if ($data && isset($data['data']['chat_title'])) {
        $title = $data['data']['chat_title'];
        $expected = "লাইভ চ্যাট সহায়তা";
        echo "  chat_title from API: {$title}\n";
        echo "  Expected:            {$expected}\n";
        if ($title === $expected) {
            echo "  ✅ MATCH! Fix confirmed.\n";
        } else {
            echo "  ❌ Still garbled or different.\n";
            echo "  API HEX: " . bin2hex($title) . "\n";
        }
    }
}

$mysqli->close();
echo "\nDone.\n";
