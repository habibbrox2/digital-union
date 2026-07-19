<?php
/**
 * modules/Services/SettingService.php
 * 
 * Service layer for system and union settings management.
 */

class SettingService
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Get all system settings as key-value pairs
     */
    public function getSystemSettings(): array
    {
        $settings = [];
        $result = $this->mysqli->query("SELECT setting_name, setting_value FROM system_settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_name']] = $row['setting_value'];
            }
        }
        return $settings;
    }

    /**
     * Alias for getSystemSettings() — matches legacy getSettings() signature
     */
    public function getSettings(): array
    {
        return $this->getSystemSettings();
    }

    /**
     * Get specific system settings by keys
     */
    public function getSystemSettingsByKeys(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types = str_repeat('s', count($keys));

        $stmt = $this->mysqli->prepare(
            "SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ($placeholders)"
        );
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $result = $stmt->get_result();

        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_name']] = $row['setting_value'];
        }
        $stmt->close();

        return $settings;
    }

    /**
     * Update system settings (text values)
     */
    public function updateSystemSettings(array $settings): array
    {
        try {
            $this->mysqli->begin_transaction();

            foreach ($settings as $key => $value) {
                $key = sanitize_input($key);
                // Sanitize URL values for known URL fields
                if (in_array($key, ['website_url', 'organization_logo'], true)) {
                    $value = $this->sanitizeUrl($value);
                } else {
                    $value = sanitize_input($value);
                }

                $stmt = $this->mysqli->prepare("
                    INSERT INTO system_settings (setting_name, setting_value)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
                ");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
            }

            $this->mysqli->commit();
            return ['status' => 'success', 'alert' => ['type' => 'success', 'title' => 'সাফল্য', 'message' => 'সিস্টেম সেটিংস সফলভাবে আপডেট হয়েছে']];
        } catch (Exception $e) {
            $this->mysqli->rollback();
            return ['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => $e->getMessage()]];
        }
    }

    /**
     * Upload organization logo
     */
    public function uploadOrganizationLogo(array $file): array
    {
        $uploadDir = __DIR__ . '/../../public/uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed)) {
            return ['status' => 'error', 'message' => 'Invalid logo type'];
        }

        $fileName = time() . '_' . basename($file['name']);
        $filePath = $uploadDir . $fileName;
        $dbPath = '/uploads/logos/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['status' => 'error', 'message' => 'Logo upload failed'];
        }

        $stmt = $this->mysqli->prepare("
            INSERT INTO system_settings (setting_name, setting_value)
            VALUES ('organization_logo', ?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
        ");
        $stmt->bind_param("s", $dbPath);
        $stmt->execute();

        return ['status' => 'success', 'path' => $dbPath];
    }

    /**
     * Upload union logo
     */
    public function uploadUnionLogo(array $file, string $unionCode, int $unionId): array
    {
        $uploadDir = __DIR__ . '/../../public/uploads/unions/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $unionCode);
        $fileName = $safe . $unionId . '_logo.png';

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            return ['status' => 'error', 'message' => 'Union logo upload failed'];
        }

        return ['status' => 'success', 'path' => '/uploads/unions/' . $fileName];
    }

    /**
     * Upload union stamp logo
     */
    public function uploadUnionStampLogo(array $file, string $unionCode, int $unionId): array
    {
        $uploadDir = __DIR__ . '/../../public/uploads/unions/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $unionCode);
        $fileName = $safe . $unionId . '_stamp.png';

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            return ['status' => 'error', 'message' => 'Union stamp logo upload failed'];
        }

        return ['status' => 'success', 'path' => '/uploads/unions/' . $fileName];
    }

    /**
     * Sanitize a URL: strip dangerous URL schemes (javascript:, data:, vbscript:)
     * and only allow http, https, ftp, ftps, or empty/relative URLs.
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        if ($scheme !== '' && !in_array(strtolower($scheme), ['http', 'https', 'ftp', 'ftps'], true)) {
            return ''; // Strip dangerous schemes entirely
        }
        // Strip any null bytes or control characters
        return str_replace(["\0", "\r", "\n"], '', $url);
    }

    /**
     * Update union settings
     */
    public function updateUnionSettings(int $unionId, array $data): array
    {
        try {
            $this->mysqli->begin_transaction();

            $stmt = $this->mysqli->prepare("
                UPDATE unions SET
                    union_name_en=?, union_name_bn=?,
                    upazila_name_en=?, upazila_name_bn=?,
                    district_name_en=?, district_name_bn=?,
                    division_name_en=?, division_name_bn=?,
                    division_id=?, district_id=?, upazila_id=?,
                    ward_count=?, union_code=?, email=?, phone=?, website=?, postcode=?, 
                    logo_url=?, stamp_logo_url=?
                WHERE union_id=?
            ");

            // Assign to local variables first — bind_param requires pass-by-reference
            $unionNameEn   = $data['union_name_en'] ?? '';
            $unionNameBn   = $data['union_name_bn'] ?? '';
            $upazilaNameEn = $data['upazila_name_en'] ?? '';
            $upazilaNameBn = $data['upazila_name_bn'] ?? '';
            $districtNameEn = $data['district_name_en'] ?? '';
            $districtNameBn = $data['district_name_bn'] ?? '';
            $divisionNameEn = $data['division_name_en'] ?? '';
            $divisionNameBn = $data['division_name_bn'] ?? '';
            $divisionId  = (int)($data['division_id'] ?? 0);
            $districtId  = (int)($data['district_id'] ?? 0);
            $upazilaId   = (int)($data['upazila_id'] ?? 0);
            $wardCount   = (int)($data['ward_count'] ?? 0);
            $unionCode   = $data['union_code'] ?? '';
            $email       = $data['email'] ?? '';
            $phone       = $data['phone'] ?? '';
            // Sanitize website URL: strip dangerous URL schemes
            $website = $data['website'] ?? '';
            $website = $this->sanitizeUrl($website);
            $postcode    = $data['postcode'] ?? '';
            $logoUrl     = $data['logo_url'] ?? '';
            $stampLogoUrl = $data['stamp_logo_url'] ?? '';

            $stmt->bind_param(
                "ssssssssiiiisssssssi",
                $unionNameEn, $unionNameBn,
                $upazilaNameEn, $upazilaNameBn,
                $districtNameEn, $districtNameBn,
                $divisionNameEn, $divisionNameBn,
                $divisionId, $districtId, $upazilaId,
                $wardCount, $unionCode,
                $email, $phone, $website, $postcode,
                $logoUrl, $stampLogoUrl,
                $unionId
            );

            $stmt->execute();
            $this->mysqli->commit();

            return ['status' => 'success', 'alert' => ['type' => 'success', 'title' => 'সাফল্য', 'message' => 'ইউনিয়ন সফলভাবে আপডেট হয়েছে']];
        } catch (Exception $e) {
            $this->mysqli->rollback();
            return ['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => $e->getMessage()]];
        }
    }

    /**
     * Update security settings
     */
    public function updateSecuritySettings(array $settings): array
    {
        $allowedKeys = ['password_policy', 'two_factor_enabled', 'session_timeout_minutes'];

        try {
            $this->mysqli->begin_transaction();

            foreach ($settings as $key => $value) {
                if (!in_array($key, $allowedKeys)) continue;
                $key = sanitize_input($key);
                $value = sanitize_input($value);

                $stmt = $this->mysqli->prepare("
                    INSERT INTO system_settings (setting_name, setting_value)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
                ");
                $stmt->bind_param("ss", $key, $value);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update security setting: ' . $key);
                }
            }

            $this->mysqli->commit();
            return ['status' => 'success', 'alert' => ['type' => 'success', 'title' => 'সাফল্য', 'message' => 'নিরাপত্তা সেটিংস সফলভাবে আপডেট হয়েছে']];
        } catch (Exception $e) {
            $this->mysqli->rollback();
            return ['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => $e->getMessage()]];
        }
    }

    /**
     * Save email templates
     */
    public function saveEmailTemplates(array $templates): array
    {
        try {
            $this->mysqli->begin_transaction();

            foreach ($templates as $name => $value) {
                $key = 'email_template_' . sanitize_input($name);
                $val = $value === null ? '' : trim($value);

                $stmt = $this->mysqli->prepare("
                    INSERT INTO system_settings (setting_name, setting_value)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
                ");
                $stmt->bind_param('ss', $key, $val);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save template: ' . $key);
                }
            }

            $this->mysqli->commit();
            return ['status' => 'success', 'alert' => ['type' => 'success', 'message' => 'Templates saved']];
        } catch (Exception $e) {
            $this->mysqli->rollback();
            return ['status' => 'error', 'alert' => ['type' => 'error', 'message' => $e->getMessage()]];
        }
    }

    /**
     * Preview an email template with sample data
     */
    public function previewEmailTemplate(string $name): array
    {
        $allowed = ['welcome', 'email_verification', 'password_reset', 'password_changed', 'failed_login_alert', 'new_device_login'];
        if (!in_array($name, $allowed, true)) {
            return ['success' => false, 'message' => 'Invalid template name'];
        }

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
            require_once __DIR__ . '/../../helpers/email_helper.php';
            $key = 'email_template_' . $name;

            // Try to load stored template
            $stmt = $this->mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ?");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $body = '';
            if ($row && !empty($row['setting_value'])) {
                $body = $row['setting_value'];
            } else {
                // Use default template
                $templateFile = __DIR__ . '/../../templates/emails/' . $name . '.twig';
                if (file_exists($templateFile)) {
                    $body = file_get_contents($templateFile);
                } else {
                    return ['success' => false, 'message' => 'Template not found'];
                }
            }

            // Simple variable replacement
            foreach ($sample as $key => $value) {
                $body = str_replace('{{ ' . $key . ' }}', $value, $body);
                $body = str_replace('{{' . $key . '}}', $value, $body);
            }

            return ['success' => true, 'subject' => 'Sample: ' . ucfirst(str_replace('_', ' ', $name)), 'body' => $body];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
