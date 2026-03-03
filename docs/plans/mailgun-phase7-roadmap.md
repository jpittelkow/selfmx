# Mailgun Phase 7: Deep Provider Management Integration

Expose the full depth of Mailgun's management APIs so admins can manage domains, DNS, webhooks, deliverability, and suppressions without leaving selfmx.

**Status**: Partial — core API + UI shipped in v0.2.1 (2026-03-02)
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
- [ ] Retrieve full domain details from Mailgun v4 API (state, created_at, wildcard, force_dkim_authority, DKIM key length)
- [ ] List domains with filtering (active / unverified / disabled) and search
- [ ] Delete domain via Mailgun API (with confirmation dialog + audit log)
- [ ] Domain health dashboard — show verification state, last verified timestamp, DNS status summary
- [x] One-click "Verify Now" trigger via `PUT /v4/domains/{name}/verify` with real-time status feedback
- [x] Display required DNS records per domain (SPF, DKIM, MX, tracking CNAME) with copy-to-clipboard

### 2. DNS Record Visibility & Comparison
- [x] Fetch and display DNS records from Mailgun API (sending records, receiving records, tracking records)
- [ ] Optional: DNS lookup to compare required vs actual records (currently display Mailgun-provided records)
- [ ] Side-by-side comparison UI: "Required by Mailgun" vs "Configured in DNS"
- [x] Record status indicators (valid / missing / mismatch) per record type
- [ ] Auto-refresh DNS status on domain detail page

### 3. DKIM Key Management
- [x] List DKIM signing keys per domain (selector, active status, key length, created date)
- [x] Rotate DKIM key on demand via Mailgun API (with audit log + `dkim_rotated_at` column)
- [ ] Configure automatic DKIM key rotation schedule (interval setting in system settings)
- [x] Show current active DKIM selector in domain detail
- [ ] DKIM key rotation history timeline

### 4. Webhook Management
- [x] List all webhooks per domain (delivered, opened, clicked, bounced, complained, unsubscribed, stored)
- [x] Create / update / delete domain-level webhooks via UI
- [x] Webhook status indicators (configured / not configured per event type)
- [ ] Test webhook endpoint with sample payload
- [x] Auto-configure selfmx webhooks on domain creation (delivery events: delivered, bounced, failed, complained)
- [ ] Show webhook URL and signing key for debugging

### 5. Inbound Route Management
- [x] List Mailgun routes with filter expression and actions
- [x] Create / update / delete routes via UI
- [ ] Route priority ordering (drag to reorder)
- [ ] Show which routes selfmx auto-created vs user-defined
- [ ] Route validation and error feedback

### 6. Email Event Monitoring
- [x] Events log page — query Mailgun Events/Logs API with filters (event type, recipient, pagination)
- [ ] Event timeline per email (sent → delivered → opened → clicked, or sent → bounced)
- [ ] Link from email detail view to provider event history
- [x] Event search with severity indicators (delivered = success, bounced = warning, failed = error)
- [ ] Export event logs to CSV

### 7. Suppression Management
- [x] Bounces list — view, search, remove bounced addresses per domain
- [x] Complaints list — view, search, remove complained addresses per domain
- [x] Unsubscribes list — view, search, remove unsubscribed addresses per domain
- [ ] Bulk import/export suppressions (CSV)
- [ ] Surface suppression warnings when composing to a suppressed address
- [ ] Bounce/complaint reason details (hard bounce, soft bounce, complaint reason)

### 8. Domain Tracking Settings
- [x] View and toggle open tracking, click tracking, unsubscribe tracking per domain
- [ ] Configure tracking CNAME (HTTPS tracking domain) settings
- [x] Show tracking stats summary on domain detail page
- [ ] Track unsubscribe link generation settings

### 9. Sending Stats & Reputation
- [x] Domain-level sending stats (accepted, delivered, bounced, complained — hourly/daily/monthly)
- [x] Stats charts on domain detail page (deliverability rate, bounce rate, complaint rate over time)
- [ ] Tag-based stats for outbound email analytics
- [ ] Sending queue status indicator per domain
- [ ] Complaint and bounce trends over time

### 10. Provider Health
- [x] API connectivity check (test Mailgun credentials on settings save)
- [ ] Provider status indicator in admin dashboard (green/yellow/red based on API health)
- [ ] Rate limit awareness — display current usage against Mailgun rate limits
- [ ] API usage reporting (requests/month, remaining quota)

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
- [ ] Unit tests for Mailgun management service layer (domain list, DKIM rotation, event querying)
- [ ] Feature tests for management API endpoints (CRUD for webhooks, routes, suppressions)
- [ ] E2E tests for domain detail page and settings
- [ ] Test with real Mailgun sandbox domain (no need for live sending)

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
