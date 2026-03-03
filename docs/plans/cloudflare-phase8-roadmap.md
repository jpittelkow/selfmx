# Cloudflare Phase 8: DNS Management & Auto-Sync Integration

Integrate with Cloudflare DNS to automatically manage DNS records required by email providers, keeping mail DNS in sync without manual zone file editing.

**Status**: Not yet started
**Parent**: [Email App Roadmap](email-app-roadmap.md) — Phase 8
**Depends on**: Email Phases 1-7 (Phases 1-6 complete, Phase 7 in progress)
**Related**: [Mailgun Phase 7](mailgun-phase7-roadmap.md)

## Overview

Email providers (Mailgun, AWS SES, SendGrid, Postmark) require specific DNS records (SPF, DKIM, MX, tracking CNAMEs) to function. Currently, users must manually configure these in their DNS provider. This phase automates DNS record management by integrating with Cloudflare, allowing:

- One-click Cloudflare connection (securely encrypted API token)
- Auto-detection of which selfmx email domains have matching Cloudflare zones
- DNS record sync dashboard showing required vs actual records per domain
- One-click creation of missing records
- Intelligent SPF merging (append instead of overwrite)
- Automated drift detection (records changed outside selfmx)
- Post-domain-creation hooks for automatic DNS setup
- Extensible provider pattern for Route 53, Google Cloud DNS, etc. (future)

## Implementation Plan

### Phase 1: Cloudflare Connection & Configuration

#### 1.1 Settings Schema & Encryption
- [ ] Add to `backend/config/settings-schema.php`:
  ```php
  'cloudflare_api_token' => [
      'type' => 'string',
      'description' => 'Cloudflare API token (scoped to zone edit)',
      'encrypted' => true,
      'nullable' => true,
  ],
  'cloudflare_auto_sync_dns' => [
      'type' => 'boolean',
      'default' => false,
      'description' => 'Automatically sync DNS records when drift detected',
  ],
  'cloudflare_dns_check_interval_hours' => [
      'type' => 'integer',
      'default' => 6,
      'description' => 'How often to check DNS records for drift',
  ],
  ```

#### 1.2 Cloudflare Service Layer
- [ ] Create `backend/app/Services/DNS/CloudflareProvider.php` (implements DnsProviderInterface)
- [ ] Methods:
  - `authenticate(token: string): bool` — test token validity
  - `listZones(): array` — fetch all zones for API token
  - `getZoneId(domain: string): ?string` — find zone matching a domain
  - `listRecords(zoneId: string): array` — fetch all DNS records in a zone
  - `createRecord(zoneId: string, record: array): array` — add DNS record
  - `updateRecord(zoneId: string, recordId: string, record: array): array` — modify record
  - `deleteRecord(zoneId: string, recordId: string): bool` — remove record
  - `getRecord(zoneId: string, type: string, name: string): ?array` — find specific record

#### 1.3 Cloudflare Configuration Page
- [ ] Create `frontend/app/(dashboard)/configuration/email-provider/cloudflare.tsx`
- [ ] Sections:
  - API token input (masked, validation on blur)
  - "Test Connection" button with status feedback
  - "Auto-detect Zones" button (fetches zones, shows matched email domains)
  - Zone list table (zone name, matching selfmx domains, record sync status)
  - Auto-sync toggle + interval setting
  - DNS check history (last checked, next check, status)

### Phase 2: Zone Detection & Domain Matching

#### 2.1 Zone Auto-Detection
- [ ] On Cloudflare connection, fetch all zones and cache zone → domain mapping
- [ ] Auto-detect which selfmx email domains have matching Cloudflare zones:
  - Exact match: `mail.example.com` → `example.com` zone
  - Subdomain match: `subdomain.mail.example.com` → `example.com` zone
- [ ] Store zone associations per email domain in database (email_domains table)

#### 2.2 Domain-Zone Mapping
- [ ] Add columns to `email_domains` table:
  - `cloudflare_zone_id: string?` — associated Cloudflare zone ID
  - `cloudflare_zone_name: string?` — human-readable zone name
  - `dns_sync_status: enum('synced', 'missing', 'mismatch', 'error')`
  - `dns_last_checked_at: timestamp?`
  - `dns_drift_detected_at: timestamp?` — when records last changed outside selfmx

