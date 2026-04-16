<?php
// controllers/BirthController.php
    
    $birthModel = new BirthApplicationModel($mysqli);
    
    
    
    
    // ===================== ROUTES =====================
    
    // =======================
    // 1️⃣ Updated List Route
    // =======================
    $router->get('/birth', function() use ($twig) {
        ensure_can('manage_births');
        // আগের মতো Twig render হবে, কিন্তু table এখন API থেকে load করবে
        echo $twig->render('pdf/birth/list.twig', [
            'title' => 'জন্ম নিবন্ধন তালিকা',
            'header_title' => 'জন্ম নিবন্ধন তালিকা'
        ]);
    });
    
    // =======================
    // 2️⃣ New API Route
    // =======================
    $router->get('/api/births', function() use ($birthModel) {
        header('Content-Type: application/json; charset=utf-8');
    
        // সমস্ত birth records getAll() method থেকে fetch
        $births = $birthModel->getAll();
    
        // যদি চান, এখানে data কাস্টমাইজ করা যায় (dates, etc)
        $data = array_map(function($b) {
            return [
                'id'                => $b['id'],
                'registration_number'=> $b['registration_number'],
                'name_bn'           => $b['name_bn'],
                'father_name_bn'    => $b['father_name_bn'],
                'mother_name_bn'    => $b['mother_name_bn'],
                'birth_date'        => $b['birth_date'],
                'office_name_en'    => $b['office_name_en'],
                'office_name_bn'    => $b['office_name_bn'],
                'district_bn'       => $b['district_bn'],
                'country_bn'        => $b['country_bn'],
                'permanent_address_bn' => $b['permanent_address_bn']
            ];
        }, $births);
    
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    });
    
    
    // 👉 Show Form (New)
    $router->get('/birth/form', function() use ($twig) {
        ensure_can('manage_births');
        echo $twig->render('pdf/birth/form.twig', [
             'title' => 'নতুন জন্ম নিবন্ধন ফরম',
            'header_title' => 'নতুন জন্ম নিবন্ধন ফরম',
            'birth' => []
        ]);
    });
    
    
    
    
    
    
    // 👉 Edit Form
    $router->get('/birth/edit/{id}', function($id) use ($twig, $birthModel) {
        ensure_can('manage_births');
        $birth = $birthModel->getById((int)$id);
        if (!$birth) {
            echo "<div class='alert alert-danger text-center my-5'>❌ জন্ম নিবন্ধন রেকর্ড পাওয়া যায়নি।</div>";
            return;
        }
        echo $twig->render('pdf/birth/form.twig', [
        	'title' => 'জন্ম নিবন্ধন সম্পাদনা',
            'header_title' => 'জন্ম নিবন্ধন সম্পাদনা',
            'birth' => $birth
        ]);
    });
    // 👉 Unified Save (Insert or Update)
    $router->post('/birth/save', function() use ($birthModel) {
        ensure_can('manage_births');
        header('Content-Type: application/json; charset=utf-8');
        $alert = ['type' => 'danger', 'message' => ''];
    
        try {
            // Verify CSRF token
                // CSRF is verified by middleware
    
            $id = (int)($_POST['id'] ?? 0);
            $data = [];
            $fields = [
                'registration_number','date_of_registration','date_of_issuance',
                'name_bn','name_en','sex','birth_date',
                'father_name_bn','father_name_en','father_nationality_bn','father_nationality_en',
                'mother_name_bn','mother_name_en','mother_nationality_bn','mother_nationality_en',
                'district_office_bn','district_office_en','upazila_office_bn','upazila_office_en',
                'union_office_bn','union_office_en',
                'office_name_bn','office_name_en','country_en','country_bn',
                'district_bn','district_en','permanent_address_bn','permanent_address_en',
                'verify_key','verify_link'
            ];
    
            foreach ($fields as $f) {
                $data[$f] = sanitize_input($_POST[$f] ?? '');
            }
    
            if (empty($data['name_bn']) || empty($data['birth_date'])) {
                throw new Exception('নাম ও জন্মতারিখ প্রদান বাধ্যতামূলক।');
            }
    
            // Convert birth_date (if formatted as d-m-Y) to Y-m-d for DB
            $bd = DateTime::createFromFormat('d-m-Y', $data['birth_date']);
            if ($bd) $data['birth_date'] = $bd->format('Y-m-d');
    
            $dateWords = dateToWords($data['birth_date']);
            $data['birth_date_words_bn'] = $dateWords['bn'];
            $data['birth_date_words_en'] = $dateWords['en'];
    
            if ($id > 0) {
                // UPDATE
                $updated = $birthModel->update($id, $data);
                if (!$updated) throw new Exception('আপডেট ব্যর্থ হয়েছে!');
                $alert = ['type' => 'success', 'message' => '✅ তথ্য সফলভাবে আপডেট হয়েছে।'];
                $pdf_url = '/birth/pdf/'.$id;
            } else {
                // CREATE
                $newId = $birthModel->create($data);
                if (!$newId) throw new Exception('ডাটাবেজে তথ্য সংরক্ষণ ব্যর্থ হয়েছে!');
                $alert = ['type' => 'success', 'message' => '✅ নতুন তথ্য সফলভাবে সংরক্ষিত হয়েছে।'];
                $pdf_url = '/birth/pdf/'.$newId;
            }
    
        } catch (Throwable $e) {
            $alert['message'] = $e->getMessage() ?: '⚠️ সার্ভার ত্রুটি ঘটেছে।';
            error_log('Birth Save Error: '.$e->getMessage());
        }
    
        echo json_encode(['alert' => $alert, 'pdf_url' => $pdf_url ?? null], JSON_UNESCAPED_UNICODE);
    });
    
    // 👉 PDF Generator
    $router->get('/birth/pdf/{id}', function($id) use ($twig, $birthModel) {
        $app = $birthModel->getById((int)$id);
        if(!$app){ echo "Record not found."; return; }
    
        $tmpDir = __DIR__ . '/../public/tmp/';
        if(!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    
        $qrPath = $tmpDir . "qr-{$id}.png";
        $barcodePath = $tmpDir . "barcode-{$id}.png";
    
        $verifyLink = $app['verify_link'] ?? "https://lgdhaka.gov.bd/verify/birth/{$app['id']}";
        generate_qr($verifyLink, $qrPath);
    
        $registrationNumber = $app['registration_number'] ?? 'UNKNOWN';
        generate_barcode($registrationNumber, $barcodePath);
    
        $html = $twig->render('pdf/birth/birth_certificate.twig', [
            'data' => $app,
            'qr' => $qrPath,
            'barcode' => $barcodePath,
            'title' => 'জন্ম নিবন্ধন সনদ',
            'header_title' => 'জন্ম নিবন্ধন সনদ'
        ]);
    
        birthPdf($html, $app['registration_number'], '', __DIR__.'/../public/assets/birth-bg.png', false);
    });
    
    // 👉 Delete
    $router->post('/birth/delete/{id}', function($id) use ($birthModel) {
        ensure_can('manage_births');
        header('Content-Type: application/json; charset=utf-8');
        $alert = ['type' => 'danger', 'message' => ''];
    
        try {
            // Verify CSRF token
                // CSRF is verified by middleware

            $id = (int)$id;
            if ($id <= 0) throw new Exception('অবৈধ আইডি প্রদান করা হয়েছে।');
    
            $deleted = $birthModel->delete($id);
            if (!$deleted) throw new Exception('রেকর্ড মুছে ফেলা ব্যর্থ হয়েছে।');
    
            $alert = ['type' => 'success', 'message' => '✅ রেকর্ড সফলভাবে মুছে ফেলা হয়েছে।'];
        } catch (Throwable $e) {
            $alert['message'] = $e->getMessage() ?: '⚠️ মুছার সময় ত্রুটি ঘটেছে।';
            error_log('Birth Delete Error: '.$e->getMessage());
        }
    
        echo json_encode(['alert' => $alert], JSON_UNESCAPED_UNICODE);
    });
    
    
    
    /* ================================================================
       ==========  BDRIS Integration (Updated for MVC)  ===============
    ================================================================ */
    require_once __DIR__ . '/../helpers/bdris_helper.php';
    
    $router->get('/birth/bdris/init', function() {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $res = bdris_generate_captcha();
    
            if (!$res || ($res['status'] ?? 'error') === 'error') {
                echo json_encode(['ok' => false, 'error' => $res['message'] ?? 'Captcha fetch failed']);
                return;
            }
    
            echo json_encode([
                'ok' => true,
                'token' => $res['token'] ?? '',
                'captcha_de_text' => $res['captcha_de_text'] ?? '',
                'captcha_data_uri' => $res['captcha_data_uri'] ?? ''
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    });
    
    
    $router->post('/birth/bdris/submit', function() {
        header('Content-Type: application/json; charset=utf-8');
    
        $ubrn = $_POST['ubrn'] ?? '';
        $dob  = sanitize_input($_POST['birthdate'] ?? '');
        $captcha = $_POST['captcha'] ?? '';
        $token = $_POST['server_token'] ?? '';
        $captcha_de_text = $_POST['captcha_de_text'] ?? '';
    
        if (!$ubrn || !$dob || !$captcha) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
            return;
        }
    
        try {
            $res = bdris_fetch_birth_data($ubrn, $dob, $captcha, $token, $captcha_de_text);
    
            if (!$res || ($res['status'] ?? '') === 'error') {
                echo json_encode(['ok' => false, 'error' => $res['message'] ?? 'Fetch failed']);
                return;
            }
    
            echo json_encode(['ok' => true, 'data' => $res]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    });


    $router->get('/death', function() use ($twig) {
    echo $twig->render('pdf/death/list.twig', [
        'title' => 'মৃত্যু নিবন্ধন তালিকা',
        'header_title' => 'মৃত্যু নিবন্ধন তালিকা'
    ]);
});

    $router->get('/api/deaths', function() use ($birthModel) {
    header('Content-Type: application/json; charset=utf-8');

    $deaths = $birthModel->getAll();

    $data = array_map(function($d) {
        return [
            'id'                     => $d['id'],
            'registration_number'    => $d['registration_number'],
            'name_bn'                => $d['name_bn'],
            'death_date'             => $d['death_date'],
            'father_name_bn'         => $d['father_name_bn'],
            'mother_name_bn'         => $d['mother_name_bn'],
            'office_name_bn'         => $d['office_name_bn'],
            'district_bn'            => $d['district_bn']
        ];
    }, $deaths);

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
});
    $router->get('/death/form', function() use ($twig) {
    echo $twig->render('pdf/death/form.twig', [
        'title' => 'নতুন মৃত্যু নিবন্ধন ফরম',
        'header_title' => 'নতুন মৃত্যু নিবন্ধন ফরম',
        'death' => []
    ]);
});
    $router->get('/death/edit/{id}', function($id) use ($twig, $birthModel) {
    $death = $birthModel->getById((int)$id);
    if (!$death) {
        echo "<div class='alert alert-danger text-center my-5'>❌ মৃত্যু নিবন্ধন রেকর্ড পাওয়া যায়নি।</div>";
        return;
    }

    echo $twig->render('pdf/death/form.twig', [
        'title' => 'মৃত্যু নিবন্ধন সম্পাদনা',
        'header_title' => 'মৃত্যু নিবন্ধন সম্পাদনা',
        'death' => $death
    ]);
});
    $router->post('/death/save', function() use ($birthModel) {
    header('Content-Type: application/json; charset=utf-8');
    $alert = ['type' => 'danger', 'message' => ''];

    try {
        // Verify CSRF token
            // CSRF is verified by middleware

        $id = (int)($_POST['id'] ?? 0);
        $data = [];

        $fields = [
            'registration_number','date_of_registration','date_of_issuance',
            'name_bn','name_en','sex','death_date',
            'father_name_bn','father_name_en',
            'mother_name_bn','mother_name_en',
            'district_office_bn','district_office_en',
            'upazila_office_bn','upazila_office_en',
            'union_office_bn','union_office_en',
            'office_name_bn','office_name_en',
            'district_bn','district_en',
            'verify_key','verify_link'
        ];

        foreach ($fields as $f) {
            $data[$f] = sanitize_input($_POST[$f] ?? '');
        }

        if (empty($data['name_bn']) || empty($data['death_date'])) {
            throw new Exception('নাম ও মৃত্যুর তারিখ বাধ্যতামূলক।');
        }

        // normalize date
        $dd = DateTime::createFromFormat('d-m-Y', $data['death_date']);
        if ($dd) $data['death_date'] = $dd->format('Y-m-d');

        if ($id > 0) {
            $updated = $birthModel->update($id, $data);
            if (!$updated) throw new Exception('আপডেট ব্যর্থ হয়েছে!');
            $alert = ['type'=>'success','message'=>'✅ তথ্য আপডেট হয়েছে'];
            $pdf_url = '/death/pdf/'.$id;
        } else {
            $newId = $birthModel->create($data);
            if (!$newId) throw new Exception('সংরক্ষণ ব্যর্থ হয়েছে!');
            $alert = ['type'=>'success','message'=>'✅ নতুন মৃত্যু নিবন্ধন সংরক্ষিত'];
            $pdf_url = '/death/pdf/'.$newId;
        }

    } catch (Throwable $e) {
        $alert['message'] = $e->getMessage();
        error_log('Death Save Error: '.$e->getMessage());
    }

    echo json_encode(['alert'=>$alert,'pdf_url'=>$pdf_url ?? null], JSON_UNESCAPED_UNICODE);
});
    $router->get('/death/pdf/{id}', function($id) use ($twig, $birthModel) {
        $app = $birthModel->getById((int)$id);
        if(!$app){ echo "Record not found."; return; }
    
        $tmpDir = __DIR__ . '/../public/tmp/';
        if(!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    
        $qrPath = $tmpDir . "death-qr-{$id}.png";
        $barcodePath = $tmpDir . "death-barcode-{$id}.png";
    
        $verifyLink = $app['verify_link'] ?? "https://lgdhaka.co/verify/death/{$id}";
        generate_qr($verifyLink, $qrPath);
        generate_barcode($app['registration_number'], $barcodePath);
    
        $html = $twig->render('pdf/death/death_certificate.twig', [
            'data' => $app,
            'qr' => $qrPath,
            'barcode' => $barcodePath,
            'title' => 'মৃত্যু নিবন্ধন সনদ',
            'header_title' => 'মৃত্যু নিবন্ধন সনদ'
        ]);
    
        birthPdf($html, $app['registration_number']);
    });
    $router->post('/death/delete/{id}', function($id) use ($birthModel) {
        header('Content-Type: application/json; charset=utf-8');
        try {            // CSRF is verified by middleware
            if (!$birthModel->delete((int)$id)) {
                throw new Exception('ডিলিট ব্যর্থ হয়েছে');
            }
            echo json_encode(['alert'=>['type'=>'success','message'=>'✅ রেকর্ড মুছে ফেলা হয়েছে']], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['alert'=>['type'=>'danger','message'=>$e->getMessage()]], JSON_UNESCAPED_UNICODE);
        }
    });

