/**
 * Form Debugging Utility - Captures all form-related events for debugging
 * This helps identify why forms might be reloading unexpectedly
 */

(function() {
    'use strict';
    
    // Enable debugging on page load
    document.addEventListener('DOMContentLoaded', function() {
        setupFormDebug();
    });
    
    function setupFormDebug() {
        console.clear();
        console.log('========== FORM DEBUG MODE ENABLED ==========');
        
        // Monitor ALL forms
        const forms = document.querySelectorAll('form');
        forms.forEach((form, index) => {
            const formId = form.id || 'form-' + index;
            console.log(`📋 Form found: ${formId}`, form);
            
            // Capture SUBMIT event
            form.addEventListener('submit', function(e) {
                console.log(`✓ SUBMIT event on ${formId}`);
                console.log(`  - Prevented: ${e.defaultPrevented}`);
                console.log(`  - Form data:`, new FormData(form));
            }, true);  // Capture phase
            
            // Capture RESET event
            form.addEventListener('reset', function(e) {
                console.warn(`⚠ RESET event on ${formId}`);
            });
            
            // Monitor all input changes
            form.querySelectorAll('input, select, textarea').forEach(input => {
                const inputName = input.name || input.id || 'unknown';
                const inputType = input.type;
                
                input.addEventListener('change', function(e) {
                    console.log(`  └─ Change: [${inputType}] ${inputName} = "${this.value}"`);
                }, false);
            });
        });
        
        // Monitor window unload
        window.addEventListener('beforeunload', function(e) {
            console.warn('⚠ BEFORE UNLOAD triggered - page navigation detected');
        });
        
        // Intercept fetch calls
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            console.log(`🔄 FETCH call:`, args[0], args[1]);
            return originalFetch.apply(this, args);
        };
        
        // Intercept XHR
        const xhr = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method, url) {
            console.log(`🔄 XHR ${method}: ${url}`);
            return xhr.apply(this, arguments);
        };
        
        console.log('========== DEBUG INITIALIZED ==========');
    }
})();