#### 2.3 Zone Selection UI
- [ ] After Cloudflare connection, offer zone selection for each email domain
- [ ] Allow manual zone selection (in case auto-detection fails)
- [ ] Show matched records count per zone

### Phase 3: DNS Record Sync Dashboard

#### 3.1 Sync Status Display
- [ ] Create `frontend/components/dns-sync-status-grid.tsx` — reusable grid component
- [ ] Per-domain grid showing:
  | Record Type | Required Value | Current Value | Status |
  |---|---|---|---|
  | SPF | `include:mailgun.org` | ✓ Configured | ✓ Synced |
  | DKIM | `v=DKIM1; k=rsa; p=...` | ✗ Missing | ✗ Missing |
  | MX | `mxa.mailgun.org` (priority 10) | ✓ Configured | ✓ Synced |
  | CNAME (tracking) | `email.example.com.mailgun.org` | ? Unknown | ? Mismatch |

#### 3.2 Record Status Indicators
- [ ] **Synced** (✓ green) — required record exists with correct value
- [ ] **Missing** (✗ red) — required record does not exist
- [ ] **Mismatch** (⚠ yellow) — record exists but value differs
- [ ] **Extra** (ℹ blue) — record exists but not required (show as info)

#### 3.3 DNS Record Sync Page
- [ ] Create `frontend/app/(dashboard)/configuration/email-provider/dns-sync.tsx`
- [ ] Sections:
  - Domain list with sync status badges
  - Domain detail: sync grid + action buttons
  - "Create Missing Records" button (shows preview)
  - "Fix Mismatches" button (shows proposed changes)
  - "Delete Extra Records" button (for cleanup)
  - Sync history log (when each record was created/updated/deleted)

### Phase 4: One-Click DNS Record Creation/Fixing

#### 4.1 SPF Record Management
- [ ] Fetch existing SPF record (if any)
- [ ] If SPF doesn't exist: create `v=spf1 include:mailgun.org ~all`
- [ ] If SPF exists: intelligently append `include:mailgun.org` without overwriting
  - Parse existing SPF includes, MX, IP ranges
  - Preserve all existing entries
  - Insert new include at appropriate position
  - Validate SPF length (max 255 chars per DNS spec)

#### 4.2 DKIM TXT Record Management
- [ ] Get active DKIM selector from Mailgun (e.g., `default`)
- [ ] Get DKIM public key from Mailgun
- [ ] Create TXT record: `default._domainkey.example.com` → `v=DKIM1; k=rsa; p=...`
- [ ] On DKIM rotation (Phase 7), update this record automatically

#### 4.3 MX Record Management
- [ ] Get required MX records from Mailgun (mxa.mailgun.org, mxb.mailgun.org, etc.)
- [ ] Create/update MX records with correct priority
- [ ] Preserve any user-added MX records (only manage Mailgun-required ones)

#### 4.4 Tracking CNAME Management
- [ ] Get tracking domain CNAME from Mailgun (if tracking enabled)
- [ ] Create CNAME record: `email.example.com` → `email.example.com.mailgun.org`

#### 4.5 Dry-Run Mode
- [ ] Before creating/updating records, show preview:
  - "The following records will be created/updated:"
  - Display each record with new value
  - Show SPF merge preview if applicable
  - Require confirmation before applying

#### 4.6 Change Application
- [ ] On confirmation, apply changes to Cloudflare
- [ ] Log each operation (create, update, delete) to database
- [ ] Show real-time progress feedback
- [ ] Handle partial failures gracefully (some records created, some failed)

### Phase 5: Automated DNS Drift Detection & Sync

#### 5.1 DNS Check Command
- [ ] Create Laravel command: `artisan dns:check-drift`
- [ ] Parameters: `--domain=example.com` (optional; all if omitted)
- [ ] Operation:
  1. Fetch current Cloudflare records for zone
  2. Compare against expected records (from Mailgun)
  3. Detect changes, additions, deletions
  4. Log drift in database (`dns_drift_detected_at`)
  5. Notify admin if drift detected

#### 5.2 Scheduled Drift Detection
- [ ] Register in Laravel scheduler (configurable interval via settings)
- [ ] Run every 6 hours by default (configurable: `cloudflare_dns_check_interval_hours`)
- [ ] On drift detection, trigger notification (see Notifications ADR-005)

