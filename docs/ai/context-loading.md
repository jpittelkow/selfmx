# Context Loading Guide

Which files to read first based on your task type.

## Before Any Implementation

**CRITICAL: Check for existing components first!**

Before writing any code, search the codebase:

1. **Search `frontend/components/`** - Does a similar component exist?
2. **Search `frontend/lib/`** - Is there a utility for this task?
3. **Search existing pages** - How is this solved elsewhere?

**Never duplicate logic across pages.** If functionality exists, use it. If it should exist, create it as a global component.

See: [Global Components Pattern](patterns/components.md)

## New Project Setup

When someone is starting a new project from selfmx (forking, customizing, "build me an app"). **Trigger phrase: "Get cooking"** starts the guided wizard.

**Read first:**
```
docs/ai/recipes/setup-new-project.md              # Master index (START HERE)
docs/ai/recipes/setup-identity-branding.md         # Tier 1: rename app, fonts, colors, docs reset
docs/ai/recipes/setup-features-auth.md             # Tier 2: feature removal, auth model
docs/ai/recipes/setup-infrastructure-repo.md       # Tier 3: database, port, timezone, git
FORK-ME.md                                         # What selfmx provides
docs/customization-checklist.md                    # Detailed feature removal file lists
frontend/config/app.ts                             # App identity config (short name)
frontend/config/fonts.ts                           # Font configuration (body + heading)
frontend/app/layout.tsx                            # Root layout (metadata, font application)
frontend/app/globals.css                           # CSS theme variables
.env.example                                       # Environment variables template
```

**Also useful:**
```
frontend/lib/app-config.tsx             # App config provider (logo, colors, features)
frontend/lib/theme-colors.ts            # Theme color application utilities
frontend/app/(dashboard)/configuration/branding/page.tsx  # Branding admin page
docs/plans/branding-ui-consistency-roadmap.md             # How branding works
```

**Key points:**
- The setup is broken into **3 tiers** — ask questions then execute per tier, pause at any boundary
- Tier 1 (Identity & Branding) renames ~50+ files with "selfmx" references
- Tier 2 (Features & Auth) removes unwanted features and trims the auth stack
- Tier 3 (Infrastructure & Repo) configures database, port, timezone, and git
- Font changes only require editing `frontend/config/fonts.ts` — CSS variables and Tailwind config reference generic `--font-body` / `--font-heading` names
- Colors are managed at runtime via the branding admin page, not in code
- Feature removal details are in `docs/customization-checklist.md` Section 4

**Recipes:**
- [Set Up New Project (master index)](recipes/setup-new-project.md)
- [Tier 1: Identity & Branding](recipes/setup-identity-branding.md)
- [Tier 2: Features & Auth](recipes/setup-features-auth.md)
- [Tier 3: Infrastructure & Repo](recipes/setup-infrastructure-repo.md)

## Frontend UI Work

**Read first:**
```
frontend/app/(dashboard)/           # Existing page patterns
frontend/components/ui/             # shadcn components (CLI-managed; frontend/components.json)
frontend/components/ui/collapsible-card.tsx  # Collapsible sections (settings, config)
frontend/components/provider-icons.tsx       # Provider/channel icons (SSO, LLM, etc.)
frontend/components/app-breadcrumbs.tsx      # Path-based breadcrumbs (auto in header)
frontend/components/                # App-specific components
frontend/config/app.ts              # App configuration (branding, etc.)
frontend/lib/api.ts                 # API call patterns
frontend/lib/utils.ts               # Utility functions
```

**Also useful:**
```
frontend/app/(dashboard)/layout.tsx # Dashboard layout structure
frontend/components/sidebar.tsx     # Navigation structure
frontend/components/header.tsx      # Header components
frontend/components/logo.tsx        # Logo component with variants
```

**Recipes:**
- [Add UI component](recipes/add-ui-component.md)
- [Add collapsible section](recipes/add-collapsible-section.md)
- [Add provider icon](recipes/add-provider-icon.md)

## Backend API Work

**Read first:**
```
backend/routes/api.php                          # Existing routes
backend/app/Http/Controllers/Api/               # Controller patterns
backend/app/Http/Requests/                      # Validation patterns
backend/app/Http/Resources/                     # Response formatting
backend/app/Http/Traits/                        # Shared controller traits
  AdminAuthorizationTrait.php                   # Last admin protection
  ApiResponseTrait.php                          # Standardized responses
```

