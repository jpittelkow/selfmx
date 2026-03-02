# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
