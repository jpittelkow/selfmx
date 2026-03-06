# Code Review Remediation Roadmap

Comprehensive remediation plan adapted from a full-repository code review covering backend (Laravel), frontend (Next.js), and infrastructure/security. Findings organized into 5 phases by severity and effort.

**Priority**: HIGH
**Status**: All Phases Complete
**Created**: 2026-03-04
**Estimated Total Effort**: ~35-40 hours

**Dependencies**:
- [Security Compliance Roadmap](security-compliance-roadmap.md) - Overlaps with security hardening
- [Docker Audit Roadmap](docker-audit-roadmap.md) - Overlaps with container hardening

---

## Task Checklist

### Phase 1: Critical Security Fixes (Day 1 — ~2 hours) ✅
- [x] Remove hardcoded Stripe live key from source code
- [x] Remove stack trace exposure from `diagnosePush` endpoint
- [x] Neutralize email enumeration endpoint (`/api/auth/check-email`)
- [x] Encrypt webhook secrets and hide from API responses
- [x] Sanitize custom CSS injection in `AppConfigProvider`
- [x] Auto-generate Reverb WebSocket secret in entrypoint

### Phase 2: High-Priority Hardening (Week 1 — ~8 hours) ✅
- [x] Escape LIKE metacharacters in all query locations
- [x] Use `cursor()`/`chunk()` for audit log exports
- [x] Add `server_tokens off` to Nginx config
- [x] Add HSTS header to security headers
- [x] Remove `unsafe-eval` from CSP (evaluate `unsafe-inline`)
- [x] Add `no-new-privileges` to docker-compose.yml
- [x] Wrap `fetchGroups` in `useCallback` in `useGroups` hook

### Phase 3: Medium-Priority Improvements (Weeks 2-3 — ~12 hours) ✅
- [x] Add transaction lock to first-user admin registration
- [x] Consolidate user deletion into `UserService::deleteUser()`
- [x] Remove 2FA/status fields from User `$fillable`, use `forceFill()`
- [x] Fix rate limiter to only count failed attempts
- [x] Standardize API response format (adopt `ApiResponseTrait` everywhere)
- [x] Extract Form Request classes for MailSetting and LLM validation
- [x] Replace deprecated `navigator.platform` usage
- [x] Type the `login` method return as `Promise<LoginResult>`
- [x] Remove `any` types from notification channel field renderer
- [x] Add debouncing to user search input
- [ ] ~~Add SHA256 verification for Meilisearch binary download~~ (skipped — Meilisearch does not publish checksums)
- [x] Add `composer audit` and `npm audit` to CI pipeline
- [x] Bind Reverb to `127.0.0.1` instead of `0.0.0.0`
- [x] Add sensitive file detection to `push.ps1`

### Phase 4: Low-Priority Cleanup (Weeks 3-4 — ~8 hours) ✅
- [x] Modernize `Notification` model from `boot()` to `booted()`
- [x] Modernize `UserGroup` from `$casts` property to `casts()` method
- [x] Add return types to model scopes (37 scopes across 10 models)
- [x] Clean up dual API token system — documented: `ApiTokenController` is legacy, `ApiKeyController` is the proper system with prefixes/rotation/revocation. Legacy kept for backward compat.
- [x] Move `formatBytes` from controller to `App\Support\Str` utility (deduplicated from 4 locations)
- [x] Fix missing `useEffect` dependencies across hooks (fixed stale closure in `mail-command-palette.tsx`)
- [x] Remove pointless catch-rethrow in `web-push.ts`
- [x] Rename `Notification` type to `AppNotification` to avoid browser API shadow
- [x] Configure log rotation for supervisor queue/scheduler (10MB max, 3 backups)
- [x] Gate `console.error` in production service worker (localhost-only)

### Phase 5: Test Coverage Expansion (Ongoing — ~12 hours) ✅
- [x] Add frontend tests for `sanitizeCss` function
- [x] Add frontend tests for auth store (2FA and error paths)
- [ ] ~~Add frontend tests for form validation schemas (Zod)~~ (deferred — schemas are inline in page components, extraction too invasive)
- [x] Add backend tests for `UserController` (CRUD, toggle admin, disable)
- [x] Add backend tests for `WebhookController` (CRUD, SSRF, delivery)
- [x] Add backend tests for `SettingController` (arbitrary key rejection)
- [x] Add backend tests for `FileManagerController` (path traversal)
- [x] Add backend tests for `JobController` (option validation)
- [x] Add backend tests for `MailSettingController`
- [x] Set up Dependabot for composer and npm dependencies

---

## Phase 1: Critical Security Fixes

**Target**: Day 1 | **Effort**: ~2 hours | **Risk if skipped**: Active exploitability