**Also useful:**
```
backend/app/Models/                             # Data models
backend/database/migrations/                    # Database schema
```

**Recipes:**
- [Add API endpoint](recipes/add-api-endpoint.md)
- [Add admin-protected action](recipes/add-admin-protected-action.md)

## Notifications Work

**Read first:**
```
docs/adr/005-notification-system-architecture.md
docs/adr/025-novu-notification-integration.md   # Optional Novu path
backend/app/Services/Notifications/NotificationOrchestrator.php
backend/app/Services/Notifications/NotificationChannelMetadata.php  # Shared trait
backend/app/Services/Notifications/Channels/    # Existing channels
backend/config/notifications.php
```

**Also useful:**
```
backend/app/Services/NovuService.php            # Novu SDK wrapper (when Novu enabled)
backend/app/Http/Controllers/Api/NovuSettingController.php   # Admin Novu config
backend/app/Http/Controllers/Api/NovuController.php          # Subscriber HMAC
backend/app/Http/Controllers/Api/NotificationController.php
backend/app/Http/Controllers/Api/NotificationChannelConfigController.php  # Admin API
backend/app/Http/Controllers/Api/UserNotificationSettingsController.php   # User API
backend/app/Models/Notification.php
frontend/app/(dashboard)/configuration/notifications/page.tsx  # Admin UI
frontend/app/(dashboard)/configuration/novu/page.tsx          # Novu config
frontend/components/notifications/notification-bell.tsx      # Bell (Novu vs local)
frontend/components/notifications/novu-inbox.tsx             # Novu Inbox wrapper
frontend/app/(dashboard)/user/preferences/page.tsx             # User UI
```

**Recipes:**
- [Trigger Notifications](recipes/trigger-notifications.md)
- [Add Notification Channel](recipes/add-notification-channel.md)
- [Configure Novu](recipes/configure-novu.md)
- [Test Notifications](recipes/test-notifications.md)
- [Verify Notification Config](recipes/verify-notification-config.md)

## LLM Work

**Read first:**
```
docs/adr/006-llm-orchestration-modes.md
backend/app/Services/LLM/LLMOrchestrator.php
backend/app/Services/LLM/Providers/             # Existing providers
backend/config/llm.php
```

**Also useful:**
```
backend/app/Http/Controllers/Api/LLMController.php
backend/app/Services/LLMModelDiscoveryService.php   # Model discovery (Test Key / Fetch Models)
backend/app/Http/Controllers/Api/LLMModelController.php
frontend/app/(dashboard)/configuration/ai/page.tsx  # AI/LLM settings UI, providerTemplates
```

For adding a new LLM provider with model discovery, use [Recipe: Add LLM Provider](recipes/add-llm-provider.md).

## Settings/Configuration Work

**Read first:**
```
docs/adr/012-admin-only-settings.md
docs/adr/014-database-settings-env-fallback.md  # Database settings with env fallback
backend/app/Services/SettingService.php         # Core settings service
backend/config/settings-schema.php              # Migratable settings definition
backend/app/Providers/ConfigServiceProvider.php # Boot-time config injection
frontend/app/(dashboard)/configuration/         # Configuration pages
backend/app/Http/Controllers/Api/SettingController.php
backend/app/Http/Controllers/Api/MailSettingController.php
backend/app/Http/Controllers/Api/SSOSettingController.php
```

**Also useful:**
```
backend/app/Models/Setting.php
backend/app/Models/SystemSetting.php
docs/plans/env-to-database-roadmap.md
```

**Adding a configuration page or menu item:**
- [frontend/app/(dashboard)/configuration/layout.tsx](frontend/app/(dashboard)/configuration/layout.tsx) – `navigationGroups` and grouped nav
- [Recipe: Add configuration menu item](recipes/add-configuration-menu-item.md)
- [Recipe: Add configuration page](recipes/add-config-page.md) – Form and page structure
- [Recipe: Add settings page](recipes/add-settings-page.md) – Settings page with SettingService

## Payments/Stripe Work

**Read first:**
```
docs/adr/026-stripe-connect-integration.md
backend/app/Services/Stripe/StripeService.php
backend/app/Services/Stripe/StripeConnectService.php
backend/app/Services/Stripe/StripeWebhookService.php
backend/config/stripe.php
backend/config/settings-schema.php                  # stripe group
```

