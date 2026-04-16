<?php
// controllers/PermissionsController.php
// Permission Management - CRUD operations
// Strictly role-based: role-based access control ONLY

global $router, $mysqli, $twig;

require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../classes/PermissionsManager.php';
require_once __DIR__ . '/../classes/RolesManager.php';
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
// PERMISSION LISTING
// =============================================================================

/**
 * GET /permissions
 * List all permissions grouped by module
 */
$router->get('/permissions', function () use ($mysqli, $twig, $requireSuperadmin) {
    ensure_can('manage_permissions', 'permissions');
    
    $pm = new PermissionsManager($mysqli);
    $modules = $pm->getAllModules();
    $allPerms = $pm->getAllPermissions();

    // Group by module for template
    $permissionsByModule = [];
    foreach ($allPerms as $p) {
        $permissionsByModule[$p['module']][] = $p;
    }

    

    echo $twig->render('permissions/index.twig', [
        'modules' => $modules,
        'permissionsByModule' => $permissionsByModule,
        'permissions' => $allPerms,
        'pageTitle' => 'Permissions Management',
        'title' => 'Permissions Management',
        'header_title' => 'Permissions Management'
    ]);
});

// =============================================================================
// MANAGE PERMISSIONS (Role-Based Only)
// =============================================================================

/**
 * GET /manage-permissions
 * Display role-based permissions management interface
 * (User-based permissions have been removed - role-based only)
 */
$router->get('/manage-permissions', function () use ($mysqli, $twig, $requireSuperadmin) {
    ensure_can('manage_permissions', 'permissions');
    
    $rm = new RolesManager($mysqli);
    $pm = new PermissionsManager($mysqli);
    
    $roles = $rm->getAllRoles();
    $permissions = $pm->getAllPermissions();
    
    // Get assigned permissions for each role
    $assignedRolePermissions = [];
    foreach ($roles as $role) {
        $assignedRolePermissions[$role['role_id']] = $rm->getPermissionsByRole($role['role_id']);
        // Convert to indexed array for template
        $indexed = [];
        foreach ($assignedRolePermissions[$role['role_id']] as $perm) {
            $indexed[$perm['permission_id']] = $perm;
        }
        $assignedRolePermissions[$role['role_id']] = $indexed;
    }
    
    
    
    echo $twig->render('permissions/manage_permissions.twig', [
        'roles' => $roles,
        'permissions' => $permissions,
        'assigned_role_permissions' => $assignedRolePermissions,
        'assigned_user_permissions' => [], // Empty - user permissions removed
        'pageTitle' => 'Manage Permissions',
        'title' => 'Manage Permissions',
        'header_title' => 'Manage Permissions'
    ]);
});

/**
 * POST /manage-permissions
 * Save role-based permission assignments
 * (User-based permissions have been removed - role-based only)
 */
$router->post('/manage-permissions', function () use ($mysqli, $twig, $requireSuperadmin) {
    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    ensure_can('manage_permissions', 'permissions');
    
    // CSRF handled by middleware; removed inline verification
    $rm = new RolesManager($mysqli);
    $pm = new PermissionsManager($mysqli);
    
    // Process role_permissions
    $rolePermissions = $_POST['role_permissions'] ?? [];
    
    if (!empty($rolePermissions) && is_array($rolePermissions)) {
        foreach ($rolePermissions as $roleId => $permissionIds) {
            $roleId = (int)$roleId;
            $permissionIds = array_map('intval', (array)$permissionIds);
            
            // Get current permissions
            $currentPerms = $rm->getPermissionsByRole($roleId);
            $currentPermIds = array_column($currentPerms, 'permission_id');
            
            // Find additions and removals
            $toAdd = array_diff($permissionIds, $currentPermIds);
            $toRemove = array_diff($currentPermIds, $permissionIds);
            
            // Add new permissions
            foreach ($toAdd as $permId) {
                $rm->assignPermissionToRole($roleId, $permId);
            }
            
            // Remove permissions
            foreach ($toRemove as $permId) {
                $rm->removePermissionFromRole($roleId, $permId);
            }
        }
    }
    
    successAlert('সফল', 'রোলভিত্তিক অনুমতি সফলভাবে আপডেট হয়েছে। ব্যবহারকারীর অনুমতি রোলের মাধ্যমে নিয়ন্ত্রিত হয়।');
    header('Location: /manage-permissions');
    exit;
});

// =============================================================================
// PERMISSION CREATION
// =============================================================================

