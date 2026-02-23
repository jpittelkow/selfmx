# Progressive Web App (PWA) Roadmap

Transform Sourdough into a full Progressive Web App with push notifications, offline support, and installability.

## Overview

A full PWA provides native app-like experience: installable, works offline, receives push notifications, and syncs in background. This roadmap builds on the existing [Web Push Notifications](web-push-notifications-roadmap.md) work.

## Current State

- [x] Basic `manifest.json` with app name, icons, theme
- [x] Backend `WebPushChannel` with VAPID signing
- [x] Mobile-responsive UI
- [x] Service worker
- [x] Offline caching
- [x] Push notification frontend
- [x] Install prompt

## Phase 1: Service Worker Foundation ✅ COMPLETE

Create the core service worker with caching strategies.

### Tasks

- [x] Create `frontend/public/sw.js` service worker
- [x] Implement cache-first strategy for static assets (JS, CSS, images)
- [x] Implement network-first strategy for API requests
- [x] Add offline fallback page (`frontend/public/offline.html`)
- [x] Register service worker in Next.js app
- [x] Handle service worker updates gracefully

### Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Caching strategy | Workbox | Industry standard, maintained by Google |
| API caching | Network-first with cache fallback | Data freshness priority |
| Static assets | Cache-first | Fast loads, assets are versioned |

### Files

| File | Purpose |
|------|---------|
| `frontend/public/sw.js` | Service worker |
| `frontend/public/offline.html` | Offline fallback page |
| `frontend/lib/service-worker.ts` | SW registration utility |
| `frontend/components/service-worker-setup.tsx` | SW registration component |

## Phase 2: Push Notifications ✅ COMPLETE

Complete the push notification flow (see [Web Push Notifications](web-push-notifications-roadmap.md) for details).

### Tasks

- [x] Handle `push` event in service worker
- [x] Handle `notificationclick` for navigation
- [x] Create `frontend/lib/web-push.ts` subscription utility
- [x] Add "Enable Notifications" UI in User Preferences
- [x] Backend `WebPushChannel` uses notifications group for subscription
- [x] Add `POST /api/user/webpush-subscription` and `DELETE /api/user/webpush-subscription` endpoints
- [x] Add VAPID key generation instructions to admin config

### Notification Features

- [ ] Rich notifications with icons and actions
- [ ] Notification categories (alerts, reminders, updates)
- [ ] Quiet hours / Do Not Disturb setting
- [ ] Badge count on app icon

## Phase 3: Offline Experience ✅ COMPLETE

Provide meaningful offline functionality.

### Tasks

- [x] Cache critical app shell (layout, navigation)
- [x] Cache user's frequently accessed data
- [x] Show offline indicator in UI
- [x] Queue failed API requests for retry (background sync)
- [x] Graceful degradation for uncached content

### Offline-Capable Pages

| Page | Offline Behavior |
|------|------------------|
| Dashboard | Show cached data with "offline" badge |
| User Preferences | Read-only from cache; save/actions disabled |
| Notifications | Show cached notifications; Mark read/Delete disabled |
| Login | Show offline message (offline.html) |

## Phase 4: Install Experience ✅ COMPLETE

Prompt users to install the PWA.

### Tasks

- [x] Enhance `manifest.json`:
  - [x] Add `screenshots` for install UI
  - [x] Add `shortcuts` for quick actions
  - [ ] Add `related_applications` if native app exists
  - [x] Update icons (ensure all sizes: 48, 72, 96, 128, 144, 152, 192, 384, 512)
- [x] Create install prompt component
- [x] Detect `beforeinstallprompt` event
- [x] Show custom install banner (non-intrusive)
- [x] Track install success/dismiss (localStorage; optional analytics)
- [x] Add "Install App" option in settings/menu

### Install Banner UX

- Show after 2+ visits (not first visit)
- Dismissible with "Don't show again" option
- Re-prompt after 30 days if dismissed

## Phase 5: Advanced Features (Optional)

Enhancements for power users.

### Tasks

- [x] Background sync for offline form submissions (already in sw.js)
- [ ] Periodic background sync for data refresh
- [x] Share Target API (receive shared content)
- [x] Shortcuts in manifest for quick actions
- [ ] Protocol handlers (custom URL schemes)

## Manifest Enhancements

Updated `manifest.json` structure:

