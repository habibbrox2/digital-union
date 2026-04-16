<?php
class ApplicationManager {
    private $conn;

    public function __construct(mysqli $mysqli)
    {
        $this->conn = $mysqli;
    }

    private static function getTypeUrlExpressionSQL(): string {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(tt.name_bl, ' ', '-'), '/', '-'), '_', '-'), '.', ''))";
    }

    protected static function getCertificateTypeFields(): string
    {
        $typeUrlExpr = self::getTypeUrlExpressionSQL();

        return "
            tt.name_bn AS certificate_type_bn,
            tt.name_en AS certificate_type_en,
            tt.name_bl AS certificate_type_bl,
            CASE
                WHEN tt.is_certificate_type = 1 THEN $typeUrlExpr  
                ELSE NULL 
            END AS type_url
        ";
    }
    /**
         * Returns SELECT fields for applications with address and translation info.
         *
         * @return string
         */
    protected static function getSelectFields()
    {
            return "
                a.*,

                -- Present Address
                pa.village_en AS present_village_en, pa.village_bn AS present_village_bn, 
                pa.district_en AS present_district_en, pa.district_bn AS present_district_bn, 
                pa.upazila_en AS present_upazila_en, pa.upazila_bn AS present_upazila_bn, 
                pa.union_en AS present_union_en, pa.union_bn AS present_union_bn, 
                pa.ward_no AS present_ward_no, pa.holding_no AS present_holding_no,
                pa.postoffice_en AS present_postoffice_en, pa.postoffice_bn AS present_postoffice_bn,
                pa.rbs_en AS present_rbs_en, pa.rbs_bn AS present_rbs_bn,

                -- Permanent Address
                per.village_en AS permanent_village_en, per.village_bn AS permanent_village_bn, 
                per.district_en AS permanent_district_en, per.district_bn AS permanent_district_bn, 
                per.upazila_en AS permanent_upazila_en, per.upazila_bn AS permanent_upazila_bn, 
                per.union_en AS permanent_union_en, per.union_bn AS permanent_union_bn, 
                per.ward_no AS permanent_ward_no, per.holding_no AS permanent_holding_no,
                per.postoffice_en AS permanent_postoffice_en, per.postoffice_bn AS permanent_postoffice_bn,
                per.rbs_en AS permanent_rbs_en, per.rbs_bn AS permanent_rbs_bn,

                -- Business Meta
                bm.business_name_bn AS business_name_bn,
                bm.business_name_en AS business_name_en,

                -- Documents
                a.documents AS existing_documents,
                a.extra_data AS extra,

                -- Certificate Info
                " . static::getCertificateTypeFields() . "
            ";
        }

     /**
         * Returns reusable JOIN clauses for address and translation info.
         *
         * @return string
         */
    protected static function getJoinStatements()
    {
            return "
                LEFT JOIN address pa ON a.present_address_id = pa.id AND pa.type = 'present'
                LEFT JOIN address per ON a.permanent_address_id = per.id AND per.type = 'permanent'
                LEFT JOIN term_translations tt  
                ON (a.certificate_type = tt.slug OR a.certificate_type IS NULL OR a.certificate_type = '')  
                AND tt.is_certificate_type = 1
                LEFT JOIN business_meta bm ON a.application_id = bm.application_id
            ";
        }

    /**
         * Get a distinct list of certificate types used in applications,
         * with translations and a slug for URL.
         *
         * @return array
         */
    public function CertificateTypeLists($union_id = null)
    {
            $types = [];
    
            $whereClause = "WHERE tt.is_certificate_type = 1";
    
            if ($union_id) {
                $unionFilter = " AND a.union_id = " . (int)$union_id;
            } else {
                $unionFilter = "";
            }
    
            $sql = "SELECT 
                        tt.slug AS certificate_type,
                        " . static::getCertificateTypeFields() . ",
                        (
                            SELECT COUNT(*) 
                            FROM applications a2 
                            WHERE a2.certificate_type = tt.slug
                            $unionFilter
                        ) AS total_applications
                    FROM term_translations tt
                    LEFT JOIN applications a ON a.certificate_type = tt.slug $unionFilter
                    $whereClause
                    GROUP BY tt.slug
                    ORDER BY tt.name_bn ASC";
    
            if ($result = $this->conn->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    $types[] = $row;
                }
                $result->free();
            }
    
            return $types;
        }
        
    // ======================
    // APPLICATIONS CRUD
    // ======================
    
    /**
         * Fetch all applications with pagination and search
         */

    public function fetchAllApplications($union_id, $page = 1, $search = '', $records_per_page = 10, $sort_by = 'apply_date', $sort_order = 'DESC', $certificate_type = null)
    {
                $offset = ($page - 1) * $records_per_page;
                $search_pattern = "%$search%";
                $allowed_sort = [
                    'application_id', 'name_bn', 'name_en', 'father_name_bn', 'father_name_en', 'mother_name_bn', 'mother_name_en',
                    'spouse_name_bn', 'spouse_name_en', 'applicant_name', 'nid', 'birth_id', 'passport_no', 'sonod_number', 'apply_date', 'status'
                ];
                $sort_by = in_array($sort_by, $allowed_sort) ? $sort_by : 'application_id';
                $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
                $where = [];
                $params = [];
                $types = '';
                // If search is numeric, match any of the key numbers with LIKE (partial match)
                if (is_numeric($search) && $search !== '') {
                    $where[] = "(a.application_id LIKE ? OR a.nid LIKE ? OR a.birth_id LIKE ? OR a.passport_no LIKE ?)";
                    for ($i = 0; $i < 4; $i++) {
                        $params[] = $search_pattern;
                        $types .= 's';
                    }
                } else {
                    $where[] = "(a.name_bn LIKE ? OR a.name_en LIKE ? OR a.father_name_bn LIKE ? OR a.father_name_en LIKE ? OR a.mother_name_bn LIKE ? OR a.mother_name_en LIKE ? OR a.spouse_name_bn LIKE ? OR a.spouse_name_en LIKE ? OR a.applicant_name LIKE ? OR a.nid LIKE ? OR a.birth_id LIKE ? OR a.passport_no LIKE ? OR a.sonod_number LIKE ?)";
                    for ($i = 0; $i < 13; $i++) {
                        $params[] = $search_pattern;
                        $types .= 's';
                    }
                }
                if ($union_id) {
                    $where[] = "a.union_id = ?";
                    $params[] = $union_id;
                    $types .= 'i';
                }
                $slugExpr = self::getTypeUrlExpressionSQL();

                if ($certificate_type) {
                    // একই প্যারামিটার দু’বার bind হবে (slug ও type_url—দুটিতেই পরীক্ষা)
                    $where[]  = "(a.certificate_type = ? OR $slugExpr = ?)";
                    $params[] = $certificate_type;   // slug পরীক্ষা
                    $params[] = $certificate_type;   // url‑slug পরীক্ষা
                    $types   .= 'ss';
                }


                $where_sql = implode(' AND ', $where);
                $selectFields = self::getSelectFields();
                $joins = self::getJoinStatements();

                $sql = "SELECT $selectFields
                        FROM applications a
                        $joins
                        WHERE $where_sql
                        ORDER BY a.$sort_by $sort_order
                        LIMIT ?, ?";
                $params[] = $offset;
                $params[] = $records_per_page;
                $types .= 'ii';
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    return ['status' => 'error', 'message' => 'Prepare failed: ' . $this->conn->error];
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $data = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                // Count query
                $count_sql = "SELECT COUNT(*) AS total FROM applications a " . self::getJoinStatements() . " WHERE $where_sql";
                $count_stmt = $this->conn->prepare($count_sql);
                if (!$count_stmt) {
                    return ['status' => 'error', 'message' => 'Prepare failed: ' . $this->conn->error];
                }
                $count_types = substr($types, 0, strlen($types) - 2); // Remove ii for offset/limit
                $count_stmt->bind_param($count_types, ...array_slice($params, 0, count($params) - 2));
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $count_stmt->close();
                $total_pages = ceil($total / $records_per_page);
                return [
                    'status' => 'success',
                    'data' => $data,
                    'total_pages' => $total_pages,
                    'total_records' => $total
                ];
            }

    /**
         * Example usage inside a method like getApplicationById
         *
         * @param int $id
         * @return array|null
         */
    public function getApplicationById($id)
    {
            $selectFields = self::getSelectFields();
            $joins = self::getJoinStatements();
    
            $stmt = $this->conn->prepare("
                SELECT $selectFields
                FROM applications a
                $joins
                WHERE a.id = ?
                LIMIT 1
            ");
    
            $stmt->bind_param('i', $id);
            $stmt->execute();
    
            $result = $stmt->get_result();
            return $result ? $result->fetch_assoc() : null;
        }

    /**
     * Get application by application_id with optional union_id filter
     * This method allows fetching application details by its ID,
     * optionally filtering by union_id.
     * @param string $application_id The unique identifier for the application.
     * @param int|null $union_id The union ID to filter the application, if applicable.
     * @return array|null Returns the application details as an associative array, or null if not found.
     * */
    public function getApplicationByApplicationId($application_id, $union_id = null)
    {
        $selectFields = self::getSelectFields();
        $joins = self::getJoinStatements();

        $sql = "SELECT $selectFields
                FROM applications a
                $joins
                WHERE a.application_id = ?";
        
        $params = [$application_id];
        $types = 's';

        // Apply union filter only if union_id is not null
        if ($union_id !== null) {
            $sql .= " AND a.union_id = ?";
            $params[] = $union_id;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        // Bind parameters dynamically
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->fetch_assoc();
    }
    /**
     * Get application by applicant_id with status = 'Approved'
     * This method fetches application details by applicant_id,
     * ensuring that only approved applications are returned.
     *
     * @param string $applicant_id The unique identifier for the applicant.
     * @param int|null $union_id   Optional union_id to further filter results.
     * @return array|null Returns the application details as an associative array, or null if not found.
     */
    public function getApprovedApplicationByApplicantId($applicant_id, $union_id = null)
    {
        $selectFields = self::getSelectFields();
        $joins = self::getJoinStatements();

        $sql = "SELECT $selectFields
                FROM applications a
                $joins
                WHERE a.applicant_id = ?";
             //   --   AND a.status = 'Approved'";

        $params = [$applicant_id];
        $types = 's';

        // Optional union filter
        if ($union_id !== null) {
            $sql .= " AND a.union_id = ?";
            $params[] = $union_id;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        // Bind parameters dynamically
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->fetch_assoc();
    }

    public function createApplication($data)
    {
        $stmt = $this->conn->prepare("INSERT INTO applications (
            application_id, applicant_id, certificate_type, union_id, sonod_number, name_en, name_bn, nid, birth_id, passport_no, birth_date,
            gender, father_name_en, father_name_bn, mother_name_en, mother_name_bn, occupation, resident, educational_qualification,
            religion, marital_status, spouse_name_en, spouse_name_bn, applicant_name, applicant_phone, applicant_photo, documents,
            present_address_id, permanent_address_id, extra_data
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            return [
                'status' => 'error',
                'message' => 'Statement prepare failed: ' . $this->conn->error
            ];
        }

        $stmt->bind_param(
            "sisssssssssssssssssssssssssiis",
            $data['application_id'], $data['applicant_id'], $data['certificate_type'], $data['union_id'],
            $data['sonod_number'], $data['name_en'], $data['name_bn'], $data['nid'], $data['birth_id'],
            $data['passport_no'], $data['birth_date'], $data['gender'], $data['father_name_en'], $data['father_name_bn'],
            $data['mother_name_en'], $data['mother_name_bn'], $data['occupation'], $data['resident'],
            $data['educational_qualification'], $data['religion'], $data['marital_status'], $data['spouse_name_en'],
            $data['spouse_name_bn'], $data['applicant_name'], $data['applicant_phone'], $data['applicant_photo'],
            $data['documents'], $data['present_address_id'], $data['permanent_address_id'],
            $data['extra_data']
        );


        if (!$stmt->execute()) {
            return [
                'status' => 'error',
                'message' => 'Database execution failed: ' . $stmt->error
            ];
        }

        return [
            'status' => 'success',
            'application_id' => $data['application_id'] // OR: $this->conn->insert_id
        ];
    }




    public function addMember($data)
    {
        $stmt = $this->conn->prepare("INSERT INTO application_members (
            application_id, certificate_type, name_en, name_bn, relation_en, relation_bn, birth_date,
            nid, gender, occupation, mobile, serial_no, address, marital_status, is_dead
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            return [
                'status' => 'error',
                'message' => 'Statement prepare failed: ' . $this->conn->error
            ];
        }

        $stmt->bind_param(
            "sssssssssssisss",
            $data['application_id'], $data['certificate_type'], $data['name_en'], $data['name_bn'],
            $data['relation_en'], $data['relation_bn'], $data['birth_date'], $data['nid'], $data['gender'],
            $data['occupation'], $data['mobile'], $data['serial_no'], $data['address'], $data['marital_status'],
            $data['is_dead']
        );

        if (!$stmt->execute()) {
            return [
                'status' => 'error',
                'message' => 'Database execution failed: ' . $stmt->error
            ];
        }

        return [
            'status' => 'success',
            'member_id' => $this->conn->insert_id
        ];
    }

    public function insertBusinessMeta($application_id, $data) {
        $sql = "INSERT INTO business_meta 
            (application_id, business_name_en, business_name_bn, ownership_type_id, vat_id, tax_id, business_type_id,
            paid_up_capital, license_fee, vat_amount, occupation_tax, income_tax, signboard_tax,
            surcharge, total_fee, business_address_id, expiry_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return ['status' => false, 'message' => $this->conn->error];
    
        $expiry_date = $data['expiry_date'] ?? null; // optional
    
        $stmt->bind_param(
            'ssssssiddddddddis',
            $application_id,                 // s
            $data['business_name_en'],        // s
            $data['business_name_bn'],        // s
            $data['ownership_type_id'],       // i
            $data['vat_id'],                  // s
            $data['tax_id'],                  // s
            $data['business_type_id'],        // i
            $data['paid_up_capital'],         // d
            $data['license_fee'],             // d
            $data['vat_amount'],              // d
            $data['occupation_tax'],          // d
            $data['income_tax'],              // d
            $data['signboard_tax'],           // d
            $data['surcharge'],               // d
            $data['total_fee'],               // d
            $data['business_address_id'],     // i
            $expiry_date                      // s (nullable)
        );

    
        $exec = $stmt->execute();
    
        return $exec 
            ? ['status' => true] 
            : ['status' => false, 'message' => $stmt->error];
    }
    
    public function updateBusinessMeta($application_id, $data) {
        $sql = "UPDATE business_meta 
                SET 
                    license_fee = ?, 
                    vat_amount = ?, 
                    occupation_tax = ?, 
                    income_tax = ?, 
                    signboard_tax = ?, 
                    surcharge = ?, 
                    total_fee = ?, 
                    fiscal_year = ?, 
                    ownership_type_id = ?, 
                    business_type_id = ?,
                    expiry_date = ?
                WHERE application_id = ?";
    
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['status' => false, 'message' => $this->conn->error];
        }
    
        $stmt->bind_param(
            'dddddddsiisi',
            $data['license_fee'],
            $data['vat_amount'],
            $data['occupation_tax'],
            $data['income_tax'],
            $data['signboard_tax'],
            $data['surcharge'],
            $data['total_fee'],
            $data['fiscal_year'],
            $data['ownership_type_id'],
            $data['business_type_id'],
            $data['expiry_date'],
            $application_id
        );

    
        return $stmt->execute()
            ? ['status' => true]
            : ['status' => false, 'message' => $stmt->error];
    }

    public function getBusinessMetaByApplicationId($application_id)
    {
        $sql = "SELECT 
            bm.*, 
            ot.ownership_name_bn AS ownership_name_bn,
            ot.ownership_name_en AS ownership_name_en,
            bt.business_name_bn AS business_type_bn,
            bt.business_name_en AS business_type_en,
            a.village_bn AS business_village_bn,
            a.village_en AS business_village_en,
            a.rbs_bn AS business_rbs_bn,
            a.rbs_en AS business_rbs_en,
            a.holding_no,
            a.ward_no AS business_ward_no,
            a.district_bn AS business_district_bn,
            a.district_en AS business_district_en,
            a.upazila_bn AS business_upazila_bn,
            a.upazila_en AS business_upazila_en,
            a.union_bn AS business_union_bn,
            a.union_en AS business_union_en,
            a.postoffice_bn AS business_postoffice_bn,
            a.postoffice_en AS business_postoffice_en
        FROM business_meta bm
        LEFT JOIN ownership_type ot ON bm.ownership_type_id = ot.id
        LEFT JOIN business_type bt ON bm.business_type_id = bt.id
        LEFT JOIN address a ON bm.business_address_id = a.id AND a.type = 'business'
        WHERE bm.application_id = ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            // Prepare failed
            throw new Exception("Prepare failed: " . $this->conn->error);
        }

        $stmt->bind_param("s", $application_id);
        $stmt->execute();

        $result = $stmt->get_result();
        if (!$result) {
            throw new Exception("Getting result failed: " . $stmt->error);
        }

        $data = $result->fetch_assoc();

        $stmt->close();

        return $data;
    }

    public function getMembersByApplication($application_id)
    {
        global $mysqli;
        $stmt = $mysqli->prepare(
            "SELECT * FROM application_members WHERE application_id = ? ORDER BY serial_no ASC"
        );
        $stmt->bind_param("s", $application_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }


    public function deleteMembersByApplication($application_id) {
        global $mysqli;
        $stmt = $mysqli->prepare("DELETE FROM application_members WHERE application_id = ?");
        if (!$stmt) {
            return false; // preparation failed
        }

        $stmt->bind_param("s", $application_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result ? true : false;
    }

    public function deleteMember($member_id)
    {
        global $mysqli;
        $stmt = $mysqli->prepare("DELETE FROM application_members WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        return $stmt->execute();
    }

    public function updateMember($member_id, $data)
    {
    }


    public function getApplication($application_id, $union_id = null)
    {
        if ($union_id === null) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM applications WHERE application_id = ?"
            );
            $stmt->bind_param("s", $application_id);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT * FROM applications WHERE application_id = ? AND union_id = ?"
            );
            $stmt->bind_param("si", $application_id, $union_id);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }


    public function updateApplicationFixed($application_id, $data, $union_id)
    {
        // ---------------- Main fields ----------------
        $name_en    = $data['name_en'] ?? '';
        $name_bn    = $data['name_bn'] ?? '';
        $nid        = $data['nid'] ?? '';
        $birth_id   = $data['birth_id'] ?? '';
        $passport_no= $data['passport_no'] ?? '';
        $birth_date = $data['birth_date'] ?? '';
        $gender     = $data['gender'] ?? '';
        $father_name_en = $data['father_name_en'] ?? '';
        $father_name_bn = $data['father_name_bn'] ?? '';
        $mother_name_en = $data['mother_name_en'] ?? '';
        $mother_name_bn = $data['mother_name_bn'] ?? '';
        $occupation = $data['occupation'] ?? '';
        $resident   = $data['resident'] ?? 'permanent';
        $educational_qualification = $data['educational_qualification'] ?? '';
        $religion   = $data['religion'] ?? '';
        $marital_status = $data['marital_status'] ?? 'Single';
        $spouse_name_en = $data['spouse_name_en'] ?? '';
        $spouse_name_bn = $data['spouse_name_bn'] ?? '';
        $applicant_name = $data['applicant_name'] ?? '';
        $applicant_phone = $data['applicant_phone'] ?? '';
        $applicant_photo = $data['applicant_photo'] ?? '';
        $documents = isset($data['documents']) ? json_encode($data['documents'], JSON_UNESCAPED_UNICODE) : '[]';

        // ---------------- Extra Data Handling ----------------
        $extra_data = $data['extra_data'] ?? '{}';

        if (is_string($extra_data)) {
            $extra_data = trim($extra_data);
            if ($extra_data === '') {
                $extra_data = '{}';
            } else {
                // Try to decode and re-encode to ensure valid JSON
                $decoded = json_decode($extra_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $extra_data = '{}'; // invalid JSON fallback
                } else {
                    $extra_data = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
        } elseif (is_array($extra_data)) {
            $extra_data = json_encode($extra_data, JSON_UNESCAPED_UNICODE);
        } else {
            $extra_data = '{}';
        }

        // ---------------- Addresses ----------------
        $present_address_id   = isset($data['present_address_id']) ? (int)$data['present_address_id'] : null;
        $permanent_address_id = isset($data['permanent_address_id']) ? (int)$data['permanent_address_id'] : null;

        $application_id = (int)$application_id;
        $union_id = (int)($union_id ?? 0);

        // ---------------- Update Query ----------------
        $stmt = $this->conn->prepare("
            UPDATE applications SET
                name_en=?, name_bn=?, nid=?, birth_id=?, passport_no=?, birth_date=?,
                gender=?, father_name_en=?, father_name_bn=?, mother_name_en=?, mother_name_bn=?,
                occupation=?, resident=?, educational_qualification=?, religion=?, marital_status=?,
                spouse_name_en=?, spouse_name_bn=?, applicant_name=?, applicant_phone=?, applicant_photo=?,
                documents=?, extra_data=?, edit_date=NOW(), present_address_id=?, permanent_address_id=?
            WHERE application_id=? AND (? = 0 OR union_id=?)
        ");

        if (!$stmt) throw new Exception("Prepare failed: " . $this->conn->error);

        $stmt->bind_param(
            'sssssssssssssssssssssssiisii',
            $name_en, $name_bn, $nid, $birth_id, $passport_no, $birth_date,
            $gender, $father_name_en, $father_name_bn, $mother_name_en, $mother_name_bn,
            $occupation, $resident, $educational_qualification, $religion, $marital_status,
            $spouse_name_en, $spouse_name_bn, $applicant_name, $applicant_phone, $applicant_photo,
            $documents, $extra_data, $present_address_id, $permanent_address_id,
            $application_id, $union_id, $union_id
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception("Execute failed: " . $error);
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            throw new Exception("No rows updated! Check application_id and union_id. (AppID: $application_id, UnionID: $union_id)");
        }

        return true;
    }




    /**
     * Get submitted application details by application_id (for viewSubmittedApplication)
     */

    public function getSubmittedApplicationDetails($application_id, $union_id = null)
    {
        $selectFields = self::getSelectFields();
        $joins = self::getJoinStatements();

        $sql = "SELECT $selectFields
                FROM applications a
                $joins
                WHERE a.application_id = ?";

        // ✅ যদি সুপার অ্যাডমিন না হয়, তাহলে ইউনিয়ন চেক করবো
        if ($union_id !== null) {
            $sql .= " AND a.union_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('si', $application_id, $union_id);
        } else {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $application_id);
        }

        if (!$stmt) {
            return null;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row;
    }

    public function deleteApplication($application_id, $union_id = null) {
        try {
            $sql = "DELETE FROM applications WHERE application_id = ?";
            if ($union_id) {
                $sql .= " AND union_id = ?";
            }

            $stmt = $this->conn->prepare($sql);
            if ($union_id) {
                $stmt->bind_param("si", $application_id, $union_id);
            } else {
                $stmt->bind_param("s", $application_id);
            }

            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            return ($affected > 0); // true if deleted, false otherwise

        } catch (Exception $e) {
            return false;
        }
    }


    public function getApprovalByApplication($application_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM application_approvals WHERE application_id = ?");
        $stmt->bind_param("s", $application_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function updateApprovalStatus($application_id, $status, $note = null)
    {
        $stmt = $this->conn->prepare("UPDATE application_approvals SET approval_status = ?, approval_note = ?, approval_date = NOW() WHERE application_id = ?");
        $stmt->bind_param("sss", $status, $note, $application_id);
        return $stmt->execute();
    }

    public function deleteApproval($id)
    {
        $stmt = $this->conn->prepare("DELETE FROM application_approvals WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    // ======================
    // APPLICATION LOGIC (fetch, approve, reject, etc.)
    // ======================

    public function getApplicationBySonodNumber($sonod_number, $certificate_type = null)
    {
        $selectFields = self::getSelectFields();
        $joins = self::getJoinStatements();

        $sql = "SELECT $selectFields
                FROM applications a
                $joins
                WHERE a.sonod_number = ?";

        $params = [$sonod_number];
        $types  = "s";

        if (!empty($certificate_type)) {
            $sql .= " AND a.certificate_type = ?";
            $params[] = $certificate_type;
            $types .= "s";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->fetch_assoc();
    }


    public function getApprovalByApplicationId($application_id)
    {
        global $mysqli;
        $stmt = $mysqli->prepare("SELECT * FROM application_approvals WHERE application_id = ?");
        $stmt->bind_param("s", $application_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    


    public function approveApplication($application_id, $data, $sonod_number, $union_id, $certificate_type = null)
    {
        $this->conn->begin_transaction();

        try {
            // Auto fetch certificate_type if not provided 
            if (empty($certificate_type)) {
                $cert_stmt = $this->conn->prepare("SELECT certificate_type FROM applications WHERE application_id = ? AND union_id = ? LIMIT 1");
                $cert_stmt->bind_param("si", $application_id, $union_id);
                $cert_stmt->execute();
                $cert_stmt->bind_result($certificate_type);
                $cert_stmt->fetch();
                $cert_stmt->close();
            }

            if (empty($certificate_type)) {
                throw new Exception("Certificate type not found for application ID: $application_id");
            }

            // Check if approval exists
            $check_stmt = $this->conn->prepare("SELECT id FROM application_approvals WHERE application_id = ? LIMIT 1");
            $check_stmt->bind_param("s", $application_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            $hasApproval = $check_stmt->num_rows > 0;
            $check_stmt->close();

            if (!empty($data['approval_date'])) {
                $approval_date = $data['approval_date']; // Already Y-m-d H:i:s from route
            } else {
                $approval_date = null;
            }
            
            if (!empty($data['verification_date'])) {
                $verification_date = $data['verification_date']; // Already Y-m-d from route
            } else {
                $verification_date = null;
            }

            // Common data
            $verifier_id = $data['verifier_id'] ?? null;
            $verifier_designation = $data['verifier_designation'] ?? null;
            $verifier_contact = $data['verifier_contact'] ?? null;
            $verifier_name_bn = $data['verifier_name_bn'] ?? null;
            $verifier_name_en = $data['verifier_name_en'] ?? null;
            $verifier_ward_no = $data['verifier_ward_no'] ?? null;
            $verification_note = $data['verification_note'] ?? null;
            $approver_id = $data['approver_id'] ?? null;
            $approver_name_bn = $data['approver_name_bn'] ?? null;
            $approver_name_en = $data['approver_name_en'] ?? null;
            $approver_ward_no = $data['approver_ward_no'] ?? null;
            //$approval_date = $data['approval_date'] ?? null;
            $approval_note = $data['approval_note'] ?? null;
            $approval_status = 'Approved';
            $certificate_fee = $data['certificate_fee'] ?? null;
            $payment_method = $data['payment_method'] ?? null;
            $payment_status = $data['payment_status'] ?? 'Unpaid';


            if ($hasApproval) {
                // Update
                $update_stmt = $this->conn->prepare("
                    UPDATE application_approvals SET
                        certificate_type = ?, verifier_id = ?, verifier_designation = ?, verifier_contact = ?,
                        verifier_name_bn = ?, verifier_name_en = ?, verifier_ward_no = ?,
                        verification_date = ?, verification_note = ?, approver_id = ?, approver_name_bn = ?,
                        approver_name_en = ?, approver_ward_no = ?, approval_date = ?,
                        approval_note = ?, approval_status = ?, certificate_fee = ?, payment_method = ?, payment_status = ?
                    WHERE application_id = ?
                ");
                $update_stmt->bind_param(
                    "ssissssssssssssssss",
                    $certificate_type, $verifier_id, $verifier_designation, $verifier_contact,
                    $verifier_name_bn, $verifier_name_en, $verifier_ward_no,
                    $verification_date, $verification_note, $approver_id, $approver_name_bn,
                    $approver_name_en, $approver_ward_no, $approval_date,
                    $approval_note, $approval_status, $certificate_fee, $payment_method, $payment_status, $application_id
                );
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // Insert
                $insert_stmt = $this->conn->prepare("
                    INSERT INTO application_approvals (
                        application_id, certificate_type, verifier_id, verifier_designation, verifier_contact,
                        verifier_name_bn, verifier_name_en, verifier_ward_no,
                        verification_date, verification_note, approver_id, approver_name_bn,
                        approver_name_en, approver_ward_no, approval_date,
                        approval_note, approval_status, certificate_fee, payment_method, payment_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_stmt->bind_param(
                    "ssisssssssssssssssss",
                    $application_id, $certificate_type, $verifier_id, $verifier_designation, $verifier_contact,
                    $verifier_name_bn, $verifier_name_en, $verifier_ward_no,
                    $verification_date, $verification_note, $approver_id, $approver_name_bn,
                    $approver_name_en, $approver_ward_no, $approval_date,
                    $approval_note, $approval_status, $certificate_fee, $payment_method, $payment_status
                );
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            $issue_by = $_SESSION['user_id'] ?? 'system';
            $issue_date = $approval_date;
            $status = 'Approved';

            if (!empty($union_id)) {
                $update_app_stmt = $this->conn->prepare("
                    UPDATE applications 
                    SET issue_by = ?, issue_date = ?, status = ?, sonod_number = ?
                    WHERE application_id = ? AND union_id = ?
                ");
                $update_app_stmt->bind_param(
                    "sssssi",
                    $issue_by, $issue_date, $status, $sonod_number,
                    $application_id, $union_id
                );
            } else {
                $update_app_stmt = $this->conn->prepare("
                    UPDATE applications 
                    SET issue_by = ?, issue_date = ?, status = ?, sonod_number = ?
                    WHERE application_id = ?
                ");
                $update_app_stmt->bind_param(
                    "sssss",
                    $issue_by, $issue_date, $status, $sonod_number,
                    $application_id
                );
            }

            $update_app_stmt->execute();
            $update_app_stmt->close();

            $this->conn->commit();

            return [
                'status' => 'success',
                'message' => 'Application approved successfully.'
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'status' => 'error',
                'message' => 'Approval failed: ' . $e->getMessage()
            ];
        }
    }



    // union_id দিয়ে ফিল্টার শুধুমাত্র applications table-এ
    public function rejectApplication($application_id, $reject_reason, $union_id, $certificate_type = null)
    {
        $reject_date = date('Y-m-d H:i:s');
        $this->conn->begin_transaction();
        try {
            // Check if rejection already exists
            $check_rejection_sql = "SELECT COUNT(*) FROM application_approvals WHERE application_id = ? AND approval_status = 'Rejected'";
            $stmt = $this->conn->prepare($check_rejection_sql);
            $stmt->bind_param("s", $application_id);
            $stmt->execute();
            $stmt->bind_result($rejection_count);
            $stmt->fetch();
            $stmt->close();
            if ($rejection_count > 0) {
                return ['status' => 'error', 'message' => 'This applicant has already been rejected.'];
            }
            // Insert rejection into application_approvals table
            $sql_rejection = "INSERT INTO application_approvals (
                                application_id, 
                                approval_status, 
                                reject_reason, 
                                reject_date,
                                certificate_type
                            ) VALUES (?, 'Rejected', ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql_rejection);
            $stmt->bind_param("sssss", $application_id, $reject_reason, $reject_date, $certificate_type);
            if (!$stmt->execute()) {
                throw new Exception("Error inserting rejection record: " . $stmt->error);
            }
            $stmt->close();
            // Update the status of the applicant in applications table
            $sql_update = "UPDATE applications SET status = 'Rejected', issue_date = NOW() WHERE application_id = ? AND union_id = ?";
            $stmt = $this->conn->prepare($sql_update);
            $stmt->bind_param("si", $application_id, $union_id);
            if (!$stmt->execute()) {
                throw new Exception("Error updating application status: " . $stmt->error);
            }
            $stmt->close();
            $this->conn->commit();
            return ['status' => 'success', 'message' => '✅ Application has been rejected successfully!'];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // union_id দিয়ে ফিল্টার শুধুমাত্র applications table-এ
    public function setApplicationOnHold($application_id, $note, $union_id)
    {
        $sql = "UPDATE applications SET status = 'On Hold', hold_note = ?, hold_date = NOW() WHERE application_id = ? AND union_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $note, $application_id, $union_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    // union_id দিয়ে ফিল্টার শুধুমাত্র applications table-এ
    public function reactivateApplication($application_id, $union_id)
    {
        $sql = "UPDATE applications SET status = 'Active', hold_note = NULL, hold_date = NULL WHERE application_id = ? AND union_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $application_id, $union_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }


    public function updateSonodStatus($application_id, $union_id, $sonod_number, $status) {
        $stmt = $this->conn->prepare("UPDATE applications SET sonod_number = ?, status = ? WHERE application_id = ? AND union_id = ?");
        $stmt->bind_param("sssi", $sonod_number, $status, $application_id, $union_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }


    public function getApplicationsByApplicantId($applicant_id, $offset = 0, $limit = 10) {
        global $mysqli;
        $unionModel = new UnionModel($mysqli);
        $selectFields = self::getSelectFields();
        $joins = self::getJoinStatements();
    
        // ডেটা আনা
        $stmt = $mysqli->prepare("
            SELECT $selectFields 
            FROM applications a 
            $joins 
            WHERE a.applicant_id = ? 
            ORDER BY a.apply_date DESC 
            LIMIT ?, ?
        ");
        $offset = (int)$offset;
        $limit = (int)$limit;
        $stmt->bind_param("sii", $applicant_id, $offset, $limit);
        $stmt->execute();       
        $result = $stmt->get_result();
        $applications = $result->fetch_all(MYSQLI_ASSOC);
    
        // প্রতিটি আবেদনের union ডেটা যোগ করা
        foreach ($applications as &$app) {
            if (!empty($app['union_id'])) {
                $app['union'] = $unionModel->getById($app['union_id']);
            }
        }
    
        // মোট রেকর্ড সংখ্যা
        $countStmt = $mysqli->prepare("
            SELECT COUNT(*) AS total 
            FROM applications a 
            $joins 
            WHERE a.applicant_id = ?
        ");
        $countStmt->bind_param("s", $applicant_id);
        $countStmt->execute();
        $totalRes = $countStmt->get_result();
        $total = (int)$totalRes->fetch_assoc()['total'];
        $total_pages = ceil($total / $limit);
    
        return [
            'applications' => $applications,
            'total' => $total,
            'total_pages' => $total_pages
        ];
    }

    /**
     * Get the most recent application by applicant ID
     */
    public function getLatestApplicationByApplicantId($applicant_id) {
        $stmt = $this->conn->prepare("SELECT * FROM applications WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $applicant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    public function getAllCertificateTypes() {
        $types = [];
    
        $whereClause = "WHERE tt.is_certificate_type = 1";
    
        $query = "SELECT 
                    tt.slug AS certificate_type,
                    " . static::getCertificateTypeFields() . "
                FROM term_translations tt
                $whereClause
                GROUP BY tt.slug
                ORDER BY tt.name_bn ASC";
    
        $result = $this->conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $types[] = [
                    'slug'      => $row['certificate_type'],
                    'name_bn'   => $row['certificate_type_bn'] ?? '',
                    'name_en'   => $row['certificate_type_en'] ?? '',
                    'name_bl'   => $row['certificate_type_bl'] ?? '',
                    'type_url'  => $row['type_url'] ?? '' // ✅ নতুন লাইন
                ];
            }
            $result->free();
        }
    
        return $types;
    }

    /**
     * Find an application by identifier (NID, Birth ID, Passport, PIN/Application ID)
     *
     * @param string $identifier The search value entered by user.
     * @param int|null $union_id Optional union filter.
     * @return array|null Application data if found, else null.
     */
    public function findApplicationByIdentifier($identifier, $union_id = null)
    {
        $selectFields = self::getSelectFields();
        $joins = self::getJoinStatements();
    
        $sql = "SELECT $selectFields
                FROM applications a
                $joins
                WHERE (a.nid = ?
                       OR a.birth_id = ?
                       OR a.passport_no = ?
                       OR a.application_id = ?
                       OR a.applicant_id = ?)";
    
        $params = [$identifier, $identifier, $identifier, $identifier, $identifier];
        $types  = 'sssss';
    
        // যদি ইউনিয়ন ফিল্টার ব্যবহার করতে চান
        if ($union_id !== null) {
            $sql .= " AND a.union_id = ?";
            $params[] = $union_id;
            $types   .= 'i';
        }
    
        $sql .= " LIMIT 1";
    
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
    
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    
        return $result->fetch_assoc() ?: null;
    }

}
