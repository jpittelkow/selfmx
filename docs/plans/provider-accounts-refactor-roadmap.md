# Provider Accounts & Multi-Provider Refactor Roadmap

Support multiple accounts from the same provider, expand to new providers, and build toward a provider-agnostic management interface.

## Phase A: Foundation *(Complete)*

**Goal**: Multi-account support with first-class provider accounts entity.

### Database & Models
- [x] `email_provider_accounts` table (encrypted credentials, health status, default flag)
- [x] `email_domains.email_provider_account_id` FK
- [x] Data migration: global settings → provider accounts, link existing domains
- [x] `EmailProviderAccount` model with `supportedProviders()`, `credentialFieldsFor()`
- [x] `EmailDomain.providerAccount()` relationship + `getEffectiveConfig()`

### Backend Services & API
- [x] `ProviderAccountService` — CRUD, test connection, set default, delete blocking
- [x] `DomainService` credential resolution: account → domain config → settings fallback
- [x] `EmailProviderAccountController` — full REST + test + set-default endpoints
- [x] Routes with `can:settings.view` / `can:settings.edit` middleware
- [x] Remove SendGrid provider (`SendGridProvider.php` deleted, settings removed)

### Frontend
- [x] Provider Accounts page (`/configuration/email-accounts`) — cards, add/edit/delete/test
- [x] Email Domains page updated with account selector in Add Domain dialog
- [x] Navigation: "Provider Accounts" in Email Hosting group
- [x] Search registration (backend + frontend)
- [x] Remove SendGrid from email-provider settings page

### Supported Providers
- Mailgun, AWS SES, Postmark (existing)
- Resend, MailerSend, SMTP2GO (new — account creation ready, provider adapters in Phase D)

### Test Coverage *(Complete)*
- [x] **ProviderAccountServiceTest** (20 tests) — CRUD operations, default management, health checks, audit logging
- [x] **ProviderAccountMigrationTest** (10 tests) — account creation, domain linking, custom credentials, multi-provider support
- [x] **ProviderAccountBackwardCompatTest** (8 tests) — credential fallback chain, mixed migration states, deletion safety
- [x] **EmailProviderAccountControllerTest** (15 tests) — all REST endpoints, permissions, validation (existing)

---

## Phase B: Provider Management Interface *(Complete)*

**Goal**: Define a provider-agnostic management contract so each provider reports its capabilities.

- [x] `ProviderManagementInterface` with `getCapabilities(): array`
- [x] Capability sub-interfaces: `HasDkimManagement`, `HasWebhookManagement`, `HasInboundRoutes`, `HasEventLog`, `HasSuppressionManagement`, `HasDeliveryStats`
- [x] Capabilities: `dkim_rotation`, `webhooks`, `inbound_routes`, `events`, `suppressions`, `stats` (+ stubs for `domain_management`, `dns_records`)
- [x] `ProviderApiException` base class; `MailgunApiException` extends it
- [x] Refactor `MailgunProvider` to implement all interfaces + `getCapabilities()`
- [x] Generic `ProviderManagementController` (replaces `MailgunManagementController`)
- [x] Routes: `/api/email/domains/{domainId}/management/...`
- [x] Deprecated `/mailgun/` aliases kept pointing to `ProviderManagementController` (removed in Phase F)
- [x] `ProviderManagementControllerTest` (15 tests)

## Phase C: Frontend Management Dashboard

**Goal**: Provider-agnostic domain management UI that adapts based on provider capabilities.

- [x] Domain detail page renders tabs based on `getCapabilities()` response
- [x] Replace Mailgun-specific management UI with generic components
- [x] Capability-aware empty states ("This provider doesn't support X")

## Phase D: New Provider Adapters *(Complete)*

**Goal**: Full `EmailProviderInterface` + partial `ProviderManagementInterface` for new providers.

- [x] `ResendProvider` — send, receive, webhooks, events, domain management
- [x] `MailerSendProvider` — send, receive, webhooks, events, suppressions, inbound routes, domain management
- [x] `Smtp2GoProvider` — send, receive, events
- [x] Provider-specific credential validation (webhook signing secrets for Resend/MailerSend)
- [x] Refactored `ProviderManagementController` to use `DomainService::resolveProvider()` (eliminates duplication)
- [x] Generalized `ProviderAccountService::testConnection()` — works with all management-capable providers
- [x] Generalized `DomainService::createDomain()` — auto-configures webhooks for any `HasWebhookManagement` provider
- [x] Exception classes: `ResendApiException`, `MailerSendApiException`, `Smtp2GoApiException`

## Phase E: Deep Management for Existing Providers *(Complete)*

**Goal**: Bring Phase 7-style deep management to SES and Postmark.

- [x] **SES**: suppressions, DKIM (Easy DKIM toggle/rotation), webhooks via configuration sets + event destinations, stats via GetSendStatistics, SES v2 API
- [x] **Postmark**: webhooks, stats, suppressions, DKIM rotation, events (message search), tracking settings

## Phase F: Cleanup & Migration Completion ✅

**Goal**: Remove legacy credential paths and old UI.

- [x] Remove old `EmailProviderSettingController` (replaced by slim `EmailHostingSettingController`)
- [x] Remove provider credential groups from `settings-schema.php` (`mailgun`, `ses`, `postmark`)
- [x] Remove `SettingService` fallback reads from `DomainService`
- [x] Remove legacy route `/email-provider-settings` (replaced by `/email-hosting-settings`)
- [x] Remove old email-provider settings page; general settings (spam threshold, attachment size) moved to Provider Accounts page

---

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Account scope | Admin-only (system-wide) | Simplifies permissions; users' domains draw from shared pool |
| SendGrid | Removed | Declining platform, free tier discontinued |
| Credential storage | `encrypted:array` cast | Laravel's built-in encryption for sensitive data |
| Migration strategy | Dual-read fallback | Account FK → domain config → SettingService (backward compat) |
| Starting scope | Phase A only | Get multi-account solid before expanding |
