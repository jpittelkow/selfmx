# Mailgun Phase 7: Deep Provider Management — Implementation Plan

**Goal**: Expose Mailgun's management APIs so admins can handle domain health, DNS records, DKIM,
webhooks, event logs, and suppression lists without leaving selfmx.

**Parent**: [mailgun-phase7-roadmap.md](mailgun-phase7-roadmap.md)
**Status**: Not yet started
**Frontend entry point**: `configuration/email-domains/` (extend existing page with tabs/detail view)

---

## Current State Summary

What already exists (do NOT re-implement):
- `MailgunProvider` — send, receive, `addDomain()`, `verifyDomain()`, `configureDomainWebhook()`
- `DomainService` — create, verify, delete, provider factory
- `EmailDomainController` — CRUD + verify route
- `EmailProviderSettingController` — settings CRUD (mailgun api_key, region, webhook_signing_key)
- Settings schema: `mailgun.api_key`, `mailgun.region`, `mailgun.webhook_signing_key`
- Config pages: email-provider (settings form), email-domains (list + verify + DNS records modal)
- `EmailProviderInterface` — `addDomain`, `verifyDomain`, `configureDomainWebhook`

What is missing:
- `MailgunProvider` calls for: DKIM, per-domain webhooks, inbound routes, events log, suppressions, tracking settings, stats
- New API endpoints to expose the above
- New settings schema entries for DKIM rotation schedule and auto-webhook toggle
- Extended frontend domain detail view with tabs

---

## Architecture Decision

**Do not create a separate management service.** Extend `MailgunProvider` with new methods and
expose them via new backend routes. Group new routes under `/email/domains/{domain}/mailgun/*`
so they are clearly scoped to a specific domain and provider.

**UI strategy**: Convert the existing domain detail modal (currently just DNS records) into a full
domain detail page (`/configuration/email-domains/[id]`) with tabs for:
DNS Records · DKIM · Webhooks · Event Log · Suppressions · Tracking · Stats

---

## Step 1: Settings Schema — New Mailgun Settings

**File**: `backend/config/settings-schema.php`

Add to the `mailgun` group:

```php
'mailgun_auto_configure_webhooks' => [
    'type' => 'boolean',
    'default' => true,
    'description' => 'Auto-configure Mailgun delivery webhooks on domain creation',
],
'mailgun_dkim_rotation_interval_days' => [
    'type' => 'integer',
    'default' => 0,
    'description' => 'Automatic DKIM key rotation interval in days (0 = disabled)',
],
```

These are system-level settings (not per-domain). Add them to the existing `EmailProviderSettingController`
response group so the provider settings page can display them in the Mailgun tab.

**Risk**: Low — additive only.

---

## Step 2: Extend `MailgunProvider` with Management Methods

**File**: `backend/app/Services/Email/MailgunProvider.php`

Add a private `managementRequest(string $method, string $path, array $payload = []): array` helper
that hits `https://api.mailgun.net/v4/` (US) or `https://api.eu.mailgun.net/v4/` (EU) with the
stored API key. Reuse the same region logic as the existing `sendEmail()` method.

All new methods go on `MailgunProvider`. Each returns a plain array (not a DTO) for simplicity.

### 2a. DKIM

```php
// GET /v4/domains/{domain}/dkim
public function getDkimKey(string $domain, array $config = []): array
// POST /v4/domains/{domain}/dkim
public function rotateDkimKey(string $domain, array $config = []): array
```

Returns: `['selector' => '...', 'public_key' => '...', 'created_at' => '...']`

### 2b. Webhooks

```php
// GET /v3/domains/{domain}/webhooks
public function listWebhooks(string $domain, array $config = []): array
// POST /v3/domains/{domain}/webhooks
public function createWebhook(string $domain, string $event, string $url, array $config = []): array
// PUT /v3/domains/{domain}/webhooks/{webhookId}
public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array
// DELETE /v3/domains/{domain}/webhooks/{webhookId}
public function deleteWebhook(string $domain, string $webhookId, array $config = []): array
```

Note: Mailgun webhook API is v3 not v4.

### 2c. Inbound Routes

```php
// GET /v3/routes  (filter by domain expression)
public function listRoutes(string $domain, array $config = []): array
// POST /v3/routes
public function createRoute(string $expression, array $actions, string $description, int $priority, array $config = []): array
// PUT /v3/routes/{id}
public function updateRoute(string $routeId, array $data, array $config = []): array
// DELETE /v3/routes/{id}
public function deleteRoute(string $routeId, array $config = []): array
```

### 2d. Event Log

```php
// GET /v3/{domain}/events
public function getEvents(string $domain, array $filters = [], array $config = []): array
```

