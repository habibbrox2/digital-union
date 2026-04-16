<?php
/**
 * RBAC Helper Functions
 * Contains permission checking helpers used across the app. Audit and
 * role-template features have been disabled; audit functions are provided
 * as no-op stubs to avoid runtime errors.
 */

require_once __DIR__ . '/../classes/PermissionsManager.php';

if (!function_exists('ensure_can')) {
    function ensure_can($permissionName, $module = null) {
        global $mysqli;
        if (!isset($mysqli)) {
            error_log('RBAC helper: $mysqli not available');
            http_response_code(500);
            if (function_exists('renderError')) renderError(500, 'Server configuration error.');
            exit;
        }

        require_once __DIR__ . '/../classes/AuthManager.php';
        $auth = new AuthManager($mysqli);
        $auth->requireLogin();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            if (function_exists('renderError')) renderError(401, 'আপনি লগইন করেননি।');
            exit;
        }

        // Superadmin bypass
        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result && (int)$result['role_id'] === 1) {
            return; // full bypass
        }

        $permissionsManager = new PermissionsManager($mysqli);

        if ($module) {
            $hasPermission = $permissionsManager->hasPermissionWithModule($userId, $permissionName, $module);
        } else {
            $hasPermission = $permissionsManager->hasPermission($userId, $permissionName);
        }

        if (!$hasPermission) {
            http_response_code(403);
            if (function_exists('renderError')) renderError(403, 'আপনার পর্যাপ্ত অনুমতি নেই।');
            exit;
        }
    }
}

if (!function_exists('ensure_can_in_union')) {
    function ensure_can_in_union($permissionName, $unionId, $module = null) {
        global $mysqli;
        if (!isset($mysqli)) {
            error_log('RBAC helper: $mysqli not available');
            http_response_code(500);
            if (function_exists('renderError')) renderError(500, 'Server configuration error.');
            exit;
        }

        require_once __DIR__ . '/../classes/AuthManager.php';
        $auth = new AuthManager($mysqli);
        $auth->requireLogin();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            if (function_exists('renderError')) renderError(401, 'আপনি লগইন করেননি।');
            exit;
        }

        // Superadmin bypass
        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result && (int)$result['role_id'] === 1) {
            return; // full bypass
        }

        $permissionsManager = new PermissionsManager($mysqli);

        if ($module) {
            $hasPermission = $permissionsManager->hasPermissionInUnionWithModule($userId, $permissionName, $unionId, $module);
        } else {
            $hasPermission = $permissionsManager->hasPermissionInUnion($userId, $permissionName, $unionId);
        }

        if (!$hasPermission) {
            http_response_code(403);
            if (function_exists('renderError')) renderError(403, 'আপনার পর্যাপ্ত অনুমতি নেই।');
            exit;
        }
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin($userId, $mysqli = null) {
        if (!$mysqli) {
            global $mysqli;
        }
        
        if (!$mysqli) {
            return false;
        }

        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result && $result['role_id'] <= 1;
    }
}

if (!function_exists('isSuperadmin')) {
    function isSuperadmin($userId, $mysqli = null) {
        return isAdmin($userId, $mysqli);
    }
}

/**
 * Compatibility: simple stub for logging a single permission change.
 * Audit logging is disabled; this returns false and does not write to DB.
 */
if (!function_exists('logPermissionChange')) {
    function logPermissionChange($mysqli, $actorId, $roleId, $permissionId, $action, $details = '') {
        return false;
    }
}

