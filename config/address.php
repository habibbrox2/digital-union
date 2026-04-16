<?php
function sonod_address(
    string $address_type,
    string $village_en,
    string $village_bn,
    string $rbs_en,
    string $rbs_bn,
    string $holding_no,
    string $ward_no,
    string $district_en,
    string $district_bn,
    string $upazila_en,
    string $upazila_bn,
    string $union_en,
    string $union_bn,
    string $postoffice_en,
    string $postoffice_bn,
    ?int $address_id = null
)
 {
    global $mysqli;

    // Ensure valid address type
    if (!in_array($address_type, ['present', 'permanent', 'business'])) {
        echo "Invalid address type. It must be 'present', 'permanent', or 'business'.";
        return null;
    }


    // Convert address_id to int if numeric, otherwise null
    if (!empty($address_id) && is_numeric($address_id)) {
        $address_id = (int)$address_id;
    } else {
        $address_id = null;
    }

    if ($address_id) {
        // UPDATE query
        $sql = "UPDATE address SET 
                    type = ?, 
                    village_en = ?, village_bn = ?, 
                    rbs_en = ?, rbs_bn = ?, 
                    holding_no = ?, ward_no = ?, 
                    district_en = ?, district_bn = ?, 
                    upazila_en = ?, upazila_bn = ?, 
                    union_en = ?, union_bn = ?, 
                    postoffice_en = ?, postoffice_bn = ?
                WHERE id = ?";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("sssssssssssssssi", 
                $address_type, $village_en, $village_bn, 
                $rbs_en, $rbs_bn, $holding_no, $ward_no, 
                $district_en, $district_bn, 
                $upazila_en, $upazila_bn, 
                $union_en, $union_bn, 
                $postoffice_en, $postoffice_bn, 
                $address_id
            );
            if ($stmt->execute()) {
                $stmt->close();
                return $address_id;
            } else {
                echo "Error executing update query: " . $stmt->error;
                $stmt->close();
                return null;
            }
        } else {
            echo "Error preparing update statement: " . $mysqli->error;
            return null;
        }
    } else {
        // INSERT query
        $sql = "INSERT INTO address (
                    type, 
                    village_en, village_bn, 
                    rbs_en, rbs_bn, 
                    holding_no, ward_no, 
                    district_en, district_bn, 
                    upazila_en, upazila_bn, 
                    union_en, union_bn, 
                    postoffice_en, postoffice_bn
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("sssssssssssssss", 
                $address_type, $village_en, $village_bn, 
                $rbs_en, $rbs_bn, $holding_no, $ward_no, 
                $district_en, $district_bn, 
                $upazila_en, $upazila_bn, 
                $union_en, $union_bn, 
                $postoffice_en, $postoffice_bn
            );
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();
                return $new_id;
            } else {
                echo "Error executing insert query: " . $stmt->error;
                $stmt->close();
                return null;
            }
        } else {
            echo "Error preparing insert statement: " . $mysqli->error;
            return null;
        }
    }
}
?>
