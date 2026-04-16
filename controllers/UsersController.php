<?php
// controllers/UsersController.php


require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../classes/RolesManager.php';
require_once __DIR__ . '/../helpers/email_helper.php';


$auth = new AuthManager($mysqli);
$userModel = new UserModel($mysqli);
$unionModel = new UnionModel($mysqli);  
$rolesManager = new RolesManager($mysqli);
/* =========================================================
   PROFILE
========================================================= */

$router->get('/profile', function () use ($twig, $mysqli, $userModel, $auth) {
    $auth->requireLogin();

    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        renderError(401, 'ব্যবহারকারীর আইডি পাওয়া যাচ্ছে না');
        return;
    }

    $profileData = $userModel->getUserDetails($userId);
    if (!$profileData) {
        renderError(404, 'প্রোফাইল পাওয়া যাচ্ছে না');
        return;
    }

    $unionInfo = null;
    if (!empty($profileData['union_name_bn']) || !empty($profileData['union_name_en'])) {
        $unionInfo = [
            'union_name_bn' => $profileData['union_name_bn'] ?? null,
            'union_name_en' => $profileData['union_name_en'] ?? null
        ];
    }

    echo $twig->render('users/profile.twig', [
        'profileData'  => $profileData,
        'unionInfo'    => $unionInfo,
        'title'        => 'User Profile',
        'header_title' => 'User Profile',
        'csrf_token'   => generateCsrfToken()
    ]);
});

/* =========================================================
   PROFILE UPDATE
========================================================= */
$router->get('/profile/update', function() use ($twig, $mysqli, $userModel, $auth) {

    $auth->requireLogin();

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;



    $profileData = $userModel->getById($user['user_id']);
    if (!$profileData) {
        echo "Profile not found.";
        return;
    }

    echo $twig->render('users/edit_profile.twig', [
        'profileData'  => $profileData,
        'title'        => 'Edit Profile',
        'header_title' => 'Edit Profile',
        'csrf_token'   => generateCsrfToken()
    ]);
    

});

$router->post('/profile/update', function () use ($mysqli, $userModel, $auth) {
    $auth->requireLogin();
    header('Content-Type: application/json');

    try {

        $user = $auth->getUserData(false);
        $userId = $user['user_id'];

        $data = [];
        $data['name_bn'] = sanitize_input($_POST['name_bn'] ?? '');
        $data['name_en'] = sanitize_input($_POST['name_en'] ?? '');
        $data['phone_number'] = sanitize_input($_POST['phone_number'] ?? '');
        $data['address'] = sanitize_input($_POST['address'] ?? '');
        $data['bio'] = sanitize_input($_POST['bio'] ?? '');

        $result = $userModel->update($userId, $data);

        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'alert' => [ 'title' => 'সফল', 'message' => $result['message'], 'type' => 'success' ],
                'redirect' => '/profile'
            ]);
        } else {
            throw new Exception($result['error'] ?? 'আপডেট করা যায়নি');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [ 'title' => 'ত্রুটি', 'message' => $e->getMessage(), 'type' => 'error' ]
        ]);
    }
    exit;
});

/* =========================================================
   PASSWORD CHANGE (AJAX)
========================================================= */
$router->post('/profile/change-password', function () use ($userModel, $auth) {
    $auth->requireLogin();
    header('Content-Type: application/json');

    try {

        $user = $auth->getUserData(false);
        $userId = $user['user_id'];

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            throw new Exception('নতুন পাসওয়ার্ড এবং নিশ্চিতকরণ পাসওয়ার্ড মেলে না');
        }

        // Verify current password
        $userData = $userModel->getById($userId);
        if (!password_verify($currentPassword, $userData['password'])) {
            throw new Exception('বর্তমান পাসওয়ার্ড সঠিক নয়');
        }

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateResult = $userModel->update($userId, [
            'password' => $hashedPassword,
            'last_password_change' => date('Y-m-d H:i:s')
        ]);

        if ($updateResult['success']) {
            if ($userData && function_exists('sendPasswordChangedEmail')) {
                    sendPasswordChangedEmail($userData['email'], false);
                }
            echo json_encode([
                'success' => true,
                'alert' => [
                    'title' => 'সফল',
                    'message' => 'পাসওয়ার্ড সফলভাবে পরিবর্তিত হয়েছে',
                    'type' => 'success'
                ],
                'redirect' => '/profile'
            ]);

        } else {
            throw new Exception($updateResult['error'] ?? 'পাসওয়ার্ড পরিবর্তন ব্যর্থ হয়েছে');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title' => 'ত্রুটি',
                'message' => $e->getMessage(),
                'type' => 'error'
            ]
        ]);
    }
    exit;
});

