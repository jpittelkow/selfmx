# ADR-028: Webhook System

## Status

Accepted

## Date

2026-03-04

## Context

Applications need to notify external systems when events occur (e.g., user actions, system events). We need outbound webhooks with signature verification, delivery tracking, and SSRF protection.

## Decision

Implement an outbound webhook system with the following design:

### Security

- **SSRF protection**: All webhook URLs are validated via `UrlValidationService::validateAndResolve()` before delivery. URLs pointing to internal/private addresses are blocked.
- **Secret encryption**: Webhook secrets are stored using Laravel's `encrypted` cast — encrypted at rest in the database.
- **HMAC signatures**: When a secret is configured, payloads are signed with HMAC-SHA256. The signature is computed over `timestamp.json_payload` to prevent replay attacks.
- **Headers**: `X-Webhook-Timestamp` and `X-Webhook-Signature` (prefixed `sha256=`) are included with signed deliveries.

### Data Model

- `Webhook` model with `name`, `url`, `secret` (encrypted), `events` (JSON array), `active` (boolean), `last_triggered_at`.
- `WebhookDelivery` model tracks each delivery attempt with `event`, `payload`, `response_code`, `response_body`, `success`.
- Secret is `hidden` from serialization — only `secret_set` boolean is exposed in API responses.
- Secret is returned in plaintext only on initial creation.

### API

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/api/webhooks` | List all webhooks |
| `POST` | `/api/webhooks` | Create webhook |
| `GET` | `/api/webhooks/{webhook}` | Get single webhook |
| `PUT` | `/api/webhooks/{webhook}` | Update webhook |
| `DELETE` | `/api/webhooks/{webhook}` | Delete webhook |
| `GET` | `/api/webhooks/{webhook}/deliveries` | Paginated delivery history |
| `POST` | `/api/webhooks/{webhook}/test` | Send test delivery |

### Event Filtering

Webhooks subscribe to specific events via the `events` JSON array. The `shouldTrigger(event)` method checks if the webhook is active and subscribed to the event.

## Consequences

### Positive

- SSRF protection prevents internal network scanning via webhook URLs
- Encrypted secrets protect against database compromise
- HMAC signatures with timestamps prevent replay attacks
- Delivery history enables debugging failed webhooks
- Test endpoint allows verification before relying on webhooks

### Negative

- No automatic retry on failure (deliveries are fire-and-forget)
- No queue-based async delivery — webhooks are sent synchronously

### Neutral

- Webhook model uses Scout/Searchable for admin search
- No user scoping — webhooks are system-wide (admin feature)

## Related Decisions

- [ADR-024](./024-security-hardening.md) — SSRF protection via `UrlValidationService`

## Notes

- Key files: `backend/app/Services/WebhookService.php`, `backend/app/Models/Webhook.php`, `backend/app/Http/Controllers/Api/WebhookController.php`
- Signature verification guide for consumers: compare `sha256=HMAC(timestamp.payload, secret)` against `X-Webhook-Signature` header, reject if timestamp is older than 5 minutes
