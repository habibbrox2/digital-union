<?php
// controllers/extraFieldsController.php



$extraFieldsModel = new ExtraFields($mysqli);

// ---------------- Admin Page ----------------
$router->get('/settings/extra-fields', function() use ($twig) {
    echo $twig->render('extra-fields/extra-fields.twig', [
        'title' => 'Extra Fields Management',
        'header_title' => 'Extra Fields Management'
    ]);
});
// Fetch certificate types for dropdown
$router->get('/api/certificate-types', function() use ($mysqli) {
    $sql = "SELECT slug, name_bn, name_en FROM term_translations WHERE is_certificate_type = 1 ORDER BY id";
    $result = $mysqli->query($sql);
    $types = [];
    while($row = $result->fetch_assoc()){
        $types[] = $row;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'success','data'=>$types], JSON_UNESCAPED_UNICODE);
});

// ---------------- Fetch all fields ----------------
$router->any('/api/extra-fields', function() use ($extraFieldsModel) {
    $certificate_type = sanitize_input($_GET['certificate_type'] ?? null); // null allows all types
    $fields = $extraFieldsModel->getAll($certificate_type); 
    echo json_encode(['status'=>'success','data'=>$fields]);
});


// ---------------- Save/update fields ----------------
$router->post('/api/extra-fields', function() use ($extraFieldsModel) {
    $input = json_decode(file_get_contents('php://input'), true);
    $certificate_type = sanitize_input($input['certificate_type'] ?? '');
    $fields = $input['fields'] ?? [];

    try {
        $extraFieldsModel->save($certificate_type, $fields);
        echo json_encode(['status'=>'success','alert'=>['type'=>'success','title'=>'সাফল্য','message'=>'ফিল্ডগুলি সফলভাবে সংরক্ষণ করা হয়েছে']]);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>$e->getMessage()]]);
    }
});

// ---------------- Fetch single field ----------------
$router->any('/api/extra-fields/{id}', function($id) use ($extraFieldsModel) {
    $field = $extraFieldsModel->getById($id);
    if ($field) {
        echo json_encode(['status'=>'success','data'=>$field]);
    } else {
        echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>'ফিল্ড পাওয়া যায়নি']]);
    }
});



// ---------------- Fetch all fields JSON ----------------
$router->any('/api/v2/extra-fields/json', function() use ($extraFieldsModel) {
    // Get all fields from DB
    $fields = $extraFieldsModel->getAll(null); // null = all certificate types

    $output = [];

    foreach ($fields as $f) {
        $type = $f['type'] ?? 'text';
        $certType = $f['certificate_type'];

        $field = [
            'id' => $f['field_id'],
            'label' => $f['label_bn'] ?? $f['label_en'] ?? '',
            'type' => $type
        ];

        if (!empty($f['placeholder'])) {
            $field['placeholder'] = $f['placeholder'];
        }
        if (!empty($f['count'])) {
            $field['count'] = (int)$f['count'];
        }
        if (!empty($f['order_after'])) {
            $field['orderAfter'] = $f['order_after'];
        }
        if ($type === 'select' && !empty($f['options'])) {
            $field['options'] = json_decode($f['options'], true);
        }

        // Initialize array for certificate type if not exists
        if (!isset($output[$certType])) $output[$certType] = [];

        $output[$certType][] = $field;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

