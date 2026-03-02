# SearchService Pattern

Use `SearchService` for full-text search with Meilisearch/Scout. The service centralizes search logic, result transformation (title, subtitle, url, highlight), and index stats. When Meilisearch is unavailable, user search falls back to database `LIKE` queries.

## Usage

- **searchUsers(query, perPage?, page)** — User search with pagination (Scout uses 3 args: perPage, pageName, page).
- **globalSearch(query, type?, filters?, page?, perPage?, scopeUserId?)** — Multi-model search; returns unified `{ data, meta }`. Pass `scopeUserId` for non-admin to scope user results to that user only.
- **getSuggestions(query, limit?, scopeUserId?)** — Fast autocomplete; returns pages, user groups (admin only), and users in one list.
- **syncPagesToIndex()** — Sync static pages from `config/search-pages.php` to the Meilisearch `pages` index.
- **searchPages(query, isAdmin, limit)** — Search the pages index (filter by `admin_only` when not admin).
- **searchUserGroups(query, limit)** — Search user groups (admin only); used in getSuggestions.
- **getPagesIndexStats()** — Document count for the pages index.
- **getIndexStats()** — Document counts per index (includes pages and all searchable models).
- **reindexAll()** / **reindexModel(model)** — Reindex all or a single searchable model. Use `search:reindex pages` to sync pages.

## XSS Safety

Transform methods must escape all user-provided text (title, subtitle) with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` before returning. Use `highlightMatch()` for highlight text (it escapes then wraps the query in `<mark>`); the frontend renders highlights with `dangerouslySetInnerHTML`.

## Adding a New Searchable Model

Add the model to `SearchService::$searchableModels` and `SearchReindexCommand::$searchableModels`, implement a type branch and transform method (with escaping) in SearchService, and add the result type icon in `frontend/components/search/search-result-icon.tsx`.

## Page Search

Pages are indexed in Meilisearch with content keywords for discoverability. The `pages` index includes titles, subtitles, URLs, and rich content describing what each page contains. Use `searchPages(query, isAdmin, limit)` to search. Pages are defined in `backend/config/search-pages.php`.

## UserGroup Search

User groups are searchable by name, slug, and description. Admin-only — non-admin users do not see group results. Use `searchUserGroups(query, limit)` or include in `globalSearch()` with `type=user_groups`.

**Key files:** `backend/app/Services/Search/SearchService.php`, `backend/config/search-pages.php`, `backend/app/Http/Controllers/Api/SearchController.php`, `backend/app/Http/Controllers/Api/Admin/SearchAdminController.php`, `backend/app/Console/Commands/SearchReindexCommand.php`, `backend/routes/api.php`, `frontend/lib/search.ts`, `frontend/components/search/search-modal.tsx`, `frontend/components/search/search-provider.tsx`.

**Related:** [Recipe: Add searchable model](../recipes/add-searchable-model.md), [Recipe: Add searchable page](../recipes/add-searchable-page.md).
