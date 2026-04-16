<?php
// /config/functions.php



function sanitize_input($data = null) {
    if (!isset($data) || $data === null) {
        return null;
    }
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize_input($value);
        }
        return $data;
    }
    $data = trim((string)$data);
    if (preg_match('/^\\d{2}-\\d{2}-\\d{4}$/', $data)) {
        $date = DateTime::createFromFormat('d-m-Y', $data);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}



function Data($data) {
    if (!is_array($data)) {
        return [];
    }
    foreach ($data as $key => $value) {
        if (empty($value)) {
            $data[$key] = '';  
        }
    }
    return $data;
}

function convertToBangla($text) {
    if (is_null($text)) {
        return '';  
    }
    $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $banglaNumbers  = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
    $translations = [
        'Islam' => 'ইসলাম',
        'Hindu' => 'হিন্দু',
        'Hinduism' => 'হিন্দু',
        'Buddhist' => 'বৌদ্ধ',
        'Christian' => 'খ্রিস্টান',
        'Other' => 'অন্যান্য',
        'Male' => 'পুরুষ',
        'Female' => 'নারী',
        'Other' => 'অন্যান্য',
        'Single' => 'অবিবাহিত',
        'Married' => 'বিবাহিত',
        'Divorced' => 'তালাকপ্রাপ্ত',
        'Widowed' => 'বিধবা',
        'permanent' => 'স্থায়ী',
        'temporary' => 'অস্থায়ী'
    ];
    return $translations[$text] ?? str_replace($englishNumbers, $banglaNumbers, $text);
}

function convertToBanglaNumber($number) {
    if (is_null($number)) {
        return '';  
    }
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $bangla  = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
    return str_replace($english, $bangla, (string)$number);
}



/**
 * Generate breadcrumbs from current URL with Bengali translations
 * Automatically handles route structure and excludes admin segments
 */
