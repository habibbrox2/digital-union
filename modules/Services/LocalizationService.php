<?php
/**
 * modules/Services/LocalizationService.php
 * 
 * Service layer for Bengali localization and breadcrumb generation.
 * Replaces convertToBangla(), convertToBanglaNumber(), generateBreadcrumbs()
 * from config/functions.php.
 */

class LocalizationService
{
    private array $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    private array $banglaNumbers  = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private array $translations = [
        'Islam' => 'ইসলাম',
        'Hindu' => 'হিন্দু',
        'Hinduism' => 'হিন্দু',
        'Buddhist' => 'বৌদ্ধ',
        'Christian' => 'খ্রিস্টান',
        'Other' => 'অন্যান্য',
        'Male' => 'পুরুষ',
        'Female' => 'নারী',
        'Single' => 'অবিবাহিত',
        'Married' => 'বিবাহিত',
        'Divorced' => 'তালাকপ্রাপ্ত',
        'Widowed' => 'বিধবা',
        'permanent' => 'স্থায়ী',
        'temporary' => 'অস্থায়ী',
    ];

    /**
     * Convert English text/numbers to Bangla
     */
    public function convertToBangla(?string $text): string
    {
        if (is_null($text)) {
            return '';
        }
        return $this->translations[$text] ?? str_replace($this->englishNumbers, $this->banglaNumbers, $text);
    }

    /**
     * Convert English number to Bangla numeral string
     */
    public function convertToBanglaNumber(mixed $number): string
    {
        if (is_null($number)) {
            return '';
        }
        return str_replace($this->englishNumbers, $this->banglaNumbers, (string)$number);
    }

    /**
     * Generate breadcrumbs from current URL with Bengali translations
     */
    public function generateBreadcrumbs(string $currentUrl): array
    {
        $currentUrl = parse_url($currentUrl, PHP_URL_PATH);
        $segments = array_filter(explode('/', trim($currentUrl, '/')), 'strlen');

        if (empty($segments)) {
            return [];
        }

        $routeTranslations = $this->getRouteTranslations();

        $excludedSegments = ['admin', 'api', 'tmp'];
        $lastSegmentSegments = ['edit', 'delete', 'view', 'preview'];

        $breadcrumbs = [];
        $path = '';

        $breadcrumbs[] = [
            'name' => 'হোম',
            'url' => '/',
            'icon' => 'fas fa-home'
        ];

        $totalSegments = count($segments);

        foreach ($segments as $index => $segment) {
            if (in_array($segment, $excludedSegments)) {
                continue;
            }
            if (is_numeric($segment) || str_starts_with($segment, '_')) {
                continue;
            }

            $path .= '/' . $segment;
            $name = $routeTranslations[$segment] ?? ucfirst(str_replace('-', ' ', $segment));
            $isLast = ($index === $totalSegments - 1);
            $shouldHaveUrl = !in_array($segment, $lastSegmentSegments) && !$isLast;

            $breadcrumb = ['name' => $name, 'is_active' => $isLast];
            if ($shouldHaveUrl) {
                $breadcrumb['url'] = $path;
            }
            $breadcrumbs[] = $breadcrumb;
        }

        return count($breadcrumbs) <= 1 ? [] : $breadcrumbs;
    }