`$filters`: `event`, `recipient`, `begin`, `end`, `subject`, `message-id`, `limit` (max 300), `page`

### 2e. Suppressions

```php
// GET/POST/DELETE /v3/{domain}/bounces
public function listBounces(string $domain, int $limit, ?string $page, array $config = []): array
public function deleteBounce(string $domain, string $address, array $config = []): array

// GET/POST/DELETE /v3/{domain}/complaints
public function listComplaints(string $domain, int $limit, ?string $page, array $config = []): array
public function deleteComplaint(string $domain, string $address, array $config = []): array

// GET/POST/DELETE /v3/{domain}/unsubscribes
public function listUnsubscribes(string $domain, int $limit, ?string $page, array $config = []): array
public function deleteUnsubscribe(string $domain, string $address, ?string $tag, array $config = []): array
```

### 2f. Tracking Settings

```php
// GET /v3/domains/{domain}/tracking
public function getTrackingSettings(string $domain, array $config = []): array
// PUT /v3/domains/{domain}/tracking/click
// PUT /v3/domains/{domain}/tracking/open
// PUT /v3/domains/{domain}/tracking/unsubscribe
public function updateTrackingSetting(string $domain, string $type, bool $active, array $config = []): array
```

### 2g. Stats

```php
// GET /v3/{domain}/stats/total
public function getDomainStats(string $domain, array $events, string $duration, array $config = []): array
```

`$events`: `['accepted', 'delivered', 'failed', 'bounced', 'complained']`
`$duration`: e.g. `'30d'`, `'7d'`, `'1d'`

### 2h. API Health Check

```php
public function checkApiHealth(array $config = []): bool
```

Hits `GET /v3/domains?limit=1` and returns true if 200.

**Risk**: Medium — large addition to MailgunProvider. Keep each method small; test independently.

---

## Step 3: New Backend Controller — `MailgunManagementController`

**File**: `backend/app/Http/Controllers/Api/MailgunManagementController.php`

This controller handles all Phase 7 management endpoints. It:
- Resolves the `EmailDomain` by ID, scoped to `$request->user()->id`
- Verifies the domain uses `provider === 'mailgun'` (return 422 otherwise)
- Extracts `provider_config` from the domain and passes to MailgunProvider methods
- Delegates to `MailgunProvider` and returns JSON

```php
class MailgunManagementController extends Controller
{
    public function __construct(private MailgunProvider $mailgun) {}

    private function resolveDomain(Request $request, int $domainId): EmailDomain
    {
        $domain = EmailDomain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($domain->provider !== 'mailgun') {
            abort(422, 'Domain is not configured with Mailgun');
        }

        return $domain;
    }
```

### Routes to add in `backend/routes/api.php`

Under the existing auth middleware group, after the domain routes:

```php
// Mailgun Management (Phase 7)
Route::prefix('email/domains/{domainId}/mailgun')->group(function () {
    // DKIM
    Route::get('dkim', [MailgunManagementController::class, 'getDkim']);
    Route::post('dkim/rotate', [MailgunManagementController::class, 'rotateDkim']);

    // Webhooks
    Route::get('webhooks', [MailgunManagementController::class, 'listWebhooks']);
    Route::post('webhooks', [MailgunManagementController::class, 'createWebhook']);
    Route::put('webhooks/{webhookId}', [MailgunManagementController::class, 'updateWebhook']);
    Route::delete('webhooks/{webhookId}', [MailgunManagementController::class, 'deleteWebhook']);

    // Routes
    Route::get('routes', [MailgunManagementController::class, 'listRoutes']);
    Route::post('routes', [MailgunManagementController::class, 'createRoute']);
    Route::put('routes/{routeId}', [MailgunManagementController::class, 'updateRoute']);
    Route::delete('routes/{routeId}', [MailgunManagementController::class, 'deleteRoute']);

    // Events
    Route::get('events', [MailgunManagementController::class, 'getEvents']);

    // Suppressions
    Route::get('suppressions/{type}', [MailgunManagementController::class, 'listSuppressions']);
    Route::delete('suppressions/{type}/{address}', [MailgunManagementController::class, 'deleteSupression']);

    // Tracking
    Route::get('tracking', [MailgunManagementController::class, 'getTracking']);
    Route::put('tracking/{type}', [MailgunManagementController::class, 'updateTracking']);

    // Stats
    Route::get('stats', [MailgunManagementController::class, 'getStats']);
});

// Provider health check
Route::get('email/provider/health', [MailgunManagementController::class, 'checkHealth']);
```

**Risk**: Low — new routes only, no changes to existing.

---

## Step 4: DKIM Rotation Console Command

**File**: `backend/app/Console/Commands/RotateDkimKeysCommand.php`

