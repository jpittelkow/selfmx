# selfmx

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Quick Context (always loaded)

- **Stack**: Laravel 11 (PHP 8.3+) + Next.js 16 (React 18, TypeScript) + SQLite default
- **Architecture**: Single Docker container (Nginx + PHP-FPM + Next.js via Supervisor), API-first
- **Backend**: routes in `backend/routes/api.php`, controllers in `backend/app/Http/Controllers/Api/`, services in `backend/app/Services/`
- **Frontend**: pages in `frontend/app/(dashboard)/`, components in `frontend/components/`, utilities in `frontend/lib/`
- **Config pages**: `frontend/app/(dashboard)/configuration/`, nav in `configuration/layout.tsx` (`navigationGroups`)
- **Settings schema**: `backend/config/settings-schema.php` (SettingService for DB + env fallback)
- **Search registration**: dual — `backend/config/search-pages.php` + `frontend/lib/search-pages.ts`

## Development Commands

**PHP is not available locally** — run all backend commands via Docker (`selfmx-dev` container).

```bash
# Start/rebuild dev environment
docker-compose up -d
docker-compose up -d --build

# Backend tests (Pest) — all tests
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test"

# Backend — single test file
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test --filter=AuthTest"

# Backend — single test method
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test --filter='it can login with valid credentials'"

# Backend — Laravel commands
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan migrate"
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan route:list"

# Frontend tests (Vitest)
docker exec selfmx-dev bash -c "cd /var/www/html/frontend && npm test"

# Frontend lint
docker exec selfmx-dev bash -c "cd /var/www/html/frontend && npm run lint"

# Frontend build
docker exec selfmx-dev bash -c "cd /var/www/html/frontend && npm run build"

# E2E tests (Playwright)
docker exec selfmx-dev bash -c "cd /var/www/html/frontend && npm run test:e2e"

# Add shadcn component (from frontend/)
docker exec selfmx-dev bash -c "cd /var/www/html/frontend && npx shadcn@latest add <component>"

# Release (bumps version, runs tests, tags, pushes)
./scripts/push.ps1 patch "feat: description of changes"
```

## Task-Type File Lookup

| Task Type | Key Files & Docs |
|-----------|-----------------|
| **Full-Stack Feature** | [add-full-stack-feature recipe](docs/ai/recipes/add-full-stack-feature.md) |
| **Email Provider** | [ADR-031](docs/adr/031-email-provider-management-architecture.md), [email-provider pattern](docs/ai/patterns/email-provider.md), [add-email-provider recipe](docs/ai/recipes/add-email-provider.md) |
| Frontend UI | `frontend/app/(dashboard)/`, `frontend/components/`, `frontend/lib/api.ts` |
| Backend API | `backend/routes/api.php`, `backend/app/Http/Controllers/Api/` |
| Config Page | [add-config-page recipe](docs/ai/recipes/add-config-page.md), [add-configuration-menu-item recipe](docs/ai/recipes/add-configuration-menu-item.md) |
| Settings (SettingService) | [ADR-014](docs/adr/014-database-settings-env-fallback.md), `backend/app/Services/SettingService.php`, `backend/config/settings-schema.php` |
| Notifications | [ADR-005](docs/adr/005-notification-system-architecture.md), `backend/app/Services/Notifications/`, [trigger-notifications recipe](docs/ai/recipes/trigger-notifications.md) |
| LLM | [ADR-006](docs/adr/006-llm-orchestration-modes.md), `backend/app/Services/LLM/` |
| Auth | [ADR-002](docs/adr/002-authentication-architecture.md), `backend/app/Http/Controllers/Api/AuthController.php` |
| Backup | [ADR-007](docs/adr/007-backup-system-design.md), `backend/app/Services/Backup/BackupService.php` |
| Payments/Stripe | [ADR-026](docs/adr/026-stripe-connect-integration.md), `backend/app/Services/Stripe/`, [setup-stripe recipe](docs/ai/recipes/setup-stripe.md) |
| Search | `backend/app/Services/Search/SearchService.php`, `frontend/lib/search.ts` |
| Help/Docs | `frontend/lib/help/help-content.ts`, `frontend/components/help/` |
| Docker | [ADR-009](docs/adr/009-docker-single-container.md), `docker/Dockerfile`, `docker-compose.yml` |
| Testing | [ADR-008](docs/adr/008-testing-strategy.md), `e2e/`, `backend/tests/` |
| PWA | [PWA roadmap](docs/plans/pwa-roadmap.md), `frontend/public/sw.js` |
| Mobile/Responsive | [ADR-013](docs/adr/013-responsive-mobile-first-design.md), `frontend/lib/use-mobile.ts` |
| Branding | `frontend/config/app.ts`, `frontend/components/logo.tsx`, `frontend/lib/app-config.tsx` |
| Release/Deploy | [commit-and-release recipe](docs/ai/recipes/commit-and-release.md), `scripts/push.ps1`, `VERSION` |
| New Project Setup | Say **"Get cooking"** -- [setup-new-project recipe](docs/ai/recipes/setup-new-project.md) |

