# Recipe: Add Searchable Model

Step-by-step guide to add a new model to full-text search (Meilisearch/Scout) so it appears in global search (Cmd+K), suggestions, and admin index stats.

## Architecture Overview

Search uses **Laravel Scout** with the Meilisearch driver. Searchable models implement the `Searchable` trait and define `toSearchableArray()` and `searchableAs()`. The **SearchService** centralizes search logic, result transformation, and index stats. The **SearchReindexCommand** and **SearchAdminController** reindex from CLI or admin UI.

```
Model (Searchable) → Scout/Meilisearch
SearchService → globalSearch(), getSuggestions(), getIndexStats()
SearchController → GET /search, GET /search/suggestions
SearchAdminController → GET /admin/search/stats, POST /admin/search/reindex
```

**Important:** `globalSearch()` returns results for **one type at a time**. When called without a `type` filter, it iterates the model list but `break`s after the first matching type (which is `users` by default). To search a specific type, pass the `type` parameter explicitly. This is by design — the frontend Cmd+K modal calls `getSuggestions()` for autocomplete (which merges pages + groups + users) and `globalSearch()` with a `type` filter for full results.

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `backend/app/Models/{Model}.php` | Modify | Add Searchable trait, toSearchableArray(), searchableAs() |
| `backend/app/Console/Commands/SearchReindexCommand.php` | Modify | Add model to `$searchableModels` |
| `backend/app/Services/Search/SearchService.php` | Modify | Add to `$searchableModels`, dedicated search method, type branch in globalSearch(), transform method (with XSS-safe escaping) |
| `backend/app/Http/Controllers/Api/SearchController.php` | Modify | Add new type to `type` validation rule |
| `backend/app/Http/Controllers/Api/Admin/SearchAdminController.php` | Modify | Add new type to `model` validation rule |
| `backend/config/scout.php` | Modify | Meilisearch index settings (filterable/sortable) — **required** for user-scoped models |
| `backend/routes/api.php` | Modify | If the new type needs admin-only access, update route middleware |
| `frontend/components/search/search-result-icon.tsx` | Modify | Add icon for new result type |
| `frontend/app/(dashboard)/configuration/search/page.tsx` | Modify | No change if stats are driven by SearchService (auto) |

## Step 1: Add Searchable Trait to Model

In your Eloquent model, use the Scout `Searchable` trait and implement the required methods.

```php
<?php
// backend/app/Models/Example.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Example extends Model
{
    use Searchable;

    /**
     * Get the indexable data array for the model (Scout/Meilisearch).
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'user_id' => $this->user_id, // include if user-scoped
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'examples';
    }
}
```

- **Index name**: Use a plural, lowercase name (e.g. `examples`, `users`). It becomes the Meilisearch index name (with optional `SCOUT_PREFIX`).
- **toSearchableArray()**: Include only fields that should be searchable and returned in results. Avoid sensitive data. Include `user_id` if the model is user-scoped (needed for Meilisearch filtering).

## Step 2: Add to SearchReindexCommand

Register the model in the reindex command so `php artisan search:reindex` and `php artisan search:reindex examples` work.

```php
// backend/app/Console/Commands/SearchReindexCommand.php

protected static array $searchableModels = [
    'users' => User::class,
    'user_groups' => UserGroup::class,
    // ... existing models ...
    'examples' => Example::class,  // add this
];
```

Use the same key as `searchableAs()` (without prefix): e.g. `examples` for index name `examples`.

## Scoping (user-scoped vs admin-only)

- **User-scoped models** (e.g. Notification, ApiToken, AIProvider): Include `user_id` in `toSearchableArray()` **and** in `filterableAttributes` in `scout.php` (see Step 5). In the search method, when `$scopeUserId !== null` (non-admin), call `$builder->where('user_id', $scopeUserId)`. In `globalSearch()`, pass `$scopeUserId` to the search method so non-admins only see their own records.
- **Admin-only models** (e.g. EmailTemplate, Webhook): In `globalSearch()`, when `$scopeUserId !== null` (non-admin), set `$total = 0` and `$allResults = []` for that type so non-admins get no results. Do not pass `$scopeUserId` to the search method.

