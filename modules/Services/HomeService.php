<?php
/**
 * modules/Services/HomeService.php
 * 
 * Service layer for public pages and employee data.
 * Handles loading and processing employee data from storage.
 */

class HomeService
{
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
        $employees = [];

        if (isset($staticData[$slug]) && is_array($staticData[$slug])) {
            $unionMap = [];
            
            foreach ($staticData[$slug] as $emp) {
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
            
            $employees = array_values($unionMap);
        }

        return $employees;
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