/**
 * GET /permissions/add
 * Show add permission form
 */
$router->get('/permissions/add', function() use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions', 'permissions');
    
    $pm = new PermissionsManager($mysqli);
    $modules = $pm->getAllModules();
    
    echo $twig->render('permissions/add_permission.twig', [
        'modules' => $modules,
        'pageTitle' => 'Add New Permission'
    ]);
});

/**
 * POST /permissions/add
 * Create permission
 */
$router->post('/permissions/add', function() use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions', 'permissions');

    // CSRF handled by middleware; removed inline verification

    $name = trim($_POST['name'] ?? '');
    $module = trim($_POST['module'] ?? 'general');
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || strlen($name) < 2) {
        errorAlert('ফর্ম ত্রুটি', 'অনুমতির নাম কমপক্ষে ২ অক্ষরের হওয়া উচিত');
        $pm = new PermissionsManager($mysqli);
        $modules = $pm->getAllModules();
        echo $twig->render('permissions/add_permission.twig', [
            'modules' => $modules,
            'title' => 'Add New Permission',
            'header_title' => 'Add New Permission'
        ]);
        exit;
    }

    if (empty($module)) {
        errorAlert('ফর্ম ত্রুটি', 'মডিউল প্রয়োজন');
        $pm = new PermissionsManager($mysqli);
        $modules = $pm->getAllModules();
        echo $twig->render('permissions/add_permission.twig', [
            'modules' => $modules,
            'title' => 'Add New Permission',
            'header_title' => 'Add New Permission'
        ]);
        exit;
    }

    $pm = new PermissionsManager($mysqli);
    $permId = $pm->createPermission($name, $module, $description);
    
    if ($permId) {
        successAlert('সফল', 'অনুমতি সফলভাবে তৈরি হয়েছে');
        header('Location: /permissions');
        exit;
    } else {
        errorAlert('ত্রুটি', 'অনুমতি তৈরি করা যায়নি। ইতোমধ্যে বিদ্যমান থাকতে পারে।');
        $modules = $pm->getAllModules();
        echo $twig->render('permissions/add_permission.twig', [
            'modules' => $modules,
            'title' => 'Add New Permission',
            'header_title' => 'Add New Permission'
        ]);
    }
});

// =============================================================================
// PERMISSION EDITING
// =============================================================================

/**
 * GET /permissions/{id}/edit
 */
$router->get('/permissions/{id}/edit', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions', 'permissions');

    $pm = new PermissionsManager($mysqli);
    $perm = $pm->getPermissionById((int)$id);
    
    if (!$perm) {
        http_response_code(404);
        exit('Permission not found');
    }
    
    $modules = $pm->getAllModules();
    
        echo $twig->render('permissions/edit_permission.twig', [
        'permission' => $perm,
        'modules' => $modules,
        'pageTitle' => 'Edit Permission',
        'title' => 'Edit Permission',
        'header_title' => 'Edit Permission'
    ]);
});

/**
 * POST /permissions/{id}/edit
 */
$router->post('/permissions/{id}/edit', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions', 'permissions');

    // CSRF handled by middleware; removed inline verification

    $name = trim($_POST['name'] ?? '');
    $module = trim($_POST['module'] ?? 'general');
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || strlen($name) < 2) {
        errorAlert('ফর্ম ত্রুটি', 'অনুমতির নাম কমপক্ষে ২ অক্ষরের হওয়া উচিত');
        $pm = new PermissionsManager($mysqli);
        $perm = $pm->getPermissionById((int)$id);
        $modules = $pm->getAllModules();
        echo $twig->render('permissions/edit_permission.twig', [
            'permission' => $perm,
            'modules' => $modules,
            'title' => 'Edit Permission',
            'header_title' => 'Edit Permission'
        ]);
        exit;
    }

    $pm = new PermissionsManager($mysqli);
    $success = $pm->updatePermission((int)$id, $name, $module, $description);
    
    if ($success) {
        successAlert('সফল', 'অনুমতি সফলভাবে আপডেট হয়েছে');
        header('Location: /permissions');
        exit;
    } else {
        errorAlert('ত্রুটি', 'অনুমতি আপডেট করা যায়নি');
        $perm = $pm->getPermissionById((int)$id);
        $modules = $pm->getAllModules();
        echo $twig->render('permissions/edit_permission.twig', [
            'permission' => $perm,
            'modules' => $modules,
            'title' => 'Edit Permission',
            'header_title' => 'Edit Permission'
        ]);
    }
});