```php
// Signature: email:rotate-dkim
// Runs daily via scheduler; skips domains where rotation is disabled or not due
```

Register in `backend/routes/console.php`:

```php
Schedule::command('email:rotate-dkim')->daily();
```

This checks `mailgun_dkim_rotation_interval_days` setting. If > 0, queries all active Mailgun
domains and rotates any whose `dkim_rotated_at` is older than the interval.

Requires adding `dkim_rotated_at` (nullable timestamp) to `email_domains` table via a new migration.

**Risk**: Low — isolated command. Rotation only fires if the setting is explicitly configured.

---

## Step 5: Frontend — Domain Detail Page

**New file**: `frontend/app/(dashboard)/configuration/email-domains/[id]/page.tsx`

Replace the current "verify + DNS records modal" pattern with a full detail page. The existing
domain list page (`email-domains/page.tsx`) keeps its list + "Add Domain" flow; domain name
becomes a link to `/configuration/email-domains/{id}`.

### Page structure

```
/configuration/email-domains/{id}
  ├── Header: domain name, status badge (Verified/Unverified), "Verify Now" button, "Delete" button
  └── Tabs:
       ├── DNS Records      (existing DNS records table, now always visible)
       ├── DKIM             (current key, rotate button, history)
       ├── Webhooks         (per-event-type list, add/edit/delete)
       ├── Inbound Routes   (Mailgun routing rules)
       ├── Event Log        (filterable event timeline)
       ├── Suppressions     (bounces/complaints/unsubscribes with search)
       ├── Tracking         (toggle open/click/unsubscribe tracking)
       └── Stats            (deliverability chart)
```

### Tab: DNS Records

Reuse the existing DNS records table from the verify modal. Fetch from the domain's `verify`
endpoint (already returns DNS records). Add auto-refresh every 60s when domain is unverified.

### Tab: DKIM

