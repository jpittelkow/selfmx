# Recipe: Setup Identity & Branding (Tier 1)

Apply the user's app name, short name, description, fonts, and brand color across the entire codebase. This is the most comprehensive tier — it renames every "selfmx" reference to the new app name.

**When to use:** After collecting Tier 1 answers from the "Get cooking" wizard. Can also be run standalone if someone just wants to rebrand.

**Context to read first:**
```
.env.example
backend/.env.example
frontend/config/app.ts
frontend/config/fonts.ts
frontend/app/layout.tsx
```

**Inputs needed:**
- `APP_NAME` — Full app name (e.g., "Acme Dashboard")
- `APP_SHORT_NAME` — 1-3 character abbreviation (e.g., "AD")
- `APP_DESCRIPTION` — One-line description
- `APP_COLOR` — Primary brand color hex (e.g., "#3b82f6")
- `FONT_CHOICE` — Font pairing selection (or "keep defaults")
- `APP_SLUG` — Lowercase kebab-case of app name (e.g., "acme-dashboard") — derive automatically

---

## Step 1: Environment Files

Update both `.env.example` files with the new app identity.

### Root `.env.example`

```env
# <APP_NAME> Docker Configuration
NEXT_PUBLIC_APP_NAME=<APP_NAME>
NEXT_PUBLIC_APP_SHORT_NAME=<APP_SHORT_NAME>
CONTAINER_NAME=<APP_SLUG>-dev
```

### `backend/.env.example`

```env
APP_NAME=<APP_NAME>
```

Also update commented database names:
```env
# DB_DATABASE=<APP_SLUG>
```

And mail from name:
```env
MAIL_FROM_NAME="${APP_NAME}"
```

---

## Step 2: Frontend Config Files

### `frontend/config/app.ts`

Update the short name fallback:
```typescript
shortName: process.env.NEXT_PUBLIC_APP_SHORT_NAME || '<APP_SHORT_NAME>',
```

### `frontend/app/layout.tsx`

1. Update metadata description:
```typescript
description: "<APP_DESCRIPTION>",
```

2. Update viewport theme color:
```typescript
themeColor: "<APP_COLOR>",
```

3. **Rename localStorage theme key** — Replace `selfmx-theme` with `<APP_SLUG>-theme`:
```typescript
var key = '<APP_SLUG>-theme';
```

### `frontend/components/theme-provider.tsx`

Update the default `storageKey` parameter (currently `selfmx-theme`):
```typescript
storageKey = "<APP_SLUG>-theme"
```

### `frontend/components/providers.tsx`

Update the storageKey prop (currently `selfmx-theme`):
```typescript
storageKey="<APP_SLUG>-theme"
```

### `frontend/components/sidebar.tsx`

Update the fallback name:
```typescript
appName || "<APP_NAME>"
```

### `frontend/components/about-dialog.tsx`

Update the fallback name:
```typescript
appName || "<APP_NAME>"
```

### `frontend/components/install-prompt.tsx`

Update the fallback constant:
```typescript
const APP_NAME_FALLBACK = "<APP_NAME>"
```

### `frontend/app/(dashboard)/configuration/layout.tsx`

Update the fallback name:
```typescript
appName || "<APP_NAME>"
```

### `frontend/app/(dashboard)/configuration/notifications/page.tsx`

Update placeholder text (Discord and Slack bot name inputs):
```typescript
placeholder="<APP_NAME>"
```

### `frontend/app/(dashboard)/configuration/system/page.tsx`

Update placeholder text (app name input):
```typescript
placeholder="<APP_NAME>"
```

### `frontend/next.config.js`

Update the env fallback:
```javascript
NEXT_PUBLIC_APP_NAME: process.env.NEXT_PUBLIC_APP_NAME || '<APP_NAME>'
```

### `frontend/public/manifest.json`

Update PWA manifest:
```json
{
  "name": "<APP_NAME>",
  "short_name": "<APP_NAME>"
}
```

### `frontend/app/api/manifest/route.ts`

Update manifest fallbacks (search for "selfmx" and replace with `<APP_NAME>`).

### `frontend/lib/request-queue.ts`

Update IndexedDB database name:
```typescript
const DB_NAME = "<APP_SLUG>-request-queue"
```

### `frontend/public/offline.html`

1. Update the page title:
```html
<title>Offline - <APP_NAME></title>
```

