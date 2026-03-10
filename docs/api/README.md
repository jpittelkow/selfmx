# selfmx API Documentation

This document provides an overview of the selfmx REST API. For complete API specification, see [openapi.yaml](./openapi.yaml).

## Base URL

- **Development**: `http://localhost:8080/api`
- **Production**: `https://your-domain.com/api`

## Authentication

selfmx uses Laravel Sanctum for session-based authentication. Include credentials with every request:

```javascript
const response = await fetch('/api/auth/user', {
  credentials: 'include',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
});
```

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/check-email` | Check email availability for signup (body: `email`; rate limited) |
| POST | `/auth/register` | Register a new user |
| POST | `/auth/login` | Login with email/password |
| POST | `/auth/logout` | Logout current user |
| GET | `/auth/user` | Get current user |
| POST | `/auth/forgot-password` | Request password reset (returns 503 if email not configured or password reset disabled by admin) |
| POST | `/auth/reset-password` | Reset password with token |
| POST | `/auth/verify-email` | Verify email address |
| POST | `/auth/resend-verification` | Resend verification email |

### Single Sign-On (SSO)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/auth/sso/providers` | List available SSO providers |
| GET | `/auth/sso/{provider}` | Redirect to SSO provider |
| GET | `/auth/callback/{provider}` | SSO callback handler |
| POST | `/auth/sso/{provider}/link` | Link SSO to existing account |
| DELETE | `/auth/sso/{provider}/unlink` | Unlink SSO provider |

**Supported providers**: Google, GitHub, Microsoft, Apple, Discord, GitLab, Generic OIDC

### Two-Factor Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/auth/2fa/status` | Get 2FA status |
| POST | `/auth/2fa/enable` | Start 2FA setup |
| POST | `/auth/2fa/confirm` | Confirm 2FA with TOTP code |
| POST | `/auth/2fa/disable` | Disable 2FA |
| POST | `/auth/2fa/verify` | Verify 2FA during login |
| GET | `/auth/2fa/recovery-codes` | Get recovery codes |
| POST | `/auth/2fa/recovery-codes/regenerate` | Regenerate recovery codes |

### Profile

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/profile` | Get user profile |
| PUT | `/profile` | Update profile |
| PUT | `/profile/password` | Update password |
| DELETE | `/profile` | Delete account |

### Dashboard

Dashboard uses static, developer-defined widgets. Data endpoints for widgets:

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/dashboard/stats` | Metrics for stats widget (Total Users, Storage Used). Returns `{ metrics: [{ label, value }] }`. |

See [Recipe: Add Dashboard Widget](../ai/recipes/add-dashboard-widget.md) for adding new widgets and endpoints.

### User Management (Admin)

Requires `admin` ability. Admin CRUD for users; disabled users cannot log in.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/users` | List users (query: `page`, `per_page`, `search`) |
| POST | `/users` | Create user (body: name, email, password, admin, skip_verification). Admin role is assigned via `admin` boolean (admin group vs default group). |
| GET | `/users/{user}` | Get user |
| PUT | `/users/{user}` | Update user (body: name, email, password). Admin role is changed via `PUT /users/{user}/groups`. |
| PUT | `/users/{user}/groups` | Update user's groups (body: group_ids). Include admin group id to grant admin. |
| DELETE | `/users/{user}` | Delete user |
| POST | `/users/{user}/toggle-admin` | Toggle admin group membership (add or remove from admin group) |
| POST | `/users/{user}/reset-password` | Reset password (body: password) |
| POST | `/users/{user}/disable` | Toggle disabled status |
| POST | `/users/{user}/resend-verification` | Resend verification email (rate limited: 1 per 5 min per user) |

### Audit Logs (Admin)

Requires `admin` ability. See [Audit Logs roadmap](../plans/audit-logs-roadmap.md), [Recipe: Trigger audit logging](../ai/recipes/trigger-audit-logging.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/audit-logs` | List audit logs (paginated). Query: `page`, `per_page`, `user_id`, `action` (search), `severity`, `date_from`, `date_to`. Response: Laravel pagination (`data`, `current_page`, `last_page`, `per_page`, `total`). |
| GET | `/audit-logs/export` | Export as CSV. Query: same filters as list. |
| GET | `/audit-logs/stats` | Statistics. Query: `date_from`, `date_to` (default last 30 days). Response: `total_actions`, `by_severity` (severity→count), `daily_trends` (date→count), `recent_warnings` (latest 5 warning/error/critical with user), `actions_by_type`, `actions_by_user`. |

### Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/settings` | Get all settings |
| PUT | `/settings` | Update settings |
| GET | `/settings/{group}` | Get settings by group |
| PUT | `/settings/{group}` | Update settings group |

### System Settings (Admin)

Requires `can:settings.view` / `can:settings.edit`. System-wide settings stored in the database with env fallback. Keys are grouped (e.g. `mail`, `backup`, `search`). See [ADR-014](../adr/014-database-settings-env-fallback.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/system-settings/public` | Public settings (no auth). Returns `settings` object (`general.app_name`, `general.app_url`, etc.) and `features` object (`email_configured`, `password_reset_available`, `email_verification_mode`, `two_factor_mode`). |
| GET | `/system-settings` | Get all system settings (grouped). |
| PUT | `/system-settings` | Update system settings (body: flat key→value map). |
| GET | `/system-settings/{group}` | Get settings for a specific group (e.g. `mail`, `backup`, `search`). |

### Mail Settings (Admin)

Settings are stored in the database with env fallback (see [ADR-014](../adr/014-database-settings-env-fallback.md)). Response/request keys use frontend names (e.g. `provider`, `host`, `port`); storage uses schema keys (e.g. `mailer`, `smtp_host`, `smtp_port`).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/mail-settings` | Get mail settings (provider, host, port, from_address, etc.) |
| PUT | `/mail-settings` | Update mail settings (same keys as GET) |
| POST | `/mail-settings/test` | Send test email (body: `{ "to": "email@example.com" }`) |
| DELETE | `/mail-settings/keys/{key}` | Reset one setting to env default. `{key}` must be the **schema key** (e.g. `smtp_password`, `mailer`, `from_address`), not the frontend key. |

### SSO Settings (Admin)

Requires `manage-settings` ability. SSO configuration is stored in the database with env fallback ([ADR-014](../adr/014-database-settings-env-fallback.md)). Keys match the `sso` group in `backend/config/settings-schema.php` (e.g. per-provider `{provider}_client_id`, `{provider}_client_secret`, `{provider}_enabled`, `{provider}_test_passed`; OIDC: `oidc_issuer_url`, etc.). Client secrets are encrypted at rest. A provider appears on the login page only when it has credentials, has passed the test, and is enabled.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/sso-settings` | Get SSO settings (all provider keys, including `*_enabled`, `*_test_passed`) |
| PUT | `/sso-settings` | Update SSO settings. **Partial payload supported:** send only global keys (`enabled`, `allow_linking`, `auto_register`, `trust_provider_email`) or one provider's keys (e.g. `google_enabled`, `google_client_id`, `google_client_secret`) to save that section. Changing credentials clears `*_test_passed` for that provider. |
| POST | `/sso-settings/test/{provider}` | Test provider credentials (validates at token endpoint). Sets `{provider}_test_passed` on success. `{provider}`: e.g. `google`, `github`, `microsoft`, `apple`, `discord`, `gitlab`, `oidc`. |
| DELETE | `/sso-settings/keys/{key}` | Reset one setting to env default. `{key}` must be the **schema key**. |

### Auth Settings (Admin)

Requires `manage-settings` ability. Authentication feature toggles are stored in the database with env fallback ([ADR-014](../adr/014-database-settings-env-fallback.md)). Keys match the `auth` group in `backend/config/settings-schema.php`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/auth-settings` | Get auth settings (`email_verification_mode`, `password_reset_enabled`, `two_factor_mode`) |
| PUT | `/auth-settings` | Update auth settings. Body: `email_verification_mode` (disabled\|optional\|required), `password_reset_enabled` (boolean), `two_factor_mode` (disabled\|optional\|required). |

**Public features:** `GET /system-settings/public` (no auth) returns `settings` (e.g. `settings.general.app_name`, `settings.general.app_url`) and a `features` object: `email_configured`, `password_reset_available` (email configured and password reset enabled), `email_verification_mode`, `two_factor_mode`. The app name is used for page titles, branding, and PWA manifest. Login and forgot-password pages use features to show/hide the "Forgot password?" link and enforce 2FA setup when required. Note: `app_url` is sourced from the `APP_URL` environment variable (not stored in the database).

### Email Templates (Admin)

Requires `manage-settings` ability. Templates are stored in the database; default content is seeded from `EmailTemplateSeeder`. See [ADR-016: Email Template System](../adr/016-email-template-system.md) and [Recipe: Add Email Template](../ai/recipes/add-email-template.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/email-templates` | List all templates (key, name, description, is_system, is_active, updated_at) |
| GET | `/email-templates/{key}` | Get single template (full content: subject, body_html, body_text, variables, etc.) |
| PUT | `/email-templates/{key}` | Update template (body: optional subject, body_html, body_text, is_active) |
| POST | `/email-templates/{key}/preview` | Preview with variables. Body: optional `variables` (object); optional `subject`, `body_html`, `body_text` for live preview of unsaved content. Response: subject, html, text. |
| POST | `/email-templates/{key}/test` | Send test email. Body: optional `to` (email; defaults to current user). Returns 503 if email not configured. |
| POST | `/email-templates/{key}/reset` | Reset system template to default content (403 if not system template). |

### Notification Templates (Admin)

Requires `settings.view` / `settings.edit` abilities. Per-type, per-channel-group templates for notification content. See [ADR-017: Notification Template System](../adr/017-notification-template-system.md) and [Recipe: Add Notification Template](../ai/recipes/add-notification-template.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notification-templates` | List all notification templates |
| GET | `/notification-templates/{id}` | Get single template (includes siblings for same type across channel groups) |
| PUT | `/notification-templates/{id}` | Update template (body: title, body, is_active). Requires `settings.edit`. |
| POST | `/notification-templates/{id}/preview` | Preview with variables. Body: optional `variables` (object); optional `title`, `body` for live preview. |
| POST | `/notification-templates/{id}/reset` | Reset system template to default content. Requires `settings.edit`. |

Channel groups: `push` (WebPush, FCM, ntfy), `inapp` (database/in-app), `chat` (Telegram, Discord, Slack, Twilio, Signal, Matrix, Vonage, SNS), `email` (per-type email templates).

### Notifications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notifications` | List notifications (params: page, per_page, unread_only, category) |
| GET | `/notifications/unread-count` | Get unread count |
| POST | `/notifications/mark-read` | Mark specific as read (body: `ids` array of UUIDs) |
| POST | `/notifications/mark-all-read` | Mark all as read |
| DELETE | `/notifications/{id}` | Delete notification |
| POST | `/notifications/delete-batch` | Batch delete notifications (body: `ids` array, max 100) |
| POST | `/notifications/test/{channel}` | Send test notification (throttled: 5/min) |
| GET | `/notifications/diagnose-push` | Diagnostic for web push delivery gates |

### Notification Settings (Admin)

System-level notification channel configuration. Requires admin access.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notification-settings` | Get system notification settings |
| PUT | `/notification-settings` | Update notification settings |
| POST | `/notification-settings/generate-vapid` | Generate WebPush VAPID key pair |
| POST | `/notification-settings/test/{channel}` | Test specific notification channel |
| DELETE | `/notification-settings/keys/{key}` | Reset one setting to env default |

### Admin Notification Channels

Channel availability and provider configuration. Requires admin access.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/notification-channels` | List all channels with config status |
| PUT | `/admin/notification-channels` | Toggle channel availability, set SMS provider |
| POST | `/admin/notification-channels/test-all` | Test all enabled channels |
| GET | `/admin/notification-channels/verify` | Verify channel configuration without sending |

### Notification Deliveries (Admin)

Delivery log for sent notifications. Requires admin access.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notification-deliveries` | List notification delivery logs (paginated) |
| GET | `/notification-deliveries/stats` | Get delivery statistics (success/failure counts) |

### User Notification Settings

Per-user notification preferences and web push subscriptions.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/user/notification-settings` | Get available channels and user preferences |
| PUT | `/user/notification-settings` | Update user channel settings |
| GET | `/user/notification-settings/type-preferences` | Get per-notification-type preferences |
| PUT | `/user/notification-settings/type-preferences` | Update per-type preference |
| GET | `/user/webpush-subscriptions` | List user's web push subscriptions |
| POST | `/user/webpush-subscription` | Register web push subscription (body: endpoint, keys) |
| DELETE | `/user/webpush-subscription` | Unsubscribe all web push |
| DELETE | `/user/webpush-subscription/{id}` | Delete specific web push subscription |

### Novu Integration

Optional Novu notification infrastructure. See [ADR-025: Novu Notification Integration](../adr/025-novu-notification-integration.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/novu/subscriber-hash` | Get HMAC hash for Novu Inbox authentication |

**Novu Settings (Admin):**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/novu-settings` | Get Novu settings (API key masked) |
| PUT | `/novu-settings` | Update Novu settings |
| POST | `/novu-settings/test` | Test Novu connection and validate workflows |
| GET | `/novu-settings/workflow-map` | Get notification type → workflow ID mappings |
| PUT | `/novu-settings/workflow-map` | Update workflow mappings |
| DELETE | `/novu-settings/keys/{key}` | Reset one Novu setting to env default |

### Real-Time Streaming

WebSocket broadcasting via Laravel Reverb. See [ADR-027: Real-Time Streaming](../adr/027-real-time-streaming.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/broadcasting/auth` | Authenticate private channel subscription (Sanctum session) |

Private channels: `app-logs` (admin, `AppLogCreated` events), `audit-logs` (admin, `AuditLogCreated` events), `user.{id}` (per-user, `NotificationSent` events). Requires `NEXT_PUBLIC_REVERB_APP_KEY` to be set on frontend.

### LLM/AI

See [ADR-006: LLM Orchestration Modes](../adr/006-llm-orchestration-modes.md). Requires `can:settings.view` / `can:settings.edit`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/llm/providers` | List configured providers with status |
| POST | `/llm/providers` | Add a provider (body: `provider`, `api_key`, `host?`, `model?`). |
| PUT | `/llm/providers/{provider}` | Update a provider's config. |
| DELETE | `/llm/providers/{provider}` | Remove a provider. |
| GET | `/llm/config` | Get LLM orchestration config (mode, primary provider, enabled providers) |
| PUT | `/llm/config` | Update LLM config (body: `mode`, `providers` array) |
| POST | `/llm/test/{provider}` | Test provider connection |
| POST | `/llm/query` | Send text query (body: `prompt`, `mode?`, `provider?`) |
| POST | `/llm/query/vision` | Send vision query (body: `prompt`, `image` file or `image_url`, `provider?`) |

**LLM Settings (Admin)**:

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/llm-settings` | Get LLM system settings |
| PUT | `/llm-settings` | Update LLM system settings |
| DELETE | `/llm-settings/keys/{key}` | Reset one LLM setting to default |
| POST | `/llm-settings/test-key` | Validate API key (body: `provider`, `api_key?`, `host?`). Returns `{ valid, error? }`. |
| POST | `/llm-settings/discover-models` | Fetch available models (body: `provider`, `api_key?`, `host?`). Returns `{ models, provider }`. |

**Supported providers**: Claude (Anthropic), OpenAI, Gemini, Ollama, Azure OpenAI, AWS Bedrock. Model discovery supported for OpenAI, Claude, Gemini, Ollama.

**Operating modes**:
- `single` - Use one provider
- `aggregation` - Query all, primary synthesizes
- `council` - All providers vote, consensus resolution

### Stripe Connect (Admin)

Requires `can:settings.view` / `can:settings.edit`. See [ADR-026: Stripe Connect](../adr/026-stripe-connect-integration.md).

**Stripe Connect:**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/stripe/connect/status` | Get Stripe Connect account status (connected, account_id, details_submitted, charges_enabled) |
| POST | `/stripe/connect/oauth-link` | Generate OAuth link to start Connect onboarding |
| POST | `/stripe/connect/account-link` | Generate account link for returning users to complete onboarding |
| POST | `/stripe/connect/login-link` | Generate Stripe Express Dashboard login link |
| DELETE | `/stripe/connect/disconnect` | Disconnect and deauthorize Stripe Connect account |

**Stripe Settings (Admin)**:

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/stripe/settings` | Get Stripe settings (keys masked) |
| PUT | `/stripe/settings` | Update Stripe settings (body: `secret_key`, `publishable_key`, `webhook_secret`, `mode`, `currency`, etc.) |
| POST | `/stripe/settings/test` | Test Stripe API key connection |
| DELETE | `/stripe/settings/keys/{key}` | Reset one setting to env default |

**Payments:**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/payments` | List current user's payments |
| POST | `/payments/intent` | Create a payment intent (body: `amount`, `currency?`, `metadata?`) |
| GET | `/payments/admin` | List all payments — admin only, requires `can:payments.manage` |
| GET | `/payments/{payment}` | Get a single payment |

**Stripe Webhook (public):**

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/stripe/webhook` | Stripe webhook receiver (no auth; signature verified via `STRIPE_WEBHOOK_SECRET`) |

### Outbound Webhooks (Admin)

Requires `can:settings.view` / `can:settings.edit`. See [ADR-028: Webhook System](../adr/028-webhook-system.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/webhooks` | List all outbound webhooks |
| POST | `/webhooks` | Create webhook (body: `name`, `url`, `events` array, `secret?`, `active?`) |
| GET | `/webhooks/{webhook}` | Get single webhook |
| PUT | `/webhooks/{webhook}` | Update webhook |
| DELETE | `/webhooks/{webhook}` | Delete webhook |
| GET | `/webhooks/{webhook}/deliveries` | Paginated delivery history (response: delivery logs with `event`, `payload`, `response_code`, `success`) |
| POST | `/webhooks/{webhook}/test` | Send a test delivery to this webhook |

Webhook payloads include `X-Webhook-Timestamp` and `X-Webhook-Signature` (`sha256=HMAC(timestamp.payload, secret)`) headers for verification.

### Integration Usage & Costs (Admin)

Requires `can:usage.view` / `can:logs.export`. See [ADR-029: Usage Tracking & Alerts](../adr/029-usage-tracking-alerts.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/usage/stats` | Aggregated usage stats. Query: `date_from`, `date_to`, `integration` (one of `llm,email,sms,storage,broadcasting,payments`), `provider`, `group_by` (`day`\|`week`\|`month`). |
| GET | `/usage/breakdown` | Detailed breakdown for one integration. Query: `date_from`, `date_to`, `integration` (required), `user_id?`. |
| GET | `/usage/export` | Export usage as CSV (streamed). Query: `date_from`, `date_to`, `integration?`, `provider?`. Requires `can:logs.export`. |

### File Manager (Admin)

Requires `can:admin`. Files are served from the active storage provider. See [ADR-030: File Manager](../adr/030-file-manager.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/storage/files` | List files/directories (paginated). Query: `path?`, `page`, `per_page`. |
| POST | `/storage/files` | Upload file(s). Body: multipart `files[]` (array). |
| GET | `/storage/files/{path}` | File or directory details + preview URL. |
| GET | `/storage/files/{path}/download` | Download a file (streamed). |
| PUT | `/storage/files/{path}/rename` | Rename file/directory. Body: `{ name }`. |
| PUT | `/storage/files/{path}/move` | Move file/directory. Body: `{ destination }`. |
| DELETE | `/storage/files/{path}` | Delete file/directory. |

Paths with `..` segments, null bytes, or blocked segments (`.env`, `config`, `.git`, `bootstrap`, `vendor`) are rejected (403).

### Backup & Restore (Admin)

Requires `can:backups.view` / `can:backups.create` / `can:backups.restore` / `can:backups.delete`. See [Backup & Restore documentation](../backup.md) for full context.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/backup` | List backups (response: `backups` array with `filename`, `size`, `created_at`) |
| POST | `/backup/create` | Create backup. Body (optional): `include_database`, `include_files`, `include_settings` (all default true). Response: `filename`, `size`, `manifest` |
| GET | `/backup/download/{filename}` | Download backup file (stream) |
| POST | `/backup/restore` | Restore. Body: either `{ "filename": "selfmx-backup-....zip" }` (restore from existing) or multipart form with backup file (restore from upload) |
| DELETE | `/backup/{filename}` | Delete a backup |

### Backup Settings (Admin)

Requires `manage-settings` ability. Backup configuration is stored in the database with env fallback ([ADR-014](../adr/014-database-settings-env-fallback.md)). Keys match the `backup` group in `backend/config/settings-schema.php` (e.g. `disk`, `retention_enabled`, `retention_days`, `schedule_enabled`, `s3_enabled`, `s3_bucket`, `s3_access_key_id`, `s3_secret_access_key`, …). Sensitive fields are encrypted at rest.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/backup-settings` | Get all backup settings (response: `settings` object) |
| PUT | `/backup-settings` | Update backup settings (body: subset of setting keys) |
| POST | `/backup-settings/reset/{key}` | Reset one setting to env default. `{key}` must be a schema key (e.g. `s3_bucket`, `retention_days`) |
| POST | `/backup-settings/test/{destination}` | Test connection. `{destination}`: `s3`, `sftp`, or `google_drive`. Uses currently saved settings. |

### Storage Settings (Admin)

Requires `manage-settings` ability. Storage configuration (driver, max upload size, allowed file types; provider-specific credentials) is stored in the database with env fallback ([ADR-014](../adr/014-database-settings-env-fallback.md)). Supported drivers: local, s3, gcs, azure, do_spaces, minio, b2. Phase 1 adds paths, health, and usage breakdown; Phase 2 adds multiple cloud providers and connection test.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/storage-settings` | Get storage settings (response: `settings` object) |
| PUT | `/storage-settings` | Update storage settings (driver, max_upload_size, allowed_file_types; provider-specific keys: s3_*, gcs_*, azure_*, do_spaces_*, minio_*, b2_*) |
| POST | `/storage-settings/test` | Test connection for current driver and config. Body: `driver` plus provider-specific fields. Returns `{ success: true }` or `{ success: false, error: "message" }` (422 on failure). |
| GET | `/storage-settings/stats` | Get usage stats (driver, total_size, total_size_formatted, file_count). Local driver only: includes `breakdown` (directory path → size, size_formatted). |
| GET | `/storage-settings/analytics` | Get analytics (local only): by_type, top_files, recent_files. |
| GET | `/storage-settings/cleanup-suggestions` | Get cleanup suggestions (local only): cache, temp, old_backups with count/size. |
| POST | `/storage-settings/cleanup` | Run cleanup. Body: `{ type: "cache" | "temp" | "old_backups" }`. |
| GET | `/storage-settings/paths` | Get storage location paths and descriptions (app, public, backups, cache, sessions, logs). |
| GET | `/storage-settings/health` | Get storage health (status: healthy\|warning, writable, disk_used_percent, disk_free_formatted, disk_total_formatted). Warning when not writable or usage ≥ 90%. |

### Search

Authenticated. See [ADR-021: Search / Meilisearch](../adr/021-search-meilisearch-integration.md).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/search` | Global search. Query: `q` (string), `type` (one of `users,user_groups,notifications,email_templates,notification_templates,api_tokens,ai_providers,webhooks`), `page`, `per_page`. Response: `{ data: [...], meta: { query, total, page, per_page } }`. |
| GET | `/search/suggestions` | Autocomplete suggestions. Query: `q`, `type`. Returns quick-match items for typeahead. |

**Search Admin** (requires `can:settings.view` / `can:settings.edit`):

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/search/stats` | Index statistics (index names, document counts, sizes). |
| GET | `/admin/search/health` | Meilisearch health status. |
| POST | `/admin/search/test-connection` | Test connection. Body: `{ host, api_key? }`. Returns `{ success, version? }`. |
| POST | `/admin/search/reindex` | Reindex. Body: `{ model? }` — one of `users,user_groups,notifications,email_templates,notification_templates,api_tokens,ai_providers,webhooks,pages`, or omit for all. |

### Jobs / Scheduled Tasks (Admin)

Requires `admin` ability. Monitor scheduled tasks and run whitelisted commands manually. See [Features: Scheduled Jobs](../features.md#scheduled-jobs).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/jobs/scheduled` | List scheduled tasks. Response: `tasks` array with `command`, `schedule`, `description`, `triggerable`, `dangerous`, `last_run` (`{ at, status }` or null), `next_run`. Includes triggerable-only commands (e.g. `log:cleanup`) even if not in schedule. |
| POST | `/jobs/run/{command}` | Run a whitelisted command. Body (optional): `{ "options": { "--dry-run": true } }`. Allowed: `backup:run`, `log:cleanup`, `log:check-suspicious`. Response (200): `success`, `output`, `duration_ms`. Response (422 on failure): same keys. Rate limit: `backup:run` once per 5 minutes. Audited as `scheduled_command_run`. |
| GET | `/jobs/queue` | Queue status. Response: `pending`, `failed`, optional `queues` (queue name → count). |
| GET | `/jobs/failed` | List failed queue jobs (paginated). Query: `per_page`. Response: Laravel pagination. |
| POST | `/jobs/failed/{id}/retry` | Retry one failed job. |
| DELETE | `/jobs/failed/{id}` | Delete one failed job. |
| POST | `/jobs/failed/retry-all` | Retry all failed jobs. |
| DELETE | `/jobs/failed/clear` | Clear all failed jobs. |

### System

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/version` | Get app version |
| GET | `/health` | Health check |

## Request/Response Examples

### Register User

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "securepassword",
    "password_confirmation": "securepassword"
  }'
```

Response:
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "email_verified_at": null,
    "is_admin": false,
    "created_at": "2024-01-01T00:00:00.000000Z"
  },
  "message": "Registration successful"
}
```
(`is_admin` is computed from admin group membership.)

### Send LLM Query

```bash
curl -X POST http://localhost:8080/api/llm/query \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{
    "prompt": "Explain quantum computing",
    "mode": "single",
    "provider": "claude"
  }'
```

Response:
```json
{
  "success": true,
  "response": "Quantum computing is...",
  "provider": "claude",
  "model": "claude-sonnet-4-20250514",
  "mode": "single",
  "tokens": {
    "input": 15,
    "output": 250,
    "total": 265
  },
  "total_duration_ms": 2500
}
```

### Create Backup

```bash
curl -X POST http://localhost:8080/api/backup/create \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {admin_token}" \
  -d '{
    "include_database": true,
    "include_files": true,
    "include_settings": true
  }'
```

Response:
```json
{
  "backup": {
    "filename": "selfmx-backup-2024-01-01_12-00-00.zip",
    "size": 1048576,
    "manifest": {
      "version": "2.0",
      "app_version": "0.1.0",
      "created_at": "2024-01-01T12:00:00.000000Z",
      "contents": {
        "database": true,
        "files": true,
        "settings": true
      }
    }
  }
}
```

## Error Responses

### Validation Error (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

### Unauthorized (401)

```json
{
  "message": "Unauthenticated."
}
```

### Forbidden (403)

```json
{
  "message": "This action is unauthorized."
}
```

### Not Found (404)

```json
{
  "message": "Resource not found."
}
```

## Rate Limiting

API requests are rate limited to prevent abuse:

- **Authentication endpoints**: 5 requests per minute
- **General API**: 60 requests per minute
- **LLM queries**: 20 requests per minute

Rate limit headers are included in responses:
- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`

## Pagination

List endpoints support pagination:

```
GET /api/notifications?page=1&per_page=20
```

Response includes pagination metadata:

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 100
  }
}
```

## OpenAPI Specification

The complete OpenAPI 3.0 specification is available at:
- **File**: [openapi.yaml](./openapi.yaml)
- **Swagger UI**: `/api/documentation` (if enabled)

Import the OpenAPI spec into tools like Postman, Insomnia, or Swagger UI for interactive documentation.
