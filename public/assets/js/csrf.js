/**
 * csrf.js
 * Compatible with your CSRF PHP middleware
 * Author: Hr Habib
 *
 * NON-INTRUSIVE
 * No existing JS file modification
 */

(function (window, document) {
    'use strict';

    /* ================================
       1. Read CSRF token from META
    ================================ */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf_token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    // expose if needed
    window.getCsrfToken = getCsrfToken;

    /* ================================
       2. Auto inject into HTML FORMS
       (POST only)
    ================================ */
    document.addEventListener('submit', function (e) {
        const form = e.target;

        if (!(form instanceof HTMLFormElement)) return;

        const method = (form.method || 'GET').toUpperCase();
        if (method !== 'POST') return;

        if (form.querySelector('input[name="csrf_token"]')) return;

        const token = getCsrfToken();
        if (!token) return;

        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'csrf_token';
        input.value = token;

        form.appendChild(input);
    }, true);

    /* ================================
       3. XMLHttpRequest (Header only)
       → matches PHP:
       $_SERVER['HTTP_X_CSRF_TOKEN']
    ================================ */
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__csrfMethod = method;
        this.__csrfUrl = url;
        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        const token  = getCsrfToken();
        const method = (this.__csrfMethod || 'GET').toUpperCase();

        // Only add CSRF headers to same-origin requests. External APIs
        // (Firebase, Google, etc.) reject requests with custom headers
        // because their CORS policy does not list them.
        let isSameOrigin = true;
        try {
            const url = this.__csrfUrl || '';
            if (url && /^https?:\/\//i.test(url)) {
                isSameOrigin = url.startsWith(window.location.origin);
            }
        } catch (e) {}

        if (
            isSameOrigin &&
            token &&
            ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
        ) {
            try {
                this.setRequestHeader('X-CSRF-TOKEN', token);
                this.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            } catch (e) {}
        }

        return originalSend.apply(this, arguments);
    };

    /* ================================
       5. Fetch API (Optional but Recommended)
    ================================ */
    const originalFetch = window.fetch;
    window.fetch = function (input, init = {}) {
        const token = getCsrfToken();
        const method = (init.method || 'GET').toUpperCase();

        // Only add CSRF headers to same-origin requests. External APIs
        // (Firebase, Google, etc.) reject requests with custom headers
        // because their CORS policy does not list them.
        let isSameOrigin = true;
        try {
            const url = typeof input === 'string' ? input : (input instanceof Request ? input.url : '');
            if (url && /^https?:\/\//i.test(url)) {
                isSameOrigin = url.startsWith(window.location.origin);
            }
        } catch (e) {}

        if (isSameOrigin && token && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            init.headers = init.headers || {};

            // Headers version
            if (init.headers instanceof Headers) {
                init.headers.set('X-CSRF-TOKEN', token);
                init.headers.set('X-Requested-With', 'XMLHttpRequest');
            } else if (Array.isArray(init.headers)) {
                init.headers.push(['X-CSRF-TOKEN', token]);
                init.headers.push(['X-Requested-With', 'XMLHttpRequest']);
            } else {
                init.headers['X-CSRF-TOKEN'] = token;
                init.headers['X-Requested-With'] = 'XMLHttpRequest';
            }
        }
        return originalFetch.call(this, input, init);
    };
})(window, document);
