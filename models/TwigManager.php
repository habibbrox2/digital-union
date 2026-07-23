<?php

    // models/TwigManager.php - simplified & fixed

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config/functions.php';
    require_once __DIR__ . '/AuthManager.php';
    require_once __DIR__ . '/RolesManager.php';
    require_once __DIR__ . '/../modules/Services/LocalizationService.php';
    require_once __DIR__ . '/../modules/Services/SettingService.php';
    require_once __DIR__ . '/../modules/Services/SanitizationService.php';

    class TwigManager {
        private $twig;
        private $localization;
        private $settings;
        private $sanitizer;

        public function __construct($mysqli, $enableCache = false) {
            $this->localization = new LocalizationService();
            $this->settings = new SettingService($mysqli);
            $this->sanitizer = new SanitizationService();
            $templatePath = __DIR__ . '/../templates';
            if (!is_dir($templatePath)) {
                throw new \RuntimeException("Templates directory not found at $templatePath");
            }

            $twigCacheDir = defined('CACHE_DIR') ? CACHE_DIR . '/twig' : sys_get_temp_dir() . '/twig_cache';
            $publicTempDir = defined('TEMP_DIR') ? TEMP_DIR : sys_get_temp_dir();

            foreach ([$twigCacheDir, $publicTempDir] as $dir) {
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
            }

            $loader = new \Twig\Loader\FilesystemLoader($templatePath);
            $this->twig = new \Twig\Environment($loader, [
                'cache' => $enableCache ? $twigCacheDir : false,
                'debug' => true,
                'auto_reload' => true,
            ]);

            $this->registerExtensions();
            $this->registerGlobals($mysqli);
            $this->registerFunctions($mysqli);
        }

        private function registerExtensions() {
            $this->twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        private function registerGlobals($mysqli) {
            $auth = new AuthManager($mysqli);
            $this->twig->addGlobal('is_logged_in', $auth->isLoggedIn());
            $this->twig->addGlobal('url', defined('SITE_URL') ? SITE_URL : '/');
            $this->twig->addGlobal('csrf_token', function_exists('generateCsrfToken') ? generateCsrfToken() : '');
            // Sanitize previous_url: strip dangerous URL schemes (javascript:, data:, vbscript:)
            $rawReferer = $_SERVER['HTTP_REFERER'] ?? '/';
            $allowedSchemes = ['http', 'https', 'ftp', 'ftps'];
            $parsedUrl = parse_url($rawReferer);
            if ($parsedUrl === false) {
                // Malformed URL — treat as unsafe
                $rawReferer = '/';
            } else {
                $scheme = $parsedUrl['scheme'] ?? '';
                if ($scheme !== '' && !in_array(strtolower($scheme), $allowedSchemes, true)) {
                    $rawReferer = '/';
                }
            }
            $this->twig->addGlobal('previous_url', $rawReferer);

            // Breadcrumbs with automatic Bengali translation
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
            $breadcrumbs = $this->localization->generateBreadcrumbs($currentUrl);
            
            // Show breadcrumbs if we have more than just home and not on specific pages
            $showBreadcrumbs = !empty($breadcrumbs) && 
                               $currentUrl !== '/' && 
                               !preg_match('/\/(index|login|logout|register)$/', $currentUrl);
            
            $this->twig->addGlobal('show_breadcrumbs', $showBreadcrumbs);
            $this->twig->addGlobal('breadcrumbs', $breadcrumbs);

            $userData = $auth->getUserData(false);
            $sidebarApplicationCount = 0;
            $certificateTypeCounts = [];
            if ($userData) {
                $userId = $userData['user_id'] ?? null;
                $rolesManager = new RolesManager($mysqli);
                $role = $rolesManager->getRoleById($userData['role_id'] ?? null);
                $userRole = $role['role_name'] ?? null;

                // Permissions - use PermissionsManager for role-based permissions only
                require_once __DIR__ . '/PermissionsManager.php';
                $permissionsManager = new PermissionsManager($mysqli);

                $isSuperAdmin = isset($userData['role_id']) && $userData['role_id'] <= 1;

                $this->twig->addGlobal('auth_user', $userData);
                $this->twig->addGlobal('user_role', $userRole);
                $this->twig->addGlobal('is_superadmin', $isSuperAdmin);
                $this->twig->addGlobal('current_user', $userData);

                $unionId = (int)($userData['union_id'] ?? 0);
                $countSql = "
                    SELECT
                        tt.slug AS type_url,
                        COUNT(a.id) AS total_applications
                    FROM term_translations tt
                    LEFT JOIN applications a
                        ON a.certificate_type = tt.slug
                ";
                $countParams = [];
                $countTypes = '';

                if (!$isSuperAdmin && $unionId > 0) {
                    $countSql .= " AND a.union_id = ?";
                    $countParams[] = $unionId;
                    $countTypes .= 'i';
                }

                $countSql .= "
                    WHERE tt.is_certificate_type = 1
                    GROUP BY tt.slug, tt.name_bl
                ";

                if ($countStmt = $mysqli->prepare($countSql)) {
                    if (!empty($countParams)) {
                        $countStmt->bind_param($countTypes, ...$countParams);
                    }
                    if ($countStmt->execute()) {
                        $countResult = $countStmt->get_result();
                        while ($countRow = $countResult ? $countResult->fetch_assoc() : null) {
                            if (!$countRow) {
                                break;
                            }
                            $typeUrl = trim((string)($countRow['type_url'] ?? ''));
                            if ($typeUrl !== '') {
                                $certificateTypeCounts[$typeUrl] = (int)($countRow['total_applications'] ?? 0);
                            }
                            $sidebarApplicationCount += (int)($countRow['total_applications'] ?? 0);
                        }
                    }
                    $countStmt->close();
                }

                $this->twig->addFunction(new \Twig\TwigFunction('can', function ($permissionName) use ($permissionsManager, $userId) {
                    if (!$userId) return false;
                    return $permissionsManager->hasPermission($userId, $permissionName);
                }));

            }

            $settings = $this->settings->getSystemSettings();
            $this->twig->addGlobal('settings', $settings);
            $this->twig->addGlobal('sidebar_application_count', $sidebarApplicationCount);
            $this->twig->addGlobal('certificate_type_counts', $certificateTypeCounts);

            // SweetAlert helper
            $sweetAlert = null;
            $sweetHelper = __DIR__ . '/../helpers/sweetalertHelper.php';
            if (file_exists($sweetHelper)) {
                require_once $sweetHelper;
                if (function_exists('getSweetAlert')) $sweetAlert = getSweetAlert();
            }
            $this->twig->addGlobal('sweetAlert', $sweetAlert);

        }

        private function registerFunctions($mysqli) {
            global $allRoutes;
            $this->twig->addFunction(new \Twig\TwigFunction('asset', fn($path) => '/assets/' . ltrim($path, '/')));
            $this->twig->addFunction(new \Twig\TwigFunction('path', function ($routeName, $params = []) use ($allRoutes) {
                $routeName = trim($routeName, '/');
                if (!empty($params) && is_array($params)) {
                    $query = http_build_query($params);
                    return '/' . $routeName . '?' . $query;
                }
                return '/' . $routeName;
            }));
            // Title-case filter for Twig (handles underscores and multibyte characters)
            $this->twig->addFilter(new \Twig\TwigFilter('title', function ($value) {
                if ($value === null) return '';
                $s = (string) $value;
                $s = str_replace('_', ' ', $s);
                return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('bn', [$this->localization, 'convertToBangla']));
        /**
         * trans filter
         * Usage: {{ 'Hello World'|trans }}
         */
        $this->twig->addFilter(new \Twig\TwigFilter('trans', function ($string, array $params = []) {

            // 🔹 Simple placeholder replacement (optional)
            foreach ($params as $key => $value) {
                $string = str_replace('{' . $key . '}', $value, $string);
            }

            // 🔹 No translation system yet → return as-is
            return $string;
        }));
            $this->twig->addFilter(new \Twig\TwigFilter('bn_number', [$this->localization, 'convertToBanglaNumber']));
            $truncateFilter = new \Twig\TwigFilter('truncate', function ($string, $length = 30, $preserve = false, $separator = '...') {
                if ($string === null) return '';
                $s = (string)$string;
                if (mb_strlen($s) <= $length) return $s;
                if ($preserve) {
                    $spacePos = mb_strpos($s, ' ', $length);
                    if ($spacePos !== false) {
                        $length = $spacePos;
                    }
                }
                return rtrim(mb_substr($s, 0, $length)) . $separator;
            });
            $this->twig->addFilter($truncateFilter);
            $listFilter = new \Twig\TwigFilter('list', function ($value, $delimiter = ',') {
                if ($value === null) return [];
                if (is_array($value)) return $value;
                if ($value instanceof \Traversable) return iterator_to_array($value);
                if (is_string($value)) {
                    $v = trim($value);
                    if ($v === '') return [];
                    return array_map('trim', explode($delimiter, $v));
                }
                return [$value];
            });
            $this->twig->addFilter($listFilter);

            $mapFilter = new \Twig\TwigFilter('map', function ($items, $arrow = null, $attribute = null) {
                // accept either positional ($arrow) or named argument attribute
                $key = $attribute ?? $arrow;

                if ($items === null) return [];
                if ($items instanceof \Traversable) $items = iterator_to_array($items);
                if (!is_array($items)) return [];

                // no key => return values as-is
                if ($key === null) {
                    return array_values($items);
                }

                // if callable provided, apply it
                if (is_callable($key)) {
                    return array_values(array_map($key, $items));
                }

                // if string -> map by property/key name
                $out = [];
                foreach ($items as $it) {
                    if (is_array($it) && array_key_exists($key, $it)) {
                        $out[] = $it[$key];
                    } elseif (is_object($it) && (isset($it->$key) || property_exists($it, $key))) {
                        $out[] = $it->$key;
                    } else {
                        $out[] = null;
                    }
                }
                return $out;
            });
            $this->twig->addFilter($mapFilter);

            // Encrypt URL helper for templates
            $this->twig->addFunction(new \Twig\TwigFunction('encrypt_url', function ($data) {
                if (empty($data)) return '';
                try {
                    $crypt = new CryptManager(ENCRYPTION_KEY, ENCRYPTION_METHOD);
                    return $crypt->encrypt((string)$data);
                } catch (\Throwable $e) {
                    error_log('encrypt_url failed: ' . $e->getMessage());
                    return $data; // fallback to plaintext
                }
            }));

            // Role helper for templates
            try {
                $rolesManager = new RolesManager($mysqli);
                $this->twig->addFunction(new \Twig\TwigFunction('getRoleName', function ($roleId) use ($rolesManager) {
                    if (empty($roleId)) return null;
                    $role = $rolesManager->getRoleById($roleId);
                    return $role['role_name'] ?? null;
                }));
            } catch (\Throwable $e) {
                // Fail silently if RolesManager is not available at runtime
            }
        }

        public function render(string $template, array $data = []): string {

            try {
                $tpl = $this->twig->load($template);
                return $tpl->render($data);

            } catch (\Twig\Error\LoaderError $e) {
                if (class_exists('ErrorHandler')) {
                    ErrorHandler::handleTwigError($e, $template);
                }
                throw new \Exception("Twig loader error for template {$template}: " . $e->getMessage());

            } catch (\Twig\Error\Error $e) {
                if (class_exists('ErrorHandler')) {
                    ErrorHandler::handleTwigError($e, $template);
                }
                throw new \Exception("Twig rendering error for {$template}: " . $e->getMessage());

            } catch (\Throwable $e) {
                if (class_exists('ErrorHandler')) {
                    ErrorHandler::handleTwigError($e, $template);
                }
                throw new \Exception("Unknown error while rendering template {$template}: " . $e->getMessage());
            }
        }
        public function twigPrepare(string $template, array $data = []): ?callable {

            try {

                $tpl = $this->twig->load($template);

                return function() use ($tpl, $data) {

                    try {

                        return $tpl->render($data);

                    } catch (\Twig\Error\Error $e) {

                        error_log("Twig render error: " . $e->getMessage());

                        return '';

                    }

                };

            } catch (\Twig\Error\LoaderError $e) {

                error_log("Template not found: {$template} - " . $e->getMessage());

                return null;

            } catch (\Twig\Error\Error $e) {

                error_log("Twig error for template {$template}: " . $e->getMessage());

                return null;

            }

        }

        public function getTwig() {
            return $this->twig;
        }
    }
