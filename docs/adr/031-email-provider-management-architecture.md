# ADR-031: Email Provider Management Architecture

**Status**: Accepted
**Date**: 2026-03-10
**Context**: selfmx needs a uniform way to manage email providers (Mailgun, SES, Postmark, etc.) with varying capabilities — from basic send/receive to DKIM rotation, suppression lists, and delivery stats.

## Decision

Use a **capability-based interface hierarchy** with `instanceof` checks at the controller level. Every provider implements the core `EmailProviderInterface`. Providers with management features additionally implement `ProviderManagementInterface` and any subset of capability-specific concern interfaces. A single `ProviderManagementController` handles all management endpoints provider-agnostically.

## Architecture

### Interface Hierarchy

```
EmailProviderInterface               ← REQUIRED: send, receive, domain registration
├── getName(): string
├── verifyWebhookSignature(Request): bool
├── parseInboundEmail(Request): ParsedEmail
├── sendEmail(Mailbox, to[], subject, html, ...): SendResult
├── parseDeliveryEvent(Request): array
├── addDomain(domain, config): DomainResult
├── verifyDomain(domain, config): DomainVerificationResult
└── configureDomainWebhook(domain, webhookUrl, config): bool

ProviderManagementInterface          ← OPTIONAL: declares management support
└── getCapabilities(): array<string, bool>

Concern Interfaces (each OPTIONAL):
├── HasDkimManagement          → getDkimKey(), rotateDkimKey()
├── HasDomainListing           → listProviderDomains()
├── HasWebhookManagement       → list/create/update/delete/testWebhook()
├── HasInboundRoutes           → list/create/update/deleteRoute()
├── HasEventLog                → getEvents()
├── HasSuppressionManagement   → list/delete/check/importBounces/Complaints/Unsubscribes()
└── HasDeliveryStats           → getDomainStats(), getTrackingSettings(), updateTrackingSetting()
```

### Capability Keys

`getCapabilities()` returns a boolean map. The controller checks these before calling methods:

| Key | Interface | Description |
|-----|-----------|-------------|
| `dkim_rotation` | `HasDkimManagement` | DKIM key retrieval and rotation |
| `domain_listing` | `HasDomainListing` | List domains registered with provider |
| `webhooks` | `HasWebhookManagement` | Webhook CRUD and testing |
| `inbound_routes` | `HasInboundRoutes` | Mail routing rule management |
| `events` | `HasEventLog` | Queryable event/activity log |
| `suppressions` | `HasSuppressionManagement` | Bounce/complaint/unsubscribe lists |
| `stats` | `HasDeliveryStats` | Delivery statistics and tracking |
| `domain_management` | — | Provider-side domain create/delete (reserved) |
| `dns_records` | — | DNS record management (reserved) |

### Provider Capability Matrix

| Capability | Mailgun | SES | Postmark | Resend |
|-----------|---------|-----|----------|--------|
| Core (send/receive/domain) | Yes | Yes (SendRawEmail with attachments) | Yes | Yes |
| DKIM rotation | Yes (v1 API) | Yes (disable/re-enable Easy DKIM) | No | No |
| Domain listing | Yes | Yes (v2 API) | — | — |
| Webhooks | Yes (native) | Yes (via SNS configuration sets) | — | — |
| Inbound routes | Yes (global, filtered) | No | — | — |
| Event log | Yes (queryable) | No (use CloudWatch) | — | — |
| Suppressions | Yes (per-domain) | Partial (account-level, not per-domain) | — | — |
| Delivery stats | Yes (per-domain) | Partial (account-level, 14-day rolling window) | — | — |
| Tracking settings | Yes (click/open/unsub) | No (managed via AWS console) | — | — |

### Controller Pattern

`ProviderManagementController` is provider-agnostic. It:

1. **Resolves** the domain + provider via `resolveManagementProvider(request, domainId)`
2. **Checks** `instanceof` for the relevant concern interface
3. **Gets credentials** via `$domain->getEffectiveConfig()`
4. **Wraps** the call with `wrapProviderCall()` for error handling
5. **Audits** important actions via `AuditService`

