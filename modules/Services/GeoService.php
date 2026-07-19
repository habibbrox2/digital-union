<?php
/**
 * modules/Services/GeoService.php
 * 
 * Service layer for geographic data management.
 * Handles curl fetching, XML/JSON parsing, geo CRUD, and post office lookups.
 * No inline logic in controllers.
 */

class GeoService
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    // ================================================================
    // FETCH & IMPORT GEO DATA FROM URL
    // ================================================================

    /**
     * Fetch geo data from XML/JSON URL, parse, and import into database
     * Replaces the inline curl + parse + insert logic in GeoController
     */
    public function fetchAndImportGeo(string $xmlUrl): array
    {
        // Fetch via cURL
        $ch = curl_init($xmlUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($ch);

        if (curl_errno($ch)) {
            $message = "cURL Error: " . curl_error($ch);
            curl_close($ch);
            return ['status' => 'error', 'message' => $message];
        }
        curl_close($ch);

        // Parse JSON or XML
        $data = json_decode($content, true);
        if ($data === null) {
            libxml_disable_entity_loader(true);
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOENT);
            if ($xml === false) {
                $errors = implode(', ', array_map(fn($e) => $e->message, libxml_get_errors()));
                libxml_clear_errors();
                return ['status' => 'error', 'message' => "Failed to parse XML: $errors"];
            }
            $data = json_decode(json_encode($xml), true);
        }

        return $this->importGeoData($data);
    }

    /**
     * Import parsed geo data into the database
     */
    private function importGeoData(array $data): array
    {
        $status = 'success';
        $messages = [];

        $geoObjects = $data['geoObject'] ?? [];
        if (!is_array($geoObjects)) {
            return ['status' => 'error', 'message' => 'Invalid or empty data structure.'];
        }

        foreach ($geoObjects as $geoObject) {
            $object = is_array($geoObject) ? $geoObject : (array)$geoObject;
            $id = (int)($object['id'] ?? 0);
            $name_en = $object['nameEn'] ?? '';
            $name_bn = $object['nameBn'] ?? '';
            $geo_level_id = (int)($object['geoLevelId'] ?? 0);
            $geo_code = (int)($object['geoCode'] ?? 0);
            $parent_geo_id = (int)($object['parentGeoId'] ?? 0);
            $rmo_code = $object['rmoCode'] ?? '';
            $ward_number = $object['wardNumber'] ?? '';
            $is_active_in_address = !empty($object['isActiveInAddress']) ? 1 : 0;
            $geo_order = $geo_level_id;
            $geo_type = $geo_level_id;

            if ($id <= 0) {
                $messages[] = "❌ Skipped invalid ID ($id).";
                continue;
            }

            // Check if exists
            $stmtCheck = $this->mysqli->prepare(
                "SELECT COUNT(*) FROM geo_location WHERE id=? AND name_en=? AND name_bn=? AND geo_code=? AND geo_order=? AND geo_type=?"
            );
            $stmtCheck->bind_param("issiii", $id, $name_en, $name_bn, $geo_code, $geo_order, $geo_type);
            $stmtCheck->execute();
            $stmtCheck->bind_result($count);
            $stmtCheck->fetch();
            $stmtCheck->close();

            if ($count > 0) {
                $messages[] = "⚠️ Skipped exists ID $id.";
                continue;
            }

            // Insert
            $stmtInsert = $this->mysqli->prepare(
                "INSERT INTO geo_location (id, name_en, name_bn, geo_level_id, geo_code, parent_geo_id, rmo_code, ward_number, is_active_in_address, geo_order, geo_type) VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmtInsert->bind_param(
                "issiiiiisii",
                $id, $name_en, $name_bn, $geo_level_id, $geo_code,
                $parent_geo_id, $rmo_code, $ward_number, $is_active_in_address,
                $geo_order, $geo_type
            );

            if ($stmtInsert->execute()) {
                $messages[] = "✅ Inserted ID $id: $name_en";
            } else {
                $messages[] = "❌ Error inserting ID $id";
                $status = 'error';
            }
            $stmtInsert->close();
        }

        return ['status' => $status, 'message' => implode("\n", $messages)];
    }

    // ================================================================
    // GEO DATA QUERIES
    // ================================================================

    /**
     * Get geo locations by order and parent
     */
    public function getGeoData(int $geoOrder, int $parentGeoId = 0): array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT id, name_en, name_bn, geo_order, geo_code, rmo_code FROM geo_location WHERE geo_order=? AND (parent_geo_id=? OR ?=0) ORDER BY name_en"
        );
        $stmt->bind_param('iii', $geoOrder, $parentGeoId, $parentGeoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $response = [];
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }
        $stmt->close();
        return $response;
    }

    /**
     * Get geo locations by type with child count and optional search
     */
    public function getGeoByType(int $geoOrder, int $parentGeoId = 0, string $searchTerm = ''): array
    {
        $nextGeoOrder = $geoOrder + 1;

        $sql = "SELECT g.id, g.name_en, g.name_bn, g.geo_order, g.geo_code, g.rmo_code,
                       (SELECT COUNT(*) FROM geo_location c 
                        WHERE c.parent_geo_id = g.id AND c.geo_order = ?) AS child_count
                FROM geo_location g
                WHERE g.geo_order = ?
                  AND (? = 0 OR g.parent_geo_id = ?)";

        $bindTypes = 'iiii';
        $bindParams = [$nextGeoOrder, $geoOrder, $parentGeoId, $parentGeoId];

        if ($searchTerm !== '') {
            $sql .= " AND g.name_en LIKE CONCAT('%', ?, '%')";
            $bindTypes .= 's';
            $bindParams[] = $searchTerm;
        }

        $sql .= " ORDER BY g.name_en";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Server error preparing geo data.'];
        }

        // Convert to references for bind_param
        $refs = [];
        foreach ($bindParams as $k => $v) {
            $refs[$k] = &$bindParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], array_merge([$bindTypes], $refs));

        if (!$stmt->execute()) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'Server error retrieving geo data.'];
        }

        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();

        return ['status' => 'success', 'data' => $data];
    }

    // ================================================================
    // UNION LOOKUPS
    // ================================================================

    /**
     * Get unions by district and upazila name
     */
    public function getUnionByDistrict(string $districtNameEn, string $upazilaNameEn): array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT union_id, union_name_en, union_name_bn, union_code FROM unions WHERE district_name_en=? AND upazila_name_en=? ORDER BY union_name_en ASC"
        );
        $stmt->bind_param("ss", $districtNameEn, $upazilaNameEn);
        $stmt->execute();
        $result = $stmt->get_result();
        $unions = [];
        while ($row = $result->fetch_assoc()) {
            $unions[] = $row;
        }
        $stmt->close();
        return $unions;
    }

    /**
     * Get unions by upazila ID
     */
    public function getUnionByUpazila(int $upazilaId): array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT union_id, union_name_en, union_name_bn, union_code FROM unions WHERE upazila_id=? AND is_active=1 ORDER BY union_name_en ASC"
        );
        $stmt->bind_param("i", $upazilaId);
        $stmt->execute();
        $result = $stmt->get_result();
        $unions = [];
        while ($row = $result->fetch_assoc()) {
            $unions[] = $row;
        }
        $stmt->close();
        return $unions;
    }

    // ================================================================
    // POST OFFICE LOOKUPS
    // ================================================================

    /**
     * Get post offices by union name or ID
     */
    public function getPostOfficesByUnion(string $unionName = '', ?int $unionId = null): array
    {
        // Resolve union name from ID if needed
        if ($unionName === '' && $unionId) {
            $stmt = $this->mysqli->prepare("SELECT union_name_en FROM unions WHERE union_id = ? LIMIT 1");
            $stmt->bind_param('i', $unionId);
            $stmt->execute();
            $stmt->bind_result($resolvedUnionName);
            if ($stmt->fetch() && !empty($resolvedUnionName)) {
                $unionName = $resolvedUnionName;
            }
            $stmt->close();
        }

        $unionName = trim($unionName);
        if ($unionName === '') {
            return ['status' => 'error', 'message' => 'union_name required'];
        }

        $stmt = $this->mysqli->prepare(
            "SELECT id, en_name, bn_name, post_code
             FROM post_offices
             WHERE LOWER(TRIM(union_name)) = LOWER(TRIM(?))
             ORDER BY en_name"
        );
        $stmt->bind_param('s', $unionName);
        $stmt->execute();
        $result = $stmt->get_result();

        $postOffices = [];
        while ($row = $result->fetch_assoc()) {
            $postOffices[] = $row;
        }
        $stmt->close();

        return ['status' => 'success', 'data' => $postOffices];
    }
}
