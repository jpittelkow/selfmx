# Sourdough Port: v0.10.1 → v0.10.3 (+ v0.9.0 Backfill)

Port changes from Sourdough upgrade guide (v0.8.6 → v0.10.3) that haven't been applied to selfmx yet. The previous port ([sourdough-port-roadmap.md](sourdough-port-roadmap.md) + [design-review-roadmap.md](design-review-roadmap.md)) covered v0.9.2 → v0.10.0.

**Status**: Complete
**Source**: Sourdough upgrade guide `upgrade-guide-0.8.6-to-0.10.3.md`
**Scope**: 4 phases — v0.9.0 backfill, v0.10.1, v0.10.2, v0.10.3

---

## Phase 1: v0.9.0 Backfill (Missing Items)

Items from the v0.9.0 release that weren't included in the previous port. Many v0.9.0 changes (API audit, webhook encryption, rate limiting, UserService deleteUser, Dependabot, bug tracker) were already done via the [code-review-remediation-roadmap.md](code-review-remediation-roadmap.md).

### Backend

- [x] **DeprecateRoute middleware** — RFC 8594 route deprecation headers. `app/Http/Middleware/DeprecateRoute.php` with `Deprecation`, `Sunset`, and `Link` headers. Registered as `deprecate` alias in `bootstrap/app.php`
- [x] **FileHelper utility class** — `app/Helpers/FileHelper.php` with `humanReadableSize()`, `detectMimeType()`, `sanitizeFilename()`, `extension()`, `isMimeTypeAllowed()`
- [x] **QueryHelper utility class** — `app/Helpers/QueryHelper.php` with `escapeLike()`, `whereLike()`, `applySorting()`, `whereDateRange()`
- [x] **Consolidate NotificationSettingsController** — Deleted deprecated `NotificationSettingsController.php`. All routes already use `UserNotificationSettingsController`. Removed stale comment from `routes/api.php`

### Tests

- [x] **AuditLogControllerTest** — Backend test suite for audit log endpoints (index, export, stats, auth). No AccessLogController exists — tested AuditLogController instead
- [x] **ProfileControllerTest** — Backend test suite for profile endpoints (show, update, password, avatar upload/delete, destroy)
- [x] **SystemSettingControllerTest** — Backend test suite for system settings endpoints (index, publicSettings, show, update)
- [x] **use-permission.test.ts** — Frontend tests for `usePermission` and `usePermissions` hooks
- [x] **validation-schemas.test.ts** — Created `lib/validation-schemas.ts` with shared Zod schemas (email, password, URL, hex color, cron, etc.) and comprehensive test suite

---

## Phase 2: v0.10.1 — Component Decomposition

### Notification Configuration Tab Extraction

- [x] **Channels tab component** — `components/notifications/channels-tab.tsx` with channel availability toggles, SMS provider selector, verify configuration
- [x] **Credentials tab component** — `components/notifications/credentials-tab.tsx` with all channel credential cards and schemas
- [x] **Rate limiting tab component** — `components/notifications/rate-limiting-tab.tsx` with queue and rate limit settings
- [x] **Email tab component** — `components/notifications/email-tab.tsx` linking to dedicated email config page
- [x] **Novu tab component** — `components/notifications/novu-tab.tsx` linking to dedicated Novu config page
- [x] **Templates tab component** — `components/notifications/templates-tab.tsx` linking to dedicated templates page
- [x] **Refactor notification config page** — Replaced 1098-line monolithic page with tabbed layout importing 6 tab components (~115 lines)

### AI Provider Dialog Decomposition

- [x] **ProviderCredentialFields sub-component** — `components/ai/provider-credential-fields.tsx` with API key, Azure endpoint, Bedrock credentials, Ollama host fields
- [x] **ProviderModelSelection sub-component** — `components/ai/provider-model-selection.tsx` with model discovery, sessionStorage caching (1-hour TTL), and selection
- [x] **Update provider dialog** — Refactored `provider-dialog.tsx` to import sub-components

### Two-Factor Authentication

- [x] **2FA service improvements** — Reviewed TwoFactorService. Already well-structured. TOTP padding fix applied (Phase 3). Password re-confirmation removal applied (Phase 3)

---

## Phase 3: v0.10.2 — Backend Improvements & Bug Fixes

### New Features

