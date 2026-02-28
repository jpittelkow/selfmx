# Features

Core functionality and feature documentation:

## User Management & Authentication

- [API Authentication Endpoints](api/README.md#authentication) - Email/password, SSO, 2FA endpoints
- [ADR-002: Authentication Architecture](adr/002-authentication-architecture.md) - Laravel Sanctum session-based auth design
- [ADR-003: SSO Provider Integration](adr/003-sso-provider-integration.md) - OAuth2/OIDC integration (Google, GitHub, Microsoft, Apple, Discord, GitLab)
- [ADR-004: Two-Factor Authentication](adr/004-two-factor-authentication.md) - TOTP + recovery codes implementation

**Capabilities:**
- Email/password authentication with Laravel Sanctum
- SSO via OAuth2/OIDC (Google, GitHub, Microsoft, Apple, Discord, GitLab, Enterprise OIDC); **sign-in and register pages** show "Continue with {provider}" only for providers that have credentials and are **enabled** (from `GET /auth/sso/providers`); **setup** is Configuration > SSO (`/configuration/sso`) with per-provider enabled toggle, setup modals, and test connection. A successful test auto-enables the provider toggle; the user clicks Save to persist. **SSO callback flow:** After the OAuth provider authenticates the user, the backend processes the callback at `/api/auth/callback/{provider}`, creates a session, and redirects to the shared frontend callback page at `/auth/callback` (`frontend/app/auth/callback/page.tsx` — note: NOT inside the `(auth)` route group, since parenthesized groups don't affect URLs). This page fetches the user session and redirects to `/dashboard`. All providers use the same callback page — it is provider-agnostic.
- Two-factor authentication (TOTP + recovery codes)
- **Passkeys (WebAuthn/FIDO2):** Passwordless authentication using biometrics or hardware security keys. Users register passkeys in User Security (`/user/security`); login page shows "Sign in with passkey" when available and passkeys are enabled. Configurable passkey mode (disabled / optional / required) in Configuration > Security. Supported on Chrome, Edge, Safari, Firefox with WebAuthn support.
- Password reset and email verification
- **Admin auth settings (Configuration > Security):** Admins configure system-wide **email verification** (disabled / optional / required), **self-service password reset** (on/off), **2FA mode** (disabled / optional / required), and **passkey mode**. This page is admin-only and does not contain per-user features. When password reset is disabled, the "Forgot password?" link and forgot-password page are hidden; backend returns 503 for forgot-password requests.
- **User security (User menu > Security, `/user/security`):** Each user manages their own password change, two-factor authentication (enable/disable, recovery codes), passkeys, and connected SSO accounts. Accessible from the user dropdown in the header. When 2FA is required by admin, users without 2FA are redirected here to set up 2FA.
- **Auth UI:** Sign-in and register use a glassmorphism card layout, password visibility toggle, password strength indicator (register/reset), and real-time email availability check on register (`POST /auth/check-email`)
- **Sign out:** User dropdown Sign Out shows an "Are you sure?" confirmation dialog; on confirm, optimistic logout clears state and redirects immediately while the backend session invalidation runs in the background
- **Admin user management**: Configuration > Users – list users (pagination, search), create/edit/disable users, role (admin) management, send verification email on creation, resend verification email, reset password. Disabled users cannot log in.
- **User groups & permissions:** Role-based access via user groups and a permission enum. Admin status is solely via the **admin** group (no `is_admin` column); the first registered user is assigned to the admin group. **Permission model:** Permissions (e.g. `users.view`, `settings.edit`, `backups.create`) are defined in `Permission` enum; Laravel Gates are auto-registered so routes use `can:permission.name`. Admin group users have all permissions implicitly. Auth response (`GET /auth/user`) includes computed `permissions` array for the frontend. **Backend:** `user_groups`, `user_group_members`, `group_permissions` tables; default groups (Administrators, Users); `HasGroups` trait; `GroupService`, `PermissionService` (cached checks); API for groups, members, permissions; `PUT /api/users/{user}/groups`. All admin/config routes are protected by granular permissions (users, groups, settings, backups, logs, audit). **Frontend:** `usePermission(permission)` and `<PermissionGate>` for conditional UI; Configuration layout shows nav items only when the user has the required permission; access to Configuration requires at least one config-related permission or admin. **Admin UI:** Configuration > Groups – list, create/edit/delete groups, manage members, permission matrix; Configuration > Users – groups column, group assignment (`UserGroupPicker`), filter by group; User profile shows group memberships. See [Recipe: Add a new permission](ai/recipes/add-new-permission.md), [Recipe: Create a custom group](ai/recipes/create-custom-group.md), [User Groups Roadmap](plans/user-groups-roadmap.md).
- All features optional for self-hosted deployments

## User Preferences & Regional Settings

**Capabilities:**
- **Per-user timezone:** Each user has a timezone setting (Preferences > Regional) that controls how all dates and times are displayed in the frontend. On login/registration, the browser's timezone is auto-detected via `Intl.DateTimeFormat` and silently sent to the backend (`POST /user/settings/detect-timezone`), which sets it only if the user hasn't explicitly chosen one. Users can override manually or select "Use system default" to revert.
- **Timezone fallback chain:** User setting -> Admin system default (`Configuration > System > Default Timezone`) -> `APP_TIMEZONE` env var -> UTC.
- **Backend support:** `User::getTimezone()` returns the effective timezone. The `GET /auth/user` response includes a `timezone` field so the frontend always has the active timezone without extra API calls.
- **Date formatting:** `formatDate()`, `formatDateTime()`, and `formatTimestamp()` in `frontend/lib/utils.ts` automatically apply the user's timezone via the `Intl.DateTimeFormat` `timeZone` option.
- **Admin default:** Admins set the system-wide default timezone in Configuration > System (General tab). This is backed by `settings-schema.php` (`general.default_timezone`) with `APP_TIMEZONE` env fallback.
- **Theme preferences:** Light, dark, or system theme (Preferences > Appearance).
- **Default LLM mode:** Per-user default for AI interactions (Preferences > Defaults).

## Dashboard & Widgets

- [Recipe: Add Dashboard Widget](ai/recipes/add-dashboard-widget.md) - Create new static widgets for the dashboard
- [Patterns: Dashboard Widget](ai/patterns/dashboard-widget.md) - Widget component structure and patterns

**Static dashboard** at `/dashboard`: Developer-defined widgets in a responsive grid layout. No user configuration—widgets are added in code.

**Available widgets:**
- **Welcome** – Greeting with user name (static)
- **System Stats** – Total users and storage used (data from `GET /api/dashboard/stats`)
- **Quick Actions** – Links to Audit Logs, Users, System Settings

**Capabilities:**
- **Widget components:** Self-contained React components in `frontend/components/dashboard/widgets/` with loading/error states; data-fetching via React Query
- **Permission-based visibility:** Use `usePermission()` to conditionally render widgets (e.g., admin-only)
- **Simple API:** `DashboardController::stats()` returns metrics for the stats widget

## In-App Help & Onboarding

- [In-App Documentation & Onboarding Roadmap](plans/in-app-documentation-roadmap.md) - Getting started wizard, tooltips, help center

**Getting Started Wizard:** Multi-step onboarding wizard for new users. Shows on first login; can be re-triggered via "Getting Started" in user dropdown. Steps: Welcome, Profile, Security (2FA recommendation), Notifications, Theme, Quick Tour, Completion. Progress persisted per-user in `user_onboarding` table.

**Contextual Tooltips:** Field-level help throughout settings pages. `HelpTooltip` component with help circle icon; hover to see description. Centralized content in `frontend/lib/tooltip-content.ts`. Extended `FormField` and `SettingsSwitchRow` components with optional `tooltip` prop.

**Help Documentation Center:** Searchable in-app help system accessible from anywhere:
- **Access:** Click help icon in header, press `?` or `Ctrl+/`, or select "Help Center" in user dropdown
- **Features:** Category sidebar, article content with markdown rendering, Fuse.js client-side search
- **Content:** User categories (Getting Started, Your Account, Security, Notifications) and permission-gated categories (Administration, User Management, Security & Access, Communications, Integrations, Audit Logs, Application Logs, Log Settings & Jobs, Usage & Costs, Backup & Data)
- **Contextual Links:** `HelpLink` component for "Learn more" links on all config pages

**Capabilities:**
- Wizard state tracking (completed steps, dismissed, reset) via API and database
- Keyboard shortcuts: `?` and `Ctrl+/` to toggle help center (disabled when typing in inputs)
- Permission-based help content: categories have a `permission` field (e.g., `backups.view`, `audit.view`) so non-admin users with specific permissions see relevant help articles. Admin users see everything.
- React context providers for both wizard (`WizardProvider`) and help (`HelpProvider`)

## Notification System

- [ADR-005: Notification System Architecture](adr/005-notification-system-architecture.md) - Multi-channel notification delivery system
- [ADR-025: Novu Notification Integration](adr/025-novu-notification-integration.md) - Optional Novu (Cloud or self-hosted) for workflows and notification center
- [API Notification Endpoints](api/README.md#notifications) - Notification management API
- [Recipe: Trigger Notifications](ai/recipes/trigger-notifications.md) - Send notifications from backend code
- [Recipe: Configure Novu](ai/recipes/configure-novu.md) - Enable and configure Novu

**Web Push (PWA):** Admins configure VAPID keys in Configuration > Notifications. Users enable browser notifications in User Preferences—subscription is stored and push is delivered via the service worker. Supports Chrome, Firefox, Edge; limited on Safari/iOS.

**PWA install experience:** Custom install prompt appears after 2+ visits (Chrome/Edge). Banner is dismissible with "Don't show again" (30-day cooldown). User Preferences includes an "Install App" card when install is available. Manifest includes full icon set, shortcuts (Dashboard, Settings), and Share Target. See [PWA roadmap](plans/pwa-roadmap.md) Phase 4 and [Recipe: Add PWA Install Prompt](ai/recipes/add-pwa-install-prompt.md).

**Share Target:** When the PWA is installed, users can share links or text from other apps to Sourdough; shared content is shown at `/share` with links to dashboard or sign-in. Manifest `share_target` uses GET with title, text, and url params.

**Offline experience:** When offline, an indicator banner appears; dashboard, user preferences, and notifications show cached data with an offline badge. Save/actions are disabled on preferences and notifications. Failed mutations (POST/PUT/PATCH/DELETE) are queued in IndexedDB and retried when back online (Background Sync in Chrome/Edge; online event elsewhere). Stale queue items (>24h) and non-retryable (4xx) responses are automatically pruned. Offline fallback page offers "Go to Dashboard" and auto-reloads when online. Workbox 7.3.0 is bundled locally (no CDN dependency). iOS safe-area CSS and standalone overscroll-behavior are supported. The `theme-color` meta tag is set statically and updated dynamically from branding settings.

**Novu (optional):** When Novu is enabled (Configuration → Novu), notifications are sent via the Novu API and the header bell uses the Novu React Inbox. Users are synced as Novu subscribers; workflows are created in the Novu dashboard. When Novu is disabled, the built-in channel system and notification templates are used.

**Capabilities:**
- In-app notification UI: header bell with unread badge, dropdown of recent items, full `/notifications` page with filters and bulk actions (or Novu Inbox when Novu is enabled)
- Real-time updates via Laravel Echo + Reverb when broadcasting is configured (local mode)
- NotificationContext provider for client-side state (local mode)
- **Global vs per-user config:** Admins enable which channels are available in Configuration > Notifications (`/configuration/notifications`); users enable channels, add webhooks/phone, test, and accept usage in User Preferences (`/user/preferences`). Users cannot enable a channel until an admin has made it available. SMS: admin chooses preferred provider (Twilio/Vonage/SNS); users enter phone number and test.

**Multi-channel notification delivery:**

| Channel | Provider | Status |
|---------|----------|--------|
| Email | SMTP, Mailgun, SendGrid, SES, Postmark | ✅ |
| Telegram | Bot API | ✅ |
| Discord | Webhooks | ✅ |
| Slack | Webhooks | ✅ |
| SMS | Twilio, Vonage, AWS SNS | ✅ |
| Signal | signal-cli | ✅ |
| Matrix | Homeserver API | ✅ |
| ntfy | ntfy push service | ✅ |
| Web Push | VAPID via minishlink/web-push | ✅ |
| FCM | Firebase Cloud Messaging v1 API | ✅ |
| In-App | Database + WebSocket | ✅ |

**Notification types** (with per-channel templates for push, inapp, chat):

| Type | Trigger |
|------|---------|
| `backup.completed` | Backup finishes successfully |
| `backup.failed` | Backup fails |
| `auth.login` | New sign-in detected |
| `auth.password_reset` | Password changed |
| `system.update` | System update available |
| `llm.quota_warning` | API quota threshold exceeded |
| `storage.warning` | Disk usage exceeds warning threshold |
| `storage.critical` | Disk usage exceeds critical threshold |
| `suspicious_activity` | Suspicious login/access patterns detected |
| `usage.budget_warning` | Integration budget nearing limit |
| `usage.budget_exceeded` | Integration budget exceeded |

## AI/LLM Orchestration

- [ADR-006: LLM Orchestration Modes](adr/006-llm-orchestration-modes.md) - Single, Aggregation, and Council modes
- [API LLM Endpoints](api/README.md#llmai) - Query endpoints for text and vision

**Multi-provider LLM support with three operating modes:**

1. **Single Mode** - Direct query to one provider
2. **Aggregation Mode** - Query all, primary synthesizes
3. **Council Mode** - All providers vote, consensus resolution

**Supported providers:**
- Claude (Anthropic)
- OpenAI (GPT-4, GPT-4o)
- Gemini (Google)
- Ollama (local/self-hosted)
- AWS Bedrock
- Azure OpenAI

**LLM model discovery:** When adding a provider in Configuration > AI, enter your API key (or Ollama host), click **Test** to validate credentials, then **Fetch Models** to load available models from the provider API. Model list is cached server-side for 1 hour. OpenAI, Claude, Gemini, and Ollama are supported for discovery; Azure and Bedrock deferred.

**Editable providers:** Existing providers can be edited in-place (pencil icon or "Edit settings" button on each provider card). Users can update the API key, model, endpoint, base URL, AWS region, and credentials without deleting and re-adding the provider. Sensitive fields (API key, AWS credentials) show "Leave blank to keep current" placeholder; only changed values are sent to the backend.

## Configuration Management

- [ADR-014: Database Settings with Environment Fallback](adr/014-database-settings-env-fallback.md) - Database-stored settings with env fallback
- System settings: Configuration > System (`/configuration/system`); core application settings including app name, URL, timezone, locale, registration policies (open/closed), session timeout, and password requirements. The app name (`general.app_name`) is stored in the settings schema with `public: true` so it is returned by `GET /system-settings/public` and used for page titles, branding, and PWA manifest
- Mail settings: Configuration > Email (`/configuration/email`); SMTP and provider credentials stored in DB with encryption for secrets
- Auth settings: Configuration > Security (`/configuration/security`), admin-only; **Authentication (system-wide)** card: email verification mode (disabled/optional/required), self-service password reset toggle, two-factor mode (disabled/optional/required), passkey mode (disabled/optional/required). No per-user features here—users manage password, 2FA, passkeys, and SSO at User menu > Security (`/user/security`). Stored in `auth` group in settings schema; public features exposed via `GET /system-settings/public` for login/forgot-password UI.
- SSO settings: Configuration > SSO (`/configuration/sso`); OAuth client IDs and secrets for Google, GitHub, Microsoft, Apple, Discord, GitLab, and OIDC; per-provider **enabled** toggle and **per-provider save** (global options card has its own save); setup instruction modals and copyable redirect URIs; test connection per provider

**Changelog:** Configuration > Changelog (`/configuration/changelog`) displays version history parsed from `CHANGELOG.md` (Keep a Changelog format). Entries grouped by version with release dates, categorized sections (Added, Changed, Fixed, Removed, Security) with color-coded badges. Older versions are collapsible. API: `GET /api/changelog` with pagination. Sidebar version footer links to the changelog page.

**Configuration navigation:** Admin configuration uses grouped, collapsible navigation (General, Users & Access, Communications, Integrations, Logs & Monitoring, Data). Groups expand/collapse; the group containing the current page is expanded by default. Expanded state persists in localStorage. Same structure on desktop sidebar and mobile drawer. See [Recipe: Add configuration menu item](ai/recipes/add-configuration-menu-item.md) and [Patterns: Configuration Navigation](ai/patterns/ui-patterns.md).

**Collapsible settings sections:** Configuration pages (SSO, Notifications, AI/LLM, Backup) use the shared `CollapsibleCard` component so provider/channel sections can be expanded or collapsed. Headers show icon, name, and status badge; content (forms, toggles) is in the expandable body. See [Patterns: CollapsibleCard](ai/patterns/ui-patterns.md) and [Recipe: Add collapsible section](ai/recipes/add-collapsible-section.md).

**Provider icons:** A shared `ProviderIcon` component (`frontend/components/provider-icons.tsx`) provides branded or monochrome icons for SSO providers, LLM providers, notification channels, email/backup providers. Used on sign-in buttons (branded) and in configuration CollapsibleCard headers (mono).

**Capabilities:**
- System-wide settings stored in `system_settings` with environment fallback (no restart for changes)
- SettingService with file-based caching; ConfigServiceProvider injects settings into Laravel config at boot
- Mail configuration (provider, SMTP, from address) editable via admin UI; sensitive values encrypted at rest
- SSO configuration (global options and per-provider credentials) editable via admin UI; client secrets encrypted at rest
- Artisan command `php artisan settings:import-env` to import current env values into the database
- Reset-to-default per setting (revert to env value)

## Search (Full-Text)

- [Meilisearch Integration Roadmap](plans/meilisearch-integration-roadmap.md) - Phases 1–6 (Docker, Scout, User searchable, API, frontend, admin)
- [Recipe: Add searchable model](ai/recipes/add-searchable-model.md) - Add new models to search
- [Patterns: SearchService](ai/patterns/search-service.md) - Backend search API and indexing

**Capabilities:**
- **Global search (Cmd+K / Ctrl+K):** Command-palette style modal from header; debounced input, results grouped by type with icons, keyboard navigation, recent searches in localStorage, highlight of matched text. Stale response handling so only the latest query’s results are shown.
- **Static page search:** Cmd+K searches both **pages** (navigation) and **database records**. Pages (Dashboard, Notifications, User Preferences, Configuration sections) appear instantly; database results (users, notifications, email templates, etc.) appear after the API response. Admin-only Configuration pages are shown only to admins.
- **Search API:** `GET /api/search` (q, type, page, per_page) and `GET /api/search/suggestions` (q, limit). Admin sees all users; non-admin results scoped to current user. Both routes use `log.access:User` (access logged when returning user data).
- **Admin:** Configuration > Search – index statistics (document counts per model), Reindex per model or Reindex all. Reindex also via `php artisan search:reindex` or `search:reindex {model}`.
- **Backend:** Laravel Scout with Meilisearch driver; SearchService with globalSearch(), getSuggestions(), getIndexStats(), reindexAll()/reindexModel(). Fallback to database LIKE when Meilisearch unavailable. Result text escaped for XSS safety when rendered with highlights.
- **Extensibility:** Add new searchable models via Searchable trait, SearchReindexCommand, SearchService type branch and transform (with XSS-safe escaping), and frontend result icon. See recipe.

## Email Templates

- [ADR-016: Email Template System](adr/016-email-template-system.md) - Database-stored customizable email templates
- [Recipe: Add Email Template](ai/recipes/add-email-template.md) - Add new templates

**Capabilities:**
- Admin UI: Configuration > Email Templates (`/configuration/email-templates`) – list templates, edit with TipTap WYSIWYG editor, variable picker, live preview, send test email, reset to default (system templates)
- Admin-editable email templates (subject, HTML body, plain text) via API
- Variable replacement with `{{variable}}` and `{{user.name}}` (dot notation)
- Template preview with sample or provided variables (supports unsaved content for live preview)
- Test email sending (requires email configured)
- Reset to default content for system templates
- Default templates: password reset, email verification, welcome, generic notification
- **Integration (Chunk D):** Password reset, email verification, and notification emails are sent using these templates (TemplatedMail Mailable; User overrides and EmailChannel use EmailTemplateService).

## Notification Templates

- [ADR-017: Notification Template System](adr/017-notification-template-system.md) - Per-type templates for push, in-app, and chat
- [Recipe: Add Notification Template](ai/recipes/add-notification-template.md) - Add new notification types
- [Recipe: Keep Notification Template Variables Up to Date](ai/recipes/keep-notification-template-variables-up-to-date.md) - Update variable descriptions when adding or changing template variables

**Capabilities:**
- Admin UI: Configuration > Notification Templates (`/configuration/notification-templates`) – list by type and channel group, edit title and body, **Available Variables** reference panel (collapsible, with descriptions and copy), live preview, reset to default (system templates)
- Per-notification-type templates for channel groups: **push** (WebPush, FCM, ntfy), **inapp** (DatabaseChannel), **chat** (Telegram, Discord, Slack, Twilio, Signal, Matrix, Vonage, SNS)
- Variable replacement with `{{variable}}` and `{{user.name}}` (dot notation)
- Template preview with sample or provided variables (supports unsaved content for live preview)
- Reset to default content for system templates
- Default types: backup.completed, backup.failed, auth.login, auth.password_reset, system.update, llm.quota_warning (each with push, inapp, chat templates)
- **Orchestrator:** `sendByType($user, $type, $variables, $channels)` uses templates per channel; existing `send()` unchanged; each channel optionally resolves a template for (type, channel_group) when present

## Audit Logs

- [Audit Logs Roadmap](plans/audit-logs-roadmap.md) – UI/UX, dashboard analytics, logging improvements
- [Audit Logging Implementation](journal/2026-01-29-audit-logging-implementation.md) – Phase 1–4 (AuditService, integrations, frontend, docs)
- [Audit Dashboard Analytics](journal/2026-01-29-audit-dashboard-analytics.md) – Phase 2 dashboard widget
- [Audit Extended Features](journal/2026-01-29-audit-extended-features.md) – Real-time streaming, structured JSON logging
- [Console and Application Logging](journal/2026-01-29-console-app-logging.md) – Backend logging audit, log levels/rotation, frontend error reporting
- [Recipe: Trigger audit logging](ai/recipes/trigger-audit-logging.md) – Log from controllers/services
- [Recipe: Add auditable action](ai/recipes/add-auditable-action.md) – Add new audited actions
- [Recipe: Extend logging](ai/recipes/extend-logging.md) – Add logging to backend/frontend, new log channels
- [Recipe: Add access logging](ai/recipes/add-access-logging.md) – HIPAA access logging for PHI
- [Logging](logging.md) – Logging standards, configuration, frontend errorLogger
- [Logging Roadmap](plans/logging-roadmap.md) – Retention, cleanup, app log export (done); optional external storage

**Capabilities:**
- **Configuration > Audit** (`/configuration/audit`): Paginated audit log with filters (user, action, severity, correlation ID, date range), search, severity badges, detail modal (old/new values, IP, user agent), CSV export. Admin only.
- **Configuration > Application Logs** (`/configuration/logs`): Real-time console log viewer and export. Enable "Live" to stream `Log::` output via private `app-logs` channel (requires `LOG_BROADCAST_ENABLED=true`, `broadcast` in `LOG_STACK`, Reverb). Export card: export log files by date range, level, correlation ID as CSV or JSON Lines (`GET /api/app-logs/export`). Admin only.
- **Configuration > Access Logs (HIPAA)** (`/configuration/access-logs`): PHI access audit trail. Table shows date, user, action, resource type, resource ID, IP, and **fields accessed** (extracted automatically by middleware). Filters (user, action, resource type, correlation ID, dates), CSV export. AccessLogService + LogResourceAccess middleware on profile, user, user-settings, and search/suggestions (when returning user data) routes. Admin only.
- **Configuration > Log retention** (`/configuration/log-retention`): Configure retention days for application logs (1–365), audit logs (30–730), access logs (6 years minimum for HIPAA). Toggle **HIPAA access logging** (enable/disable). When disabled, “Delete all access logs” is available (with HIPAA violation warning). Cleanup via `php artisan log:cleanup` (optional `--dry-run`, `--archive`). Admin only.
- **Live streaming (audit)**: "Live" toggle on audit page streams new logs in real time via private `audit-logs` channel (Reverb); connection status and highlight animation for new entries. Requires BROADCAST_CONNECTION=reverb and admin user.
- **Dashboard analytics** (admin dashboard): “System Activity” widget with stats cards (total actions, warnings/errors), severity donut chart, activity trends area chart (last 30 days), recent warnings list, and “View all logs” link.
- **Stats API** (`GET /audit-logs/stats`): `total_actions`, `by_severity`, `daily_trends`, `recent_warnings`, `actions_by_type`, `actions_by_user`. Query params: `date_from`, `date_to`.
- **Suspicious activity**: Banner on admin dashboard when 5+ failed logins in 15 min or 10+ export actions in 1 hour; `GET /api/suspicious-activity`. Scheduled `log:check-suspicious` notifies admins (in-app + email).
- AuditService + AuditLogging trait; auth, user management, settings, and backup actions logged. Action naming `{resource}.{action}`; severity info/warning/error/critical; sensitive data masked.

**Structured application logging:**
- **Correlation ID**: Middleware adds `X-Correlation-ID` to requests (or uses client-provided header) and response for tracing.
- **JSON logging**: Optional `json` channel writes structured JSON to stderr (correlation_id, user_id, ip_address, request_uri). Set `LOG_STACK=single,json` in `.env`.
- **Log levels and rotation**: `LOG_LEVEL` (debug/info/warning) and `LOG_DAILY_DAYS` (default 14) in `.env`; daily channel has context tap.
- **Frontend error reporting**: `errorLogger` (`frontend/lib/error-logger.ts`) reports to `POST /api/client-errors`; ErrorBoundary and global handlers report uncaught errors. Use `errorLogger.report()` / `errorLogger.captureMessage()` instead of `console.error`/`console.warn`.

## Backup & Restore

**Documentation hub:** [Backup & Restore](backup.md) – user guide, admin settings, developer docs, key files, and how to extend.

- [ADR-007: Backup System Design](adr/007-backup-system-design.md) – ZIP-based backup with manifest, destinations, security
- [ADR-014](adr/014-database-settings-env-fallback.md) / [ADR-015](adr/015-env-only-settings.md) – Backup settings in DB with env fallback; env-only settings
- [API: Backup operations](api/README.md#backup--restore-admin) – List, create, download, restore, delete
- [API: Backup settings](api/README.md#backup-settings-admin) – Get/update settings, reset key, test destination
- [Recipe: Add backup destination](ai/recipes/add-backup-destination.md) – Add a new storage destination
- [Recipe: Extend backup/restore](ai/recipes/extend-backup-restore.md) – New settings, restore behavior, scheduling, notifications
- [Patterns: Backup & Restore](ai/patterns/backup-restore.md) – Settings flow, destination interface, UI structure

**Capabilities:**
- ZIP-based backup format with manifest (version 2.0)
- Database backup (SQLite file copy; MySQL/PostgreSQL export as JSON with integrity hash for security)
- File backup (uploaded files under `storage/app/public`)
- Settings backup (database-stored settings; sensitive values handled securely)
- Scheduled backups (daily/weekly/monthly; configurable time and destinations)
- Remote destinations: local disk, S3, SFTP, Google Drive (pluggable via `DestinationInterface`)
- **Backup settings UI**: Configuration > Backup – **Backups** tab (create, download, restore, delete); **Settings** tab (retention, schedule, S3/SFTP/Google Drive credentials, encryption, notifications). All backup configuration stored in DB with env fallback; Test Connection for each remote destination.

## Storage Settings

- [Storage Settings Enhancement Roadmap](plans/storage-settings-roadmap.md) – Phases 1–4 complete

**Capabilities (Phase 1 – Local Storage Transparency):**
- **Configuration > Storage** (`/configuration/storage`): Storage driver selection, max upload size, allowed file types. Stored in DB with env fallback (`manage-settings`).
- **Storage paths**: UI shows where files are stored (app, public, backups, cache, sessions, logs) with path and description; `GET /storage-settings/paths`.
- **Storage health**: Warning alert when storage is not writable or disk usage ≥ 90%; free/total space and usage percent; `GET /storage-settings/health`.
- **Usage breakdown**: Statistics card shows total size, file count, driver, and per-directory usage (local driver only); `GET /storage-settings/stats` includes `breakdown` by directory.

**Capabilities (Phase 2 – Additional Providers):**
- **Supported drivers**: Local, Amazon S3, Google Cloud Storage (GCS), Azure Blob Storage, DigitalOcean Spaces, MinIO, Backblaze B2.
- **Provider-specific forms**: Dynamic fields per driver (bucket, region, credentials, endpoint where applicable). GCS uses service account JSON; Azure uses connection string and container; S3-compatible (DO Spaces, MinIO, B2) use key/secret and optional custom endpoint.
- **Connection test**: "Test Connection" button (non-local drivers) calls `POST /storage-settings/test` and shows success or error message.

**Capabilities (Phase 3 – File Manager):**
- **Configuration > Storage > Manage Files** (`/configuration/storage/files`): Browse, upload, download, delete, rename, move; image/PDF/text preview. Admin only.

**Capabilities (Phase 4 – Analytics & Monitoring):**
- **Storage Analytics**: Donut chart for file type breakdown, top 10 largest files, recently modified files; `GET /storage-settings/analytics` (local driver only).
- **Alerts & Thresholds**: Configurable warning/critical thresholds; enable alerts; email notifications. `storage:check-alerts` runs daily via scheduler. Notification templates: `storage.warning`, `storage.critical`.
- **Cleanup Tools**: Identify reclaimable space (cache, temp files older than 7 days, old backups beyond retention); one-click cleanup; `GET /storage-settings/cleanup-suggestions`, `POST /storage-settings/cleanup` with `{ type: 'cache' | 'temp' | 'old_backups' }`.

## Scheduled Jobs

**Configuration > Jobs** (`/configuration/jobs`) – Monitor and run scheduled tasks. Admin only.

**Capabilities:**
- **Scheduled Tasks tab**: List of tasks from Laravel scheduler (and triggerable-only commands like log:cleanup). Columns: command, schedule, last run, description. **Run Now** for whitelisted commands (backup:run, log:cleanup, log:check-suspicious); confirmation dialog with extra warning for destructive commands; output and duration shown after run. Run history stored in `task_runs` table.
- **Queue Status tab**: Pending and failed job counts; queue breakdown when available.
- **Failed Jobs tab**: List failed queue jobs with retry, delete, retry all, clear all.
- **API**: `GET /api/jobs/scheduled` (tasks with triggerable, last_run, dangerous); `POST /api/jobs/run/{command}` (body: optional `{ options: {} }`); existing queue/failed endpoints. Manual runs are audited (`scheduled_command_run`).

## Integration Usage & Costs

**Configuration > Usage & Costs** (`/configuration/usage`) – Track and visualize costs across all paid integrations. Admin only (permission: `usage.view`).

**Capabilities:**
- **Usage Tracking**: Automatic instrumentation of all paid integration calls (LLM, Email, SMS, Storage, Broadcasting). Records integration type, provider, metric, quantity, estimated cost, and user attribution in `integration_usage` table.
- **Stats API**: Aggregated usage stats with date range, integration, and provider filters. Daily/weekly/monthly cost breakdown per integration type. Provider-level breakdown with cost and quantity totals.
- **Dashboard Page**: Date range selector with presets (7d, 30d, 90d, this month, last month). Summary stat cards (Total Cost, LLM, Email, SMS, Storage, Broadcasting). Stacked area chart showing cost trends by integration type. Integration filter toggles to show/hide types. Sortable provider breakdown table with totals row. Empty state when no usage data exists.
- **Cost Alerts**: Configurable monthly budget per integration type. Alert threshold (default 80%). Daily scheduled check notifies admins when budgets are approaching or exceeded.
- **CSV Export**: Download filtered usage data as CSV (date range + integration + provider filters).
- **Dashboard Widget**: Monthly cost summary with sparkline trend on the main dashboard for admin users.
- **Per-User Breakdown**: Usage attributed to individual users for LLM and SMS integrations.
- **Settings Schema**: `usage` group in `settings-schema.php` with LLM pricing, per-integration budgets, and alert threshold.
- **API**: `GET /api/usage/stats` (aggregated stats), `GET /api/usage/breakdown` (per-integration detail), `GET /api/usage/export` (CSV download).

## User Pages

User-facing pages for managing personal account settings.

### User Profile (`/user/profile`)

- View and edit name and email
- Avatar display with initials fallback
- View group memberships (read-only badges)
- **Account deletion:** Delete own account with email confirmation; permanently removes user and associated data

### User Security (`/user/security`)

Accessible from the **user dropdown** in the header (click your name) → **Security**.

- **Password change:** Update password with current password verification
- **Two-Factor Authentication:** Enable/disable 2FA, scan QR code with authenticator app, enter verification code, view/regenerate recovery codes
- **Passkeys:** Add/remove WebAuthn passkeys for passwordless login; shows browser support status; lists registered passkeys with alias and creation date
- **SSO Connections:** Link/unlink OAuth providers (Google, GitHub, Microsoft, etc.); view connected account nickname

### User Preferences (`/user/preferences`)

- **Theme selection:** Light, Dark, or System (follows OS preference)
- **Default LLM mode:** Single, Aggregation, or Council mode for AI queries
- **Notification channel preferences:** Enable/disable channels (Telegram, Discord, Slack, SMS, etc.), configure webhooks and phone numbers, test notifications, accept usage terms
- **Browser push notifications:** Enable WebPush with one-click subscription
- **PWA install:** "Install App" card when PWA install is available

## Branding

**Configuration > Branding** (`/configuration/branding`): Customize application appearance. Admin only.

**Capabilities:**
- **Logo upload:** Upload custom logo (max 2MB) or enter URL; preview and delete
- **Dark mode logo:** Upload a separate logo for dark mode (max 2MB) or enter URL; automatically shown when dark theme is active, falls back to main logo if not set. Preview shown on dark background.
- **Favicon upload:** Upload custom favicon (max 512KB) or enter URL; preview and delete
- **Theme colors:** Primary and secondary color customization via color picker; reset to system defaults
- **Custom CSS:** Inject custom CSS to override default styles
- **Live preview:** See changes in real-time preview panel before saving
- **Reset to defaults:** One-click reset all branding to system defaults

## API & Webhooks

**Configuration > API** (`/configuration/api`): Manage API access and webhook integrations. This page has **mixed permissions**: the tokens section is visible to all authenticated users; the webhooks section is admin-only (`can:settings.view/edit`).

**Capabilities:**
- **Personal access tokens (all users):** Create, view, and revoke API tokens; token preview (last 4 chars) shown after creation; last used date; tokens are shown once on creation and cannot be viewed again; **tokens expire after 7 days** (configurable via `SANCTUM_TOKEN_EXPIRATION`)
- **Outgoing webhooks (admin only):** Configure webhook endpoints for system events; available events: `user.created`, `user.updated`, `user.deleted`, `backup.completed`, `backup.failed`, `settings.updated`. Webhooks have a `user_id` column that tracks the admin who created them (for audit/ownership), not for access scoping — all admins can see and manage all webhooks.
- **Webhook management:** Create/delete webhooks, enable/disable, set secret for signature verification
- **Webhook signatures:** When a secret is configured, webhooks include HMAC-SHA256 signatures (`X-Webhook-Signature: sha256=...`) and timestamps (`X-Webhook-Timestamp`) for payload verification
- **Webhook URL validation:** Webhooks cannot point to internal/private addresses (SSRF protection)
- **Webhook testing:** Test button sends a test payload to the configured URL; last triggered timestamp shown
- **API**: `GET/POST/DELETE /api-tokens` for tokens; `GET/POST/DELETE /webhooks`, `POST /webhooks/{id}/test` for webhooks

## Security

- [ADR-024: Security Hardening](adr/024-security-hardening.md) - SSRF protection, SQL injection fixes, OAuth security, password policy
- [Security Compliance Roadmap](plans/security-compliance-roadmap.md) - SOC 2, ISO 27001 compliance tracking
- [Security Patterns](ai/patterns/security.md) - SSRF protection, webhook signatures, password validation patterns

**Capabilities:**

### SSRF Protection
- `UrlValidationService` validates all external URL fetches
- Blocks private IP ranges (10.x, 172.16-31.x, 192.168.x, 127.x)
- Blocks cloud metadata endpoints (169.254.169.254)
- Validates redirect targets during fetch operations
- Applied to: LLM vision queries (image URLs), webhooks, OIDC issuer discovery

### Authentication Security
- **Password policy:** Requires mixed case, numbers, symbols (8+ characters); compromised password check in production via Have I Been Pwned API
- **2FA enforcement:** Session flag properly set after verification; middleware validates protected routes
- **OAuth CSRF protection:** Cryptographic state tokens validated on SSO callbacks
- **Token expiration:** API tokens expire after 7 days (configurable)

### Data Security
- **Backup format:** MySQL/PostgreSQL backups use JSON format with integrity hash; parameterized queries on restore (no SQL injection)
- **File upload whitelist:** Default safe file types when none configured; MIME validation prevents extension spoofing

### Webhook Security
- **HMAC signatures:** Payloads signed with SHA-256 when secret configured
- **Timestamp binding:** Signatures include timestamp to prevent replay attacks
- **URL validation:** Cannot target internal/private addresses

### HTTP Security Headers
- **Content-Security-Policy:** Controls resource loading; restricts scripts, styles, images, fonts, and connections to trusted sources
- **X-Frame-Options:** `SAMEORIGIN` prevents clickjacking attacks
- **X-Content-Type-Options:** `nosniff` prevents MIME type sniffing
- **Referrer-Policy:** `strict-origin-when-cross-origin` controls referrer header exposure
- **Permissions-Policy:** Disables camera, microphone, and geolocation browser APIs

### CORS Configuration
- **Restricted origins:** Only `FRONTEND_URL` allowed (defaults to `APP_URL`; configured via environment)
- **Restricted methods:** Only `GET`, `POST`, `PUT`, `DELETE`, `PATCH`, `OPTIONS`
- **Restricted headers:** Only necessary headers (`Content-Type`, `Authorization`, `X-XSRF-TOKEN`, etc.)
- **Preflight caching:** 24-hour cache for CORS preflight responses

### Rate Limiting
- **Auth endpoints:** Login, register, password reset, 2FA verification protected via `rate.sensitive` middleware. The `rate.sensitive` middleware is a custom middleware (`App\Http\Middleware\RateLimitSensitive`, registered in `bootstrap/app.php`) that applies stricter rate limits to security-sensitive endpoints. It accepts a named limiter parameter (e.g. `rate.sensitive:login`, `rate.sensitive:register`, `rate.sensitive:2fa`).
- **Client error reporting:** Limited to 10 requests/minute
- **Email checks:** Limited to 10 requests/minute to prevent enumeration
