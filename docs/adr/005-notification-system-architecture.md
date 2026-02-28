# ADR-005: Notification System Architecture

## Status

Accepted

## Date

2026-01-24

## Context

Sourdough needs a flexible notification system that:
- Supports multiple delivery channels (email, SMS, chat, push)
- Allows users to choose their preferred channels
- Handles failures gracefully with retries
- Scales from single-user to enterprise deployments
- Stores notifications for in-app display

## Decision

We will implement a **channel-based notification orchestrator** with a unified interface for all providers.

### Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                   Notification System                             │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Event ──► NotificationOrchestrator                              │
│                    │                                              │
│                    ▼                                              │
│            ┌──────────────┐                                      │
│            │ User Prefs   │ ──► Filter enabled channels          │
│            └──────────────┘                                      │
│                    │                                              │
│         ┌─────────┼─────────┬─────────┬─────────┐               │
│         ▼         ▼         ▼         ▼         ▼               │
│    ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐      │
│    │ Email  │ │  Chat  │ │  SMS   │ │  Push  │ │ In-App │      │
│    │Channel │ │Channel │ │Channel │ │Channel │ │Channel │      │
│    └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘      │
│        │          │          │          │          │             │
│    ┌───┴───┐  ┌───┴───┐  ┌───┴───┐  ┌───┴───┐  ┌───┴───┐       │
│    │ SMTP  │  │Telegram│  │Twilio │  │ Web   │  │  DB   │       │
│    │Mailgun│  │Discord │  │Vonage │  │ Push  │  │WebSock│       │
│    │SendGrd│  │ Slack  │  │  SNS  │  │  FCM  │  │       │       │
│    │  SES  │  │ Signal │  │       │  │       │  │       │       │
│    └───────┘  └────────┘  └───────┘  └───────┘  └───────┘       │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
```

### Channel Interface

All channels implement `ChannelInterface`. The interface has evolved since this ADR was written; the current signature is:

```php
interface ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array;
    public function getName(): string;
    public function isAvailableFor(User $user): bool;
}
```

> **Note:** The original ADR specified `Notifiable $user` and `Notification $notification` parameters, plus `isConfigured()` and `getIdentifier()` methods. The implementation evolved to use `User` directly, pass title/message/data as separate parameters, and use `isAvailableFor()` for per-user availability checks. `isConfigured()` was replaced by channel-enabled checks in the config layer.

### Supported Channels

| Channel | Providers | Queue Support |
|---------|-----------|---------------|
| Email | SMTP, Mailgun, SendGrid, SES, Postmark | ✅ |
| Telegram | Bot API | ✅ |
| Discord | Webhooks | ✅ |
| Slack | Webhooks | ✅ |
| SMS | Twilio, Vonage, AWS SNS | ✅ |
| Signal | signal-cli | ✅ |
| Matrix | Matrix Protocol | ✅ |
| Web Push | VAPID | ✅ |
| Firebase | FCM | ✅ |
| In-App | Database + WebSocket | ✅ |

### Notification Model

```sql
notifications
├── id (UUID)
├── user_id (FK → users)
├── type (string, e.g., 'backup.completed')
├── title
├── body
├── data (JSON, additional metadata)
├── channels_sent (JSON array)
├── read_at (timestamp, nullable)
├── created_at
└── updated_at
```

### Queue Processing

Notifications are queued by default:

```php
class NotificationOrchestrator
{
    public function send(User $user, Notification $notification): void
    {
        // Store in database (in-app)
        $this->storeNotification($user, $notification);
        
        // Get user's enabled channels
        $channels = $this->getUserChannels($user);
        
        // Dispatch to queue for each channel
        foreach ($channels as $channel) {
            SendNotificationJob::dispatch($user, $notification, $channel)
                ->onQueue('notifications');
        }
    }
}
```

### User Preferences

Users configure their notification preferences:

```json
{
  "notifications": {
    "email": true,
    "telegram": {
      "enabled": true,
      "chat_id": "123456789"
    },
    "discord": {
      "enabled": false,
      "webhook_url": null
    },
    "sms": {
      "enabled": true,
      "phone": "+1234567890"
    }
  }
}
```

### Notification Types

```php
// Example notification types
'auth.login'           // New login detected
'auth.password_reset'  // Password was reset
'backup.started'       // Backup started
'backup.completed'     // Backup completed
'backup.failed'        // Backup failed
'llm.quota_warning'    // API quota warning
'system.update'        // System update available
```

## Consequences

### Positive

- Unified interface simplifies adding new channels
- User preferences give control over delivery
- Queue-based delivery handles high volume
- In-app notifications provide guaranteed delivery
- Failed deliveries don't block other channels

### Negative

- Multiple channels increase complexity
- Each provider has unique configuration
- Queue worker required for async delivery
- WebSocket setup needed for real-time in-app

### Neutral

- All channels are optional (can run with none)
- Providers can be added incrementally
- Each channel can have its own retry policy

## Related Decisions

- [ADR-001: Technology Stack](./001-technology-stack.md)
- [ADR-025: Novu Notification Integration](./025-novu-notification-integration.md) — optional alternative: when Novu is enabled, the orchestrator delegates to Novu API; otherwise the channel-based implementation above is used.

## Notes

### Provider Configuration

Each provider is configured via environment variables:

```env
# Email
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587

# Telegram
TELEGRAM_BOT_TOKEN=123456:ABC-DEF
TELEGRAM_ENABLED=true

# Twilio
TWILIO_SID=ACxxxxxx
TWILIO_TOKEN=xxxxx
TWILIO_FROM=+15551234567
```

### WebSocket for Real-Time

For real-time in-app notifications:
- Laravel Reverb (self-hosted)
- Frontend listens on user's private channel
- New notifications pushed instantly
