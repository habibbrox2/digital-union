<?php
// controllers/SettingController.php



global $router;
$unionModel = new UnionModel($mysqli);
$userModel = new UserModel($mysqli);
/*
|--------------------------------------------------------------------------
| GET : Main Settings Page
|--------------------------------------------------------------------------
*/
$router->get('/settings', function () use ($twig, $mysqli, $unionModel, $userModel) {

    ensure_admin_or_can('manage_settings');

    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;
    if (!$userId) renderError(403, "Unauthorized access!");


    $profileData = $userModel->getById($userId);

    // ---------- System Settings ----------
    $systemSettings = [];
    $result = $mysqli->query("SELECT setting_name, setting_value FROM system_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $systemSettings[$row['setting_name']] = $row['setting_value'];
        }
    }

    // ---------- Union Settings ----------
    $unionSettings = [];
    if (!empty($profileData['union_id'])) {
        $unionSettings = $unionModel->getById($profileData['union_id']) ?: [];
    }

    // Fetch all unions via model
    $unions = $unionModel->getAllUnions();

    echo $twig->render('settings/setting.twig', [
        'title'            => 'Settings',
        'system_settings'  => $systemSettings,
        'union_settings'   => $unionSettings,
        'unions'           => $unions,
        'header_title'     => 'System & Union Settings',
    ]);
});

/*
|--------------------------------------------------------------------------
| POST : Update System Settings
|--------------------------------------------------------------------------
*/
$router->post('/settings/system', function () use ($mysqli) {

    ensure_can('manage_settings');
    header('Content-Type: application/json');

    // CSRF is verified by middleware

    $settings = $_POST['settings'] ?? [];

    $mysqli->begin_transaction();
    try {

        // ---- Logo Upload ----
        if (!empty($_FILES['organization_logo']['name'])) {

            $uploadDir = __DIR__ . '/../public/uploads/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
            if (!in_array($_FILES['organization_logo']['type'], $allowed)) {
                throw new Exception('Invalid logo type');
            }

            $fileName = time() . '_' . basename($_FILES['organization_logo']['name']);
            $filePath = $uploadDir . $fileName;
            $dbPath   = '/uploads/logos/' . $fileName;

            if (!move_uploaded_file($_FILES['organization_logo']['tmp_name'], $filePath)) {
                throw new Exception('Logo upload failed');
            }

            $stmt = $mysqli->prepare("
                INSERT INTO system_settings (setting_name, setting_value)
                VALUES ('organization_logo', ?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
            ");
            $stmt->bind_param("s", $dbPath);
            $stmt->execute();
        }

        // ---- Text Settings ----
        foreach ($settings as $key => $value) {
            $key = sanitize_input($key);
            $value = sanitize_input($value);

            $stmt = $mysqli->prepare("
                INSERT INTO system_settings (setting_name, setting_value)
                VALUES (?,?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
            ");
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
        }

        $mysqli->commit();
        echo json_encode(['status'=>'success','alert'=>['type'=>'success','title'=>'সাফল্য','message'=>'সিস্টেম সেটিংকস সফলভাবে অনে হয়েছে']]);

    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>$e->getMessage()]]);
    }
});

/*
|--------------------------------------------------------------------------
| POST : Update Union Settings
|--------------------------------------------------------------------------
*/
$router->post('/settings/union', function () use ($mysqli) {

    ensure_can('manage_settings');
    header('Content-Type: application/json');

    // CSRF is verified by middleware

    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;
    if (!$userId) return json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'নিরাপত্তা','message'=>'অনুমতি প্রয়োজন']]);

    $userModel = new UserModel($mysqli);
    $profile = $userModel->getById($userId);
    $unionId = intval($_POST['union_id'] ?? $profile['union_id'] ?? 0);
    if (!$unionId) return json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>'ইউনিয়ন পাওয়া যায়নি']]);

    $data = array_map('sanitize_input', $_POST);
    $data['logo_url'] = '';

    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Invalid email']);
        return;
    }

    $mysqli->begin_transaction();
    try {

        // ---- Logo Upload ----
        if (!empty($_FILES['union_logo']['name'])) {
            $uploadDir = __DIR__ . '/../public/uploads/unions/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $safe = preg_replace('/[^a-zA-Z0-9_-]/','',$data['union_code'] ?? 'union');
            $fileName = $safe . $unionId . '_logo.png';

            if (!move_uploaded_file($_FILES['union_logo']['tmp_name'], $uploadDir.$fileName)) {
                throw new Exception('Union logo upload failed');
            }

            $data['logo_url'] = '/uploads/unions/' . $fileName;
        }

        $stmt = $mysqli->prepare("
            UPDATE unions SET
                union_name_en=?, union_name_bn=?,
                upazila_name_en=?, upazila_name_bn=?,
                district_name_en=?, district_name_bn=?,
                division_name_en=?, division_name_bn=?,
                division_id=?, district_id=?, upazila_id=?,
                ward_count=?, union_code=?, email=?, phone=?, website=?, postcode=?, logo_url=?
            WHERE union_id=?
        ");

        $stmt->bind_param(
            "ssssssssiiiissssssi",
            $data['union_name_en'],$data['union_name_bn'],
            $data['upazila_name_en'],$data['upazila_name_bn'],
            $data['district_name_en'],$data['district_name_bn'],
            $data['division_name_en'],$data['division_name_bn'],
            $data['division_id'],$data['district_id'],$data['upazila_id'],
            $data['ward_count'],$data['union_code'],
            $data['email'],$data['phone'],$data['website'],$data['postcode'],$data['logo_url'],
            $unionId
        );

        $stmt->execute();
        $mysqli->commit();

        echo json_encode(['status'=>'success','alert'=>['type'=>'success','title'=>'সাফল্য','message'=>'ইউনিয়ন সফলভাবে অনে হয়েছে']]);

    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>$e->getMessage()]]);
    }
});


