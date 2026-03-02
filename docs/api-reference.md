# API Reference

REST API documentation:

- [API Documentation](api/README.md) - Complete API reference with endpoints, authentication, examples
- [OpenAPI Specification](api/openapi.yaml) - OpenAPI 3.0 spec for API tooling integration

The REST API is available at `/api`. All authenticated endpoints require a valid Sanctum session or token.

---

## Public Endpoints (No Auth Required)

```
GET    /api/version                    Application version info
GET    /api/health                     Health check (returns {status: "ok"})
GET    /api/auth/sso/providers         List enabled SSO providers
GET    /api/system-settings/public     Public system settings (app name, features)
GET    /api/branding                   Public branding (logo, colors, app name)
POST   /api/client-errors              Report client-side errors (rate limited: 10/min)
```

---

## Authentication

```
POST   /api/auth/check-email           Check email availability (rate limited: 10/min)
POST   /api/auth/register              Register new user (rate limited)
POST   /api/auth/login                 Login with email/password (rate limited)
POST   /api/auth/logout                Logout (auth required)
GET    /api/auth/user                  Get current user (auth required)
POST   /api/auth/forgot-password       Request password reset (rate limited)
POST   /api/auth/reset-password        Reset password with token (rate limited)
POST   /api/auth/verify-email          Verify email with token
POST   /api/auth/resend-verification   Resend verification email (auth required)
```

### SSO (Single Sign-On)

```
GET    /api/auth/sso/{provider}        Redirect to SSO provider (google, github, microsoft, apple, discord, gitlab, oidc)
GET    /api/auth/callback/{provider}   SSO callback handler
POST   /api/auth/sso/{provider}/link   Link SSO account to existing user (auth required)
DELETE /api/auth/sso/{provider}/unlink Unlink SSO account (auth required)
```

### Two-Factor Authentication

```
GET    /api/auth/2fa/status            Get 2FA status (auth required)
POST   /api/auth/2fa/enable            Start 2FA setup, returns QR code (auth required)
POST   /api/auth/2fa/confirm           Confirm 2FA with TOTP code (auth required)
POST   /api/auth/2fa/disable           Disable 2FA (auth required, body: password)
POST   /api/auth/2fa/verify            Verify 2FA during login (rate limited, body: code or recovery_code)
GET    /api/auth/2fa/recovery-codes    Get recovery codes (auth required)
POST   /api/auth/2fa/recovery-codes/regenerate  Regenerate recovery codes (auth required)
```

### Passkeys (WebAuthn)

```
GET    /api/auth/passkeys              List user's passkeys (auth required)
POST   /api/auth/passkeys/register/options  Get registration options (auth required)
POST   /api/auth/passkeys/register     Register new passkey (auth required)
PUT    /api/auth/passkeys/{id}         Update passkey name (auth required)
DELETE /api/auth/passkeys/{id}         Delete passkey (auth required)
POST   /api/auth/passkeys/login/options  Get login options (rate limited: 10/min)
POST   /api/auth/passkeys/login        Authenticate with passkey (rate limited: 10/min)
```

---

## User Endpoints

All require auth + email verification + 2FA setup (if enforced).

### Profile

```
GET    /api/profile                    Get profile
PUT    /api/profile                    Update profile (name, email, avatar)
PUT    /api/profile/password           Change password (body: current_password, password, password_confirmation)
DELETE /api/profile                    Delete account
```

### User Settings

```
GET    /api/user/settings              Get user preferences
PUT    /api/user/settings              Update user preferences
GET    /api/user/notification-settings Get notification preferences (channels, types)
PUT    /api/user/notification-settings Update notification preferences
POST   /api/user/webpush-subscription  Store web push subscription
DELETE /api/user/webpush-subscription  Remove web push subscription
```

### Notifications

```
GET    /api/notifications              List notifications (query: page, per_page, unread_only)
GET    /api/notifications/unread-count Get unread count
POST   /api/notifications/mark-read    Mark specific as read (body: notification_ids[])
POST   /api/notifications/mark-all-read Mark all as read
DELETE /api/notifications/{id}         Delete notification
POST   /api/notifications/test/{channel} Send test notification (body: channel-specific config)
```

### API Tokens

```
GET    /api/api-tokens                 List user's API tokens
POST   /api/api-tokens                 Create token (body: name, abilities[])
DELETE /api/api-tokens/{token}         Revoke token
```

---

## Dashboard

```
GET    /api/dashboard/stats            Dashboard widget data (permission-filtered)
```

---

## Search

```
GET    /api/search                     Global search (query: q, type?, page?, per_page?)
                                       Returns: data[], meta{query, total, page, per_page}
                                       Admin sees all users; non-admin scoped to self
GET    /api/search/suggestions         Autocomplete (query: q, limit?)
```

---

## Admin: Settings

All require `can:settings.view` for GET, `can:settings.edit` for mutations.

### General Settings

