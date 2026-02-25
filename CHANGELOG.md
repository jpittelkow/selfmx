# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



## [0.6.3] - 2026-02-24

### Fixed
- Resolve Semgrep CI findings with nosemgrep suppressions and shared security headers include
## [0.6.2] - 2026-02-24

### Changed
- Migrate passkey auth to Laragear WebAuthn request types

### Fixed
- Harden SSRF protection with DNS pinning, suppress internal error leaks, add nginx security headers

### Changed
- Release v0.6.1
- Release v0.6.0
## [0.3.0] - 2026-02-15

### Fixed
- SystemSetting model returned string "null" instead of PHP null when settings were cleared, causing broken images in branding after logo deletion
- Changelog page empty in Docker — CHANGELOG.md was not copied into Docker image or volume-mounted for development

### Changed
- SystemSetting value getter now uses json_last_error() instead of null-coalescing operator for correct null handling
- Frontend branding settings and app-config provider sanitize the string "null" as defense-in-depth

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
