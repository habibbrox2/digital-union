<?php

// ==================== BusinessOwnershipType Class ====================

class BusinessOwnershipType {
    private $conn;

    public function __construct($mysqli) {
        $this->conn = $mysqli;
    }

    public function fetchBusinessTypes($page = 1, $limit = 10, $search = '', $sort = 'id', $order = 'ASC') {
        $offset = ($page - 1) * $limit;

        // নিরাপদ কলাম চেকিং
        $allowedSortColumns = ['id', 'business_name_bn', 'business_name_en', 'license_fee', 'vat_amount', 'occupation_tax', 'income_tax', 'signboard_tax', 'surcharge'];
        if (!in_array($sort, $allowedSortColumns)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        // মূল query
        $query = "SELECT * FROM business_type WHERE 1";

        if (!empty($search)) {
            $query .= " AND (business_name_bn LIKE ? OR business_name_en LIKE ?)";
        }

        $query .= " ORDER BY $sort $order LIMIT ?, ?";

        $stmt = $this->conn->prepare($query);

        if (!empty($search)) {
            $searchParam = "%$search%";
            $stmt->bind_param("ssii", $searchParam, $searchParam, $offset, $limit);
        } else {
            $stmt->bind_param("ii", $offset, $limit);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $businessTypes = [];
        while ($row = $result->fetch_assoc()) {
            $businessTypes[] = $row;
        }

        // Total count
        $countQuery = "SELECT COUNT(*) as total FROM business_type WHERE 1";
        if (!empty($search)) {
            $countQuery .= " AND (business_name_bn LIKE ? OR business_name_en LIKE ?)";
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bind_param("ss", $searchParam, $searchParam);
        } else {
            $countStmt = $this->conn->prepare($countQuery);
        }

        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRows = $countResult->fetch_assoc()['total'];
        $totalPages = ceil($totalRows / $limit);

        return [
            'businessTypes' => $businessTypes,
            'totalPages' => $totalPages,
            'currentPage' => $page
        ];
    }


    public function addBusinessType($data) {
        $query = "INSERT INTO business_type (business_name_bn, business_name_en, license_fee, vat_amount, occupation_tax, income_tax, signboard_tax, surcharge, union_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "ssddddddi",
            $data['business_name_bn'],
            $data['business_name_en'],
            $data['license_fee'],
            $data['vat_amount'],
            $data['occupation_tax'],
            $data['income_tax'],
            $data['signboard_tax'],
            $data['surcharge'],
            $data['union_id']
        );
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'ব্যবসার ধরণ সফলভাবে যোগ করা হয়েছে।' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }

    public function getBusinessTypeById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM business_type WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function updateBusinessType($id, $data) {
        $query = "UPDATE business_type SET business_name_bn = ?, business_name_en = ?, license_fee = ?, vat_amount = ?, occupation_tax = ?, income_tax = ?, signboard_tax = ?, surcharge = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "ssddddddi",
            $data['business_name_bn'],
            $data['business_name_en'],
            $data['license_fee'],
            $data['vat_amount'],
            $data['occupation_tax'],
            $data['income_tax'],
            $data['signboard_tax'],
            $data['surcharge'],
            $id
        );
        return $stmt->execute() ? [ 'status' => 'success', 'message' => 'Business type updated successfully.' ] :
            [ 'status' => 'error', 'message' => $stmt->error ];
    }


    public function deleteBusinessType($id) {
        $stmt = $this->conn->prepare("DELETE FROM business_type WHERE id = ?");
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
        $stmt->bind_param("ss", $data['ownership_name_bn'], $data['ownership_name_en']);
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
        $stmt->bind_param("ssi", $data['ownership_name_bn'], $data['ownership_name_en'], $id);
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
