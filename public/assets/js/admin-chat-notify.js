/**
 * admin-chat-notify.js
 * Global live chat notification system for admins.
 * Polls for new visitor messages and shows Facebook-style notifications.
 * Works on ALL admin pages when the admin is logged in with 'manage_chat' permission.
 */
(function () {
  'use strict';

  // ========================
  // Configuration
  // ========================
  var CONFIG = {
    pollInterval: 12000,          // 12 seconds
    notifySoundUrl: null,         // We'll use Web Audio API
    chatAdminUrl: '/chat/admin',
  };

  // ========================
  // State
  // ========================
  var state = {
    previousCount: -1,
    previousSessionId: '',
    pollTimer: null,
    notificationPermission: false,
    sidebarChatLink: null,
    sidebarBadge: null,
    fab: null,
    fabBadge: null,
    latestSessionId: '',
    lastNotifyTime: 0,
    // Admin notification preferences (fetched from API)
    notifySettings: null,
  };

  // ========================
  // Notification Preferences
  // ========================
  function isNotifySoundEnabled() {
    // Default to enabled if settings haven't loaded yet
    if (!state.notifySettings) return true;
    return state.notifySettings.sound === '1';
  }

  function isNotifyDesktopEnabled() {
    if (!state.notifySettings) return true;
    return state.notifySettings.desktop === '1';
  }

  function isNotifyToastEnabled() {
    if (!state.notifySettings) return true;
    return state.notifySettings.toast === '1';
  }

  // ========================
  // DOM caching
  // ========================
  var els = {};

  function cacheDOMElements() {
    els.sidebarChatLink = document.querySelector('.sidebar a[href="/chat/admin"]');
    els.sidebarNavText = els.sidebarChatLink
      ? els.sidebarChatLink.querySelector('.nav-text')
      : null;
  }

  function openAdminConversation(sessionId) {
    var target = CONFIG.chatAdminUrl;
    if (sessionId) target += '?session_id=' + encodeURIComponent(sessionId);
    window.focus();
    window.location.href = target;
  }

  function ensureAdminFab() {
    if (document.getElementById('chatAdminFab')) {
      els.fab = document.getElementById('chatAdminFab');
      els.fabBadge = document.getElementById('chatAdminFabBadge');
      return;
    }
    var fab = document.createElement('button');
    fab.type = 'button';
    fab.id = 'chatAdminFab';
    fab.className = 'chat-admin-fab';
    fab.setAttribute('aria-label', 'Open live chat conversations');
    fab.setAttribute('title', 'Live chat');
    fab.innerHTML = '<i class="fas fa-comments"></i><span id="chatAdminFabBadge" class="chat-admin-fab-badge" aria-live="polite">0</span>';
    fab.addEventListener('click', function () {
      openAdminConversation(state.latestSessionId || '');
    });
    document.body.appendChild(fab);
    els.fab = fab;
    els.fabBadge = document.getElementById('chatAdminFabBadge');
  }

  function updateAdminFab(count, sessionId) {
    if (!els.fab) ensureAdminFab();
    if (sessionId !== undefined) state.latestSessionId = sessionId;
    if (!els.fabBadge) return;
    if (count > 0) {
      els.fabBadge.textContent = count > 99 ? '99+' : String(count);
      els.fabBadge.classList.add('visible');
      els.fab.classList.add('has-unread');
    } else {
      els.fabBadge.classList.remove('visible');
      els.fab.classList.remove('has-unread');
    }
  }

  // ========================
  // Notification Permission
  // ========================
  function requestNotificationPermission() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
      state.notificationPermission = true;
      return;
    }
    if (Notification.permission === 'denied') return;
    Notification.requestPermission().then(function (perm) {
      state.notificationPermission = perm === 'granted';
    }).catch(function () {});
  }

  // ========================
  // Notification Sound (Web Audio API)
  // ========================
  var _audioCtx = null;

  function getAudioContext() {
    if (_audioCtx) return _audioCtx;
    var Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return null;
    _audioCtx = new Ctor();
    return _audioCtx;
  }

  function playNotificationSound() {
    var ctx = getAudioContext();
    if (!ctx) return;

    try {
      // Pleasant two-tone chime
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.type = 'sine';
      osc.frequency.setValueAtTime(523.25, ctx.currentTime);       // C5
      osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.12); // E5

      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);

      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.3);
    } catch (e) {
      // Silently fail
    }
  }

  // ========================
  // Desktop Notification
  // ========================
  function showDesktopNotification(title, body, sessionId) {
    if (!('Notification' in window)) return;
    if (!state.notificationPermission && Notification.permission !== 'granted') return;
    state.notificationPermission = Notification.permission === 'granted';
    if (!state.notificationPermission) return;

    try {
      var notif = new Notification(title || 'নতুন বার্তা', {
        body: body || '',
        icon: (document.querySelector('link[rel="shortcut icon"]') || {}).href || undefined,
        silent: true, // Our own sound plays separately
        tag: 'chat-notify-' + sessionId, // Group notifications by session
      });

      notif.addEventListener('click', function () {
        window.focus();
        window.location.href = CONFIG.chatAdminUrl;
        this.close();
      });

      // Auto-close after 8 seconds
      setTimeout(function () { notif.close(); }, 8000);
    } catch (e) {
      // Silently fail
    }
  }

  // ========================
  // In-page Toast Notification (Facebook-style)
  // ========================
  function showToastNotification(title, message, sessionId) {
    // Remove existing toast if it exists
    var existing = document.getElementById('chatAdminToast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = 'chatAdminToast';
    toast.className = 'chat-admin-toast';

    // Toast content
    toast.innerHTML =
      '<div class="chat-admin-toast-inner">' +
        '<div class="chat-admin-toast-avatar">' +
          '<i class="fas fa-comment-dots"></i>' +
        '</div>' +
        '<div class="chat-admin-toast-content">' +
          '<div class="chat-admin-toast-title">' + escapeAttr(title) + '</div>' +
          '<div class="chat-admin-toast-message">' + escapeAttr(message) + '</div>' +
        '</div>' +
        '<button class="chat-admin-toast-close" aria-label="Close">&times;</button>' +
      '</div>';

    // Click to open chat admin
    toast.addEventListener('click', function (e) {
      if (e.target.closest('.chat-admin-toast-close')) {
        toast.remove();
        return;
      }
      window.focus();
      openAdminConversation(sessionId);
    });

    document.body.appendChild(toast);

    // Trigger enter animation
    requestAnimationFrame(function () {
      toast.classList.add('open');
    });

    // Auto-remove after 6 seconds
    var autoRemoveTimer = setTimeout(function () {
      if (toast && toast.parentNode) {
        toast.classList.remove('open');
        setTimeout(function () { if (toast.parentNode) toast.remove(); }, 300);
      }
    }, 6000);

    // Close button handler
    toast.querySelector('.chat-admin-toast-close').addEventListener('click', function (e) {
      e.stopPropagation();
      clearTimeout(autoRemoveTimer);
      toast.classList.remove('open');
      setTimeout(function () { if (toast.parentNode) toast.remove(); }, 300);
    });
  }

  // ========================
  // Sidebar Badge Update
  // ========================
  function updateSidebarBadge(count) {
    if (!els.sidebarChatLink) {
      // Re-cache in case sidebar was dynamically loaded
      els.sidebarChatLink = document.querySelector('.sidebar a[href="/chat/admin"]');
      els.sidebarNavText = els.sidebarChatLink
        ? els.sidebarChatLink.querySelector('.nav-text')
        : null;
    }

    if (!els.sidebarChatLink) return;

    // Remove existing badge
    var existing = els.sidebarChatLink.querySelector('.chat-sidebar-badge');
    if (existing) existing.remove();

    if (count > 0) {
      var badge = document.createElement('span');
      badge.className = 'chat-sidebar-badge';
      badge.textContent = count > 99 ? '99+' : count;
      // Insert after the nav text
      if (els.sidebarNavText) {
        els.sidebarNavText.parentNode.insertBefore(badge, els.sidebarNavText.nextSibling);
      } else {
        els.sidebarChatLink.appendChild(badge);
      }
    }
  }

  // ========================
  // Favicon Badge
  // ========================
  var _faviconData = {
    link: null,
    canvas: null,
    originalHref: null,
  };

  function getFaviconLink() {
    if (_faviconData.link) return _faviconData.link;
    var links = document.querySelectorAll('link[rel*="icon"]');
    for (var i = 0; i < links.length; i++) {
      if (links[i].getAttribute('href')) {
        _faviconData.link = links[i];
        _faviconData.originalHref = links[i].getAttribute('href');
        return _faviconData.link;
      }
    }
    // Fallback: create one
    var link = document.createElement('link');
    link.rel = 'shortcut icon';
    document.head.appendChild(link);
    _faviconData.link = link;
    return link;
  }

  function updateFaviconBadge(count) {
    if (count <= 0) {
      restoreFavicon();
      return;
    }

    var link = getFaviconLink();
    if (!link) return;

    if (!_faviconData.canvas) {
      _faviconData.canvas = document.createElement('canvas');
    }
    var canvas = _faviconData.canvas;
    canvas.width = 32;
    canvas.height = 32;
    var ctx = canvas.getContext('2d');

    // Draw the original favicon first
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.addEventListener('load', function () {
      ctx.clearRect(0, 0, 32, 32);
      ctx.drawImage(img, 0, 0, 32, 32);

      // Draw red badge circle
      ctx.beginPath();
      ctx.arc(26, 6, 8, 0, Math.PI * 2);
      ctx.fillStyle = '#e74c3c';
      ctx.fill();
      ctx.strokeStyle = '#fff';
      ctx.lineWidth = 1.5;
      ctx.stroke();

      // Draw count text
      ctx.fillStyle = '#fff';
      ctx.font = 'bold 10px Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      var text = count > 9 ? '9+' : String(count);
      ctx.fillText(text, 26, 6);

      // Only update if badge is still needed
      if (count > 0) {
        link.setAttribute('href', canvas.toDataURL('image/png'));
      }
    });
    img.addEventListener('error', function () {
      // If favicon can't be loaded, just draw the badge on a default bg
      ctx.clearRect(0, 0, 32, 32);
      ctx.fillStyle = '#008B8B';
      ctx.fillRect(0, 0, 32, 32);
      ctx.fillStyle = '#fff';
      ctx.font = 'bold 12px Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText('CHAT', 16, 14);

      ctx.beginPath();
      ctx.arc(26, 6, 8, 0, Math.PI * 2);
      ctx.fillStyle = '#e74c3c';
      ctx.fill();
      ctx.strokeStyle = '#fff';
      ctx.lineWidth = 1.5;
      ctx.stroke();

      ctx.fillStyle = '#fff';
      ctx.font = 'bold 10px Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      var text = count > 9 ? '9+' : String(count);
      ctx.fillText(text, 26, 6);

      if (count > 0) {
        link.setAttribute('href', canvas.toDataURL('image/png'));
      }
    });
    img.src = _faviconData.originalHref || (link.getAttribute('href') || '');
  }

  function restoreFavicon() {
    if (_faviconData.link && _faviconData.originalHref) {
      _faviconData.link.setAttribute('href', _faviconData.originalHref);
    }
  }

  // ========================
  // Tab Title Notification
  // ========================
  var _originalTitle = document.title;
  var _titleInterval = null;
  var _currentTitleCount = 0;

  function startTitleNotification(count) {
    if (count <= 0) {
      stopTitleNotification();
      return;
    }
    _currentTitleCount = count;
    if (_titleInterval) return;

    _originalTitle = document.title;

    _titleInterval = setInterval(function () {
      if (_currentTitleCount > 0) {
        var prefix = '\uD83D\uDD14 (' + (_currentTitleCount > 9 ? '9+' : _currentTitleCount) + ') ';
        document.title = prefix + 'নতুন বার্তা';
      } else {
        stopTitleNotification();
      }
    }, 1000);
  }

  function stopTitleNotification() {
    if (_titleInterval) {
      clearInterval(_titleInterval);
      _titleInterval = null;
    }
    document.title = _originalTitle;
  }

  // ========================
  // Helpers
  // ========================
  function escapeAttr(text) {
    if (typeof text !== 'string') return '';
    return text.replace(/&/g, '&amp;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function truncate(str, len) {
    if (!str) return '';
    if (str.length <= len) return str;
    return str.substring(0, len) + '...';
  }

  function formatMessagePreview(msg) {
    if (!msg) return '';
    if (msg.message_type === 'text') {
      return truncate(msg.message, 80);
    }
    if (msg.message_type === 'image') return 'একটি ছবি পাঠানো হয়েছে';
    return 'একটি ফাইল পাঠানো হয়েছে';
  }

  // ========================
  // Main Polling Logic
  // ========================
  function checkForNewMessages() {
    fetch('/api/chat/admin/unread/total')
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.status !== 'success') return;

        var total = parseInt(data.data.total) || 0;
        var latest = data.data.latest;
        var latestSessionId = latest && latest.session_id ? latest.session_id : '';

        // Store admin notification preferences from API response
        if (data.data.notify_settings) {
          state.notifySettings = data.data.notify_settings;
        }

        // Update sidebar badge
        updateSidebarBadge(total);
        updateAdminFab(total, latestSessionId);

        // Update favicon badge
        updateFaviconBadge(total);

        // Update tab title
        if (document.hidden) {
          startTitleNotification(total);
        } else {
          stopTitleNotification();
        }

        // Check if there's a NEW message (count increased OR new session)
        var isNewMessage = false;
        if (state.previousCount >= 0 && total > state.previousCount) {
          isNewMessage = true;
        } else if (state.previousCount === -1) {
          // First check — just store the count, no notification
          state.previousCount = total;
          if (latest) state.previousSessionId = latest.session_id || '';
          return;
        }

        state.previousCount = total;

        if (isNewMessage && latest) {
          var visitorName = latest.visitor_name || 'অজ্ঞাত দর্শক';
          var preview = formatMessagePreview(latest);
          var sessId = latest.session_id || '';

          // Play notification sound (respect admin preference)
          if (isNotifySoundEnabled()) {
            playNotificationSound();
          }

          // Show in-page toast (respect admin preference)
          if (isNotifyToastEnabled()) {
            showToastNotification(visitorName, preview, sessId);
          }

          // Show desktop notification (only when tab is hidden, respect admin preference)
          if (document.hidden && isNotifyDesktopEnabled()) {
            showDesktopNotification(
              visitorName + ' (' + total + ' টি আনরিড)',
              preview,
              sessId
            );
          }
        }

        // Update session ID tracker for next poll
        if (latest && latest.session_id) {
          state.previousSessionId = latest.session_id;
        }
      })
      .catch(function (err) {
        // Silently fail — don't spam console on network errors
      });
  }

  // ========================
  // Start/Stop Polling
  // ========================
  function startPolling() {
    if (state.pollTimer) return;
    state.pollTimer = setInterval(checkForNewMessages, CONFIG.pollInterval);
    // Also do an immediate check
    checkForNewMessages();
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  // ========================
  // Reset unread state (when navigating to chat admin page)
  // ========================
  function resetUnreadState() {
    state.previousCount = 0;
    state.previousSessionId = '';
    updateSidebarBadge(0);
    updateAdminFab(0, '');
    updateFaviconBadge(0);
    stopTitleNotification();
  }

  // ========================
  // Handle visibility change
  // ========================
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      // Tab became visible — do an immediate check
      checkForNewMessages();
    }
  });

  // ========================
  // Initialize
  // ========================
  function init() {
    // Only run if this is an admin page with sidebar (logged in)
    var sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;

    // Check if there's a chat admin link in sidebar (i.e., user has manage_chat permission)
    var chatLink = sidebar.querySelector('a[href="/chat/admin"]');
    if (!chatLink) return;

    cacheDOMElements();
    ensureAdminFab();

    // Request notification permission on first interaction
    var requestPermHandler = function () {
      requestNotificationPermission();
      document.removeEventListener('click', requestPermHandler);
      document.removeEventListener('touchstart', requestPermHandler);
    };
    document.addEventListener('click', requestPermHandler);
    document.addEventListener('touchstart', requestPermHandler);

    // Keep global unread state synchronized on every admin page.
    startPolling();
    // If we're NOT on the chat admin page, start polling
    if (window.location.pathname !== CONFIG.chatAdminUrl &&
        window.location.pathname !== CONFIG.chatAdminUrl + '/') {
      startPolling();
    } else {
      // We're on the chat admin page — reset badge
      // Keep the unread FAB visible on the admin conversation page.
    }

    // When the user navigates to the admin chat page, reset the badge
    // This handles SPA-like anchor clicks (common in sidebar navigation)
    document.addEventListener('click', function (e) {
      var link = e.target.closest('a');
      if (link && link.getAttribute('href') === CONFIG.chatAdminUrl) {
        // User is clicking the chat link — we'll reset after a short delay
        // to let the page load
        setTimeout(resetUnreadState, 500);
        stopPolling();
      }
    });

    // Cleanup on page unload
    window.addEventListener('beforeunload', function () {
      stopPolling();
      stopTitleNotification();
    });
  }

  // Run after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
