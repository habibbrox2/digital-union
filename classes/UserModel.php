<?php

/**
 * User Model - Production Ready (Updated for Database Schema)
 * Handles all user-related database operations with security and validation
 */

class UserModel {
    private $mysqli;
    private $table = 'users';
    
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }
    
    /**
     * Create a new user
     */
    public function create($data) {
        if (!$this->validate($data)) {
            return ['success' => false, 'error' => 'Validation failed'];
        }
        
        $stmt = $this->mysqli->prepare(
            "INSERT INTO {$this->table} 
            (union_id, username, email, password, name_bn, name_en, phone_number, 
             address, role_id, designation, ward_no, status, 
             language_preference, timezone, is_email_notifications_enabled, 
             is_sms_notifications_enabled) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => $this->mysqli->error];
        }
        
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
        
        $stmt->bind_param(
            "isssssssississii",
            $data['union_id'],
            $data['username'],
            $data['email'],
            $hashedPassword,
            $data['name_bn'],
            $data['name_en'],
            $data['phone_number'],
            $data['address'],
            $data['role_id'],
            $data['designation'],
            $data['ward_no'],
            $data['status'],
            $data['language_preference'],
            $data['timezone'],
            $data['is_email_notifications_enabled'],
            $data['is_sms_notifications_enabled']
        );
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'user_id' => $this->mysqli->insert_id,
                'message' => 'ব্যবহারকারী সফলভাবে তৈরি হয়েছে'
            ];
        }
        
        return ['success' => false, 'error' => $stmt->error];
    }
    
    /**
     * Get user by ID
     */
    public function getById($userId) {
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM {$this->table} 
             WHERE user_id = ? AND is_deleted = 0"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    /**
     * Find user by ID (alias for getById)
     */

    public function findById($userId) {
        return $this->getById($userId);
    }   

    /**
     * Get user by ID with union details
     */
    public function getByIdWithUnion($userId) {
        $sql = "SELECT u.*, un.union_name_bn, un.union_name_en
                FROM {$this->table} u
                LEFT JOIN unions un ON u.union_id = un.union_id
                WHERE u.user_id = ? AND u.is_deleted = 0 LIMIT 1";
        
        $stmt = $this->mysqli->prepare($sql);
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        return $res->num_rows === 1 ? $res->fetch_assoc() : null;
    }

    /**
     * Get user by email
     */
    public function getByEmail($email) {
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM {$this->table} 
             WHERE email = ? AND is_deleted = 0"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    /**
     * Get user by username
     */
    public function getByUsername($username) {
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM {$this->table} 
             WHERE username = ? AND is_deleted = 0"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    /**
     * Update user details
     */
    public function update($userId, $data) {
        if (!$this->validateUpdate($data)) {
            return ['success' => false, 'error' => 'Validation failed'];
        }
        
        $updates = [];
        $params = [];
        $types = '';
        
        $allowedFields = [
            'username', 'email', 'name_bn', 'name_en', 'phone_number', 'address', 'bio',
            'designation', 'ward_no', 'language_preference', 'timezone',
            'is_email_notifications_enabled', 'is_sms_notifications_enabled',
            'status', 'profile_picture_url', 'role_id', 'union_id'
        ];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $updates[] = "{$key} = ?";
                $params[] = $value;
                
                if (is_null($value)) {
                    $types .= 's';
                } elseif (is_int($value)) {
                    $types .= 'i';
                } else {
                    $types .= 's';
                }
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'কোন আপডেট ডেটা প্রদান করা হয়নি'];
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE user_id = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Query preparation failed: ' . $this->mysqli->error];
        }
        
        // Add userId to params
        $params[] = $userId;
        $types .= 'i';
        
        // Create a stable reference array
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = $params[$key];
        }
        
        $bindArray = [$types];
        foreach ($refs as $key => $value) {
            $bindArray[] = &$refs[$key];
        }
        
        if (!call_user_func_array([$stmt, 'bind_param'], $bindArray)) {
            $stmt->close();
            return ['success' => false, 'error' => 'Parameter binding failed'];
        }
        
        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'ব্যবহারকারী সফলভাবে আপডেট হয়েছে'];
        }
        
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => $error];
    }

     
    /**
     * Update password
     */
    public function updatePassword($userId, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET password = ?, last_password_change = NOW() 
             WHERE user_id = ?"
        );
        $stmt->bind_param("si", $hashedPassword, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে'];
        }
        
        return ['success' => false, 'error' => $stmt->error];
    }
    
    /**
     * Update login info
     */
    public function updateLoginInfo($userId, $ipAddress, $userAgent) {
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET last_login = NOW(), ip_address = ?, user_agent = ?, login_attempts = 0 
             WHERE user_id = ?"
        );
        $stmt->bind_param("ssi", $ipAddress, $userAgent, $userId);
        return $stmt->execute();
    }
    
    /**
     * Increment login attempts
     */
    public function incrementLoginAttempts($userId) {
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET login_attempts = login_attempts + 1 
             WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    }
    
    /**
     * Lock account
     */
    public function lockAccount($userId, $minutes = 30) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
        
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET locked_until = ? 
             WHERE user_id = ?"
        );
        $stmt->bind_param("si", $lockedUntil, $userId);
        return $stmt->execute();
    }
    
    /**
     * Verify email
     */
    public function verifyEmail($userId) {
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET email_verified_at = NOW() 
             WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    }
    
    /**
     * Verify phone
     */
    public function verifyPhone($userId) {
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET phone_verified_at = NOW() 
             WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    }
    
    /**
     * Soft delete user
     */
    public function softDelete($userId) {
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET is_deleted = 1, deleted_at = NOW(), status = 'inactive' 
             WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'ব্যবহারকারী সফলভাবে মুছে ফেলা হয়েছে'];
        }
        
        return ['success' => false, 'error' => $stmt->error];
    }
    
    /**
     * Restore user
     */
    public function restore($userId) {
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} 
             SET is_deleted = 0, deleted_at = NULL, status = 'active' 
             WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'ব্যবহারকারী পুনরুদ্ধার করা হয়েছে'];
        }
        
        return ['success' => false, 'error' => $stmt->error];
    }
    
    /**
     * Get all users by union
     */
    public function getByUnion($unionId, $limit = 50, $offset = 0) {
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM {$this->table} 
            WHERE union_id = ? AND is_deleted = 0 
            LIMIT ? OFFSET ?"
        );
        
        if (!$stmt) {
            return [];
        }
        
        // Fix: Create stable variables for references
        $param1 = $unionId;
        $param2 = $limit;
        $param3 = $offset;
        
        $stmt->bind_param("iii", $param1, $param2, $param3);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $data;
    }

    
    /**
     * Get all users by role
     */
    public function getByRole($roleId, $limit = 50, $offset = 0) {
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM {$this->table} 
            WHERE role_id = ? AND is_deleted = 0 
            LIMIT ? OFFSET ?"
        );
        
        if (!$stmt) {
            return [];
        }
        
        // Fix: Create stable variables for references
        $param1 = $roleId;
        $param2 = $limit;
        $param3 = $offset;
        
        $stmt->bind_param("iii", $param1, $param2, $param3);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $data;
    }

    
    /**
     * Search users
     */
    public function search($searchTerm, $limit = 50, $offset = 0) {
        $searchTerm = '%' . $searchTerm . '%';
        
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM {$this->table} 
            WHERE (username LIKE ? OR email LIKE ? OR name_bn LIKE ? OR name_en LIKE ?) 
            AND is_deleted = 0 
            LIMIT ? OFFSET ?"
        );
        
        if (!$stmt) {
            return [];
        }
        
        // Fix: Create stable variables for references
        $param1 = $searchTerm;
        $param2 = $searchTerm;
        $param3 = $searchTerm;
        $param4 = $searchTerm;
        $param5 = $limit;
        $param6 = $offset;
        
        $stmt->bind_param("ssssii", $param1, $param2, $param3, $param4, $param5, $param6);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $data;
    }

    
    /**
     * Count all users
     */
    public function count($includeDeleted = false) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        if (!$includeDeleted) {
            $sql .= " WHERE is_deleted = 0";
        }
        
        $result = $this->mysqli->query($sql);
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    
    /**
     * Validate user data
     */
    private function validate($data) {
        // Username validation
        if (empty($data['username']) || strlen($data['username']) < 3) {
            return false;
        }
        
        // Email validation
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Password validation
        if (empty($data['password']) || strlen($data['password']) < 6) {
            return false;
        }
        
        // Check duplicate username
        if ($this->getByUsername($data['username'])) {
            return false;
        }
        
        // Check duplicate email
        if ($this->getByEmail($data['email'])) {
            return false;
        }
        
        // Phone number validation if provided
        if (!empty($data['phone_number']) && !preg_match('/^[+]?[0-9]{10,15}$/', $data['phone_number'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate update data
     */
    private function validateUpdate($data) {
        // Phone number validation if provided
        if (!empty($data['phone_number']) && !preg_match('/^[+]?[0-9]{10,15}$/', $data['phone_number'])) {
            return false;
        }
        
        // Email validation if provided
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Upload profile picture
     */
    public function uploadProfilePicture($file) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxFileSize) {
            return null;
        }
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        if (!in_array($mime, $allowedTypes)) {
            return null;
        }
        
        if (!getimagesize($file['tmp_name'])) {
            return null;
        }
        
        $uploadDir = __DIR__ . '/../public/uploads/profiles/';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif'
        ][$mime];
        
        $fileName = uniqid('profile_', true) . $ext;
        $target = $uploadDir . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $target)) {
            chmod($target, 0644);
            return "/uploads/profiles/" . $fileName;
        }
        
        return null;
    }
    
    /**
     * Get user details with union info
     */
    public function getUserDetails($userId) {
        $sql = "SELECT u.*, r.role_name, un.union_name_bn, un.union_name_en
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN unions un ON u.union_id = un.union_id
            WHERE u.user_id = ? AND u.is_deleted = 0 LIMIT 1";
        
        $stmt = $this->mysqli->prepare($sql);
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        return $res->num_rows === 1 ? $res->fetch_assoc() : null;
    }
    
    /**
     * Get all users with optional filters
     */
    public function getAll($filters = [], $sortBy = 'user_id', $sortDir = 'ASC') {
        $sql = "SELECT u.*, 
                   r.role_name, 
                   un.union_name_bn, 
                   un.union_name_en 
            FROM {$this->table} u 
            LEFT JOIN roles r ON u.role_id = r.role_id 
            LEFT JOIN unions un ON u.union_id = un.union_id 
            WHERE u.is_deleted = 0";
        
        $params = [];
        $types = '';
        
        if (!empty($filters)) {
            if (isset($filters['role_id'])) {
                $sql .= " AND u.role_id = ?";
                $params[] = (int)$filters['role_id'];
                $types .= 'i';
            }
            
            if (isset($filters['union_id'])) {
                $sql .= " AND u.union_id = ?";
                $params[] = (int)$filters['union_id'];
                $types .= 'i';
            }
            
            if (isset($filters['status'])) {
                $sql .= " AND u.status = ?";
                $params[] = $filters['status'];
                $types .= 's';
            }
            
            if (isset($filters['search'])) {
                $search = '%' . $filters['search'] . '%';
                $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.name_en LIKE ? OR u.name_bn LIKE ?)";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $types .= 'ssss';
            }
            
            if (isset($filters['exclude_superadmin']) && $filters['exclude_superadmin'] === true) {
                $sql .= " AND u.role_id > 1";
            }
        }
        
        $validSortColumns = ['user_id', 'username', 'email', 'name_en', 'name_bn', 
                            'role_id', 'union_id', 'status', 'created_at', 'last_login'];
        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'user_id';
        }
        
        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY u.{$sortBy} {$sortDir}";
        
        // Add LIMIT and OFFSET if provided
        if (isset($filters['limit']) && isset($filters['offset'])) {
            $limit = (int)$filters['limit'];
            $offset = (int)$filters['offset'];
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';
        }
        
        if (empty($params)) {
            $result = $this->mysqli->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        
        // Bind parameters
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = $params[$key];
        }
        
        $bindArray = [$types];
        foreach ($refs as $key => $value) {
            $bindArray[] = &$refs[$key];
        }
        
        call_user_func_array([$stmt, 'bind_param'], $bindArray);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        
        return $data;
    }

    
    /**
     * Check if user exists by ID
     */
    public function exists($userId) {
        if (!is_numeric($userId)) {
            return false;
        }
        
        $stmt = $this->mysqli->prepare(
            "SELECT 1 FROM {$this->table} 
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
    
    /**
     * Check if email exists (excluding a specific user if needed)
     */
    public function emailExists($email, $excludeUserId = null) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        if ($excludeUserId) {
            $stmt = $this->mysqli->prepare(
                "SELECT 1 FROM {$this->table} 
                WHERE email = ? AND user_id != ? AND is_deleted = 0 
                LIMIT 1"
            );
            
            if (!$stmt) {
                return false;
            }
            
            // Fix: Create stable variables for references
            $param1 = $email;
            $param2 = (int)$excludeUserId;
            $stmt->bind_param("si", $param1, $param2);
        } else {
            $stmt = $this->mysqli->prepare(
                "SELECT 1 FROM {$this->table} 
                WHERE email = ? AND is_deleted = 0 
                LIMIT 1"
            );
            
            if (!$stmt) {
                return false;
            }
            
            // Fix: Create stable variable for reference
            $param1 = $email;
            $stmt->bind_param("s", $param1);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();
        
        return $exists;
    }

    
    // ============================================
    // 2. USERNAME EXISTS - Fixed
    // ============================================

    public function usernameExists($username, $excludeUserId = null) {
        if ($excludeUserId) {
            $stmt = $this->mysqli->prepare(
                "SELECT 1 FROM {$this->table} 
                WHERE username = ? AND user_id != ? AND is_deleted = 0 
                LIMIT 1"
            );
            
            if (!$stmt) {
                return false;
            }
            
            // Fix: Create stable variables for references
            $param1 = $username;
            $param2 = (int)$excludeUserId;
            $stmt->bind_param("si", $param1, $param2);
        } else {
            $stmt = $this->mysqli->prepare(
                "SELECT 1 FROM {$this->table} 
                WHERE username = ? AND is_deleted = 0 
                LIMIT 1"
            );
            
            if (!$stmt) {
                return false;
            }
            
            // Fix: Create stable variable for reference
            $param1 = $username;
            $stmt->bind_param("s", $param1);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();
        
        return $exists;
    }

    
    /**
     * Activate user
     */
    public function activate($userId) {
        $userId = (int)$userId;
        $status = 'active';
        
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} SET status = ? WHERE user_id = ? AND is_deleted = 0"
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
    
    /**
     * Deactivate user
     */
    public function deactivate($userId) {
        $userId = (int)$userId;
        $status = 'inactive';
        
        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table} SET status = ? WHERE user_id = ? AND is_deleted = 0"
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
    
    /**
     * Check if user is admin
     */
    public function isAdmin($userId) {
        $user = $this->getById($userId);
        return $user && (int)$user['role_id'] <= 1;
    }
    
    /**
     * Check if user is active
     */
    public function isActive($userId) {
        $user = $this->getById($userId);
        return $user && $user['status'] === 'active';
    }
    
    /**
     * Get user count
     */
    public function countUsers($filters = []) {
        $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE is_deleted = 0";
        
        if (isset($filters['role_id'])) {
            $sql .= " AND role_id = " . (int)$filters['role_id'];
        }
        
        if (isset($filters['union_id'])) {
            $sql .= " AND union_id = " . (int)$filters['union_id'];
        }
        
        if (isset($filters['status'])) {
            $sql .= " AND status = '" . $this->mysqli->real_escape_string($filters['status']) . "'";
        }
        
        if (isset($filters['exclude_superadmin']) && $filters['exclude_superadmin'] === true) {
            $sql .= " AND role_id > 1";
        }
        
        $result = $this->mysqli->query($sql);
        
        if ($result) {
            $row = $result->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }
        
        return 0;
    }
}