```php
// Example: DKIM rotation endpoint
public function rotateDkim(Request $request, int $domainId): JsonResponse
{
    [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);

    if (! $provider instanceof HasDkimManagement) {
        return $this->errorResponse('Provider does not support DKIM management', 422);
    }

    return $this->wrapProviderCall(function () use ($domain, $provider) {
        $result = $provider->rotateDkimKey($domain->name, $domain->getEffectiveConfig());
        // ... audit logging, timestamp update
        return $this->dataResponse(['data' => $result]);
    });
}
```

### Error Handling

```
ProviderApiException (extends RuntimeException)
├── $httpStatus   — HTTP status from provider API
└── $responseBody — Decoded response body

Controller Error Mapping:
├── 401/403 from provider → 502 to frontend (prevents session-expired intercept)
├── Other 4xx/5xx → passed through
└── Exceptions → caught in wrapProviderCall()
```

### Credential Resolution

Three-tier priority for provider credentials:

1. **Account FK** — `EmailProviderAccount.credentials` (encrypted) + domain overrides merged
2. **Domain config** — `EmailDomain.provider_config` (encrypted, legacy)
3. **System defaults** — `SettingService` fallback per provider (e.g., `mailgun.api_key`)

```
EmailProviderAccount (per-user, per-provider)
├── credentials: encrypted JSON {api_key, region, ...}
├── is_default: bool (one default per provider per user)
└── Required fields vary by provider (see credentialFieldsFor())

EmailDomain
├── email_provider_account_id: FK (preferred)
├── provider_config: encrypted JSON (override or legacy)
└── getEffectiveConfig() merges account + domain overrides
```

### Webhook Architecture

Two webhook endpoints, provider-agnostic via `{provider}` route parameter:

| Route | Purpose | Handler |
|-------|---------|---------|
| `POST /api/email/webhook/{provider}` | Inbound email | `EmailWebhookController::handle()` |
| `POST /api/email/webhook/{provider}/events` | Delivery events | `EmailWebhookController::handleEvent()` |

Flow:
1. Verify provider-specific signature (HMAC-SHA256 for Mailgun, SNS certificate for SES)
2. Parse payload via provider's `parseInboundEmail()` or `parseDeliveryEvent()`
3. Process through `EmailService` (inbound) or update delivery status (events)
4. Return 200 even on processing errors (prevents provider retries)

### Auto-Configuration on Domain Creation

`DomainService::createDomain()` automatically:
1. Registers domain with provider (`addDomain()`)
2. Configures inbound webhook route
3. Creates delivery event webhooks (delivered, permanent_fail, complained, stored)
4. Re-associates orphaned mailboxes from previously deleted domain

### Result DTOs

| DTO | Fields | Factory Methods |
|-----|--------|-----------------|
| `ParsedEmail` | from, to, cc, bcc, subject, body, headers, attachments, messageId, spamScore | Constructor |
| `SendResult` | success, providerMessageId, error | `success()`, `failure()` |
| `DomainResult` | success, providerDomainId, dnsRecords, error | `success()`, `failure()` |
| `DomainVerificationResult` | isVerified, dnsRecords, error | Constructor |

## API Routes

### Domain CRUD (`/api/email/domains`)

```
GET    /                          → List user domains
POST   /                          → Create domain (registers with provider)
GET    /{emailDomain}             → Show domain with mailboxes
PUT    /{emailDomain}             → Update (catchall, config, account)
DELETE /{emailDomain}             → Delete (orphans mailboxes)
POST   /{emailDomain}/verify      → Check DNS verification
```

### Management (`/api/email/domains/{domainId}/management`)