## Step 3: Add to SearchService

Update **SearchService** in four places: model map, dedicated search method, globalSearch type branch, and result transformation.

**3a. Add to `$searchableModels`:**

Append your model to the existing `$searchableModels` array (do not replace the array):

```php
// backend/app/Services/Search/SearchService.php

protected static array $searchableModels = [
    'users' => User::class,
    'user_groups' => UserGroup::class,
    // ... existing models ...
    'examples' => Example::class,  // add this
];
```

**3b. Add a dedicated search method:**

Every searchable model needs a dedicated search method that handles: empty query fallback, Meilisearch search (with user scoping if applicable), and database `LIKE` fallback when Meilisearch is unavailable. This follows the established pattern used by `searchUsers()`, `searchNotifications()`, `searchEmailTemplates()`, etc.

For a **user-scoped** model:

```php
/**
 * Search examples (user-scoped when scopeUserId set).
 */
public function searchExamples(string $query, ?int $perPage = null, int $page = 1, ?int $scopeUserId = null): LengthAwarePaginator
{
    $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
    $page = (int) $page;
    $query = trim($query);

    if ($query === '') {
        $q = Example::query()->orderBy('title');
        if ($scopeUserId !== null) {
            $q->where('user_id', $scopeUserId);
        }
        return $q->paginate($perPage, ['*'], 'page', $page);
    }

    try {
        $builder = Example::search($query);
        if ($scopeUserId !== null) {
            $builder->where('user_id', $scopeUserId);
        }
        return $builder->paginate($perPage, 'page', $page);
    } catch (\Exception $e) {
        Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
        $q = Example::query()->where(function ($qb) use ($query) {
            $qb->where('title', 'like', "%{$query}%")->orWhere('body', 'like', "%{$query}%");
        });
        if ($scopeUserId !== null) {
            $q->where('user_id', $scopeUserId);
        }
        return $q->orderBy('title')->paginate($perPage, ['*'], 'page', $page);
    }
}
```

For an **admin-only** model (no `$scopeUserId` parameter):

```php
/**
 * Search examples (admin only).
 */
public function searchExamples(string $query, ?int $perPage = null, int $page = 1): LengthAwarePaginator
{
    $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
    $page = (int) $page;
    $query = trim($query);

    if ($query === '') {
        return Example::query()->orderBy('title')->paginate($perPage, ['*'], 'page', $page);
    }

    try {
        return Example::search($query)->paginate($perPage, 'page', $page);
    } catch (\Exception $e) {
        Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
        return Example::where('title', 'like', "%{$query}%")
            ->orWhere('body', 'like', "%{$query}%")
            ->orderBy('title')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
```

**3c. Add type branch in `globalSearch()`:**

In the `foreach ($types as $modelType)` loop, add an `if` block for the new type. Note that each branch ends with `break;` — `globalSearch()` returns results for **one type at a time**:

For a **user-scoped** model:

```php
if ($modelType === 'examples') {
    $paginator = $this->searchExamples($query ?: ' ', $perPage, $page, $scopeUserId);
    foreach ($paginator->items() as $example) {
        $allResults[] = $this->transformExampleToResult($example, $query);
    }
    $total = $paginator->total();
    break;
}
```

For an **admin-only** model:

```php
if ($modelType === 'examples') {
    if ($scopeUserId !== null) {
        $total = 0;
    } else {
        $paginator = $this->searchExamples($query ?: ' ', $perPage, $page);
        foreach ($paginator->items() as $example) {
            $allResults[] = $this->transformExampleToResult($example, $query);
        }
        $total = $paginator->total();
    }
    break;
}
```

**3d. Add transform method and URL:**

Create a method that returns the unified result shape. **Escape all user-provided text** (title, subtitle) with `htmlspecialchars()` before returning, so the API response is safe to render with `dangerouslySetInnerHTML` for highlights. Use `highlightMatch()` which escapes text before wrapping the query in `<mark>`.

