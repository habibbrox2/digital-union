<?php

// ==================== BusinessOwnershipType Class ====================

class BusinessOwnershipType {
    private $conn;

    public function __construct($mysqli) {
        $this->conn = $mysqli;
    }

    public function fetchBusinessTypes($page = 1, $limit = 10, $search = '', $sort = 'id', $order = 'ASC', $unionId = null) {
        // Allow $limit = 0 or null to fetch ALL records (for client-side pagination)
        $fetchAll = ($limit === 0 || $limit === null);
        if ($fetchAll) {
            $limit = 0;
        }
        $offset = $fetchAll ? 0 : ($page - 1) * $limit;

        // নিরাপদ কলাম চেকিং
        $allowedSortColumns = ['id', 'business_name_bn', 'business_name_en', 'license_fee', 'vat_amount', 'occupation_tax', 'income_tax', 'signboard_tax', 'surcharge', 'union_name_bn'];
        if (!in_array($sort, $allowedSortColumns)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        // Determine sort column with table alias if sorting by union name (from joined table)
        $sortColumn = ($sort === 'union_name_bn') ? 'u.union_name_bn' : "bt.$sort";

        // --- WHERE conditions (shared between data query and count query) ---
        $whereConditions = [];
        $whereParams = [];
        $whereTypes = '';

        // Union filter — include records matching the user's union, plus orphaned records (union_id = 0 or NULL)
        if ($unionId !== null && $unionId > 0) {
            $whereConditions[] = '(bt.union_id = ? OR bt.union_id = 0 OR bt.union_id IS NULL)';
            $whereParams[] = $unionId;
            $whereTypes .= 'i';
        }

        // Search filter
        if (!empty($search)) {
            $whereConditions[] = '(business_name_bn LIKE ? OR business_name_en LIKE ?)';
            $searchParam = "%$search%";
            $whereParams[] = $searchParam;
            $whereParams[] = $searchParam;
            $whereTypes .= 'ss';
        }

        $whereClause = '';
        if (!empty($whereConditions)) {
            $whereClause = ' AND ' . implode(' AND ', $whereConditions);
        }

        // --- Data query (WHERE + ORDER BY, optionally with LIMIT/OFFSET) ---
        $selectFrom = "SELECT bt.*, u.union_name_bn FROM business_type bt LEFT JOIN unions u ON bt.union_id = u.union_id";
        if ($fetchAll) {
            $query = "$selectFrom WHERE 1$whereClause ORDER BY $sortColumn $order";
            $dataParams = $whereParams;
            $dataTypes = $whereTypes;
        } else {
            $query = "$selectFrom WHERE 1$whereClause ORDER BY $sortColumn $order LIMIT ?, ?";
            $dataParams = array_merge($whereParams, [$offset, $limit]);
            $dataTypes = $whereTypes . 'ii';
        }

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return [
                'businessTypes' => [],
                'totalPages' => 1,
                'currentPage' => $page
            ];
        }

        if (!empty($dataParams)) {
            $stmt->bind_param($dataTypes, ...$dataParams);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $businessTypes = [];
        while ($row = $result->fetch_assoc()) {
            $businessTypes[] = $row;
        }

        // --- Count query (WHERE only, no ORDER BY / LIMIT) ---
        $countQuery = "SELECT COUNT(*) as total FROM business_type bt WHERE 1$whereClause";

        $countStmt = $this->conn->prepare($countQuery);
        if ($countStmt) {
            if (!empty($whereParams)) {
                $countStmt->bind_param($whereTypes, ...$whereParams);
            }
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $totalRows = $countResult->fetch_assoc()['total'];
        } else {
            $totalRows = 0;
        }

        $totalPages = $fetchAll ? 1 : max(1, ceil($totalRows / $limit));

        return [
            'businessTypes' => $businessTypes,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'totalRecords' => $totalRows,
        ];
    }


    public function addBusinessType($data) {
        $query = "INSERT INTO business_type (business_name_bn, business_name_en, license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge, union_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Database prepare failed: ' . $this->conn->error];
        }
        $businessNameBn  = $data['business_name_bn'] ?? '';
        $businessNameEn  = $data['business_name_en'] ?? '';
        $licenseFee      = $data['license_fee'] ?? 0;
        $vatAmount       = $data['vat_amount'] ?? 0;
        $occupationTax   = $data['occupation_tax'] ?? 0;
        $incomeTax       = $data['income_tax'] ?? 0;
        $signboardTax    = $data['signboard_tax'] ?? 0;
        $surcharge       = $data['surcharge'] ?? 0;
        $unionId         = $data['union_id'] ?? 0;

        $stmt->bind_param(
            "ssddddddi",
            $businessNameBn, $businessNameEn,
            $licenseFee, $vatAmount,
            $occupationTax, $incomeTax,
            $signboardTax, $surcharge,
            $unionId
        );
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'ব্যবসার ধরণ সফলভাবে যোগ করা হয়েছে।' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }

