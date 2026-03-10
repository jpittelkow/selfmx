# Anti-Patterns

What NOT to do when developing on Sourdough. Read only the files relevant to your task.

## Files

| Category | File | Topics |
|----------|------|--------|
| Backend | [backend.md](backend.md) | Business logic, user scoping, access logging, FormRequest, response format, last admin, pagination, password, indexes, migrations, interfaces |
| Frontend | [frontend.md](frontend.md) | Duplicate logic, groups fetch, utilities, error handling, loading, save button, API URLs, credentials, shadcn, TypeScript, help system |
| Forms | [forms.md](forms.md) | Required fields, onChange, setValue, shouldDirty, empty strings |
| Database | [database.md](database.md) | Raw SQL, N+1, SQLite |
| Architecture | [architecture.md](architecture.md) | Admin routes, secrets, settings models, SettingService, BackupService |
| Testing | [testing.md](testing.md) | Authentication, user isolation |
| Responsive | [responsive.md](responsive.md) | Desktop-first CSS, touch targets, fixed widths, tables, mobile hook, breakpoints, landscape, hover-only, inline utilities |
| Widgets | [widgets.md](widgets.md) | Loading/error states, permission checks, no user-configurable infrastructure |

## Quick Checklist

Before submitting code, verify:

- [ ] **No duplicated logic** - Searched for existing components/utilities first
- [ ] **Centralized utilities** - Common functions in `frontend/lib/utils.ts`, not inline
- [ ] **Shared components used** - Reusable functionality in `frontend/components/` or `frontend/lib/`
- [ ] Business logic is in Services, not Controllers
- [ ] All queries are user-scoped where appropriate
- [ ] FormRequest classes used for validation
- [ ] Response format is consistent (`data`, `message`, `meta`)
- [ ] Last-admin checks use `AdminAuthorizationTrait`; pagination uses `config('app.pagination.default')`
- [ ] User password: plaintext when User has `hashed` cast; no `Hash::make()`
- [ ] Foreign keys have indexes
- [ ] Frontend has loading and error states
- [ ] Using `api` utility, not raw fetch
- [ ] No hardcoded URLs or secrets
- [ ] Admin routes have proper middleware
- [ ] Tests verify user isolation
- [ ] Form fields optional by default (`.optional()`, `mode: "onBlur"`, `reset()`)
- [ ] Custom inputs use `setValue(..., { shouldDirty: true })`
- [ ] **Mobile-first CSS** - Base styles for mobile, breakpoints for larger
- [ ] **Touch targets** - All interactive elements minimum 44px
- [ ] **Tables** - Wrapped in `overflow-x-auto` or have card view alternative
- [ ] **Tested** - Verified at 320px, 375px, 768px, and 1024px+ widths
- [ ] **User deletion** - Uses `UserService::deleteUser()`, not `$user->delete()` directly
- [ ] **URL validation** - User-supplied URLs validated through `UrlValidationService` (SSRF protection)
- [ ] **No services in migrations** - Never use `app(Service::class)` in migrations
- [ ] **N+1 queries** - Eager-load relations with `->with()` where needed
- [ ] **Correlation IDs** - Uses `app('correlation_id')` for log correlation, not custom IDs
- [ ] **Avatars** - Uses shadcn `Avatar`/`AvatarFallback` + shared `getInitials()`, not raw `div` with `.charAt(0)`
- [ ] **Checkboxes** - Uses shadcn `Checkbox`, not native `<input type="checkbox">`
- [ ] **Buttons** - Uses shadcn `Button`, not `role="button"` divs
- [ ] **Loading spinners** - Uses `Loader2` or `SettingsPageSkeleton`, not custom `border-b-2` spinner divs
- [ ] **PWA safe areas** - Header has `pt-[env(safe-area-inset-top)]`; bottom elements have `pb-[env(safe-area-inset-bottom)]`
- [ ] **No hover-only actions** - Delete/action buttons always visible or have touch-accessible alternative
- [ ] **No animations via framer-motion** - Use `tailwindcss-animate` utilities (`animate-in`, `fade-in`, `slide-in-from-bottom-*`)
