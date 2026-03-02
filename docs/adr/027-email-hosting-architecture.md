# ADR-027: Email Hosting Architecture

**Status**: Accepted
**Date**: 2026-02-28
**Context**: selfmx needs to send and receive email for user-owned domains

## Decision

Use email provider APIs (Mailgun first, extensible) instead of running IMAP/SMTP servers. Inbound email arrives via provider webhooks; outbound email is sent via provider HTTP APIs. All email is stored in the application database and indexed with Meilisearch.

## Architecture

### Provider Abstraction

```
EmailProviderInterface
├── verifyWebhookSignature(Request): bool
├── parseInboundEmail(Request): ParsedEmail
├── sendEmail(Mailbox, to[], subject, html, text, attachments): SendResult
├── addDomain(domain): DomainResult
├── verifyDomain(domain): DomainVerificationResult
└── configureDomainWebhook(domain, webhookUrl): void
```

Providers implement this interface. `MailgunProvider` is the first implementation. Adding new providers (SES, SendGrid, Postmark) requires implementing the interface and registering the provider name.

### Inbound Email Flow

```
Mailgun webhook → POST /api/email/webhook/mailgun
  → Verify HMAC signature
  → Check idempotency (email_webhook_logs)
  → Parse multipart payload into ParsedEmail
  → Resolve mailbox (exact match → catchall → reject)
  → Check spam score against threshold
  → Resolve conversation thread (In-Reply-To → References → subject)
  → Create Email + EmailRecipients + EmailAttachments
  → Update thread counters
  → Log webhook result
```

### Conversation Threading

Three-tier resolution, matching Gmail/Fastmail behavior:

1. **In-Reply-To header** — Look up the referenced Message-ID in the emails table, use its thread
2. **References header** — Try each Message-ID in the References chain
3. **Subject fallback** — Normalize subject (strip `Re:`, `Fwd:`, etc.), match against the user's existing threads
4. **New thread** — Create a new thread if no match found

### Database Schema

| Table | Purpose |
|-------|---------|
| `email_domains` | Domains registered with providers (encrypted config per domain) |
| `mailboxes` | Email addresses; `*` address = catchall |
| `email_threads` | Conversation groups with denormalized counters |
| `emails` | All messages (inbound + outbound) with full headers |
| `email_recipients` | To/CC/BCC per email |
| `email_attachments` | Files stored via StorageService |
| `email_labels` | User-defined Gmail-style labels |
| `email_label_assignments` | Many-to-many: emails ↔ labels |
| `email_webhook_logs` | Idempotency tracking for webhook deduplication |

All tables (except `email_webhook_logs`) are scoped by `user_id` for multi-tenant isolation.

### System Views vs Labels

System "folders" (Inbox, Starred, Sent, Drafts, Spam, Trash) are **query filters**, not separate storage. For example:
- Inbox = `direction=inbound, is_spam=false, is_trashed=false, is_draft=false`
- Starred = `is_starred=true`
- Sent = `direction=outbound`

Labels are user-defined tags applied via the pivot table. An email can have zero or more labels.

### Settings

Two settings groups in `settings-schema.php`:

- `email_hosting` — `default_provider`, `spam_threshold`, `max_attachment_size`
- `mailgun` — `api_key` (encrypted), `region` (us/eu), `webhook_signing_key` (encrypted)

Per-domain credentials are stored encrypted in `email_domains.provider_config`.

## Key Files

| Area | Files |
|------|-------|
| Models | `backend/app/Models/Email*.php`, `Mailbox.php` |
| Services | `backend/app/Services/Email/EmailService.php`, `DomainService.php`, `SpamFilterService.php` |
| Provider | `backend/app/Services/Email/EmailProviderInterface.php`, `MailgunProvider.php` |
| Controllers | `backend/app/Http/Controllers/Api/Email*.php`, `MailboxController.php` |
| Routes | `backend/routes/api.php` (email prefix) |
| Frontend | `frontend/app/(dashboard)/mail/page.tsx`, `frontend/components/mail/` |
| Config pages | `frontend/app/(dashboard)/configuration/email-provider/`, `email-domains/`, `mailboxes/` |
| Settings | `backend/config/settings-schema.php` (`email_hosting`, `mailgun` groups) |
| Roadmap | `docs/plans/email-app-roadmap.md` |

## Alternatives Considered

1. **Run IMAP/SMTP servers (Dovecot + Postfix)** — Rejected. Complex to configure, requires port 25 access (often blocked by cloud providers), significant operational overhead for spam filtering, TLS certificates, IP reputation management.

2. **Use existing mailbox protocols with relay** — Rejected. Still requires SMTP relay configuration and doesn't simplify the architecture meaningfully.

3. **Folder-based organization** — Rejected in favor of Gmail-style labels. Labels are more flexible (an email can belong to multiple categories) and align with modern email client expectations.

4. **Store emails in object storage** — Rejected. Keeping emails in SQLite/MySQL/PostgreSQL allows full-text search via Meilisearch Scout integration and simplifies queries for inbox views with complex filters.

## Consequences

- **Positive**: No SMTP/IMAP server management, works behind NAT/firewalls, provider handles deliverability and spam preprocessing
- **Positive**: Provider abstraction allows swapping providers without changing business logic
- **Positive**: All data in application DB enables full-text search, AI features, and unified backup
- **Negative**: Dependent on provider API availability and pricing
- **Negative**: Webhook-based inbound has slight latency vs direct SMTP delivery
- **Negative**: Provider-specific webhook formats require per-provider parsing logic