function generateBreadcrumbs($currentUrl)
{
    // Query string বাদ
    $currentUrl = parse_url($currentUrl, PHP_URL_PATH);
    $segments = array_filter(explode('/', trim($currentUrl, '/')), 'strlen');
    
    if (empty($segments)) {
        return [];
    }

    // English to Bengali route translations
    $routeTranslations = [
        'dashboard' => 'ড্যাশবোর্ড',
        'profile' => 'আমার প্রোফাইল',
        'applications' => 'আবেদনসমূহ',
        'users' => 'ব্যবহারকারী',
        'roles' => 'ভূমিকা',
        'permissions' => 'অনুমতি',
        'unions' => 'ইউনিয়ন',
        'addresses' => 'ঠিকানা',
        'births' => 'জন্ম নিবন্ধন',
        'geo' => 'ভৌগোলিক তথ্য',
        'settings' => 'সেটিংস',
        'term-translations' => 'পদ অনুবাদ',
        'extra-fields' => 'অতিরিক্ত ক্ষেত্র',
        'add' => 'যোগ করুন',
        'edit' => 'সম্পাদনা',
        'view' => 'দেখুন',
        'admin' => 'প্রশাসনিক প্যানেল',
    ];

    // Segments that should not appear in breadcrumbs
    $excludedSegments = ['admin', 'api', 'tmp', 'tmp'];
    
    // Segments that indicate detail/edit pages (no URL for these)
    $lastSegmentSegments = ['edit', 'delete', 'view', 'preview'];

    $breadcrumbs = [];
    $path = '';

    // Always add home
    $breadcrumbs[] = [
        'name' => 'হোম',
        'url' => '/',
        'icon' => 'fas fa-home'
    ];

    $totalSegments = count($segments);
    
    foreach ($segments as $index => $segment) {
        // Skip excluded segments
        if (in_array($segment, $excludedSegments)) {
            continue;
        }

        // Skip numeric IDs and internal segments
        if (is_numeric($segment) || substr($segment, 0, 1) === '_') {
            continue;
        }

        $path .= '/' . $segment;
        
        // Get Bengali translation or default English version
        $name = $routeTranslations[$segment] ?? ucfirst(str_replace('-', ' ', $segment));
        
        // Check if this is the last segment
        $isLast = ($index === $totalSegments - 1);
        
        // Determine if this segment should have a URL
        $shouldHaveUrl = !in_array($segment, $lastSegmentSegments) && !$isLast;
        
        $breadcrumb = [
            'name' => $name,
            'is_active' => $isLast
        ];
        
        if ($shouldHaveUrl) {
            $breadcrumb['url'] = $path;
        }
        
        $breadcrumbs[] = $breadcrumb;
    }

    // If we only have home breadcrumb, return empty
    if (count($breadcrumbs) <= 1) {
        return [];
    }

    return $breadcrumbs;
}


    function getSettings() {
        global $mysqli;

        $settings = [];
        $systemQuery = "SELECT setting_name, setting_value FROM system_settings";
        $result = $mysqli->query($systemQuery);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_name']] = $row['setting_value'];
            }
        }

        return $settings;
    }






    function handleApplicantFileUpload($applicant_id)
    {
        // Absolute path to your public/uploads directory
        $publicRoot = dirname(__DIR__) . '/public'; // project root
        $photoUploadDir    = $publicRoot . '/uploads/application/';
        $documentUploadDir = $publicRoot . '/uploads/documents/';

        // Ensure upload directories exist
        foreach ([$photoUploadDir, $documentUploadDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true); // tighter perms than 0777
            }
        }

        $applicant_photo_path = '';

        // Detect photo input key
        $photoKey = isset($_FILES['applicant_photo']) ? 'applicant_photo' : 'photo';

        // Handle photo upload
        if (!empty($_FILES[$photoKey]['tmp_name'])) {
            $photo_extension = strtolower(pathinfo($_FILES[$photoKey]['name'], PATHINFO_EXTENSION));
            $allowed_photo_extensions = ['jpg', 'jpeg', 'png', 'webp', 'jfif'];

            if (in_array($photo_extension, $allowed_photo_extensions, true)) {
                $final_extension = ($photo_extension === 'jfif') ? 'jpg' : $photo_extension;
                $photoFilename = $applicant_id . '.' . $final_extension;
                $fullPath = $photoUploadDir . $photoFilename;

                if (move_uploaded_file($_FILES[$photoKey]['tmp_name'], $fullPath)) {
                    // Public URL path relative to web root
                    $applicant_photo_path = '/uploads/application/' . $photoFilename;
                }
            }
        }

        $documents_path = [];

        // Handle multiple document uploads
        if (!empty($_FILES['documents']['name'][0])) {
            $allowed_doc_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'jfif'];

            foreach ($_FILES['documents']['name'] as $key => $fileName) {
                $fileTmp = $_FILES['documents']['tmp_name'][$key];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (in_array($fileExtension, $allowed_doc_extensions, true)) {
                    $finalDocExtension = ($fileExtension === 'jfif') ? 'jpg' : $fileExtension;
                    $docFilename = $applicant_id . '_doc' . ($key + 1) . '.' . $finalDocExtension;
                    $fullDocPath = $documentUploadDir . $docFilename;

                    if (move_uploaded_file($fileTmp, $fullDocPath)) {
                        $documents_path[] = '/uploads/documents/' . $docFilename;
                    }
                }
            }
        }

        return [
            'photo'          => $applicant_photo_path,
            'documents'      => $documents_path,
            'documents_json' => json_encode($documents_path, JSON_UNESCAPED_SLASHES)
        ];
    }







    function getFee($feeName) {
        global $mysqli;
        
        $stmt = $mysqli->prepare("SELECT fee_value FROM fee_manage WHERE fee_name = ?");
        $stmt->bind_param("s", $feeName);
        $stmt->execute();
        $stmt->bind_result($feeValue);
        $stmt->fetch();
        $stmt->close();
        
        if ($feeValue) {
            return $feeValue;
        } else {
            return null;  
        }
    }










    function showMessage($message, $type = 'success') {
        echo "
            <div class='toast-container position-fixed top-0 end-0 p-3' style='z-index: 1050;'>
                <div class='toast' role='alert' aria-live='assertive' aria-atomic='true'>
                    <div class='toast-header'>
                        <strong class='me-auto'>Notification</strong>
                        <button type='button' class='btn-close' data-bs-dismiss='toast' aria-label='Close'></button>
                    </div>
                    <div class='toast-body alert alert-{$type}' role='alert'>
                        {$message}
                    </div>
                </div>
            </div>
            <script>
                var toastEl = document.querySelector('.toast');
                var toast = new bootstrap.Toast(toastEl, {
                    autohide: true,
                    delay: 3000 // Time in milliseconds before auto-close (3 seconds)
                });
                toast.show();
            </script>
        ";
    }
