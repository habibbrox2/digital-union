<?php
/**
 * modules/Services/SanitizationService.php
 * 
 * Service layer for input sanitization and data cleaning.
 * Replaces sanitize_input() and Data() from config/functions.php.
 */

class SanitizationService
{
    /**
     * Sanitize input data — trim, convert date format, escape HTML
     * Matches the behavior of sanitize_input() from config/functions.php
     */
    public function sanitizeInput(mixed $data = null): mixed
    {
        if (!isset($data) || $data === null) {
            return null;
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeInput($value);
            }
            return $data;
        }
        $data = trim((string)$data);
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $data)) {
            $date = DateTime::createFromFormat('d-m-Y', $data);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Clean data array — replace empty values with empty string
     * Matches the behavior of Data() from config/functions.php
     */
    public function cleanData(array $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        foreach ($data as $key => $value) {
            if (empty($value)) {
                $data[$key] = '';
            }
        }
        return $data;
    }
}
