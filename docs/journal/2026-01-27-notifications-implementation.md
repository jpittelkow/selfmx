# Notifications UI & Real-Time Implementation - 2026-01-27

## Overview

Implemented the in-app notification UI (bell, dropdown, full page) and real-time updates via Laravel Echo + Reverb, completing the high-priority items from the [Notifications Roadmap](../plans/notifications-roadmap.md).

## Implementation Approach

### Frontend

- **NotificationContext** (`frontend/lib/notifications.tsx`): React context holding recent notifications, unread count, and actions (fetch, mark read, mark all read, delete). Prepends new items on real-time events and refetches when user changes.
- **NotificationProvider**: Wraps the app inside `AuthInitializer`. Subscribes to Echo `private-user.{id}` when the user is logged in and Reverb is configured; leaves channel and disconnects on logout.
- **Bell + dropdown**: `NotificationBell` in the header; `NotificationDropdown` uses Popover on desktop and Sheet on mobile (via `useIsMobile`). Recent items, mark-all-read, and “View all” link to `/notifications`.
- **Notifications page** (`/notifications`): Tabs (All / Unread), paginated list, select-all, bulk mark read, bulk delete. Uses existing REST API with `?unread=1` support.
- **Reusable pieces**: `NotificationItem` (title, message, relative time, mark-read button) and `NotificationList` (loading, empty, list).

### Backend

- **Broadcasting**: `config/broadcasting.php` configured with Reverb driver. `routes/channels.php` defines `user.{id}` private channel; `bootstrap/app.php` loads it via `channels`. Broadcasting authentication route registered in `routes/web.php` with Sanctum middleware for Laravel Echo.
- **NotificationSent event**: Implements `ShouldBroadcastNow`, broadcasts on `PrivateChannel('user.' . $user->id)` with `broadcastAs('NotificationSent')` and `broadcastWith()` returning notification payload.
- **DatabaseChannel**: After creating a notification, dispatches `NotificationSent` only when `config('broadcasting.default') !== 'null'`.
- **NotificationController**: `index` supports `?unread=1` for filtering unread notifications.

### Echo Client

- **`frontend/lib/echo.ts`**: Lazily creates Echo (Reverb via Pusher protocol) when `NEXT_PUBLIC_REVERB_APP_KEY` is set. Custom authorizer POSTs to `/broadcasting/auth` with `credentials: 'include'` for Sanctum. Exposes `getEcho()` and `disconnectEcho()`.
- **Nginx**: `location /broadcasting` added so Laravel serves `/broadcasting/auth`. Next.js dev rewrites `/broadcasting` to the Laravel dev server.

## Challenges Encountered

- **Echo types**: `Echo` requires a generic (`Echo<"pusher">`); custom authorizer had to match `ChannelAuthorizationCallback` (`(error: Error | null, authData) => void`). Fixed with explicit typings and `authData as { auth: string }`.
- **Set iteration**: `for (const id of selectedIds)` broke on older TS targets. Replaced with `for (const id of Array.from(selectedIds))`.

## Observations

- Keeping Echo creation lazy and behind env avoids SSR issues and lets the app run without Reverb.
- Using Sheet for the dropdown on mobile keeps the pattern consistent with the rest of the app.
- `ShouldBroadcastNow` avoids queue workers for local dev while still supporting queues later if we switch to `ShouldBroadcast`.

## Trade-offs

- **Reverb**: Using Laravel Reverb (self-hosted) with the Pusher-compatible protocol. No external service dependency.
- **Optional real-time**: When broadcasting is `null` or Reverb is unconfigured, the UI works with polling-free fetch-on-load only; no fallback polling was added.

## Next Steps (Future Considerations)

- Per-user channel preferences and notification-type filters (roadmap).
- Add ntfy / Novu channels (roadmap).
- Optional polling fallback when Echo is unavailable.
- Wire orchestrator into backup, auth, and jobs using [trigger-notifications](../ai/recipes/trigger-notifications.md).

## Testing Notes

- Bell shows unread count; dropdown lists recent items; mark read / mark all read work.
- Notifications page: All / Unread tabs, pagination, bulk delete.
- With `BROADCAST_CONNECTION=reverb` and Reverb configured, new in-app notifications appear in real time.
- Mobile: Bell in header; dropdown opens as Sheet.
