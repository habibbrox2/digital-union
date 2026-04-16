<?php
/**
 * User Helper Functions
 * Production-ready helper functions for user data access
 * Replaces undefined functions like getUserProfileById(), etc.
 */

// ==================== USER PROFILE & DATA RETRIEVAL ====================

if (!function_exists('getUserProfileById')) {
    /**
     * Get complete user profile by ID
     * Fetches user data with union information
     * 
     * @param int $userId User ID
     * @return array|null User profile data or null if not found
     */
    function getUserProfileById($userId) {
        global $mysqli;
        
        if (!is_numeric($userId) || $userId <= 0) {
            return null;
        }
        
        $userId = (int)$userId;
        
        $stmt = $mysqli->prepare(
            "SELECT * FROM users 
             WHERE user_id = ? AND is_deleted = 0 
             LIMIT 1"
        );
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
}

if (!function_exists('getUserById')) {
    /**
     * Get user by ID (alias for getUserProfileById)
     * 
     * @param int $userId User ID
     * @return array|null User data or null if not found
     */
    function getUserById($userId) {
        return getUserProfileById($userId);
    }
}

if (!function_exists('getUserByEmail')) {
    /**
     * Get user by email address
     * 
     * @param string $email User email
     * @return array|null User data or null if not found
     */
    function getUserByEmail($email) {
        global $mysqli;
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        $stmt = $mysqli->prepare(
            "SELECT * FROM users 
             WHERE email = ? AND is_deleted = 0 
             LIMIT 1"
        );
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
}

if (!function_exists('getUserByUsername')) {
    /**
     * Get user by username
     * 
     * @param string $username Username
     * @return array|null User data or null if not found
     */
    function getUserByUsername($username) {
        global $mysqli;
        
        $username = sanitize_input($username);
        
        $stmt = $mysqli->prepare(
            "SELECT * FROM users 
             WHERE username = ? AND is_deleted = 0 
             LIMIT 1"
        );
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
}

if (!function_exists('getAllUsers')) {
    /**
     * Get all non-deleted users with optional filtering
     * 
     * @param array $filters Optional filters (role_id, union_id, status, etc.)
     * @param string $sortBy Column to sort by
     * @param string $sortDir Sort direction (ASC or DESC)
     * @return array Array of user records
     */
    function getAllUsers($filters = [], $sortBy = 'user_id', $sortDir = 'ASC') {
        global $mysqli;
        
        $sql = "SELECT * FROM users WHERE is_deleted = 0";
        $params = [];
        $types = '';
        
        // Apply filters if provided
        if (!empty($filters)) {
            if (isset($filters['role_id'])) {
                $sql .= " AND role_id = ?";
                $params[] = (int)$filters['role_id'];
                $types .= 'i';
            }
            
            if (isset($filters['union_id'])) {
                $sql .= " AND union_id = ?";
                $params[] = (int)$filters['union_id'];
                $types .= 'i';
            }
            
            if (isset($filters['status'])) {
                $sql .= " AND status = ?";
                $params[] = sanitize_input($filters['status']);
                $types .= 's';
            }
            
            if (isset($filters['search'])) {
                $search = '%' . sanitize_input($filters['search']) . '%';
                $sql .= " AND (username LIKE ? OR email LIKE ? OR name_en LIKE ? OR name_bn LIKE ?)";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $types .= 'ssss';
            }
        }
        
        // Validate sort column (prevent SQL injection)
        $validSortColumns = ['user_id', 'username', 'email', 'name_en', 'name_bn', 
                            'role_id', 'union_id', 'status', 'created_at'];
        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'user_id';
        }
        
        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY {$sortBy} {$sortDir}";
        
        if (empty($params)) {
            $result = $mysqli->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('getUsersByRole')) {
    /**
     * Get all users with specific role
     * 
     * @param int $roleId Role ID
     * @return array Array of user records
     */
    function getUsersByRole($roleId) {
        return getAllUsers(['role_id' => (int)$roleId]);
    }
}

if (!function_exists('getUsersByUnion')) {
    /**
     * Get all users in specific union
     * 
     * @param int $unionId Union ID
     * @return array Array of user records
     */
    function getUsersByUnion($unionId) {
        return getAllUsers(['union_id' => (int)$unionId]);
    }
}

// ==================== USER DATA VALIDATION & EXISTENCE ====================

if (!function_exists('userExists')) {
    /**
     * Check if user exists by ID
     * 
     * @param int $userId User ID
     * @return bool True if user exists and not deleted
     */
    function userExists($userId) {
        global $mysqli;
        
        if (!is_numeric($userId)) {
            return false;
        }
        
        $stmt = $mysqli->prepare(
            "SELECT 1 FROM users 
             WHERE user_id = ? AND is_deleted = 0 
             LIMIT 1"
        );
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("i", (int)$userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('emailExists')) {
    /**
     * Check if email exists (excluding a specific user if needed)
     * 
     * @param string $email Email to check
     * @param int $excludeUserId Optional: exclude specific user from check
     * @return bool True if email exists
     */
    function emailExists($email, $excludeUserId = null) {
        global $mysqli;
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        if ($excludeUserId) {
            $stmt = $mysqli->prepare(
                "SELECT 1 FROM users 
                 WHERE email = ? AND user_id != ? AND is_deleted = 0 
                 LIMIT 1"
            );
            $stmt->bind_param("si", $email, (int)$excludeUserId);
        } else {
            $stmt = $mysqli->prepare(
                "SELECT 1 FROM users 
                 WHERE email = ? AND is_deleted = 0 
                 LIMIT 1"
            );
            $stmt->bind_param("s", $email);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('usernameExists')) {
    /**
     * Check if username exists (excluding a specific user if needed)
     * 
     * @param string $username Username to check
     * @param int $excludeUserId Optional: exclude specific user from check
     * @return bool True if username exists
     */
    function usernameExists($username, $excludeUserId = null) {
        global $mysqli;
        
        $username = sanitize_input($username);
        
        if ($excludeUserId) {
            $stmt = $mysqli->prepare(
                "SELECT 1 FROM users 
                 WHERE username = ? AND user_id != ? AND is_deleted = 0 
                 LIMIT 1"
            );
            $stmt->bind_param("si", $username, (int)$excludeUserId);
        } else {
            $stmt = $mysqli->prepare(
                "SELECT 1 FROM users 
                 WHERE username = ? AND is_deleted = 0 
                 LIMIT 1"
            );
            $stmt->bind_param("s", $username);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result && $result->num_rows > 0;
    }
}

// ==================== USER PROFILE UPDATES ====================

if (!function_exists('updateUserProfile')) {
    /**
     * Update user profile fields
     * 
     * @param int $userId User ID
     * @param array $data Fields to update (name_bn, name_en, phone_number, etc.)
     * @return array Result array with success status
     */
    function updateUserProfile($userId, $data) {
        global $mysqli;
        
        if (!is_numeric($userId) || !is_array($data)) {
            return ['success' => false, 'message' => 'আপনার এই সুবিধা নেই'];
        }
        
        $userId = (int)$userId;
        
        // Allowed fields for user profile update
        $allowedFields = [
            'name_bn', 'name_en', 'phone_number', 'address', 'bio',
            'designation', 'ward_no', 'language_preference', 'timezone',
            'is_email_notifications_enabled', 'is_sms_notifications_enabled',
            'profile_picture_url'
        ];
        
        $updates = [];
        $params = [];
        $types = '';
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $updates[] = "{$key} = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : 's';
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'message' => 'কোন আপডেট ডেটা প্রদান করা হয়েছে না'];
        }
        
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ? AND is_deleted = 0";
        $params[] = $userId;
        $types .= 'i';
        
        $stmt = $mysqli->prepare($sql);
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'ডেটাবেস ত্রুটি: ' . $mysqli->error];
        }
        
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'ব্যবহারকারী প্রোফাইল সফলভাবে আপডেট হয়েছে'
            ];
        }
        
        return ['success' => false, 'message' => 'আপডেট করতে ব্যর্থ হয়েছে: ' . $stmt->error];
    }
}

if (!function_exists('updateUserPassword')) {
    /**
     * Update user password
     * 
     * @param int $userId User ID
     * @param string $newPassword New password (plaintext)
     * @return array Result array with success status
     */
    function updateUserPassword($userId, $newPassword) {
        global $mysqli;
        
        if (!is_numeric($userId) || strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'পাসওয়ার্ড অন্তত 6 অক্ষর দীর্ঘ হওয়া আবশ্যক'];
        }
        
        $userId = (int)$userId;
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $stmt = $mysqli->prepare(
            "UPDATE users SET password = ? WHERE user_id = ? AND is_deleted = 0"
        );
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'ডেটাবেস ত্রুটি'];
        }
        
        $stmt->bind_param("si", $hashedPassword, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'পাসওয়ার্ড সফলভাবে পরিবর্তিত হয়েছে'];
        }
        
        return ['success' => false, 'message' => 'পাসওয়ার্ড পরিবর্তন করতে ব্যর্থ হয়েছে'];
    }
}

