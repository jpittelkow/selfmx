# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).















## [0.2.17] - 2026-03-10

### Added
- Domain import UI, provider guides, and emoji branding
## [0.2.16] - 2026-03-09

### Added
- Provider account domain listing and auto-import from Mailgun
## [0.2.13] - 2026-03-05

### Fixed
- Correct Mailgun webhook events, stats query, DMARC records, and queue monitor
## [0.2.12] - 2026-03-05

### Added
- Complete code review Phase 5 G�� test coverage and bug fix
## [0.2.11] - 2026-03-04

### Changed
- Centralize settings masking and encryption in SettingService, fix webhook interface
## [0.2.10] - 2026-03-04

### Fixed
- Inline log viewer, Mailgun webhook error messages, email provider validation
## [0.2.9] - 2026-03-04

### Added
- Mailbox forwarding with email forwarding service, API endpoints, and management UI
## [0.2.8] - 2026-03-04

### Fixed
- Mailgun API fixes (form-encoded requests, DKIM v3 endpoint, webhook URL format, auth error mapping), delivery status sync from provider events, inbox inbound-only filtering
## [0.2.7] - 2026-03-04

### Fixed
- Cast FieldError types in notifications page, add lint and build to push script
## [0.2.6] - 2026-03-04

### Fixed
- CI failures - TypeScript type cast and composer audit tolerance
## [0.2.5] - 2026-03-04

### Fixed
- Code review remediation - security hardening, input validation, and refactoring
## [0.2.4] - 2026-03-03

### Added
- Mailgun deep integration, email compose improvements, and UI refinements
## [0.2.3] - 2026-03-02

### Fixed
- Deduplicate emails before adding unique index to prevent migration failure
## [0.2.2] - 2026-03-02

### Added
- Implement Mailgun management API, email UI improvements, webhook tests, and DKIM rotation
- Implement email app Phase 1 - domains, mailboxes, threads, compose, contacts, labels, rules, spam filters, AI, and provider settings

### Fixed
- Release workflow race condition, update changelog and Phase 7 roadmap

### Changed
- Release v0.2.1
- Release v0.2.1
- Release v0.2.0
- Release v0.2.0
- Release v0.8.2
## [Unreleased]

## [0.2.1] - 2026-03-02

### Added
- **Mailgun Management API (Phase 7 partial)** — Full domain management dashboard accessible from Configuration > Email Domains > domain detail page
  - **DNS Records** — Display required records (SPF, DKIM, MX, tracking CNAME) with copy-to-clipboard and validity indicators; one-click "Verify Now"
  - **DKIM** — View current DKIM selector and public key; rotate key on demand with audit logging and `dkim_rotated_at` tracking; `RotateDkimKeysCommand` artisan command
  - **Webhooks** — List, create, update, delete per-event webhooks (delivered, opened, clicked, bounced, complained, unsubscribed, stored); auto-configure delivery webhooks with one click
  - **Inbound Routes** — List, create, delete Mailgun routing rules with expression/actions/priority
  - **Event Log** — Query Mailgun events with filters (event type, recipient); paginated results with severity-colored badges
  - **Suppressions** — View and remove bounces, complaints, and unsubscribes; check suppression status for specific addresses
  - **Tracking** — Toggle open, click, and unsubscribe tracking per domain via switches
  - **Stats** — Domain-level sending stats (accepted, delivered, failed, complained) with delivery/bounce/complaint rate cards; 7d/30d/90d duration selector; daily breakdown table
  - **Provider Health** — API connectivity check endpoint
- **Email webhook tests** — Comprehensive Pest test suite for Mailgun webhook ingestion (signature validation, email creation, delivery status updates, duplicate handling, spam detection, threading)
- **Unread count in browser tab title** — Mail page now shows `(N) Inbox | appName` in the document title, updating dynamically
- **Email UI improvements** — Enhanced compose dialog, improved thread list, email detail view refinements, thread list skeleton improvements

### Changed
- Added `dkim_rotated_at` column to `email_domains` table
- Added unique constraint on `mailbox_id` + `message_id` in `emails` table to prevent duplicate ingestion
- Added `PruneWebhookLogsCommand` for log cleanup
- New planning docs: email design audit roadmap, email import move plan, Mailgun Phase 7 plan/roadmap, Cloudflare Phase 8 roadmap, email Phase 3 plan

## [0.2.0] - 2026-03-01

### Added
- **Email Hosting (Phases 1–6)** — Complete self-hosted email using provider APIs
  - **Phase 1: Foundation** — Email provider abstraction with Mailgun; domain management (add, verify DNS, catchall, delete); mailbox management; inbound webhook processing; header-based conversation threading; Gmail-style labels; three-panel email client UI; compose dialog; HTML rendering in sandboxed iframe; attachment support; email actions (star, read/unread, spam, trash, bulk); system folders (Inbox, Starred, Sent, Drafts, Spam, Trash); admin config pages; webhook idempotency
  - **Phase 2: Compose & Reply** — Rich text compose (TipTap editor); outbound sending via Mailgun API; reply/reply-all/forward with quoted messages; drafts auto-save; per-mailbox signatures; delivery status tracking (delivered, bounced, failed)
  - **Phase 3: Search & Contacts** — Meilisearch full-text indexing; search filters (date, attachment, from, to, label); auto-extracted contacts; contact management (merge, edit, avatars); contact autocomplete in compose
  - **Phase 4: AI Features** — Email summarization and thread summaries; smart categorization/auto-labeling; smart reply suggestions; priority inbox with AI-scored importance
  - **Phase 5: Navigation & Design** — Mail folders in main sidebar; global compose button; 2-panel layout refactor; URL-based view state; unread count badges; standardized config pages with SettingsFormCard; empty states and loading skeletons; mobile responsive refinements; dark mode consistency pass
  - **Phase 6: Advanced Features** — Real-time push via Laravel Reverb; spam filtering (per-user allow/block lists); email rules/filters (auto-label, auto-archive, auto-forward); scheduled send; snooze; multiple providers (AWS SES, SendGrid, Postmark); email import (mbox/eml); keyboard shortcuts; command palette; bulk selection

### Fixed
- Removed orphaned `access_logs` references from database migrations (table was previously removed)
- Fixed entrypoint migration sync to use `cp -rn` (no-overwrite) preserving local edits during development

## [0.1.0] - 2026-02-28

### Added
- Initial project setup based on Sourdough
