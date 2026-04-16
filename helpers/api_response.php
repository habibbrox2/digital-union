<?php

/**
 * API Response Helper Functions
 * Standardized JSON response format for AJAX endpoints
 * Frontend SweetAlert2 Integration
 */

// ==================== RESPONSE FUNCTIONS WITH function_exists() GUARDS ====================

if (!function_exists('respondSuccess')) {
    /**
     * Send a JSON success response
     * 
     * @param string $message Response message
     * @param mixed $data Optional data to include
     * @param int $statusCode HTTP status code
     * 
     * Frontend SweetAlert2 Integration:
     * fetch('/api/endpoint')
     *   .then(r => r.json())
     *   .then(data => {
     *     if(data.status === 'success') {
     *       Swal.fire('সফল', data.message, 'success');
     *     }
     *   });
     */
    function respondSuccess($message, $data = null, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

if (!function_exists('respondError')) {
    /**
     * Send a JSON error response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param mixed $data Optional data to include
     * 
     * Frontend SweetAlert2 Integration:
     * fetch('/api/endpoint')
     *   .then(r => r.json())
     *   .then(data => {
     *     if(data.status === 'error') {
     *       Swal.fire('ত্রুটি', data.message, 'error');
     *     }
     *   });
     */
    function respondError($message, $statusCode = 400, $data = null) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

if (!function_exists('respondWarning')) {
    /**
     * Send a JSON warning response
     * 
     * @param string $message Warning message
     * @param mixed $data Optional data to include
     * 
     * Frontend SweetAlert2 Integration:
     * fetch('/api/endpoint')
     *   .then(r => r.json())
     *   .then(data => {
     *     if(data.status === 'warning') {
     *       Swal.fire('সতর্কতা', data.message, 'warning');
     *     }
     *   });
     */
    function respondWarning($message, $data = null) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => 'warning',
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

if (!function_exists('respondInfo')) {
    /**
     * Send a JSON info response
     * 
     * @param string $message Info message
     * @param mixed $data Optional data to include
     * 
     * Frontend SweetAlert2 Integration:
     * fetch('/api/endpoint')
     *   .then(r => r.json())
     *   .then(data => {
     *     if(data.status === 'info') {
     *       Swal.fire('তথ্য', data.message, 'info');
     *     }
     *   });
     */
    function respondInfo($message, $data = null) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => 'info',
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

if (!function_exists('respond')) {
    /**
     * Send a generic JSON response
     * 
     * @param string $status Response status (success, error, warning, info)
     * @param string $message Response message
     * @param mixed $data Optional data
     * @param int $statusCode HTTP status code
     * 
     * Frontend SweetAlert2 Integration:
     * const showAlert = (response) => {
     *   const iconMap = {
     *     'success': 'success',
     *     'error': 'error',
     *     'warning': 'warning',
     *     'info': 'info'
     *   };
     *   Swal.fire(response.message, response.data?.details || '', iconMap[response.status] || 'info');
     * };
     * 
     * fetch('/api/endpoint')
     *   .then(r => r.json())
     *   .then(showAlert);
     */
    function respond($status, $message, $data = null, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

// ==================== VALIDATION RESPONSE HELPERS ====================

if (!function_exists('respondValidationError')) {
    /**
     * Send validation error response with field-specific errors
     * 
     * @param array $errors Associative array of field => error message
     * 
     * Frontend SweetAlert2 Integration:
     * fetch('/api/endpoint')
     *   .then(r => r.json())
     *   .then(data => {
     *     if(data.status === 'validation_error') {
     *       const errorList = Object.values(data.errors).join('<br>');
     *       Swal.fire('ভ্যালিডেশন ত্রুটি', errorList, 'error');
     *     }
     *   });
     */
    function respondValidationError($errors) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => 'validation_error',
            'message' => 'Validation failed',
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

if (!function_exists('respondPaginated')) {
    /**
     * Send paginated response
     * 
     * @param array $items Data items
     * @param int $page Current page
     * @param int $pageSize Items per page
     * @param int $total Total items count
     * @param string $message Optional message
     */
    function respondPaginated($items, $page, $pageSize, $total, $message = 'Success') {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        
        $totalPages = ceil($total / $pageSize);
        
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => $items,
            'pagination' => [
                'page' => (int)$page,
                'pageSize' => (int)$pageSize,
                'total' => (int)$total,
                'totalPages' => (int)$totalPages
            ]
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
}

// ==================== LEGACY COMPATIBILITY ====================

if (!function_exists('responseJson')) {
    /**
     * Legacy: Generic JSON response
     */
    function responseJson($status, $message, $data = null) {
        respond($status, $message, $data);
    }
}
