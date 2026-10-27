<?php
require_once __DIR__ . '/../vendor/autoload.php';

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_DIR', BASE_PATH . '/storage');
define('CACHE_DIR', STORAGE_DIR . '/cache');
define('TEMP_DIR', STORAGE_DIR . '/tmp');

if (!is_dir(STORAGE_DIR)) mkdir(STORAGE_DIR, 0755, true);
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0755, true);

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'lgdhaka.test';

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../app/Helpers/sweetalertHelper.php';
require_once __DIR__ . '/../app/Helpers/security.php';
require_once __DIR__ . '/../app/Helpers/email_helper.php';
require_once __DIR__ . '/../app/Helpers/validator.php';
require_once __DIR__ . '/../app/Models/PermissionsManager.php';
require_once __DIR__ . '/../app/Models/RolesManager.php';
require_once __DIR__ . '/../app/Models/AuthManager.php';
require_once __DIR__ . '/../app/Services/LoginService.php';