/* =========================================================
   HELPER FUNCTION: FILE UPLOAD
========================================================= */
function uploadFile(array $file, string $targetDir): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'ফাইল আপলোডে ত্রুটি'];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $allowed = ['jpg','jpeg','png','gif'];
    if (!in_array(strtolower($ext), $allowed)) {
        return ['success' => false, 'error' => 'ফাইল টাইপ অনুমোদিত নয়'];
    }

    if ($file['size'] > 5*1024*1024) { // 5MB limit
        return ['success' => false, 'error' => 'ফাইল আকার সীমার বাইরে'];
    }

    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

    $filename = uniqid() . '.' . $ext;
    $path = rtrim($targetDir, '/') . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $path)) {
        return ['success' => true, 'path' => '/' . $path];
    } else {
        return ['success' => false, 'error' => 'ফাইল সংরক্ষণ ব্যর্থ হয়েছে'];
    }
}

/* =========================================================
   ADD USER (ADMIN) - GET & POST
========================================================= */

$router->get('/users/add', function () use ($twig, $mysqli, $userModel, $auth, $unionModel, $rolesManager) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    ensure_role_level(ROLE_LEVEL_SECRETARY);  // Secretary+ can add users

    $roles = $rolesManager->getAllRoles();
    $unions = $unionModel->getAllUnions();

    // Bangla role name mapping
    $roleBangla = [
        1 => 'অ্যাডমিনিস্ট্রেটর',
        2 => 'সচিব',
        3 => 'চেয়ারম্যান',
        4 => 'মেম্বার',
        5 => 'কম্পিউটার অপারেটর',
        6 => 'গ্রাম পুলিশ',
        7 => 'অফিস সহকারী'
    ];

    // Attach bangla name to roles array
    foreach ($roles as &$r) {
        // normalize older 'id' key to 'role_id' if present
        $rid = isset($r['role_id']) ? (int)$r['role_id'] : (isset($r['id']) ? (int)$r['id'] : null);
        $r['id'] = $rid;
        $r['name_bn'] = $roleBangla[$rid] ?? $r['role_name'];
    }

    echo $twig->render('users/add_user.twig', [
        'title' => 'নতুন ব্যবহারকারী যোগ করুন',
        'roles' => $roles,
        'unions' => $unions,
        'csrf_token' => generateCsrfToken(),
        'header_title' => 'নতুন ব্যবহারকারী'
    ]);
});

