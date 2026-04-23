<?php

/**
 * Validation Helper - Production Ready
 * Data validation utilities (function-based)
 */

if (!function_exists('validateRequired')) {
    function validateRequired($data, $field, $message = null) {
        if (empty($data[$field] ?? null)) {
            return ['valid' => false, 'error' => $message ?? "{$field} আবশ্যক"];
        }
        return ['valid' => true];
    }
}

if (!function_exists('validateEmail')) {
    function validateEmail($data, $field, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'error' => $message ?? "{$field} বৈধ ইমেইল নয়"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateMinLength')) {
    function validateMinLength($data, $field, $length, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (strlen($data[$field]) < $length) {
            return ['valid' => false, 'error' => $message ?? "{$field} কমপক্ষে {$length} অক্ষর হতে হবে"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateMaxLength')) {
    function validateMaxLength($data, $field, $length, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (strlen($data[$field]) > $length) {
            return ['valid' => false, 'error' => $message ?? "{$field} {$length} অক্ষরের চেয়ে বেশি হতে পারে না"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateNumeric')) {
    function validateNumeric($data, $field, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!is_numeric($data[$field])) {
            return ['valid' => false, 'error' => $message ?? "{$field} সংখ্যা হতে হবে"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateInteger')) {
    function validateInteger($data, $field, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!is_int($data[$field]) && !ctype_digit((string)$data[$field])) {
            return ['valid' => false, 'error' => $message ?? "{$field} পূর্ণ সংখ্যা হতে হবে"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateRegex')) {
    function validateRegex($data, $field, $pattern, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!preg_match($pattern, $data[$field])) {
            return ['valid' => false, 'error' => $message ?? "{$field} অবৈধ ফর্ম্যাট"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateIn')) {
    function validateIn($data, $field, $list, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!in_array($data[$field], $list, true)) {
            return ['valid' => false, 'error' => $message ?? "{$field} অবৈধ মান"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateUnique')) {
    function validateUnique($data, $field, $table, $mysqli, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM {$table} WHERE {$field} = ? LIMIT 1");
        
        if (!$stmt) {
            return ['valid' => false, 'error' => 'ডাটাবেস ত্রুটি'];
        }
        
        $stmt->bind_param("s", $data[$field]);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result['count'] > 0) {
            return ['valid' => false, 'error' => $message ?? "{$field} ইতিমধ্যে বিদ্যমান"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateMatch')) {
    function validateMatch($data, $field, $matchField, $message = null) {
        if (!isset($data[$field]) || !isset($data[$matchField])) {
            return ['valid' => true];
        }
        
        if ($data[$field] !== $data[$matchField]) {
            return ['valid' => false, 'error' => $message ?? "{$field} মেলে না"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateDate')) {
    function validateDate($data, $field, $format = 'Y-m-d', $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        $d = DateTime::createFromFormat($format, $data[$field]);
        
        if (!$d || $d->format($format) !== $data[$field]) {
            return ['valid' => false, 'error' => $message ?? "{$field} বৈধ তারিখ নয়"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validatePhone')) {
    function validatePhone($data, $field, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!preg_match('/^[+]?[0-9]{10,15}$/', $data[$field])) {
            return ['valid' => false, 'error' => $message ?? "{$field} বৈধ ফোন নম্বর নয়"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateURL')) {
    function validateURL($data, $field, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => true];
        }
        
        if (!filter_var($data[$field], FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'error' => $message ?? "{$field} বৈধ URL নয়"];
        }
        
        return ['valid' => true];
    }
}

if (!function_exists('validateFiscalYear')) {
    /**
     * Validate fiscal year format (YYYY-YYYY)
     * Example: 2024-2025, 2025-2026
     */
    function validateFiscalYear($data, $field, $message = null) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['valid' => false, 'error' => $message ?? "{$field} আবশ্যক"];
        }
        
        $fiscalYear = trim($data[$field]);
        
        // Format validation: YYYY-YYYY
        if (!preg_match('/^\d{4}-\d{4}$/', $fiscalYear)) {
            return ['valid' => false, 'error' => $message ?? "{$field} সঠিক ফরম্যাটে নয় (YYYY-YYYY)"];
        }
        
        // Extract start and end year
        list($startYear, $endYear) = explode('-', $fiscalYear);
        $startYear = (int)$startYear;
        $endYear = (int)$endYear;
        
        // Validate that end year is exactly start year + 1
        if ($endYear !== $startYear + 1) {
            return ['valid' => false, 'error' => $message ?? "{$field} ক্রমাগত বছর হতে হবে"];
        }
        
        // Check if fiscal year is reasonable (within ±2 years of current year)
        $currentYear = (int)date('Y');
        if ($startYear < $currentYear - 1 || $startYear > $currentYear + 2) {
            return ['valid' => false, 'error' => $message ?? "{$field} বৈধ নয়"];
        }
        
        return ['valid' => true];
    }
}

// ==================== BATCH VALIDATION ====================

if (!function_exists('validateBatch')) {
    function validateBatch($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            foreach ($fieldRules as $rule => $params) {
                $result = null;
                
                switch ($rule) {
                    case 'required':
                        $result = validateRequired($data, $field, $params);
                        break;
                    case 'email':
                        $result = validateEmail($data, $field, $params);
                        break;
                    case 'minLength':
                        $result = validateMinLength($data, $field, $params['length'] ?? 0, $params['message'] ?? null);
                        break;
                    case 'maxLength':
                        $result = validateMaxLength($data, $field, $params['length'] ?? 255, $params['message'] ?? null);
                        break;
                    case 'numeric':
                        $result = validateNumeric($data, $field, $params);
                        break;
                    case 'integer':
                        $result = validateInteger($data, $field, $params);
                        break;
                    case 'regex':
                        $result = validateRegex($data, $field, $params['pattern'] ?? '', $params['message'] ?? null);
                        break;
                    case 'in':
                        $result = validateIn($data, $field, $params['list'] ?? [], $params['message'] ?? null);
                        break;
                    case 'unique':
                        $result = validateUnique($data, $field, $params['table'] ?? '', $params['mysqli'] ?? null, $params['message'] ?? null);
                        break;
                    case 'match':
                        $result = validateMatch($data, $field, $params['field'] ?? '', $params['message'] ?? null);
                        break;
                    case 'date':
                        $result = validateDate($data, $field, $params['format'] ?? 'Y-m-d', $params['message'] ?? null);
                        break;
                    case 'phone':
                        $result = validatePhone($data, $field, $params);
                        break;
                    case 'url':
                        $result = validateURL($data, $field, $params);
                        break;
                }
                
                if ($result && !$result['valid']) {
                    $errors[$field] = $result['error'];
                    break;
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

// ==================== LEGACY CLASS COMPATIBILITY ====================

if (!class_exists('Validator')) {
    class Validator {
        private $errors = [];
        private $data = [];
        
        public function __construct($data = []) {
            $this->data = $data;
        }
        
        public function required($field, $message = null) {
            $result = validateRequired($this->data, $field, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function email($field, $message = null) {
            $result = validateEmail($this->data, $field, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function minLength($field, $length, $message = null) {
            $result = validateMinLength($this->data, $field, $length, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function maxLength($field, $length, $message = null) {
            $result = validateMaxLength($this->data, $field, $length, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function numeric($field, $message = null) {
            $result = validateNumeric($this->data, $field, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function integer($field, $message = null) {
            $result = validateInteger($this->data, $field, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function regex($field, $pattern, $message = null) {
            $result = validateRegex($this->data, $field, $pattern, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function in($field, $list, $message = null) {
            $result = validateIn($this->data, $field, $list, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function unique($field, $table, $mysqli, $message = null) {
            $result = validateUnique($this->data, $field, $table, $mysqli, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function match($field, $matchField, $message = null) {
            $result = validateMatch($this->data, $field, $matchField, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function date($field, $format = 'Y-m-d', $message = null) {
            $result = validateDate($this->data, $field, $format, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function phone($field, $message = null) {
            $result = validatePhone($this->data, $field, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function url($field, $message = null) {
            $result = validateURL($this->data, $field, $message);
            if (!$result['valid']) {
                $this->errors[$field] = $result['error'];
            }
            return $this;
        }
        
        public function getErrors() {
            return $this->errors;
        }
        
        public function isValid() {
            return empty($this->errors);
        }
        
        public function getErrorMessages() {
            return array_values($this->errors);
        }
        
        public function getError($field) {
            return $this->errors[$field] ?? null;
        }
    }
}

// Helper function for backward compatibility
if (!function_exists('validate')) {
    function validate($data = []) {
        return new Validator($data);
    }
}
