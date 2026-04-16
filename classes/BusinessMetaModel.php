<?php
class BusinessMetaModel {
    private $conn;

    public function __construct(mysqli $mysqli) {
        $this->conn = $mysqli;
    }

    /**
     * Insert new business meta record
     */
    public function insertBusinessMeta($application_id, $data) {
        $sql = "INSERT INTO business_meta 
            (application_id, business_name_en, business_name_bn, ownership_type_id, vat_id, tax_id, business_type_id,
            paid_up_capital, license_fee, vat_amount, occupation_tax, income_tax, signboard_tax,
            surcharge, total_fee, business_address_id, expiry_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return ['status' => false, 'message' => $this->conn->error];
    
        $expiry_date = $data['expiry_date'] ?? null;
    
        $stmt->bind_param(
            'sssisiddddddddis',
            $application_id,
            $data['business_name_en'],
            $data['business_name_bn'],
            $data['ownership_type_id'],
            $data['vat_id'],
            $data['tax_id'],
            $data['business_type_id'],
            $data['paid_up_capital'],
            $data['license_fee'],
            $data['vat_amount'],
            $data['occupation_tax'],
            $data['income_tax'],
            $data['signboard_tax'],
            $data['surcharge'],
            $data['total_fee'],
            $data['business_address_id'],
            $expiry_date
        );
    
        $exec = $stmt->execute();
        return $exec ? ['status' => true] : ['status' => false, 'message' => $stmt->error];
    }

    /**
     * Update an existing business meta record
     */
    public function updateBusinessMeta($application_id, $data) {
        $sql = "UPDATE business_meta SET
            business_name_en = ?, 
            business_name_bn = ?, 
            ownership_type_id = ?, 
            vat_id = ?, 
            tax_id = ?, 
            business_type_id = ?, 
            paid_up_capital = ?, 
            license_fee = ?, 
            vat_amount = ?, 
            occupation_tax = ?, 
            income_tax = ?, 
            signboard_tax = ?, 
            surcharge = ?, 
            total_fee = ?, 
            business_address_id = ?, 
            expiry_date = ?
            WHERE application_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return ['status' => false, 'message' => $this->conn->error];

        $expiry_date = $data['expiry_date'] ?? null;

        $stmt->bind_param(
            'sssisiddddddddiss',
            $data['business_name_en'],
            $data['business_name_bn'],
            $data['ownership_type_id'],
            $data['vat_id'],
            $data['tax_id'],
            $data['business_type_id'],
            $data['paid_up_capital'],
            $data['license_fee'],
            $data['vat_amount'],
            $data['occupation_tax'],
            $data['income_tax'],
            $data['signboard_tax'],
            $data['surcharge'],
            $data['total_fee'],
            $data['business_address_id'],
            $expiry_date,
            $application_id
        );

        $exec = $stmt->execute();
        return $exec ? ['status' => true] : ['status' => false, 'message' => $stmt->error];
    }

    /**
     * Fetch business meta by application ID
     */
    public function getBusinessMetaByApplicationId($application_id) {
        $stmt = $this->conn->prepare("SELECT * FROM business_meta WHERE application_id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("s", $application_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_assoc() : null;
    }
}
