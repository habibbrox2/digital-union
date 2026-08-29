<?php
/**
 * SweetAlert Helper
 * 
 * Helper functions for consistent SweetAlert2 implementation across the application
 * Supports success, error, warning, info, and confirmation messages
 * 
 * NOTE: All response messages should be in Bengali (বাংলা) for consistency
 * throughout the application. Use Bengali text for titles and messages
 * when calling these functions.
 * 
 * Example:
 * setSweetAlert('সফল', 'তথ্য সফলভাবে সংরক্ষণ করা হয়েছে', 'success');
 * errorAlert('ত্রুটি', 'তথ্য সংরক্ষণ করতে ব্যর্থ হয়েছে');
 */

/**
 * Set a SweetAlert message in the session
 * 
 * @param string $title - Alert title (Bengali recommended)
 * @param string $message - Alert message (Bengali recommended)
 * @param string $type - 'success', 'error', 'warning', 'info', 'question'
 * @param array $options - Additional options (button text, etc.)
 */
if (!function_exists('setSweetAlert')) {
    function setSweetAlert($title, $message, $type = 'info', $options = [])
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['sweetalert'] = [
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'options' => $options
        ];
    }
}

/**
 * Get and clear SweetAlert from session
 * 
 * @return array|null
 */
if (!function_exists('getSweetAlert')) {
    function getSweetAlert()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $alert = $_SESSION['sweetalert'] ?? null;
        unset($_SESSION['sweetalert']);
        
        return $alert;
    }
}

/**
 * Quick success alert
 * Use Bengali messages: successAlert('সফল', 'আপনার তথ্য সংরক্ষিত হয়েছে')
 */
if (!function_exists('successAlert')) {
    function successAlert($title, $message = '', $options = [])
    {
        setSweetAlert($title, $message, 'success', $options);
    }
}

/**
 * Quick error alert
 * Use Bengali messages: errorAlert('ত্রুটি', 'একটি সমস্যা হয়েছে')
 */
if (!function_exists('errorAlert')) {
    function errorAlert($title, $message = '', $options = [])
    {
        setSweetAlert($title, $message, 'error', $options);
    }
}

/**
 * Quick warning alert
 * Use Bengali messages: warningAlert('সতর্কতা', 'আপনি কি নিশ্চিত?')
 */
if (!function_exists('warningAlert')) {
    function warningAlert($title, $message = '', $options = [])
    {
        setSweetAlert($title, $message, 'warning', $options);
    }
}

/**
 * Quick info alert
 * Use Bengali messages: infoAlert('তথ্য', 'আপনার তথ্য প্রক্রিয়া করা হচ্ছে')
 */
if (!function_exists('infoAlert')) {
    function infoAlert($title, $message = '', $options = [])
    {
        setSweetAlert($title, $message, 'info', $options);
    }
}

/**
 * JSON response with SweetAlert data
 * Useful for API responses that need to display alerts
 * 
 * NOTE: Use Bengali for title and message parameters
 * 
 * @param bool $success
 * @param string $title - Bengali text recommended
 * @param string $message - Bengali text recommended
 * @param array $data
 * @param string $redirect
 */
if (!function_exists('jsonResponse')) {
    function jsonResponse($success = true, $title = '', $message = '', $data = [], $redirect = null)
    {
        header('Content-Type: application/json');
        
        $response = [
            'success' => $success,
            'alert' => [
                'title' => $title,
                'message' => $message,
                'type' => $success ? 'success' : 'error'
            ],
            'data' => $data
        ];
        
        if ($redirect) {
            $response['redirect'] = $redirect;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Toast notification helper
 * Returns JavaScript code to display a toast
 * 
 * NOTE: Use Bengali for message parameter
 * Example: toastAlert('সফলভাবে সংরক্ষিত হয়েছে', 'success')
 * 
 * @param string $message - Bengali text recommended
 * @param string $type - 'success', 'error', 'warning', 'info'
 * @param string $position - 'top-start', 'top', 'top-end', 'center-start', 'center', 'center-end', 'bottom-start', 'bottom', 'bottom-end', 'top-right', 'bottom-right'
 */
if (!function_exists('toastAlert')) {
    function toastAlert($message, $type = 'info', $position = 'top-right')
    {
        // Escape message for JavaScript
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        
        return "
        <script>
        Swal.fire({
            toast: true,
            icon: '{$type}',
            title: '{$escapedMessage}',
            position: '{$position}',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });
        </script>
        ";
    }
}
?>