# Recipe: Add Full-Stack Feature

Composite recipe for adding a complete new feature spanning backend API, frontend UI, and configuration. References individual recipes for details.

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| **Backend** | | |
| `backend/database/migrations/{timestamp}_create_{table}_table.php` | Create | Database table |
| `backend/app/Models/{Name}.php` | Create | Eloquent model |
| `backend/app/Services/{Name}Service.php` | Create | Business logic (if non-trivial) |
| `backend/app/Http/Controllers/Api/{Name}Controller.php` | Create | API controller |
| `backend/app/Http/Requests/Store{Name}Request.php` | Create | Validation (POST/PUT) |
| `backend/app/Http/Resources/{Name}Resource.php` | Create | Response formatting (optional) |
| `backend/routes/api.php` | Modify | Add routes |
| **Frontend** | | |
| `frontend/app/(dashboard)/configuration/{name}/page.tsx` | Create | Config/management page |
| `frontend/app/(dashboard)/configuration/layout.tsx` | Modify | Add nav item to `navigationGroups` |
| `frontend/components/{name}/` | Create | Feature-specific components (if needed) |
| **Search & Help** | | |
| `backend/config/search-pages.php` | Modify | Search registration (backend) |
| `frontend/lib/search-pages.ts` | Modify | Search registration (frontend) |
| `frontend/lib/help/help-content.ts` | Modify | Help article (optional) |

## Workflow

### 1. Plan

- Check [roadmaps](../../roadmaps.md) for existing plans
- Check [architecture ADRs](../../architecture.md) for relevant decisions
- Identify which recipes below apply
- Read reference implementations for similar features

### 2. Backend First

1. **Migration + Model** - Create table with `user_id` FK and index. See [add-api-endpoint recipe](add-api-endpoint.md) for skeleton.
2. **Service** - Put business logic in `backend/app/Services/{Name}Service.php`. Controllers validate and route only.
3. **Controller + Routes** - Follow [add-api-endpoint recipe](add-api-endpoint.md). Use `ApiResponseTrait`, user scoping, `config('app.pagination.default')`.
4. **Run migration** - `docker-compose exec app php /var/www/html/backend/artisan migrate`

### 3. Frontend

1. **Page** - Follow [add-config-page recipe](add-config-page.md) for form pages, or copy patterns from existing list/CRUD pages.
2. **Nav item** - Add to `configuration/layout.tsx` `navigationGroups`. See [add-configuration-menu-item recipe](add-configuration-menu-item.md).
3. **Components** - Reuse from `frontend/components/`. Search before creating new ones.

### 4. Polish

1. **Search** - Register in both `search-pages.php` and `frontend/lib/search-pages.ts`. See [add-searchable-page recipe](add-searchable-page.md).
2. **Help** - Add article to `frontend/lib/help/help-content.ts`. See [add-help-article recipe](add-help-article.md).
3. **Audit** - Add audit logging for important actions. See [trigger-audit-logging recipe](trigger-audit-logging.md).
4. **Permissions** - If admin-only, use `can:admin` middleware. For granular permissions, see [add-new-permission recipe](add-new-permission.md).

## Individual Recipes Referenced

| Step | Recipe |
|------|--------|
| API endpoint | [add-api-endpoint.md](add-api-endpoint.md) |
| Config page | [add-config-page.md](add-config-page.md) |
| Nav item | [add-configuration-menu-item.md](add-configuration-menu-item.md) |
| Searchable page | [add-searchable-page.md](add-searchable-page.md) |
| Help article | [add-help-article.md](add-help-article.md) |
| Audit logging | [trigger-audit-logging.md](trigger-audit-logging.md) |
| Permissions | [add-new-permission.md](add-new-permission.md) |
| Tests | [add-tests.md](add-tests.md) |

## Checklist

- [ ] Migration created and run
- [ ] Model with `user_id`, fillable, casts
- [ ] Service for business logic
- [ ] Controller with `ApiResponseTrait`, user scoping, pagination
- [ ] FormRequest for validation
- [ ] Routes in `api.php` with `auth:sanctum` middleware
- [ ] Frontend page with loading/error states
- [ ] Nav item in `configuration/layout.tsx`
- [ ] Search registered (both backend and frontend)
- [ ] Help article added (optional)
- [ ] Audit logging for important actions (optional)
- [ ] Mobile-responsive (tested at 320px, 375px, 768px, 1024px+)
