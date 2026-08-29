/**
 * Popup Message System - Global notification display
 * Usage: Popup.show('Message text', 'success|error|warning|info', 3000)
 */
const Popup = (function() {
    let popupId = 0;

    function createPopupElement(message, type, duration) {
        const id = `popup-${++popupId}`;
        const popup = document.createElement('div');
        popup.id = id;
        popup.className = `popup popup-${type}`;
        popup.innerHTML = `
            <div class="popup-content">
                <span class="popup-message">${escapeHtml(message)}</span>
                <button class="popup-close" aria-label="Close">&times;</button>
            </div>
        `;

        // Close button handler
        popup.querySelector('.popup-close').addEventListener('click', function() {
            removePopup(id);
        });

        document.body.appendChild(popup);

        // Trigger animation
        requestAnimationFrame(() => popup.classList.add('show'));

        // Auto-remove after duration (if duration > 0)
        if (duration > 0) {
            setTimeout(() => removePopup(id), duration);
        }

        return id;
    }

    function removePopup(id) {
        const popup = document.getElementById(id);
        if (popup) {
            popup.classList.remove('show');
            popup.addEventListener('transitionend', function() {
                popup.remove();
            }, { once: true });
            // Fallback remove after animation
            setTimeout(() => {
                if (popup.parentNode) popup.remove();
            }, 500);
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    return {
        /**
         * Show a popup message
         * @param {string} message - Message text
         * @param {string} type - Type: 'success', 'error', 'warning', 'info'
         * @param {number} duration - Duration in ms (0 = no auto-close)
         */
        show: function(message, type = 'info', duration = 3000) {
            if (!message) return;
            type = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
            return createPopupElement(message, type, duration);
        },

        /**
         * Show success message
         */
        success: function(message, duration = 3000) {
            return this.show(message, 'success', duration);
        },

        /**
         * Show error message
         */
        error: function(message, duration = 5000) {
            return this.show(message, 'error', duration);
        },

        /**
         * Show warning message
         */
        warning: function(message, duration = 4000) {
            return this.show(message, 'warning', duration);
        },

        /**
         * Show info message
         */
        info: function(message, duration = 3000) {
            return this.show(message, 'info', duration);
        },

        /**
         * Remove a popup by ID
         */
        remove: function(id) {
            removePopup(id);
        },

        /**
         * Remove all popups
         */
        removeAll: function() {
            document.querySelectorAll('.popup').forEach(p => {
                p.remove();
            });
        }
    };
})();
