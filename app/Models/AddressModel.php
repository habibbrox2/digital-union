<?php
/**
 * classes/AddressModel.php
 * 
 * Address model - handles address database queries.
 */
class AddressModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Get address by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT id, type, village_en, village_bn, rbs_en, rbs_bn, holding_no, ward_no, 
                    district_en, district_bn, upazila_en, upazila_bn, union_en, union_bn, 
                    postoffice_en, postoffice_bn 
             FROM address WHERE id = ?"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $address = $result->fetch_assoc();
        $stmt->close();
        return $address ?: null;
    }
}
