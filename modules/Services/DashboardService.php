<?php
/**
 * modules/Services/DashboardService.php
 * 
 * Service layer for all dashboard-related business logic.
 * Controllers should only call these methods and render templates.
 */

class DashboardService
{
    private mysqli $mysqli;
    private ?AuthManager $auth = null;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    private function getAuth(): AuthManager
    {
        if ($this->auth === null) {
            $this->auth = new AuthManager($this->mysqli);
        }
        return $this->auth;
    }

    /**
     * Get monthly total applications chart data
     */
    public function getMonthlyCertificateData(): array
    {
        $user = $this->getAuth()->getUserData(false);
        $params = [];
        $types = '';
        $unionModel = new UnionModel($this->mysqli);
        $where = $unionModel->getUnionCondition($params, $types, 'a', true);

        $query = "
            SELECT DATE_FORMAT(a.apply_date, '%Y-%m') AS month, COUNT(a.id) AS total
            FROM applications a
            $where
            GROUP BY month
            ORDER BY month
        ";

        $stmt = $this->mysqli->prepare($query);
        if ($stmt === false) {
            return ['labels' => [], 'datasets' => []];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $labels = [];
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['month'];
            $data[] = (int)$row['total'];
        }
        $stmt->close();

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'মোট আবেদন',
                'data' => $data,
                'borderColor' => '#007bff',
                'backgroundColor' => '#007bff',
                'fill' => false
            ]]
        ];
    }

    /**
     * Get monthly applications by status chart data
     */
    public function getMonthlyStatusData(): array
    {
        $user = $this->getAuth()->getUserData(false);
        $params = [];
        $types = '';
        $unionModel = new UnionModel($this->mysqli);
        $where = $unionModel->getUnionCondition($params, $types, 'a', true);

        $query = "
            SELECT DATE_FORMAT(a.apply_date, '%Y-%m') AS month, LOWER(a.status) AS status, COUNT(a.id) AS total
            FROM applications a
            $where
            GROUP BY month, status
            ORDER BY month
        ";

        $stmt = $this->mysqli->prepare($query);
        if ($stmt === false) {
            return ['labels' => [], 'datasets' => []];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $rawData = [];
        $labels = [];
        while ($row = $result->fetch_assoc()) {
            $rawData[$row['status']][$row['month']] = (int)$row['total'];
            if (!in_array($row['month'], $labels)) {
                $labels[] = $row['month'];
            }
        }
        $stmt->close();

        $statusTypes = ['pending', 'approved', 'rejected', 'on_hold'];
        $colors = ['#ffc107', '#28a745', '#dc3545', '#6c757d'];
        $datasets = [];

        foreach ($statusTypes as $index => $status) {
            $data = [];
            foreach ($labels as $month) {
                $data[] = $rawData[$status][$month] ?? 0;
            }
            $datasets[] = [
                'label' => ucfirst(str_replace('_', ' ', $status)),
                'data' => $data,
                'borderColor' => $colors[$index],
                'backgroundColor' => $colors[$index],
                'fill' => false
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /**
     * Get certificate data grouped by type
     */
    public function getCertificateData(): array
    {
        $user = $this->getAuth()->getUserData(false);
        $union_id = $user['union_id'] ?? null;

        $params = [];
        $types = '';

        $query = "
            SELECT t.slug, t.name_bn, COUNT(a.id) AS total_applications
            FROM term_translations AS t
            LEFT JOIN applications AS a
                ON t.slug = a.certificate_type
        ";

        if (!empty($union_id) && $union_id != 0) {
            $query .= " AND a.union_id = ?";
            $params[] = $union_id;
            $types .= 'i';
        }

        $query .= "
            WHERE t.is_certificate_type = 1
            GROUP BY t.slug, t.name_bn
            ORDER BY t.name_bn ASC
        ";

        $stmt = $this->mysqli->prepare($query);
        if ($stmt === false) {
            return ['total_certificates' => 0, 'total_applications' => 0, 'labels' => [], 'datasets' => []];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $colors = [
            '#007bff', '#dc3545', '#28a745', '#ffc107', '#6610f2', '#fd7e14',
            '#20c997', '#6f42c1', '#e83e8c', '#343a40', '#17a2b8', '#fd6f6f',
            '#ffc0cb', '#90ee90', '#ffa500', '#8a2be2', '#00ced1', '#ff69b4',
            '#cd5c5c', '#4b0082', '#2e8b57', '#ff4500', '#9acd32', '#1e90ff',
            '#ff1493', '#32cd32', '#8b0000', '#00fa9a', '#ff6347', '#4682b4',
            '#b22222', '#ff8c00', '#006400', '#8b008b', '#483d8b', '#2f4f4f',
            '#00bfff', '#ff00ff', '#c71585', '#191970', '#7fff00', '#d2691e',
            '#ff7f50', '#6495ed', '#ffdead', '#daa520', '#808000', '#556b2f',
            '#fa8072', '#f08080', '#e9967a', '#8fbc8f', '#20b2aa', '#87cefa'
        ];

        $datasets = [];
        $index = 0;
        $totalApplications = 0;
        $totalCertificates = 0;

        while ($row = $result->fetch_assoc()) {
            $datasets[] = [
                'label' => $row['name_bn'],
                'data' => [(int)$row['total_applications']],
                'borderColor' => $colors[$index % count($colors)],
                'backgroundColor' => $colors[$index % count($colors)],
                'fill' => false
            ];
            $totalApplications += (int)$row['total_applications'];
            $totalCertificates++;
            $index++;
        }
        $stmt->close();

        // Get total certificate count separately
        $stmt2 = $this->mysqli->prepare("SELECT COUNT(*) AS total_certificates FROM term_translations WHERE is_certificate_type = 1");
        $stmt2->execute();
        $result2 = $stmt2->get_result()->fetch_assoc();
        $totalCertificatesAlt = (int)$result2['total_certificates'];
        $stmt2->close();

        return [
            'total_certificates' => $totalCertificatesAlt,
            'total_applications' => $totalApplications,
            'labels' => ['মোট আবেদন'],
            'datasets' => $datasets
        ];
    }

    /**
     * Search certificates across all types
     */
    public function certificateSearch(string $query): array
    {
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return ['error' => 'Search term length invalid'];
        }

        $stmt = $this->mysqli->prepare("
            SELECT
                a.id AS app_id,
                a.application_id,
                a.applicant_id,
                a.sonod_number,
                a.name_bn,
                a.name_en,
                a.issue_date,
                a.certificate_type,
                a.apply_date,
                a.extra_data,
                aa.approval_status,
                am.name_en AS member_name_en,
                am.name_bn AS member_name_bn,
                am.relation_en,
                am.relation_bn,
                am.nid AS member_nid,
                bm.business_name_en,
                bm.business_name_bn,
                bm.vat_id,
                bm.tax_id,
                t.name_bn AS certificate_name_bn,
                t.name_en AS certificate_name_en,
                t.name_bl AS certificate_name_bl
            FROM applications a
            LEFT JOIN term_translations t ON t.slug = a.certificate_type
            LEFT JOIN application_approvals aa ON a.application_id = aa.application_id
            LEFT JOIN application_members am ON a.application_id = am.application_id
            LEFT JOIN business_meta bm ON a.application_id = bm.application_id
            WHERE
                a.application_id LIKE CONCAT('%', ?, '%')
                OR a.sonod_number LIKE CONCAT('%', ?, '%')
                OR a.name_en LIKE CONCAT('%', ?, '%')
                OR a.name_bn LIKE CONCAT('%', ?, '%')
                OR a.extra_data LIKE CONCAT('%', ?, '%')
                OR am.name_en LIKE CONCAT('%', ?, '%')
                OR am.name_bn LIKE CONCAT('%', ?, '%')
                OR am.nid LIKE CONCAT('%', ?, '%')
                OR bm.business_name_en LIKE CONCAT('%', ?, '%')
                OR bm.business_name_bn LIKE CONCAT('%', ?, '%')
                OR bm.vat_id LIKE CONCAT('%', ?, '%')
                OR bm.tax_id LIKE CONCAT('%', ?, '%')
            ORDER BY a.issue_date DESC
            LIMIT 50
        ");

        if (!$stmt) {
            return ['error' => 'Database error preparing statement'];
        }

        $stmt->bind_param(str_repeat('s', 12), $query, $query, $query, $query, $query, $query, $query, $query, $query, $query, $query, $query);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();

        return ['query' => $query, 'count' => count($data), 'results' => $data];
    }

    /**
     * Get error statistics
     */
    public function getErrorStats(): array
    {
        $stats = ErrorHandler::getErrorStats();

        $bytes = $stats['file_size_bytes'];
        if ($bytes >= 1048576) {
            $stats['file_size_display'] = round($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            $stats['file_size_display'] = round($bytes / 1024, 1) . ' KB';
        } else {
            $stats['file_size_display'] = $bytes . ' B';
        }

        return $stats;
    }

    /**
     * Get error logs for live view
     */
    public function getErrorLogs(): array
    {
        $logFile = __DIR__ . '/../../storage/logs/error.log';
        if (!file_exists($logFile)) {
            return ['logs' => []];
        }

        $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return ['logs' => array_slice($logs, -1000)];
    }

    /**
     * Clear error logs
     */
    public function clearErrorLogs(): bool
    {
        $logFile = __DIR__ . '/../../storage/logs/error.log';
        return file_put_contents($logFile, '') !== false;
    }

    /**
     * Check if error log file exists and is readable
     */
    public function getErrorLogFile(): string
    {
        return __DIR__ . '/../../storage/logs/error.log';
    }
}
