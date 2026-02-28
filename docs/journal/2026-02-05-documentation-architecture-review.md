# Documentation Architecture Review

**Date:** 2026-02-05
**Type:** Documentation fix
**Roadmap:** [Documentation Architecture Review](../plans/documentation-architecture-review-roadmap.md)

## What Changed

Comprehensive documentation-only review and fixes across 4 phases, addressing cross-document inconsistencies, missing architectural explanations, developer experience gaps, and version accuracy.

### Phase 1: Cross-Document Consistency Fixes

- **Fixed request flow diagram** (`docs/ai/architecture-map.md`): Corrected to show Nginx as the single entry point (Browser -> Nginx -> Next.js or PHP-FPM), not Browser -> Next.js -> Nginx
- **Aligned LLM orchestration modes** (`docs/ai/architecture-map.md`): Removed incorrect `failover` and `consensus` modes; aligned to canonical ADR-006 names (Single, Aggregation, Council)
- **Fixed SSE vs WebSocket discrepancy** (`docs/adr/023-audit-logging-system.md`): Removed incorrect `/api/audit-logs/stream` SSE endpoint reference; documented that real-time streaming uses WebSocket via Laravel Echo/Reverb
- **Fixed settings flow ambiguity** (`docs/ai/architecture-map.md`): Changed ambiguous "system_settings table (or settings with user_id = null)" to definitive "system_settings table (global, no user_id)"
- **Updated backup format diagram** (`docs/adr/007-backup-system-design.md`): Changed `database.sql` to show actual format: `database.sqlite` (file copy) or `database.json` (JSON export with SHA-256 hash); updated database backup strategy table

### Phase 2: Architectural Clarity

- **Created settings decision flowchart** (`docs/ai/patterns/settings-overview.md`): New pattern file with decision tree for choosing between Setting model, SettingService, SystemSetting, config(), and .env
- **Documented permission model policy** (`docs/ai/patterns/permission-checking.md`): Added section explaining when to use `can:admin` vs granular permissions; File Manager documented as intentional `can:admin` usage
- **Documented ConfigServiceProvider degraded mode** (`docs/adr/014-database-settings-env-fallback.md`): Added section on what happens when database is unavailable, cache corruption recovery, and production safety
- **Documented storage system pattern deviation** (`docs/adr/022-storage-provider-system.md`): Explained why storage uses Laravel Filesystem abstraction instead of a custom interface, and how it compares to notification/LLM/backup patterns
- **Clarified webhook ownership** (`docs/features.md`): Documented that `user_id` tracks creator for audit, not access scoping; all admins see all webhooks
- **Clarified API token location** (`docs/ai/context-loading.md`): Fixed reference from profile page to Configuration > API page; documented mixed permissions (tokens for all users, webhooks admin-only)
- **Clarified Council mode status** (`docs/adr/006-llm-orchestration-modes.md`): Added implementation note that the full consensus engine is aspirational; current implementation uses primary-synthesizes approach
- **Documented cross-database backup restore** (`docs/backup.md`): Added compatibility matrix showing same-driver restore is supported; cross-driver restore is not

### Phase 3: Developer Experience Additions

- **Added resource requirements** (`docs/docker.md`): Table with minimum (1 GB RAM, 1 CPU) and recommended (2 GB+ RAM, 2+ cores) requirements; Meilisearch externalization guidance
- **Added SANCTUM_STATEFUL_DOMAINS** (`docs/development.md`): Added to required variables table with explanation of silent 401 failure mode
- **Documented dual page registration** (`docs/ai/recipes/add-config-page.md`, `docs/ai/recipes/add-searchable-page.md`): Both backend `search-pages.php` and frontend search config must be updated; sync risk documented
- **Added missing cross-reference links**: Security patterns link in features.md Security section; ADR-024 entry in architecture.md; rate.sensitive middleware documented in features.md
- **Added roadmap to pre-release checklist** (`docs/roadmaps.md`)

### Phase 4: Version and Accuracy Nits

- **Updated Next.js version** from 14 to 16 in overview.md, quick-reference.md, architecture.md, dev/README.md (confirmed from `package.json`: `^16.1.6`)
- **Documented rate.sensitive middleware** (`docs/ai/patterns/security.md`): Added new section with usage examples, registration location, and when to use
- **Fixed audit log retention default** (`docs/adr/023-audit-logging-system.md`): Changed from 90 days to 365 days to match actual code in `settings-schema.php` and `logging.php`

## Files Changed

| File | Change Type |
|------|-------------|
| `docs/plans/documentation-architecture-review-roadmap.md` | Created |
| `docs/ai/patterns/settings-overview.md` | Created |
| `docs/ai/architecture-map.md` | Fixed request flow, LLM modes, settings flow |
| `docs/adr/023-audit-logging-system.md` | Fixed SSE/WebSocket, retention default |
| `docs/adr/007-backup-system-design.md` | Fixed backup format, database strategy table |
| `docs/adr/014-database-settings-env-fallback.md` | Added degraded mode section |
| `docs/adr/022-storage-provider-system.md` | Added pattern deviation note |
| `docs/adr/006-llm-orchestration-modes.md` | Clarified council mode status |
| `docs/features.md` | Webhook ownership, API page permissions, security links, rate limiting |
| `docs/ai/context-loading.md` | Fixed API token page reference |
| `docs/ai/patterns/permission-checking.md` | Added permission policy section |
| `docs/ai/patterns/security.md` | Added rate.sensitive middleware docs |
| `docs/ai/patterns/README.md` | Added settings-overview entry |
| `docs/backup.md` | Added cross-database restore compatibility |
| `docs/docker.md` | Added resource requirements |
| `docs/development.md` | Added SANCTUM_STATEFUL_DOMAINS |
| `docs/ai/recipes/add-config-page.md` | Added dual page registration step |
| `docs/ai/recipes/add-searchable-page.md` | Added dual registration note |
| `docs/roadmaps.md` | Added documentation review to pre-release checklist |
| `docs/overview.md` | Updated Next.js version |
| `docs/quick-reference.md` | Updated Next.js version |
| `docs/architecture.md` | Updated Next.js version, added ADR-024 entry |
| `docs/dev/README.md` | Updated Next.js version |

## Why

Documentation-only architecture review revealed cross-document inconsistencies, missing explanations, and stale version references. These could mislead both human developers and AI assistants working with the codebase. All fixes improve accuracy without changing any application code.
