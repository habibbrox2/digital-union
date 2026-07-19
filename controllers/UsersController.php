<?php
/**
 * controllers/UsersController.php
 * 
 * User management routes - thin closures using UserService.
 * All business logic (profile update, password change, CRUD, bulk ops, export)
 * delegated to modules/Services/UserService.php.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);
$userService = new UserService($mysqli);
$auth = new AuthManager($mysqli);
require_once __DIR__ . '/../helpers/email_helper.php';

// ================================================================
// PROFILE VIEW
// ================================================================
$router->get('/profile', function () use ($twig, $auth, $mysqli) {
    $auth->requireLogin();

    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        renderError(401, 'ব্যবহারকারীর আইডি পাওয়া যাচ্ছে না');
        return;
    }

    $userModel = new UserModel($mysqli);
    $profileData = $userModel->getUserDetails($userId);

    if (!$profileData) {
        renderError(404, 'প্রোফাইল পাওয়া যাচ্ছে না');
        return;
    }

    $unionInfo = null;
    if (!empty($profileData['union_name_bn']) || !empty($profileData['union_name_en'])) {
        $unionInfo = [
            'union_name_bn' => $profileData['union_name_bn'] ?? null,
            'union_name_en' => $profileData['union_name_en'] ?? null,
        ];
    }

    echo $twig->render('users/profile.twig', [
        'profileData'  => $profileData,
        'unionInfo'    => $unionInfo,
        'title'        => 'User Profile',
        'header_title' => 'User Profile',
        'csrf_token'   => generateCsrfToken(),
    ]);
});

// ================================================================
// PROFILE EDIT (FORM)
// ================================================================
$router->get('/profile/update', function () use ($twig, $auth, $mysqli) {
    $auth->requireLogin();

    $user = $auth->getUserData(false);
    $userModel = new UserModel($mysqli);

    $profileData = $userModel->getById($user['user_id']);
    if (!$profileData) {
        echo "Profile not found.";
        return;
    }

    echo $twig->render('users/edit_profile.twig', [
        'profileData'  => $profileData,
        'title'        => 'Edit Profile',
        'header_title' => 'Edit Profile',
        'csrf_token'   => generateCsrfToken(),
    ]);
});

// ================================================================
// PROFILE UPDATE (POST)
// ================================================================
$router->post('/profile/update', function () use ($auth, $userService, $mysqli) {
    $auth->requireLogin();
    header('Content-Type: application/json');

    $user = $auth->getUserData(false);
    $userId = $user['user_id'];

    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $userModel = new UserModel($mysqli);
        $existingUser = $userModel->getById($userId);
        $uploadUrl = $userModel->uploadProfilePicture($_FILES['profile_picture']);

        if ($uploadUrl === null && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            echo json_encode([
                'success' => false,
                'alert' => ['title' => 'ত্রুটি', 'message' => 'প্রোফাইল ছবি আপলোড ব্যর্থ হয়েছে। শুধুমাত্র JPG, PNG, GIF ফাইল অনুমোদিত (সর্বোচ্চ 5MB)।', 'type' => 'error'],
            ]);
            exit;
        }

        if ($uploadUrl !== null) {
            // Delete old profile picture from disk
            if (!empty($existingUser['profile_picture_url'])) {
                $oldPath = __DIR__ . '/../../public' . $existingUser['profile_picture_url'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $_POST['profile_picture_url'] = $uploadUrl;
        }
    }

    $result = $userService->updateProfile($userId, $_POST);
    echo json_encode($result);
    exit;
});

// ================================================================
// PASSWORD CHANGE
// ================================================================
$router->post('/profile/change-password', function () use ($auth, $userService) {
    $auth->requireLogin();
    header('Content-Type: application/json');

    $user = $auth->getUserData(false);
    $result = $userService->changePassword($user['user_id'], $_POST);
    echo json_encode($result);
    exit;
});

// ================================================================
// ADD USER (FORM)
// ================================================================
$router->get('/users/add', function () use ($twig, $auth, $authService, $mysqli) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    $authService->ensureRoleLevel(ROLE_LEVEL_SECRETARY);

    $unionModel = new UnionModel($mysqli);
    $rolesManager = new RolesManager($mysqli);

    $roles = $rolesManager->getAllRoles();
    $unions = $unionModel->getAllUnions();

    $roleBangla = [
        1 => 'অ্যাডমিনিস্ট্রেটর',
        2 => 'সচিব',
        3 => 'চেয়ারম্যান',
        4 => 'মেম্বার',
        5 => 'কম্পিউটার অপারেটর',
        6 => 'গ্রাম পুলিশ',
        7 => 'অফিস সহকারী',
    ];

    foreach ($roles as &$r) {
        $rid = isset($r['role_id']) ? (int)$r['role_id'] : (isset($r['id']) ? (int)$r['id'] : null);
        $r['id'] = $rid;
        $r['name_bn'] = $roleBangla[$rid] ?? $r['role_name'];
    }

    echo $twig->render('users/add_user.twig', [
        'title'        => 'নতুন ব্যবহারকারী যোগ করুন',
        'roles'        => $roles,
        'unions'       => $unions,
        'csrf_token'   => generateCsrfToken(),
        'header_title' => 'নতুন ব্যবহারকারী',
    ]);
});

// ================================================================
// ADD USER (POST)
// ================================================================
$router->post('/users/add', function () use ($auth, $authService, $userService, $mysqli) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    $authService->ensureRoleLevel(ROLE_LEVEL_SECRETARY);
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
        $address = sanitize_input($_POST['address'] ?? '');

        // Extra fields
        $status = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 'active' : 'inactive';
        $language = $_POST['language'] ?? 'bn';
        $timezone = $_POST['timezone'] ?? 'Asia/Dhaka';
        $emailNotif = isset($_POST['email_notifications']) ? 1 : 0;
        $smsNotif = isset($_POST['sms_notifications']) ? 1 : 0;

        // RBAC validation
        $errors = [];
        $hierarchyErrors = [];
        if (!$userService->validateUserDataForPrivilegeEscalation($currentUserId, ['role_id' => $role_id, 'union_id' => $union_id], $hierarchyErrors)) {
            $errors = array_merge($errors, $hierarchyErrors);
        }

        if ($role_id === 4 && empty($ward_no)) {
            $errors[] = 'রোল মেম্বার হলে ওয়ার্ড নং প্রদান করতে হবে';
        }

        if (!empty($errors)) {
            throw new Exception(implode(', ', $errors));
        }

        $userModel = new UserModel($mysqli);

        // Handle profile picture upload
        $profilePictureUrl = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadUrl = $userModel->uploadProfilePicture($_FILES['profile_picture']);
            if ($uploadUrl === null && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                throw new Exception('প্রোফাইল ছবি আপলোড ব্যর্থ হয়েছে। শুধুমাত্র JPG, PNG, GIF ফাইল অনুমোদিত (সর্বোচ্চ 5MB)।');
            }
            if ($uploadUrl !== null) {
                $profilePictureUrl = $uploadUrl;
            }
        }

        $res = $userService->createUser([
            'username' => $username,
            'email' => $email,
            'name_bn' => $name_bn,
            'name_en' => $name_en,
            'phone_number' => $phone,
            'password' => $password,
            'confirm_password' => $confirm,
            'role_id' => $role_id,
            'union_id' => $union_id,
            'ward_no' => $ward_no,
            'address' => $address,
            'status' => $status,
            'language_preference' => $language,
            'timezone' => $timezone,
            'is_email_notifications_enabled' => $emailNotif,
            'is_sms_notifications_enabled' => $smsNotif,
        ], $userModel);

        if (!$res['success']) {
            throw new Exception(implode(', ', $res['errors'] ?? ['ব্যবহারকারী তৈরি করা যায়নি']));
        }

        // Update profile_picture_url if one was uploaded (create() doesn't support it in INSERT)
        if ($profilePictureUrl !== null && !empty($res['user_id'])) {
            $userModel->update($res['user_id'], ['profile_picture_url' => $profilePictureUrl]);
        }

        if (function_exists('sendWelcomeEmail')) {
            sendWelcomeEmail($email, $username);
        }

        echo json_encode([
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => 'নতুন ব্যবহারকারী যোগ করা হয়েছে', 'type' => 'success'],
            'redirect' => '/users',
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => $e->getMessage(), 'type' => 'error'],
        ]);
    }
    exit;
});

// ================================================================
// USERS LIST
// ================================================================
$router->get('/users', function () use ($twig, $auth, $authService, $mysqli) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');

    $currentUser = $auth->getUserData(false);
    $isSuperAdmin = (int)$currentUser['role_id'] <= 1;
    $userModel = new UserModel($mysqli);
    $rolesManager = new RolesManager($mysqli);

    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $role   = $_GET['role'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $filters = [];
    if ($search) $filters['search'] = $search;
    if ($status) $filters['status'] = $status;
    if ($role) $filters['role_id'] = (int)$role;
    if (!$isSuperAdmin && !empty($currentUser['union_id'])) {
        $filters['union_id'] = $currentUser['union_id'];
    }
    if (!$isSuperAdmin) {
        $filters['exclude_superadmin'] = true;
    }

    $total = $userModel->countUsers($filters);
    $pages = max(1, ceil($total / $perPage));

    $filters['limit'] = $perPage;
    $filters['offset'] = $offset;
    $users = $userModel->getAll($filters, 'created_at', 'DESC');

    $roles = $rolesManager->getAllRoles();
    foreach ($roles as &$r) {
        $r['id'] = $r['role_id'] ?? ($r['id'] ?? null);
    }

    echo $twig->render('users/admin_users_list.twig', [
        'users'      => $users,
        'pagination' => [
            'current'       => $page,
            'total'         => $pages,
            'per_page'      => $perPage,
            'showing_from'  => $total > 0 ? $offset + 1 : 0,
            'showing_to'    => min($offset + $perPage, $total),
            'total_records' => $total,
        ],
        'filters'     => ['search' => $search, 'status' => $status, 'role' => $role],
        'roles'        => $roles,
        'total_users'  => $total,
        'active_count' => $userModel->countUsers(array_merge(
            array_diff_key($filters, array_flip(['limit', 'offset'])),
            ['status' => 'active']
        )),
        'inactive_count' => $userModel->countUsers(array_merge(
            array_diff_key($filters, array_flip(['limit', 'offset'])),
            ['status' => 'inactive']
        )),
        'title'        => 'Users Management',
        'header_title' => 'Manage All Users',
        'csrf_token'   => generateCsrfToken(),
    ]);
});

// ================================================================
// USER VIEW (ADMIN)
// ================================================================
$router->get('/users/{id}', function ($id) use ($twig, $auth, $authService, $mysqli) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');

    $userModel = new UserModel($mysqli);
    $profileData = $userModel->getUserDetails((int)$id);

    if (!$profileData) {
        renderError(404, 'ব্যবহারকারী খুঁজে পাওয়া যায়নি');
        return;
    }

    $unionInfo = null;
    if (!empty($profileData['union_name_bn']) || !empty($profileData['union_name_en'])) {
        $unionInfo = [
            'union_name_bn' => $profileData['union_name_bn'] ?? null,
            'union_name_en' => $profileData['union_name_en'] ?? null,
        ];
    }

    echo $twig->render('users/admin_user_view.twig', [
        'profileData'  => $profileData,
        'unionInfo'    => $unionInfo,
        'title'        => 'ব্যবহারকারী প্রোফাইল',
        'header_title' => 'ব্যবহারকারী প্রোফাইল',
        'csrf_token'   => generateCsrfToken(),
    ]);
});

// ================================================================
// USER EDIT (FORM)
// ================================================================
$router->get('/users/{id}/edit', function ($id) use ($twig, $auth, $authService, $userService, $mysqli) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    $authService->ensureRoleLevel(ROLE_LEVEL_SECRETARY);

    $currentUser = $auth->getUserData(false);
    $currentUserId = (int)$currentUser['user_id'];

    $userModel = new UserModel($mysqli);
    $profileData = $userModel->getUserDetails((int)$id);

    if (!$profileData) {
        renderError(404, 'ব্যবহারকারী খুঁজে পাওয়া যায়নি');
        return;
    }

    // Prevent editing yourself
    if ((int)$id === $currentUserId) {
        renderError(403, 'নিজের অ্যাকাউন্ট এখান থেকে সম্পাদনা করা যাবে না। প্রোফাইল পৃষ্ঠা ব্যবহার করুন।');
        return;
    }

    // Prevent privilege escalation: can't edit users with equal or higher role level
    if (!$userService->canManageUserByLevel($currentUserId, (int)$id)) {
        renderError(403, 'আপনার চেয়ে সমান বা উচ্চতর ভূমিকার ব্যবহারকারী সম্পাদনা করার অনুমতি নেই।');
        return;
    }

    $unionModel = new UnionModel($mysqli);
    $unions = $unionModel->getAllUnions();

    // Fetch and enrich roles with Bangla names
    $rolesManager = new RolesManager($mysqli);
    $roles = $rolesManager->getAllRoles();
    $roleBangla = [
        1 => 'অ্যাডমিনিস্ট্রেটর',
        2 => 'সচিব',
        3 => 'চেয়ারম্যান',
        4 => 'মেম্বার',
        5 => 'কম্পিউটার অপারেটর',
        6 => 'গ্রাম পুলিশ',
        7 => 'অফিস সহকারী',
    ];
    foreach ($roles as &$r) {
        $rid = isset($r['role_id']) ? (int)$r['role_id'] : (isset($r['id']) ? (int)$r['id'] : null);
        $r['id'] = $rid;
        $r['name_bn'] = $roleBangla[$rid] ?? $r['role_name'];
    }

    echo $twig->render('users/admin_user_edit.twig', [
        'profileData'  => $profileData,
        'roles'        => $roles,
        'unions'       => $unions,
        'title'        => 'ব্যবহারকারী সম্পাদনা',
        'header_title' => 'ব্যবহারকারী সম্পাদনা',
        'csrf_token'   => generateCsrfToken(),
    ]);
});

// ================================================================
// USER UPDATE (POST)
// ================================================================
$router->post('/users/{id}/edit', function ($id) use ($auth, $authService, $userService, $mysqli) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    $authService->ensureRoleLevel(ROLE_LEVEL_SECRETARY);
    header('Content-Type: application/json');

    $currentUser = $auth->getUserData(false);
    $currentUserId = (int)$currentUser['user_id'];
    $id = (int)$id;

    // Prevent editing yourself
    if ($id === $currentUserId) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => 'নিজের অ্যাকাউন্ট এখান থেকে সম্পাদনা করা যাবে না। প্রোফাইল পৃষ্ঠা ব্যবহার করুন।', 'type' => 'error'],
        ]);
        exit;
    }

    // Prevent privilege escalation: can't edit users with equal or higher role level
    if (!$userService->canManageUserByLevel($currentUserId, $id)) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => 'আপনার চেয়ে সমান বা উচ্চতর ভূমিকার ব্যবহারকারী সম্পাদনা করার অনুমতি নেই।', 'type' => 'error'],
        ]);
        exit;
    }

    $userModel = new UserModel($mysqli);
    $existingUser = $userModel->getById($id);

    if (!$existingUser) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => 'ব্যবহারকারী পাওয়া যায়নি', 'type' => 'error'],
        ]);
        exit;
    }

    try {
        // Extract username/email first for duplicate validation
        $inputUsername = sanitize_input($_POST['username'] ?? $existingUser['username']);
        $inputEmail = sanitize_input($_POST['email'] ?? $existingUser['email']);

        // Validate unique username and email (exclude current user from check)
        if ($inputUsername !== $existingUser['username'] && $userModel->usernameExists($inputUsername, $id)) {
            throw new Exception('এই ব্যবহারকারীর নাম ইতিমধ্যে অন্য কেউ ব্যবহার করছে।');
        }
        if ($inputEmail !== $existingUser['email'] && $userModel->emailExists($inputEmail, $id)) {
            throw new Exception('এই ইমেইল ইতিমধ্যে অন্য কেউ ব্যবহার করছে।');
        }

        $data = [
            'username' => $inputUsername,
            'email' => $inputEmail,
            'name_bn' => sanitize_input($_POST['name_bn'] ?? $existingUser['name_bn']),
            'name_en' => sanitize_input($_POST['name_en'] ?? $existingUser['name_en']),
            'phone_number' => sanitize_input($_POST['phone_number'] ?? $existingUser['phone_number']),
            'address' => sanitize_input($_POST['address'] ?? $existingUser['address'] ?? ''),
            'status' => $_POST['status'] ?? $existingUser['status'],
            'role_id' => (int)($_POST['role_id'] ?? $existingUser['role_id']),
            'union_id' => !empty($_POST['union_id']) ? (int)$_POST['union_id'] : null,
            'ward_no' => sanitize_input($_POST['ward_no'] ?? $existingUser['ward_no'] ?? ''),
            'language_preference' => $_POST['language_preference'] ?? $existingUser['language_preference'] ?? 'bn',
            'timezone' => $_POST['timezone'] ?? $existingUser['timezone'] ?? 'Asia/Dhaka',
            'is_email_notifications_enabled' => isset($_POST['is_email_notifications_enabled']) ? 1 : 0,
            'is_sms_notifications_enabled' => isset($_POST['is_sms_notifications_enabled']) ? 1 : 0,
        ];

        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadUrl = $userModel->uploadProfilePicture($_FILES['profile_picture']);
            if ($uploadUrl === null && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                throw new Exception('প্রোফাইল ছবি আপলোড ব্যর্থ হয়েছে। শুধুমাত্র JPG, PNG, GIF ফাইল অনুমোদিত (সর্বোচ্চ 5MB)।');
            }
            if ($uploadUrl !== null) {
                // Delete old profile picture from disk
                if (!empty($existingUser['profile_picture_url'])) {
                    $oldPath = __DIR__ . '/../../public' . $existingUser['profile_picture_url'];
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
                $data['profile_picture_url'] = $uploadUrl;
            }
        }

        // RBAC: validate role/union changes to prevent privilege escalation
        $hierarchyErrors = [];
        if (!$userService->validateUserDataForPrivilegeEscalation($currentUserId, ['role_id' => $data['role_id']], $hierarchyErrors)) {
            throw new Exception(implode(', ', $hierarchyErrors));
        }

        // Handle password update (only if provided)
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (!empty($password) || !empty($confirmPassword)) {
            if ($password !== $confirmPassword) {
                throw new Exception('পাসওয়ার্ড এবং নিশ্চিত পাসওয়ার্ড মিলছে না');
            }
            if (strlen($password) < 8) {
                throw new Exception('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে');
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            $data['last_password_change'] = date('Y-m-d H:i:s');
        }

        // Validate ward_no for role 4
        if ($data['role_id'] === 4 && empty($data['ward_no'])) {
            throw new Exception('সদস্য ভূমিকার জন্য ওয়ার্ড নং বাধ্যতামূলক');
        }

        $result = $userModel->update($id, $data);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'আপডেট করতে ব্যর্থ হয়েছে');
        }

        echo json_encode([
            'success' => true,
            'alert' => ['title' => 'সফল', 'message' => 'ব্যবহারকারী সফলভাবে আপডেট হয়েছে', 'type' => 'success'],
            'redirect' => '/users/' . $id,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => ['title' => 'ত্রুটি', 'message' => $e->getMessage(), 'type' => 'error'],
        ]);
    }
    exit;
});

// ================================================================
// SINGLE DELETE
// ================================================================
$router->post('/users/{id}/delete', function ($id) use ($auth, $authService, $userService) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    $authService->ensureRoleLevel(ROLE_LEVEL_CHAIRMAN);
    header('Content-Type: application/json');

    $currentUser = $auth->getUserData(false);
    $result = $userService->deleteUser((int)$id, (int)$currentUser['user_id']);
    echo json_encode($result);
    exit;
});

// ================================================================
// BULK DELETE
// ================================================================
$router->post('/users/bulk-delete', function () use ($auth, $authService, $userService) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    header('Content-Type: application/json');

    $currentUser = $auth->getUserData(false);
    $ids = $_POST['user_ids'] ?? [];
    $result = $userService->bulkDeleteUsers($ids, (int)$currentUser['user_id']);
    echo json_encode($result);
    exit;
});

// ================================================================
// TOGGLE USER STATUS
// ================================================================
$router->post('/users/{id}/toggle-status', function ($id) use ($auth, $authService, $userService) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    header('Content-Type: application/json');

    $currentUser = $auth->getUserData(false);
    $result = $userService->toggleUserStatus((int)$id, (int)$currentUser['user_id']);
    echo json_encode($result);
    exit;
});

// ================================================================
// BULK STATUS TOGGLE
// ================================================================
$router->post('/users/bulk-toggle-status', function () use ($auth, $authService, $userService) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');
    header('Content-Type: application/json');

    $ids = $_POST['user_ids'] ?? [];
    $newStatus = $_POST['status'] ?? 'active';
    $result = $userService->bulkToggleStatus($ids, $newStatus);
    echo json_encode($result);
    exit;
});

// ================================================================
// USER EXPORT (CSV)
// ================================================================
$router->get('/users/export', function () use ($auth, $authService, $userService) {
    $auth->requireLogin();
    $authService->ensureCan('manage_users', 'users');

    $userService->exportUsersCsv();
});
