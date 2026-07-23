/**
 * Custom Live Chat Widget — Vanilla JS
 * Smart Union Parishad Portal
 * Features: Emoji picker, File upload, Read status, Chat history, Typing indicator
 *
 * UTF-8 SAFE: All Bengali text is set via textContent, NOT innerHTML,
 * to prevent Unicode encoding corruption.
 */

(function () {
  'use strict';

  // ========================
  // CSRF Token
  // ========================
  function getCsrfToken() {
    if (window.getCsrfToken) return window.getCsrfToken();
    const meta = document.querySelector('meta[name="csrf_token"]');
    return meta ? meta.getAttribute('content') : null;
  }

  function addCsrfHeader(headers) {
    const token = getCsrfToken();
    if (token) {
      headers['X-CSRF-TOKEN'] = token;
      headers['X-Requested-With'] = 'XMLHttpRequest';
    }
    return headers;
  }

  // ========================
  // Configuration
  // ========================
  const CONFIG = {
    pollInterval: 4000,
    sessionKey: 'chat_session_id',
    sigKey: 'chat_session_sig',
    nameKey: 'chat_visitor_name',
    unionKey: 'chat_visitor_union',
    apiBase: '/api/chat',
    pageSize: 50,
    maxFileSize: 10 * 1024 * 1024, // 10MB
  };

  // ========================
  // State
  // ========================
  let state = {
    sessionId: localStorage.getItem(CONFIG.sessionKey) || null,
    sessionSig: localStorage.getItem(CONFIG.sigKey) || '',
    visitorName: localStorage.getItem(CONFIG.nameKey) || '',
    visitorUnion: localStorage.getItem(CONFIG.unionKey) || '',
    isOpen: false,
    lastMessageTime: null,
    pollTimer: null,
    hasMoreHistory: false,
    historyOffset: 0,
    isLoadingHistory: false,
    pendingFile: null,
    sentMessageIds: new Set(),
    seenMessageIds: new Set(),
  };

  // ========================
  // DOM References
  // ========================
  let els = {};

  // ========================
  // Utility
  // ========================
  function generateId() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = Math.random() * 16 | 0;
      const v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function formatTime(dateStr) {
    const d = new Date(dateStr);
    const now = new Date();
    const hrs = d.getHours().toString().padStart(2, '0');
    const mins = d.getMinutes().toString().padStart(2, '0');
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const diffDays = Math.floor((today - msgDate) / (1000 * 60 * 60 * 24));

    if (diffDays === 0) return hrs + ':' + mins;
    if (diffDays === 1) return '\u0997\u09a4\u0995\u09be\u09b2 ' + hrs + ':' + mins;
    return d.getDate().toString().padStart(2, '0') + '/' + (d.getMonth() + 1).toString().padStart(2, '0') + ' ' + hrs + ':' + mins;
  }

  // ========================
  // DOMPurify XSS Sanitization
  // ========================
  function sanitizeHTML(text) {
    if (typeof text !== 'string') return '';
    if (typeof DOMPurify !== 'undefined' && DOMPurify.sanitize) {
      return DOMPurify.sanitize(text, { ALLOWED_TAGS: [], ALLOWED_ATTR: [] });
    }
    // Fallback: strip HTML tags if DOMPurify not loaded
    return text.replace(/<[^>]*>/g, '');
  }

  // Escape text for safe use in HTML attribute values only.
  // For visible text, always use textContent.
  function escapeAttr(text) {
    if (typeof text !== 'string') return '';
    return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function getFileIcon(mimeType) {
    if (!mimeType) return 'fa-file';
    if (mimeType.startsWith('image/')) return 'fa-file-image';
    if (mimeType.includes('pdf')) return 'fa-file-pdf';
    if (mimeType.includes('word') || mimeType.includes('document')) return 'fa-file-word';
    if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'fa-file-excel';
    if (mimeType.includes('zip') || mimeType.includes('rar')) return 'fa-file-archive';
    if (mimeType.includes('text')) return 'fa-file-alt';
    return 'fa-file';
  }

  // Helper: create text element
  function createTextEl(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined && text !== null) el.textContent = text;
    return el;
  }

  // Helper: create icon element (innerHTML safe since icons are trusted)
  function createIcon(className) {
    const span = document.createElement('span');
    span.innerHTML = '<i class="' + className + '"></i>';
    return span;
  }

  // Common emojis for picker
  const EMOJIS = [
    '\uD83D\uDE00', '\uD83D\uDE03', '\uD83D\uDE04', '\uD83D\uDE01', '\uD83D\uDE05', '\uD83D\uDE02', '\uD83E\uDD23',
    '\uD83D\uDE0A', '\uD83D\uDE07', '\uD83D\uDE42', '\uD83D\uDE09', '\uD83D\uDE0C', '\uD83D\uDE0D', '\uD83E\uDD70',
    '\uD83D\uDE18', '\uD83D\uDE17', '\uD83D\uDE19', '\uD83D\uDE1A', '\uD83E\uDD17', '\uD83E\uDD29', '\uD83E\uDD14',
    '\uD83E\uDD28', '\uD83D\uDE10', '\uD83D\uDE11', '\uD83D\uDE36', '\uD83D\uDE44', '\uD83D\uDE0F', '\uD83D\uDE23',
    '\uD83D\uDE25', '\uD83D\uDE2E', '\uD83E\uDD10', '\uD83D\uDE2F', '\uD83D\uDE2A', '\uD83D\uDE2B', '\uD83D\uDE34',
    '\uD83D\uDE24', '\uD83D\uDE21', '\uD83E\uDD2C', '\uD83D\uDE08', '\uD83D\uDC7F', '\uD83D\uDC80', '\u2620\uFE0F',
    '\uD83D\uDCA9', '\uD83E\uDD21', '\uD83D\uDC79', '\uD83D\uDC7A', '\uD83D\uDC7B', '\uD83D\uDC7D', '\uD83D\uDC7E',
    '\uD83D\uDC4D', '\uD83D\uDC4E', '\uD83D\uDC4A', '\u270A', '\uD83E\uDD1B', '\uD83E\uDD1C', '\uD83D\uDC4F',
    '\uD83D\uDE4C', '\uD83D\uDC50', '\uD83E\uDD32', '\uD83E\uDD1D', '\uD83D\uDE4F', '\u270D\uFE0F', '\uD83D\uDC85',
    '\u2764\uFE0F', '\uD83E\uDDE1', '\uD83D\uDC9B', '\uD83D\uDC9A', '\uD83D\uDC99', '\uD83D\uDC9C', '\uD83D\uDDA4',
    '\uD83D\uDC94', '\u2763\uFE0F', '\uD83D\uDC95', '\uD83D\uDC9E', '\uD83D\uDC93', '\uD83D\uDC97', '\uD83D\uDC96',
    '\u2728', '\uD83C\uDF1F', '\u2B50', '\uD83C\uDF20', '\uD83D\uDD25', '\uD83D\uDCAF', '\u2705',
  ];

  // ========================
  // File Preview
  // ========================
  function isPreviewableFile(msg) {
    if (!msg || !msg.file_url) return false;
    if (msg.message_type === 'image') return false; // Already shown inline
    const type = (msg.file_type || '').toLowerCase();
    return type.includes('pdf') ||
      type.includes('word') || type.includes('document') ||
      type.includes('excel') || type.includes('spreadsheet') ||
      type.includes('presentation') || type.includes('powerpoint') ||
      type.includes('text/plain') || type.includes('text/csv');
  }

  function getPreviewUrl(fileUrl, fileType) {
    const type = (fileType || '').toLowerCase();
    // PDF — use native browser PDF viewer
    if (type.includes('pdf')) return fileUrl;
    // Office documents — use Google Docs Viewer
    if (type.includes('word') || type.includes('document') ||
        type.includes('excel') || type.includes('spreadsheet') ||
        type.includes('presentation') || type.includes('powerpoint')) {
      return 'https://docs.google.com/viewer?url=' + encodeURIComponent(fileUrl) + '&embedded=true';
    }
    return null;
  }

  function showFilePreview(msg) {
    if (!msg || !msg.file_url) return;

    // Remove existing preview if any
    const existing = document.getElementById('chatFilePreview');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.className = 'chat-preview-overlay';
    overlay.id = 'chatFilePreview';

    const container = document.createElement('div');
    container.className = 'chat-preview-container';

    // Header
    const header = document.createElement('div');
    header.className = 'chat-preview-header';

    const title = document.createElement('span');
    title.className = 'chat-preview-title';
    title.textContent = msg.file_name || 'File Preview';
    header.appendChild(title);

    const closeBtn = document.createElement('button');
    closeBtn.className = 'chat-preview-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.setAttribute('aria-label', 'Close preview');
    closeBtn.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(closeBtn);
    container.appendChild(header);

    // Body
    const body = document.createElement('div');
    body.className = 'chat-preview-body';

    const type = (msg.file_type || '').toLowerCase();
    if (type.includes('text/plain') || type.includes('text/csv')) {
      // Fetch and display plain text
      const pre = document.createElement('pre');
      pre.className = 'chat-preview-text';
      pre.textContent = '\u09b2\u09cb\u09a1 \u09b9\u099a\u09cd\u099b\u09c7...';
      body.appendChild(pre);
      container.appendChild(body);
      overlay.appendChild(container);
      document.body.appendChild(overlay);

      fetch(msg.file_url)
        .then(function (r) { return r.text(); })
        .then(function (txt) { pre.textContent = txt; })
        .catch(function () { pre.textContent = '\u09ab\u09be\u0987\u09b2 \u09b2\u09cb\u09a1 \u0995\u09b0\u09be \u09af\u09be\u09df\u09a8\u09bf\u0964'; });
      return;
    }

    const previewUrl = getPreviewUrl(msg.file_url, msg.file_type);

    if (type.includes('pdf')) {
      const embed = document.createElement('embed');
      embed.src = previewUrl;
      embed.type = 'application/pdf';
      embed.className = 'chat-preview-embed';
      body.appendChild(embed);
    } else if (previewUrl) {
      const iframe = document.createElement('iframe');
      iframe.src = previewUrl;
      iframe.className = 'chat-preview-embed';
      iframe.setAttribute('frameborder', '0');
      iframe.setAttribute('allowfullscreen', 'true');
      body.appendChild(iframe);
    } else {
      body.appendChild(createTextEl('p', 'chat-preview-error', '\u09aa\u09cd\u09b0\u09bf\u09ad\u09bf\u0989 \u09b8\u09ae\u09b0\u09cd\u09a5\u09bf\u09a4 \u09a8\u09df\u0964'));
    }

    container.appendChild(body);
    overlay.appendChild(container);
    document.body.appendChild(overlay);

    // Close on overlay click
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.remove();
    });

    // Close on Escape
    function onKeydown(e) {
      if (e.key === 'Escape') {
        overlay.remove();
        document.removeEventListener('keydown', onKeydown);
      }
    }
    document.addEventListener('keydown', onKeydown);
  }

  // ========================
  // Clipboard
  // ========================
  function copyToClipboard(text) {
    if (!text) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        showCopyFeedback(true);
      }).catch(function () {
        fallbackCopy(text);
      });
    } else {
      fallbackCopy(text);
    }
  }

  function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '-9999px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      showCopyFeedback(true);
    } catch (e) {
      showCopyFeedback(false);
    }
    document.body.removeChild(ta);
  }

  let _copyFeedbackTimer = null;

  function showCopyFeedback(success) {
    if (!els.messages) return;

    const existing = els.messages.querySelector('.chat-copy-feedback');
    if (existing) existing.remove();

    const feedback = document.createElement('div');
    feedback.className = 'chat-copy-feedback';
    feedback.textContent = success
      ? '\u0995\u09aa\u09bf \u0995\u09b0\u09be \u09b9\u09df\u09c7\u099b\u09c7'
      : '\u0995\u09aa\u09bf \u0995\u09b0\u09be \u09af\u09be\u09df\u09a8\u09bf';
    feedback.style.cssText = 'position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); background: #333; color: #fff; padding: 8px 16px; border-radius: 8px; font-size: 13px; z-index: 10001; opacity: 0; transition: opacity 0.3s;';
    document.body.appendChild(feedback);

    requestAnimationFrame(function () {
      feedback.style.opacity = '1';
    });

    if (_copyFeedbackTimer) clearTimeout(_copyFeedbackTimer);
    _copyFeedbackTimer = setTimeout(function () {
      feedback.style.opacity = '0';
      setTimeout(function () { feedback.remove(); }, 300);
    }, 1500);
  }

  // ========================
  // API Calls
  // ========================
  // ========================
  // Session Management
  // ========================
  function resetSession() {
    // Clear old session data
    state.sessionId = generateId();
    state.sessionSig = '';
    state.lastMessageTime = null;
    state.hasMoreHistory = false;
    state.historyOffset = 0;
    state.sentMessageIds = new Set();

    // Persist to localStorage
    localStorage.setItem(CONFIG.sessionKey, state.sessionId);
    localStorage.removeItem(CONFIG.sigKey);
  }

  function showSessionExpiredNotice() {
    if (!els.messages) return;

    // Remove any existing notice
    const existing = els.messages.querySelector('.chat-expired-notice');
    if (existing) existing.remove();

    // Remove welcome message to replace it
    const welcome = els.messages.querySelector('.chat-welcome');
    if (welcome) welcome.remove();

    const notice = document.createElement('div');
    notice.className = 'chat-expired-notice';

    const icon = document.createElement('span');
    icon.textContent = '\u23F3';
    icon.style.cssText = 'font-size: 32px; display: block; margin-bottom: 8px;';
    notice.appendChild(icon);

    const title = document.createElement('div');
    title.textContent = '\u0986\u09aa\u09a8\u09be\u09b0 \u0986\u0997\u09c7\u09b0 \u09b8\u09be\u09a4\u09cd\u09b8\u09a8\u09c7\u09b0 \u09ae\u09bf\u09af\u09bc\u09be\u09a6 \u09ab\u09c1\u09b0\u09c1 \u09b9\u09df\u09c7\u099b\u09c7'; // Your previous session has expired
    title.className = 'chat-welcome-title';
    notice.appendChild(title);

    const msg = document.createElement('div');
    msg.textContent = '\u0986\u09aa\u09a8\u09be\u09b0 \u09b8\u09c1\u09ac\u09bf\u09a7\u09be\u09b0 \u099c\u09a8\u09cd\u09af \u098f\u0995\u099f\u09bf \u09a8\u09a4\u09c1\u09a8 \u09b8\u09be\u09a4\u09cd\u09b8\u09a8 \u09b6\u09c1\u09b0\u09c1 \u0995\u09b0\u09be \u09b9\u09df\u09c7\u099b\u09c7\u0964 \u0986\u09aa\u09a8\u09bf \u098f\u0996\u09a8 \u0986\u09ac\u09be\u09b0 \u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09aa\u09be\u09a0\u09be\u09a4\u09c7 \u09aa\u09be\u09b0\u09c7\u09a8\u0964';
    notice.appendChild(msg);

    els.messages.appendChild(notice);

    // Auto-remove the notice after 5 seconds to show the welcome message
    setTimeout(function () {
      const n = els.messages.querySelector('.chat-expired-notice');
      if (n) n.remove();
      showWelcome();
    }, 5000);
  }

  async function apiCall(method, path, body) {
    const url = CONFIG.apiBase + path;
    const options = {
      method: method,
      headers: { 'Content-Type': 'application/json' },
    };
    if (body) options.body = JSON.stringify(body);

    try {
      const res = await fetch(url, options);
      const data = await res.json();
      // Auto-recover session signature from any API response
      if (data.session_sig) {
        state.sessionSig = data.session_sig;
        localStorage.setItem(CONFIG.sigKey, state.sessionSig);
      }
      // If session expired, auto-reset and start a fresh session
      if (data.session_expired) {
        resetSession();              // update state + localStorage
        showSessionExpiredNotice();  // handle UI (clears messages, shows notice, then shows welcome)
      }
      if (data.status === 'error') throw new Error(data.message || 'Unknown error');
      return data;
    } catch (err) {
      console.error('[Chat] API Error:', err);
      throw err;
    }
  }

  async function sendMessage(text) {
    if (!state.sessionId) {
      state.sessionId = generateId();
      localStorage.setItem(CONFIG.sessionKey, state.sessionId);
    }

    const payload = {
      session_id: state.sessionId,
      session_sig: state.sessionSig,
      message: text,
      visitor_name: state.visitorName || null,
      visitor_union_name: state.visitorUnion || null,
    };

    return await apiCall('POST', '/send', payload);
  }

  async function fetchMessages() {
    if (!state.sessionId) return { messages: [], has_more: false };

    let path = '/messages?session_id=' + encodeURIComponent(state.sessionId);
    if (state.sessionSig) path += '&session_sig=' + encodeURIComponent(state.sessionSig);
    if (state.lastMessageTime) {
      path += '&after=' + encodeURIComponent(state.lastMessageTime);
    }

    const result = await apiCall('GET', path);
    if (result.data && result.data.length > 0) {
      state.lastMessageTime = new Date().toISOString();
    }
    return { messages: result.data || [], has_more: result.has_more || false };
  }

  async function loadHistory(offset) {
    if (!state.sessionId) return { messages: [], has_more: false };

    let path = '/messages?session_id=' + encodeURIComponent(state.sessionId);
    if (state.sessionSig) path += '&session_sig=' + encodeURIComponent(state.sessionSig);
    path += '&offset=' + offset + '&limit=' + CONFIG.pageSize;
    const result = await apiCall('GET', path);
    return { messages: result.data || [], has_more: result.has_more || false };
  }

  async function uploadFile(file) {
    if (!state.sessionId) {
      state.sessionId = generateId();
      localStorage.setItem(CONFIG.sessionKey, state.sessionId);
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('session_id', state.sessionId);
    if (state.sessionSig) formData.append('session_sig', state.sessionSig);
    if (state.visitorName) formData.append('visitor_name', state.visitorName);
    if (state.visitorUnion) formData.append('visitor_union_name', state.visitorUnion);

    const res = await fetch(CONFIG.apiBase + '/upload', {
      method: 'POST',
      body: formData,
    });
    const result = await res.json();
    if (result.data && result.data.session_sig) {
      state.sessionSig = result.data.session_sig;
      localStorage.setItem(CONFIG.sigKey, state.sessionSig);
    }
    return result;
  }

  // ========================
  // Rendering (DOM-based, UTF-8 safe)
  // ========================
  function renderMessage(msg) {
    const div = document.createElement('div');
    div.className = 'chat-msg ' + (msg.sender_type === 'admin' ? 'admin' : 'visitor');
    div.setAttribute('data-msg-id', msg.id);
    div.setAttribute('data-sender', msg.sender_type);
    div.setAttribute('data-auto-reply', msg.auto_reply || 0);

    if (msg.sender_type === 'admin') {
      const senderRow = document.createElement('div');
      senderRow.className = 'chat-msg-sender-row';
      const sender = createTextEl('span', 'chat-msg-sender', widgetSettings.chat_agent_name || '\u09b8\u09b9\u09be\u09af\u09bc\u0995');
      senderRow.appendChild(sender);
      if (msg.auto_reply == 1) {
        const badge = document.createElement('span');
        badge.className = 'chat-msg-auto-reply-badge';
        badge.textContent = '\uD83E\uDD16 \u09b8\u09cd\u09ac\u099a\u09be\u09b2\u09bf\u09a4 \u0989\u09a4\u09cd\u09a4\u09b0';
        senderRow.appendChild(badge);
      }
      div.appendChild(senderRow);
    }

    if (msg.message_type === 'text' || !msg.message_type) {
      const cleanMsg = sanitizeHTML(msg.message);
      const textDiv = createTextEl('div', 'chat-msg-text', cleanMsg);
      div.appendChild(textDiv);
    }

    if (msg.file_url) {
      if (msg.message_type === 'image') {
        const link = document.createElement('a');
        link.href = msg.file_url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'chat-msg-image-link';
        const img = document.createElement('img');
        img.src = msg.file_url;
        img.className = 'chat-msg-image';
        img.alt = msg.file_name || 'Image';
        img.loading = 'lazy';
        link.appendChild(img);
        div.appendChild(link);
      } else {
        const fileLink = document.createElement('a');
        fileLink.href = msg.file_url;
        fileLink.target = '_blank';
        fileLink.rel = 'noopener noreferrer';
        fileLink.className = 'chat-msg-file';
        fileLink.setAttribute('download', msg.file_name || 'file');

        fileLink.appendChild(createIcon('fas ' + getFileIcon(msg.file_type)));

        const infoSpan = document.createElement('span');
        infoSpan.className = 'chat-msg-file-info';

        infoSpan.appendChild(createTextEl('span', 'chat-msg-file-name', msg.file_name || 'File'));

        if (msg.file_size) {
          infoSpan.appendChild(createTextEl('span', 'chat-msg-file-size', formatFileSize(parseInt(msg.file_size))));
        }

        fileLink.appendChild(infoSpan);
        div.appendChild(fileLink);

        // Preview button for previewable files
        if (isPreviewableFile(msg)) {
          const previewBtn = document.createElement('button');
          previewBtn.className = 'chat-msg-preview-btn';
          previewBtn.innerHTML = '<i class="fas fa-eye"></i> \u09aa\u09cd\u09b0\u09bf\u09ad\u09bf\u0989';
          previewBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            showFilePreview(msg);
          });
          div.appendChild(previewBtn);
        }
      }
    }

    if (msg.message_type === 'text' || !msg.message_type) {
      const copyBtn = document.createElement('button');
      copyBtn.className = 'chat-msg-copy';
      copyBtn.setAttribute('title', '\u0995\u09aa\u09bf \u0995\u09b0\u09c1\u09a8');
      copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
      copyBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        copyToClipboard(msg.message);
      });
      div.appendChild(copyBtn);
    }

    const footer = document.createElement('div');
    footer.className = 'chat-msg-footer';

    footer.appendChild(createTextEl('span', 'chat-msg-time', formatTime(msg.created_at)));

    if (msg.sender_type === 'visitor') {
      const statusSpan = document.createElement('span');
      statusSpan.className = 'chat-msg-status';
      if (msg.is_read == 1) {
        statusSpan.innerHTML = '<span class="status-icon status-read"><i class="fas fa-check-double"></i></span>';
      } else {
        statusSpan.innerHTML = '<span class="status-icon status-sent"><i class="fas fa-check"></i></span>';
      }
      footer.appendChild(statusSpan);
    }

    div.appendChild(footer);
    return div;
  }

  function scrollToBottom() {
    if (els.messages) {
      els.messages.scrollTop = els.messages.scrollHeight;
    }
  }

  // showRegistrationForm() removed — registration fields are now inline in the input area

  async function transitionToChat(name, union) {
    // Save to state & localStorage
    state.visitorName = name;
    state.visitorUnion = union || '';
    localStorage.setItem(CONFIG.nameKey, name);
    if (union) localStorage.setItem(CONFIG.unionKey, union);

    // Fade out registration input area
    if (els.regInputArea) {
      els.regInputArea.classList.add('fade-out');
    }

    // Update welcome message, load existing messages, and start polling
    showWelcome();
    await loadMessages();
    startPolling();

    // After fade-out completes, show normal chat input with fade-in
    setTimeout(function () {
      if (els.regInputArea) els.regInputArea.style.display = 'none';

      if (els.inputWrapper) {
        els.inputWrapper.style.display = '';
        els.inputWrapper.style.opacity = '0';
        els.inputWrapper.style.transition = 'opacity 0.3s ease';
        requestAnimationFrame(function () {
          if (els.inputWrapper) els.inputWrapper.style.opacity = '1';
        });
      }

      // Focus input after it appears
      setTimeout(function () {
        if (els.input) els.input.focus();
      }, 350);
    }, 280);
  }

  function showWelcome() {
    if (!els.messages) return;
    const isOfflineMode = isOffline();

    els.messages.innerHTML = '';

    const welcome = document.createElement('div');
    welcome.className = 'chat-welcome';

    const icon = createTextEl('span', 'chat-welcome-icon', '\uD83D\uDCAC');
    welcome.appendChild(icon);

    // Show visitor info greeting if name is available
    if (state.visitorName) {
      const greeting = document.createElement('div');
      greeting.className = 'chat-welcome-greeting';
      greeting.textContent = '\u0986\u09aa\u09a8\u09be\u0995\u09c7 \u09b8\u09cd\u09ac\u09be\u0997\u09a4\u09ae, ' + state.visitorName + '!';  // Welcome, {name}!
      welcome.appendChild(greeting);

      if (state.visitorUnion) {
        const unionInfo = document.createElement('div');
        unionInfo.className = 'chat-welcome-union';
        unionInfo.innerHTML = '<i class="fas fa-map-marker-alt"></i> ' + state.visitorUnion;
        welcome.appendChild(unionInfo);
      }
    }

    const title = createTextEl('div', 'chat-welcome-title', widgetSettings.chat_welcome_title || '\u09b8\u09be\u09b9\u09be\u09af\u09bc\u09cd\u09af \u09aa\u09cd\u09b0\u09af\u09bc\u09cb\u099c\u09a8?');
    welcome.appendChild(title);

    const msgText = document.createElement('div');
    msgText.textContent = isOfflineMode
      ? (widgetSettings.chat_offline_message || '\u0986\u09ae\u09b0\u09be \u09ac\u09b0\u09cd\u09a4\u09ae\u09be\u09a8\u09c7 \u0985\u09ab\u09b2\u09be\u0987\u09a8\u09c7 \u0986\u099b\u09bf\u0964')
      : (widgetSettings.chat_welcome_message || '\u0986\u09aa\u09a8\u09be\u09b0 \u09aa\u09cd\u09b0\u09b6\u09cd\u09a8 \u09b2\u09bf\u0996\u09c1\u09a8, \u0986\u09ae\u09b0\u09be \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be \u0995\u09b0\u09ac\u0964');
    welcome.appendChild(msgText);

    els.messages.appendChild(welcome);
  }

  function addMessages(messages, prepend) {
    if (!els.messages || !messages.length) return;

    const welcome = els.messages.querySelector('.chat-welcome');
    if (welcome) welcome.remove();

    if (prepend) {
      messages.forEach(function (msg) {
        if (els.messages.querySelector('[data-msg-id="' + msg.id + '"]')) return;
        const el = renderMessage(msg);
        const loadBtn = els.messages.querySelector('.chat-load-earlier');
        if (loadBtn) {
          loadBtn.after(el);
        } else {
          els.messages.insertBefore(el, els.messages.firstChild);
        }
      });
    } else {
      messages.forEach(function (msg) {
        if (els.messages.querySelector('[data-msg-id="' + msg.id + '"]')) return;
        els.messages.appendChild(renderMessage(msg));
      });
      scrollToBottom();
    }
  }

  function addLoadEarlierButton() {
    if (els.messages.querySelector('.chat-load-earlier')) return;

    const container = document.createElement('div');
    container.className = 'chat-load-earlier';

    const btn = document.createElement('button');
    btn.className = 'chat-load-earlier-btn';
    btn.id = 'chatLoadEarlier';
    btn.textContent = '\u0986\u0997\u09c7\u09b0 \u09ac\u09be\u09b0\u09cd\u09a4\u09be\u0997\u09c1\u09b2\u09cb \u09a6\u09c7\u0996\u09c1\u09a8';

    const iconSpan = document.createElement('span');
    iconSpan.innerHTML = '<i class="fas fa-chevron-up"></i> ';
    btn.insertBefore(iconSpan, btn.firstChild);

    container.appendChild(btn);
    els.messages.insertBefore(container, els.messages.firstChild);

    btn.addEventListener('click', loadEarlierMessages);
  }

  function removeLoadEarlierButton() {
    const btn = els.messages.querySelector('.chat-load-earlier');
    if (btn) btn.remove();
  }

  // ========================
  // Load Earlier Messages
  // ========================
  async function loadEarlierMessages() {
    if (state.isLoadingHistory || !state.hasMoreHistory) return;
    state.isLoadingHistory = true;

    const loadBtn = document.getElementById('chatLoadEarlier');
    if (loadBtn) {
      loadBtn.disabled = true;
      loadBtn.innerHTML = '<span><i class="fas fa-spinner fa-spin"></i> \u09b2\u09cb\u09a1 \u09b9\u099a\u09cd\u099b\u09c7...</span>';
    }

    try {
      const result = await loadHistory(state.historyOffset);
      if (result.messages.length > 0) {
        state.historyOffset += result.messages.length;
        state.hasMoreHistory = result.has_more;

        const prevScrollHeight = els.messages.scrollHeight;
        const prevScrollTop = els.messages.scrollTop;

        removeLoadEarlierButton();
        addMessages(result.messages, true);

        if (result.has_more) {
          addLoadEarlierButton();
          requestAnimationFrame(function () {
            els.messages.scrollTop = els.messages.scrollHeight - prevScrollHeight + prevScrollTop;
          });
        }
      }
    } catch (e) {
      console.error('[Chat] Failed to load history:', e);
    } finally {
      state.isLoadingHistory = false;
      if (loadBtn) {
        loadBtn.disabled = false;
        loadBtn.innerHTML = '\u0986\u0997\u09c7\u09b0 \u09ac\u09be\u09b0\u09cd\u09a4\u09be\u0997\u09c1\u09b2\u09cb \u09a6\u09c7\u0996\u09c1\u09a8';
      }
    }
  }

  // ========================
  // Emoji Picker
  // ========================
  function toggleEmojiPicker() {
    const picker = document.getElementById('chatEmojiPicker');
    if (!picker) return;
    picker.classList.toggle('open');
    if (els.emojiBtn) els.emojiBtn.classList.toggle('active');
  }

  function buildEmojiPicker() {
    const picker = document.createElement('div');
    picker.className = 'chat-emoji-picker';
    picker.id = 'chatEmojiPicker';

    const grid = document.createElement('div');
    grid.className = 'chat-emoji-grid';

    EMOJIS.forEach(function (emoji) {
      const btn = document.createElement('button');
      btn.className = 'chat-emoji-item';
      btn.textContent = emoji;
      btn.type = 'button';
      btn.addEventListener('click', function () { insertEmoji(emoji); });
      grid.appendChild(btn);
    });

    picker.appendChild(grid);
    return picker;
  }

  function insertEmoji(emoji) {
    if (els.input) {
      const cursorPos = els.input.selectionStart;
      const text = els.input.value;
      els.input.value = text.slice(0, cursorPos) + emoji + text.slice(cursorPos);
      els.input.focus();
      els.input.selectionStart = els.input.selectionEnd = cursorPos + emoji.length;
      els.input.style.height = 'auto';
      els.input.style.height = Math.min(els.input.scrollHeight, 80) + 'px';
    }
    const picker = document.getElementById('chatEmojiPicker');
    if (picker) picker.classList.remove('open');
    if (els.emojiBtn) els.emojiBtn.classList.remove('active');
  }

  // ========================
  // File Upload
  // ========================
  function handleFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > CONFIG.maxFileSize) {
      alert('\u09ab\u09be\u0987\u09b2\u099f\u09bf \u0996\u09c1\u09ac \u09ac\u09a1\u09bc\u0964 \u09b8\u09b0\u09cd\u09ac\u09cb\u099a\u09cd\u099a \u09e7\u09e6\u09abu \u0985\u09a8\u09c1\u09ae\u09cb\u09a6\u09bf\u09a4\u0964');
      e.target.value = '';
      return;
    }

    let preview = document.getElementById('chatUploadPreview');
    if (!preview) {
      preview = document.createElement('div');
      preview.className = 'chat-upload-preview';
      preview.id = 'chatUploadPreview';
      els.input.parentNode.insertBefore(preview, els.input);
    }

    preview.innerHTML = '';

    preview.appendChild(createIcon('fas ' + (file.type.startsWith('image/') ? 'fa-file-image' : 'fa-file')));

    const nameSpan = document.createElement('span');
    nameSpan.className = 'chat-upload-preview-name';
    nameSpan.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
    preview.appendChild(nameSpan);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'chat-upload-preview-remove';
    removeBtn.id = 'chatUploadRemove';
    removeBtn.innerHTML = '&times;';
    preview.appendChild(removeBtn);

    const progress = document.createElement('div');
    progress.className = 'chat-upload-progress';
    const progressBar = document.createElement('div');
    progressBar.className = 'chat-upload-progress-bar';
    progressBar.id = 'chatUploadProgressBar';
    progress.appendChild(progressBar);
    preview.appendChild(progress);

    state.pendingFile = file;

    document.getElementById('chatUploadRemove').addEventListener('click', function () {
      state.pendingFile = null;
      preview.remove();
      els.fileInput.value = '';
    });
  }

  async function handleFileUpload() {
    if (!state.pendingFile) return;

    const preview = document.getElementById('chatUploadPreview');
    const progressBar = document.getElementById('chatUploadProgressBar');
    if (progressBar) progressBar.style.width = '30%';

    try {
      const result = await uploadFile(state.pendingFile);
      if (result.status === 'success') {
        if (progressBar) progressBar.style.width = '100%';
        const tempMsg = {
          id: 'temp-file-' + Date.now(),
          message: '[\u09ab\u09be\u0987\u09b2] ' + state.pendingFile.name,
          sender_type: 'visitor',
          message_type: state.pendingFile.type.startsWith('image/') ? 'image' : 'file',
          file_url: result.data.file_url,
          file_name: state.pendingFile.name,
          file_size: state.pendingFile.size,
          file_type: state.pendingFile.type,
          created_at: new Date().toISOString(),
          is_read: 0,
        };
        addMessages([tempMsg]);
      } else {
        alert('\u09ab\u09be\u0987\u09b2 \u0986\u09aa\u09b2\u09cb\u09a1 \u09ac\u09cd\u09af\u09b0\u09cd\u09a5 \u09b9\u09df\u09c7\u099b\u09c7: ' + (result.message || '\u0985\u099c\u09be\u09a8\u09be \u09a4\u09cd\u09b0\u09c1\u099f\u09bf'));
      }
    } catch (e) {
      alert('\u09ab\u09be\u0987\u09b2 \u0986\u09aa\u09b2\u09cb\u09a1 \u09ac\u09cd\u09af\u09b0\u09cd\u09a5 \u09b9\u09df\u09c7\u099b\u09c7\u0964');
    } finally {
      state.pendingFile = null;
      if (preview) preview.remove();
      els.fileInput.value = '';
    }
  }

  // ========================
  // Typing Indicator
  // ========================
  let lastTypingSent = 0;

  function sendTypingNotification() {
    if (!state.sessionId) return;
    const now = Date.now();
    if (now - lastTypingSent < 3000) return;
    lastTypingSent = now;

    const typingPayload = { session_id: state.sessionId };
    if (state.sessionSig) typingPayload.session_sig = state.sessionSig;
    fetch(CONFIG.apiBase + '/typing', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(typingPayload),
    }).catch(function () {});
  }

  function setupTypingListener() {
    if (els.input) {
      els.input.addEventListener('input', function () {
        sendTypingNotification();
      });
    }
  }

  async function checkAdminTyping() {
    if (!state.sessionId || !state.isOpen) return;
    try {
      let typingUrl = CONFIG.apiBase + '/typing?session_id=' + encodeURIComponent(state.sessionId);
      if (state.sessionSig) typingUrl += '&session_sig=' + encodeURIComponent(state.sessionSig);
      const res = await fetch(typingUrl);
      const data = await res.json();
      if (data.status === 'success' && data.data) {
        // Auto-recover session signature from response
        if (data.session_sig) {
          state.sessionSig = data.session_sig;
          localStorage.setItem(CONFIG.sigKey, state.sessionSig);
        }
        if (els.typing) {
          els.typing.classList.toggle('visible', !!data.data.is_typing);
        }
      }
    } catch (e) {}
  }

  // ========================
  // Notification Sound (Web Audio API — no external files)
  // ========================
  let _audioCtx = null;

  function getAudioContext() {
    if (!_audioCtx) {
      try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (AC) _audioCtx = new AC();
      } catch (e) {}
    }
    return _audioCtx;
  }

  function playNotificationSound() {
    // Respect admin sound toggle setting
    if (widgetSettings.chat_sound_enabled !== '1') return;
    const ctx = getAudioContext();
    if (!ctx) return;

    try {
      const now = ctx.currentTime;

      const osc1 = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = 'sine';
      osc1.frequency.value = 880;
      gain1.gain.setValueAtTime(0.25, now);
      gain1.gain.exponentialRampToValueAtTime(0.01, now + 0.15);
      osc1.connect(gain1);
      gain1.connect(ctx.destination);
      osc1.start(now);
      osc1.stop(now + 0.15);

      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = 'sine';
      osc2.frequency.value = 660;
      gain2.gain.setValueAtTime(0.2, now + 0.1);
      gain2.gain.exponentialRampToValueAtTime(0.01, now + 0.3);
      osc2.connect(gain2);
      gain2.connect(ctx.destination);
      osc2.start(now + 0.1);
      osc2.stop(now + 0.3);
    } catch (e) {
      // Audio not supported — silently fail
    }
  }

  // ========================
  // Desktop Notification (Browser Notification API)
  // ========================
  let _desktopNotifyGranted = false;

  function requestNotificationPermission() {
    if (!('Notification' in window)) return; // Browser doesn't support it
    if (Notification.permission === 'granted') {
      _desktopNotifyGranted = true;
      return;
    }
    if (Notification.permission === 'denied') return;
    // Default state — request permission
    Notification.requestPermission().then(function (perm) {
      _desktopNotifyGranted = perm === 'granted';
    }).catch(function () {});
  }

  function showDesktopNotification(title, body) {
    if (!('Notification' in window)) return;
    if (!_desktopNotifyGranted && Notification.permission !== 'granted') return;
    _desktopNotifyGranted = Notification.permission === 'granted';
    if (!_desktopNotifyGranted) return;

    try {
      const notif = new Notification(title || '\u09a8\u09a4\u09c1\u09a8 \u09ac\u09be\u09b0\u09cd\u09a4\u09be', {
        body: body || '',
        icon: getFaviconLink().getAttribute('href') || undefined,
        tag: 'chat-notification',
        silent: true, // Our own sound plays separately
      });

      // Click notification to focus the tab and open the chat
      notif.onclick = function () {
        window.focus();
        if (els.button && !state.isOpen) {
          els.button.click();
        }
      };

      // Auto-close after 8 seconds
      setTimeout(function () { notif.close(); }, 8000);
    } catch (e) {
      // Notification not supported
    }
  }

  // ========================
  // Tab Title Notification
  // ========================
  let _originalTitle = document.title;
  let _titleNotifyInterval = null;
  let _titleUnreadCount = 0;

  function startTitleNotification(count) {
    if (count <= 0) {
      stopTitleNotification();
      return;
    }
    _titleUnreadCount = count;

    startFaviconBadge(count);

    if (_titleNotifyInterval) return;

    const isBangla = /[\u0980-\u09FF]/.test(_originalTitle);

    _titleNotifyInterval = setInterval(function () {
      if (document.title === _originalTitle) {
        let prefix = '';
        if (_titleUnreadCount > 0) {
          prefix = '\uD83D\uDD14 (' + (_titleUnreadCount > 9 ? '9+' : _titleUnreadCount) + ') ';
        }
        document.title = prefix + (isBangla
          ? '\u09a8\u09a4\u09c1\u09a8 \u09ac\u09be\u09b0\u09cd\u09a4\u09be'
          : 'New message');
      } else {
        document.title = _originalTitle;
      }
    }, 1000);
  }

  function stopTitleNotification() {
    if (_titleNotifyInterval) {
      clearInterval(_titleNotifyInterval);
      _titleNotifyInterval = null;
    }
    _titleUnreadCount = 0;
    if (document.title !== _originalTitle) {
      document.title = _originalTitle;
    }
    stopFaviconBadge();
  }

  // ========================
  // Favicon Badge
  // ========================
  let _faviconLink = null;
  let _faviconCanvas = null;
  let _originalFaviconHref = null;
  let _faviconBadgeActive = false;

  function getFaviconLink() {
    if (_faviconLink) return _faviconLink;
    _faviconLink = document.querySelector('link[rel="icon"], link[rel="shortcut icon"]');
    if (!_faviconLink) {
      _faviconLink = document.createElement('link');
      _faviconLink.rel = 'icon';
      document.head.appendChild(_faviconLink);
    }
    return _faviconLink;
  }

  function drawFaviconBadge(count) {
    const link = getFaviconLink();
    const originalHref = link.getAttribute('href') || '/favicon.ico';

    if (!_faviconCanvas) {
      _faviconCanvas = document.createElement('canvas');
      _faviconCanvas.width = 32;
      _faviconCanvas.height = 32;
    }

    const canvas = _faviconCanvas;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const img = new Image();
    img.crossOrigin = 'anonymous';

    function drawBadgeOnCtx() {
      const badgeSize = 16;
      const badgeX = 16;
      const badgeY = 0;

      // Draw red circle at top-right corner
      ctx.beginPath();
      ctx.arc(badgeX + badgeSize / 2, badgeY + badgeSize / 2, badgeSize / 2, 0, Math.PI * 2);
      ctx.fillStyle = '#e74c3c';
      ctx.fill();
      ctx.strokeStyle = '#fff';
      ctx.lineWidth = 2;
      ctx.stroke();

      // Draw count text
      const text = count > 9 ? '9+' : String(count);
      ctx.fillStyle = '#fff';
      ctx.font = 'bold 10px Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(text, badgeX + badgeSize / 2, badgeY + badgeSize / 2 + 1);

      // Only apply to favicon if badge is still active (prevents race condition
      // where image loads after stopFaviconBadge already restored the original)
      if (_faviconBadgeActive) {
        link.setAttribute('href', canvas.toDataURL('image/png'));
      }
    }

    img.onload = function () {
      ctx.clearRect(0, 0, 32, 32);
      ctx.drawImage(img, 0, 0, 32, 32);
      drawBadgeOnCtx();
    };

    img.onerror = function () {
      ctx.clearRect(0, 0, 32, 32);
      drawBadgeOnCtx();
    };

    img.src = originalHref;
  }

  function startFaviconBadge(count) {
    if (count <= 0) {
      stopFaviconBadge();
      return;
    }

    _faviconBadgeActive = true;

    const link = getFaviconLink();
    if (!_originalFaviconHref) {
      _originalFaviconHref = link.getAttribute('href') || '/favicon.ico';
    }

    drawFaviconBadge(count);
  }

  function stopFaviconBadge() {
    _faviconBadgeActive = false;
    if (_originalFaviconHref) {
      const link = getFaviconLink();
      link.setAttribute('href', _originalFaviconHref);
      _originalFaviconHref = null;
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      stopTitleNotification();
    }
  });

  window.addEventListener('focus', function () {
    stopTitleNotification();
  });

  // ========================
  // Polling
  // ========================
  function startPolling() {
    stopPolling();
    state.pollTimer = setInterval(async function () {
      if (!state.sessionId || !state.isOpen) return;
      try {
        const result = await fetchMessages();
        if (result.messages.length > 0) {
          // --- Sound: only play for unseen human admin messages ---
          const unseenHumanAdmin = result.messages.filter(function (m) {
            const isHumanAdmin = m.sender_type === 'admin' && m.auto_reply != 1;
            const alreadySeen = state.seenMessageIds.has(m.id);
            if (m.id) state.seenMessageIds.add(m.id);
            return isHumanAdmin && !alreadySeen;
          });
          if (unseenHumanAdmin.length > 0) {
            playNotificationSound();
            if (document.hidden) {
              const adminMsgs = result.messages.filter(function (m) { return m.sender_type === 'admin'; });
              const adminCount = adminMsgs.length;
              startTitleNotification(adminCount);
              // Show desktop notification for the latest admin message
              const latestMsg = adminMsgs[adminMsgs.length - 1];
              if (latestMsg) {
                const notifBody = latestMsg.message_type === 'text'
                  ? latestMsg.message
                  : (latestMsg.message_type === 'image' ? '\u098f\u0995\u099f\u09bf \u099b\u09ac\u09bf \u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09b9\u09df\u09c7\u099b\u09c7' : '\u098f\u0995\u099f\u09bf \u09ab\u09be\u0987\u09b2 \u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09b9\u09df\u09c7\u099b\u09c7');
                showDesktopNotification(
                  widgetSettings.chat_title || '\u09b2\u09be\u0987\u09ad \u099a\u09cd\u09af\u09be\u099f \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be',
                  notifBody
                );
              }
            }
          }

          addMessages(result.messages);
          if (els.typing) els.typing.classList.remove('visible');
          result.messages.forEach(function (msg) {
            if (msg.sender_type === 'admin') {
              document.querySelectorAll('.chat-msg.visitor[data-msg-id]').forEach(function (el) {
                const statusEl = el.querySelector('.chat-msg-status');
                if (statusEl) {
                  statusEl.innerHTML = '<span class="status-icon status-read"><i class="fas fa-check-double"></i></span>';
                }
              });
            }
          });
          updateUnreadBadge(result.messages.filter(function (m) { return m.sender_type === 'admin' && !m.is_read; }).length);
        }
        checkAdminTyping();
      } catch (e) {}
    }, CONFIG.pollInterval);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
    if (els.typing) els.typing.classList.remove('visible');
  }

  // Cleanup all timers on page unload
  window.addEventListener('beforeunload', function () {
    stopPolling();
    stopBackgroundCheck();
    stopAdminStatusCheck();
    stopTitleNotification();
  });

  // ========================
  // Unread Badge
  // ========================
  function updateUnreadBadge(count) {
    if (!els.badge) return;
    if (count > 0) {
      els.badge.textContent = count > 9 ? '9+' : String(count);
      els.badge.classList.add('visible');
      if (els.button) els.button.classList.add('has-unread');
    } else {
      els.badge.classList.remove('visible');
      if (els.button) els.button.classList.remove('has-unread');
    }
  }

  // ========================
  // Toggle Chat
  // ========================
  function toggleChat() {
    state.isOpen = !state.isOpen;

    if (state.isOpen) {
      // Request notification permission on first open
      requestNotificationPermission();
      els.window.classList.add('open');
      els.button.classList.add('active');

      stopBackgroundCheck();

      // If visitor hasn't provided name yet, show welcome + registration in input area
      if (!state.visitorName) {
        showWelcome();
        if (els.inputWrapper) els.inputWrapper.style.display = 'none';
        if (els.regInputArea) {
          els.regInputArea.style.display = '';
          els.regNameInput.value = state.visitorName || '';
          els.regUnionInput.value = state.visitorUnion || '';
          setTimeout(function () { if (els.regNameInput) els.regNameInput.focus(); }, 300);
        }
        updateUnreadBadge(0);
        stopTitleNotification();
        return;
      }

      loadMessages();
      startPolling();
      updateUnreadBadge(0);
      stopTitleNotification();
    } else {
      startBackgroundCheck();
      els.window.classList.remove('open');
      els.button.classList.remove('active');
      const picker = document.getElementById('chatEmojiPicker');
      if (picker) picker.classList.remove('open');
      if (els.emojiBtn) els.emojiBtn.classList.remove('active');
      stopPolling();
    }
  }

  async function loadMessages() {
    if (!state.sessionId) {
      showWelcome();
      return;
    }

    try {
      const result = await loadHistory(0);
      if (result.messages.length > 0) {
        state.historyOffset = result.messages.length;
        state.hasMoreHistory = result.has_more;
        state.lastMessageTime = new Date().toISOString();

        // Track seen IDs so polling doesn't re-trigger sound for historical messages
        result.messages.forEach(function (m) {
          if (m.id) state.seenMessageIds.add(m.id);
        });

        addMessages(result.messages);

        if (result.has_more) {
          addLoadEarlierButton();
        }

        apiCall('POST', '/read', { session_id: state.sessionId, session_sig: state.sessionSig }).catch(function () {});
      } else {
        showWelcome();
      }
    } catch (e) {
      showWelcome();
    }
  }

  // ========================
  // Handle Send
  // ========================
  async function handleSend() {
    const text = els.input.value.trim();
    if (!text && !state.pendingFile) return;

    if (state.pendingFile) {
      await handleFileUpload();
      if (!text) {
        els.input.value = '';
        return;
      }
    }

    els.input.value = '';
    els.sendBtn.disabled = true;
    els.input.style.height = 'auto';

    const tempMsg = {
      id: 'temp-' + Date.now(),
      message: text,
      sender_type: 'visitor',
      message_type: 'text',
      created_at: new Date().toISOString(),
      is_read: 0,
    };
    addMessages([tempMsg]);

    try {
      const result = await sendMessage(text);
      // Store session_sig from server response
      if (result && result.data && result.data.session_sig) {
        state.sessionSig = result.data.session_sig;
        localStorage.setItem(CONFIG.sigKey, state.sessionSig);
      }
      // If server returned an auto-reply, append it to the messages
      if (result && result.auto_reply) {
        addMessages([result.auto_reply]);
      }
    } catch (e) {
      const errMsg = {
        id: 'err-' + Date.now(),
        message: '\u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09af\u09be\u09df\u09a8\u09bf\u0964 \u0986\u09ac\u09be\u09b0 \u099a\u09c7\u09b7\u09cd\u099f\u09be \u0995\u09b0\u09c1\u09a8\u0964',
        sender_type: 'admin',
        created_at: new Date().toISOString(),
      };
      addMessages([errMsg]);
    } finally {
      els.sendBtn.disabled = false;
      els.input.focus();
    }
  }

  // ========================
  // Widget Settings
  // ========================
  const widgetSettings = {
    chat_enabled: '1',
    chat_title: '\u09b2\u09be\u0987\u09ad \u099a\u09cd\u09af\u09be\u099f \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be',
    chat_subtitle: '\u09b8\u09cd\u09ae\u09be\u09b0\u09cd\u099f \u0987\u0989\u09a8\u09bf\u09af\u09bc\u09a8 \u09aa\u09b0\u09bf\u09b7\u09a6',
    chat_welcome_message: '\u0986\u09aa\u09a8\u09be\u09b0 \u09aa\u09cd\u09b0\u09b6\u09cd\u09a8 \u09b2\u09bf\u0996\u09c1\u09a8, \u0986\u09ae\u09b0\u09be \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be \u0995\u09b0\u09ac\u0964',
    chat_welcome_title: '\u09b8\u09be\u09b9\u09be\u09af\u09bc\u09cd\u09af \u09aa\u09cd\u09b0\u09af\u09bc\u09cb\u099c\u09a8?',
    chat_agent_name: '\u09b8\u09b9\u09be\u09af\u09bc\u0995',
    chat_primary_color: '#008B8B',
    chat_offline_enabled: '0',
    chat_offline_start: '17:00',
    chat_offline_end: '09:00',
    chat_offline_message: '\u0986\u09ae\u09b0\u09be \u09ac\u09b0\u09cd\u09a4\u09ae\u09be\u09a8\u09c7 \u0985\u09ab\u09b2\u09be\u0987\u09a8\u09c7 \u0986\u099b\u09bf\u0964 \u0986\u09aa\u09a8\u09be\u09b0 \u09ac\u09be\u09b0\u09cd\u09a4\u09be \u099b\u09c7\u09a1\u09bc\u09c7 \u09a6\u09bf\u09a8, \u0986\u09ae\u09b0\u09be \u09aa\u09b0\u09c7 \u0989\u09a4\u09cd\u09a4\u09b0 \u09a6\u09c7\u09ac\u0964',
    chat_placeholder: '\u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09b2\u09bf\u0996\u09c1\u09a8...',
    chat_name_placeholder: '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae (\u0990\u099a\u09cd\u099b\u09bf\u0995)',
    chat_sound_enabled: '1',
  };

  async function fetchSettings() {
    try {
      const res = await fetch('/api/chat/settings');
      const result = await res.json();
      if (result.status === 'success' && result.data) {
        Object.assign(widgetSettings, result.data);
      }
    } catch (e) {}
  }

  function isOffline() {
    if (widgetSettings.chat_offline_enabled !== '1') return false;
    const now = new Date();
    const currentMinutes = now.getHours() * 60 + now.getMinutes();
    const startParts = (widgetSettings.chat_offline_start || '17:00').split(':');
    const endParts = (widgetSettings.chat_offline_end || '09:00').split(':');
    const startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1]);
    const endMinutes = parseInt(endParts[0]) * 60 + parseInt(endParts[1]);
    if (startMinutes < endMinutes) {
      return currentMinutes >= startMinutes && currentMinutes < endMinutes;
    } else {
      return currentMinutes >= startMinutes || currentMinutes < endMinutes;
    }
  }

  // ========================
  // Offline Inquiry Form
  // ========================
  function buildOfflineForm() {
    const form = document.createElement('div');
    form.className = 'chat-offline-form';
    form.id = 'chatOfflineForm';

    const title = document.createElement('div');
    title.className = 'chat-offline-form-title';
    title.textContent = widgetSettings.chat_offline_form_title || '\u0986\u09ae\u09b0\u09be \u0985\u09ab\u09b2\u09be\u0987\u09a8\u09c7 \u0986\u099b\u09bf';
    form.appendChild(title);

    const subtitle = document.createElement('div');
    subtitle.className = 'chat-offline-form-subtitle';
    subtitle.textContent = widgetSettings.chat_offline_form_subtitle || '\u09a8\u09bf\u099a\u09c7\u09b0 \u09ab\u09b0\u09cd\u09ae\u099f\u09bf \u09aa\u09c2\u09b0\u09a3 \u0995\u09b0\u09c1\u09a8, \u0986\u09ae\u09b0\u09be \u09aa\u09b0\u09c7 \u0989\u09a4\u09cd\u09a4\u09b0 \u09a6\u09c7\u09ac\u0964';
    form.appendChild(subtitle);

    // Name field (required)
    const nameGroup = document.createElement('div');
    nameGroup.className = 'chat-offline-field';
    const nameLabel = document.createElement('label');
    nameLabel.textContent = '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae *';
    nameLabel.htmlFor = 'chatOfflineName';
    nameGroup.appendChild(nameLabel);
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.id = 'chatOfflineName';
    nameInput.className = 'chat-offline-input';
    nameInput.maxLength = 100;
    nameInput.setAttribute('aria-label', '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae');
    nameInput.placeholder = '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae';
    nameGroup.appendChild(nameInput);
    form.appendChild(nameGroup);

    // Phone field (optional)
    const phoneGroup = document.createElement('div');
    phoneGroup.className = 'chat-offline-field';
    const phoneLabel = document.createElement('label');
    phoneLabel.textContent = '\u09ab\u09cb\u09a8 \u09a8\u09ae\u09cd\u09ac\u09b0';
    phoneLabel.htmlFor = 'chatOfflinePhone';
    phoneGroup.appendChild(phoneLabel);
    const phoneInput = document.createElement('input');
    phoneInput.type = 'tel';
    phoneInput.id = 'chatOfflinePhone';
    phoneInput.className = 'chat-offline-input';
    phoneInput.maxLength = 30;
    phoneInput.setAttribute('aria-label', '\u09ab\u09cb\u09a8');
    phoneInput.placeholder = '\u09ab\u09cb\u09a8 \u09a8\u09ae\u09cd\u09ac\u09b0';
    phoneGroup.appendChild(phoneInput);
    form.appendChild(phoneGroup);

    // Email field (optional)
    const emailGroup = document.createElement('div');
    emailGroup.className = 'chat-offline-field';
    const emailLabel = document.createElement('label');
    emailLabel.textContent = '\u0987\u09ae\u09c7\u0987\u09b2';
    emailLabel.htmlFor = 'chatOfflineEmail';
    emailGroup.appendChild(emailLabel);
    const emailInput = document.createElement('input');
    emailInput.type = 'email';
    emailInput.id = 'chatOfflineEmail';
    emailInput.className = 'chat-offline-input';
    emailInput.maxLength = 100;
    emailInput.setAttribute('aria-label', '\u0987\u09ae\u09c7\u0987\u09b2');
    emailInput.placeholder = '\u0987\u09ae\u09c7\u0987\u09b2';
    emailGroup.appendChild(emailInput);
    form.appendChild(emailGroup);

    // Message field (required)
    const msgGroup = document.createElement('div');
    msgGroup.className = 'chat-offline-field';
    const msgLabel = document.createElement('label');
    msgLabel.textContent = '\u09ac\u09be\u09b0\u09cd\u09a4\u09be *';
    msgLabel.htmlFor = 'chatOfflineMsg';
    msgGroup.appendChild(msgLabel);
    const msgTextarea = document.createElement('textarea');
    msgTextarea.id = 'chatOfflineMsg';
    msgTextarea.className = 'chat-offline-textarea';
    msgTextarea.rows = 4;
    msgTextarea.maxLength = 1000;
    msgTextarea.setAttribute('aria-label', '\u09ac\u09be\u09b0\u09cd\u09a4\u09be');
    msgTextarea.placeholder = '\u0986\u09aa\u09a8\u09be\u09b0 \u09ac\u09be\u09b0\u09cd\u09a4\u09be...';
    msgGroup.appendChild(msgTextarea);
    form.appendChild(msgGroup);

    // Submit button
    const submitBtn = document.createElement('button');
    submitBtn.type = 'button';
    submitBtn.className = 'chat-offline-submit';
    submitBtn.id = 'chatOfflineSubmit';
    submitBtn.textContent = '\u09aa\u09be\u09a0\u09be\u09a8';
    form.appendChild(submitBtn);

    // Error container
    const errorDiv = document.createElement('div');
    errorDiv.className = 'chat-offline-error';
    errorDiv.id = 'chatOfflineError';
    errorDiv.style.display = 'none';
    form.appendChild(errorDiv);

    // Success message (hidden by default)
    const successDiv = document.createElement('div');
    successDiv.className = 'chat-offline-success';
    successDiv.id = 'chatOfflineSuccess';
    successDiv.style.display = 'none';
    form.appendChild(successDiv);

    return form;
  }

  function showOfflineForm() {
    if (!els.inputArea) return;
    els.inputArea.innerHTML = '';
    els.inputArea.appendChild(buildOfflineForm());

    document.getElementById('chatOfflineSubmit').addEventListener('click', function() {
      submitOfflineForm();
    });

    // Enter key support in message field
    document.getElementById('chatOfflineMsg').addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitOfflineForm();
      }
    });
  }

  async function submitOfflineForm() {
    const nameEl = document.getElementById('chatOfflineName');
    const phoneEl = document.getElementById('chatOfflinePhone');
    const emailEl = document.getElementById('chatOfflineEmail');
    const msgEl = document.getElementById('chatOfflineMsg');
    const errorEl = document.getElementById('chatOfflineError');
    const successEl = document.getElementById('chatOfflineSuccess');
    const submitBtn = document.getElementById('chatOfflineSubmit');

    if (!nameEl || !msgEl) return;

    const name = nameEl.value.trim();
    const phone = phoneEl ? phoneEl.value.trim() : '';
    const email = emailEl ? emailEl.value.trim() : '';
    const message = msgEl.value.trim();

    // Client-side validation
    if (!name) {
      errorEl.textContent = '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae \u09a6\u09bf\u09a8';
      errorEl.style.display = 'block';
      nameEl.focus();
      return;
    }
    if (!message) {
      errorEl.textContent = '\u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09b2\u09bf\u0996\u09c1\u09a8';
      errorEl.style.display = 'block';
      msgEl.focus();
      return;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errorEl.textContent = '\u09ac\u09c8\u09a7 \u0987\u09ae\u09c7\u0987\u09b2 \u09a0\u09bf\u0995\u09be\u09a8\u09be \u09a6\u09bf\u09a8';
      errorEl.style.display = 'block';
      emailEl.focus();
      return;
    }

    errorEl.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = '\u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09b9\u099a\u09cd\u099b\u09c7...';

    try {
      const res = await fetch('/api/chat/offline', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name, phone: phone, email: email, message: message }),
      });
      const data = await res.json();

      if (data.status === 'success') {
        // Hide form, show success
        document.querySelectorAll('.chat-offline-field').forEach(function(el) { el.style.display = 'none'; });
        submitBtn.style.display = 'none';
        successEl.textContent = data.message || widgetSettings.chat_offline_success_message || '\u0986\u09aa\u09a8\u09be\u09b0 \u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09b9\u09df\u09c7\u099b\u09c7';
        successEl.style.display = 'block';
      } else {
        errorEl.textContent = data.message || '\u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09ac\u09cd\u09af\u09b0\u09cd\u09a5 \u09b9\u09df\u09c7\u099b\u09c7';
        errorEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = '\u09aa\u09be\u09a0\u09be\u09a8';
      }
    } catch (e) {
      errorEl.textContent = '\u09aa\u09be\u09a0\u09be\u09a8\u09cb \u09ac\u09cd\u09af\u09b0\u09cd\u09a5 \u09b9\u09df\u09c7\u099b\u09c7, \u0986\u09ac\u09be\u09b0 \u099a\u09c7\u09b7\u09cd\u099f\u09be \u0995\u09b0\u09c1\u09a8';
      errorEl.style.display = 'block';
      submitBtn.disabled = false;
      submitBtn.textContent = '\u09aa\u09be\u09a0\u09be\u09a8';
    }
  }

  // ========================
  // Build Widget DOM (UTF-8 Safe)
  // ========================
  function buildWidget() {
    if (document.getElementById('chat-widget-root')) return;

    const primaryColor = widgetSettings.chat_primary_color || '#008B8B';

    function lightenColor(hex, percent) {
      const num = parseInt(hex.replace('#', ''), 16);
      const amt = Math.round(2.55 * percent);
      const R = Math.min(255, (num >> 16) + amt);
      const G = Math.min(255, ((num >> 8) & 0x00FF) + amt);
      const B = Math.min(255, (num & 0x0000FF) + amt);
      return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
    }

    const lighterColor = lightenColor(primaryColor, 15);
    const isOfflineMode = isOffline();
    const gradientStyle = 'linear-gradient(135deg, ' + primaryColor + ', ' + lighterColor + ')';

    const root = document.createElement('div');
    root.id = 'chat-widget-root';

    const btn = document.createElement('button');
    btn.className = 'chat-button';
    btn.id = 'chatButton';
    btn.setAttribute('aria-label', '\u09b2\u09be\u0987\u09ad \u099a\u09cd\u09af\u09be\u099f \u0996\u09c1\u09b2\u09c1\u09a8');
    btn.style.background = gradientStyle;
    btn.innerHTML = '<span class="chat-btn-icon"><i class="fas fa-comment-dots"></i></span><span class="chat-badge" id="chatBadge">0</span>';
    root.appendChild(btn);

    const win = document.createElement('div');
    win.className = 'chat-window';
    win.id = 'chatWindow';

    const header = document.createElement('div');
    header.className = 'chat-header';
    header.style.background = gradientStyle;

    const avatar = document.createElement('div');
    avatar.className = 'chat-header-avatar';
    avatar.innerHTML = '<i class="fas fa-headset"></i>';

    // Online/offline status indicator dot
    const statusDot = document.createElement('span');
    statusDot.className = 'chat-status-dot ' + (isOfflineMode ? 'offline' : 'online');
    avatar.appendChild(statusDot);
    els.statusDot = statusDot;

    header.appendChild(avatar);

    const headerInfo = document.createElement('div');
    headerInfo.className = 'chat-header-info';

    const headerTitle = document.createElement('div');
    headerTitle.className = 'chat-header-title';
    headerTitle.textContent = widgetSettings.chat_title || '\u09b2\u09be\u0987\u09ad \u099a\u09cd\u09af\u09be\u099f \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be';
    headerInfo.appendChild(headerTitle);

    const headerSubtitle = document.createElement('div');
    headerSubtitle.className = 'chat-header-subtitle';
    headerSubtitle.textContent = isOfflineMode
      ? '\u0985\u09ab\u09b2\u09be\u0987\u09a8'
      : (widgetSettings.chat_subtitle || '\u09b8\u09cd\u09ae\u09be\u09b0\u09cd\u099f \u0987\u0989\u09a8\u09bf\u09af\u09bc\u09a8 \u09aa\u09b0\u09bf\u09b7\u09a6');
    headerInfo.appendChild(headerSubtitle);
    els.headerSubtitle = headerSubtitle;
    header.appendChild(headerInfo);

    const closeBtn = document.createElement('button');
    closeBtn.className = 'chat-header-close';
    closeBtn.id = 'chatCloseBtn';
    closeBtn.setAttribute('aria-label', '\u09ac\u09a8\u09cd\u09a7 \u0995\u09b0\u09c1\u09a8');
    closeBtn.innerHTML = '<i class="fas fa-times"></i>';
    header.appendChild(closeBtn);
    win.appendChild(header);

    const messages = document.createElement('div');
    messages.className = 'chat-messages';
    messages.id = 'chatMessages';

    const welcome = document.createElement('div');
    welcome.className = 'chat-welcome';

    const welcomeIcon = createTextEl('span', 'chat-welcome-icon', '\uD83D\uDCAC');
    welcome.appendChild(welcomeIcon);

    const welcomeTitle = createTextEl('div', 'chat-welcome-title', widgetSettings.chat_welcome_title || '\u09b8\u09be\u09b9\u09be\u09af\u09bc\u09cd\u09af \u09aa\u09cd\u09b0\u09af\u09bc\u09cb\u099c\u09a8?');
    welcome.appendChild(welcomeTitle);

    const welcomeText = document.createElement('div');
    welcomeText.textContent = isOfflineMode
      ? (widgetSettings.chat_offline_message || '\u0986\u09ae\u09b0\u09be \u09ac\u09b0\u09cd\u09a4\u09ae\u09be\u09a8\u09c7 \u0985\u09ab\u09b2\u09be\u0987\u09a8\u09c7 \u0986\u099b\u09bf\u0964')
      : (widgetSettings.chat_welcome_message || '\u0986\u09aa\u09a8\u09be\u09b0 \u09aa\u09cd\u09b0\u09b6\u09cd\u09a8 \u09b2\u09bf\u0996\u09c1\u09a8, \u0986\u09ae\u09b0\u09be \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be \u0995\u09b0\u09ac\u0964');
    welcome.appendChild(welcomeText);
    messages.appendChild(welcome);
    win.appendChild(messages);

    const typing = document.createElement('div');
    typing.className = 'chat-typing';
    typing.id = 'chatTyping';
    for (let t = 0; t < 3; t++) {
      typing.appendChild(document.createElement('span'));
    }
    win.appendChild(typing);

    const inputArea = document.createElement('div');
    inputArea.className = 'chat-input-area';
    els.inputArea = inputArea;

    const inputWrapper = document.createElement('div');
    inputWrapper.style.position = 'relative';

    inputWrapper.appendChild(buildEmojiPicker());

    const toolbar = document.createElement('div');
    toolbar.className = 'chat-input-toolbar';

    const emojiBtn = document.createElement('button');
    emojiBtn.type = 'button';
    emojiBtn.className = 'chat-tool-btn';
    emojiBtn.id = 'chatEmojiBtn';
    emojiBtn.setAttribute('title', '\u0987\u09ae\u09cb\u099c\u09bf');
    emojiBtn.innerHTML = '<i class="far fa-smile"></i>';
    toolbar.appendChild(emojiBtn);

    const fileBtn = document.createElement('button');
    fileBtn.type = 'button';
    fileBtn.className = 'chat-tool-btn';
    fileBtn.id = 'chatFileBtn';
    fileBtn.setAttribute('title', '\u09ab\u09be\u0987\u09b2 \u0986\u09aa\u09b2\u09cb\u09a1');
    fileBtn.innerHTML = '<i class="fas fa-paperclip"></i>';
    toolbar.appendChild(fileBtn);

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.className = 'chat-file-input';
    fileInput.id = 'chatFileInput';
    fileInput.setAttribute('aria-label', '\u09ab\u09be\u0987\u09b2 \u0986\u09aa\u09b2\u09cb\u09a1');
    fileInput.setAttribute('accept', 'image/jpeg,image/png,image/gif,image/webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,.txt,.csv,.zip');
    toolbar.appendChild(fileInput);

    inputWrapper.appendChild(toolbar);

    const inputRow = document.createElement('div');
    inputRow.className = 'chat-input-row';

    const textarea = document.createElement('textarea');
    textarea.className = 'chat-input';
    textarea.id = 'chatInput';
    textarea.setAttribute('aria-label', '\u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09b2\u09bf\u0996\u09c1\u09a8');
    textarea.rows = 1;
    textarea.maxLength = 500;
    textarea.setAttribute('placeholder', widgetSettings.chat_placeholder || '\u09ac\u09be\u09b0\u09cd\u09a4\u09be \u09b2\u09bf\u0996\u09c1\u09a8...');
    inputRow.appendChild(textarea);

    const sendBtn = document.createElement('button');
    sendBtn.className = 'chat-send-btn';
    sendBtn.id = 'chatSendBtn';
    sendBtn.setAttribute('aria-label', '\u09aa\u09be\u09a0\u09be\u09a8');
    sendBtn.style.background = gradientStyle;
    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
    inputRow.appendChild(sendBtn);

    inputWrapper.appendChild(inputRow);
    inputArea.appendChild(inputWrapper);

    // ========================
    // Registration Input Area (shown instead of normal chat input for new visitors)
    // ========================
    const regInputArea = document.createElement('div');
    regInputArea.className = 'chat-reg-input-area';
    regInputArea.style.display = 'none';

    const regNameInput = document.createElement('input');
    regNameInput.type = 'text';
    regNameInput.className = 'chat-reg-field';
    regNameInput.id = 'chatRegName';
    regNameInput.setAttribute('aria-label', '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae');
    regNameInput.maxLength = 50;
    regNameInput.placeholder = '\u0986\u09aa\u09a8\u09be\u09b0 \u09a8\u09be\u09ae *';

    const regUnionInput = document.createElement('input');
    regUnionInput.type = 'text';
    regUnionInput.className = 'chat-reg-field';
    regUnionInput.id = 'chatRegUnion';
    regUnionInput.setAttribute('aria-label', '\u0986\u09aa\u09a8\u09be\u09b0 \u0987\u0989\u09a8\u09bf\u09af\u09bc\u09a8\u09c7\u09b0 \u09a8\u09be\u09ae');
    regUnionInput.maxLength = 100;
    regUnionInput.placeholder = '\u0986\u09aa\u09a8\u09be\u09b0 \u0987\u0989\u09a8\u09bf\u09af\u09bc\u09a8\u09c7\u09b0 \u09a8\u09be\u09ae';

    const regStartBtn = document.createElement('button');
    regStartBtn.className = 'chat-reg-start-btn';
    regStartBtn.textContent = '\u09b6\u09c1\u09b0\u09c1 \u0995\u09b0\u09c1\u09a8';

    function submitRegistration() {
      let name = regNameInput.value.trim();
      let union = regUnionInput.value.trim();
      if (!name) {
        regNameInput.focus();
        regNameInput.style.borderColor = '#e74c3c';
        setTimeout(function () {
          if (regNameInput) regNameInput.style.borderColor = '';
        }, 2000);
        return;
      }
      transitionToChat(name, union);
    }

    regStartBtn.addEventListener('click', submitRegistration);
    regNameInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') submitRegistration();
    });
    regUnionInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') submitRegistration();
    });

    regInputArea.appendChild(regNameInput);
    regInputArea.appendChild(regUnionInput);
    regInputArea.appendChild(regStartBtn);
    inputArea.appendChild(regInputArea);

    win.appendChild(inputArea);

    root.appendChild(win);
    document.body.appendChild(root);

    els.root = root;
    els.button = btn;
    els.inputArea = inputArea;
    els.window = win;
    els.badge = document.getElementById('chatBadge');
    els.messages = messages;
    els.typing = typing;
    els.input = textarea;
    els.sendBtn = sendBtn;
    els.closeBtn = closeBtn;
    els.inputWrapper = inputWrapper;
    els.regInputArea = regInputArea;
    els.regNameInput = regNameInput;
    els.regUnionInput = regUnionInput;
    els.regStartBtn = regStartBtn;
    els.emojiBtn = emojiBtn;
    els.fileBtn = fileBtn;
    els.fileInput = fileInput;

    els.emojiBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleEmojiPicker();
    });

    const emojiPickerEl = document.getElementById('chatEmojiPicker');
    emojiPickerEl.addEventListener('click', function (e) {
      const item = e.target.closest('.chat-emoji-item');
      if (item) insertEmoji(item.textContent);
    });

    document.addEventListener('click', function (e) {
      const picker = document.getElementById('chatEmojiPicker');
      if (picker && !picker.contains(e.target) && e.target !== els.emojiBtn && !els.emojiBtn.contains(e.target)) {
        picker.classList.remove('open');
        if (els.emojiBtn) els.emojiBtn.classList.remove('active');
      }
    });

    setupTypingListener();

    els.fileBtn.addEventListener('click', function () { els.fileInput.click(); });
    els.fileInput.addEventListener('change', handleFileSelect);

    els.input.addEventListener('input', function () {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 80) + 'px';
    });

    els.input.addEventListener('paste', function (e) {
      const items = e.clipboardData && e.clipboardData.items;
      if (!items) return;

      for (let i = 0; i < items.length; i++) {
        const item = items[i];
        if (item.type && item.type.indexOf('image/') === 0) {
          e.preventDefault();
          let file = item.getAsFile();
          if (file) {
            file = new File([file], 'clipboard-' + Date.now() + '.' + (file.type.split('/')[1] || 'png'), { type: file.type });
            handleFileSelect({ target: { files: [file], value: '' } });
          }
          break;
        }
      }
    });

    els.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    });

    els.button.addEventListener('click', toggleChat);
    els.closeBtn.addEventListener('click', toggleChat);
    els.sendBtn.addEventListener('click', handleSend);

    // Close chat on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && state.isOpen) {
        // If a file preview is open, let the preview handler deal with it
        if (document.getElementById('chatFilePreview')) return;
        toggleChat();
      }
    });

    els.messages.addEventListener('scroll', function () {
      if (this.scrollTop < 30 && state.hasMoreHistory && !state.isLoadingHistory) {
        loadEarlierMessages();
      }
    });
    // If in offline mode, show the offline inquiry form instead of the normal chat input
    if (isOffline()) {
      showOfflineForm();
    }
  }

  // ========================
  // Unread Check
  // ========================
  // Lightweight unread count helper — fetches just the raw number from /api/chat/unread/count
  async function fetchUnreadCount() {
    if (!state.sessionId) return 0;
    try {
      let ucUrl = CONFIG.apiBase + '/unread/count?session_id=' + encodeURIComponent(state.sessionId);
      if (state.sessionSig) ucUrl += '&session_sig=' + encodeURIComponent(state.sessionSig);
      const res = await fetch(ucUrl);
      if (!res.ok) return 0;
      // Extract recovered session sig from response header
      const recoveredSig = res.headers.get('X-Chat-Session-Sig');
      if (recoveredSig) {
        state.sessionSig = recoveredSig;
        localStorage.setItem(CONFIG.sigKey, state.sessionSig);
      }
      const text = await res.text();
      return parseInt(text) || 0;
    } catch (e) {
      return 0;
    }
  }

  let _backgroundTimer = null;
  let _lastCheckedUnreadCount = 0;
  let _backgroundCheckInitialized = false;

  async function checkUnreadOnLoad() {
    if (!state.sessionId) return;
    const count = await fetchUnreadCount();
    if (count > 0) {
      updateUnreadBadge(count);
      // No sound on page load — only badge
    }
  }

  // ========================
  // Background Notification Check (runs regardless of chat state)
  // ========================
  async function hasHumanAdminMessages() {
    // Fetch the latest messages and check if any are human (non-auto) admin replies
    if (!state.sessionId) return false;
    try {
      let path = '/messages?session_id=' + encodeURIComponent(state.sessionId);
      if (state.sessionSig) path += '&session_sig=' + encodeURIComponent(state.sessionSig);
      path += '&limit=5';
      const res = await fetch(CONFIG.apiBase + path);
      const data = await res.json();
      if (data.data && data.data.length > 0) {
        return data.data.some(function (m) {
          return m.sender_type === 'admin' && m.auto_reply != 1;
        });
      }
    } catch (e) {}
    return false;
  }

  function startBackgroundCheck() {
    stopBackgroundCheck();
    if (!state.sessionId) return;
    _backgroundCheckInitialized = false;
    _lastCheckedUnreadCount = 0;

    _backgroundTimer = setInterval(async function () {
      if (!state.sessionId) return;
      try {
        const count = await fetchUnreadCount();

        // On first poll, just set baseline — no sound
        if (!_backgroundCheckInitialized) {
          _backgroundCheckInitialized = true;
          _lastCheckedUnreadCount = count;
          updateUnreadBadge(count);
          return;
        }

        // If count increased since last check, verify it's a human reply before sounding
        if (count > _lastCheckedUnreadCount) {
          const isHumanReply = await hasHumanAdminMessages();
          if (isHumanReply) {
            playNotificationSound();

            // Show desktop notification when tab is hidden
            if (document.hidden) {
              showDesktopNotification(
                widgetSettings.chat_title || '\u09b2\u09be\u0987\u09ad \u099a\u09cd\u09af\u09be\u099f \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be',
                '\u0986\u09aa\u09a8\u09be\u09b0 ' + count + ' \u099f\u09bf \u09a8\u09a4\u09c1\u09a8 \u09ac\u09be\u09b0\u09cd\u09a4\u09be \u098f\u09b8\u09c7\u099b\u09c7'
              );
            }
          }
        }

        _lastCheckedUnreadCount = count;

        // Update badge regardless of chat state
        updateUnreadBadge(count);

        // Title + favicon notification when tab is hidden
        if (document.hidden && count > 0) {
          startTitleNotification(count);
        }
      } catch (e) {}
    }, 10000);
  }

  function stopBackgroundCheck() {
    if (_backgroundTimer) {
      clearInterval(_backgroundTimer);
      _backgroundTimer = null;
    }
  }

  // Stop background check when user opens chat (full polling takes over)

  // ========================
  // Admin Online Status Check
  // ========================
  let _adminStatusTimer = null;

  async function updateAdminStatus() {
    if (!els.statusDot) return;
    try {
      const res = await fetch('/api/chat/admin/status');
      const data = await res.json();
      if (data.status === 'success' && data.data) {
        const isOnline = data.data.online;
        const isOfflineMode = isOffline();
        const isActive = isOnline && !isOfflineMode;

        // Update the status dot using classList (preserves base class)
        els.statusDot.classList.toggle('online', isActive);
        els.statusDot.classList.toggle('offline', !isActive);

        // Update the subtitle to show online/offline status
        if (els.headerSubtitle) {
          const statusText = isActive
            ? (widgetSettings.chat_subtitle || '\u09b8\u09cd\u09ae\u09be\u09b0\u09cd\u099f \u0987\u0989\u09a8\u09bf\u09af\u09bc\u09a8 \u09aa\u09b0\u09bf\u09b7\u09a6')
            : '\u0985\u09ab\u09b2\u09be\u0987\u09a8';
          els.headerSubtitle.textContent = statusText;
        }
      }
    } catch (e) {
      // Silently fail — indicator stays at its current state
    }
  }

  function startAdminStatusCheck() {
    stopAdminStatusCheck();
    // Initial check
    updateAdminStatus();
    // Poll every 30 seconds
    _adminStatusTimer = setInterval(updateAdminStatus, 30000);
  }

  function stopAdminStatusCheck() {
    if (_adminStatusTimer) {
      clearInterval(_adminStatusTimer);
      _adminStatusTimer = null;
    }
  }

  // Clean up admin status timer on page unload
  window.addEventListener('beforeunload', stopAdminStatusCheck);

  // ========================
  // Initialize
  // ========================
  async function init() {
    if (window.location.pathname.indexOf('/chat/admin') === 0) return;
    if (window.location.pathname.indexOf('/settings/chat') === 0) return;

    await fetchSettings();
    if (widgetSettings.chat_enabled !== '1') return;

    _originalTitle = document.title;

    buildWidget();
    checkUnreadOnLoad();
    startBackgroundCheck();
    startAdminStatusCheck();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
