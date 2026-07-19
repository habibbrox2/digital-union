<?php
/* ===================== AUTHENTICATION CHECK ===================== */
// Skip browser-only auth guards when running from CLI cron/smoke scripts.
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
if (!$isCli) {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Optional: Check for admin role if your system has roles
// if (($_SESSION['role'] ?? '') !== 'admin') {
//     http_response_code(403);
//     die('Access Denied: Admin privileges required.');
// }

/* ===================== কনফিগারেশন / CONFIG ===================== */

define('DB_HOST', 'localhost');
define('DB_USER', 'tdhuedhn_lgdhaka');
define('DB_PASS', 'F4o*PbcT~lkB');
define('DB_NAME', 'tdhuedhn_lgdhaka');

// ব্যাকআপ ডিরেক্টরি
define('BACKUP_DIR', dirname(__DIR__, 1) . '/Database/');

// প্রতিবার কতগুলো সারি এক্সপোর্ট করবে
define('EXPORT_CHUNK_SIZE', 500);

// View table data - rows per page
define('VIEW_ROWS_PER_PAGE', 50);

/* ===================== ইনিশিয়ালাইজেশন / INIT ===================== */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);
ini_set('memory_limit', '-1');

// ব্যাকআপ ফোল্ডার তৈরি করুন যদি না থাকে
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}
if (!is_dir(BACKUP_DIR . 'full/')) {
    mkdir(BACKUP_DIR . 'full/', 0755, true);
}

/* ===================== ডাটাবেস সংযোগ / DATABASE CONNECTION ===================== */

function db()
{
    static $db;
    if ($db) return $db;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
        return $db;
    } catch (Exception $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

/* ===================== টেবিল মেটাডাটা / TABLE METADATA ===================== */

// Expose the shared connection for legacy scripts that expect a global handle.
try {
    $mysqli = db();
} catch (Exception $e) {
    if ($isCli) {
        throw $e;
    }
}

function getTablesWithStats()
{
    $sql = "
        SELECT 
            table_name,
            IFNULL(table_rows, 0) as table_rows,
            ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        ORDER BY table_name
    ";

    $res = db()->query($sql);
    return $res->fetch_all(MYSQLI_ASSOC);
}

/* ===================== VIEW TABLE DATA ===================== */

function viewTableData($table, $page = 1, $limit = VIEW_ROWS_PER_PAGE, $search = '', $sort = '', $order = 'ASC')
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $tableCheck = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($tableCheck->num_rows === 0) {
        throw new Exception('Table does not exist');
    }

    $page = max(1, (int)$page);
    $limit = max(1, min(1000, (int)$limit));
    $offset = ($page - 1) * $limit;

    // Search Logic
    $where = "";
    if (!empty($search)) {
        $searchEscaped = $db->real_escape_string($search);
        $cols = [];
        $res = $db->query("SHOW COLUMNS FROM `$table`");
        while ($row = $res->fetch_assoc()) {
            $cols[] = "`" . $row['Field'] . "` LIKE '%$searchEscaped%'";
        }
        if (!empty($cols)) {
            $where = "WHERE " . implode(" OR ", $cols);
        }
    }

    // Sort Logic
    $orderBy = "";
    if (!empty($sort)) {
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '" . $db->real_escape_string($sort) . "'");
        if ($res->num_rows > 0) {
            $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
            $orderBy = "ORDER BY `$sort` $order";
        }
    }

    $totalRows = $db->query("SELECT COUNT(*) c FROM `$table` $where")->fetch_assoc()['c'];

    $columns = [];
    $columnsResult = $db->query("SHOW COLUMNS FROM `$table`");
    while ($col = $columnsResult->fetch_assoc()) {
        $columns[] = $col;
    }

    $data = [];
    $dataResult = $db->query("SELECT * FROM `$table` $where $orderBy LIMIT $limit OFFSET $offset");
    while ($row = $dataResult->fetch_assoc()) {
        $data[] = $row;
    }

    return [
        'success' => true,
        'table' => $table,
        'columns' => $columns,
        'data' => $data,
        'totalRows' => (int)$totalRows,
        'page' => $page,
        'limit' => $limit,
        'totalPages' => ceil($totalRows / $limit),
        'search' => $search,
        'sort' => $sort,
        'order' => $order
    ];
}

/* ===================== GET TABLE DETAILS ===================== */

function getTableDetails($table)
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $tableCheck = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($tableCheck->num_rows === 0) {
        throw new Exception('Table does not exist');
    }

    $columns = [];
    $columnsResult = $db->query("SHOW COLUMNS FROM `$table`");
    while ($col = $columnsResult->fetch_assoc()) {
        $columns[] = $col;
    }

    return [
        'success' => true,
        'table' => $table,
        'columns' => $columns
    ];
}

/* ===================== SEARCH AND REPLACE ===================== */

function searchAndReplace($table, $column, $search, $replace)
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }
    if (empty($column) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        throw new Exception('Invalid column name');
    }

    $stmt = $db->prepare("UPDATE `$table` SET `$column` = REPLACE(`$column`, ?, ?)");
    if (!$stmt) throw new Exception($db->error);

    $stmt->bind_param('ss', $search, $replace);
    $stmt->execute();

    return [
        'success' => true,
        'message' => "Updated " . $stmt->affected_rows . " rows.",
        'affected_rows' => $stmt->affected_rows
    ];
}

/* ===================== EXECUTE CUSTOM SQL ===================== */

function executeQuery($sql, $disableFK = false)
{
    $db = db();

    if ($disableFK) {
        $db->query("SET FOREIGN_KEY_CHECKS=0");
    }

    try {
        // Use multi_query to handle multiple statements
        if ($db->multi_query($sql)) {
            $results = [];
            $affected_rows_total = 0;

            do {
                if ($result = $db->store_result()) {
                    // It's a SELECT-like query
                    $data = $result->fetch_all(MYSQLI_ASSOC);
                    $fields = $result->fetch_fields();
                    $results[] = [
                        'type' => 'result',
                        'data' => $data,
                        'fields' => $fields,
                        'count' => count($data)
                    ];
                    $result->free();
                } else {
                    // It's a statement like INSERT, UPDATE, DELETE
                    if ($db->affected_rows > -1) {
                        $affected_rows_total += $db->affected_rows;
                    }
                }
            } while ($db->more_results() && $db->next_result());

            if ($db->error) {
                throw new Exception($db->error);
            }

            // If there are result sets, return the last one.
            if (!empty($results)) {
                $lastResult = end($results);
                $lastResult['success'] = true;
                return $lastResult;
            }

            // If there were no result sets (only statements)
            return [
                'success' => true,
                'type' => 'statement',
                'message' => 'Query executed successfully.',
                'affected_rows' => $affected_rows_total
            ];
        } else {
            throw new Exception($db->error);
        }
    } catch (Exception $e) {
        // Clean up any remaining results from a failed multi-query
        while ($db->more_results() && $db->next_result()) {
            if ($result = $db->store_result()) {
                $result->free();
            }
        }
        throw new Exception($e->getMessage());
    } finally {
        if ($disableFK) {
            $db->query("SET FOREIGN_KEY_CHECKS=1");
        }
    }
}

/* ===================== OPTIMIZE TABLE ===================== */

function optimizeTable($table)
{
    $db = db();
    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $result = $db->query("OPTIMIZE TABLE `$table`");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $lastMsg = end($rows);
    $msgText = $lastMsg['Msg_text'] ?? 'Optimized';

    return [
        'success' => true,
        'message' => "Table optimized: " . $msgText,
        'details' => $rows
    ];
}

/* ===================== REPAIR TABLE ===================== */

function repairTable($table)
{
    $db = db();
    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $result = $db->query("REPAIR TABLE `$table`");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $lastMsg = end($rows);
    $msgText = $lastMsg['Msg_text'] ?? 'Repaired';

    return [
        'success' => true,
        'message' => "Table repair result: " . $msgText,
        'details' => $rows
    ];
}

/* ===================== EMPTY TABLE ===================== */

function emptyTable($table)
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $tableCheck = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($tableCheck->num_rows === 0) {
        throw new Exception('Table does not exist');
    }

    $rowCount = $db->query("SELECT COUNT(*) c FROM `$table`")->fetch_assoc()['c'];

    $db->query("TRUNCATE TABLE `$table`");

    return [
        'success' => true,
        'message' => "টেবিল খালি করা হয়েছে / Table emptied",
        'rowsDeleted' => (int)$rowCount
    ];
}

/* ===================== DROP TABLE ===================== */

function dropTable($table)
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $tableCheck = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($tableCheck->num_rows === 0) {
        throw new Exception('Table does not exist');
    }

    $db->query("DROP TABLE `" . $db->real_escape_string($table) . "`");

    return [
        'success' => true,
        'message' => "টেবিল ডিলিট করা হয়েছে / Table '$table' dropped successfully"
    ];
}

/* ===================== CREATE TABLE ===================== */

