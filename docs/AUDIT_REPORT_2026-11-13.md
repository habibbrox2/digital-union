# Digital Union — Security & Engineering Audit Report

- **Repository:** `habibbrox2/digital-union`
- **Reviewed branch:** `main`
- **Reviewed tree commit:** `a6539c480876fb2e3003c2faa873fcde885c7a7f`
- **Audit date:** 2026-11-13
- **Method:** Read-only static source review

> This is a source-code audit, not a penetration test. Production secrets, database records, and live endpoints were not accessed. No application code, production data, or deployment configuration was changed during the audit.

## 1. Executive summary

The highest-risk areas are Geo import SSRF, disabled TLS verification in BDRIS requests, inconsistent active/deleted-account authorization checks, weak fallback secrets, unstable encryption-key fallback, and inconsistent upload validation. CI also does not run the existing PHPUnit suite.

Severity is based on potential impact and source evidence. Some findings are conditional on production configuration and are marked accordingly.

## 2. Findings

### SEC-01 — Geo import may permit server-side request forgery (SSRF)
**Severity: HIGH | Confidence: High**

**Files:** `app/Http/Controllers/GeoController.php`, `app/Services/GeoService.php`

The Geo import endpoint accepts a user-provided `xml_url`, validates only that it is a URL, then fetches it with cURL without a strict host/IP allowlist, redirect-target validation, or explicit time/response-size limits.

**Risk:** An authenticated user with Geo-management permission may cause the server to request internal or restricted network endpoints.

**Remediation:** Allow only HTTPS and exact trusted source hosts; reject loopback, private, link-local, multicast and reserved IP ranges; disable redirects or revalidate every redirect; restrict cURL protocols; set connection/total timeouts and response-size limits; parse XML with network access disabled. Add tests for localhost, private IPs, unsafe redirects, unsupported schemes and oversized responses.

### SEC-02 — BDRIS TLS certificate verification is disabled
**Severity: HIGH | Confidence: High**

**File:** `app/Helpers/bdris_helper.php`

The cURL configuration sets `CURLOPT_SSL_VERIFYPEER => false` and `CURLOPT_SSL_VERIFYHOST => 0`.

**Risk:** The remote TLS endpoint is not authenticated. Personal/birth-registration data may be exposed to interception or modification.

**Remediation:** Enable peer and hostname verification, configure a current CA bundle, fail closed on certificate errors, and test invalid-certificate rejection.

### SEC-03 — Authorization does not consistently enforce active/deleted account state
**Severity: HIGH | Confidence: High**

**Files:** `app/Services/AuthService.php`, `app/Models/PermissionsManager.php`

Some role and permission checks load a user's role or evaluate superadmin privileges without consistently requiring `status = 'active'` and `is_deleted = 0`. The deleted-user filter in `getUserData()` is not consistently used as the authorization gate.

**Risk:** A disabled or soft-deleted account may retain authorization through an existing session or privileged-role shortcut.

**Remediation:** Centralize principal loading; require active/not-deleted state before every authorization decision, including superadmin shortcuts; revoke sessions after disable/delete/role changes (for example, with a session-version field); test existing sessions across all role levels.

### SEC-04 — Chat signing secret has a predictable fallback
**Severity: HIGH if fallback is active | Confidence: High for code path; production configuration not inspected**

**File:** `app/Services/ChatService.php`

When `CHAT_SESSION_SECRET` is missing or too short, the service derives a fallback from `__FILE__` and `SERVER_NAME`. Legacy sessions with an empty stored signature are also treated as valid.

**Risk:** The fallback is deterministic and may be discoverable. If used in production, it is not a safe signing secret.

**Remediation:** Require a random 32+ byte secret and fail closed if absent/short; never derive secrets from public metadata. Migrate legacy unsigned sessions and then reject them. Verify presence without exposing the secret value.

### SEC-05 — Encryption key silently falls back to a random runtime key
**Severity: HIGH when `ENCRYPTION_KEY` is absent | Confidence: High**

**File:** `config/config.php`

If `ENCRYPTION_KEY` is missing, the application generates a random key at runtime.

**Risk:** The key may differ between requests, making previously encrypted data undecryptable.

**Remediation:** Require a stable configured key in every non-development environment and fail fast if absent or malformed. Store it outside source control and implement a documented key-rotation strategy.

### SEC-06 — Upload validation is inconsistent
**Severity: HIGH | Confidence: High**

**Files:** `app/Services/FileUploadService.php`, `app/Services/SettingService.php`, `app/Models/UserModel.php`, `public/.htaccess`

Evidence includes SVG and uncommon image formats being accepted, MIME checks based only on an `image/` prefix, document validation relying mainly on extensions, organization-logo validation trusting client-provided MIME, and union logo/stamp uploads being saved with `.png` names without validating actual content. Upload-directory protections are inconsistent, and directory listing is commented out in the public .htaccess.

