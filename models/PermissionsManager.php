<?php
/**
 * PermissionsManager.php
 * Manages permissions, checks access, and handles permission assignments
 * 
 * UPDATED: Support for permission (name, module) lookup
 * Date: December 29, 2025
 */

class PermissionsManager {
    private $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    /**
     * Check if user has a specific permission
     * 
     * @param int $userId User ID
     * @param string $permissionName Permission name
     * @param string $module Module name (optional, defaults to 'general')
     * @return bool True if user has permission
     */
	/**
	 * Module-agnostic permission check
	 * Role has $permissionName in any module
	 */
	public function hasPermission($userId, $permissionName) {
		// Superadmin bypass
		$stmt = $this->mysqli->prepare(
			"SELECT u.role_id FROM users u
			 WHERE u.user_id = ? AND u.role_id <= 1
			 LIMIT 1"
		);
		$stmt->bind_param("i", $userId);
		$stmt->execute();
		if ($stmt->get_result()->num_rows > 0) {
			$stmt->close();
			return true;
		}
		$stmt->close();

		// Role-based permissions (ignore module)
		$sql = "SELECT COUNT(*) as count
				FROM users u
				JOIN role_permissions rp ON u.role_id = rp.role_id
				JOIN permissions p ON rp.permission_id = p.id
				WHERE u.user_id = ?
				  AND p.name = ?";
		
		$stmt = $this->mysqli->prepare($sql);
		$stmt->bind_param("is", $userId, $permissionName);
		$stmt->execute();
		$result = $stmt->get_result()->fetch_assoc();
		$stmt->close();

		return $result['count'] > 0;
	}

    /**
     * Check if user has permission in specific union
     * 
     * @param int $userId User ID
     * @param string $permissionName Permission name
     * @param int $unionId Union ID
     * @param string $module Module name (optional)
     * @return bool True if user has permission
     */
    public function hasPermissionInUnion($userId, $permissionName, $unionId, $module = 'general') {
        // Check global role first
        if ($this->hasPermission($userId, $permissionName, $module)) {
            return true;
        }

        // Check union-specific roles
        $sql = "SELECT COUNT(*) as count 
                FROM user_roles ur
                JOIN role_permissions rp ON ur.role_id = rp.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE ur.user_id = ? 
                  AND ur.union_id = ?
                  AND p.name = ? 
                  AND p.module = ?";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("iiss", $userId, $unionId, $permissionName, $module);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result['count'] > 0;
    }


	/**
	 * Module-specific permission check
	 * Role has $permissionName AND module=$module
	 */
	public function hasPermissionWithModule($userId, $permissionName, $module) {
		// Superadmin bypass
		$stmt = $this->mysqli->prepare(
			"SELECT u.role_id FROM users u
			 WHERE u.user_id = ? AND u.role_id <= 1
			 LIMIT 1"
		);
		$stmt->bind_param("i", $userId);
		$stmt->execute();
		if ($stmt->get_result()->num_rows > 0) {
			$stmt->close();
			return true;
		}
		$stmt->close();

		// Role-based permissions (check module)
		$sql = "SELECT COUNT(*) as count
				FROM users u
				JOIN role_permissions rp ON u.role_id = rp.role_id
				JOIN permissions p ON rp.permission_id = p.id
				WHERE u.user_id = ?
				  AND p.name = ?
				  AND p.module = ?";
		
		$stmt = $this->mysqli->prepare($sql);
		$stmt->bind_param("iss", $userId, $permissionName, $module);
		$stmt->execute();
		$result = $stmt->get_result()->fetch_assoc();
		$stmt->close();

		return $result['count'] > 0;
	}

	/**
	 * Module-specific permission check in union
	 */
	public function hasPermissionInUnionWithModule($userId, $permissionName, $unionId, $module) {
		// Check module-specific global permission first
		if ($this->hasPermissionWithModule($userId, $permissionName, $module)) {
			return true;
		}

		// Then check union-specific role permissions
		$sql = "SELECT COUNT(*) as count
				FROM user_roles ur
				JOIN role_permissions rp ON ur.role_id = rp.role_id
				JOIN permissions p ON rp.permission_id = p.id
				WHERE ur.user_id = ?
				  AND ur.union_id = ?
				  AND p.name = ?
				  AND p.module = ?";

		$stmt = $this->mysqli->prepare($sql);
		$stmt->bind_param("iiss", $userId, $unionId, $permissionName, $module);
		$stmt->execute();
		$result = $stmt->get_result()->fetch_assoc();
		$stmt->close();

		return $result['count'] > 0;
	}

    // =====================================================================
    // PERMISSIONS MANAGEMENT
    // =====================================================================