$router->post('/users/add', function () use ($mysqli, $userModel, $auth) {
    // ✅ Load email helper
    require_once __DIR__ . '/../helpers/email_helper.php';
    require_once __DIR__ . '/../helpers/rbac_helpers.php';
    
    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    ensure_role_level(ROLE_LEVEL_SECRETARY);  // Secretary+ can add users
    header('Content-Type: application/json');

    try {
        $currentUser = $auth->getUserData(false);
        $currentUserId = $currentUser['user_id'] ?? null;
        
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $name_bn = sanitize_input($_POST['name_bn'] ?? '');
        $name_en = sanitize_input($_POST['name_en'] ?? '');
        $phone = sanitize_input($_POST['phone_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $role_id = (int)($_POST['role_id'] ?? 0);
        $union_id = !empty($_POST['union_id']) ? (int)$_POST['union_id'] : null;
        $ward_no = sanitize_input($_POST['ward_no'] ?? '');

        // 🔒 RBAC: Validate new user's role level doesn't exceed manager's privilege
        $newUserLevel = getRoleLevelFromId($role_id);
        $currentLevel = getUserRoleLevel($currentUserId, $mysqli);
        
        // Cannot create user with same or higher privilege
        if ($newUserLevel <= $currentLevel) {
            throw new Exception('আপনি নিজের সমান বা উচ্চতর সুবিধা সম্পন্ন ব্যবহারকারী তৈরি করতে পারেন না');
        }

        // Basic validation
        $errors = [];
        if (!$username) $errors[] = 'ব্যবহারকারীনাম প্রয়োজন';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'সঠিক ইমেইল প্রয়োজন';
        if (!$name_bn) $errors[] = 'বাংলা নাম প্রয়োজন';
        if (!$password) $errors[] = 'পাসওয়ার্ড প্রয়োজন';
        if ($password !== $confirm) $errors[] = 'পাসওয়ার্ড মিলছে না';
        if ($role_id <= 0) $errors[] = 'সঠিক রোল নির্বাচন করুন';

        // 🔒 RBAC: Prevent privilege escalation
        $userData = ['role_id' => $role_id, 'union_id' => $union_id];
        $hierarchyErrors = [];
        if (!validateUserDataForPrivilegeEscalation($currentUserId, $userData, $mysqli, $hierarchyErrors)) {
            $errors = array_merge($errors, $hierarchyErrors);
        }

        // If role is 'মেম্বার' (id 4) require ward_no
        if ($role_id === 4 && empty($ward_no)) {
            $errors[] = 'রোল মেম্বার হলে ওয়ার্ড নং প্রদান করতে হবে';
        }

        if ($userModel->usernameExists($username)) $errors[] = 'এই ব্যবহারকারীর নাম ইতিমধ্যে ব্যবহৃত হয়েছে';
        if ($userModel->emailExists($email)) $errors[] = 'এই ইমেইল ইতিমধ্যে ব্যবহৃত হয়েছে';

        if (!empty($errors)) throw new Exception(implode(', ', $errors));

        $data = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'name_bn' => $name_bn,
            'name_en' => $name_en,
            'phone_number' => $phone,
            'role_id' => $role_id,
            'union_id' => $union_id,
            'ward_no' => $ward_no,
            'status' => 'active'
        ];

        $res = $userModel->create($data);
        if (!$res['success']) throw new Exception($res['error'] ?? 'ব্যবহারকারী তৈরি করা যায়নি');

        // 📧 Send welcome email to newly created user
        if (function_exists('sendWelcomeEmail')) {
            sendWelcomeEmail($email, $username);
        }

        echo json_encode([
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => 'নতুন ব্যবহারকারী যোগ করা হয়েছে', 'type' => 'success'],
            'redirect' => '/users'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => $e->getMessage(), 'type' => 'error']
        ]);
    }
    exit;
});

/* =========================================================
   USERS LIST
========================================================= */

$router->get('/users', function () use ($twig, $userModel, $auth, $rolesManager) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');

    $currentUser = $auth->getUserData(false);
    $isSuperAdmin = (int)$currentUser['role_id'] <= 1;

    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $role   = $_GET['role'] ?? '';

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    // Use UserModel to fetch list and count
    $filters = [];
    if ($search) $filters['search'] = $search;
    if ($status) $filters['status'] = $status;
    if ($role) $filters['role_id'] = (int)$role;
    
    // Filter by union if not superadmin
    if (!$isSuperAdmin && !empty($currentUser['union_id'])) {
        $filters['union_id'] = $currentUser['union_id'];
    }
    
    // Prevent non-superadmin from seeing superadmin users
    if (!$isSuperAdmin) {
        $filters['exclude_superadmin'] = true;
    }

    $total = $userModel->countUsers($filters);
    $pages = max(1, ceil($total / $perPage));

    // add pagination to filters for model if supported
    $filters['limit'] = $perPage;
    $filters['offset'] = $offset;
    $users = $userModel->getAll($filters, 'created_at', 'DESC');


    $roles = $rolesManager->getAllRoles();
    // normalize role ids
    foreach ($roles as &$r) {
        $r['id'] = $r['role_id'] ?? ($r['id'] ?? null);
    }

    echo $twig->render('users/admin_users_list.twig', [
        'users'       => $users,
        'pagination'  => [
            'current'      => $page,
            'total'        => $pages,
            'per_page'     => $perPage,
            'showing_from' => $total > 0 ? $offset + 1 : 0,
            'showing_to'   => min($offset + $perPage, $total),
            'total_records' => $total
        ],
        'filters' => [
            'search' => $search,
            'status' => $status,
            'role'   => $role
        ],
        'roles'       => $roles,
        'total_users' => $total,
        'title'       => 'Users Management',
        'header_title' => 'Manage All Users',
        'csrf_token'  => generateCsrfToken()
    ]);

});
/* =========================================================
   SINGLE DELETE (AJAX)
========================================================= */

