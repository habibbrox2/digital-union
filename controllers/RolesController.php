<?php
// controllers/RolesController.php
// Complete Role Management with CRUD operations and permission assignment
// Strictly role-based architecture (USER → ROLE → PERMISSIONS)

global $router, $mysqli, $twig;

require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../classes/RolesManager.php';
require_once __DIR__ . '/../classes/PermissionsManager.php';
require_once __DIR__ . '/../classes/AuthManager.php';
require_once __DIR__ . '/../helpers/rbac_helpers.php';
require_once __DIR__ . '/../helpers/sweetalertHelper.php';

// =============================================================================
// AUTHENTICATION MIDDLEWARE
// =============================================================================

$requireSuperadmin = function() use ($mysqli) {
    $auth = new AuthManager($mysqli);
    $auth->requireLogin();
    $user = $auth->getUserData(false);
    if (!$user) {
        http_response_code(401);
        exit('অননুমোদিত');
    }
    if (!isSuperadmin($user['user_id'], $mysqli)) {
        http_response_code(403);
        exit('নিষিদ্ধ: শুধুমাত্র সুপারঅ্যাডমিন');
    }
    return $user;
};

// =============================================================================
// ROLE LISTING & VIEWING (READ-ONLY)
// =============================================================================

/**
 * GET /roles
 * List all roles with action buttons for superadmin
 */
$router->get('/roles', function() use ($mysqli, $twig, $requireSuperadmin) {
    ensure_can('manage_roles', 'roles');
    $rolesManager = new RolesManager($mysqli);
    $roles = $rolesManager->getAll();

    echo $twig->render('roles/index.twig', [
        'roles' => $roles,
        'pageTitle' => 'Roles Management',
        'header_title' => 'Roles Management',
        'title' => 'Roles Management'
    ]);
});

/**
 * GET /roles/add
 * Show role creation form (superadmin only)
 */
$router->get('/roles/add', function() use ($twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');
    
    echo $twig->render('roles/add_role.twig', [
        'pageTitle' => 'Add New Role',
        'title' => 'Add New Role',
        'header_title' => 'Add New Role'
    ]);
});

/**
 * POST /roles/add
 * Handle role creation (superadmin only)
 */
$router->post('/roles/add', function() use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');

    // CSRF handled by middleware; removed inline verification

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || strlen($name) < 2) {
        errorAlert('ফর্ম ত্রুটি', 'রোলের নাম কমপক্ষে ২ অক্ষরের হওয়া উচিত');
        echo $twig->render('roles/add_role.twig', [
            'title' => 'Add New Role',
            'header_title' => 'Add New Role',
            'form_data' => $_POST
        ]);
        exit;
    }

    $rolesManager = new RolesManager($mysqli);
    $roleId = $rolesManager->createRole($name, $description);

    if ($roleId) {
        successAlert('সফল', 'রোল সফলভাবে তৈরি হয়েছে');
        header('Location: /roles');
        exit;
    } else {
        errorAlert('ত্রুটি', 'রোল তৈরি ব্যর্থ হয়েছে। নামটি ইতিমধ্যে বিদ্যমান থাকতে পারে।');
        echo $twig->render('roles/add_role.twig', [
            'title' => 'Add New Role',
            'header_title' => 'Add New Role',
            'form_data' => $_POST
        ]);
    }
});

/**
 * GET /roles/{id}
 * View single role details with permissions count
 */
$router->get('/roles/{id}', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_can('manage_roles', 'roles');
    $rolesManager = new RolesManager($mysqli);
    $role = $rolesManager->getRoleById((int)$id);
    
    if (!$role) {
        http_response_code(404);
        exit('Role not found');
    }

    $permissionsManager = new PermissionsManager($mysqli);
    $permissions = $permissionsManager->getPermissionsByRole((int)$id);

    echo $twig->render('roles/view.twig', [
        'role' => $role,
        'permissions' => $permissions,
        'permissionCount' => count($permissions),
        'title' => 'View Role',
        'header_title' => 'View Role'
    ]);
});

// =============================================================================
// ROLE EDITING
// =============================================================================

$router->get('/roles/{id}/edit', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');
    $rolesManager = new RolesManager($mysqli);
    $role = $rolesManager->getRoleById((int)$id);

    if (!$role) {
        http_response_code(404);
        exit('Role not found');
    }

    echo $twig->render('roles/edit_role.twig', [
        'role' => $role,
        'pageTitle' => 'Edit Role',
        'title' => 'Edit Role',
        'header_title' => 'Edit Role'
    ]);
});