```php
protected function transformExampleToResult(Example $example, string $query): array
{
    $title = $example->title;
    $subtitle = $example->body ? Str::limit($example->body, 60) : null;
    $highlight = null;
    if ($query !== '') {
        $highlight = [
            'title' => $this->highlightMatch($title, $query),
            'subtitle' => $subtitle ? $this->highlightMatch($subtitle, $query) : null,
        ];
    }
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSubtitle = $subtitle !== null ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
    return [
        'id' => $example->id,
        'type' => 'example',
        'title' => $safeTitle,
        'subtitle' => $safeSubtitle,
        'url' => '/examples/' . $example->id,
        'highlight' => $highlight,
    ];
}
```

## Step 4: Update Validation Rules

Add the new type to the validation rules in both controllers so type-filtered search and admin reindex accept the new model. **If you skip this, searching by `?type=examples` will return a 422 error.**

**SearchController** (`backend/app/Http/Controllers/Api/SearchController.php`):

```php
// In the search() method validation:
'type' => ['nullable', 'string', 'in:users,user_groups,notifications,email_templates,notification_templates,api_tokens,ai_providers,webhooks,examples'],
```

**SearchAdminController** (`backend/app/Http/Controllers/Api/Admin/SearchAdminController.php`):

```php
// In the reindex() method validation:
'model' => ['nullable', 'string', 'in:pages,users,user_groups,notifications,email_templates,notification_templates,api_tokens,ai_providers,webhooks,examples'],
```

## Step 5: Meilisearch Index Settings

In `backend/config/scout.php`, under `meilisearch.index-settings`, add filterable and sortable attributes for the new index.

**This is required for user-scoped models** — without `user_id` in `filterableAttributes`, the `$builder->where('user_id', $scopeUserId)` calls in the search method will not work in Meilisearch and search will silently return unscoped results.

For a **user-scoped** model:

```php
'meilisearch' => [
    'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
    'key' => env('MEILISEARCH_KEY'),
    'index-settings' => [
        // ... existing index settings ...
        'examples' => [
            'filterableAttributes' => ['user_id'],  // required for user scoping
            'sortableAttributes' => ['created_at'],
        ],
    ],
],
```

For an **admin-only** model (no `user_id` needed):

```php
'examples' => [
    'filterableAttributes' => ['id'],
    'sortableAttributes' => ['created_at'],
],
```

After updating, apply the settings by running `php artisan scout:sync-index-settings` (if available) or via the Meilisearch dashboard.

## Step 6: Add Result Icon (Frontend)

In `frontend/components/search/search-result-icon.tsx`, add an entry to the `TYPE_ICONS` map for the new result type. The file is a `"use client"` component that imports icons from `lucide-react`. Add your icon to the existing import and add a new entry to `TYPE_ICONS` using the same `type` string as in the backend transform method.

```tsx
// frontend/components/search/search-result-icon.tsx

// 1. Add your icon (e.g. BookOpen) to the existing import line:
import { User, Bell, Mail, Key, Bot, Webhook, FileText, Users, MessageSquareText, BookOpen, type LucideIcon } from "lucide-react";
//                                                                                   ^^^^^^^^ add this

// 2. Add the entry to the existing TYPE_ICONS map:
const TYPE_ICONS: Record<string, LucideIcon> = {
  // ... existing entries ...
  example: BookOpen,  // add this
};
```

## Step 7: Run Initial Index

After adding the model, index existing records:

```bash
# All indexes
php artisan search:reindex

# Only the new model
php artisan search:reindex examples
```

Or use **Configuration > Search** in the admin UI and click **Reindex** for the new model or **Reindex all**.

## Step 8 (Optional): Add to Suggestions

The `getSuggestions()` method powers the Cmd+K autocomplete. By default it searches pages, user groups (admin only), and users. If you want your new model to appear in autocomplete suggestions, add a search call in `getSuggestions()`:

