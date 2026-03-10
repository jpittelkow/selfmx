# Email App Roadmap

Self-hosted email client using email providers (Mailgun, etc.) for domain management, inbound routing via webhooks, and outbound sending via API. See [Email Provider Comparison Guide](email-provider-comparison.md) for provider selection criteria, pricing, and free tier details.

## Phase 1: Foundation — Domains, Mailboxes & Inbound Email *(Complete)*

**Goal**: Receive emails via Mailgun webhook, store them, and view them in a basic inbox.

- [x] Database schema (9 tables: email_domains, mailboxes, email_threads, emails, email_recipients, email_attachments, email_labels, email_label_assignments, email_webhook_logs)
- [x] Models with relationships and Scout search on Email
- [x] Email provider interface + Mailgun implementation
- [x] Core services: EmailService, DomainService, SpamFilterService
- [x] Webhook endpoint for inbound email ingestion
- [x] CRUD controllers for domains, mailboxes, emails, threads, labels, attachments
- [x] Settings schema for email + Mailgun provider config
- [x] Config pages: email provider settings, domain management, mailbox management
- [x] Email client UI: three-panel layout (labels | thread list | email detail)
- [x] Compose dialog for sending email
- [x] Sidebar + search + breadcrumb registration
- [x] Help articles for email client and admin configuration
- [x] ADR-027: Email Hosting Architecture

## Phase 2: Compose, Send & Reply *(Complete)*

**Goal**: Full outbound email capability with rich composition.

- [x] Rich text compose editor (TipTap with formatting toolbar)
- [x] Outbound sending via Mailgun API
- [x] Reply/reply-all/forward with quoted message
- [x] Drafts auto-save (5-second debounce)
- [x] Per-mailbox signatures
- [x] Sent folder (outbound emails with direction filtering)
- [x] Delivery status tracking (delivered, bounced, failed via Mailgun webhooks)

## Phase 3: Search & Contacts *(Complete)*

**Goal**: Full-text search across all emails and automatic contact management.

- [x] Meilisearch full-text indexing of emails (subject, body, from, to)
- [x] Search UI with filters (date range, has:attachment, from:, to:, label:)
- [x] Auto-extracted contacts from email headers
- [x] Contact management (merge, edit, initials-based avatars)
- [x] Contact autocomplete in compose

## Phase 4: AI Features *(Complete)*

**Goal**: Leverage the existing LLM orchestration system for smart email features.

- [x] Email summarization (thread summary)
- [x] Smart categorization / auto-labeling
- [x] Smart reply suggestions
- [x] Priority inbox (AI-scored importance)

## Phase 5: Navigation Integration & Design Review *(Complete)*

**Goal**: Integrate mail navigation into the main app sidebar and polish the UI.

- [x] Move mail folder navigation (Inbox, Starred, Sent, Drafts, Spam, Trash) into main sidebar
- [x] Move label navigation and creation into main sidebar
- [x] Move Compose button into main sidebar (accessible from any page)
- [x] Extract shared mail types to `frontend/lib/mail-types.ts`
- [x] Create `MailDataProvider` context for cross-component mail state (labels, unread count, compose)
- [x] Refactor mail page from 3-panel to 2-panel layout (thread list + detail)
- [x] URL-based view state (`/mail?view=starred`, `/mail?view=label&labelId=5`)
- [x] Global ComposeDialog rendered at AppShell level
- [x] Unread count badge in sidebar with 60-second polling
- [x] Audit existing pages against shadcn blocks catalog
- [x] Compose dialog — upgrade to Gmail-style compact layout with drag-and-drop attachments
- [x] Configuration pages — standardize with `SettingsFormCard`/`SettingsFormField` wrappers, normalized headers
- [x] Empty states (`EmptyState` component), loading skeletons (`ContentSkeleton`, `ThreadListSkeleton`, `EmailDetailSkeleton`), enhanced error boundary
- [x] Mobile responsive refinements (action bar dropdown, scrollable filter chips, responsive headers)
- [x] Dark mode consistency pass (email iframe theme-aware rendering, `dark:prose-invert` in editor)

### Audit Notes

- **Aligns with shadcn patterns**: 2-panel mail layout, settings sidebar+form, card-based config pages, consistent use of shadcn primitives throughout
- **Intentional divergence**: 2-panel (not 3-panel) mail layout — labels are in the main sidebar instead of a separate panel
- **Future improvements for Phase 6**: Data table sorting/selection/column visibility, command palette integration, keyboard shortcuts

