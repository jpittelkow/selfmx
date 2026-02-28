# Roadmap Archive

Completed roadmaps and implementation history. See [roadmaps.md](roadmaps.md) for active work.

## Completed (Core Done)

High-priority work complete. Only optional/lower-priority items remain.

| Roadmap | Completed | Remaining Work |
|---------|-----------|----------------|
| Passkeys Code Review | 2026-02-24 | None — review complete. Added 15 backend tests, delete confirmation, rename UI, error handling refactor, input trimming, ARIA attributes, ADR-018 documentation. |
| [Notification System Review](plans/notification-system-review.md) | 2026-02-22 | Remaining: Phase 9 — 48 code review fixes (7 high security/data integrity, 17 medium correctness, 21 low polish) |
| Integration Usage Dashboard | 2026-02-14 | Optional: additional LLM pricing presets, broadcasting instrumentation via event listener |
| [Changelog Page](plans/changelog-roadmap.md) | 2026-02-14 | Optional: Phase 4 (auto-generate from GitHub releases, "What's New" modal) |
| UI Issues: Toggles & Theme Adherence | 2026-02-14 | Manual testing recommended during build verification |
| [In-App Documentation & Onboarding](plans/in-app-documentation-roadmap.md) | 2026-02-05 | Optional: additional help articles, advanced onboarding flows |
| [Progressive Web App (PWA)](plans/pwa-roadmap.md) | 2026-02-27 | Optional: periodic sync, protocol handlers, rich notifications. Phase 6 (Mobile Native Push Notifications) complete — all investigations done, tested on desktop/Android/iOS. |
| [Storage Settings Enhancement](plans/storage-settings-roadmap.md) | 2026-01-31 | Optional: usage-over-time chart, orphaned/duplicate file detection |
| [Web Push Notifications](plans/web-push-notifications-roadmap.md) | 2026-01-31 | Merged into PWA roadmap; core complete |
| [Auth UI Redesign](plans/auth-ui-redesign-roadmap.md) | 2026-01-29 | Optional: illustrations, page transitions |
| [Logging](plans/logging-roadmap.md) | 2026-01-29 | Optional: archival, aggregation, scheduled export |
| [Audit Logs & Logging](plans/audit-logs-roadmap.md) | 2026-01-29 | Optional: external storage, aggregation |
| [LLM Model Discovery](plans/llm-model-discovery-roadmap.md) | 2026-01-29 | Optional: troubleshooting E2E, additional regions for Bedrock |
| [Notifications](plans/notifications-roadmap.md) | 2026-01-27 | Optional: user docs |
| Notification Refactor to Novu | 2026-02-07 | Optional Novu (Cloud/self-hosted); local system remains fallback. [ADR-025](adr/025-novu-notification-integration.md), [configure-novu](ai/recipes/configure-novu.md) |
| [Versioning System](plans/versioning-system-roadmap.md) | 2026-01-30 | Optional: Phase 4 (version check, update notification) |
| [Mobile Responsiveness](plans/mobile-responsive-roadmap.md) | 2026-01-27 | Optional: QA/testing items |
| [SSO Settings Enhancement](plans/sso-settings-enhancement-roadmap.md) | 2026-01-30 | Optional: Phase 4 branded logos, Phase 9 screenshots |
| [Admin Features](plans/admin-features-roadmap.md) | 2026-01-30 | Optional: Per-type notification templates, notification digest settings |

## Completed (Fully Done)

All tasks complete.

