<?php
/**
 * modules/Services/FeeService.php
 * 
 * Service layer for fee management.
 * Replaces getFee() from config/functions.php.
 */

class FeeService
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Get fee value by fee name
     */
    public function getFee(string $feeName): ?string
    {
        $stmt = $this->mysqli->prepare("SELECT fee_value FROM fee_manage WHERE fee_name = ?");
        $stmt->bind_param("s", $feeName);
        $stmt->execute();
        $stmt->bind_result($feeValue);
        $stmt->fetch();
        $stmt->close();

        return $feeValue ?: null;
    }
}