if (!function_exists('getPermissionById')) {
    function getPermissionById($mysqli, $permissionId) {
        $stmt = $mysqli->prepare("SELECT * FROM permissions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $permissionId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }
}

if (!function_exists('getRoleById')) {
    function getRoleById($mysqli, $roleId) {
        $stmt = $mysqli->prepare("SELECT * FROM roles WHERE role_id = ? LIMIT 1");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }
}

// =====================================================================
// ROLE LEVEL / RANKING SYSTEM HELPERS
// =====================================================================

/**
 * Get role level of a user
 * @param int $userId User ID
 * @param mysqli $mysqli Database connection
 * @return int|null Role level
 */
if (!function_exists('getUserRoleLevel')) {
    function getUserRoleLevel($userId, $mysqli = null) {
        if (!$mysqli) {
            global $mysqli;
        }
        
        if (!$mysqli) {
            return null;
        }

        $stmt = $mysqli->prepare(
            "SELECT r.role_level FROM users u
             JOIN roles r ON u.role_id = r.role_id
             WHERE u.user_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result ? (int)$result['role_level'] : null;
    }
}

/**
 * Check if user1 has higher privilege than user2
 * Lower role_level = higher privilege
 * @param int $user1Id First user ID
 * @param int $user2Id Second user ID
 * @param mysqli $mysqli Database connection
 * @return bool True if user1 has higher privilege
 */
if (!function_exists('isUserHigherPrivilege')) {
    function isUserHigherPrivilege($user1Id, $user2Id, $mysqli = null) {
        if (!$mysqli) {
            global $mysqli;
        }
        
        if (!$mysqli) {
            return false;
        }

        $level1 = getUserRoleLevel($user1Id, $mysqli);
        $level2 = getUserRoleLevel($user2Id, $mysqli);
        
        if ($level1 === null || $level2 === null) {
            return false;
        }
        
        return $level1 < $level2;
    }
}

/**
 * Check if user has role level equal to or above minimum
 * @param int $userId User ID
 * @param int $minLevel Minimum role level (lower = higher privilege required)
 * @param mysqli $mysqli Database connection
 * @return bool True if user meets privilege level
 */
if (!function_exists('hasMinimumRoleLevel')) {
    function hasMinimumRoleLevel($userId, $minLevel, $mysqli = null) {
        if (!$mysqli) {
            global $mysqli;
        }
        
        if (!$mysqli) {
            return false;
        }

        $userLevel = getUserRoleLevel($userId, $mysqli);
        if ($userLevel === null) {
            return false;
        }
        
        // Lower role_level = higher privilege
        return $userLevel <= $minLevel;
    }
}

/**
 * Ensure user has minimum role level
 * Exits with error if not met
 * @param int $minLevel Minimum role level
 * @param string $errorMessage Error message (optional)
 */
if (!function_exists('ensure_role_level')) {
    function ensure_role_level($minLevel, $errorMessage = null) {
        global $mysqli;
        
        if (!isset($mysqli)) {
            http_response_code(500);
            if (function_exists('renderError')) renderError(500, 'সার্ভার কনফিগারেশন ত্রুটি।');
            exit;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            if (function_exists('renderError')) renderError(401, 'আপনি লগইন করেননি।');
            exit;
        }

        $userLevel = getUserRoleLevel($userId, $mysqli);
        if ($userLevel === null || $userLevel > $minLevel) {
            http_response_code(403);
            $message = $errorMessage ?? 'আপনার এই অপারেশনের জন্য সর্বনিম্ন ভূমিকা স্তর নেই।';
            if (function_exists('renderError')) renderError(403, $message);
            exit;
        }
    }
}

/**
 * Get privilege level name for display
 * @param int $roleLevel Role level
 * @param string $language 'bn' or 'en'
 * @return string Privilege level name
 */
if (!function_exists('getRoleLevelDisplay')) {
    function getRoleLevelDisplay($roleLevel, $language = 'bn') {
        $levels = [
            1 => ['bn' => 'সর্বোচ্চ (সুপারঅ্যাডমিন)', 'en' => 'Highest (Superadmin)'],
            2 => ['bn' => 'অত্যন্ত উচ্চ (সিস্টেম অ্যাডমিন)', 'en' => 'Very High (System Admin)'],
            3 => ['bn' => 'উচ্চ (সচিব)', 'en' => 'High (Secretary)'],
            4 => ['bn' => 'মাঝারি-উচ্চ (চেয়ারম্যান)', 'en' => 'Medium-High (Chairman)'],
            5 => ['bn' => 'মাঝারি (সদস্য)', 'en' => 'Medium (Member)'],
            6 => ['bn' => 'মাঝারি-নিম্ন (অপারেটর)', 'en' => 'Medium-Low (Operator)'],
            7 => ['bn' => 'নিম্ন (পুলিশ)', 'en' => 'Low (Police)'],
            8 => ['bn' => 'অত্যন্ত নিম্ন (সহকারী)', 'en' => 'Very Low (Assistant)'],
            9 => ['bn' => 'সর্বনিম্ন (অতিথি)', 'en' => 'Lowest (Guest)']
        ];
        
        return $levels[$roleLevel][$language] ?? 'অজানা';
    }
}

/**
 * Enhanced canManageUser with role level support
 * Replaces previous version - now uses role levels
 * @param int $managerId Manager user ID
 * @param int $targetId Target user ID
 * @param mysqli $mysqli Database connection
 * @return bool True if manager can manage target
 */
if (!function_exists('canManageUserByLevel')) {
    function canManageUserByLevel($managerId, $targetId, $mysqli = null) {
        if (!$mysqli) {
            global $mysqli;
        }

        if (!$mysqli || $managerId == $targetId) {
            return false;
        }

        $managerLevel = getUserRoleLevel($managerId, $mysqli);
        $targetLevel = getUserRoleLevel($targetId, $mysqli);
        
        if ($managerLevel === null || $targetLevel === null) {
            return false;
        }

        // Superadmin (level 1) can manage everyone except other superadmins
        if ($managerLevel === 1) {
            return $targetLevel !== 1;
        }

        // Others can manage users with equal or lower privilege (higher role_level number)
        return $managerLevel < $targetLevel;
    }
}


/**
 * RBAC: Check if a user can manage another user
 * NOW WITH ROLE LEVEL SUPPORT - Uses role hierarchy levels
 * 
 * @param int $managerId User ID trying to manage
 * @param int $targetId User ID being managed
 * @param mysqli $mysqli Database connection
 * @return bool True if manager can manage target user
 */
if (!function_exists('canManageUser')) {
    function canManageUser($managerId, $targetId, $mysqli) {
        // Use role level based function if available
        if (function_exists('canManageUserByLevel')) {
            return canManageUserByLevel($managerId, $targetId, $mysqli);
        }

        // Fallback to old role_id based method
        if ($managerId == $targetId) {
            return false; // Can't manage yourself
        }

        // Get manager's role
        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $managerId);
        $stmt->execute();
        $managerResult = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$managerResult) {
            return false;
        }
        
        $managerRoleId = (int)$managerResult['role_id'];
        
        // Superadmin (role_id = 1) can manage everyone
        if ($managerRoleId === 1) {
            return true;
        }

        // Get target's role
        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $targetId);
        $stmt->execute();
        $targetResult = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$targetResult) {
            return false;
        }
        
        $targetRoleId = (int)$targetResult['role_id'];
        
        // Non-superadmin cannot manage superadmin
        if ($targetRoleId === 1) {
            return false;
        }
        
        // Non-superadmins can manage users with equal or lower role
        return $managerRoleId <= $targetRoleId;
    }
}