| Roadmap | Completed |
|---------|-----------|
| [Database Tables Audit](plans/database-tables-audit-roadmap.md) | 2026-02-28 |
| Mobile Header Styling Fixes | 2026-02-24 |
| [GraphQL API with User API Keys](plans/graphql-api-roadmap.md) | 2026-02-22 |
| Stripe Connect Fork Experience | 2026-02-22 |
| Login Page Cleanup | 2026-02-22 |
| [Stripe Connect Bug Fixes](plans/stripe-bug-fixes-roadmap.md) | 2026-02-22 |
| Logo Update | 2026-02-21 |
| [Stripe Connect Integration](plans/stripe-connect-roadmap.md) | 2026-02-21 |
| Repository Ownership Transfer (jpittelkow -> Sourdough-start) | 2026-02-21 |
| [Documentation Audit](plans/documentation-audit-roadmap.md) | 2026-01-31 |
| [Configurable Auth Features](plans/configurable-auth-features-roadmap.md) | 2026-01-30 |
| [Dashboard Improvements](plans/dashboard-improvements-roadmap.md) | 2026-01-30 |
| [User Groups](plans/user-groups-roadmap.md) | 2026-01-30 |
| [Meilisearch Integration](plans/meilisearch-integration-roadmap.md) | 2026-01-30 |
| [Meilisearch Configuration](plans/meilisearch-configuration-roadmap.md) | 2026-01-30 |
| [Integration Settings](plans/integration-settings-roadmap.md) | 2026-01-29 |
| [Email Configuration Dependencies](plans/email-configuration-dependencies-roadmap.md) | 2026-01-29 |
| [Env to Database Migration](plans/env-to-database-roadmap.md) | 2026-01-29 |
| [Global Components Audit](plans/global-components-audit-roadmap.md) | 2026-01-28 |
| [Branding & UI Consistency](plans/branding-ui-consistency-roadmap.md) | 2026-01-27 |
| [Settings Restructure](plans/settings-restructure-roadmap.md) | 2026-01-27 |
| [Critical Fixes](plans/critical-fixes-roadmap.md) | 2026-01-27 |
| [shadcn/ui CLI Setup](plans/shadcn-cli-setup-roadmap.md) | 2026-01-27 |
| [Configuration Navigation Redesign](plans/config-navigation-redesign-roadmap.md) | 2026-01-29 |
| [Collapsible Settings UI](plans/collapsible-settings-ui-roadmap.md) | 2026-01-29 |
| [Branded Iconography](plans/branded-iconography-roadmap.md) | 2026-01-29 |
| [Docker Container Audit](plans/docker-audit-roadmap.md) | 2026-02-05 |
| [Security Compliance Review](plans/security-compliance-roadmap.md) | 2026-02-05 |
| Page Title Fixing | 2026-02-04 |
| PWA Review | 2026-02-05 |
| PWA Hardening | 2026-02-05 |
| Login Testing & Review | 2026-02-05 |
| GitHub Actions Hardening | 2026-02-05 |
| Documentation Restructure | 2026-02-05 |
| PWA: Configuration navigation on mobile | 2026-02-06 |
| Faster Sign Out | 2026-02-06 |

## Journal Entries

Implementation history and development notes in `journal/`:

