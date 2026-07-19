<?php
/**
 * modules/Services/BirthService.php
 * 
 * Service layer for birth and death registration business logic.
 * Handles data preparation, validation, PDF generation, and BDRIS integration.
 * Database CRUD is delegated to BirthApplicationModel.
 */

require_once __DIR__ . '/../../helpers/qrCode.php';
require_once __DIR__ . '/../../helpers/pdfGenarate.php';
require_once __DIR__ . '/../../helpers/bdris_helper.php';

class BirthService
{
    private mysqli $mysqli;
    private BirthApplicationModel $birthModel;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->birthModel = new BirthApplicationModel($mysqli);
    }

    public function getModel(): BirthApplicationModel
    {
        return $this->birthModel;
    }

    // ================================================================
    // BIRTH SAVE
    // ================================================================

    /**
     * Save a birth record (create or update)
     * Handles field mapping, date conversion, date-to-words, validation
     */
    public function saveBirth(array $postData): array
    {
        $id = (int)($postData['id'] ?? 0);
        $data = [];
        $fields = [
            'registration_number', 'date_of_registration', 'date_of_issuance',
            'name_bn', 'name_en', 'sex', 'birth_date',
            'father_name_bn', 'father_name_en', 'father_nationality_bn', 'father_nationality_en',
            'mother_name_bn', 'mother_name_en', 'mother_nationality_bn', 'mother_nationality_en',
            'district_office_bn', 'district_office_en', 'upazila_office_bn', 'upazila_office_en',
            'union_office_bn', 'union_office_en',
            'office_name_bn', 'office_name_en', 'country_en', 'country_bn',
            'district_bn', 'district_en', 'permanent_address_bn', 'permanent_address_en',
            'verify_key', 'verify_link'
        ];

        foreach ($fields as $f) {
            $data[$f] = sanitize_input($postData[$f] ?? '');
        }

        if (empty($data['name_bn']) || empty($data['birth_date'])) {
            throw new \Exception('নাম ও জন্মতারিখ প্রদান বাধ্যতামূলক।');
        }

        // Convert date format if needed
        $bd = \DateTime::createFromFormat('d-m-Y', $data['birth_date']);
        if ($bd) {
            $data['birth_date'] = $bd->format('Y-m-d');
        }

        // Generate date words
        $dateWords = dateToWords($data['birth_date']);
        $data['birth_date_words_bn'] = $dateWords['bn'] ?? '';
        $data['birth_date_words_en'] = $dateWords['en'] ?? '';

        if ($id > 0) {
            $updated = $this->birthModel->update($id, $data);
            if (!$updated) {
                throw new \Exception('আপডেট ব্যর্থ হয়েছে!');
            }
            return [
                'alert' => ['type' => 'success', 'message' => '✅ তথ্য সফলভাবে আপডেট হয়েছে।'],
                'pdf_url' => '/birth/pdf/' . $id
            ];
        } else {
            $newId = $this->birthModel->create($data);
            if (!$newId) {
                throw new \Exception('ডাটাবেজে তথ্য সংরক্ষণ ব্যর্থ হয়েছে!');
            }
            return [
                'alert' => ['type' => 'success', 'message' => '✅ নতুন তথ্য সফলভাবে সংরক্ষিত হয়েছে।'],
                'pdf_url' => '/birth/pdf/' . $newId
            ];
        }
    }

    // ================================================================
    // DEATH SAVE
    // ================================================================

    /**
     * Save a death record (create or update)
     * Handles field mapping, date conversion, validation
     */
    public function saveDeath(array $postData): array
    {
        $id = (int)($postData['id'] ?? 0);
        $data = [];

        $fields = [
            'registration_number', 'date_of_registration', 'date_of_issuance',
            'name_bn', 'name_en', 'sex', 'death_date',
            'father_name_bn', 'father_name_en',
            'mother_name_bn', 'mother_name_en',
            'district_office_bn', 'district_office_en',
            'upazila_office_bn', 'upazila_office_en',
            'union_office_bn', 'union_office_en',
            'office_name_bn', 'office_name_en',
            'district_bn', 'district_en',
            'verify_key', 'verify_link'
        ];

        foreach ($fields as $f) {
            $data[$f] = sanitize_input($postData[$f] ?? '');
        }

        if (empty($data['name_bn']) || empty($data['death_date'])) {
            throw new \Exception('নাম ও মৃত্যুর তারিখ বাধ্যতামূলক।');
        }

        // Convert date format if needed
        $dd = \DateTime::createFromFormat('d-m-Y', $data['death_date']);
        if ($dd) {
            $data['death_date'] = $dd->format('Y-m-d');
        }

        if ($id > 0) {
            $updated = $this->birthModel->update($id, $data);
            if (!$updated) {
                throw new \Exception('আপডেট ব্যর্থ হয়েছে!');
            }
            return [
                'alert' => ['type' => 'success', 'message' => '✅ তথ্য আপডেট হয়েছে'],
                'pdf_url' => '/death/pdf/' . $id
            ];
        } else {
            $newId = $this->birthModel->create($data);
            if (!$newId) {
                throw new \Exception('সংরক্ষণ ব্যর্থ হয়েছে!');
            }
            return [
                'alert' => ['type' => 'success', 'message' => '✅ নতুন মৃত্যু নিবন্ধন সংরক্ষিত'],
                'pdf_url' => '/death/pdf/' . $newId
            ];
        }
    }

    // ================================================================
    // DATA FORMATTING
    // ================================================================

    /**
     * Format birth records for JSON API response
     */
    public function formatBirthList(): array
    {
        $records = $this->birthModel->getAll();
        return array_map(function($b) {
            return [
                'id'                   => $b['id'],
                'registration_number'  => $b['registration_number'],
                'name_bn'              => $b['name_bn'],
                'father_name_bn'       => $b['father_name_bn'],
                'mother_name_bn'       => $b['mother_name_bn'],
                'birth_date'           => $b['birth_date'],
                'office_name_en'       => $b['office_name_en'],
                'office_name_bn'       => $b['office_name_bn'],
                'district_bn'          => $b['district_bn'],
                'country_bn'           => $b['country_bn'],
                'permanent_address_bn' => $b['permanent_address_bn'],
            ];
        }, $records);
    }

    /**
     * Format death records for JSON API response
     */
    public function formatDeathList(): array
    {
        $records = $this->birthModel->getAll();
        return array_map(function($d) {
            return [
                'id'                     => $d['id'],
                'registration_number'    => $d['registration_number'],
                'name_bn'                => $d['name_bn'],
                'death_date'             => $d['death_date'],
                'father_name_bn'         => $d['father_name_bn'],
                'mother_name_bn'         => $d['mother_name_bn'],
                'office_name_bn'         => $d['office_name_bn'],
                'district_bn'            => $d['district_bn'],
            ];
        }, $records);
    }

    // ================================================================
    // BIRTH DELETE
    // ================================================================

    /**
     * Delete a birth/death record
     */
    public function deleteBirth(int $id): array
    {
        if ($id <= 0) {
            throw new \Exception('অবৈধ আইডি প্রদান করা হয়েছে।');
        }

        $deleted = $this->birthModel->delete($id);
        if (!$deleted) {
            throw new \Exception('রেকর্ড মুছে ফেলা ব্যর্থ হয়েছে।');
        }

        return ['alert' => ['type' => 'success', 'message' => '✅ রেকর্ড সফলভাবে মুছে ফেলা হয়েছে।']];
    }

    // ================================================================
    // PDF GENERATION
    // ================================================================

    /**
     * Generate birth certificate PDF
     */
    public function generateBirthPdf(int $id): void
    {
        $app = $this->birthModel->getById($id);
        if (!$app) {
            echo "Record not found.";
            return;
        }

        $tmpDir = __DIR__ . '/../../storage/tmp/';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $qrPath = $tmpDir . "qr-{$id}.png";
        $barcodePath = $tmpDir . "barcode-{$id}.png";

        $verifyLink = $app['verify_link'] ?? "https://lgdhaka.gov.bd/verify/birth/{$app['id']}";
        generate_qr($verifyLink, $qrPath);

        $registrationNumber = $app['registration_number'] ?? 'UNKNOWN';
        generate_barcode($registrationNumber, $barcodePath);

        // This is called from the controller which has $twig
        // So we use a global reference pattern
        global $twig;
        $html = $twig->render('pdf/birth/birth_certificate.twig', [
            'data' => $app,
            'qr' => $qrPath,
            'barcode' => $barcodePath,
            'title' => 'জন্ম নিবন্ধন সনদ',
            'header_title' => 'জন্ম নিবন্ধন সনদ'
        ]);

        birthPdf($html, $app['registration_number'], '', __DIR__ . '/../../public/assets/birth-bg.png', false);
    }

    /**
     * Generate death certificate PDF
     */
    public function generateDeathPdf(int $id): void
    {
        $app = $this->birthModel->getById($id);
        if (!$app) {
            echo "Record not found.";
            return;
        }

        $tmpDir = __DIR__ . '/../../storage/tmp/';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $qrPath = $tmpDir . "death-qr-{$id}.png";
        $barcodePath = $tmpDir . "death-barcode-{$id}.png";

        $verifyLink = $app['verify_link'] ?? "https://lgdhaka.co/verify/death/{$id}";
        generate_qr($verifyLink, $qrPath);
        generate_barcode($app['registration_number'], $barcodePath);

        global $twig;
        $html = $twig->render('pdf/death/death_certificate.twig', [
            'data' => $app,
            'qr' => $qrPath,
            'barcode' => $barcodePath,
            'title' => 'মৃত্যু নিবন্ধন সনদ',
            'header_title' => 'মৃত্যু নিবন্ধন সনদ'
        ]);

        birthPdf($html, $app['registration_number']);
    }
}