$router->post('/users/{id}/delete', function ($id) use ($mysqli, $userModel, $auth) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    ensure_role_level(ROLE_LEVEL_CHAIRMAN);  // CHAIRMAN+ can delete users
    header('Content-Type: application/json');

    try {
        // 🔒 RBAC: Check role hierarchy
        $currentUser = $auth->getUserData(false);
        $currentUserId = $currentUser['user_id'] ?? null;
        $currentLevel = getUserRoleLevel($currentUserId, $mysqli);
        
        // Prevent self-deletion
        if ($currentUserId == $id) {
            throw new Exception('নিজের অ্যাকাউন্ট মুছে ফেলা যাবে না');
        }
        
        // Get target user data
        $targetUser = $userModel->getById($id);
        if (!$targetUser) {
            throw new Exception('ব্যবহারকারী পাওয়া যায়নি বা ইতিমধ্যে মুছে ফেলা হয়েছে');
        }
        
        $targetLevel = getRoleLevelFromId((int)$targetUser['role_id']);
        
        // 🔒 RBAC: Cannot delete superadmin/system admin users (level <= 2)
        if ($targetLevel <= ROLE_LEVEL_SYSTEM_ADMIN) {
            throw new Exception('উচ্চ সুবিধা সম্পন্ন ব্যবহারকারী মুছা যায় না।');
        }
        
        // 🔒 RBAC: Cannot delete users with equal or higher privilege
        if ($targetLevel <= $currentLevel) {
            throw new Exception('আপনি নিজের সমান বা উচ্চতর সুবিধা সম্পন্ন ব্যবহারকারী মুছতে পারেন না।');
        }
        
        // 🔒 RBAC: Non-superadmins cannot manage users higher than their role
        if (!canManageUserByLevel($currentUserId, $id, $mysqli)) {
            throw new Exception('আপনার এই ব্যবহারকারী মুছার অনুমতি নেই।');
        }

        // Check existence via model
        if (!$userModel->exists($id)) {
            throw new Exception('ব্যবহারকারী পাওয়া যায়নি বা ইতিমধ্যে মুছে ফেলা হয়েছে');
        }

        $res = $userModel->softDelete($id);
        if (!$res['success']) throw new Exception($res['error'] ?? 'মুছে ফেলা যায়নি');

        echo json_encode([
            'success' => true,
            'alert' => [ 'title' => 'সফল', 'message' => $res['message'], 'type' => 'success' ],
            'redirect' => '/users'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title' => 'ত্রুটি',
                'message' => $e->getMessage(),
                'type' => 'error'
            ]
        ]);
    }
    exit;
});

/* =========================================================
   BULK DELETE (AJAX)
========================================================= */

$router->post('/users/bulk-delete', function () use ($mysqli, $userModel, $auth) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    header('Content-Type: application/json');

    try {

        $ids = $_POST['user_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            throw new Exception('কোনো ব্যবহারকারী নির্বাচন করা হয়নি');
        }

        // Prevent self-deletion in bulk
        $currentUser = $auth->getUserData(false);
        $currentUserId = $currentUser['user_id'];
        if (in_array($currentUserId, $ids)) {
            throw new Exception('নিজের অ্যাকাউন্ট মুছে ফেলা যাবে না');
        }

        $affected = 0;
        foreach ($ids as $uid) {
            $uid = (int)$uid;
            if ($userModel->exists($uid)) {
                $r = $userModel->softDelete($uid);
                if ($r['success']) $affected++;
            }
        }

        echo json_encode([
            'success' => true,
            'alert' => [ 'title' => 'সফল', 'message' => "$affected জন ব্যবহারকারী মুছে ফেলা হয়েছে", 'type' => 'success' ],
            'redirect' => '/users'
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title' => 'ত্রুটি',
                'message' => $e->getMessage(),
                'type' => 'error'
            ]
        ]);
    }
    exit;
});