$router->post('/roles/{id}/edit', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');

    // CSRF handled by middleware; removed inline verification

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || strlen($name) < 2) {
        errorAlert('ফর্ম ত্রুটি', 'রোলের নাম কমপক্ষে ২ অক্ষরের হওয়া উচিত');
        $role = (new RolesManager($mysqli))->getRoleById((int)$id);
        echo $twig->render('roles/edit_role.twig', [
            'role' => $role,
            'title' => 'Edit Role',
            'header_title' => 'Edit Role',
            'form_data' => $_POST
        ]);
        exit;
    }

    $rolesManager = new RolesManager($mysqli);
    $success = $rolesManager->updateRole((int)$id, $name, $description);

    if ($success) {
        successAlert('সফল', 'রোল সফলভাবে আপডেট হয়েছে');
        header('Location: /roles');
        exit;
    } else {
        errorAlert('ত্রুটি', 'রোল আপডেট করা যায়নি');
        $role = $rolesManager->getRoleById((int)$id);
        echo $twig->render('roles/edit_role.twig', [
            'role' => $role,
            'title' => 'Edit Role',
            'header_title' => 'Edit Role'
        ]);
    }
});

// =============================================================================
// ROLE DELETION
// =============================================================================

$router->get('/roles/{id}/delete', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');
    $rolesManager = new RolesManager($mysqli);
    $role = $rolesManager->getRoleById((int)$id);

    if (!$role) {
        http_response_code(404);
        exit('Role not found');
    }

    echo $twig->render('roles/delete_confirm.twig', [
        'role' => $role,
        'title' => 'Delete Role',
        'header_title' => 'Delete Role'
    ]);
});

$router->post('/roles/{id}/delete', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');

    // CSRF handled by middleware; removed inline verification

    $rolesManager = new RolesManager($mysqli);
    $success = $rolesManager->deleteRole((int)$id);

    if ($success) {
        successAlert('সফল', 'রোল সফলভাবে মুছে ফেলা হয়েছে');
        header('Location: /roles');
        exit;
    } else {
        errorAlert('ত্রুটি', 'রোল মুছতে ব্যর্থ হয়েছে। এটি ব্যবহৃত হতে পারে।');
        $role = $rolesManager->getRoleById((int)$id);
        echo $twig->render('roles/delete_confirm.twig', [
            'role' => $role,
            'title' => 'Delete Role',
            'header_title' => 'Delete Role'
        ]);
    }
});

// =============================================================================
// ROLE PERMISSION MANAGEMENT
// =============================================================================

$router->get('/roles/{id}/permissions', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');
    $rolesManager = new RolesManager($mysqli);
    $role = $rolesManager->getRoleById((int)$id);

    if (!$role) {
        http_response_code(404);
        exit('Role not found');
    }

    echo $twig->render('roles/manage_permissions.twig', [
        'role' => $role,
        'title' => 'Manage Role Permissions',
        'header_title' => 'Manage Role Permissions'
    ]);
});

$router->get('/api/roles/{id}/permissions', function($id) use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_roles', 'roles');

    $rolesManager = new RolesManager($mysqli);
    $role = $rolesManager->getRoleById((int)$id);
    if (!$role) {
        http_response_code(404);
        echo json_encode(['error' => 'Role not found']);
        exit;
    }

    $permissionsManager = new PermissionsManager($mysqli);
    $allPermissions = $permissionsManager->getAllPermissions();
    $rolePermissions = $permissionsManager->getPermissionsByRole((int)$id);
    $rolePermIds = array_map(fn($p) => (int)$p['id'], $rolePermissions);

    $result = array_map(function($perm) use ($rolePermIds) {
        return [
            'id' => (int)$perm['id'],
            'name' => $perm['name'],
            'module' => $perm['module'],
            'description' => $perm['description'] ?? '',
            'assigned' => in_array((int)$perm['id'], $rolePermIds)
        ];
    }, $allPermissions);

    echo json_encode($result);
});

$router->post('/roles/{id}/permissions', function($id) use ($mysqli, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_roles', 'roles');
    
    // CSRF handled by middleware; removed inline verification

    $data = json_decode($_POST['permissions_json'] ?? '[]', true);
    $newPermIds = is_array($data) ? array_map(fn($p) => (int)$p, array_filter($data)) : [];

    $rolesManager = new RolesManager($mysqli);
    $success = $rolesManager->bulkAssignPermissionsToRole((int)$id, $newPermIds);

    if ($success) {
        successAlert('সফল', 'অনুমতিসমূহ আপডেট করা হয়েছে');
    } else {
        errorAlert('ত্রুটি', 'অনুমতি সংরক্ষণ করা যায়নি');
    }

    header('Location: /roles/' . ((int)$id) . '/permissions');
    exit;
});

// =============================================================================
// API ENDPOINTS
// =============================================================================

$router->get('/api/roles', function() use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_roles', 'roles');
    
    $rolesManager = new RolesManager($mysqli);
    $roles = $rolesManager->getAll();
    
    echo json_encode([
        'success' => true,
        'roles' => $roles
    ]);
});

$router->get('/api/roles/{id}', function($id) use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_roles', 'roles');
    
    $rolesManager = new RolesManager($mysqli);
    $role = $rolesManager->getRoleById((int)$id);
    
    if (!$role) {
        http_response_code(404);
        echo json_encode(['error' => 'Role not found']);
        exit;
    }
    
    $permissionsManager = new PermissionsManager($mysqli);
    $permissions = $permissionsManager->getPermissionsByRole((int)$id);
    
    echo json_encode([
        'success' => true,
        'role' => $role,
        'permissions' => $permissions,
        'permission_count' => count($permissions)
    ]);
});
