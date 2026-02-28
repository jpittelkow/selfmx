# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).





## [0.8.0] - 2026-02-28

### Added
- Admin-only theming, Reverb defaults, inline migration seeders
## [0.7.11] - 2026-02-27

### Added
- Theming engine, auth UI redesign, dashboard widget improvements
## [0.7.8] - 2026-02-26

### Changed
- Update 6 file(s) -- backend/app/Http/Controllers/Api/NotificationController.php, backend/app/Http/Controllers/Api/PasskeyController.php, docker/Dockerfile, frontend/app/(dashboard)/user/preferences/page.tsx, frontend/next-env.d.ts (+1 more)

### Fixed
- Passkey error handling, push notification device matching, and WebAuthn permissions policy

### Changed
- Release v0.7.7
- Release v0.7.6
## [0.7.6] - 2026-02-26

### Added
- Add push notification diagnostics endpoint and delivery debug logging
## [0.7.5] - 2026-02-26

### Fixed
- Always show native push notification regardless of window focus

## [0.7.4] - 2026-02-26

### Fixed
- Separate webpush channel toggle from device management

## [0.7.3] - 2026-02-26

### Fixed
- Improve PWA push notifications and multi-device UX

## [0.7.2] - 2026-02-26

### Changed
- Extract business logic from controllers into dedicated service classes
- Add error boundary pages for auth, dashboard, and root layouts
- Extract storage, AI, and security page components into reusable modules
- Add new services: AuthService, SSOSettingService, SSOTestService, WebhookService, NotificationTemplateSampleService

## [0.7.1] - 2026-02-25

### Fixed
- Improve multi-device push subscriptions and service worker updates

## [0.7.0] - 2026-02-24

### Added
- Laravel Reverb WebSocket support for real-time broadcasting
- Multi-device push subscription management
- Passkey authentication improvements

### Fixed
- Exclude missing-user-entrypoint Semgrep rule variant

## [0.6.4] - 2026-02-24

### Fixed
- Migrated Semgrep from GitHub Action to direct CLI with rule exclusions for cleaner CI
- Fixed flaky GraphQL error test

## [0.6.3] - 2026-02-24

### Fixed
- Resolved Semgrep CI findings with nosemgrep suppressions for false positives
- Added shared security headers Nginx include file

## [0.6.2] - 2026-02-24

### Security
- SSRF protection hardening with DNS pinning to prevent DNS rebinding attacks
- Internal error details no longer leak to API responses
- Added security headers to Nginx configuration

## [0.6.1] - 2026-02-24

### Changed
- Migrated passkey authentication from custom PasskeyService to Laragear WebAuthn typed request classes
- Simplified PasskeyController register/login flows using built-in request methods
- Added Auth::logout() and session invalidation when disabled user attempts passkey login
- Updated User model and auth config for WebAuthn compatibility

## [0.6.0] - 2026-02-23

### Added
- GraphQL introspection enabled for development tooling
- Release test gates — push.ps1 now runs backend and frontend tests before releasing
- Passkeys code review task added to roadmap

### Fixed
- Registered Lighthouse service provider in bootstrap/providers.php for Laravel 11 GraphQL routes
- Removed incorrect @field directives from GraphQL schema to allow Lighthouse auto-discovery
- Fixed RefreshDatabase transaction isolation so test data is visible to GraphQL HTTP requests
- Added context-based user resolution for GraphQL resolvers with auth guard fallback
- Fixed DisableIntrospection to use int constants with explicit feature gate in tests

## [0.5.2] - 2026-02-22

### Fixed
- Corrected Lighthouse error handlers and Stripe webhook test expectations

## [0.5.1] - 2026-02-22

### Fixed
- Resolved CI test failures in Stripe webhook, GraphQL, and API key tests

## [0.5.0] - 2026-02-22

### Added
- Stripe Connect integration with 1% application fee, Connect onboarding, webhooks, payment history, and settings UI
- GraphQL API via Lighthouse with queries and mutations for notifications, profile, and settings
- Notification delivery tracking across all notification channels
- API key management — create, revoke, and manage API keys for programmatic access
- PWA improvements for enhanced progressive web app experience

## [0.4.0] - 2026-02-15

### Added
- Notification system overhaul with per-user settings, timezone support, and channel management
- Push subscription expiry detection that auto-removes stale subscriptions

### Changed
- Replaced hand-rolled RFC 8291 WebPush payload encryption with minishlink/web-push library
- Release tooling — push.ps1 now auto-detects branch, guards against detached HEAD, supports non-interactive mode

### Fixed
- Fixed TypeScript error in notifications page by properly casting Object.values() result
- Updated composer.lock to include minishlink/web-push dependencies

## [0.3.1] - 2026-02-15

### Added
- Service worker cache versioning — release pipeline auto-updates CACHE_VERSION in sw.js
- Service worker cleans up old versioned caches on activate
- Expanded add-searchable-model recipe with dedicated search methods, validation, and Scout config

## [0.3.0] - 2026-02-16

