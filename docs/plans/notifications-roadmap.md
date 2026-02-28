# Notifications Roadmap

Complete the notifications system with in-app UI, real-time updates, and user preferences.

**Priority**: HIGH  
**Status**: Completed (2026-01-27)  
**Last Updated**: 2026-01-27

**Dependencies**:
- [Settings Restructure](settings-restructure-roadmap.md) - User preferences page needed for notification channel preferences

---

## Task Checklist

### Notifications UI (HIGH Priority)
- [x] Build notification bell component with unread badge for header
- [x] Create notification dropdown panel with recent notifications
- [x] Build full notifications page with list, filters, and bulk actions
- [x] Create NotificationContext provider for state management

### Notifications Backend (MEDIUM Priority)
- [x] Implement per-user notification channel preferences
- [x] Add WebSocket/polling for real-time notification updates (Laravel Echo + Reverb)

### Additional Providers (MEDIUM Priority)
- [x] Implement ntfy channel for self-hosted push notifications (2026-01-27)

---

## Current State Assessment

**Backend (Strong Foundation)**:
- NotificationOrchestrator service with multi-channel routing
- 13 channel implementations in `backend/app/Services/Notifications/Channels/`:
  - Database (in-app)
  - Email (SMTP/providers)
  - Telegram, Discord, Slack (chat platforms)
  - Signal, Matrix (secure messaging)
  - Twilio, Vonage, SNS (SMS)
  - WebPush, FCM (push notifications)
- Notification model with UUID, read/unread tracking
- API endpoints: list, unread-count, mark-read, mark-all-read, test

**Frontend (Incomplete)**:
- Settings page for channel configuration exists
- **Missing**: In-app notification UI (bell, dropdown, list)
- **Missing**: Real-time notification updates

---

## Frontend In-App Notifications (HIGH Priority)

### Notification Bell with Badge

Location: Header component (`frontend/components/header.tsx`)

**Features**:
- Bell icon with unread count badge
- Animated indicator for new notifications
- Polling or WebSocket for real-time updates
- Click to open notification panel

### Notification Dropdown Panel

**Features**:
- Shows recent notifications (5-10 items)
- Quick mark as read
- Click notification to navigate to related item
- "View All" link to full notifications page
- Empty state when no notifications

### Notifications Page

New route: `/notifications`

**Features**:
- Full notification list with pagination
- Filter by read/unread
- Filter by notification type
- Bulk actions (mark all read, delete)
- Notification details view

### Notification Context/Provider

```typescript
// frontend/lib/notifications.tsx
interface NotificationContext {
  notifications: Notification[];
  unreadCount: number;
  fetchNotifications: () => Promise<void>;
  markAsRead: (ids: string[]) => Promise<void>;
  markAllAsRead: () => Promise<void>;
}
```

**Implementation Files**:
- `frontend/components/notifications/notification-bell.tsx`
- `frontend/components/notifications/notification-dropdown.tsx`
- `frontend/components/notifications/notification-item.tsx`
- `frontend/components/notifications/notification-list.tsx`
- `frontend/app/(dashboard)/notifications/page.tsx`
- `frontend/lib/notifications.tsx` - Context provider

**Modify Frontend Files**:
- `frontend/components/header.tsx` - Add notification bell
- `frontend/components/providers.tsx` - Add notification provider
- `frontend/app/(dashboard)/settings/notifications/page.tsx` - Enhance with preferences

---

## Real-Time Notifications (MEDIUM Priority)

**Options**:

1. **Polling (Simple)**: Fetch unread count every 30-60 seconds
2. **WebSocket (Recommended)**: Laravel Echo + Reverb for instant updates
3. **Server-Sent Events**: Lightweight alternative to WebSocket

**WebSocket Implementation** (Laravel Echo):

```
Backend:
- Configure broadcasting in config/broadcasting.php
- Create NotificationSent event
- Broadcast to user's private channel

Frontend:
- Install laravel-echo and pusher-js (Reverb uses Pusher protocol)
- Subscribe to user's notification channel
- Update notification context on new events
```

---

## Provider Configuration Improvements (HIGH Priority)

The current notification settings page needs backend support. Required work:

### Dedicated Notification Settings Endpoint - COMPLETED

> **Completed in [Critical Fixes Roadmap](critical-fixes-roadmap.md)**: `NotificationSettingsController` created with `GET/PUT /api/settings/notifications` endpoints.

Expected response format:

