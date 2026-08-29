<?php
/**
 * modules/Services/MigrationService.php
 * 
 * Automatic database migration service.
 * Tracks which migrations have been run in a schema_migrations table,
 * and auto-runs pending migrations on each request.
 * 
 * Usage:
 *   $migrationService = new MigrationService($mysqli);
 *   $migrationService->run();
 */

class MigrationService
{
    private mysqli $mysqli;
    private static bool $ran = false;
    
    // Migration files directory
    private string $migrationsDir;
    
    // Tables managed by this service (for CREATE TABLE IF NOT EXISTS)
    private array $managedTables = [];
    
    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->migrationsDir = dirname(__DIR__, 2) . '/database';
    }
    
    /**
     * Run all pending migrations. Idempotent — safe to call on every request.
     */
    public function run(): void
    {
        if (self::$ran) {
            return;
        }
        self::$ran = true;
        
        try {
            // Step 1: Ensure schema_migrations table exists
            $this->ensureMigrationTable();
            
            // Step 2: Run table creation migrations (CREATE TABLE IF NOT EXISTS)
            $this->runTableMigrations();
            
            // Step 3: Run incremental SQL migrations from migrations/ directory
            $this->runIncrementalMigrations();
            
        } catch (\Throwable $e) {
            error_log('[Migration] Auto-migration failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Create the schema_migrations tracking table if it doesn't exist.
     */
    private function ensureMigrationTable(): void
    {
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `migration_name` VARCHAR(255) NOT NULL,
                `batch` INT UNSIGNED NOT NULL DEFAULT 1,
                `status` ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
                `error_message` TEXT DEFAULT NULL,
                `started_at` DATETIME DEFAULT NULL,
                `completed_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_migration_name` (`migration_name`),
                KEY `idx_batch` (`batch`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * Get the next batch number.
     */
    private function getNextBatch(): int
    {
        $result = $this->mysqli->query("SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM schema_migrations");
        $row = $result->fetch_assoc();
        return (int)($row['next_batch'] ?? 1);
    }
    
    /**
     * Check if a migration has already been run.
     */
    private function isMigrationRun(string $name): bool
    {
        $stmt = $this->mysqli->prepare("SELECT 1 FROM schema_migrations WHERE migration_name = ? AND status = 'completed' LIMIT 1");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    
    /**
     * Record a migration as started.
     */
    private function markStarted(string $name, int $batch): void
    {
        $stmt = $this->mysqli->prepare("
            INSERT INTO schema_migrations (migration_name, batch, status, started_at)
            VALUES (?, ?, 'running', NOW())
            ON DUPLICATE KEY UPDATE status = 'running', started_at = NOW(), error_message = NULL
        ");
        $stmt->bind_param("si", $name, $batch);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Record a migration as completed.
     */
    private function markCompleted(string $name): void
    {
        $stmt = $this->mysqli->prepare("UPDATE schema_migrations SET status = 'completed', completed_at = NOW() WHERE migration_name = ?");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Record a migration as failed.
     */
    private function markFailed(string $name, string $error): void
    {
        $stmt = $this->mysqli->prepare("UPDATE schema_migrations SET status = 'failed', error_message = ? WHERE migration_name = ?");
        $stmt->bind_param("ss", $error, $name);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Run table creation migrations from database/ directory.
     * These are CREATE TABLE IF NOT EXISTS statements.
     */
    private function runTableMigrations(): void
    {
        $batch = $this->getNextBatch();
        
        // Core application tables — ordered by dependency
        $tables = [
            'users.sql' => '001_create_users_table',
            'roles.sql' => '002_create_roles_table',
            'user_roles.sql' => '003_create_user_roles_table',
            'unions.sql' => '004_create_unions_table',
            'address.sql' => '005_create_address_table',
            'geo_location.sql' => '006_create_geo_location_table',
            'permissions.sql' => '007_create_permissions_table',
            'role_permissions.sql' => '008_create_role_permissions_table',
            'role_level_definitions.sql' => '009_create_role_level_definitions_table',
            'system_settings.sql' => '010_create_system_settings_table',
            'term_translations.sql' => '011_create_term_translations_table',
            'extra_fields.sql' => '012_create_extra_fields_table',
            
            // Application tables
            'applications.sql' => '020_create_applications_table',
            'application_members.sql' => '021_create_application_members_table',
            'application_approvals.sql' => '022_create_application_approvals_table',
            'birth_applications.sql' => '023_create_birth_applications_table',
            'ownership_type.sql' => '024_create_ownership_type_table',
            'business_type.sql' => '025_create_business_type_table',
            'business_meta.sql' => '026_create_business_meta_table',
            
            // Chat tables
            'chat_sessions.sql' => '030_create_chat_sessions_table',
            'chat_messages.sql' => '031_create_chat_messages_table',
            'chat_canned_responses.sql' => '032_create_chat_canned_responses_table',
            'chat_offline_messages.sql' => '033_create_chat_offline_messages_table',
            'chat_push_subscriptions.sql' => '034_create_chat_push_subscriptions_table',
            'chat_rate_limits.sql' => '035_create_chat_rate_limits_table',
            'fcm_tokens.sql' => '036_create_fcm_tokens_table',
            
            // Auth tables
            'email_verifications.sql' => '040_create_email_verifications_table',
            'password_resets.sql' => '041_create_password_resets_table',
            'login_history.sql' => '042_create_login_history_table',
            'failed_login_attempts.sql' => '043_create_failed_login_attempts_table',
            
            // Other tables
            'email_queue.sql' => '050_create_email_queue_table',
            'payments.sql' => '051_create_payments_table',
            'post_offices.sql' => '052_create_post_offices_table',
            'fee_manage.sql' => '053_create_fee_manage_table',
            'certificate_fee.sql' => '054_create_certificate_fee_table',
            'license_renewal_history.sql' => '055_create_license_renewal_history_table',
        ];
        
        foreach ($tables as $sqlFile => $migrationName) {
            if ($this->isMigrationRun($migrationName)) {
                continue;
            }
            
            $sqlPath = $this->migrationsDir . '/' . $sqlFile;
            if (!file_exists($sqlPath)) {
                continue;
            }
            
            $this->markStarted($migrationName, $batch);
            
            try {
                $sql = file_get_contents($sqlPath);
                if ($sql !== false && trim($sql) !== '') {
                    // Remove comment lines and extract only DDL statements
                    $cleaned = $this->cleanSql($sql);
                    if (!empty($cleaned)) {
                        $this->mysqli->multi_query($cleaned);
                        // Consume all results
                        while ($this->mysqli->more_results()) {
                            $this->mysqli->next_result();
                        }
                    }
                }
                $this->markCompleted($migrationName);
            } catch (\Throwable $e) {
                $this->markFailed($migrationName, $e->getMessage());
                error_log("[Migration] Failed: $migrationName — " . $e->getMessage());
            }
        }
    }
    
    /**
     * Run incremental SQL migrations from migrations/ directory.
     */
    private function runIncrementalMigrations(): void
    {
        $batch = $this->getNextBatch();
        $migrationsPath = dirname(__DIR__, 2) . '/migrations';
        
        if (!is_dir($migrationsPath)) {
            return;
        }
        
        $files = glob($migrationsPath . '/*.sql');
        if (empty($files)) {
            return;
        }
        
        sort($files); // Ensure consistent order
        
        foreach ($files as $file) {
            $basename = basename($file, '.sql');
            $migrationName = 'file_' . $basename;
            
            if ($this->isMigrationRun($migrationName)) {
                continue;
            }
            
            $this->markStarted($migrationName, $batch);
            
            try {
                $sql = file_get_contents($file);
                if ($sql !== false && trim($sql) !== '') {
                    $cleaned = $this->cleanSql($sql);
                    if (!empty($cleaned)) {
                        $this->mysqli->multi_query($cleaned);
                        while ($this->mysqli->more_results()) {
                            $this->mysqli->next_result();
                        }
                    }
                }
                $this->markCompleted($migrationName);
            } catch (\Throwable $e) {
                $this->markFailed($migrationName, $e->getMessage());
                error_log("[Migration] Failed: $migrationName — " . $e->getMessage());
            }
        }
    }
    
    /**
     * Clean SQL content: remove comments, extract only DDL statements.
     * Skips DML (INSERT, UPDATE, DELETE) to avoid duplicate data errors.
     */
    private function cleanSql(string $sql): string
    {
        // Remove multi-line comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Remove single-line comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        
        // Split into statements by semicolons
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $cleaned = [];
        foreach ($statements as $stmt) {
            if (empty($stmt)) continue;
            
            // Only keep DDL statements: CREATE TABLE, ALTER TABLE
            if (preg_match('/^\s*(CREATE\s+TABLE|ALTER\s+TABLE)/i', $stmt)) {
                $cleaned[] = $stmt . ';';
            }
        }
        
        return implode("\n", $cleaned);
    }
    
    /**
     * Get migration status for display.
     */
    public function getStatus(): array
    {
        $result = $this->mysqli->query("
            SELECT migration_name, status, batch, error_message, started_at, completed_at
            FROM schema_migrations
            ORDER BY batch ASC, id ASC
        ");
        
        $migrations = [];
        while ($row = $result->fetch_assoc()) {
            $migrations[] = $row;
        }
        
        $stats = $this->mysqli->query("
            SELECT status, COUNT(*) as cnt FROM schema_migrations GROUP BY status
        ");
        $summary = [];
        while ($row = $stats->fetch_assoc()) {
            $summary[$row['status']] = (int)$row['cnt'];
        }
        
        return [
            'migrations' => $migrations,
            'summary' => $summary,
            'total' => array_sum($summary),
        ];
    }
    
    /**
     * Reset all migrations (for development only).
     */
    public function reset(): void
    {
        $this->mysqli->query("DROP TABLE IF EXISTS schema_migrations");
        self::$ran = false;
    }
}
