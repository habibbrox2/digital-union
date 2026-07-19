<?php
/**
 * modules/Services/NumberGeneratorService.php
 * 
 * Service layer for unique number generation (tracking numbers, sonod numbers,
 * applicant IDs, sequential numbers).
 * Replaces getSequentialNumber(), generateTrackingNumber(), generateApplicantId(),
 * generateSonodNumber(), extract_birth_year() from config/functions.php.
 */

class NumberGeneratorService
{
    private mysqli $mysqli;
    private UnionService $unionService;

    public function __construct(mysqli $mysqli, ?UnionService $unionService = null)
    {
        $this->mysqli = $mysqli;
        $this->unionService = $unionService ?? new UnionService($mysqli);
    }

    /**
     * Extract birth year from birth_date string
     */
    public function extractBirthYear(?string $birthDate): ?string
    {
        if (empty($birthDate)) return null;
        if (preg_match('/^(\d{4})[-\/]?\d{0,2}[-\/]?\d{0,2}$/', $birthDate, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Get validated 7-digit union code (delegates to UnionService)
     */
    public function getUnionCode(string $unionCode): string
    {
        return $this->unionService->getUnionCode($unionCode);
    }

    /**
     * Fetch union row by union_code (delegates to UnionService)
     */
    public function getUnionByCode(string $unionCode): ?array
    {
        return $this->unionService->getUnionByCode($unionCode);
    }

    /**
     * Generate next unique sequential number (atomic & transaction-safe)
     */
    public function getSequentialNumber(
        string $tablename,
        string $column,
        string $prefix,
        int $seqLength = 6,
        ?string $unionCode = null,
        int $maxAttempts = 1000000
    ): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tablename)) {
            throw new InvalidArgumentException("Invalid table name.");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException("Invalid column name.");
        }

        $unionId = null;
        if (!empty($unionCode)) {
            $unionData = $this->getUnionByCode($unionCode);
            if ($unionData && isset($unionData['union_id'])) {
                $unionId = (int)$unionData['union_id'];
            }
        }

        $this->mysqli->begin_transaction();

        try {
            $sql = "SELECT $column FROM `$tablename` WHERE $column LIKE CONCAT(?, '%')";
            if ($unionId) {
                $sql .= " AND union_id = ?";
            }
            $sql .= " ORDER BY application_id DESC LIMIT 1 FOR UPDATE";

            $stmt = $this->mysqli->prepare($sql);
            if ($unionId) {
                $stmt->bind_param('si', $prefix, $unionId);
            } else {
                $stmt->bind_param('s', $prefix);
            }
            $stmt->execute();
            $stmt->bind_result($lastValue);
            $stmt->fetch();
            $stmt->close();

            $nextSeq = ($lastValue)
                ? (int)substr($lastValue, strlen($prefix)) + 1
                : 1;

            $checkSql = "SELECT COUNT(*) FROM `$tablename` WHERE $column = ?";
            $checkStmt = $this->mysqli->prepare($checkSql);

            $attempt = 0;
            while ($attempt < $maxAttempts) {
                $attempt++;
                $candidateSeq = str_pad($nextSeq, $seqLength, '0', STR_PAD_LEFT);
                $candidateFull = $prefix . $candidateSeq;

                $checkStmt->bind_param('s', $candidateFull);
                $checkStmt->execute();
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->reset();

                if ($count == 0) {
                    $checkStmt->close();
                    $this->mysqli->commit();
                    return $candidateSeq;
                }
                $nextSeq++;
            }

            $checkStmt->close();
            $this->mysqli->rollback();
            throw new RuntimeException("Unique sequential number not found after $maxAttempts attempts.");

        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Generate Tracking Number
     * Format: YYMMDD (6) + union_code (7) + seq (4) = 17 digits
     */
    public function generateTrackingNumber(string $unionCode): string
    {
        $unionCode = $this->getUnionCode($unionCode);
        $prefix = date('ymd') . $unionCode;
        $seqLength = 4;
        $seq = $this->getSequentialNumber('applications', 'application_id', $prefix, $seqLength, $unionCode);
        return $prefix . $seq;
    }

    /**
     * Generate Applicant ID
     */
    public function generateApplicantId(
        ?string $nid = null,
        ?string $birthId = null,
        ?string $passportNo = null,
        ?string $unionCode = null,
        ?string $birthDate = null
    ): string {
        // Check if applicant already exists
        $sql = "SELECT applicant_id FROM applications 
                WHERE (nid = ? AND nid != '') 
                   OR (birth_id = ? AND birth_id != '') 
                   OR (passport_no = ? AND passport_no != '') 
                LIMIT 1";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("sss", $nid, $birthId, $passportNo);
        $stmt->execute();
        $stmt->bind_result($existing);
        $stmt->fetch();
        $stmt->close();

        if (!empty($existing)) return $existing;

        $birthYear = $this->extractBirthYear($birthDate);
        $year = !empty($birthYear) ? $birthYear : date('Y');

        $unionCode = $this->getUnionCode($unionCode);
        $prefix = $year . $unionCode;

        $seq = $this->getSequentialNumber('applications', 'applicant_id', $prefix, 6, $unionCode);
        return substr($prefix . $seq, 0, 17);
    }

    /**
     * Generate Sonod Number
     * Format: YY + union_code(7) + seq(8) = 17 digits
     */
    public function generateSonodNumber(string $tablename, string $unionCode): string
    {
        $unionCode = $this->getUnionCode($unionCode);
        $prefix = date('y') . $unionCode;
        $seq = $this->getSequentialNumber($tablename, 'sonod_number', $prefix, 8, $unionCode);
        return substr($prefix . $seq, 0, 17);
    }
}