    /**
     * Get all permissions
     * @return array Array of all permissions
     */
    public function getAllPermissions() {
        $sql = "SELECT id, name, module, description, created_at 
                FROM permissions 
                ORDER BY module, name";
        
        $result = $this->mysqli->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Get permissions by module
     * @param string $module Module name
     * @return array Array of permissions
     */
    public function getPermissionsByModule($module) {
        $sql = "SELECT id, name, module, description, created_at 
                FROM permissions 
                WHERE module = ?
                ORDER BY name";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("s", $module);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * Get permission by ID
     * @param int $permissionId Permission ID
     * @return array|null Permission data or null
     */
    public function getPermissionById($permissionId) {
        $stmt = $this->mysqli->prepare(
            "SELECT id, name, module, description, created_at, updated_at 
             FROM permissions 
             WHERE id = ? 
             LIMIT 1"
        );
        $stmt->bind_param("i", $permissionId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    /**
     * Get permission by name and module
     * @param string $name Permission name
     * @param string $module Module name
     * @return array|null Permission data or null
     */
    public function getPermissionByNameAndModule($name, $module = 'general') {
        $stmt = $this->mysqli->prepare(
            "SELECT id, name, module, description, created_at, updated_at 
             FROM permissions 
             WHERE name = ? AND module = ? 
             LIMIT 1"
        );
        $stmt->bind_param("ss", $name, $module);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    /**
     * Create new permission
     * @param string $name Permission name
     * @param string $module Module name (defaults to 'general')
     * @param string|null $description Description
     * @return int|false Permission ID on success, false on failure
     */
    public function createPermission($name, $module = 'general', $description = null) {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO permissions (name, module, description) 
             VALUES (?, ?, ?)"
        );
        $stmt->bind_param("sss", $name, $module, $description);
        
        if ($stmt->execute()) {
            $permId = $this->mysqli->insert_id;
            $stmt->close();
            return $permId;
        }
        $stmt->close();
        return false;
    }

    /**
     * Update permission
     * @param int $permissionId Permission ID
     * @param string $name Permission name
     * @param string $module Module name
     * @param string|null $description Description
     * @return bool Success status
     */
    public function updatePermission($permissionId, $name, $module = 'general', $description = null) {
        $stmt = $this->mysqli->prepare(
            "UPDATE permissions 
             SET name = ?, module = ?, description = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param("sssi", $name, $module, $description, $permissionId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Delete permission
     * @param int $permissionId Permission ID
     * @return bool Success status
     */
    public function deletePermission($permissionId) {
        $stmt = $this->mysqli->prepare(
            "DELETE FROM permissions WHERE id = ?"
        );
        $stmt->bind_param("i", $permissionId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Get all modules
     * @return array Array of module names
     */
    public function getAllModules() {
        $result = $this->mysqli->query(
            "SELECT DISTINCT module FROM permissions ORDER BY module"
        );
        $modules = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $modules[] = $row['module'];
            }
        }
        return $modules;
    }

    /**
     * Get permission count
     * @return int Total permissions count
     */
    public function getPermissionCount() {
        $result = $this->mysqli->query(
            "SELECT COUNT(*) as count FROM permissions"
        );
        $row = $result->fetch_assoc();
        return $row['count'] ?? 0;
    }

    // =====================================================================
    // DEPRECATED METHODS (for backward compatibility)
    // =====================================================================

    /**
     * @deprecated Use hasPermission() with module parameter instead
     */
    public function assignPermission($roleId, $permissionId) {
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
     * @deprecated Use RolesManager instead
     */
    public function revokePermission($roleId, $permissionId) {
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
     * @deprecated Use RolesManager::getPermissionsByRole() instead
     */
    public function getPermissionsByRole($roleId) {
        $stmt = $this->mysqli->prepare(
            "SELECT p.* FROM permissions p
             JOIN role_permissions rp ON p.id = rp.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.module, p.name"
        );
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * @deprecated Use RolesManager instead
     */
    public function assignRoleToUser($userId, $roleId, $unionId = null) {
        if ($unionId !== null) {
            $stmt = $this->mysqli->prepare(
                "INSERT INTO user_roles (user_id, role_id, union_id) 
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE role_id = ?, updated_at = NOW()"
            );
            $stmt->bind_param("iiii", $userId, $roleId, $unionId, $roleId);
        } else {
            $stmt = $this->mysqli->prepare(
                "UPDATE users SET role_id = ? WHERE user_id = ?"
            );
            $stmt->bind_param("ii", $roleId, $userId);
        }
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * @deprecated Use RolesManager instead
     */
    public function revokeRoleFromUser($userId, $roleId, $unionId = null) {
        if ($unionId !== null) {
            $stmt = $this->mysqli->prepare(
                "DELETE FROM user_roles 
                 WHERE user_id = ? AND role_id = ? AND union_id = ?"
            );
            $stmt->bind_param("iii", $userId, $roleId, $unionId);
        } else {
            $stmt = $this->mysqli->prepare(
                "UPDATE users SET role_id = 3 WHERE user_id = ?"
            );
            $stmt->bind_param("i", $userId);
        }
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }


    public function getRolePermissions($roleId) {
        $roleId = (int)$roleId;
        $query = "SELECT p.* FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
                ORDER BY p.module, p.name";
        
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $result = $stmt->get_result();
        $permissions = [];
        
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row;
        }
        
        $stmt->close();
        return $permissions;
    }

}