### 1.1 Remove Hardcoded Stripe Key

**Files**:
- `backend/config/stripe.php:21`
- `backend/config/settings-schema.php:220`
- `frontend/app/(dashboard)/configuration/stripe/page.tsx:302,320`

**Problem**: A live Stripe publishable key (`pk_live_51T3IOF...`) is hardcoded in source code. Forks inherit it, tying them to a specific Stripe account.

**Fix**: Replace all instances with `env('STRIPE_PLATFORM_CLIENT_ID', '')` on backend and empty string on frontend.

---

### 1.2 Remove Stack Trace Exposure from `diagnosePush`

**File**: `backend/app/Http/Controllers/Api/NotificationController.php:215-220`

**Problem**: Returns `$e->getTraceAsString()` to any authenticated user in non-production environments. Exposes file paths, class names, library versions.

**Fix**: Remove the `trace` key entirely from the error response. Log server-side instead via `Log::error()`.

---

### 1.3 Neutralize Email Enumeration Endpoint

**File**: `backend/app/Http/Controllers/Api/AuthController.php:41-49`

**Problem**: `/api/auth/check-email` reveals whether an email is registered by returning `available: !$exists`.

**Fix**: Always return `available: true`. Real uniqueness validation happens on the `register` endpoint (`'unique:users'` rule).

---

### 1.4 Encrypt Webhook Secrets and Hide from API Responses

**Files**:
- `backend/app/Models/Webhook.php`
- `backend/app/Http/Controllers/Api/WebhookController.php`

**Problem**: Webhook signing secrets stored as plaintext with no `encrypted` cast and no `$hidden` property. Secrets returned in all API responses. Compare with `AIProvider` which correctly uses `'api_key' => 'encrypted'` and `$hidden = ['api_key']`.

**Fix**:
1. Add `'secret' => 'encrypted'` to `casts()` and `protected $hidden = ['secret']` in `Webhook.php`
2. Create migration to alter column to `text` and encrypt existing plaintext secrets
3. Append `secret_set: true/false` to all WebhookController responses

---

### 1.5 Sanitize Custom CSS in `AppConfigProvider`

**Files**:
- `frontend/lib/app-config.tsx:212`
- `frontend/lib/sanitize.ts`

**Problem**: Custom CSS from settings is injected into the page without sanitization, potentially allowing CSS-based attacks (data exfiltration via `url()`, UI redressing via `@import`).

**Fix**: Add `sanitizeCss()` function to `sanitize.ts` and use it before injecting custom CSS.

---

### 1.6 Auto-Generate Reverb WebSocket Secret

**Files**:
- `docker/Dockerfile:59`
- `docker/entrypoint.sh`
- `docker-compose.yml`

**Problem**: `REVERB_APP_SECRET=selfmx-secret` is hardcoded. All default deployments share the same WebSocket auth secret.

**Fix**: Follow existing APP_KEY/MEILI_MASTER_KEY auto-generation pattern in `entrypoint.sh`. Only the secret can be auto-generated (key is baked into frontend at build time).

---

## Phase 2: High-Priority Hardening

**Target**: Week 1 | **Effort**: ~8 hours | **Risk if skipped**: Data exposure, DoS vectors

### 2.1 Escape LIKE Metacharacters

**Files**: `SearchService.php`, `ApiKeyService.php`, `ContactService.php`, `ContactController.php`, `UserController.php`

Create a shared `escapeLike()` helper and apply to all LIKE query locations:
```php
function escapeLike(string $value): string {
    return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
}
```

### 2.2 Stream Large Exports

**File**: `AuditService.php:189-192`

Replace `->get()` with `->cursor()` for CSV export queries. Add a maximum date range to prevent unbounded queries.

### 2.3 Nginx Security Headers

**Files**: `docker/nginx.conf`, `docker/nginx-security-headers.conf`

- Add `server_tokens off;` in `http` block
- Add `Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"` header
- Remove `'unsafe-eval'` from CSP `script-src`

### 2.4 Docker Container Hardening

**File**: `docker-compose.yml`

Add `security_opt: [no-new-privileges:true]` to docker-compose service.

### 2.5 Frontend Hook Fix

**File**: `frontend/lib/use-groups.ts`

Wrap `fetchGroups` in `useCallback` to prevent unnecessary re-renders.

---

## Phase 3: Medium-Priority Improvements

**Target**: Weeks 2-3 | **Effort**: ~12 hours | **Risk if skipped**: Edge-case bugs, compliance gaps

### 3.1 Backend Fixes