**Also useful:**
```
backend/app/Http/Controllers/Api/StripeSettingController.php
backend/app/Http/Controllers/Api/StripeConnectController.php
backend/app/Http/Controllers/Api/StripeConnectCallbackController.php
backend/app/Http/Controllers/Api/StripePaymentController.php
backend/app/Http/Controllers/Api/StripeWebhookController.php
backend/app/Models/Payment.php
backend/app/Models/StripeCustomer.php
backend/app/Models/StripeWebhookEvent.php
frontend/lib/stripe.ts
frontend/app/(dashboard)/configuration/stripe/page.tsx
frontend/app/(dashboard)/configuration/payments/page.tsx
backend/app/Providers/ConfigServiceProvider.php     # injectStripeConfig
backend/routes/api.php                              # stripe routes
backend/routes/web.php                              # connect callback
```

**Recipes:**
- [Setup Stripe](recipes/setup-stripe.md)
- [Add Payment Flow](recipes/add-payment-flow.md)
- [Handle Stripe Webhooks](recipes/handle-stripe-webhooks.md)
- [Stripe Connect Onboarding](recipes/stripe-connect-onboarding.md)

## Email Provider Selection Work

**Read first:**
```
docs/adr/031-email-provider-management-architecture.md  # Capability-based provider architecture (interfaces, DTOs, credential hierarchy)
docs/adr/027-email-hosting-architecture.md              # Core email hosting architecture
docs/plans/email-provider-comparison.md                 # Provider comparison, pricing, selection criteria, API docs
backend/app/Services/Email/EmailProviderInterface.php   # Core provider interface contract
backend/app/Services/Email/ProviderManagementInterface.php  # Management capability declaration
backend/app/Services/Email/Concerns/                    # Capability-specific interfaces (7 files)
```

**Also useful:**
```
backend/app/Services/Email/MailgunProvider.php    # Reference adapter implementation (full capabilities)
backend/app/Services/Email/SesProvider.php        # SES adapter (partial capabilities)
backend/app/Services/Email/DomainService.php      # Domain lifecycle, provider factory, credential resolution
backend/app/Http/Controllers/Api/ProviderManagementController.php  # Provider-agnostic management controller
backend/app/Models/EmailProviderAccount.php       # Multi-account credential storage
backend/app/Exceptions/ProviderApiException.php   # Provider error type
backend/app/Services/Email/ParsedEmail.php        # Inbound email DTO
backend/app/Services/Email/SendResult.php         # Send result DTO
backend/app/Services/Email/DomainResult.php       # Domain registration DTO
backend/config/settings-schema.php                # email_hosting settings group
frontend/app/(dashboard)/configuration/email-provider/ # Provider configuration UI
```

**Patterns & Recipes:**
- [Email Provider Pattern](patterns/email-provider.md)
- [Email Provider Anti-Patterns](anti-patterns/email-provider.md)
- [Recipe: Add Email Provider](recipes/add-email-provider.md)

## Email Template Work

**Read first:**
```
docs/adr/016-email-template-system.md
backend/app/Models/EmailTemplate.php
backend/app/Services/EmailTemplateService.php
backend/database/seeders/EmailTemplateSeeder.php
```

**Also useful (backend):**
```
backend/app/Http/Controllers/Api/EmailTemplateController.php
backend/routes/api.php
backend/app/Services/RenderedEmail.php
backend/app/Mail/TemplatedMail.php
backend/app/Models/User.php (sendPasswordResetNotification, sendEmailVerificationNotification)
backend/app/Services/Notifications/Channels/EmailChannel.php
```

**Frontend (Admin UI):**
```
frontend/app/(dashboard)/configuration/email-templates/page.tsx
frontend/app/(dashboard)/configuration/email-templates/[key]/page.tsx
frontend/components/email-template-editor.tsx
frontend/components/variable-picker.tsx
frontend/app/(dashboard)/configuration/layout.tsx
```

**Recipes:**
- [Add Email Template](recipes/add-email-template.md)

## Notification Template Work

**Read first:**
```
docs/adr/017-notification-template-system.md
backend/app/Models/NotificationTemplate.php
backend/app/Services/NotificationTemplateService.php
backend/database/seeders/NotificationTemplateSeeder.php
```