2. Update the icon letter (currently hardcoded `S`) to the first character of `APP_SHORT_NAME`:
```html
<div class="icon">A</div>
```
(Where `A` is the first letter of the short name, e.g., for "AD" use "A")

### `frontend/public/sw.js`

Update the file header comment:
```javascript
/**
 * <APP_NAME> PWA Service Worker
```

### `frontend/lib/help/help-content.ts`

Update welcome text — replace "selfmx" with `<APP_NAME>` in all occurrences.

### `frontend/package.json`

Update the `name` field:
```json
"name": "<APP_SLUG>-frontend"
```

---

## Step 3: Backend Config Files

### `backend/config/app.php`

Update name fallback:
```php
'name' => env('APP_NAME', '<APP_NAME>'),
```

### `backend/config/database.php`

Update all database name fallbacks (SQLite, MySQL, PostgreSQL sections):
```php
env('DB_DATABASE', '<APP_SLUG>')
```

Update Redis prefix:
```php
env('APP_NAME', '<APP_SLUG>')
```

### `backend/config/cache.php`

Update cache prefix fallback:
```php
env('APP_NAME', '<APP_SLUG>')
```

### `backend/config/session.php`

Update session cookie name fallback:
```php
env('APP_NAME', '<APP_SLUG>')
```

### `backend/config/mail.php`

Update from name fallback:
```php
env('MAIL_FROM_NAME', '<APP_NAME>')
```

### `backend/config/settings-schema.php`

Update all default values that reference "selfmx":
- `app_name` default → `<APP_NAME>`
- Mail from-name default → `<APP_NAME>`
- Discord bot name default → `<APP_NAME>`
- Slack bot name default → `<APP_NAME>`

### `backend/config/notifications.php`

Update bot name fallbacks:
```php
env('DISCORD_BOT_NAME', '<APP_NAME>')
env('SLACK_BOT_NAME', '<APP_NAME>')
```

### `backend/config/version.php`

Update or remove the GitHub repo URL for version checks. If the user provided a repo URL, update it. Otherwise, comment it out:
```php
// 'https://api.github.com/repos/<username>/<repo>/releases/latest'
```

### `backend/config/search-pages.php`

Update help page title:
```php
'Help: Welcome to <APP_NAME>'
```

### `backend/config/backup.php`

Update file header comment:
```php
/**
 * <APP_NAME> Backup Configuration
```

### `backend/config/llm.php`

Update file header comment:
```php
/**
 * <APP_NAME> LLM Configuration
```

### `backend/config/sso.php`

Update file header comment:
```php
/**
 * <APP_NAME> SSO Configuration
```

### `backend/composer.json`

Update name and description:
```json
{
  "name": "<APP_SLUG>/backend",
  "description": "<APP_NAME> Backend - Laravel API"
}
```

---

## Step 4: Backend Application Files

### `backend/app/Models/SystemSetting.php`

Update hardcoded default app name:
```php
$settings['general']['app_name'] = '<APP_NAME>'
```

### `backend/app/Services/Backup/BackupService.php`

Update backup filename prefix:
```php
"<APP_SLUG>-backup-{$timestamp}.zip"
```

### `backend/app/Http/Controllers/Api/BackupController.php`

Update filename validation regex to match the new prefix:
```php
'/^<APP_SLUG>-backup-\d{4}.../'
```

### `backend/app/Http/Controllers/Api/WebhookController.php`

Update User-Agent header:
```php
'User-Agent' => '<APP_NAME>-Webhook/1.0'
```

### `backend/app/Services/StorageService.php`

Update storage test file prefix:
```php
'.<APP_SLUG>-storage-test-'
```

### `backend/app/Services/Notifications/Channels/MatrixChannel.php`

Update transaction ID prefix:
```php
uniqid('<APP_SLUG>_', true)
```

### `backend/app/Services/Notifications/NotificationOrchestrator.php`

Update test notification message:
```php
'This is a test notification from <APP_NAME>.'
```

### `backend/routes/web.php`

Update the file header comment:
```php
| <APP_NAME> uses a separate Next.js frontend, so web routes are minimal.
```

### Notification Channel Fallbacks (~15 files)

Search for `config('app.name', 'selfmx')` across all files in `backend/app/Services/Notifications/Channels/` and replace with:
```php
config('app.name', '<APP_NAME>')
```

**Note:** Not all channel files will have this fallback — only those that format notification text. Check each file.