```json
{
  "channels": [
    {
      "id": "email",
      "name": "Email",
      "enabled": true,
      "configured": true,
      "system_configured": true,
      "user_settings": {},
      "available_settings": []
    },
    {
      "id": "telegram",
      "name": "Telegram",
      "enabled": false,
      "configured": false,
      "system_configured": true,
      "user_settings": {
        "chat_id": ""
      },
      "available_settings": [
        {"key": "chat_id", "label": "Chat ID", "type": "text", "required": true}
      ]
    }
  ],
  "preferences": {
    "digest_enabled": false,
    "digest_frequency": "daily",
    "quiet_hours_enabled": false,
    "quiet_hours_start": "22:00",
    "quiet_hours_end": "08:00"
  }
}
```

### Per-User Channel Settings

Store user-specific settings (like Telegram chat_id, Discord webhook) in the settings table:

```
group: notifications
key: telegram_chat_id
value: "123456789"
```

### System vs User Configuration

Distinguish between:
- **System config**: API keys, bot tokens (env/admin only)
- **User config**: Chat IDs, personal webhooks, preferences

**New Backend Files**:
- `backend/app/Http/Controllers/Api/NotificationSettingsController.php`
- `backend/database/migrations/xxxx_create_notification_preferences_table.php`
- `backend/app/Models/NotificationPreference.php`

**Modify Backend Files**:
- `backend/routes/api.php` - Add notification settings routes
- `backend/app/Http/Controllers/Api/NotificationController.php` - Enhance endpoints

---

## Notification Preferences (MEDIUM Priority)

Allow users to configure:

- **Channel preferences**: Which channels to receive notifications on
- **Notification types**: Which notification types to receive
- **Digest settings**: Bundle notifications into daily/weekly digest
- **Quiet hours**: Don't send push/SMS during specified hours

**Database schema addition**:

```
notification_preferences table:
- user_id
- channel (email, telegram, etc.)
- notification_type (or * for all)
- enabled (boolean)
- settings (JSON - frequency, etc.)
```

---

## Email Provider Management (MEDIUM Priority)

For email specifically, allow admin configuration of:

- **Provider selection**: SMTP, Mailgun, SendGrid, SES, Postmark
- **Credentials**: API keys, server settings
- **Templates**: Welcome email, password reset, notifications
- **Test email**: Send test to verify configuration

This could be a sub-section of System Settings or Notification Settings.

---

## Additional Notification Providers ✅ COMPLETE

### ntfy (Completed 2026-01-27)

**What**: Simple HTTP-based pub/sub notification service for push notifications to phones/desktops.

**Benefits**:
- Self-hosted option (Docker-friendly)
- No app required - uses UnifiedPush on Android
- Simple HTTP POST API
- Supports attachments and actions
- Free public server available

**Implementation**:
- `NtfyChannel` in `backend/app/Services/Notifications/Channels/`
- Configurable server URL (self-hosted or ntfy.sh)
- User-to-topic mapping

**Links**: [ntfy.sh](https://ntfy.sh) | [GitHub](https://github.com/binwiederhier/ntfy)

---

## Notification Templates (LOW Priority)

Define reusable notification templates:

```
notification_templates table:
- type (e.g., "welcome", "password_reset", "backup_complete")
- channel (email, telegram, etc.)
- subject_template
- body_template
- variables (JSON schema)
```

Benefits:
- Consistent messaging across channels
- Admin-editable without code changes
- Supports localization

---

## Implementation Priority

| Component | Priority | Effort | Dependencies |
|-----------|----------|--------|--------------|
| Notification Bell + Dropdown | HIGH | Medium | None |
| Notifications Page | HIGH | Low | Bell component |
| Notification Context Provider | HIGH | Low | API exists |
| Settings Backend Endpoint | HIGH | Medium | None |
| Per-User Channel Settings | MEDIUM | Medium | Settings endpoint |
| Real-Time (WebSocket) | MEDIUM | High | Laravel Echo setup |
| Notification Preferences | MEDIUM | Medium | Settings endpoint |
| Email Provider Admin UI | MEDIUM | High | System settings |
| ~~ntfy Channel Integration~~ | ~~MEDIUM~~ | ~~Low~~ | ✅ Complete |
| Notification Templates | LOW | High | None |

---

## Files Reference

**Backend**:
- `backend/app/Services/Notifications/NotificationOrchestrator.php`
- `backend/app/Services/Notifications/Channels/`
- `backend/app/Http/Controllers/Api/NotificationController.php`
- `backend/config/notifications.php`

**Frontend**:
- `frontend/app/(dashboard)/settings/notifications/page.tsx`
- `frontend/components/header.tsx`

**Documentation**:
- `docs/adr/005-notification-system-architecture.md` - Notification system architecture