// Reusable helper to get CryptManager instance
if (!function_exists('get_crypt_manager')) {
    function get_crypt_manager() {

        if (!defined('ENCRYPTION_KEY') || !defined('ENCRYPTION_METHOD')) {
        }
        return new CryptManager(ENCRYPTION_KEY, ENCRYPTION_METHOD);
    }
}


/**
 * Ensure current user is superadmin or has given permission
 * Throws or exits with 403 if not allowed
 */
function ensure_admin_or_can(string $permission) {
    global $mysqli;
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        renderError(403, 'Unauthorized access!');
        exit;
    }

    // Quick allow for super admin role flag in session
    if (!empty($_SESSION['is_superadmin'])) return true;

    // Use RolesManager / PermissionsManager for role & permission checks
    require_once __DIR__ . '/../classes/RolesManager.php';
    require_once __DIR__ . '/../classes/PermissionsManager.php';

    // Quick allow for super admin role flag in users table
    $stmt = $mysqli->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($row['role_id']) && $row['role_id'] <= 1) return true;

    $permissionsManager = new PermissionsManager($mysqli);
    if ($permissionsManager->hasPermission($userId, $permission)) return true;

    // Otherwise use ensure_can as a fallback if present
    if (function_exists('ensure_can')) {
        try {
            ensure_can($permission);
            return true;
        } catch (Throwable $e) {
            renderError(403, 'Unauthorized access!');
            exit;
        }
    }

    renderError(403, 'Unauthorized access!');
    exit;
}



/**
 * Extract birth year from birth_date (YYYY or YYYY-MM-DD or YYYY/MM/DD)
 */
function extract_birth_year($birth_date) {
    if (empty($birth_date)) return null;

    if (preg_match('/^(\d{4})[-\/]?\d{0,2}[-\/]?\d{0,2}$/', $birth_date, $matches)) {
        return $matches[1];
    }
    return null;
}


/**
 * Get validated 7-digit union code
 */
function getUnionCode($union_code) {
    if (empty($union_code) || !ctype_digit($union_code)) {
        throw new InvalidArgumentException("Union code must be numeric.");
    }
    return str_pad(substr($union_code, 0, 7), 7, '0', STR_PAD_RIGHT);
}


/**
 * Fetch union row by union_code
 */
function getUnionByCode($union_Code) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_code = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed: " . $mysqli->error);
    }
    $stmt->bind_param("s", $union_Code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}



/**
 * Generate next unique sequential number (atomic & transaction-safe)
 */
function getSequentialNumber($tablename, $column, $prefix, $seqLength = 6, $union_code = null, $maxAttempts = 1000000) {
    global $mysqli;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tablename)) {
        throw new InvalidArgumentException("Invalid table name.");
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        throw new InvalidArgumentException("Invalid column name.");
    }

    $union_id = null;
    if (!empty($union_code)) {
        $unionData = getUnionByCode($union_code);
        if ($unionData && isset($unionData['union_id'])) {
            $union_id = (int)$unionData['union_id'];
        }
    }

    // Transaction শুরু
    $mysqli->begin_transaction();

    try {
        // SELECT ... FOR UPDATE → একই প্রিফিক্সের শেষ রো লক করে
        $sql = "SELECT $column FROM `$tablename` WHERE $column LIKE CONCAT(?, '%')";
        if ($union_id) {
            $sql .= " AND union_id = ?";
        }
        $sql .= " ORDER BY application_id DESC LIMIT 1 FOR UPDATE";

        $stmt = $mysqli->prepare($sql);
        if ($union_id) $stmt->bind_param('si', $prefix, $union_id);
        else $stmt->bind_param('s', $prefix);
        $stmt->execute();
        $stmt->bind_result($lastValue);
        $stmt->fetch();
        $stmt->close();

        $nextSeq = ($lastValue)
            ? (int)substr($lastValue, strlen($prefix)) + 1
            : 1;

        // ডুপ্লিকেট চেক
        $checkSql = "SELECT COUNT(*) FROM `$tablename` WHERE $column = ?";
        $checkStmt = $mysqli->prepare($checkSql);

        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            $candidateSeq = str_pad($nextSeq, $seqLength, '0', STR_PAD_LEFT);
            $candidateFull = $prefix . $candidateSeq;

            $checkStmt->bind_param('s', $candidateFull);
            $checkStmt->execute();
            $checkStmt->bind_result($count);
            $checkStmt->fetch();
            $checkStmt->reset();

            if ($count == 0) {
                $checkStmt->close();
                $mysqli->commit();
                return $candidateSeq;
            }
            $nextSeq++;
        }

        $checkStmt->close();
        $mysqli->rollback();
        throw new RuntimeException("Unique sequential number not found after $maxAttempts attempts.");

    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
}