| Fix | File | Effort |
|-----|------|--------|
| Transaction lock on first-user registration | `AuthController.php:64-79` | 30 min |
| Consolidate user deletion logic | `ProfileController.php`, `UserController.php` | 1 hr |
| Remove 2FA fields from `$fillable` | `User.php:37-48` | 30 min |
| Fix rate limiter (count only failures) | `RateLimitSensitive.php:24-46` | 30 min |
| Standardize `ApiResponseTrait` usage | 8 controllers | 2 hrs |
| Extract Form Request classes | `MailSettingController`, `LLMController` | 1 hr |

### 3.2 Frontend Fixes

| Fix | File | Effort |
|-----|------|--------|
| Replace `navigator.platform` | `header.tsx`, `preferences/page.tsx` | 15 min |
| Type `login` return properly | `lib/auth.ts` | 10 min |
| Remove `any` types in notifications | `notifications/page.tsx` | 20 min |
| Debounce user search | `users/page.tsx` | 15 min |

### 3.3 Infrastructure Fixes

| Fix | File | Effort |
|-----|------|--------|
| SHA256 verify Meilisearch download | `Dockerfile` | 10 min |
| Add `composer audit` + `npm audit` to CI | CI workflow | 10 min |
| Bind Reverb to `127.0.0.1` | `docker/supervisord.conf` | 2 min |
| Sensitive file detection in `push.ps1` | `scripts/push.ps1` | 15 min |

---

## Phase 4: Low-Priority Cleanup

**Target**: Weeks 3-4 | **Effort**: ~8 hours | **Risk if skipped**: Tech debt, inconsistency

### 4.1 Backend Cleanup
- Modernize `Notification::boot()` to `booted()`, `UserGroup::$casts` to `casts()`
- Add `Builder` return types to model scopes
- Review dual API token systems for consolidation
- Move `DashboardController::formatBytes` to a utility

### 4.2 Frontend Cleanup
- Fix missing `useEffect` dependencies across hooks (with `useCallback`)
- Remove pointless catch-rethrow in `web-push.ts`
- Rename `Notification` to `AppNotification` to avoid browser API shadow
- Remove or gate `console.error` in production service worker

### 4.3 Infrastructure Cleanup
- Configure log rotation for supervisor queue/scheduler

---

## Phase 5: Test Coverage Expansion

**Target**: Ongoing | **Effort**: ~12 hours | **Risk if skipped**: Regressions go undetected

### 5.1 Frontend Tests (Priority Order)

| Test Area | Why | Effort |
|-----------|-----|--------|
| `sanitizeCss` | New security function needs coverage | 1 hr |
| Auth store (2FA, error paths) | Existing tests only cover happy path | 1 hr |
| Zod form validation schemas | Edge cases for each schema | 2 hrs |

### 5.2 Backend Tests (Priority Order)

| Controller | Security-Sensitive Operations | Effort |
|------------|-------------------------------|--------|
| `UserController` | CRUD, toggle admin, disable, IDOR | 2 hrs |
| `WebhookController` | CRUD, secret handling, SSRF | 1 hr |
| `FileManagerController` | Path traversal, upload validation | 1 hr |
| `SettingController` | Arbitrary key rejection | 1 hr |
| `JobController` | Option validation, permission | 30 min |
| `MailSettingController` | Provider-specific validation | 30 min |

### 5.3 CI Additions
- Set up Dependabot for `composer` and `npm` dependencies

---

## Positive Patterns to Preserve

These demonstrate strong engineering and should be maintained as standards:

- **SSRF protection** — DNS pinning, private IP blocking in `UrlValidationService`
- **Backup filename validation** — strict regex, no path traversal possible
- **Sanctum auth** — session regeneration, CSRF, cookie security
- **DOMPurify** — restrictive allowlist for HTML sanitization
- **Correlation IDs** — end-to-end request tracing
- **Encrypted API keys** — `AIProvider` model uses `encrypted` cast + `$hidden`
- **Pinned GitHub Actions** — SHA-based references for supply chain security
- **Error logger** — structured reporting with correlation IDs
- **Zod validation** — consistent schema-based form validation

---

## Items from Sourdough Review That Do NOT Apply

| Issue | Why N/A |
|-------|---------|
| `AccessLogController::deleteAll` permission | Different controller structure in selfmx |
| Settings validation against schema | Already validated via `SettingService` |
| `dangerouslySetInnerHTML` in search | Already uses `sanitizeHighlightHtml()` |
| `usePageTitle` timer leaks | Properly cleaned up in selfmx |
| Dead link `/configuration/mail` | selfmx uses `/configuration/mailboxes` |
| `usePermission` admin bypass | Backend injects all permissions for admins |
| `npm ci` in Dockerfile | Deliberate choice for cross-platform compat |
