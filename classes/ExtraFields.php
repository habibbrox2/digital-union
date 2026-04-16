<?php
// classes/ExtraFields.php

class ExtraFields {
    private $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
        $this->createTableAndInsertData();
    }

    private function createTableAndInsertData() {
        // 1. Create table if not exists
        $sqlCreate = "
            CREATE TABLE IF NOT EXISTS extra_fields (
                id INT AUTO_INCREMENT PRIMARY KEY,
                certificate_type VARCHAR(50) NOT NULL,
                field_id VARCHAR(50) NOT NULL,
                label_en VARCHAR(255),
                label_bn VARCHAR(255),
                type VARCHAR(50) DEFAULT 'text',
                placeholder VARCHAR(255),
                count INT DEFAULT 1,
                order_after VARCHAR(50),
                options TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        if (!$this->mysqli->query($sqlCreate)) {
            throw new Exception('Failed to create extra_fields table: ' . $this->mysqli->error);
        }

        // 2. Check if table already has data
        $result = $this->mysqli->query("SELECT COUNT(*) as cnt FROM extra_fields");
        if (!$result) {
            throw new Exception('Failed to check extra_fields table: ' . $this->mysqli->error);
        }

        $row = $result->fetch_assoc();
        if ($row['cnt'] > 0) {
            // Table already has data, skip insert
            return;
        }

        // 3. Insert default data (all 36 rows)
        $sqlInsert = "
            INSERT INTO `extra_fields` 
            (`id`, `certificate_type`, `field_id`, `label_en`, `label_bn`, `type`, `placeholder`, `count`, `order_after`, `options`, `created_at`) VALUES
            (1, 'bibahito', 'marriage_date', NULL, 'বিবাহের তারিখ', 'date', NULL, 1, 'marital_status', NULL, '2025-09-22 11:37:08'),
            (2, 'character', 'purpose', NULL, 'সনদ গ্রহণের কারণ', 'textarea', NULL, 2, 'marital_status', NULL, '2025-09-22 11:37:08'),
            (3, 'warish', 'name_en', 'Enter deceased person\'s name in English', 'মৃত ব্যক্তির নাম (ইংরেজিতে)', 'label', 'Enter deceased person\'s name in English', 1, NULL, NULL, '2025-09-22 11:37:08'),
            (4, 'warish', 'name_bn', 'মৃত ব্যক্তির নাম বাংলায় লিখুন', 'মৃত ব্যক্তির নাম (বাংলায়)', 'label', 'মৃত ব্যক্তির নাম বাংলায় লিখুন', 1, NULL, NULL, '2025-09-22 11:37:08'),
            (5, 'warish', 'death_date', NULL, 'মৃত্যুর তারিখ', 'date', NULL, 1, 'birth_date', NULL, '2025-09-22 11:37:08'),
            (6, 'warish', 'applicant_name_father', NULL, 'আবেদনকারীর পিতার নাম', 'text', NULL, 2, 'applicant_name', NULL, '2025-09-22 11:37:08'),
            (7, 'death', 'name_en', 'Enter deceased person\'s name in English', 'মৃত ব্যক্তির নাম (ইংরেজিতে)', 'label', 'Enter deceased person\'s name in English', 1, NULL, NULL, '2025-09-22 11:38:06'),
            (8, 'death', 'name_bn', 'মৃত ব্যক্তির নাম বাংলায় লিখুন', 'মৃত ব্যক্তির নাম (বাংলায়)', 'label', 'মৃত ব্যক্তির নাম বাংলায় লিখুন', 1, NULL, NULL, '2025-09-22 11:38:06'),
            (9, 'death', 'death_date', NULL, 'মৃত্যুর তারিখ', 'date', NULL, 1, 'birth_date', NULL, '2025-09-22 11:38:06'),
            (10, 'death', 'death_reason', NULL, 'মৃত্যুর কারণ', 'text', NULL, 2, 'death_date', NULL, '2025-09-22 11:38:06'),
            (11, 'ekoinam', 'nickname', NULL, 'প্রকাশে নাম / ডাকনাম', 'text', NULL, 2, 'name_en', NULL, '2025-09-22 11:38:06'),
            (12, 'nodibanga', 'affected_reason', NULL, 'ক্ষতিগ্রস্ত হওয়ার কারণ', 'textarea', NULL, 2, 'applicant_phone', NULL, '2025-09-22 11:38:06'),
            (13, 'obibahito', 'reason', NULL, 'অবিবাহিত থাকার কারণ', 'textarea', NULL, 2, 'birth_date', NULL, '2025-09-22 11:38:06'),
            (14, 'onapotti', 'business_name', NULL, 'ব্যবসা/প্রতিষ্ঠান/ভবনের নাম', 'text', NULL, 2, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (15, 'onapotti', 'business_location', NULL, 'ব্যবসা/প্রতিষ্ঠান/ভবনের অবস্থান', 'text', NULL, 2, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (16, 'onapotti', 'business_type', NULL, 'ব্যবসা/প্রতিষ্ঠান/ভবনের ধরণ', 'text', NULL, 2, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (17, 'onapotti', 'trade_license_no', NULL, 'ট্রেড লাইসেন্স নং', 'text', NULL, 1, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (18, 'onapotti', 'land_description', NULL, 'ব্যবসা/প্রতিষ্ঠান/ভবনের ব্যবহৃত জমির বিবরণ', 'text', NULL, 1, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (19, 'onapotti', 'opposition_details', NULL, 'অনাপত্তির বিবরণ', 'textarea', NULL, 1, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (20, 'onapotti', 'mouza', NULL, 'মৌজা', 'number', NULL, 1, 'applicant_phone', NULL, '2025-09-22 11:38:06'),
            (21, 'onapotti', 'khatian_no', NULL, 'খতিয়ান নং', 'number', NULL, 1, 'mouza', NULL, '2025-09-22 11:38:06'),
            (22, 'onapotti', 'land_type', NULL, 'জমির ধরণ', 'text', NULL, 1, 'khatian_no', NULL, '2025-09-22 11:38:06'),
            (23, 'onapotti', 'land_amount', NULL, 'জমির পরিমাণ', 'number', NULL, 1, 'khatian_no', NULL, '2025-09-22 11:38:06'),
            (24, 'onumoti', 'permission_reason', NULL, 'কর্ম ক্ষেত্রের নাম', 'text', NULL, 2, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (25, 'others', 'description', NULL, 'বিবরণ', 'textarea', NULL, 2, 'applicant_phone', NULL, '2025-09-22 11:38:06'),
            (26, 'protibondi', 'disability_type', NULL, 'প্রতিবন্ধিতার ধরণ', 'text', NULL, 2, 'name_bn', NULL, '2025-09-22 11:38:06'),
            (27, 'protibondi', 'disability_percentage', NULL, 'প্রতিবন্ধিতার হার', 'number', NULL, 1, 'disability_type_bn', NULL, '2025-09-22 11:38:06'),
            (28, 'prottyon', 'certified_by', NULL, 'প্রত্যয়নকারী কর্তৃপক্ষ', 'text', NULL, 2, 'applicant_phone', NULL, '2025-09-22 11:38:06'),
            (29, 'punobibaho', 'previous_marriage_status', NULL, 'পূর্বের বিবাহের অবস্থা', 'text', NULL, 1, 'spouse_name_bn', NULL, '2025-09-22 11:38:06'),
            (30, 'rastakhonon', 'digging_purpose', NULL, 'রাস্তা খননের উদ্দেশ্য', 'textarea', NULL, 2, 'applicant_phone', NULL, '2025-09-22 11:38:06'),
            (31, 'rastakhonon', 'road_type', NULL, 'রাস্তার ধরণ', 'text', NULL, 2, 'digging_purpose', NULL, '2025-09-22 11:38:06'),
            (32, 'sonaton', 'religious_id_number', NULL, 'ধর্মীয় পরিচিতি নম্বর', 'text', NULL, 1, 'birth_id', NULL, '2025-09-22 11:38:06'),
            (33, 'vumihin', 'landless_reason', NULL, 'ভূমিহীনতার কারণ', 'textarea', NULL, 2, 'applicant_phone', NULL, '2025-09-22 11:38:06'),
            (34, 'yearlyincome', 'earn_type', NULL, 'আয়ের ধরণ', 'select', NULL, 1, 'name_bn', '[{\"value\":\"1\",\"text\":\"মাসিক\"},{\"value\":\"2\",\"text\":\"বার্ষিক\"}]', '2025-09-22 11:38:06'),
            (35, 'yearlyincome', 'yearly_income', NULL, 'আয়ের পরিমান', 'number', NULL, 1, 'earn_type', NULL, '2025-09-22 11:38:06'),
            (36, 'yearlyincome', 'purpose', NULL, 'সনদ গ্রহণের কারণ', 'text', NULL, 1, 'yearly_income', NULL, '2025-09-22 11:38:06');
        ";

        if (!$this->mysqli->query($sqlInsert)) {
            throw new Exception('Failed to insert default data: ' . $this->mysqli->error);
        }
    }


    public function getAll($certificate_type = '') {
        if ($certificate_type) {
            $stmt = $this->mysqli->prepare("SELECT * FROM extra_fields WHERE certificate_type=? ORDER BY id ASC");
            $stmt->bind_param("s", $certificate_type);
        } else {
            $stmt = $this->mysqli->prepare("SELECT * FROM extra_fields ORDER BY certificate_type, id ASC");
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $fields = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $fields;
    }

    public function getById($id) {
        $id = intval($id);
        $stmt = $this->mysqli->prepare("SELECT * FROM extra_fields WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $field = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $field ?: null;
    }

    public function save($certificate_type, array $fields) {
        if (!$certificate_type || !is_array($fields)) {
            throw new Exception('Invalid input');
        }

        $this->mysqli->begin_transaction();
        try {
            // Delete old fields
            $stmt = $this->mysqli->prepare("DELETE FROM extra_fields WHERE certificate_type=?");
            $stmt->bind_param("s", $certificate_type);
            $stmt->execute();
            $stmt->close();

            // Insert new fields
            $stmt = $this->mysqli->prepare("
                INSERT INTO extra_fields 
                (certificate_type, field_id, label_bn, label_en, placeholder, type, count, order_after, options)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($fields as $f) {
                $field_id    = sanitize_input($f['field_id'] ?? '');
                $label_bn    = sanitize_input($f['label_bn'] ?? '');
                $label_en    = sanitize_input($f['label_en'] ?? '');
                $placeholder = sanitize_input($f['placeholder'] ?? '');
                $type        = sanitize_input($f['type'] ?? 'text');
                $count       = intval($f['count'] ?? 1);
                $order_after = sanitize_input($f['order_after'] ?? '');
                $options     = !empty($f['options']) ? json_encode($f['options'], JSON_UNESCAPED_UNICODE) : null;

                $stmt->bind_param("ssssssiss", $certificate_type, $field_id, $label_bn, $label_en, $placeholder, $type, $count, $order_after, $options);
                $stmt->execute();
            }

            $stmt->close();
            $this->mysqli->commit();

            return true;
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }
}
