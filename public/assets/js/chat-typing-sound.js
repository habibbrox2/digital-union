/**
 * Shared Typing Sound Utility for Live Chat
 * Used by both admin panel (admin.twig) and visitor widget (chat.js)
 * Provides consistent typing indicator sound with settings support.
 */

(function() {
    'use strict';

    // ============================================================
    // Audio Context Management (Singleton)
    // ============================================================
    let _typingAudioCtx = null;
    let _notificationAudioCtx = null;

    function getTypingAudioContext() {
        if (!_typingAudioCtx) {
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (AC) _typingAudioCtx = new AC();
            } catch (e) {}
        }
        return _typingAudioCtx;
    }

    function getNotificationAudioContext() {
        if (!_notificationAudioCtx) {
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (AC) _notificationAudioCtx = new AC();
            } catch (e) {}
        }
        return _notificationAudioCtx;
    }

    // ============================================================
    // Typing Sound - Short keyboard click simulation
    // ============================================================
    /**
     * Play typing sound (keyboard click simulation)
     * @param {Object} options - Configuration options
     * @param {boolean} options.enabled - Whether sound is enabled (from settings)
     * @param {number} options.gain - Initial gain value (default: 0.06)
     * @param {number} options.duration - Duration in seconds (default: 0.04)
     */
    function playTypingSound(options = {}) {
        const opts = {
            enabled: true,
            gain: 0.06,
            duration: 0.04,
            ...options
        };

        if (!opts.enabled) return;

        const ctx = getTypingAudioContext();
        if (!ctx) return;

        try {
            const now = ctx.currentTime;

            // Short filtered noise burst for a natural keyboard click
            const bufferSize = Math.floor(ctx.sampleRate * opts.duration);
            const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
            const data = buffer.getChannelData(0);

            for (let i = 0; i < bufferSize; i++) {
                data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (ctx.sampleRate * 0.008));
            }

            const noise = ctx.createBufferSource();
            noise.buffer = buffer;

            const filter = ctx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.frequency.value = 1800;
            filter.Q.value = 1.5;

            const gain = ctx.createGain();
            gain.gain.setValueAtTime(opts.gain, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + opts.duration);

            noise.connect(filter);
            filter.connect(gain);
            gain.connect(ctx.destination);
            noise.start(now);
            noise.stop(now + opts.duration);
        } catch (e) {
            // Silently fail - audio may not be available
        }
    }

    // ============================================================
    // Notification Sound - More prominent notification
    // ============================================================
    /**
     * Play notification sound (for new messages)
     * @param {Object} options - Configuration options
     * @param {boolean} options.enabled - Whether sound is enabled (from settings)
     */
    function playNotificationSound(options = {}) {
        const opts = {
            enabled: true,
            ...options
        };

        if (!opts.enabled) return;

        const ctx = getNotificationAudioContext();
        if (!ctx) return;

        try {
            const now = ctx.currentTime;

            // Two-tone notification: 600Hz -> 900Hz
            const osc1 = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            const gain = ctx.createGain();

            osc1.type = 'sine';
            osc2.type = 'sine';
            osc1.frequency.value = 600;
            osc2.frequency.value = 900;

            gain.gain.setValueAtTime(0, now);
            gain.gain.linearRampToValueAtTime(0.15, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);

            osc1.connect(gain);
            osc2.connect(gain);
            gain.connect(ctx.destination);

            osc1.start(now);
            osc2.start(now + 0.08);
            osc1.stop(now + 0.3);
            osc2.stop(now + 0.5);
        } catch (e) {
            // Silently fail
        }
    }

    // ============================================================
    // State Management for Typing Sound (prevents repeated playing)
    // ============================================================
    const typingSoundState = {
        visitor: {
            wasTyping: false,
            reset() { this.wasTyping = false; }
        },
        admin: {
            wasTyping: false,
            reset() { this.wasTyping = false; }
        }
    };

    /**
     * Check if typing sound should play (transition from not typing to typing)
     * @param {'visitor'|'admin'} side - Which side is typing
     * @param {boolean} isTyping - Current typing state
     * @param {Object} options - Sound options (enabled, etc.)
     * @returns {boolean} Whether sound was played
     */
    function checkAndPlayTypingSound(side, isTyping, options = {}) {
        const state = typingSoundState[side];
        if (!state) return false;

        const wasTyping = state.wasTyping;
        state.wasTyping = isTyping;

        // Play sound only on transition from not typing to typing
        if (isTyping && !wasTyping) {
            playTypingSound(options);
            return true;
        }
        return false;
    }

    /**
     * Reset typing sound state for a side (e.g., when chat closes)
     * @param {'visitor'|'admin'} side
     */
    function resetTypingSoundState(side) {
        if (typingSoundState[side]) {
            typingSoundState[side].reset();
        }
    }

    // ============================================================
    // Public API
    // ============================================================
    window.ChatTypingSound = {
        playTypingSound,
        playNotificationSound,
        checkAndPlayTypingSound,
        resetTypingSoundState,
        getTypingAudioContext,
        getNotificationAudioContext
    };

    // Also expose for module systems
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = window.ChatTypingSound;
    }
})();