```json
{
  "name": "Sourdough",
  "short_name": "Sourdough",
  "description": "Starter Application Framework for AI Development",
  "start_url": "/",
  "display": "standalone",
  "orientation": "portrait-primary",
  "theme_color": "#3b82f6",
  "background_color": "#ffffff",
  "icons": [
    { "src": "/icons/icon-48.png", "sizes": "48x48", "type": "image/png" },
    { "src": "/icons/icon-72.png", "sizes": "72x72", "type": "image/png" },
    { "src": "/icons/icon-96.png", "sizes": "96x96", "type": "image/png" },
    { "src": "/icons/icon-128.png", "sizes": "128x128", "type": "image/png" },
    { "src": "/icons/icon-144.png", "sizes": "144x144", "type": "image/png" },
    { "src": "/icons/icon-152.png", "sizes": "152x152", "type": "image/png" },
    { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any maskable" },
    { "src": "/icons/icon-384.png", "sizes": "384x384", "type": "image/png" },
    { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable" }
  ],
  "screenshots": [
    { "src": "/screenshots/dashboard.png", "sizes": "1280x720", "type": "image/png" },
    { "src": "/screenshots/mobile.png", "sizes": "750x1334", "type": "image/png" }
  ],
  "shortcuts": [
    { "name": "Dashboard", "url": "/dashboard", "icons": [{ "src": "/icons/shortcut-dashboard.png", "sizes": "96x96" }] },
    { "name": "Settings", "url": "/user/preferences", "icons": [{ "src": "/icons/shortcut-settings.png", "sizes": "96x96" }] }
  ]
}
```

## Environment Variables

```env
# VAPID keys for push notifications
# Generate with: npx web-push generate-vapid-keys
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@yourdomain.com
```

## Testing Checklist

- [ ] Lighthouse PWA audit passes (90+ score) — run manually: `npx lighthouse http://localhost:8080 --only-categories=pwa`
- [ ] Install prompt appears after criteria met (2+ visits)
- [ ] Push notifications work on Chrome, Firefox, Edge
- [ ] Offline page displays when network unavailable
- [ ] Cached pages load instantly
- [ ] Service worker updates without breaking app
- [ ] Works on iOS Safari (with limitations noted)
- [ ] Works on Android Chrome

**PWA Review (2026-02-05):** Code review completed. Removed missing screenshots from manifest, fixed `console.error` → `errorLogger`, added share page URL validation (http/https only). See [journal/2026-02-05-pwa-review.md](../journal/2026-02-05-pwa-review.md).

**PWA Hardening (2026-02-05):** Comprehensive hardening pass. Fixed broken server-side manifest route (headers-based URL resolution), generated missing `apple-icon.png` and `favicon.ico`, added `<meta name="theme-color">` with dynamic branding updates, split icon `purpose: "any maskable"` into separate entries, bundled Workbox 7.3.0 locally (removed CDN dependency), added 24h stale queue cleanup with 4xx removal, replaced hardcoded app name on share page, added `postMessage` NAVIGATE fallback for notification clicks, added standalone CSS and iOS safe-area support. See [journal/2026-02-05-pwa-hardening.md](../journal/2026-02-05-pwa-hardening.md).

## Phase 6: Mobile Native Push Notification Fix

In-app notifications work but push notifications do not appear in the native OS notification tray on iOS or Android when the PWA is installed to home screen.

### Code Audit Findings

After reviewing the current implementation, the code paths look correct on paper:

- **Service worker `push` handler** (`sw.js:48-67`): Parses JSON payload, calls `self.registration.showNotification()` inside `event.waitUntil()` — correct pattern.
- **Backend `WebPushChannel`** (`WebPushChannel.php`): Builds proper payload with `title`, `body`, `icon`, `badge`, `tag`, `data`, `timestamp`. Uses `minishlink/web-push` with VAPID auth and 86400s TTL — correct.
- **Subscription flow** (`web-push.ts`): Registers SW, requests permission, calls `pushManager.subscribe()` with `userVisibleOnly: true` and VAPID key — correct.
- **Notification click handler** (`sw.js:70-91`): Closes notification, navigates via `client.navigate()` with `postMessage` fallback — correct (hardened 2026-02-05).
- **SW registration** (`service-worker.ts`): Registers `/sw.js` at scope `/` — correct.

No obvious code bugs found. The likely issues are **environmental/configuration** rather than code defects.

### Investigation Tasks