Files to check:
- `DatabaseChannel.php`
- `DiscordChannel.php` (also has bot username fallback)
- `EmailChannel.php`
- `FCMChannel.php`
- `NtfyChannel.php`
- `SignalChannel.php`
- `SlackChannel.php` (also has bot username fallback)
- `SNSChannel.php`
- `TelegramChannel.php`
- `TwilioChannel.php`
- `VonageChannel.php` (also has from name fallback)
- `WebPushChannel.php`

### Seeders

Update fallbacks in:
- `backend/database/seeders/NotificationTemplateSeeder.php`
- `backend/database/seeders/EmailTemplateSeeder.php`

### Template Controllers

Update fallbacks in:
- `backend/app/Http/Controllers/Api/EmailTemplateController.php`
- `backend/app/Http/Controllers/Api/NotificationTemplateController.php`

---

## Step 5: Docker Files

### `docker-compose.yml`

1. Update header comment: `# <APP_NAME> Development Docker Compose`
2. Update container name default: `${CONTAINER_NAME:-<APP_SLUG>-dev}`
3. **Rename all volume references** — Replace `selfmx_data`, `selfmx_storage` with `<APP_SLUG>_data`, `<APP_SLUG>_storage` (both in service mounts and volume definitions)

### `docker-compose.prod.yml`

1. Update header comment: `# <APP_NAME> Production Docker Compose`
2. Update image reference: `ghcr.io/<username>/<APP_SLUG>:latest` (or comment out if no registry yet)
3. Update container name default: `${CONTAINER_NAME:-<APP_SLUG>}`
4. **Rename all volume references** — Replace `selfmx_data`, `selfmx_storage`, `selfmx_backups` with `<APP_SLUG>_data`, `<APP_SLUG>_storage`, `<APP_SLUG>_backups`

### `docker/Dockerfile`

Update LABEL lines:
```dockerfile
LABEL maintainer="<APP_NAME>"
LABEL description="<APP_NAME> - <APP_DESCRIPTION>"
```

### `docker/entrypoint.sh`

Update startup banner text — replace "selfmx" references with `<APP_NAME>` (the `${APP_NAME:-selfmx}` fallbacks and any hardcoded mentions).

### `.github/workflows/ci.yml`

Update Docker image tag:
```yaml
tags: <APP_SLUG>:test
```

---

## Step 6: Fonts

If the user chose non-default fonts, update `frontend/config/fonts.ts`:

1. Change the import to the chosen fonts from `next/font/google` (exception: Geist fonts — see note below)
2. Update `bodyFont` and `headingFont` with the new font constructors
3. Adjust `weight` arrays as needed (check Google Fonts for available weights)

**Important:** The CSS variable names (`--font-body`, `--font-heading`) must stay the same. Only change the font constructor and import.

### Font Pairing Reference

| Style | Body Import | Heading Import | Heading Weights |
|-------|------------|----------------|-----------------|
| Clean & modern (default) | `Inter` | `Newsreader` | `["400", "500", "600"]` |
| Geometric & modern | `DM_Sans` | `DM_Serif_Display` | `["400"]` |
| Friendly & warm | `Plus_Jakarta_Sans` | `Lora` | `["400", "500", "600", "700"]` |
| Techy / developer | `Geist_Sans` (from `geist/font/sans`) | `Geist_Mono` (from `geist/font/mono`) | N/A (single weight) |
| Corporate & classic | `Source_Sans_3` | `Playfair_Display` | `["400", "500", "600", "700"]` |
| Creative & bold | `Poppins` | `Abril_Fatface` | `["400"]` |
| Soft & readable | `Nunito` | `Merriweather` | `["400", "700"]` |

**Note for Geist fonts:** These are not from `next/font/google` — they come from the `geist` npm package. If selected, you **must** install the package first: `npm install geist` (inside the `frontend/` directory), then import from `geist/font/sans` and `geist/font/mono` instead of `next/font/google`.

---

## Step 7: Brand Color

Update the viewport theme color in `frontend/app/layout.tsx`:
```typescript
export const viewport: Viewport = {
  themeColor: "<APP_COLOR>",
};
```

Note: Runtime brand color customization is handled via Configuration > Branding after first boot. No other code changes needed for colors.

---

## Step 8: Documentation Reset

1. **`VERSION`** — Reset to `0.1.0`
2. **`CHANGELOG.md`** — Clear history or start with: `# Changelog\n\n## 0.1.0\n\n- Initial project setup based on selfmx`
3. **`README.md`** — Rewrite header with new app name and description
4. **`CLAUDE.md`** — Update project name (first line: `# <APP_NAME>`)
5. **`docs/overview.md`** — Update project description and feature list
6. **`docs/api/openapi.yaml`** — Update API title, description, and contact name:
```yaml
info:
  title: <APP_NAME> API
  description: |
    <APP_DESCRIPTION>
  contact:
    name: <APP_NAME> Support
```