/**
 * RBAC: Validate user data to prevent privilege escalation
 * Ensures a user can't assign roles they don't have permission to grant
 * 
 * @param int $userId User performing the action
 * @param array $userData User data to validate (includes 'role_id', 'union_id')
 * @param mysqli $mysqli Database connection
 * @param array &$errors Array to collect validation errors
 * @return bool True if validation passed
 */
if (!function_exists('validateUserDataForPrivilegeEscalation')) {
    function validateUserDataForPrivilegeEscalation($userId, $userData, $mysqli, &$errors = []) {
        if (empty($userId) || empty($userData)) {
            $errors[] = 'অবৈধ ব্যবহারকারী তথ্য';
            return false;
        }

        // Get current user's role
        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentUserResult = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$currentUserResult) {
            $errors[] = 'বর্তমান ব্যবহারকারী পাওয়া যায়নি';
            return false;
        }
        
        $currentUserRoleId = (int)$currentUserResult['role_id'];
        
        // Superadmin (role_id = 1) can create any role
        if ($currentUserRoleId === 1) {
            return true;
        }

        // Non-superadmins cannot create/assign superadmin role (role_id = 1)
        if (isset($userData['role_id']) && (int)$userData['role_id'] === 1) {
            $errors[] = 'আপনি সুপারঅ্যাডমিন ভূমিকা নির্ধারণ করতে পারেন না';
            return false;
        }

        // Non-superadmins cannot create/assign role higher than their own
        if (isset($userData['role_id'])) {
            $targetRoleId = (int)$userData['role_id'];
            if ($targetRoleId < $currentUserRoleId) {
                $errors[] = 'আপনি নিজের চেয়ে উচ্চতর ভূমিকা নির্ধারণ করতে পারেন না';
                return false;
            }
        }

        return true;
    }
}