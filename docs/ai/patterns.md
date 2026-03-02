# Code Patterns

Follow these patterns for consistency across the codebase.

## Backend Patterns

### Controller Pattern

Controllers use `ApiResponseTrait` for consistent JSON responses. See [ApiResponseTrait](#apiresponsetrait) for available methods.

```php
<?php
// backend/app/Http/Controllers/Api/ExampleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExampleRequest;
use App\Http\Requests\UpdateExampleRequest;
use App\Http\Resources\ExampleResource;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Example;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ExampleController extends Controller
{
    use ApiResponseTrait;

    /**
     * List all examples for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $examples = Example::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(config('app.pagination.default'));

        return $this->dataResponse([
            'data' => ExampleResource::collection($examples),
            'meta' => [
                'current_page' => $examples->currentPage(),
                'last_page' => $examples->lastPage(),
                'total' => $examples->total(),
            ],
        ]);
    }

    /**
     * Store a new example.
     */
    public function store(StoreExampleRequest $request): JsonResponse
    {
        $example = Example::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return $this->createdResponse('Example created successfully.', [
            'data' => new ExampleResource($example),
        ]);
    }

    /**
     * Show a single example.
     */
    public function show(Request $request, Example $example): JsonResponse
    {
        // Policy check (if using policies)
        $this->authorize('view', $example);

        return $this->dataResponse([
            'data' => new ExampleResource($example),
        ]);
    }

    /**
     * Update an example.
     */
    public function update(UpdateExampleRequest $request, Example $example): JsonResponse
    {
        $this->authorize('update', $example);

        $example->update($request->validated());

        return $this->successResponse('Example updated successfully.', [
            'data' => new ExampleResource($example),
        ]);
    }

    /**
     * Delete an example.
     */
    public function destroy(Request $request, Example $example): JsonResponse
    {
        $this->authorize('delete', $example);

        $example->delete();

        return $this->successResponse('Example deleted successfully.');
    }
}
```

### User Disable Pattern

When supporting disable/enable for user accounts, use a nullable `disabled_at` timestamp and block disabled users at login:

- **Model**: Add `disabled_at` to `$fillable` and `$casts` (datetime). Add `isDisabled(): bool` helper that returns `$this->disabled_at !== null`.
- **Login**: In `AuthController::login()`, after `Auth::attempt()` succeeds, check `$user->isDisabled()`. If disabled, call `Auth::logout()` and return 403 with a clear message (e.g. "This account has been disabled. Please contact your administrator.").
- **Admin toggle**: In `UserController::toggleDisabled()`, prevent disabling self or the last admin (use `AdminAuthorizationTrait::ensureNotLastAdmin()`). Toggle by setting `disabled_at` to `now()` or `null`.
- **API**: Expose `disabled_at` in user list/detail responses so the frontend can show Active/Disabled badge and Enable/Disable action.

**Key files**: `backend/app/Models/User.php`, `backend/app/Http/Controllers/Api/AuthController.php`, `backend/app/Http/Controllers/Api/UserController.php`, `backend/app/Http/Traits/AdminAuthorizationTrait.php`.

### AuditService Pattern

Use `AuditService` to log significant user actions for compliance and debugging. The service is registered as a singleton; inject it in controllers or use the `AuditLogging` trait.

```php
use App\Services\AuditService;
use App\Http\Traits\AuditLogging;

// Option 1: Inject AuditService
public function __construct(private AuditService $auditService) {}

$this->auditService->log('user.created', $user, [], ['name' => $user->name]);
$this->auditService->logAuth('login', $user);
$this->auditService->logAuth('login_failed', null, ['email' => $email], 'warning');
$this->auditService->logSettings('mail', $oldValues, $newValues, $request->user()->id);
$this->auditService->logUserAction('custom.action', null, null, 'info');
$this->auditService->logModelChange($model, 'resource.updated', $oldValues, $newValues);

// Option 2: Use AuditLogging trait (forwards to AuditService::log)
use AuditLogging;
$this->audit('user.created', $user, [], ['name' => $user->name], null, 'info');
```

- **When to use**: Log auth events (login, logout, failed login, 2FA), user management (create, update, delete, disable, admin toggle), settings changes, backup operations, and any other action that should be auditable.
- **Action naming**: Use `{resource}.{action}` (e.g. `auth.login`, `user.created`, `settings.updated`, `backup.restored`). Severity: `info`, `warning`, `error`, `critical`.
- **Sensitive data**: `AuditService::log()` and `logSettings()` automatically mask keys containing `password`, `token`, `secret`, `api_key`, etc. (values replaced with `***`). Do not pass raw secrets in `old_values`/`new_values`.
- **Error resilience**: If writing the audit log fails, the service logs to Laravel Log and returns null; the request is not broken.
- **Request context**: If you do not pass `Request`, the service resolves it from the container when available (for IP and user agent).

**Key files**: `backend/app/Services/AuditService.php`, `backend/app/Http/Traits/AuditLogging.php`, `backend/app/Models/AuditLog.php`, `backend/app/Http/Controllers/Api/AuditLogController.php`. See [Recipe: Trigger audit logging](recipes/trigger-audit-logging.md) and [Recipe: Add auditable action](recipes/add-auditable-action.md).

### Permission Checking Pattern (User Groups)

Use `PermissionService` for cached permission checks, or `User::hasPermission()` / `User::inGroup()` on the User model (which uses the `HasGroups` trait).

```php
use App\Enums\Permission;
use App\Services\PermissionService;

// In controller or service: cached check
public function __construct(private PermissionService $permissionService) {}

if (!$this->permissionService->check($request->user(), Permission::BACKUPS_CREATE)) {
    abort(403, 'You do not have permission to create backups.');
}

// On User model (from HasGroups trait): direct check
if ($user->inGroup('admin')) { /* admin has all permissions */ }
if ($user->hasPermission(Permission::SETTINGS_EDIT)) { /* ... */ }
if ($user->hasPermission('users.view', null, null)) { /* global permission */ }
```

- **Admin group**: Users in the `admin` group have all permissions implicitly; no need to store every permission.
- **Caching**: `PermissionService::check()` caches results per user (single key per user for SQLite compatibility). Call `clearUserCache($user)` or `clearGroupCache($group)` when membership or group permissions change.
- **Admin check**: `User::isAdmin()` returns `true` if the user is in the `admin` group. The API includes a computed `is_admin` attribute derived from this.
- **Route protection**: All permissions are registered as Laravel Gates in `AppServiceProvider`. Use `->middleware('can:permission.name')` on routes (e.g. `can:users.view`, `can:backups.create`). See [backend/routes/api.php](backend/routes/api.php) for examples.

**Key files**: `backend/app/Services/PermissionService.php`, `backend/app/Traits/HasGroups.php`, `backend/app/Enums/Permission.php`, `backend/app/Models/UserGroup.php`, `backend/app/Providers/AppServiceProvider.php`.

### Permission Gate (Frontend)

Use `usePermission(permission)` or `<PermissionGate>` to conditionally render UI based on the current user's permissions. The auth user from `GET /auth/user` includes a computed `permissions` array (admin users receive all permission strings).

```tsx
import { usePermission } from "@/lib/use-permission";
import { PermissionGate } from "@/components/permission-gate";

// Hook: single permission check
const canEditBackups = usePermission("backups.restore");
if (canEditBackups) {
  // show restore button
}

// Component: wrap content that requires a permission
<PermissionGate permission="users.create" fallback={null}>
  <Button>Create User</Button>
</PermissionGate>
```

- **Config navigation**: Configuration layout filters nav items by permission; each item can specify `permission` so only users with that permission see the link. Access to the Configuration area requires at least one of the config-related permissions (or admin).
- **Backend is source of truth**: Frontend permission checks are for UX only; API routes are protected with `can:permission` middleware.
- **Admin check**: Prefer `isAdminUser(user)` from `@/lib/auth` over `user?.is_admin` so the UI stays correct if the API ever drops the computed `is_admin` attribute (admin is derived from admin group membership).

**Key files**: `frontend/lib/use-permission.ts`, `frontend/lib/auth.ts` (`isAdminUser`), `frontend/components/permission-gate.tsx`, `frontend/app/(dashboard)/configuration/layout.tsx`, [Recipe: Add a new permission](recipes/add-new-permission.md).

### User Group Assignment (Frontend)

Use the shared **`useGroups()`** hook and **`UserGroupPicker`** component for group lists and user-group assignment. Do not fetch groups inline in multiple places.

```tsx
// Use the shared hook for group list (filter dropdown, picker data)
import { useGroups } from "@/lib/use-groups";

const { groups, isLoading, error, refetch } = useGroups();
// groups: Group[] from GET /groups
```

```tsx
// For user edit: use UserGroupPicker (uses useGroups internally)
import { UserGroupPicker } from "@/components/admin/user-group-picker";

<UserGroupPicker
  selectedGroupIds={selectedGroupIds}
  onChange={setSelectedGroupIds}
  currentUserId={currentUser?.id}
  editedUserId={user?.id}
/>
```

- **Backend**: Load groups on user list/detail (`UserController::index` / `show` with `with('groups:id,name,slug')`). Update memberships via `PUT /users/{user}/groups` with `{ group_ids: number[] }`; audit and clear permission cache.
- **Current user**: Pass `currentUserId` so the picker can prevent removing self from the admin group (`editedUserId === currentUserId`).
- **Profile**: Auth user from `GET /auth/user` includes `groups`; display read-only on profile page.

**Key files**: `frontend/lib/use-groups.ts`, `frontend/components/admin/user-group-picker.tsx`, `backend/app/Http/Controllers/Api/UserController.php` (`updateGroups`).

### Dashboard Widget Pattern

Dashboard uses static, developer-defined widgets. Widgets are self-contained React components added directly to the dashboard page—no database storage or user configuration.

**Widget component structure:**

```tsx
"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

interface StatsWidgetProps {
  metrics?: string[];
  title?: string;
}

export function StatsWidget({
  metrics = ["users", "storage"],
  title = "System Stats",
}: StatsWidgetProps) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["dashboard", "stats", metrics],
    queryFn: () => api.get("/dashboard/stats", { params: { metrics } }),
    staleTime: 5 * 60 * 1000, // Cache 5 minutes
  });

  if (isLoading) return <WidgetSkeleton />;
  if (error) return <WidgetError onRetry={refetch} />;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        {data?.metrics?.map((m) => (
          <div key={m.label} className="flex justify-between text-sm">
            <span className="text-muted-foreground">{m.label}</span>
            <span className="font-medium">{m.value}</span>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
```

**Adding to dashboard:**

```tsx
// frontend/app/(dashboard)/dashboard/page.tsx
export default function DashboardPage() {
  const { hasPermission } = usePermission();
  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      <WelcomeWidget />
      <StatsWidget />
      {hasPermission("admin") && <SystemHealthWidget />}
    </div>
  );
}
```

**Key files:** `frontend/app/(dashboard)/dashboard/page.tsx`, `frontend/components/dashboard/`, `frontend/components/dashboard/widgets/` (reference: welcome-widget, stats-widget, quick-actions-widget). See [Recipe: Add Dashboard Widget](recipes/add-dashboard-widget.md).

### Logging Pattern

Use the Laravel `Log` facade for operational and diagnostic events (not user actions; use AuditService for those). Every log record gets `correlation_id`, `user_id`, `ip_address`, and `request_uri` from the context processor when in HTTP context.

```php
use Illuminate\Support\Facades\Log;

// Success / operational events
Log::info('Backup created', ['filename' => $filename, 'size' => $size]);
Log::info('Email sent', ['user_id' => $user->id, 'to' => $user->email]);

// Recoverable issues
Log::warning('LLM provider failed', ['provider' => $name, 'error' => $e->getMessage()]);

// Failures
Log::error('Backup restore failed', ['error' => $e->getMessage()]);
```

- **Levels**: Use `info` for normal operations, `warning` for recoverable issues (e.g. one provider failed), `error` for failures. Use `debug` only for development.
- **Context**: Always pass a structured array (ids, names, duration_ms). Do not log secrets or full request bodies.
- **Frontend**: Use `errorLogger` from `frontend/lib/error-logger.ts` instead of `console.error`/`console.warn` so client errors are sent to `POST /api/client-errors` and appear in backend logs with correlation ID.

**Key files**: `backend/config/logging.php`, `backend/app/Logging/ContextProcessor.php`, `backend/app/Http/Middleware/AddCorrelationId.php`, `frontend/lib/error-logger.ts`, `docs/logging.md`. See [Recipe: Extend logging](recipes/extend-logging.md).

**Related features**: Application log export (`GET /api/app-logs/export`, filter by date/level/correlation_id); log retention (Configuration > Log retention, `log:cleanup` with `--dry-run`/`--archive`); suspicious activity alerting (`log:check-suspicious` every 15 min, notifies admins; `GET /api/suspicious-activity` for dashboard).

### LLMModelDiscoveryService Pattern

Use `LLMModelDiscoveryService` to discover available models for an LLM provider using credentials.

- `discoverModels(provider, credentials)` — Returns normalized model list with server-side caching (1h TTL).
- `validateCredentials(provider, credentials)` — Returns true if credentials are valid (at least one model returned).

Each provider has a private `discover{Provider}Models()` method that:

1. Calls the provider's models/deployments API.
2. Normalizes the response to `[{id, name, provider, capabilities?}]`.
3. Filters to relevant models (e.g. chat or text-generation only).

Credential requirements vary by provider:

| Provider | Credentials |
|----------|-------------|
| openai, claude, gemini | `api_key` |
| ollama | `host` |
| azure | `endpoint`, `api_key` |
| bedrock | `region`, `access_key`, `secret_key` |

The service cache key must include a hash of all credential fields used by the provider (e.g. endpoint for Azure, access_key/secret_key for Bedrock) so different accounts do not share the same cached model list.

**Key files**: `backend/app/Services/LLMModelDiscoveryService.php`, `backend/app/Http/Controllers/Api/LLMModelController.php`. See [Recipe: Add LLM Provider](recipes/add-llm-provider.md) and [LLM Model Discovery Roadmap](../plans/llm-model-discovery-roadmap.md).

### SettingService Pattern

Use `SettingService` for system-wide settings that can be stored in the database with environment fallback. Do not use `SystemSetting` directly for migratable settings; use the service so env fallback and caching apply.

```php
<?php
// Inject SettingService in controller or service
use App\Services\SettingService;

public function __construct(private SettingService $settingService) {}

// Get a single setting (env fallback)
$value = $this->settingService->get('mail', 'smtp_host', '127.0.0.1');

// Get all settings for a group (env fallback)
$mailSettings = $this->settingService->getGroup('mail');

// Set a setting (encryption applied per settings-schema; cache cleared)
$this->settingService->set('mail', 'smtp_password', $password, $request->user()->id);

// Check if value is overridden in DB (vs env)
if ($this->settingService->isOverridden('mail', 'smtp_host')) { ... }

// Reset to env default (delete from DB, clear cache)
$this->settingService->reset('mail', 'smtp_password');

// Get all settings (for boot-time config injection; cached)
$all = $this->settingService->all();
```

- Define new migratable settings in `backend/config/settings-schema.php` with `env`, `default`, `encrypted`, and optionally `public` keys. Use `'public' => true` for settings that must be exposed via `GET /system-settings/public` (e.g. `general.app_name` for page titles and branding).
- Add boot-time injection in `ConfigServiceProvider::boot()` for new groups.
- Use file cache only for settings (not DB) to avoid circular dependency.

### SearchService Pattern

Use `SearchService` for full-text search with Meilisearch/Scout. The service centralizes search logic, result transformation (title, subtitle, url, highlight), and index stats. When Meilisearch is unavailable, user search falls back to database `LIKE` queries.

- **searchUsers(query, perPage?, page)** — User search with pagination (Scout uses 3 args: perPage, pageName, page).
- **globalSearch(query, type?, filters?, page?, perPage?, scopeUserId?)** — Multi-model search; returns unified `{ data, meta }`. Pass `scopeUserId` for non-admin to scope user results to that user only.
- **getSuggestions(query, limit?, scopeUserId?)** — Fast autocomplete; returns pages, user groups (admin only), and users in one list.
- **syncPagesToIndex()** — Sync static pages from `config/search-pages.php` to the Meilisearch `pages` index.
- **searchPages(query, isAdmin, limit)** — Search the pages index (filter by `admin_only` when not admin).
- **searchUserGroups(query, limit)** — Search user groups (admin only); used in getSuggestions.
- **getPagesIndexStats()** — Document count for the pages index.
- **getIndexStats()** — Document counts per index (includes pages and all searchable models).
- **reindexAll()** / **reindexModel(model)** — Reindex all or a single searchable model. Use `search:reindex pages` to sync pages.

**XSS safety:** Transform methods must escape all user-provided text (title, subtitle) with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` before returning. Use `highlightMatch()` for highlight text (it escapes then wraps the query in `<mark>`); the frontend renders highlights with `dangerouslySetInnerHTML`.

**Adding a new searchable model:** Add the model to `SearchService::$searchableModels` and `SearchReindexCommand::$searchableModels`, implement a type branch and transform method (with escaping) in SearchService, and add the result type icon in `frontend/components/search/search-result-icon.tsx`. See [Recipe: Add searchable model](recipes/add-searchable-model.md).

**Page search:** Pages are indexed in Meilisearch with content keywords for discoverability. The `pages` index includes titles, subtitles, URLs, and rich content describing what each page contains. Use `searchPages(query, isAdmin, limit)` to search. Pages are defined in `backend/config/search-pages.php`. See [Recipe: Add searchable page](recipes/add-searchable-page.md).

**UserGroup search:** User groups are searchable by name, slug, and description. Admin-only — non-admin users do not see group results. Use `searchUserGroups(query, limit)` or include in `globalSearch()` with `type=user_groups`.

**Key files:** `backend/app/Services/Search/SearchService.php`, `backend/config/search-pages.php`, `backend/app/Http/Controllers/Api/SearchController.php`, `backend/app/Http/Controllers/Api/Admin/SearchAdminController.php`, `backend/app/Console/Commands/SearchReindexCommand.php`, `backend/routes/api.php`, `frontend/lib/search.ts`, `frontend/components/search/search-modal.tsx`, `frontend/components/search/search-provider.tsx`.

### EmailTemplateService Pattern

Use `EmailTemplateService` for rendering email templates with variable replacement. Send rendered content via the **TemplatedMail** Mailable:

```php
use App\Mail\TemplatedMail;
use App\Services\EmailTemplateService;
use App\Services\RenderedEmail;
use Illuminate\Support\Facades\Mail;

public function __construct(private EmailTemplateService $templateService) {}

// Render a template (active templates only)
$rendered = $this->templateService->render('password_reset', [
    'user' => ['name' => $user->name, 'email' => $user->email],
    'reset_url' => $resetUrl,
    'expires_in' => '60 minutes',
    'app_name' => config('app.name'),
]);

// Send via TemplatedMail (standard pattern)
Mail::to($user->email)->send(new TemplatedMail($rendered));

// Or use rendered content directly
$rendered->subject; // "Reset your password"
$rendered->html;    // HTML body
$rendered->text;    // Plain text body
```

- **TemplatedMail**: Accepts a `RenderedEmail` DTO; sets subject and HTML body. Use for all template-based sends so admins’ customizations apply.
- Variable placeholders: `{{variable}}` or `{{user.name}}` (dot notation for nested arrays). Missing variables are replaced with empty string.
- For admin preview of any template (including inactive), use `renderTemplate(EmailTemplate $template, array $variables): RenderedEmail`.
- For **live preview of unsaved content** (e.g. admin UI), use `renderContent(string $subject, string $bodyHtml, ?string $bodyText, array $variables): RenderedEmail`. The preview API accepts optional `subject`, `body_html`, `body_text` in the request body and uses them when present.
- Default templates are defined in `EmailTemplateSeeder` and seeded on migration; reset-to-default uses `getDefaultContent($key)`.

**Integration points:** Built-in templates are wired as follows. Use the same pattern (render + TemplatedMail) for new flows.

- **password_reset** – `User::sendPasswordResetNotification($token)`; reset URL uses `config('app.frontend_url')` + `/reset-password?token=...&email=...`.
- **email_verification** – `User::sendEmailVerificationNotification()`; verification URL uses frontend `/verify-email?id=...&hash=...`.
- **notification** – `EmailChannel::send()`; passes user, title, message, action_url, action_text, app_name.
- **welcome** – Template exists; not yet wired (e.g. to `Registered` event).

**Key files**: `backend/app/Services/EmailTemplateService.php`, `backend/app/Services/RenderedEmail.php`, `backend/app/Mail/TemplatedMail.php`, `backend/app/Models/EmailTemplate.php`, `backend/app/Models/User.php` (overrides), `backend/app/Services/Notifications/Channels/EmailChannel.php`, `backend/database/seeders/EmailTemplateSeeder.php`. See [ADR-016: Email Template System](../adr/016-email-template-system.md) and [Recipe: Add Email Template](recipes/add-email-template.md).

### Email Template Admin UI Pattern

The admin UI for email templates lives under **Configuration > Email Templates** (`/configuration/email-templates`):

- **List page**: Fetches `GET /api/email-templates`; displays name, description, Active/Inactive badge, System badge, last updated; row click navigates to `/configuration/email-templates/[key]`.
- **Editor page**: Fetches `GET /api/email-templates/{key}`; form with subject (Input), body (TipTap WYSIWYG via `EmailTemplateEditor`), plain text (Textarea, optional), Active (Switch). Save calls `PUT /api/email-templates/{key}`. Reset to default (system templates only) calls `POST /api/email-templates/{key}/reset`.
- **EmailTemplateEditor**: TipTap with StarterKit, Link, Image, Placeholder; toolbar (Bold, Italic, headings, lists, link, image, variable picker). Controlled: `content` and `onChange(html)`. Variable picker inserts `{{variable}}` at cursor.
- **VariablePicker**: Dropdown listing template `variables`; on select, parent inserts `{{variable}}` into the editor.
- **Live preview**: Debounce (e.g. 500ms) then `POST /api/email-templates/{key}/preview` with current `subject`, `body_html`, `body_text`; render returned HTML in an iframe (sandbox). Preview API uses `EmailTemplateService::renderContent()` when body is provided.
- **Test email**: Dialog with email input (default current user); `POST /api/email-templates/{key}/test` with `{ to }`. Handle 503 when email is not configured.

**Key files**: `frontend/app/(dashboard)/configuration/email-templates/page.tsx`, `frontend/app/(dashboard)/configuration/email-templates/[key]/page.tsx`, `frontend/components/email-template-editor.tsx`, `frontend/components/variable-picker.tsx`. Navigation: add "Email Templates" to `frontend/app/(dashboard)/configuration/layout.tsx`.

### NotificationTemplateService Pattern

Use `NotificationTemplateService` for rendering per-type, per-channel-group notification templates (push, inapp, chat). Email continues to use EmailTemplateService (ADR-016).

```php
use App\Services\NotificationTemplateService;

public function __construct(private NotificationTemplateService $templateService) {}

// Render a template (active only) for a type and channel group
$rendered = $this->templateService->render('backup.completed', 'inapp', [
    'user' => ['name' => $user->name, 'email' => $user->email],
    'app_name' => config('app.name'),
    'backup_name' => $backup->name,
]);

// $rendered is ['title' => string, 'body' => string]
$title = $rendered['title'];
$body = $rendered['body'];
```

- **getByTypeAndChannel($type, $channelGroup)**: Find active template. Returns null if none.
- **render($type, $channelGroup, $variables)**: Render active template; throws if not found.
- **renderTemplate(NotificationTemplate $template, $variables)**: Admin preview of any template.
- **renderContent($title, $body, $variables)**: Live preview of unsaved content.
- **getDefaultContent($type, $channelGroup)**: For reset; delegates to NotificationTemplateSeeder::getDefaultFor().

Channel groups: `push` (WebPush, FCM, ntfy), `inapp` (DatabaseChannel), `chat` (Telegram, Discord, Slack, Twilio, Signal, Matrix, Vonage, SNS). Each channel’s `send()` optionally resolves a template for (type, channel_group) when present; otherwise uses passed title/message. Use **NotificationOrchestrator::sendByType($user, $type, $variables, $channels)** to send using templates per channel.

**Key files**: `backend/app/Services/NotificationTemplateService.php`, `backend/app/Models/NotificationTemplate.php`, `backend/app/Services/Notifications/NotificationOrchestrator.php`, `backend/app/Services/Notifications/Channels/*`. See [ADR-017: Notification Template System](../adr/017-notification-template-system.md) and [Recipe: Add Notification Template](recipes/add-notification-template.md).

### Notification Template Admin UI Pattern

The admin UI for notification templates lives under **Configuration > Notification Templates** (`/configuration/notification-templates`):

- **List page**: Fetches `GET /api/notification-templates`; displays type, channel group (Push/In-App/Chat), title, Active/Inactive badge, System badge, last updated; row click navigates to `/configuration/notification-templates/[id]`.
- **Editor page**: Fetches `GET /api/notification-templates/{id}`; form with title (Input), body (Textarea), Active (Switch). Save calls `PUT /api/notification-templates/{id}`. Reset to default (system templates only) calls `POST /api/notification-templates/{id}/reset`.
- **Live preview**: Debounce (e.g. 500ms) then `POST /api/notification-templates/{id}/preview` with current `title`, `body`; display returned title and body in a preview panel. Preview API uses `NotificationTemplateService::renderContent()` when title/body are provided.
- **Variables**: Template `variables` list is shown in the form description; use placeholders like `{{user.name}}`, `{{app_name}}`, `{{backup_name}}` in title and body. No separate variable picker component (simpler than email templates).
- **Variables Reference panel**: The editor page includes a collapsible "Available Variables" card (below the Preview card). The API returns `variable_descriptions` (from `NotificationTemplateController::variableDescriptions()`) alongside `variables` in `GET /api/notification-templates/{id}`. Each row shows the placeholder (e.g. `{{app_name}}`), a short description, and a Copy button. When adding a new notification type or a new variable name, add an entry to `variableDescriptions()` so the panel stays accurate; see [Recipe: Add Notification Template](recipes/add-notification-template.md) and [Recipe: Keep Notification Template Variables Reference Up to Date](recipes/keep-notification-template-variables-up-to-date.md).

**Key files**: `frontend/app/(dashboard)/configuration/notification-templates/page.tsx`, `frontend/app/(dashboard)/configuration/notification-templates/[id]/page.tsx`. Navigation: add "Notification Templates" to `frontend/app/(dashboard)/configuration/layout.tsx` (Communications group). Search: add entry to `frontend/lib/search-pages.ts`; the `NotificationTemplate` model is searchable (Scout/Meilisearch)—see [Add Searchable Model](recipes/add-searchable-model.md). Backend: `SearchService` (globalSearch, transformNotificationTemplateToResult), `SearchReindexCommand`, `backend/config/search-pages.php` (config-notification-templates page), and `search-result-icon.tsx` (notification_template icon).

### Backup & Restore Patterns

**Documentation:** Full backup docs (user, admin, developer; key files; recipes): [Backup & Restore](../backup.md).

**Backup settings (database with env fallback):** Backup configuration is stored in the `backup` group in `backend/config/settings-schema.php`. ConfigServiceProvider injects flat keys into nested `config('backup.*')` at boot; BackupService and destination classes read only from `config()`. Do not read SettingService inside BackupService or destinations; keep config as the single source at runtime.

**Destination interface:** New storage destinations implement `App\Services\Backup\Destinations\DestinationInterface`: `upload(localPath, filename): array`, `download(filename, localPath): array`, `list(): array`, `delete(filename): bool`, `isAvailable(): bool`, `getName(): string`. Destinations read `config('backup.destinations.{id}.*')` in `__construct()`; config is populated from DB by ConfigServiceProvider.

**Settings API and Test Connection:** BackupSettingController exposes `GET/PUT /backup-settings` and `POST /backup-settings/test/{destination}`. For Test Connection, the controller builds nested backup config from SettingService (via `injectBackupConfigFromSettings()`), injects it into config, then instantiates the destination and calls `isAvailable()`. Validation rules in `update()` must cover every backup schema key you expose in the UI.

**Backup UI:** The backup page has two tabs: **Backups** (list, create, download, restore, delete) and **Settings** (form for retention, schedule, S3/SFTP/Google Drive, encryption, notifications). Settings are fetched when the user opens the Settings tab; form uses react-hook-form + zod; SaveButton submits to `PUT /backup-settings`; Test Connection calls `POST /backup-settings/test/{destination}`. Add new settings by extending the schema, defaultBackupSettings, fetch mapping, and a Card in the Settings tab.

**Adding a new backup setting:** (1) Add flat key to `backup` group in settings-schema.php (use `encrypted` for secrets). (2) Add env default in backup.php if needed. (3) Map flat → nested in ConfigServiceProvider::injectBackupConfig(). (4) Add validation and injectBackupConfigFromSettings() in BackupSettingController. (5) Add field to frontend schema, defaults, fetch mapping, and a FormField/SettingsSwitchRow in the right Card.

**Adding a new destination:** See [Add backup destination](recipes/add-backup-destination.md). Summary: implement DestinationInterface, add flat keys to backup schema and backup.php destinations, map in ConfigServiceProvider and BackupSettingController, add Card and Test Connection in Settings tab.

**Extending backup/restore behavior:** See [Extend backup and restore features](recipes/extend-backup-restore.md) for new restore logic, scheduling, notifications, new backup content, or UI-only changes.

### Storage Settings Pattern

**Documentation:** [Storage Settings Enhancement Roadmap](../plans/storage-settings-roadmap.md), [Features: Storage Settings](../features.md#storage-settings).

**Storage settings (database):** Storage configuration is stored in the `storage` group via `SystemSetting::get`/`set` (no settings-schema; keys are driver plus provider-prefixed, e.g. `driver`, `s3_bucket`, `gcs_credentials_json`). All provider credentials and options use this single group so one active driver is configured at a time.

**StorageService:** `App\Services\StorageService` defines `PROVIDERS` (id → label, driver), `getProviderConfig(provider)`, `getAvailableProviders()`, `testConnection(provider, config)`, and `buildDiskConfig(provider, config)`. Connection test: build disk config from request/DB keys, set a temporary disk name in config, `Storage::disk(name)->put()` then `delete()` a test path, clear the temp disk. For S3-compatible providers the driver is `s3` with custom `endpoint` and `use_path_style_endpoint`; for GCS/Azure the driver is `gcs`/`azure` and drivers are registered in AppServiceProvider via `Storage::extend()` when the Flysystem adapter package is installed.

**Settings API and Test Connection:** StorageSettingController exposes `GET/PUT /storage-settings`, `POST /storage-settings/test`, `GET /storage-settings/stats`, `GET /storage-settings/paths`, `GET /storage-settings/health`. Test accepts `driver` and provider-prefixed keys in the body; controller calls `StorageService::testConnection($validated['driver'], $request->except(['driver']))`. Validation in `update()` uses `required_if:driver,{provider}` for each provider’s keys (e.g. `s3_bucket`, `gcs_credentials_json`, `minio_endpoint`).

**Storage UI:** Configuration > Storage: driver dropdown (local, s3, gcs, azure, do_spaces, minio, b2) with ProviderIcon, dynamic form sections per driver, max upload size and allowed file types (shared), Test Connection button (non-local) with loading/success/error state, Save. Test payload must include `driver` and all visible provider fields so the backend can build disk config.

**Adding a new storage provider:** See [Add storage provider](recipes/add-storage-provider.md). Summary: add to `StorageService::PROVIDERS` and `getSettingPrefix`, implement `buildDiskConfig()` branch; add disk in filesystems.php and optionally `Storage::extend()` for new driver types; add validation rules and frontend form section + test payload; add provider icon if needed.

### ScheduledTaskService Pattern (Manual Run of Scheduled Commands)

**Whitelist-only:** Only commands listed in `ScheduledTaskService::TRIGGERABLE_COMMANDS` may be run from the Jobs UI. Prevents arbitrary Artisan execution. Add new triggerable commands there with `description` and `dangerous` (extra confirmation in UI).

**Flow:** Controller validates command via `isTriggerable()`, calls `run($command, $userId, $options)`. Service creates `TaskRun` (if table exists), runs `Artisan::call()`, updates TaskRun with status/output/duration, returns `success`, `output`, `duration_ms`, `exit_code`. Controller audits `scheduled_command_run` and returns JSON (422 on failure).

**Rate limiting:** Optional `RATE_LIMIT_SECONDS` per command (e.g. `backup:run` 300s). Service uses `getLastRunAt()` from `task_runs`; block if last run was within the limit.

**Run history:** `task_runs` stores command, user_id (null = scheduled), status, started_at, completed_at, output, error, duration_ms. Use `getLastRun()` / `getRunHistory()` for UI. Service checks `Schema::hasTable('task_runs')` so it works before migration.

**Key files:** `ScheduledTaskService`, `JobController::scheduled()` (parses `schedule:list`, merges triggerable metadata and last_run; appends triggerable-only commands), `JobController::run()`, `TaskRun` model. Frontend: Configuration > Jobs, Run Now with confirmation (extra for dangerous).

### Form Request Pattern

```php
<?php
// backend/app/Http/Requests/StoreExampleRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by route middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'in:typeA,typeB,typeC'],
            'settings' => ['nullable', 'array'],
            'settings.key' => ['required_with:settings', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide a name.',
            'type.in' => 'Invalid type selected.',
        ];
    }
}
```

### Resource Pattern

```php
<?php
// backend/app/Http/Resources/ExampleResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExampleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'settings' => $this->settings,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
```

### Service Pattern

```php
<?php
// backend/app/Services/Example/ExampleService.php

namespace App\Services\Example;

use App\Models\Example;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ExampleService
{
    /**
     * Process an example with business logic.
     */
    public function process(Example $example, array $options = []): ProcessResult
    {
        try {
            // Business logic here
            $result = $this->doSomething($example, $options);

            Log::info('Example processed', [
                'example_id' => $example->id,
                'result' => $result,
            ]);

            return new ProcessResult(success: true, data: $result);
        } catch (\Exception $e) {
            Log::error('Example processing failed', [
                'example_id' => $example->id,
                'error' => $e->getMessage(),
            ]);

            return new ProcessResult(success: false, error: $e->getMessage());
        }
    }

    private function doSomething(Example $example, array $options): array
    {
        // Implementation
        return [];
    }
}
```

### Channel/Provider Pattern (for pluggable implementations)

Notification channels implement `ChannelInterface` and receive user-specific config from user settings:

```php
<?php
// backend/app/Services/Notifications/Channels/ExampleChannel.php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExampleChannel implements ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        // User-specific config from User Preferences
        $webhookUrl = $user->getSetting('notifications', 'example_webhook_url');

        if (!$webhookUrl) {
            throw new \RuntimeException('Example webhook URL not configured');
        }

        try {
            $response = Http::timeout(30)->post($webhookUrl, [
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'data' => $data,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Webhook error: ' . $response->body());
            }

            return ['sent' => true];
        } catch (\Exception $e) {
            Log::error('ExampleChannel: Send failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getName(): string
    {
        return 'Example';
    }

    public function isAvailableFor(User $user): bool
    {
        return config('notifications.channels.example.enabled', false);
    }
}
```

**Key points:**
- User-specific settings (webhooks, phone numbers) come from `$user->getSetting('notifications', 'key')`
- Global config (API keys, enabled status) comes from `config('notifications.channels.{channel}.*')`
- Add channel name/description to `NotificationChannelMetadata` trait
- Add user settings to `UserNotificationSettingsController::getRequiredSettings()`

See [Recipe: Add Notification Channel](recipes/add-notification-channel.md) for full guide.

### Route Definition Pattern

```php
// backend/routes/api.php

use App\Http\Controllers\Api\ExampleController;

// Public routes (no auth)
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Resource routes (CRUD)
    Route::apiResource('examples', ExampleController::class);

    // Custom routes
    Route::post('/examples/{example}/process', [ExampleController::class, 'process']);

    // Settings routes
    Route::prefix('settings')->group(function () {
        Route::get('/example', [SettingController::class, 'getExample']);
        Route::put('/example', [SettingController::class, 'updateExample']);
    });
});