**For detailed file lists per task type:** [context-loading.md](docs/ai/context-loading.md)

## Gotchas

- **Bug tracking** - When you encounter something that looks like it could be a bigger bug (unexpected behavior, edge cases, error patterns), log it in [docs/plans/bug-tracker.md](docs/plans/bug-tracker.md). Always do this proactively — don't wait to be asked
- **Service layer** - Business logic in `Services/`, not controllers
- **User scoping** - Most tables have `user_id`. Always filter by `$request->user()->id`
- **User password** - User model uses `hashed` cast. Pass plaintext; never use `Hash::make()` in controllers
- **Admin is group-based** - Use `$user->isAdmin()` / `$user->inGroup('admin')` on backend; `isAdminUser(user)` from `frontend/lib/auth.ts` on frontend
- **Sanctum cookies** - Auth uses session cookies, not Bearer tokens. Include `credentials: 'include'` in fetch
- **SQLite default** - Test array/JSON columns carefully; code also supports MySQL/PostgreSQL
- **API prefix** - All backend routes under `/api/`. Frontend calls go through Nginx proxy
- **Settings models** - User settings use `Setting`; system settings use `SystemSetting`. For schema-backed settings, use **SettingService** (not `SystemSetting::get/set` directly)
- **shadcn/ui** - Components in `frontend/components/ui/` are CLI-managed. Use `npx shadcn@latest add <component>` from `frontend/`
- **Form fields optional by default** - Use `z.string().optional()`, `mode: "onBlur"`, `reset()` for initial values, `setValue(..., { shouldDirty: true })` for custom inputs
- **Mobile-first CSS** - Base styles for mobile, add `md:`, `lg:` for larger. Use `useIsMobile()` for conditional rendering
- **Global components** - Never duplicate logic across pages. Search `frontend/components/` and `frontend/lib/` first
- **Audit actions** - Use `AuditService` with `{resource}.{action}` naming (e.g. `user.created`)
- **Config nav registration** - New config pages need an entry in `configuration/layout.tsx` `navigationGroups`
- **Search dual registration** - New pages need entries in both `backend/config/search-pages.php` and `frontend/lib/search-pages.ts`

**Pre-submit checklist:** [docs/ai/anti-patterns/README.md](docs/ai/anti-patterns/README.md#quick-checklist)

## Deep-Dive Docs (read for complex tasks)

| Guide | When to Read |
|-------|-------------|
| [AI Development Guide](docs/ai/README.md) | Recipes index, workflow, planning requirements |
| [Context Loading](docs/ai/context-loading.md) | Detailed file lists per task type |
| [Patterns](docs/ai/patterns/README.md) | Code patterns with examples |
| [Anti-Patterns](docs/ai/anti-patterns/README.md) | Common mistakes and pre-submit checklist |
| [Quick Reference](docs/quick-reference.md) | Commands, structure, naming conventions |
| [Architecture ADRs](docs/architecture.md) | Design decisions |
| [Roadmaps](docs/roadmaps.md) | What's planned |

**Using as a Template**: See [FORK-ME.md](FORK-ME.md) for instructions on using selfmx as a base for your own project.
