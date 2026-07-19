<?php
/**
 * modules/Services/ExtraFieldsService.php
 * 
 * Service layer for extra fields management.
 * Handles JSON formatting, data transformation, and permission checks.
 * Database CRUD is delegated to ExtraFields model.
 */

class ExtraFieldsService
{
    private ExtraFields $model;

    public function __construct(mysqli $mysqli)
    {
        $this->model = new ExtraFields($mysqli);
    }

    /**
     * Get the ExtraFields model instance
     */
    public function getModel(): ExtraFields
    {
        return $this->model;
    }

    /**
     * Get certificate types for dropdown
     */
    public function getCertificateTypes(): array
    {
        return $this->model->getCertificateTypes();
    }

    /**
     * Get all fields for a given certificate type (or all)
     */
    public function getAllFields(?string $certificateType = null): array
    {
        return $this->model->getAll($certificateType);
    }

    /**
     * Get a single field by ID
     */
    public function getFieldById(int $id): ?array
    {
        return $this->model->getById($id);
    }

    /**
     * Save fields for a certificate type
     */
    public function saveFields(string $certificateType, array $fields): array
    {
        try {
            $this->model->save($certificateType, $fields);
            return [
                'status' => 'success',
                'alert' => [
                    'type' => 'success',
                    'title' => 'সাফল্য',
                    'message' => 'ফিল্ডগুলি সফলভাবে সংরক্ষণ করা হয়েছে',
                ],
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'alert' => [
                    'type' => 'error',
                    'title' => 'ত্রুটি',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Format fields as JSON for public API consumption
     * Groups by certificate type with proper field metadata
     */
    public function getFormattedFieldsJson(): array
    {
        $fields = $this->model->getAll(null);
        $output = [];

        foreach ($fields as $f) {
            $type = $f['type'] ?? 'text';
            $certType = $f['certificate_type'];

            $field = [
                'id' => $f['field_id'],
                'label' => $f['label_bn'] ?? $f['label_en'] ?? '',
                'type' => $type,
            ];

            if (!empty($f['placeholder'])) {
                $field['placeholder'] = $f['placeholder'];
            }
            if (!empty($f['count'])) {
                $field['count'] = (int)$f['count'];
            }
            if (!empty($f['order_after'])) {
                $field['orderAfter'] = $f['order_after'];
            }
            if ($type === 'select' && !empty($f['options'])) {
                $field['options'] = json_decode($f['options'], true);
            }

            if (!isset($output[$certType])) {
                $output[$certType] = [];
            }

            $output[$certType][] = $field;
        }

        return $output;
    }
}
