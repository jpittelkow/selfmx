# Sourdough Port: v0.9.2 → v0.10.0

Port changes from sourdough commit [`487e110`](https://github.com/Sourdough-start/sourdough/commit/487e110ceff8499e8131af01a8b24f0872c78031) into selfmx. 33 files changed (+8,682 / -4,061) across 4 phases.

**Status**: Complete ✅
**Source**: Sourdough v0.10.0 (commit 487e110)
**Scope**: 4 phases — backend code, ADR corrections, API docs, and AI pattern docs

## Pre-Port: Already Done in selfmx

These changes from the commit are already present in our working tree (skip):

- [x] `backend/app/Models/PushSubscription.php` — Encrypted casts for endpoint, p256dh, auth
- [x] `backend/phpunit.xml` — SCOUT_DRIVER=collection for tests
- [x] `docker/Dockerfile` — Meilisearch SHA256 checksum already removed; npm kept
- [x] `docker/nginx-security-headers.conf` — CSP already has `unsafe-inline`

---

## Phase 1: Backend Code Changes

Functional code changes. Small, low-risk, independent of each other.

### Avatar Upload/Delete (ProfileController + UserService)
- [x] `backend/app/Http/Controllers/Api/ProfileController.php` — Added constructor injection (UserService, AuditService), audit logging in update/updatePassword, `uploadAvatar()` and `deleteAvatar()` methods
- [x] `backend/routes/api.php` — Routes already existed (POST/DELETE /api/profile/avatar)
- [x] `backend/app/Services/UserService.php` — Added avatar file cleanup, `performedByUserId` param, onboarding cleanup, Storage/Schema imports

### User Sorting (UserController)
- [x] `backend/app/Http/Controllers/Api/UserController.php` — Added dynamic sorting with validated `sort` + `sort_dir` query params (whitelist: name, email, created_at)

### Usage Tracking (UsageController)
- [x] `backend/app/Http/Controllers/Api/UsageController.php` — Added `payments` to integration type validation in all 3 methods

### Test Infrastructure
- [x] `backend/tests/Pest.php` — Removed GraphQL transaction isolation workaround
- [x] `backend/tests/TestCase.php` — Force Scout collection driver in `setUp()`

### Dependency Update
- [x] `backend/composer.lock` — Already at league/commonmark 2.8.1; no update needed

---

## Phase 2: ADR Corrections

Documentation-only changes correcting ADR specs to match actual implementation. No runtime impact.

- [x] `docs/adr/005-notification-system-architecture.md` — Add ntfy.sh to supported channels; correct notification model (`body` → `message`, remove `channels_sent`); update NotificationOrchestrator code examples; add evolution note
- [x] `docs/adr/006-llm-orchestration-modes.md` — Already correct in selfmx; no changes needed
- [x] `docs/adr/007-backup-system-design.md` — Already correct in selfmx; no changes needed
- [x] `docs/adr/017-notification-template-system.md` — Already correct in selfmx; no changes needed
- [x] `docs/adr/022-storage-provider-system.md` — Already correct in selfmx; no changes needed
- [x] `docs/adr/028-webhook-system.md` — Already correct in selfmx; no changes needed
- [x] `docs/adr/029-usage-tracking-alerts.md` — Already correct in selfmx; no changes needed
- [x] `docs/adr/030-file-manager.md` — Already correct in selfmx; no changes needed

---

## Phase 3: API Documentation Expansion

Large documentation additions — the bulk of the commit by line count.

### OpenAPI Spec (+2,138 / -1,143)
- [ ] `docs/api/openapi.yaml` — Add ~25+ undocumented endpoint definitions (Notifications, Novu, Broadcasting, LLM, Stripe Connect, Storage Settings, Search, Outbound Webhooks, Integration Usage); fix Notification.id type (integer → UUID string); fix mark-read schema; correct LLM config and vision query schemas *(deferred — OpenAPI spec not yet in selfmx)*

### API README (+221 / -12)
- [x] `docs/api/README.md` — Added 9+ new sections with ~100+ endpoints: System Settings, Notification Templates/Settings/Deliveries, Admin Notification Channels, User Notification Settings, Novu Integration, Real-Time Broadcasting, LLM Orchestration, Stripe Connect/Settings/Payments, Outbound Webhooks, Integration Usage/Costs, File Manager, Search

---

## Phase 4: AI Patterns, Plans & Journals

Documentation for AI development guidance and audit tracking.

### AI Patterns & Anti-Patterns
- [ ] `docs/ai/patterns/ui-patterns.md` (+157) — Add 8 new pattern sections: Avatar & Initials, Loading States (SettingsPageSkeleton vs Loader2), Entrance Animations (tailwindcss-animate), Typography (Newsreader serif), Notification Icons (color-coded), PWA Safe Area Insets, PasswordInput, Configuration Page Conventions *(deferred — file not yet in selfmx)*
- [x] `docs/ai/patterns/README.md` — Updated UI Components section description; added naming convention rows
- [x] `docs/ai/anti-patterns/frontend.md` (+88) — Added avatar fallback, native checkbox, custom spinner anti-patterns
- [x] `docs/ai/anti-patterns/responsive.md` (+47) — Added PWA safe area insets pattern; always-visible action buttons for touch
- [x] `docs/ai/anti-patterns/README.md` (+7) — Added UI anti-pattern checklist items

### Audit Journals (new files)
- [x] `docs/journal/2026-03-08-adr-api-audit-batch-2.md` — Audit of ADRs 005, 016, 017, 025, 027 (27 API doc gaps found)
- [x] `docs/journal/2026-03-08-adr-api-audit-batches-3-4.md` — Audit of ADRs 007, 010, 014, 021, 022, 006, 026, 028, 029, 030 (1 impl bug, 5 impl fixes, 60+ API doc gaps, 9 ADR updates)

### Roadmap & Plan Updates
- [ ] `docs/plans/adr-api-audit-roadmap.md` (+95/-19) — Update audit progress from 8 → 23 ADRs *(deferred — file not yet in selfmx)*
- [x] `docs/plans/bug-tracker.md` (+7) — Logged bug: "GET /storage-settings returns incomplete data (missing provider config)"
- [ ] `docs/plans/changelog-roadmap.md` (+5) — Add implementation journal references *(deferred)*
- [ ] `docs/plans/configurable-auth-features-roadmap.md` (+4) — Add implementation journal reference *(deferred)*

---

## Merge Considerations

### Conflict-Prone Files
- **`backend/routes/api.php`** — Our working tree has provider accounts routes added. The avatar routes from sourdough need to be merged in alongside those changes.
- **`backend/composer.lock`** — Better to just run `composer update league/commonmark` inside the container rather than copying the lock file.

### Approach
- **Phase 1** should be done manually — read the sourdough versions of each file, adapt the changes to our codebase (which has diverged with provider accounts refactor, email forwarding, etc.)
- **Phases 2–4** can mostly be copied directly from sourdough since our docs haven't diverged much. Diff each file first to check for selfmx-specific content.

---

## Not in This Commit (Future Work)

The original roadmap described 92 files across 8 workstreams. The frontend component work below was **not included in commit 487e110** and is not present in sourdough's repo. These would need to be built from scratch in selfmx if desired:

- **AI Provider Component Extraction** — Decompose AI settings page into `ai-types.ts`, `orchestration-mode-card.tsx`, `provider-card.tsx`, `provider-dialog.tsx`, `provider-list-card.tsx`
- **SSO Component Extraction** — Decompose SSO page into `types.ts`, `sso-global-options-card.tsx`, `sso-provider-card.tsx`, `sso-oidc-card.tsx`
- **Help Center Enhancements** — TOC sidebar, search highlighting, keyboard navigation, syntax highlighting
- **User Management Frontend** — DataTable component, avatar-upload component, security overview dashboard, user table migration to TanStack React Table
- **Design System Polish** — Spinner standardization, sidebar redesign, auth page split-screen, dashboard animations, notification UI improvements, about dialog, preferences tabs
- **New Dependencies** — @tanstack/react-table, highlight.js, rehype-highlight

These are covered in the dedicated **[Design Review: Sourdough Frontend Port](design-review-roadmap.md)** roadmap — 8 workstreams, ~48 frontend files.

## Recommended Port Order

1. **Phase 1** (Backend Code) — functional changes, test after each
2. **Phase 2** (ADR Corrections) — copy from sourdough, review for selfmx-specific divergence
3. **Phase 3** (API Docs) — largest by line count, copy from sourdough with review
4. **Phase 4** (AI Patterns & Journals) — copy from sourdough, adapt references
