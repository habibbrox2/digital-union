<?php

/**
 * SQL Migration Model
 * Handles database migrations with safety checks and backups
 * 
 * Author: Hr Habib
 * Updated: 2026
 */

declare(strict_types=1);

class SqlMigrationModel
{
    private mysqli $mysqli;
    private string $backupDir;
    private array $executionLog = [];

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->backupDir = __DIR__ . '/../storage/db_backups/';
        
        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Get mysqli instance
     */
    public function getMysqli(): mysqli
    {
        return $this->mysqli;
    }

    /**
     * Check if query is dangerous (DROP TABLE, TRUNCATE, DELETE FROM)
     */
    public function isDangerousQuery(string $query): bool
    {
        return preg_match('/\b(DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM|DROP\s+DATABASE)\b/i', $query) === 1;
    }

    /**
     * Extract table name from SQL query
     */
    public function extractTableName(string $query): ?string
    {
        if (preg_match('/(TABLE|FROM)\s+`?([a-zA-Z0-9_]+)`?/i', $query, $matches)) {
            return $matches[2] ?? null;
        }
        return null;
    }

    /**
     * Read SQL file and parse queries
     */
    public function readSqlFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new Exception("Failed to read file");
        }

        $queries = [];
        $currentQuery = '';

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '--') || str_starts_with($line, '/*')) {
                continue;
            }

            $currentQuery .= ' ' . $line;

            // Check if query ends with semicolon
            if (str_ends_with($line, ';')) {
                $currentQuery = trim($currentQuery, '; ');
                if (!empty($currentQuery)) {
                    $queries[] = $currentQuery;
                }
                $currentQuery = '';
            }
        }

        // Add any remaining query
        if (!empty($currentQuery)) {
            $currentQuery = trim($currentQuery, '; ');
            $queries[] = $currentQuery;
        }

        return $queries;
    }

    /**
     * Categorize queries into safe and dangerous
     */
    public function categorizeQueries(array $queries): array
    {
        $safe = [];
        $dangerous = [];

        foreach ($queries as $query) {
            if (empty(trim($query))) {
                continue;
            }

            if ($this->isDangerousQuery($query)) {
                $dangerous[] = [
                    'query' => $query,
                    'table' => $this->extractTableName($query)
                ];
            } else {
                $safe[] = $query;
            }
        }

        return [
            'safe' => $safe,
            'dangerous' => $dangerous
        ];
    }

    /**
     * Backup a table using mysqldump
     */
    public function backupTable(string $table): ?string
    {
        try {
            $timestamp = date('Ymd_His');
            $backupFile = $this->backupDir . $table . '_' . $timestamp . '.sql';

            $host = DB_HOST;
            $user = DB_USER;
            $password = DB_PASS;
            $dbName = DB_NAME;

            $command = sprintf(
                'mysqldump --user=%s --password=%s --host=%s %s %s > %s',
                escapeshellarg($user),
                escapeshellarg($password),
                escapeshellarg($host),
                escapeshellarg($dbName),
                escapeshellarg($table),
                escapeshellarg($backupFile)
            );

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception("Backup failed for table: {$table}");
            }

            return $backupFile;
        } catch (Exception $e) {
            error_log("Backup error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Execute safe queries
     */
    public function executeSafeQueries(array $queries): array
    {
        $results = [];

        foreach ($queries as $query) {
            try {
                $result = $this->mysqli->query($query);
                $results[] = [
                    'query' => $query,
                    'status' => 'success',
                    'message' => 'Executed successfully'
                ];
                $this->executionLog[] = "✓ Safe Query: {$query}";
            } catch (Exception $e) {
                $results[] = [
                    'query' => $query,
                    'status' => 'error',
                    'message' => $this->mysqli->error
                ];
                $this->executionLog[] = "✗ Error in Query: {$query} - " . $this->mysqli->error;
            }
        }

        return $results;
    }

    /**
     * Execute dangerous queries with backup
     */
    public function executeDangerousQueries(array $dangerousQueries): array
    {
        $results = [];

        foreach ($dangerousQueries as $item) {
            $query = $item['query'];
            $table = $item['table'];

            try {
                // Create backup first
                $backupFile = null;
                if ($table) {
                    $backupFile = $this->backupTable($table);
                    if ($backupFile) {
                        $this->executionLog[] = "📦 Backup created: {$backupFile}";
                    }
                }

                // Execute dangerous query
                $result = $this->mysqli->query($query);
                $results[] = [
                    'query' => $query,
                    'table' => $table,
                    'backup' => $backupFile,
                    'status' => 'success',
                    'message' => 'Executed with backup'
                ];
                $this->executionLog[] = "⚠️ Dangerous Query Executed: {$query}";
            } catch (Exception $e) {
                $results[] = [
                    'query' => $query,
                    'table' => $table,
                    'status' => 'error',
                    'message' => $this->mysqli->error
                ];
                $this->executionLog[] = "✗ Error in Dangerous Query: {$query} - " . $this->mysqli->error;
            }
        }

        return $results;
    }

    /**
     * Get execution log
     */
    public function getExecutionLog(): array
    {
        return $this->executionLog;
    }

    /**
     * Get list of backup files
     */
    public function getBackupList(): array
    {
        $backups = [];
        
        if (is_dir($this->backupDir)) {
            $files = array_reverse(scandir($this->backupDir));
            
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && str_ends_with($file, '.sql')) {
                    $filePath = $this->backupDir . $file;
                    $backups[] = [
                        'name' => $file,
                        'path' => $filePath,
                        'size' => filesize($filePath),
                        'date' => filemtime($filePath),
                        'formatted_date' => date('d M Y H:i:s', filemtime($filePath))
                    ];
                }
            }
        }

        return $backups;
    }

    /**
     * Get backup file size formatted
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
