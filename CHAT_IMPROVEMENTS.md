# Live Chat System - Improvement Plan

## Current State Summary

The live chat system is a custom-built solution using:
- **Backend**: Plain PHP + MySQLi
- **Frontend**: Vanilla JavaScript, Twig templates, Bootstrap
- **Database**: 4 tables (chat_sessions, chat_messages, chat_canned_responses, chat_rate_limits)

### Current Features
- Floating chat widget with mobile responsive design
- Visitor and admin messaging
- File upload (images, PDF, DOC, XLS, ZIP up to 10MB) with MIME validation
- Emoji picker with 80+ emojis
- Typing indicators (visitor ↔ admin bidirectional)
- Canned/quick responses for admins (CRUD + search)
- AI auto-reply bot (keyword-based, uses canned responses)
- Offline mode with time-based scheduling
- Offline inquiry form (name, phone, email, message) with admin view
- Notifications: tab title, favicon badge, desktop notifications, notification sound
- Chat history with load earlier / infinite scroll
- Read status (single/double check marks)
- Copy button on messages
- Clipboard image paste for upload
- Admin panel with conversation list, unread badges, close conversation
- Conversation search (text) + status filter (active/closed/all)
- Offline messages admin view (paginated, mark read, delete)
- Settings page for widget customization with live preview
- Bengali language interface
- REST API (8 visitor + 9 admin endpoints incl. offline messages)
- Auto database migration (CREATE TABLE IF NOT EXISTS)
- Auto session cleanup (30-day retention)
- Rate limiting (all 8 visitor endpoints)
- 3-layer XSS protection (HTMLPurifier + DOMPurify + textContent)

---

## Improvement Areas

### 1. Security & Hardening

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **Rate Limiting** | All visitor endpoints are rate-limited | Add rate limiting middleware per IP/session | ✅ **Done** |
| **Session Security** | UUID only, no signature | Add HMAC signature to session_id with constant-time verification | ✅ **Done** |
| **XSS Risk** | Messages sanitized via HTMLPurifier (server) + DOMPurify (client) + textContent | 3-layer protection implemented | ✅ **Done** |
| **SQL Injection** | Subqueries in admin conversations | Rewrote with 2 derived tables + JOINs | ✅ **Done** |
| **File Upload** | .htaccess blocks script execution in upload dir | Deny rules for PHP, PY, PL, etc. | ✅ **Done** |
| **CSRF on visitor API** | Only admin APIs have CSRF check | Add CSRF or Origin validation for visitor endpoints | ✅ **Done** |
| **Input Validation** | Basic length checks only | Add regex validation for names, phone, etc. | ❌ Pending |
| **File Name XSS** | File name in message text not sanitized | Applied chatSanitizeMessage() to file names in upload | ✅ **Done** |

### 2. Real-time Communication

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **Polling Only** | 4-second polling for messages, 10s background check | Implement WebSocket (Ratchet) or Server-Sent Events | ❌ Pending |
| **High Server Load** | Multiple clients polling every 4-10s | WebSocket reduces server round-trips | ❌ Pending |
| **Message Delay** | Up to 4s delay for new messages | Real-time push eliminates delay | ❌ Pending |

### 3. Admin Panel Improvements

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **Search/Filter** | Text search + status dropdown (active/closed/all) | Search by name, union, message content | ✅ **Done** |
| **Pagination** | Loads all conversations at once | Add offset-based pagination with load-more button, smart refresh | ✅ **Done** |
| **Analytics** | No statistics dashboard | Add chat volume, response time, satisfaction metrics | ❌ Pending |
| **Agent Assignment** | All admins see all chats | Assign chats to specific agents | ❌ Pending |
| **Chat Transfer** | Cannot transfer between agents | Add transfer functionality | ❌ Pending |
| **User Ban/Block** | Cannot block abusive visitors | Add IP blocking and visitor banning | ❌ Pending |

