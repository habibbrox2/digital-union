<?php
/**
 * modules/Services/HomeService.php
 * 
 * Service layer for public pages and employee data.
 * Handles loading and processing employee data from storage.
 */

class HomeService
{
    private $mysqli;

    public function __construct($mysqli = null)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Get employee page configurations
     */
    public function getPageConfigs(): array
    {
        return [
            'chairman' => [
                'title' => 'চেয়ারম্যান',
                'subtitle' => 'ঢাকা জেলার সকল ইউপি চেয়ারম্যান বৃন্দ',
            ],
            'secretary' => [
                'title' => 'ইউপি প্রশাসনিক কর্মকর্তা',
                'subtitle' => 'ঢাকা জেলার সকল ইউপি ইউপি প্রশাসনিক কর্মকর্তা বৃন্দ',
            ],
            'computer_operator' => [
                'title' => 'হিসাব সহকারী',
                'subtitle' => 'ঢাকা জেলার সকল ইউপি হিসাব সহকারী কম্পিউটার অপারেটর বৃন্দ',
            ],
            'member' => [
                'title' => 'মেম্বার',
                'subtitle' => 'ঢাকা জেলার সকল ইউপি মেম্বার বৃন্দ',
            ],
            'village_police' => [
                'title' => 'গ্রামপুলিশ',
                'subtitle' => 'ঢাকা জেলার সকল ইউপি গ্রামপুলিশ বৃন্দ',
            ],
            'udc' => [
                'title' => 'উদ্যোক্তা',
                'subtitle' => 'ঢাকা জেলার সকল ইউপি উদ্যোক্তা বৃন্দ',
            ]
        ];
    }

    /**
     * Load employee data for a given slug
     */
    public function getEmployeeData(string $slug): array
    {
        $staticData = $this->loadStaticData();

        if (isset($staticData[$slug]) && is_array($staticData[$slug]) && count($staticData[$slug]) > 0) {
            return $this->formatEmployees($staticData[$slug]);
        }

        return $this->loadFromDatabase($slug);
    }

    /**
     * Load employee data from database by role slug
     */
    private function loadFromDatabase(string $slug): array
    {
        if (!$this->mysqli) {
            return [];
        }

        $roleMap = [
            'chairman' => 3,
            'secretary' => 2,
            'computer_operator' => 5,
            'member' => 4,
            'village_police' => 6,
            'udc' => 7,
        ];

        if (!isset($roleMap[$slug])) {
            return [];
        }

        $roleId = $roleMap[$slug];

        $sql = "SELECT u.name_bn, u.name_en, u.designation, u.phone_number, u.email,
                       u.profile_picture_url, un.union_name_bn
                FROM users u
                LEFT JOIN unions un ON u.union_id = un.union_id
                WHERE u.role_id = ? AND u.is_deleted = 0
                ORDER BY un.union_name_bn ASC, u.name_bn ASC";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result();

        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = [
                'union' => $row['union_name_bn'] ?: 'অজানা ইউনিয়ন',
                'name' => $row['name_bn'] ?: $row['name_en'],
                'designation' => $row['designation'] ?: ucfirst(str_replace('_', ' ', $slug)),
                'mobile' => $row['phone_number'] ?: '',
                'email' => $row['email'] ?: '',
                'image' => $row['profile_picture_url'] ?: '/assets/images/default.png',
                'electoral_area' => ''
            ];
        }

        $stmt->close();

        return $this->formatEmployees($employees);
    }

    /**
     * Format raw employee array into union-grouped structure
     */
    private function formatEmployees(array $employees): array
    {
        $unionMap = [];

        foreach ($employees as $emp) {
            $unionName = $emp['union'] ?: 'অজানা ইউনিয়ন';

            if (!isset($unionMap[$unionName])) {
                $unionMap[$unionName] = [
                    'union_name' => $unionName,
                    'persons' => []
                ];
            }

            $unionMap[$unionName]['persons'][] = [
                'name' => $emp['name'],
                'designation' => $emp['designation'],
                'mobile' => $emp['mobile'],
                'email' => $emp['email'],
                'image' => $emp['image'] ?: '/assets/images/default.png',
                'electoral_area' => $emp['electoral_area'] ?? ''
            ];
        }

        return array_values($unionMap);
    }

    /**
     * Load static data from file
     */
    private function loadStaticData(): array
    {
        $dataFile = __DIR__ . '/../../storage/data/employee_data.php';
        if (file_exists($dataFile)) {
            return require $dataFile;
        }
        return [];
    }
}