```
GET  /capabilities                          → Provider capability map
GET  /dkim                                  → Current DKIM key/selector
POST /dkim/rotate                           → Rotate DKIM key
GET  /dkim/rotation-history                 → Audit log of rotations
GET  /webhooks                              → List domain webhooks
POST /webhooks                              → Create webhook
POST /webhooks/auto-configure               → Upsert all event webhooks
POST /webhooks/{webhookId}/test             → Send test payload
PUT  /webhooks/{webhookId}                  → Update webhook URL
DELETE /webhooks/{webhookId}                → Delete webhook
GET  /routes                                → List inbound routes
POST /routes                                → Create route
PUT  /routes/{routeId}                      → Update route
DELETE /routes/{routeId}                    → Delete route
GET  /events                                → Query event log
GET  /suppressions/check                    → Check single address
POST /suppressions/check-batch              → Batch check (up to 15)
GET  /suppressions/{type}                   → List bounces/complaints/unsubscribes
GET  /suppressions/{type}/export            → CSV download (streamed)
POST /suppressions/{type}/import            → CSV upload (max 5MB)
DELETE /suppressions/{type}/{address}        → Remove suppression entry
GET  /tracking                              → Tracking settings
PUT  /tracking/{type}                       → Toggle click/open/unsub tracking
GET  /stats                                 → Delivery statistics
```

## Key Files

| Area | Files |
|------|-------|
| Core Interface | `backend/app/Services/Email/EmailProviderInterface.php` |
| Management Interface | `backend/app/Services/Email/ProviderManagementInterface.php` |
| Concern Interfaces | `backend/app/Services/Email/Concerns/Has*.php` (7 files) |
| Mailgun Provider | `backend/app/Services/Email/MailgunProvider.php` |
| SES Provider | `backend/app/Services/Email/SesProvider.php` |
| Result DTOs | `backend/app/Services/Email/ParsedEmail.php`, `SendResult.php`, `DomainResult.php`, `DomainVerificationResult.php` |
| Exception | `backend/app/Exceptions/ProviderApiException.php` |
| Management Controller | `backend/app/Http/Controllers/Api/ProviderManagementController.php` |
| Domain Service | `backend/app/Services/Email/DomainService.php` |
| Webhook Controller | `backend/app/Http/Controllers/Api/EmailWebhookController.php` |
| Domain Controller | `backend/app/Http/Controllers/Api/EmailDomainController.php` |
| Account Model | `backend/app/Models/EmailProviderAccount.php` |
| Domain Model | `backend/app/Models/EmailDomain.php` |
| Routes | `backend/routes/api.php` (email prefix group) |
| Tests | `backend/tests/Feature/MailgunManagementTest.php` |
| Provider Comparison | `docs/plans/email-provider-comparison.md` |

## Alternatives Considered

1. **Separate controller per provider** — Rejected. Would duplicate capability checking, error handling, audit logging, and credential resolution across every provider controller. The `instanceof` pattern centralizes all of this.

2. **Single monolithic interface with all methods** — Rejected. Forces providers to implement stub methods for unsupported features (e.g., SES implementing `listRoutes()` as empty). Concern interfaces let providers opt in to only what they support.

3. **Feature flags in config instead of interfaces** — Rejected. PHP `instanceof` checks provide compile-time safety and IDE support. Config-based flags would allow calling methods that don't exist.

4. **Abstract base class with optional method overrides** — Rejected. PHP doesn't support optional interface methods. An abstract class would couple providers to a shared implementation and make testing harder.

## Consequences

- **Positive**: New providers only implement interfaces for capabilities they support — no dead code
- **Positive**: Controller is provider-agnostic — adding a provider requires zero controller changes
- **Positive**: Frontend can query `/capabilities` to show/hide management UI sections dynamically
- **Positive**: Credential hierarchy (account → domain → system) supports multi-account setups
- **Negative**: Provider API differences require per-provider translation (e.g., SES uses SNS for webhooks, Mailgun uses native webhooks)
- **Negative**: Some capabilities are approximate — SES suppressions are account-level, not domain-level, but the interface assumes domain-level
