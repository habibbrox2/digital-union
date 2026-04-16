<?php
class BirthApplicationModel {
    private $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    // ==============================
    // CREATE (Insert New Record)
    // ==============================
public function create(array $data): int
{
    if (!$this->mysqli instanceof mysqli) {
        throw new Exception("Database connection not initialized.");
    }

    $this->mysqli->begin_transaction();

    try {

        $sql = "INSERT INTO birth_applications (
            registration_number, date_of_registration, date_of_issuance,
            birth_date, birth_date_words_bn, birth_date_words_en,
            name_bn, name_en, sex,
            father_name_bn, father_name_en, father_nationality_bn, father_nationality_en,
            mother_name_bn, mother_name_en, mother_nationality_bn, mother_nationality_en,
            district_office_bn, district_office_en,
            upazila_office_en, upazila_office_bn,
            union_office_en, union_office_bn,
            office_name_bn, office_name_en,
            country_bn, country_en,
            district_bn, district_en,
            permanent_address_bn, permanent_address_en,
            verify_key, verify_link, created_at
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, NOW()
        )";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->mysqli->error);
        }

        $stmt->bind_param(
            'sssssssssssssssssssssssssssssssss',

            $data['registration_number'],
            $data['date_of_registration'],
            $data['date_of_issuance'],

            $data['birth_date'],
            $data['birth_date_words_bn'],
            $data['birth_date_words_en'],

            $data['name_bn'],
            $data['name_en'],
            $data['sex'],

            $data['father_name_bn'],
            $data['father_name_en'],
            $data['father_nationality_bn'],
            $data['father_nationality_en'],

            $data['mother_name_bn'],
            $data['mother_name_en'],
            $data['mother_nationality_bn'],
            $data['mother_nationality_en'],

            $data['district_office_bn'],
            $data['district_office_en'],

            $data['upazila_office_en'],
            $data['upazila_office_bn'],

            $data['union_office_en'],
            $data['union_office_bn'],

            $data['office_name_bn'],
            $data['office_name_en'],

            $data['country_bn'],
            $data['country_en'],

            $data['district_bn'],
            $data['district_en'],

            $data['permanent_address_bn'],
            $data['permanent_address_en'],

            $data['verify_key'],
            $data['verify_link']
        );

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }

        $insertId = $stmt->insert_id;
        $stmt->close();
        $this->mysqli->commit();

        return (int)$insertId;

    } catch (Throwable $e) {
        $this->mysqli->rollback();
        error_log("BirthModel::create() error: " . $e->getMessage());
        throw new Exception("Birth record could not be saved. " . $e->getMessage());
    }
}



    // ==============================
    // READ (Fetch All)
    // ==============================
    public function getAll(): array {
        $res = $this->mysqli->query("SELECT * FROM birth_applications ORDER BY id DESC");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    // ==============================
    // READ (Fetch Single Record)
    // ==============================
    public function getById(int $id): ?array {
        $stmt = $this->mysqli->prepare("SELECT * FROM birth_applications WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }


    // ==============================
    // UPDATE (Edit Existing Record)
    // ==============================
    public function update(int $id, array $data): bool {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            $fields[] = "`$key` = ?";
            $values[] = $value;
        }
        $values[] = $id;

        $sql = "UPDATE birth_applications SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->mysqli->error);
        }

        $stmt->bind_param(str_repeat('s', count($data)) . 'i', ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }


    // ==============================
    // DELETE (Remove Record)
    // ==============================
    public function delete(int $id): bool {
        $stmt = $this->mysqli->prepare("DELETE FROM birth_applications WHERE id = ?");
        $stmt->bind_param("i", $id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}
