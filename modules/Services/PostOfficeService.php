<?php
/**
 * modules/Services/PostOfficeService.php
 * 
 * Service layer for post office management CRUD operations.
 */

class PostOfficeService
{
    private mysqli $mysqli;

    private const ALLOWED_SORT_COLUMNS = [
        'p.id', 'p.upazila_name', 'p.union_name', 'p.en_name', 'p.bn_name', 'p.post_code', 'p.created_at'
    ];

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Create a new post office record
     */
    public function create(array $data): array
    {
        $upazilaName = trim($data['upazila_name'] ?? '');
        $unionName = trim($data['union_name'] ?? '');
        $enName = trim($data['en_name'] ?? '');
        $bnName = trim($data['bn_name'] ?? '');
        $postCode = trim($data['post_code'] ?? '');

        if ($upazilaName === '' || $unionName === '' || (empty($enName) && empty($bnName))) {
            return ['status' => 'error', 'message' => 'উপজেলা, ইউনিয়ন এবং পোস্ট অফিসের নাম প্রয়োজন'];
        }

        $stmt = $this->mysqli->prepare(
            "INSERT INTO post_offices (upazila_name, union_name, en_name, bn_name, post_code) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssss", $upazilaName, $unionName, $enName, $bnName, $postCode);

        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'পোস্ট অফিস সফলভাবে যোগ করা হয়েছে'];
        }
        return ['status' => 'error', 'message' => 'ডাটাবেস ত্রুটি: ' . $stmt->error];
    }

    /**
     * Update an existing post office record
     */
    public function update(int $id, array $data): array
    {
        $upazilaName = trim($data['upazila_name'] ?? '');
        $unionName = trim($data['union_name'] ?? '');
        $enName = trim($data['en_name'] ?? '');
        $bnName = trim($data['bn_name'] ?? '');
        $postCode = trim($data['post_code'] ?? '');

        if (!$id || $upazilaName === '' || $unionName === '') {
            return ['status' => 'error', 'message' => 'অবৈধ তথ্য'];
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE post_offices SET upazila_name = ?, union_name = ?, en_name = ?, bn_name = ?, post_code = ? WHERE id = ?"
        );
        $stmt->bind_param("sssssi", $upazilaName, $unionName, $enName, $bnName, $postCode, $id);

        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'পোস্ট অফিস সফলভাবে হালনাগাদ করা হয়েছে'];
        }
        return ['status' => 'error', 'message' => 'ডাটাবেস ত্রুটি: ' . $stmt->error];
    }

    /**
     * Get a single post office by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT id, upazila_name, union_name, en_name, bn_name, post_code FROM post_offices WHERE id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Delete a post office by ID
     */
    public function delete(int $id): array
    {
        if (!$id) {
            return ['status' => 'error', 'message' => 'অবৈধ আইডি'];
        }

        $stmt = $this->mysqli->prepare("DELETE FROM post_offices WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'পোস্ট অফিস সফলভাবে মুছে ফেলা হয়েছে'];
        }
        return ['status' => 'error', 'message' => 'ডাটাবেস ত্রুটি'];
    }

    /**
     * Filter/search post offices with pagination
     */
    public function filter(array $params): array
    {
        $search = trim($params['search'] ?? '');
        $sortColumn = $this->sanitizeSortColumn($params['sort_column'] ?? 'p.upazila_name');
        $sortDirection = $this->sanitizeSortDirection($params['sort_direction'] ?? 'ASC');
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, min(100, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = '';
        $bindParams = [];
        $types = '';

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where = "WHERE p.en_name LIKE ? OR p.bn_name LIKE ? OR p.post_code LIKE ? OR p.upazila_name LIKE ? OR p.union_name LIKE ?";
            $bindParams = [$like, $like, $like, $like, $like];
            $types = 'sssss';
        }

        // Count
        $countSql = "SELECT COUNT(*) as total FROM post_offices p $where";
        $countStmt = $this->mysqli->prepare($countSql);
        if (!$countStmt) {
            return ['status' => 'error', 'message' => 'ডাটাবেস ত্রুটি'];
        }
        if ($types) {
            $countStmt->bind_param($types, ...$bindParams);
        }
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch
        $dataSql = "SELECT p.id, p.upazila_name, p.union_name, p.en_name, p.bn_name, p.post_code, p.created_at
                    FROM post_offices p
                    $where
                    ORDER BY $sortColumn $sortDirection
                    LIMIT ? OFFSET ?";

        $dataStmt = $this->mysqli->prepare($dataSql);
        if (!$dataStmt) {
            return ['status' => 'error', 'message' => 'ডাটাবেস ত্রুটি'];
        }

        $allParams = $bindParams;
        $allTypes = $types . 'ii';
        $allParams[] = $limit;
        $allParams[] = $offset;

        if ($allTypes) {
            $dataStmt->bind_param($allTypes, ...$allParams);
        }
        $dataStmt->execute();
        $result = $dataStmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $dataStmt->close();

        return [
            'status' => 'success',
            'data' => $data,
            'total' => $total,
        ];
    }

    /**
     * Get all upazilas for dropdown
     */
    public function getAllUpazilas(): array
    {
        $result = $this->mysqli->query(
            "SELECT id, name_en, name_bn FROM geo_location WHERE geo_order = 2 ORDER BY name_en"
        );
        $upazilas = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $upazilas[] = $row;
            }
        }
        return $upazilas;
    }

    /**
     * Get all unions for dropdown
     */
    public function getAllUnions(): array
    {
        $result = $this->mysqli->query(
            "SELECT name_en, name_bn FROM geo_location WHERE geo_order = 3 ORDER BY name_en"
        );
        $unions = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $unions[] = $row;
            }
        }
        return $unions;
    }

    private function sanitizeSortColumn(string $column): string
    {
        return in_array($column, self::ALLOWED_SORT_COLUMNS) ? $column : 'p.upazila_name';
    }

    private function sanitizeSortDirection(string $direction): string
    {
        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    }
}