### 4. Visitor Experience

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **Pre-chat Form** | Optional name/union fields (shown on first interaction) | Configurable via settings | ✅ **Done** |
| **Department Selection** | Single general queue | Add department/category routing | ❌ Pending |
| **Chat Rating** | No feedback mechanism | Add 1-5 star rating after chat ends | ❌ Pending |
| **Email Transcript** | Cannot save conversation | Add email transcript option | ❌ Pending |
| **Offline Form** | Offline message shown, input still works | Add detailed offline inquiry form + admin view with pagination, mark read, delete | ✅ **Done** |
| **Language Preference** | Fixed Bengali | Add English/Bangla toggle | ❌ Pending |

### 5. Database & Performance

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **Index Optimization** | Basic indexes only | Add composite indexes for common queries | ❌ Pending |
| **Message Archiving** | All messages in one table | Archive old messages to separate table | ❌ Pending |
| **Caching** | Every request hits database | Add Redis/Memcached for settings and canned responses | ❌ Pending |
| **N+1 Queries** | Subqueries in conversation list | Rewrote with 2 derived tables (MAX(id) + GROUP BY) | ✅ **Done** |
| **Data Retention** | Auto-delete for closed sessions > 30 days | Cleanup endpoint + 5% auto-trigger on admin load | ✅ **Done** |
| **Lightweight Endpoint** | /api/chat/unread/count returns plain text | ~1 byte response, no JSON parsing needed | ✅ **Done** |

### 6. Code Quality

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **Monolithic Controller** | 941 lines in one file | Created ChatService class in modules/Services/ — 10 helper functions moved | ✅ **Done** |
| **JS var usage** | admin.twig used `var` inconsistently with chat.js | Converted all 50+ `var` to `let`/`const` | ✅ **Done** |
| **No Unit Tests** | Zero test coverage | Add PHPUnit tests for API endpoints | ❌ Pending |
| **No Error Logging** | Errors only in browser | Add structured logging to file/database | ❌ Pending |
| **No API Versioning** | Single API version | Add /api/v1/ prefix for future upgrades | ❌ Pending |
| **Input Sanitization** | Inline sanitization | Use existing SanitizationService consistently | ❌ Pending |
| **Hardcoded Values** | Color, limits, paths hardcoded | Move all to settings/config | ❌ Pending |

### 7. Feature Enhancements

| Feature | Description | Priority | Status |
|---------|-------------|----------|--------|
| **AI Auto-Reply Bot** | FAQ-based auto responses using canned responses | High | ✅ **Done** |
| **Image Preview Modal** | Click to enlarge images in chat | Medium | ❌ Pending |
| **Mobile App Push** | PWA with service workers | Medium | ❌ Pending |
| **Message Reactions** | Emoji reactions on messages | Low | ❌ Pending |
| **File Preview** | Preview PDF/DOC without download (PDF embed, Google Docs Viewer for Office docs, TXT fetch) | Medium | ✅ **Done** |
| **Keyboard Shortcuts** | Enter to send, Esc to close | Low | ✅ **Done** |
| **Notification Sound** | Web Audio API notification chime | Low | ✅ **Done** |
| **Favicon Badge** | Red circle with unread count on favicon | Low | ✅ **Done** |
| **Tab Title Notification** | Flashes "(N) New message" when tab in background | Low | ✅ **Done** |
| **Desktop Notification** | Browser Notification API for admin replies | Low | ✅ **Done** |
| **Background Notifications** | Sound + badge + desktop notify when chat closed | Low | ✅ **Done** |

### 8. Infrastructure & DevOps

| Issue | Current State | Improvement | Status |
|-------|--------------|-------------|--------|
| **No CDN** | Assets served locally | Use CDN for static assets | ❌ Pending |
| **No Compression** | No gzip/brotli | Enable server compression | ❌ Pending |
| **No Monitoring** | No uptime/performance tracking | Add monitoring (UptimeRobot, Pingdom) | ❌ Pending |
| **No Backup Strategy** | Manual backups only | Automated daily database backups | ❌ Pending |
| **No CI/CD** | Manual deployment | Add GitHub Actions or similar | ❌ Pending |

---

## Implementation Priority

### Phase 1 (Critical - Completed ✅)

