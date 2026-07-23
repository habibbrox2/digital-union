<?php
/**
 * modules/Services/AuthService.php
 * 
 * Service layer for authentication and authorization checks.
 * Replaces ensure_admin_or_can() and ensure_can() from global helper functions.
 */

class AuthService
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Get the currently authenticated user's ID.
     * Checks session timeout (30 min inactivity). Returns null if not logged in or session expired.
     */
    public function getCurrentUserId(): ?int
    {
        // Check session timeout (30 min)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
            session_unset();
            session_destroy();
            return null;
        }

        // Update last activity timestamp
        $_SESSION['last_activity'] = time();

        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Ensure the current user has the given permission (with optional module scope).
     * Exits with 403 if not allowed.
     */
    public function ensureCan(string $permission, ?string $module = null): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            if (function_exists('renderError')) renderError(401, 'আপনি লগইন করেননি।');
            exit;
        }

        require_once __DIR__ . '/../../models/AuthManager.php';
        require_once __DIR__ . '/../../models/RolesManager.php';
        require_once __DIR__ . '/../../models/PermissionsManager.php';

        // Superadmin bypass
        $stmt = $this->mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result && (int)$result['role_id'] === 1) {
            return;
        }

        $permissionsManager = new PermissionsManager($this->mysqli);

        if ($module) {
            $hasPermission = $permissionsManager->hasPermissionWithModule($userId, $permission, $module);
        } else {
            $hasPermission = $permissionsManager->hasPermission($userId, $permission);
        }

        if (!$hasPermission) {
            http_response_code(403);
            if (function_exists('renderError')) renderError(403, 'আপনার পর্যাপ্ত অনুমতি নেই।');
            exit;
        }
    }

    /**
     * Ensure the current user has a minimum role level.
     * Lower role_level = higher privilege. Exits with error if not met.
     */
    public function ensureRoleLevel(int $minLevel, ?string $errorMessage = null): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            if (function_exists('renderError')) renderError(401, 'আপনি লগইন করেননি।');
            exit;
        }

        require_once __DIR__ . '/../../models/RolesManager.php';

        $stmt = $this->mysqli->prepare(
            "SELECT r.role_level FROM users u
             JOIN roles r ON u.role_id = r.role_id
             WHERE u.user_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userLevel = $result ? (int)$result['role_level'] : null;

        if ($userLevel === null || $userLevel > $minLevel) {
            http_response_code(403);
            $message = $errorMessage ?? 'আপনার এই অপারেশনের জন্য সর্বনিম্ন ভূমিকা স্তর নেই।';
            if (function_exists('renderError')) renderError(403, $message);
            exit;
        }
    }

    /**
     * Check if a user is superadmin (role_id <= 1).
     */
    public function isSuperadmin(int $userId): bool
    {
        $stmt = $this->mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result && (int)$result['role_id'] <= 1;
    }

    /**
     * Ensure the current user is a super admin or has the given permission.
     * Exits with 403 if not allowed (legacy compatibility, delegates to ensureCan).
     */
    public function ensureAdminOrCan(string $permission): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            renderError(403, 'Unauthorized access!');
            exit;
        }

        // Quick allow for super admin role flag in session
        if (!empty($_SESSION['is_superadmin'])) return;

        require_once __DIR__ . '/../../models/RolesManager.php';
        require_once __DIR__ . '/../../models/PermissionsManager.php';

        // Quick allow for role_id <= 1
        $stmt = $this->mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['role_id']) && $row['role_id'] <= 1) return;

        // Use PermissionsManager directly (same as ensureCan without module)
        $permissionsManager = new PermissionsManager($this->mysqli);
        if ($permissionsManager->hasPermission($userId, $permission)) return;

        renderError(403, 'Unauthorized access!');
        exit;
    }
}
