<?php
// classes/UnionModel.php

class UnionModel {
    protected $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    // Fetch all unions (simple)
    public function getAllUnions() {
        $sql = "SELECT * FROM unions ORDER BY union_name_en ASC";
        $result = $this->mysqli->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    // Fetch unions with search, sort, pagination
    public function fetchAllUnions($search = '', $sortBy = 'union_name_en', $sortDir = 'ASC', $page = 1, $limit = 10) {
        $validSortColumns = [
            'union_name_en','union_name_bn',
            'upazila_name_en','district_name_en',
            'division_name_en','ward_count',
            'email','phone','website'
        ];

        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'union_name_en';
        }

        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

        $page   = max((int)$page, 1);
        $limit  = max((int)$limit, 1);
        $offset = ($page - 1) * $limit;

        $sql    = "FROM unions WHERE 1=1 ";
        $params = [];
        $types  = '';

        if ($search !== '') {
            $search = '%' . $search . '%';
            $sql .= " AND (
                union_name_en LIKE ? OR union_name_bn LIKE ?
                OR upazila_name_en LIKE ? OR district_name_en LIKE ?
                OR division_name_en LIKE ? OR email LIKE ?
                OR phone LIKE ? OR website LIKE ?
            ) ";
            for ($i = 0; $i < 8; $i++) {
                $params[] = $search;
                $types   .= 's';
            }
        }

        // count
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) total {$sql}");
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        // data
        $stmt = $this->mysqli->prepare(
            "SELECT * {$sql} ORDER BY {$sortBy} {$sortDir} LIMIT ? OFFSET ?"
        );

        if ($params) {
            $params[] = $limit;
            $params[] = $offset;
            $types   .= 'ii';
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param('ii', $limit, $offset);
        }

        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['data' => $data, 'total' => $total];
    }

    // Get union by ID
    public function getById($id) {
        $stmt = $this->mysqli->prepare("SELECT * FROM unions WHERE union_id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    }

    // Get union info helper
    public function getInfo($union_id) {
        if (!is_numeric($union_id) || $union_id <= 0) {
            return [null, null];
        }

        $union = $this->getById((int)$union_id);
        $union_code = $union['union_code'] ?? null;

        return [$union, $union_code];
    }

    // Union condition helper (permission-based)
    public function getUnionCondition(&$params, &$types, $alias = '', $withWhere = false) {
        $prefix = $alias ? "{$alias}." : '';
        $auth   = new AuthManager($this->mysqli);
        $user   = $auth->getUserData(false);

        if (!empty($user['union_id']) && $user['union_id'] != 0) {
            $params[] = $user['union_id'];
            $types   .= 'i';
            return ($withWhere ? 'WHERE' : '') . " {$prefix}union_id = ?";
        }
        return $withWhere ? 'WHERE 1=1' : '';
    }
}
