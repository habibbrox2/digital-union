<?php
/**
 * Service layer for unique number generation (tracking numbers, sonod numbers,
 * applicant IDs and sequential numbers).
 */

class NumberGeneratorService
{
    /**
     * Shared numeric baseline. It is formatted to each generator's existing
     * sequence width (4, 6 or 8 digits) without changing that width.
     */
    private const INITIAL_SEQUENCE = 102030;

    private mysqli $mysqli;
    private UnionService $unionService;

    public function __construct(mysqli $mysqli, ?UnionService $unionService = null)
    {
        $this->mysqli = $mysqli;
        $this->unionService = $unionService ?? new UnionService($mysqli);
    }

    /**
     * Extract birth year from a date-like string.
     */
    public function extractBirthYear(?string $birthDate): ?string
    {
        if (empty($birthDate)) {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/]?\d{0,2}[-\/]?\d{0,2}$/', $birthDate, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get validated 7-digit union code (delegates to UnionService).
     */
    public function getUnionCode(string $unionCode): string
    {
        return $this->unionService->getUnionCode($unionCode);
    }

    /**
     * Fetch union row by union_code (delegates to UnionService).
     */
    public function getUnionByCode(string $unionCode): ?array
    {
        return $this->unionService->getUnionByCode($unionCode);
    }

    /**
     * Generate the next available sequence suffix.
     *
     * The first sequence is based on 102030 and is formatted to the existing
     * sequence width:
     * - 4 digits: 2030
     * - 6 digits: 102030
     * - 8 digits: 00102030
     *
     * Existing values are never rewritten. When values already exist for the
     * prefix, generation continues after the highest existing suffix.
     *
     * Note: this method selects a candidate; the caller must persist it under
     * a UNIQUE constraint on the generated-number column to guarantee
     * uniqueness across concurrent requests.
     */
    public function getSequentialNumber(
        string $tablename,
        string $column,
        string $prefix,
        int $seqLength = 6,
        ?string $unionCode = null,
        int $maxAttempts = 1025040
    ): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tablename)) {
            throw new InvalidArgumentException('Invalid table name.');
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid column name.');
        }

        if ($seqLength < 1 || $seqLength > 8) {
            throw new InvalidArgumentException('Sequence length must be between 1 and 8 digits.');
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be greater than zero.');
        }

        if ($prefix === '') {
            throw new InvalidArgumentException('Sequence prefix cannot be empty.');
        }

        $unionId = null;
        if ($unionCode !== null && $unionCode !== '') {
            $unionData = $this->getUnionByCode($unionCode);
            if (!$unionData || !isset($unionData['union_id'])) {
                throw new RuntimeException('Unable to resolve union code for sequential number generation.');
            }

            $unionId = (int) $unionData['union_id'];
        }

        // Preserve the configured width while applying the common 102030 baseline.
        $initialSequence = (int) substr(
            str_pad((string) self::INITIAL_SEQUENCE, $seqLength, '0', STR_PAD_LEFT),
            -$seqLength
        );
        $maximumSequence = (10 ** $seqLength) - 1;

        $transactionStarted = false;

        try {
            $this->mysqli->begin_transaction();
            $transactionStarted = true;

            $sql = "SELECT `$column`
                    FROM `$tablename`
                    WHERE `$column` LIKE CONCAT(?, '%')";

            if ($unionId !== null) {
                $sql .= ' AND union_id = ?';
            }

            // The generated column, not application_id, determines sequence order.
            $sql .= " ORDER BY `$column` DESC LIMIT 1 FOR UPDATE";

            $stmt = $this->mysqli->prepare($sql);
            if ($unionId !== null) {
                $stmt->bind_param('si', $prefix, $unionId);
            } else {
                $stmt->bind_param('s', $prefix);
            }

            $stmt->execute();
            $stmt->bind_result($lastValue);
            $hasLastValue = $stmt->fetch();
            $stmt->close();

            $nextSequence = $initialSequence;
            if ($hasLastValue && $lastValue !== null) {
                $lastSuffix = substr((string) $lastValue, strlen($prefix));

                if ($lastSuffix !== '' && !ctype_digit($lastSuffix)) {
                    throw new RuntimeException('The latest stored sequence suffix is not numeric.');
                }

                if ($lastSuffix !== '') {
                    $nextSequence = max($initialSequence, ((int) $lastSuffix) + 1);
                }
            }

            $checkSql = "SELECT COUNT(*)
                         FROM `$tablename`
                         WHERE `$column` = ?";
            $checkStmt = $this->mysqli->prepare($checkSql);

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                if ($nextSequence > $maximumSequence) {
                    throw new RuntimeException(
                        "Sequential number capacity exhausted for {$seqLength}-digit sequence."
                    );
                }

                $candidateSequence = str_pad(
                    (string) $nextSequence,
                    $seqLength,
                    '0',
                    STR_PAD_LEFT
                );
                $candidateFull = $prefix . $candidateSequence;

                $checkStmt->bind_param('s', $candidateFull);
                $checkStmt->execute();
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->free_result();

                if ((int) $count === 0) {
                    $checkStmt->close();
                    $this->mysqli->commit();
                    $transactionStarted = false;

                    return $candidateSequence;
                }

                $nextSequence++;
            }

            $checkStmt->close();

            throw new RuntimeException(
                "Unique sequential number not found after {$maxAttempts} attempts."
            );
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->mysqli->rollback();
            }

            throw $e;
        }
    }

    /**
     * Generate Tracking Number.
     * Format: YYMMDD (6) + union_code (7) + sequence (4) = 17 digits.
     */
    public function generateTrackingNumber(string $unionCode): string
    {
        $unionCode = $this->getUnionCode($unionCode);
        $prefix = date('ymd') . $unionCode;
        $sequence = $this->getSequentialNumber(
            'applications',
            'application_id',
            $prefix,
            4,
            $unionCode
        );

        return $prefix . $sequence;
    }

    /**
     * Generate Applicant ID.
     * Format: YYYY (4) + union_code (7) + sequence (6) = 17 digits.
     */
    public function generateApplicantId(
        ?string $nid = null,
        ?string $birthId = null,
        ?string $passportNo = null,
        ?string $unionCode = null,
        ?string $birthDate = null
    ): string {
        // Reuse an existing applicant ID when any supplied identity matches.
        $sql = "SELECT applicant_id
                FROM applications
                WHERE (nid = ? AND nid != '')
                   OR (birth_id = ? AND birth_id != '')
                   OR (passport_no = ? AND passport_no != '')
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('sss', $nid, $birthId, $passportNo);
        $stmt->execute();
        $stmt->bind_result($existing);
        $stmt->fetch();
        $stmt->close();

        if (!empty($existing)) {
            return $existing;
        }

        $birthYear = $this->extractBirthYear($birthDate);
        $year = $birthYear ?? date('Y');

        if ($unionCode === null || $unionCode === '') {
            throw new InvalidArgumentException('Union code is required to generate an applicant ID.');
        }

        $unionCode = $this->getUnionCode($unionCode);
        $prefix = $year . $unionCode;

        $sequence = $this->getSequentialNumber(
            'applications',
            'applicant_id',
            $prefix,
            6,
            $unionCode
        );

        return $prefix . $sequence;
    }

    /**
     * Generate Sonod Number.
     * Format: YY (2) + union_code (7) + sequence (8) = 17 digits.
     */
    public function generateSonodNumber(string $tablename, string $unionCode): string
    {
        $unionCode = $this->getUnionCode($unionCode);
        $prefix = date('y') . $unionCode;

        $sequence = $this->getSequentialNumber(
            $tablename,
            'sonod_number',
            $prefix,
            8,
            $unionCode
        );

        return $prefix . $sequence;
    }
}