/* =========================================================
   TOGGLE USER STATUS (AJAX)
========================================================= */

$router->post('/users/{id}/toggle-status', function ($id) use ($mysqli, $userModel, $auth) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    header('Content-Type: application/json');

    try {

        // Prevent changing own status
        $currentUser = $auth->getUserData(false);
        if ($currentUser['user_id'] == $id) {
            throw new Exception('নিজের স্ট্যাটাস পরিবর্তন করা যাবে না');
        }

        // Toggle via model
        $user = $userModel->getById($id);
        if (!$user) throw new Exception('ব্যবহারকারী পাওয়া যায়নি');

        if ($user['status'] === 'active') {
            $res = $userModel->deactivate($id);
            $newStatus = 'inactive';
        } else {
            $res = $userModel->activate($id);
            $newStatus = 'active';
        }

        if (!$res['success']) throw new Exception($res['message'] ?? 'স্ট্যাটাস আপডেট করা যায়নি');

        $statusText = $newStatus === 'active' ? 'সক্রিয়' : 'নিষ্ক্রিয়';

        echo json_encode([
            'success' => true,
            'alert' => [ 'title' => 'সফল', 'message' => "ব্যবহারকারী এখন $statusText", 'type' => 'success' ],
            'data' => [ 'status' => $newStatus, 'user_id' => $id ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title' => 'ত্রুটি',
                'message' => $e->getMessage(),
                'type' => 'error'
            ]
        ]);
    }
    exit;
});

/* =========================================================
   VIEW SINGLE USER (ADMIN)
========================================================= */

$router->get('/users/{id}', function ($id) use ($twig, $mysqli, $userModel, $auth) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');

    $currentUser = $auth->getUserData(false);
    $isSuperAdmin = (int)$currentUser['role_id'] <= 1;

    $userData = $userModel->getUserDetails($id);
    if (!$userData) {
        errorAlert('ত্রুটি', 'ব্যবহারকারী পাওয়া যাচ্ছে না');
        header('Location: /users');
        exit;
    }
    
    // Prevent non-superadmin from viewing superadmin profile
    $targetUserIsSuperAdmin = (int)$userData['role_id'] <= 1;
    if ($targetUserIsSuperAdmin && !$isSuperAdmin) {
        errorAlert('ত্রুটি', 'আপনি এই ব্যবহারকারীর প্রোফাইল দেখতে পারেন না');
        header('Location: /users');
        exit;
    }
    
    // Prevent non-superadmin from viewing users from other unions
    if (!$isSuperAdmin && $userData['union_id'] != $currentUser['union_id']) {
        errorAlert('ত্রুটি', 'আপনি অন্য ইউনিয়নের ব্যবহারকারী দেখতে পারেন না');
        header('Location: /users');
        exit;
    }

    echo $twig->render('users/admin_user_view.twig', [
        'userData'     => $userData,
        'title'        => 'User Details',
        'header_title' => 'User Details',
        'csrf_token'   => generateCsrfToken()
    ]);
});



/* =========================================================
   EDIT USER (ADMIN) - GET
========================================================= */
$router->get('/users/{id}/edit', function ($id) use ($twig, $mysqli, $userModel, $auth) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');

    $userData = $userModel->getUserDetails($id);
    if (!$userData) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => 'ব্যবহারকারী পাওয়া যায়নি', 'type' => 'error']
        ]);
        exit;
    }

    $roles = $mysqli->query("SELECT role_id AS id, role_name FROM roles ORDER BY role_name")->fetch_all(MYSQLI_ASSOC);
    $unions = $mysqli->query("SELECT union_id, union_name_bn FROM unions ORDER BY union_name_bn")->fetch_all(MYSQLI_ASSOC);

    echo $twig->render('users/admin_user_edit.twig', [
        'title'        => 'Edit User',
        'userData'     => $userData,
        'roles'        => $roles,
        'unions'       => $unions,
        'header_title' => 'Edit User',
        'csrf_token'   => generateCsrfToken()
    ]);
});