- Show current selector, public key (truncated + copy button), key length, created date
- "Rotate Key" button with confirmation dialog ("This will change your DKIM selector. Update your
  DNS after rotation.")
- Table of rotation history (pull from local audit log, not Mailgun — Mailgun doesn't store history)

### Tab: Webhooks

- Table: Event Type | URL | Status
- Rows: `delivered`, `opened`, `clicked`, `bounced`, `complained`, `unsubscribed`, `stored`
- Actions: Configure | Edit | Delete per row
- "Auto-configure selfmx webhooks" button — calls backend to set all delivery events to the
  selfmx webhook URL
- Edit dialog: URL input

### Tab: Inbound Routes

- Table: Priority | Filter Expression | Actions | Source (selfmx / user-defined)
- Add route button (expression + action inputs)
- Delete with confirmation

### Tab: Event Log

- Filters: event type (multi-select), date range, recipient, subject
- Paginated table: Timestamp | Event | Recipient | Subject | Details
- Row expand: full event payload
- Event type badges: delivered (green), opened (blue), clicked (blue), bounced (amber),
  failed (red), complained (red)

### Tab: Suppressions

- Sub-tabs: Bounces | Complaints | Unsubscribes
- Each: search input + paginated table + delete button per row
- Bounce shows reason and error code; complaint shows source

### Tab: Tracking

- Three toggles: Open Tracking | Click Tracking | Unsubscribe Tracking
- Each toggle saves immediately via PUT

### Tab: Stats

- Date range selector: 7d / 30d / 90d
- Line/bar chart: Accepted · Delivered · Bounced · Complained over time
- Summary cards: Delivery rate · Bounce rate · Complaint rate

**Risk**: High effort, but isolated to a new page. No changes to existing pages except adding
the link from the domain list.

---

## Step 6: Frontend — Provider Health in Admin Dashboard

**File**: `frontend/app/(dashboard)/configuration/email-provider/page.tsx`

In the Mailgun tab, below the existing API key/region fields:

```tsx
// Provider health row
<div className="flex items-center gap-2 text-sm">
  <span className={cn("h-2 w-2 rounded-full", health === 'ok' ? "bg-green-500" : "bg-red-500")} />
  {health === 'ok' ? 'API connected' : 'API unreachable — check your API key'}
</div>
```

Fetch from `GET /email/provider/health` on page load. This is a lightweight check (one Mailgun
domain list call). Cache for 5 minutes; don't re-fetch on every keystroke.

Also add new settings fields to the Mailgun tab:
- Auto-configure webhooks (toggle)
- DKIM rotation interval (number input, suffix "days", 0 = disabled)

**Risk**: Low — additive to existing page.

---

## Step 7: Suppression Warning in Compose

**File**: `frontend/components/mail/compose-dialog.tsx`

When a recipient is added (in the `to`, `cc`, or `bcc` fields), call a lightweight endpoint:

```
GET /email/domains/{domainId}/mailgun/suppressions/check?address={email}
```

If the address is in bounces or complaints, show an inline warning badge next to that recipient
chip: `⚠ Suppressed` with a tooltip explaining why.

Backend: add `checkSuppression(string $domain, string $address)` to `MailgunProvider` — a combined
check against `GET /v3/{domain}/bounces/{address}` and `GET /v3/{domain}/complaints/{address}`.

**Risk**: Low. Do this last — it's a nice-to-have UX polish. Skip if adding latency to the compose
flow is a concern (make it opt-in via a feature flag in settings).

---

## Implementation Order

| Priority | Step | Effort | Risk |
|----------|------|--------|------|
| 1 | Step 1 — Settings schema additions | Tiny | Low |
| 2 | Step 2 — MailgunProvider management methods | Large | Medium |
| 3 | Step 3 — MailgunManagementController + routes | Medium | Low |
| 4 | Step 5 (DNS + DKIM tabs only) | Medium | Low |
| 5 | Step 5 (Webhooks + Inbound Routes tabs) | Medium | Low |
| 6 | Step 5 (Event Log tab) | Medium | Medium |
| 7 | Step 5 (Suppressions tab) | Medium | Low |
| 8 | Step 5 (Tracking + Stats tabs) | Medium | Low |
| 9 | Step 6 — Provider health UI | Small | Low |
| 10 | Step 4 — DKIM rotation command | Small | Low |
| 11 | Step 7 — Compose suppression warning | Small | Low |

Start backend (Steps 1–3) before building any frontend tabs. Once the API endpoints exist, the
tabs can be built and tested independently.

---

## File Checklist

### New files
| File | Purpose |
|------|---------|
| `backend/app/Http/Controllers/Api/MailgunManagementController.php` | Management API endpoints |
| `backend/app/Console/Commands/RotateDkimKeysCommand.php` | Scheduled DKIM rotation |
| `backend/database/migrations/xxxx_add_dkim_rotated_at_to_email_domains_table.php` | Track rotation timestamp |
| `backend/tests/Feature/MailgunManagementTest.php` | Feature tests |
| `frontend/app/(dashboard)/configuration/email-domains/[id]/page.tsx` | Domain detail page |

### Modified files
| File | Change |
|------|--------|
| `backend/app/Services/Email/MailgunProvider.php` | Add ~15 new management methods |
| `backend/config/settings-schema.php` | Add 2 new mailgun settings |
| `backend/routes/api.php` | Add management routes |
| `backend/routes/console.php` | Register DKIM rotation schedule |
| `frontend/app/(dashboard)/configuration/email-domains/page.tsx` | Domain name → link to detail page |
| `frontend/app/(dashboard)/configuration/email-provider/page.tsx` | Health indicator + new settings fields |
| `frontend/components/mail/compose-dialog.tsx` | Suppression warning (Step 7, optional) |

---

## Backend Testing Strategy

**Feature tests** (`backend/tests/Feature/MailgunManagementTest.php`):
- Mock Mailgun HTTP responses using Laravel's `Http::fake()`
- Test each controller endpoint: 200 with correct data, 404 for missing domain, 422 for non-Mailgun domain
- Test user scoping: domain owned by another user returns 403

**Unit tests**: Not needed separately — the MailgunProvider methods are thin HTTP wrappers; test via feature tests.

---

## Gotchas

- **Mailgun API versions**: Management is a mix of v3 and v4. Routes/Webhooks/Events/Suppressions are v3. Domain details/DKIM are v4. Keep this clear in the `managementRequest()` helper (accept a `$version` param, default `'v4'`).
- **Per-domain API key**: The `provider_config` on `EmailDomain` can override the global API key. Always pass the resolved config through from the controller to the provider methods.
- **Mailgun route filtering**: The routes API (`GET /v3/routes`) returns ALL routes, not filtered by domain. Filter client-side (or server-side) by checking if the route expression contains the domain name.
- **Event log pagination**: Mailgun uses `p` (page token) not offset. Store the next-page URL returned by Mailgun and pass it back to the frontend as `nextPage`. The frontend passes it back on the next request.
- **Stats granularity**: `GET /v3/{domain}/stats/total` returns per-event totals for a duration. For a chart, call with `resolution=day` and parse the `stats` array of `{time, accepted, delivered, ...}` objects.
- **DKIM history**: Mailgun does not provide a history API. Store rotation events in the audit log (use `AuditService`) and read from there for the history table.
- **Rate limits**: Mailgun v3/v4 have rate limits (typically 300 req/min). The management UI is low-volume; no throttling layer needed. Add exponential backoff on 429 responses in `managementRequest()`.