```
GET    /api/settings                   List all settings
PUT    /api/settings                   Update settings (body: key-value pairs)
GET    /api/settings/notifications     Get notification settings
PUT    /api/settings/notifications     Update notification settings
GET    /api/settings/{group}           Get settings by group
PUT    /api/settings/{group}           Update settings group
```

### System Settings

```
GET    /api/system-settings            List system settings (admin view)
PUT    /api/system-settings            Update system settings
GET    /api/system-settings/{group}    Get system settings by group
```

### Auth Settings

```
GET    /api/auth-settings              Get auth feature toggles (registration, 2FA, passkeys)
PUT    /api/auth-settings              Update auth settings
```

### SSO Settings

```
GET    /api/sso-settings               Get SSO provider configurations
PUT    /api/sso-settings               Update SSO settings
POST   /api/sso-settings/test/{provider} Test SSO provider connection
DELETE /api/sso-settings/keys/{key}    Reset SSO setting to env default
```

### Mail Settings

```
GET    /api/mail-settings              Get SMTP/mail configuration
PUT    /api/mail-settings              Update mail settings
POST   /api/mail-settings/test         Send test email (body: optional to)
DELETE /api/mail-settings/keys/{key}   Reset mail setting to env default
```

### Notification Channel Settings

```
GET    /api/notification-settings      Get channel configurations (Slack, Discord, etc.)
PUT    /api/notification-settings      Update channel settings
POST   /api/notification-settings/test/{channel} Test channel (body: channel-specific)
DELETE /api/notification-settings/keys/{key}     Reset channel setting to default
```

### Admin Notification Channels

```
GET    /api/admin/notification-channels  Get admin-level channel config
PUT    /api/admin/notification-channels  Update admin channel config
```

### Log Retention

```
GET    /api/log-retention              Get log retention settings (retention days)
PUT    /api/log-retention              Update log retention settings
```

---

## Admin: LLM/AI

### LLM Providers (can:settings.view/edit)

```
GET    /api/llm/providers              List configured LLM providers
POST   /api/llm/providers              Add provider (body: provider config)
PUT    /api/llm/providers/{provider}   Update provider
DELETE /api/llm/providers/{provider}   Remove provider
GET    /api/llm/config                 Get LLM orchestration config (mode, primary)
PUT    /api/llm/config                 Update LLM config
POST   /api/llm/test/{provider}        Test provider connection
POST   /api/llm/query                  Send query (body: prompt, system_prompt?, mode?, provider?)
POST   /api/llm/query/vision           Vision query (body: prompt, image, mime_type?)
```

### LLM Settings

```
GET    /api/llm-settings               Get system-wide LLM settings
PUT    /api/llm-settings               Update LLM settings
DELETE /api/llm-settings/keys/{key}    Reset LLM setting to default
POST   /api/llm-settings/test-key      Validate API key (body: provider, api_key?, host?)
POST   /api/llm-settings/discover-models Fetch available models (body: provider, api_key?, host?)
```

---

## Admin: Users & Groups

### User Management (can:users.*)

```
GET    /api/users                      List users (query: page, per_page, search, group?)
POST   /api/users                      Create user (body: name, email, password?, is_admin?)
GET    /api/users/{user}               Get user details (includes groups)
PUT    /api/users/{user}               Update user
DELETE /api/users/{user}               Delete user
POST   /api/users/{user}/toggle-admin  Toggle admin group membership
POST   /api/users/{user}/reset-password Force password reset
POST   /api/users/{user}/disable       Toggle disabled status
POST   /api/users/{user}/resend-verification Resend verification email
PUT    /api/users/{user}/groups        Update user's group memberships (body: group_ids[])
```

### Permissions (can:groups.view)

```
GET    /api/permissions                List available permissions (categorized for UI)
```

### User Groups (can:groups.view/manage)

```
GET    /api/groups                     List groups (with members_count)
POST   /api/groups                     Create group (body: name, slug, description?, is_default?)
GET    /api/groups/{group}             Get group details
PUT    /api/groups/{group}             Update group (system group: slug immutable)
DELETE /api/groups/{group}             Delete group (system groups forbidden)
GET    /api/groups/{group}/members     List members (paginated)
POST   /api/groups/{group}/members     Add members (body: user_ids[])
DELETE /api/groups/{group}/members/{user} Remove member
GET    /api/groups/{group}/permissions Get group permissions
PUT    /api/groups/{group}/permissions Update permissions (body: permissions[])
```

---

## Admin: Audit & Logs

### Audit Logs (can:audit.view, can:logs.export)

```
GET    /api/audit-logs                 List audit logs (query: page, per_page, user_id?, action?, severity?, date_from?, date_to?, correlation_id?)
GET    /api/audit-logs/export          Export as CSV (same filters)
GET    /api/audit-logs/stats           Stats for dashboard (query: date_from?, date_to?)
                                       Returns: total_actions, by_severity, daily_trends, recent_warnings, actions_by_type, actions_by_user
```

### Application Logs (can:logs.export)

```
GET    /api/app-logs/export            Export app logs (query: date_from?, date_to?, level?, correlation_id?)
```

### Suspicious Activity (can:logs.view)

