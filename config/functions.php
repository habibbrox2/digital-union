<?php
// /config/functions.php
// Thin wrapper functions — all delegate to dedicated service classes in modules/Services/

// Ensure all service classes are available
require_once __DIR__ . '/../modules/Services/SanitizationService.php';
require_once __DIR__ . '/../modules/Services/LocalizationService.php';
require_once __DIR__ . '/../modules/Services/NumberGeneratorService.php';
require_once __DIR__ . '/../modules/Services/FileUploadService.php';
require_once __DIR__ . '/../modules/Services/FeeService.php';
require_once __DIR__ . '/../modules/Services/UnionService.php';
require_once __DIR__ . '/../modules/Services/SettingService.php';
require_once __DIR__ . '/../modules/Services/AuthService.php';

if (!function_exists('sanitize_input')) {
    function sanitize_input($data = null) {
        static $service = null;
        if ($service === null) $service = new SanitizationService();
        return $service->sanitizeInput($data);
    }
}

if (!function_exists('Data')) {
    function Data($data) {
        static $service = null;
        if ($service === null) $service = new SanitizationService();
        return $service->cleanData($data);
    }
}

if (!function_exists('convertToBangla')) {
    function convertToBangla($text) {
        static $service = null;
        if ($service === null) $service = new LocalizationService();
        return $service->convertToBangla($text);
    }
}

if (!function_exists('convertToBanglaNumber')) {
    function convertToBanglaNumber($number) {
        static $service = null;
        if ($service === null) $service = new LocalizationService();
        return $service->convertToBanglaNumber($number);
    }
}

if (!function_exists('generateBreadcrumbs')) {
    function generateBreadcrumbs($currentUrl) {
        static $service = null;
        if ($service === null) $service = new LocalizationService();
        return $service->generateBreadcrumbs($currentUrl);
    }
}

if (!function_exists('getSettings')) {
    function getSettings() {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new SettingService($mysqli);
        return $service->getSettings();
    }
}

if (!function_exists('handleApplicantFileUpload')) {
    function handleApplicantFileUpload($applicant_id) {
        static $service = null;
        if ($service === null) $service = new FileUploadService();
        return $service->handleUpload($applicant_id);
    }
}

if (!function_exists('getFee')) {
    function getFee($feeName) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new FeeService($mysqli);
        return $service->getFee($feeName);
    }
}

if (!function_exists('showMessage')) {
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
                    delay: 3000
                });
                toast.show();
            </script>
        ";
    }
}

if (!function_exists('get_crypt_manager')) {
    function get_crypt_manager() {
        if (!defined('ENCRYPTION_KEY') || !defined('ENCRYPTION_METHOD')) {
        }
        return new CryptManager(ENCRYPTION_KEY, ENCRYPTION_METHOD);
    }
}

if (!function_exists('extract_birth_year')) {
    function extract_birth_year($birth_date) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new NumberGeneratorService($mysqli);
        return $service->extractBirthYear($birth_date);
    }
}

if (!function_exists('getUnionCode')) {
    function getUnionCode($union_code) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new UnionService($mysqli);
        return $service->getUnionCode($union_code);
    }
}

if (!function_exists('getUnionByCode')) {
    function getUnionByCode($union_Code) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new UnionService($mysqli);
        return $service->getUnionByCode($union_Code);
    }
}

if (!function_exists('getSequentialNumber')) {
    function getSequentialNumber($tablename, $column, $prefix, $seqLength = 6, $union_code = null, $maxAttempts = 1000000) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new NumberGeneratorService($mysqli);
        return $service->getSequentialNumber($tablename, $column, $prefix, $seqLength, $union_code, $maxAttempts);
    }
}

if (!function_exists('generateTrackingNumber')) {
    function generateTrackingNumber($union_code) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new NumberGeneratorService($mysqli);
        return $service->generateTrackingNumber($union_code);
    }
}

if (!function_exists('generateApplicantId')) {
    function generateApplicantId($nid = null, $birth_id = null, $passport_no = null, $union_code = null, $birth_date = null) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new NumberGeneratorService($mysqli);
        return $service->generateApplicantId($nid, $birth_id, $passport_no, $union_code, $birth_date);
    }
}

if (!function_exists('generateSonodNumber')) {
    function generateSonodNumber($tablename, $union_code) {
        global $mysqli;
        static $service = null;
        if ($service === null) $service = new NumberGeneratorService($mysqli);
        return $service->generateSonodNumber($tablename, $union_code);
    }
}