---

## Checklist

- [ ] All `.env.example` files updated
- [ ] Frontend config files updated (app.ts, layout.tsx, fonts.ts)
- [ ] Theme localStorage key renamed in all 3 files (layout.tsx, theme-provider.tsx, providers.tsx)
- [ ] All frontend fallback names updated (sidebar, about-dialog, install-prompt, config layout, notification page, system page)
- [ ] PWA manifest and dynamic manifest route updated
- [ ] Offline page updated (offline.html title and icon letter)
- [ ] Service worker header comment updated (sw.js)
- [ ] IndexedDB name updated (request-queue.ts)
- [ ] Help content updated
- [ ] Package names updated (package.json, composer.json)
- [ ] All backend config fallbacks updated (app, database, cache, session, mail, settings-schema, notifications, version, search-pages)
- [ ] All backend config header comments updated (backup, llm, sso)
- [ ] All backend application references updated (SystemSetting, BackupService, BackupController, WebhookController, StorageService, MatrixChannel, NotificationOrchestrator)
- [ ] Backend routes/web.php header comment updated
- [ ] All notification channel fallbacks updated (~15 files)
- [ ] Seeders and template controllers updated
- [ ] Docker files updated (compose dev, compose prod, Dockerfile, entrypoint.sh)
- [ ] CI workflow image tag updated
- [ ] Fonts updated (if non-default)
- [ ] Brand color set in viewport
- [ ] Documentation reset (VERSION, CHANGELOG, README, CLAUDE.md, overview, openapi.yaml)

---

## Files Modified by This Recipe

| Category | Files | What Changes |
|----------|-------|-------------|
| Environment | `.env.example`, `backend/.env.example` | App name, short name, container name, DB name |
| Frontend config | `frontend/config/app.ts`, `frontend/config/fonts.ts` | Short name fallback, font imports |
| Frontend layout | `frontend/app/layout.tsx` | Description, theme color, theme storage key |
| Frontend theme | `frontend/components/theme-provider.tsx`, `frontend/components/providers.tsx` | Storage key |
| Frontend components | `sidebar.tsx`, `about-dialog.tsx`, `install-prompt.tsx` | Fallback names |
| Frontend pages | `configuration/layout.tsx`, `configuration/notifications/page.tsx`, `configuration/system/page.tsx` | Fallback names, placeholders |
| Frontend PWA | `public/manifest.json`, `app/api/manifest/route.ts`, `lib/request-queue.ts`, `public/offline.html`, `public/sw.js` | App name, IndexedDB name, offline page title, SW comment |
| Frontend misc | `next.config.js`, `lib/help/help-content.ts`, `package.json` | Fallbacks, welcome text, package name |
| Backend config | `config/app.php`, `database.php`, `cache.php`, `session.php`, `mail.php`, `settings-schema.php`, `notifications.php`, `version.php`, `search-pages.php`, `backup.php`, `llm.php`, `sso.php` | All fallback strings, header comments |
| Backend app | `SystemSetting.php`, `BackupService.php`, `BackupController.php`, `WebhookController.php`, `StorageService.php`, `MatrixChannel.php`, `NotificationOrchestrator.php`, `routes/web.php` | Hardcoded names, prefixes, regex, comments |
| Backend channels | ~15 notification channel files | `config('app.name')` fallbacks |
| Backend seeders | `NotificationTemplateSeeder.php`, `EmailTemplateSeeder.php` | Fallbacks |
| Docker | `docker-compose.yml`, `docker-compose.prod.yml`, `Dockerfile`, `entrypoint.sh` | Volume names, container names, labels, banner |
| CI | `.github/workflows/ci.yml` | Image tag |
| Docs | `VERSION`, `CHANGELOG.md`, `README.md`, `CLAUDE.md`, `docs/overview.md`, `docs/api/openapi.yaml` | Reset/rewrite, API docs title |

## Related

- [Setup New Project (master index)](setup-new-project.md)
- [Setup Features & Auth (Tier 2)](setup-features-auth.md)
- [Setup Infrastructure & Repo (Tier 3)](setup-infrastructure-repo.md)
- [Customization Checklist](../customization-checklist.md)