/* =========================================================
   EDIT USER (ADMIN) - POST (AJAX)
========================================================= */
$router->post('/users/{id}/edit', function ($id) use ($userModel, $auth, $mysqli) {
    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    // Role level check is done below for specific operations
    header('Content-Type: application/json');

    try {
        $currentUser = $auth->getUserData(false);
        $currentLevel = getUserRoleLevel($currentUser['user_id'], $mysqli);
        
        // Prevent non-superadmin from editing superadmin profile
        $userData = $userModel->getUserDetails($id);
        $targetLevel = getUserRoleLevel($id, $mysqli);
        
        // Check if can manage this user by level
        if (!canManageUserByLevel($currentUser['user_id'], $id, $mysqli)) {
            throw new Exception('আপনার এই ব্যবহারকারী সম্পাদনা করার অনুমতি নেই');
        }
        
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $name_bn = sanitize_input($_POST['name_bn'] ?? '');
        $name_en = sanitize_input($_POST['name_en'] ?? '');
        $phone = sanitize_input($_POST['phone_number'] ?? '');
        $role_id = (int)($_POST['role_id'] ?? 0);
        $union_id = !empty($_POST['union_id']) ? (int)$_POST['union_id'] : null;
        $ward_no = sanitize_input($_POST['ward_no'] ?? '');
        $status = $_POST['status'] ?? 'active';

        // 🔒 RBAC: If changing role, validate new role level
        if ($role_id > 0 && $userData && (int)$userData['role_id'] !== $role_id) {
            $newLevel = getRoleLevelFromId($role_id);
            // Cannot assign role with higher or equal privilege
            if ($newLevel < $currentLevel) {
                throw new Exception('আপনি নিজের সমান বা উচ্চতর সুবিধা প্রদান করতে পারেন না');
            }
        }

        // Validation
        $errors = [];
        if (empty($username)) $errors[] = 'ব্যবহারকারীর নাম প্রয়োজন';
        if (empty($email)) $errors[] = 'ইমেইল প্রয়োজন';
        if (empty($name_bn)) $errors[] = 'বাংলা নাম প্রয়োজন';
        if ($role_id <= 0) $errors[] = 'সঠিক রোল নির্বাচন করুন';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'সঠিক ইমেইল প্রদান করুন';
        if (!empty($phone) && !preg_match('/^01[3-9]\d{8}$/', $phone)) $errors[] = 'সঠিক ফোন নম্বর দিন';
        
        // Role 4 requires ward_no
        if ($role_id === 4 && empty($ward_no)) {
            $errors[] = 'মেম্বার রোলের জন্য ওয়ার্ড নং প্রয়োজন';
        }

        if ($userModel->usernameExists($username, $id)) $errors[] = 'এই ব্যবহারকারীর নাম ইতিমধ্যে ব্যবহৃত হয়েছে';
        if ($userModel->emailExists($email, $id)) $errors[] = 'এই ইমেইল ইতিমধ্যে ব্যবহৃত হয়েছে';

        if (!empty($errors)) throw new Exception(implode(', ', $errors));

        // Handle profile picture upload
        $profile_picture_url = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('শুধুমাত্র JPG, PNG বা GIF ছবি আপলোড করুন');
            }

            if ($file['size'] > $maxSize) {
                throw new Exception('ছবি সর্বোচ্চ 5MB হতে পারে');
            }

            // Create upload directory
            $uploadDir = __DIR__ . '/../public/uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $id . '_' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $profile_picture_url = '/uploads/profiles/' . $filename;

                // Delete old profile picture
                $oldUser = $userModel->findById($id);
                if ($oldUser && !empty($oldUser['profile_picture_url'])) {
                    $oldPath = __DIR__ . '/../public' . $oldUser['profile_picture_url'];
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            } else {
                throw new Exception('ছবি আপলোড করতে ব্যর্থ');
            }
        }

        $data = [
            'username' => $username,
            'email' => $email,
            'name_bn' => $name_bn,
            'name_en' => $name_en,
            'phone_number' => $phone,
            'role_id' => $role_id,
            'union_id' => $union_id,
            'ward_no' => $ward_no,
            'status' => $status,
            'language_preference' => sanitize_input($_POST['language_preference'] ?? 'bn'),
            'timezone' => sanitize_input($_POST['timezone'] ?? 'Asia/Dhaka'),
            'is_email_notifications_enabled' => isset($_POST['is_email_notifications_enabled']) ? 1 : 0,
            'is_sms_notifications_enabled' => isset($_POST['is_sms_notifications_enabled']) ? 1 : 0,
        ];

        if ($profile_picture_url !== null) {
            $data['profile_picture_url'] = $profile_picture_url;
        }

        $res = $userModel->update($id, $data);
        if (!$res['success']) throw new Exception($res['error'] ?? 'আপডেট করা যায়নি');

        echo json_encode([
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => 'ব্যবহারকারীর তথ্য আপডেট হয়েছে', 'type' => 'success'],
            'redirect' => "/users/$id/edit"
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => $e->getMessage(), 'type' => 'error']
        ]);
    }
    exit;
});
 
