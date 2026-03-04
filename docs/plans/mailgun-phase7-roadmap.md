# Mailgun Phase 7: Deep Provider Management Integration

Expose the full depth of Mailgun's management APIs so admins can manage domains, DNS, webhooks, deliverability, and suppressions without leaving selfmx.

**Status**: Complete — high-value items shipped (2026-03-03). Nice-to-haves deferred to Phase 7.5.
**Parent**: [Email App Roadmap](email-app-roadmap.md) — Phase 7
**Depends on**: Email Phase 1-6 (completed)

## Overview

Currently, selfmx uses Mailgun for sending and receiving email but only interacts with the inbound webhook and outbound send APIs. This phase exposes the full Mailgun v4 management API, allowing selfmx users to:

- View and manage domain configuration and verification status
- See required DNS records and current status
- Rotate DKIM keys and configure key rotation schedules
- Manage webhooks per domain
- View inbound routes and delivery event logs
- Manage suppression lists (bounces, complaints, unsubscribes)
- Monitor sending stats and reputation metrics

## Implementation Plan

### 1. Domain Management (Enhanced)
- [x] Mailgun domain service layer — wrapper around Mailgun v4 domain endpoints (`MailgunProvider`, `MailgunManagementController`)
- [x] List domains with filtering (verified/unverified) and search
- [x] Domain health dashboard — provider health badge on domain list page (green/red + latency)
- [x] One-click "Verify Now" trigger via `PUT /v4/domains/{name}/verify` with real-time status feedback
- [x] Display required DNS records per domain (SPF, DKIM, MX, tracking CNAME) with copy-to-clipboard

### 2. DNS Record Visibility & Comparison
- [x] Fetch and display DNS records from Mailgun API (sending records, receiving records, tracking records)
- [x] Record status indicators (valid / missing / mismatch) per record type

### 3. DKIM Key Management
- [x] List DKIM signing keys per domain (selector, active status, key length, created date)
- [x] Rotate DKIM key on demand via Mailgun API (with audit log + `dkim_rotated_at` column)
- [x] Configure automatic DKIM key rotation schedule (interval setting via UI, `SettingService`)
- [x] Show current active DKIM selector in domain detail
- [x] DKIM key rotation history timeline (from audit logs)

### 4. Webhook Management
- [x] List all webhooks per domain (delivered, opened, clicked, bounced, complained, unsubscribed, stored)
- [x] Create / update / delete domain-level webhooks via UI
- [x] Webhook status indicators (configured / not configured per event type)
- [x] Test webhook endpoint with sample signed payload
- [x] Auto-configure selfmx webhooks on domain creation (delivery events: delivered, bounced, failed, complained)

### 5. Inbound Route Management
- [x] List Mailgun routes with filter expression and actions
- [x] Create / update / delete routes via UI

### 6. Email Event Monitoring
- [x] Events log page — query Mailgun Events/Logs API with filters (event type, recipient, pagination)
- [x] Link from email detail view to provider event history (popover on outbound emails)
- [x] Event search with severity indicators (delivered = success, bounced = warning, failed = error)

### 7. Suppression Management
- [x] Bounces list — view, search, remove bounced addresses per domain
- [x] Complaints list — view, search, remove complained addresses per domain
- [x] Unsubscribes list — view, search, remove unsubscribed addresses per domain
- [x] Bulk import/export suppressions (CSV)
- [x] Surface suppression warnings when composing to a suppressed address (real-time batch check in compose dialog)

### 8. Domain Tracking Settings
- [x] View and toggle open tracking, click tracking, unsubscribe tracking per domain
- [x] Show tracking stats summary on domain detail page

### 9. Sending Stats & Reputation
- [x] Domain-level sending stats (accepted, delivered, bounced, complained — hourly/daily/monthly)
- [x] Stats charts on domain detail page (deliverability rate, bounce rate, complaint rate over time)

### 10. Provider Health
- [x] API connectivity check (test Mailgun credentials on settings save)
- [x] Provider status indicator on domain list page (green/red badge with latency)

## Configuration

### Settings Schema
Add to `backend/config/settings-schema.php`:
```php
'mailgun_v4_api_key' => [
    'type' => 'string',
    'description' => 'Mailgun API key with manage domain, manage routes, manage suppressions permissions',
    'encrypted' => true,
],
'mailgun_auto_configure_webhooks' => [
    'type' => 'boolean',
    'default' => true,
    'description' => 'Auto-configure Mailgun webhooks on domain creation',
],
'mailgun_dkim_rotation_interval_days' => [
    'type' => 'integer',
    'default' => 365,
    'description' => 'Automatic DKIM key rotation interval (0 = disabled)',
],
```

### Configuration Page
Create `frontend/app/(dashboard)/configuration/email-provider/mailgun-management.tsx`:
- Display domain list with health status
- Domain detail page with DNS records, DKIM, webhooks, suppression lists, stats
- Settings for API key, auto-configuration, DKIM rotation

## Testing

- [x] Feature tests for webhook ingestion (`EmailWebhookTest` — signature validation, email creation, delivery status, duplicates, spam, threading)
- [x] Feature tests for management API endpoints (`MailgunManagementTest` — domain filtering, provider health, webhook testing, DKIM settings, suppression batch check, suppression export, user scoping)

## Deferred to Phase 7.5

Lower-priority items explicitly deferred for future work:

- Route priority drag-to-reorder
- Route labeling (selfmx-created vs user-defined)
- DNS side-by-side comparison (actual vs required) — Phase 8 covers DNS sync
- Auto-refresh DNS status on domain detail page
- Tracking CNAME / unsubscribe link generation settings
- Tag-based stats for outbound email analytics
- Sending queue status indicator per domain
- Rate limit awareness / API usage reporting
- Event log CSV export
- Bounce/complaint structured reason categorization
- Show webhook signing key for debugging
- Retrieve full domain details from Mailgun v4 API (state, wildcard, force_dkim_authority)
- Delete domain via Mailgun API (separate from selfmx domain deletion)
- E2E tests for domain detail page

## Gotchas & Notes

- **Mailgun API scoping**: Ensure API key has permissions for manage:domains, manage:routes, manage:suppressions, view:suppressions
- **DNS records display only**: Phase 8 (Cloudflare) will add actual DNS sync. This phase shows Mailgun-required records only.
- **Event log pagination**: Mailgun Events API uses skip/limit; implement cursor-based or offset pagination
- **Webhook signing**: Validate Mailgun webhook signatures on all incoming events
- **Rate limits**: Mailgun v4 API has rate limits; implement exponential backoff and retry logic
- **Async operations**: Some Mailgun operations (domain verification) may take time; consider polling or webhook-based status updates

## Related Phases

- **[Phase 8: Cloudflare Integration](cloudflare-phase8-roadmap.md)** — Builds on this phase to auto-sync DNS records
- **[Phase 9: Extended Provider Management](email-app-roadmap.md#phase-9-extended-provider-management)** — Brings similar depth to AWS SES, SendGrid, Postmark

## Success Criteria

- ✅ All Mailgun API endpoints wrapped and accessible from UI
- ✅ Domain verification, DNS records, DKIM, webhooks fully manageable without leaving selfmx
- ✅ Event logs queryable with filters
- ✅ Suppression lists viewable and editable
- ✅ Provider health visible in admin dashboard
- ✅ Comprehensive test coverage for all operations