// ==================== USER STATUS & ROLE MANAGEMENT ====================

if (!function_exists('isUserAdmin')) {
    /**
     * Check if user is admin (role_id > 1)
     * 
     * @param int $userId User ID
     * @return bool True if user is admin
     */
    function isUserAdmin($userId) {
        $user = getUserProfileById($userId);
        return $user && (int)$user['role_id'] > 1;
    }
}

if (!function_exists('isUserActive')) {
    /**
     * Check if user is active (status = 'active')
     * 
     * @param int $userId User ID
     * @return bool True if user is active
     */
    function isUserActive($userId) {
        $user = getUserProfileById($userId);
        return $user && $user['status'] === 'active';
    }
}

if (!function_exists('activateUser')) {
    /**
     * Activate a user account
     * 
     * @param int $userId User ID
     * @return array Result array
     */
    function activateUser($userId) {
        global $mysqli;
        
        $userId = (int)$userId;
        $status = 'active';
        
        $stmt = $mysqli->prepare(
            "UPDATE users SET status = ? WHERE user_id = ? AND is_deleted = 0"
        );
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'ডেটাবেস ত্রুটি'];
        }
        
        $stmt->bind_param("si", $status, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'ব্যবহারকারী সক্রিয় হয়েছে'];
        }
        
        return ['success' => false, 'message' => 'সক্রিয় করতে ব্যর্থ হয়েছে'];
    }
}