- [x] **Backup upload endpoint** — `POST /api/backup/upload` stores backup files without triggering restore. Validates file type/size, sanitizes filename, logs via AuditService
- [x] **Queued notification delivery stats card** — Added "Queued" status to delivery log stats grid (5 columns: success, failed, queued, rate_limited, skipped)
- [x] **LLM model discovery credential fallback** — Added `provider_id` parameter to test-key and discover-models endpoints. Falls back to stored encrypted credentials when API key not provided

### Changes

- [x] **Remove password re-confirmation from 2FA endpoints** — Removed `current_password` validation from `disable()`, `recoveryCodes()`, `regenerateRecoveryCodes()` in TwoFactorController
- [x] **Queue worker database connection** — Added `--connection=database` to supervisord queue worker command
- [x] **Breadcrumb "user" segment non-navigable** — Already ported (see "Already Ported" table)

### Bug Fixes

- [x] **Fix 2FA TOTP verification** — Added `rtrim($secret, '=')` to strip padding before verification in `TwoFactorService::verifyCode()`
- [x] **Fix avatar image stretching** — Added `object-cover` to `AvatarImage` component in `components/ui/avatar.tsx`
- [x] **Fix changelog page list styling** — Changed to proper `list-disc` with `ml-4` margin

---

## Phase 4: v0.10.3 — Notification Permissions

### Notification Permission Flow

- [x] **Notification permission guided flow in onboarding** — Updated `notifications-step.tsx` with browser push permission request button showing granted/denied/default states
- [x] **Notification permission banner component** — Created `components/notifications/notification-permission-banner.tsx` with dismissible banner and localStorage tracking
- [x] **useNotificationPrompt hook** — Created `lib/use-notification-prompt.ts` with session-scoped prompt limiting

### Bug Fixes

- [x] **Fix avatar image stretching (v0.10.3)** — Covered by the Phase 3 fix (applied to `AvatarImage` base component)

---

## Release Script Change (v0.10.3)

> **Note:** Sourdough v0.10.3 changed the release script to require manual changelog entries instead of auto-generating from commits. selfmx already has its own release process via `scripts/push.ps1` — evaluate whether this change is relevant.

- [x] **Evaluate release script changelog behavior** — selfmx's `push.ps1` auto-generates changelog from commits, which works well. No change needed — the Sourdough approach is not relevant here

---

## Dashboard Redesign & Changelog AI Export (Skipped)

These items from the original roadmap were removed as not needed for selfmx:
- Dashboard redesign (8 tasks) — existing dashboard is sufficient
- Changelog AI export (5 tasks) — not needed

---

## Already Ported (Skip)

These items from the upgrade guide are already present in selfmx:

| Feature | Source | Already In |
|---------|--------|-----------|
| API audit with consistent validation | v0.9.0 | code-review-remediation-roadmap.md |
| Encrypt webhook secrets at rest | v0.9.0 | code-review-remediation Phase 1 |
| Input sanitization / XSS prevention | v0.9.0 | code-review-remediation Phase 1 |
| Tighten rate limiting | v0.9.0 | code-review-remediation Phase 2 |
| UserService with deleteUser() | v0.9.0 | code-review-remediation Phase 3 |
| Dependabot configuration | v0.9.0 | `.github/dependabot.yml` |
| Bug tracker | v0.9.0 | `docs/plans/bug-tracker.md` |
| use-debounce hook | v0.9.0 | Already in frontend |
| Dashboard widget components | v0.10.0 | design-review-roadmap.md Workstream 8 |
| AI provider component extraction | v0.10.0 | design-review-roadmap.md Workstream 3 |
| SSO component extraction | v0.10.0 | design-review-roadmap.md Workstream 4 |
| Avatar upload component | v0.10.0 | design-review-roadmap.md Workstream 6 |
| DataTable component | v0.10.0 | design-review-roadmap.md Workstream 6 |
| Security overview dashboard | v0.10.0 | design-review-roadmap.md Workstream 6 |
| Help center TOC with scroll-spy | v0.10.0 | design-review-roadmap.md Workstream 5 |
| Profile API endpoint | v0.10.0 | sourdough-port-roadmap.md Phase 1 |
| 4 new ADRs (027-030) | v0.9.0 | Already in `docs/adr/` |
| Queued delivery status constant | v0.10.2 | NotificationDelivery model |
| NotificationsStep in onboarding | v0.10.2 | `components/onboarding/steps/` |
| Breadcrumb non-navigable segment | v0.10.2 | Already in `app-breadcrumbs.tsx` |