**Also useful (backend):**
```
backend/app/Http/Controllers/Api/NotificationTemplateController.php
backend/routes/api.php
backend/app/Services/Notifications/NotificationOrchestrator.php
backend/app/Services/Notifications/Channels/DatabaseChannel.php
backend/app/Services/Notifications/Channels/WebPushChannel.php
backend/app/Services/Notifications/Channels/EmailChannel.php
```

**Frontend (Admin UI):**
```
frontend/app/(dashboard)/configuration/notification-templates/page.tsx
frontend/app/(dashboard)/configuration/notification-templates/[id]/page.tsx
frontend/app/(dashboard)/configuration/layout.tsx
```

**Recipes:**
- [Add Notification Template](recipes/add-notification-template.md)
- [Trigger Notifications](recipes/trigger-notifications.md) — sendByType()
- [Keep notification template variables up to date](recipes/keep-notification-template-variables-up-to-date.md)

## Authentication Work

**Read first:**
```
docs/adr/002-authentication-architecture.md
docs/adr/003-sso-provider-integration.md
docs/adr/004-two-factor-authentication.md
backend/app/Http/Controllers/Api/AuthController.php
frontend/lib/auth.ts
```

**Also useful:**
```
backend/app/Services/Auth/SSOService.php        # SSO logic (cache-based state tokens, stateless Socialite)
backend/routes/web.php                          # SSO browser routes (redirect + callback) — uses web middleware, NOT api
backend/app/Services/Auth/TwoFactorService.php
backend/app/Services/Auth/PasskeyService.php
frontend/app/(auth)/                            # Auth pages (login, register, etc.)
frontend/app/auth/callback/page.tsx             # SSO callback handler (post-OAuth redirect; NOT inside (auth) route group — see file comments)
frontend/components/auth/                       # Auth components
  - auth-page-layout.tsx                        # login-03 layout (bg-muted + Card)
  - auth-divider.tsx                            # SSO/email divider (bg-card)
  - auth-state-card.tsx                         # Success/error states (bg-muted + Card)
  - sso-buttons.tsx                             # SSO provider buttons (login/register)
frontend/components/admin/
  - sso-setup-modal.tsx                         # SSO setup instructions per provider
frontend/components/ui/
  - form-field.tsx                              # Label + description/helpLink + Input + error
  - loading-button.tsx                           # Button with spinner
```

**Recipes:**
- [Add SSO provider](recipes/add-sso-provider.md)

## User Management Work

**Read first:**
```
docs/adr/002-authentication-architecture.md
backend/app/Http/Controllers/Api/UserController.php
backend/app/Models/User.php
frontend/app/(dashboard)/configuration/users/page.tsx
frontend/components/admin/user-table.tsx
frontend/components/admin/user-dialog.tsx
```

**Also useful:**
```
backend/routes/api.php                          # users routes (can:admin), PUT /users/{user}/groups
backend/app/Http/Traits/AdminAuthorizationTrait.php
frontend/components/admin/user-group-picker.tsx # Group assignment in user edit
frontend/lib/use-groups.ts                      # useGroups() for filter/picker
frontend/lib/auth.ts                            # isAdminUser(user) for admin checks (group-based)
```

## User Groups Work

**Read first:**
```
backend/app/Models/UserGroup.php
backend/app/Models/GroupPermission.php
backend/app/Traits/HasGroups.php
backend/app/Services/GroupService.php
backend/app/Services/PermissionService.php
backend/app/Enums/Permission.php
```

**Also useful (backend):**
```
backend/app/Http/Controllers/Api/GroupController.php
backend/routes/api.php                          # groups, permissions routes
backend/app/Http/Resources/GroupResource.php
```

**Frontend (Admin UI):**
```
frontend/app/(dashboard)/configuration/groups/page.tsx
frontend/components/admin/group-table.tsx
frontend/components/admin/group-dialog.tsx
frontend/components/admin/member-manager.tsx
frontend/components/admin/permission-matrix.tsx
frontend/components/admin/user-group-picker.tsx   # Group multi-select (user edit)
frontend/app/(dashboard)/configuration/layout.tsx  # Groups nav item (permission-based filtering)
frontend/lib/use-groups.ts                        # useGroups() hook
frontend/lib/use-permission.ts                    # usePermission(), usePermissions()
frontend/components/permission-gate.tsx           # PermissionGate component
```