function createTable($data)
{
    $db = db();
    $tableName = $data['table_name'] ?? '';
    $columns = $data['columns'] ?? [];

    if (empty($tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        throw new Exception('Invalid table name.');
    }

    if (empty($columns)) {
        throw new Exception('No columns provided for the table.');
    }

    $sql = "CREATE TABLE `" . $db->real_escape_string($tableName) . "` (\n";
    $columnDefs = [];
    $primaryKeys = [];
    $uniqueKeys = [];
    $indexKeys = [];

    foreach ($columns as $col) {
        $colName = $col['name'] ?? '';
        if (empty($colName) || !preg_match('/^[a-zA-Z0-9_]+$/', $colName)) {
            continue; // Skip invalid column names
        }

        $def = "  `" . $db->real_escape_string($colName) . "` ";
        $type = strtoupper($col['type'] ?? 'VARCHAR');
        $length = $col['length'] ?? '';

        $def .= $type;
        if (!empty($length) && in_array($type, ['VARCHAR', 'INT', 'DECIMAL'])) {
            $def .= "($length)";
        }

        $def .= " NOT NULL";

        if (isset($col['default'])) {
            if (strtoupper($col['default']) === 'NULL') {
                $def = str_replace('NOT NULL', 'NULL', $def);
                $def .= " DEFAULT NULL";
            } elseif (strtoupper($col['default']) === 'CURRENT_TIMESTAMP') {
                $def .= " DEFAULT CURRENT_TIMESTAMP";
            } elseif ($col['default'] !== '') {
                $def .= " DEFAULT '" . $db->real_escape_string($col['default']) . "'";
            }
        }

        if (isset($col['ai']) && $col['ai'] === true) {
            $def .= " AUTO_INCREMENT";
        }

        $columnDefs[] = $def;

        $index = $col['index'] ?? '';
        if ($index === 'PRIMARY') {
            $primaryKeys[] = "`" . $db->real_escape_string($colName) . "`";
        } elseif ($index === 'UNIQUE') {
            $uniqueKeys[] = "UNIQUE KEY `unique_" . $db->real_escape_string($colName) . "` (`" . $db->real_escape_string($colName) . "`)";
        } elseif ($index === 'INDEX') {
            $indexKeys[] = "KEY `idx_" . $db->real_escape_string($colName) . "` (`" . $db->real_escape_string($colName) . "`)";
        }
    }

    $sql .= implode(",\n", $columnDefs);

    if (!empty($primaryKeys)) {
        $sql .= ",\n  PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
    }
    if (!empty($uniqueKeys)) {
        $sql .= ",\n  " . implode(",\n  ", $uniqueKeys);
    }
    if (!empty($indexKeys)) {
        $sql .= ",\n  " . implode(",\n  ", $indexKeys);
    }

    $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    try {
        $db->query($sql);
        return [
            'success' => true,
            'message' => "Table '$tableName' created successfully.",
            'sql' => $sql // For debugging
        ];
    } catch (Exception $e) {
        throw new Exception("Failed to create table: " . $e->getMessage() . " (Query: $sql)");
    }
}


/* ===================== SERVER INFO ===================== */

function getServerInfo()
{
    $db = db();

    return [
        'success' => true,
        'php_version' => phpversion(),
        'mysql_version' => $db->server_info,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
        'max_upload_size' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'db_host' => DB_HOST,
        'db_name' => DB_NAME,
        'db_user' => DB_USER,
        'db_stat' => $db->stat()
    ];
}

/* ===================== SINGLE TABLE EXPORT ===================== */

function exportInit($table, $allowDrop = false, $structureOnly = false)
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    $tableCheck = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($tableCheck->num_rows === 0) {
        throw new Exception('Table does not exist');
    }

    $total = $structureOnly ? 0 : $db->query("SELECT COUNT(*) c FROM `$table`")->fetch_assoc()['c'];

    $suffix = $structureOnly ? '_structure' : '';
    $file = "{$table}{$suffix}.sql";
    $fullPath = BACKUP_DIR . $file;

    $create = $db->query("SHOW CREATE TABLE `$table`")->fetch_assoc()['Create Table'];

    $sql  = "-- ========================================\n";
    $sql .= "-- টেবিল / Table: $table\n";
    $sql .= "-- তারিখ / Date: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- মোড / Mode: " . ($structureOnly ? "Structure Only" : "Full Export") . "\n";
    $sql .= "-- মোট সারি / Total Rows: $total\n";
    $sql .= "-- ========================================\n\n";

    if ($allowDrop) {
        $sql .= "DROP TABLE IF EXISTS `$table`;\n\n";
    }

    $sql .= str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $create) . ";\n\n";

    if (!$structureOnly) {
        $sql .= "-- ডাটা ইনসার্ট / Data Insert\n\n";
    }

    file_put_contents($fullPath, $sql);

    return [
        'success' => true,
        'file' => $file,
        'total' => (int)$total,
        'structureOnly' => $structureOnly
    ];
}

function exportChunk($table, $file, $offset)
{
    $db = db();

    if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Invalid table name');
    }

    if (empty($file) || !preg_match('/^[a-zA-Z0-9_]+(_structure)?\.sql$/', $file)) {
        throw new Exception('Invalid file name');
    }

    $offset = (int)$offset;

    $res = $db->query(
        "SELECT * FROM `$table` LIMIT " . EXPORT_CHUNK_SIZE . " OFFSET $offset"
    );

    $sql = '';
    $rowCount = 0;

    while ($row = $res->fetch_assoc()) {
        $cols = array_map(fn($c) => "`$c`", array_keys($row));
        $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string($v) . "'", $row);

        $sql .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        $rowCount++;
    }

    if (!empty($sql)) {
        file_put_contents(BACKUP_DIR . $file, $sql, FILE_APPEND);
    }

    return [
        'success' => true,
        'processed' => $rowCount
    ];
}

/* ===================== FULL DATABASE EXPORT - SEPARATE FILES ===================== */

function fullDatabaseExportInit($allowDrop = false, $structureOnly = false)
{
    $tables = getTablesWithStats();
    $exportTasks = [];

    foreach ($tables as $table) {
        $tableName = $table['table_name'];
        $total = $structureOnly ? 0 : db()->query("SELECT COUNT(*) c FROM `$tableName`")->fetch_assoc()['c'] ?? 0;

        $exportTasks[] = [
            'table' => $tableName,
            'total' => (int)$total,
            'rows' => $table['table_rows'],
            'size' => $table['size_mb']
        ];
    }

    return [
        'success' => true,
        'tasks' => $exportTasks,
        'totalTables' => count($exportTasks),
        'allowDrop' => $allowDrop,
        'structureOnly' => $structureOnly
    ];
}

/* ===================== FULL DATABASE EXPORT - SINGLE FILE ===================== */

function fullDatabaseSingleFileInit($allowDrop = false, $structureOnly = false)
{
    $tables = getTablesWithStats();
    $tasks = [];

    foreach ($tables as $t) {
        $tableName = $t['table_name'];
        $totalRows = $structureOnly ? 0 : db()->query("SELECT COUNT(*) c FROM `$tableName`")->fetch_assoc()['c'] ?? 0;

        $tasks[] = [
            'table'     => $tableName,
            'totalRows' => (int)$totalRows,
            'rows'      => $t['table_rows'],
            'size_mb'   => $t['size_mb']
        ];
    }

    $timestamp = date('Y-m-d_H-i-s');
    $filename = "full/full_database_{$timestamp}.sql";
    $path = BACKUP_DIR . $filename;

    $header = "-- ========================================\n";
    $header .= "-- FULL DATABASE EXPORT\n";
    $header .= "-- তারিখ / Date: " . date('Y-m-d H:i:s') . "\n";
    $header .= "-- মোড / Mode: " . ($structureOnly ? "Structure Only" : "Full Export (structure + data)") . "\n";
    $header .= "-- মোট টেবিল / Total Tables: " . count($tasks) . "\n";
    $header .= "-- ========================================\n\n";
    $header .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    file_put_contents($path, $header);

    return [
        'success'       => true,
        'filename'      => $filename,
        'tasks'         => $tasks,
        'structureOnly' => $structureOnly,
        'allowDrop'     => $allowDrop
    ];
}

function exportTableChunkToSingleFile($table, $filename, $offset = 0, $allowDrop = false, $structureOnly = false)
{
    $db = db();
    $path = BACKUP_DIR . $filename;

    $sql = '';

    if ($offset === 0) {
        if ($allowDrop) {
            $sql .= "DROP TABLE IF EXISTS `$table`;\n\n";
        }

        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch_assoc()['Create Table'];
        $sql .= str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $create) . ";\n\n";

        if (!$structureOnly) {
            $sql .= "-- ডাটা ইনসার্ট / Data Insert for table `$table`\n\n";
        }
    }

    if (!$structureOnly) {
        $res = $db->query("SELECT * FROM `$table` LIMIT " . EXPORT_CHUNK_SIZE . " OFFSET $offset");

        $rowCount = 0;
        while ($row = $res->fetch_assoc()) {
            $cols = array_map(fn($c) => "`$c`", array_keys($row));
            $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string($v) . "'", $row);

            $sql .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            $rowCount++;
        }

        if ($rowCount > 0) {
            $sql .= "\n";
        }
    }

    if ($sql !== '') {
        file_put_contents($path, $sql, FILE_APPEND | LOCK_EX);
    }

    return [
        'success'    => true,
        'processed'  => $rowCount ?? 0,
        'finished'   => ($rowCount ?? 0) < EXPORT_CHUNK_SIZE
    ];
}

function finalizeSingleFile($filename)
{
    $path = BACKUP_DIR . $filename;
    $footer = "\nSET FOREIGN_KEY_CHECKS=1;\n\n";
    $footer .= "-- Export সম্পন্ন হয়েছে / Export completed at " . date('Y-m-d H:i:s') . "\n";

    file_put_contents($path, $footer, FILE_APPEND);
    return ['success' => true];
}

/* ===================== IMPORT ===================== */

function previewSQLFile($file, $max_queries = 15, $max_bytes = 102400) // 100KB
{
    if (!file_exists($file)) {
        throw new Exception('ফাইল পাওয়া যায়নি');
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ফাইল খুলতে ব্যর্থ');
    }

    $queries = [];
    $current_query = '';
    $bytes_read = 0;

    while (($line = fgets($handle)) !== false && count($queries) < $max_queries && $bytes_read < $max_bytes) {
        $bytes_read += strlen($line);
        $trimmed_line = trim($line);

        if (empty($trimmed_line) || strpos($trimmed_line, '--') === 0 || strpos($trimmed_line, '/*') === 0) {
            continue;
        }

        $current_query .= $line;

        if (substr($trimmed_line, -1) === ';') {
            $queries[] = trim($current_query);
            $current_query = '';
        }
    }

    fclose($handle);

    return [
        'success' => true,
        'queries' => $queries,
        'count' => count($queries)
    ];
}

function importSQLFile($file, $allowDrop = false, $enableFK = false)
{
    if (!file_exists($file)) {
        throw new Exception('ফাইল পাওয়া যায়নি / File not found');
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ফাইল পড়তে ব্যর্থ');
    }

    $db = db();
    $current_query = '';
    $count = 0;
    $errors = [];

    $db->query('SET autocommit=0');
    $db->query('SET FOREIGN_KEY_CHECKS=' . ($enableFK ? 1 : 0));
    $db->begin_transaction();

    try {
        while (($line = fgets($handle)) !== false) {
            $trimmed_line = trim($line);

            // Skip comments and empty lines only if we are not in the middle of a query
            if ($current_query === '' && (empty($trimmed_line) || strpos($trimmed_line, '--') === 0 || strpos($trimmed_line, '/*') === 0)) continue;

            $current_query .= $line;

            if (substr($trimmed_line, -1) === ';') {
                $q = trim($current_query);
                $current_query = '';

                if (empty($q)) continue;

                if (!$allowDrop && stripos($q, 'DROP TABLE') === 0) continue;

                $db->query($q);
                $count++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        $errors[] = substr(trim($current_query), 0, 100) . '... → ' . $e->getMessage();
    } finally {
        $db->query('SET autocommit=1');
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        fclose($handle);
    }

    $is_success = empty($errors);
    $message = $is_success ? "$count টি স্টেটমেন্ট সফলভাবে চালানো হয়েছে" : "ইমপোর্ট অসম্পূর্ণ বা ব্যর্থ হয়েছে";
    if ($errors) {
        $message .= " (" . count($errors) . " টি ত্রুটি)";
    }
    return [
        'success' => $is_success,
        'count'   => $count,
        'errors'  => $errors,
        'message' => $message
    ];
}

/* ===================== SCHEMA DIAGRAM ===================== */

function getDatabaseSchema()
{
    $db = db();
    $schema = DB_NAME;

    // Get tables
    $tables = [];
    $res = $db->query("SHOW TABLES");
    while ($row = $res->fetch_array()) {
        $tables[] = $row[0];
    }

    // Get relationships (Foreign Keys)
    $relationships = [];
    $sql = "
        SELECT 
            TABLE_NAME, 
            COLUMN_NAME, 
            REFERENCED_TABLE_NAME
        FROM 
            INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE 
            TABLE_SCHEMA = '" . $db->real_escape_string($schema) . "' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
    ";

    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        $relationships[] = $row;
    }

    return [
        'success' => true,
        'tables' => $tables,
        'relationships' => $relationships
    ];
}

/* ===================== BACKUP FILES ===================== */

function getBackupFiles()
{
    $out = [];
    $patterns = [BACKUP_DIR . '*.sql', BACKUP_DIR . 'full/*.sql'];

    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if ($files === false) continue;

        foreach ($files as $f) {
            $size = filesize($f);
            $relativePath = substr($f, strlen(BACKUP_DIR));
            $relativePath = str_replace('\\', '/', $relativePath);
            $out[] = [
                'name' => $relativePath,
                'size' => $size < 1024 * 1024
                    ? round($size / 1024, 2) . ' KB'
                    : round($size / 1024 / 1024, 2) . ' MB',
                'date' => date('Y-m-d H:i:s', filemtime($f))
            ];
        }
    }

    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));

    return $out;
}