    public function getBusinessTypeById($id) {
        $stmt = $this->conn->prepare("SELECT bt.*, u.union_name_bn FROM business_type bt LEFT JOIN unions u ON bt.union_id = u.union_id WHERE bt.id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function updateBusinessType($id, $data) {
        $query = "UPDATE business_type SET business_name_bn = ?, business_name_en = ?, license_fee = ?, vat_amount = ?, occupation_tax = ?, income_tax = ?, signboard_tax = ?, surcharge = ?, union_id = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Database prepare failed: ' . $this->conn->error];
        }
        $businessNameBn  = $data['business_name_bn'] ?? '';
        $businessNameEn  = $data['business_name_en'] ?? '';
        $licenseFee      = $data['license_fee'] ?? 0;
        $vatAmount       = $data['vat_amount'] ?? 0;
        $occupationTax   = $data['occupation_tax'] ?? 0;
        $incomeTax       = $data['income_tax'] ?? 0;
        $signboardTax    = $data['signboard_tax'] ?? 0;
        $surcharge       = $data['surcharge'] ?? 0;
        $unionId         = $data['union_id'] ?? 0;

        $stmt->bind_param(
            "ssddddddii",
            $businessNameBn, $businessNameEn,
            $licenseFee, $vatAmount,
            $occupationTax, $incomeTax,
            $signboardTax, $surcharge,
            $unionId,
            $id
        );
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'Business type updated successfully.' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }


    public function deleteBusinessType($id) {
        $stmt = $this->conn->prepare("DELETE FROM business_type WHERE id = ?");
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Database prepare failed: ' . $this->conn->error];
        }
        $stmt->bind_param("i", $id);
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'Business type deleted successfully.' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }

    // ==================== Ownership Type ====================

    public function fetchOwnershipTypes() {
        $query = "SELECT * FROM ownership_type ORDER BY id DESC";
        $result = $this->conn->query($query);
        $ownershipTypes = [];
        while ($row = $result->fetch_assoc()) {
            $ownershipTypes[] = $row;
        }
        return $ownershipTypes;
    }

    public function addOwnershipType($data) {
        $query = "INSERT INTO ownership_type (ownership_name_bn, ownership_name_en) VALUES (?, ?)";
        $stmt = $this->conn->prepare($query);
        $ownershipBn = $data['ownership_name_bn'];
        $ownershipEn = $data['ownership_name_en'];
        $stmt->bind_param("ss", $ownershipBn, $ownershipEn);
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'Ownership type added successfully.' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }

    public function getOwnershipTypeById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM ownership_type WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function updateOwnershipType($id, $data) {
        $query = "UPDATE ownership_type SET ownership_name_bn = ?, ownership_name_en = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $ownershipBn = $data['ownership_name_bn'];
        $ownershipEn = $data['ownership_name_en'];
        $stmt->bind_param("ssi", $ownershipBn, $ownershipEn, $id);
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'Ownership type updated successfully.' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }

    public function deleteOwnershipType($id) {
        $stmt = $this->conn->prepare("DELETE FROM ownership_type WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'Ownership type deleted successfully.' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }

    // ==================== Utility Methods ====================
    public function getBusinessTypes() {
        $businessTypes = [];
        $stmt = $this->conn->prepare("SELECT id, business_name_bn, business_name_en FROM business_type ORDER BY business_name_bn ASC");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $businessTypes[] = $row;
            }
            $stmt->close();
        }
        return $businessTypes;
    }

    public function getOwnershipTypes() {
        $ownershipTypes = [];
        $stmt = $this->conn->prepare("SELECT id, ownership_name_bn, ownership_name_en FROM ownership_type ORDER BY ownership_name_bn ASC");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $ownershipTypes[] = $row;
            }
            $stmt->close();
        }
        return $ownershipTypes;
    }
}
