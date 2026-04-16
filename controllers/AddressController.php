<?php
// controllers/AddressController.php

global $mysqli;

function getAddressById($address_id) {
    global $mysqli;
    header('Content-Type: application/json');
    if (empty($address_id) || !is_numeric($address_id)) {
        echo json_encode(['error' => 'Invalid address ID']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT id, type, village_en, village_bn, rbs_en, rbs_bn, holding_no, ward_no, district_en, district_bn, upazila_en, upazila_bn, union_en, union_bn, postoffice_en, postoffice_bn FROM address WHERE id = ?");
    $stmt->bind_param('i', $address_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $address = $result->fetch_assoc();
    $stmt->close();
    if ($address) {
        echo json_encode([
            'success' => true,
            'alert' => [
                'title' => 'সফল',
                'message' => 'ঠিকানা পাওয়া গেছে',
                'type' => 'success'
            ],
            'data' => $address
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title' => 'ত্রুটি',
                'message' => 'Address not found',
                'type' => 'error'
            ],
            'data' => null
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

global $router;

$router->get('/api/address/{address_id}', function($address_id) {
    global $mysqli;
    require_once __DIR__ . '/../helpers/rbac_helpers.php';
    ensure_can('manage_addresses');
    getAddressById($address_id);
});
