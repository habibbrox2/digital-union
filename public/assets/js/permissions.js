/**
 * Permission Check and Management System
 * Client-side permission validation and UI control
 * Version: 2.0 - Fixed syntax errors and dropdown issues
 */

(function() {
    'use strict';
    
    const PermissionManager = {
        // Configuration
        config: {
            apiEndpoint: '/api/check-permission',
            cacheTimeout: 300000, // 5 minutes
            debugMode: false
        },
        
        // Cache for permission checks
        permissionCache: new Map(),
        
        // Initialize
        init: function() {
            this.setupPermissionChecks();
            this.setupAssignButtons();
            this.log('Permission Manager Initialized');
        },
        
        // Setup permission-based visibility
        setupPermissionChecks: function() {
            const self = this;
            const elements = document.querySelectorAll('[data-permission]');
            
            if (elements.length === 0) return;
            
            elements.forEach(element => {
                const permission = element.getAttribute('data-permission');
                if (!permission) return;
                
                self.checkPermission(permission)
                    .then(hasPermission => {
                        if (!hasPermission) {
                            element.style.display = 'none';
                            element.classList.add('permission-denied');
                        } else {
                            element.classList.add('permission-granted');
                        }
                    })
                    .catch(error => {
                        self.log('Permission check failed: ' + error.message);
                        // Default to hiding on error for security
                        element.style.display = 'none';
                    });
            });
        },
        
        // Setup assign permission buttons
        setupAssignButtons: function() {
            const self = this;
            
            // Remove existing handlers to prevent duplicates
            document.removeEventListener('click', self._handleAssignClick);
            
            // Store bound function for removal later
            self._handleAssignClick = function(event) {
                const button = event.target.closest('[data-assign-permission]');
                if (!button) return;
                
                event.preventDefault();
                event.stopPropagation();
                
                const userId = button.dataset.userId;
                const permissionId = button.dataset.permissionId;
                const unionId = button.dataset.unionId;
                const allowed = button.dataset.allowed || '1';
                
                if (!userId || !permissionId) {
                    self.log('Missing required data attributes');
                    return;
                }
                
                self.assignPermission(userId, permissionId, unionId, allowed, button);
            };
            
            document.addEventListener('click', self._handleAssignClick);
        },
        
        // Check if user has permission
        checkPermission: function(permission) {
            const self = this;
            
            // Check cache first
            if (this.permissionCache.has(permission)) {
                const cached = this.permissionCache.get(permission);
                const now = Date.now();
                
                if (now - cached.timestamp < this.config.cacheTimeout) {
                    return Promise.resolve(cached.value);
                }
            }
            
            // Fetch from API
            return fetch(this.config.apiEndpoint + '?permission=' + encodeURIComponent(permission), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                const hasPermission = data.hasPermission || false;
                
                // Cache the result
                self.permissionCache.set(permission, {
                    value: hasPermission,
                    timestamp: Date.now()
                });
                
                return hasPermission;
            })
            .catch(error => {
                self.log('Permission check error: ' + error.message);
                throw error;
            });
        },
        
        // Assign permission to user
        assignPermission: function(userId, permissionId, unionId, allowed, buttonElement) {
            const self = this;
            
            // Disable button during request
            if (buttonElement) {
                buttonElement.disabled = true;
                const originalHTML = buttonElement.innerHTML;
                buttonElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                buttonElement.dataset.originalHtml = originalHTML;
            }
            
            const formData = new URLSearchParams({
                permission_id: permissionId,
                union_id: unionId || '',
                allowed: allowed
            });
            
            fetch('/users/' + userId + '/assign-permission', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                // Try to parse as JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                }
                
                // Fallback for non-JSON responses
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        return { 
                            success: response.ok, 
                            error: response.ok ? null : 'Invalid response format' 
                        };
                    }
                });
            })
            .then(data => {
                // Re-enable button
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.innerHTML = buttonElement.dataset.originalHtml || 'Assign';
                }
                
                if (data.success || data.message) {
                    // Success
                    if (typeof SweetAlertUtil !== 'undefined') {
                        SweetAlertUtil.toast(
                            data.message || 'অনুমতি সফলভাবে প্রদান করা হয়েছে!', 
                            'success'
                        );
                    }
                    
                    // Update button state
                    if (buttonElement) {
                        if (allowed === '1' || allowed === 1) {
                            buttonElement.classList.add('btn-success');
                            buttonElement.classList.remove('btn-outline-success', 'btn-secondary');
                            buttonElement.innerHTML = '<i class="fas fa-check"></i> প্রদান করা হয়েছে';
                        } else {
                            buttonElement.classList.add('btn-outline-danger');
                            buttonElement.classList.remove('btn-success', 'btn-secondary');
                            buttonElement.innerHTML = '<i class="fas fa-times"></i> বাতিল করা হয়েছে';
                        }
                    }
                    
                    // Clear permission cache
                    self.permissionCache.clear();
                } else {
                    // Error
                    const errorMsg = data.error || 'অনুমতি প্রদানে সমস্যা হয়েছে';
                    
                    if (typeof SweetAlertUtil !== 'undefined') {
                        SweetAlertUtil.toast(errorMsg, 'error');
                    } else {
                        alert('Error: ' + errorMsg);
                    }
                }
            })
            .catch(error => {
                self.log('Assign permission error: ' + error.message);
                
                // Re-enable button
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.innerHTML = buttonElement.dataset.originalHtml || 'Assign';
                }
                
                if (typeof SweetAlertUtil !== 'undefined') {
                    SweetAlertUtil.toast('সংযোগে সমস্যা হয়েছে', 'error');
                } else {
                    alert('Error: ' + error.message);
                }
            });
        },
        
        // Revoke permission from user
        revokePermission: function(userId, permissionId, unionId, buttonElement) {
            return this.assignPermission(userId, permissionId, unionId, '0', buttonElement);
        },
        
        // Clear permission cache
        clearCache: function() {
            this.permissionCache.clear();
            this.log('Permission cache cleared');
        },
        
        // Refresh all permission checks
        refresh: function() {
            this.clearCache();
            this.setupPermissionChecks();
            this.log('Permission checks refreshed');
        },
        
        // Logging
        log: function(message) {
            if (this.config.debugMode) {
                console.log('[PermissionManager] ' + message);
            }
        }
    };
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            PermissionManager.init();
        });
    } else {
        PermissionManager.init();
    }
    
    // Expose to global scope
    window.PermissionManager = PermissionManager;
    
})();