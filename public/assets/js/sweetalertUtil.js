/**
 * SweetAlert2 Utility Helper
 * Global utility functions for consistent SweetAlert2 usage
 * Version: 2.1 - Fixed for dropdown compatibility
 */

const SweetAlertUtil = {
    // Config
    config: {
        confirmButtonColor: '#007bff',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'হ্যাঁ',
        cancelButtonText: 'না',
        denyButtonText: 'বাতিল করুন'
    },

    /**
     * Show success alert
     */
    success: function(title, message = '', options = {}) {
        const defaultOptions = {
            icon: 'success',
            title: title,
            text: message,
            confirmButtonColor: this.config.confirmButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            allowOutsideClick: true,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Show error alert
     */
    error: function(title, message = '', options = {}) {
        const defaultOptions = {
            icon: 'error',
            title: title,
            text: message,
            confirmButtonColor: this.config.confirmButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            allowOutsideClick: true,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Show warning alert
     */
    warning: function(title, message = '', options = {}) {
        const defaultOptions = {
            icon: 'warning',
            title: title,
            text: message,
            confirmButtonColor: this.config.confirmButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            allowOutsideClick: true,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Show info alert
     */
    info: function(title, message = '', options = {}) {
        const defaultOptions = {
            icon: 'info',
            title: title,
            text: message,
            confirmButtonColor: this.config.confirmButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            allowOutsideClick: true,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Show question/confirm dialog
     */
    question: function(title, message = '', options = {}) {
        const defaultOptions = {
            icon: 'question',
            title: title,
            text: message,
            showCancelButton: true,
            confirmButtonColor: this.config.confirmButtonColor,
            cancelButtonColor: this.config.cancelButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            cancelButtonText: this.config.cancelButtonText,
            allowOutsideClick: false,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Show confirmation dialog with callback
     */
    confirm: function(title, message = '', onConfirm, onCancel = null) {
        return this.question(title, message).then((result) => {
            if (result.isConfirmed) {
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            } else if (result.isDismissed && typeof onCancel === 'function') {
                onCancel();
            }
        });
    },

    /**
     * Show toast notification
     */
    toast: function(message, type = 'info', position = 'top-end') {
        const Toast = Swal.mixin({
            toast: true,
            position: position,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        return Toast.fire({
            icon: type,
            title: message
        });
    },

    /**
     * Show loading/processing alert
     */
    loading: function(title = 'প্রসেস করা হচ্ছে...', message = '') {
        return Swal.fire({
            title: title,
            text: message,
            icon: 'info',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    },

    /**
     * Close current alert
     */
    close: function() {
        return Swal.close();
    },

    /**
     * Hide loading indicator
     */
    hide: function() {
        return Swal.hideLoading();
    },

    /**
     * Show input dialog
     */
    input: function(title, message = '', inputType = 'text', options = {}) {
        const defaultOptions = {
            icon: 'info',
            title: title,
            text: message,
            input: inputType,
            inputPlaceholder: 'আপনার উত্তর লিখুন...',
            showCancelButton: true,
            confirmButtonColor: this.config.confirmButtonColor,
            cancelButtonColor: this.config.cancelButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            cancelButtonText: this.config.cancelButtonText,
            allowOutsideClick: false,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Show HTML content alert
     */
    html: function(title, htmlContent, options = {}) {
        const defaultOptions = {
            title: title,
            html: htmlContent,
            confirmButtonColor: this.config.confirmButtonColor,
            confirmButtonText: this.config.confirmButtonText,
            allowOutsideClick: true,
            allowEscapeKey: true
        };
        return Swal.fire({ ...defaultOptions, ...options });
    },

    /**
     * Global error handler for AJAX
     */
    handleAjaxError: function(xhr, status, error, customTitle = 'সমস্যা হয়েছে') {
        let message = 'কিছু সমস্যা হয়েছে। আবার চেষ্টা করুন।';
        
        if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        } else if (xhr.responseJSON && xhr.responseJSON.alert) {
            return this.error(
                xhr.responseJSON.alert.title || customTitle, 
                xhr.responseJSON.alert.message || message
            );
        }

        this.error(customTitle, message);
    },

    /**
     * Update configuration
     */
    setConfig: function(config) {
        this.config = { ...this.config, ...config };
    }
};

// Expose globally
window.SweetAlertUtil = SweetAlertUtil;