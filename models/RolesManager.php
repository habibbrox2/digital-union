<?php
/**
 * RolesManager.php
 * Manages role creation, updating, and role-permission associations
 * 
 * Date: December 29, 2025
 */

class RolesManager {
    private $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    /**
     * Get all roles
     * @return array Array of roles
     */
    public function getAllRoles() {
        $sql = "SELECT role_id, role_name, description, created_at, updated_at 
                FROM roles 
                ORDER BY role_name ASC";
        
        $result = $this->mysqli->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    public function getAll() {
        $sql = "SELECT role_id, role_name, description, created_at, updated_at 
                FROM roles 
                ORDER BY role_id ASC";
        
        $result = $this->mysqli->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    /**
     * Get role by ID
     * @param int $roleId Role ID
     * @return array|null Role data or null
     */
    public function getRoleById($roleId) {
        $stmt = $this->mysqli->prepare(
            "SELECT role_id, role_name, description, created_at, updated_at 
             FROM roles 
             WHERE role_id = ? 
             LIMIT 1"
        );
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    /**
     * Get role by name
     * @param string $roleName Role name
     * @return array|null Role data or null
     */
    public function getRoleByName($roleName) {
        $stmt = $this->mysqli->prepare(
            "SELECT role_id, role_name, description, created_at, updated_at 
             FROM roles 
             WHERE role_name = ? 
             LIMIT 1"
        );
        $stmt->bind_param("s", $roleName);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    /**
     * Create new role
     * @param string $roleName Role name
     * @param string|null $description Description
     * @return int|false Role ID on success, false on failure
     */
    public function createRole($roleName, $description = null) {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO roles (role_name, description) 
             VALUES (?, ?)"
        );
        $stmt->bind_param("ss", $roleName, $description);
        
        if ($stmt->execute()) {
            $roleId = $this->mysqli->insert_id;
            $stmt->close();
            return $roleId;
        }
        $stmt->close();
        return false;
    }

    /**
     * Update role
     * @param int $roleId Role ID
     * @param string $roleName New role name
     * @param string|null $description New description
     * @return bool Success status
     */
    public function updateRole($roleId, $roleName, $description = null) {
        $stmt = $this->mysqli->prepare(
            "UPDATE roles 
             SET role_name = ?, description = ?, updated_at = NOW()
             WHERE role_id = ?"
        );
        $stmt->bind_param("ssi", $roleName, $description, $roleId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Delete role (with cascade)
     * @param int $roleId Role ID
     * @return bool Success status
     */
    public function deleteRole($roleId) {
        // Check if role is in use
        if ($roleId == 1) {
            // Prevent deleting Administrator role
            return false;
        }

        $stmt = $this->mysqli->prepare(
            "DELETE FROM roles WHERE role_id = ?"
        );
        $stmt->bind_param("i", $roleId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Get total roles count
     * @return int Total roles count
     */
    public function getRolesCount() {
        $result = $this->mysqli->query("SELECT COUNT(*) as count FROM roles");
        $row = $result->fetch_assoc();
        return $row['count'] ?? 0;
    }

    // =====================================================================
    // ROLE PERMISSIONS MANAGEMENT
    // =====================================================================

    /**
     * Get all permissions assigned to a role
     * @param int $roleId Role ID
     * @return array Array of permissions [id, name, module, description]
     */
    public function getPermissionsByRole($roleId) {
        $sql = "SELECT p.id, p.name, p.module, p.description, p.created_at
                FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
                ORDER BY p.module, p.name";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * Assign permission to role
     * @param int $roleId Role ID
     * @param int $permissionId Permission ID
     * @return bool Success status
     */
    public function assignPermissionToRole($roleId, $permissionId) {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO role_permissions (role_id, permission_id) 
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE created_at = NOW()"
        );
        $stmt->bind_param("ii", $roleId, $permissionId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Remove permission from role
     * @param int $roleId Role ID
     * @param int $permissionId Permission ID
     * @return bool Success status
     */
    public function removePermissionFromRole($roleId, $permissionId) {
        $stmt = $this->mysqli->prepare(
            "DELETE FROM role_permissions 
             WHERE role_id = ? AND permission_id = ?"
        );
        $stmt->bind_param("ii", $roleId, $permissionId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Bulk assign permissions to role (replaces existing)
     * @param int $roleId Role ID
     * @param array $permissionIds Array of permission IDs
     * @return bool Success status
     */
    public function bulkAssignPermissionsToRole($roleId, $permissionIds = []) {
        // Delete existing permissions
        $stmt = $this->mysqli->prepare(
            "DELETE FROM role_permissions WHERE role_id = ?"
        );
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $stmt->close();

        // Add new permissions
        if (empty($permissionIds)) {
            return true;
        }

        $stmt = $this->mysqli->prepare(
            "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
        );

        foreach ($permissionIds as $permId) {
            $permId = (int)$permId;
            $stmt->bind_param("ii", $roleId, $permId);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }
        }
        $stmt->close();
        return true;
    }

    /**
     * Get permission count for a role
     * @param int $roleId Role ID
     * @return int Permission count
     */
    public function getPermissionCountForRole($roleId) {
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) as count FROM role_permissions WHERE role_id = ?"
        );
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['count'] ?? 0;
    }

    // =====================================================================
    // USER ROLE MANAGEMENT
    // =====================================================================

    /**
     * Get roles for a user (global and union-specific)
     * @param int $userId User ID
     * @param int|null $unionId Filter by union (optional)
     * @return array Array of user roles
     */
    public function getUserRoles($userId, $unionId = null) {
        if ($unionId !== null) {
            $sql = "SELECT ur.id, ur.user_id, ur.role_id, ur.union_id, r.role_name
                    FROM user_roles ur
                    JOIN roles r ON ur.role_id = r.role_id
                    WHERE ur.user_id = ? AND ur.union_id = ?
                    ORDER BY r.role_name";
            
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param("ii", $userId, $unionId);
        } else {
            $sql = "SELECT ur.id, ur.user_id, ur.role_id, ur.union_id, r.role_name
                    FROM user_roles ur
                    JOIN roles r ON ur.role_id = r.role_id
                    WHERE ur.user_id = ?
                    ORDER BY ur.union_id, r.role_name";
            
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param("i", $userId);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * Get users with specific role
     * @param int $roleId Role ID
     * @param int|null $unionId Filter by union (optional)
     * @return array Array of users
     */
    public function getUsersByRole($roleId, $unionId = null) {
        if ($unionId !== null) {
            $sql = "SELECT DISTINCT u.user_id, u.username, u.email
                    FROM users u
                    JOIN user_roles ur ON u.user_id = ur.user_id
                    WHERE ur.role_id = ? AND ur.union_id = ?
                    ORDER BY u.username";
            
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param("ii", $roleId, $unionId);
        } else {
            $sql = "SELECT DISTINCT u.user_id, u.username, u.email
                    FROM users u
                    JOIN user_roles ur ON u.user_id = ur.user_id
                    WHERE ur.role_id = ?
                    ORDER BY u.username";
            
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param("i", $roleId);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * Assign role to user (global role)
     * @param int $userId User ID
     * @param int $roleId Role ID
     * @return bool Success status
     */
    public function assignRoleToUser($userId, $roleId) {
        $stmt = $this->mysqli->prepare(
            "UPDATE users SET role_id = ? WHERE user_id = ?"
        );
        $stmt->bind_param("ii", $roleId, $userId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Assign role to user in specific union
     * @param int $userId User ID
     * @param int $roleId Role ID
     * @param int $unionId Union ID
     * @return bool Success status
     */
    public function assignRoleToUserInUnion($userId, $roleId, $unionId) {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO user_roles (user_id, role_id, union_id) 
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = ?, updated_at = NOW()"
        );
        $stmt->bind_param("iiii", $userId, $roleId, $unionId, $roleId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Remove role from user in specific union
     * @param int $userId User ID
     * @param int $roleId Role ID
     * @param int $unionId Union ID
     * @return bool Success status
     */
    public function removeRoleFromUserInUnion($userId, $roleId, $unionId) {
        $stmt = $this->mysqli->prepare(
            "DELETE FROM user_roles 
             WHERE user_id = ? AND role_id = ? AND union_id = ?"
        );
        $stmt->bind_param("iii", $userId, $roleId, $unionId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    // =====================================================================
    // ROLE LEVEL / RANKING SYSTEM
    // =====================================================================

    /**
     * Get role level from role ID
     * @param int $roleId Role ID
     * @return int|null Role level or null if not found
     */
    public function getRoleLevelByRoleId($roleId) {
        $stmt = $this->mysqli->prepare(
            "SELECT role_level FROM roles WHERE role_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result ? (int)$result['role_level'] : null;
    }

    /**
     * Get all roles ordered by level (privilege)
     * @param string $order ASC (highest privilege first) or DESC
     * @return array Array of roles ordered by level
     */
    public function getRolesByLevel($order = 'ASC') {
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $sql = "SELECT role_id, role_name, description, role_level, created_at, updated_at 
                FROM roles 
                WHERE role_level IS NOT NULL
                ORDER BY role_level " . $order;
        
        $result = $this->mysqli->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Update role level
     * @param int $roleId Role ID
     * @param int $roleLevel Role level (1-10)
     * @return bool Success status
     */
    public function updateRoleLevel($roleId, $roleLevel) {
        $roleLevel = (int)$roleLevel;
        
        // Validate role level
        if ($roleLevel < 1 || $roleLevel > 10) {
            return false;
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE roles SET role_level = ?, updated_at = NOW() WHERE role_id = ?"
        );
        $stmt->bind_param("ii", $roleLevel, $roleId);
        $success = $stmt->execute();
        $stmt->close();
        
        return $success;
    }

    /**
     * Check if role1 has higher privilege than role2
     * @param int $role1Id First role ID
     * @param int $role2Id Second role ID
     * @return bool True if role1 has higher privilege
     */
    public function isRoleHigherPrivilege($role1Id, $role2Id) {
        $level1 = $this->getRoleLevelByRoleId($role1Id);
        $level2 = $this->getRoleLevelByRoleId($role2Id);
        
        if ($level1 === null || $level2 === null) {
            return false;
        }
        
        // Lower role_level = higher privilege
        return $level1 < $level2;
    }

    /**
     * Check if role1 has equal or higher privilege than role2
     * @param int $role1Id First role ID
     * @param int $role2Id Second role ID
     * @return bool True if role1 has equal or higher privilege
     */
    public function isRoleEqualOrHigherPrivilege($role1Id, $role2Id) {
        $level1 = $this->getRoleLevelByRoleId($role1Id);
        $level2 = $this->getRoleLevelByRoleId($role2Id);
        
        if ($level1 === null || $level2 === null) {
            return false;
        }
        
        return $level1 <= $level2;
    }

    /**
     * Get roles below a certain level
     * @param int $maxLevel Maximum role level
     * @return array Array of role IDs
     */
    public function getRolesBelowLevel($maxLevel) {
        $stmt = $this->mysqli->prepare(
            "SELECT role_id FROM roles 
             WHERE role_level > ? AND role_level IS NOT NULL
             ORDER BY role_level ASC"
        );
        $stmt->bind_param("i", $maxLevel);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return array_column($result, 'role_id');
    }

    /**
     * Get roles at or above a certain level
     * @param int $minLevel Minimum role level
     * @return array Array of role IDs
     */
    public function getRolesAboveLevel($minLevel) {
        $stmt = $this->mysqli->prepare(
            "SELECT role_id FROM roles 
             WHERE role_level <= ? AND role_level IS NOT NULL
             ORDER BY role_level ASC"
        );
        $stmt->bind_param("i", $minLevel);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return array_column($result, 'role_id');
    }

    /**
     * Get privilege gap between two roles
     * @param int $role1Id First role ID
     * @param int $role2Id Second role ID
     * @return int|null Privilege difference (lower = higher privilege)
     */
    public function getPrivilegeGap($role1Id, $role2Id) {
        $level1 = $this->getRoleLevelByRoleId($role1Id);
        $level2 = $this->getRoleLevelByRoleId($role2Id);
        
        if ($level1 === null || $level2 === null) {
            return null;
        }
        
        return abs($level2 - $level1);
    }

    /**
     * Validate role level change
     * Check if a user can change another role's level
     * @param int $userId User ID making change
     * @param int $targetRoleId Role being changed
     * @param int $newLevel New role level
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function validateRoleLevelChange($userId, $targetRoleId, $newLevel) {
        // Get user's role
        $userRole = $this->getUserPrimaryRole($userId);
        if (!$userRole) {
            return ['success' => false, 'error' => 'ব্যবহারকারীর ভূমিকা পাওয়া যায়নি'];
        }

        $userLevel = $this->getRoleLevelByRoleId($userRole['role_id']);
        if ($userLevel === null) {
            return ['success' => false, 'error' => 'ব্যবহারকারীর স্তর নির্ধারণ করা যায়নি'];
        }

        // User cannot change role to level higher than their own
        if ($newLevel < $userLevel && $userLevel !== ROLE_LEVEL_SUPERADMIN) {
            return ['success' => false, 'error' => 'আপনি নিজের চেয়ে উচ্চতর স্তরের ভূমিকা পরিবর্তন করতে পারেন না'];
        }

        // Superadmin can change any role
        if ($userLevel === ROLE_LEVEL_SUPERADMIN) {
            return ['success' => true];
        }

        // Others can only change roles below them
        return ['success' => true];
    }

    /**
     * Get user's primary role
     * @param int $userId User ID
     * @return array|null User's role data
     */
    public function getUserPrimaryRole($userId) {
        $stmt = $this->mysqli->prepare(
            "SELECT r.* FROM roles r
             JOIN users u ON u.role_id = r.role_id
             WHERE u.user_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result;
    }

    /**
     * Get highest privilege role a user can assign
     * @param int $userId User ID
     * @return int|null Minimum assignable role level
     */
    public function getHighestAssignableRoleLevel($userId) {
        $role = $this->getUserPrimaryRole($userId);
        if (!$role) {
            return null;
        }

        $userLevel = (int)$role['role_level'];
        
        // Superadmin can assign all roles
        if ($userLevel === 1) {
            return 9;  // Can assign up to level 9
        }

        // Others can assign roles below their level
        return $userLevel + 1;
    }
}