1. [ ] **Verify VAPID keys are configured** — Check that `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, and `VAPID_SUBJECT` are set in production settings (via admin UI or env). Missing keys cause `WebPushChannel` to throw before sending.
2. [ ] **Verify subscription is stored server-side** — After enabling Web Push in user preferences, confirm `settings` table has `group=notifications`, `key=webpush_subscription` with valid `endpoint` + `keys` JSON for the test user.
3. [ ] **Test push delivery end-to-end on desktop first** — Use the "Test" button on user preferences to trigger a test notification. Check:
   - Backend logs for `WebPushChannel::send()` success/failure
   - Browser DevTools > Application > Service Workers > Push for incoming events
   - DevTools > Console for any SW errors during `push` handler
4. [ ] **Test on Android Chrome (installed PWA)** — Install PWA to home screen, enable Web Push, send test notification while app is in background. Check:
   - Service worker is `active` and `running` (chrome://serviceworker-internals on Android via USB debug)
   - Push subscription endpoint uses `fcm.googleapis.com` (Android uses FCM as push transport)
   - Notification appears in system tray
   - Tapping notification opens PWA and navigates to correct URL
5. [ ] **Test on iOS Safari (installed PWA)** — Requires iOS 16.4+ and PWA added to Home Screen. Check:
   - Permission prompt appears when enabling Web Push (iOS only shows this for home screen PWAs)
   - Push subscription endpoint uses Apple's push service
   - Notification appears in system tray when app is closed
   - Note: iOS requires `userVisibleOnly: true` (already set) and the PWA must be added to home screen before push works
6. [x] **Check payload size limits** — Payload size guard added to `WebPushChannel::send()` (3800 byte limit with progressive truncation).
7. [x] **Verify `badge.png` exists** — Confirmed at `frontend/public/badge.png` (4455 bytes).
8. [ ] **Test notification with app in different states** — Verify push arrives when:
   - App is open in foreground (should show in-app only — foreground deduplication now active)
   - App is in background/minimized
   - App is fully closed (SW should wake and show notification)
   - Device is locked/screen off
9. [x] **Document mobile push requirements** — Added "Mobile Push Notifications" section to help content (`help-content.ts`) and iOS guidance to user preferences page.

### Code Fixes Applied

- [x] **Add push event error logging in SW** — Added `console.error('[SW] ...')` logging to push payload parse and `showNotification()` catch blocks. Visible via Chrome remote debugging (Android) and Safari Web Inspector (iOS).
- [x] **Add payload size guard** — `WebPushChannel::send()` checks payload size against 3800 byte limit (leaving headroom for encryption overhead). Strips `data` key first, then truncates `body` if still too large. Logs warning.
- [x] **Add `badge.png` if missing** — Already exists (`frontend/public/badge.png`, 4455 bytes).
- [x] **iOS permission flow guidance** — User preferences page shows contextual helper text for iOS users: explains home screen install requirement (Safari) or iOS 16.4+ requirement (installed PWA). Also shows guidance when VAPID is not configured.
- [x] **Foreground push deduplication** — SW checks `clients.matchAll()` for focused visible client before `showNotification()`. When app is in foreground, skips system notification and posts `PUSH_RECEIVED` message instead. `ServiceWorkerSetup` dispatches `push-received` custom event for notification bell refresh.
- [x] **Mobile push documentation** — Added "Mobile Push Notifications" section to help content covering requirements, iOS/Android specifics, and troubleshooting.

### Verification Checklist

Use this checklist to verify push notifications work end-to-end after applying fixes.

#### Prerequisites
- [ ] VAPID keys configured (check admin UI or env: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`)
- [ ] At least one user has Web Push enabled in Preferences
- [ ] Subscription stored: check `settings` table for `group=notifications`, `key=webpush_subscription`

#### Desktop
- [ ] Click "Test" in User Preferences — notification appears in system tray
- [ ] Check browser DevTools > Application > Service Workers > Push for incoming events
- [ ] Check browser console for any `[SW]` error logs
- [ ] Verify notification click navigates to correct URL

#### Android (installed PWA)
- [ ] Install PWA via Chrome menu
- [ ] Enable Web Push in Preferences
- [ ] Send test notification while app is in background — appears in system tray
- [ ] Send test notification while app is in foreground — only in-app notification (no duplicate)
- [ ] Tap notification — opens PWA and navigates correctly

#### iOS (installed PWA, iOS 16.4+)
- [ ] Add to Home Screen via Safari share menu
- [ ] Open from home screen, enable Web Push
- [ ] Verify iOS permission prompt appears
- [ ] Send test notification while app is closed — appears in notification center
- [ ] Tap notification — opens PWA
- [ ] Verify helper text appears when viewing preferences in Safari (not installed)

### Key Files

| File | Role |
|------|------|
| `frontend/public/sw.js` | Push event handler, notification click handler |
| `frontend/lib/web-push.ts` | Browser push subscription management |
| `frontend/lib/service-worker.ts` | SW registration |
| `frontend/components/service-worker-setup.tsx` | SW setup + NAVIGATE fallback listener |
| `frontend/app/(dashboard)/user/preferences/page.tsx` | Web Push enable/disable UI |
| `backend/app/Services/Notifications/Channels/WebPushChannel.php` | Server-side push delivery via VAPID |
| `backend/config/notifications.php` | Channel config including VAPID keys |

## Browser Support Notes

| Feature | Chrome | Firefox | Safari | Edge |
|---------|--------|---------|--------|------|
| Service Worker | ✅ | ✅ | ✅ | ✅ |
| Push Notifications | ✅ | ✅ | ⚠️ iOS 16.4+ | ✅ |
| Install Prompt | ✅ | ❌ | ⚠️ Manual | ✅ |
| Background Sync | ✅ | ❌ | ❌ | ✅ |
| Share Target | ✅ | ❌ | ⚠️ Limited | ✅ |

## Dependencies

- Manual SW setup with [Workbox](https://developer.chrome.com/docs/workbox) 7.3.0 (bundled locally in `frontend/public/workbox/`)
- Existing notification system infrastructure

## References

- [MDN PWA Guide](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps)
- [web.dev PWA](https://web.dev/progressive-web-apps/)
- [Workbox Documentation](https://developer.chrome.com/docs/workbox)
- [Push API](https://developer.mozilla.org/en-US/docs/Web/API/Push_API)