#### 5.3 Auto-Sync Option
- [ ] If `cloudflare_auto_sync_dns` enabled, automatically fix drift
- [ ] Repair strategy:
  - Missing records: create them
  - Mismatched records: update them
  - Extra records: offer to delete (or whitelist user-managed records)
- [ ] Log auto-sync operations for audit trail

#### 5.4 Notification on Drift
- [ ] Send admin notification when drift detected (if auto-sync disabled)
- [ ] Notification: "DNS records for [domain] have drifted. [View] [Auto-fix]"
- [ ] Link to DNS sync page for review

### Phase 6: Post-Domain-Creation Hooks

#### 6.1 Domain Creation Hook
- [ ] After adding domain to Mailgun (in DomainService):
  1. Check if Cloudflare is connected
  2. Auto-detect matching zone
  3. Create SPF, DKIM, MX, CNAME records (if dry-run succeeds)
  4. Return status: "DNS records created" vs "Manual configuration needed"

#### 6.2 DKIM Rotation Hook
- [ ] After rotating DKIM key in Phase 7:
  1. Get new DKIM selector and public key
  2. Update DKIM TXT record in Cloudflare
  3. Notify user: "DKIM rotated and DNS updated"

#### 6.3 Domain Deletion Hook
- [ ] When deleting domain from Mailgun:
  1. Option to delete associated DNS records from Cloudflare (or archive)
  2. Warn if removing MX record would break email delivery
  3. Log deletion for audit trail

### Phase 7: DNS Record Audit & History

#### 7.1 Full DNS Record List
- [ ] Show all records in zone (not just email-related)
- [ ] Highlight email-related records with badges (SPF, DKIM, MX, CNAME)
- [ ] Filter by type or tag for browsing

#### 7.2 Record Changelog
- [ ] Track when selfmx last modified each record
  - Created: `2026-03-01 14:32:00 by API token`
  - Updated: `2026-03-05 09:15:00 by DNS drift auto-sync`
  - Deleted: `2026-03-10 11:20:00`
- [ ] Show who/what triggered changes (manual button vs auto-sync vs domain hook)

#### 7.3 Record Comparison View
- [ ] Side-by-side: "Required by Mailgun" vs "Found in Cloudflare"
- [ ] Highlight discrepancies in color
- [ ] Export to PDF/CSV for documentation

### Phase 8: Multi-Provider DNS Awareness

#### 8.1 Provider-Agnostic Abstraction
- [ ] Create `DnsProviderInterface`:
  ```php
  interface DnsProviderInterface {
      public function authenticate(config: array): bool;
      public function listZones(): array;
      public function getZoneId(domain: string): ?string;
      public function listRecords(zoneId: string): array;
      public function createRecord(zoneId: string, record: array): array;
      public function updateRecord(zoneId: string, recordId: string, record: array): array;
      public function deleteRecord(zoneId: string, recordId: string): bool;
  }
  ```

#### 8.2 Email Provider DNS Requirements
- [ ] Abstract DNS requirements from email providers:
  ```php
  interface EmailProviderInterface {
      public function getDnsRecords(domain: string): array;
      // returns [{type: 'SPF', value: '...', name: 'example.com'}, ...]
  }
  ```

#### 8.3 Multi-Provider DNS Handling
- [ ] Support multiple email providers per domain
- [ ] Conflict detection: warn if two providers require conflicting MX records
- [ ] DNS strategy: merge SPF, separate DKIM by selector

#### 8.4 Future DNS Providers (Placeholder)
- [ ] `AwsRoute53Provider` (AWS Route 53)
- [ ] `GoogleCloudDnsProvider` (Google Cloud DNS)
- [ ] `DigitalOceanDnsProvider` (DigitalOcean)
- [ ] `ManualDnsProvider` (display-only; for self-managed DNS)

## Configuration

### Settings Schema
(Already listed in Phase 1.1)

### Environment Variables
```env
# Optional: default Cloudflare API token (can be overridden in UI)
CLOUDFLARE_API_TOKEN=
CLOUDFLARE_AUTO_SYNC_DNS=false
CLOUDFLARE_DNS_CHECK_INTERVAL_HOURS=6
```