/**
 * Generate Tracking Number
 * Format: YYMMDD + union_code(7) + seq(6) = 17 digits
 */
/**
 * Generate Tracking Number
 * Format: YYMMDD (6) + union_code (7) + seq (4) = 17 digits
 */
function generateTrackingNumber($union_code) {
    // 1. ইউনিয়ন কোড বৈধ করা হচ্ছে
    $union_code = getUnionCode($union_code);
    
    // 2. প্রিফিক্স তৈরি করা হচ্ছে: YYMMDD + union_code = 13 digits
    // (এখানে date('ymd') ধরে নেওয়া হচ্ছে যে আপনি 2-digit বছর ব্যবহার করছেন)
    $prefix = date('ymd') . $union_code;
    
    // 3. সিকোয়েন্স নম্বর তৈরি করা হচ্ছে
    // 17 - 13 = 4.  মোট দৈর্ঘ্য 17 রাখতে হলে seq-এর দৈর্ঘ্য 4 হতে হবে।
    // ডুপ্লিকেট সমস্যা এড়াতে seqLength 6 থেকে 4-এ আপডেট করা হলো।
    $seqLength = 4; // 17 - (6 + 7) = 4
    
    // ট্রানজেকশন-সেফ সিকোয়েন্স নম্বর
    $seq = getSequentialNumber('applications', 'application_id', $prefix, $seqLength, $union_code);
    
    // 4. ট্র্যাকিং নম্বর তৈরি
    // এখন $prefix-এর দৈর্ঘ্য 13 এবং $seq-এর দৈর্ঘ্য 4। 
    // যোগ করলে মোট দৈর্ঘ্য হবে 17, তাই আর substr() ব্যবহার করার প্রয়োজন নেই।
    return $prefix . $seq;
}


/**
 * Generate Applicant ID
 * Format:
 * - If $birth_date exists: birth_year(4) + union_code(7) + seq(6)
 * - Otherwise: current_year(4) + union_code(7) + seq(6)
 */
function generateApplicantId($nid = null, $birth_id = null, $passport_no = null, $union_code = null, $birth_date = null) {
    global $mysqli;

    // Check if applicant already exists
    $sql = "SELECT applicant_id FROM applications 
            WHERE (nid = ? AND nid != '') 
               OR (birth_id = ? AND birth_id != '') 
               OR (passport_no = ? AND passport_no != '') 
            LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("sss", $nid, $birth_id, $passport_no);
    $stmt->execute();
    $stmt->bind_result($existing);
    $stmt->fetch();
    $stmt->close();

    if (!empty($existing)) return $existing;

    // ✅ Determine prefix year
    $birthYear = extract_birth_year($birth_date);
    $year = !empty($birthYear) ? $birthYear : date('Y');

    // ✅ Generate rest as usual
    $union_code = getUnionCode($union_code);
    $prefix = $year . $union_code;

    $seq = getSequentialNumber('applications', 'applicant_id', $prefix, 6, $union_code);
    return substr($prefix . $seq, 0, 17);
}


/**
 * Generate Sonod Number
 * Format: YY + union_code(7) + seq(8) = 17 digits
 */
function generateSonodNumber($tablename, $union_code) {
    $union_code = getUnionCode($union_code);
    $prefix = date('y') . $union_code;
    $seq = getSequentialNumber($tablename, 'sonod_number', $prefix, 8, $union_code);
    return substr($prefix . $seq, 0, 17);
}