/* =========================================================
   RESET USER PASSWORD (ADMIN) - POST (AJAX)
========================================================= */
$router->post('/users/{id}/reset-password', function ($id) use ($userModel, $auth, $mysqli) {
    // 📧 Email helper
    require_once __DIR__ . '/../helpers/email_helper.php';
    require_once __DIR__ . '/../helpers/rbac_helpers.php';

    $auth->requireLogin();
    ensure_can('manage_users', 'users');
    header('Content-Type: application/json');

    try {
        $currentUser = $auth->getUserData(false);
        $isSuperAdmin = (int)$currentUser['role_id'] <= 1;

        // Fetch target user
        $user = $userModel->getById($id);
        if (!$user) {
            throw new Exception('ব্যবহারকারী পাওয়া যায়নি');
        }

        // 🔒 RBAC: Non-superadmin cannot reset superadmin password
        if ((int)$user['role_id'] <= 1 && !$isSuperAdmin) {
            throw new Exception('সুপারঅ্যাডমিনের পাসওয়ার্ড পরিবর্তনের অনুমতি নেই');
        }

        // 🔒 RBAC: Role hierarchy check
        if (!canManageUser($currentUser['user_id'], $id, $mysqli)) {
            throw new Exception('আপনার এই ব্যবহারকারীর পাসওয়ার্ড পরিবর্তনের অনুমতি নেই');
        }

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!$password) {
            throw new Exception('পাসওয়ার্ড প্রদান করা হয়নি');
        }

        if ($password !== $confirm) {
            throw new Exception('পাসওয়ার্ড এবং নিশ্চিতকরণ পাসওয়ার্ড মেলে না');
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $res = $userModel->update($id, [
            'password' => $hashed,
            'last_password_change' => date('Y-m-d H:i:s')
        ]);

        if (!$res['success']) {
            throw new Exception($res['error'] ?? 'পাসওয়ার্ড রিসেট ব্যর্থ হয়েছে');
        }

        // 📧 Security notification email
        if (!empty($user['email']) && function_exists('sendPasswordChangedEmail')) {
            sendPasswordChangedEmail($user['email'], $user['username'] ?? '');
        }

        echo json_encode([
            'success' => true,
            'alert' => [
                'title' => 'সফল',
                'message' => 'পাসওয়ার্ড সফলভাবে পরিবর্তিত হয়েছে',
                'type' => 'success'
            ],
            'redirect' => "/users/$id/edit"
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title' => 'ত্রুটি',
                'message' => $e->getMessage(),
                'type' => 'error'
            ]
        ]);
    }
    exit;
});





