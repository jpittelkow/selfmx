# Mailgun Phase 7: Deep Provider Management Integration

Expose the full depth of Mailgun's management APIs so admins can manage domains, DNS, webhooks, deliverability, and suppressions without leaving selfmx.

**Status**: Not yet started
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
- [ ] Mailgun domain service layer — wrapper around Mailgun v4 domain endpoints
- [ ] Retrieve full domain details from Mailgun v4 API (state, created_at, wildcard, force_dkim_authority, DKIM key length)
- [ ] List domains with filtering (active / unverified / disabled) and search
- [ ] Delete domain via Mailgun API (with confirmation dialog + audit log)
- [ ] Domain health dashboard — show verification state, last verified timestamp, DNS status summary
- [ ] One-click "Verify Now" trigger via `PUT /v4/domains/{name}/verify` with real-time status feedback
- [ ] Display required DNS records per domain (SPF, DKIM, MX, tracking CNAME) with copy-to-clipboard

### 2. DNS Record Visibility & Comparison
- [ ] Fetch and display DNS records from Mailgun API (sending records, receiving records, tracking records)
- [ ] Optional: DNS lookup to compare required vs actual records (currently display Mailgun-provided records)
- [ ] Side-by-side comparison UI: "Required by Mailgun" vs "Configured in DNS"
- [ ] Record status indicators (valid / missing / mismatch) per record type
- [ ] Auto-refresh DNS status on domain detail page

### 3. DKIM Key Management
- [ ] List DKIM signing keys per domain (selector, active status, key length, created date)
- [ ] Rotate DKIM key on demand via Mailgun API
- [ ] Configure automatic DKIM key rotation schedule (interval setting in system settings)
- [ ] Show current active DKIM selector in domain detail
- [ ] DKIM key rotation history timeline

### 4. Webhook Management
- [ ] List all webhooks per domain (delivered, opened, clicked, bounced, complained, unsubscribed, stored)
- [ ] Create / update / delete domain-level webhooks via UI
- [ ] Webhook status indicators (configured / not configured per event type)
- [ ] Test webhook endpoint with sample payload
- [ ] Auto-configure selfmx webhooks on domain creation (delivery events: delivered, bounced, failed, complained)
- [ ] Show webhook URL and signing key for debugging

### 5. Inbound Route Management
- [ ] List Mailgun routes with filter expression and actions
- [ ] Create / update / delete routes via UI with expression builder
- [ ] Route priority ordering (drag to reorder)
- [ ] Show which routes selfmx auto-created vs user-defined
- [ ] Route validation and error feedback

### 6. Email Event Monitoring
- [ ] Events log page — query Mailgun Events/Logs API with filters (event type, recipient, date range, subject, message-id)
- [ ] Event timeline per email (sent → delivered → opened → clicked, or sent → bounced)
- [ ] Link from email detail view to provider event history
- [ ] Event search with severity indicators (delivered = success, bounced = warning, failed = error)
- [ ] Export event logs to CSV

### 7. Suppression Management
- [ ] Bounces list — view, search, add, remove bounced addresses per domain
- [ ] Complaints list — view, search, add, remove complained addresses per domain
- [ ] Unsubscribes list — view, search, add, remove unsubscribed addresses per domain
- [ ] Bulk import/export suppressions (CSV)
- [ ] Surface suppression warnings when composing to a suppressed address
- [ ] Bounce/complaint reason details (hard bounce, soft bounce, complaint reason)

### 8. Domain Tracking Settings
- [ ] View and toggle open tracking, click tracking, unsubscribe tracking per domain
- [ ] Configure tracking CNAME (HTTPS tracking domain) settings
- [ ] Show tracking stats summary on domain detail page
- [ ] Track unsubscribe link generation settings

### 9. Sending Stats & Reputation
- [ ] Domain-level sending stats (accepted, delivered, bounced, complained — hourly/daily/monthly)
- [ ] Stats charts on domain detail page (deliverability rate, bounce rate, complaint rate over time)
- [ ] Tag-based stats for outbound email analytics
- [ ] Sending queue status indicator per domain
- [ ] Complaint and bounce trends over time

### 10. Provider Health
- [ ] API connectivity check (test Mailgun credentials on settings save)
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

- [ ] Unit tests for Mailgun service layer (domain list, DKIM rotation, event querying)
- [ ] Feature tests for configuration CRUD (create domain, verify, delete)
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