$router->any('/settings/get_union_data', function() use ($mysqli, $unionModel) {

    header('Content-Type: application/json');

    // CSRF is verified by middleware

    $unionId = filter_input(INPUT_POST, 'union_id', FILTER_VALIDATE_INT);
    if (!$unionId) { echo json_encode(['status'=>'error','message'=>'Invalid Union ID']); exit; }

    $union = $unionModel->getById($unionId);
    if ($union) {
        echo json_encode(['status'=>'success','data'=>$union]);
    } else {
        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>'ইউনিয়ন পাওয়া যায়নি']]);
    }
    exit;

});



// ================= Security Settings =================

$router->get('/settings/security', function() use ($twig, $mysqli, $unionModel, $userModel) {

    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) renderError(403, "Unauthorized access!");



    $keys = ['password_policy','two_factor_enabled','session_timeout_minutes'];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    $types = str_repeat('s', count($keys));

    $query = "SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ($placeholders)";

    $stmt = $mysqli->prepare($query);

    $stmt->bind_param($types,...$keys);

    $stmt->execute();

    $result = $stmt->get_result();

    $securitySettings = [];

    if ($result) while($row = $result->fetch_assoc()) $securitySettings[$row['setting_name']] = $row['setting_value'];



    echo $twig->render('settings/security.twig',[
        'title'               => 'Security Settings',
        'security_settings'   => $securitySettings,
        'header_title'        => 'Security Settings'
    ]);

});



$router->post('/settings/security', function() use ($mysqli) {

    header('Content-Type: application/json');

    // CSRF is verified by middleware

    $settings = $_POST['settings'] ?? [];

    $allowedKeys = ['password_policy','two_factor_enabled','session_timeout_minutes'];

    $success = true;



    $mysqli->begin_transaction();

    try {

        foreach($settings as $key=>$value){

            if(!in_array($key,$allowedKeys)) continue;

            $key = sanitize_input($key);

            $value = sanitize_input($value);

            $stmt = $mysqli->prepare("

                INSERT INTO system_settings (setting_name, setting_value)

                VALUES (?,?)

                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)

            ");

            $stmt->bind_param("ss",$key,$value);

            if(!$stmt->execute()) throw new Exception('Failed to update security setting: '.$key);

        }

        $mysqli->commit();

        echo json_encode(['status'=>'success','alert'=>['type'=>'success','title'=>'সাফল্য','message'=>'নিরাপত্তা সেটিংস সফলভাবে আপডেট হয়েছে']]);

    } catch(Exception $e){

        $mysqli->rollback();

        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>$e->getMessage()]]);

    }

});



// ================= Notification Settings =================

$router->get('/settings/notifications', function() use ($twig, $mysqli, $unionModel, $userModel) {

    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) { echo "Unauthorized access!"; exit; }



    $keys = ['email_notifications_enabled','sms_notifications_enabled','push_notifications_enabled'];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    $types = str_repeat('s', count($keys));

    $stmt = $mysqli->prepare("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ($placeholders)");

    $stmt->bind_param($types,...$keys);

    $stmt->execute();

    $result = $stmt->get_result();

    $notificationSettings = [];

    if($result) while($row=$result->fetch_assoc()) $notificationSettings[$row['setting_name']]=$row['setting_value'];



    echo $twig->render('settings/notifications.twig',[
        'title'                   => 'Notification Settings',
        'notification_settings'   => $notificationSettings,
        'header_title'            => 'Notification Settings'
    ]);

});