```php
// In SearchService::getSuggestions(), after the existing search blocks:

// Search examples (optional — add if you want autocomplete results for this type)
$exampleLimit = max(2, $limit - count($results));
if ($exampleLimit > 0) {
    $exampleResults = $this->globalSearch($query, 'examples', [], 1, $exampleLimit, $scopeUserId);
    $results = array_merge($results, $exampleResults['data']);
}
```

For admin-only models, wrap in an `if ($isAdmin)` check (similar to the user groups block).

## Checklist

### Backend

- [ ] Model uses `Searchable` trait and implements `toSearchableArray()` and `searchableAs()`
- [ ] Model includes `user_id` in `toSearchableArray()` if user-scoped
- [ ] SearchReindexCommand: model added to `$searchableModels`
- [ ] SearchService: model added to `$searchableModels`
- [ ] SearchService: dedicated search method added (e.g. `searchExamples()`) with empty-query fallback, Meilisearch search, and database `LIKE` fallback
- [ ] SearchService: type branch in `globalSearch()` (with `break;`) calling the search method and transform
- [ ] SearchService: transform method with **HTML-escaped** title/subtitle (and `highlightMatch()` for highlights)
- [ ] SearchController: new type added to `type` validation rule
- [ ] SearchAdminController: new type added to `model` validation rule for reindex
- [ ] scout.php: index-settings added — **required** for user-scoped models (`user_id` in `filterableAttributes`)
- [ ] Run `search:reindex` (or reindex from admin UI)

### Frontend

- [ ] search-result-icon.tsx: icon added for the new result type (matching the backend `type` string)

### Optional

- [ ] SearchService: added to `getSuggestions()` if model should appear in Cmd+K autocomplete
- [ ] Help article added/updated for the new searchable feature

### Testing

- [ ] Unit/feature test: model is searchable and reindex runs
- [ ] Manual: global search (Cmd+K) returns the new type with `?type=examples`; admin Search page shows stats and reindex works
- [ ] Manual: verify user scoping works (non-admin only sees own records, or no results for admin-only models)

## Compliance

- **XSS:** All `title` and `subtitle` (and any user-provided text in results) must be escaped with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` before returning from the API. Use `highlightMatch()` for highlight text (it escapes before wrapping the query in `<mark>`). The frontend renders highlights with `dangerouslySetInnerHTML`.

## Example: UserGroup (Admin-Only)

UserGroup is an admin-only searchable model. Non-admins do not see group results.

- **Model:** Add `Searchable` trait; `toSearchableArray()` returns `id`, `name`, `slug`, `description`; `searchableAs()` returns `'user_groups'`.
- **SearchService:** Add `user_groups` to `$searchableModels`. Has a `searchUserGroups($query, $limit)` method that returns `array` (not `LengthAwarePaginator`) using `->take($limit)->get()` — this is a simpler pattern used for small result sets in suggestions. In `globalSearch()`, the `user_groups` branch calls `UserGroup::search($query)->paginate(...)` directly (rather than calling the dedicated method) because `globalSearch()` needs paginated results while `searchUserGroups()` is optimized for suggestions. For new models, prefer the standard `LengthAwarePaginator` search method pattern shown in Step 3b above.
- **getSuggestions:** For admin users, call `searchUserGroups($query, $limit)` and merge page + group + user results. Non-admins skip groups.
- **Validation:** `user_groups` is in both SearchController's `type` rule and SearchAdminController's `model` rule.
- **URL:** Results link to `/configuration/groups?highlight={id}`.
- **Icon:** Add `group: Users` in `search-result-icon.tsx`.

See `backend/app/Models/UserGroup.php` and `SearchService::searchUserGroups()` for the implementation.

## Related Documentation

- [SearchService Pattern](../patterns/search-service.md)
- [Add Searchable Page](add-searchable-page.md) — For static navigation pages (not Eloquent models)
- [Meilisearch Integration Roadmap](../../plans/meilisearch-integration-roadmap.md)
- [Laravel Scout](https://laravel.com/docs/scout)
- [Meilisearch](https://www.meilisearch.com/docs)