## Phase 6: Advanced Features *(Complete)*

**Goal**: Power-user features and operational maturity.

- [x] Real-time push via Laravel Reverb on new email arrival (EmailReceived event, `mail.{userId}` channel)
- [x] Spam filtering improvements (per-user allow/block lists, 3-phase check: block → allow → threshold)
- [x] Email rules/filters (auto-label, auto-archive, auto-forward, condition builder UI)
- [x] Scheduled send (send_at column, ProcessScheduledEmailsCommand scheduler, calendar + time picker UI)
- [x] Snooze (per-user snoozed_until, ProcessSnoozedEmailsCommand, snooze picker with presets)
- [x] Multiple provider support (AWS SES, SendGrid, Postmark — provider config tabs, per-domain provider selection)
- [x] Email import (mbox/eml upload, sync for <10MB, async job for larger, progress polling)
- [x] Keyboard shortcuts (j/k navigate, r/a/f reply/all/forward, c compose, s star, # trash, / search, Escape deselect)
- [x] Command palette (Ctrl+K, mail-specific commands: navigate, compose, search, import)
- [x] Bulk selection in thread list (checkboxes, select all, bulk read/star/trash actions)

## Phase 7: Provider Management — Mailgun Deep Integration *(Partial — v0.2.1)*

**Goal**: Expose the full depth of Mailgun's management APIs so admins can manage domains, DNS, webhooks, deliverability, and suppressions without leaving selfmx.

**Full roadmap**: [→ Mailgun Phase 7 Detailed Roadmap](mailgun-phase7-roadmap.md)

### Domain Management (Enhanced)

- [ ] Retrieve full domain details from Mailgun v4 API (state, created_at, wildcard, force_dkim_authority, DKIM key length)
- [ ] List domains with filtering (active / unverified / disabled) and search
- [ ] Delete domain via Mailgun API (with confirmation + audit log)
- [x] Display required DNS records per domain (SPF, DKIM, MX, tracking CNAME) with copy-to-clipboard
- [x] One-click "Verify Now" trigger via `PUT /v4/domains/{name}/verify` with real-time status feedback
- [ ] Domain health dashboard — show verification state, last verified timestamp, missing/misconfigured records

### DNS Record Visibility

- [x] Fetch and display DNS records from Mailgun (sending records, receiving records, tracking records)
- [ ] Side-by-side comparison: "Required by Mailgun" vs "Found in DNS" (via DNS lookup)
- [x] Record status indicators (valid / missing / mismatch) per record type
- [ ] Auto-refresh DNS status on domain detail page

### DKIM Key Management

- [x] List DKIM signing keys per domain (selector, active status, key length)
- [x] Rotate DKIM key on demand via API (with audit log + dkim_rotated_at tracking)
- [ ] Configure automatic DKIM key rotation schedule (interval setting)
- [x] Show current active DKIM selector in domain detail

### Webhook Management

- [x] List all webhooks per domain (delivered, opened, clicked, bounced, complained, unsubscribed, stored)
- [x] Create / update / delete domain-level webhooks via UI
- [x] Webhook status indicators (configured / not configured per event type)
- [ ] Test webhook endpoint with sample payload
- [x] Auto-configure selfmx webhooks on domain creation (delivery events: delivered, bounced, failed, complained)

### Inbound Route Management

- [x] List Mailgun routes with filter expression and actions
- [x] Create / update / delete routes via UI
- [ ] Route priority ordering (drag to reorder)
- [ ] Show which routes selfmx auto-created vs user-defined

### Email Event Monitoring

- [x] Events log page — query Mailgun Events/Logs API with filters (event type, recipient, date range, subject, message-id)
- [ ] Event timeline per email (sent → delivered → opened → clicked, or sent → bounced)
- [ ] Link from email detail view to provider event history
- [x] Event search with severity indicators (delivered = success, bounced = warning, failed = error)

### Suppression Management

- [x] Bounces list — view, search, remove bounced addresses per domain
- [x] Complaints list — view, search, remove complained addresses per domain
- [x] Unsubscribes list — view, search, remove unsubscribed addresses per domain
- [ ] Bulk import/export suppressions (CSV)
- [ ] Surface suppression warnings when composing to a suppressed address

### Domain Tracking Settings

- [x] View and toggle open tracking, click tracking, unsubscribe tracking per domain
- [ ] Configure tracking CNAME (HTTPS tracking domain) settings
- [x] Show tracking stats summary on domain detail page

### Sending Stats & Reputation

- [x] Domain-level sending stats (accepted, delivered, bounced, complained — hourly/daily/monthly)
- [x] Stats charts on domain detail page (deliverability rate, bounce rate, complaint rate over time)
- [ ] Tag-based stats for outbound email analytics
- [ ] Sending queue status indicator per domain

### Provider Health

- [x] API connectivity check (test Mailgun credentials on settings save)
- [ ] Provider status indicator in admin dashboard (green/yellow/red based on API health)
- [ ] Rate limit awareness — display current usage against Mailgun rate limits

## Phase 8: DNS Management — Cloudflare Integration

**Goal**: Integrate with Cloudflare DNS to automatically manage DNS records required by email providers, keeping mail DNS in sync without manual zone file editing.

**Full roadmap**: [→ Cloudflare Phase 8 Detailed Roadmap](cloudflare-phase8-roadmap.md)

### Cloudflare Connection

- [ ] Cloudflare API token configuration in settings (encrypted, scoped to DNS edit permissions)
- [ ] Zone list — fetch and display all Cloudflare zones associated with the API token
- [ ] Auto-detect which selfmx email domains have matching Cloudflare zones
- [ ] API connectivity test on settings save

### DNS Record Sync

- [ ] Fetch current DNS records from Cloudflare for each email domain's zone
- [ ] Compare Mailgun-required records against Cloudflare-actual records
- [ ] Sync status dashboard: per-domain grid showing each required record type (SPF, DKIM, MX, tracking CNAME) with status (synced / missing / mismatch / extra)
- [ ] One-click "Create Missing Records" — auto-create all missing DNS records in Cloudflare
- [ ] One-click "Fix Mismatched Records" — update records that exist but have wrong values
- [ ] Intelligent SPF merge — append `include:mailgun.org` to existing SPF record instead of overwriting
- [ ] MX record management — add Mailgun MX records (`mxa.mailgun.org`, `mxb.mailgun.org`) with correct priority
- [ ] DKIM TXT record management — create/update DKIM selector record when keys rotate
- [ ] Tracking CNAME management — create tracking subdomain CNAME pointing to Mailgun

### DNS Record Audit

- [ ] Full DNS record list per zone (all record types, not just email-related)
- [ ] Highlight email-related records in zone view
- [ ] Record history / changelog (track when selfmx last modified a record)
- [ ] Dry-run mode — show what would be created/updated before applying changes

### Automated Sync

- [ ] Scheduled DNS check command (artisan command, configurable interval via settings)
- [ ] Notification on DNS drift (records changed or missing outside selfmx)
- [ ] Auto-sync option (opt-in) — automatically fix DNS records when drift detected
- [ ] Post-domain-creation hook — after adding a domain to Mailgun, automatically create DNS records in Cloudflare
- [ ] Post-DKIM-rotation hook — after DKIM key rotation, update Cloudflare DNS record

### Multi-Provider DNS Awareness

- [ ] Provider-agnostic DNS requirement resolution — each email provider reports its required records, Cloudflare integration syncs them
- [ ] Handle multiple email domains across different providers (e.g., domain-a on Mailgun, domain-b on SES) with correct DNS for each
- [ ] Conflict detection — warn if two providers require conflicting DNS records for the same domain

### Future: Additional DNS Providers

- [ ] DNS provider interface abstraction (mirroring email provider pattern)
- [ ] Route 53 implementation (AWS DNS)
- [ ] Google Cloud DNS implementation
- [ ] DigitalOcean DNS implementation
- [ ] Manual DNS provider (display-only — shows required records for user to configure manually)

## Provider Accounts & Multi-Provider Refactor

**Goal**: Support multiple accounts from the same provider, provider-agnostic management interface, new providers.

**Full roadmap**: [→ Provider Accounts Refactor Roadmap](provider-accounts-refactor-roadmap.md)

- [x] Phase A: Foundation — multi-account support, provider accounts entity, SendGrid removed
- [ ] Phase B: Provider Management Interface (`ProviderManagementInterface` with capabilities)
- [ ] Phase C: Frontend Management Dashboard (provider-agnostic domain detail UI)
- [ ] Phase D: New Provider Adapters (Resend, MailerSend, SMTP2GO)
- [ ] Phase E: Deep Management for SES & Postmark
- [ ] Phase F: Cleanup (remove legacy settings fallback)

## Phase 9: Extended Provider Management

**Goal**: Bring the same deep management capabilities from Phase 7 to other email providers.

### AWS SES Management

- [ ] Identity management (domain + email address verification)
- [ ] DKIM configuration (Easy DKIM + BYODKIM)
- [ ] MAIL FROM domain configuration
- [ ] Sending authorization policies
- [ ] Configuration sets (event tracking, IP pools, delivery options)
- [ ] Suppression list management (account-level and per-config-set)
- [ ] Reputation dashboard (bounce rate, complaint rate, sending quota usage)
- [ ] SNS topic management for event notifications
- [ ] Virtual deliverability manager insights (if enabled)
- [ ] Account-level sending quotas and sending rate display

### Resend Management

- [ ] Domain verification and DNS records
- [ ] API key management
- [ ] Email sending and delivery tracking
- [ ] Webhook configuration for events
- [ ] Suppression management

### MailerSend Management

- [ ] Domain verification and DNS records
- [ ] API token management
- [ ] Email sending with templates
- [ ] Analytics and activity tracking
- [ ] Suppression management (bounces, unsubscribes, spam complaints)
- [ ] Inbound routing configuration

### SMTP2GO Management

- [ ] Sender domain verification
- [ ] SMTP credential management
- [ ] Sending statistics and reports
- [ ] Suppression management
- [ ] Webhook configuration

### Postmark Management

- [ ] Server management (list, create, configure)
- [ ] Domain authentication (DKIM, return-path, SPF)
- [ ] DNS record verification
- [ ] Message streams (transactional vs broadcast, inbound)
- [ ] Bounce management (list, activate, deactivate bounces)
- [ ] Suppression management per message stream
- [ ] Server-level stats (delivered, bounced, spam complaints, tracked)
- [ ] Inbound processing rules
- [ ] Webhook configuration per message stream

## Phase 10: GraphQL API Audit — Email Model Coverage

**Goal**: The GraphQL API currently only exposes core Sourdough entities (users, notifications, audit logs, payments, usage stats). None of the email-specific models are available via GraphQL. This phase audits the gap and extends the schema so external API consumers can query and manage email data.

### Audit: Current State

The GraphQL schema (`backend/graphql/schema.graphql`) exposes:
- **Queries**: `me`, `myNotifications`, `myApiKeys`, `myNotificationSettings`, `auditLogs`, `notificationDeliveries`, `payments`, `usageStats`, `usageBreakdown`, `userGroups`, `users`
- **Mutations**: `updateProfile`, `markNotificationAsRead`, `deleteNotifications`, `updateNotificationSettings`, `updateTypePreferences`
- **Auth**: All operations require `api-key` guard (Sanctum API key, not session cookies)
- **Frontend usage**: None — the frontend uses REST exclusively. GraphQL is for external API consumers only.

**Missing**: All email domain models — no way for external integrations to read mailboxes, query emails, send messages, manage labels, or interact with contacts via GraphQL.

### Email Query Types

- [ ] `EmailDomain` type + `emailDomains` query (list user's domains with verification status)
- [ ] `Mailbox` type + `mailboxes` query (list mailboxes, filterable by domain)
- [ ] `EmailThread` type + `emailThreads` query (paginated, filterable by label, starred, read status, date range)
- [ ] `Email` type + `emails` query (paginated, filterable by thread, direction, date range, has:attachment)
- [ ] `email(id: ID!)` single email query with full body, headers, recipients, attachments
- [ ] `EmailRecipient` type (nested on Email: to, cc, bcc)
- [ ] `EmailAttachment` type (nested on Email: filename, mimeType, size, download URL)
- [ ] `EmailLabel` type + `emailLabels` query (list user's labels with unread counts)
- [ ] `Contact` type + `contacts` query (paginated, searchable by name/email)
- [ ] `EmailRule` type + `emailRules` query (list user's filter rules)
- [ ] `SpamFilterList` type + `spamFilterLists` query (user's allow/block lists)

### Email Mutation Types

- [ ] `sendEmail` mutation (to, cc, bcc, subject, htmlBody, textBody, attachments, replyToEmailId)
- [ ] `saveDraft` mutation (create or update a draft email)
- [ ] `replyToEmail` / `forwardEmail` mutations (with quoted content handling)
- [ ] `trashEmails` / `untrashEmails` mutations (bulk by ID list)
- [ ] `markAsRead` / `markAsUnread` mutations (bulk by ID list)
- [ ] `starEmail` / `unstarEmail` mutations (bulk by ID list)
- [ ] `archiveEmails` / `unarchiveEmails` mutations
- [ ] `applyLabel` / `removeLabel` mutations (email IDs + label ID)
- [ ] `createLabel` / `updateLabel` / `deleteLabel` mutations
- [ ] `createMailbox` / `updateMailbox` / `deleteMailbox` mutations
- [ ] `createContact` / `updateContact` / `deleteContact` / `mergeContacts` mutations
- [ ] `createEmailRule` / `updateEmailRule` / `deleteEmailRule` mutations
- [ ] `updateSpamFilterList` mutation (add/remove entries from allow/block lists)
- [ ] `scheduleEmail` mutation (send_at parameter)
- [ ] `snoozeEmail` / `unsnoozeEmail` mutations (snoozed_until parameter)

### Subscription Types (if using GraphQL subscriptions)

- [ ] Evaluate whether to add GraphQL subscriptions for real-time email events (new email, delivery status changes)
- [ ] `emailReceived` subscription (new inbound email for a mailbox)
- [ ] `deliveryStatusChanged` subscription (sent email delivery updates)
- [ ] Decide: WebSocket subscriptions vs polling — currently using Laravel Reverb for frontend push, evaluate if external consumers need the same

### Schema Design Decisions

- [ ] Decide on connection-based pagination (Relay cursor style) vs offset pagination (current pattern) for email queries
- [ ] Decide depth limits for nested types (e.g., thread → emails → recipients → contact) to prevent expensive queries
- [ ] Decide on attachment content access — download URL only vs inline base64 (size considerations)
- [ ] Define rate limits per query type (email listing vs single email vs send operations)
- [ ] Define permission model — reuse existing user scoping (`user_id` filter) or add GraphQL-specific scopes

### Backend Implementation

- [ ] Add GraphQL types for all email models to `schema.graphql`
- [ ] Create query resolvers in `backend/app/GraphQL/Queries/` (Email*, Mailbox, Contact, etc.)
- [ ] Create mutation resolvers in `backend/app/GraphQL/Mutations/` (SendEmail, TrashEmails, etc.)
- [ ] Ensure all queries are user-scoped (filter by authenticated user's `id`)
- [ ] Add input validation matching existing REST request validation (SendEmailRequest, SaveDraftRequest)
- [ ] Add query complexity scoring to prevent abuse (especially for nested email → thread → emails queries)
- [ ] Audit log integration — mutations should generate the same audit events as REST endpoints

### Admin Queries

- [ ] `adminEmailDomains` query (all domains across all users, requires admin permission)
- [ ] `adminMailboxes` query (all mailboxes, requires admin permission)
- [ ] `adminEmailStats` query (system-wide email volume, delivery rates, storage usage)
- [ ] `adminWebhookLogs` query (webhook ingestion logs for debugging)

### Documentation & Testing

- [ ] Update GraphQL playground/introspection with email type descriptions
- [ ] Add example queries to API documentation (`docs/api/`)
- [ ] Update OpenAPI spec or create separate GraphQL schema docs
- [ ] Write Pest tests for all new queries and mutations (auth, scoping, pagination, edge cases)
- [ ] Update GraphQL config page to show email-related usage stats

## Phase 11: Mail Forwarding *(Complete — v0.3.0)*

**Goal**: First-class mail forwarding — forward specific mailboxes or catchall mail to external email addresses, with per-forward choice of keeping a local copy or passing through.

### Forwarding Configuration

- [x] `mailbox_forwards` table (user_id, mailbox_id, forward_to, keep_local_copy, is_active)
- [x] `MailboxForward` model with `Mailbox` and `User` relationships
- [x] `MailboxForwardController` API — GET/PUT/DELETE per mailbox (`/api/email/mailboxes/{mailbox}/forward`)

### Forwarding Engine

- [x] `EmailForwardingService` — handles pass-through (no local storage) and keep-copy (store + forward) flows
- [x] Integrate into `EmailService::processInboundEmail` — check for active forward before/after DB transaction
- [x] Pass-through mode: forward via provider API, skip local email storage entirely
- [x] Keep-copy mode: store email locally, then forward after transaction commits
- [x] Transparent forwarding: preserve original From, add `X-Forwarded-To` header

### Frontend

- [x] Forwarding section in Edit Mailbox dialog (toggle, forward-to address, keep local copy toggle)
- [x] Forwarding badge on mailbox table rows
- [x] Catchall forwarding: forward on wildcard/catchall mailbox applies to all unmatched domain mail

### Testing

- [x] Unit tests for `EmailForwardingService` (11 tests)
- [x] Feature tests for both forwarding modes in `processInboundEmail`
- [x] API endpoint tests (CRUD, user scoping, validation) (7 tests)
