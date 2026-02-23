# ADR-017: Notification Template System

## Status

Accepted

## Date

2026-01-30

## Context

Sourdough sends notifications via multiple channels (in-app, push, chat, email). Email already uses a database-stored template system (ADR-016). For push, in-app, and chat channels, notification content is currently passed as title and message from calling code, making it hard for admins to customize wording per notification type and channel without code changes. We need per-notification-type, per-channel-group templates with variable substitution.

## Decision

We will implement a **per-type notification template system** for push, in-app, and chat channel groups:

- **NotificationTemplate model**: Stores type (e.g. `backup.completed`, `auth.login`), channel_group (`push`, `inapp`, `chat`, `email`), title, body, variables (JSON), is_system, is_active. Unique on (type, channel_group).
- **Channel groups**: `push` (WebPush, FCM, ntfy), `inapp` (DatabaseChannel), `chat` (Telegram, Discord, Slack, Twilio, Signal, Matrix, Vonage, SNS), `email` (per-type email templates with HTML body). Transactional emails (password_reset, email_verification, welcome) remain in EmailTemplate (ADR-016).
- **Variable replacement**: Same as EmailTemplateService — `{{variable}}` and `{{user.name}}` (dot notation); missing variables replaced with empty string.
- **NotificationTemplateService**: `getByTypeAndChannel($type, $channelGroup)`, `render($type, $channelGroup, $variables)`, `renderTemplate(NotificationTemplate $template, $variables)`, `renderContent($title, $body, $variables)`, `getAvailableVariables($type, $channelGroup)`, `getDefaultContent($type, $channelGroup)`.
- **NotificationOrchestrator**: New `sendByType($user, $type, $variables, $channels)` resolves templates per channel group and sends; existing `send($user, $type, $title, $message, $data)` unchanged. Each channel’s `send()` optionally resolves a template for (type, channel_group) when present, otherwise uses passed title/message (backward compatible).
- **Seeder**: NotificationTemplateSeeder defines default templates for common types (backup.completed, backup.failed, auth.login, auth.password_reset, system.update, llm.quota_warning) × push, inapp, chat; run from migration. Seeder exposes `getDefaultFor($type, $channelGroup)` for reset.
- **Admin API**: NotificationTemplateController under `auth:sanctum` + `can:settings.view` / `can:settings.edit` with index, show, update, preview, reset (by id).

### Architecture

- Admin UI: Configuration > Notification Templates — list by type/channel_group, edit title/body, live preview, reset to default.
- Channels: DatabaseChannel, WebPushChannel, FCMChannel, NtfyChannel use push/inapp templates; TelegramChannel, DiscordChannel, SlackChannel, TwilioChannel, SignalChannel, MatrixChannel, VonageChannel, SNSChannel use chat templates. If a template exists for (type, channel_group), channel renders it with variables; else uses title/message from `send()`.

## Consequences

### Positive

- Admins can customize notification text per type and channel (push vs in-app vs chat) without code changes.
- Backward compatible: existing `send($user, $type, $title, $message, $data)` still works; channels fall back to passed title/message when no template exists.
- `sendByType()` enables template-first flows with a single call and variable payload.

### Negative

- More templates to maintain (e.g. 6 types × 3 channel groups = 18 defaults). New notification types require seeder entries and optional migration.

## Related Decisions

- [ADR-005: Notification System Architecture](005-notification-system-architecture.md) — channel interface and orchestrator.
- [ADR-016: Email Template System](016-email-template-system.md) — email continues to use EmailTemplate; notification templates cover push, inapp, chat.
- [ADR-025: Novu Notification Integration](025-novu-notification-integration.md) — when Novu is enabled, templates and delivery are managed in Novu; the local template system is used only when Novu is not configured.

## Notes

### Key files

- `backend/app/Models/NotificationTemplate.php`
- `backend/app/Services/NotificationTemplateService.php`
- `backend/database/seeders/NotificationTemplateSeeder.php`
- `backend/app/Http/Controllers/Api/NotificationTemplateController.php`
- `backend/app/Services/Notifications/NotificationOrchestrator.php` — `sendByType()`, channel-to-group mapping
- `backend/app/Services/Notifications/Channels/*` — resolveContent() using NotificationTemplateService
- `frontend/app/(dashboard)/configuration/notification-templates/page.tsx`
- `frontend/app/(dashboard)/configuration/notification-templates/[id]/page.tsx`

### Recipe

- [Add Notification Template](../ai/recipes/add-notification-template.md)