function deleteBackupFile($filename)
{
    if (strpos($filename, '..') !== false) {
        throw new Exception('Invalid filename');
    }
    $file = BACKUP_DIR . $filename;

    if (!file_exists($file)) {
        throw new Exception('ফাইল পাওয়া যায়নি');
    }

    if (!unlink($file)) {
        throw new Exception('ফাইল ডিলিট করতে ব্যর্থ');
    }

    return ['success' => true, 'message' => 'ফাইল ডিলিট হয়েছে'];
}

/* ===================== AJAX HANDLER ===================== */

if (!$isCli && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        if (empty($action)) {
            throw new Exception('No action specified');
        }

        switch ($action) {

            case 'export_init':
                $allowDrop = ($_POST['allowDrop'] ?? 'false') === 'true';
                $structureOnly = ($_POST['structureOnly'] ?? 'false') === 'true';
                echo json_encode(exportInit($_POST['table'], $allowDrop, $structureOnly));
                break;

            case 'export_chunk':
                echo json_encode(exportChunk(
                    $_POST['table'],
                    $_POST['file'],
                    $_POST['offset'] ?? 0
                ));
                break;

            case 'full_export_init':
                $allowDrop = ($_POST['allowDrop'] ?? 'false') === 'true';
                $structureOnly = ($_POST['structureOnly'] ?? 'false') === 'true';
                echo json_encode(fullDatabaseExportInit($allowDrop, $structureOnly));
                break;

            case 'full_single_init':
                $allowDrop = ($_POST['allowDrop'] ?? 'false') === 'true';
                $structureOnly = ($_POST['structureOnly'] ?? 'false') === 'true';
                echo json_encode(fullDatabaseSingleFileInit($allowDrop, $structureOnly));
                break;

            case 'export_table_single_chunk':
                $allowDrop = ($_POST['allowDrop'] ?? 'false') === 'true';
                $structureOnly = ($_POST['structureOnly'] ?? 'false') === 'true';
                echo json_encode(exportTableChunkToSingleFile(
                    $_POST['table'],
                    $_POST['filename'],
                    (int)($_POST['offset'] ?? 0),
                    $allowDrop,
                    $structureOnly
                ));
                break;

            case 'finalize_single':
                echo json_encode(finalizeSingleFile($_POST['filename']));
                break;

            case 'view_table':
                echo json_encode(viewTableData(
                    $_POST['table'],
                    $_POST['page'] ?? 1,
                    $_POST['limit'] ?? VIEW_ROWS_PER_PAGE,
                    $_POST['search'] ?? '',
                    $_POST['sort'] ?? '',
                    $_POST['order'] ?? 'ASC'
                ));
                break;

            case 'empty_table':
                echo json_encode(emptyTable($_POST['table']));
                break;

            case 'import':
                if (!isset($_FILES['sql_file'])) {
                    throw new Exception('কোন ফাইল আপলোড হয়নি');
                }
                if ($_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File upload error: ' . $_FILES['sql_file']['error']);
                }
                $allowDrop = ($_POST['allowDrop'] ?? 'false') === 'true';
                $enableFK = ($_POST['enableFK'] ?? 'false') === 'true';
                echo json_encode(importSQLFile($_FILES['sql_file']['tmp_name'], $allowDrop, $enableFK));
                break;

            case 'import_backup':
                $allowDrop = ($_POST['allowDrop'] ?? 'false') === 'true';
                $enableFK = ($_POST['enableFK'] ?? 'false') === 'true';
                $filename = $_POST['filename'];
                if (strpos($filename, '..') !== false) throw new Exception('Invalid filename');
                echo json_encode(importSQLFile(BACKUP_DIR . $filename, $allowDrop, $enableFK));
                break;

            case 'get_schema':
                echo json_encode(getDatabaseSchema());
                break;

            case 'get_table_details':
                echo json_encode(getTableDetails($_POST['table']));
                break;

            case 'preview_import':
                $file_to_preview = '';
                if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
                    $file_to_preview = $_FILES['sql_file']['tmp_name'];
                } elseif (isset($_POST['filename'])) {
                    $filename = $_POST['filename'];
                    if (strpos($filename, '..') !== false) throw new Exception('Invalid filename');
                    $file_to_preview = BACKUP_DIR . $filename;
                } else {
                    throw new Exception('কোন ফাইল নির্বাচন করা হয়নি');
                }
                echo json_encode(previewSQLFile($file_to_preview));
                break;

            case 'delete_backup':
                echo json_encode(deleteBackupFile($_POST['filename']));
                break;

            case 'get_backup_files':
                echo json_encode(['success' => true, 'files' => getBackupFiles()]);
                break;

            case 'download':
                $filename = $_POST['filename'] ?? '';
                if (strpos($filename, '..') !== false) throw new Exception('Invalid filename');
                $file = BACKUP_DIR . $filename;

                if (!file_exists($file)) {
                    throw new Exception('ফাইল পাওয়া যায়নি');
                }

                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($file));
                readfile($file);
                exit;

            case 'execute_query':
                $disableFK = ($_POST['disable_fk'] ?? 'false') === 'true';
                echo json_encode(executeQuery($_POST['sql'], $disableFK));
                break;

            case 'search_replace':
                echo json_encode(searchAndReplace($_POST['table'], $_POST['column'], $_POST['search'], $_POST['replace']));
                break;

            case 'optimize_table':
                echo json_encode(optimizeTable($_POST['table']));
                break;

            case 'repair_table':
                echo json_encode(repairTable($_POST['table']));
                break;

            case 'drop_table':
                echo json_encode(dropTable($_POST['table']));
                break;

            case 'create_table':
                $tableData = json_decode($_POST['table_data'], true);
                echo json_encode(createTable($tableData));
                break;

            case 'server_info':
                echo json_encode(getServerInfo());
                break;

            default:
                throw new Exception('অবৈধ অ্যাকশন: ' . $action);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'error' => get_class($e)
        ]);
    }
    exit;
}

// When included from CLI scripts, skip the HTML UI output.
if (php_sapi_name() === 'cli') {
    return;
}
?>