**User Management (groups integration):**
```
backend/app/Http/Controllers/Api/UserController.php  # index (groups + filter), show (groups), updateGroups
frontend/app/(dashboard)/configuration/users/page.tsx
frontend/components/admin/user-table.tsx             # Groups column
frontend/components/admin/user-dialog.tsx            # UserGroupPicker
```

**Recipes:**
- [Create custom group](recipes/create-custom-group.md)
- [Add new permission](recipes/add-new-permission.md)
- [Assign user to groups](recipes/assign-user-to-groups.md)

## Backup System Work

**Read first:**
```
docs/adr/007-backup-system-design.md
backend/app/Services/Backup/BackupService.php
backend/app/Services/Backup/Destinations/         # Existing destinations (DestinationInterface)
backend/config/settings-schema.php              # backup group (flat keys)
backend/app/Providers/ConfigServiceProvider.php # injectBackupConfig()
```

**Also useful:**
```
backend/app/Http/Controllers/Api/BackupController.php       # List, create, download, restore, delete
backend/app/Http/Controllers/Api/BackupSettingController.php # Settings API + Test Connection
backend/config/backup.php
frontend/app/(dashboard)/configuration/backup/page.tsx       # Backups tab + Settings tab
```

**Recipes:**
- [Add backup destination](recipes/add-backup-destination.md)
- [Extend backup and restore features](recipes/extend-backup-restore.md)

## Storage Settings Work

**Read first:**
```
backend/app/Services/StorageService.php                       # Provider config, testConnection, buildDiskConfig
backend/app/Http/Controllers/Api/StorageSettingController.php  # Settings, paths, health, stats, test endpoint
backend/config/filesystems.php                                # Disk configuration (local, s3, gcs, azure, do_spaces, minio, b2)
frontend/app/(dashboard)/configuration/storage/page.tsx        # Storage settings UI (driver dropdown, dynamic forms, Test Connection)
```

**Also useful:**
```
backend/app/Providers/AppServiceProvider.php  # GCS/Azure Storage::extend() when packages installed
backend/app/Console/Commands/StorageAlertCommand.php  # storage:check-alerts (uses SettingService for storage alert settings)
frontend/components/provider-icons.tsx        # Storage provider icons (s3, gdrive, azure, do_spaces, minio, b2)
docs/plans/storage-settings-roadmap.md       # Phases 1–2 done; Phases 3–4 (file manager, analytics) planned
```

**Recipes:**
- [Add storage provider](recipes/add-storage-provider.md)

## Search Work

**Read first:**
```
backend/app/Services/Search/SearchService.php
backend/app/Http/Controllers/Api/SearchController.php
backend/config/scout.php
backend/routes/api.php                    # search, search/suggestions
frontend/components/search/search-modal.tsx
frontend/components/search/search-provider.tsx
frontend/lib/search.ts
frontend/lib/search-pages.ts              # Static page search (Pages group in Cmd+K)
```

**Also useful:**
```
backend/app/Console/Commands/SearchReindexCommand.php
backend/app/Http/Controllers/Api/Admin/SearchAdminController.php
frontend/app/(dashboard)/configuration/search/page.tsx
frontend/components/search/search-result-icon.tsx
```

**Compliance:** Search and suggestions endpoints return user data. Transform methods must escape title/subtitle (XSS). See [Patterns: SearchService](patterns/search-service.md) and [Recipe: Add searchable model](recipes/add-searchable-model.md).

**Recipes:**
- [Add searchable model](recipes/add-searchable-model.md)
- [Add searchable page](recipes/add-searchable-page.md)

## Help / Documentation Work

**Read first:**
```
frontend/lib/help/help-content.ts          # Article definitions (userHelpCategories + permissionHelpCategories)
frontend/components/help/                  # HelpLink, HelpCenterModal, HelpProvider
frontend/lib/tooltip-content.ts            # Field-level tooltip content
backend/config/search-pages.php            # Search index (help: URL entries with permission field)
```

**Also useful:**
```
frontend/components/ui/help-tooltip.tsx    # HelpTooltip for FormField
frontend/components/search/search-modal.tsx  # Intercepts help: URLs
frontend/components/app-shell.tsx          # HelpProvider nesting (must wrap SearchProvider)
frontend/lib/use-permission.ts             # usePermission() hook (help uses permissions array)
```

