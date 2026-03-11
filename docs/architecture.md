# Architecture

## Architecture Overview

selfmx uses a decoupled architecture:

```
┌─────────────────────────────────────────────────────────────┐
│                    Single Docker Container                   │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │   Nginx      │───▶│  Next.js     │    │  PHP-FPM     │  │
│  │   (Proxy)    │    │  Frontend    │    │  Laravel API │  │
│  └──────────────┘    └──────────────┘    └──────────────┘  │
│         │                                        │          │
│         └────────────────┬───────────────────────┘          │
│                          ▼                                   │
│                  ┌──────────────┐                           │
│                  │   SQLite     │                           │
│                  │   Database   │                           │
│                  └──────────────┘                           │
└─────────────────────────────────────────────────────────────┘
```

## Architecture Decision Records

Architecture Decision Records (ADRs) document all significant design decisions:

- [ADR-001: Technology Stack](adr/001-technology-stack.md) - Laravel 11, Next.js 16, SQLite, Docker selection rationale
  - Key files: `backend/composer.json`, `frontend/package.json`, `backend/config/app.php`, `frontend/next.config.js`
- [ADR-002: Authentication Architecture](adr/002-authentication-architecture.md) - Laravel Sanctum session-based authentication
  - Key files: `backend/app/Http/Controllers/Api/AuthController.php`, `backend/app/Http/Controllers/Api/UserController.php`, `backend/config/auth.php`, `backend/config/sanctum.php`, `frontend/lib/auth.ts`, `frontend/app/(auth)/`, `frontend/app/(dashboard)/configuration/users/page.tsx`, `frontend/components/admin/user-table.tsx`, `frontend/components/admin/user-dialog.tsx`
- [ADR-003: SSO Provider Integration](adr/003-sso-provider-integration.md) - OAuth2/OIDC provider integration strategy
  - Key files: `backend/app/Http/Controllers/Api/SSOController.php`, `backend/app/Services/Auth/SSOService.php`, `backend/config/sso.php`, `frontend/components/auth/sso-buttons.tsx`
- [ADR-004: Two-Factor Authentication](adr/004-two-factor-authentication.md) - TOTP implementation with recovery codes
  - Key files: `backend/app/Http/Controllers/Api/TwoFactorController.php`, `backend/app/Services/Auth/TwoFactorService.php`, `frontend/components/auth/two-factor-form.tsx`
- [ADR-005: Notification System Architecture](adr/005-notification-system-architecture.md) - Multi-channel notification orchestrator
  - Key files: `backend/app/Services/Notifications/NotificationOrchestrator.php`, `backend/app/Services/Notifications/NotificationChannelMetadata.php`, `backend/app/Services/Notifications/Channels/`, `backend/app/Http/Controllers/Api/NotificationChannelConfigController.php`, `backend/app/Http/Controllers/Api/UserNotificationSettingsController.php`, `backend/config/notifications.php`
- [ADR-006: LLM Orchestration Modes](adr/006-llm-orchestration-modes.md) - Single, Aggregation, Council mode designs
  - Key files: `backend/app/Services/LLM/LLMOrchestrator.php`, `backend/app/Services/LLM/Providers/`, `backend/app/Services/LLMModelDiscoveryService.php`, `backend/app/Http/Controllers/Api/LLMModelController.php`, `backend/config/llm.php`
- [ADR-007: Backup System Design](adr/007-backup-system-design.md) - Backup format, scheduling, remote storage
  - Key files: `backend/app/Services/Backup/BackupService.php`, `backend/app/Services/Backup/Destinations/`, `backend/config/backup.php`, `backend/app/Http/Controllers/Api/BackupSettingController.php`, `backend/config/settings-schema.php` (backup group)
  - **Full backup documentation:** [Backup & Restore](backup.md) (user, admin, developer guides; key files; recipes and patterns)
- [ADR-008: Testing Strategy](adr/008-testing-strategy.md) - Pest PHP, Vitest, Playwright testing approach
  - Key files: `playwright.config.ts`, `frontend/vitest.config.ts`, `backend/phpunit.xml`, `e2e/`, `backend/tests/`, `frontend/__tests__/`
- [ADR-009: Docker Single-Container Architecture](adr/009-docker-single-container.md) - Single container with Supervisor process management
  - Key files: `docker/Dockerfile`, `docker/supervisord.conf`, `docker/nginx.conf`, `docker-compose.yml`, `docker-compose.prod.yml`
- [ADR-010: Database Abstraction Strategy](adr/010-database-abstraction.md) - SQLite default with MySQL/PostgreSQL/Supabase support
  - Key files: `backend/config/database.php`, `backend/database/migrations/`
- [ADR-011: Global Navigation Architecture](adr/011-global-navigation-architecture.md) - Frontend navigation and routing structure
  - Key files: `frontend/components/sidebar.tsx`, `frontend/components/app-shell.tsx`, `frontend/components/header.tsx`, `frontend/components/logo.tsx`, `frontend/config/app.ts`