<!DOCTYPE html>
<html lang="bn">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ডাটাবেস ব্যাকআপ টুল | Database Backup Tool</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text: #1f2937;
            --text-light: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body,
        button,
        input,
        select,
        textarea {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            background: linear-gradient(to right, var(--primary), #8b5cf6);
            color: white;
            padding: 40px 20px;
            border-radius: 16px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            letter-spacing: -0.025em;
        }

        .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
        }

        .section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: transform 0.2s;
            margin-bottom: 0;
            /* Reset margin for grid */
        }

        .section:hover {
            transform: translateY(-2px);
        }

        .section h2 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid var(--bg);
            padding-bottom: 12px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text);
        }

        select,
        input[type="text"],
        input[type="file"],
        textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: #f9fafb;
        }

        select:focus,
        input:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
            background-color: white;
        }

        button {
            background-color: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        button:hover {
            background-color: var(--primary-hover);
        }

        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .btn-success {
            background-color: var(--success);
        }

        .btn-success:hover {
            background-color: #059669;
        }

        .btn-danger {
            background-color: var(--danger);
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            white-space: pre-line;
            font-weight: 500;
        }

        .message.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .message.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 10px;
        }

        th {
            background-color: #f8fafc;
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            font-size: 0.95rem;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: #f9fafc;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--card-bg);
            margin: 50px auto;
            border-radius: 16px;
            width: 90%;
            max-width: 1200px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: none;
        }

        .modal-header {
            background: white;
            color: var(--text);
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            font-size: 1.5rem;
            margin: 0;
        }

        .close {
            color: var(--text-light);
            font-size: 28px;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close:hover {
            color: var(--text);
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            max-height: calc(80vh - 140px);
        }

        .modal-footer {
            padding: 20px;
            text-align: right;
            border-top: 1px solid var(--border);
            background-color: #f9fafb;
        }

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 16px 0;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons button {
            padding: 6px 10px;
            font-size: 0.85rem;
            width: auto;
            border-radius: 6px;
            box-shadow: none;
            border: 1px solid transparent;
        }

        .btn-view {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        .btn-view:hover {
            background-color: #bae6fd;
        }

        .btn-optimize {
            background-color: #dcfce7;
            color: #15803d;
        }

        .btn-optimize:hover {
            background-color: #bbf7d0;
        }

        .btn-repair {
            background-color: #fef3c7;
            color: #b45309 !important;
        }

        .btn-repair:hover {
            background-color: #fde68a;
        }

        .btn-empty {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .btn-empty:hover {
            background-color: #fecaca;
        }

        .btn-export {
            background-color: #e0e7ff;
            color: #3730a3;
        }

        .btn-export:hover {
            background-color: #c7d2fe;
        }

        /* Progress Bar */
        .progress-container {
            background: #e5e7eb;
            border-radius: 999px;
            height: 12px;
            overflow: hidden;
            margin-top: 15px;
        }

        progress {
            width: 100%;
            height: 12px;
            appearance: none;
            border: none;
        }

        progress::-webkit-progress-bar {
            background-color: #e5e7eb;
        }

        progress::-webkit-progress-value {
            background-color: var(--primary);
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 2rem;
            }

            .action-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php
        try {
            db(); // Initial connection check
        } catch (Exception $e) {
            // If connection fails, show a full-page error and stop.
            echo '
            <div style="padding: 40px 20px; text-align: center;">
                <div class="section" style="background-color: #fef2f2; border-color: #fecaca;">
                    <h2 style="color: #991b1b;">❌ ডাটাবেস সংযোগ ব্যর্থ | Database Connection Failed</h2>
                    <p style="margin-top: 15px; color: #991b1b;">ডাটাবেস সার্ভারের সাথে সংযোগ স্থাপন করা সম্ভব হচ্ছে না। অনুগ্রহ করে আপনার কনফিগারেশন (`db.php`) চেক করুন।</p>
                    <p style="margin-top: 10px; font-family: monospace; background: rgba(0,0,0,0.05); padding: 10px; border-radius: 5px; color: #b91c1c; font-size: 0.9em; text-align: left; white-space: pre-wrap;">' . htmlspecialchars($e->getMessage()) . '</p>
                </div>
            </div>
            ';
            echo '</div></body></html>';
            exit;
        }
        ?>
        <header>
            <h1>🗄️ ডাটাবেস ব্যাকআপ ম্যানেজমেন্ট টুল</h1>
            <div class="subtitle">Single & Full Database Export - Separate or Single File</div>
        </header>

        <div class="content-wrapper">
            <div id="message" class="message"></div>
            <div id="loading" class="loading" style="display:none; text-align:center; padding:30px;">
                <div class="spinner" style="border:5px solid #f3f3f3; border-top:5px solid #667eea; border-radius:50%; width:50px; height:50px; animation:spin 1s linear infinite; margin:0 auto 15px;"></div>
                <p>প্রক্রিয়াকরণ চলছে... / Processing...</p>
            </div>

            <!-- Server Info -->
            <div class="section" style="margin-bottom: 24px;">
                <h2>🖥️ সার্ভার ও ডাটাবেস তথ্য | Server Info</h2>
                <div id="serverInfoContent">
                    <p style="color: var(--text-light); margin-bottom: 15px;">Click the button below to load server status.</p>
                    <button onclick="loadServerInfo()" class="btn-view" style="background-color: var(--primary); color: white;">🔄 তথ্য দেখুন (Check Status)</button>
                </div>
            </div>

            <!-- SQL Query Executor -->
            <div class="section" style="margin-top: 24px;">
                <h2>💻 SQL কুয়েরি রান করুন | Run SQL Query</h2>
                <textarea id="sqlInput" rows="5" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ddd; font-family:monospace; margin-bottom:10px;" placeholder="SELECT * FROM users WHERE id = 1;"></textarea>
                <div style="margin-bottom: 10px;">
                    <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer;"><input type="checkbox" id="disableFkSql"> Disable Foreign Key Checks</label>
                </div>
                <button onclick="runQuery()" class="btn-success" style="background-color: var(--primary);">▶️ রান কুয়েরি (Run Query)</button>
                <div id="queryResult" style="margin-top:20px; overflow-x:auto;"></div>
            </div>

            <!-- Table Management -->
            <div class="section" style="margin-top: 24px;">
                <h2>⚙️ টেবিল ম্যানেজমেন্ট</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" id="tableSearchInput" onkeyup="searchTable()" placeholder="🔍 টেবিল খুঁজুন... / Search tables..." style="flex-grow: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" autocomplete="off">
                    <button onclick="openCreateTableModal()" class="btn-success" style="background-color: var(--success);">➕ নতুন টেবিল তৈরি করুন</button>
                </div>
                <table id="managementTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>টেবিল</th>
                            <th>সারি</th>
                            <th>সাইজ</th>
                            <th>অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $tables = getTablesWithStats();
                        $i = 0; ?>
                        <?php foreach ($tables as $t): $i++; ?>
                            <tr>
                                <td><?= $i ?></td>
                                <td><strong><?= htmlspecialchars($t['table_name']) ?></strong></td>
                                <td><?= number_format($t['table_rows']) ?></td>
                                <td><?= $t['size_mb'] ?> MB</td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-view" onclick="viewTable('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>')" title="View Data">👁️</button>
                                        <button class="btn-view" style="font-weight: bold;" onclick="toggleTableDetails('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>', this)" title="Expand Details">...</button>
                                        <button class="btn-export" onclick="exportSingleTable('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>')" title="Export Table">📤</button>
                                        <button class="btn-optimize" onclick="optimizeTable('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>')" title="Optimize Table">⚡</button>
                                        <button class="btn-repair" onclick="repairTable('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>')" title="Repair Table">🔧</button>
                                        <button class="btn-empty" onclick="emptyTableConfirm('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>')" title="Empty Table">🗑️</button>
                                        <button class="btn-danger" onclick="dropTableConfirm('<?= htmlspecialchars($t['table_name'], ENT_QUOTES) ?>')" title="Drop Table">❌</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Search & Replace -->
            <div class="section" style="margin-top: 24px;">
                <h2>🔍 খুঁজুন এবং পরিবর্তন করুন | Search & Replace</h2>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label>টেবিল নির্বাচন করুন (Table)</label>
                        <select id="srTable" onchange="loadSrColumns()">
                            <option value="">-- Select Table --</option>
                            <?php foreach ($tables as $t): ?>
                                <option value="<?= htmlspecialchars($t['table_name']) ?>"><?= htmlspecialchars($t['table_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>কলাম নির্বাচন করুন (Column)</label>
                        <select id="srColumn">
                            <option value="">-- Select Column --</option>
                        </select>
                    </div>
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                    <div class="form-group"><label>খুঁজুন (Search)</label><input type="text" id="srSearch" placeholder="Text to find"></div>
                    <div class="form-group"><label>পরিবর্তন করুন (Replace)</label><input type="text" id="srReplace" placeholder="Replacement text"></div>
                </div>
                <button onclick="doSearchReplace()" class="btn-view" style="background-color: var(--warning); color: #000;">
                    🔄 পরিবর্তন করুন (Replace)
                </button>
            </div>

            <div class="grid">
                <!-- Single Table Export -->
                <div class="section">
                    <h2>📤 টেবিল এক্সপোর্ট করুন | Export Table</h2>
                    <div class="form-group">
                        <label for="tableSelect">টেবিল নির্বাচন করুন:</label>
                        <select id="tableSelect">
                            <option value="">-- একটি টেবিল নির্বাচন করুন --</option>
                            <?php
                            foreach ($tables as $t):
                            ?>
                                <option value="<?= htmlspecialchars($t['table_name']) ?>">
                                    <?= htmlspecialchars($t['table_name']) ?> (<?= number_format($t['table_rows']) ?> rows, <?= $t['size_mb'] ?> MB)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="checkbox-group">
                        <label class="checkbox-item">
                            <input type="checkbox" id="structureOnlySingle">
                            <span>শুধুমাত্র স্ট্রাকচার (ডাটা ছাড়া)</span>
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" id="dropTableSingle">
                            <span>DROP TABLE স্টেটমেন্ট যোগ করুন</span>
                        </label>
                    </div>
                    <button onclick="startExport()" id="exportBtn">🚀 এক্সপোর্ট শুরু করুন</button>
                    <div class="progress-container" id="progressContainer" style="margin-top:20px; display:none;">
                        <progress id="progress" value="0" max="100"></progress>
                        <div class="status" id="status" style="margin-top:10px; text-align:center; font-weight:bold; color:var(--primary);">প্রস্তুত...</div>
                    </div>
                </div>

                <!-- Full Database Export -->
                <div class="section">
                    <h2>🗄️ সম্পূর্ণ ডাটাবেস এক্সপোর্ট | Export Full Database</h2>
                    <div class="checkbox-group">
                        <label class="checkbox-item">
                            <input type="checkbox" id="structureOnlyFull">
                            <span>শুধুমাত্র স্ট্রাকচার (ডাটা ছাড়া)</span>
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" id="dropTableFull">
                            <span>DROP TABLE স্টেটমেন্ট যোগ করুন</span>
                        </label>
                    </div>
                    <button onclick="confirmFullExport()" id="fullExportBtn">🚀 সম্পূর্ণ ডাটাবেস এক্সপোর্ট করুন</button>
                    <div class="progress-container" id="fullProgressContainer" style="margin-top:20px; display:none;">
                        <progress id="fullProgress" value="0" max="100"></progress>
                        <div class="status" id="fullStatus" style="margin-top:10px; text-align:center; font-weight:bold; color:var(--primary);">প্রস্তুত...</div>
                    </div>
                </div>
            </div>

            <!-- Import -->
            <div class="section" style="margin-top: 24px;">
                <h2>📥 SQL ফাইল ইমপোর্ট করুন | Import SQL File</h2>
                <form id="importForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="sqlFile">SQL ফাইল নির্বাচন করুন:</label>
                        <input type="file" id="sqlFile" name="sql_file" accept=".sql" required>
                    </div>
                    <div class="checkbox-group">
                        <label class="checkbox-item">
                            <input type="checkbox" id="dropTableImport" name="allowDrop">
                            <span>DROP TABLE স্টেটমেন্ট অনুমোদন করুন</span>
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" id="enableFKImport" name="enableFK">
                            <span>Enable Foreign Key Checks</span>
                        </label>
                    </div>
                    <div class="button-group" style="display: flex; gap: 10px;">
                        <button type="submit" class="btn-success" style="background-color: var(--success);">⬆️ আপলোড ও ইমপোর্ট</button>
                        <button type="button" onclick="previewImportUpload()" style="background-color: var(--secondary);">👁️ প্রিভিউ</button>
                    </div>
                </form>
            </div>

            <!-- Backup Files List -->
            <div class="section" style="margin-top: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>📂 সংরক্ষিত ব্যাকআপ ফাইল</h2>
                    <div>
                        <button onclick="importSelectedBackups()" class="btn-view" style="background-color: var(--info); margin-right: 5px;">📥 নির্বাচিত ইমপোর্ট</button>
                        <button onclick="deleteSelectedBackups()" class="btn-danger" style="margin-right: 5px;">🗑️ নির্বাচিত ডিলিট</button>
                        <button onclick="importAllBackups()" class="btn-success" style="background-color: var(--success); margin-right: 5px;">📥 সব ইমপোর্ট</button>
                        <button onclick="location.reload()">🔄 রিফ্রেশ</button>
                    </div>
                </div>
                <div class="checkbox-group" style="margin-bottom: 15px; border-top: 1px solid var(--border); padding-top: 15px;">
                    <label class="checkbox-item">
                        <input type="checkbox" id="dropTableBackupImport">
                        <span>DROP TABLE স্টেটমেন্ট অনুমোদন করুন</span>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="enableFKBackupImport">
                        <span>Enable Foreign Key Checks</span>
                    </label>
                </div>
                <?php $backupFiles = getBackupFiles(); ?>
                <?php if (count($backupFiles) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                                <th>#</th>
                                <th>ফাইলের নাম</th>
                                <th>সাইজ</th>
                                <th>তারিখ</th>
                                <th>অ্যাকশন</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 0;
                            foreach ($backupFiles as $file): $i++; ?>
                                <tr>
                                    <td style="text-align: center;"><input type="checkbox" class="backup-checkbox" value="<?= htmlspecialchars($file['name']) ?>"></td>
                                    <td><?= $i ?></td>
                                    <td><?= htmlspecialchars($file['name']) ?></td>
                                    <td><?= $file['size'] ?></td>
                                    <td><?= $file['date'] ?></td>
                                    <td>
                                        <button class="btn-view" onclick="importBackup('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')" style="background-color: var(--info); color: white;">📥 ইমপোর্ট</button>
                                        <button class="btn-view" onclick="previewBackup('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">👁️ প্রিভিউ</button>
                                        <button class="btn-optimize" onclick="downloadBackup('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">💾 ডাউনলোড</button>
                                        <button class="btn-empty" onclick="deleteBackup('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">🗑️ ডিলিট</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align:center; color:#777;">কোন ব্যাকআপ ফাইল পাওয়া যায়নি</p>
                <?php endif; ?>
            </div>

            <!-- Schema Diagram -->
            <div class="section" style="margin-top: 24px;">
                <h2>📊 ডাটাবেস স্কিমা ডায়াগ্রাম | Database Schema Diagram</h2>
                <div style="margin-bottom: 15px;">
                    <button onclick="loadSchemaDiagram()">🔄 ডায়াগ্রাম লোড করুন</button>
                    <span id="diagramStatus" style="margin-left: 10px; color: #666;"></span>
                </div>
                <div id="diagramContainer" style="overflow: auto; border: 1px solid #e9ecef; border-radius: 8px; background: white; padding: 20px; min-height: 400px; text-align: center;">
                    <div id="mermaidDiagram" class="mermaid"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- View Modal -->
    <div id="viewTableModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTableName">টেবিল ডাটা</h2>
                <span class="close" onclick="document.getElementById('viewTableModal').style.display='none'">&times;</span>
            </div>
            <div class="modal-body">
                <div id="tableDataContainer"></div>
                <div id="paginationContainer" style="text-align:center; margin-top:20px;"></div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewSqlModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalPreviewFileName">SQL প্রিভিউ</h2>
                <span class="close" onclick="document.getElementById('previewSqlModal').style.display='none'">&times;</span>
            </div>
            <div class="modal-body">
                <p>এই ফাইল থেকে প্রথম কয়েকটি কোয়েরি নিচে দেখানো হলো। (This is a preview of the first few queries from the file.)</p>
                <div id="sqlPreviewContainer" style="background: #2d2d2d; color: #f1f1f1; padding: 20px; border-radius: 8px; max-height: 50vh; overflow: auto;">
                    <pre><code id="sqlPreviewCode"></code></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 id="confirmModalTitle">নিশ্চিত করুন | Confirmation</h2>
                <span class="close" onclick="document.getElementById('confirmModal').style.display='none'">&times;</span>
            </div>
            <div class="modal-body">
                <p id="confirmModalMessage" style="font-size: 1.1rem;"></p>
            </div>
            <div class="modal-footer">
                <button id="confirmBtnCancel" style="background-color: var(--secondary);">বাতিল | Cancel</button>
                <button id="confirmBtnOk" style="background-color: var(--danger);">ঠিক আছে | OK</button>
            </div>
        </div>
    </div>

    <!-- Full Export Modal -->
    <div id="fullExportModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>সম্পূর্ণ ডাটাবেস এক্সপোর্ট</h2>
                <span class="close" onclick="document.getElementById('fullExportModal').style.display='none'">&times;</span>
            </div>
            <div class="modal-body">
                <p>আপনি কিভাবে ডাটাবেস এক্সপোর্ট করতে চান?</p>
                <div class="checkbox-group" style="margin-top: 20px;">
                    <label class="checkbox-item"><input type="radio" name="export_mode" value="separate" checked><span>আলাদা ফাইল (প্রতি টেবিলের জন্য একটি)</span></label>
                    <label class="checkbox-item"><input type="radio" name="export_mode" value="single"><span>একটি একক ফাইল (সম্পূর্ণ ডাটাবেস)</span></label>
                </div>
            </div>
            <div class="modal-footer"><button onclick="document.getElementById('fullExportModal').style.display='none'" style="background-color: var(--secondary);">বাতিল</button><button onclick="executeFullExportChoice()" style="background-color: var(--primary);">এক্সপোর্ট করুন</button></div>
        </div>
    </div>

    <!-- Create Table Modal -->
    <div id="createTableModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2>➕ নতুন টেবিল তৈরি করুন | Create New Table</h2>
                <span class="close" onclick="document.getElementById('createTableModal').style.display='none'">&times;</span>
            </div>
            <form id="createTableForm" onsubmit="event.preventDefault(); submitCreateTable();">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="newTableName">টেবিলের নাম | Table Name</label>
                        <input type="text" id="newTableName" name="table_name" required pattern="[a-zA-Z0-9_]+" title="Only letters, numbers, and underscores are allowed.">
                    </div>
                    <hr style="margin: 20px 0;">
                    <h4>কলাম | Columns</h4>
                    <div id="columnsContainer"></div>
                    <button type="button" onclick="addColumnToModal()" style="margin-top: 10px; background-color: var(--secondary);">➕ কলাম যোগ করুন | Add Column</button>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="document.getElementById('createTableModal').style.display='none'" style="background-color: var(--secondary);">বাতিল | Cancel</button>
                    <button type="submit" style="background-color: var(--success);">তৈরি করুন | Create Table</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        var total = 0,
            offset = 0,
            file = '',
            table = '';
        var fullExportTasks = [],
            currentTaskIndex = 0;
        var singleFileName = '',
            singleFileTasks = [],
            currentTableIdx = 0,
            currentOffset = 0;
        var currentViewTable = '',
            currentPage = 1,
            currentSearch = '',
            currentSort = '',
            currentOrder = 'ASC';
        let confirmCallback = null;

        function showInfo(message, title = 'Information') {
            const modal = document.getElementById('confirmModal');
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmModalMessage').textContent = message;
            document.getElementById('confirmBtnCancel').style.display = 'none';
            const btnOk = document.getElementById('confirmBtnOk');
            btnOk.textContent = 'OK';
            btnOk.style.backgroundColor = 'var(--primary)';

            modal.style.display = 'block';

            btnOk.onclick = () => {
                modal.style.display = 'none';
            };
            document.querySelector('#confirmModal .close').onclick = () => {
                modal.style.display = 'none';
            };
        }

        function showConfirm(message, onConfirm, title = 'নিশ্চিত করুন | Confirmation', okText = 'ঠিক আছে | OK', okColor = 'var(--danger)') {
            const modal = document.getElementById('confirmModal');
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmModalMessage').textContent = message;

            const btnOk = document.getElementById('confirmBtnOk');
            const btnCancel = document.getElementById('confirmBtnCancel');

            btnOk.textContent = okText;
            btnOk.style.backgroundColor = okColor;
            btnCancel.style.display = 'inline-flex';

            modal.style.display = 'block';

            btnOk.onclick = () => {
                modal.style.display = 'none';
                onConfirm();
            };
            btnCancel.onclick = () => modal.style.display = 'none';
            document.querySelector('#confirmModal .close').onclick = () => modal.style.display = 'none';
        }

        function showMessage(msg, type = 'success') {
            const title = type === 'success' ? '✅ সফল | Success' : '❌ ত্রুটি | Error';
            showInfo(msg, title);
        }

        function toggleLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        async function makeRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            for (let k in data) formData.append(k, data[k]);

            const res = await fetch('', {
                method: 'POST',
                body: formData
            });
            if (!res.ok) throw new Error('Server error ' + res.status);
            const json = await res.json();
            if (!json.success) throw new Error(json.message || 'Unknown error');
            return json;
        }

        // Server Info
        async function loadServerInfo() {
            toggleLoading(true);
            try {
                const res = await makeRequest('server_info');
                toggleLoading(false);

                let html = '<table style="width:100%; border-collapse: collapse;">';
                const row = (label, val) => `<tr><td style="padding:8px; border-bottom:1px solid #eee; width:200px;"><strong>${label}:</strong></td><td style="padding:8px; border-bottom:1px solid #eee;">${val}</td></tr>`;

                html += row('PHP Version', res.php_version);
                html += row('MySQL Version', res.mysql_version);
                html += row('Server Software', res.server_software);
                html += row('Memory Usage', res.memory_usage);
                html += row('Max Upload Size', res.max_upload_size);
                html += row('Post Max Size', res.post_max_size);
                html += row('DB Host', res.db_host);
                html += row('DB Name', res.db_name);
                html += row('DB User', res.db_user);
                html += row('Connection Status', '<span style="color:var(--success); font-weight:bold;">Connected ✅</span>');
                html += row('DB Stats', res.db_stat);
                html += '</table>';
                html += '<div style="margin-top:15px;"><button onclick="loadServerInfo()">🔄 রিফ্রেশ (Refresh)</button></div>';

                document.getElementById('serverInfoContent').innerHTML = html;
                showMessage('Server info loaded', 'success');
            } catch (e) {
                toggleLoading(false);
                document.getElementById('serverInfoContent').innerHTML = `<p style="color:red; font-weight:bold;">Connection Failed ❌</p><p>Error: ${e.message}</p><button onclick="loadServerInfo()">Retry</button>`;
                showMessage('Error loading server info', 'error');
            }
        }

        // Single table export
        async function startExport() {
            table = document.getElementById('tableSelect').value;
            if (!table) return showInfo('Please select a table to export.', 'টেবিল নির্বাচন করুন');

            const allowDrop = document.getElementById('dropTableSingle').checked;
            const structureOnly = document.getElementById('structureOnlySingle').checked;

            const btn = document.getElementById('exportBtn');
            btn.disabled = true;
            btn.textContent = '⏳ প্রসেস চলছে...';

            document.getElementById('progressContainer').style.display = 'block';

            try {
                const r = await makeRequest('export_init', {
                    table,
                    allowDrop: allowDrop.toString(),
                    structureOnly: structureOnly.toString()
                });
                total = r.total;
                file = r.file;
                offset = 0;

                if (structureOnly) {
                    showMessage(`স্ট্রাকচার এক্সপোর্ট সম্পন্ন! ফাইল: ${file}`, 'success');
                    btn.disabled = false;
                    btn.textContent = '🚀 এক্সপোর্ট শুরু করুন';
                    document.getElementById('progressContainer').style.display = 'none';
                    setTimeout(() => location.reload(), 2000);
                } else {
                    document.getElementById('status').textContent = `0 / ${total} rows`;
                    exportNextChunk();
                }
            } catch (e) {
                showMessage('ত্রুটি: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = '🚀 এক্সপোর্ট শুরু করুন';
                document.getElementById('progressContainer').style.display = 'none';
            }
        }

        async function exportSingleTable(tableName) {
            showConfirm(`Are you sure you want to export the table "${tableName}"?`, () => {
                document.getElementById('tableSelect').value = tableName;
                // Scroll to the export section for user feedback
                const exportSectionTitle = Array.from(document.querySelectorAll('.section h2')).find(h => h.textContent.includes('Export Table'));
                if (exportSectionTitle) {
                    exportSectionTitle.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
                startExport();
            }, 'Confirm Export', 'Yes, Export', 'var(--primary)');
        }

        async function exportNextChunk() {
            try {
                const r = await makeRequest('export_chunk', {
                    table,
                    file,
                    offset
                });
                offset += r.processed;

                const percent = Math.min(100, Math.round((offset / total) * 100));
                document.getElementById('progress').value = percent;
                document.getElementById('status').textContent = `${offset} / ${total} rows (${percent}%)`;

                if (offset < total && r.processed > 0) {
                    exportNextChunk();
                } else {
                    showMessage(`এক্সপোর্ট সম্পন্ন! ফাইল: ${file}`, 'success');
                    document.getElementById('exportBtn').disabled = false;
                    document.getElementById('exportBtn').textContent = '🚀 এক্সপোর্ট শুরু করুন';
                    setTimeout(() => location.reload(), 2000);
                }
            } catch (e) {
                showMessage('ত্রুটি: ' + e.message, 'error');
                document.getElementById('exportBtn').disabled = false;
                document.getElementById('exportBtn').textContent = '🚀 এক্সপোর্ট শুরু করুন';
            }
        }

        // Full export - mode selection
        function confirmFullExport() {
            document.getElementById('fullExportModal').style.display = 'block';
        }

        function executeFullExportChoice() {
            const isSingle = document.querySelector('input[name="export_mode"]:checked').value === 'single';
            document.getElementById('fullExportModal').style.display = 'none';
            startFullExport(isSingle);
        }

        async function startFullExport(isSingleFile) {
            const allowDrop = document.getElementById('dropTableFull').checked;
            const structureOnly = document.getElementById('structureOnlyFull').checked;

            const btn = document.getElementById('fullExportBtn') || document.querySelector('button[onclick*="confirmFullExport"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '⏳ প্রসেস চলছে...';
            }

            document.getElementById('fullProgressContainer').style.display = 'block';
            document.getElementById('fullProgress').value = 0;

            try {
                if (isSingleFile) {
                    const res = await makeRequest('full_single_init', {
                        allowDrop: allowDrop.toString(),
                        structureOnly: structureOnly.toString()
                    });

                    singleFileName = res.filename;
                    singleFileTasks = res.tasks;
                    currentTableIdx = 0;
                    currentOffset = 0;

                    document.getElementById('fullStatus').textContent = `একক ফাইল: ${singleFileName} (${singleFileTasks.length} টেবিল)`;

                    await processSingleFileNext();
                } else {
                    const res = await makeRequest('full_export_init', {
                        allowDrop: allowDrop.toString(),
                        structureOnly: structureOnly.toString()
                    });

                    fullExportTasks = res.tasks;
                    currentTaskIndex = 0;

                    document.getElementById('fullStatus').textContent = `মোট টেবিল: ${res.totalTables}`;

                    await processNextTable(allowDrop, structureOnly);
                }
            } catch (e) {
                showMessage('ত্রুটি: ' + e.message, 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🚀 সম্পূর্ণ ডাটাবেস এক্সপোর্ট করুন';
                }
                document.getElementById('fullProgressContainer').style.display = 'none';
            }
        }

        // Separate files mode processing (your original logic)
        async function processNextTable(allowDrop, structureOnly) {
            if (currentTaskIndex >= fullExportTasks.length) {
                showMessage(`সম্পূর্ণ ডাটাবেস এক্সপোর্ট সম্পন্ন! ${fullExportTasks.length} টেবিল`, 'success');
                document.getElementById('fullExportBtn').disabled = false;
                document.getElementById('fullExportBtn').textContent = '🚀 সম্পূর্ণ ডাটাবেস এক্সপোর্ট করুন';
                setTimeout(() => location.reload(), 2000);
                return;
            }

            const task = fullExportTasks[currentTaskIndex];
            const tbl = task.table;

            document.getElementById('fullStatus').textContent = `প্রসেস হচ্ছে: ${tbl} (${currentTaskIndex + 1}/${fullExportTasks.length})`;

            try {
                const init = await makeRequest('export_init', {
                    table: tbl,
                    allowDrop: allowDrop.toString(),
                    structureOnly: structureOnly.toString()
                });

                if (!structureOnly) {
                    let off = 0;
                    while (true) {
                        const chunk = await makeRequest('export_chunk', {
                            table: tbl,
                            file: init.file,
                            offset: off
                        });
                        off += chunk.processed;
                        if (chunk.processed === 0) break;
                    }
                }

                currentTaskIndex++;
                const percent = Math.round((currentTaskIndex / fullExportTasks.length) * 100);
                document.getElementById('fullProgress').value = percent;

                processNextTable(allowDrop, structureOnly);
            } catch (e) {
                showMessage('ত্রুটি: ' + e.message, 'error');
                document.getElementById('fullExportBtn').disabled = false;
                document.getElementById('fullExportBtn').textContent = '🚀 সম্পূর্ণ ডাটাবেস এক্সপোর্ট করুন';
            }
        }

        // Single file mode processing
        async function processSingleFileNext() {
            if (currentTableIdx >= singleFileTasks.length) {
                await makeRequest('finalize_single', {
                    filename: singleFileName
                });
                showMessage(`সম্পন্ন! একক ফাইল তৈরি: ${singleFileName}\n${singleFileTasks.length} টেবিল`, 'success');
                document.getElementById('fullExportBtn').disabled = false;
                document.getElementById('fullExportBtn').textContent = '🚀 সম্পূর্ণ ডাটাবেস এক্সপোর্ট করুন';
                setTimeout(() => location.reload(), 3000);
                return;
            }

            const task = singleFileTasks[currentTableIdx];
            const tbl = task.table;

            document.getElementById('fullStatus').textContent = `প্রসেস হচ্ছে: ${tbl} (${currentTableIdx + 1}/${singleFileTasks.length})`;

            try {
                const r = await makeRequest('export_table_single_chunk', {
                    table: tbl,
                    filename: singleFileName,
                    offset: currentOffset,
                    allowDrop: document.getElementById('dropTableFull').checked.toString(),
                    structureOnly: document.getElementById('structureOnlyFull').checked.toString()
                });

                if (r.finished) {
                    currentTableIdx++;
                    currentOffset = 0;
                    const percent = Math.round((currentTableIdx / singleFileTasks.length) * 100);
                    document.getElementById('fullProgress').value = percent;
                } else {
                    currentOffset += r.processed;
                }

                processSingleFileNext();
            } catch (e) {
                showMessage('ত্রুটি: ' + e.message, 'error');
                document.getElementById('fullExportBtn').disabled = false;
                document.getElementById('fullExportBtn').textContent = '🚀 সম্পূর্ণ ডাটাবেস এক্সপোর্ট করুন';
            }
        }

        // Import form
        document.getElementById('importForm').addEventListener('submit', async e => {
            e.preventDefault();
            showConfirm('Are you sure you want to import this file? This may overwrite existing data.', async () => {
                const allowDrop = document.getElementById('dropTableImport').checked;
                const enableFK = document.getElementById('enableFKImport').checked;
                toggleLoading(true);

                const formData = new FormData(e.target);
                formData.append('action', 'import');
                formData.append('allowDrop', allowDrop.toString());
                formData.append('enableFK', enableFK.toString());

                try {
                    const res = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    const json = await res.json();
                    toggleLoading(false);

                    if (json.success) {
                        showMessage(json.message + `\n${json.count} queries executed`, 'success');
                        if (json.errors?.length) console.warn('Import warnings:', json.errors);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showMessage(json.message, 'error');
                    }
                } catch (err) {
                    toggleLoading(false);
                    showMessage('ইমপোর্ট ত্রুটি: ' + err.message, 'error');
                }
            }, 'Confirm Import', 'Yes, Import', 'var(--primary)');
        });

        // প্রিভিউ (আপলোড করা ফাইল)
        async function previewImportUpload() {
            const fileInput = document.getElementById('sqlFile');
            if (!fileInput.files || fileInput.files.length === 0) {
                return showInfo('প্রথমে একটি SQL ফাইল নির্বাচন করুন।', 'File Required');
            }

            toggleLoading(true);
            const formData = new FormData();
            formData.append('action', 'preview_import');
            formData.append('sql_file', fileInput.files[0]);
            try {
                const res = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                toggleLoading(false);

                if (json.success) {
                    document.getElementById('modalPreviewFileName').textContent = `প্রিভিউ: ${fileInput.files[0].name}`;
                    document.getElementById('sqlPreviewCode').textContent = json.queries.join('\n\n');
                    document.getElementById('previewSqlModal').style.display = 'block';
                } else {
                    showMessage(json.message, 'error');
                }
            } catch (err) {
                toggleLoading(false);
                showMessage('প্রিভিউ দেখাতে ত্রুটি: ' + err.message, 'error');
            }
        }

        // প্রিভিউ (সংরক্ষিত ব্যাকআপ)
        async function previewBackup(filename) {
            toggleLoading(true);
            try {
                const result = await makeRequest('preview_import', {
                    filename
                });
                toggleLoading(false);

                if (result.success) {
                    document.getElementById('modalPreviewFileName').textContent = `প্রিভিউ: ${filename}`;
                    document.getElementById('sqlPreviewCode').textContent = result.queries.join('\n\n');
                    document.getElementById('previewSqlModal').style.display = 'block';
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                toggleLoading(false);
                showMessage('প্রিভিউ দেখাতে ত্রুটি: ' + error.message, 'error');
            }
        }

        // ব্যাকআপ ইমপোর্ট করুন
        async function importBackup(filename) {
            showConfirm(`Import backup file "${filename}"? This may overwrite existing data.`, async () => {
                const allowDrop = document.getElementById('dropTableBackupImport').checked;
                const enableFK = document.getElementById('enableFKBackupImport').checked;
                toggleLoading(true);

                try {
                    const result = await makeRequest('import_backup', {
                        filename: filename,
                        allowDrop: allowDrop.toString(),
                        enableFK: enableFK.toString()
                    });

                    toggleLoading(false);

                    let message = `✅ ${result.message}\n${result.count} queries executed`;
                    if (result.errors && result.errors.length > 0) {
                        message += `\n\nWarnings:\n${result.errors.slice(0, 3).join('\n')}`;
                    }
                    showMessage(message, 'success');
                    setTimeout(() => location.reload(), 2000);

                } catch (error) {
                    toggleLoading(false);
                    showMessage('ত্রুটি: ' + error.message, 'error');
                }
            }, 'Confirm Import', 'Yes, Import', 'var(--primary)');
        }

        // ব্যাকআপ ডাউনলোড করুন
        function downloadBackup(filename) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'download';

            const filenameInput = document.createElement('input');
            filenameInput.type = 'hidden';
            filenameInput.name = 'filename';
            filenameInput.value = filename;

            form.appendChild(actionInput);
            form.appendChild(filenameInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // ব্যাকআপ ডিলিট করুন
        async function deleteBackup(filename) {
            showConfirm(`Are you sure you want to delete "${filename}"?`, async () => {
                toggleLoading(true);
                try {
                    const result = await makeRequest('delete_backup', {
                        filename: filename
                    });

                    toggleLoading(false);
                    showMessage('✅ ' + result.message, 'success');
                    setTimeout(() => location.reload(), 1500);

                } catch (error) {
                    toggleLoading(false);
                    showMessage('ত্রুটি: ' + error.message, 'error');
                }
            });
        }

        // টেবিল ডাটা দেখুন
        async function viewTable(tableName, page = 1, search = '', sort = '', order = 'ASC') {
            currentViewTable = tableName;
            currentPage = page;
            currentSearch = search;
            currentSort = sort;
            currentOrder = order;

            toggleLoading(true);

            try {
                const result = await makeRequest('view_table', {
                    table: tableName,
                    page: page,
                    search: search,
                    sort: sort,
                    order: order
                });

                toggleLoading(false);

                // Show modal
                document.getElementById('modalTableName').textContent = `টেবিল: ${tableName} | Table: ${tableName}`;

                // Build table HTML
                let html = '<div style="margin-bottom:15px; display:flex; gap:10px;">';
                html += `<input type="text" id="modalSearchInput" value="${result.search}" placeholder="Search in table..." style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">`;
                html += `<button onclick="viewTable('${tableName}', 1, document.getElementById('modalSearchInput').value, '${result.sort}', '${result.order}')" style="padding:8px 15px;">Search</button>`;
                html += `<button onclick="viewTable('${tableName}', 1, '', '', 'ASC')" style="padding:8px 15px; background:#6c757d;">Reset</button>`;
                html += '</div>';

                html += '<div style="overflow-x: auto;"><table>';

                // Table header
                html += '<thead><tr>';
                result.columns.forEach(col => {
                    let sortIcon = result.sort === col.Field ? (result.order === 'ASC' ? ' ▲' : ' ▼') : '';
                    let nextOrder = result.sort === col.Field && result.order === 'ASC' ? 'DESC' : 'ASC';
                    html += `<th style="cursor:pointer;" onclick="viewTable('${tableName}', 1, '${result.search}', '${col.Field}', '${nextOrder}')">${col.Field}${sortIcon}<br><small style="font-weight: normal; opacity: 0.8;">${col.Type}</small></th>`;
                });
                html += '</tr></thead>';

                // Table body
                html += '<tbody>';
                if (result.data.length > 0) {
                    result.data.forEach(row => {
                        html += '<tr>';
                        Object.values(row).forEach(val => {
                            const displayVal = val === null ? '<em style="color: #999;">NULL</em>' :
                                (val.toString().length > 100 ? val.toString().substring(0, 100) + '...' : val);
                            html += `<td title="${val}">${displayVal}</td>`;
                        });
                        html += '</tr>';
                    });
                } else {
                    html += `<tr><td colspan="${result.columns.length}" style="text-align: center; padding: 40px; color: #999;">টেবিল খালি | Table is empty</td></tr>`;
                }
                html += '</tbody></table></div>';

                document.getElementById('tableDataContainer').innerHTML = html;

                // Build pagination
                let paginationHTML = '';
                if (result.totalPages > 1) {
                    paginationHTML += `<button onclick="viewTable(currentViewTable, ${Math.max(1, page - 1)}, currentSearch, currentSort, currentOrder)" ${page === 1 ? 'disabled' : ''}>« পূর্ববর্তী | Previous</button>`;
                    paginationHTML += `<span>পৃষ্ঠা ${page} / ${result.totalPages} | Page ${page} of ${result.totalPages}</span>`;
                    paginationHTML += `<button onclick="viewTable(currentViewTable, ${Math.min(result.totalPages, page + 1)}, currentSearch, currentSort, currentOrder)" ${page === result.totalPages ? 'disabled' : ''}>পরবর্তী | Next »</button>`;
                } else {
                    paginationHTML = `<span>মোট ${result.totalRows} টি সারি | Total ${result.totalRows} rows</span>`;
                }
                document.getElementById('paginationContainer').innerHTML = paginationHTML;

                // Show modal
                document.getElementById('viewTableModal').style.display = 'block';

            } catch (error) {
                toggleLoading(false);
                showMessage('ত্রুটি: ' + error.message, 'error');
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('viewTableModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('viewTableModal');
            const previewModal = document.getElementById('previewSqlModal');
            if (event.target === modal || event.target === previewModal) {
                closeModal();
                previewModal.style.display = 'none';
            }
        }

        // টেবিল খালি করার নিশ্চিতকরণ
        function emptyTableConfirm(tableName) {
            const message = `⚠️ সতর্কতা! আপনি কি নিশ্চিত "${tableName}" টেবিলের সমস্ত ডাটা মুছে ফেলতে চান?\n\nThis action cannot be undone!`;
            showConfirm(message, () => emptyTableAction(tableName));
        }

        // টেবিল খালি করুন
        async function emptyTableAction(tableName) {
            toggleLoading(true);
            try {
                const result = await makeRequest('empty_table', {
                    table: tableName
                });
                toggleLoading(false);
                showMessage(`✅ ${result.message}\n${result.rowsDeleted} টি সারি মুছে ফেলা হয়েছে | ${result.rowsDeleted} rows deleted`, 'success');
                setTimeout(() => location.reload(), 2000);
            } catch (error) {
                toggleLoading(false);
                showMessage('ত্রুটি: ' + error.message, 'error');
            }
        }

        // Drop table confirmation
        function dropTableConfirm(tableName) {
            const message = `🔥🔥🔥 WARNING! Are you sure you want to permanently DROP the table "${tableName}"?\n\nThis action is irreversible and will delete the table structure and all its data!`;
            showConfirm(message, () => dropTableAction(tableName));
        }

        // Drop table action
        async function dropTableAction(tableName) {
            toggleLoading(true);
            try {
                const result = await makeRequest('drop_table', {
                    table: tableName
                });
                toggleLoading(false);
                showMessage(`✅ ${result.message}`, 'success');
                setTimeout(() => location.reload(), 2000);
            } catch (error) {
                toggleLoading(false);
                showMessage('Error: ' + error.message, 'error');
            }
        }

        // Load Schema Diagram
        async function loadSchemaDiagram() {
            const container = document.getElementById('mermaidDiagram');
            const status = document.getElementById('diagramStatus');

            // Load Mermaid if not present
            if (typeof mermaid === 'undefined') {
                status.textContent = 'লাইব্রেরি লোড হচ্ছে...';
                await new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js';
                    script.onload = resolve;
                    script.onerror = () => reject(new Error('Mermaid JS load failed'));
                    document.head.appendChild(script);
                });
                mermaid.initialize({
                    startOnLoad: false,
                    theme: 'default'
                });
            }

            status.textContent = 'ডাটা লোড হচ্ছে...';
            toggleLoading(true);

            try {
                const result = await makeRequest('get_schema');
                toggleLoading(false);

                if (!result.success) throw new Error(result.message);

                status.textContent = 'ডায়াগ্রাম তৈরি হচ্ছে...';

                let graph = 'erDiagram\n';

                result.tables.forEach(t => {
                    graph += `    ${t} {}\n`;
                });

                result.relationships.forEach(rel => {
                    graph += `    ${rel.TABLE_NAME} }|..|| ${rel.REFERENCED_TABLE_NAME} : "${rel.COLUMN_NAME}"\n`;
                });

                container.innerHTML = graph;
                container.removeAttribute('data-processed');

                await mermaid.run({
                    nodes: [container]
                });
                status.textContent = '';
            } catch (error) {
                toggleLoading(false);
                status.textContent = 'ত্রুটি';
                showMessage('ডায়াগ্রাম ত্রুটি: ' + error.message, 'error');
            }
        }

        // Table Search Function
        function searchTable() {
            var input, filter, table, tr, td, i, txtValue;
            input = document.getElementById("tableSearchInput");
            filter = input.value.toUpperCase();
            table = document.getElementById("managementTable");
            tr = table.getElementsByTagName("tr");
            for (i = 0; i < tr.length; i++) {
                td = tr[i].getElementsByTagName("td")[0];
                if (td) {
                    txtValue = td.textContent || td.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        // Optimize Table
        async function optimizeTable(tableName) {
            showConfirm(`Optimize table "${tableName}"? This may take some time.`, async () => {
                toggleLoading(true);
                try {
                    const res = await makeRequest('optimize_table', {
                        table: tableName
                    });
                    toggleLoading(false);
                    showMessage(res.message, 'success');
                } catch (e) {
                    toggleLoading(false);
                    showMessage('Error: ' + e.message, 'error');
                }
            }, 'Confirm Optimize', 'Optimize', 'var(--primary)');
        }

        // Repair Table
        async function repairTable(tableName) {
            showConfirm(`Repair table "${tableName}"?`, async () => {
                toggleLoading(true);
                try {
                    const res = await makeRequest('repair_table', {
                        table: tableName
                    });
                    toggleLoading(false);
                    showMessage(res.message, 'success');
                } catch (e) {
                    toggleLoading(false);
                    showMessage('Error: ' + e.message, 'error');
                }
            }, 'Confirm Repair', 'Repair', 'var(--warning)');
        }

        // Run Custom SQL
        async function runQuery() {
            const sql = document.getElementById('sqlInput').value;
            if (!sql.trim()) return showInfo('Please enter a query.', 'Input Required');
            const disableFK = document.getElementById('disableFkSql').checked;

            toggleLoading(true);
            try {
                const res = await makeRequest('execute_query', {
                    sql,
                    disable_fk: disableFK.toString()
                });
                toggleLoading(false);

                const container = document.getElementById('queryResult');
                container.innerHTML = '';

                if (res.type === 'result' && res.data) {
                    let html = '<table><thead><tr>';
                    res.fields.forEach(f => html += `<th>${f.name}</th>`);
                    html += '</tr></thead><tbody>';
                    res.data.forEach(row => {
                        html += '<tr>';
                        for (let k in row) html += `<td>${row[k] === null ? 'NULL' : row[k]}</td>`;
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    html += `<p style="margin-top:10px; color:#666;">Total rows: ${res.count}</p>`;
                    container.innerHTML = html;
                } else {
                    showMessage(res.message + (res.affected_rows !== undefined ? ` (Affected rows: ${res.affected_rows})` : ''), 'success');
                }
            } catch (e) {
                toggleLoading(false);
                showMessage('Query Error: ' + e.message, 'error');
            }
        }

        function openCreateTableModal() {
            document.getElementById('createTableForm').reset();
            const columnsContainer = document.getElementById('columnsContainer');
            columnsContainer.innerHTML = '';
            // Add a few default columns to start with
            addColumnToModal('id', 'INT', '11', 'PRIMARY', true);
            addColumnToModal('name', 'VARCHAR', '255');
            addColumnToModal('created_at', 'TIMESTAMP', '', '', false, 'CURRENT_TIMESTAMP');
            document.getElementById('createTableModal').style.display = 'block';
        }

        function addColumnToModal(name = '', type = 'VARCHAR', length = '255', index = '', is_ai = false, default_val = '') {
            const container = document.getElementById('columnsContainer');
            const columnRow = document.createElement('div');
            columnRow.className = 'column-row';
            columnRow.style.display = 'flex';
            columnRow.style.gap = '10px';
            columnRow.style.marginBottom = '10px';
            columnRow.style.alignItems = 'center';

            const dataTypes = ['INT', 'VARCHAR', 'TEXT', 'DATE', 'TIMESTAMP', 'DECIMAL', 'BOOLEAN', 'DATETIME'];
            const indexTypes = ['', 'PRIMARY', 'UNIQUE', 'INDEX'];

            let typeOptions = dataTypes.map(dt => `<option value="${dt}" ${dt === type ? 'selected' : ''}>${dt}</option>`).join('');
            let indexOptions = indexTypes.map(it => `<option value="${it}" ${it === index ? 'selected' : ''}>${it || '---'}</option>`).join('');

            columnRow.innerHTML = `
                <input type="text" name="col_name[]" placeholder="Column Name" value="${name}" required style="flex: 2;">
                <select name="col_type[]" style="flex: 1.5;">${typeOptions}</select>
                <input type="text" name="col_length[]" placeholder="Length" value="${length}" style="flex: 1;">
                <input type="text" name="col_default[]" placeholder="Default" value="${default_val}" style="flex: 1.5;">
                <select name="col_index[]" style="flex: 1.5;">${indexOptions}</select>
                <label style="display:flex; align-items:center; gap: 5px; flex: 0.5;"><input type="checkbox" name="col_ai[]" ${is_ai ? 'checked' : ''}> A_I</label>
                <button type="button" onclick="this.parentElement.remove()" class="btn-danger" style="padding: 5px 10px;">-</button>
            `;
            container.appendChild(columnRow);
        }

        async function submitCreateTable() {
            const form = document.getElementById('createTableForm');
            const formData = new FormData(form);
            const data = {
                table_name: formData.get('table_name'),
                columns: []
            };

            const names = formData.getAll('col_name[]');
            const types = formData.getAll('col_type[]');
            const lengths = formData.getAll('col_length[]');
            const defaults = formData.getAll('col_default[]');
            const indices = formData.getAll('col_index[]');
            const ais = document.querySelectorAll('input[name="col_ai[]"]');

            for (let i = 0; i < names.length; i++) {
                if (names[i]) {
                    data.columns.push({
                        name: names[i],
                        type: types[i],
                        length: lengths[i],
                        default: defaults[i],
                        index: indices[i],
                        ai: ais[i].checked
                    });
                }
            }

            if (data.columns.length === 0) return showInfo('Please add at least one column.', 'No Columns');

            toggleLoading(true);
            try {
                const result = await makeRequest('create_table', {
                    table_data: JSON.stringify(data)
                });
                toggleLoading(false);
                document.getElementById('createTableModal').style.display = 'none';
                showMessage(result.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } catch (error) {
                toggleLoading(false);
                showMessage('Error creating table: ' + error.message, 'error');
            }
        }

        async function toggleTableDetails(tableName, element) {
            const tr = element.closest('tr');
            const nextTr = tr.nextElementSibling;
            const colCount = tr.cells.length;

            if (nextTr && nextTr.classList.contains('details-row')) {
                nextTr.remove();
                element.innerHTML = '...';
                return;
            }

            element.innerHTML = '⏳';

            // Add loading row
            const loadingRow = tr.insertAdjacentElement('afterend', document.createElement('tr'));
            loadingRow.className = 'details-row';
            loadingRow.innerHTML = `<td colspan="${colCount}" style="text-align:center; padding:20px; background: #fdfdfd;">Loading details...</td>`;

            try {
                const res = await makeRequest('get_table_details', {
                    table: tableName
                });
                loadingRow.remove(); // remove loading

                const detailsRow = tr.insertAdjacentElement('afterend', document.createElement('tr'));
                detailsRow.className = 'details-row';
                let html = `<td colspan="${colCount}" style="padding: 20px; background: #f9fafb;">`;
                html += `<h5 style="margin-bottom:10px; font-size:1.1rem;">Columns for <strong>${tableName}</strong></h5>`;
                html += '<div style="overflow-x:auto;"><table><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
                res.columns.forEach(col => {
                    html += `<tr>
                        <td><strong>${col.Field}</strong></td>
                        <td>${col.Type}</td>
                        <td>${col.Null}</td>
                        <td>${col.Key}</td>
                        <td>${col.Default === null ? '<em>NULL</em>' : col.Default}</td>
                        <td>${col.Extra}</td>
                    </tr>`;
                });
                html += '</tbody></table></div></td>';
                detailsRow.innerHTML = html;
                element.innerHTML = '...';

            } catch (e) {
                loadingRow.innerHTML = `<td colspan="${colCount}" style="text-align:center; padding:20px; color:red; background: #fff5f5;">Error: ${e.message}</td>`;
                element.innerHTML = '...';
            }
        }

        function importAllBackups() {
            showConfirm(
                'Are you sure you want to import ALL backup files? This will execute them sequentially (Oldest to Newest). This action cannot be undone.',
                async () => {
                        // Check if drop table option is checked in the import section
                        const allowDrop = document.getElementById('dropTableBackupImport').checked;
                        const enableFK = document.getElementById('enableFKBackupImport').checked;

                        toggleLoading(true);
                        const loadingMsg = document.querySelector('#loading p');
                        const originalMsg = loadingMsg.textContent;

                        try {
                            const res = await makeRequest('get_backup_files');
                            let files = res.files;

                            if (!files || files.length === 0) {
                                toggleLoading(false);
                                return showMessage('No backup files found.', 'error');
                            }

                            // Reverse to process oldest first (assuming list is DESC date)
                            files.reverse();

                            let errors = [];
                            for (let i = 0; i < files.length; i++) {
                                const file = files[i];
                                loadingMsg.textContent = `Importing (${i + 1}/${files.length}): ${file.name}...`;

                                try {
                                    await makeRequest('import_backup', {
                                        filename: file.name,
                                        allowDrop: allowDrop.toString(),
                                        enableFK: enableFK.toString()
                                    });
                                } catch (e) {
                                    errors.push(`${file.name}: ${e.message}`);
                                }
                            }

                            toggleLoading(false);
                            loadingMsg.textContent = originalMsg;

                            if (errors.length > 0) {
                                showMessage(`Completed with errors.\nFailed: ${errors.length}\n\nErrors:\n${errors.slice(0,3).join('\n')}`, 'warning');
                            } else {
                                showMessage(`Successfully imported ${files.length} files.`, 'success');
                                setTimeout(() => location.reload(), 2000);
                            }
                        } catch (e) {
                            toggleLoading(false);
                            loadingMsg.textContent = originalMsg;
                            showMessage('Error: ' + e.message, 'error');
                        }
                    },
                    'Confirm Import All', 'Yes, I understand the risk', 'var(--danger)'
            );
        }

        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.backup-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }

        function getSelectedBackups() {
            const checkboxes = document.querySelectorAll('.backup-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        function deleteSelectedBackups() {
            const selected = getSelectedBackups();
            if (selected.length === 0) return showInfo('Please select files to delete.', 'No Selection');

            showConfirm(`Are you sure you want to delete ${selected.length} files?`, async () => {
                toggleLoading(true);
                let deletedCount = 0;
                for (const filename of selected) {
                    try {
                        await makeRequest('delete_backup', {
                            filename
                        });
                        deletedCount++;
                    } catch (e) {
                        console.error(`Failed to delete ${filename}:`, e);
                    }
                }
                toggleLoading(false);
                showMessage(`Successfully deleted ${deletedCount} files.`, 'success');
                setTimeout(() => location.reload(), 1500);
            }, 'Confirm Delete', 'Delete Selected', 'var(--danger)');
        }

        function importSelectedBackups() {
            const selected = getSelectedBackups();
            if (selected.length === 0) return showInfo('Please select files to import.', 'No Selection');

            // Reverse to process oldest first (assuming list is displayed Newest first)
            // document.querySelectorAll returns in document order.
            const selectedOrdered = selected.reverse();

            showConfirm(`Import ${selected.length} files? They will be processed sequentially (Oldest to Newest based on selection).`, async () => {
                const allowDrop = document.getElementById('dropTableBackupImport').checked;
                const enableFK = document.getElementById('enableFKBackupImport').checked;
                toggleLoading(true);
                const loadingMsg = document.querySelector('#loading p');
                const originalMsg = loadingMsg.textContent;

                let errors = [];
                for (let i = 0; i < selectedOrdered.length; i++) {
                    const filename = selectedOrdered[i];
                    loadingMsg.textContent = `Importing (${i + 1}/${selectedOrdered.length}): ${filename}...`;
                    try {
                        await makeRequest('import_backup', {
                            filename,
                            allowDrop: allowDrop.toString(),
                            enableFK: enableFK.toString()
                        });
                    } catch (e) {
                        errors.push(`${filename}: ${e.message}`);
                    }
                }

                toggleLoading(false);
                loadingMsg.textContent = originalMsg;
                showMessage(errors.length > 0 ? `Completed with errors.\n${errors.join('\n')}` : `Successfully imported ${selected.length} files.`, errors.length > 0 ? 'warning' : 'success');
                if (errors.length === 0) setTimeout(() => location.reload(), 2000);
            }, 'Confirm Import', 'Import Selected', 'var(--primary)');
        }

        async function loadSrColumns() {
            const table = document.getElementById('srTable').value;
            const colSelect = document.getElementById('srColumn');
            colSelect.innerHTML = '<option value="">Loading...</option>';
            if (!table) {
                colSelect.innerHTML = '<option value="">-- Select Column --</option>';
                return;
            }

            try {
                const res = await makeRequest('get_table_details', {
                    table
                });
                let html = '<option value="">-- Select Column --</option>';
                res.columns.forEach(c => {
                    html += `<option value="${c.Field}">${c.Field} (${c.Type})</option>`;
                });
                colSelect.innerHTML = html;
            } catch (e) {
                colSelect.innerHTML = '<option value="">Error loading columns</option>';
            }
        }

        async function doSearchReplace() {
            const table = document.getElementById('srTable').value;
            const column = document.getElementById('srColumn').value;
            const search = document.getElementById('srSearch').value;
            const replace = document.getElementById('srReplace').value;

            if (!table || !column) return showInfo('Please select table and column.', 'Missing Info');
            if (!search) return showInfo('Please enter search text.', 'Missing Info');

            showConfirm(`Replace "${search}" with "${replace}" in ${table}.${column}?`, async () => {
                toggleLoading(true);
                try {
                    const res = await makeRequest('search_replace', {
                        table,
                        column,
                        search,
                        replace
                    });
                    toggleLoading(false);
                    showMessage(res.message, 'success');
                } catch (e) {
                    toggleLoading(false);
                    showMessage('Error: ' + e.message, 'error');
                }
            }, 'Confirm Replace', 'Replace', 'var(--warning)');
        }
    </script>
</body>

</html>