| # | Task | Status |
|---|------|--------|
| 1 | Rate limiting on all API endpoints | ✅ **Done** |
| 2 | HMAC signature on session IDs (all 8 visitor endpoints now validate) | ✅ **Done** |
| 3 | File upload directory hardening (.htaccess) | ✅ **Done** |
| 4 | Fix N+1 queries in admin conversation list | ✅ **Done** |
| 5 | Add auto-delete policy for old messages | ✅ **Done** |

### Phase 2 (High - Partially Complete)

| # | Task | Status |
|---|------|--------|
| 6 | WebSocket implementation for real-time messaging | ❌ Pending |
| 7 | Admin search and filter functionality | ✅ **Done** |
| 8 | Chatbot/auto-reply for FAQ | ✅ **Done** |
| 9 | XSS protection with DOMPurify (frontend) + HTMLPurifier (backend) | ✅ **Done** |
| 10 | Add CSRF validation to visitor API endpoints | ✅ **Done** |

### Phase 3 (Medium - Pending)

| # | Task | Status |
|---|------|--------|
| 11 | Analytics dashboard | ❌ Pending |
| 12 | Agent assignment system | ❌ Pending |
| 13 | Chat rating/feedback | ❌ Pending |
| 14 | Email transcript option | ❌ Pending |
| 15 | Pre-chat form with department selection | ❌ Pending |

### Phase 4 (Low - Pending)

| # | Task | Status |
|---|------|--------|
| 16 | PWA support | ❌ Pending |
| 17 | Message reactions | ❌ Pending |

---

## Technology Recommendations

### For Real-time (Phase 2)
- **Ratchet** (PHP WebSocket library) - Already in PHP ecosystem
- **Pusher** or **Ably** - Managed WebSocket service (easier)
- **Socket.io** with Node.js microservice - If team knows Node.js

### For Caching (Phase 1)
- **Redis** - Fast key-value store for settings, canned responses
- Already used? Check `config/cache.php` or similar

### For Frontend (Phase 3)
- **DOMPurify** - Client-side XSS protection ✅ **Already integrated**

### For Testing (Phase 1)
- **PHPUnit** - Backend unit tests
- **Playwright** or **Cypress** - End-to-end tests

---

## Quick Wins (Completed: 4/5 ✅)

| Task | Effort | Impact | Status |
|------|--------|--------|--------|
| Add `.htaccess` to uploads folder | 30 min | High (security) | ✅ **Done** |
| Add rate limiting function | 2 hours | High (prevent spam) | ✅ **Done** |
| Add `cache-control` headers | 1 hour | Medium (performance) | ❌ Pending |
| Add `rel="noopener noreferrer"` to external links | 30 min | Medium (security) | ✅ **Done** |
| Add favicon badge for unread | 2 hours | Low (UX) | ✅ **Done** |

---

## Notes

- ✅ **var → let/const**: admin.twig JS converted from `var` to `let`/`const` for consistency with chat.js
- ✅ **File name XSS**: Uploaded file names now sanitized through `chatSanitizeMessage()` before display
- ✅ **Auto-reply bot**: Keyword-based auto-replies using canned responses with bot-loop prevention (30s cooldown)
- ✅ **Conversation search**: Text search by name/union/message + status filter dropdown
- ✅ **Conversation pagination**: Offset-based load-more in admin dashboard (50 per page), smart refresh preserves loaded pages
- ✅ **HMAC session signing**: All 8 visitor endpoints validate HMAC-SHA256 signatures. Automatic signing for new sessions, backward-compatible with legacy sessions. `chat_rate_limits` column auto-migrated.
- ✅ **ChatService refactoring**: 10 helper functions moved from ChatController.php (941 lines → ~550 lines) to `modules/Services/ChatService.php` following existing AuthService pattern
- ✅ **Offline messages admin view**: Dedicated admin page `/chat/admin/offline` with paginated card UI, mark as read, delete, and unread count badges. API: `GET /api/chat/admin/offline`, `GET /.../count`, `POST /.../{id}/read`, `POST /.../{id}/delete`
- Current system uses **localStorage** for session management - consider moving to secure HttpOnly cookies for visitor sessions
- The `chatAutoMigrate()` function runs on every request - consider moving to a proper migration system
- Canned responses are hardcoded on first run - consider adding a seed mechanism
- Typing indicator uses 5-second timeout - this could be configurable in settings
