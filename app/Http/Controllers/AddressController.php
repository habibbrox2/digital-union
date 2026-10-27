<?php
/**
 * controllers/AddressController.php
 * 
 * Address routes - uses closures only.
 * All DB logic is in the model classes.
 */

global $router, $mysqli;

$authService = new AuthService($mysqli);
$addressModel = new AddressModel($mysqli);

$router->get('/api/address/{address_id}', function($address_id) use ($addressModel, $authService) {
    $authService->ensureCan('manage_addresses');
    header('Content-Type: application/json');

    if (empty($address_id) || !is_numeric($address_id)) {
        echo json_encode(['error' => 'Invalid address ID']);
        exit;
    }

    $address = $addressModel->getById((int)$address_id);

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
});