**Risk:** Spoofed, malformed, oversized or active-content files may be accepted and publicly served.

**Remediation:** Centralize uploads. Validate upload errors, `is_uploaded_file()`, size, extension and server-detected MIME; re-encode raster images; disallow SVG unless sanitized and isolated; use random filenames; store private documents outside the web root and serve through authorization checks; disable script execution and directory listing in all upload directories; send `nosniff`.

### SEC-07 — CSRF fallback is not reliably bound to the current session
**Severity: MEDIUM | Confidence: High**

**File:** `config/csrf.php`

When the session token does not match, the fallback accepts a token matching the JavaScript-readable `csrf_cookie` and copies it into the session. A query-string token is also accepted.

**Risk:** This weakens session-bound CSRF protection if an attacker can inject/control the cookie; query tokens may leak through browser history and logs.

**Remediation:** Reject session-token mismatches rather than restoring from an unsigned cookie. If double-submit is required, sign/bind it to session context. Accept tokens only from POST body or a dedicated header, never query strings.

### SEC-08 — Password-reset URL trusts the Host header
**Severity: MEDIUM | Confidence: High**

**File:** `app/Models/AuthManager.php`

Password-reset URLs are built from `HTTP_HOST`; HTTPS detection based only on `isset($_SERVER['HTTPS'])` does not reject the value `off`.

**Risk:** If host headers are not strictly validated, reset emails may contain attacker-controlled links.

**Remediation:** Build reset URLs from a trusted canonical `APP_URL`/site URL configuration and enforce HTTPS.

### SEC-09 — Password-reset tokens and reset flow need hardening
**Severity: MEDIUM | Confidence: High**

**Files:** `app/Models/AuthManager.php` and password-reset handling code

Reset tokens are stored in plaintext. The reset path has no clearly enforced app-level per-IP/account throttle and accepts a shorter password than the shared registration policy.

**Risk:** Database disclosure exposes valid reset tokens; weak throttling and inconsistent password policy increase account-takeover risk.

**Remediation:** Store token hashes, consume tokens atomically, add per-IP and per-account rate limits, use one shared password policy, and invalidate existing sessions after a successful reset.

### SEC-10 — Security headers helper has no confirmed production call site
**Severity: MEDIUM | Confidence: High for repository search; runtime headers not inspected**

**Files:** `app/Helpers/security.php`, `public/.htaccess`

The helper defines CSP and other security headers, but no production call site was found in the reviewed source. Corresponding .htaccess header directives are commented out.

**Risk:** Intended browser-side protections may not be present in actual responses.

**Remediation:** Invoke the header helper centrally before output or configure headers at the web server; verify actual production HTTP responses after deployment.

### SEC-11 — Logout uses GET
**Severity: LOW | Confidence: High**

**File:** `app/Http/Controllers/LoginController.php`

The logout route uses GET and destroys the session.

**Risk:** Cross-site navigation may trigger logout unexpectedly.

**Remediation:** Change logout to POST and require a valid CSRF token.

### DATA-01 — Application files are deleted before database deletion is confirmed
**Severity: MEDIUM | Confidence: High**

**File:** `app/Services/ApplicationService.php`

The application deletion flow removes files before confirming the database deletion.

**Risk:** A database failure may leave records pointing to missing files.

**Remediation:** Use a transaction-aware/two-phase cleanup or outbox job; canonicalize and constrain file paths.

### DATA-02 — SQL migration safety relies on fragile parsing
**Severity: MEDIUM | Confidence: High**

**File:** `app/Models/SqlMigrationModel.php`

Regex-based dangerous-SQL classification and line-based parsing are not robust SQL parsers.

**Risk:** Comments, compound statements and grammar edge cases may bypass intended safeguards or break migrations.

**Remediation:** Restrict execution to reviewed migrations, require explicit privileged confirmation for destructive operations, and use a tested migration framework/parser.

### DATA-03 — mysqldump password is passed in process arguments
**Severity: MEDIUM | Confidence: High**

**File:** `app/Models/SqlMigrationModel.php`

The backup command includes `--password=...` in the command line.

**Risk:** Process diagnostics may expose command-line arguments.

**Remediation:** Use a temporary `--defaults-extra-file` with strict permissions or another credential mechanism that avoids password-in-argv; clean temporary files reliably and keep backups outside the document root.

### OPS-01 — CI does not run the existing PHPUnit suite
**Severity: HIGH for release confidence | Confidence: High**

**Files:** `.github/workflows/deploy.yml`, `tests/`

The workflow performs Composer/PHP checks but does not execute the existing PHPUnit suite.

**Risk:** Changes may pass CI while breaking authentication, RBAC or business workflows.

**Remediation:** Run PHPUnit in CI, configure a disposable integration-test database, and add static analysis and dependency vulnerability scanning.

### OPS-02 — CI PHP version differs from configured hosting handler
**Severity: MEDIUM | Confidence: High**