// =============================================================================
// PERMISSION DELETION
// =============================================================================

/**
 * GET /permissions/{id}/delete
 */
$router->get('/permissions/{id}/delete', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions', 'permissions');

    $pm = new PermissionsManager($mysqli);
    $perm = $pm->getPermissionById((int)$id);
    
    if (!$perm) {
        http_response_code(404);
        exit('Permission not found');
    }
    
    echo $twig->render('permissions/delete_confirm.twig', [
        'permission' => $perm,
        'title' => 'Delete Permission',
        'header_title' => 'Delete Permission'
    ]);
});

/**
 * POST /permissions/{id}/delete
 */
$router->post('/permissions/{id}/delete', function($id) use ($mysqli, $twig, $requireSuperadmin) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions', 'permissions');

    // CSRF handled by middleware; removed inline verification

    $pm = new PermissionsManager($mysqli);
    $success = $pm->deletePermission((int)$id);
    
    if ($success) {
        successAlert('সফল', 'অনুমতি সফলভাবে মুছে ফেলা হয়েছে');
        header('Location: /permissions');
        exit;
    } else {
        errorAlert('ত্রুটি', 'অনুমতি মুছতে ব্যর্থ হয়েছে। এটি ব্যবহৃত হতে পারে।');
        $perm = $pm->getPermissionById((int)$id);
        echo $twig->render('permissions/delete_confirm.twig', [
            'permission' => $perm,
            'title' => 'Delete Permission',
            'header_title' => 'Delete Permission'
        ]);
    }
});

// =============================================================================
// API ENDPOINTS
// =============================================================================

/**
 * GET /api/permissions
 * List all permissions (JSON)
 */
$router->get('/api/permissions', function () use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions', 'permissions');
    
    $pm = new PermissionsManager($mysqli);
    $perms = $pm->getAllPermissions();
    
    echo json_encode([
        'success' => true,
        'permissions' => $perms
    ]);
    exit;
});

/**
 * GET /api/permissions/{id}
 * Get single permission (JSON)
 */
$router->get('/api/permissions/{id}', function($id) use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions', 'permissions');
    
    $pm = new PermissionsManager($mysqli);
    $perm = $pm->getPermissionById((int)$id);
    
    if (!$perm) {
        http_response_code(404);
        echo json_encode(['error' => 'Permission not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'permission' => $perm
    ]);
});

/**
 * GET /api/permissions/search
 * Search permissions by name (JSON)
 */
$router->get('/api/permissions/search', function() use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions', 'permissions');
    
    $query = trim($_GET['q'] ?? '');
    $module = trim($_GET['module'] ?? '');
    
    if (empty($query) && empty($module)) {
        http_response_code(400);
        echo json_encode(['error' => 'Query or module parameter required']);
        exit;
    }
    
    $pm = new PermissionsManager($mysqli);
    
    if (!empty($module) && empty($query)) {
        $perms = $pm->getPermissionsByModule($module);
    } else {
        $allPerms = $pm->getAllPermissions();
        $perms = array_filter($allPerms, function($p) use ($query) {
            return stripos($p['name'], $query) !== false || 
                   stripos($p['description'] ?? '', $query) !== false;
        });
    }
    
    echo json_encode([
        'success' => true,
        'results' => array_values($perms),
        'count' => count($perms)
    ]);
});

/**
 * GET /api/permissions/module/{module}
 * Get permissions by module (JSON)
 */
$router->get('/api/permissions/module/{module}', function($module) use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions', 'permissions');
    
    $pm = new PermissionsManager($mysqli);
    $perms = $pm->getPermissionsByModule($module);
    
    echo json_encode([
        'success' => true,
        'module' => $module,
        'permissions' => $perms,
        'count' => count($perms)
    ]);
});

/**
 * GET /api/check-permission
 * Check if current user has a specific permission
 * Query: ?permission=permission_name
 */
