<?php
/**
 * modules/Services/UserService.php
 * 
 * Service layer for user management business logic.
 * Handles profile updates, password changes, user CRUD, bulk operations,
 * CSV export, file uploads, and RBAC validation.
 * All DB operations delegated to UserModel.
 */

class UserService
{
    private mysqli $mysqli;
    private UserModel $userModel;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->userModel = new UserModel($mysqli);
    }

    // =====================================================================
    // PROFILE OPERATIONS
    // =====================================================================

    /**
     * Update user profile
     */
    public function updateProfile(int $userId, array $input): array
    {
        $data = [
            'name_bn' => sanitize_input($input['name_bn'] ?? ''),
            'name_en' => sanitize_input($input['name_en'] ?? ''),
            'phone_number' => sanitize_input($input['phone_number'] ?? ''),
            'address' => sanitize_input($input['address'] ?? ''),
            'bio' => sanitize_input($input['bio'] ?? ''),
        ];

        // Pass through profile_picture_url if set (handled by route upload)
        if (!empty($input['profile_picture_url'])) {
            $data['profile_picture_url'] = $input['profile_picture_url'];
        }

        $result = $this->userModel->update($userId, $data);

        if ($result['success']) {
            return [
                'success' => true,
                'alert' => ['title' => 'সফল', 'message' => $result['message'], 'type' => 'success'],
                'redirect' => '/profile',
            ];
        }

        return [
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => $result['error'] ?? 'আপডেট করা যায়নি', 'type' => 'error'],
        ];
    }

    /**
     * Change user password with validation
     */
    public function changePassword(int $userId, array $input): array
    {
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'নতুন পাসওয়ার্ড এবং নিশ্চিতকরণ পাসওয়ার্ড মেলে না', 'type' => 'error'],
            ];
        }

        $userData = $this->userModel->getById($userId);
        if (!$userData || !password_verify($currentPassword, $userData['password'])) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'বর্তমান পাসওয়ার্ড সঠিক নয়', 'type' => 'error'],
            ];
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateResult = $this->userModel->update($userId, [
            'password' => $hashedPassword,
            'last_password_change' => date('Y-m-d H:i:s'),
        ]);

        if (!$updateResult['success']) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => $updateResult['error'] ?? 'পাসওয়ার্ড পরিবর্তন ব্যর্থ হয়েছে', 'type' => 'error'],
            ];
        }

        // Send email notification if enabled
        if (!empty($userData['email']) && function_exists('sendPasswordChangedEmail')) {
            sendPasswordChangedEmail($userData['email'], false);
        }

        return [
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => 'পাসওয়ার্ড সফলভাবে পরিবর্তিত হয়েছে', 'type' => 'success'],
            'redirect' => '/profile',
        ];
    }

    // =====================================================================
    // USER CRUD
    // =====================================================================

    /**
     * Validate and create a new user
     */
    public function createUser(array $data, UserModel $userModel): array
    {
        $errors = [];

        if (!$data['username']) $errors[] = 'ব্যবহারকারীনাম প্রয়োজন';
        if (!$data['email'] || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'সঠিক ইমেইল প্রয়োজন';
        if (!$data['name_bn']) $errors[] = 'বাংলা নাম প্রয়োজন';
        if (!$data['password']) $errors[] = 'পাসওয়ার্ড প্রয়োজন';
        if ($data['password'] !== $data['confirm_password']) $errors[] = 'পাসওয়ার্ড মিলছে না';
        if ($data['role_id'] <= 0) $errors[] = 'সঠিক রোল নির্বাচন করুন';

        if ($userModel->usernameExists($data['username'])) $errors[] = 'এই ব্যবহারকারীর নাম ইতিমধ্যে ব্যবহৃত হয়েছে';
        if ($userModel->emailExists($data['email'])) $errors[] = 'এই ইমেইল ইতিমধ্যে ব্যবহৃত হয়েছে';

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $userData = [
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'name_bn' => $data['name_bn'],
            'name_en' => $data['name_en'] ?? '',
            'phone_number' => $data['phone_number'] ?? '',
            'role_id' => $data['role_id'],
            'union_id' => $data['union_id'] ?? null,
            'ward_no' => $data['ward_no'] ?? '',
            'address' => $data['address'] ?? '',
            'designation' => $data['designation'] ?? '',
            'status' => $data['status'] ?? 'active',
            'language_preference' => $data['language_preference'] ?? 'bn',
            'timezone' => $data['timezone'] ?? 'Asia/Dhaka',
            'is_email_notifications_enabled' => $data['is_email_notifications_enabled'] ?? 0,
            'is_sms_notifications_enabled' => $data['is_sms_notifications_enabled'] ?? 0,
        ];

        return $userModel->create($userData);
    }

    /**
     * Validate user data for privilege escalation prevention
     */
    public function validateUserDataForPrivilegeEscalation(int $currentUserId, array $userData, array &$errors = []): bool
    {
        if (empty($currentUserId) || empty($userData)) {
            $errors[] = 'অবৈধ ব্যবহারকারী তথ্য';
            return false;
        }

        $currentUserRoleId = $this->getUserRoleId($currentUserId);
        if ($currentUserRoleId === null) {
            $errors[] = 'বর্তমান ব্যবহারকারী পাওয়া যায়নি';
            return false;
        }

        if ($currentUserRoleId === 1) {
            return true;
        }

        if (isset($userData['role_id']) && (int)$userData['role_id'] === 1) {
            $errors[] = 'আপনি সুপারঅ্যাডমিন ভূমিকা নির্ধারণ করতে পারেন না';
            return false;
        }

        if (isset($userData['role_id'])) {
            $targetRoleId = (int)$userData['role_id'];
            if ($targetRoleId < $currentUserRoleId) {
                $errors[] = 'আপনি নিজের চেয়ে উচ্চতর ভূমিকা নির্ধারণ করতে পারেন না';
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a user can manage another user by role level
     */
    public function canManageUserByLevel(int $managerId, int $targetId): bool
    {
        if ($managerId === $targetId) {
            return false;
        }

        $managerLevel = $this->getUserRoleLevel($managerId);
        $targetLevel = $this->getUserRoleLevel($targetId);

        if ($managerLevel === null || $targetLevel === null) {
            return false;
        }

        if ($managerLevel === 1) {
            return $targetLevel !== 1;
        }

        return $managerLevel < $targetLevel;
    }

    // =====================================================================
    // DELETE OPERATIONS
    // =====================================================================

    /**
     * Soft-delete a single user with RBAC checks
     */
    public function deleteUser(int $userId, int $currentUserId): array
    {
        if ($currentUserId === $userId) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'নিজের অ্যাকাউন্ট মুছে ফেলা যাবে না', 'type' => 'error'],
            ];
        }

        $targetUser = $this->userModel->getById($userId);
        if (!$targetUser) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'ব্যবহারকারী পাওয়া যায়নি বা ইতিমধ্যে মুছে ফেলা হয়েছে', 'type' => 'error'],
            ];
        }

        // Check role level — system admins or above cannot be deleted by non-superadmins
        require_once __DIR__ . '/../../config/roles.php';
        $targetLevel = getRoleLevelFromId((int)$targetUser['role_id']);
        if ($targetLevel <= ROLE_LEVEL_SYSTEM_ADMIN) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'উচ্চ সুবিধা সম্পন্ন ব্যবহারকারী মুছা যায় না।', 'type' => 'error'],
            ];
        }

        if (!$this->canManageUserByLevel($currentUserId, $userId)) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'আপনার এই ব্যবহারকারী মুছার অনুমতি নেই।', 'type' => 'error'],
            ];
        }

        if (!$this->userModel->exists($userId)) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'ব্যবহারকারী পাওয়া যায়নি বা ইতিমধ্যে মুছে ফেলা হয়েছে', 'type' => 'error'],
            ];
        }

        $res = $this->userModel->softDelete($userId);
        if (!$res['success']) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => $res['error'] ?? 'মুছে ফেলা যায়নি', 'type' => 'error'],
            ];
        }

        return [
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => $res['message'], 'type' => 'success'],
            'redirect' => '/users',
        ];
    }

    /**
     * Bulk soft-delete users
     */
    public function bulkDeleteUsers(array $userIds, int $currentUserId): array
    {
        if (!is_array($userIds) || empty($userIds)) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'কোনো ব্যবহারকারী নির্বাচন করা হয়নি', 'type' => 'error'],
            ];
        }

        if (in_array($currentUserId, $userIds)) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'নিজের অ্যাকাউন্ট মুছে ফেলা যাবে না', 'type' => 'error'],
            ];
        }

        $affected = 0;
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($this->userModel->exists($uid)) {
                $r = $this->userModel->softDelete($uid);
                if ($r['success']) $affected++;
            }
        }

        return [
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => "$affected জন ব্যবহারকারী মুছে ফেলা হয়েছে", 'type' => 'success'],
            'redirect' => '/users',
        ];
    }

    // =====================================================================
    // STATUS OPERATIONS
    // =====================================================================

    /**
     * Toggle a single user's active/inactive status
     */
    public function toggleUserStatus(int $userId, int $currentUserId): array
    {
        if ($currentUserId === $userId) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'নিজের স্ট্যাটাস পরিবর্তন করা যাবে না', 'type' => 'error'],
            ];
        }

        $user = $this->userModel->getById($userId);
        if (!$user) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'ব্যবহারকারী পাওয়া যায়নি', 'type' => 'error'],
            ];
        }

        if ($user['status'] === 'active') {
            $this->userModel->deactivate($userId);
        } else {
            $this->userModel->activate($userId);
        }

        return [
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => 'স্ট্যাটাস পরিবর্তিত হয়েছে', 'type' => 'success'],
        ];
    }

    /**
     * Bulk toggle user statuses
     */
    public function bulkToggleStatus(array $userIds, string $newStatus): array
    {
        if (!is_array($userIds) || empty($userIds)) {
            return [
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'কোনো ব্যবহারকারী নির্বাচন করা হয়নি', 'type' => 'error'],
            ];
        }

        $affected = 0;
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if (!$this->userModel->exists($uid)) continue;

            if ($newStatus === 'active') {
                $r = $this->userModel->activate($uid);
            } else {
                $r = $this->userModel->deactivate($uid);
            }
            if ($r['success']) $affected++;
        }

        return [
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => "$affected জন ব্যবহারকারীর স্ট্যাটাস পরিবর্তিত হয়েছে", 'type' => 'success'],
        ];
    }

    // =====================================================================
    // CSV EXPORT
    // =====================================================================

    /**
     * Generate a CSV export of all users and send it as a download response.
     * This writes directly to php://output and exits.
     */
    public function exportUsersCsv(): void
    {
        $users = $this->userModel->getAll([], 'created_at', 'DESC');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="users_export.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

        fputcsv($output, ['ID', 'Username', 'Email', 'Name (BN)', 'Name (EN)', 'Phone', 'Role', 'Status', 'Created']);

        foreach ($users as $u) {
            fputcsv($output, [
                $u['user_id'], $u['username'], $u['email'],
                $u['name_bn'], $u['name_en'], $u['phone_number'],
                $u['role_name'] ?? '', $u['status'], $u['created_at'],
            ]);
        }

        fclose($output);
        exit;
    }

    // =====================================================================
    // FILE UPLOAD
    // =====================================================================

    /**
     * Upload a user profile file
     */
    public function uploadFile(array $file, string $targetDir): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'ফাইল আপলোডে ত্রুটি'];
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array(strtolower($ext), $allowed)) {
            return ['success' => false, 'error' => 'ফাইল টাইপ অনুমোদিত নয়'];
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'ফাইল আকার সীমার বাইরে'];
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = uniqid() . '.' . $ext;
        $path = rtrim($targetDir, '/') . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $path)) {
            return ['success' => true, 'path' => '/' . $path];
        }

        return ['success' => false, 'error' => 'ফাইল সংরক্ষণ ব্যর্থ হয়েছে'];
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function getUserRoleId(int $userId): ?int
    {
        $stmt = $this->mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? (int)$result['role_id'] : null;
    }

    private function getUserRoleLevel(int $userId): ?int
    {
        $stmt = $this->mysqli->prepare(
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
