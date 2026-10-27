<?php
class TermTranslation {
    private $mysqli;

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }

    private function sanitizeSortColumn($column) {
        $allowed = ['id', 'slug', 'name_bn', 'name_en', 'name_bl'];
        return in_array($column, $allowed) ? $column : 'id';
    }

    private function sanitizeSortDirection($direction) {
        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    }

    public function fetchFiltered($search = '', $sortColumn = 'id', $sortDirection = 'ASC', $limit = 10, $offset = 0) {
        $sortColumn = $this->sanitizeSortColumn($sortColumn);
        $sortDirection = $this->sanitizeSortDirection($sortDirection);

        $search = "%{$search}%";
        $query = "SELECT * FROM term_translations 
                  WHERE slug LIKE ? OR name_bn LIKE ? OR name_en LIKE ? OR name_bl LIKE ?
                  ORDER BY $sortColumn $sortDirection
                  LIMIT ? OFFSET ?";

        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return [];
        }

        $stmt->bind_param("ssssii", $search, $search, $search, $search, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }

    public function countFiltered($search = '') {
        $search = "%{$search}%";
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as total FROM term_translations 
                                        WHERE slug LIKE ? OR name_bn LIKE ? OR name_en LIKE ? OR name_bl LIKE ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return 0;
        }

        $stmt->bind_param("ssss", $search, $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['total'] ?? 0;
    }

    public function existsBySlugAndNameBl($slug, $name_bl) {
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as count FROM term_translations WHERE slug = ? AND name_bl = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("ss", $slug, $name_bl);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return ($result['count'] ?? 0) > 0;
    }

    public function getAll() {
        $query = "SELECT * FROM term_translations ORDER BY id DESC";
        $result = $this->mysqli->query($query);
        if (!$result) {
            error_log("Query failed: " . $this->mysqli->error);
            return [];
        }

        $terms = [];
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        return $terms;
    }

    public function getById($id) {
        $stmt = $this->mysqli->prepare("SELECT * FROM term_translations WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return null;
        }

        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function create($slug, $name_bn, $name_en, $name_bl, $is_certificate_type = 0) {
        $slug = trim($slug);
        $name_bn = trim($name_bn);
        $name_en = trim($name_en);
        $name_bl = trim($name_bl);

        if (empty($slug) || empty($name_bl)) {
            error_log("Validation failed: slug or name_bl is empty.");
            return false;
        }

        $stmt = $this->mysqli->prepare("INSERT INTO term_translations (slug, name_bn, name_en, name_bl, is_certificate_type) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("ssssi", $slug, $name_bn, $name_en, $name_bl, $is_certificate_type);
        return $stmt->execute();
    }

    public function update($id, $slug, $name_bn, $name_en, $name_bl, $is_certificate_type = 0) {
        $slug = trim($slug);
        $name_bn = trim($name_bn);
        $name_en = trim($name_en);
        $name_bl = trim($name_bl);

        if (empty($slug) || empty($name_bl)) {
            error_log("Validation failed: slug or name_bl is empty.");
            return false;
        }

        $stmt = $this->mysqli->prepare("UPDATE term_translations SET slug = ?, name_bn = ?, name_en = ?, name_bl = ?, is_certificate_type = ? WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("ssssii", $slug, $name_bn, $name_en, $name_bl, $is_certificate_type, $id);
        return $stmt->execute();
    }

    public function delete($id) {
        $stmt = $this->mysqli->prepare("DELETE FROM term_translations WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    public function getBySlug($slug) {
        $stmt = $this->mysqli->prepare("SELECT * FROM term_translations WHERE slug = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return [];
        }

        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $result = $stmt->get_result();

        $terms = [];
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        return $terms;
    }

    public function getCertificateTypes() {
        $stmt = $this->mysqli->prepare("SELECT * FROM term_translations WHERE is_certificate_type = 1 ORDER BY id ASC");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->mysqli->error);
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $terms = [];
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        return $terms;
    }

    public function updateSlugInRelatedTables($oldSlug, $newSlug) {
        $tables = ['applications', 'application_approvals'];
        foreach ($tables as $table) {
            $query = "UPDATE $table SET certificate_type = ? WHERE certificate_type = ?";
            $stmt = $this->mysqli->prepare($query);
            if ($stmt) {
                $stmt->bind_param("ss", $newSlug, $oldSlug);
                $stmt->execute();
                $stmt->close();
            } else {
                error_log("Failed to prepare slug update for $table: " . $this->mysqli->error);
            }
        }
    }
}