// Admin-only routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminController::class, 'users']);
});
```

### First User Gets Admin Pattern

The first registered user automatically becomes an admin. Use this pattern in registration and SSO flows.

```php
use App\Models\User;
use App\Services\GroupService;

$groupService = app(GroupService::class);
$isFirstUser = User::count() === 0;

if ($isFirstUser) {
    $groupService->ensureDefaultGroupsExist();
}

$user = User::create([
    'name' => $validated['name'],
    'email' => $validated['email'],
    'password' => $validated['password'],
]);

if ($isFirstUser) {
    $user->assignGroup('admin');
} else {
    $groupService->assignDefaultGroupToUser($user);
}
```

**Key points:**
- Check `User::count() === 0` before creating the user
- Ensure default groups exist before assigning
- First user gets `admin` group, subsequent users get default group

**Used in:** `AuthController::register()`, `SSOService::createUserFromSocialite()`

### Multi-Channel Error Handling Pattern

When sending notifications through multiple channels, catch errors per channel and aggregate results. Don't let one channel's failure prevent others.

```php
// From NotificationOrchestrator::send()
public function send(User $user, string $type, string $title, string $message, array $data = [], ?array $channels = null): array
{
    $channels = $channels ?? $this->getDefaultChannels();
    $results = [];

    foreach ($channels as $channel) {
        try {
            $channelInstance = $this->getChannelInstance($channel);

            // Skip if channel is not available
            if (!$channelInstance || !$this->isChannelEnabled($channel)) {
                continue;
            }

            if (!$channelInstance->isAvailableFor($user)) {
                continue;
            }

            $result = $channelInstance->send($user, $type, $title, $message, $data);
            $results[$channel] = [
                'success' => true,
                'result' => $result,
            ];
        } catch (\Exception $e) {
            Log::error("Notification channel {$channel} failed", [
                'user' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            $results[$channel] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    return $results;
}
```

**Key points:**
- Iterate channels, wrap each in try/catch
- Log failures but continue to next channel
- Return aggregated results: `[channel => [success => bool, result|error => ...]]`
- Caller can inspect which channels succeeded/failed

**Key file:** `backend/app/Services/Notifications/NotificationOrchestrator.php`

### Filename Validation Pattern (Path Traversal Prevention)

Validate filenames before file operations to prevent path traversal attacks.

```php
// From BackupController
private function validateFilename(string $filename): bool
{
    // Only allow alphanumeric, dash, underscore, and .zip extension
    // Must match our backup naming pattern: selfmx-backup-YYYY-MM-DD_HH-ii-ss.zip
    if (!preg_match('/^selfmx-backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename)) {
        return false;
    }

    // Double-check for path traversal characters
    if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
        return false;
    }

    return true;
}

// Usage in controller
public function download(string $filename): StreamedResponse|JsonResponse
{
    if (!$this->validateFilename($filename)) {
        return response()->json(['message' => 'Invalid backup filename'], 400);
    }
    // ... proceed with download
}
```

**For general file paths (FileManagerController):**

```php
private function validatePath(string $path): bool
{
    // Block path traversal and null bytes
    if (str_contains($path, '..') || preg_match('#\0#', $path)) {
        return false;
    }

    // Block access to sensitive directories
    $blocked = ['.env', 'config', '.git', 'bootstrap', 'vendor'];
    $segments = $path === '' ? [] : explode('/', trim($path, '/'));
    foreach ($segments as $segment) {
        if (in_array(strtolower($segment), $blocked, true)) {
            return false;
        }
    }

    return true;
}
```

**Key points:**
- Always validate before any file operation (read, write, delete)
- Block `..` for path traversal
- Block null bytes (`\0`)
- Use allowlist regex for expected filename patterns
- Block access to sensitive directories

**Key files:** `backend/app/Http/Controllers/Api/BackupController.php`, `backend/app/Http/Controllers/Api/FileManagerController.php`, `backend/app/Http/Requests/FilePathRequest.php`

## Backend Traits

Use shared traits for consistent controller behavior. Location: `backend/app/Http/Traits/`.

### AdminAuthorizationTrait

Prevents modifying or deleting the last admin. "Admin" means the user is in the `admin` group; "last admin" is the last user in that group. Use `ensureNotLastAdmin(User $user, string $action)` before delete/disable/demote actions on admin users. Also use it when removing a member from the admin group (e.g. `GroupController::removeMember`) or when bulk-updating user groups (e.g. `UserController::updateGroups`) so the last admin cannot be removed.

```php
use App\Http\Traits\AdminAuthorizationTrait;

class UserController extends Controller
{
    use AdminAuthorizationTrait;

    public function destroy(User $user): JsonResponse
    {
        if ($error = $this->ensureNotLastAdmin($user, 'delete')) {
            return $error;
        }
        // ... prevent self-delete, then delete
        return $this->deleteResponse('User deleted successfully');
    }

    public function toggleAdmin(User $user): JsonResponse
    {
        if ($error = $this->ensureNotLastAdmin($user, 'remove admin status from')) {
            return $error;
        }
        // ...
    }
}
```

In `GroupController::removeMember`, when the group is the admin group, call `ensureNotLastAdmin($user, 'remove from admin group')` before removing. In `UserController::updateGroups`, before syncing group IDs, if the user is in the admin group and the new list omits the admin group, call `ensureNotLastAdmin($user, 'remove from admin group')`.

Common action verbs: `'delete'`, `'disable'`, `'remove admin status from'`, `'remove from admin group'`. See [Add admin-protected action](recipes/add-admin-protected-action.md).

### ApiResponseTrait

Standardized JSON response helpers. Convention: use `message` for success/error text, `data` for payload, `meta` for pagination when applicable.

| Method | Use |
|--------|-----|
| `successResponse($message, $data = [], $status = 200)` | Success with message and optional data |
| `createdResponse($message, $data = [])` | 201 created |
| `errorResponse($message, $status = 400)` | Error with message |
| `deleteResponse($message = 'Resource deleted successfully')` | 200 with message (use for delete operations) |
| `dataResponse($data, $status = 200)` | Raw data (e.g. paginated list) |

Delete endpoints use **200 with a message body** (not 204) for consistency. Use `deleteResponse()` for delete operations.

```php
use App\Http\Traits\ApiResponseTrait;

class ExampleController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $items = Example::paginate($request->input('per_page', config('app.pagination.default')));
        return $this->dataResponse($items);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $example = Example::create($request->validated());
        return $this->createdResponse('Example created', ['example' => $example]);
    }

    public function destroy(Example $example): JsonResponse
    {
        $example->delete();
        return $this->deleteResponse('Example deleted successfully');
    }
}
```

### Pagination config

Use `config('app.pagination.default')` (default 20) for list endpoints. Audit logs use `config('app.pagination.audit_log')` (50).

```php
$perPage = $request->input('per_page', config('app.pagination.default'));
$logs = AuditLog::paginate($request->input('per_page', config('app.pagination.audit_log')));
```

### User password (hashed cast)

The `User` model uses Laravel's `hashed` cast for `password`. Pass **plaintext** when creating or updating users; the cast hashes automatically. Do not use `Hash::make()` in controllers for User password fields, or you will double-hash.

```php
// Good – plaintext, cast hashes
User::create(['name' => $n, 'email' => $e, 'password' => $validated['password']]);
$user->update(['password' => $validated['password']]);

// Bad – double-hash when User has hashed cast
User::create(['password' => Hash::make($validated['password'])]);
```

## Frontend Patterns

### Global Component Principle

**CRITICAL: Never duplicate logic across pages.** All reusable functionality must be implemented as shared components or utilities.

| Type | Location | When to Create |
|------|----------|----------------|
| UI Components | `frontend/components/ui/` | Generic UI elements (buttons, cards, inputs) |
| Feature Components | `frontend/components/` | App-specific components used on 2+ pages |
| Hooks | `frontend/lib/` or `frontend/hooks/` | Shared stateful logic |
| Utilities | `frontend/lib/` | Pure functions, API client, helpers |

**Before creating any component or utility:**
1. Search the codebase for existing implementations
2. If similar functionality exists, use or extend it
3. Only create page-specific code when explicitly required and document why

**Examples of global components:**
- `Logo` - Used on auth pages, sidebar, dashboard
- `usePageTitle` - Sets document title consistently (format: "Page Name | App Name" from config). Used by `AuthPageLayout` for auth pages, by root and share pages directly, and by `PageTitleManager` for dashboard routes.
- `api` - API client with auth handling
- `formatDate`, `formatDateTime`, `formatTimestamp`, `formatBytes`, `getErrorMessage` - in `frontend/lib/utils.ts` (date/bytes formatting and API error message extraction)

See [Cursor rule: global-components.mdc](../../.cursor/rules/global-components.mdc) for full guidelines.

### Settings Page Components

Use these shared components for consistent settings/configuration page UX.

#### SettingsPageSkeleton

Loading state component for settings pages:

```tsx
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";

export default function MySettingsPage() {
  const [isLoading, setIsLoading] = useState(true);

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return <div>...</div>;
}
```

**Props:**
- `minHeight?: string` - Custom min-height (default: "400px")

**Key file:** `frontend/components/ui/settings-page-skeleton.tsx`

#### SaveButton

Form save button with built-in dirty/saving state handling:

```tsx
import { SaveButton } from "@/components/ui/save-button";

export default function MySettingsPage() {
  const { formState: { isDirty } } = useForm();
  const [isSaving, setIsSaving] = useState(false);

  return (
    <form onSubmit={handleSubmit}>
      {/* form fields */}
      <SaveButton isDirty={isDirty} isSaving={isSaving} />
    </form>
  );
}
```

**Props:**
- `isDirty: boolean` - Whether form has unsaved changes
- `isSaving: boolean` - Whether form is currently submitting
- `children?: ReactNode` - Custom button text (default: "Save Changes")
- Extends all `ButtonProps` except `type`

**Key file:** `frontend/components/ui/save-button.tsx`

#### SettingsSwitchRow

Settings row with label (and optional description) on the left and a switch on the right. The switch keeps its natural size; the row provides a 44px touch target for accessibility. Use for configuration toggles (e.g. "Enable SSO", "Allow account linking").

```tsx
import { SettingsSwitchRow } from "@/components/ui/settings-switch-row";

<SettingsSwitchRow
  label="Enable feature"
  description="Optional description below the label"
  checked={watch("enabled")}
  onCheckedChange={(checked) => setValue("enabled", checked, { shouldDirty: true })}
/>
```

**Props:**
- `label: string` - Label shown on the left
- `description?: string` - Optional description below the label
- `checked: boolean` - Controlled checked state
- `onCheckedChange: (checked: boolean) => void` - Called when toggled
- `disabled?: boolean` - Disable the switch
- `className?: string` - Additional class for the row container

**Key file:** `frontend/components/ui/settings-switch-row.tsx`

#### CollapsibleCard Pattern

Use `CollapsibleCard` for expandable sections in settings, configuration, or list pages. The header (title, description, icon, status badge, optional header actions) is always visible; the body expands/collapses with a smooth animation.

```tsx
import { CollapsibleCard } from "@/components/ui/collapsible-card";
import { MessageSquare } from "lucide-react";

<CollapsibleCard
  title="Slack"
  description="Send notifications to Slack channels."
  icon={<MessageSquare className="h-4 w-4" />}
  status={{ label: "Configured", variant: "success" }}
  defaultOpen={false}
>
  <div className="space-y-4">{/* form fields */}</div>
</CollapsibleCard>
```

**Props:**
- `title: string` - Card title (always visible)
- `description?: string` - Optional description in header
- `icon?: ReactNode` - Optional icon (e.g. Lucide) in header
- `status?: { label: string; variant: "default" | "success" | "warning" | "destructive" }` - Badge in header
- `defaultOpen?: boolean` - Whether card starts expanded (default: false)
- `open?: boolean` - Controlled open state
- `onOpenChange?: (open: boolean) => void` - Called when open state changes
- `headerActions?: ReactNode` - Content before chevron (e.g. toggle switch)
- `children: ReactNode` - Expandable content
- `disabled?: boolean` - Disable expand/collapse
- `className?: string` - Additional CSS for the card

**When to use:**
- Multiple provider/channel cards on a page (SSO, Notifications, LLM, Backup)
- Configuration sections that can be collapsed
- Lists with expandable item details

**Key file:** `frontend/components/ui/collapsible-card.tsx`. See [Recipe: Add collapsible section](recipes/add-collapsible-section.md).

#### ProviderIcon Pattern

Use the shared `ProviderIcon` component for SSO providers, LLM providers, notification channels, email/backup providers, and similar. Icons live in **`frontend/components/provider-icons.tsx`**; do not add inline icon maps in page components.

```tsx
import { ProviderIcon } from "@/components/provider-icons";

// Sign-in buttons (branded style)
<ProviderIcon provider={provider.icon} size="sm" style="branded" />

// Config/settings headers (mono, theme-aware)
<ProviderIcon provider="google" size="sm" style="mono" />
<ProviderIcon provider="openai" size="sm" style="mono" />
```

**Props:**
- `provider: string` - Provider id (e.g. `google`, `github`, `openai`, `key` for generic). Must match a key in `provider-icons.tsx`; unknown ids fall back to `key`.
- `size?: "sm" | "md" | "lg"` - Size preset (default: `md`).
- `style?: "mono" | "branded"` - Mono uses `currentColor` (theme-aware); branded reserves official colors (currently same as mono in implementation).
- `className?: string` - Additional CSS classes.

**When to use:**
- SSO sign-in/register buttons and Configuration > SSO card headers
- Configuration > AI/LLM provider headers
- Configuration > Backup destination headers (e.g. S3, Google Drive)
- Any UI that shows a third-party provider or channel name and needs a consistent icon

**Adding a new icon:** Edit `frontend/components/provider-icons.tsx`. Add the id to `ProviderIconId` (optional), then add an entry to `SSO_ICONS`, `LLM_ICONS`, or `ALL_ICONS` with the same key. Use `renderSvg(className, <path d="..." />)` for simple SVGs. See [Recipe: Add SSO Provider](recipes/add-sso-provider.md) Step 7.

**Key file:** `frontend/components/provider-icons.tsx`

### Help System Pattern

The in-app help system provides searchable documentation, contextual links, and tooltips. Use it for user-facing and admin documentation.

**Article structure** (`frontend/lib/help/help-content.ts`):
- **HelpArticle**: `id`, `title`, `content` (markdown), `tags?`
- **HelpCategory**: `slug`, `name`, `icon?`, `articles`, `adminOnly?`
- Categories are split into `userHelpCategories` (all users) and `adminHelpCategories` (admin only)

**HelpLink component** – Opens a specific article in the help modal:

```tsx
import { HelpLink } from "@/components/help/help-link";

<p className="text-muted-foreground">
  Configure this feature. <HelpLink articleId="my-article-id" />
</p>
```

**HelpProvider** – Wraps the app and provides `openArticle(articleId)`. The help modal is opened with `?` or `Ctrl+/`. `HelpProvider` must wrap `SearchProvider` so the search modal can open help articles.

**Search integration** – Help articles are indexed in `backend/config/search-pages.php` with a `help:` URL prefix (e.g. `help:welcome`). The search modal intercepts these URLs and calls `openArticle()` instead of navigating. Add one entry per article; include `admin_only: true` for admin-only articles.

**When adding help content:** See [Recipe: Add help article](recipes/add-help-article.md).

**Key files:**
- `frontend/lib/help/help-content.ts` – Article definitions
- `frontend/components/help/` – HelpLink, HelpCenterModal, HelpProvider
- `backend/config/search-pages.php` – Search index entries for help articles

### Configuration Navigation Pattern

Configuration uses **grouped, collapsible navigation** in [frontend/app/(dashboard)/configuration/layout.tsx](frontend/app/(dashboard)/configuration/layout.tsx). Items are organized into groups (General, Users & Access, Communications, Integrations, Logs & Monitoring, Data). Each group is collapsible; the group containing the current page is expanded by default.

**Structure:**

- **navigationGroups**: Array of `{ name, icon, items }`. Each `item` has `name`, `href`, `icon`, `description`.
- **GroupedNavigation**: Renders each group as a `Collapsible` (shadcn/ui). Trigger shows group name and icon; content lists links.
- **Persistence**: Expanded/collapsed state is stored in localStorage under `config-nav-expanded-groups` so preferences survive refresh.

**Adding a new item:**

1. Choose the appropriate group (see [Recipe: Add configuration menu item](recipes/add-configuration-menu-item.md)).
2. Add an entry to that group's `items` in `navigationGroups` with `name`, `href`, `icon`, `description`.
3. Create the page at `frontend/app/(dashboard)/configuration/[slug]/page.tsx` so the href resolves.

**Adding a new group:** Only when the feature area does not fit existing groups. Add a new object to `navigationGroups` with `name`, `icon`, and `items` (array). Document the new group in the recipe and here.

**Key file:** [frontend/app/(dashboard)/configuration/layout.tsx](frontend/app/(dashboard)/configuration/layout.tsx)

### Auth Page Components

Use these shared components for consistent authentication page UX.

#### AuthPageLayout

Layout wrapper for auth pages with Logo, title, and description:

```tsx
import { AuthPageLayout } from "@/components/auth/auth-page-layout";

export default function LoginPage() {
  return (
    <AuthPageLayout
      title="Sign In"
      description="Enter your credentials to access your account"
    >
      {/* Form content */}
    </AuthPageLayout>
  );
}
```

**Props:**
- `title: string` - Page title
- `description?: string` - Optional description text
- `children: ReactNode` - Page content

**Key file:** `frontend/components/auth/auth-page-layout.tsx`

#### FormField

Label + optional description/help link + Input + error message combo for form fields:

```tsx
import { FormField } from "@/components/ui/form-field";
import { Input } from "@/components/ui/input";

export default function MyForm() {
  const { register, formState: { errors } } = useForm();

  return (
    <FormField
      id="email"
      label="Email"
      description="We'll never share your email."
      helpLink={{ label: "Help", url: "https://example.com/help" }}
      error={errors.email?.message}
    >
      <Input id="email" type="email" {...register("email")} />
    </FormField>
  );
}
```

**Props:**
- `id: string` - Field ID (for label htmlFor)
- `label: string | ReactNode` - Label text or custom label element
- `description?: string` - Optional muted text below the label
- `helpLink?: { label: string; url?: string; onClick?: () => void }` - Optional help link (url opens in new tab; onClick for modals)
- `error?: string` - Error message to display
- `children: ReactNode` - Input component
- `className?: string` - Additional CSS classes

**Key file:** `frontend/components/ui/form-field.tsx`

#### LoadingButton

Button with loading spinner support:

```tsx
import { LoadingButton } from "@/components/ui/loading-button";

export default function MyForm() {
  const [isLoading, setIsLoading] = useState(false);

  return (
    <LoadingButton
      type="submit"
      isLoading={isLoading}
      loadingText="Signing in..."
    >
      Sign In
    </LoadingButton>
  );
}
```

**Props:**
- `isLoading: boolean` - Whether button is in loading state
- `loadingText?: string` - Text to show when loading (defaults to children)
- `children: ReactNode` - Button text
- Extends all `ButtonProps`

**Key file:** `frontend/components/ui/loading-button.tsx`

#### SSO Provider Display (Sign-In vs Setup)

**Sign-in and register pages:** SSO buttons are rendered by `SSOButtons` (`frontend/components/auth/sso-buttons.tsx`), which fetches **enabled** providers from `GET /auth/sso/providers`. A provider is shown only when it has credentials, has **passed** the connection test (`{provider}_test_passed`), and the per-provider `{provider}_enabled` flag is true. Each provider shows a "Continue with {name}" button with an icon. Icons come from the shared **`ProviderIcon`** component (`frontend/components/provider-icons.tsx`): the `icon` value from the API must exist in the icon map there; unknown icons fall back to the generic `key` icon. When adding a new SSO provider, add its icon to `provider-icons.tsx` (see [Recipe: Add SSO Provider](recipes/add-sso-provider.md) Step 7).

**Setup page:** Configuration > SSO (`frontend/app/(dashboard)/configuration/sso/page.tsx`) is where admins configure provider credentials. The **Global options** card (Enable SSO, Allow account linking, etc.) has its own save button; each provider card has a **per-provider save** button so only that provider's settings are sent on save. Each provider card uses `CollapsibleCard` with `ProviderIcon` in the header, a "Setup instructions" button (opens `SSOSetupModal`), status badge (**Not configured** → **Test required** → **Test passed** / **Enabled**), per-provider **Enabled** toggle (shown only when credentials are set **and** test has passed), redirect URI (copyable), and "Test connection" button. The "Test connection" button is enabled only when both Client ID and Client secret are filled; on success, settings are refetched so `test_passed` updates and the Enable toggle appears. The `providers` array includes `enabledKey` and `testPassedKey`; schema and defaultValues include `{provider}_enabled` and `{provider}_test_passed`. New providers must be added to the setup page, to `provider-icons.tsx`, and to `frontend/components/admin/sso-setup-modal.tsx` (setup instructions content).

**SSO Provider Enabled Toggle pattern:** Three conditions for a provider to appear on the login page: (1) **Credentials** (client_id and client_secret; for OIDC also issuer_url), (2) **Test passed** (`{provider}_test_passed` set by successful "Test connection"), (3) **Enabled** (`{provider}_enabled` is true). The admin can turn off a provider without removing credentials; changing credentials clears `test_passed` so the provider must be re-tested before it can be enabled again.

**SSO Test Connection (Credential Validation) pattern:** The backend test endpoint (`POST /api/sso-settings/test/{provider}`) **validates credentials** at the provider's token endpoint; it must not pass with incorrect credentials. Implementation: POST to the provider's token endpoint with `grant_type=authorization_code`, `code=test_connection_validation`, `client_id`, `client_secret`, and `redirect_uri`. Interpret the response: **invalid_client** or HTTP 401 → credentials invalid (do not set `test_passed`); **invalid_grant**, **invalid_request**, or **bad_verification_code** (GitHub) → credentials accepted (set `test_passed = true`). Require client_secret for the test. **Frontend after success:** Update the form directly (`setValue(testPassedKey, true)` and `setValue(enabledKey, true, { shouldDirty: true })`) instead of calling `fetchSettings()`. This avoids a full page reload that collapses all `CollapsibleCard` components and hides the newly-appeared toggle. See `SSOSettingController::test()` and `validateCredentialsAtTokenEndpoint()` in `backend/app/Http/Controllers/Api/SSOSettingController.php`. When adding a new provider, implement token-endpoint credential validation (discovery for OIDC/Google/Microsoft; known token URL for GitHub/Discord/GitLab; or custom logic for providers like Apple).

**Key files:**
- Icons: `frontend/components/provider-icons.tsx` (single source for SSO, LLM, backup icons)
- Sign-in/register: `frontend/components/auth/sso-buttons.tsx` (uses ProviderIcon)
- Setup: `frontend/app/(dashboard)/configuration/sso/page.tsx`
- Setup modal: `frontend/components/admin/sso-setup-modal.tsx` (provider-specific setup instructions, redirect URI, links to provider consoles)
- Backend config: `backend/config/sso.php` (name, icon, enabled, color)
- Backend test: `backend/app/Http/Controllers/Api/SSOSettingController.php` (`test()`, `validateCredentialsAtTokenEndpoint()`)

#### AuthDivider

"Or continue with" divider for separating SSO and email auth:

```tsx
import { AuthDivider } from "@/components/auth/auth-divider";

export default function LoginPage() {
  return (
    <>
      <SSOButtons />
      <AuthDivider />
      <EmailForm />
    </>
  );
}
```

**Props:**
- `text?: string` - Divider text (default: "Or continue with email")

**Key file:** `frontend/components/auth/auth-divider.tsx`

#### AuthStateCard

State card for success/error/warning/loading states on auth pages:

```tsx
import { AuthStateCard } from "@/components/auth/auth-state-card";
import { CheckCircle } from "lucide-react";

export default function SuccessPage() {
  return (
    <AuthStateCard
      variant="success"
      icon={CheckCircle}
      title="Email Verified"
      description="Your email has been verified successfully."
      footer={
        <Link href="/dashboard">
          <Button>Go to Dashboard</Button>
        </Link>
      }
    >
      <p>Additional content here</p>
    </AuthStateCard>
  );
}
```

**Props:**
- `variant: "success" | "error" | "warning" | "loading"` - State variant
- `icon?: LucideIcon` - Custom icon (defaults to variant icon)
- `title: string` - Card title
- `description?: string | ReactNode` - Card description
- `children?: ReactNode` - Card content
- `footer?: ReactNode` - Footer actions

**Key file:** `frontend/components/auth/auth-state-card.tsx`

### PWA Install Prompt Pattern

Use the **`useInstallPrompt()`** hook and **`InstallPrompt`** component for PWA install experience: capture `beforeinstallprompt`, show a non-intrusive banner after 2+ visits, and offer "Install App" in settings.

```tsx
import { useInstallPrompt } from "@/lib/use-install-prompt";
import { InstallPrompt } from "@/components/install-prompt";

// In app shell: show banner when criteria met
<InstallPrompt />

// In settings or header: manual install button
const { canPrompt, isInstalled, promptInstall } = useInstallPrompt();
if (canPrompt && !isInstalled) {
  <Button onClick={() => promptInstall()}>Install App</Button>
}
```

- **Hook:** `useInstallPrompt()` returns `deferredPrompt`, `canPrompt`, `isInstalled`, `promptInstall()`, `dismissBanner(dontShowAgain?)`, `shouldShowBanner`. Visit count and dismissal (30-day cooldown) are stored in localStorage.
- **Banner:** `InstallPrompt` renders only when `shouldShowBanner` (2+ visits, not dismissed, install available, not already installed). Dismissible with "Don't show again" option.
- **Integration:** Add `<InstallPrompt />` to `AppShell`; add "Install App" card/section in User Preferences using the hook for the install button.
- **Detection:** `isInstalled` is derived from `display-mode: standalone` media query and `appinstalled` event; `canPrompt` is true when `beforeinstallprompt` has fired and app is not installed.

**Key files:** `frontend/lib/use-install-prompt.ts`, `frontend/components/install-prompt.tsx`, `frontend/components/app-shell.tsx`, `frontend/app/(dashboard)/user/preferences/page.tsx`. See [PWA roadmap](../plans/pwa-roadmap.md) Phase 4.

### Page Component Pattern

```tsx
// frontend/app/(dashboard)/example/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { toast } from 'sonner';
import { Loader2 } from 'lucide-react';

interface Example {
  id: string;
  name: string;
  description: string;
  created_at: string;
}

export default function ExamplePage() {
  const [examples, setExamples] = useState<Example[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchExamples();
  }, []);

  const fetchExamples = async () => {
    try {
      setLoading(true);
      const response = await api.get('/examples');
      setExamples(response.data.data);
      setError(null);
    } catch (err) {
      setError('Failed to load examples');
      toast.error('Failed to load examples');
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] space-y-4">
        <p className="text-destructive">{error}</p>
        <Button onClick={fetchExamples}>Retry</Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Examples</h1>
          <p className="text-muted-foreground">Manage your examples here.</p>
        </div>
        <Button>Create Example</Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {examples.map((example) => (
          <Card key={example.id}>
            <CardHeader>
              <CardTitle>{example.name}</CardTitle>
              <CardDescription>{example.description}</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground">
                Created: {new Date(example.created_at).toLocaleDateString()}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      {examples.length === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <p className="text-muted-foreground mb-4">No examples yet.</p>
            <Button>Create your first example</Button>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
```

### Settings Page Pattern

```tsx
// frontend/app/(dashboard)/settings/example/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { toast } from 'sonner';
import { Loader2 } from 'lucide-react';

interface ExampleSettings {
  enabled: boolean;
  api_key: string;
  webhook_url: string;
}

const defaultSettings: ExampleSettings = {
  enabled: false,
  api_key: '',
  webhook_url: '',
};

export default function ExampleSettingsPage() {
  const [settings, setSettings] = useState<ExampleSettings>(defaultSettings);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      const response = await api.get('/settings/example');
      setSettings({ ...defaultSettings, ...response.data });
    } catch (err) {
      toast.error('Failed to load settings');
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      await api.put('/settings/example', settings);
      toast.success('Settings saved successfully');
    } catch (err) {
      toast.error('Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Example Settings</h1>
        <p className="text-muted-foreground">Configure example integration.</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Configuration</CardTitle>
          <CardDescription>Set up your example integration.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <Label>Enable Integration</Label>
              <p className="text-sm text-muted-foreground">
                Turn on example integration features.
              </p>
            </div>
            <Switch
              checked={settings.enabled}
              onCheckedChange={(checked) =>
                setSettings({ ...settings, enabled: checked })
              }
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="api_key">API Key</Label>
            <Input
              id="api_key"
              type="password"
              value={settings.api_key}
              onChange={(e) =>
                setSettings({ ...settings, api_key: e.target.value })
              }
              placeholder="Enter your API key"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="webhook_url">Webhook URL</Label>
            <Input
              id="webhook_url"
              type="url"
              value={settings.webhook_url}
              onChange={(e) =>
                setSettings({ ...settings, webhook_url: e.target.value })
              }
              placeholder="https://example.com/webhook"
            />
          </div>

          <Button onClick={handleSave} disabled={saving}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Save Changes
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
```

### API Utility Pattern

The `api` client uses axios with built-in interceptors for error handling, auth redirects, correlation ID tracking, and offline request queuing.

```typescript
// frontend/lib/api.ts
import axios from "axios";

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL
    ? `${process.env.NEXT_PUBLIC_API_URL}/api`
    : "/api",
  withCredentials: true, // Include cookies for Sanctum
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

// Response interceptor handles:
// - Correlation ID capture from headers
// - 401 redirect to /login
// - 403 with requires_2fa_setup redirect to /user/security
// - Network errors queued for retry when online (mutations only)
```

**Usage:**

```typescript
import { api } from "@/lib/api";

// GET request
const response = await api.get<{ data: Example[] }>("/examples");
const examples = response.data.data;

// POST request
await api.post("/examples", { name: "New Example" });

// PUT request
await api.put(`/examples/${id}`, { name: "Updated" });

// DELETE request
await api.delete(`/examples/${id}`);

// With query params
await api.get("/examples", { params: { page: 2, per_page: 20 } });

// File download (blob)
const response = await api.get(`/backup/download/${filename}`, {
  responseType: "blob",
});
```

**Key features:**
- `withCredentials: true` ensures Sanctum session cookies are sent
- Interceptors automatically redirect to `/login` on 401
- Network errors on mutations (POST/PUT/PATCH/DELETE) are queued for retry when back online
- Correlation ID from response headers is captured for error logging

**Key file:** `frontend/lib/api.ts`

### Form with Validation Pattern

```tsx
// Using react-hook-form + zod
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';

const formSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  email: z.string().email('Invalid email address'),
  type: z.enum(['typeA', 'typeB', 'typeC']),
});

type FormData = z.infer<typeof formSchema>;

export function ExampleForm({ onSubmit }: { onSubmit: (data: FormData) => void }) {
  const form = useForm<FormData>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      name: '',
      email: '',
      type: 'typeA',
    },
  });

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Name</FormLabel>
              <FormControl>
                <Input {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        {/* More fields... */}
        <Button type="submit">Submit</Button>
      </form>
    </Form>
  );
}
```

### Reusable Component Pattern

Components with multiple variants, sizes, and fallback behavior:

```tsx
// frontend/components/example-component.tsx
'use client';

import { useState } from 'react';
import Image from 'next/image';
import { cn } from '@/lib/utils';

// 1. Define typed props interface with JSDoc comments
export interface ExampleComponentProps {
  /** Display variant */
  variant?: 'full' | 'compact' | 'minimal';
  /** Size preset */
  size?: 'sm' | 'md' | 'lg';
  /** Image source (optional - falls back to text) */
  src?: string | null;
  /** Display text */
  label: string;
  /** Additional CSS classes */
  className?: string;
}

// 2. Define size configuration object for consistency
const sizeConfig = {
  sm: {
    container: 'h-6 w-6',
    text: 'text-sm',
    gap: 'gap-1.5',
  },
  md: {
    container: 'h-8 w-8',
    text: 'text-base',
    gap: 'gap-2',
  },
  lg: {
    container: 'h-10 w-10',
    text: 'text-lg',
    gap: 'gap-2.5',
  },
};

// 3. Export the component with sensible defaults
export function ExampleComponent({
  variant = 'full',
  size = 'md',
  src,
  label,
  className,
}: ExampleComponentProps) {
  // 4. Track image load errors for fallback behavior
  const [imageError, setImageError] = useState(false);
  const sizes = sizeConfig[size];
  const hasImage = src && !imageError;

  // 5. Handle different variants
  if (variant === 'minimal') {
    return (
      <span className={cn('font-medium', sizes.text, className)}>
        {label}
      </span>
    );
  }

  // 6. Use cn() to merge conditional classes
  return (
    <div className={cn('flex items-center', sizes.gap, className)}>
      {hasImage ? (
        <div className={cn(sizes.container, 'relative')}>
          <Image
            src={src}
            alt={label}
            fill
            className="object-contain"
            onError={() => setImageError(true)}
          />
        </div>
      ) : (
        // 7. Provide styled fallback when no image
        <div
          className={cn(
            sizes.container,
            'flex items-center justify-center rounded-md',
            'bg-primary text-primary-foreground font-bold'
          )}
        >
          {label.slice(0, 2).toUpperCase()}
        </div>
      )}
      {variant === 'full' && (
        <span className={cn('font-medium', sizes.text)}>{label}</span>
      )}
    </div>
  );
}
```

**Key principles:**
- TypeScript interface with JSDoc for props
- Size configuration object for consistent sizing
- `cn()` utility for conditional class merging
- Image `onError` handler for graceful fallbacks
- Sensible defaults for optional props

**Real example:** See `frontend/components/logo.tsx`

### Charts (shadcn + Recharts)

Use the shadcn chart component (`ChartContainer`, `ChartTooltip`, `ChartTooltipContent`) with Recharts for dashboard and analytics charts. Config-driven colors and tooltips; theme-aware.

- **ChartContainer**: Wrap charts; requires `config` (`ChartConfig`) and `className` with `min-h-[...]` for responsive height. Injects `--color-{key}` CSS variables from config.
- **ChartConfig**: Keys map to data keys; each entry has `label` and `color` (or `theme { light, dark }`). Use `var(--color-{key})` for fills/strokes.
- **ChartTooltip / ChartTooltipContent**: Optional; use `nameKey`, `labelKey` when keys differ from config.
- Prefer `AreaChart`, `BarChart`, `PieChart` + `Pie` (donut via `innerRadius`) from `recharts`. Use `accessibilityLayer` on the chart.

**Key files:** `frontend/components/ui/chart.tsx`, `frontend/components/audit/audit-severity-chart.tsx`, `frontend/components/audit/audit-trends-chart.tsx`. See [Add dashboard widget](recipes/add-dashboard-widget.md) for chart widget variation.

## Error Handling Patterns

### Backend Error Response Format

```php
// Consistent error response structure
return response()->json([
    'message' => 'Human-readable error message.',
    'errors' => [
        'field_name' => ['Specific field error.'],
    ],
], 422);

// Common error responses
return response()->json(['message' => 'Unauthenticated.'], 401);
return response()->json(['message' => 'Forbidden.'], 403);
return response()->json(['message' => 'Not found.'], 404);
return response()->json(['message' => 'Server error.'], 500);

// Using Laravel's abort helper
abort(404, 'Example not found.');
abort(403, 'You do not have permission to access this resource.');
```

### Backend Exception Handling in Services

```php
<?php
// backend/app/Services/Example/ExampleService.php

use Illuminate\Support\Facades\Log;

class ExampleService
{
    public function process(Example $example, array $options = []): ProcessResult
    {
        try {
            $result = $this->doProcessing($example, $options);

            return new ProcessResult(success: true, data: $result);
        } catch (ValidationException $e) {
            // Re-throw validation exceptions (let Laravel handle)
            throw $e;
        } catch (ExternalApiException $e) {
            // Log external failures, return graceful error
            Log::warning('External API failed', [
                'example_id' => $example->id,
                'error' => $e->getMessage(),
            ]);

            return new ProcessResult(
                success: false,
                error: 'External service temporarily unavailable.'
            );
        } catch (\Exception $e) {
            // Log unexpected errors
            Log::error('Unexpected error in ExampleService', [
                'example_id' => $example->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new ProcessResult(
                success: false,
                error: 'An unexpected error occurred.'
            );
        }
    }
}
```

### Frontend Error Handling Pattern

```tsx
// Handling API errors with proper user feedback
const handleSubmit = async (data: FormData) => {
  try {
    setSubmitting(true);
    setError(null);

    await api.post('/examples', data);
    toast.success('Example created successfully');
    router.push('/examples');
  } catch (err: any) {
    // Handle validation errors (422)
    if (err.response?.status === 422 && err.response?.data?.errors) {
      const errors = err.response.data.errors;
      Object.entries(errors).forEach(([field, messages]) => {
        form.setError(field as any, {
          message: (messages as string[])[0],
        });
      });
      toast.error('Please fix the validation errors');
    }
    // Handle authentication errors (401)
    else if (err.response?.status === 401) {
      toast.error('Session expired. Please log in again.');
      router.push('/login');
    }
    // Handle forbidden errors (403)
    else if (err.response?.status === 403) {
      toast.error('You do not have permission to perform this action');
    }
    // Handle not found errors (404)
    else if (err.response?.status === 404) {
      toast.error('The requested resource was not found');
    }
    // Handle all other errors
    else {
      const message = err.response?.data?.message || 'Something went wrong';
      toast.error(message);
      setError(message);
    }
  } finally {
    setSubmitting(false);
  }
};
```

### Frontend Error Boundary Pattern

```tsx
// frontend/components/error-boundary.tsx
'use client';

import { Component, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      return (
        <Card className="max-w-md mx-auto mt-8">
          <CardHeader>
            <CardTitle>Something went wrong</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <p className="text-muted-foreground">
              An unexpected error occurred. Please try again.
            </p>
            <Button onClick={() => this.setState({ hasError: false })}>
              Try Again
            </Button>
          </CardContent>
        </Card>
      );
    }

    return this.props.children;
  }
}
```

### API Response Status Codes

| Code | Meaning | When to Use |
|------|---------|-------------|
| 200 | OK | Successful GET, PUT; DELETE (with message body via `deleteResponse()`) |
| 201 | Created | Successful POST that creates a resource |
| 400 | Bad Request | Malformed request (invalid JSON, etc.) |
| 401 | Unauthorized | Not authenticated (missing/invalid session) |
| 403 | Forbidden | Authenticated but not allowed |
| 404 | Not Found | Resource doesn't exist or user doesn't own it |
| 422 | Unprocessable Entity | Validation failed |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Internal Server Error | Unexpected backend error |

## Responsive & Mobile-First Patterns

### Mobile-First Class Order

Always write base styles for mobile, then add breakpoint modifiers for larger screens:

```tsx
// ✅ CORRECT: Mobile-first (base → larger screens)
<div className="flex flex-col md:flex-row lg:gap-6">
<div className="w-full md:w-1/2 lg:w-1/3">
<div className="p-4 md:p-6 lg:p-8">
<h1 className="text-2xl md:text-3xl lg:text-4xl">

// ❌ WRONG: Desktop-first (breaks mobile)
<div className="flex-row md:flex-col">
<div className="w-1/3 md:w-full">
```

### Responsive Grid Layout

```tsx
// Cards: 1 column on mobile → multi-column on larger screens
<div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
  {items.map(item => (
    <Card key={item.id}>...</Card>
  ))}
</div>

// Form fields: stack on mobile, side-by-side on tablet+
<div className="grid gap-4 md:grid-cols-2">
  <FormField name="firstName" />
  <FormField name="lastName" />
</div>
```

### Mobile Detection Hook

Use the `useIsMobile` hook for conditional rendering:

```tsx
import { useIsMobile } from "@/lib/use-mobile";

export function ResponsiveComponent() {
  const isMobile = useIsMobile();

  // Fundamentally different UIs for mobile vs desktop
  if (isMobile) {
    return <MobileVersion />;
  }
  return <DesktopVersion />;
}
```

**Key file**: `frontend/lib/use-mobile.ts`

### Responsive Navigation Pattern

```tsx
// Mobile: Sheet (drawer) component
// Desktop: Fixed sidebar

import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { useIsMobile } from "@/lib/use-mobile";

export function ResponsiveSidebar({ children }: { children: React.ReactNode }) {
  const isMobile = useIsMobile();
  const [open, setOpen] = useState(false);

  if (isMobile) {
    return (
      <>
        <Button variant="ghost" size="icon" onClick={() => setOpen(true)}>
          <Menu className="h-5 w-5" />
        </Button>
        <Sheet open={open} onOpenChange={setOpen}>
          <SheetContent side="left" className="w-64 p-0">
            <nav onClick={() => setOpen(false)}>{children}</nav>
          </SheetContent>
        </Sheet>
      </>
    );
  }

  return (
    <aside className="hidden md:flex md:w-64 md:flex-col border-r">
      {children}
    </aside>
  );
}
```

### Responsive Table Pattern

```tsx
// Option 1: Horizontal scroll wrapper (simpler)
<div className="overflow-x-auto rounded-md border">
  <Table className="min-w-[600px]">
    {/* table content */}
  </Table>
</div>

// Option 2: Card view on mobile (better UX)
import { useIsMobile } from "@/lib/use-mobile";

export function DataView({ data }: { data: Item[] }) {
  const isMobile = useIsMobile();

  if (isMobile) {
    return (
      <div className="space-y-4">
        {data.map(item => (
          <Card key={item.id}>
            <CardHeader>
              <CardTitle className="text-base">{item.name}</CardTitle>
            </CardHeader>
            <CardContent className="text-sm">
              {/* Mobile-friendly card layout */}
            </CardContent>
          </Card>
        ))}
      </div>
    );
  }

  return <Table>{/* Desktop table view */}</Table>;
}
```

### Touch Target Pattern

Ensure all interactive elements have minimum 44x44px touch targets:

```tsx
// ✅ CORRECT: Adequate touch targets
<Button className="min-h-[44px]">Submit</Button>

<button className="p-3 hover:bg-accent rounded-md">
  <Icon className="h-5 w-5" /> {/* 20px + 24px padding = 44px+ */}
</button>

// Icon button with explicit size
<Button variant="ghost" size="icon" className="h-11 w-11">
  <Settings className="h-5 w-5" />
</Button>

// ❌ WRONG: Too small for touch
<button className="p-1"><Icon className="h-4 w-4" /></button>
```

### Responsive Typography Pattern

```tsx
// Page titles - scale up on larger screens
<h1 className="text-2xl font-bold md:text-3xl lg:text-4xl">Dashboard</h1>

// Section headers
<h2 className="text-xl font-semibold md:text-2xl">Recent Activity</h2>

// Body text
<p className="text-sm md:text-base text-muted-foreground">
  Description text.
</p>
```

### Hide/Show by Breakpoint

```tsx
// Mobile only
<div className="block md:hidden">Mobile content</div>

// Desktop only  
<div className="hidden md:block">Desktop content</div>

// Tablet and up
<div className="hidden sm:block">Tablet+ content</div>
```

### Redirect Pages Pattern

Use redirect pages to maintain backward compatibility when restructuring routes. Common for migrating `/admin/*` or `/settings/*` routes to `/configuration/*`.

```tsx
// frontend/app/(dashboard)/admin/backup/page.tsx
import { redirect } from "next/navigation";

export default function BackupRedirect() {
  redirect("/configuration/backup");
}
```

This pattern is used in 14+ pages to preserve bookmarks and external links when routes are reorganized.

### Test Connection Button Pattern

Use for testing external service connections (backup destinations, storage providers, SSO, notification channels). Track testing state per item to show loading spinner on the correct button.

```tsx
const [testingDestination, setTestingDestination] = useState<string | null>(null);

const handleTestDestination = async (destination: string) => {
  setTestingDestination(destination);
  try {
    await api.post(`/backup-settings/test/${destination}`);
    toast.success("Connection successful");
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : "Connection failed";
    toast.error(msg);
  } finally {
    setTestingDestination(null);
  }
};

// Button for each destination
<Button
  variant="outline"
  onClick={() => handleTestDestination("s3")}
  disabled={!!testingDestination}
>
  {testingDestination === "s3" ? (
    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
  ) : null}
  Test Connection
</Button>
```

**Key points:**
- Track which item is being tested (not just boolean) to show spinner on correct button
- Disable all test buttons while any test is in progress
- Clear testing state in `finally` block

**Used in:** Backup settings, Storage settings, SSO settings, Notification channels, AI providers, Search (Meilisearch).

### File Download (Blob) Pattern

Use for downloading files from API endpoints that return binary data.

```tsx
const handleDownloadBackup = async (filename: string) => {
  try {
    const response = await api.get(`/backup/download/${filename}`, {
      responseType: "blob",
    });

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement("a");
    link.href = url;
    link.setAttribute("download", filename);
    document.body.appendChild(link);
    link.click();
    link.parentNode?.removeChild(link);
    window.URL.revokeObjectURL(url);
  } catch (error: any) {
    toast.error(error.message || "Failed to download");
  }
};
```

**Key points:**
- Use `responseType: "blob"` in axios request
- Create object URL from blob, trigger download via hidden link
- Clean up: remove link and revoke object URL to prevent memory leaks

**Used in:** Backup downloads, Log exports (CSV/JSONL), Access log exports, File manager downloads.

### Error Message Extraction Pattern

Extract error messages from API responses consistently across catch blocks.

```tsx
// In catch blocks
catch (err: unknown) {
  const msg = err instanceof Error ? err.message : null;
  toast.error(msg ?? "Failed to save settings");
}

// For axios errors with response data
catch (error: unknown) {
  const err = error as Error & { response?: { data?: { message?: string } } };
  toast.error(
    err.message || err.response?.data?.message || "Operation failed"
  );
}
```

**Note:** The `api` interceptor in `frontend/lib/api.ts` already extracts `response.data.message` and throws it as an `Error`, so for most cases `err.message` is sufficient.

### useOnline Hook Pattern

Use `useOnline()` for reactive online/offline state in UI.

```tsx
import { useOnline } from "@/lib/use-online";

export function MyComponent() {
  const { isOnline, isOffline } = useOnline();

  // Disable actions when offline
  <Button disabled={isOffline}>Submit</Button>

  // Show offline indicator
  {isOffline && <Badge variant="secondary">Offline</Badge>}
}
```

**Hook implementation:**

```tsx
// frontend/lib/use-online.ts
export function useOnline(): { isOnline: boolean; isOffline: boolean } {
  const [isOnline, setIsOnline] = useState(() =>
    typeof navigator !== "undefined" ? navigator.onLine : true
  );

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, []);

  return { isOnline, isOffline: !isOnline };
}
```

**Used with:** PWA features, notification pages, any page that should indicate offline state.

**Key file:** `frontend/lib/use-online.ts`

### Typed Confirmation Dialog Pattern

For dangerous operations (restore, delete all), require user to type a specific word to confirm.

```tsx
const [restoreTarget, setRestoreTarget] = useState<string | null>(null);
const [restoreConfirmation, setRestoreConfirmation] = useState("");
const [isRestoring, setIsRestoring] = useState(false);

const handleRestore = async () => {
  if (!restoreTarget || restoreConfirmation !== "RESTORE") {
    toast.error('Please type "RESTORE" to confirm');
    return;
  }

  setIsRestoring(true);
  try {
    await api.post(`/backup/restore/${restoreTarget}`);
    toast.success("Restore initiated");
  } catch (error: any) {
    toast.error(error.message || "Restore failed");
  } finally {
    setIsRestoring(false);
    setRestoreTarget(null);
    setRestoreConfirmation("");
  }
};

// In dialog
<Dialog>
  <DialogContent>
    <DialogHeader>
      <DialogTitle>Restore Backup</DialogTitle>
      <DialogDescription>
        This will overwrite all current data. Type <strong>RESTORE</strong> to confirm.
      </DialogDescription>
    </DialogHeader>
    <Input
      value={restoreConfirmation}
      onChange={(e) => setRestoreConfirmation(e.target.value)}
      placeholder="Type RESTORE"
    />
    <DialogFooter>
      <Button
        variant="destructive"
        onClick={handleRestore}
        disabled={isRestoring || restoreConfirmation !== "RESTORE"}
      >
        {isRestoring && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
        Restore
      </Button>
    </DialogFooter>
  </DialogContent>
</Dialog>
```

**Key points:**
- Button stays disabled until exact match (case-sensitive)
- Clear confirmation text when dialog closes
- Use for: backup restore, data deletion, account deletion

**Used in:** Backup restore, dangerous settings operations.

## Security Patterns

### URL Validation (SSRF Protection)

Use `UrlValidationService` for all external URL fetches to prevent SSRF attacks:

```php
use App\Services\UrlValidationService;

class MyController extends Controller
{
    public function __construct(
        private UrlValidationService $urlValidator
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url'],
        ]);

        // Validate URL is not private/internal
        if (!$this->urlValidator->validateUrl($validated['url'])) {
            return response()->json([
                'message' => 'Invalid URL: internal or private addresses are not allowed',
            ], 422);
        }

        // Safe to use the URL
        // ...
    }

    public function fetchContent(string $url): ?string
    {
        // Use fetchContent for safe HTTP requests with redirect validation
        return $this->urlValidator->fetchContent($url, timeout: 10);
    }
}
```

**Use for:** Webhook URLs, OIDC discovery, image URLs in LLM vision queries, any user-provided URL that will be fetched.

### Webhook Signature Verification (Consumer Side)

When receiving webhooks from selfmx, verify the signature:

```php
public function handleWebhook(Request $request): JsonResponse
{
    $secret = config('services.selfmx.webhook_secret');
    $timestamp = $request->header('X-Webhook-Timestamp');
    $signature = $request->header('X-Webhook-Signature');
    $payload = $request->getContent();

    // Verify signature
    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    if (!hash_equals($expected, $signature)) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    // Prevent replay attacks (optional but recommended)
    if (abs(time() - (int) $timestamp) > 300) { // 5 minute window
        return response()->json(['error' => 'Request too old'], 401);
    }

    // Process webhook
    $data = json_decode($payload, true);
    // ...
}
```

### Password Validation

Use `Password::defaults()` for all password fields to enforce the application-wide policy:

```php
use Illuminate\Validation\Rules\Password;

$validated = $request->validate([
    'password' => ['required', 'string', Password::defaults()],
]);
```

The policy is configured in `AppServiceProvider::boot()` and includes: mixed case, numbers, symbols, and compromised password check in production.

### OAuth State Validation

When implementing OAuth flows, always validate state tokens:

```php
// Generate state on redirect
$stateToken = bin2hex(random_bytes(32));
session()->put("oauth_state:{$provider}", $stateToken);
$redirectUrl = $this->buildOAuthUrl($provider, state: $stateToken);

// Validate on callback
$receivedState = $request->input('state');
$expectedState = session()->pull("oauth_state:{$provider}");

if (!$expectedState || !hash_equals($expectedState, $receivedState)) {
    return response()->json(['error' => 'Invalid state'], 400);
}
```

## Naming Conventions Summary

| Type | Pattern | Example |
|------|---------|---------|
| Controller | `{Feature}Controller.php` | `ExampleController.php` |
| Service | `{Feature}Service.php` | `ExampleService.php` |
| Model | `{Singular}.php` | `Example.php` |
| FormRequest | `{Action}{Model}Request.php` | `StoreExampleRequest.php` |
| Resource | `{Model}Resource.php` | `ExampleResource.php` |
| Channel | `{Provider}Channel.php` | `TelegramChannel.php` |
| Provider | `{Provider}Provider.php` | `OpenAIProvider.php` |
| Frontend page | `page.tsx` in route folder | `app/(dashboard)/example/page.tsx` |
| Frontend component | `kebab-case.tsx` | `example-card.tsx` |
| Migration | `{timestamp}_create_{table}_table.php` | `2024_01_01_000000_create_examples_table.php` |
