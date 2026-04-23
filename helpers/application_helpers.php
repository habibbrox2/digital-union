<?php
// helpers/application_helpers.php
// Helper functions moved from controllers/ApplicationController.php

if (!function_exists('si')) {
    function si($val)
    {
        return sanitize_input($val ?? '');
    }
}

if (!function_exists('respondError')) {
    function respondError($msg)
    {
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    }
}

if (!function_exists('convertBanglaToEnglishNumber')) {
    function convertBanglaToEnglishNumber($string)
    {
        $bangla  = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($bangla, $english, $string);
    }
}
if (!function_exists('explodeRelation')) {
    function explodeRelation($value)
    {
        return strpos($value, '|') !== false
            ? array_map('trim', explode('|', $value, 2))
            : ['', ''];
    }
}

if (!function_exists('updateAddress')) {
    function updateAddress($type, $existing_id = null)
    {
        return sonod_address(
            $type,
            si($_POST["{$type}_village_en"]),
            si($_POST["{$type}_village_bn"]),
            si($_POST["{$type}_rbs_en"]),
            si($_POST["{$type}_rbs_bn"]),
            si($_POST["{$type}_holding_no"]),
            si($_POST["{$type}_ward_no"]),
            si($_POST["{$type}_district_en"]),
            si($_POST["{$type}_district_bn"]),
            si($_POST["{$type}_upazila_en"]),
            si($_POST["{$type}_upazila_bn"]),
            si($_POST["{$type}_union_en"]),
            si($_POST["{$type}_union_bn"]),
            si($_POST["{$type}_postoffice_en"]),
            si($_POST["{$type}_postoffice_bn"]),
            $existing_id
        );
    }
}

if (!function_exists('insertMembers')) {
    function insertMembers($application_id, $certificate_type)
    {
        global $appmanager;

        $serials = $_POST['serial_no'] ?? [];
        $names_bn = $_POST['warish_name_bn'] ?? [];
        $names_en = $_POST['warish_name_en'] ?? [];
        $relations = $_POST['relation_bn_en'] ?? [];
        $births = $_POST['member_birth_date'] ?? [];
        $nids = $_POST['relation_nid'] ?? [];
        $marriage = $_POST['marrage_state'] ?? [];
        $deads = $_POST['is_dead'] ?? [];

        $count = count($serials);
        if ($count !== count($names_bn) || $count !== count($names_en)) {
            throw new Exception("Member data arrays are inconsistent");
        }

        for ($i = 0; $i < $count; $i++) {
            [$relation_bn, $relation_en] = explodeRelation($relations[$i] ?? '');

            $memberData = [
                'application_id' => $application_id,
                'certificate_type' => $certificate_type,
                'name_en' => si($names_en[$i]),
                'name_bn' => si($names_bn[$i]),
                'relation_en' => $relation_en,
                'relation_bn' => $relation_bn,
                'birth_date' => si($births[$i]),
                'nid' => si($nids[$i]),
                'gender' => null,
                'occupation' => null,
                'mobile' => null,
                'serial_no' => intval($serials[$i]),
                'address' => null,
                'marital_status' => si($marriage[$i]),
                'is_dead' => ($deads[$i] === '1') ? '1' : '0'
            ];

            if (!$appmanager->addMember($memberData)) {
                throw new Exception("Failed to add member: {$memberData['name_bn']}");
            }
        }
    }
}

if (!function_exists('updateBusinessMeta')) {
    function updateBusinessMeta($application_id, $existing_address_id)
    {
        global $mysqli, $appmanager;

        $address_id = updateAddress('business', $existing_address_id);
        $type_id = intval($_POST['business_type']);

        $stmt = $mysqli->prepare("SELECT license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge FROM business_type WHERE id = ?");
        $stmt->bind_param("i", $type_id);
        $stmt->execute();
        $fees = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fees) throw new Exception("Invalid business type");

        $total = array_sum($fees);

        $meta = [
            'business_name_en' => si($_POST['business_name_en']),
            'business_name_bn' => si($_POST['business_name_bn']),
            'ownership_type_id' => intval($_POST['business_ownership_type'] ?? 0),
            'vat_id' => si($_POST['vat_id']),
            'tax_id' => si($_POST['tax_id']),
            'business_type_id' => $type_id,
            'paid_up_capital' => floatval($_POST['paid_up_capital']),
            'license_fee' => $fees['license_fee'],
            'vat_amount' => $fees['vat_amount'],
            'occupation_tax' => $fees['occupation_tax'],
            'total_fee' => $total,
            'business_address_id' => $address_id
        ];

        if (!$appmanager->updateBusinessMeta($application_id, $meta)) {
            throw new Exception("Business meta update failed");
        }
    }
}

