# Add a Searchable Page

Add a new page to the global search (Cmd+K) with content-aware indexing.

## When to Use

When adding a new page to the application that users should be able to discover via search.

## Steps

### 1. Add Page to Config

Edit `backend/config/search-pages.php`:

```php
[
    'id' => 'config-my-feature',
    'title' => 'Configuration > My Feature',
    'subtitle' => 'Configure my feature settings',
    'url' => '/configuration/my-feature',
    'admin_only' => true, // false for user-accessible pages
    'content' => implode(' ', [
        // Provider names users might search for
        'ProviderA ProviderB',
        // Feature keywords
        'setting option configuration',
        // Technology terms
        'API integration webhook',
    ]),
],
```

### 2. Sync the Index

```bash
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan search:reindex pages"
```

### 3. Test the Search

1. Open the app (http://localhost:8080)
2. Press Cmd+K (or Ctrl+K)
3. Search for keywords from your content
4. Verify the page appears in results

## Content Guidelines

- **Include provider/service names**: AWS, Google, OpenAI, etc.
- **Include feature keywords**: What the page configures
- **Include synonyms**: Different terms users might search for
- **Keep content concise**: ~50-100 words per page

## Important: Dual Registration

Pages must be registered in **two places** to appear correctly in search:

1. **Backend** (`backend/config/search-pages.php`) — for Meilisearch-backed API search
2. **Frontend** (`frontend/lib/search-pages.ts` or the relevant frontend search config) — for instant client-side filtering before API results load

If only one is updated, search results will be inconsistent. Always update both when adding or removing a page.

## Checklist

- [ ] Added page to `config/search-pages.php`
- [ ] Added page to frontend search pages config
- [ ] Set `admin_only` correctly
- [ ] Added relevant content keywords
- [ ] Ran `search:reindex pages`
- [ ] Tested search finds the page (both instant and API results)