$router->get('/api/check-permission', function() use ($mysqli) {
    header('Content-Type: application/json');
    
    $permission = trim($_GET['permission'] ?? '');
    
    if (empty($permission)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Permission parameter required'
        ]);
        exit;
    }
    
    try {
        $auth = new AuthManager($mysqli);
        $user = $auth->getUserData(false);
        
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Not authenticated',
                'has_permission' => false
            ]);
            exit;
        }
        
        $userId = $user['user_id'];
        
        // Check if user is superadmin (role_id = 1)
        $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result && (int)$result['role_id'] === 1) {
            // Superadmin has all permissions
            echo json_encode([
                'success' => true,
                'has_permission' => true,
                'permission' => $permission,
                'user_id' => $userId,
                'is_superadmin' => true
            ]);
            exit;
        }
        
        // Check actual permission
        $pm = new PermissionsManager($mysqli);
        $hasPermission = $pm->hasPermission($userId, $permission);
        
        echo json_encode([
            'success' => true,
            'has_permission' => (bool)$hasPermission,
            'permission' => $permission,
            'user_id' => $userId,
            'is_superadmin' => false
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage(),
            'has_permission' => false
        ]);
    }
    exit;
});

// =============================================================================
// PERMISSION ASSIGNMENT (UI and API)
// =============================================================================

/**
 * GET /permissions/assign
 * Show permission assignment interface
 */
$router->get('/permissions/assign', function () use ($mysqli, $twig) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions');
    
    $rm = new RolesManager($mysqli);
    $pm = new PermissionsManager($mysqli);
    
    $roles = $rm->getAllRoles();
    $permissions = $pm->getAllPermissions();
    
    // Get assigned permissions for each role
    $assignedRolePermissions = [];
    foreach ($roles as $role) {
        $assignedRolePermissions[$role['role_id']] = $rm->getPermissionsByRole($role['role_id']);
        // Convert to indexed array for template
        $indexed = [];
        foreach ($assignedRolePermissions[$role['role_id']] as $perm) {
            $indexed[$perm['id']] = $perm;
        }
        $assignedRolePermissions[$role['role_id']] = $indexed;
    }
    
    
    
    echo $twig->render('permissions/assign_permissions.twig', [
        'roles' => $roles,
        'permissions' => $permissions,
        'assigned_role_permissions' => $assignedRolePermissions,
        'pageTitle' => 'Assign Permissions',
        'title' => 'Assign Permissions',
        'header_title' => 'Assign Permissions'
    ]);
});

/**
 * POST /permissions/assign
 * Save permission assignments
 */
$router->post('/permissions/assign', function () use ($mysqli) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions');
    
    // CSRF handled by middleware; removed inline verification
    
    $rm = new RolesManager($mysqli);
    $pm = new PermissionsManager($mysqli);
    $user = (new AuthManager($mysqli))->getUserData(false);
    
    // Process role_permissions
    $rolePermissions = $_POST['role_permissions'] ?? [];
    
    if (!empty($rolePermissions) && is_array($rolePermissions)) {
        foreach ($rolePermissions as $roleId => $permissionIds) {
            $roleId = (int)$roleId;
            $permissionIds = array_map('intval', (array)$permissionIds);
            
            // Get current permissions
            $currentPerms = $rm->getPermissionsByRole($roleId);
            $currentPermIds = array_column($currentPerms, 'id');
            
            // Find additions and removals
            $toAdd = array_diff($permissionIds, $currentPermIds);
            $toRemove = array_diff($currentPermIds, $permissionIds);
            
            // Add new permissions
            foreach ($toAdd as $permId) {
                $rm->assignPermissionToRole($roleId, $permId);
            }
            
            // Remove permissions
            foreach ($toRemove as $permId) {
                $rm->removePermissionFromRole($roleId, $permId);
            }
        }
    }
    
    successAlert('সফল', 'অনুমতিসমূহ সফলভাবে বরাদ্দ করা হয়েছে');
    header('Location: /permissions/assign');
    exit;
});
/**
 * POST /permissions/assign/{roleId}
 * Save permission assignments per role (AJAX)
 */
$router->post('/permissions/assign/{roleId}', function ($roleId) use ($mysqli) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions');
    
    $rm = new RolesManager($mysqli);
    $pm = new PermissionsManager($mysqli);
    $user = (new AuthManager($mysqli))->getUserData(false);
    

    
    $roleId = (int)$roleId;
    $permissionIds = $_POST['role_permissions'] ?? [];
    $permissionIds = array_map('intval', (array)$permissionIds);

    // Get current permissions
    $currentPerms = $rm->getPermissionsByRole($roleId);
    $currentPermIds = array_column($currentPerms, 'id');
    
    // Find additions and removals
    $toAdd = array_diff($permissionIds, $currentPermIds);
    $toRemove = array_diff($currentPermIds, $permissionIds);
    
    // Add new permissions
    foreach ($toAdd as $permId) {
        $rm->assignPermissionToRole($roleId, $permId);
    }
    
    // Remove permissions
    foreach ($toRemove as $permId) {
        $rm->removePermissionFromRole($roleId, $permId);
    }
    
    echo json_encode(['success' => true, 'message' => 'Permissions saved successfully']);
    exit;
});
/**
 * GET /api/roles/{id}/permissions
 * Get permissions assigned to a role (JSON)
 */