if (!function_exists('deactivateUser')) {
    /**
     * Deactivate a user account
     * 
     * @param int $userId User ID
     * @return array Result array
     */
    function deactivateUser($userId) {
        global $mysqli;
        
        $userId = (int)$userId;
        $status = 'inactive';
        
        $stmt = $mysqli->prepare(
            "UPDATE users SET status = ? WHERE user_id = ? AND is_deleted = 0"
        );
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'ডেটাবেস ত্রুটি'];
        }
        
        $stmt->bind_param("si", $status, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'ব্যবহারকারী নিষ্ক্রিয় হয়েছে'];
        }
        
        return ['success' => false, 'message' => 'নিষ্ক্রিয় করতে ব্যর্থ হয়েছে'];
    }
}

if (!function_exists('deleteUser')) {
    /**
     * Soft delete a user (mark as deleted)
     * 
     * @param int $userId User ID
     * @return array Result array
     */
    function deleteUser($userId) {
        global $mysqli;
        
        $userId = (int)$userId;
        $now = date('Y-m-d H:i:s');
        
        $stmt = $mysqli->prepare(
            "UPDATE users SET is_deleted = 1, deleted_at = ? WHERE user_id = ?"
        );
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'ডেটাবেস ত্রুটি'];
        }
        
        $stmt->bind_param("si", $now, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'ব্যবহারকারী সফলভাবে মুছে ফেলা হয়েছে'];
        }
        
        return ['success' => false, 'message' => 'মুছে ফেলতে ব্যর্থ হয়েছে'];
    }
}

if (!function_exists('restoreUser')) {
    /**
     * Restore a deleted user
     * 
     * @param int $userId User ID
     * @return array Result array
     */
    function restoreUser($userId) {
        global $mysqli;
        
        $userId = (int)$userId;
        
        $stmt = $mysqli->prepare(
            "UPDATE users SET is_deleted = 0, deleted_at = NULL WHERE user_id = ?"
        );
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'ডেটাবেস ত্রুটি'];
        }
        
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'ব্যবহারকারী পুনরুদ্ধার হয়েছে'];
        }
        
        return ['success' => false, 'message' => 'পুনরুদ্ধার করতে ব্যর্থ হয়েছে'];
    }
}

// ==================== USER STATISTICS & COUNTS ====================

if (!function_exists('getUserCount')) {
    /**
     * Get count of active users
     * 
     * @param array $filters Optional filters
     * @return int Count of users
     */
    function getUserCount($filters = []) {
        global $mysqli;
        
        $sql = "SELECT COUNT(*) as total FROM users WHERE is_deleted = 0";
        
        if (isset($filters['role_id'])) {
            $sql .= " AND role_id = " . (int)$filters['role_id'];
        }
        
        if (isset($filters['union_id'])) {
            $sql .= " AND union_id = " . (int)$filters['union_id'];
        }
        
        if (isset($filters['status'])) {
            $sql .= " AND status = '" . $mysqli->real_escape_string($filters['status']) . "'";
        }
        
        $result = $mysqli->query($sql);
        
        if ($result) {
            $row = $result->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }
        
        return 0;
    }
}

// ==================== USER SESSION & AUTHENTICATION HELPERS ====================

if (!function_exists('getLoggedInUser')) {
    /**
     * Get currently logged-in user data
     * 
     * @return array|null User data if logged in, null otherwise
     */
    function getLoggedInUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        return getUserProfileById($_SESSION['user_id']);
    }
}

if (!function_exists('getCurrentUserId')) {
    /**
     * Get currently logged-in user ID
     * 
     * @return int|null User ID if logged in, null otherwise
     */
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentUserRole')) {
    /**
     * Get role ID of currently logged-in user
     * 
     * @return int|null Role ID if logged in, null otherwise
     */
    function getCurrentUserRole() {
        return $_SESSION['role_id'] ?? null;
    }
}

if (!function_exists('getCurrentUserUnion')) {
    /**
     * Get union ID of currently logged-in user
     * 
     * @return int|null Union ID if logged in, null otherwise
     */
    function getCurrentUserUnion() {
        $user = getLoggedInUser();
        return $user ? $user['union_id'] : null;
    }
}
