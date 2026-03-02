# selfmx AI Development Instructions

This file provides GitHub Copilot with context about the selfmx codebase. For comprehensive documentation, see `docs/ai/README.md`.

## Getting Started

Before any implementation work:

1. Read `CLAUDE.md` -> `docs/overview.md` -> `docs/ai/README.md`
2. Check `docs/ai/context-loading.md` for which files to read per task type
3. Find applicable recipes in `docs/ai/recipes/` (47 step-by-step guides)
4. Review patterns in `docs/ai/patterns/README.md`

## Project Structure

```
backend/           Laravel 11 API (PHP 8.3+)
├── app/Services/  Business logic (NOT in controllers)
├── app/Http/Controllers/Api/  REST endpoints
├── config/        Laravel config files
└── routes/api.php Route definitions

frontend/          Next.js 14 (React 18, TypeScript)
├── app/(dashboard)/  Protected pages
├── components/    Reusable components (ui/ for shadcn)
└── lib/           Utilities (api.ts, auth.ts, utils.ts)

docs/ai/           AI development guide
├── README.md      Start here
├── context-loading.md  Files to read per task
├── recipes/       47 step-by-step guides
└── patterns/       Code patterns
```

## Critical Rules

### User Data Scoping

- Most tables have `user_id` column - always filter by `$request->user()->id`
- Admin routes require `auth:sanctum` and `admin` middleware
- Admin status is group-based: use `$user->isAdmin()` or `$user->inGroup('admin')`

### Service Layer Pattern

- Business logic goes in `backend/app/Services/`, NOT controllers
- Controllers only validate input and call services
- Example: `BackupService`, `NotificationOrchestrator`, `AuditService`

### Global Components (No Duplication)

- Never duplicate logic across pages
- Use shared components from `frontend/components/`
- Use utilities from `frontend/lib/` (api, auth, utils)
- If functionality exists, use it. If it should exist, create it globally.

### Settings Architecture

- Schema-backed settings use `SettingService` (not `SystemSetting::get` directly)
- Settings defined in `backend/config/settings-schema.php`
- User settings use `Setting` model; system settings use `SystemSetting` model

### Authentication

- Uses Laravel Sanctum session cookies (not Bearer tokens)
- Include `credentials: 'include'` in fetch calls
- User password uses `hashed` cast - pass plaintext, don't use `Hash::make()`

## Logging Requirements

### Audit Logging

Use `AuditService::log()` for user actions:
- Auth events (login, logout, password change)
- Settings changes
- Admin actions

### Application Logging

```php
Log::info('Operation completed', ['user_id' => $id, 'duration_ms' => $ms]);
Log::warning('Recoverable failure', ['provider' => $name]);
Log::error('Operation failed', ['error' => $message]);
```

### Frontend Error Logging

Use `errorLogger` from `frontend/lib/error-logger.ts`:
```typescript
import { errorLogger } from '@/lib/error-logger';
errorLogger.report(error, { context: 'ComponentName' });
// NOT console.error()
```

## Code Review Checklist

Before completing any task:

- [ ] No debug code (`dd()`, `dump()`, `console.log`, `debugger`)
- [ ] No hardcoded secrets (use `config()` and env)
- [ ] User scoping applied (queries filter by `user_id`)
- [ ] Admin routes protected (`auth:sanctum`, `admin` middleware)
- [ ] Logging added where appropriate
- [ ] Documentation updated if needed

## Docker Development

```bash
# Start development environment
docker-compose up -d

# Rebuild after Dockerfile changes
docker-compose up -d --build

# Run artisan commands
docker-compose exec app php /var/www/html/backend/artisan <command>

# View logs
docker-compose logs -f
```

Never use `docker run` directly - always use `docker-compose`.

## Common Gotchas

- **API prefix**: All backend routes are under `/api/`
- **SQLite default**: Dev uses SQLite but supports MySQL/PostgreSQL
- **shadcn/ui**: Components in `frontend/components/ui/` are CLI-managed
- **Form fields**: Config pages should make fields optional unless required

## Documentation References

| Topic | Location |
|-------|----------|
| AI Development Guide | `docs/ai/README.md` |
| Context Loading | `docs/ai/context-loading.md` |
| Code Patterns | `docs/ai/patterns/README.md` |
| Anti-Patterns | `docs/ai/anti-patterns/README.md` |
| Recipes (32) | `docs/ai/recipes/` |
| Architecture | `docs/architecture.md` |
| Features | `docs/features.md` |
| Quick Reference | `docs/quick-reference.md` |