if (!function_exists('templatePath')) {
    function templatePath($base_dir, $type, $default = 'default.twig')
    {
        if (!empty($type) && $type !== 'application') {
            $custom_template = "{$base_dir}/{$type}.twig";
            $custom_template_path = __DIR__ . "/../templates/{$custom_template}";
            if (file_exists($custom_template_path)) {
                return $custom_template;
            }
        }
        return "{$base_dir}/{$default}";
    }
}

if (!function_exists('getFullApplicationData')) {
    function getFullApplicationData($application_id, $union_id = null, $includeMeta = false)
    {
        global $appmanager;

        $application = $appmanager->getApplicationByApplicationId($application_id, $union_id);
        if (!$application) return null;
        if (!empty($application['extra_data'])) {
            $decoded = json_decode($application['extra_data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $application['extra_data'] = $decoded;
            } else {
                $application['extra_data'] = [];
            }
        }
        if ($includeMeta) {
            if ($application['certificate_type'] === 'trade') {
                $application['business_meta'] = $appmanager->getBusinessMetaByApplicationId($application_id);
            }
            if ($application['certificate_type'] === 'warish') {
                $application['warish_members'] = $appmanager->getMembersByApplication($application_id);
            }
        }

        return $application;
    }
}

if (!function_exists('formatApplicationId')) {
    function formatApplicationId($application_id, $length = 6)
    {
        return str_pad($application_id, $length, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('calculateAge')) {
    function calculateAge($birth_date, $reference_date = null)
    {
        $birth = new DateTime($birth_date);
        $ref = $reference_date ? new DateTime($reference_date) : new DateTime();
        $age = $ref->diff($birth);
        return $age->y;
    }
}

if (!function_exists('deleteApplication')) {
    function deleteApplication($application_id)
    {
        global $appmanager;

        if (!$appmanager->deleteApplication($application_id)) {
            throw new Exception("Failed to delete application ID: {$application_id}");
        }
    }
}

if (!function_exists('fetchApplicationById')) {
    function fetchApplicationById($application_id, $union_id = null)
    {
        global $appmanager;

        return $appmanager->getApplicationByApplicationId($application_id, $union_id);
    }
}

if (!function_exists('applicationRejectForm')) {
    function applicationRejectForm($application_id, $reason)
    {
        global $appmanager;

        if (!$appmanager->rejectApplication($application_id, $reason)) {
            throw new Exception("Failed to reject application ID: {$application_id}");
        }
    }
}

/**
 * Generate fiscal year options dynamically
 * Generate from current year - 1 to current year + 2
 * 
 * @param string $selectedYear - Currently selected fiscal year (optional)
 * @return array - Array of fiscal year options
 */
if (!function_exists('generateFiscalYearOptions')) {
    function generateFiscalYearOptions($selectedYear = null)
    {
        $currentYear = (int)date('Y');
        $minYear = 2022; // Minimum fiscal year starting from 2022-2023
        $startYear = max($currentYear - 5, $minYear); // Don't go below 2022
        $endYear = $currentYear + 3; // End at current year + 3 to include next fiscal year

        $options = [];
        for ($year = $startYear; $year < $endYear; $year++) {
            $fiscalYear = $year . '-' . ($year + 1);
            $options[] = [
                'value' => $fiscalYear,
                'label' => $fiscalYear,
                'selected' => ($fiscalYear === $selectedYear)
            ];
        }

        return $options;
    }
}
