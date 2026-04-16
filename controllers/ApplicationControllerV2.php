<?php
// controllers/applicationControllerM.php

$router->any('/api/applications/search', function () {
    global $appmanager, $twig;

    header('Content-Type: application/json; charset=utf-8');

    $union_code = sanitize_input($_POST['union_code'] ?? '');
    $union = getUnionByCode($union_code);
    $union_id = $union['union_id'] ?? null;

    $identifier = $_POST['query'] ?? null;
    if (!$identifier) {
        echo json_encode([
            'status' => 'error',
            'message' => 'সার্চ ভ্যালু প্রদান করুন'
        ]);
        return;
    }

    $application = $appmanager->findApplicationByIdentifier($identifier, $union_id);

    if ($application) {
        echo json_encode([
            'status' => 'success',
            'data' => $application
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'কোনো তথ্য পাওয়া যায়নি'
        ]);
    }
});







$router->get(
    '/{certificate_type}/apply',
    function ($certificate_type = null) use ($twig, $auth, $mysqli) {

        $user = $auth->getUserData(false);
        $union_id = $user['union_id'] ?? null;

        // Fetch union info
        $union = null;
        if ($union_id) {
            $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
            $stmt->bind_param("i", $union_id);
            $stmt->execute();
            $union = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? '';
        $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;

        // ডিফল্ট ডেটা
        $merged_data = [
            'union_id' => $union_id,
            'members' => [],
        ];


        if ($certificate_type === 'trade') {
            $businessOwnership = new BusinessOwnershipType($mysqli);
            $merged_data['business_types'] = $businessOwnership->getBusinessTypes();
            $merged_data['ownership_types'] = $businessOwnership->getOwnershipTypes();
            $merged_data['business_meta'] = [
                'business_name' => '',
                'business_type' => '',
                'ownership_type' => '',
                'business_address' => '',
            ];
        }

        $tpl = 'applications/forms/' . basename(($certificate_type ?? '')) . '-v2.twig';
        if (!$twig->getLoader()->exists($tpl)) {
            $tpl = 'applications/forms/default-v2.twig';
        }

        echo $twig->render($tpl, [
            'title'        => $certificate_type_bn . ' - নতুন আবেদন',
            'header_title' => $certificate_type_bn . ' - নতুন আবেদন',
            'data'         => $merged_data,
            'union'        => $union,
            'extra_data'   => [], // নতুন আবেদনের জন্য খালি
        ]);
    }
);




$router->post('/applications/{certificate_type}/apply', function ($certificate_type = null) {
    global $mysqli, $appmanager, $auth;

    // CSRF is verified by middleware

    $union_code = sanitize_input($_POST['union_code'] ?? '');
    $union = getUnionByCode($union_code);
    $union_id = $union['union_id'] ?? null;

    // Present Address Insert
    $presentAddressId = sonod_address(
        'present',
        sanitize_input($_POST['present_village_en']),
        sanitize_input($_POST['present_village_bn']),
        sanitize_input($_POST['present_rbs_en']),
        sanitize_input($_POST['present_rbs_bn']),
        sanitize_input($_POST['present_holding_no']),
        sanitize_input($_POST['present_ward_no']),
        sanitize_input($_POST['present_district_en']),
        sanitize_input($_POST['present_district_bn']),
        sanitize_input($_POST['present_upazila_en']),
        sanitize_input($_POST['present_upazila_bn']),
        sanitize_input($_POST['present_union_en']),
        sanitize_input($_POST['present_union_bn']),
        sanitize_input($_POST['present_postoffice_en']),
        sanitize_input($_POST['present_postoffice_bn'])
    );
    $permanentAddressId = sonod_address(
        'permanent',
        sanitize_input($_POST['permanent_village_en']),
        sanitize_input($_POST['permanent_village_bn']),
        sanitize_input($_POST['permanent_rbs_en']),
        sanitize_input($_POST['permanent_rbs_bn']),
        sanitize_input($_POST['permanent_holding_no']),
        sanitize_input($_POST['permanent_ward_no']),
        sanitize_input($_POST['permanent_district_en']),
        sanitize_input($_POST['permanent_district_bn']),
        sanitize_input($_POST['permanent_upazila_en']),
        sanitize_input($_POST['permanent_upazila_bn']),
        sanitize_input($_POST['permanent_union_en']),
        sanitize_input($_POST['permanent_union_bn']),
        sanitize_input($_POST['permanent_postoffice_en']),
        sanitize_input($_POST['permanent_postoffice_bn'])
    );

    if (!$presentAddressId || !$permanentAddressId) {
        echo json_encode(['status' => 'error', 'message' => 'Address insertion failed']);
        return;
    }

    $nid        = convertBanglaToEnglishNumber(sanitize_input($_POST['nid']));
    $birth_id   = convertBanglaToEnglishNumber(sanitize_input($_POST['birth_id']));
    $passport_no = convertBanglaToEnglishNumber(sanitize_input($_POST['passport_no']));

    $birth_date = sanitize_input($_POST['birth_date']);
    $applicant_id   = generateApplicantId($nid, $birth_id, $passport_no, $union_code, $birth_date);
    $application_id = generateTrackingNumber($union_code);

    $uploadResult = handleApplicantFileUpload($application_id);
    $photoPath    = $uploadResult['photo'];
    $documents_json = $uploadResult['documents_json'];

    $certificate_type = isset($_POST['certificate_type']) ? sanitize_input($_POST['certificate_type']) : 'application';

    $data = [
        'application_id' => $application_id,
        'applicant_id' => $applicant_id,
        'certificate_type' => $certificate_type,
        'union_id' => $union_id,
        'sonod_number' => '',
        'name_en' => sanitize_input($_POST['name_en']),
        'name_bn' => sanitize_input($_POST['name_bn']),
        'nid' => $nid,
        'birth_id' => $birth_id,
        'passport_no' => $passport_no,
        'birth_date' => $birth_date,
        'gender' => sanitize_input($_POST['gender']),
        'father_name_en' => sanitize_input($_POST['father_name_en']),
        'father_name_bn' => sanitize_input($_POST['father_name_bn']),
        'mother_name_en' => sanitize_input($_POST['mother_name_en']),
        'mother_name_bn' => sanitize_input($_POST['mother_name_bn']),
        'occupation' => sanitize_input($_POST['occupation']),
        'resident' => sanitize_input($_POST['resident']),
        'educational_qualification' => sanitize_input($_POST['educational_qualification']),
        'religion' => sanitize_input($_POST['religion']),
        'marital_status' => sanitize_input($_POST['marital_status']),
        'spouse_name_en' => sanitize_input($_POST['spouse_name_en']),
        'spouse_name_bn' => sanitize_input($_POST['spouse_name_bn']),
        'applicant_name' => sanitize_input($_POST['applicant_name']),
        'applicant_phone' => sanitize_input($_POST['applicant_phone']),
        'applicant_photo' => $photoPath,
        'documents' => $documents_json,
        'present_address_id' => $presentAddressId,
        'permanent_address_id' => $permanentAddressId,
        'extra_data' => isset($_POST['extra_data']) ? $_POST['extra_data'] : null
    ];

    $mysqli->begin_transaction();
    try {
        if (!$appmanager->createApplication($data)) {
            throw new Exception('Application insertion failed');
        }

        // application_members insert
        $serial_nos       = $_POST['serial_no'] ?? [];
        $member_name_bns  = $_POST['warish_name_bn'] ?? [];
        $member_name_ens  = $_POST['warish_name_en'] ?? [];
        $relations        = $_POST['relation'] ?? [];
        $member_birth_dates = $_POST['member_birth_date'] ?? [];
        $relation_nids    = $_POST['relation_nid'] ?? [];
        $marriage_states  = $_POST['marrage_state'] ?? [];
        $is_deads         = $_POST['is_dead'] ?? [];

        $count = count($serial_nos);
        for ($i = 0; $i < $count; $i++) {
            $relation_value = $relations[$i] ?? '';
            if (!empty($relation_value) && strpos($relation_value, '|') !== false) {
                list($relation_bn, $relation_en) = explode('|', $relation_value);
            } else {
                $relation_bn = '';
                $relation_en = '';
            }
            if (empty($member_name_bns[$i]) && empty($relation_bn)) {
                continue;
            }
            $memberData = [
                'application_id'  => $application_id,
                'certificate_type' => $certificate_type,
                'name_en'         => sanitize_input($member_name_ens[$i]),
                'name_bn'         => sanitize_input($member_name_bns[$i]),
                'relation_en'     => $relation_en,
                'relation_bn'     => $relation_bn,
                'birth_date'      => sanitize_input($member_birth_dates[$i]),
                'nid'             => sanitize_input($relation_nids[$i]),
                'serial_no'       => intval($serial_nos[$i]),
                'marital_status'  => sanitize_input($marriage_states[$i]),
                'is_dead'         => ($is_deads[$i] === '1') ? '1' : '0'
            ];
            if (!$appmanager->addMember($memberData)) {
                throw new Exception('application_members insertion failed at index ' . $i);
            }
        }

        if ($certificate_type === 'trade') {

            // Business Address Insert
            $businessAddressId = sonod_address(
                'business',
                sanitize_input($_POST['business_village_en']),
                sanitize_input($_POST['business_village_bn']),
                sanitize_input($_POST['business_rbs_en']),
                sanitize_input($_POST['business_rbs_bn']),
                sanitize_input($_POST['business_holding_no']),
                sanitize_input($_POST['business_ward_no']),
                sanitize_input($_POST['business_district_en']),
                sanitize_input($_POST['business_district_bn']),
                sanitize_input($_POST['business_upazila_en']),
                sanitize_input($_POST['business_upazila_bn']),
                sanitize_input($_POST['business_union_en']),
                sanitize_input($_POST['business_union_bn']),
                sanitize_input($_POST['business_postoffice_en']),
                sanitize_input($_POST['business_postoffice_bn'])
            );

            // Business Type ID
            $business_type_id = intval($_POST['business_type'] ?? 0);


            // Business Type থেকে ফি ডেটা ফেচ
            $stmt = $mysqli->prepare("SELECT license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge 
                                        FROM business_type WHERE id = ?");
            $stmt->bind_param("i", $business_type_id);
            $stmt->execute();
            $fees = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$fees) {
                throw new Exception('Invalid Business Type ID or fees not found');
            }

            // Total Fee হিসাব
            $total_fee = ($fees['license_fee'] ?? 0) +
                ($fees['vat_amount'] ?? 0) +
                ($fees['occupation_tax'] ?? 0) +
                ($fees['income_tax'] ?? 0) +
                ($fees['signboard_tax'] ?? 0) +
                ($fees['surcharge'] ?? 0);

            $businessMetaData = [
                'business_name_en'    => sanitize_input($_POST['business_name_en'] ?? ''),
                'business_name_bn'    => sanitize_input($_POST['business_name_bn'] ?? ''),
                'ownership_type_id'   => intval($_POST['business_ownership_type'] ?? 0),
                'vat_id'              => sanitize_input($_POST['vat_id'] ?? ''),
                'tax_id'              => sanitize_input($_POST['tax_id'] ?? ''),
                'business_type_id'    => $business_type_id,
                'paid_up_capital'     => floatval($_POST['paid_up_capital'] ?? 0),
                'license_fee'         => $fees['license_fee'] ?? 0,
                'vat_amount'          => $fees['vat_amount'] ?? 0,
                'occupation_tax'      => $fees['occupation_tax'] ?? 0,
                'income_tax'          => $fees['income_tax'] ?? 0,
                'signboard_tax'       => $fees['signboard_tax'] ?? 0,
                'surcharge'           => $fees['surcharge'] ?? 0,
                'total_fee'           => $total_fee,
                'business_address_id' => $businessAddressId
            ];


            // Insert Business Meta
            if (!$appmanager->insertBusinessMeta($application_id, $businessMetaData)) {
                throw new Exception('Business meta insertion failed');
            }
        }

        $mysqli->commit();
        echo json_encode(['status' => 'success', 'alert' => ['type' => 'success', 'title' => 'সাফল্য', 'message' => 'আবেদন সফলভাবে জমা দেওয়া হয়েছে'], 'application_id' => $application_id, 'union_code' => $union_code]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
});



$router->get(
    '/applications/{certificate_type}/edit/{application_id}',
    function ($certificate_type = null, $application_id = null) {
        global $mysqli, $appmanager, $twig, $auth;

        $user = $auth->getUserData(false);
        $union_id = $user['union_id'] ?? null;

        $application = $appmanager->getApplicationByApplicationId($application_id, $union_id);
        if (!$application) {
            renderError(404, 'Application not found');
        }

        // Fetch union info
        $union = null;
        if (isset($application['union_id'])) {
            $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
            $stmt->bind_param("i", $application['union_id']);
            $stmt->execute();
            $union = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // merge data
        $merged_data = $application;


        $extra_data_json = $application['extra_data'] ?? '';
        $extra_data = !empty($extra_data_json) ? json_decode($extra_data_json, true) : [];



        if (($application['certificate_type'] ?? '') === 'trade') {
            $businessOwnership = new BusinessOwnershipType($mysqli);
            $merged_data['business_types'] = $businessOwnership->getBusinessTypes();
            $merged_data['ownership_types'] = $businessOwnership->getOwnershipTypes();
            $business_meta = $appmanager->getBusinessMetaByApplicationId($application_id) ?? [];
            $merged_data = array_merge($merged_data, $business_meta);
        }

        $merged_data['members'] = $appmanager->getMembersByApplication($application_id);

        $tpl = 'applications/forms/' . basename(($application['certificate_type'] ?? '')) . '-v2.twig';
        if (!$twig->getLoader()->exists($tpl)) {
            $tpl = 'applications/forms/default-v2.twig';
        }

        echo $twig->render($tpl, [
            'title'        => 'আবেদন সম্পাদনা',
            'header_title' => 'আবেদন সম্পাদনা',
            'data'         => $merged_data,
            'union'        => $union,
            'extra_data' => $extra_data,
        ]);
    }
);

$router->post('/applications/{certificate_type}/edit/{application_id}', function ($certificate_type = null, $application_id = null) {
    global $mysqli, $appmanager, $auth;

    // CSRF is verified by middleware

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $application = $appmanager->getApplicationByApplicationId($application_id);

    if (!$application) {
        echo json_encode(['status' => 'error', 'message' => 'Application not found']);
        return;
    }

    $sanitizeArray = fn($arr) => is_array($arr) ? array_map('sanitize_input', $arr) : [];

    $extra_data = $_POST['extra_data'] ?? '{}';

    if (is_string($extra_data)) {
        $extra_data = trim($extra_data);
        if ($extra_data === '') {
            $extra_data = '{}';
        } else {
            $decoded = json_decode($extra_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $extra_data = '{}';
            } else {
                $extra_data = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }
    } elseif (is_array($extra_data)) {
        $extra_data = json_encode($extra_data, JSON_UNESCAPED_UNICODE);
    } else {
        $extra_data = '{}';
    }

    // ===== Addresses =====
    $presentAddressId = sonod_address(
        'present',
        sanitize_input($_POST['present_village_en'] ?? ''),
        sanitize_input($_POST['present_village_bn'] ?? ''),
        sanitize_input($_POST['present_rbs_en'] ?? ''),
        sanitize_input($_POST['present_rbs_bn'] ?? ''),
        sanitize_input($_POST['present_holding_no'] ?? ''),
        sanitize_input($_POST['present_ward_no'] ?? ''),
        sanitize_input($_POST['present_district_en'] ?? ''),
        sanitize_input($_POST['present_district_bn'] ?? ''),
        sanitize_input($_POST['present_upazila_en'] ?? ''),
        sanitize_input($_POST['present_upazila_bn'] ?? ''),
        sanitize_input($_POST['present_union_en'] ?? ''),
        sanitize_input($_POST['present_union_bn'] ?? ''),
        sanitize_input($_POST['present_postoffice_en'] ?? ''),
        sanitize_input($_POST['present_postoffice_bn'] ?? ''),
        $application['present_address_id'] ?? null
    );
    $permanentAddressId = sonod_address(
        'permanent',
        sanitize_input($_POST['permanent_village_en'] ?? ''),
        sanitize_input($_POST['permanent_village_bn'] ?? ''),
        sanitize_input($_POST['permanent_rbs_en'] ?? ''),
        sanitize_input($_POST['permanent_rbs_bn'] ?? ''),
        sanitize_input($_POST['permanent_holding_no'] ?? ''),
        sanitize_input($_POST['permanent_ward_no'] ?? ''),
        sanitize_input($_POST['permanent_district_en'] ?? ''),
        sanitize_input($_POST['permanent_district_bn'] ?? ''),
        sanitize_input($_POST['permanent_upazila_en'] ?? ''),
        sanitize_input($_POST['permanent_upazila_bn'] ?? ''),
        sanitize_input($_POST['permanent_union_en'] ?? ''),
        sanitize_input($_POST['permanent_union_bn'] ?? ''),
        sanitize_input($_POST['permanent_postoffice_en'] ?? ''),
        sanitize_input($_POST['permanent_postoffice_bn'] ?? ''),
        $application['permanent_address_id'] ?? null
    );

    if (!$presentAddressId || !$permanentAddressId) {
        echo json_encode(['status' => 'error', 'message' => 'Address update failed']);
        return;
    }

    // ===== Main Data =====
    $data = [
        'name_en' => sanitize_input($_POST['name_en'] ?? ''),
        'name_bn' => sanitize_input($_POST['name_bn'] ?? ''),
        'nid' => sanitize_input($_POST['nid'] ?? ''),
        'birth_id' => sanitize_input($_POST['birth_id'] ?? ''),
        'passport_no' => sanitize_input($_POST['passport_no'] ?? ''),
        'birth_date' => sanitize_input($_POST['birth_date'] ?? ''),
        'gender' => sanitize_input($_POST['gender'] ?? ''),
        'father_name_en' => sanitize_input($_POST['father_name_en'] ?? ''),
        'father_name_bn' => sanitize_input($_POST['father_name_bn'] ?? ''),
        'mother_name_en' => sanitize_input($_POST['mother_name_en'] ?? ''),
        'mother_name_bn' => sanitize_input($_POST['mother_name_bn'] ?? ''),
        'occupation' => sanitize_input($_POST['occupation'] ?? ''),
        'resident' => sanitize_input($_POST['resident'] ?? ''),
        'educational_qualification' => sanitize_input($_POST['educational_qualification'] ?? ''),
        'religion' => sanitize_input($_POST['religion'] ?? ''),
        'marital_status' => sanitize_input($_POST['marital_status'] ?? ''),
        'spouse_name_en' => sanitize_input($_POST['spouse_name_en'] ?? ''),
        'spouse_name_bn' => sanitize_input($_POST['spouse_name_bn'] ?? ''),
        'applicant_name' => sanitize_input($_POST['applicant_name'] ?? ''),
        'applicant_phone' => sanitize_input($_POST['applicant_phone'] ?? ''),
        'applicant_photo' => !empty($_FILES['applicant_photo']['name'])
            ? handleApplicantFileUpload($application_id)['photo']
            : $application['applicant_photo'],
        'documents' => isset($_POST['documents'])
            ? $sanitizeArray($_POST['documents'])
            : json_decode($application['documents'], true),
        'extra_data' => $extra_data,
        'present_address_id' => $presentAddressId,
        'permanent_address_id' => $permanentAddressId
    ];

    $mysqli->begin_transaction();
    try {
        $appmanager->updateApplicationFixed($application_id, $data, $union_id);
        $appmanager->deleteMembersByApplication($application_id);

        // ===== Add Members =====
        $serial_nos        = $sanitizeArray($_POST['serial_no'] ?? []);
        $member_name_bns   = $sanitizeArray($_POST['warish_name_bn'] ?? []);
        $member_name_ens   = $sanitizeArray($_POST['warish_name_en'] ?? []);
        $relations         = $sanitizeArray($_POST['relation'] ?? []);
        $member_birth_dates = $sanitizeArray($_POST['member_birth_date'] ?? []);
        $relation_nids     = $sanitizeArray($_POST['relation_nid'] ?? []);
        $marriage_states   = $sanitizeArray($_POST['marrage_state'] ?? []);
        $is_deads          = $sanitizeArray($_POST['is_dead'] ?? []);

        for ($i = 0; $i < count($serial_nos); $i++) {
            $relation_bn = $relation_en = '';
            if (!empty($relations[$i]) && strpos($relations[$i], '|') !== false) {
                list($relation_bn, $relation_en) = explode('|', $relations[$i]);
            }
            if (empty($member_name_bns[$i]) && empty($relation_bn)) continue;

            $memberData = [
                'application_id' => $application_id,
                'certificate_type' => sanitize_input($certificate_type),
                'name_en' => $member_name_ens[$i] ?? '',
                'name_bn' => $member_name_bns[$i] ?? '',
                'relation_en' => $relation_en,
                'relation_bn' => $relation_bn,
                'birth_date' => $member_birth_dates[$i] ?? '',
                'nid' => $relation_nids[$i] ?? '',
                'serial_no' => intval($serial_nos[$i] ?? 0),
                'marital_status' => $marriage_states[$i] ?? '',
                'is_dead' => (!empty($is_deads[$i]) && $is_deads[$i] === '1') ? '1' : '0'
            ];

            $appmanager->addMember($memberData);
        }

        // ===== Trade Certificate =====
        if (sanitize_input($certificate_type) === 'trade') {
            // Business address + meta
            $businessAddressId = sonod_address(
                'business',
                sanitize_input($_POST['business_village_en'] ?? ''),
                sanitize_input($_POST['business_village_bn'] ?? ''),
                sanitize_input($_POST['business_rbs_en'] ?? ''),
                sanitize_input($_POST['business_rbs_bn'] ?? ''),
                sanitize_input($_POST['business_holding_no'] ?? ''),
                sanitize_input($_POST['business_ward_no'] ?? ''),
                sanitize_input($_POST['business_district_en'] ?? ''),
                sanitize_input($_POST['business_district_bn'] ?? ''),
                sanitize_input($_POST['business_upazila_en'] ?? ''),
                sanitize_input($_POST['business_upazila_bn'] ?? ''),
                sanitize_input($_POST['business_union_en'] ?? ''),
                sanitize_input($_POST['business_union_bn'] ?? ''),
                sanitize_input($_POST['business_postoffice_en'] ?? ''),
                sanitize_input($_POST['business_postoffice_bn'] ?? '')
            );

            $business_type_id = intval(sanitize_input($_POST['business_type'] ?? 0));
            $stmt = $mysqli->prepare("SELECT license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge FROM business_type WHERE id=?");
            $stmt->bind_param("i", $business_type_id);
            $stmt->execute();
            $fees = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $total_fee = array_sum($fees);

            $businessMetaData = [
                'business_name_en' => sanitize_input($_POST['business_name_en'] ?? ''),
                'business_name_bn' => sanitize_input($_POST['business_name_bn'] ?? ''),
                'ownership_type_id' => intval(sanitize_input($_POST['business_ownership_type'] ?? 0)),
                'vat_id' => sanitize_input($_POST['vat_id'] ?? ''),
                'tax_id' => sanitize_input($_POST['tax_id'] ?? ''),
                'business_type_id' => $business_type_id,
                'paid_up_capital' => floatval(sanitize_input($_POST['paid_up_capital'] ?? 0)),
                'license_fee' => $fees['license_fee'] ?? 0,
                'vat_amount' => $fees['vat_amount'] ?? 0,
                'occupation_tax' => $fees['occupation_tax'] ?? 0,
                'income_tax' => $fees['income_tax'] ?? 0,
                'signboard_tax' => $fees['signboard_tax'] ?? 0,
                'surcharge' => $fees['surcharge'] ?? 0,
                'total_fee' => $total_fee,
                'business_address_id' => $businessAddressId
            ];

            $appmanager->updateBusinessMeta($application_id, $businessMetaData);
        }

        $mysqli->commit();
        echo json_encode(['status' => 'success', 'alert' => ['type' => 'success', 'title' => 'সাফল্য', 'message' => 'আবেদন সফলভাবে আপডেট হয়েছে']]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => $e->getMessage()]]);
    }
});
$router->get(
    '/applications/{certificate_type}/reapply/{applicant_id}',
    function ($certificate_type = null, $applicant_id = null) {
        global $twig, $appmanager, $auth, $mysqli;



        $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
        $applicant_id     = sanitize_input($applicant_id);

        $reuse_data = $appmanager->getApprovedApplicationByApplicantId($applicant_id);

        if (!$reuse_data) {
            echo $twig->render('errors/error.twig', ['message' => 'Applicant not found.']);
            return;
        }



        if ($certificate_type === 'trade') {
            $businessOwnership = new BusinessOwnershipType($mysqli);
            $reuse_data['business_types']   = $businessOwnership->getBusinessTypes();
            $reuse_data['ownership_types']  = $businessOwnership->getOwnershipTypes();
        }

        echo $twig->render('applications/forms/default-v2.twig', [
            'data'                  => $reuse_data,
            'reuse_mode'            => true,
            'certificate_type'      => $certificate_type,
            'certificate_type_bn'   => $twig->getGlobals()['certificate_type_bn'] ?? null,
            'title'                 => 'আবেদন ফর্ম পূরণ করুন',
            'header_title'          => 'আবেদন ফর্ম পূরণ করুন',
        ]);
    }
);

// ===================== Application Approve Routes =====================
$router->get('/applications/{certificate_type}/approve/{application_id}', function ($certificate_type = null, $application_id = null) use ($twig, $mysqli, $auth, $appmanager, $unionModel) {


    $auth->requireLogin();
    $user      = $auth->getUserData(false);
    $union_id  = $user['union_id'] ?? null;

    // Module-scoped permission check
    ensure_can('manage_applications', 'applications');

    $certificate_type    = $twig->getGlobals()['certificate_type'] ?? null;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;

    $application = getFullApplicationData($application_id, $union_id, true);
    if (!$application) {
        die("Application not found.");
    }
    $approval = $appmanager->getApprovalByApplicationId($application_id);

    [$union, $union_code] = $unionModel->getInfo($application['union_id']);

    // লাইসেন্স নম্বর ঠিক করা
    $license_number = !empty($application['sonod_number'])
        ? $application['sonod_number']
        : generateSonodNumber('applications', $union_code);

    $businessTypes  = [];
    $ownershipTypes = [];
    if ($application['certificate_type'] === 'trade') {
        $businessOwnership = new BusinessOwnershipType($mysqli);
        $businessTypes     = $businessOwnership->getBusinessTypes();
        $ownershipTypes    = $businessOwnership->getOwnershipTypes();
    }

    $documents = isset($application['existing_documents'])
        ? json_decode($application['existing_documents'], true)
        : [];

    $unionMembers = [];

    if (!empty($application['union_id'])) {

        $stmt = $mysqli->prepare("
            SELECT 
                u.user_id,
                u.name_bn,
                u.name_en,
                u.role_id,
                u.phone_number AS phone,
                u.email,
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

        $stmt->bind_param("i", $application['union_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $roleNamesBn = [
            1 => 'অ্যাডমিনিস্ট্রেটর',
            2 => 'সচিব',
            3 => 'চেয়ারম্যান',
            4 => 'মেম্বার',
            5 => 'কম্পিউটার অপারেটর',
            6 => 'গ্রাম পুলিশ',
            7 => 'অফিস সহকারী',
        ];
        while ($row = $result->fetch_assoc()) {
            $roleId = (int)$row['role_id'];

            // Initialize role group if not exists
            if (!isset($unionMembers[$roleId])) {
                $unionMembers[$roleId] = [
                    'role_name' => $roleNamesBn[$roleId] ?? $row['role_name'] ?? 'Unknown Role',
                    'role_id' => $roleId,
                    'members' => []
                ];
            }

            // Add member to the role group
            $unionMembers[$roleId]['members'][] = [
                'user_id' => $row['user_id'] ?? 0,
                'name_bn' => $row['name_bn'] ?? '',
                'name_en' => $row['name_en'] ?? '',
                'phone' => $row['phone'] ?? '',
                'email' => $row['email'] ?? ''
            ];
        }

        $stmt->close();
    }




    echo $twig->render('applications/approve-page.twig', [
        'title'              => 'আবেদন অনুমোদন ফর্ম',
        'header_title'       => 'অনুমোদন ফর্ম',
        'data'               => $application,
        'approval'           => $approval,
        'documents'          => $documents,
        'union'              => $union,
        'business_meta'      => $application['business_meta'] ?? null,
        'business_types'     => $businessTypes,
        'ownership_types'    => $ownershipTypes,
        'license_number'     => $license_number,
        'certificate_type'   => $certificate_type,
        'certificate_type_bn' => $certificate_type_bn,
        'extra_data'         => $application['extra_data'] ?? [],
        'union_members'      => $unionMembers,
    ]);
});

$router->post('/applications/{certificate_type}/approve/{application_id}', function ($certificate_type = null, $application_id = null) {
    global $appmanager, $mysqli, $auth;

    // CSRF is verified by middleware

    $auth->requireLogin();
    $user     = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    // Module-scoped permission check
    ensure_can('manage_applications', 'applications');

    // Superadmin if role_id <= 1
    $isSuperAdmin = (isset($user['role_id']) && $user['role_id'] <= 1);
    // সুপারঅ্যাডমিন নয় এবং union_id ফাঁকা হলে
    if (!$isSuperAdmin && empty($union_id)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'আপনার ইউনিয়ন আইডি পাওয়া যায়নি। অনুমোদন সম্ভব নয়।'
        ]);
        return;
    }
    $lookupUnion  = $isSuperAdmin ? null : $union_id;

    $application = $appmanager->getApplication($application_id, $lookupUnion);
    if (!$application) {
        echo json_encode(['status' => 'error', 'message' => 'আবেদন পাওয়া যায়নি।']);
        return;
    }

    $certificate_type = $application['certificate_type'] ?? 'application';

    $union_code = null;
    if (!empty($application['union_id'])) {
        // Fetch union info
        global $mysqli;
        $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
        $stmt->bind_param("i", $application['union_id']);
        $stmt->execute();
        $union = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($union && !empty($union['union_code'])) {
            $union_code = $union['union_code'];
        }
    }

    if (empty($_POST['approval_date'])) {
        echo json_encode(['status' => 'error', 'message' => 'অনুগ্রহ করে অনুমোদনের তারিখ নির্বাচন করুন।']);
        return;
    }

    $sonod_number = !empty($_POST['license_number'])
        ? sanitize_input($_POST['license_number'])
        : generateSonodNumber('applications', $union_code);

    // 🟢 Date conversion directly from POST (sanitize_input kept for text fields only)
    $approval_date = null;
    if (!empty($_POST['approval_date'])) {
        $approvalDateTime = DateTime::createFromFormat('d-m-Y', $_POST['approval_date']);
        $approval_date = $approvalDateTime ? $approvalDateTime->format('Y-m-d H:i:s') : null;
    }

    $verification_date = null;
    if (!empty($_POST['verification_date'])) {
        $verificationDateTime = DateTime::createFromFormat('d-m-Y', $_POST['verification_date']);
        $verification_date = $verificationDateTime ? $verificationDateTime->format('Y-m-d') : null;
    }

    $data = [
        'verifier_id'          => sanitize_input($_POST['verifier_id'] ?? ''),
        'verifier_designation' => sanitize_input($_POST['verifier_designation'] ?? ''),
        'verifier_contact'     => sanitize_input($_POST['verifier_contact'] ?? ''),
        'verifier_name_bn'     => sanitize_input($_POST['verifier_name_bn'] ?? ''),
        'verifier_name_en'     => sanitize_input($_POST['verifier_name_en'] ?? ''),
        'verifier_ward_no'     => sanitize_input($_POST['verifier_ward_no'] ?? ''),
        'verification_date'    => $verification_date,
        'verification_note'    => sanitize_input($_POST['verification_note'] ?? ''),
        'approver_id'          => sanitize_input($_POST['approver_id'] ?? ''),
        'approver_name_bn'     => sanitize_input($_POST['approver_name_bn'] ?? ''),
        'approver_name_en'     => sanitize_input($_POST['approver_name_en'] ?? ''),
        'approver_ward_no'     => sanitize_input($_POST['approver_ward_no'] ?? ''),
        'approval_date'        => $approval_date,
        'approval_note'        => sanitize_input($_POST['approval_note'] ?? ''),
        'certificate_fee'      => isset($_POST['certificate_fee']) && $_POST['certificate_fee'] !== '' ? floatval($_POST['certificate_fee']) : NULL,
        'payment_method'       => sanitize_input($_POST['payment_method'] ?? ''),
        'payment_status'       => sanitize_input($_POST['payment_status'] ?? ''),
        'certificate_type'     => $certificate_type,
        'sonod_number'         => $sonod_number,
    ];

    $mysqli->begin_transaction();
    try {
        $result = $appmanager->approveApplication($application_id, $data, $sonod_number, $union_id, $certificate_type);

        if ($certificate_type === 'trade') {
            $fiscal_year = isset($_POST['fiscal_year']) ? sanitize_input($_POST['fiscal_year']) : null;
            $businessMetaData = [
                'license_fee'       => isset($_POST['license_fee']) ? floatval($_POST['license_fee']) : NULL,
                'vat_amount'        => isset($_POST['vat_amount']) ? floatval($_POST['vat_amount']) : NULL,
                'occupation_tax'    => isset($_POST['occupation_tax']) ? floatval($_POST['occupation_tax']) : NULL,
                'income_tax'        => isset($_POST['income_tax']) ? floatval($_POST['income_tax']) : NULL,
                'signboard_tax'     => isset($_POST['signboard_tax']) ? floatval($_POST['signboard_tax']) : NULL,
                'surcharge'         => isset($_POST['surcharge']) ? floatval($_POST['surcharge']) : NULL,
                'total_fee'         => isset($_POST['total_fee']) ? floatval($_POST['total_fee']) : NULL,
                'fiscal_year'       => $fiscal_year,
                'ownership_type_id' => isset($_POST['ownership_type_id']) ? intval($_POST['ownership_type_id']) : NULL,
                'business_type_id'  => isset($_POST['business_type_id']) ? intval($_POST['business_type_id']) : NULL,
            ];

            if ($fiscal_year) {
                $parts = explode('-', $fiscal_year);
                $businessMetaData['expiry_date'] = isset($parts[1])
                    ? trim($parts[1]) . '-06-30'
                    : null;
            }

            $existingMeta = $appmanager->getBusinessMetaByApplicationId($application_id);
            $businessUpdateResult = $existingMeta
                ? $appmanager->updateBusinessMeta($application_id, $businessMetaData)
                : $appmanager->insertBusinessMeta($application_id, $businessMetaData);

            if (!$businessUpdateResult['status']) {
                throw new Exception($businessUpdateResult['message'] ?? 'Business meta update failed');
            }
        }

        $mysqli->commit();
        echo json_encode([
            'status'       => 'success',
            'message'      => 'আবেদন সফলভাবে অনুমোদিত হয়েছে।',
            'sonod_number' => $sonod_number
        ]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode([
            'status'  => 'error',
            'message' => 'অনুমোদনে সমস্যা হয়েছে: ' . $e->getMessage()
        ]);
    }
});


$router->get('/applications/{certificate_type}/delete', function ($certificate_type = null) use ($appmanager, $auth, $mysqli) {
    // User login check
    $auth->requireLogin();
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    // Module-scoped permission check
    ensure_can('delete', 'applications');

    // Get application ID from POST
    $applicationId = $_POST['applicationId'] ?? null;
    if (!$applicationId) {
        echo json_encode(['status' => 'error', 'message' => 'অ্যাপ্লিকেশন আইডি প্রয়োজন।']);
        return;
    }

    // Restrict by union unless super admin
    $lookupUnion = (isset($user['role_id']) && $user['role_id'] <= 1) ? null : $union_id;

    // Call delete method (boolean return)
    $deleted = $appmanager->deleteApplication($applicationId, $lookupUnion);

    if ($deleted) {
        echo json_encode(['status' => 'success', 'message' => 'আবেদন মুছে ফেলা হয়েছে।']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'কোনো আবেদন মুছে যায়নি বা পাওয়া যায়নি।']);
    }
});


$router->get(
    '/verify/{url_path}_bn/{sonod_number}/{union_code}/{rmo_code}',
    function ($url_path = null, $sonod_number = null, $union_code = null, $rmo_code = null) {

        global $appmanager, $mysqli, $twig;

        // ================= Validation =================
        if (empty($sonod_number)) {
            renderError(400, 'Sonod number is required.');
            return;
        }

        if (empty($url_path)) {
            renderError(400, 'Certificate type is missing.');
            return;
        }

        // ================= Certificate Type =================
        // url_path = trade, warish, citizenship etc
        $certificate_type = sanitize_input($url_path);
        $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;

        // ================= Union =================
        $union = null;
        if (!empty($union_code)) {
            $union = getUnionByCode(sanitize_input($union_code));
        }

        // ================= Application =================
        $application = $appmanager->getapplicationbysonodnumber(
            sanitize_input($sonod_number),
            $certificate_type,
            $union['union_id'] ?? null
        );

        if (!$application) {
            renderError(404, 'এই সনদ নম্বরের কোনো তথ্য পাওয়া যায়নি।');
            return;
        }

        // ================= Approval =================
        $approval = !empty($application['application_id'])
            ? $appmanager->getApprovalByApplicationId($application['application_id'])
            : null;

        // ================= Members =================
        if (!empty($application['application_id'])) {
            $members = $appmanager->getMembersByApplication($application['application_id']);
            if (!empty($members)) {
                $application['warish_members'] = $members;
            }
        }

        // ================= Trade License Extra =================
        $business_meta = null;
        if ($certificate_type === 'trade' && !empty($application['application_id'])) {
            $business_meta = $appmanager->getBusinessMetaByApplicationId(
                $application['application_id']
            );
        }

        // ================= Extra JSON =================
        if (!empty($application['extra_data'])) {
            $application['extra'] = json_decode($application['extra_data'], true);
        }

        // ================= Template =================
        $template = templatePath(
            'applications/online-verify/bangla',
            $certificate_type
        );

        // ================= Render =================
        echo $twig->render($template, [
            'title'               => 'সনদ যাচাই',
            'header_title'        => 'সনদ যাচাই',
            'citizen'             => data($application),
            'union'               => $union,
            'certificate_type'    => $certificate_type,
            'certificate_type_bn' => $certificate_type_bn,
            'union_code'          => $union_code,
            'rmo_code'            => $rmo_code,
            'approval'            => $approval,
            'business_meta'       => $business_meta,
        ]);
    }
);

$router->post('/api/check/existing/application', function () {

    global $appmanager;

    // ================= Input Validation =================
    $input = json_decode(file_get_contents('php://input'), true);

    $searchData      = trim($input['searchData'] ?? '');
    $applicationType = trim($input['applicationType'] ?? '');
    $typeIndex       = trim($input['type'] ?? ''); // numeric: 1,2,3 ...
    $call_from       = trim($input['call_from'] ?? 'web');

    // ================= Type Mapping =================
    $TypeApplication = [
        'nagorik',
        'death',
        'obibahito',
        'punobibaho',
        'ekoinam',
        'sonaton',
        'prottyon',
        'nodibanga',
        'character',
        'vumihin',
        'yearlyincome',
        'protibondi',
        'onumoti',
        'voter',
        'onapotti',
        'rastakhonon',
        'warish',
        'family',
        'trade',
        'bibahito'
    ];

    // Numeric type to string
    $typeIndex = intval($typeIndex);
    if ($typeIndex < 1 || $typeIndex > count($TypeApplication)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid type.'
        ]);
        return;
    }

    $type = $TypeApplication[$typeIndex - 1]; // map 1→'nagorik', 2→'death', ...

    if (empty($searchData) || empty($applicationType)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Required fields are missing.'
        ]);
        return;
    }

    // ================= Application Lookup =================
    if (intval($applicationType) === 2) {
        // Sonod check
        $application = $appmanager->getapplicationbysonodnumber($searchData, $type);
        if ($application) {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'sonod_no' => $application['sonod_no'] ?? null,
                    'pin'      => $application['pin'] ?? null,
                    'union_id' => $application['union_id'] ?? null,
                    'type'     => $type
                ]
            ]);
            return;
        }
    } elseif (intval($applicationType) === 1) {
        // Tracking check
        $application = $appmanager->getApplicationByApplicationId($searchData, $type);
        if ($application) {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'tracking' => $application['tracking'] ?? null,
                    'pin'      => $application['pin'] ?? null,
                    'union_id' => $application['union_id'] ?? null,
                    'type'     => $type
                ]
            ]);
            return;
        }
    }

    // ================= Not Found =================
    echo json_encode([
        'status' => 'error',
        'message' => 'এই সনদ/আবেদন নম্বরের কোনো তথ্য পাওয়া যায়নি।'
    ]);
});


$router->any('/api2/check/existing/application', function () {

    header("Content-Type: application/json");

    // Get POST data from frontend
    $input = file_get_contents('php://input'); // read raw body
    $data = json_decode($input, true);

    if (!$data) {
        // fallback for form-encoded data
        $data = $_POST;
    }

    // Validate required fields
    if (empty($data['searchData']) || empty($data['applicationType']) || empty($data['type'])) {
        echo json_encode([
            "status" => "error",
            "message" => "Required fields are missing."
        ]);
        exit;
    }

    // Initialize cURL
    $ch = curl_init("https://admin.lgdhaka.com/api/check/exiting/application");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Set headers for JSON
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Accept: application/json"
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode([
            "status" => "error",
            "message" => "cURL Error: $err"
        ]);
        exit;
    }

    // Decode response
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        // HTML or non-JSON detected
        echo json_encode([
            "status" => "error",
            "message" => "API returned non-JSON response.",
            "raw_response" => $response
        ]);
    } else {
        // Return decoded JSON to frontend
        echo json_encode($decoded);
    }
});