| Date | Entry |
|------|-------|
| 2026-02-14 | [Documentation & Architecture Review](journal/2026-02-14-documentation-architecture-review.md) |
| 2026-02-14 | [Integration Usage Dashboard](journal/2026-02-14-integration-usage-dashboard.md) |
| 2026-02-14 | [Changelog Page & Theme Adherence Fixes](journal/2026-02-14-changelog-and-theme-fixes.md) |
| 2026-02-06 | [Faster Sign Out](journal/2026-02-06-faster-sign-out.md) |
| 2026-02-05 | [Documentation Restructure](journal/2026-02-05-documentation-restructure.md) |
| 2026-02-05 | [Frontend Code Review](journal/2026-02-05-frontend-code-review.md) |
| 2026-02-05 | [Code Review Phase 2: Backend Architecture, Database, Response Format](journal/2026-02-05-code-review-phase-2.md) |
| 2026-02-05 | [In-App Documentation Completion](journal/2026-02-05-in-app-docs-completion.md) |
| 2026-02-05 | [Wizard and Help Center Styling Fixes](journal/2026-02-05-wizard-help-center-styling-fixes.md) |
| 2026-02-05 | [Meilisearch Production Permission Denied Fix](journal/2026-02-05-meilisearch-production-permissions.md) |
| 2026-02-05 | [Cache Permissions Fix](journal/2026-02-05-cache-permissions-fix.md) |
| 2026-02-05 | [SSO Test Connection Toggle Fix](journal/2026-02-05-sso-test-toggle-fix.md) |
| 2026-02-05 | [Phase 1: Security and Authentication Code Review](journal/2026-02-05-phase-1-security-review.md) |
| 2026-02-05 | [Page Titles App Name Fix](journal/2026-02-05-page-titles-app-name-fix.md) |
| 2026-02-05 | [Docker Build Optimization & Security Updates](journal/2026-02-05-docker-optimization-and-security-updates.md) |
| 2026-02-05 | [In-App Documentation & Onboarding (Phases 1-3)](journal/2026-02-05-in-app-documentation-phases-1-3.md) |
| 2026-02-05 | [Security Compliance Documentation Completion](journal/2026-02-05-security-compliance-documentation-completion.md) |
| 2026-02-05 | [Compliance Templates](journal/2026-02-05-compliance-templates.md) |
| 2026-02-05 | [Docker Container Audit](journal/2026-02-05-docker-container-audit.md) |
| 2026-02-05 | [Login Testing & Review](journal/2026-02-05-login-testing-review.md) |
| 2026-02-05 | [PWA Hardening](journal/2026-02-05-pwa-hardening.md) |
| 2026-02-05 | [PWA Review and Code Audit](journal/2026-02-05-pwa-review.md) |
| 2026-02-05 | [GitHub Actions Hardening](journal/2026-02-05-github-actions-hardening.md) |
| 2026-02-05 | [Migration Service Container Fix](journal/2026-02-05-migration-service-container-fix.md) |
| 2026-02-04 | [Security Page Architecture Cleanup](journal/2026-02-04-security-page-cleanup.md) |
| 2026-02-02 | [Security SAST Automation](journal/2026-02-02-security-sast-automation.md) |
| 2026-02-02 | [Security Review Phase 1: Security Headers & CORS Hardening](journal/2026-02-02-security-review-phase-1.md) |
| 2026-01-31 | [Documentation Audit Phase 8: Cross-Reference & Completeness](journal/2026-01-31-documentation-audit-phase-8.md) |
| 2026-01-31 | [Documentation Audit Phase 4: ADR & Architecture](journal/2026-01-31-documentation-audit-phase-4-adr.md) |
| 2026-01-31 | [Documentation Audit Phase 3: Patterns & Anti-Patterns](journal/2026-01-31-documentation-audit-phase-3.md) |
| 2026-01-31 | [Documentation Audit Phase 2: AI Recipes](journal/2026-01-31-documentation-audit-phase-2.md) |
| 2026-01-31 | [Documentation Audit Phase 1: Cursor Rules](journal/2026-01-31-documentation-audit-phase-1.md) |
| 2026-01-31 | [PWA Phase 4 and 5: Install Experience and Advanced Features](journal/2026-01-31-pwa-phase-4-5-install-and-advanced.md) |
| 2026-01-31 | [PWA Phase 3: Offline Experience](journal/2026-01-31-pwa-phase-3-offline-experience.md) |
| 2026-01-31 | [PWA Phase 2: Push Notifications](journal/2026-01-31-pwa-push-notifications.md) |
| 2026-01-30 | [Notification Templates Implementation](journal/2026-01-30-notification-templates.md) |
| 2026-01-30 | [Meilisearch Embedded in Container](journal/2026-01-30-meilisearch-embedded.md) |
| 2026-01-30 | [Remove is_admin, Admin Group Only](journal/2026-01-30-remove-is-admin-group-only.md) |
| 2026-01-30 | [Meilisearch Configuration](journal/2026-01-30-meilisearch-configuration.md) |
| 2026-01-30 | [Dashboard Static Simplification](journal/2026-01-30-dashboard-static-simplification.md) |
| 2026-01-30 | [Alpine to Debian Migration for Meilisearch](journal/2026-01-30-alpine-to-debian-meilisearch.md) |
| 2026-01-30 | [User Groups Phase 4: Admin UI](journal/2026-01-30-user-groups-phase-4-admin-ui.md) |
| 2026-01-30 | [Search documentation update](journal/2026-01-30-search-documentation-update.md) |
| 2026-01-30 | [Meilisearch Integration (Phases 4–6)](journal/2026-01-30-meilisearch-phases-4-6.md) |
| 2026-01-30 | [Meilisearch Integration (Phases 1–3)](journal/2026-01-30-meilisearch-phases-1-3.md) |
| 2026-01-30 | [Configurable Auth Features](journal/2026-01-30-configurable-auth-features.md) |
| 2026-01-30 | [Storage Phase 4: Analytics & Monitoring](journal/2026-01-30-storage-phase-4-analytics.md) |
| 2026-01-30 | [Storage Settings Phase 2 (Additional Providers)](journal/2026-01-30-storage-providers-phase-2.md) |
| 2026-01-30 | [Storage Settings Phase 1 (Local Storage Transparency)](journal/2026-01-30-storage-settings-phase-1.md) |
| 2026-01-30 | [SSO Settings Enhancement](journal/2026-01-30-sso-settings-enhancement.md) |
| 2026-01-29 | [Configuration Navigation Redesign](journal/2026-01-29-config-nav-redesign.md) |
| 2026-01-29 | [Scheduled Tasks/Jobs UI (Run Now & History)](journal/2026-01-29-scheduled-jobs-ui.md) |
| 2026-01-29 | [Live Console Logs & HIPAA Access Logging](journal/2026-01-29-live-logs-hipaa-logging.md) |
| 2026-01-29 | [Access Logs Field Tracking](journal/2026-01-29-access-logs-field-tracking.md) |
| 2026-01-29 | [HIPAA Access Logging Toggle](journal/2026-01-29-hipaa-logging-toggle.md) |
| 2026-01-29 | [Console and Application Logging](journal/2026-01-29-console-app-logging.md) |
| 2026-01-29 | [Audit Dashboard Analytics (Phase 2)](journal/2026-01-29-audit-dashboard-analytics.md) |
| 2026-01-29 | [Audit Extended Features (Real-time Streaming & Structured Logging)](journal/2026-01-29-audit-extended-features.md) |
| 2026-01-29 | [Audit Logging Implementation](journal/2026-01-29-audit-logging-implementation.md) |
| 2026-01-29 | [LLM Settings Page Consolidation](journal/2026-01-29-llm-settings-consolidation.md) |
| 2026-01-29 | [LLM Model Discovery](journal/2026-01-29-llm-model-discovery.md) |
| 2026-01-29 | [User Management Admin (HIGH Priority)](journal/2026-01-29-user-management-admin.md) |
| 2026-01-29 | [Email Template Integration (Chunk D)](journal/2026-01-29-email-template-integration-chunk-d.md) |
| 2026-01-29 | [Email Template Infrastructure (Chunk B)](journal/2026-01-29-email-template-infrastructure.md) |
| 2026-01-29 | [Backup Settings Migration (Env to DB Phase 6)](journal/2026-01-29-backup-settings-migration.md) |
| 2026-01-28 | [SSO Settings Migration (Env to DB Phase 5)](journal/2026-01-28-sso-settings-migration.md) |
| 2026-01-28 | [Notification & LLM Settings Migration (Env to DB Phase 3–4)](journal/2026-01-28-notification-llm-settings-migration.md) |
| 2026-01-28 | [SettingService Implementation (Env to DB Phase 1–2)](journal/2026-01-28-setting-service-implementation.md) |
| 2026-01-28 | [Notification Config Split (Global vs Per-User)](journal/2026-01-28-notification-config-split.md) |
| 2026-01-27 | [Notifications Implementation](journal/2026-01-27-notifications-implementation.md) |
| 2026-01-27 | [Mobile Responsiveness Implementation](journal/2026-01-27-mobile-responsiveness-implementation.md) |
| 2026-01-27 | [shadcn/ui CLI Migration](journal/2026-01-27-shadcn-cli-migration.md) |
| 2026-01-26 | [AI Documentation Optimization](journal/2026-01-26-ai-documentation-optimization.md) |
| 2026-01-26 | [Docker Next.js Volume Fix](journal/2026-01-26-docker-nextjs-volume-fix.md) |
| 2026-01-26 | [Navigation Refactor](journal/2026-01-26-navigation-refactor.md) |
| 2026-01-26 | [Section 2 Settings Implementation](journal/2026-01-26-section-2-settings-implementation.md) |
| 2026-01-26 | [Documentation Restructure](journal/2026-01-26-documentation-restructure.md) |
