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

    /**
     * Normalize a file path before storing in the database.
     *
     * Handles malformed paths such as:
     *   - "http://uploads/application/..."  → "/uploads/application/..."
     *   - "https://uploads/documents/..."  → "/uploads/documents/..."
     *   - "uploads/application/..."         → "/uploads/application/..."
     *
     * Correct paths (already starting with /) are returned as-is.
     * Empty/null values return an empty string.
     *
     * @param  string|null $path  Raw file path
     * @return string             Normalized path starting with '/' or empty string
     */
    public function normalizeFilePath(?string $path): string
    {
        $path = trim($path ?? '');

        if ($path === '') {
            return '';
        }

        // Strip full URL prefix (protocol + domain) if present
        // e.g. "http://lgdhaka.local/uploads/foo.jpg" → "/uploads/foo.jpg"
        // e.g. "http://uploads/foo.jpg"              → "/uploads/foo.jpg"
        if (preg_match('#^https?://#i', $path)) {
            // Extract path starting from /uploads/ if present, otherwise strip domain
            if (preg_match('#(/uploads/.+)$#i', $path, $m)) {
                $path = $m[1];
            } else {
                $path = preg_replace('#^https?://[^/]*#i', '', $path);
            }
        }

        // Ensure leading slash for relative paths
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }
}