```
GET    /api/suspicious-activity        List detected suspicious patterns
```

---

## Admin: Templates

### Email Templates (can:settings.view/edit)

```
GET    /api/email-templates            List all templates
GET    /api/email-templates/{key}      Get template by key
PUT    /api/email-templates/{key}      Update template (body: subject, body_html, body_text)
POST   /api/email-templates/{key}/preview Preview rendered (body: variables?, subject?, body_html?, body_text?)
POST   /api/email-templates/{key}/test Send test email (body: to?)
POST   /api/email-templates/{key}/reset Reset to default (system templates only)
```

See [ADR-016](adr/016-email-template-system.md), [Patterns](ai/patterns/email-template-service.md).

### Notification Templates (can:settings.view/edit)

```
GET    /api/notification-templates     List all templates
GET    /api/notification-templates/{id} Get template
PUT    /api/notification-templates/{id} Update template (body: title, body)
POST   /api/notification-templates/{id}/preview Preview rendered (body: variables?, title?, body?)
POST   /api/notification-templates/{id}/reset Reset to default (system templates only)
```

See [ADR-017](adr/017-notification-template-system.md), [Patterns](ai/patterns/notification-template-service.md).

---

## Admin: Backup & Restore

### Backups (can:backups.*)

```
GET    /api/backup                     List backups
POST   /api/backup/create              Create backup (body: include_database?, include_files?, include_settings?)
GET    /api/backup/download/{filename} Download backup file
POST   /api/backup/restore             Restore (body: filename or multipart backup file)
DELETE /api/backup/{filename}          Delete backup
```

### Backup Settings (can:settings.view/edit)

```
GET    /api/backup-settings            Get backup configuration
PUT    /api/backup-settings            Update backup settings
POST   /api/backup-settings/reset/{key} Reset setting to env default
POST   /api/backup-settings/test/{destination} Test destination (s3, sftp, google_drive)
```

See [Backup & Restore](backup.md), [API README – Backup](api/README.md#backup--restore-admin).

---

## Admin: Storage

### Storage Settings (can:settings.view/edit)

```
GET    /api/storage-settings           Get storage configuration
PUT    /api/storage-settings           Update storage settings (driver, credentials)
POST   /api/storage-settings/test      Test storage connection
GET    /api/storage-settings/stats     Storage usage statistics
GET    /api/storage-settings/analytics Storage analytics (file types, growth)
GET    /api/storage-settings/cleanup-suggestions Cleanup recommendations
POST   /api/storage-settings/cleanup   Run cleanup (body: suggestions to apply)
GET    /api/storage-settings/paths     List storage paths
GET    /api/storage-settings/health    Storage health check
```

### File Manager (can:admin)

```
GET    /api/storage/files              List files (query: path?, page?, per_page?)
POST   /api/storage/files              Upload file (multipart: file, path?)
GET    /api/storage/files/{path}       Get file metadata
GET    /api/storage/files/{path}/download Download file
PUT    /api/storage/files/{path}/rename Rename file (body: name)
PUT    /api/storage/files/{path}/move  Move file (body: destination)
DELETE /api/storage/files/{path}       Delete file
```

---

## Admin: Search

### Search Admin (can:settings.view/edit)

```
GET    /api/admin/search/stats         Index statistics (document counts per model)
GET    /api/admin/search/health        Meilisearch health status
POST   /api/admin/search/test-connection Test Meilisearch connection
POST   /api/admin/search/reindex       Reindex (body: model? - omit for all)
```

---

## Admin: Jobs & Tasks

### Scheduled Jobs (can:settings.view/edit)

```
GET    /api/jobs/scheduled             List scheduled tasks (with last run, next run)
POST   /api/jobs/run/{command}         Run task manually (whitelist: backup:run, log:cleanup, log:check-suspicious)
GET    /api/jobs/queue                 Queue status (pending, processing counts)
GET    /api/jobs/failed                List failed jobs
POST   /api/jobs/failed/{id}/retry     Retry failed job
DELETE /api/jobs/failed/{id}           Delete failed job
POST   /api/jobs/failed/retry-all      Retry all failed jobs
DELETE /api/jobs/failed/clear          Clear all failed jobs
```

---

## Admin: Webhooks

### Webhooks (can:settings.view/edit)

```
GET    /api/webhooks                   List webhooks
POST   /api/webhooks                   Create webhook (body: url, events[], secret?, active?)
GET    /api/webhooks/{webhook}/deliveries List delivery attempts
POST   /api/webhooks/{webhook}/test    Send test delivery
PUT    /api/webhooks/{webhook}         Update webhook
DELETE /api/webhooks/{webhook}         Delete webhook
```

---

## Admin: Branding

### Branding (can:settings.edit for mutations)

```
PUT    /api/branding                   Update branding (body: app_name, primary_color, etc.)
POST   /api/branding/logo              Upload logo (multipart: logo)
POST   /api/branding/favicon           Upload favicon (multipart: favicon)
DELETE /api/branding/logo              Remove custom logo
DELETE /api/branding/favicon           Remove custom favicon
```