**Files:** `.github/workflows/deploy.yml`, `public/.htaccess`

CI tests PHP 8.2 while the tracked hosting handler selects PHP 8.4.

**Risk:** CI may miss runtime-specific issues.

**Remediation:** Test the production PHP version and any intentionally supported minimum version in a CI matrix.

### OPS-03 — Third-party deployment actions are tag-pinned
**Severity: MEDIUM | Confidence: High**

**File:** `.github/workflows/deploy.yml`

Third-party actions are referenced by version tags rather than full commit SHAs.

**Risk:** Mutable upstream tags create supply-chain risk for deployment workflows.

**Remediation:** Pin actions to reviewed full SHAs, require production environment approval, prefer SFTP or verified FTPS, and deploy a tested immutable artifact.

### ARCH-01 — Authentication and authorization logic is duplicated
**Evidence:** `AuthManager`, `AuthService`, `PermissionsManager` and RBAC helpers implement overlapping identity/session/permission behavior.

**Recommendation:** Centralize principal loading and policy evaluation in one authorization service. Controllers should use one policy API; template flags must not be the security boundary.

### ARCH-02 — Upload handling is duplicated
**Evidence:** Applicant, profile, organization-logo, union-logo and chat uploads use separate validation and naming rules.

**Recommendation:** Build one upload abstraction with explicit policies for each upload category and centralized MIME, size, storage and access enforcement.

### ARCH-03 — Production configuration should fail closed
**Evidence:** Encryption and chat signing configuration have fallback behavior.

**Recommendation:** Validate required environment variables centrally at bootstrap. Production should refuse to start when critical secrets are missing or malformed.

### PERF-01 — Union listing has no upper pagination limit
**File:** `app/Models/UnionModel.php`

The listing limit is clamped to a minimum but has no maximum.

**Recommendation:** Enforce a maximum page size (for example, 100), validate page/offset ranges and review indexes using query plans.

### PERF-02 — Chat schema migration check runs during controller registration
**Files:** `app/Http/Controllers/ChatController.php`, `app/Services/ChatService.php`

The controller invokes `autoMigrate()` during registration/bootstrap.

**Recommendation:** Move schema migrations to a CLI/deployment step and remove DDL privileges from the normal web database user.

## 3. Recommended remediation order

1. SEC-01: Restrict Geo import SSRF.
2. SEC-02: Restore BDRIS TLS verification.
3. SEC-03: Enforce active/deleted account state in every authorization path.
4. SEC-04: Require a strong chat signing secret and retire unsigned legacy sessions.
5. SEC-05: Require a stable encryption key.
6. SEC-06: Centralize and harden uploads.
7. OPS-01: Run PHPUnit before deployment.
8. SEC-08/09: Harden reset URL, token storage, throttling and password policy.
9. SEC-07/10/11: Fix CSRF recovery, apply headers and use POST logout.
10. DATA-01/02/03: Make file cleanup consistent and harden migration/backup operations.
11. OPS-02/03: Align PHP versions and pin deployment actions.
12. ARCH/PERF: Centralize policies, move migrations out of web bootstrap and cap pagination.

## 4. Staging verification checklist

- [ ] Geo import rejects localhost, private/link-local IPs, unsupported schemes and unsafe redirects.
- [ ] Geo import enforces connection/total timeouts and response-size limits.
- [ ] BDRIS rejects invalid TLS certificates.
- [ ] Inactive/deleted users receive 401/403 on protected routes, including existing sessions and privileged-role paths.
- [ ] Missing/short `CHAT_SESSION_SECRET` prevents production chat from starting.
- [ ] Missing `ENCRYPTION_KEY` prevents production startup; no random fallback is used.
- [ ] Uploads are MIME/content validated, size-limited and stored with safe names.
- [ ] Upload directories cannot execute scripts or be browsed.
- [ ] Password-reset emails always use the configured canonical HTTPS host.
- [ ] Reset tokens are hashed, atomically consumed and rate-limited.
- [ ] CSRF mismatch is rejected without cookie-to-session restoration.
- [ ] Global security headers appear in actual HTTP responses.
- [ ] Logout requires POST and a valid CSRF token.
- [ ] PHPUnit and static analysis run before deployment.
- [ ] CI tests the production PHP runtime.
- [ ] Backup/restore and migration rollback are tested on staging.

## 5. Final assessment

The project has useful foundations, including broad prepared-statement usage, session ID regeneration at login, separated test/deploy jobs, and chat rate limiting/signing. The key weakness is inconsistent enforcement: controls exist in some paths while parallel paths use weaker alternatives.

The highest-value architectural investment is to centralize authentication, authorization, uploads, environment validation and database migrations rather than adding isolated checks to individual controllers.

This report describes the reviewed `main` tree at commit `a6539c480876fb2e3003c2faa873fcde885c7a7f`. It is a static source assessment; production behavior and configuration-dependent findings must be verified in staging.
