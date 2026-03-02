# Recipe: Code Review

Step-by-step guide to review code for production readiness, consistency with project patterns, and adherence to ADRs.

## When to Review

- After implementing features
- Before commits
- After refactoring
- When explicitly requested (e.g. code-reviewer agent)

## Quick Checks (Must Pass)

Critical issues that must be fixed before merge:

- [ ] **No debug code** – See [Debug Code Detection](#debug-code-detection) below
- [ ] **No hardcoded secrets or credentials** – Use `config()` and env
- [ ] **User scoping** – Queries that return user data filter by `user_id` (or equivalent)
- [ ] **Admin routes protected** – Admin endpoints use `auth:sanctum` and `admin` middleware

## Backend Review Checklist (Laravel)

### Architecture

- [ ] Business logic is in **Services**, not in controllers
- [ ] **FormRequest** classes used for validation (no inline `$request->validate()`)
- [ ] Schema-backed settings use **SettingService** (not raw `SystemSetting::get/set` for mail etc.)
- [ ] Last-admin checks use **AdminAuthorizationTrait** (not manual checks)
- [ ] Providers/channels implement the required **interfaces**

See: [patterns](../patterns/README.md), [anti-patterns](../anti-patterns/README.md)

### Security

- [ ] All user-scoped queries include `user_id` (or equivalent) filter
- [ ] Admin routes have `auth:sanctum` and `admin` middleware
- [ ] No secrets in code; use `config()` and `.env`
- [ ] User password: pass plaintext when User has `hashed` cast; no `Hash::make()` in controllers

### Database

- [ ] Foreign keys have indexes (e.g. `$table->index('user_id')`)
- [ ] Migrations are reversible (`down()` implemented)
- [ ] No N+1 queries; use `with()` for relations
- [ ] Pagination uses `config('app.pagination.default')` (or `config('app.pagination.audit_log')` where applicable)

### Response Format

- [ ] JSON uses consistent structure: `data`, `message`, `meta` where applicable
- [ ] HTTP status codes: 201 for create, 200 for delete (with message body; use `deleteResponse()`), 4xx/5xx for errors

## Frontend Review Checklist (React/Next.js)

### Global Components

- [ ] No duplicated logic across pages; search codebase before adding new logic
- [ ] Reusable UI from `frontend/components/` (e.g. Logo, SaveButton, SettingsPageSkeleton)
- [ ] Utilities in `frontend/lib/` (api, auth, utils)

See: [Global Components Pattern](../patterns/components.md)

### State and Forms

- [ ] Loading states present (e.g. `SettingsPageSkeleton`, Loader2)
- [ ] Error handling with try/catch and user feedback (toast)
- [ ] Form uses `mode: "onBlur"` (not `onChange`)
- [ ] Initial data set with `reset()`, not `setValue()`
- [ ] Custom inputs use `setValue(..., { shouldDirty: true })`
- [ ] **SaveButton** component used for save actions (not inline button logic)

### API and Auth

- [ ] Using `api` utility from `@/lib/api` (not raw fetch where avoidable)
- [ ] No hardcoded API URLs
- [ ] If using fetch directly, `credentials: 'include'` is set (or use api utility)
- [ ] Admin checks use `isAdminUser(user)` from `@/lib/auth` (not `user?.is_admin`) so UI stays correct if API changes

### Mobile and Responsive (ADR-013)

- [ ] **Mobile-first CSS** – Base styles for mobile; `md:`, `lg:` for larger screens
- [ ] **Touch targets** – Minimum 44px for interactive elements
- [ ] **Tables** – Wrapped in `overflow-x-auto` or have card alternative
- [ ] Conditional layout uses `useIsMobile()` hook (not hardcoded breakpoints)

See: [ADR-013: Responsive Mobile-First Design](../../adr/013-responsive-mobile-first-design.md)

### TypeScript

- [ ] Props have interfaces (no untyped props)
- [ ] No `any` without justification

## Deprecated Patterns to Flag

If any of these appear, recommend the preferred approach:

| Pattern | Prefer |
|--------|--------|
| `response()->json(...)` with manual format | ApiResponseTrait methods (`successResponse`, `createdResponse`, `deleteResponse`, `dataResponse`, `errorResponse`) |
| `SystemSetting::get/set` for mail (or other schema-backed group) | SettingService |
| Inline `$request->validate()` in controller | FormRequest class |
| Manual "last admin" check | AdminAuthorizationTrait |
| `user?.is_admin` for admin UI | `isAdminUser(user)` from `@/lib/auth` |
| `setValue()` for form initial load | `reset()` |
| `mode: "onChange"` in useForm | `mode: "onBlur"` |
| Hardcoded pagination (e.g. `15`, `20`) | `config('app.pagination.default')` |
| Desktop-first CSS (e.g. base = desktop, `md:` = mobile) | Mobile-first (base = mobile, `md:` = larger) |
| `catch (error: any)` in frontend | `catch (error: unknown)` and `getErrorMessage(error, fallback)` from `@/lib/utils` |

## Debug Code Detection

Search for and remove (or justify) before merge:

**PHP/Laravel:**

- `dd()`, `dump()`, `var_dump()`, `print_r()`
- `Log::debug()`, `\Log::debug()`
- `ray()` (if using Ray)
- Large commented-out code blocks

**JavaScript/TypeScript:**

- `console.log()`, `console.debug()`, `console.info()`
- `console.warn()`, `console.error()` – review; keep only if intentional (e.g. error boundaries)
- `debugger;`
- Large commented-out code blocks

## Final Checklist

Use this combined checklist for a full pass. For detailed examples, see [anti-patterns – Summary Checklist](../anti-patterns/README.md#summary-checklist).

- [ ] **No duplicated logic** – Existing components/utilities used where applicable
- [ ] **Shared components** – New reusable code in `frontend/components/` or `frontend/lib/`
- [ ] Business logic in Services, not Controllers
- [ ] Queries user-scoped where appropriate
- [ ] FormRequest used for validation
- [ ] Response format consistent (`data`, `message`, `meta`)
- [ ] Last-admin checks use AdminAuthorizationTrait; pagination uses config
- [ ] User password: plaintext with `hashed` cast; no `Hash::make()` in controllers
- [ ] Foreign keys have indexes
- [ ] Frontend has loading and error states
- [ ] Using `api` utility, not raw fetch (unless justified)
- [ ] No hardcoded URLs or secrets
- [ ] Admin routes have proper middleware
- [ ] Tests verify user isolation where relevant
- [ ] Form fields optional by default; `mode: "onBlur"`; `reset()` for initial data
- [ ] Custom inputs use `setValue(..., { shouldDirty: true })`
- [ ] Mobile-first CSS; touch targets ≥ 44px; tables in `overflow-x-auto`
- [ ] No debug code left in changes
- [ ] No deprecated patterns (see [Deprecated Patterns to Flag](#deprecated-patterns-to-flag))
- [ ] **Logging** – Services use `Log::` with structured context; frontend uses `errorLogger` (not `console.error`/`console.warn`). See [Logging Guide](../../logging.md).

## References

- [patterns](../patterns/README.md) – Correct implementations
- [anti-patterns](../anti-patterns/README.md) – What to flag and avoid
- [Global Components Pattern](../patterns/components.md) – Component rules
- [architecture.md](../../architecture.md) – ADRs and key files
- [Logging Guide](../../logging.md) – Logging checklist (access, application, audit, frontend)