- [ADR-012: Admin-Only Settings Access](adr/012-admin-only-settings.md) - Admin authorization for system settings
  - Key files: `frontend/app/(dashboard)/configuration/layout.tsx`, `frontend/app/(dashboard)/configuration/`, `frontend/components/admin/`
- [ADR-013: Responsive Mobile-First Design](adr/013-responsive-mobile-first-design.md) - Mobile-first responsive design approach
  - Key files: `frontend/lib/use-mobile.ts`, `frontend/components/sidebar.tsx`, `frontend/components/app-shell.tsx`, `frontend/components/header.tsx`, `frontend/components/ui/sheet.tsx`, `frontend/tailwind.config.ts`
- [ADR-014: Database Settings with Environment Fallback](adr/014-database-settings-env-fallback.md) - Database-stored settings with env fallback and boot-time config injection
  - Key files: `backend/app/Services/SettingService.php`, `backend/app/Providers/ConfigServiceProvider.php`, `backend/config/settings-schema.php`, `backend/app/Models/SystemSetting.php`
- [ADR-015: Environment-Only Settings](adr/015-env-only-settings.md) - Settings that must remain in .env (APP_KEY, DB_*, CACHE_STORE, LOG_*, APP_ENV, APP_DEBUG)
  - Key files: `backend/config/settings-schema.php`, `.env.example`
- [ADR-016: Email Template System](adr/016-email-template-system.md) - Database-stored email templates with variable replacement; TemplatedMail Mailable; User overrides (password reset, email verification); EmailChannel uses notification template
  - Key files: `backend/app/Models/EmailTemplate.php`, `backend/app/Services/EmailTemplateService.php`, `backend/app/Services/RenderedEmail.php`, `backend/app/Mail/TemplatedMail.php`, `backend/app/Models/User.php` (sendPasswordResetNotification, sendEmailVerificationNotification), `backend/app/Services/Notifications/Channels/EmailChannel.php`, `backend/app/Http/Controllers/Api/EmailTemplateController.php`, `backend/database/seeders/EmailTemplateSeeder.php`; frontend: `frontend/app/(dashboard)/configuration/email-templates/page.tsx`, `frontend/app/(dashboard)/configuration/email-templates/[key]/page.tsx`, `frontend/components/email-template-editor.tsx`, `frontend/components/variable-picker.tsx`
- [ADR-017: Notification Template System](adr/017-notification-template-system.md) - Per-type templates for push, inapp, chat; NotificationTemplateService; sendByType(); channels resolve template when present
  - Key files: `backend/app/Models/NotificationTemplate.php`, `backend/app/Services/NotificationTemplateService.php`, `backend/database/seeders/NotificationTemplateSeeder.php`, `backend/app/Http/Controllers/Api/NotificationTemplateController.php`, `backend/app/Services/Notifications/NotificationOrchestrator.php`, `backend/app/Services/Notifications/Channels/*`; frontend: `frontend/app/(dashboard)/configuration/notification-templates/page.tsx`, `frontend/app/(dashboard)/configuration/notification-templates/[id]/page.tsx`
- [ADR-018: Passkey / WebAuthn Authentication](adr/018-passkey-webauthn.md) - Passkey (WebAuthn) sign-in with Laragear/WebAuthn; configurable passkey_mode (disabled/optional/required)
  - Key files: `backend/app/Services/Auth/PasskeyService.php`, `backend/app/Http/Controllers/Api/PasskeyController.php`, `backend/app/Models/User.php` (WebAuthnAuthenticatable), `backend/config/settings-schema.php` (auth.passkey_mode), `frontend/lib/use-passkeys.ts`, `frontend/components/auth/passkey-register-dialog.tsx`, `frontend/components/auth/passkey-login-button.tsx`, `frontend/app/(dashboard)/user/security/page.tsx`, `frontend/app/(auth)/login/page.tsx`, `frontend/app/(dashboard)/configuration/security/page.tsx`
- [ADR-019: Progressive Web App (PWA) Service Worker](adr/019-progressive-web-app.md) - Manual service worker with Workbox 7.3.0 (bundled locally); cache-first for static assets, network-first for API; offline fallback; stale-queue cleanup; safe-area CSS
  - Key files: `frontend/public/sw.js`, `frontend/public/offline.html`, `frontend/public/workbox/`, `frontend/lib/service-worker.ts`, `frontend/components/service-worker-setup.tsx`, `scripts/generate-pwa-icons.mjs`, `frontend/app/api/manifest/route.ts`
- [ADR-020: User Groups and Permissions System](adr/020-user-groups-permissions.md) - Group-based authorization with granular permissions
  - Key files: `backend/app/Services/GroupService.php`, `backend/app/Services/PermissionService.php`, `backend/app/Models/UserGroup.php`, `backend/app/Enums/Permission.php`, `backend/app/Http/Controllers/Api/GroupController.php`, `backend/app/Http/Traits/AdminAuthorizationTrait.php`, `frontend/components/permission-gate.tsx`, `frontend/lib/use-permission.ts`, `frontend/app/(dashboard)/configuration/groups/page.tsx`