### Configuration Pages
- `frontend/app/(dashboard)/configuration/email-provider/cloudflare.tsx` — API connection, zone detection
- `frontend/app/(dashboard)/configuration/email-provider/dns-sync.tsx` — sync dashboard, record management
- Add to `configuration/layout.tsx` navigationGroups

## Database Migrations

```php
// Add columns to email_domains table
Schema::table('email_domains', function (Blueprint $table) {
    $table->string('cloudflare_zone_id')->nullable();
    $table->string('cloudflare_zone_name')->nullable();
    $table->enum('dns_sync_status', ['synced', 'missing', 'mismatch', 'error'])->default('missing');
    $table->timestamp('dns_last_checked_at')->nullable();
    $table->timestamp('dns_drift_detected_at')->nullable();
});

// New table: dns_record_changelog
Schema::create('dns_record_changelog', function (Blueprint $table) {
    $table->id();
    $table->foreignId('email_domain_id');
    $table->string('record_type'); // SPF, DKIM, MX, CNAME
    $table->string('record_name'); // e.g., example.com, _domainkey.example.com
    $table->text('old_value')->nullable();
    $table->text('new_value')->nullable();
    $table->enum('action', ['created', 'updated', 'deleted']);
    $table->string('triggered_by'); // 'manual_button', 'auto_sync', 'domain_hook', etc.
    $table->text('cloudflare_response')->nullable(); // raw API response
    $table->timestamps();
    $table->foreignId('user_id');
});
```

## Testing

- [ ] Unit tests for Cloudflare service (zone list, record CRUD, SPF merge)
- [ ] Feature tests for DNS sync dashboard (status calculation, dry-run)
- [ ] E2E tests for record creation flow (from Cloudflare connection to record created)
- [ ] Test SPF merge edge cases (multiple includes, ip4/ip6, all/~all qualifiers)
- [ ] Test with Cloudflare sandbox/test API token
- [ ] Test drift detection (create record outside selfmx, verify detection)

## Gotchas & Notes

- **Cloudflare API scoping**: Token requires `Zone:Edit` permission (zone.zone_ruleset:edit, zone.dns_records:edit)
- **DNS propagation**: Records are created immediately in Cloudflare but may take time to propagate globally (TTL dependent)
- **SPF string length**: RFC 5321 limits SPF to 255 chars per DNS response. Warn user if over limit
- **DKIM selector uniqueness**: Each DKIM selector must map to a different key; validate on rotation
- **Trailing dots in DNS names**: Cloudflare API requires names without trailing dot; add/strip as needed
- **TTL management**: Keep default TTL or allow user customization? Consider auto-low-TTL before changes, then restore
- **Cloudflare rate limits**: API has rate limits; implement exponential backoff, batch operations
- **Cached zone list**: Cache zone list for 1 hour to avoid excessive API calls; add refresh button

## Related Phases

- **[Phase 7: Mailgun Deep Integration](mailgun-phase7-roadmap.md)** — Provides DNS record requirements and event logging
- **[Phase 9: Extended Provider Management](email-app-roadmap.md#phase-9-extended-provider-management)** — Extends DNS awareness to SES, SendGrid, Postmark
- **[Phase 10: GraphQL API Audit](email-app-roadmap.md#phase-10-graphql-api-audit-email-model-coverage)** — Expose DNS records and zone data via GraphQL

## Success Criteria

- ✅ Cloudflare API token securely stored and testable from UI
- ✅ Email domains auto-detect matching zones or allow manual selection
- ✅ DNS sync dashboard shows status for all required records (SPF, DKIM, MX, CNAME)
- ✅ One-click creation of missing records (with preview/confirmation)
- ✅ Intelligent SPF merging (append without overwriting existing includes)
- ✅ Automated drift detection (scheduled checks, notifications)
- ✅ Optional auto-sync (auto-fix detected drift)
- ✅ Post-domain-creation hook: DNS records auto-created on new domain
- ✅ Post-DKIM-rotation hook: DKIM DNS record auto-updated
- ✅ Full audit trail (changelog showing all DNS changes)
- ✅ Comprehensive test coverage (service layer, UI, integration with Mailgun)
- ✅ DNS provider interface abstraction ready for Route 53 / GCP DNS / DigitalOcean implementations
