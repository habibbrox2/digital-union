<?php
/**
 * modules/Services/UnionService.php
 * 
 * Service layer for union CRUD operations.
 * All database logic is encapsulated here - controllers only call these methods.
 */

class UnionService
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Create a new union
     */
    public function create(array $data): array
    {
        $stmt = $this->mysqli->prepare("
            INSERT INTO unions (
                union_code, division_id, district_id, upazila_id,
                union_name_en, union_name_bn,
                upazila_name_en, upazila_name_bn,
                district_name_en, district_name_bn,
                division_name_en, division_name_bn,
                ward_count,
                email, phone, website, postcode,
                logo_url, stamp_logo_url, latitude, longitude,
                is_active, remarks
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->bind_param(
            "siiisssssssssisssssddiis",
            sanitize_input($data['union_code']),
            (int)$data['division_id'],
            (int)$data['district_id'],
            (int)$data['upazila_id'],
            sanitize_input($data['union_name_en']),
            sanitize_input($data['union_name_bn']),
            sanitize_input($data['upazila_name_en']),
            sanitize_input($data['upazila_name_bn']),
            sanitize_input($data['district_name_en']),
            sanitize_input($data['district_name_bn']),
            sanitize_input($data['division_name_en']),
            sanitize_input($data['division_name_bn']),
            (int)($data['ward_count'] ?? 9),
            sanitize_input($data['email']),
            sanitize_input($data['phone']),
            sanitize_input($data['website']),
            sanitize_input($data['postcode']),
            sanitize_input($data['logo_url'] ?? ''),
            sanitize_input($data['stamp_logo_url'] ?? ''),
            $data['latitude'] !== '' ? (float)$data['latitude'] : null,
            $data['longitude'] !== '' ? (float)$data['longitude'] : null,
            isset($data['is_active']) ? 1 : 0,
            sanitize_input($data['remarks'])
        );

        $success = $stmt->execute();
        $stmt->close();

        return [
            'success' => $success,
            'message' => $success ? 'Union created successfully' : 'Failed to create union'
        ];
    }

    /**
     * Update an existing union
     */
    public function update(int $id, array $data): array
    {
        $stmt = $this->mysqli->prepare("
            UPDATE unions SET
                union_code=?, division_id=?, district_id=?, upazila_id=?,
                union_name_en=?, union_name_bn=?,
                upazila_name_en=?, upazila_name_bn=?,
                district_name_en=?, district_name_bn=?,
                division_name_en=?, division_name_bn=?,
                ward_count=?,
                email=?, phone=?, website=?, postcode=?,
                logo_url=?, stamp_logo_url=?, latitude=?, longitude=?,
                is_active=?, remarks=?
            WHERE union_id=?
        ");

        $stmt->bind_param(
            "siiisssssssssisssssddiis",
            sanitize_input($data['union_code']),
            (int)$data['division_id'],
            (int)$data['district_id'],
            (int)$data['upazila_id'],
            sanitize_input($data['union_name_en']),
            sanitize_input($data['union_name_bn']),
            sanitize_input($data['upazila_name_en']),
            sanitize_input($data['upazila_name_bn']),
            sanitize_input($data['district_name_en']),
            sanitize_input($data['district_name_bn']),
            sanitize_input($data['division_name_en']),
            sanitize_input($data['division_name_bn']),
            (int)($data['ward_count'] ?? 9),
            sanitize_input($data['email']),
            sanitize_input($data['phone']),
            sanitize_input($data['website']),
            sanitize_input($data['postcode']),
            sanitize_input($data['logo_url']),
            sanitize_input($data['stamp_logo_url'] ?? ''),
            $data['latitude'] !== '' ? (float)$data['latitude'] : null,
            $data['longitude'] !== '' ? (float)$data['longitude'] : null,
            isset($data['is_active']) ? 1 : 0,
            sanitize_input($data['remarks']),
            (int)$id
        );

        $success = $stmt->execute();
        $stmt->close();

        return [
            'success' => $success,
            'message' => $success ? 'Union updated successfully' : 'Update failed'
        ];
    }

    /**
     * Delete a union by ID
     */
    public function delete(int $id): array
    {
        try {
            $stmt = $this->mysqli->prepare("DELETE FROM unions WHERE union_id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            return [
                'success' => true,
                'message' => 'ইউনিয়ন মুছে ফেলা হয়েছে'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate union data before create/update
     */
    public function validate(array $data): array
    {
        $errors = [];
        if (empty($data['union_name_en'])) $errors[] = 'Union name (EN) required';
        if (empty($data['union_code']))    $errors[] = 'Union code required';
        return $errors;
    }

    /**
     * Get validated 7-digit union code
     */
    public function getUnionCode(string $unionCode): string
    {
        if (empty($unionCode)) {
            return '';
        }
        if (!ctype_digit($unionCode)) {
            throw new InvalidArgumentException("Union code must be numeric.");
        }
        return str_pad(substr($unionCode, 0, 7), 7, '0', STR_PAD_RIGHT);
    }

    /**
     * Fetch union row by union_code
     */
    public function getUnionByCode(string $unionCode): ?array
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM unions WHERE union_code = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException("Prepare failed: " . $this->mysqli->error);
        }
        $stmt->bind_param("s", $unionCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
