<?php

/**
 * modules/Services/ApplicationService.php
 * 
 * Service layer for certificate application business logic.
 * Handles common orchestration patterns: union lookups, data preparation,
 * template resolution, PDF generation, and certificate verification.
 * 
 * Database CRUD is delegated to ApplicationManager (in classes/).
 */

// Ensure helper functions are available
require_once __DIR__ . '/../../helpers/application_helpers.php';
require_once __DIR__ . '/../../helpers/pdfGenarate.php';
require_once __DIR__ . '/../../models/AuthManager.php';
require_once __DIR__ . '/../../models/BusinessOwnershipType.php';

class ApplicationService
{
    private mysqli $mysqli;
    private ApplicationManager $appManager;
    private ?UnionModel $unionModel = null;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->appManager = new ApplicationManager($mysqli);
    }

    public function getAppManager(): ApplicationManager
    {
        return $this->appManager;
    }

    // ================================================================
    // UNION HELPERS
    // ================================================================

    /**
     * Fetch union by ID (uses UnionModel to avoid duplicating SQL)
     */
    public function getUnionById(int $unionId): ?array
    {
        if ($this->unionModel === null) {
            $this->unionModel = new UnionModel($this->mysqli);
        }
        return $this->unionModel->getById($unionId);
    }

    /**
     * Get all unions (delegates to UnionModel)
     */
    public function getAllUnions(): array
    {
        if ($this->unionModel === null) {
            $this->unionModel = new UnionModel($this->mysqli);
        }
        return $this->unionModel->getAllUnions();
    }

    /**
     * Get union info (code + data)
     */
    public function getUnionInfo(int $unionId): array
    {
        if ($this->unionModel === null) {
            $this->unionModel = new UnionModel($this->mysqli);
        }
        return $this->unionModel->getInfo($unionId);
    }

    // ================================================================
    // APPLICATION DATA PREPARATION
    // ================================================================

    /**
     * Decode extra_data JSON string to array
     */
    public function decodeExtraData(?string $extraData): array
    {
        if (empty($extraData)) {
            return [];
        }
        $decoded = json_decode($extraData, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Parse documents JSON
     */
    public function parseDocuments(?string $documentsJson): array
    {
        if (empty($documentsJson)) {
            return [];
        }
        $decoded = json_decode($documentsJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Prepare full application data with related entities
     * Attaches members, business_meta, approval, and decoded extra_data
     */
    public function prepareApplicationData(array $application): array
    {
        $appId = $application['application_id'] ?? null;

        // Decode extra_data
        if (!empty($application['extra_data']) && is_string($application['extra_data'])) {
            $decoded = $this->decodeExtraData($application['extra_data']);
            if (!empty($decoded)) {
                $application['extra_data'] = $decoded;
                $application['extra'] = $decoded;
            }
        }

        // Attach related entities
        if (!empty($appId)) {
            $certType = $application['certificate_type'] ?? '';

            if (in_array($certType, ['warish', 'family'], true)) {
                $application['warish_members'] = $this->appManager->getMembersByApplication($appId);
            }

            if ($certType === 'trade') {
                $application['business_meta'] = $this->appManager->getBusinessMetaByApplicationId($appId);
            }

            $application['approval'] = $this->appManager->getApprovalByApplicationId($appId);
        }

        return $application;
    }

    /**
     * Resolve template path
     */
    public function resolveTemplate(string $baseDir, ?string $certificateType, string $default = 'default.twig'): string
    {
        return templatePath($baseDir, $certificateType ?? '', $default);
    }

    // ================================================================
    // CERTIFICATE VIEW DATA
    // ================================================================

    /**
     * Build common certificate view data from application
     */
    public function buildCertificateViewData(array $application, ?array $union = null, array $extras = []): array
    {
        $certType = $application['certificate_type'] ?? '';
        $data = [
            'title'            => $extras['title'] ?? 'Certificate',
            'header_title'     => $extras['header_title'] ?? 'Certificate',
            'data'             => Data($application),
            'detail'           => Data($application),
            'citizen'          => Data($application),
            'union'            => $union,
            'certificate_type' => $extras['certificate_type'] ?? $certType,
            'approval'         => $application['approval'] ?? null,
            'business_meta'    => $application['business_meta'] ?? null,
        ];

        foreach (['certificate_type_bn', 'certificate_type_en', 'union_code', 'rmo_code', 'documents'] as $key) {
            if (isset($extras[$key])) {
                $data[$key] = $extras[$key];
            }
        }

        return $data;
    }

    // ================================================================
    // PDF GENERATION
    // ================================================================

    public function generateCertificatePdf(string $htmlContent, string $filename): void
    {
        generatePdf($htmlContent, $filename);
    }

    public function makeCertificatePdf(string $htmlContent, string $filename): void
    {
        makePdf($htmlContent, $filename);
    }

    // ================================================================
    // COMMON INLINE SQL LOOKUPS (replaces repeated $mysqli->prepare in controllers)
    // ================================================================

    /**
     * Get union name_bn by union ID
     */
    public function getUnionNameById(int $unionId): string
    {
        $stmt = $this->mysqli->prepare("SELECT union_name_bn FROM unions WHERE union_id = ? LIMIT 1");
        if (!$stmt) return '';
        $stmt->bind_param("i", $unionId);
        $stmt->execute();
        $stmt->bind_result($name);
        $stmt->fetch();
        $stmt->close();
        return $name ?? '';
    }

    /**
     * Get certificate type Bengali name from term_translations
     */
    public function getCertificateTypeName(string $slug): string
    {
        $stmt = $this->mysqli->prepare("SELECT name_bn FROM term_translations WHERE slug = ? AND is_certificate_type = 1 LIMIT 1");
        if (!$stmt) return $slug;
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $stmt->bind_result($name);
        $stmt->fetch();
        $stmt->close();
        return $name ?? $slug;
    }

    /**
     * Get business type fees by ID
     */
    public function getBusinessTypeFees(int $businessTypeId): ?array
    {
        $stmt = $this->mysqli->prepare("SELECT license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge FROM business_type WHERE id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $businessTypeId);
        $stmt->execute();
        $fees = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fees ?: null;
    }

    /**
     * Get union members grouped by role for approval page
     */
    public function getUnionMembersForApproval(int $unionId): array
    {
        $stmt = $this->mysqli->prepare("
            SELECT 
                u.user_id,
                u.name_bn,
                u.name_en,
                u.role_id,
                u.phone_number AS phone,
                u.email,
                u.ward_no,
                r.role_id AS id,
                r.role_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.union_id = ? 
            AND u.is_deleted = 0
            AND u.name_bn IS NOT NULL 
            AND u.name_bn != ''
            ORDER BY 
                CASE 
                    WHEN u.role_id = 1 THEN 1
                    WHEN u.role_id = 2 THEN 2
                    WHEN u.role_id = 3 THEN 3
                    WHEN u.role_id = 4 THEN 4
                    WHEN u.role_id = 5 THEN 5
                    WHEN u.role_id = 6 THEN 6
                    WHEN u.role_id = 7 THEN 7
                    ELSE 99
                END,
                u.name_bn ASC
        ");

        $stmt->bind_param("i", $unionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $roleNamesBn = [
            1 => 'অ্যাডমিনিস্ট্রেটর',
            2 => 'সচিব',
            3 => 'চেয়ারম্যান',
            4 => 'মেম্বার',
            5 => 'কম্পিউটার অপারেটর',
            6 => 'গ্রাম পুলিশ',
            7 => 'অফিস সহকারী',
        ];

        $unionMembers = [];
        while ($row = $result->fetch_assoc()) {
            $roleId = (int)$row['role_id'];

            if (!isset($unionMembers[$roleId])) {
                $unionMembers[$roleId] = [
                    'role_name' => $roleNamesBn[$roleId] ?? $row['role_name'] ?? 'Unknown Role',
                    'role_id' => $roleId,
                    'members' => []
                ];
            }

            $unionMembers[$roleId]['members'][] = [
                'user_id' => $row['user_id'] ?? 0,
                'name_bn' => $row['name_bn'] ?? '',
                'name_en' => $row['name_en'] ?? '',
                'phone' => $row['phone'] ?? '',
                'email' => $row['email'] ?? '',
                'ward_no' => $row['ward_no'] ?? ''
            ];
        }

        return $unionMembers;
    }

    // ================================================================
    // LOOKUP
    // ================================================================

    /**
     * Find application by sonod number with full preparation
     */
    public function getApplicationBySonodNumber(string $sonodNumber, ?string $certificateType): ?array
    {
        $application = $this->appManager->getapplicationbysonodnumber($sonodNumber, $certificateType);
        if (!$application) {
            return null;
        }
        return $this->prepareApplicationData($application);
    }

    /**
     * Get application by application_id with optional union filter
     */
    public function getApplicationById(string $applicationId, ?int $unionId = null): ?array
    {
        return $this->appManager->getApplicationByApplicationId($applicationId, $unionId);
    }

    /**
     * Get full application data with all related entities
     */
    public function getFullApplicationData(string $applicationId, ?int $unionId = null, bool $includeMeta = false): ?array
    {
        return getFullApplicationData($applicationId, $unionId, $includeMeta);
    }

    // ================================================================
    // FIX SONOD STATUS
    // ================================================================

    /**
     * Fix sonod status — generate missing sonod numbers, update status for approved applications.
     */
    public function fixSonodStatus(string $applicationId, ?int $unionId): array
    {
        $application = $this->appManager->getApplication($applicationId, $unionId);

        if (!$application) {
            return ['status' => 'error', 'message' => 'Application not found.'];
        }

        $status = $application['status'] ?? '';
        $sonod_number = $application['sonod_number'] ?? '';
        $union_code = null;

        if (isset($application['union_id'])) {
            if ($this->unionModel === null) {
                $this->unionModel = new UnionModel($this->mysqli);
            }
            $union = $this->unionModel->getById($application['union_id']);
            $union_code = $union['union_code'] ?? null;
        }

        $update_needed = false;
        $new_sonod_number = $sonod_number;
        $new_status = $status;

        if ($status === 'Approved' && empty($sonod_number)) {
            $new_sonod_number = generateSonodNumber('applications', $union_code);
            $update_needed = true;
        } elseif (!empty($sonod_number) && (empty($status) || strtolower($status) === 'pending')) {
            $new_status = 'Approved';
            $update_needed = true;
        }

        if ($update_needed) {
            $update_result = $this->appManager->updateSonodStatus($applicationId, $unionId, $new_sonod_number, $new_status);
            if ($update_result) {
                return [
                    'status' => 'success',
                    'message' => 'Updated successfully',
                    'sonod_number' => $new_sonod_number,
                    'status_val' => $new_status,
                ];
            }
            return ['status' => 'error', 'message' => 'Update failed'];
        }

        return ['status' => 'info', 'message' => 'No update needed'];
    }

    // ================================================================
    // REMOTE SEARCH
    // ================================================================

    /**
     * Search for an application via remote admin API with field mapping
     */
    public function remoteSearch(string $identifier, ?int $unionId, ?string $certificateType): ?array
    {
        try {
            $remoteData = [
                'searchData'      => $identifier,
                'applicationType' => '1',
                'unionId'         => (string)$unionId,
                'call_from'       => 'checking',
                'type'            => '1',
            ];

            $ch = curl_init('https://admin.lgdhaka.com/api/check/exiting/application');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($remoteData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr || $httpCode !== 200) {
                return null;
            }

            $decoded = json_decode($response, true);
            if (!$decoded || !isset($decoded['status']) || $decoded['status'] !== 'success') {
                return null;
            }

            $raw = $decoded['data'] ?? [];
            if (empty($raw)) {
                return null;
            }

            return $this->mapRemoteApplicationFields($raw, $certificateType);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Map remote API fields to local form field names
     */
    private function mapRemoteApplicationFields(array $raw, ?string $certificateType): array
    {
        $mapped = $raw;

        // Map address fields
        foreach (['present', 'permanent'] as $prefix) {
            $suffixMap = [
                'district_name_bn'   => 'district_bn',
                'district_name_en'   => 'district_en',
                'upazila_name_bn'    => 'upazila_bn',
                'upazila_name_en'    => 'upazila_en',
                'postoffice_name_bn' => 'postoffice_bn',
                'postoffice_name_en' => 'postoffice_en',
            ];
            foreach ($suffixMap as $remoteSuffix => $localSuffix) {
                $remoteKey = $prefix . '_' . $remoteSuffix;
                $localKey  = $prefix . '_' . $localSuffix;
                if (isset($raw[$remoteKey]) && !isset($mapped[$localKey])) {
                    $mapped[$localKey] = $raw[$remoteKey];
                }
            }
        }

        // Map contact
        if (isset($raw['mobile']) && empty($mapped['applicant_phone'])) {
            $mapped['applicant_phone'] = $raw['mobile'];
        }

        // Map spouse
        $spouseBn = $raw['wife_name_bn'] ?? $raw['husband_name_bn'] ?? null;
        if ($spouseBn && empty($mapped['spouse_name_bn'])) {
            $mapped['spouse_name_bn'] = $spouseBn;
        }
        $spouseEn = $raw['wife_name_en'] ?? $raw['husband_name_en'] ?? null;
        if ($spouseEn && empty($mapped['spouse_name_en'])) {
            $mapped['spouse_name_en'] = $spouseEn;
        }

        // Map enums (gender, religion, marital_status, resident)
        $enumMaps = [
            'gender' => ['1' => 'male', '2' => 'female', '3' => 'other'],
            'religion' => ['1' => 'Islam', '2' => 'Hinduism', '3' => 'Christianity', '4' => 'Buddhism', '5' => 'Other'],
            'marital_status' => ['1' => 'Single', '2' => 'Married', '3' => 'Divorced', '4' => 'Widowed'],
            'resident' => ['1' => 'permanent', '2' => 'temporary'],
        ];

        foreach ($enumMaps as $field => $map) {
            if (isset($raw[$field])) {
                $mapped[$field] = $map[(string)$raw[$field]] ?? $raw[$field];
            }
        }

        // Add union name
        $resultUnionId = $mapped['union_id'] ?? null;
        $mapped['union_name_bn'] = $resultUnionId ? $this->getUnionNameById((int)$resultUnionId) : '';

        // Add certificate type name
        $remoteCertType = $mapped['certificate_type'] ?? $certificateType ?? '';
        $ctBn = $this->getCertificateTypeName($remoteCertType);
        $mapped['certificate_type_bn'] = $ctBn ?: $remoteCertType;
        $mapped['source'] = 'remote';

        return $mapped;
    }

    // ================================================================
    // SUBMIT APPLICATION
    // ================================================================

    /**
     * Submit a new certificate application.
     * Handles address creation, file upload, member insertion,
     * trade business meta, and transaction management.
     *
     * @param array  $post            Raw \$_POST data
     * @param array  $files           Raw \$_FILES data
     * @param string $certificateType Certificate type slug
     * @return array Standardised response array with status/message/application_id
     */
    public function submitApplication(array $post, array $files, string $certificateType): array
    {
        // Try to get union_code from POST first, then fallback to session user's union
        $union_code = sanitize_input($post['union_code'] ?? '');
        if (empty($union_code)) {
            $userData = $this->getSessionUserData();
            if (!empty($userData['union_id'])) {
                $unionInfo = $this->getUnionInfo((int)$userData['union_id']);
                $union_code = $unionInfo[1] ?? '';
            }
        }

        $union = getUnionByCode($union_code);
        $union_id = $union['union_id'] ?? null;

        if (empty($union_code) || empty($union_id)) {
            return ['status' => 'error', 'message' => 'ইউনিয়ন নির্বাচন করুন। ইউনিয়ন কোড পাওয়া যায়নি।'];
        }

        // --- Create addresses ---
        $presentAddressId = sonod_address(
            'present',
            sanitize_input($post['present_village_en'] ?? ''),
            sanitize_input($post['present_village_bn'] ?? ''),
            sanitize_input($post['present_rbs_en'] ?? ''),
            sanitize_input($post['present_rbs_bn'] ?? ''),
            sanitize_input($post['present_holding_no'] ?? ''),
            sanitize_input($post['present_ward_no'] ?? ''),
            sanitize_input($post['present_district_en'] ?? ''),
            sanitize_input($post['present_district_bn'] ?? ''),
            sanitize_input($post['present_upazila_en'] ?? ''),
            sanitize_input($post['present_upazila_bn'] ?? ''),
            sanitize_input($post['present_union_en'] ?? ''),
            sanitize_input($post['present_union_bn'] ?? ''),
            sanitize_input($post['present_postoffice_en'] ?? ''),
            sanitize_input($post['present_postoffice_bn'] ?? '')
        );
        $permanentAddressId = sonod_address(
            'permanent',
            sanitize_input($post['permanent_village_en'] ?? ''),
            sanitize_input($post['permanent_village_bn'] ?? ''),
            sanitize_input($post['permanent_rbs_en'] ?? ''),
            sanitize_input($post['permanent_rbs_bn'] ?? ''),
            sanitize_input($post['permanent_holding_no'] ?? ''),
            sanitize_input($post['permanent_ward_no'] ?? ''),
            sanitize_input($post['permanent_district_en'] ?? ''),
            sanitize_input($post['permanent_district_bn'] ?? ''),
            sanitize_input($post['permanent_upazila_en'] ?? ''),
            sanitize_input($post['permanent_upazila_bn'] ?? ''),
            sanitize_input($post['permanent_union_en'] ?? ''),
            sanitize_input($post['permanent_union_bn'] ?? ''),
            sanitize_input($post['permanent_postoffice_en'] ?? ''),
            sanitize_input($post['permanent_postoffice_bn'] ?? '')
        );

        if (!$presentAddressId || !$permanentAddressId) {
            return ['status' => 'error', 'message' => 'Address creation failed'];
        }

        // --- Normalise identifiers ---
        $nid         = convertBanglaToEnglishNumber(sanitize_input($post['nid'] ?? ''));
        $birth_id    = convertBanglaToEnglishNumber(sanitize_input($post['birth_id'] ?? ''));
        $passport_no = convertBanglaToEnglishNumber(sanitize_input($post['passport_no'] ?? ''));
        $birth_date  = sanitize_input($post['birth_date'] ?? '');

        // --- Generate IDs ---
        $applicant_id    = generateApplicantId($nid, $birth_id, $passport_no, $union_code, $birth_date);
        $application_id  = generateTrackingNumber($union_code);

        // --- File upload ---
        $uploadResult = handleApplicantFileUpload($application_id);
        $photoPath     = $uploadResult['photo'] ?? '';
        $documentsJson = $uploadResult['documents_json'] ?? '';
        $uploadError   = $uploadResult['error'] ?? '';

        // Fallback: use default photo if none was uploaded
        if (empty($photoPath)) {
            $photoPath = '';
        }

        // --- Build application data ---
        $data = [
            'application_id'       => $application_id,
            'applicant_id'         => $applicant_id,
            'certificate_type'     => $certificateType,
            'union_id'             => $union_id,
            'sonod_number'         => '',
            'name_en'              => sanitize_input($post['name_en'] ?? ''),
            'name_bn'              => sanitize_input($post['name_bn'] ?? ''),
            'nid'                  => $nid,
            'birth_id'             => $birth_id,
            'passport_no'          => $passport_no,
            'birth_date'           => $birth_date,
            'gender'               => sanitize_input($post['gender'] ?? ''),
            'father_name_en'       => sanitize_input($post['father_name_en'] ?? ''),
            'father_name_bn'       => sanitize_input($post['father_name_bn'] ?? ''),
            'mother_name_en'       => sanitize_input($post['mother_name_en'] ?? ''),
            'mother_name_bn'       => sanitize_input($post['mother_name_bn'] ?? ''),
            'occupation'           => sanitize_input($post['occupation'] ?? ''),
            'resident'             => sanitize_input($post['resident'] ?? ''),
            'educational_qualification' => sanitize_input($post['educational_qualification'] ?? ''),
            'religion'             => sanitize_input($post['religion'] ?? ''),
            'marital_status'       => sanitize_input($post['marital_status'] ?? ''),
            'spouse_name_en'       => sanitize_input($post['spouse_name_en'] ?? ''),
            'spouse_name_bn'       => sanitize_input($post['spouse_name_bn'] ?? ''),
            'applicant_name'       => sanitize_input(
                trim($post['applicant_name'] ?? '') !== ''
                    ? $post['applicant_name']
                    : ($post['name_bn'] ?? '')
            ),
            'applicant_phone'      => sanitize_input($post['applicant_phone'] ?? ''),
            'applicant_photo'      => $photoPath,
            'documents'            => $documentsJson,
            'present_address_id'   => $presentAddressId,
            'permanent_address_id' => $permanentAddressId,
            'extra_data'           => $post['extra_data'] ?? null,
        ];

        // --- Transaction ---
        $this->mysqli->begin_transaction();
        try {
            $createResult = $this->appManager->createApplication($data);
            if (($createResult['status'] ?? '') !== 'success') {
                throw new \Exception($createResult['message'] ?? 'Application creation failed');
            }

            // --- Insert members (warish) ---
            $this->insertApplicationMembers($application_id, $certificateType, $post);

            // --- Trade-specific business meta ---
            if ($certificateType === 'trade') {
                $this->insertTradeBusinessMeta($application_id, $post);
            }

            $this->mysqli->commit();

            $response = [
                'status'         => 'success',
                'alert'          => [
                    'type'    => 'success',
                    'title'   => 'সাফল্য',
                    'message' => 'আবেদন সফলভাবে জমা দেওয়া হয়েছে',
                ],
                'application_id' => $application_id,
                'union_code'     => $union_code,
            ];
            if ($uploadError) {
                $response['upload_warning'] = $uploadError;
            }
            return $response;
        } catch (\Exception $e) {
            $this->mysqli->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Update an existing application.
     * Handles address update, photo re-upload, member re-insertion,
     * trade business meta update, and transaction management.
     */
    public function updateApplication(string $applicationId, array $post, array $files, string $certificateType): array
    {
        $userData = $this->getSessionUserData();
        $union_id = $userData['union_id'] ?? null;

        // Fetch existing application
        $application = $this->appManager->getApplicationByApplicationId($applicationId);
        if (!$application) {
            return ['status' => 'error', 'message' => 'Application not found'];
        }

        // --- Build / update addresses (pass existing ID to trigger UPDATE) ---
        $presentAddressId = sonod_address(
            'present',
            sanitize_input($post['present_village_en'] ?? ''),
            sanitize_input($post['present_village_bn'] ?? ''),
            sanitize_input($post['present_rbs_en'] ?? ''),
            sanitize_input($post['present_rbs_bn'] ?? ''),
            sanitize_input($post['present_holding_no'] ?? ''),
            sanitize_input($post['present_ward_no'] ?? ''),
            sanitize_input($post['present_district_en'] ?? ''),
            sanitize_input($post['present_district_bn'] ?? ''),
            sanitize_input($post['present_upazila_en'] ?? ''),
            sanitize_input($post['present_upazila_bn'] ?? ''),
            sanitize_input($post['present_union_en'] ?? ''),
            sanitize_input($post['present_union_bn'] ?? ''),
            sanitize_input($post['present_postoffice_en'] ?? ''),
            sanitize_input($post['present_postoffice_bn'] ?? ''),
            $application['present_address_id'] ?? null
        );
        $permanentAddressId = sonod_address(
            'permanent',
            sanitize_input($post['permanent_village_en'] ?? ''),
            sanitize_input($post['permanent_village_bn'] ?? ''),
            sanitize_input($post['permanent_rbs_en'] ?? ''),
            sanitize_input($post['permanent_rbs_bn'] ?? ''),
            sanitize_input($post['permanent_holding_no'] ?? ''),
            sanitize_input($post['permanent_ward_no'] ?? ''),
            sanitize_input($post['permanent_district_en'] ?? ''),
            sanitize_input($post['permanent_district_bn'] ?? ''),
            sanitize_input($post['permanent_upazila_en'] ?? ''),
            sanitize_input($post['permanent_upazila_bn'] ?? ''),
            sanitize_input($post['permanent_union_en'] ?? ''),
            sanitize_input($post['permanent_union_bn'] ?? ''),
            sanitize_input($post['permanent_postoffice_en'] ?? ''),
            sanitize_input($post['permanent_postoffice_bn'] ?? ''),
            $application['permanent_address_id'] ?? null
        );

        if (!$presentAddressId || !$permanentAddressId) {
            return ['status' => 'error', 'message' => 'Address update failed'];
        }

        // --- Handle photo re-upload ---
        $editPhotoUploadResult = null;
        $editPhotoUploadError = '';
        $hasNewPhoto = !empty($files['applicant_photo']['name']) || !empty($files['photo']['name']);
        if ($hasNewPhoto) {
            $editPhotoUploadResult = handleApplicantFileUpload($applicationId);
            if (!$editPhotoUploadResult['success'] || empty($editPhotoUploadResult['photo'])) {
                $editPhotoUploadError = $editPhotoUploadResult['error'] ?? 'ছবি আপলোড ব্যর্থ হয়েছে। পুরাতন ছবি সংরক্ষিত হয়েছে।';
            }
        }

        // --- Sanitise extra_data ---
        $extra_data = $post['extra_data'] ?? '{}';
        if (is_string($extra_data)) {
            $extra_data = trim($extra_data);
            if ($extra_data === '') {
                $extra_data = '{}';
            } else {
                $decoded = json_decode($extra_data, true);
                $extra_data = (json_last_error() === JSON_ERROR_NONE)
                    ? json_encode($decoded, JSON_UNESCAPED_UNICODE)
                    : '{}';
            }
        } elseif (is_array($extra_data)) {
            $extra_data = json_encode($extra_data, JSON_UNESCAPED_UNICODE);
        } else {
            $extra_data = '{}';
        }

        // --- Build update data ---
        $sanitizeDoc = function ($arr) {
            return is_array($arr) ? array_map('sanitize_input', $arr) : [];
        };

        $data = [
            'name_en'              => sanitize_input($post['name_en'] ?? ''),
            'name_bn'              => sanitize_input($post['name_bn'] ?? ''),
            'nid'                  => sanitize_input($post['nid'] ?? ''),
            'birth_id'             => sanitize_input($post['birth_id'] ?? ''),
            'passport_no'          => sanitize_input($post['passport_no'] ?? ''),
            'birth_date'           => sanitize_input($post['birth_date'] ?? ''),
            'gender'               => sanitize_input($post['gender'] ?? ''),
            'father_name_en'       => sanitize_input($post['father_name_en'] ?? ''),
            'father_name_bn'       => sanitize_input($post['father_name_bn'] ?? ''),
            'mother_name_en'       => sanitize_input($post['mother_name_en'] ?? ''),
            'mother_name_bn'       => sanitize_input($post['mother_name_bn'] ?? ''),
            'occupation'           => sanitize_input($post['occupation'] ?? ''),
            'resident'             => sanitize_input($post['resident'] ?? ''),
            'educational_qualification' => sanitize_input($post['educational_qualification'] ?? ''),
            'religion'             => sanitize_input($post['religion'] ?? ''),
            'marital_status'       => sanitize_input($post['marital_status'] ?? ''),
            'spouse_name_en'       => sanitize_input($post['spouse_name_en'] ?? ''),
            'spouse_name_bn'       => sanitize_input($post['spouse_name_bn'] ?? ''),
            'applicant_name'       => sanitize_input(
                trim($post['applicant_name'] ?? '') !== ''
                    ? $post['applicant_name']
                    : ($post['name_bn'] ?? '')
            ),
            'applicant_phone'      => sanitize_input($post['applicant_phone'] ?? ''),
            'applicant_photo'      => ($editPhotoUploadResult && !empty($editPhotoUploadResult['photo']))
                ? $editPhotoUploadResult['photo']
                : ($application['applicant_photo'] ?? ''),
            'documents'            => isset($post['documents'])
                ? $sanitizeDoc($post['documents'])
                : json_decode($application['documents'] ?? '[]', true),
            'extra_data'           => $extra_data,
            'present_address_id'   => $presentAddressId,
            'permanent_address_id' => $permanentAddressId,
        ];

        // --- Transaction ---
        $this->mysqli->begin_transaction();
        try {
            $this->appManager->updateApplicationFixed($applicationId, $data, $union_id);
            $this->appManager->deleteMembersByApplication($applicationId);

            // --- Re-insert members ---
            $this->insertApplicationMembers($applicationId, $certificateType, $post);

            // --- Trade-specific business meta ---
            if ($certificateType === 'trade') {
                $this->updateTradeBusinessMeta($applicationId, $post);
            }

            $this->mysqli->commit();

            $response = [
                'status' => 'success',
                'alert'  => [
                    'type'    => 'success',
                    'title'   => 'সাফল্য',
                    'message' => 'আবেদন সফলভাবে আপডেট হয়েছে',
                ],
            ];
            if ($editPhotoUploadError) {
                $response['upload_warning'] = $editPhotoUploadError;
            }
            return $response;
        } catch (\Exception $e) {
            $this->mysqli->rollback();
            return [
                'status' => 'error',
                'alert'  => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => $e->getMessage()],
            ];
        }
    }

    /**
     * Insert family / warish members for an application.
     */
    private function insertApplicationMembers(string $applicationId, string $certificateType, array $post): void
    {
        $serial_nos       = $post['serial_no'] ?? [];
        $member_name_bns  = $post['warish_name_bn'] ?? [];
        $member_name_ens  = $post['warish_name_en'] ?? [];
        $relations        = $post['relation'] ?? [];
        $member_birth_dates = $post['member_birth_date'] ?? [];
        $relation_nids    = $post['relation_nid'] ?? [];
        $marriage_states  = $post['marrage_state'] ?? [];
        $is_deads         = $post['is_dead'] ?? [];

        $count = count($serial_nos);
        for ($i = 0; $i < $count; $i++) {
            $relation_value = $relations[$i] ?? '';
            $relation_bn = '';
            $relation_en = '';
            if (!empty($relation_value) && strpos($relation_value, '|') !== false) {
                [$relation_bn, $relation_en] = explode('|', $relation_value);
            }

            if (empty($member_name_bns[$i] ?? '') && empty($relation_bn)) {
                continue;
            }

            $memberData = [
                'application_id'  => $applicationId,
                'certificate_type' => $certificateType,
                'name_en'         => sanitize_input($member_name_ens[$i] ?? ''),
                'name_bn'         => sanitize_input($member_name_bns[$i] ?? ''),
                'relation_en'     => $relation_en,
                'relation_bn'     => $relation_bn,
                'birth_date'      => sanitize_input($member_birth_dates[$i] ?? ''),
                'nid'             => sanitize_input($relation_nids[$i] ?? ''),
                'serial_no'       => (int)($serial_nos[$i] ?? 0),
                'marital_status'  => sanitize_input($marriage_states[$i] ?? ''),
                'is_dead'         => (!empty($is_deads[$i]) && $is_deads[$i] === '1') ? '1' : '0',
            ];

            $addResult = $this->appManager->addMember($memberData);
            $ok = is_array($addResult)
                ? (($addResult['status'] ?? false) === true)
                : (bool)$addResult;
            if (!$ok) {
                $msg = is_array($addResult) ? ($addResult['message'] ?? '') : '';
                throw new \Exception(($msg ?: 'Member insertion failed') . ' at index ' . $i);
            }
        }
    }

    /**
     * Insert trade business meta for a new application.
     */
    private function insertTradeBusinessMeta(string $applicationId, array $post): void
    {
        $businessAddressId = sonod_address(
            'business',
            sanitize_input($post['business_village_en'] ?? ''),
            sanitize_input($post['business_village_bn'] ?? ''),
            sanitize_input($post['business_rbs_en'] ?? ''),
            sanitize_input($post['business_rbs_bn'] ?? ''),
            sanitize_input($post['business_holding_no'] ?? ''),
            sanitize_input($post['business_ward_no'] ?? ''),
            sanitize_input($post['business_district_en'] ?? ''),
            sanitize_input($post['business_district_bn'] ?? ''),
            sanitize_input($post['business_upazila_en'] ?? ''),
            sanitize_input($post['business_upazila_bn'] ?? ''),
            sanitize_input($post['business_union_en'] ?? ''),
            sanitize_input($post['business_union_bn'] ?? ''),
            sanitize_input($post['business_postoffice_en'] ?? ''),
            sanitize_input($post['business_postoffice_bn'] ?? '')
        );

        $business_type_id = (int)($post['business_type'] ?? 0);
        $fees = $this->getBusinessTypeFees($business_type_id);

        if (!$fees) {
            throw new \Exception('Invalid Business Type ID or fees not found');
        }

        $total_fee = ($fees['license_fee'] ?? 0)
            + ($fees['vat_amount'] ?? 0)
            + ($fees['occupation_tax'] ?? 0)
            + ($fees['income_tax'] ?? 0)
            + ($fees['signboard_tax'] ?? 0)
            + ($fees['surcharge'] ?? 0);

        $businessMetaData = [
            'business_name_en'    => sanitize_input($post['business_name_en'] ?? ''),
            'business_name_bn'    => sanitize_input($post['business_name_bn'] ?? ''),
            'ownership_type_id'   => (int)($post['business_ownership_type'] ?? 0),
            'vat_id'              => sanitize_input($post['vat_id'] ?? ''),
            'tax_id'              => sanitize_input($post['tax_id'] ?? ''),
            'business_type_id'    => $business_type_id,
            'paid_up_capital'     => (float)($post['paid_up_capital'] ?? 0),
            'license_fee'         => $fees['license_fee'] ?? 0,
            'vat_amount'          => $fees['vat_amount'] ?? 0,
            'occupation_tax'      => $fees['occupation_tax'] ?? 0,
            'income_tax'          => $fees['income_tax'] ?? 0,
            'signboard_tax'       => $fees['signboard_tax'] ?? 0,
            'surcharge'           => $fees['surcharge'] ?? 0,
            'total_fee'           => $total_fee,
            'business_address_id' => $businessAddressId,
        ];

        if (!$this->appManager->insertBusinessMeta($applicationId, $businessMetaData)) {
            throw new \Exception('Business meta insertion failed');
        }
    }

    /**
     * Update trade business meta for an existing application.
     */
    private function updateTradeBusinessMeta(string $applicationId, array $post): void
    {
        $businessAddressId = sonod_address(
            'business',
            sanitize_input($post['business_village_en'] ?? ''),
            sanitize_input($post['business_village_bn'] ?? ''),
            sanitize_input($post['business_rbs_en'] ?? ''),
            sanitize_input($post['business_rbs_bn'] ?? ''),
            sanitize_input($post['business_holding_no'] ?? ''),
            sanitize_input($post['business_ward_no'] ?? ''),
            sanitize_input($post['business_district_en'] ?? ''),
            sanitize_input($post['business_district_bn'] ?? ''),
            sanitize_input($post['business_upazila_en'] ?? ''),
            sanitize_input($post['business_upazila_bn'] ?? ''),
            sanitize_input($post['business_union_en'] ?? ''),
            sanitize_input($post['business_union_bn'] ?? ''),
            sanitize_input($post['business_postoffice_en'] ?? ''),
            sanitize_input($post['business_postoffice_bn'] ?? '')
        );

        $business_type_id = (int)($post['business_type'] ?? 0);
        $fees = $this->getBusinessTypeFees($business_type_id);
        $total_fee = $fees ? array_sum($fees) : 0;

        $businessMetaData = [
            'business_name_en'    => sanitize_input($post['business_name_en'] ?? ''),
            'business_name_bn'    => sanitize_input($post['business_name_bn'] ?? ''),
            'ownership_type_id'   => (int)($post['business_ownership_type'] ?? 0),
            'vat_id'              => sanitize_input($post['vat_id'] ?? ''),
            'tax_id'              => sanitize_input($post['tax_id'] ?? ''),
            'business_type_id'    => $business_type_id,
            'paid_up_capital'     => (float)($post['paid_up_capital'] ?? 0),
            'license_fee'         => $fees['license_fee'] ?? 0,
            'vat_amount'          => $fees['vat_amount'] ?? 0,
            'occupation_tax'      => $fees['occupation_tax'] ?? 0,
            'income_tax'          => $fees['income_tax'] ?? 0,
            'signboard_tax'       => $fees['signboard_tax'] ?? 0,
            'surcharge'           => $fees['surcharge'] ?? 0,
            'total_fee'           => $total_fee,
            'business_address_id' => $businessAddressId,
        ];

        if (!$this->appManager->updateBusinessMeta($applicationId, $businessMetaData)) {
            throw new \Exception('Business meta update failed');
        }
    }

    /**
     * Get current session user data (user_id, union_id, role_id).
     */
    private function getSessionUserData(): array
    {
        $auth = new \AuthManager($this->mysqli);
        $user = $auth->getUserData(false);
        return $user ?: [];
    }

    // ================================================================
    // APPROVE APPLICATION
    // ================================================================

    /**
     * Approve a certificate application.
     * Handles input validation, sonod number generation, date parsing,
     * trade business meta update, and transaction management.
     *
     * @param string  $applicationId  Application to approve
     * @param array   $post           Raw \$_POST data
     * @param int|null $unionId       Current user's union ID
     * @param bool    $isSuperAdmin   Whether the current user is superadmin
     * @return array Structured response
     */
    public function approveApplication(string $applicationId, array $post, ?int $unionId, bool $isSuperAdmin): array
    {
        $lookupUnion  = $isSuperAdmin ? null : $unionId;

        $application = $this->appManager->getApplication($applicationId, $lookupUnion);
        if (!$application) {
            return ['status' => 'error', 'message' => 'আবেদন পাওয়া যায়নি।'];
        }

        $certificate_type = $application['certificate_type'] ?? 'application';

        // --- Union code lookup ---
        $union_code = null;
        if (!empty($application['union_id'])) {
            $unionInfo = $this->getUnionInfo((int)$application['union_id']);
            $union_code = $unionInfo[1] ?? null;
        }

        // --- Validate required fields ---
        if (empty($post['approval_date'])) {
            return ['status' => 'error', 'message' => 'অনুগ্রহ করে অনুমোদনের তারিখ নির্বাচন করুন।'];
        }

        if ($certificate_type === 'trade') {
            $fiscal_year = trim((string)($post['fiscal_year'] ?? ''));
            $ownership_type_id = trim((string)($post['ownership_type_id'] ?? ''));
            $business_type_id = trim((string)($post['business_type_id'] ?? ''));

            if ($fiscal_year === '') {
                return ['status' => 'error', 'message' => 'অনুগ্রহ করে সনদের অর্থবছর নির্বাচন করুন।'];
            }
            if ($ownership_type_id === '') {
                return ['status' => 'error', 'message' => 'অনুগ্রহ করে মালিকানার ধরণ নির্বাচন করুন।'];
            }
            if ($business_type_id === '') {
                return ['status' => 'error', 'message' => 'অনুগ্রহ করে ব্যবসার ধরণ নির্বাচন করুন।'];
            }
        }

        // --- Sonod number determination (3-tier) ---
        $sonod_number = null;
        $posted_license_number = trim((string)($post['license_number'] ?? ''));
        try {
            if ($posted_license_number !== '') {
                $sonod_number = sanitize_input($posted_license_number);
            } elseif (!empty($application['sonod_number'])) {
                $sonod_number = sanitize_input($application['sonod_number']);
            } elseif (!empty($union_code)) {
                $sonod_number = generateSonodNumber('applications', $union_code);
            } else {
                throw new \RuntimeException('লাইসেন্স নম্বর তৈরি করা যাচ্ছে না, কারণ ইউনিয়ন কোড পাওয়া যায়নি।');
            }
        } catch (\Throwable $e) {
            $response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            if ($isSuperAdmin) {
                $response['debug'] = [
                    'mysql_error' => $e->getMessage(),
                    'error_log_path' => ini_get('error_log'),
                    'union_code' => $union_code,
                ];
            }
            return $response;
        }

        // --- Duplicate sonod number check ---
        if (!empty($sonod_number)) {
            $existingSonod = $this->appManager->getApplicationBySonodNumber($sonod_number);
            if ($existingSonod && ($existingSonod['application_id'] ?? null) !== $applicationId) {
                return [
                    'status' => 'error',
                    'message' => 'এই সনদ নম্বর (' . $sonod_number . ') ইতিমধ্যে আরেকটি আবেদনের জন্য ব্যবহৃত হয়েছে। অনুগ্রহ করে একটি ভিন্ন সনদ নম্বর ব্যবহার করুন।'
                ];
            }
        }

        // --- Parse dates ---
        $approval_date = $this->parseDateInput($post['approval_date'] ?? '');
        $verification_date = $this->parseDateInput($post['verification_date'] ?? '');

        // --- Build approval data array ---
        $data = [
            'verifier_id'          => isset($post['verifier_id']) && $post['verifier_id'] !== '' ? (int)$post['verifier_id'] : 0,
            'verifier_designation' => !empty($post['verifier_designation']) ? sanitize_input($post['verifier_designation']) : '',
            'verifier_contact'     => !empty($post['verifier_contact']) ? sanitize_input($post['verifier_contact']) : '',
            'verifier_name_bn'     => !empty($post['verifier_name_bn']) ? sanitize_input($post['verifier_name_bn']) : '',
            'verifier_name_en'     => !empty($post['verifier_name_en']) ? sanitize_input($post['verifier_name_en']) : '',
            'verifier_ward_no'     => !empty($post['verifier_ward_no']) ? sanitize_input($post['verifier_ward_no']) : '',
            'verification_date'    => $verification_date,
            'verification_note'    => !empty($post['verification_note']) ? sanitize_input($post['verification_note']) : '',
            'approver_id'          => !empty($post['approver_id']) ? sanitize_input($post['approver_id']) : '',
            'approver_name_bn'     => !empty($post['approver_name_bn']) ? sanitize_input($post['approver_name_bn']) : '',
            'approver_name_en'     => !empty($post['approver_name_en']) ? sanitize_input($post['approver_name_en']) : '',
            'approver_ward_no'     => !empty($post['approver_ward_no']) ? sanitize_input($post['approver_ward_no']) : '',
            'approval_date'        => $approval_date,
            'issue_time'           => !empty($post['issue_time']) ? sanitize_input($post['issue_time']) : '12:00:00',
            'approval_note'        => !empty($post['approval_note']) ? sanitize_input($post['approval_note']) : '',
            'certificate_fee'      => isset($post['certificate_fee']) && $post['certificate_fee'] !== '' ? (float)$post['certificate_fee'] : 0.00,
            'payment_method'       => !empty($post['payment_method']) ? sanitize_input($post['payment_method']) : '',
            'payment_status'       => !empty($post['payment_status']) ? sanitize_input($post['payment_status']) : '',
            'certificate_type'     => $certificate_type,
            'sonod_number'         => $sonod_number,
        ];

        $cert_type_bn = $this->getCertificateTypeName($certificate_type);

        // --- Transaction ---
        $this->mysqli->begin_transaction();
        try {
            $result = $this->appManager->approveApplication($applicationId, $data, $sonod_number, $unionId, $certificate_type);

            if ($certificate_type === 'trade') {
                $this->approveTradeBusinessMeta($applicationId, $post);
            }

            $this->mysqli->commit();

            $isUpdate = isset($application['status']) && strtolower($application['status']) === 'approved';

            return [
                'status'             => 'success',
                'message'            => $isUpdate ? 'অনুমোদন সফলভাবে হালনাগাদ হয়েছে।' : 'আবেদন সফলভাবে অনুমোদিত হয়েছে।',
                'is_update'          => $isUpdate,
                'sonod_number'       => $sonod_number,
                'certificate_type'   => $certificate_type,
                'certificate_type_bn' => $cert_type_bn,
            ];
        } catch (\Exception $e) {
            $this->mysqli->rollback();
            $response = [
                'status'  => 'error',
                'message' => 'অনুমোদনে সমস্যা হয়েছে: ' . $e->getMessage(),
            ];
            if ($isSuperAdmin) {
                $response['debug'] = [
                    'mysql_error' => $e->getMessage(),
                    'error_log_path' => ini_get('error_log'),
                ];
            }
            return $response;
        }
    }

    /**
     * Update trade business meta during approval.
     * Merges posted values with existing meta, calculates expiry_date from fiscal year.
     */
    private function approveTradeBusinessMeta(string $applicationId, array $post): void
    {
        $fiscal_year = isset($post['fiscal_year']) ? sanitize_input($post['fiscal_year']) : null;

        $existingMeta = $this->appManager->getBusinessMetaByApplicationId($applicationId);
        $hasExistingMeta = !empty($existingMeta);
        $existingMeta = $existingMeta ?: [];

        $businessMetaData = [
            'business_name_en'    => $existingMeta['business_name_en'] ?? '',
            'business_name_bn'    => $existingMeta['business_name_bn'] ?? '',
            'vat_id'              => $existingMeta['vat_id'] ?? '',
            'tax_id'              => $existingMeta['tax_id'] ?? '',
            'paid_up_capital'     => $existingMeta['paid_up_capital'] ?? 0,
            'business_address_id' => $existingMeta['business_address_id'] ?? null,
            'license_fee'         => !empty($post['license_fee']) ? (float)$post['license_fee'] : ($existingMeta['license_fee'] ?? null),
            'vat_amount'          => !empty($post['vat_amount']) ? (float)$post['vat_amount'] : ($existingMeta['vat_amount'] ?? null),
            'occupation_tax'      => !empty($post['occupation_tax']) ? (float)$post['occupation_tax'] : ($existingMeta['occupation_tax'] ?? null),
            'income_tax'          => !empty($post['income_tax']) ? (float)$post['income_tax'] : ($existingMeta['income_tax'] ?? null),
            'signboard_tax'       => !empty($post['signboard_tax']) ? (float)$post['signboard_tax'] : ($existingMeta['signboard_tax'] ?? null),
            'surcharge'           => !empty($post['surcharge']) ? (float)$post['surcharge'] : ($existingMeta['surcharge'] ?? null),
            'total_fee'           => !empty($post['total_fee']) ? (float)$post['total_fee'] : ($existingMeta['total_fee'] ?? null),
            'fiscal_year'         => $fiscal_year ?: ($existingMeta['fiscal_year'] ?? null),
            'ownership_type_id'   => !empty($post['ownership_type_id']) ? (int)$post['ownership_type_id'] : ($existingMeta['ownership_type_id'] ?? null),
            'business_type_id'    => !empty($post['business_type_id']) ? (int)$post['business_type_id'] : ($existingMeta['business_type_id'] ?? null),
        ];

        // Calculate expiry_date from fiscal_year
        if ($fiscal_year) {
            $parts = explode('-', $fiscal_year);
            $businessMetaData['expiry_date'] = isset($parts[1])
                ? trim($parts[1]) . '-06-30'
                : null;
        } elseif (!empty($existingMeta['expiry_date'])) {
            $businessMetaData['expiry_date'] = $existingMeta['expiry_date'];
        }

        $businessUpdateResult = $hasExistingMeta
            ? $this->appManager->updateBusinessMeta($applicationId, $businessMetaData)
            : $this->appManager->insertBusinessMeta($applicationId, $businessMetaData);

        $status = is_array($businessUpdateResult)
            ? ($businessUpdateResult['status'] ?? false)
            : (bool)$businessUpdateResult;

        if (!$status) {
            $msg = is_array($businessUpdateResult)
                ? ($businessUpdateResult['message'] ?? 'Business meta update failed')
                : 'Business meta update failed';
            throw new \Exception($msg);
        }
    }

    /**
     * Parse a date string from multiple formats into Y-m-d.
     * Supports 'Y-m-d' and 'd-m-Y' formats.
     */
    private function parseDateInput(?string $dateInput): ?string
    {
        if (empty($dateInput)) {
            return null;
        }
        try {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $dateInput);
            if (!$dateTime instanceof \DateTime) {
                $dateTime = \DateTime::createFromFormat('d-m-Y', $dateInput);
            }
            return ($dateTime instanceof \DateTime) ? $dateTime->format('Y-m-d') : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ================================================================
    // RENEW TRADE LICENSE
    // ================================================================

    /**
     * Renew a trade license.
     * Validates the fiscal year format, fetches the application with union scoping,
     * builds renewal data from POST, and calls renewLicense.
     *
     * @param string  $applicationId  Application to renew
     * @param array   $post           Raw \$_POST data
     * @param int|null $unionId       Current user's union ID
     * @param bool    $isSuperAdmin   Whether user is superadmin
     * @return array Structured response
     */
    public function renewTradeLicense(string $applicationId, array $post, ?int $unionId, bool $isSuperAdmin): array
    {
        if (empty($applicationId)) {
            return ['status' => 'error', 'message' => 'অ্যাপ্লিকেশন আইডি প্রয়োজন।'];
        }

        $lookupUnion = $isSuperAdmin ? null : $unionId;
        $application = $this->appManager->getApplicationByApplicationId($applicationId, $lookupUnion);
        if (!$application) {
            return ['status' => 'error', 'message' => 'অ্যাপ্লিকেশন খুঁজে পাওয়া যায়নি।'];
        }

        $fiscal_year = sanitize_input($post['fiscal_year'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $fiscal_year)) {
            return ['status' => 'error', 'message' => 'অর্থবছর ফর্ম্যাট অবৈধ।'];
        }

        $renewal_data = [
            'fiscal_year'   => $fiscal_year,
            'license_fee'   => !empty($post['license_fee']) ? (float)$post['license_fee'] : 0,
            'vat_amount'    => !empty($post['vat_amount']) ? (float)$post['vat_amount'] : 0,
            'occupation_tax' => !empty($post['occupation_tax']) ? (float)$post['occupation_tax'] : 0,
            'income_tax'    => !empty($post['income_tax']) ? (float)$post['income_tax'] : 0,
            'signboard_tax' => !empty($post['signboard_tax']) ? (float)$post['signboard_tax'] : 0,
            'surcharge'     => !empty($post['surcharge']) ? (float)$post['surcharge'] : 0,
            'total_fee'     => !empty($post['total_fee']) ? (float)$post['total_fee'] : 0,
            'remarks'       => sanitize_input($post['remarks'] ?? ''),
        ];

        try {
            $result = $this->appManager->renewLicense($applicationId, $renewal_data);
            return [
                'status' => 'success',
                'message' => 'লাইসেন্স সফলভাবে নবায়ন করা হয়েছে।',
                'data' => $result,
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'নবায়ন ব্যর্থ হয়েছে: ' . $e->getMessage()];
        }
    }

    // ================================================================
    // REJECT APPLICATION
    // ================================================================

    /**
     * Reject an application by ID with union scoping.
     * Wraps the global applicationRejectForm() call.
     */
    public function rejectApplication(string $applicationId, string $reason, ?int $unionId = null, ?string $certificateType = null): array
    {
        if (function_exists('applicationRejectForm')) {
            try {
                applicationRejectForm($applicationId, $reason, $unionId, $certificateType);
                return ['status' => 'success', 'message' => 'Application rejected successfully.'];
            } catch (\Exception $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return ['status' => 'error', 'message' => 'applicationRejectForm function not found'];
    }

    /**
     * Reject application via POST with reason validation.
     * Fetches application for certificate_type, then rejects.
     */
    public function rejectApplicationPost(string $applicationId, string $reason, ?int $unionId): array
    {
        if (empty($reason)) {
            return ['status' => 'error', 'message' => 'Reject reason is required!'];
        }

        $application = $this->appManager->getApplication($applicationId, $unionId);
        $certificateType = $application['certificate_type'] ?? 'application';

        $result = $this->appManager->rejectApplication($applicationId, $reason, $unionId, $certificateType);
        return $result;
    }

    // ================================================================
    // DELETE APPLICATION
    // ================================================================

    /**
     * Delete an application by ID with union scoping.
     * Cleans up associated photo and document files from disk before DB deletion.
     */
    public function deleteApplicationById(string $applicationId, ?int $unionId, bool $isSuperAdmin): array
    {
        if (empty($applicationId)) {
            return ['status' => 'error', 'message' => 'অ্যাপ্লিকেশন আইডি প্রয়োজন।'];
        }

        $lookupUnion = $isSuperAdmin ? null : $unionId;

        // Fetch application data BEFORE deletion so we know which files to remove
        $application = $this->appManager->getApplicationByApplicationId($applicationId, $lookupUnion);
        if (!$application) {
            return ['status' => 'error', 'message' => 'কোনো আবেদন মুছে যায়নি বা পাওয়া যায়নি।'];
        }

        // Delete photo & document files from disk (skips shared default images)
        $this->deleteApplicationFiles($application);

        // Delete the database record
        $this->appManager->deleteApplication($applicationId, $lookupUnion);

        return ['status' => 'success', 'message' => 'আবেদন এবং সংশ্লিষ্ট ফাইলসমূহ মুছে ফেলা হয়েছে।'];
    }

    /**
     * Delete uploaded files (photo + documents) associated with an application.
     * Only removes actual uploaded files — skips default/shared images.
     * Uses the same public root resolution as FileUploadService::handleUpload().
     *
     * @param array $application Application data containing applicant_photo and documents
     */
    private function deleteApplicationFiles(array $application): void
    {
        // Resolve public root the same way FileUploadService does
        $publicRoot = dirname(__DIR__) . '/public';

        // --- Delete applicant photo ---
        $photoPath = $application['applicant_photo'] ?? '';
        // Only delete custom uploaded photos (skip shared default images like default.jpg, default-avatar.png)
        if (!empty($photoPath) && strpos($photoPath, 'default.') === false) {
            $fullPhotoPath = $publicRoot . $photoPath;
            if (file_exists($fullPhotoPath)) {
                @unlink($fullPhotoPath);
            }
        }

        // --- Delete documents ---
        $documentsJson = $application['documents'] ?? $application['existing_documents'] ?? '';
        if (!empty($documentsJson)) {
            $documents = is_string($documentsJson) ? json_decode($documentsJson, true) : $documentsJson;
            if (is_array($documents)) {
                foreach ($documents as $docPath) {
                    if (!empty($docPath) && is_string($docPath)) {
                        $fullDocPath = $publicRoot . $docPath;
                        if (file_exists($fullDocPath)) {
                            @unlink($fullDocPath);
                        }
                    }
                }
            }
        }
    }

    // ================================================================
    // FETCH APPLICATIONS LIST
    // ================================================================

    /**
     * Fetch paginated list of applications with sorting and search.
     * Handles param extraction and superadmin union override.
     */
    public function fetchApplicationsList(array $post, ?int $userUnionId, ?int $userRoleId, string $certificateType): array
    {
        $page = isset($post['page']) ? (int)$post['page'] : 1;
        $search = isset($post['search']) ? sanitize_input($post['search']) : '';
        $sort_by = isset($post['sort_by']) ? sanitize_input($post['sort_by']) : 'application_id';
        $sort_order = isset($post['sort_order']) && strtolower($post['sort_order']) === 'asc' ? 'ASC' : 'DESC';
        $records_per_page = isset($post['records_per_page']) ? (int)$post['records_per_page'] : 10;

        // Allow superadmin to filter by union
        $union_id = $userUnionId;
        if (($userRoleId !== null && $userRoleId <= 1) && !empty($post['union_id'])) {
            $tmp = filter_var($post['union_id'], FILTER_VALIDATE_INT);
            if ($tmp !== false) $union_id = $tmp;
        }

        return $this->appManager->fetchAllApplications(
            $union_id,
            $page,
            $search,
            $records_per_page,
            $sort_by,
            $sort_order,
            $certificateType
        );
    }

    /**
     * Fetch existing application by ID with union scoping.
     * Wraps the global fetchApplicationById() call.
     */
    public function fetchExistingApplication(string $applicationId, ?int $unionId, bool $isSuperAdmin): ?array
    {
        $lookupUnion = $isSuperAdmin ? null : $unionId;
        return $this->appManager->getApplicationByApplicationId($applicationId, $lookupUnion);
    }

    // ================================================================
    // PREPARE APPROVAL PAGE DATA
    // ================================================================

    /**
     * Prepare all data needed for the approval page template.
     * Consolidates application fetch, union info, license number generation,
     * business types, documents parsing, and union members.
     *
     * @param string  $applicationId     Application to approve
     * @param int|null $unionId          Current user's union ID
     * @param string|null $certificateType  From twig globals
     * @param string|null $certificateTypeBn From twig globals
     * @return array Prepared data for template rendering
     */
    public function prepareApprovalPageData(string $applicationId, ?int $unionId, ?string $certificateType = null, ?string $certificateTypeBn = null): array
    {
        $application = $this->getFullApplicationData($applicationId, $unionId, true);
        if (!$application) {
            return ['error' => 'Application not found.'];
        }

        $approval = $this->appManager->getApprovalByApplicationId($applicationId);

        $union = null;
        $union_code = null;
        if (!empty($application['union_id'])) {
            $unionInfo = $this->getUnionInfo((int)$application['union_id']);
            $union = $unionInfo[0] ?? null;
            $union_code = $unionInfo[1] ?? null;
        }

        $license_number = !empty($application['sonod_number'])
            ? $application['sonod_number']
            : generateSonodNumber('applications', $union_code);

        $businessTypes  = [];
        $ownershipTypes = [];
        if (($application['certificate_type'] ?? '') === 'trade') {
            $businessOwnership = new \BusinessOwnershipType($this->mysqli);
            $businessTypes     = $businessOwnership->getBusinessTypes();
            $ownershipTypes    = $businessOwnership->getOwnershipTypes();
        }

        $documents = isset($application['existing_documents'])
            ? json_decode($application['existing_documents'], true)
            : [];

        $unionMembers = [];
        if (!empty($application['union_id'])) {
            $unionMembers = $this->getUnionMembersForApproval((int)$application['union_id']);
        }

        $fiscal_year = null;
        if (!empty($application['business_meta']['fiscal_year'])) {
            $fiscal_year = $application['business_meta']['fiscal_year'];
        }

        return [
            'application'        => $application,
            'approval'           => $approval,
            'union'              => $union,
            'union_code'         => $union_code,
            'license_number'     => $license_number,
            'business_types'     => $businessTypes,
            'ownership_types'    => $ownershipTypes,
            'documents'          => $documents,
            'union_members'      => $unionMembers,
            'certificate_type'   => $certificateType,
            'certificate_type_bn' => $certificateTypeBn,
            'fiscal_year'        => $fiscal_year,
        ];
    }

    // ================================================================
    // FISCAL YEAR
    // ================================================================

    public function generateFiscalYearOptions(?string $selectedYear = null): array
    {
        return generateFiscalYearOptions($selectedYear);
    }
}