**Key concepts:**
- Help categories use `permission?: string` (not `adminOnly`) to gate visibility
- `getAllCategories(permissions)` / `findArticle(id, permissions)` take a permissions array
- Every config page should have a `HelpLink` in its description area

**Recipe:**
- [Add help article](recipes/add-help-article.md)

**Pattern:** [Help System](patterns/ui-patterns.md#help-system)

## Dashboard/Widget Work

> **Note**: Dashboard uses static, developer-defined widgets (no user configuration).
> Layout: welcome banner (full-width) → metric cards + quick actions (3-col grid) → usage chart (admin).
> Stats use `AuditStatsCard` for metric cards. Quick actions use 2x2 icon tile grid.

**Read first:**
```
frontend/app/(dashboard)/dashboard/page.tsx         # Main dashboard layout
frontend/components/dashboard/                      # Widget components
frontend/components/audit/audit-stats-card.tsx      # Metric card component (used by StatsWidget)
docs/ai/recipes/add-dashboard-widget.md             # Widget creation guide
```

**Also useful:**
```
backend/app/Http/Controllers/Api/DashboardController.php  # Data endpoints for widgets
frontend/components/dashboard/widgets/                    # Reference: welcome (greeting), stats (metric cards), quick-actions (icon tiles)
frontend/components/usage/usage-dashboard-widget.tsx      # Usage chart with time range toggles
frontend/lib/use-permission.ts                            # Permission-based visibility
```

**Recipes:**
- [Add Dashboard Widget](recipes/add-dashboard-widget.md)

## Audit Logging Work

**Read first:**
```
docs/ai/patterns/audit-service.md      # AuditService Pattern
backend/app/Services/AuditService.php  # Core audit service
backend/app/Http/Traits/AuditLogging.php
backend/app/Models/AuditLog.php
backend/app/Http/Controllers/Api/AuditLogController.php
```

**Also useful:**
```
backend/routes/api.php                 # audit-logs routes (admin)
frontend/app/(dashboard)/configuration/audit/page.tsx  # Audit log UI
frontend/app/(dashboard)/dashboard/page.tsx            # Dashboard (audit widget for admins)
frontend/components/audit/audit-dashboard-widget.tsx   # Audit summary widget
frontend/components/audit/audit-stats-card.tsx         # Stat card component
frontend/components/audit/audit-severity-chart.tsx     # Severity donut chart
frontend/components/audit/audit-trends-chart.tsx       # Activity trends area chart
frontend/components/ui/chart.tsx                       # shadcn chart (Recharts)
backend/database/migrations/           # audit_logs table, severity column
```

**Stats API** (`GET /audit-logs/stats`): Returns `total_actions`, `by_severity`, `daily_trends` (date→count), `recent_warnings` (latest 5 warning/error/critical), `actions_by_type`, `actions_by_user`. Query params: `date_from`, `date_to` (default last 30 days). Index/export support filter by `correlation_id`.

**Recipes:**
- [Trigger audit logging](recipes/trigger-audit-logging.md)
- [Add auditable action](recipes/add-auditable-action.md)
- [Add dashboard widget](recipes/add-dashboard-widget.md) – for dashboard analytics widgets (see audit widget as example)

## Application Logging Work

**Read first:**
```
docs/logging.md
backend/config/logging.php
backend/app/Logging/ContextProcessor.php
backend/app/Http/Middleware/AddCorrelationId.php
frontend/lib/error-logger.ts
frontend/components/error-boundary.tsx
frontend/components/error-handler-setup.tsx
```

**Also useful:**
```
backend/app/Http/Controllers/Api/ClientErrorController.php
backend/app/Services/AppLogExportService.php      # App log file export (date/level/correlation_id)
backend/app/Http/Controllers/Api/AppLogExportController.php  # GET /api/app-logs/export
backend/app/Http/Controllers/Api/LogRetentionController.php  # Log retention settings (group logging)
backend/app/Console/Commands/LogCleanupCommand.php           # log:cleanup (--dry-run, --archive)
backend/app/Services/SuspiciousActivityService.php           # Suspicious pattern detection
backend/app/Console/Commands/CheckSuspiciousActivityCommand.php  # log:check-suspicious
backend/routes/api.php  # client-errors, app-logs/export, log-retention, suspicious-activity
frontend/app/(dashboard)/configuration/logs/page.tsx          # Application Logs + Export
frontend/app/(dashboard)/configuration/log-retention/page.tsx # Log retention UI
```

**Recipes:**
- [Extend logging](recipes/extend-logging.md)

## Scheduled Jobs / Admin Tasks

**Read first:**
```
backend/app/Services/ScheduledTaskService.php   # Command whitelist, run(), last run, rate limit
backend/app/Http/Controllers/Api/JobController.php
backend/app/Models/TaskRun.php
backend/routes/api.php  # jobs/* routes (admin)
frontend/app/(dashboard)/configuration/jobs/page.tsx  # Scheduled Tasks, Queue, Failed Jobs, Run Now
```

**Also useful:**
```
backend/routes/console.php       # Schedule definitions (backup:run, log:check-suspicious, etc.)
backend/database/migrations/     # task_runs table
```

Manual run is whitelist-only (`backup:run`, `log:cleanup`, `log:check-suspicious`). Runs are recorded in `task_runs` and audited.

## Docker/Infrastructure Work

**Read first:**
```
docs/adr/009-docker-single-container.md
docker/Dockerfile
docker/supervisord.conf
docker/nginx.conf
docker/entrypoint.sh
docker-compose.yml
```

**Also useful:**
```
docker-compose.prod.yml
.env.example
```

## Database Work

**Read first:**
```
docs/adr/010-database-abstraction.md
backend/config/database.php
backend/database/migrations/                    # Existing schema
```

**Also useful:**
```
backend/app/Models/                             # Eloquent models
```

## Testing Work

**Read first:**
```
docs/adr/008-testing-strategy.md
e2e/                                            # Playwright tests
backend/tests/                                  # PHP tests
frontend/__tests__/                             # Vitest tests (if exists)
```

**Recipes:**
- [Add tests](recipes/add-tests.md)

## Navigation/Layout Work

**Read first:**
```
docs/adr/011-global-navigation-architecture.md
frontend/components/sidebar.tsx
frontend/components/header.tsx
frontend/components/app-breadcrumbs.tsx     # Path-based breadcrumbs (in header)
frontend/components/app-shell.tsx           # Main layout shell (container, content well)
frontend/components/logo.tsx
frontend/config/app.ts
frontend/app/(dashboard)/layout.tsx
```

## Branding/UI Customization Work

**Read first:**
```
docs/plans/branding-ui-consistency-roadmap.md
frontend/config/app.ts                  # Centralized app configuration
frontend/components/logo.tsx            # Logo component with dark/light mode variants
frontend/lib/app-config.tsx             # App config provider (logoUrl, logoDarkUrl, etc.)
frontend/app/globals.css                # CSS theme variables
```

**Also useful:**
```
frontend/components/header.tsx          # Uses Logo component
frontend/components/sidebar.tsx         # Uses Logo component
frontend/components/theme-provider.tsx  # Theme provider (useTheme for dark mode detection)
backend/app/Http/Controllers/Api/BrandingController.php  # Branding API (logo, dark logo, favicon)
.env.example                            # Branding environment variables
```

## PWA Work

**Read first:**
```
docs/adr/019-progressive-web-app.md               # PWA architecture decisions
docs/plans/pwa-roadmap.md                         # PWA phases and tasks
frontend/public/manifest.json                     # Static manifest (API route overrides at /api/manifest)
frontend/app/api/manifest/route.ts                # Dynamic manifest (branding, full icon set, shortcuts, share_target)
frontend/public/sw.js                             # Service worker (caching, push, sync)
frontend/lib/use-install-prompt.ts                # Install prompt hook
frontend/components/install-prompt.tsx            # Install banner component
frontend/lib/request-queue.ts                      # Offline request queue and background sync
```

**Also useful:**
```
frontend/lib/service-worker.ts                    # SW registration
frontend/lib/web-push.ts                          # Push subscription
frontend/components/service-worker-setup.tsx      # SW registration + NAVIGATE listener
frontend/components/app-shell.tsx                 # InstallPrompt integration
frontend/components/offline-indicator.tsx         # Offline banner (safe-area-top)
frontend/app/(dashboard)/user/preferences/page.tsx  # Install App section
frontend/app/share/page.tsx                       # Share Target handler
frontend/app/globals.css                          # PWA standalone mode, safe-area CSS
frontend/public/workbox/                          # Local Workbox 7.3.0 modules
scripts/generate-pwa-icons.mjs                    # Icon generation (icons, apple-icon, favicon)
```

**Pattern:** [PWA Install Prompt](patterns/ui-patterns.md#pwa-install-prompt)

**Recipes:**
- [Add PWA install prompt](recipes/add-pwa-install-prompt.md)

## Mobile/Responsive Work

**Read first:**
```
docs/adr/013-responsive-mobile-first-design.md    # Responsive design decisions
docs/plans/mobile-responsive-roadmap.md           # Implementation roadmap
frontend/lib/use-mobile.ts                        # Mobile detection hook
frontend/components/ui/sheet.tsx                  # Drawer component for mobile nav
```

**Also useful:**
```
frontend/components/sidebar.tsx         # Responsive sidebar implementation
frontend/components/app-shell.tsx       # Main layout with responsive structure
frontend/components/header.tsx          # Header with mobile menu toggle
frontend/tailwind.config.ts             # Tailwind breakpoint configuration
frontend/app/globals.css                # Global responsive styles
```

**Pattern:** [Responsive Design](patterns/responsive.md)
**Recipe:** `docs/ai/recipes/make-component-responsive.md`

## Webhooks Work

**Read first:**
```
backend/app/Http/Controllers/Api/WebhookController.php
backend/app/Models/Webhook.php
backend/app/Models/WebhookDelivery.php
backend/routes/api.php                    # webhooks routes (can:settings.view/edit)
```

**Also useful:**
```
frontend/app/(dashboard)/configuration/api/page.tsx  # Webhooks managed in API & Webhooks page
```

Webhooks allow external systems to receive notifications when events occur. Webhooks are managed in **Configuration > API** alongside API tokens. Endpoints: list, create, update, delete, test, view deliveries.

## API Tokens Work

**Read first:**
```
backend/app/Http/Controllers/Api/ApiTokenController.php
backend/routes/api.php                    # api-tokens routes (authenticated)
```

**Also useful:**
```
frontend/app/(dashboard)/configuration/api/page.tsx  # API tokens + webhooks page (Configuration > API)
```

API tokens allow programmatic access to the API (uses Laravel Sanctum's built-in `PersonalAccessToken` model). Users can create, list, and revoke their own tokens. Tokens are managed on the **Configuration > API** page (not user profile). This page has mixed permissions: the tokens section is visible to all authenticated users; the webhooks section is admin-only.

## Release / Deployment Work

**Read first:**
```
docs/ai/recipes/commit-and-release.md  # Step-by-step release recipe (START HERE)
scripts/push.ps1                       # Release script — commit, bump, tag, push (primary tool)
VERSION                                 # Current version number
.github/workflows/release.yml          # Release workflow (tag push + workflow_dispatch)
scripts/bump-version.sh                # Version bump script (bash, used by CI)
frontend/package.json                  # version field (must match VERSION)
frontend/public/sw.js                  # CACHE_VERSION (auto-bumped to selfmx-vX.Y.Z)
```

**Key points:**
- **`scripts/push.ps1`** is the primary release tool — it handles staging, commit, version bump, tag, and push in one command
- The "push" shortcut (Cursor rule `.cursor/rules/push-shortcut.mdc`) runs pre-flight checks then delegates to `push.ps1`
- Releases are triggered by pushing a `v*` tag to remote
- The workflow auto-syncs version files, creates a GitHub Release, and builds Docker
- Both `push.ps1` and `bump-version.sh` update three files: `VERSION`, `frontend/package.json`, and `frontend/public/sw.js` (`CACHE_VERSION`)
- The `CACHE_VERSION` in `sw.js` is set to `selfmx-vX.Y.Z` to bust service worker caches on each release
- Rebase conflicts in VERSION/package.json/sw.js are common after release workflow commits

**Recipe:**
- [Commit, Push & Release](recipes/commit-and-release.md)

## Adding a New Feature (Full Stack)

**Read in order:**
1. `docs/roadmaps.md` - Check if planned
2. `docs/architecture.md` - Find relevant ADRs
3. Relevant ADR file - Understand design decisions
4. `backend/routes/api.php` - See route patterns
5. Similar existing feature - Copy patterns
6. `docs/ai/patterns/README.md` - Code style reference
7. Relevant recipe in `docs/ai/recipes/` - Step-by-step guide