// ================= Email Templates Settings =================
$router->get('/settings/email-templates', function() use ($twig, $mysqli, $unionModel, $userModel) {

    ensure_admin_or_can('manage_settings');

    // load existing templates from system_settings
    $keys = ['email_template_welcome','email_template_email_verification','email_template_password_reset','email_template_password_changed','email_template_failed_login_alert','email_template_new_device_login'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('s', count($keys));

    $stmt = $mysqli->prepare("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ($placeholders)");
    $stmt->bind_param($types, ...$keys);
    $stmt->execute();
    $res = $stmt->get_result();
    $systemSettings = [];
    if ($res) while($row = $res->fetch_assoc()) $systemSettings[$row['setting_name']] = $row['setting_value'];

    echo $twig->render('settings/email_templates.twig', [
        'title' => 'Email Templates',
        'system_settings' => $systemSettings,
        'header_title' => 'Email Templates',
        'csrf_token' => generateCsrfToken()
    ]);

});

$router->post('/settings/email-templates', function() use ($mysqli) {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json');

    $templates = $_POST['templates'] ?? [];

    $mysqli->begin_transaction();
    try {
        foreach ($templates as $name => $value) {
            $key = 'email_template_' . sanitize_input($name);
            $val = $value === null ? '' : $value;
            // allow HTML, but trim
            $val = trim($val);

            $stmt = $mysqli->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('ss', $key, $val);
            if (!$stmt->execute()) throw new Exception('Failed to save template: ' . $key);
        }
        $mysqli->commit();
        echo json_encode(['status'=>'success','alert'=>['type'=>'success','message'=>'Templates saved']]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['status'=>'error','alert'=>['type'=>'error','message'=>$e->getMessage()]]);
    }

});


// Preview stored or default template with sample data
$router->post('/settings/email-templates/preview', function() use ($mysqli) {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $name = sanitize_input($_POST['name'] ?? '');
    $allowed = ['welcome','email_verification','password_reset','password_changed','failed_login_alert','new_device_login'];
    if (!in_array($name, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invalid template name']);
        return;
    }

    // sample data for rendering
    $sample = [
        'username' => 'জন ডো',
        'email' => 'user@example.com',
        'verification_link' => (defined('SITE_URL') ? SITE_URL : '') . '/verify-email?token=sample',
        'reset_link' => (defined('SITE_URL') ? SITE_URL : '') . '/reset-password?token=sample',
        'expiry_hours' => 24,
        'failed_attempts' => 3,
        'attempted_at' => date('Y-m-d H:i:s'),
        'ip_address' => '203.0.113.5',
        'user_agent' => 'Mozilla/5.0 (Preview)',
        'login_at' => date('Y-m-d H:i:s'),
        'device' => 'Windows PC',
        'browser' => 'Chrome',
        'location' => 'Dhaka, Bangladesh',
        'mail_from_name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'লগ ধাকা',
        'site_url' => defined('SITE_URL') ? SITE_URL : ''
    ];

    try {
        // Try to load stored template
        $key = 'email_template_' . $name;
        $stmt = $mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $res = $stmt->get_result();
        $stored = '';
        if ($res && ($row = $res->fetch_assoc())) {
            $stored = $row['setting_value'] ?? '';
        }

        // Render using TwigManager if available
        if (!class_exists('TwigManager')) {
            // fallback: try global $twig if available
            global $twig;
            if (!isset($twig)) {
                throw new Exception('Twig not available');
            }
            if (!empty($stored)) {
                $html = $twig->createTemplate($stored)->render($sample);
            } else {
                $html = $twig->render('emails/' . $name . '.twig', $sample);
            }
        } else {
            $twigManager = new TwigManager($mysqli);
            $twigEngine = $twigManager->getTwig();
            if (!empty($stored)) {
                $html = $twigEngine->createTemplate($stored)->render($sample);
            } else {
                $html = $twigEngine->render('emails/' . $name . '.twig', $sample);
            }
        }

        echo json_encode(['success'=>true,'html'=>$html]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }

});



$router->post('/settings/notifications', function() use ($mysqli) {

    header('Content-Type: application/json');

    // CSRF is verified by middleware

    $settings = $_POST['settings'] ?? [];

    $allowedKeys = ['email_notifications_enabled','sms_notifications_enabled','push_notifications_enabled'];

    $success = true;



    $mysqli->begin_transaction();

    try {

        foreach($settings as $key=>$value){

            if(!in_array($key,$allowedKeys)) continue;

            $key = sanitize_input($key);

            $value = sanitize_input($value);

            $stmt = $mysqli->prepare("

                INSERT INTO system_settings (setting_name, setting_value)

                VALUES (?,?)

                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)

            ");

            $stmt->bind_param("ss",$key,$value);

            if(!$stmt->execute()) throw new Exception('Failed to update notification setting: '.$key);

        }

        $mysqli->commit();

        echo json_encode(['status'=>'success','alert'=>['type'=>'success','title'=>'সাফল্য','message'=>'বিজ্ঞপ্তি সেটিংস সফলভাবে আপডেট হয়েছে']]);

    } catch(Exception $e){

        $mysqli->rollback();

        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>$e->getMessage()]]);

    }

});