    private function getRouteTranslations(): array
    {
        return [
            'dashboard' => 'ড্যাশবোর্ড',
            'profile' => 'আমার প্রোফাইল',
            'applications' => 'আবেদনসমূহ',
            'users' => 'ব্যবহারকারী',
            'roles' => 'ভূমিকা',
            'permissions' => 'অনুমতি',
            'unions' => 'ইউনিয়ন',
            'addresses' => 'ঠিকানা',
            'births' => 'জন্ম নিবন্ধন',
            'geo' => 'ভৌগোলিক তথ্য',
            'settings' => 'সেটিংস',
            'term-translations' => 'পদ অনুবাদ',
            'extra-fields' => 'অতিরিক্ত ক্ষেত্র',
            'add' => 'যোগ করুন',
            'edit' => 'সম্পাদনা',
            'view' => 'দেখুন',
            'admin' => 'প্রশাসনিক',
            'login' => 'লগইন',
            'register' => 'নিবন্ধন',
            'logout' => 'লগআউট',
            'update' => 'আপডেট',
            'delete' => 'মুছুন',
            'list' => 'তালিকা',
            'search' => 'অনুসন্ধান',
            'reports' => 'প্রতিবেদন',
            'analytics' => 'বিশ্লেষণ',
            'notifications' => 'বিজ্ঞপ্তি',
            'email' => 'ইমেইল',
            'security' => 'নিরাপত্তা',
            'business-types' => 'ব্যবসার ধরণ',
            'ownership-types' => 'মালিকানার ধরণ',
            'post-offices' => 'পোস্ট অফিস',
            'post_offices' => 'পোস্ট অফিস',
            'email-templates' => 'ইমেইল টেমপ্লেট',
            'email_templates' => 'ইমেইল টেমপ্লেট',
            'migrations' => 'মাইগ্রেশন',
            'error' => 'ত্রুটি',
            'errors' => 'ত্রুটিসমূহ',
            'logs' => 'লগ',
            'manage-permissions' => 'অনুমতি ব্যবস্থাপনা',
            'manage_permissions' => 'অনুমতি ব্যবস্থাপনা',
            'assign' => 'বরাদ্দ করুন',
            'revoke' => 'প্রত্যাহার করুন',
            'change-password' => 'পাসওয়ার্ড পরিবর্তন',
            'change_password' => 'পাসওয়ার্ড পরিবর্তন',
            'password-reset' => 'পাসওয়ার্ড রিসেট',
            'password_reset' => 'পাসওয়ার্ড রিসেট',
            'reset-password' => 'পাসওয়ার্ড রিসেট',
            'reset_password' => 'পাসওয়ার্ড রিসেট',
            'verify' => 'যাচাই করুন',
            'approve' => 'অনুমোদন',
            'reject' => 'বাতিল',
            'download' => 'ডাউনলোড',
            'upload' => 'আপলোড',
            'export' => 'এক্সপোর্ট',
            'import' => 'ইম্পোর্ট',
            'print' => 'প্রিন্ট',
            'certificate' => 'সার্টিফিকেট',
            'certificates' => 'সার্টিফিকেট',
            'renew' => 'নবায়ন',
            'renewal' => 'নবায়ন',
            'history' => 'ইতিহাস',
            'license-renewal-history' => 'লাইসেন্স নবায়ন ইতিহাস',
            'nagorik' => 'নাগরিকত্ব',
            'trade' => 'ট্রেড লাইসেন্স',
            'warish' => 'ওয়ারিশ',
            'family' => 'পরিবার',
            'default' => 'ডিফল্ট',
            'birth' => 'জন্ম',
            'death' => 'মৃত্যু',
            'apply' => 'আবেদন',
            'forms' => 'ফর্ম',
            'online-verify' => 'অনলাইন যাচাই',
            'online_verify' => 'অনলাইন যাচাই',
            'bangla' => 'বাংলা',
            'english' => 'ইংরেজি',
            'public' => 'পাবলিক',
            'employee-list' => 'কর্মচারী তালিকা',
            'employee_list' => 'কর্মচারী তালিকা',
            'setting' => 'সেটিংস',
            'business_types' => 'ব্যবসার ধরণ',
            'ownership_types' => 'মালিকানার ধরণ',
            'ownership_type' => 'মালিকানার ধরণ',
            'post-office' => 'পোস্ট অফিস',
            'email-template' => 'ইমেইল টেমপ্লেট',
            'application' => 'আবেদন',
            'applicant' => 'আবেদনকারী',
            'payment' => 'পেমেন্ট',
            'payments' => 'পেমেন্ট',
            'report' => 'প্রতিবেদন',
            'fee' => 'ফি',
            'fees' => 'ফি',
            'manage' => 'ব্যবস্থাপনা',
            'configuration' => 'কনফিগারেশন',
            'config' => 'কনফিগারেশন',
            'template' => 'টেমপ্লেট',
            'templates' => 'টেমপ্লেট',
            'notification' => 'বিজ্ঞপ্তি',
            'backup' => 'ব্যাকআপ',
            'backups' => 'ব্যাকআপ',
            'help' => 'সাহায্য',
            'about' => 'সম্পর্কে',
            'contact' => 'যোগাযোগ',
            'support' => 'সাপোর্ট',
            'privacy' => 'গোপনীয়তা',
            'terms' => 'শর্তাবলী',
            'api' => 'এপিআই',
            'documentation' => 'ডকুমেন্টেশন',
            'docs' => 'ডকুমেন্টেশন',
            'status' => 'স্থিতি',
        ];
    }
}