- [ADR-021: Search with Meilisearch Integration](adr/021-search-meilisearch-integration.md) - Embedded Meilisearch with Laravel Scout for full-text search
  - Key files: `backend/app/Services/Search/SearchService.php`, `backend/app/Http/Controllers/Api/SearchController.php`, `backend/config/scout.php`, `backend/config/search-pages.php`, `frontend/components/search/search-modal.tsx`, `frontend/components/search/search-provider.tsx`, `frontend/lib/search.ts`
- [ADR-022: Storage Provider System](adr/022-storage-provider-system.md) - Multi-provider file storage with S3, GCS, Azure, and local support
  - Key files: `backend/app/Services/StorageService.php`, `backend/app/Http/Controllers/Api/StorageSettingController.php`, `backend/app/Http/Controllers/Api/FileManagerController.php`, `backend/config/filesystems.php`, `frontend/app/(dashboard)/configuration/storage/page.tsx`, `frontend/components/storage/file-browser.tsx`
- [ADR-023: Audit Logging System](adr/023-audit-logging-system.md) - Database-backed audit logging with real-time broadcasting and severity levels
  - Key files: `backend/app/Services/AuditService.php`, `backend/app/Models/AuditLog.php`, `backend/app/Events/AuditLogCreated.php`, `backend/app/Http/Controllers/Api/AuditLogController.php`, `frontend/app/(dashboard)/configuration/audit/page.tsx`, `frontend/lib/use-audit-stream.ts`
- [ADR-024: Security Hardening](adr/024-security-hardening.md) - SSRF protection, SQL injection fixes, OAuth security, password policy, webhook signatures
  - Key files: `backend/app/Services/UrlValidationService.php`, `backend/app/Services/WebhookService.php`, `backend/app/Http/Middleware/RateLimitSensitive.php`
  - See also: [Security Patterns](ai/patterns/security.md)
- [ADR-027: Email Hosting Architecture](adr/027-email-hosting-architecture.md) - Provider-based email hosting with webhook inbound, API outbound, conversation threading, Gmail-style labels
  - Key files: `backend/app/Services/Email/EmailService.php`, `backend/app/Services/Email/MailgunProvider.php`, `backend/app/Services/Email/EmailProviderInterface.php`, `backend/app/Http/Controllers/Api/EmailWebhookController.php`, `backend/app/Models/Email.php`, `frontend/app/(dashboard)/mail/page.tsx`, `frontend/components/mail/`
- [ADR-031: Email Provider Management Architecture](adr/031-email-provider-management-architecture.md) - Capability-based provider interfaces, management controller, credential hierarchy, webhook architecture
  - Key files: `backend/app/Services/Email/ProviderManagementInterface.php`, `backend/app/Services/Email/Concerns/Has*.php`, `backend/app/Http/Controllers/Api/ProviderManagementController.php`, `backend/app/Models/EmailProviderAccount.php`, `backend/app/Services/Email/DomainService.php`

### Logging and Observability

Application logging (operational and diagnostic events) is separate from audit logging (user actions). Backend uses Laravel `Log` facade with structured context; correlation IDs and request context are added via `ContextProcessor`. Frontend errors are reported to `POST /api/client-errors` and logged server-side. See [Logging](logging.md) for standards, configuration, and key files.

**Key files**: `backend/config/logging.php`, `backend/app/Logging/ContextProcessor.php`, `backend/app/Http/Middleware/AddCorrelationId.php`, `backend/app/Http/Controllers/Api/ClientErrorController.php`, `frontend/lib/error-logger.ts`, `frontend/components/error-boundary.tsx`, `frontend/components/error-handler-setup.tsx`

## Settings Architecture

### Database Settings with Env Fallback (ADR-014)

System-wide configurable settings are stored in `system_settings` with environment fallback:

- **SettingService**: `get()`, `getGroup()`, `set()`, `reset()`, `all()` with file-based caching and env fallback per `backend/config/settings-schema.php`. The schema defines a `public` flag per key; when saving, `SettingService::set()` sets `is_public` on the database record so public settings (e.g. `general.app_name`) are returned by `SystemSetting::getPublic()`
- **ConfigServiceProvider**: Injects database settings into Laravel config at boot (skips when DB not ready)
- **Encryption**: Sensitive values stored with `is_encrypted`; model decrypts on read

**Key files**: `backend/app/Services/SettingService.php`, `backend/app/Providers/ConfigServiceProvider.php`, `backend/config/settings-schema.php`, `backend/app/Models/SystemSetting.php`, `backend/app/Http/Controllers/Api/MailSettingController.php`

### Settings Caching Strategy

- Settings cached via SettingService (file store, not DB) with TTL; cache cleared on any `set()` or `reset()`
- In-memory cache for same-request performance

**Key files**: `backend/app/Services/SettingService.php`, `backend/config/cache.php`

### Settings Validation

Validation rules per setting type in controllers; schema defines env keys and defaults in `backend/config/settings-schema.php`.

**Key files**: `backend/config/settings-schema.php`, `backend/app/Http/Controllers/Api/MailSettingController.php`, `backend/app/Http/Controllers/Api/SettingController.php`