### Fixed
- SystemSetting model returned string "null" instead of PHP null when settings were cleared, causing broken images in branding after logo deletion
- Changelog page empty in Docker — CHANGELOG.md was not copied into Docker image or volume-mounted for development

### Changed
- SystemSetting value getter now uses json_last_error() instead of null-coalescing operator for correct null handling
- Frontend branding settings and app-config provider sanitize the string "null" as defense-in-depth

## [0.2.0] - 2026-02-15

### Added
- Integration Usage Dashboard with cost tracking across LLM, Email, SMS, Storage, and Broadcasting providers
- Usage stats API with date range, integration, and provider filters
- Stacked area chart for cost trends and sortable provider breakdown table
- Cost alert budgets with daily scheduled checks and admin notifications
- CSV export of filtered usage data
- Monthly cost dashboard widget with sparkline trend
- Per-user cost attribution for LLM and SMS integrations
- "Get Cooking" tiered setup wizard for new project customization
- Changelog page in Configuration area for viewing version history

### Changed
- Dark mode fixes across configuration pages for consistent theme adherence

### Fixed
- Fixed theme preference race condition — use localStorage as single source of truth instead of stale API values

## [0.1.26] - 2026-02-14

### Added
- Integration Usage Dashboard (Configuration > Usage & Costs) with cost tracking across LLM, Email, SMS, Storage, and Broadcasting
- Usage tracking instrumentation in LLM orchestrator, email/SMS channels, and storage service
- Usage stats API with date range, integration, and provider filters
- Stacked area chart for cost trends and sortable provider breakdown table
- Cost alert budgets with daily scheduled checks and admin notifications
- CSV export of filtered usage data
- Monthly cost dashboard widget with sparkline trend for admin dashboard
- Per-user cost attribution for LLM and SMS integrations
- "Get Cooking" tiered setup wizard for new project customization (3-tier guided flow)
- Changelog page in Configuration area for viewing version history
- Dark mode fixes across configuration pages for consistent theme adherence

## [0.1.25] - 2026-02-07

### Added
- Novu notification infrastructure integration (optional cloud/self-hosted)
- Local notification system remains as default fallback

### Changed
- Notification system refactored to support Novu as optional provider

## [0.1.24] - 2026-02-06

### Added
- PWA configuration navigation on mobile devices
- Faster sign-out flow with immediate UI feedback

### Fixed
- Mobile navigation in PWA standalone mode

## [0.1.23] - 2026-02-05

### Added
- In-app documentation and help center with searchable articles
- Setup wizard for first-time onboarding
- Security compliance documentation (SOC 2, ISO 27001 templates)
- GitHub Actions CI/CD hardening

### Fixed
- Docker build optimization and security updates
- Meilisearch production permission denied errors
- Cache permissions in container
- SSO test connection toggle state
- Page titles now use configured app name
- PWA service worker hardening and offline improvements

### Changed
- Documentation restructured for better developer experience
- Login flow reviewed and tested end-to-end
- Docker container security audit completed

## [0.1.22] - 2026-02-04

### Fixed
- Security page architecture cleanup

## [0.1.21] - 2026-02-02

### Added
- SAST (Static Application Security Testing) automation
- Security headers and CORS hardening

## [0.1.20] - 2026-01-31

### Added
- PWA offline experience with background sync
- PWA push notifications via Web Push (VAPID)
- PWA install experience with custom prompts
- Documentation audit across all docs (8 phases)

## [0.1.19] - 2026-01-30

### Added
- Meilisearch integration (embedded in container, full-text search)
- Meilisearch admin configuration page
- User groups with permission-based access control
- Configurable auth features (registration, email verification, password reset)
- Storage settings with multiple provider support (S3, GCS, Azure, DO Spaces, MinIO, B2)
- Storage analytics and monitoring
- SSO settings enhancement with per-provider configuration
- Dashboard static simplification

### Changed
- Admin status now determined by group membership (removed is_admin column)
- Migrated from Alpine to Debian for Meilisearch compatibility
- Notification templates implementation

## [0.1.18] - 2026-01-29

### Added
- Configuration navigation redesign with grouped collapsible sections
- Live console logs and HIPAA access logging
- Audit dashboard analytics with charts and statistics
- Real-time audit log streaming
- LLM model discovery (test key, fetch models per provider)
- User management admin interface
- Email template system with editor and preview
- Branded iconography across the application

### Changed
- LLM settings consolidated into single AI configuration page
- Collapsible settings UI pattern standardized

## [0.1.17] - 2026-01-28

### Added
- SSO settings migration to database (env to DB Phase 5)
- Notification and LLM settings migration (env to DB Phases 3-4)
- SettingService implementation with env fallback and encryption (Phases 1-2)
- Notification configuration split (global vs per-user)

## [0.1.16] - 2026-01-27

### Added
- Multi-channel notification system (email, SMS, push, in-app, chat)
- Mobile-responsive design across all pages
- shadcn/ui CLI migration for component management
- Branding and UI consistency improvements
- Settings page restructure
- Critical bug fixes

### Changed
- Navigation refactored for mobile-first approach