$router->get('/api/roles/{id}/permissions', function($id) use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions', 'permissions');
    
    $rm = new RolesManager($mysqli);
    $perms = $rm->getPermissionsByRole((int)$id);
    
    echo json_encode([
        'success' => true,
        'permissions' => $perms,
        'count' => count($perms)
    ]);
});

/**
 * POST /api/roles/{id}/permissions/save
 * Save permissions for a role (JSON API)
 */
$router->post('/api/roles/{id}/permissions/save', function($id) use ($mysqli, $requireSuperadmin) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions', 'permissions');
    
    $roleId = (int)$id;
    $rm = new RolesManager($mysqli);
    
    // Get posted JSON data
    $data = json_decode(file_get_contents('php://input'), true);
    $permissionIds = $data['permission_ids'] ?? [];
    $permissionIds = array_map('intval', (array)$permissionIds);
    
    $currentPerms = $rm->getPermissionsByRole($roleId);
    $currentPermIds = array_column($currentPerms, 'id');
    
    $toAdd = array_diff($permissionIds, $currentPermIds);
    $toRemove = array_diff($currentPermIds, $permissionIds);
    
    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;
    
    foreach ($toAdd as $permId) {
        $rm->assignPermissionToRole($roleId, $permId);
    }
    
    foreach ($toRemove as $permId) {
        $rm->removePermissionFromRole($roleId, $permId);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Permissions updated successfully',
        'added' => count($toAdd),
        'removed' => count($toRemove)
    ]);
});


// =============================================================================
// PERMISSION REVOCATION
// =============================================================================

/**
 * GET /permissions/revoke/{roleId}/{permissionId}
 * Show revoke confirmation page
 */
$router->get('/permissions/revoke/{roleId}/{permissionId}', function($roleId, $permissionId) use ($mysqli, $twig) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions');
    
    $rm = new RolesManager($mysqli);
    $pm = new PermissionsManager($mysqli);
    
    $roleId = (int)$roleId;
    $permissionId = (int)$permissionId;
    
    $role = $rm->getRoleById($roleId);
    $permission = $pm->getPermissionById($permissionId);
    
    if (!$role || !$permission) {
        http_response_code(404);
        exit('ভূমিকা বা অনুমতি পাওয়া যায়নি');
    }
    
    echo $twig->render('permissions/revoke_permission.twig', [
        'role' => $role,
        'permission' => $permission,
        'pageTitle' => 'Revoke Permission',
        'title' => 'Revoke Permission',
        'header_title' => 'Revoke Permission'
    ]);
});

/**
 * POST /permissions/revoke/{roleId}/{permissionId}
 * Revoke permission from role
 */
$router->post('/permissions/revoke/{roleId}/{permissionId}', function($roleId, $permissionId) use ($mysqli) {
    ensure_role_level(ROLE_LEVEL_SUPERADMIN);
    ensure_can('manage_permissions');
    
    $roleId = (int)$roleId;
    $permissionId = (int)$permissionId;
    
    $rm = new RolesManager($mysqli);
    $rm->removePermissionFromRole($roleId, $permissionId);
    
    successAlert('সফল', 'অনুমতি সফলভাবে প্রত্যাহার করা হয়েছে');
    header('Location: /permissions/assign');
    exit;
});

/**
 * POST /api/permissions/{roleId}/revoke/{permissionId}
 * Revoke permission via AJAX
 */
$router->post('/api/permissions/{roleId}/revoke/{permissionId}', function($roleId, $permissionId) use ($mysqli) {
    header('Content-Type: application/json');
    ensure_can('manage_permissions');
    
    $roleId = (int)$roleId;
    $permissionId = (int)$permissionId;
    
    try {
        $rm = new RolesManager($mysqli);
        $result = $rm->removePermissionFromRole($roleId, $permissionId);
        
        echo json_encode([
            'success' => true,
            'message' => 'অনুমতি সফলভাবে প্রত্যাহার করা হয়েছে'
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'অনুমতি প্রত্যাহার করতে ব্যর্থ হয়েছে'
        ]);
    }
    exit;
});
?>