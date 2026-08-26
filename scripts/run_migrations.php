<?php
/**
 * scripts/run_migrations.php
 *
 * CLI migration runner — applies pending SQL files from database/migrations/.
 * Usage:  php scripts/run_migrations.php
 *
 * Safe to re-run: each migration file is tracked in a `schema_migrations` table
 * so it is only applied once.
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$migrationDir = __DIR__ . '/../database/migrations';

if (!is_dir($migrationDir)) {
    echo "Migration directory not found: {$migrationDir}\n";
    exit(1);
}

// ── Ensure tracking table exists ─────────────────────────────────────
$mysqli->query("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename    VARCHAR(255) NOT NULL UNIQUE,
        applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Collect applied filenames ────────────────────────────────────────
$applied = [];
$res = $mysqli->query("SELECT filename FROM schema_migrations ORDER BY id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $applied[$row['filename']] = true;
    }
}

// ── Scan migration files ─────────────────────────────────────────────
$files = glob($migrationDir . '/*.sql');
sort($files);

if (empty($files)) {
    echo "No migration files found in {$migrationDir}\n";
    exit(0);
}

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (!isset($applied[$name])) {
        $pending[] = $file;
    }
}

if (empty($pending)) {
    echo "All migrations already applied. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($pending) . " pending migration(s):\n";
foreach ($pending as $f) {
    echo "  → " . basename($f) . "\n";
}
echo "\n";

// ── Apply each pending migration ─────────────────────────────────────
$success = 0;
$errors  = 0;

foreach ($pending as $file) {
    $name = basename($file);
    echo "Applying {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "FAILED (could not read file)\n";
        $errors++;
        continue;
    }

    // Split on semicolons (ignore comments / empty)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with($s, '--')
    );

    $ok = true;
    foreach ($statements as $stmt) {
        if ($mysqli->query($stmt) === false) {
            echo "FAILED ({$mysqli->error})\n";
            error_log("[migration] {$name}: {$mysqli->error}");
            $ok = false;
            break;
        }
    }

    if ($ok) {
        $ins = $mysqli->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $ins->bind_param('s', $name);
        $ins->execute();
        $ins->close();

        echo "OK\n";
        $success++;
    } else {
        $errors++;
    }
}

echo "\nDone. {$success} applied, {$errors} failed.\n";
exit($errors > 0 ? 1 : 0);
